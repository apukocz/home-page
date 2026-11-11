<?php
/**
 * Panel Sterowania Bramami - PI1 (10.0.0.4)
 * 
 * Dedykowany panel do szybkiego sterowania bramami posesji:
 * - Brama wjazdowa (GPIO 530, pin fizyczny / impuls 0.5s)
 * - Brama garażowa (GPIO 527, pin fizyczny / impuls 0.5s)
 * 
 * Obsługuje wywołania WWW (AJAX / Optimistic UI), REST API (JSON)
 * oraz polecenia cURL / automatyzacji (Siri Shortcuts, Home Assistant, Alexa).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

// Konfiguracja bram dla panelu
$GATES = [
    'brama_wjazd' => [
        'id' => 'brama_wjazd',
        'name' => 'Brama wjazdowa',
        'desc' => 'Brama wjazdowa na posesję • GPIO 530 • Impuls 0.5s',
        'param' => 'relay_brama_wjazdowa',
        'gpio' => 530,
        'icon' => 'gate',
        'default_time' => '0.5'
    ],
    'brama_garaz' => [
        'id' => 'brama_garaz',
        'name' => 'Brama garażowa',
        'desc' => 'Brama segmentowa garażowa • GPIO 527 • Impuls 0.5s',
        'param' => 'relay_brama_garazowa',
        'gpio' => 527,
        'icon' => 'garage',
        'default_time' => '0.5'
    ]
];

/**
 * Sprawdza aktywne procesy script_brama.sh w systemie (/proc lub fallback ps)
 */
function getActiveGateProcesses() {
    static $cachedProcesses = null;
    if ($cachedProcesses !== null) {
        return $cachedProcesses;
    }

    $cachedProcesses = [];

    // 1. Bezpośredni szybki odczyt z /proc (brak forków procesu)
    $procFiles = @glob('/proc/[0-9]*/cmdline');
    if (!empty($procFiles)) {
        foreach ($procFiles as $cmdFile) {
            $cmd = @file_get_contents($cmdFile);
            if ($cmd !== false && strpos($cmd, 'script_brama.sh') !== false) {
                $cachedProcesses[] = str_replace("\0", ' ', $cmd);
            }
        }
        return $cachedProcesses;
    }

    // 2. Fallback - wywołanie ps
    if (function_exists('shell_exec')) {
        $output = @shell_exec("ps -eo args 2>/dev/null | grep -E 'script_brama\\.sh' | grep -v grep");
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                if (trim($line) !== '') {
                    $cachedProcesses[] = trim($line);
                }
            }
        }
    }

    return $cachedProcesses;
}

/**
 * Odczytuje aktualny status bramy (proces w tle lub plik .state)
 */
function getGateStatusInfo($gateKey) {
    global $GATES;
    $gpioDir = defined('GPIO_DIR') ? GPIO_DIR : (is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');
    $gate = $GATES[$gateKey] ?? null;
    if (!$gate) {
        return ['state' => 'unknown', 'label' => 'Nieznana', 'badge' => 'badge-secondary', 'is_moving' => false, 'is_open' => false];
    }

    $param = $gate['param'];
    $gpio = $gate['gpio'];

    // 1. Sprawdzenie czy skrypt script_brama.sh aktualnie wykonuje impuls (trwa sleep 0.5s)
    $activeProcesses = getActiveGateProcesses();
    $isMoving = false;
    $currentAction = '';
    foreach ($activeProcesses as $cmd) {
        if (strpos($cmd, " {$param}") !== false || strpos($cmd, " {$gpio}") !== false) {
            $isMoving = true;
            if (strpos($cmd, ' open') !== false) $currentAction = 'open';
            elseif (strpos($cmd, ' close') !== false) $currentAction = 'close';
            elseif (strpos($cmd, ' stop') !== false) $currentAction = 'stop';
            break;
        }
    }

    if ($isMoving) {
        return [
            'state' => 'moving',
            'label' => 'Impuls aktywny...',
            'badge' => 'badge-warning',
            'is_moving' => true,
            'is_open' => true,
            'action' => $currentAction
        ];
    }

    // 2. Odczyt pliku .state (1 = open, 2 = closed/stop, 0 = pulse)
    $stateFilesToCheck = [
        rtrim($gpioDir, '/') . '/' . $param . '.state',
        rtrim($gpioDir, '/') . '/' . $gateKey . '.state'
    ];
    if ($gateKey === 'brama_wjazd') {
        $stateFilesToCheck[] = rtrim($gpioDir, '/') . '/relay_brama_wjazd.state';
        $stateFilesToCheck[] = rtrim($gpioDir, '/') . '/wjazdowa.state';
    } else {
        $stateFilesToCheck[] = rtrim($gpioDir, '/') . '/relay_brama_garaz.state';
        $stateFilesToCheck[] = rtrim($gpioDir, '/') . '/garazowa.state';
    }

    foreach ($stateFilesToCheck as $sf) {
        if (file_exists($sf) && is_readable($sf)) {
            $val = trim(@file_get_contents($sf));
            if ($val === '1') {
                return [
                    'state' => 'open',
                    'label' => 'Otwarta',
                    'badge' => 'badge-success',
                    'is_moving' => false,
                    'is_open' => true
                ];
            } elseif ($val === '2') {
                return [
                    'state' => 'closed',
                    'label' => 'Zamknięta',
                    'badge' => 'badge-secondary',
                    'is_moving' => false,
                    'is_open' => false
                ];
            } elseif ($val === '0') {
                return [
                    'state' => 'moving',
                    'label' => 'Impuls...',
                    'badge' => 'badge-warning',
                    'is_moving' => true,
                    'is_open' => true
                ];
            }
        }
    }

    return [
        'state' => 'closed',
        'label' => 'Zamknięta',
        'badge' => 'badge-secondary',
        'is_moving' => false,
        'is_open' => false
    ];
}

/**
 * Wykonuje komendę sterowania bramą przez script_brama.sh
 */
function executeGateAction($gateKey, $action, $time = null) {
    global $GATES, $GATE_CONFIG;
    $gpioDir = defined('GPIO_DIR') ? GPIO_DIR : (is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');
    $sudoPrefix = defined('USE_SUDO') && USE_SUDO ? 'sudo ' : '';
    $envPrefix = 'GPIO_DIR=' . escapeshellarg($gpioDir) . ' ';
    $isSimulation = defined('SIMULATION_MODE') && SIMULATION_MODE;

    // Normalizacja akcji
    if ($action === 'toggle' || empty($action)) {
        $action = 'open';
    }
    if (!in_array($action, ['open', 'close', 'stop'])) {
        return ['success' => false, 'error' => "Błędna akcja: '{$action}'. Dozwolone: open, close, stop."];
    }

    // Normalizacja identyfikatora bramy
    if ($gateKey === 'wjazd' || $gateKey === 'wjazdowa' || $gateKey === 'brama_wjazdowa' || $gateKey === 'relay_brama_wjazdowa' || $gateKey === 'relay_brama_wjazd') {
        $gateKey = 'brama_wjazd';
    }
    if ($gateKey === 'garaz' || $gateKey === 'garazowa' || $gateKey === 'brama_garazowa' || $gateKey === 'relay_brama_garazowa' || $gateKey === 'relay_brama_garaz') {
        $gateKey = 'brama_garaz';
    }

    // Obsługa wywołania dla obu bram (ALL / bramy_all)
    if ($gateKey === 'ALL' || $gateKey === 'bramy_all' || $gateKey === 'all') {
        $results = [];
        $allSuccess = true;
        foreach (array_keys($GATES) as $k) {
            $r = executeGateAction($k, $action, $time);
            $results[$k] = $r;
            if (empty($r['success'])) $allSuccess = false;
            usleep(100000); // 100ms odstępu między bramami
        }
        $actText = ($action === 'open') ? 'Otwórz' : (($action === 'close') ? 'Zamknij' : 'Stop');
        return [
            'success' => $allSuccess,
            'gate' => 'ALL',
            'action' => $action,
            'message' => "Wysłano akcję '{$actText}' do wszystkich bram posesji",
            'results' => $results
        ];
    }

    if (!isset($GATES[$gateKey])) {
        return ['success' => false, 'error' => "Nieznana brama: '{$gateKey}'."];
    }

    $gate = $GATES[$gateKey];
    $param = $gate['param'];
    $gpio = (int)$gate['gpio'];
    $pulseTime = ($time !== null && is_numeric($time) && (float)$time > 0) ? (string)$time : ($gate['default_time'] ?? '0.5');

    $scriptPath = defined('SCRIPT_BRAMA_PATH') && file_exists(SCRIPT_BRAMA_PATH) ? SCRIPT_BRAMA_PATH : __DIR__ . '/script_brama.sh';
    if (!file_exists($scriptPath)) {
        return ['success' => false, 'error' => "Brak pliku skryptu bram: {$scriptPath}"];
    }

    if ($isSimulation) {
        $simFile = rtrim($gpioDir, '/') . '/' . $param . '.state';
        @file_put_contents($simFile, ($action === 'open' ? "1\n" : "2\n"));
        return [
            'success' => true,
            'gate' => $gateKey,
            'name' => $gate['name'],
            'action' => $action,
            'gpio' => $gpio,
            'time' => $pulseTime,
            'output' => "Symulacja: script_brama.sh {$param} {$action} {$gpio} {$pulseTime}",
            'message' => "Symulacja: Wysłano impuls do {$gate['name']}"
        ];
    }

    // Uruchomienie skryptu w tle (nohup / &)
    $cmd = $envPrefix . $sudoPrefix . 'nohup /bin/sh ' . escapeshellcmd($scriptPath) . ' '
         . escapeshellarg($param) . ' '
         . escapeshellarg($action) . ' '
         . escapeshellarg((string)$gpio) . ' '
         . escapeshellarg($pulseTime) . ' > /dev/null 2>&1 &';

    exec($cmd);

    // Zapis do pliku logu
    $logFile = rtrim($gpioDir, '/') . '/script_brama.log';
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " [brama.php exec] {$cmd}\n", FILE_APPEND);

    // Zapis stanu do plików .state
    $stateVal = ($action === 'open') ? '1' : '2';
    @file_put_contents(rtrim($gpioDir, '/') . '/' . $param . '.state', "{$stateVal}\n");
    @file_put_contents(rtrim($gpioDir, '/') . '/' . $gateKey . '.state', "{$stateVal}\n");
    if ($gateKey === 'brama_wjazd') {
        @file_put_contents(rtrim($gpioDir, '/') . '/relay_brama_wjazd.state', "{$stateVal}\n");
        @file_put_contents(rtrim($gpioDir, '/') . '/wjazdowa.state', "{$stateVal}\n");
    } else {
        @file_put_contents(rtrim($gpioDir, '/') . '/relay_brama_garaz.state', "{$stateVal}\n");
        @file_put_contents(rtrim($gpioDir, '/') . '/garazowa.state', "{$stateVal}\n");
    }

    $actLabel = ($action === 'open') ? 'Otwórz' : (($action === 'close') ? 'Zamknij' : 'Stop');
    return [
        'success' => true,
        'gate' => $gateKey,
        'name' => $gate['name'],
        'action' => $action,
        'action_label' => $actLabel,
        'gpio' => $gpio,
        'time' => $pulseTime,
        'message' => "Wysłano impuls: {$gate['name']} ({$actLabel}, {$pulseTime}s)",
        'cmd' => $cmd
    ];
}

/**
 * Pobiera ostatnie linie z logu script_brama.log
 */
function getRecentGateLogs($limit = 5) {
    $gpioDir = defined('GPIO_DIR') ? GPIO_DIR : (is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');
    $logFile = rtrim($gpioDir, '/') . '/script_brama.log';
    if (!file_exists($logFile) || !is_readable($logFile)) {
        return [];
    }
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    return array_slice(array_reverse($lines), 0, $limit);
}

// =============================================================================
// OBSŁUGA ŻĄDAŃ API I FORMULARZY
// =============================================================================

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] == '1')
       || (isset($_REQUEST['api']) && $_REQUEST['api'] !== '')
       || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

// 1. Dedykowane zapytanie o status (JSON)
if ((isset($_GET['api']) && $_GET['api'] === 'status') || (isset($_GET['action']) && $_GET['action'] === 'status')) {
    header('Content-Type: application/json; charset=utf-8');
    $statusData = [];
    foreach ($GATES as $k => $g) {
        $statusData[$k] = array_merge($g, getGateStatusInfo($k));
    }
    echo json_encode([
        'success' => true,
        'gates' => $statusData,
        'logs' => getRecentGateLogs(5),
        'server_time' => date('H:i:s'),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Obsługa akcji przez POST lub GET
$requestedGate = $_REQUEST['gate'] ?? ($_REQUEST['room'] ?? ($_REQUEST['device'] ?? null));
$requestedAction = $_REQUEST['action'] ?? null;
$requestedTime = $_REQUEST['time'] ?? ($_REQUEST['duration'] ?? null);

// Skróty z parametrów GET: ?brama_wjazd=open, ?brama_garaz=open, ?wjazd=open itd.
if (!$requestedGate) {
    if (isset($_GET['brama_wjazd'])) {
        $requestedGate = 'brama_wjazd';
        $requestedAction = $_GET['brama_wjazd'] === '1' ? 'open' : $_GET['brama_wjazd'];
    } elseif (isset($_GET['brama_garaz'])) {
        $requestedGate = 'brama_garaz';
        $requestedAction = $_GET['brama_garaz'] === '1' ? 'open' : $_GET['brama_garaz'];
    } elseif (isset($_GET['wjazd'])) {
        $requestedGate = 'brama_wjazd';
        $requestedAction = $_GET['wjazd'] === '1' ? 'open' : $_GET['wjazd'];
    } elseif (isset($_GET['garaz'])) {
        $requestedGate = 'brama_garaz';
        $requestedAction = $_GET['garaz'] === '1' ? 'open' : $_GET['garaz'];
    }
}

if ($requestedGate) {
    if (!$requestedAction) {
        $requestedAction = 'open'; // Domyślna akcja: impuls otwarcia / przełączenia
    }

    $response = executeGateAction($requestedGate, $requestedAction, $requestedTime);

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        // Dołącz zaktualizowany status obu bram
        $statusData = [];
        foreach ($GATES as $k => $g) {
            $statusData[$k] = array_merge($g, getGateStatusInfo($k));
        }
        $response['gates'] = $statusData;
        $response['logs'] = getRecentGateLogs(5);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $_SESSION['flash_message'] = $response['message'] ?? ($response['error'] ?? 'Wykonano akcję');
        $_SESSION['flash_type'] = !empty($response['success']) ? 'success' : 'danger';
        header("Location: brama.php");
        exit;
    }
}

// Odczyt aktualnych statusów do renderowania HTML
$gateStates = [];
foreach ($GATES as $k => $g) {
    $gateStates[$k] = array_merge($g, getGateStatusInfo($k));
}
$recentLogs = getRecentGateLogs(5);

// Diagnostyka środowiska
$diagScriptBramaExists = file_exists(SCRIPT_BRAMA_PATH);
$diagScriptBramaExec = is_executable(SCRIPT_BRAMA_PATH);
$diagGpioDirExists = is_dir(GPIO_DIR);
$diagGpioDirWritable = is_writable(GPIO_DIR);
$currentUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sterowanie Bramami • PI1</title>
    <style>
        :root {
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --bg-card-hover: #243248;
            --border-color: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --success-hover: #059669;
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --warning: #f59e0b;
            --indigo: #6366f1;
            --radius-lg: 14px;
            --radius-md: 10px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -2px rgba(0, 0, 0, 0.3);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            padding: 16px 12px 60px 12px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Pasek nawigacji między serwerami */
        .server-nav {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
            background: var(--bg-card);
            padding: 10px 16px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .server-nav-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .server-nav-label svg {
            width: 16px;
            height: 16px;
            stroke: #94a3b8;
            fill: none;
            stroke-width: 2;
        }

        .server-nav-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            flex: 1;
        }

        .server-nav-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .server-nav-link:hover {
            background: #23334d;
            border-color: var(--primary);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .server-nav-link.active {
            background: rgba(59, 130, 246, 0.15);
            border-color: #3b82f6;
            color: #93c5fd;
        }

        .server-nav-link svg {
            width: 15px;
            height: 15px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            flex-shrink: 0;
        }

        /* Nagłówek */
        header {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: var(--bg-card);
            padding: 16px 20px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .header-title h1 {
            font-size: 1.35rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
            margin-bottom: 4px;
        }

        .header-title p {
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Toast Container */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            min-width: 280px;
            max-width: 420px;
            background: #1e293b;
            color: #fff;
            padding: 12px 18px;
            border-radius: 10px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
            border-left: 4px solid var(--primary);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease forwards;
            pointer-events: auto;
            border: 1px solid var(--border-color);
        }

        .toast.success { border-left-color: var(--success); }
        .toast.danger { border-left-color: var(--danger); }
        .toast.warning { border-left-color: var(--warning); }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-10px); }
        }

        /* Alert flash */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; }
        .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; }

        /* Karty bram - Układ siatki */
        .gates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .gate-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: border-color 0.2s ease, transform 0.2s ease;
        }

        .gate-card:hover {
            border-color: rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
        }

        .gate-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .gate-meta {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .gate-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(59, 130, 246, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            flex-shrink: 0;
        }

        .gate-icon-box svg {
            width: 24px;
            height: 24px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
        }

        .gate-title-area h2 {
            font-size: 1.15rem;
            color: #ffffff;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .gate-title-area p {
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.3;
        }

        /* Status Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.18);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }

        .badge-secondary {
            background: rgba(148, 163, 184, 0.12);
            color: #cbd5e1;
            border: 1px solid rgba(148, 163, 184, 0.25);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
            animation: pulse-border 1.5s infinite ease-in-out;
        }

        @keyframes pulse-border {
            0%, 100% { border-color: rgba(245, 158, 11, 0.4); }
            50% { border-color: rgba(245, 158, 11, 0.9); }
        }

        /* Główny duży klawisz pilota */
        .gate-main-btn-wrapper {
            margin-bottom: 12px;
        }

        .btn-gate-main {
            width: 100%;
            padding: 16px 20px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            border: 1px solid #3b82f6;
            color: #ffffff;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.4);
            transition: all 0.2s ease;
        }

        .btn-gate-main:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-color: #60a5fa;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.5);
        }

        .btn-gate-main:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(30, 58, 138, 0.4);
        }

        .btn-gate-main.moving {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            border-color: #fbbf24;
            animation: pulse-main 1.2s infinite ease-in-out;
        }

        @keyframes pulse-main {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(0.99); opacity: 0.9; }
        }

        .btn-gate-main svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            flex-shrink: 0;
        }

        /* Przyciski funkcyjne: Otwórz / Zamknij / Stop */
        .gate-btn-group {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            border-radius: var(--radius-md);
            font-size: 0.88rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: #ffffff;
        }

        .btn svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            flex-shrink: 0;
        }

        .btn-open {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #34d399;
        }

        .btn-open:hover {
            background: var(--success);
            border-color: var(--success-hover);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-close {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #f87171;
        }

        .btn-close:hover {
            background: var(--danger);
            border-color: var(--danger-hover);
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-stop {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #e2e8f0;
        }

        .btn-stop:hover {
            background: #334155;
            border-color: #64748b;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 8px 14px;
            font-size: 0.82rem;
        }

        .btn-secondary:hover {
            background: #334155;
            border-color: #475569;
            color: #ffffff;
        }

        .btn-xs {
            padding: 6px 12px;
            font-size: 0.78rem;
        }

        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Sekcja Akcji Grupowych */
        .group-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .group-meta {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .group-meta svg {
            width: 20px;
            height: 20px;
            stroke: #60a5fa;
            fill: none;
            stroke-width: 2;
        }

        .group-meta strong {
            font-size: 0.95rem;
            color: #ffffff;
        }

        .group-meta span {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .group-btns {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Historia wywołań */
        .logs-section {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            padding: 16px 20px;
            margin-bottom: 24px;
        }

        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .logs-header h3 {
            font-size: 0.9rem;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .logs-header h3 svg {
            width: 16px;
            height: 16px;
            stroke: #94a3b8;
            fill: none;
            stroke-width: 2;
        }

        .log-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .log-item {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.76rem;
            background: rgba(15, 23, 42, 0.6);
            padding: 6px 10px;
            border-radius: 6px;
            border-left: 3px solid var(--primary);
            color: #cbd5e1;
            word-break: break-all;
        }

        .log-empty {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-style: italic;
        }

        /* Stopka diagnostyczna */
        footer {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            padding: 16px 20px;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .diag-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .diag-item {
            display: flex;
            justify-content: space-between;
            background: rgba(15, 23, 42, 0.5);
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .status-ok { color: #34d399; font-weight: 600; }
        .status-err { color: #f87171; font-weight: 600; }

        @media (max-width: 600px) {
            .gates-grid {
                grid-template-columns: 1fr;
            }
            .server-nav {
                flex-direction: column;
                align-items: flex-start;
            }
            .group-card {
                flex-direction: column;
                align-items: stretch;
            }
            .group-btns {
                justify-content: stretch;
            }
            .group-btns button {
                flex: 1;
            }
        }
    </style>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<div class="container">
    <!-- Pasek przełączania paneli -->
    <nav class="server-nav">
        <div class="server-nav-label">
            <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <span>Panele:</span>
        </div>
        <div class="server-nav-links">
            <a href="http://10.0.0.2/" class="server-nav-link" title="Sterowanie kotłownią">
                <svg viewBox="0 0 24 24"><path d="M12 2c1.1 0 2 .9 2 2 0 .74-.4 1.38-1 1.72v1.56a5 5 0 0 1 4 4.9v2.82l1.7 1.7A1 1 0 0 1 18 18H6a1 1 0 0 1-.7-1.7l1.7-1.7v-2.82a5 5 0 0 1 4-4.9V5.72C10.4 5.38 10 4.74 10 4c0-1.1.9-2 2-2z"/><path d="M12 9a3 3 0 0 0-3 3v3h6v-3a3 3 0 0 0-3-3z"/></svg>
                <span>Kotłownia</span>
            </a>
            <a href="index.php" class="server-nav-link" title="Sterowanie roletami - Grzegorzka 8">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg>
                <span>Grzegorzka 8</span>
            </a>
            <a href="http://10.0.0.5/" class="server-nav-link" title="Sterowanie roletami - Grzegorzka 8A">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Grzegorzka 8A</span>
            </a>
            <a href="wentylacja.php" class="server-nav-link" title="Sterowanie wentylacją">
                <svg viewBox="0 0 24 24"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                <span>Wentylacja</span>
            </a>
            <a href="brama.php" class="server-nav-link active" title="Sterowanie bramami posesji">
                <svg viewBox="0 0 24 24"><path d="M4 4v16M20 4v16M4 8h16M4 16h16M9 4v16M15 4v16"/></svg>
                <span>Brama</span>
            </a>
        </div>
    </nav>

    <!-- Nagłówek panelu -->
    <header>
        <div class="header-title">
            <h1>
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v16M20 4v16M4 8h16M4 16h16M9 4v16M15 4v16"/></svg>
                Sterowanie Bramami
            </h1>
            <p>Brama wjazdowa (GPIO 530) • Brama garażowa (GPIO 527) • Serwer PI1 (10.0.0.4)</p>
        </div>
        <div class="header-actions">
            <button type="button" class="btn btn-secondary btn-xs" onclick="refreshGateStatus()" title="Odśwież stan bram">
                <svg viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                <span>Odśwież</span>
            </button>
            <span id="liveStatusIndicator" style="font-size: 0.75rem; color: #34d399; display: inline-flex; align-items: center; gap: 4px;">
                <span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:#10b981;"></span> Live
            </span>
        </div>
    </header>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>">
            <?= htmlspecialchars($_SESSION['flash_message']) ?>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- Grupa: Sterowanie obiema bramami naraz -->
    <section class="group-card">
        <div class="group-meta">
            <svg viewBox="0 0 24 24"><path d="M4 4v16M20 4v16M4 8h16M4 16h16M9 4v16M15 4v16"/></svg>
            <div>
                <strong>Wszystkie bramy posesji</strong>
                <div><span>Wspólne wyzwalanie bramy wjazdowej i garażowej</span></div>
            </div>
        </div>
        <div class="group-btns">
            <button type="button" class="btn btn-open btn-xs" onclick="triggerGate('ALL', 'open')">
                <svg viewBox="0 0 24 24"><path d="M18 15l-6-6-6 6"/></svg>
                <span>Otwórz obie</span>
            </button>
            <button type="button" class="btn btn-close btn-xs" onclick="triggerGate('ALL', 'close')">
                <svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                <span>Zamknij obie</span>
            </button>
            <button type="button" class="btn btn-stop btn-xs" onclick="triggerGate('ALL', 'stop')">
                <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                <span>Stop obie</span>
            </button>
        </div>
    </section>

    <!-- KARTY DWÓCH BRAM: Brama wjazdowa i Brama garażowa -->
    <main class="gates-grid">
        <?php foreach ($gateStates as $gateId => $gate): ?>
            <div class="gate-card" id="card-<?= htmlspecialchars($gateId) ?>">
                <div>
                    <!-- Nagłówek karty -->
                    <div class="gate-header">
                        <div class="gate-meta">
                            <div class="gate-icon-box">
                                <?php if ($gate['icon'] === 'garage'): ?>
                                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 21V9"/></svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24"><path d="M4 4v16M20 4v16M4 8h16M4 16h16M9 4v16M15 4v16"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="gate-title-area">
                                <h2><?= htmlspecialchars($gate['name']) ?></h2>
                                <p><?= htmlspecialchars($gate['desc']) ?></p>
                            </div>
                        </div>
                        <div>
                            <span class="badge <?= htmlspecialchars($gate['badge']) ?>" id="badge-<?= htmlspecialchars($gateId) ?>">
                                <?= htmlspecialchars($gate['label']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- GŁÓWNY DUŻY KLAWISZ (PILOT BRAMY) -->
                    <div class="gate-main-btn-wrapper">
                        <button type="button" 
                                class="btn-gate-main <?= !empty($gate['is_moving']) ? 'moving' : '' ?>" 
                                id="btn-main-<?= htmlspecialchars($gateId) ?>"
                                onclick="triggerGate('<?= htmlspecialchars($gateId) ?>', 'open')"
                                title="Kliknij, aby wysłać impuls do bramy">
                            <span class="btn-icon" id="btn-icon-<?= htmlspecialchars($gateId) ?>">
                                <?php if (!empty($gate['is_moving'])): ?>
                                    <span class="spinner"></span>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                <?php endif; ?>
                            </span>
                            <span class="btn-text" id="btn-text-<?= htmlspecialchars($gateId) ?>">
                                <?= !empty($gate['is_moving']) ? 'Wysyłanie impulsu...' : 'Klawisz: ' . htmlspecialchars($gate['name']) ?>
                            </span>
                        </button>
                    </div>
                </div>

                <!-- PRZYCISKI FUNKCYJNE: Otwórz / Zamknij / Stop -->
                <div class="gate-btn-group">
                    <button type="button" 
                            class="btn btn-open" 
                            id="btn-open-<?= htmlspecialchars($gateId) ?>"
                            onclick="triggerGate('<?= htmlspecialchars($gateId) ?>', 'open')"
                            title="Otwórz bramę (impuls open)">
                        <svg viewBox="0 0 24 24"><path d="M18 15l-6-6-6 6"/></svg>
                        <span>Otwórz</span>
                    </button>

                    <button type="button" 
                            class="btn btn-close" 
                            id="btn-close-<?= htmlspecialchars($gateId) ?>"
                            onclick="triggerGate('<?= htmlspecialchars($gateId) ?>', 'close')"
                            title="Zamknij bramę (impuls close)">
                        <svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                        <span>Zamknij</span>
                    </button>

                    <button type="button" 
                            class="btn btn-stop" 
                            id="btn-stop-<?= htmlspecialchars($gateId) ?>"
                            onclick="triggerGate('<?= htmlspecialchars($gateId) ?>', 'stop')"
                            title="Zatrzymaj bramę (impuls stop)">
                        <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                        <span>Stop</span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </main>

    <!-- Ostatnie operacje w logu -->
    <section class="logs-section">
        <div class="logs-header">
            <h3>
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Ostatnie wywołania bram (script_brama.log)
            </h3>
            <span style="font-size: 0.75rem; color: var(--text-muted);" id="logsTimestamp">
                Aktualizacja: <?= date('H:i:s') ?>
            </span>
        </div>
        <ul class="log-list" id="logsList">
            <?php if (!empty($recentLogs)): ?>
                <?php foreach ($recentLogs as $log): ?>
                    <li class="log-item"><?= htmlspecialchars($log) ?></li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="log-empty">Brak wcześniejszych wpisów w logu bram.</li>
            <?php endif; ?>
        </ul>
    </section>

    <!-- Stopka diagnostyczna -->
    <footer>
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <strong>Diagnostyka serwera i sterownika bram (PI1):</strong>
            <span style="font-size:0.75rem;">Użytkownik PHP: <code><?= htmlspecialchars($currentUser) ?></code></span>
        </div>
        <div class="diag-grid">
            <div class="diag-item">
                <span>Skrypt (script_brama.sh):</span>
                <span class="<?= $diagScriptBramaExists && $diagScriptBramaExec ? 'status-ok' : 'status-err' ?>">
                    <?= $diagScriptBramaExists ? ($diagScriptBramaExec ? 'Wykonywalny' : 'Brak +x') : 'Nie znaleziono' ?>
                </span>
            </div>
            <div class="diag-item">
                <span>Katalog GPIO (.state):</span>
                <span class="<?= $diagGpioDirExists && $diagGpioDirWritable ? 'status-ok' : 'status-err' ?>">
                    <?= $diagGpioDirExists ? ($diagGpioDirWritable ? 'Zapisywalny' : 'Tylko odczyt') : 'Brak folderu' ?>
                </span>
            </div>
            <div class="diag-item">
                <span>Brama wjazdowa (GPIO):</span>
                <span class="status-ok">GPIO 530 (Pin 24)</span>
            </div>
            <div class="diag-item">
                <span>Brama garażowa (GPIO):</span>
                <span class="status-ok">GPIO 527 (Pin 21)</span>
            </div>
        </div>
    </footer>
</div>

<script>
// Pokaż powiadomienie Toast
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    let iconSvg = '';
    if (type === 'success') {
        iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
    } else if (type === 'danger') {
        iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
    } else {
        iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    }

    toast.innerHTML = `
        <div style="flex-shrink: 0; display: flex; align-items: center;">${iconSvg}</div>
        <div style="flex: 1; font-weight: 500;">${message}</div>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.3s ease forwards';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

// Wyzwolenie akcji sterowania bramą
async function triggerGate(gateId, action) {
    const mainBtn = document.getElementById(`btn-main-${gateId}`);
    const mainBtnIcon = document.getElementById(`btn-icon-${gateId}`);
    const mainBtnText = document.getElementById(`btn-text-${gateId}`);
    const badge = document.getElementById(`badge-${gateId}`);

    // 1. NATYCHMIASTOWA REAKCJA UI (0 ms) - OPTIMISTIC UPDATE
    if (mainBtn) {
        mainBtn.classList.add('moving');
        if (mainBtnIcon) mainBtnIcon.innerHTML = '<span class="spinner"></span>';
        if (mainBtnText) mainBtnText.textContent = 'Impuls...';
    }
    if (badge) {
        badge.className = 'badge badge-warning';
        badge.textContent = 'Impuls aktywny...';
    }

    if (gateId === 'ALL') {
        ['brama_wjazd', 'brama_garaz'].forEach(gid => {
            const b = document.getElementById(`badge-${gid}`);
            const mb = document.getElementById(`btn-main-${gid}`);
            const mi = document.getElementById(`btn-icon-${gid}`);
            const mt = document.getElementById(`btn-text-${gid}`);
            if (b) { b.className = 'badge badge-warning'; b.textContent = 'Impuls aktywny...'; }
            if (mb) { mb.classList.add('moving'); }
            if (mi) { mi.innerHTML = '<span class="spinner"></span>'; }
            if (mt) { mt.textContent = 'Impuls...'; }
        });
    }

    try {
        const formData = new FormData();
        formData.append('gate', gateId);
        formData.append('action', action);
        formData.append('ajax', '1');

        const response = await fetch('brama.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error ${response.status}`);
        }

        const data = await response.json();

        if (data.success) {
            showToast(data.message || 'Wysłano impuls do bramy', 'success');
        } else {
            showToast(data.error || 'Błąd sterowania bramą', 'danger');
        }

        // Zaktualizuj stany na podstawie odpowiedzi
        if (data.gates) {
            updateGateUi(data.gates);
        }
        if (data.logs) {
            updateLogsUi(data.logs);
        }

        // Dodatkowe odświeżenie po 800ms (gdy impuls 0.5s się zakończy)
        setTimeout(refreshGateStatus, 800);

    } catch (err) {
        console.error('Błąd sterowania bramą:', err);
        showToast('Błąd połączenia z serwerem', 'danger');
        setTimeout(refreshGateStatus, 1500);
    }
}

// Aktualizacja UI bram
function updateGateUi(gates) {
    for (const [gid, gdata] of Object.entries(gates)) {
        const badge = document.getElementById(`badge-${gid}`);
        const mainBtn = document.getElementById(`btn-main-${gid}`);
        const mainBtnIcon = document.getElementById(`btn-icon-${gid}`);
        const mainBtnText = document.getElementById(`btn-text-${gid}`);

        if (badge) {
            badge.className = `badge ${gdata.badge || 'badge-secondary'}`;
            badge.textContent = gdata.label || 'Nieznany';
        }

        if (mainBtn) {
            if (gdata.is_moving) {
                mainBtn.classList.add('moving');
                if (mainBtnIcon) mainBtnIcon.innerHTML = '<span class="spinner"></span>';
                if (mainBtnText) mainBtnText.textContent = 'Wysyłanie impulsu...';
            } else {
                mainBtn.classList.remove('moving');
                if (mainBtnIcon) mainBtnIcon.innerHTML = '<svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
                if (mainBtnText) mainBtnText.textContent = `Klawisz: ${gdata.name}`;
            }
        }
    }
}

// Aktualizacja listy ostatnich logów
function updateLogsUi(logs) {
    const list = document.getElementById('logsList');
    const ts = document.getElementById('logsTimestamp');
    if (ts) {
        const d = new Date();
        ts.textContent = 'Aktualizacja: ' + d.toTimeString().split(' ')[0];
    }
    if (!list) return;

    if (!logs || logs.length === 0) {
        list.innerHTML = '<li class="log-empty">Brak wcześniejszych wpisów w logu bram.</li>';
        return;
    }

    list.innerHTML = logs.map(l => `<li class="log-item">${escapeHtml(l)}</li>`).join('');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Odpytanie o status w tle (polling)
async function refreshGateStatus() {
    try {
        const res = await fetch('brama.php?api=status&_t=' + Date.now(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            const data = await res.json();
            if (data.gates) {
                updateGateUi(data.gates);
            }
            if (data.logs) {
                updateLogsUi(data.logs);
            }
        }
    } catch (e) {
        // Cichy błąd pollingu w tle
    }
}

// Automatyczny polling co 2.5 sekundy
setInterval(refreshGateStatus, 2500);
</script>

</body>
</html>
