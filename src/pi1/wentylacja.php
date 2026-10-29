<?php
/**
 * Panel Sterowania Wentylacją - PI1 (10.0.0.4)
 * 
 * Obsługuje 2 jednostki wentylacyjne:
 * - Wentylacja 1: 10.0.0.8
 * - Wentylacja 2: 10.0.0.9
 *
 * Domyślnie wysyła polecenia API równolegle pod oba adresy jednocześnie (curl_multi).
 */

session_start();

// Definicja adresów IP i nazw urządzeń
$WENTYLACJA = [
    '1' => [
        'id' => '1',
        'ip' => '10.0.0.8',
        'name' => 'Wentylacja 1',
        'desc' => 'Jednostka główna (10.0.0.8)'
    ],
    '2' => [
        'id' => '2',
        'ip' => '10.0.0.9',
        'name' => 'Wentylacja 2',
        'desc' => 'Jednostka dodatkowa (10.0.0.9)'
    ]
];
$REKUPERATORY = &$WENTYLACJA; // Alias dla wstecznej kompatybilności

// Timeouty cURL (w milisekundach) - szybkie czasy, by strona nie wisiała gdy urządzenia są wyłączone
define('CURL_CONNECT_TIMEOUT_MS', 1500);
define('CURL_TOTAL_TIMEOUT_MS', 2500);

/**
 * Wykonuje zapytania HTTP cURL równolegle do wybranych rekuperatorów za pomocą curl_multi
 * (z automatycznym fallbackiem do CLI curl / streams w razie braku modułu php-curl)
 *
 * @param string $functionName Nazwa funkcji w API (np. 'AutomaticMode', 'TurnOn')
 * @param array $queryParams Dodatkowe parametry GET przekazywane w query string
 * @param string $target 'both' (domyślnie), '1' (10.0.0.8), '2' (10.0.0.9)
 * @return array Szczegółowy wynik z odpowiedziami dla każdego hosta
 */
function callRecuperatorApi($functionName, $queryParams = [], $target = 'both') {
    global $REKUPERATORY;

    $hosts = [];
    if ($target === '1' || $target === '10.0.0.8') {
        $hosts['10.0.0.8'] = $REKUPERATORY['1'];
    } elseif ($target === '2' || $target === '10.0.0.9') {
        $hosts['10.0.0.9'] = $REKUPERATORY['2'];
    } else {
        foreach ($REKUPERATORY as $r) {
            $hosts[$r['ip']] = $r;
        }
    }

    $urlParams = array_merge(['name' => $functionName], $queryParams);
    $queryString = http_build_query($urlParams);

    $results = [];
    $allSuccess = true;
    $anySuccess = false;

    // 1. Natywny moduł curl_multi_init (optymalny, wielowątkowy w PHP)
    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $curlHandles = [];

        foreach ($hosts as $ip => $dev) {
            $ch = curl_init();
            $url = "http://{$ip}/API/RunFunction?{$queryString}";
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, CURL_CONNECT_TIMEOUT_MS);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, CURL_TOTAL_TIMEOUT_MS);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'HomeControl/PI1-Wentylacja');
            curl_multi_add_handle($mh, $ch);
            $curlHandles[$ip] = ['ch' => $ch, 'url' => $url, 'dev' => $dev];
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($curlHandles as $ip => $data) {
            $ch = $data['ch'];
            $url = $data['url'];
            $dev = $data['dev'];
            $content = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $errNo = curl_errno($ch);
            $errMsg = curl_error($ch);
            $httpCode = (int)($info['http_code'] ?? 0);
            $totalTime = (float)($info['total_time'] ?? 0);

            $isOk = ($errNo === 0 && ($httpCode >= 200 && $httpCode < 300));
            if ($isOk) {
                $anySuccess = true;
            } else {
                $allSuccess = false;
            }

            $humanError = null;
            if ($errNo !== 0) {
                if ($errNo === 28) {
                    $humanError = 'Przekroczono limit czasu połączenia (urządzenie wyłączone)';
                } elseif ($errNo === 7) {
                    $humanError = 'Brak połączenia z portem 80 (urządzenie wyłączone)';
                } else {
                    $humanError = "Błąd cURL ({$errNo}): {$errMsg}";
                }
            } elseif ($httpCode === 0) {
                $humanError = 'Brak połączenia (urządzenie wyłączone)';
            } elseif ($httpCode !== 200) {
                $humanError = "Odpowiedź HTTP {$httpCode}";
            }

            $results[$ip] = [
                'ip' => $ip,
                'name' => $dev['name'],
                'url' => $url,
                'success' => $isOk,
                'http_code' => $httpCode,
                'error' => $humanError,
                'errno' => $errNo,
                'response' => $content,
                'time_sec' => round($totalTime, 3)
            ];

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }
    // 2. Fallback przez CLI /usr/bin/curl (gdyby PHP nie miało rozszerzenia ext-curl)
    else {
        foreach ($hosts as $ip => $dev) {
            $url = "http://{$ip}/API/RunFunction?{$queryString}";
            $escapedUrl = escapeshellarg($url);
            $cmd = "/usr/bin/curl -s -m 2.5 --connect-timeout 1.5 -w '\\n%{http_code}\\n%{time_total}' {$escapedUrl} 2>&1";

            $start = microtime(true);
            $output = @shell_exec($cmd);
            $duration = round(microtime(true) - $start, 3);

            $httpCode = 0;
            $body = '';
            if ($output !== null) {
                $lines = explode("\n", trim($output));
                if (count($lines) >= 2) {
                    $timeTotal = (float)array_pop($lines);
                    $httpCode = (int)array_pop($lines);
                    $body = implode("\n", $lines);
                }
            }

            $isOk = ($httpCode >= 200 && $httpCode < 300);
            if ($isOk) {
                $anySuccess = true;
            } else {
                $allSuccess = false;
            }

            $humanError = null;
            if (!$isOk) {
                $humanError = ($httpCode === 0) ? 'Brak połączenia (urządzenie wyłączone)' : "Odpowiedź HTTP {$httpCode}";
            }

            $results[$ip] = [
                'ip' => $ip,
                'name' => $dev['name'],
                'url' => $url,
                'success' => $isOk,
                'http_code' => $httpCode,
                'error' => $humanError,
                'errno' => $isOk ? 0 : ($httpCode === 0 ? 7 : 0),
                'response' => $body,
                'time_sec' => $duration
            ];
        }
    }

    return [
        'success' => $allSuccess,
        'any_success' => $anySuccess,
        'function' => $functionName,
        'params' => $queryParams,
        'target' => $target,
        'query_string' => $queryString,
        'timestamp' => date('Y-m-d H:i:s'),
        'results' => $results
    ];
}

/**
 * Sprawdza status łączności z urządzeniem (krótki connect test)
 */
function checkRecuperatorConnection($target = 'both') {
    global $REKUPERATORY;
    $hosts = [];
    if ($target === '1' || $target === '10.0.0.8') {
        $hosts['10.0.0.8'] = $REKUPERATORY['1'];
    } elseif ($target === '2' || $target === '10.0.0.9') {
        $hosts['10.0.0.9'] = $REKUPERATORY['2'];
    } else {
        foreach ($REKUPERATORY as $r) {
            $hosts[$r['ip']] = $r;
        }
    }

    $statuses = [];

    // Jeśli moduł curl_multi jest dostępny
    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $curlHandles = [];

        foreach ($hosts as $ip => $dev) {
            $ch = curl_init();
            $url = "http://{$ip}/";
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1000);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1200);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_multi_add_handle($mh, $ch);
            $curlHandles[$ip] = ['ch' => $ch, 'dev' => $dev];
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($curlHandles as $ip => $data) {
            $ch = $data['ch'];
            $dev = $data['dev'];
            $info = curl_getinfo($ch);
            $errNo = curl_errno($ch);
            $httpCode = (int)($info['http_code'] ?? 0);
            $totalTime = (float)($info['total_time'] ?? 0);

            $online = ($errNo === 0 && $httpCode > 0);

            $statuses[$ip] = [
                'ip' => $ip,
                'name' => $dev['name'],
                'online' => $online,
                'http_code' => $httpCode,
                'latency_ms' => round($totalTime * 1000),
                'status_label' => $online ? 'Dostępny' : 'Wyłączony / Brak połączenia',
                'status_class' => $online ? 'badge-success' : 'badge-danger'
            ];

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    // Fallback: szybki test połączenia TCP przez fsockopen
    else {
        foreach ($hosts as $ip => $dev) {
            $start = microtime(true);
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen($ip, 80, $errno, $errstr, 1.0);
            $latency = round((microtime(true) - $start) * 1000);
            $online = ($fp !== false);
            if ($fp) {
                @fclose($fp);
            }

            $statuses[$ip] = [
                'ip' => $ip,
                'name' => $dev['name'],
                'online' => $online,
                'http_code' => $online ? 200 : 0,
                'latency_ms' => $latency,
                'status_label' => $online ? 'Dostępny' : 'Wyłączony / Brak połączenia',
                'status_class' => $online ? 'badge-success' : 'badge-danger'
            ];
        }
    }

    return $statuses;
}

// =========================================================================
// Obsługa poleceń (API JSON / POST / GET)
// =========================================================================

$requestedAction = $_POST['action'] ?? $_GET['action'] ?? null;
$requestedName = $_POST['name'] ?? $_GET['name'] ?? null;
$requestedTarget = $_POST['target'] ?? $_GET['target'] ?? 'both';

// Sprawdzenie czy żądanie to odpytanie o status połączenia
if ((isset($_GET['api']) && $_GET['api'] === 'status') || $requestedAction === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $conn = checkRecuperatorConnection($requestedTarget);
    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'statuses' => $conn
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Jeśli podano akcję lub bezpośrednią nazwę funkcji API
if ($requestedAction !== null || $requestedName !== null) {
    $functionName = null;
    $queryParams = [];
    $humanDesc = '';

    // 1. Bezpośrednie wywołanie API po parametrze 'name'
    if ($requestedName !== null) {
        $functionName = trim($requestedName);
        $humanDesc = "Wywołanie funkcji API: {$functionName}";

        // Pobranie wszystkich pozostałych parametrów (z wyłączeniem sterujących panelem)
        $ignoredKeys = ['name', 'action', 'target', 'api'];
        $sourceParams = array_merge($_GET, $_POST);
        foreach ($sourceParams as $k => $v) {
            if (!in_array($k, $ignoredKeys, true)) {
                $queryParams[$k] = $v;
            }
        }
    }
    // 2. Mapowanie akcji panelu na funkcje API
    else {
        switch ($requestedAction) {
            // Zasilanie
            case 'turn_on':
            case 'power_on':
                $functionName = 'TurnOn';
                $humanDesc = 'Włączenie wentylacji';
                break;

            case 'turn_off':
            case 'power_off':
                $functionName = 'TurnOff';
                $humanDesc = 'Wyłączenie wentylacji';
                break;

            // Tryby wentylacji
            case 'auto':
            case 'automatic_mode':
                $functionName = 'AutomaticMode';
                $humanDesc = 'Tryb Automatyczny (Auto)';
                break;

            case 'normal':
            case 'manual_normal':
                $functionName = 'ManualMode';
                $queryParams = [
                    'airflowvalue' => 100,
                    'valueofairextraction' => 100
                ];
                $humanDesc = 'Tryb Normalny (100% nawiew / 100% wywiew)';
                break;

            case 'slow':
            case 'manual_slow':
                $functionName = 'ManualMode';
                $queryParams = [
                    'airflowvalue' => 80,
                    'valueofairextraction' => 70
                ];
                $humanDesc = 'Tryb Wolny / Cichy (80% nawiew / 70% wywiew)';
                break;

            case 'manual':
            case 'manual_custom':
                $airflow = (int)($_POST['airflow'] ?? $_GET['airflow'] ?? $_POST['airflowvalue'] ?? $_GET['airflowvalue'] ?? 100);
                $extraction = (int)($_POST['extraction'] ?? $_GET['extraction'] ?? $_POST['valueofairextraction'] ?? $_GET['valueofairextraction'] ?? 100);
                $functionName = 'ManualMode';
                $queryParams = [
                    'airflowvalue' => max(10, min(100, $airflow)),
                    'valueofairextraction' => max(10, min(100, $extraction))
                ];
                $humanDesc = "Tryb Ręczny ({$queryParams['airflowvalue']}% nawiew / {$queryParams['valueofairextraction']}% wywiew)";
                break;

            // Wakacje
            case 'holiday_on':
                $days = (int)($_POST['days'] ?? $_GET['days'] ?? 1);
                $days = max(1, min(99, $days));
                $functionName = 'TurnOnHoliday';
                $queryParams = ['days' => $days];
                $humanDesc = "Włączenie trybu Wakacje ({$days} dni)";
                break;

            case 'holiday_off':
                $functionName = 'TurnOffHoliday';
                $humanDesc = 'Wyłączenie trybu Wakacje';
                break;

            // Wietrzenie (Airing)
            case 'airing_on':
                $time = (int)($_POST['time'] ?? $_GET['time'] ?? 20);
                $time = max(1, min(180, $time));
                $functionName = 'TurnOnAiring';
                $queryParams = ['time' => $time];
                $humanDesc = "Włączenie wietrzenia Airing ({$time} min)";
                break;

            case 'airing_off':
                $functionName = 'TurnOffAiring';
                $humanDesc = 'Wyłączenie wietrzenia Airing';
                break;

            // Ogrzewanie (Heating)
            case 'heating_on':
                $temp = (int)($_POST['temp'] ?? $_GET['temp'] ?? $_POST['comforttemperature'] ?? $_GET['comforttemperature'] ?? 22);
                $temp = max(15, min(30, $temp));
                $functionName = 'TurnOnHeating';
                $queryParams = ['comforttemperature' => $temp];
                $humanDesc = "Włączenie grzania (Temp: {$temp}°C)";
                break;

            case 'heating_off':
                $functionName = 'TurnOffHeating';
                $humanDesc = 'Wyłączenie grzania';
                break;

            // Bypass
            case 'bypass_open':
                $functionName = 'SetByPassMode';
                $queryParams = ['mode' => 1];
                $humanDesc = 'Bypass Otwarty (Tryb letni)';
                break;

            case 'bypass_close':
                $functionName = 'SetByPassMode';
                $queryParams = ['mode' => 0];
                $humanDesc = 'Bypass Zamknięty (Tryb zimowy / rekuperacja)';
                break;

            case 'bypass_auto':
                $functionName = 'SetByPassMode';
                $queryParams = ['mode' => 2];
                $humanDesc = 'Bypass Automatyczny';
                break;

            case 'bypass':
                $mode = (int)($_POST['mode'] ?? $_GET['mode'] ?? 2);
                $functionName = 'SetByPassMode';
                $queryParams = ['mode' => $mode];
                $modeLabels = [0 => 'Zamknięty', 1 => 'Otwarty', 2 => 'Automatyczny'];
                $humanDesc = "Bypass: " . ($modeLabels[$mode] ?? "Tryb {$mode}");
                break;

            // GWC (Gruntowy Wymiennik Ciepła)
            case 'gwc_auto':
                $functionName = 'SetGWCMode';
                $queryParams = ['mode' => 2];
                $humanDesc = 'GWC Tryb Automatyczny';
                break;

            case 'gwc_on':
                $functionName = 'SetGWCMode';
                $queryParams = ['mode' => 1];
                $humanDesc = 'GWC Włączony';
                break;

            case 'gwc_off':
                $functionName = 'SetGWCMode';
                $queryParams = ['mode' => 0];
                $humanDesc = 'GWC Wyłączony';
                break;

            case 'gwc':
                $mode = (int)($_POST['mode'] ?? $_GET['mode'] ?? 2);
                $functionName = 'SetGWCMode';
                $queryParams = ['mode' => $mode];
                $gwcLabels = [0 => 'Wyłączony', 1 => 'Włączony', 2 => 'Automatyczny'];
                $humanDesc = "GWC: " . ($gwcLabels[$mode] ?? "Tryb {$mode}");
                break;

            // Wymiana filtrów
            case 'replace_filters':
                $functionName = 'ReplaceFilters';
                $humanDesc = 'Wymiana filtrów (Reset licznika)';
                break;

            default:
                $functionName = null;
                break;
        }
    }

    if ($functionName === null) {
        $response = [
            'success' => false,
            'message' => "Nieznana akcja lub brak nazwy funkcji API"
        ];
    } else {
        $apiResult = callRecuperatorApi($functionName, $queryParams, $requestedTarget);
        $targetDesc = ($requestedTarget === '1') ? 'Wentylacja 1 (10.0.0.8)' : (($requestedTarget === '2') ? 'Wentylacja 2 (10.0.0.9)' : 'Obie jednostki (10.0.0.8 + 10.0.0.9)');

        $msgParts = [];
        foreach ($apiResult['results'] as $ip => $r) {
            if ($r['success']) {
                $msgParts[] = "{$ip}: OK";
            } else {
                $msgParts[] = "{$ip}: Błąd (" . ($r['error'] ?? 'Brak odpowiedzi') . ")";
            }
        }

        $summaryMsg = "{$humanDesc} &bull; {$targetDesc} &bull; " . implode(', ', $msgParts);
        $response = array_merge([
            'message' => $summaryMsg,
            'desc' => $humanDesc,
            'target_desc' => $targetDesc
        ], $apiResult);
    }

    // Jeśli zapytanie przez fetch / AJAX, cURL, API lub CLI - zwróć JSON
    $isApiCall = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_USER_AGENT']) && stripos($_SERVER['HTTP_USER_AGENT'], 'curl') !== false)
        || isset($_GET['action']) || isset($_POST['action'])
        || isset($_GET['name']) || isset($_POST['name'])
        || isset($_GET['api']) || isset($_POST['api'])
        || php_sapi_name() === 'cli';

    if ($isApiCall) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Standardowe żądanie formularza HTML POST: zapisz flash i przekieruj
    $_SESSION['flash_message'] = $response['message'] ?? 'Polecenie przetworzone';
    $_SESSION['flash_type'] = ($response['success'] ?? false) ? 'success' : (($response['any_success'] ?? false) ? 'warning' : 'danger');
    header('Location: wentylacja.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sterowanie Wentylacją • PI1 (10.0.0.4)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #0f172a;
            --bg-card: #1e293b;
            --bg-card-hover: #243247;
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
            --cyan: #06b6d4;
            --indigo: #6366f1;
            --purple: #8b5cf6;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -2px rgba(0, 0, 0, 0.3);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: var(--bg-base);
            color: var(--text-main);
            padding: 20px 16px 60px 16px;
            min-height: 100vh;
        }

        .container {
            max-width: 980px;
            margin: 0 auto;
        }

        /* Pasek linków zewnętrznych / serwerów domowych */
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

        /* Belka nawigacji multi-server */
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

        /* Header strony */
        header {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 24px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow);
        }

        .header-title h1 {
            font-size: 1.45rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #ffffff;
        }

        .header-title p {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 4px;
        }

        /* Pasek przełącznika celu (Target Selector) */
        .target-bar {
            background: #182234;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 12px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .target-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            color: #cbd5e1;
        }

        .target-options {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .target-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: #253347;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            color: #94a3b8;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }

        .target-pill:hover {
            color: #fff;
            border-color: #475569;
        }

        .target-pill.active {
            background: #2563eb;
            border-color: #3b82f6;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.4);
        }

        .target-pill input {
            display: none;
        }

        /* Karty statusu urządzeń */
        .devices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .device-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow);
        }

        .device-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .device-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #28374d;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            flex-shrink: 0;
        }

        .device-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: #f1f5f9;
        }

        .device-ip {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-secondary { background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3); }

        /* Sekcje sterowania */
        .control-section {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            border-bottom: 1px solid rgba(51, 65, 85, 0.4);
            padding-bottom: 10px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.05rem;
            font-weight: 700;
            color: #f1f5f9;
        }

        .section-title svg {
            width: 20px;
            height: 20px;
            color: #60a5fa;
            stroke-width: 2;
        }

        .section-desc {
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        /* Przyciski akcji */
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            appearance: none;
            border: 1px solid transparent;
            outline: none;
            font-size: 0.88rem;
            font-weight: 600;
            padding: 10px 18px;
            min-height: 42px;
            border-radius: var(--radius-md);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
            color: #ffffff;
            background: #253347;
            border-color: var(--border-color);
        }

        .btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
        }

        .btn:active:not(:disabled) {
            transform: scale(0.98);
        }

        .btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn svg {
            width: 16px;
            height: 16px;
            stroke-width: 2;
            flex-shrink: 0;
        }

        /* Kolory przycisków */
        .btn-primary { background: #2563eb; border-color: rgba(255, 255, 255, 0.12); }
        .btn-primary:hover:not(:disabled) { background: #1d4ed8; }

        .btn-success { background: #059669; border-color: rgba(255, 255, 255, 0.12); }
        .btn-success:hover:not(:disabled) { background: #047857; }

        .btn-danger { background: #dc2626; border-color: rgba(255, 255, 255, 0.12); }
        .btn-danger:hover:not(:disabled) { background: #b91c1c; }

        .btn-amber { background: #d97706; border-color: rgba(255, 255, 255, 0.12); }
        .btn-amber:hover:not(:disabled) { background: #b45309; }

        .btn-cyan { background: #0891b2; border-color: rgba(255, 255, 255, 0.12); }
        .btn-cyan:hover:not(:disabled) { background: #0e7490; }

        .btn-purple { background: #7c3aed; border-color: rgba(255, 255, 255, 0.12); }
        .btn-purple:hover:not(:disabled) { background: #6d28d9; }

        .btn-secondary { background: #334155; border-color: #475569; }
        .btn-secondary:hover:not(:disabled) { background: #475569; }

        /* Formularze parametrów (wietrzenie, wakacje, temperatura, manual) */
        .param-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed rgba(51, 65, 85, 0.6);
        }

        .param-label {
            font-size: 0.82rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .param-input {
            background: #0f172a;
            border: 1px solid var(--border-color);
            color: #fff;
            padding: 8px 12px;
            border-radius: var(--radius-md);
            font-size: 0.88rem;
            width: 90px;
            text-align: center;
        }

        .param-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .param-quick-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-xs {
            padding: 5px 10px;
            min-height: 32px;
            font-size: 0.78rem;
            border-radius: 6px;
        }

        /* Spinner ładowania w przycisku */
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

        /* Powiadomienia Flash & Toasty */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 420px;
            pointer-events: none;
        }

        .toast {
            background: #1e293b;
            color: #fff;
            padding: 12px 18px;
            border-radius: var(--radius-md);
            border-left: 4px solid var(--primary);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
            font-size: 0.88rem;
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

        /* Konsola ostatnich wywołań (Live Console) */
        .log-box {
            background: #090d16;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 14px 18px;
            margin-top: 20px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.8rem;
            color: #cbd5e1;
        }

        .log-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            border-bottom: 1px solid #1e293b;
            padding-bottom: 8px;
            color: #94a3b8;
            font-weight: 600;
        }

        .log-list {
            max-height: 220px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .log-entry {
            line-height: 1.45;
            padding: 4px 0;
            border-bottom: 1px dotted rgba(51, 65, 85, 0.4);
        }

        .log-time { color: #64748b; margin-right: 8px; }
        .log-ok { color: #34d399; }
        .log-err { color: #f87171; }
        .log-url { color: #93c5fd; }

        /* Ściągawka cURL */
        .curl-box {
            background: #111827;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-top: 20px;
        }

        .curl-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #e2e8f0;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .curl-code {
            background: #030712;
            border: 1px solid #1f2937;
            border-radius: 6px;
            padding: 10px 14px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.78rem;
            color: #a5f3fc;
            overflow-x: auto;
            white-space: pre;
            line-height: 1.5;
        }

        /* Responsywność */
        @media (max-width: 640px) {
            body { padding: 10px 8px 40px 8px; }
            header { padding: 14px; }
            .header-title h1 { font-size: 1.2rem; }
            .btn { width: 100%; }
            .btn-group { flex-direction: column; }
            .param-bar { flex-direction: column; align-items: stretch; }
            .param-input { width: 100%; }
        }
    </style>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<div class="container">
    <!-- Pasek linków zewnętrznych / serwerów domowych -->
    <div class="server-links">
        <a href="index.php" class="server-link">PI1 (Rolety: Salon / Piwnica / Dach / Bramy) &rarr;</a>
        <a href="http://10.0.0.5/" class="server-link">PI2 (Rolety: Piętro 2 / Parter) &rarr;</a>
        <a href="http://10.0.0.2/" class="server-link">Cubie (Dom / Piece) &rarr;</a>
        <span class="server-link active-host">Wentylacja: 10.0.0.8 &bull; 10.0.0.9</span>
    </div>

    <!-- Belka przełączania serwerów -->
    <nav class="server-nav">
        <div class="server-nav-label">
            <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            <span>Panele:</span>
        </div>
        <div class="server-nav-links">
            <a href="index.php" class="server-nav-link" title="Sterowanie roletami na serwerze PI1">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg>
                <span>PI1: Rolety</span>
                <span class="badge-ip">10.0.0.4</span>
            </a>
            <a href="index_full.php" class="server-nav-link" title="Wszystkie przekaźniki na serwerze PI1">
                <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                <span>PI1: Przekaźniki (16)</span>
            </a>
            <a href="wentylacja.php" class="server-nav-link active" title="Sterowanie dwoma jednostkami wentylacji">
                <svg viewBox="0 0 24 24"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                <span>Wentylacja (2x)</span>
                <span class="badge-ip">10.0.0.8 &bull; 10.0.0.9</span>
            </a>
            <a href="http://10.0.0.5/" class="server-nav-link" title="Sterowanie roletami na serwerze PI2">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>PI2: Rolety</span>
                <span class="badge-ip">10.0.0.5</span>
            </a>
            <a href="http://10.0.0.2/" class="server-nav-link" title="Kotłownia i wentylacja na Cubie">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Cubie: Kotłownia</span>
                <span class="badge-ip">10.0.0.2</span>
            </a>
        </div>
    </nav>

    <header>
        <div class="header-title">
            <h1>
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                Sterowanie Wentylacją
            </h1>
            <p>Równoległe sterowanie jednostkami wentylacyjnymi • HTTP REST API • Serwer PI1 (10.0.0.4)</p>
        </div>
        <div>
            <button type="button" class="btn btn-secondary btn-xs" onclick="checkAllStatuses()" title="Odśwież stan połączenia z jednostkami">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Sprawdź połączenie
            </button>
        </div>
    </header>

    <!-- Przełącznik wyboru celu poleceń (Obie / Tylko 1 / Tylko 2) -->
    <div class="target-bar">
        <div class="target-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            <span>Cel wysyłanych poleceń:</span>
        </div>
        <div class="target-options">
            <label class="target-pill active" id="pill_both" onclick="setTarget('both')">
                <input type="radio" name="target_radio" value="both" checked>
                <span>Obie jednostki (10.0.0.8 + 10.0.0.9)</span>
            </label>
            <label class="target-pill" id="pill_1" onclick="setTarget('1')">
                <input type="radio" name="target_radio" value="1">
                <span>Tylko Wentylacja 1 (10.0.0.8)</span>
            </label>
            <label class="target-pill" id="pill_2" onclick="setTarget('2')">
                <input type="radio" name="target_radio" value="2">
                <span>Tylko Wentylacja 2 (10.0.0.9)</span>
            </label>
        </div>
    </div>

    <!-- Karty statusu urządzeń -->
    <div class="devices-grid">
        <div class="device-card" id="card_dev_1">
            <div class="device-info">
                <div class="device-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><circle cx="6" cy="6" r="1"/><circle cx="6" cy="18" r="1"/></svg>
                </div>
                <div>
                    <div class="device-name">Wentylacja 1</div>
                    <div class="device-ip">10.0.0.8</div>
                </div>
            </div>
            <div>
                <span class="badge badge-secondary" id="badge_status_1">Gotowy</span>
            </div>
        </div>

        <div class="device-card" id="card_dev_2">
            <div class="device-info">
                <div class="device-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><circle cx="6" cy="6" r="1"/><circle cx="6" cy="18" r="1"/></svg>
                </div>
                <div>
                    <div class="device-name">Wentylacja 2</div>
                    <div class="device-ip">10.0.0.9</div>
                </div>
            </div>
            <div>
                <span class="badge badge-secondary" id="badge_status_2">Gotowy</span>
            </div>
        </div>
    </div>

    <!-- 1. ZASILANIE -->
    <div class="control-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10"/></svg>
                    Zasilanie Jednostek
                </div>
                <div class="section-desc">Główne włączenie lub wyłączenie wentylatorów (TurnOn / TurnOff)</div>
            </div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-success" onclick="execCmd('turn_on', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10"/></svg>
                <span>Włącz wentylację (TurnOn)</span>
            </button>
            <button type="button" class="btn btn-danger" onclick="execCmd('turn_off', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span>Wyłącz wentylację (TurnOff)</span>
            </button>
        </div>
    </div>

    <!-- 2. GŁÓWNE TRYBY WENTYLACJI -->
    <div class="control-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                    Tryby Wentylacji (Przepływ Powietrza)
                </div>
                <div class="section-desc">Regulacja wydajności wentylacji: Automatyczny, Normalny 100% lub Cichy/Wolny</div>
            </div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-primary" onclick="execCmd('auto', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Tryb Auto (AutomaticMode)</span>
            </button>
            <button type="button" class="btn btn-cyan" onclick="execCmd('normal', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span>Tryb Normalny (100% / 100%)</span>
            </button>
            <button type="button" class="btn btn-amber" onclick="execCmd('slow', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 12h20M12 2v20M5 5l14 14"/></svg>
                <span>Tryb Wolny (80% / 70%)</span>
            </button>
        </div>

        <!-- Własne parametry ManualMode -->
        <div class="param-bar">
            <span class="param-label">Własna regulacja prędkości:</span>
            <label class="param-label">Nawiew:
                <input type="number" id="custom_airflow" class="param-input" min="10" max="100" value="85" step="5"> %
            </label>
            <label class="param-label">Wywiew:
                <input type="number" id="custom_extraction" class="param-input" min="10" max="100" value="75" step="5"> %
            </label>
            <button type="button" class="btn btn-secondary btn-xs" onclick="applyCustomManual(this)">
                <span>Zastosuj nastawę ręczną</span>
            </button>
        </div>
    </div>

    <!-- 3. WIETRZENIE (AIRING) -->
    <div class="control-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                    Przewietrzanie (Airing)
                </div>
                <div class="section-desc">Intensywne wietrzenie pomieszczeń z automatycznym wyłączeniem po zadanym czasie</div>
            </div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-cyan" onclick="execCmd('airing_on', { time: getAiringTime() }, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span>Włącz wietrzenie</span>
            </button>
            <button type="button" class="btn btn-danger" onclick="execCmd('airing_off', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span>Wyłącz wietrzenie (TurnOffAiring)</span>
            </button>
        </div>
        <div class="param-bar">
            <span class="param-label">Czas wietrzenia (minuty):</span>
            <input type="number" id="input_airing_time" class="param-input" min="1" max="180" value="20">
            <div class="param-quick-btns">
                <button type="button" class="btn btn-secondary btn-xs" onclick="setAiringInput(10)">10 min</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setAiringInput(20)">20 min</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setAiringInput(30)">30 min</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setAiringInput(60)">60 min</button>
            </div>
        </div>
    </div>

    <!-- 4. TRYB WAKACJE -->
    <div class="control-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                    Tryb Wakacje (Holiday)
                </div>
                <div class="section-desc">Minimalna wymiana powietrza na czas nieobecności domowników</div>
            </div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-purple" onclick="execCmd('holiday_on', { days: getHolidayDays() }, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>Włącz Wakacje</span>
            </button>
            <button type="button" class="btn btn-secondary" onclick="execCmd('holiday_off', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <span>Wyłącz Wakacje (TurnOffHoliday)</span>
            </button>
        </div>
        <div class="param-bar">
            <span class="param-label">Liczba dni wakacji:</span>
            <input type="number" id="input_holiday_days" class="param-input" min="1" max="99" value="1">
            <div class="param-quick-btns">
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHolidayInput(1)">1 dzień</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHolidayInput(3)">3 dni</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHolidayInput(7)">7 dni</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHolidayInput(14)">14 dni</button>
            </div>
        </div>
    </div>

    <!-- 5. OGRZEWANIE (HEATING) -->
    <div class="control-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                    Ogrzewanie / Nagrzewnica (Heating)
                </div>
                <div class="section-desc">Załączenie dogrzewania powietrza nawiewanego z nastawą temperatury komfortu</div>
            </div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-amber" onclick="execCmd('heating_on', { temp: getHeatingTemp() }, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span>Włącz Grzanie</span>
            </button>
            <button type="button" class="btn btn-secondary" onclick="execCmd('heating_off', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <span>Wyłącz Grzanie (TurnOffHeating)</span>
            </button>
        </div>
        <div class="param-bar">
            <span class="param-label">Temperatura komfortu:</span>
            <input type="number" id="input_heating_temp" class="param-input" min="15" max="30" value="22"> °C
            <div class="param-quick-btns">
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHeatingTemp(20)">20°C</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHeatingTemp(21)">21°C</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHeatingTemp(22)">22°C</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHeatingTemp(23)">23°C</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="setHeatingTemp(24)">24°C</button>
            </div>
        </div>
    </div>

    <!-- 6. BYPASS -->
    <div class="control-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
                    Bypass (Obejście Wymiennika)
                </div>
                <div class="section-desc">Tryb pracy klapy obejścia: Automatyczny (mode=2), Otwarty - letni (mode=1) lub Zamknięty (mode=0)</div>
            </div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-primary" onclick="execCmd('bypass_auto', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                <span>Bypass Automatyczny (mode=2)</span>
            </button>
            <button type="button" class="btn btn-cyan" onclick="execCmd('bypass_open', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 17l9.2-9.2M17 17V7H7"/></svg>
                <span>Bypass Otwarty (mode=1)</span>
            </button>
            <button type="button" class="btn btn-secondary" onclick="execCmd('bypass_close', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Bypass Zamknięty (mode=0)</span>
            </button>
        </div>
    </div>

    <!-- 7. GWC (GRUNTOWY WYMIENNIK CIEPŁA) -->
    <div class="control-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    GWC (Gruntowy Wymiennik Ciepła)
                </div>
                <div class="section-desc">Sterowanie czerpnią gruntową GWC: Auto (mode=2), Włączona (mode=1) lub Wyłączona (mode=0)</div>
            </div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-primary" onclick="execCmd('gwc_auto', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                <span>GWC Auto (mode=2)</span>
            </button>
            <button type="button" class="btn btn-success" onclick="execCmd('gwc_on', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>
                <span>GWC Włącz (mode=1)</span>
            </button>
            <button type="button" class="btn btn-secondary" onclick="execCmd('gwc_off', {}, this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <span>GWC Wyłącz (mode=0)</span>
            </button>
        </div>
    </div>

    <!-- 8. FILTRY / SERWIS -->
    <div class="control-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
                    Filtry i Serwis
                </div>
                <div class="section-desc">Zatwierdzenie wymiany filtrów i zresetowanie licznika czasu pracy (ReplaceFilters)</div>
            </div>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-amber" onclick="confirmReplaceFilters(this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                <span>Potwierdź wymianę filtrów (ReplaceFilters)</span>
            </button>
        </div>
    </div>

    <!-- KONSOLA OSTATNICH ZAPYTAŃ / LIVE LOG -->
    <div class="log-box">
        <div class="log-header">
            <span>Rejestr Ostatnich Zapytań API (Live Log)</span>
            <button type="button" class="btn btn-secondary btn-xs" onclick="clearLog()">Wyczyść</button>
        </div>
        <div class="log-list" id="logList">
            <div class="log-entry" style="color: #64748b;">Panel załadowany. Gotowy do wysyłania poleceń do obu rekuperatorów.</div>
        </div>
    </div>

    <!-- ŚCIĄGAWKA POLECEŃ CURL -->
    <div class="curl-box">
        <div class="curl-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
            <span>Przykłady Wywołań cURL z Konsoli / Home Assistant / Skryptów Bash:</span>
        </div>
        <div class="curl-code"># 1. Wysyłanie przez serwer PI1 do OBU jednostek wentylacji jednocześnie:
curl -s "http://10.0.0.4/wentylacja.php?action=auto"
curl -s "http://10.0.0.4/wentylacja.php?action=normal"
curl -s "http://10.0.0.4/wentylacja.php?action=slow"
curl -s "http://10.0.0.4/wentylacja.php?action=airing_on&time=20"
curl -s "http://10.0.0.4/wentylacja.php?action=airing_off"
curl -s "http://10.0.0.4/wentylacja.php?action=holiday_on&days=1"
curl -s "http://10.0.0.4/wentylacja.php?action=heating_on&temp=22"
curl -s "http://10.0.0.4/wentylacja.php?action=bypass&mode=1"
curl -s "http://10.0.0.4/wentylacja.php?action=gwc&mode=2"
curl -s "http://10.0.0.4/wentylacja.php?action=replace_filters"

# 2. Bezpośrednie przekazywanie nazwy funkcji RunFunction:
curl -s "http://10.0.0.4/wentylacja.php?name=AutomaticMode"
curl -s "http://10.0.0.4/wentylacja.php?name=ManualMode&airflowvalue=80&valueofairextraction=70"
curl -s "http://10.0.0.4/wentylacja.php?name=SetByPassMode&mode=1"

# 3. Wybór tylko jednej jednostki (opcjonalny target):
curl -s "http://10.0.0.4/wentylacja.php?action=auto&target=1"   # tylko 10.0.0.8
curl -s "http://10.0.0.4/wentylacja.php?action=auto&target=2"   # tylko 10.0.0.9</div>
    </div>
</div>

<script>
    // Zmienna wybranego celu (domyślnie 'both')
    let currentTarget = 'both';

    function setTarget(target) {
        currentTarget = target;
        document.querySelectorAll('.target-pill').forEach(pill => pill.classList.remove('active'));
        const activePill = document.getElementById('pill_' + target);
        if (activePill) activePill.classList.add('active');
        showToast("Zmieniono cel poleceń: " + (target === '1' ? 'Wentylacja 1 (10.0.0.8)' : (target === '2' ? 'Wentylacja 2 (10.0.0.9)' : 'Obie jednostki (10.0.0.8 + 10.0.0.9)')), 'info');
    }

    function getAiringTime() {
        const val = parseInt(document.getElementById('input_airing_time').value, 10);
        return isNaN(val) ? 20 : Math.max(1, Math.min(180, val));
    }

    function setAiringInput(mins) {
        document.getElementById('input_airing_time').value = mins;
    }

    function getHolidayDays() {
        const val = parseInt(document.getElementById('input_holiday_days').value, 10);
        return isNaN(val) ? 1 : Math.max(1, Math.min(99, val));
    }

    function setHolidayInput(days) {
        document.getElementById('input_holiday_days').value = days;
    }

    function getHeatingTemp() {
        const val = parseInt(document.getElementById('input_heating_temp').value, 10);
        return isNaN(val) ? 22 : Math.max(15, Math.min(30, val));
    }

    function setHeatingTemp(temp) {
        document.getElementById('input_heating_temp').value = temp;
    }

    function applyCustomManual(btn) {
        const airflow = parseInt(document.getElementById('custom_airflow').value, 10) || 100;
        const extraction = parseInt(document.getElementById('custom_extraction').value, 10) || 100;
        execCmd('manual', { airflow: airflow, extraction: extraction }, btn);
    }

    function confirmReplaceFilters(btn) {
        if (confirm("Czy na pewno chcesz zresetować licznik wymiany filtrów w jednostkach wentylacji (ReplaceFilters)?")) {
            execCmd('replace_filters', {}, btn);
        }
    }

    // Wykonanie polecenia przez AJAX z natychmiastowym feedbackiem (Optimistic UI)
    async function execCmd(action, params = {}, btnElement = null) {
        let originalContent = '';
        if (btnElement) {
            btnElement.disabled = true;
            originalContent = btnElement.innerHTML;
            btnElement.innerHTML = '<span class="spinner"></span> <span>Wysyłanie...</span>';
        }

        const payload = new URLSearchParams();
        payload.append('action', action);
        payload.append('target', currentTarget);
        for (const [key, value] of Object.entries(params)) {
            payload.append(key, value);
        }

        const now = new Date().toLocaleTimeString();
        appendLog(`[${now}] Wysyłanie polecenia '${action}' (${currentTarget})...`, 'info');

        try {
            const response = await fetch('wentylacja.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: payload.toString()
            });

            const data = await response.json();
            handleCmdResponse(data);
        } catch (err) {
            appendLog(`Błąd komunikacji z serwerem PI1: ${err.message}`, 'error');
            showToast(`Błąd sieci: ${err.message}`, 'danger');
        } finally {
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = originalContent;
            }
        }
    }

    function handleCmdResponse(data) {
        const now = new Date().toLocaleTimeString();
        const results = data.results || {};

        let okCount = 0;
        let errCount = 0;

        for (const [ip, res] of Object.entries(results)) {
            if (res.success) {
                okCount++;
                appendLog(`[${now}] <strong>${ip}</strong>: Sukces (HTTP ${res.http_code}, ${res.time_sec}s) - ${res.url}`, 'success');
            } else {
                errCount++;
                appendLog(`[${now}] <strong>${ip}</strong>: Niepowodzenie (${res.error || 'Błąd połączenia'}) - ${res.url}`, 'error');
            }
        }

        if (okCount > 0 && errCount === 0) {
            showToast(data.message || "Polecenie wykonane pomyślnie!", "success");
        } else if (okCount > 0 && errCount > 0) {
            showToast(data.message || "Wykonano z ostrzeżeniami dla części jednostek", "warning");
        } else {
            showToast(data.message || "Brak odpowiedzi od jednostek (urządzenia mogą być wyłączone)", "danger");
        }
    }

    // Sprawdzenie dostępności obu jednostek
    async function checkAllStatuses() {
        const badge1 = document.getElementById('badge_status_1');
        const badge2 = document.getElementById('badge_status_2');
        badge1.className = 'badge badge-warning';
        badge1.textContent = 'Sprawdzanie...';
        badge2.className = 'badge badge-warning';
        badge2.textContent = 'Sprawdzanie...';

        try {
            const response = await fetch('wentylacja.php?api=status', { credentials: 'same-origin' });
            const data = await response.json();
            const statuses = data.statuses || {};

            if (statuses['10.0.0.8']) {
                const s1 = statuses['10.0.0.8'];
                badge1.className = 'badge ' + s1.status_class;
                badge1.textContent = s1.online ? `Dostępny (${s1.latency_ms} ms)` : 'Wyłączony';
            }
            if (statuses['10.0.0.9']) {
                const s2 = statuses['10.0.0.9'];
                badge2.className = 'badge ' + s2.status_class;
                badge2.textContent = s2.online ? `Dostępny (${s2.latency_ms} ms)` : 'Wyłączony';
            }

            appendLog(`Status łączności odświeżony: 10.0.0.8 (${statuses['10.0.0.8']?.online ? 'Online' : 'Offline'}), 10.0.0.9 (${statuses['10.0.0.9']?.online ? 'Online' : 'Offline'})`, 'info');
        } catch (e) {
            badge1.className = 'badge badge-danger';
            badge1.textContent = 'Błąd testu';
            badge2.className = 'badge badge-danger';
            badge2.textContent = 'Błąd testu';
        }
    }
            const data = await response.json();
            const statuses = data.statuses || {};

            if (statuses['10.0.0.8']) {
                const s1 = statuses['10.0.0.8'];
                badge1.className = 'badge ' + s1.status_class;
                badge1.textContent = s1.online ? `Dostępny (${s1.latency_ms} ms)` : 'Wyłączony';
            }
            if (statuses['10.0.0.9']) {
                const s2 = statuses['10.0.0.9'];
                badge2.className = 'badge ' + s2.status_class;
                badge2.textContent = s2.online ? `Dostępny (${s2.latency_ms} ms)` : 'Wyłączony';
            }

            appendLog(`Status łączności odświeżony: 10.0.0.8 (${statuses['10.0.0.8']?.online ? 'Online' : 'Offline'}), 10.0.0.9 (${statuses['10.0.0.9']?.online ? 'Online' : 'Offline'})`, 'info');
        } catch (e) {
            badge1.className = 'badge badge-danger';
            badge1.textContent = 'Błąd testu';
            badge2.className = 'badge badge-danger';
            badge2.textContent = 'Błąd testu';
        }
    }

    function appendLog(htmlContent, type = 'info') {
        const logList = document.getElementById('logList');
        const entry = document.createElement('div');
        entry.className = 'log-entry';

        let color = '#cbd5e1';
        if (type === 'success') color = '#34d399';
        if (type === 'error') color = '#f87171';
        if (type === 'warning') color = '#fbbf24';

        entry.style.color = color;
        entry.innerHTML = htmlContent;

        logList.insertBefore(entry, logList.firstChild);
        while (logList.children.length > 35) {
            logList.removeChild(logList.lastChild);
        }
    }

    function clearLog() {
        document.getElementById('logList').innerHTML = '<div class="log-entry" style="color: #64748b;">Rejestr wyczyszczony.</div>';
    }

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
        }, 4000);
    }

    // Automatyczne sprawdzenie statusu po załadowaniu strony
    window.addEventListener('DOMContentLoaded', () => {
        checkAllStatuses();
    });
</script>

<?php if (!empty($_SESSION['flash_message'])): ?>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            showToast("<?= htmlspecialchars(addslashes($_SESSION['flash_message'])) ?>", "<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>");
        });
    </script>
    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
<?php endif; ?>

</body>
</html>
