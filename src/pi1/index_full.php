<?php
/**
 * Panel Sterowania Roletami - Widok Wszystkich Przekaźników (PI1)
 *
 * Wyświetla każdy z 16 fizycznych przekaźników z relay_setup.conf z osobna:
 * - Piętro 1 (8 przekaźników: Wschód lewe/prawe, Południe lewe/środkowe/prawe, Zachód lewe/prawe/balkon)
 * - Piwnica (5 przekaźników: Kuchnia lewe/prawe, Prasowalnia, Składzik, Wejście)
 * - Dach (1 przekaźnik: Okno dachowe)
 * - Bramy (2 przekaźniki: Brama wjazdowa, Brama garażowa)
 */

session_start();
require_once __DIR__ . '/config.php';

// Ładowanie listy 16 pojedynczych przekaźników
$allRooms = $INDIVIDUAL_ROOMS;
$showExtra = isset($_GET['extra']) && $_GET['extra'] == '1';
if ($showExtra && !empty($EXTRA_ROOMS)) {
    $allRooms = array_merge($INDIVIDUAL_ROOMS, $EXTRA_ROOMS);
}

// =========================================================================
// Funkcje pomocnicze
// =========================================================================

/**
 * Pobiera listę aktywnych procesów skryptów script.sh / script_one.sh.
 * Wynik jest cachowany w pamięci na czas trwania bieżącego żądania PHP.
 * Bezpośredni odczyt z /proc (błyskawiczny, ~50-80ms na Raspberry Pi 1, 0 forków procesu),
 * a w razie potrzeby pojedyncze wywołanie ps jako fallback.
 */
function getActiveScriptProcesses() {
    static $cachedProcesses = null;
    if ($cachedProcesses !== null) {
        return $cachedProcesses;
    }

    $cachedProcesses = [];

    // 1. Bezpośredni odczyt z /proc (brak spawn'owania procesów bash/ps/grep)
    $procFiles = @glob('/proc/[0-9]*/cmdline');
    if (!empty($procFiles)) {
        foreach ($procFiles as $cmdFile) {
            $cmd = @file_get_contents($cmdFile);
            if ($cmd !== false && (strpos($cmd, 'script.sh') !== false || strpos($cmd, 'script_one.sh') !== false)) {
                $cachedProcesses[] = str_replace("\0", ' ', $cmd);
            }
        }
        return $cachedProcesses;
    }

    // 2. Fallback - jedno wywołanie ps na całe żądanie (zamiast kilkudziesięciu osobnych)
    if (function_exists('shell_exec')) {
        $output = @shell_exec("ps -eo args 2>/dev/null | grep -E 'script(_one)?\\.sh' | grep -v grep");
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
 * Sprawdza stan pojedynczego przekaźnika
 */
function getRelayInfo($relay) {
    $gpioDir = GPIO_DIR;
    $stateFile = rtrim($gpioDir, '/') . '/' . $relay . '.state';
    
    $isOpenRunning = false;
    $isCloseRunning = false;
    
    $activeProcesses = getActiveScriptProcesses();
    if (!empty($activeProcesses)) {
        foreach ($activeProcesses as $cmd) {
            if (strpos($cmd, " {$relay} open") !== false) {
                $isOpenRunning = true;
                break;
            } elseif (strpos($cmd, " {$relay} close") !== false) {
                $isCloseRunning = true;
                break;
            }
        }
    }

    if ($isOpenRunning) {
        return ['state' => 'opening', 'label' => 'Otwieranie...', 'badge' => 'badge-warning', 'is_open' => false];
    }
    if ($isCloseRunning) {
        return ['state' => 'closing', 'label' => 'Zamykanie...', 'badge' => 'badge-warning', 'is_open' => true];
    }

    if (file_exists($stateFile) && is_readable($stateFile)) {
        $val = trim(@file_get_contents($stateFile));
        if ($val === '1') {
            return ['state' => 'open', 'label' => 'Otwarty', 'badge' => 'badge-success', 'is_open' => true];
        } elseif ($val === '2') {
            return ['state' => 'closed', 'label' => 'Zamknięty', 'badge' => 'badge-secondary', 'is_open' => false];
        } elseif ($val === '0') {
            return ['state' => 'moving', 'label' => 'Impuls/Aktywny', 'badge' => 'badge-warning', 'is_open' => true];
        }
    }

    return ['state' => 'closed', 'label' => 'Zamknięty', 'badge' => 'badge-secondary', 'is_open' => false];
}

/**
 * Pobiera stan dla danej pozycji (składa się z 1 przekaźnika)
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
function runScriptCommand($relay, $action, $time = null) {
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $relay)) {
        return ['success' => false, 'error' => 'Błędna nazwa przekaźnika'];
    }
    if (!in_array($action, ['open', 'close', 'stop'])) {
        return ['success' => false, 'error' => 'Błędna akcja'];
    }
    $timeVal = ($time !== null && is_numeric($time) && (int)$time > 0) ? (int)$time : null;

    if (SIMULATION_MODE) {
        $simStateFile = rtrim(GPIO_DIR, '/') . '/' . $relay . '.state';
        @file_put_contents($simStateFile, ($action === 'open' ? '1' : '2'));
        return ['success' => true, 'output' => "Symulacja: {$relay} -> {$action}" . ($timeVal ? " (czas: {$timeVal}s)" : '')];
    }

    $isBrama = (strpos($relay, 'brama') !== false);
    $script = ($isBrama && defined('SCRIPT_ONE_PATH') && file_exists(SCRIPT_ONE_PATH)) ? SCRIPT_ONE_PATH : SCRIPT_PATH;

    if (!file_exists($script)) {
        return ['success' => false, 'error' => "Brak pliku skryptu: {$script}"];
    }

    $gpioDir = GPIO_DIR;
    $sudoPrefix = USE_SUDO ? 'sudo ' : '';
    $envPrefix = 'GPIO_DIR=' . escapeshellarg($gpioDir) . ' ';

    if ($action === 'stop') {
        $cmd = $envPrefix . $sudoPrefix . escapeshellcmd($script) . ' ' . escapeshellarg($relay) . ' stop 2>&1';
        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);
        
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
        $timeArg = ($timeVal !== null) ? ' ' . escapeshellarg((string)$timeVal) : '';
        $cmd = $envPrefix . $sudoPrefix . 'nohup ' . escapeshellcmd($script) . ' ' . escapeshellarg($relay) . ' ' . escapeshellarg($action) . $timeArg . ' > /dev/null 2>&1 &';
        exec($cmd);

        return [
            'success' => true,
            'action' => $action,
            'time' => $timeVal,
            'relay' => $relay,
            'cmd' => $cmd,
            'output' => 'Uruchomiono w tle'
        ];
    }
}

/**
 * Wykonuje akcję dla pojedynczego przekaźnika
 */
function handleRoomAction($roomId, $actionRequested, $allRooms, $time = null) {
    if (!isset($allRooms[$roomId])) {
        return ['success' => false, 'error' => 'Nie znaleziono przekaźnika: ' . htmlspecialchars($roomId)];
    }

    $roomData = $allRooms[$roomId];
    $timeVal = ($time !== null && is_numeric($time) && (int)$time > 0) ? (int)$time : null;

    $action = $actionRequested;
    if ($action === 'toggle') {
        $status = getRoomStatus($roomData);
        $action = $status['next_action'];
    }

    $results = [];
    $allOk = true;

    foreach ($roomData['relays'] as $relay) {
        $res = runScriptCommand($relay, $action, $timeVal);
        $results[] = $res;
        if (empty($res['success'])) {
            $allOk = false;
        }
        if (count($roomData['relays']) > 1) {
            usleep(50000);
        }
    }

    // Natychmiastowe ustalenie nowego statusu bez ponownego skanowania procesów
    if ($action === 'open') {
        $newStatus = [
            'state' => 'opening',
            'label' => 'Otwieranie...',
            'badge' => 'badge-warning',
            'next_action' => 'close',
            'next_label' => 'Zamknij',
            'is_moving' => true
        ];
    } elseif ($action === 'close') {
        $newStatus = [
            'state' => 'closing',
            'label' => 'Zamykanie...',
            'badge' => 'badge-warning',
            'next_action' => 'open',
            'next_label' => 'Otwórz',
            'is_moving' => true
        ];
    } else {
        $newStatus = [
            'state' => 'closed',
            'label' => 'Zatrzymano',
            'badge' => 'badge-secondary',
            'next_action' => 'open',
            'next_label' => 'Otwórz',
            'is_moving' => false
        ];
    }

    $actionName = ($action === 'open' ? 'Otwieranie' : ($action === 'close' ? 'Zamykanie' : 'Zatrzymano'));
    $timeDesc = ($timeVal !== null && $action !== 'stop') ? " ({$timeVal}s)" : "";
    return [
        'success' => $allOk,
        'action' => $action,
        'time' => $timeVal,
        'room_id' => $roomId,
        'room_name' => $roomData['name'],
        'message' => "{$actionName}{$timeDesc}: {$roomData['name']}",
        'new_status' => $newStatus,
        'results' => $results
    ];
}

/**
 * Zwraca grupę pokoi na podstawie identyfikatora zbiorczego
 */
function getTargetRooms($target, $allRooms) {
    if ($target === 'ALL') {
        return ['rooms' => $allRooms, 'desc' => 'wszystkich 16 przekaźników (PI1)'];
    }
    if ($target === 'FLOOR_PIETRO_1' || $target === 'pietro_1') {
        $subset = array_filter($allRooms, fn($r) => ($r['floor'] ?? '') === 'pietro_1');
        return ['rooms' => $subset, 'desc' => 'Piętra 1 (Salonu)'];
    }
    if ($target === 'FLOOR_PIWNICA' || $target === 'piwnica') {
        $subset = array_filter($allRooms, fn($r) => ($r['floor'] ?? '') === 'piwnica');
        return ['rooms' => $subset, 'desc' => 'Piwnicy'];
    }
    if ($target === 'FLOOR_DACH' || $target === 'dach') {
        $subset = array_filter($allRooms, fn($r) => ($r['floor'] ?? '') === 'dach');
        return ['rooms' => $subset, 'desc' => 'Dachu'];
    }
    if ($target === 'FLOOR_BRAMY' || $target === 'bramy') {
        $subset = array_filter($allRooms, fn($r) => ($r['floor'] ?? '') === 'bramy');
        return ['rooms' => $subset, 'desc' => 'Bram'];
    }
    return null;
}

// =========================================================================
// API i POST
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

// Pobranie opcjonalnego parametru czasu (np. ?time=11 lub POST time=11)
$rawTime = $_GET['time'] ?? $_POST['time'] ?? $_GET['duration'] ?? $_POST['duration'] ?? $_GET['t'] ?? $_POST['t'] ?? null;
$requestedTime = ($rawTime !== null && is_numeric($rawTime) && (int)$rawTime > 0) ? (int)$rawTime : null;

if (isset($_GET['room']) && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $roomId = $_GET['room'];
    $action = $_GET['action'];

    $groupTarget = getTargetRooms($roomId, $allRooms);
    if ($groupTarget !== null) {
        $allSuccess = true;
        foreach ($groupTarget['rooms'] as $rId => $rData) {
            $r = handleRoomAction($rId, $action, $allRooms, $requestedTime);
            if (!$r['success']) $allSuccess = false;
            usleep(50000);
        }
        $timeMsg = ($requestedTime !== null) ? " ({$requestedTime}s)" : "";
        echo json_encode([
            'success' => $allSuccess,
            'action' => $action,
            'time' => $requestedTime,
            'message' => "Wykonano akcję '{$action}'{$timeMsg} dla {$groupTarget['desc']}"
        ]);
    } else {
        echo json_encode(handleRoomAction($roomId, $action, $allRooms, $requestedTime));
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

    $groupTarget = getTargetRooms($roomId, $allRooms);
    if ($groupTarget !== null) {
        $allSuccess = true;
        foreach ($groupTarget['rooms'] as $rId => $rData) {
            $r = handleRoomAction($rId, $action, $allRooms, $requestedTime);
            if (!$r['success']) $allSuccess = false;
            usleep(50000);
        }
        $timeMsg = ($requestedTime !== null) ? " ({$requestedTime}s)" : "";
        $response = [
            'success' => $allSuccess,
            'action' => $action,
            'time' => $requestedTime,
            'message' => "Wykonano akcję '{$action}'{$timeMsg} dla {$groupTarget['desc']}"
        ];
    } else {
        $response = handleRoomAction($roomId, $action, $allRooms, $requestedTime);
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } else {
        $_SESSION['flash_message'] = $response['message'] ?? ($response['error'] ?? 'Wykonano akcję');
        $_SESSION['flash_type'] = !empty($response['success']) ? 'success' : 'danger';
        header("Location: index_full.php" . ($showExtra ? '?extra=1' : ''));
        exit;
    }
}

// Diagnostyka
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
    <title>Sterowanie Roletami PI1 - Wszystkie przekaźniki</title>
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

        /* Pasek linków zewnętrznych */
        .server-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            padding: 4px 6px;
        }

        .server-link {
            font-size: 0.78rem;
            color: #94a3b8;
            text-decoration: none;
            padding: 4px 10px;
            border-radius: 6px;
            background: rgba(30, 41, 59, 0.4);
            border: 1px solid rgba(51, 65, 85, 0.5);
            transition: all 0.15s ease;
        }

        .server-link:hover {
            color: #ffffff;
            background: rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }

        .server-link.active-host {
            color: #93c5fd;
            border-color: rgba(59, 130, 246, 0.4);
            font-weight: 600;
        }

        /* Nagłówek */
        header {
            margin-bottom: 16px;
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
            background: #182234;
            border: 1px solid var(--border-color);
            color: var(--text-main);
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

        .server-nav-link svg {
            width: 15px;
            height: 15px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            flex-shrink: 0;
        }

        .server-nav-link .badge-ip {
            background: rgba(255, 255, 255, 0.07);
            color: var(--text-muted);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .server-nav-link:hover .badge-ip {
            color: #93c5fd;
            background: rgba(59, 130, 246, 0.15);
        }

        /* Pasek nawigacji między widokami */
        .view-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            background: var(--bg-card);
            padding: 8px 12px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .nav-tabs-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .nav-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: var(--radius-md);
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 600;
            transition: all 0.15s ease;
        }

        .nav-tab svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
        }

        .nav-tab:hover {
            color: var(--text-main);
            background: var(--bg-card-hover);
        }

        .nav-tab.active {
            color: #ffffff;
            background: var(--primary);
        }

        .view-badge-info {
            font-size: 0.8rem;
            color: var(--text-muted);
            padding: 4px 8px;
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

        /* Kondygnacje / Sekcje */
        .floor-section {
            margin-bottom: 24px;
        }

        .floor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
            padding: 10px 14px;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
        }

        .floor-title-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .floor-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .floor-icon svg {
            width: 20px;
            height: 20px;
            stroke-width: 2;
        }

        .floor-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: -0.01em;
            line-height: 1.2;
        }

        .floor-subtitle {
            font-size: 0.78rem;
            color: var(--text-muted);
            display: block;
        }

        .floor-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-floor {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 11px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-floor:hover {
            background: var(--bg-card-hover);
            border-color: #475569;
        }

        .btn-floor svg {
            width: 12px;
            height: 12px;
        }

        .btn-floor.danger {
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.3);
            background: rgba(239, 68, 68, 0.1);
        }

        .btn-floor.danger:hover {
            background: rgba(239, 68, 68, 0.2);
            border-color: var(--danger);
        }

        /* Lista wierszy */
        .room-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .room-row {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 12px 18px;
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

        .room-info {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1 1 240px;
            min-width: 0;
        }

        .room-icon-box {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #2a384f;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #93c5fd;
        }

        .room-icon-box svg {
            width: 20px;
            height: 20px;
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
            font-size: 0.98rem;
            font-weight: 600;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .relay-id-tag {
            font-family: monospace;
            font-size: 0.7rem;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid #334155;
            color: #94a3b8;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .room-desc {
            font-size: 0.74rem;
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
            font-size: 0.88rem;
            font-weight: 600;
            padding: 9px 16px;
            min-height: 40px;
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
            width: 15px;
            height: 15px;
            fill: currentColor;
            flex-shrink: 0;
        }

        .btn-toggle {
            min-width: 150px;
            background: #2563eb;
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .btn-toggle:hover:not(:disabled) {
            background: #1d4ed8;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .btn-toggle.mode-close {
            background: #4f46e5;
        }
        .btn-toggle.mode-close:hover:not(:disabled) {
            background: #4338ca;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
        }

        .btn-toggle.moving {
            background: #b45309;
            color: #fef3c7;
        }

        .btn-stop {
            min-width: 85px;
            background: #ef4444;
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .btn-stop:hover:not(:disabled) {
            background: #dc2626;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .spinner {
            width: 15px;
            height: 15px;
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
    <!-- Nawigacja multi-server -->
    <div class="server-links">
        <span class="server-link active-host">PI1 (Rolety: Salon / Piwnica / Dach / Bramy)</span>
        <a href="wentylacja.php" class="server-link">Wentylacja (10.0.0.8 / 10.0.0.9) &rarr;</a>
        <a href="http://10.0.0.118/" class="server-link">PI0 (Kotłownia: Piec CWU / CO / Pompa) &rarr;</a>
        <a href="http://10.0.0.5/" class="server-link">PI2 (Rolety: Piętro 2 / Parter) &rarr;</a>
        <a href="http://10.0.0.2/" class="server-link">Cubie (Dom / Piece) &rarr;</a>
    </div>

    <header>
        <div class="header-title">
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #60a5fa;"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Wszystkie Przekaźniki PI1
            </h1>
            <p>16 fizycznych linii relay_setup.conf • Kontroler I2C PCA9685</p>
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

    <!-- Belka przełączania serwerów (PI1, PI2, Cubie) -->
    <nav class="server-nav">
        <div class="server-nav-label">
            <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <span>Serwery:</span>
        </div>
        <div class="server-nav-links">
            <a href="http://10.0.0.118/" class="server-nav-link" title="Przejdź do sterowania piecem, CWU i pompą na serwerze PI0">
                <svg viewBox="0 0 24 24"><path d="M12 2c1.1 0 2 .9 2 2 0 .74-.4 1.38-1 1.72v1.56a5 5 0 0 1 4 4.9v2.82l1.7 1.7A1 1 0 0 1 18 18H6a1 1 0 0 1-.7-1.7l1.7-1.7v-2.82a5 5 0 0 1 4-4.9V5.72C10.4 5.38 10 4.74 10 4c0-1.1.9-2 2-2z"/><path d="M12 9a3 3 0 0 0-3 3v3h6v-3a3 3 0 0 0-3-3z"/></svg>
                <span>PI0: Kotłownia (Piec CWU / CO / Pompa)</span>
                <span class="badge-ip">10.0.0.118</span>
            </a>
            <a href="http://10.0.0.5/" class="server-nav-link" title="Przejdź do sterowania roletami Piętro 2 / Parter na serwerze PI2">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>PI2: Rolety Piętro 2 / Parter</span>
                <span class="badge-ip">10.0.0.5</span>
            </a>
            <a href="http://10.0.0.2/" class="server-nav-link" title="Przejdź do sterowania piecem, CWU, pompami i rekuperacją na serwerze Cubie">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Cubie: Kotłownia / Wentylacja</span>
                <span class="badge-ip">10.0.0.2</span>
            </a>
            <a href="wentylacja.php" class="server-nav-link" title="Przejdź do sterowania wentylacją (10.0.0.8, 10.0.0.9)">
                <svg viewBox="0 0 24 24"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                <span>Wentylacja (2x)</span>
                <span class="badge-ip">10.0.0.8 &bull; 10.0.0.9</span>
            </a>
        </div>
    </nav>

    <nav class="view-nav">
        <div class="nav-tabs-group">
            <a href="index.php" class="nav-tab">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Pokoje (10)
            </a>
            <a href="index_full.php" class="nav-tab active">
                <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Wszystkie przekaźniki (16)
            </a>
            <a href="wentylacja.php" class="nav-tab">
                <svg viewBox="0 0 24 24"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                Wentylacja (2)
            </a>
        </div>
        <div class="view-badge-info">
            Widok szczegółowy: 16 niezależnych przekaźników
        </div>
    </nav>

    <?php if (!empty($_SESSION['flash_message'])): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                showToast("<?= htmlspecialchars(addslashes($_SESSION['flash_message'])) ?>", "<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>");
            });
        </script>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- Grupowanie przekaźników wg sekcji -->
    <?php
    $groupedRooms = [];
    foreach ($allRooms as $rId => $rData) {
        $fl = $rData['floor'] ?? 'inne';
        $groupedRooms[$fl][$rId] = $rData;
    }
    $floorOrder = [];
    if (isset($groupedRooms['pietro_1'])) $floorOrder[] = 'pietro_1';
    if (isset($groupedRooms['piwnica'])) $floorOrder[] = 'piwnica';
    if (isset($groupedRooms['dach'])) $floorOrder[] = 'dach';
    if (isset($groupedRooms['bramy'])) $floorOrder[] = 'bramy';
    foreach ($groupedRooms as $fl => $items) {
        if (!in_array($fl, $floorOrder)) $floorOrder[] = $fl;
    }
    ?>

    <main>
        <?php foreach ($floorOrder as $floorKey): ?>
            <?php 
                $roomsInFloor = $groupedRooms[$floorKey] ?? [];
                if (empty($roomsInFloor)) continue;
                $floorMeta = $FLOORS[$floorKey] ?? [
                    'name' => ucfirst($floorKey),
                    'desc_full' => count($roomsInFloor) . ' pojedynczych przekaźników',
                    'icon' => 'home'
                ];
            ?>
            <section class="floor-section">
                <div class="floor-header">
                    <div class="floor-title-box">
                        <div class="floor-icon">
                            <?php if ($floorKey === 'pietro_1'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 13v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6M4 13V8a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v5M2 13h20"/></svg>
                            <?php elseif ($floorKey === 'piwnica'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                            <?php elseif ($floorKey === 'dach'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            <?php elseif ($floorKey === 'bramy'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 12h20M12 5v14"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h2 class="floor-title"><?= htmlspecialchars($floorMeta['name']) ?></h2>
                            <span class="floor-subtitle"><?= htmlspecialchars($floorMeta['desc_full'] ?? (count($roomsInFloor) . ' przekaźników')) ?></span>
                        </div>
                    </div>
                    <div class="floor-actions">
                        <button type="button" class="btn-floor" onclick="triggerAction('FLOOR_<?= strtoupper($floorKey) ?>', 'open')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 15l-6-6-6 6"/></svg>
                            Otwórz sekcję
                        </button>
                        <button type="button" class="btn-floor" onclick="triggerAction('FLOOR_<?= strtoupper($floorKey) ?>', 'close')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg>
                            Zamknij sekcję
                        </button>
                        <button type="button" class="btn-floor danger" onclick="triggerAction('FLOOR_<?= strtoupper($floorKey) ?>', 'stop')">
                            <svg viewBox="0 0 24 24" fill="currentColor"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                            Stop
                        </button>
                    </div>
                </div>

                <div class="room-list">
                    <?php foreach ($roomsInFloor as $roomId => $room): ?>
                        <?php 
                            $st = getRoomStatus($room); 
                            $isMoving = !empty($st['is_moving']);
                            $nextAct = $st['next_action'];
                            $isCloseNext = ($nextAct === 'close');
                            $relayCode = $room['relays'][0] ?? '';
                        ?>
                        <div class="room-row" id="row-<?= htmlspecialchars($roomId) ?>">
                            <div class="room-info">
                                <div class="room-icon-box">
                                    <?php if ($room['icon'] === 'kitchen'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M18 8h1a4 4 0 0 1 0 8h-1M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8zM6 1v3M10 1v3M14 1v3"/></svg>
                                    <?php elseif ($room['icon'] === 'sunrise'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M12 2v4M4.93 10.93l2.83-2.83M2 18h2M20 18h2M19.07 10.93l-2.83-2.83M16 18a4 4 0 0 0-8 0M1 22h22"/></svg>
                                    <?php elseif ($room['icon'] === 'sun'): ?>
                                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                                    <?php elseif ($room['icon'] === 'sunset'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M17 18a5 5 0 0 0-10 0M12 9v4M4.22 10.22l1.42 1.42M1 18h2M21 18h2M18.36 11.64l1.42-1.42M23 22H1M16 5l-4 4-4-4"/></svg>
                                    <?php elseif ($room['icon'] === 'iron'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M4 17h16a2 2 0 0 0 2-2V9a3 3 0 0 0-3-3H6a4 4 0 0 0-4 4v7a2 2 0 0 0 2 2zM16 6V4a2 2 0 0 0-2-2h-4"/></svg>
                                    <?php elseif ($room['icon'] === 'box'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16zM3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
                                    <?php elseif ($room['icon'] === 'door'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M18 20V6a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v14M4 20h16M14 12v.01"/></svg>
                                    <?php elseif ($room['icon'] === 'roof'): ?>
                                        <svg viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                    <?php elseif ($room['icon'] === 'gate'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M4 4v16M20 4v16M4 8h16M4 16h16M9 4v16M15 4v16"/></svg>
                                    <?php elseif ($room['icon'] === 'garage'): ?>
                                        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 21V9"/></svg>
                                    <?php elseif ($room['icon'] === 'living_room'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M2 13v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6M4 13V8a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v5M2 13h20"/></svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 12h18M12 3v18"/></svg>
                                    <?php endif; ?>
                                </div>
                                <div class="room-details">
                                    <div class="room-name-wrapper">
                                        <span class="room-name"><?= htmlspecialchars($room['name']) ?></span>
                                        <span class="relay-id-tag"><?= htmlspecialchars($relayCode) ?></span>
                                        <span class="badge <?= $st['badge'] ?>" id="badge-<?= htmlspecialchars($roomId) ?>">
                                            <span class="badge-dot"></span>
                                            <span class="badge-text"><?= htmlspecialchars($st['label']) ?></span>
                                        </span>
                                    </div>
                                    <div class="room-desc"><?= htmlspecialchars($room['desc']) ?></div>
                                </div>
                            </div>

                            <div class="room-actions">
                                <form method="POST" action="index_full.php" style="display:inline;" onsubmit="return handleFormSubmit(event, '<?= htmlspecialchars($roomId) ?>', 'toggle')">
                                    <input type="hidden" name="room" value="<?= htmlspecialchars($roomId) ?>">
                                    <input type="hidden" name="action" value="toggle" id="action-<?= htmlspecialchars($roomId) ?>">
                                    
                                    <button type="submit" 
                                            class="btn btn-toggle <?= $isCloseNext ? 'mode-close' : '' ?> <?= $isMoving ? 'moving' : '' ?>" 
                                            id="btn-toggle-<?= htmlspecialchars($roomId) ?>"
                                            title="Kliknij, aby przełączyć: Otwórz / Zamknij">
                                        <span class="btn-icon" id="icon-toggle-<?= htmlspecialchars($roomId) ?>">
                                            <?php if ($isMoving): ?>
                                                <div class="spinner"></div>
                                            <?php elseif ($isCloseNext): ?>
                                                <svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                                            <?php else: ?>
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

                                <form method="POST" action="index_full.php" style="display:inline;" onsubmit="return handleFormSubmit(event, '<?= htmlspecialchars($roomId) ?>', 'stop')">
                                    <input type="hidden" name="room" value="<?= htmlspecialchars($roomId) ?>">
                                    <input type="hidden" name="action" value="stop">
                                    
                                    <button type="submit" 
                                            class="btn btn-stop" 
                                            id="btn-stop-<?= htmlspecialchars($roomId) ?>"
                                            title="Natychmiast zatrzymaj ruch">
                                        <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                                        <span>Stop</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </main>

    <!-- Stopka -->
    <footer>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <strong>Diagnostyka serwera i uprawnień (PI1):</strong>
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
    function showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = message;
        container.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 20);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 350);
        }, 3500);
    }

    // Grupy dla widoku wszystkich przekaźników PI1
    const GROUP_ROOMS = {
        'FLOOR_PIETRO_1': ['relay_pietro-1_duzy-pokoj_wschod_lewe', 'relay_pietro-1_duzy-pokoj_wschod_prawe', 'relay_pietro-1_duzy-pokoj_poludnie_lewe', 'relay_pietro-1_duzy-pokoj_poludnie_srodkowe', 'relay_pietro-1_duzy-pokoj_poludnie_prawe', 'relay_pietro-1_duzy-pokoj_zachod_lewe', 'relay_pietro-1_duzy-pokoj_zachod_prawe', 'relay_pietro-1_duzy-pokoj_zachod_balkon'],
        'FLOOR_PIWNICA': ['relay_piwnica_kuchnia_lewe', 'relay_piwnica_kuchnia_prawe', 'relay_piwnica_prasowalnia', 'relay_piwnica_skladzik', 'relay_piwnica_wejscie'],
        'FLOOR_DACH': ['relay_pietro-3_dach'],
        'FLOOR_BRAMY': ['relay_brama_wjazd', 'relay_brama_garaz'],
        'ALL': ['relay_pietro-1_duzy-pokoj_wschod_lewe', 'relay_pietro-1_duzy-pokoj_wschod_prawe', 'relay_pietro-1_duzy-pokoj_poludnie_lewe', 'relay_pietro-1_duzy-pokoj_poludnie_srodkowe', 'relay_pietro-1_duzy-pokoj_poludnie_prawe', 'relay_pietro-1_duzy-pokoj_zachod_lewe', 'relay_pietro-1_duzy-pokoj_zachod_prawe', 'relay_pietro-1_duzy-pokoj_zachod_balkon', 'relay_piwnica_kuchnia_lewe', 'relay_piwnica_kuchnia_prawe', 'relay_piwnica_prasowalnia', 'relay_piwnica_skladzik', 'relay_piwnica_wejscie', 'relay_pietro-3_dach', 'relay_brama_wjazd', 'relay_brama_garaz']
    };

    async function handleFormSubmit(event, roomId, action) {
        event.preventDefault();
        await triggerAction(roomId, action);
        return false;
    }

    async function triggerAction(roomId, action) {
        const btnToggle = document.getElementById(`btn-toggle-${roomId}`);
        const btnStop = document.getElementById(`btn-stop-${roomId}`);

        // 1. NATYCHMIASTOWA REAKCJA UI (0 ms) - OPTIMISTIC UPDATE
        let effectiveAction = action;
        if (action === 'toggle') {
            effectiveAction = (btnToggle && btnToggle.classList.contains('mode-close')) ? 'close' : 'open';
        }

        let optStatus = null;
        if (effectiveAction === 'open') {
            optStatus = {
                state: 'opening',
                label: 'Otwieranie...',
                badge: 'badge-warning',
                next_action: 'close',
                next_label: 'Zamknij',
                is_moving: true
            };
        } else if (effectiveAction === 'close') {
            optStatus = {
                state: 'closing',
                label: 'Zamykanie...',
                badge: 'badge-warning',
                next_action: 'open',
                next_label: 'Otwórz',
                is_moving: true
            };
        } else if (effectiveAction === 'stop') {
            optStatus = {
                state: 'closed',
                label: 'Zatrzymano',
                badge: 'badge-secondary',
                next_action: 'open',
                next_label: 'Otwórz',
                is_moving: false
            };
        }

        if (optStatus) {
            if (GROUP_ROOMS[roomId]) {
                GROUP_ROOMS[roomId].forEach(id => updateRoomUI(id, optStatus));
            } else {
                updateRoomUI(roomId, optStatus);
            }
        }

        if (btnStop) btnStop.disabled = false;

        try {
            const formData = new FormData();
            formData.append('room', roomId);
            formData.append('action', action);
            formData.append('ajax', '1');

            const response = await fetch('index_full.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showToast(data.message || 'Polecenie wysłane pomyślnie', 'success');
                if (data.new_status && roomId !== 'ALL' && !GROUP_ROOMS[roomId]) {
                    updateRoomUI(roomId, data.new_status);
                }
            } else {
                showToast(data.error || 'Wystąpił błąd podczas wykonywania polecenia', 'danger');
                pollStatus();
            }
        } catch (err) {
            console.error('Błąd połączenia:', err);
            showToast('Błąd połączenia z serwerem Apache: ' + err.message, 'danger');
            pollStatus();
        } finally {
            setTimeout(pollStatus, 1500);
        }
    }

    function updateRoomUI(roomId, st) {
        const badge = document.getElementById(`badge-${roomId}`);
        const btnToggle = document.getElementById(`btn-toggle-${roomId}`);
        const iconToggle = document.getElementById(`icon-toggle-${roomId}`);
        const textToggle = document.getElementById(`text-toggle-${roomId}`);

        if (!badge || !btnToggle) return;

        const badgeClass = st.badge.startsWith('badge-') ? st.badge : `badge-${st.badge}`;
        badge.className = `badge ${badgeClass}`;
        const badgeText = badge.querySelector('.badge-text');
        if (badgeText) badgeText.textContent = st.label;

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

    let isPolling = false;
    async function pollStatus() {
        if (isPolling) return;
        isPolling = true;
        try {
            const res = await fetch('index_full.php?api=status');
            if (!res.ok) return;
            const data = await res.json();
            if (data && data.statuses) {
                for (const [rId, st] of Object.entries(data.statuses)) {
                    updateRoomUI(rId, st);
                }
            }
        } catch (e) {
            // Cichy błąd pollingu
        } finally {
            isPolling = false;
        }
    }

    setInterval(pollStatus, 3000);
</script>
</body>
</html>
