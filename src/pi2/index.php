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

// Łączenie listy pomieszczeń (główne 10 + ewentualnie dodatkowe jeśli włączono)
$allRooms = $ROOMS;
$showExtra = isset($_GET['extra']) && $_GET['extra'] == '1';
if ($showExtra && !empty($EXTRA_ROOMS)) {
    $allRooms = array_merge($ROOMS, $EXTRA_ROOMS);
}

// Rejestr wszystkich akcji (pokoje główne + pojedyncze pod-przekaźniki dla kliknięć po rozwinięciu)
$allActionableRooms = $allRooms;
foreach ($allRooms as $rId => $rData) {
    if (!empty($rData['sub_relays'])) {
        foreach ($rData['sub_relays'] as $subId => $subData) {
            $allActionableRooms[$subId] = [
                'name' => $subData['name'],
                'desc' => $subData['desc'],
                'relays' => $subData['relays'],
                'floor' => $rData['floor'] ?? ''
            ];
        }
    }
}
// Dołącz wszystkie pojedyncze przekaźniki z INDIVIDUAL_ROOMS oraz bezpośrednie nazwy fizycznych przekaźników
if (!empty($INDIVIDUAL_ROOMS)) {
    foreach ($INDIVIDUAL_ROOMS as $indId => $indData) {
        if (!isset($allActionableRooms[$indId])) {
            $allActionableRooms[$indId] = $indData;
        }
        foreach ($indData['relays'] as $rel) {
            if (!isset($allActionableRooms[$rel])) {
                $allActionableRooms[$rel] = [
                    'name' => $indData['name'],
                    'desc' => $indData['desc'],
                    'relays' => [$rel],
                    'floor' => $indData['floor'] ?? ''
                ];
            }
        }
    }
}

// =========================================================================
// Funkcje pomocnicze
// =========================================================================

/**
 * Pobiera listę aktywnych procesów skryptu script.sh.
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
            if ($cmd !== false && strpos($cmd, 'script.sh') !== false) {
                $cachedProcesses[] = str_replace("\0", ' ', $cmd);
            }
        }
        return $cachedProcesses;
    }

    // 2. Fallback - jedno wywołanie ps na całe żądanie (zamiast kilkudziesięciu osobnych)
    if (function_exists('shell_exec')) {
        $output = @shell_exec("ps -eo args 2>/dev/null | grep 'script.sh' | grep -v grep");
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
    
    // Sprawdzenie czy proces script.sh dla danej rolety jest aktualnie uruchomiony w tle
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

    // Odczyt pliku .state: 1 = open, 2 = close/stop
    if (file_exists($stateFile) && is_readable($stateFile)) {
        $val = trim(@file_get_contents($stateFile));
        if ($val === '1') {
            return ['state' => 'open', 'label' => 'Otwarty', 'badge' => 'badge-success', 'is_open' => true];
        } elseif ($val === '2') {
            return ['state' => 'closed', 'label' => 'Zamknięty', 'badge' => 'badge-secondary', 'is_open' => false];
        }
    }

    return ['state' => 'closed', 'label' => 'Zamknięty', 'badge' => 'badge-secondary', 'is_open' => false];
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
function runScriptCommand($relay, $action, $time = null) {
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $relay)) {
        return ['success' => false, 'error' => 'Błędna nazwa przekaźnika'];
    }
    if (!in_array($action, ['open', 'close', 'stop'])) {
        return ['success' => false, 'error' => 'Błędna akcja'];
    }
    $timeVal = ($time !== null && is_numeric($time) && (int)$time > 0) ? (int)$time : null;

    if (SIMULATION_MODE) {
        // Tryb symulacyjny (dla testów bez fizycznego I2C)
        $simStateFile = rtrim(GPIO_DIR, '/') . '/' . $relay . '.state';
        @file_put_contents($simStateFile, ($action === 'open' ? '1' : '2'));
        return ['success' => true, 'output' => "Symulacja: {$relay} -> {$action}" . ($timeVal ? " (czas: {$timeVal}s)" : '')];
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
 * Wykonuje akcję dla całego pokoju
 */
function handleRoomAction($roomId, $actionRequested, $allRooms, $time = null) {
    if (!isset($allRooms[$roomId])) {
        if (strpos($roomId, 'relay_') === 0 && isset($allRooms[substr($roomId, 6)])) {
            $roomId = substr($roomId, 6);
        } elseif (isset($allRooms['relay_' . $roomId])) {
            $roomId = 'relay_' . $roomId;
        }
    }

    if (!isset($allRooms[$roomId])) {
        return ['success' => false, 'error' => 'Nie znaleziono pomieszczenia: ' . htmlspecialchars($roomId)];
    }

    $roomData = $allRooms[$roomId];
    $timeVal = ($time !== null && is_numeric($time) && (int)$time > 0) ? (int)$time : null;

    // Jeśli akcja to toggle, pobieramy aktualny stan, aby ustalić kierunek
    $action = $actionRequested;
    if ($action === 'toggle') {
        $status = getRoomStatus($roomData);
        $action = $status['next_action']; // 'open' lub 'close'
    }

    $results = [];
    $allOk = true;

    foreach ($roomData['relays'] as $relay) {
        $res = runScriptCommand($relay, $action, $timeVal);
        $results[] = $res;
        if (empty($res['success'])) {
            $allOk = false;
        }
        // Drobna pauza 50ms, aby uniknąć kolizji na magistrali I2C przy wysyłaniu pakietu
        if (count($roomData['relays']) > 1) {
            usleep(50000);
        }
    }

    // Natychmiastowe ustalenie nowego statusu bez ponownego kosztownego skanowania procesów
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

// =========================================================================
// Obsługa API (JSON dla żądań asynchronicznych fetch/AJAX)
// =========================================================================

if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json');
    $statuses = [];
    foreach ($allActionableRooms as $id => $room) {
        $statuses[$id] = getRoomStatus($room);
    }
    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'statuses' => $statuses
    ]);
    exit;
}

/**
 * Zwraca grupę pokoi na podstawie identyfikatora zbiorczego (ALL, FLOOR_PIETRO_2, FLOOR_PARTER)
 */
function getTargetRooms($target, $allRooms) {
    if ($target === 'ALL') {
        return ['rooms' => $allRooms, 'desc' => 'wszystkich pomieszczeń'];
    }
    if ($target === 'FLOOR_PIETRO_2' || $target === 'pietro_2') {
        $subset = array_filter($allRooms, fn($r) => ($r['floor'] ?? '') === 'pietro_2');
        return ['rooms' => $subset, 'desc' => 'Piętra 2'];
    }
    if ($target === 'FLOOR_PARTER' || $target === 'parter') {
        $subset = array_filter($allRooms, fn($r) => ($r['floor'] ?? '') === 'parter');
        return ['rooms' => $subset, 'desc' => 'Parteru'];
    }
    return null;
}

// Pobranie opcjonalnego parametru czasu (np. ?time=11 lub POST time=11)
$rawTime = $_GET['time'] ?? $_POST['time'] ?? $_GET['duration'] ?? $_POST['duration'] ?? $_GET['t'] ?? $_POST['t'] ?? null;
$requestedTime = ($rawTime !== null && is_numeric($rawTime) && (int)$rawTime > 0) ? (int)$rawTime : null;

// Obsługa zdalnych wywołań przez GET (np. curl "http://SERVER/index.php?room=FLOOR_PIETRO_2&action=open&time=11")
if (isset($_GET['room']) && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $roomId = $_GET['room'];
    $action = $_GET['action'];

    $groupTarget = getTargetRooms($roomId, $allRooms);
    if ($groupTarget !== null) {
        $allSuccess = true;
        foreach ($groupTarget['rooms'] as $rId => $rData) {
            $r = handleRoomAction($rId, $action, $allActionableRooms, $requestedTime);
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
        echo json_encode(handleRoomAction($roomId, $action, $allActionableRooms, $requestedTime));
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
            $r = handleRoomAction($rId, $action, $allActionableRooms, $requestedTime);
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
        $response = handleRoomAction($roomId, $action, $allActionableRooms, $requestedTime);
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
    <title>Sterowanie Roletami PI2</title>
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

        /* Przełącznik widoków (Pokoje / Wszystkie przekaźniki) */
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

        .btn-toggle-all-expand {
            background: rgba(59, 130, 246, 0.08);
            border: 1px solid rgba(59, 130, 246, 0.25);
            color: #93c5fd;
            border-radius: var(--radius-md);
            padding: 7px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }

        .btn-toggle-all-expand:hover {
            background: rgba(59, 130, 246, 0.18);
            color: #ffffff;
            border-color: #60a5fa;
        }

        .btn-toggle-all-expand.active {
            background: rgba(59, 130, 246, 0.25);
            color: #ffffff;
            border-color: #3b82f6;
        }

        /* Przycisk rozwijania w wierszu pokoju */
        .btn-expand {
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            border-radius: var(--radius-md);
            padding: 3px 8px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }

        .btn-expand:hover {
            background: rgba(59, 130, 246, 0.25);
            border-color: #60a5fa;
            color: #ffffff;
        }

        .btn-expand .chevron {
            width: 12px;
            height: 12px;
            transition: transform 0.2s ease;
        }

        .btn-expand.expanded .chevron {
            transform: rotate(180deg);
        }

        .btn-expand.expanded {
            background: rgba(59, 130, 246, 0.3);
            border-color: #3b82f6;
            color: #ffffff;
        }

        /* Kontener rozwiniętych pojedynczych rolet */
        .sub-room-container {
            margin-top: 8px;
            margin-left: 20px;
            padding-left: 14px;
            border-left: 2px dashed rgba(59, 130, 246, 0.4);
            display: flex;
            flex-direction: column;
            gap: 8px;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .sub-room-row {
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: relative;
        }

        .sub-room-row:hover {
            background: rgba(30, 41, 59, 0.85);
            border-color: #475569;
        }

        .sub-room-row::before {
            content: '';
            position: absolute;
            left: -14px;
            top: 50%;
            width: 12px;
            height: 2px;
            border-bottom: 2px dashed rgba(59, 130, 246, 0.4);
        }

        .sub-room-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }

        .sub-tree-icon {
            color: #60a5fa;
            font-size: 1.15rem;
            line-height: 1;
            user-select: none;
        }

        .sub-room-name {
            font-size: 0.92rem;
            font-weight: 600;
            color: #e2e8f0;
        }

        .sub-room-desc {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* Przyciski w rozmiarze mini dla rozwinięcia */
        .btn-sm {
            min-height: 36px !important;
            padding: 6px 12px !important;
            font-size: 0.82rem !important;
            gap: 6px !important;
        }

        .btn-sm.btn-toggle {
            min-width: 110px !important;
        }

        .btn-sm.btn-stop {
            min-width: 65px !important;
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

        /* Kondygnacje / Grupy */
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
                Sterowanie Roletami PI2
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

    <!-- Belka przełączania serwerów (PI1, PI2, Cubie) -->
    <nav class="server-nav">
        <div class="server-nav-label">
            <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <span>Serwery:</span>
        </div>
        <div class="server-nav-links">
            <a href="http://10.0.0.4/" class="server-nav-link" title="Przejdź do sterowania roletami Salon / Piwnica / Bramy na serwerze PI1">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>PI1: Rolety Salon / Piwnica / Bramy</span>
                <span class="badge-ip">10.0.0.4</span>
            </a>
            <a href="http://10.0.0.2/" class="server-nav-link" title="Przejdź do sterowania piecem, CWU, pompami i rekuperacją na serwerze Cubie">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Cubie: Kotłownia / Wentylacja</span>
                <span class="badge-ip">10.0.0.2</span>
            </a>
            <a href="http://10.0.0.4/wentylacja.php" class="server-nav-link" title="Przejdź do sterowania wentylacją (10.0.0.8, 10.0.0.9) na serwerze PI1">
                <svg viewBox="0 0 24 24"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                <span>PI1: Wentylacja (2x)</span>
                <span class="badge-ip">10.0.0.8 &bull; 10.0.0.9</span>
            </a>
        </div>
    </nav>

    <nav class="view-nav">
        <div class="nav-tabs-group">
            <a href="index.php" class="nav-tab active">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Pokoje (10)
            </a>
            <a href="index_full.php" class="nav-tab">
                <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Wszystkie przekaźniki (13)
            </a>
        </div>
        <button type="button" class="btn-toggle-all-expand" onclick="toggleAllExpand()" id="btnToggleAllExpand" title="Rozwiń lub zwiń pojedyncze rolety w połączonych pokojach">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 13l5 5 5-5M7 6l5 5 5-5"/></svg>
            <span class="toggle-text">Rozwiń szczegóły (3)</span>
        </button>
    </nav>

    <?php if (!empty($_SESSION['flash_message'])): ?>
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                showToast("<?= htmlspecialchars(addslashes($_SESSION['flash_message'])) ?>", "<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>");
            });
        </script>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- Lista podzielona na kondygnacje: Piętro 2 jako pierwsze, Parter jako drugi -->
    <?php
    $groupedRooms = [];
    foreach ($allRooms as $rId => $rData) {
        $fl = $rData['floor'] ?? 'inne';
        $groupedRooms[$fl][$rId] = $rData;
    }
    // Gwarantowana kolejność: pietro_2 jako pierwsze, parter jako drugi
    $floorOrder = [];
    if (isset($groupedRooms['pietro_2'])) $floorOrder[] = 'pietro_2';
    if (isset($groupedRooms['parter'])) $floorOrder[] = 'parter';
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
                    'name' => ($floorKey === 'pietro_2' ? 'Piętro 2' : ($floorKey === 'parter' ? 'Parter' : 'Dodatkowe')),
                    'desc' => count($roomsInFloor) . ' pomieszczeń',
                    'icon' => ($floorKey === 'pietro_2' ? 'roof' : 'living_room')
                ];
            ?>
            <section class="floor-section">
                <div class="floor-header">
                    <div class="floor-title-box">
                        <div class="floor-icon">
                            <?php if ($floorKey === 'pietro_2'): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h2 class="floor-title"><?= htmlspecialchars($floorMeta['name']) ?></h2>
                            <span class="floor-subtitle"><?= htmlspecialchars($floorMeta['desc']) ?></span>
                        </div>
                    </div>
                    <div class="floor-actions">
                        <button type="button" class="btn-floor" onclick="triggerAction('FLOOR_<?= strtoupper($floorKey) ?>', 'open')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 15l-6-6-6 6"/></svg>
                            Otwórz piętro
                        </button>
                        <button type="button" class="btn-floor" onclick="triggerAction('FLOOR_<?= strtoupper($floorKey) ?>', 'close')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg>
                            Zamknij piętro
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
                            $nextAct = $st['next_action']; // 'open' lub 'close'
                            $isCloseNext = ($nextAct === 'close');
                        ?>
                        <div class="room-row" id="row-<?= htmlspecialchars($roomId) ?>">
                            <!-- Kolumna 1: Informacje o pomieszczeniu -->
                            <div class="room-info">
                                <div class="room-icon-box">
                                    <?php if ($room['icon'] === 'bath'): ?>
                                        <!-- Łazienka -->
                                        <svg viewBox="0 0 24 24"><path d="M4 12h16a1 1 0 0 1 1 1v3a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4v-3a1 1 0 0 1 1-1zM6 12V5a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2v1M5 20l-1 2M19 20l1 2"/></svg>
                                    <?php elseif ($room['icon'] === 'bed'): ?>
                                        <!-- Sypialnia / Pokój -->
                                        <svg viewBox="0 0 24 24"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v4"/></svg>
                                    <?php elseif ($room['icon'] === 'garden'): ?>
                                        <!-- Ogród -->
                                        <svg viewBox="0 0 24 24"><path d="M12 22v-9M8 10a4 4 0 0 1 8 0c0 3-4 6-4 6s-4-3-4-6zM12 14c-2 0-4-1.5-4-3.5M12 14c2 0 4-1.5 4-3.5"/></svg>
                                    <?php elseif ($room['icon'] === 'computer'): ?>
                                        <!-- Komputerówka -->
                                        <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                                    <?php elseif ($room['icon'] === 'book'): ?>
                                        <!-- Biblioteczka -->
                                        <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15z"/></svg>
                                    <?php elseif ($room['icon'] === 'door'): ?>
                                        <!-- Drzwi / Wejście -->
                                        <svg viewBox="0 0 24 24"><path d="M18 20V6a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v14M4 20h16M14 12v.01"/></svg>
                                    <?php elseif ($room['icon'] === 'roof'): ?>
                                        <!-- Piętro 2 / Dach -->
                                        <svg viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                    <?php elseif ($room['icon'] === 'living_room'): ?>
                                        <!-- Salon / Parter -->
                                        <svg viewBox="0 0 24 24"><path d="M2 13v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6M4 13V8a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v5M2 13h20"/></svg>
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
                                        <?php if (!empty($room['sub_relays'])): ?>
                                            <button type="button" 
                                                    class="btn-expand" 
                                                    onclick="toggleExpand('<?= htmlspecialchars($roomId) ?>')" 
                                                    id="expand-btn-<?= htmlspecialchars($roomId) ?>" 
                                                    title="Kliknij, aby rozwinąć/zwinąć poszczególne rolety">
                                                <span><?= count($room['sub_relays']) ?> rolety</span>
                                                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                                            </button>
                                        <?php endif; ?>
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

                        <?php if (!empty($room['sub_relays'])): ?>
                            <div class="sub-room-container" id="sub-container-<?= htmlspecialchars($roomId) ?>" style="display: none;">
                                <?php foreach ($room['sub_relays'] as $subId => $subRoom): ?>
                                    <?php
                                        $subSt = getRoomStatus($subRoom);
                                        $isSubMoving = !empty($subSt['is_moving']);
                                        $subNextAct = $subSt['next_action'];
                                        $isSubCloseNext = ($subNextAct === 'close');
                                    ?>
                                    <div class="sub-room-row" id="row-<?= htmlspecialchars($subId) ?>">
                                        <div class="sub-room-info">
                                            <span class="sub-tree-icon">↳</span>
                                            <div>
                                                <div class="room-name-wrapper">
                                                    <span class="sub-room-name"><?= htmlspecialchars($subRoom['name']) ?></span>
                                                    <span class="badge <?= $subSt['badge'] ?>" id="badge-<?= htmlspecialchars($subId) ?>">
                                                        <span class="badge-dot"></span>
                                                        <span class="badge-text"><?= htmlspecialchars($subSt['label']) ?></span>
                                                    </span>
                                                </div>
                                                <div class="sub-room-desc"><?= htmlspecialchars($subRoom['desc']) ?></div>
                                            </div>
                                        </div>
                                        <div class="room-actions">
                                            <form method="POST" action="index.php" style="display:inline;" onsubmit="return handleFormSubmit(event, '<?= htmlspecialchars($subId) ?>', 'toggle')">
                                                <input type="hidden" name="room" value="<?= htmlspecialchars($subId) ?>">
                                                <input type="hidden" name="action" value="toggle" id="action-<?= htmlspecialchars($subId) ?>">
                                                <button type="submit" 
                                                        class="btn btn-sm btn-toggle <?= $isSubCloseNext ? 'mode-close' : '' ?> <?= $isSubMoving ? 'moving' : '' ?>" 
                                                        id="btn-toggle-<?= htmlspecialchars($subId) ?>"
                                                        title="Przełącz stan pojedynczej rolety">
                                                    <span class="btn-icon" id="icon-toggle-<?= htmlspecialchars($subId) ?>">
                                                        <?php if ($isSubMoving): ?>
                                                            <div class="spinner"></div>
                                                        <?php elseif ($isSubCloseNext): ?>
                                                            <svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
                                                        <?php else: ?>
                                                            <svg viewBox="0 0 24 24"><path d="M18 15l-6-6-6 6"/></svg>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="btn-text" id="text-toggle-<?= htmlspecialchars($subId) ?>">
                                                        <?php if ($isSubMoving): ?>
                                                            <?= $subSt['state'] === 'opening' ? 'Otwieranie...' : 'Zamykanie...' ?>
                                                        <?php elseif ($isSubCloseNext): ?>
                                                            Zamknij
                                                        <?php else: ?>
                                                            Otwórz
                                                        <?php endif; ?>
                                                    </span>
                                                </button>
                                            </form>

                                            <form method="POST" action="index.php" style="display:inline;" onsubmit="return handleFormSubmit(event, '<?= htmlspecialchars($subId) ?>', 'stop')">
                                                <input type="hidden" name="room" value="<?= htmlspecialchars($subId) ?>">
                                                <input type="hidden" name="action" value="stop">
                                                <button type="submit" 
                                                        class="btn btn-sm btn-stop" 
                                                        id="btn-stop-<?= htmlspecialchars($subId) ?>"
                                                        title="Zatrzymaj pojedynczą roletę">
                                                    <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>
                                                    <span>Stop</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
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

    // Rozwijanie / zwijanie pojedynczego pokoju
    function toggleExpand(roomId) {
        const container = document.getElementById(`sub-container-${roomId}`);
        const btn = document.getElementById(`expand-btn-${roomId}`);
        if (!container || !btn) return;

        const isCurrentlyOpen = (container.style.display !== 'none');
        if (isCurrentlyOpen) {
            container.style.display = 'none';
            btn.classList.remove('expanded');
        } else {
            container.style.display = 'flex';
            btn.classList.add('expanded');
        }
        updateExpandAllBtnState();
    }

    // Zbiorcze rozwijanie / zwijanie wszystkich połączonych rolet
    function toggleAllExpand() {
        const containers = document.querySelectorAll('.sub-room-container');
        const buttons = document.querySelectorAll('.btn-expand');
        const toggleBtn = document.getElementById('btnToggleAllExpand');
        if (!containers.length) return;

        let anyClosed = false;
        containers.forEach(c => {
            if (c.style.display === 'none') anyClosed = true;
        });

        containers.forEach(c => {
            c.style.display = anyClosed ? 'flex' : 'none';
        });
        buttons.forEach(b => {
            if (anyClosed) b.classList.add('expanded');
            else b.classList.remove('expanded');
        });

        if (toggleBtn) {
            const toggleText = toggleBtn.querySelector('.toggle-text');
            if (anyClosed) {
                toggleBtn.classList.add('active');
                if (toggleText) toggleText.textContent = 'Zwiń szczegóły';
            } else {
                toggleBtn.classList.remove('active');
                if (toggleText) toggleText.textContent = 'Rozwiń szczegóły (3)';
            }
        }
    }

    // Aktualizacja stanu zbiorczego przycisku
    function updateExpandAllBtnState() {
        const containers = document.querySelectorAll('.sub-room-container');
        const toggleBtn = document.getElementById('btnToggleAllExpand');
        if (!containers.length || !toggleBtn) return;

        let allOpen = true;
        containers.forEach(c => {
            if (c.style.display === 'none') allOpen = false;
        });

        const toggleText = toggleBtn.querySelector('.toggle-text');
        if (allOpen) {
            toggleBtn.classList.add('active');
            if (toggleText) toggleText.textContent = 'Zwiń szczegóły';
        } else {
            toggleBtn.classList.remove('active');
            if (toggleText) toggleText.textContent = 'Rozwiń szczegóły (3)';
        }
    }

    // Mapowanie połączonych pokoi na pod-rolety dla natychmiastowej reakcji UI
    const ROOM_SUB_RELAYS = {
        'lazienka_gora': ['pietro-2_lazienka_prawe', 'pietro-2_lazienka_lewe'],
        'olek': ['pietro-2_olek_glowne', 'pietro-2_olek_balkon'],
        'biblioteczka': ['pietro-2_biblioteczka_glowne', 'pietro-2_biblioteczka_balkon']
    };

    const GROUP_ROOMS = {
        'FLOOR_PIETRO_2': ['lazienka_gora', 'przemek', 'olek', 'biblioteczka', 'pietro-2_lazienka_prawe', 'pietro-2_lazienka_lewe', 'pietro-2_olek_glowne', 'pietro-2_olek_balkon', 'pietro-2_biblioteczka_glowne', 'pietro-2_biblioteczka_balkon'],
        'FLOOR_PARTER': ['mama', 'lazienka_dol', 'komputerowka', 'ogrod', 'wejscie_glowne', 'wejscie_okno'],
        'ALL': ['lazienka_gora', 'przemek', 'olek', 'biblioteczka', 'pietro-2_lazienka_prawe', 'pietro-2_lazienka_lewe', 'pietro-2_olek_glowne', 'pietro-2_olek_balkon', 'pietro-2_biblioteczka_glowne', 'pietro-2_biblioteczka_balkon', 'mama', 'lazienka_dol', 'komputerowka', 'ogrod', 'wejscie_glowne', 'wejscie_okno']
    };

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
                if (ROOM_SUB_RELAYS[roomId]) {
                    ROOM_SUB_RELAYS[roomId].forEach(subId => updateRoomUI(subId, optStatus));
                }
            }
        }

        if (btnStop) btnStop.disabled = false;

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

    // Aktualizacja pojedynczego wiersza w DOM
    function updateRoomUI(roomId, st) {
        const badge = document.getElementById(`badge-${roomId}`);
        const btnToggle = document.getElementById(`btn-toggle-${roomId}`);
        const iconToggle = document.getElementById(`icon-toggle-${roomId}`);
        const textToggle = document.getElementById(`text-toggle-${roomId}`);

        if (!badge || !btnToggle) return;

        // Aktualizacja Badge
        const badgeClass = st.badge.startsWith('badge-') ? st.badge : `badge-${st.badge}`;
        badge.className = `badge ${badgeClass}`;
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

    // Automatyczne odpytywanie o stan co 3 sekundy z blokadą jednoczesnych zapytań
    let isPolling = false;
    async function pollStatus() {
        if (isPolling) return;
        isPolling = true;
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
            // Cichy błąd pollingu
        } finally {
            isPolling = false;
        }
    }

    // Uruchomienie cyklicznego pollingu
    setInterval(pollStatus, 3000);
</script>
</body>
</html>
