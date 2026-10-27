<?php
/**
 * Panel Sterowania Roletami i Przekaźnikami I2C (Apache / PHP)
 *
 * Obsługuje 2 przyciski dla każdego wiersza:
 * 1. Otwórz / Zamknij (przełącznik stanu open/close)
 * 2. Stop (natychmiastowe zatrzymanie ruchu)
 */

session_start();
require_once __DIR__ . '/config.php';

// Łączenie listy pomieszczeń (główne 8 + ewentualnie dodatkowe jeśli włączono)
$allRooms = $ROOMS;
$showExtra = isset($_GET['extra']) && $_GET['extra'] == '1';
if ($showExtra && !empty($EXTRA_ROOMS)) {
    $allRooms = array_merge($ROOMS, $EXTRA_ROOMS);
}

// =========================================================================
// Funkcje pomocnicze
// =========================================================================

/**
 * Sprawdza stan pojedynczego przekaźnika
 */
function getRelayInfo($relay) {
    $gpioDir = GPIO_DIR;
    $stateFile = rtrim($gpioDir, '/') . '/' . $relay . '.state';
    
    // Sprawdzenie czy proces script.sh dla danej rolety jest aktualnie uruchomiony w tle
    $isOpenRunning = false;
    $isCloseRunning = false;
    
    if (function_exists('shell_exec')) {
        $checkOpen = @shell_exec("ps -eo args | grep -v grep | grep " . escapeshellarg("script.sh {$relay} open"));
        $checkClose = @shell_exec("ps -eo args | grep -v grep | grep " . escapeshellarg("script.sh {$relay} close"));
        $isOpenRunning = !empty(trim((string)$checkOpen));
        $isCloseRunning = !empty(trim((string)$checkClose));
    }

    if ($isOpenRunning) {
        return ['state' => 'opening', 'label' => 'Otwieranie...', 'badge' => 'warning', 'is_open' => false];
    }
    if ($isCloseRunning) {
        return ['state' => 'closing', 'label' => 'Zamykanie...', 'badge' => 'warning', 'is_open' => true];
    }

    // Odczyt pliku .state: 1 = open, 2 = close/stop
    if (file_exists($stateFile) && is_readable($stateFile)) {
        $val = trim(@file_get_contents($stateFile));
        if ($val === '1') {
            return ['state' => 'open', 'label' => 'Otwarty', 'badge' => 'success', 'is_open' => true];
        } elseif ($val === '2') {
            return ['state' => 'closed', 'label' => 'Zamknięty', 'badge' => 'secondary', 'is_open' => false];
        }
    }

    return ['state' => 'closed', 'label' => 'Zamknięty', 'badge' => 'secondary', 'is_open' => false];
}

/**
 * Pobiera łączny stan dla całego pokoju (może składać się z kilku przekaźników)
 */
function getRoomStatus($roomData) {
    $relays = $roomData['relays'];
    $isOpening = false;
    $isClosing = false;
    $openCount = 0;
    $total = count($relays);

    foreach ($relays as $relay) {
        $info = getRelayInfo($relay);
        if ($info['state'] === 'opening') $isOpening = true;
        if ($info['state'] === 'closing') $isClosing = true;
        if ($info['is_open']) $openCount++;
    }

    if ($isOpening) {
        return [
            'state' => 'opening',
            'label' => 'Otwieranie...',
            'badge' => 'badge-warning',
            'next_action' => 'close',
            'next_label' => 'Zamknij',
            'is_moving' => true
        ];
    }
    if ($isClosing) {
        return [
            'state' => 'closing',
            'label' => 'Zamykanie...',
            'badge' => 'badge-warning',
            'next_action' => 'open',
            'next_label' => 'Otwórz',
            'is_moving' => true
        ];
    }

    if ($openCount === $total) {
        return [
            'state' => 'open',
            'label' => 'Otwarty',
            'badge' => 'badge-success',
            'next_action' => 'close',
            'next_label' => 'Zamknij',
            'is_moving' => false
        ];
    } elseif ($openCount > 0) {
        return [
            'state' => 'partial',
            'label' => "Częściowo otwarty ({$openCount}/{$total})",
            'badge' => 'badge-info',
            'next_action' => 'close',
            'next_label' => 'Zamknij',
            'is_moving' => false
        ];
    } else {
        return [
            'state' => 'closed',
            'label' => 'Zamknięty',
            'badge' => 'badge-secondary',
            'next_action' => 'open',
            'next_label' => 'Otwórz',
            'is_moving' => false
        ];
    }
}

/**
 * Wykonuje skrypt dla pojedynczego przekaźnika
 */
function runScriptCommand($relay, $action) {
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $relay)) {
        return ['success' => false, 'error' => 'Błędna nazwa przekaźnika'];
    }
    if (!in_array($action, ['open', 'close', 'stop'])) {
        return ['success' => false, 'error' => 'Błędna akcja'];
    }

    if (SIMULATION_MODE) {
        // Tryb symulacyjny (dla testów bez fizycznego I2C)
        $simStateFile = rtrim(GPIO_DIR, '/') . '/' . $relay . '.state';
        @file_put_contents($simStateFile, ($action === 'open' ? '1' : '2'));
        return ['success' => true, 'output' => "Symulacja: {$relay} -> {$action}"];
    }

    $script = SCRIPT_PATH;
    if (!file_exists($script)) {
        return ['success' => false, 'error' => "Brak pliku skryptu: {$script}"];
    }

    $gpioDir = GPIO_DIR;
    $sudoPrefix = USE_SUDO ? 'sudo ' : '';
    $envPrefix = 'GPIO_DIR=' . escapeshellarg($gpioDir) . ' ';

    if ($action === 'stop') {
        // Stop zatrzymuje silnik od razu i ubija procesy w tle
        $cmd = $envPrefix . $sudoPrefix . escapeshellcmd($script) . ' ' . escapeshellarg($relay) . ' stop 2>&1';
        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);
        
        // Zapisujemy od razu stan 2 (zatrzymany/zamknięty) w razie gdyby skrypt nie zdążył
        $stateFile = rtrim($gpioDir, '/') . '/' . $relay . '.state';
        if (is_writable($stateFile) || (!file_exists($stateFile) && is_writable($gpioDir))) {
            @file_put_contents($stateFile, "2\n");
        }

        return [
            'success' => ($returnVar === 0),
            'action' => 'stop',
            'relay' => $relay,
            'cmd' => $cmd,
            'output' => implode("\n", $output)
        ];
    } else {
        // Open oraz Close trwają po kilkanaście/kilkadziesiąt sekund (sleep $CONF_TIME).
        // Muszą działać w tle (nohup / &), aby:
        // 1) Apache nie blokował żądania HTTP
        // 2) Przycisk Stop mógł natychmiast zabić proces
        $cmd = $envPrefix . $sudoPrefix . 'nohup ' . escapeshellcmd($script) . ' ' . escapeshellarg($relay) . ' ' . escapeshellarg($action) . ' > /dev/null 2>&1 &';
        exec($cmd);

        return [
            'success' => true,
            'action' => $action,
            'relay' => $relay,
            'cmd' => $cmd,
            'output' => 'Uruchomiono w tle'
        ];
    }
}

/**
 * Wykonuje akcję dla całego pokoju
 */
function handleRoomAction($roomId, $actionRequested, $allRooms) {
    if (!isset($allRooms[$roomId])) {
        return ['success' => false, 'error' => 'Nie znaleziono pomieszczenia: ' . htmlspecialchars($roomId)];
    }

    $roomData = $allRooms[$roomId];
    $status = getRoomStatus($roomData);

    // Jeśli akcja to toggle, wybieramy przeciwną do aktualnego stanu
    $action = $actionRequested;
    if ($action === 'toggle') {
        $action = $status['next_action']; // 'open' lub 'close'
    }

    $results = [];
    $allOk = true;

    foreach ($roomData['relays'] as $relay) {
        $res = runScriptCommand($relay, $action);
        $results[] = $res;
        if (empty($res['success'])) {
            $allOk = false;
        }
        // Drobna pauza 50ms, aby uniknąć kolizji na magistrali I2C przy wysyłaniu pakietu
        if (count($roomData['relays']) > 1) {
            usleep(50000);
        }
    }

    $actionName = ($action === 'open' ? 'Otwieranie' : ($action === 'close' ? 'Zamykanie' : 'Zatrzymano'));
    return [
        'success' => $allOk,
        'action' => $action,
        'room_id' => $roomId,
        'room_name' => $roomData['name'],
        'message' => "{$actionName}: {$roomData['name']}",
        'new_status' => getRoomStatus($roomData),
        'results' => $results
    ];
}

// =========================================================================
// Obsługa API (JSON dla żądań asynchronicznych fetch/AJAX)
// =========================================================================

if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json');
    $statuses = [];
    foreach ($allRooms as $id => $room) {
        $statuses[$id] = getRoomStatus($room);
    }
    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'statuses' => $statuses
    ]);
    exit;
}

// Obsługa zdalnych wywołań przez GET (np. curl "http://SERVER/index.php?room=dach_wejscie&action=open")
if (isset($_GET['room']) && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $roomId = $_GET['room'];
    $action = $_GET['action'];

    if ($roomId === 'ALL') {
        $allSuccess = true;
        foreach ($allRooms as $rId => $rData) {
            $r = handleRoomAction($rId, $action, $allRooms);
            if (!$r['success']) $allSuccess = false;
            usleep(50000);
        }
        echo json_encode([
            'success' => $allSuccess,
            'action' => $action,
            'message' => "Wykonano akcję '{$action}' dla wszystkich pomieszczeń"
        ]);
    } else {
        echo json_encode(handleRoomAction($roomId, $action, $allRooms));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roomId = $_POST['room'] ?? '';
    $action = $_POST['action'] ?? '';
    $isCurl = isset($_SERVER['HTTP_USER_AGENT']) && stripos($_SERVER['HTTP_USER_AGENT'], 'curl') !== false;
    $isJsonAccept = isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
              || (isset($_POST['ajax']) && $_POST['ajax'] == '1')
              || $isCurl || $isJsonAccept;

    $response = [];

    if ($roomId === 'ALL') {
        // Zbiorcza akcja dla wszystkich rolet
        $allSuccess = true;
        foreach ($allRooms as $rId => $rData) {
            $r = handleRoomAction($rId, $action, $allRooms);
            if (!$r['success']) $allSuccess = false;
            usleep(50000);
        }
        $response = [
            'success' => $allSuccess,
            'action' => $action,
            'message' => "Wykonano akcję '{$action}' dla wszystkich pomieszczeń"
        ];
    } else {
        $response = handleRoomAction($roomId, $action, $allRooms);
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } else {
        $_SESSION['flash_message'] = $response['message'] ?? ($response['error'] ?? 'Wykonano akcję');
        $_SESSION['flash_type'] = !empty($response['success']) ? 'success' : 'danger';
        header("Location: index.php" . ($showExtra ? '?extra=1' : ''));
        exit;
    }
}

// Diagnostyka środowiska
$diagScriptExists = file_exists(SCRIPT_PATH);
$diagScriptExec = is_executable(SCRIPT_PATH);
$diagGpioDirExists = is_dir(GPIO_DIR);
$diagGpioDirWritable = is_writable(GPIO_DIR);
$currentUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sterowanie Roletami</title>
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
            --indigo-hover: #4f46e5;
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
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.02em;
        }

        .header-title p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .global-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-global {
            padding: 8px 14px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: #2a374a;
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-global:hover {
            background: #33435c;
            transform: translateY(-1px);
        }

        .btn-global.danger:hover {
            background: var(--danger);
            border-color: var(--danger);
        }

        /* Powiadomienia Flash & Toasty */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 380px;
            pointer-events: none;
        }

        .toast {
            background: #1e293b;
            color: #fff;
            padding: 12px 18px;
            border-radius: var(--radius-md);
            border-left: 4px solid var(--primary);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
            font-size: 0.9rem;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            pointer-events: auto;
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .toast.success { border-left-color: var(--success); }
        .toast.danger { border-left-color: var(--danger); }
        .toast.warning { border-left-color: var(--warning); }

        /* Lista wierszy */
        .room-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .room-row {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: var(--shadow);
            transition: transform 0.15s ease, background-color 0.15s ease;
        }

        .room-row:hover {
            background-color: var(--bg-card-hover);
        }

        /* Lewa kolumna: Informacja o pokoju */
        .room-info {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1 1 240px;
            min-width: 0;
        }

        .room-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #2a384f;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #93c5fd;
        }

        .room-icon-box svg {
            width: 22px;
            height: 22px;
            fill: currentColor;
        }

        .room-details {
            min-width: 0;
        }

        .room-name-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .room-name {
            font-size: 1.05rem;
            font-weight: 600;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .room-desc {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Status Badge */
        .badge {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
        }

        .badge-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }

        .badge-secondary {
            background: rgba(148, 163, 184, 0.15);
            color: #cbd5e1;
        }
        .badge-secondary .badge-dot { background: #94a3b8; }

        .badge-success {
            background: rgba(16, 185, 129, 0.18);
            color: #6ee7b7;
        }
        .badge-success .badge-dot { background: #10b981; }

        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        .badge-warning .badge-dot {
            background: #f59e0b;
            animation: pulse 1.2s infinite ease-in-out;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        .badge-info .badge-dot { background: #3b82f6; }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.4); opacity: 0.4; }
        }

        /* Prawa kolumna: DWA PRZYCISKI */
        .room-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .btn {
            appearance: none;
            border: none;
            outline: none;
            font-size: 0.92rem;
            font-weight: 600;
            padding: 10px 18px;
            min-height: 44px;
            border-radius: var(--radius-md);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
            text-decoration: none;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn:active:not(:disabled) {
            transform: scale(0.97);
        }

        .btn svg {
            width: 17px;
            height: 17px;
            fill: currentColor;
            flex-shrink: 0;
        }

        /* PRZYCISK 1: Otwórz / Zamknij (Główny Toggle) */
        .btn-toggle {
            min-width: 165px;
            background: #2563eb;
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .btn-toggle:hover:not(:disabled) {
            background: #1d4ed8;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        /* Gdy następna akcja to Zamknij (czyli roleta jest otwarta) */
        .btn-toggle.mode-close {
            background: #4f46e5;
        }
        .btn-toggle.mode-close:hover:not(:disabled) {
            background: #4338ca;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
        }

        /* Gdy w ruchu */
        .btn-toggle.moving {
            background: #b45309;
            color: #fef3c7;
        }

        /* PRZYCISK 2: Stop */
        .btn-stop {
            min-width: 95px;
            background: #ef4444;
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .btn-stop:hover:not(:disabled) {
            background: #dc2626;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        /* Spinner ładowania w przycisku */
        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Stopka i diagnostyka */
        footer {
            margin-top: 30px;
            padding: 18px 20px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .diag-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .diag-item {
            background: #151f30;
            padding: 8px 12px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .diag-item .status-ok { color: var(--success); font-weight: 600; }
        .diag-item .status-err { color: var(--danger); font-weight: 600; }

        .extra-toggle {
            display: inline-block;
            margin-top: 10px;
            color: var(--primary);
            text-decoration: none;
            font-size: 0.82rem;
            cursor: pointer;
        }
        .extra-toggle:hover { text-decoration: underline; }

        /* Responsywność na telefonach */
        @media (max-width: 640px) {
            body { padding: 10px 8px 40px 8px; }
            header { padding: 14px; }
            .header-title h1 { font-size: 1.2rem; }
            .room-row {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                padding: 12px;
            }
            .room-info {
                justify-content: flex-start;
                flex: none;
            }
            .room-actions {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 8px;
            }
            .btn-toggle, .btn-stop {
                min-width: 0;
                width: 100%;
                padding: 11px 8px;
                font-size: 0.88rem;
            }
        }
    </style>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<div class="container">
    <header>
        <div class="header-title">
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #60a5fa;"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg>
                Sterowanie Roletami
            </h1>
            <p>Kontroler I2C PCA9685 • Linux Apache</p>
        </div>
        <div class="global-actions">
            <button type="button" class="btn-global" onclick="triggerAction('ALL', 'open')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg>
                Otwórz wszystkie
            </button>
            <button type="button" class="btn-global" onclick="triggerAction('ALL', 'close')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                Zamknij wszystkie
            </button>
            <button type="button" class="btn-global danger" onclick="triggerAction('ALL', 'stop')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                Stop wszystkie
            </button>
        </div>
    </header>

    <?php if (!empty($_SESSION['flash_message'])): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                showToast("<?= htmlspecialchars(addslashes($_SESSION['flash_message'])) ?>", "<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>");
            });
        </script>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- Lista wierszy (Każdy wiersz ma dokładnie 2 przyciski) -->
    <main class="room-list">
        <?php foreach ($allRooms as $roomId => $room): ?>
            <?php 
                $st = getRoomStatus($room); 
                $isMoving = !empty($st['is_moving']);
                $nextAct = $st['next_action']; // 'open' lub 'close'
                $isCloseNext = ($nextAct === 'close');
            ?>
            <div class="room-row" id="row-<?= htmlspecialchars($roomId) ?>">
                <!-- Kolumna 1: Informacje o pomieszczeniu -->
                <div class="room-info">
                    <div class="room-icon-box">
                        <?php if ($room['icon'] === 'kitchen'): ?>
                            <!-- Kuchnia -->
                            <svg viewBox="0 0 24 24"><path d="M18 4v16M6 4v7a3 3 0 0 0 3 3v6M10 4v4M8 4v4M12 4v7a3 3 0 0 1-3 3"/></svg>
                        <?php elseif ($room['icon'] === 'living_room'): ?>
                            <!-- Duży pokój -->
                            <svg viewBox="0 0 24 24"><path d="M2 13v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6M4 13V8a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v5M2 13h20"/></svg>
                        <?php elseif ($room['icon'] === 'sunrise'): ?>
                            <!-- Wschód -->
                            <svg viewBox="0 0 24 24"><path d="M12 2v6M4.93 10.93l4.24-4.24M2 18h20M20 18a8 8 0 0 0-16 0"/></svg>
                        <?php elseif ($room['icon'] === 'sun'): ?>
                            <!-- Południe -->
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                        <?php elseif ($room['icon'] === 'sunset'): ?>
                            <!-- Zachód -->
                            <svg viewBox="0 0 24 24"><path d="M12 10V4M4.93 10.93l4.24-4.24M2 18h20M20 18a8 8 0 0 0-16 0M16 10l-4 4-4-4"/></svg>
                        <?php elseif ($room['icon'] === 'roof'): ?>
                            <!-- Dach -->
                            <svg viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <?php elseif ($room['icon'] === 'door'): ?>
                            <!-- Drzwi / Wejście -->
                            <svg viewBox="0 0 24 24"><path d="M18 20V6a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v14M4 20h16M14 12v.01"/></svg>
                        <?php else: ?>
                            <!-- Domyślna ikona okna -->
                            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 12h18M12 3v18"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="room-details">
                        <div class="room-name-wrapper">
                            <span class="room-name"><?= htmlspecialchars($room['name']) ?></span>
                            <span class="badge <?= $st['badge'] ?>" id="badge-<?= htmlspecialchars($roomId) ?>">
                                <span class="badge-dot"></span>
                                <span class="badge-text"><?= htmlspecialchars($st['label']) ?></span>
                            </span>
                        </div>
                        <div class="room-desc"><?= htmlspecialchars($room['desc']) ?></div>
                    </div>
                </div>

                <!-- Kolumna 2: Dokładnie DWA przyciski w wierszu -->
                <div class="room-actions">
                    <form method="POST" action="index.php" style="display:inline;" onsubmit="return handleFormSubmit(event, '<?= htmlspecialchars($roomId) ?>', 'toggle')">
                        <input type="hidden" name="room" value="<?= htmlspecialchars($roomId) ?>">
                        <input type="hidden" name="action" value="toggle" id="action-<?= htmlspecialchars($roomId) ?>">
                        
                        <!-- PRZYCISK 1: Otwórz / Zamknij -->
                        <button type="submit" 
                                class="btn btn-toggle <?= $isCloseNext ? 'mode-close' : '' ?> <?= $isMoving ? 'moving' : '' ?>" 
                                id="btn-toggle-<?= htmlspecialchars($roomId) ?>"
                                title="Kliknij, aby przełączyć: Otwórz / Zamknij">
                            <span class="btn-icon" id="icon-toggle-<?= htmlspecialchars($roomId) ?>">
                                <?php if ($isMoving): ?>
                                    <div class="spinner"></div>
                                <?php elseif ($isCloseNext): ?>
                                    <!-- Ikona zamykania w dół -->
                                    <svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                                <?php else: ?>
                                    <!-- Ikona otwierania w górę -->
                                    <svg viewBox="0 0 24 24"><path d="M18 15l-6-6-6 6"/></svg>
                                <?php endif; ?>
                            </span>
                            <span class="btn-text" id="text-toggle-<?= htmlspecialchars($roomId) ?>">
                                <?php if ($isMoving): ?>
                                    <?= $st['state'] === 'opening' ? 'Otwieranie...' : 'Zamykanie...' ?>
                                <?php elseif ($isCloseNext): ?>
                                    Zamknij
                                <?php else: ?>
                                    Otwórz
                                <?php endif; ?>
                            </span>
                        </button>
                    </form>

                    <form method="POST" action="index.php" style="display:inline;" onsubmit="return handleFormSubmit(event, '<?= htmlspecialchars($roomId) ?>', 'stop')">
                        <input type="hidden" name="room" value="<?= htmlspecialchars($roomId) ?>">
                        <input type="hidden" name="action" value="stop">
                        
                        <!-- PRZYCISK 2: Stop -->
                        <button type="submit" 
                                class="btn btn-stop" 
                                id="btn-stop-<?= htmlspecialchars($roomId) ?>"
                                title="Natychmiast zatrzymaj ruch rolety">
                            <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                            <span>Stop</span>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </main>

    <!-- Stopka diagnostyczna -->
    <footer>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <strong>Diagnostyka serwera i uprawnień:</strong>
            <?php if (!$showExtra && !empty($EXTRA_ROOMS)): ?>
                <a href="index.php?extra=1" class="extra-toggle">+ Pokaż pozostałe rolety (bramy, parter, składzik)</a>
            <?php elseif ($showExtra): ?>
                <a href="index.php" class="extra-toggle">Ukryj dodatkowe rolety</a>
            <?php endif; ?>
        </div>
        <div class="diag-grid">
            <div class="diag-item">
                <span>Skrypt (script.sh):</span>
                <span class="<?= $diagScriptExists && $diagScriptExec ? 'status-ok' : 'status-err' ?>">
                    <?= $diagScriptExists ? ($diagScriptExec ? 'Wykonywalny' : 'Brak +x') : 'Nie znaleziono' ?>
                </span>
            </div>
            <div class="diag-item">
                <span>Folder GPIO (.state):</span>
                <span class="<?= $diagGpioDirExists && $diagGpioDirWritable ? 'status-ok' : 'status-err' ?>">
                    <?= $diagGpioDirExists ? ($diagGpioDirWritable ? 'Zapisywalny' : 'Tylko odczyt') : 'Brak folderu' ?>
                </span>
            </div>
            <div class="diag-item">
                <span>Użytkownik Apache:</span>
                <span style="color:#93c5fd;"><?= htmlspecialchars($currentUser) ?></span>
            </div>
            <div class="diag-item">
                <span>Tryb Sudo:</span>
                <span><?= USE_SUDO ? 'Włączony' : 'Wyłączony' ?></span>
            </div>
        </div>
    </footer>
</div>

<script>
    // System powiadomień Toast
    function showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = message;
        container.appendChild(toast);
        
        // Animacja wejścia
        setTimeout(() => toast.classList.add('show'), 20);
        
        // Usunięcie po 3.5s
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 350);
        }, 3500);
    }

    // Obsługa formularza przez fetch (AJAX) - bez przeładowania strony
    async function handleFormSubmit(event, roomId, action) {
        event.preventDefault();
        await triggerAction(roomId, action);
        return false;
    }

    // Wykonanie akcji
    async function triggerAction(roomId, action) {
        const btnToggle = document.getElementById(`btn-toggle-${roomId}`);
        const btnStop = document.getElementById(`btn-stop-${roomId}`);

        if (roomId !== 'ALL' && btnToggle) {
            btnToggle.disabled = true;
        }
        if (roomId !== 'ALL' && btnStop) {
            btnStop.disabled = true;
        }

        try {
            const formData = new FormData();
            formData.append('room', roomId);
            formData.append('action', action);
            formData.append('ajax', '1');

            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showToast(data.message || 'Polecenie wysłane pomyślnie', 'success');
                // Jeśli serwer zwrócił nowy status dla pokoju, aktualizujemy widok od razu
                if (data.new_status && roomId !== 'ALL') {
                    updateRoomUI(roomId, data.new_status);
                }
            } else {
                showToast(data.error || 'Wystąpił błąd podczas wykonywania polecenia', 'danger');
            }
        } catch (err) {
            console.error('Błąd połączenia:', err);
            showToast('Błąd połączenia z serwerem Apache: ' + err.message, 'danger');
        } finally {
            if (roomId !== 'ALL' && btnToggle) btnToggle.disabled = false;
            if (roomId !== 'ALL' && btnStop) btnStop.disabled = false;
            // Odpytanie o stan po 1s
            setTimeout(pollStatus, 800);
        }
    }

    // Aktualizacja pojedynczego wiersza w DOM
    function updateRoomUI(roomId, st) {
        const badge = document.getElementById(`badge-${roomId}`);
        const btnToggle = document.getElementById(`btn-toggle-${roomId}`);
        const iconToggle = document.getElementById(`icon-toggle-${roomId}`);
        const textToggle = document.getElementById(`text-toggle-${roomId}`);

        if (!badge || !btnToggle) return;

        // Aktualizacja Badge
        badge.className = `badge ${st.badge}`;
        const badgeText = badge.querySelector('.badge-text');
        if (badgeText) badgeText.textContent = st.label;

        // Aktualizacja Przycisku Otwórz / Zamknij
        const isMoving = !!st.is_moving;
        const isCloseNext = (st.next_action === 'close');

        btnToggle.className = `btn btn-toggle ${isCloseNext ? 'mode-close' : ''} ${isMoving ? 'moving' : ''}`;
        
        if (textToggle) {
            if (isMoving) {
                textToggle.textContent = (st.state === 'opening') ? 'Otwieranie...' : 'Zamykanie...';
            } else if (isCloseNext) {
                textToggle.textContent = 'Zamknij';
            } else {
                textToggle.textContent = 'Otwórz';
            }
        }

        if (iconToggle) {
            if (isMoving) {
                iconToggle.innerHTML = '<div class="spinner"></div>';
            } else if (isCloseNext) {
                iconToggle.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>';
            } else {
                iconToggle.innerHTML = '<svg viewBox="0 0 24 24"><path d="M18 15l-6-6-6 6"/></svg>';
            }
        }
    }

    // Automatyczne odpytywanie o stan co 2.5 sekundy
    async function pollStatus() {
        try {
            const res = await fetch('index.php?api=status');
            if (!res.ok) return;
            const data = await res.json();
            if (data && data.statuses) {
                for (const [rId, st] of Object.entries(data.statuses)) {
                    updateRoomUI(rId, st);
                }
            }
        } catch (e) {
            // Cichy błąd pollingu, ponowi próbę w kolejnym cyklu
        }
    }

    // Uruchomienie cyklicznego pollingu
    setInterval(pollStatus, 2500);
</script>
</body>
</html>
