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

            $parsedData = null;
            if ($isOk && !empty($content)) {
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $parsedData = $decoded;
                }
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
                'data' => $parsedData,
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

            $parsedData = null;
            if ($isOk && !empty($body)) {
                $decoded = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $parsedData = $decoded;
                }
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
                'data' => $parsedData,
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

/**
 * Pobiera i tłumaczy aktualne parametry pracy oraz temperatury z urządzeń wentylacji
 * 
 * Zapytanie API do urządzeń:
 * /API/RunFunction?name=GetTemperatures
 *
 * Mapowanie i tłumaczenie parametrów:
 * - Intake: Zewnątrz (czerpnia)
 * - Launcher: Usuwanie (wyrzutnia)
 * - Supply: Nawiew (do pomieszczeń)
 * - Extract: Wywiew (z pomieszczeń)
 * - HeaterCooler: Nagrzewnica
 * - HeaterCoolerActive: Nagrzewnica włączona (1) / wyłączona (0)
 * - GWC: GWC (gruntowy wymiennik ciepła)
 * - GWCActive: GWC włączone (1) / wyłączone (0)
 * - Room: Pokój
 * - RoomTempActive: Temp. pokoju aktywna (1) / nieaktywna (0)
 * - Additional: Dodatkowa
 * - AdditionalTempActive: Temp. dodatkowa aktywna (1) / nieaktywna (0)
 *
 * @param string $target 'both' (domyślnie), '1' (10.0.0.8), '2' (10.0.0.9)
 * @return array
 */
function getRecuperatorTemperatures($target = 'both') {
    $apiResult = callRecuperatorApi('GetTemperatures', [], $target);
    $devices = [];
    $allOk = true;
    $anyOk = false;

    foreach ($apiResult['results'] as $ip => $item) {
        $raw = $item['data'] ?? null;
        if ($raw === null && !empty($item['response'])) {
            $raw = json_decode($item['response'], true);
        }

        $isValid = is_array($raw) && (!isset($raw['GetTemperaturesResult']) || $raw['GetTemperaturesResult'] === true);
        $isSuccess = $item['success'] && $isValid;

        if ($isSuccess) {
            $anyOk = true;
        } else {
            $allOk = false;
        }

        $params = null;
        if ($isValid) {
            $params = [
                'intake' => isset($raw['Intake']) ? round((float)$raw['Intake'], 1) : null,
                'intake_label' => 'Zewnątrz',
                'launcher' => isset($raw['Launcher']) ? round((float)$raw['Launcher'], 1) : null,
                'launcher_label' => 'Usuwanie',
                'supply' => isset($raw['Supply']) ? round((float)$raw['Supply'], 1) : null,
                'supply_label' => 'Nawiew',
                'extract' => isset($raw['Extract']) ? round((float)$raw['Extract'], 1) : null,
                'extract_label' => 'Wywiew',
                'heater_cooler' => isset($raw['HeaterCooler']) ? round((float)$raw['HeaterCooler'], 1) : null,
                'heater_cooler_active' => !empty($raw['HeaterCoolerActive']) ? 1 : 0,
                'heater_cooler_label' => 'Nagrzewnica',
                'gwc' => isset($raw['GWC']) ? round((float)$raw['GWC'], 1) : null,
                'gwc_active' => !empty($raw['GWCActive']) ? 1 : 0,
                'gwc_label' => 'GWC',
                'room' => isset($raw['Room']) ? round((float)$raw['Room'], 1) : null,
                'room_active' => !empty($raw['RoomTempActive']) ? 1 : 0,
                'room_label' => 'Pokój',
                'additional' => isset($raw['Additional']) ? round((float)$raw['Additional'], 1) : null,
                'additional_active' => !empty($raw['AdditionalTempActive']) ? 1 : 0,
                'additional_label' => 'Dodatkowa'
            ];
        }

        $devices[$ip] = [
            'ip' => $ip,
            'name' => $item['name'],
            'online' => $item['success'],
            'success' => $isSuccess,
            'http_code' => $item['http_code'] ?? 0,
            'latency_ms' => round(($item['time_sec'] ?? 0) * 1000),
            'time_sec' => $item['time_sec'] ?? 0,
            'error' => $item['error'] ?? ($isValid ? null : 'Błąd odczytu danych z rekuperatora'),
            'parameters' => $params,
            'raw' => $raw
        ];
    }

    return [
        'success' => $allOk,
        'any_success' => $anyOk,
        'timestamp' => date('Y-m-d H:i:s'),
        'target' => $target,
        'devices' => $devices
    ];
}

// =========================================================================
// Obsługa poleceń (API JSON / POST / GET)
// =========================================================================

$requestedAction = $_POST['action'] ?? $_GET['action'] ?? null;
$requestedName = $_POST['name'] ?? $_GET['name'] ?? null;
$requestedTarget = $_POST['target'] ?? $_GET['target'] ?? 'both';

// Sprawdzenie czy żądanie to odpytanie o temperatury / parametry pracy
if ((isset($_GET['api']) && in_array(strtolower($_GET['api']), ['temperatures', 'temp', 'temps', 'gettemperatures'], true))
    || $requestedAction === 'get_temperatures'
    || $requestedAction === 'temperatures') {
    header('Content-Type: application/json; charset=utf-8');
    $temps = getRecuperatorTemperatures($requestedTarget);
    echo json_encode($temps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

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

            // Odczyt parametrów pracy i temperatur
            case 'get_temperatures':
            case 'temperatures':
                $functionName = 'GetTemperatures';
                $humanDesc = 'Pobranie temperatur i parametrów pracy';
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

        /* Karty statusu urządzeń i dashboard parametrów */
        .params-section {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }

        .params-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .last-update-time {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        .devices-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 16px;
            margin-top: 14px;
        }

        .device-card-large {
            background: #141c2c;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
            transition: all 0.2s ease;
        }

        .device-card-large:hover {
            border-color: #3b82f6;
        }

        .dev-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border-bottom: 1px solid rgba(51, 65, 85, 0.5);
            padding-bottom: 12px;
        }

        .dev-info-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dev-badge-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            flex-shrink: 0;
        }

        .dev-badge-icon svg {
            width: 20px;
            height: 20px;
            stroke-width: 2;
        }

        .dev-title-text {
            font-size: 1rem;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dev-ip-tag {
            font-size: 0.76rem;
            color: #93c5fd;
            background: rgba(59, 130, 246, 0.15);
            padding: 2px 7px;
            border-radius: 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        .dev-subtitle-text {
            font-size: 0.76rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* 4 główne temperatury w pętli wentylacji */
        .temps-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .temp-box {
            background: #0d1424;
            border: 1px solid rgba(51, 65, 85, 0.7);
            border-radius: 8px;
            padding: 10px 8px;
            text-align: center;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 86px;
            transition: all 0.2s ease;
        }

        .temp-box:hover {
            background: #111a2f;
            transform: translateY(-2px);
        }

        .temp-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        /* Akcenty kolorystyczne dla toru powietrza */
        .temp-box.box-intake::before { background: #38bdf8; }     /* Zewnątrz - błękitny */
        .temp-box.box-supply::before { background: #10b981; }     /* Nawiew - zielony */
        .temp-box.box-extract::before { background: #f59e0b; }    /* Wywiew - pomarańczowy */
        .temp-box.box-launcher::before { background: #818cf8; }   /* Usuwanie - indygo */

        .temp-box-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .temp-box-val {
            font-size: 1.35rem;
            font-weight: 700;
            color: #ffffff;
            margin: 4px 0;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .temp-box-val small {
            font-size: 0.78rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-left: 2px;
        }

        .temp-box-sub {
            font-size: 0.68rem;
            color: #64748b;
        }

        /* Podzespoły i czujniki (Nagrzewnica, GWC, Pokój, Dodatkowa) */
        .components-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }

        .comp-card {
            background: #0d1424;
            border: 1px solid rgba(51, 65, 85, 0.5);
            border-radius: 8px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .comp-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .comp-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: rgba(30, 41, 59, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            flex-shrink: 0;
        }

        .comp-icon svg {
            width: 15px;
            height: 15px;
            stroke-width: 2;
        }

        .comp-name {
            font-size: 0.78rem;
            font-weight: 600;
            color: #cbd5e1;
        }

        .comp-temp {
            font-size: 0.88rem;
            font-weight: 700;
            color: #ffffff;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        .comp-status-badge {
            font-size: 0.68rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }

        .comp-status-on {
            background: rgba(16, 185, 129, 0.18);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }

        .comp-status-warn {
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }

        .comp-status-off {
            background: rgba(148, 163, 184, 0.1);
            color: #64748b;
            border: 1px solid rgba(148, 163, 184, 0.2);
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
        <a href="http://10.0.0.118/" class="server-link">PI0 (Kotłownia: Piec CWU / Piec CO / Pompa) &rarr;</a>
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
            <a href="http://10.0.0.118/" class="server-nav-link" title="Sterowanie kotłownią na serwerze PI0">
                <svg viewBox="0 0 24 24"><path d="M12 2c1.1 0 2 .9 2 2 0 .74-.4 1.38-1 1.72v1.56a5 5 0 0 1 4 4.9v2.82l1.7 1.7A1 1 0 0 1 18 18H6a1 1 0 0 1-.7-1.7l1.7-1.7v-2.82a5 5 0 0 1 4-4.9V5.72C10.4 5.38 10 4.74 10 4c0-1.1.9-2 2-2z"/><path d="M12 9a3 3 0 0 0-3 3v3h6v-3a3 3 0 0 0-3-3z"/></svg>
                <span>PI0: Kotłownia</span>
                <span class="badge-ip">10.0.0.118</span>
            </a>
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

    <!-- KARTY AKTUALNYCH PARAMETRÓW PRACY URZĄDZEŃ -->
    <div class="control-section params-section">
        <div class="section-header">
            <div>
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/></svg>
                    Aktualne Parametry Pracy Urządzeń
                </div>
                <div class="section-desc">Pomiary w czasie rzeczywistym z czujników temperatury obu jednostek wentylacyjnych (/API/RunFunction?name=GetTemperatures)</div>
            </div>
            <div class="params-header-actions">
                <span class="last-update-time" id="lastTempUpdate">Oczekiwanie na dane...</span>
                <button type="button" class="btn btn-secondary btn-xs" id="btnRefreshTemps" onclick="refreshTemperatures()" title="Odśwież aktualne temperatury">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    <span>Odśwież parametry</span>
                </button>
            </div>
        </div>

        <div class="devices-dashboard">
            <!-- Karta Wentylacja 1 (10.0.0.8) -->
            <div class="device-card-large" id="card_dev_1">
                <div class="dev-header-row">
                    <div class="dev-info-left">
                        <div class="dev-badge-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><circle cx="6" cy="6" r="1"/><circle cx="6" cy="18" r="1"/></svg>
                        </div>
                        <div>
                            <div class="dev-title-text">
                                <span>Wentylacja 1</span>
                                <span class="dev-ip-tag">10.0.0.8</span>
                            </div>
                            <div class="dev-subtitle-text">Jednostka główna</div>
                        </div>
                    </div>
                    <div>
                        <span class="badge badge-secondary" id="badge_status_1">Pobieranie...</span>
                    </div>
                </div>

                <!-- 4 Główne temperatury w torze powietrza -->
                <div class="temps-grid-4">
                    <div class="temp-box box-intake" title="Intake: Temperatura powietrza czerpanego z zewnątrz">
                        <div class="temp-box-label">Zewnątrz</div>
                        <div class="temp-box-val" id="val_intake_1">--.- <small>°C</small></div>
                        <div class="temp-box-sub">Czerpnia</div>
                    </div>

                    <div class="temp-box box-supply" title="Supply: Temperatura powietrza nawiewanego do pomieszczeń">
                        <div class="temp-box-label">Nawiew</div>
                        <div class="temp-box-val" id="val_supply_1">--.- <small>°C</small></div>
                        <div class="temp-box-sub">Do pokoi</div>
                    </div>

                    <div class="temp-box box-extract" title="Extract: Temperatura powietrza wywiewanego z pomieszczeń">
                        <div class="temp-box-label">Wywiew</div>
                        <div class="temp-box-val" id="val_extract_1">--.- <small>°C</small></div>
                        <div class="temp-box-sub">Z pokoi</div>
                    </div>

                    <div class="temp-box box-launcher" title="Launcher: Temperatura powietrza usuwanego na zewnątrz">
                        <div class="temp-box-label">Usuwanie</div>
                        <div class="temp-box-val" id="val_launcher_1">--.- <small>°C</small></div>
                        <div class="temp-box-sub">Wyrzutnia</div>
                    </div>
                </div>

                <!-- Podzespoły: Nagrzewnica, GWC -->
                <div class="components-row">
                    <!-- Nagrzewnica -->
                    <div class="comp-card" title="Nagrzewnica (HeaterCooler)">
                        <div class="comp-left">
                            <div class="comp-icon" style="color:#fbbf24;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                            </div>
                            <div>
                                <div class="comp-name">Nagrzewnica</div>
                                <div class="comp-temp" id="val_heater_1">--.- °C</div>
                            </div>
                        </div>
                        <span class="comp-status-badge comp-status-off" id="badge_heater_1">--</span>
                    </div>

                    <!-- GWC -->
                    <div class="comp-card" title="Gruntowy Wymiennik Ciepła (GWC)">
                        <div class="comp-left">
                            <div class="comp-icon" style="color:#34d399;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <div>
                                <div class="comp-name">GWC</div>
                                <div class="comp-temp" id="val_gwc_1">--.- °C</div>
                            </div>
                        </div>
                        <span class="comp-status-badge comp-status-off" id="badge_gwc_1">--</span>
                    </div>
                </div>
            </div>

            <!-- Karta Wentylacja 2 (10.0.0.9) -->
            <div class="device-card-large" id="card_dev_2">
                <div class="dev-header-row">
                    <div class="dev-info-left">
                        <div class="dev-badge-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><circle cx="6" cy="6" r="1"/><circle cx="6" cy="18" r="1"/></svg>
                        </div>
                        <div>
                            <div class="dev-title-text">
                                <span>Wentylacja 2</span>
                                <span class="dev-ip-tag">10.0.0.9</span>
                            </div>
                            <div class="dev-subtitle-text">Jednostka dodatkowa</div>
                        </div>
                    </div>
                    <div>
                        <span class="badge badge-secondary" id="badge_status_2">Pobieranie...</span>
                    </div>
                </div>

                <!-- 4 Główne temperatury w torze powietrza -->
                <div class="temps-grid-4">
                    <div class="temp-box box-intake" title="Intake: Temperatura powietrza czerpanego z zewnątrz">
                        <div class="temp-box-label">Zewnątrz</div>
                        <div class="temp-box-val" id="val_intake_2">--.- <small>°C</small></div>
                        <div class="temp-box-sub">Czerpnia</div>
                    </div>

                    <div class="temp-box box-supply" title="Supply: Temperatura powietrza nawiewanego do pomieszczeń">
                        <div class="temp-box-label">Nawiew</div>
                        <div class="temp-box-val" id="val_supply_2">--.- <small>°C</small></div>
                        <div class="temp-box-sub">Do pokoi</div>
                    </div>

                    <div class="temp-box box-extract" title="Extract: Temperatura powietrza wywiewanego z pomieszczeń">
                        <div class="temp-box-label">Wywiew</div>
                        <div class="temp-box-val" id="val_extract_2">--.- <small>°C</small></div>
                        <div class="temp-box-sub">Z pokoi</div>
                    </div>

                    <div class="temp-box box-launcher" title="Launcher: Temperatura powietrza usuwanego na zewnątrz">
                        <div class="temp-box-label">Usuwanie</div>
                        <div class="temp-box-val" id="val_launcher_2">--.- <small>°C</small></div>
                        <div class="temp-box-sub">Wyrzutnia</div>
                    </div>
                </div>

                <!-- Podzespoły: Nagrzewnica, GWC -->
                <div class="components-row">
                    <!-- Nagrzewnica -->
                    <div class="comp-card" title="Nagrzewnica (HeaterCooler)">
                        <div class="comp-left">
                            <div class="comp-icon" style="color:#fbbf24;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                            </div>
                            <div>
                                <div class="comp-name">Nagrzewnica</div>
                                <div class="comp-temp" id="val_heater_2">--.- °C</div>
                            </div>
                        </div>
                        <span class="comp-status-badge comp-status-off" id="badge_heater_2">--</span>
                    </div>

                    <!-- GWC -->
                    <div class="comp-card" title="Gruntowy Wymiennik Ciepła (GWC)">
                        <div class="comp-left">
                            <div class="comp-icon" style="color:#34d399;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </div>
                            <div>
                                <div class="comp-name">GWC</div>
                                <div class="comp-temp" id="val_gwc_2">--.- °C</div>
                            </div>
                        </div>
                        <span class="comp-status-badge comp-status-off" id="badge_gwc_2">--</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 1. TRYBY WENTYLACJI -->
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

    <!-- 2. WIETRZENIE (AIRING) -->
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

    <!-- 3. BYPASS -->
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

    <!-- 4. GWC (GRUNTOWY WYMIENNIK CIEPŁA) -->
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

    <!-- 6. TRYB WAKACJE -->
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

    <!-- 7. ZASILANIE JEDNOSTEK -->
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
            <div class="log-entry" style="color: #64748b;">Panel załadowany. Gotowy do wysyłania poleceń do obu jednostek wentylacyjnych.</div>
        </div>
    </div>
</div>

<script>
    // Zmienna wybranego celu (domyślnie 'both')
    let currentTarget = 'both';
    let isRefreshingTemps = false;

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

            // Po wykonaniu polecenia odśwież parametry z lekkim opóźnieniem
            setTimeout(refreshTemperatures, 1200);
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

    // Pobranie i odświeżenie temperatur oraz parametrów pracy w czasie rzeczywistym
    async function refreshTemperatures() {
        if (isRefreshingTemps) return;
        isRefreshingTemps = true;

        const btn = document.getElementById('btnRefreshTemps');
        const updateLabel = document.getElementById('lastTempUpdate');
        let origHtml = '';
        if (btn) {
            btn.disabled = true;
            origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner" style="width:12px;height:12px;border-width:1.5px;"></span> <span>Odświeżanie...</span>';
        }

        try {
            const response = await fetch('wentylacja.php?api=temperatures', { credentials: 'same-origin' });
            const data = await response.json();
            const devices = data.devices || {};

            updateDeviceCard('1', '10.0.0.8', devices['10.0.0.8']);
            updateDeviceCard('2', '10.0.0.9', devices['10.0.0.9']);

            const now = new Date().toLocaleTimeString();
            if (updateLabel) {
                updateLabel.textContent = `Odświeżono: ${now}`;
            }
        } catch (err) {
            console.error('Błąd pobierania temperatur:', err);
            if (updateLabel) {
                updateLabel.textContent = 'Błąd odczytu danych';
            }
        } finally {
            isRefreshingTemps = false;
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }
    }

    // Aktualizacja widoku pojedynczej karty jednostki
    function updateDeviceCard(id, ip, devData) {
        const badge = document.getElementById(`badge_status_${id}`);

        if (!devData || !devData.online || !devData.parameters) {
            if (badge) {
                badge.className = 'badge badge-danger';
                badge.textContent = devData?.error || 'Wyłączony / Brak danych';
            }
            return;
        }

        if (badge) {
            badge.className = 'badge badge-success';
            badge.textContent = `Dostępny (${devData.latency_ms} ms)`;
        }

        const p = devData.parameters;

        // 4 Główne temperatury w torze wentylacji
        setMetricVal(`val_intake_${id}`, p.intake);
        setMetricVal(`val_supply_${id}`, p.supply);
        setMetricVal(`val_extract_${id}`, p.extract);
        setMetricVal(`val_launcher_${id}`, p.launcher);

        // Nagrzewnica
        setMetricVal(`val_heater_${id}`, p.heater_cooler);
        const bHeater = document.getElementById(`badge_heater_${id}`);
        if (bHeater) {
            if (p.heater_cooler_active === 1) {
                bHeater.className = 'comp-status-badge comp-status-warn';
                bHeater.textContent = 'Wł';
            } else {
                bHeater.className = 'comp-status-badge comp-status-off';
                bHeater.textContent = 'Wył';
            }
        }

        // GWC
        setMetricVal(`val_gwc_${id}`, p.gwc);
        const bGwc = document.getElementById(`badge_gwc_${id}`);
        if (bGwc) {
            if (p.gwc_active === 1) {
                bGwc.className = 'comp-status-badge comp-status-on';
                bGwc.textContent = 'Wł';
            } else {
                bGwc.className = 'comp-status-badge comp-status-off';
                bGwc.textContent = 'Wył';
            }
        }
    }

    function setMetricVal(elemId, val) {
        const el = document.getElementById(elemId);
        if (!el) return;
        if (val === null || val === undefined || isNaN(val)) {
            el.innerHTML = '--.- <small>°C</small>';
        } else {
            el.innerHTML = `${Number(val).toFixed(1)} <small>°C</small>`;
        }
    }

    // Sprawdzenie dostępności obu jednostek (używane przez przycisk w nagłówku)
    async function checkAllStatuses() {
        await refreshTemperatures();
        appendLog('Sprawdzono połączenie i odświeżono parametry urządzeń.', 'info');
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

    // Automatyczne sprawdzenie statusu i pobranie parametrów po załadowaniu strony
    window.addEventListener('DOMContentLoaded', () => {
        refreshTemperatures();
        // Cykliczne auto-odświeżanie parametrów co 30 sekund
        setInterval(refreshTemperatures, 30000);
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
