<?php
/**
 * Konfiguracja sterowania kotłownią / przekaźnikami GPIO - PI0 (10.0.0.2)
 *
 * Obsługiwane urządzenia:
 * - Piec Gazowy CWU (GPIO537 / pin 25)
 * - Piec Gazowy CO  (GPIO536 / pin 24)
 * - Pompa obiegowa  (GPIO535 / pin 23)
 *
 * Obsługiwane czujniki temperatury 1-Wire (DS18B20):
 * - 28-000004d2f065: CWU (Zasobnik ciepłej wody użytkowej)
 * - 28-021463f067ff: Gaz + (Zasilanie z kotła gazowego)
 * - 28-0004170d18ff: Gaz - (Powrót do kotła gazowego)
 * - 28-0315047d06ff: CO + (Zasilanie instalacji grzewczej CO)
 * - 28-000004ef79c2: CO - (Powrót z instalacji grzewczej CO)
 */

// Katalog z plikami GPIO (symlinki do sysfs)
if (!defined('GPIO_DIR')) {
    define('GPIO_DIR', is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');
}

// Ścieżka do magistrali 1-Wire (DS18B20)
if (!defined('W1_DEVICES_DIR')) {
    define('W1_DEVICES_DIR', is_dir('/sys/bus/w1/devices') ? '/sys/bus/w1/devices' : (is_dir('/var/www/w1') ? '/var/www/w1' : __DIR__ . '/gpio/w1'));
}

// Logika przekaźników (moduł przekaźnikowy sterowany stanem niskim):
// '0' = przekaźnik załączony (ON)
// '1' = przekaźnik rozłączony (OFF)
define('RELAY_ON', '0');
define('RELAY_OFF', '1');

// =============================================================================
// Konfiguracja czujników temperatury 1-Wire (DS18B20)
// =============================================================================

$TEMP_SENSORS = [
    // 1. Ciepła Woda Użytkowa (CWU)
    'cwu' => [
        'id'          => 'cwu',
        'sensor_id'   => '28-000004d2f065',
        'name'        => 'CWU',
        'sub_name'    => 'Zasobnik',
        'full_name'   => 'Zasobnik Ciepłej Wody Użytkowej (CWU)',
        'group'       => 'cwu',
        'role'        => 'tank',
        'desc'        => 'Temperatura ciepłej wody użytkowej w zasobniku',
        'icon'        => 'water',
        'accent'      => '#06b6d4', // Cyjan
        'badge'       => 'badge-cwu'
    ],

    // 2. Kocioł Gazowy (Gaz + / Gaz -)
    'gaz_plus' => [
        'id'          => 'gaz_plus',
        'sensor_id'   => '28-021463f067ff',
        'name'        => 'Gaz +',
        'sub_name'    => 'Zasilanie',
        'full_name'   => 'Kocioł Gazowy • Zasilanie (Gaz +)',
        'group'       => 'gaz',
        'role'        => 'supply',
        'desc'        => 'Wyjście gorącej wody z kotła gazowego',
        'icon'        => 'flame_plus',
        'accent'      => '#ef4444', // Czerwony
        'badge'       => 'badge-supply'
    ],
    'gaz_minus' => [
        'id'          => 'gaz_minus',
        'sensor_id'   => '28-0004170d18ff',
        'name'        => 'Gaz -',
        'sub_name'    => 'Powrót',
        'full_name'   => 'Kocioł Gazowy • Powrót (Gaz -)',
        'group'       => 'gaz',
        'role'        => 'return',
        'desc'        => 'Powrót wody do kotła gazowego',
        'icon'        => 'flame_minus',
        'accent'      => '#3b82f6', // Niebieski
        'badge'       => 'badge-return'
    ],

    // 3. Centralne Ogrzewanie (CO + / CO -)
    'co_plus' => [
        'id'          => 'co_plus',
        'sensor_id'   => '28-0315047d06ff',
        'name'        => 'CO +',
        'sub_name'    => 'Zasilanie',
        'full_name'   => 'Centralne Ogrzewanie • Zasilanie (CO +)',
        'group'       => 'co',
        'role'        => 'supply',
        'desc'        => 'Zasilanie instalacji grzejnikowej CO',
        'icon'        => 'radiator_plus',
        'accent'      => '#f97316', // Pomarańczowy
        'badge'       => 'badge-supply'
    ],
    'co_minus' => [
        'id'          => 'co_minus',
        'sensor_id'   => '28-000004ef79c2',
        'name'        => 'CO -',
        'sub_name'    => 'Powrót',
        'full_name'   => 'Centralne Ogrzewanie • Powrót (CO -)',
        'group'       => 'co',
        'role'        => 'return',
        'desc'        => 'Powrót wody z grzejników instalacji CO',
        'icon'        => 'radiator_minus',
        'accent'      => '#60a5fa', // Błękitny
        'badge'       => 'badge-return'
    ],
];

// Grupy czujników w instalacji kotłowni (CO, Gaz, CWU)
$TEMP_GROUPS = [
    'co' => [
        'id'          => 'co',
        'title'       => 'Centralne Ogrzewanie (CO)',
        'short_title' => 'CO',
        'desc'        => 'Obieg grzejnikowy CO',
        'accent'      => '#f97316',
        'icon'        => 'radiator',
        'sensors'     => ['co_plus', 'co_minus'],
        'show_delta'  => true,
    ],
    'gaz' => [
        'id'          => 'gaz',
        'title'       => 'Kocioł Gazowy (Gaz)',
        'short_title' => 'Gaz',
        'desc'        => 'Obieg kotła gazowego',
        'accent'      => '#ef4444',
        'icon'        => 'flame',
        'sensors'     => ['gaz_plus', 'gaz_minus'],
        'show_delta'  => true,
    ],
    'cwu' => [
        'id'          => 'cwu',
        'title'       => 'Ciepła Woda Użytkowa (CWU)',
        'short_title' => 'CWU',
        'desc'        => 'Zasobnik ciepłej wody użytkowej',
        'accent'      => '#06b6d4',
        'icon'        => 'water',
        'sensors'     => ['cwu'],
        'show_delta'  => false,
    ],
];

// Lista urządzeń i mapowanie GPIO
$DEVICES = [
    'piec_cwu' => [
        'id'             => 'piec_cwu',
        'name'           => 'Piec Gazowy CWU',
        'short_name'     => 'Piec CWU',
        'desc'           => 'Ciepła Woda Użytkowa • Sterowanie zasobnikiem',
        'gpio'           => '537',
        'pin'            => '25',
        'script'         => 'script_piec_gaz_cwu.sh',
        'param'          => 'relay_piec_gaz_cwu',
        'state_file'     => GPIO_DIR . '/relay_piec_gaz_cwu.state',
        'alt_state_file' => GPIO_DIR . '/piec_cwu.state',
        'file'           => GPIO_DIR . '/gpio537',
        'sysfs_file'     => '/sys/class/gpio/gpio537/value',
        'icon'           => 'flame_water',
        'accent'         => '#f97316', // Pomarańczowy
        'legacy_sw'      => 'sw1',
    ],
    'piec_co' => [
        'id'             => 'piec_co',
        'name'           => 'Piec Gazowy CO',
        'short_name'     => 'Piec CO',
        'desc'           => 'Centralne Ogrzewanie • Sterowanie obwodem grzejnikowym',
        'gpio'           => '536',
        'pin'            => '24',
        'script'         => 'script_piec_gaz.sh',
        'param'          => 'relay_piec_gaz',
        'state_file'     => GPIO_DIR . '/relay_piec_gaz.state',
        'alt_state_file' => GPIO_DIR . '/piec_co.state',
        'file'           => GPIO_DIR . '/gpio536',
        'sysfs_file'     => '/sys/class/gpio/gpio536/value',
        'icon'           => 'flame',
        'accent'         => '#ef4444', // Czerwony
        'legacy_sw'      => 'sw2',
    ],
    'pompa' => [
        'id'             => 'pompa',
        'name'           => 'Pompa obiegowa',
        'short_name'     => 'Pompa obiegowa',
        'desc'           => 'Wymuszanie cyrkulacji wody w instalacji grzewczej',
        'gpio'           => '535',
        'pin'            => '23',
        'script'         => 'script_pompa_obieg.sh',
        'param'          => 'relay_pompa_obiegowa',
        'state_file'     => GPIO_DIR . '/relay_pompa_obiegowa.state',
        'file'           => GPIO_DIR . '/gpio535',
        'sysfs_file'     => '/sys/class/gpio/gpio535/value',
        'icon'           => 'pump',
        'accent'         => '#06b6d4', // Cyjan
        'legacy_sw'      => 'sw3',
    ]
];

// Ścieżki do skryptów urządzeń kotłowni
define('SCRIPT_PIEC_CWU_PATH', __DIR__ . '/script_piec_gaz_cwu.sh');
define('SCRIPT_PIEC_CO_PATH', __DIR__ . '/script_piec_gaz.sh');
define('SCRIPT_POMPA_PATH', __DIR__ . '/script_pompa_obieg.sh');

/**
 * Zwraca właściwą ścieżkę do skryptu urządzenia
 */
function getDeviceScriptPath($scriptName) {
    $candidates = [
        __DIR__ . '/' . $scriptName,
        '/var/www/html/' . $scriptName,
        '/var/www/' . $scriptName,
        '/var/www/gpio/' . $scriptName,
    ];
    foreach ($candidates as $cand) {
        if ($cand && file_exists($cand)) {
            return $cand;
        }
    }
    return __DIR__ . '/' . $scriptName;
}

function getScriptPompaPath($scriptName = 'script_pompa_obieg.sh') {
    return getDeviceScriptPath($scriptName);
}

// Opcjonalne dodatkowe GPIO (np. relay power / test gpio535)
$EXTRA_DEVICES = [
    'relay_power' => [
        'id'          => 'relay_power',
        'name'        => 'Zasilanie przekaźników',
        'short_name'  => 'Relay Power',
        'desc'        => 'Szyna zasilania modułu przekaźnikowego',
        'gpio'        => '535',
        'pin'         => '23',
        'file'        => GPIO_DIR . '/gpio535',
        'sysfs_file'  => '/sys/class/gpio/gpio535/value',
        'icon'        => 'power',
        'accent'      => '#a855f7',
        'legacy_sw'   => 'sw4',
    ]
];

/**
 * Odczytuje stan danego urządzenia z pliku GPIO
 *
 * @param string $deviceId Identyfikator urządzenia ('piec_cwu', 'piec_co', 'pompa')
 * @return array Informacja o stanie
 */
function getDeviceState($deviceId) {
    global $DEVICES, $EXTRA_DEVICES;

    $dev = $DEVICES[$deviceId] ?? $EXTRA_DEVICES[$deviceId] ?? null;
    if (!$dev) {
        return [
            'state'   => 'unknown',
            'is_on'   => false,
            'raw'     => null,
            'label'   => 'Nieznany',
            'badge'   => 'badge-secondary',
            'error'   => 'Nieznane urządzenie'
        ];
    }

    $raw = null;
    $filePath = null;
    $isOn = false;
    $stateDetected = false;

    // 1. Sprawdzenie dedykowanego pliku stanu .state (jeśli urządzenie go posiada)
    $stateFilesToTry = array_filter([
        $dev['state_file'] ?? null,
        $dev['alt_state_file'] ?? null,
        (defined('GPIO_DIR') ? GPIO_DIR . '/relay_' . $deviceId . '.state' : null),
        (defined('GPIO_DIR') ? GPIO_DIR . '/' . $deviceId . '.state' : null),
    ]);

    foreach ($stateFilesToTry as $sf) {
        if (file_exists($sf) && is_readable($sf)) {
            $stateRaw = @file_get_contents($sf);
            if ($stateRaw !== false && trim($stateRaw) !== '') {
                $val = trim($stateRaw);
                // 1 = włączony (open), 0 lub 2 = wyłączony (close / stop)
                $isOn = ($val === '1');
                $raw = $val;
                $filePath = $sf;
                $stateDetected = true;
                break;
            }
        }
    }

    // 2. Jeśli brak pliku .state, odczyt bezpośrednio z pliku rejestru GPIO
    if (!$stateDetected) {
        $filesToTry = array_filter([
            $dev['file'] ?? null,
            $dev['sysfs_file'] ?? null,
            ($deviceId === 'piec_cwu' && defined('GPIO_DIR')) ? GPIO_DIR . '/gpio526' : null,
            ($deviceId === 'piec_co' && defined('GPIO_DIR')) ? GPIO_DIR . '/gpio527' : null,
            ($deviceId === 'pompa' && defined('GPIO_DIR')) ? GPIO_DIR . '/gpio530' : null,
        ]);

        foreach ($filesToTry as $f) {
            if (file_exists($f) && is_readable($f)) {
                $rawVal = @file_get_contents($f);
                if ($rawVal !== false && $rawVal !== null) {
                    $val = trim($rawVal);
                    $raw = $val;
                    $filePath = $f;
                    $isOn = ($val === RELAY_ON);
                    $stateDetected = true;
                    break;
                }
            }
        }
    }

    if ($stateDetected) {
        return [
            'id'       => $dev['id'],
            'name'     => $dev['name'],
            'gpio'     => $dev['gpio'],
            'pin'      => $dev['pin'],
            'raw'      => $raw,
            'is_on'    => $isOn,
            'state'    => $isOn ? 'on' : 'off',
            'label'    => $isOn ? 'WŁĄCZONY' : 'WYŁĄCZONY',
            'badge'    => $isOn ? 'badge-success' : 'badge-secondary',
            'file'     => $filePath,
        ];
    }

    return [
        'id'       => $dev['id'],
        'name'     => $dev['name'],
        'gpio'     => $dev['gpio'],
        'pin'      => $dev['pin'],
        'raw'      => null,
        'is_on'    => false,
        'state'    => 'error',
        'label'    => 'Brak dostępu',
        'badge'    => 'badge-danger',
        'error'    => 'Nie można odczytać pliku GPIO ' . $dev['file']
    ];
}

/**
 * Ustawia stan przekaźnika GPIO
 *
 * @param string $deviceId Identyfikator urządzenia
 * @param string $action 'on', 'off', 'toggle', 'open', 'close'
 * @return array Wynik operacji ze statusem
 */
function setDeviceState($deviceId, $action) {
    global $DEVICES, $EXTRA_DEVICES;

    $dev = $DEVICES[$deviceId] ?? $EXTRA_DEVICES[$deviceId] ?? null;
    if (!$dev) {
        return ['success' => false, 'error' => "Nieznane urządzenie: $deviceId"];
    }

    $current = getDeviceState($deviceId);
    $targetVal = RELAY_OFF;
    $scriptAction = 'close';

    $actionLower = strtolower(trim($action));
    if ($actionLower === 'on' || $actionLower === '1' || $actionLower === 'open' || $actionLower === 'start') {
        $targetVal = RELAY_ON;
        $scriptAction = 'open';
    } elseif ($actionLower === 'off' || $actionLower === '0' || $actionLower === 'close' || $actionLower === 'stop') {
        $targetVal = RELAY_OFF;
        $scriptAction = 'close';
    } elseif ($actionLower === 'toggle') {
        $targetVal = ($current['is_on'] ? RELAY_OFF : RELAY_ON);
        $scriptAction = ($current['is_on'] ? 'close' : 'open');
    } else {
        return ['success' => false, 'error' => "Nieprawidłowa akcja: $action (dozwolone: on, off, toggle, open, close)"];
    }

    // Obsługa urządzeń kotłowni przez dedykowane skrypty bash
    if (!empty($dev['script'])) {
        $scriptName = $dev['script'];
        $scriptPath = getDeviceScriptPath($scriptName);

        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'error'   => "Brak pliku skryptu: {$scriptPath}",
                'device'  => $deviceId
            ];
        }

        $gpioDir = defined('GPIO_DIR') ? GPIO_DIR : '/var/www/gpio';
        $envPrefix = 'GPIO_DIR=' . escapeshellarg($gpioDir) . ' ';
        // Wywołanie: /bin/sh <script> open (włącz) lub close (wyłącz)
        $cmd = $envPrefix . '/bin/sh ' . escapeshellcmd($scriptPath) . ' ' . escapeshellarg($scriptAction) . ' 2>&1';

        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            // Spróbuj przez sudo jeśli konieczne
            $sudoCmd = 'sudo ' . $cmd;
            $sOut = [];
            $sRet = 0;
            exec($sudoCmd, $sOut, $sRet);
            if ($sRet === 0) {
                $returnVar = 0;
                $output = $sOut;
            }
        }

        // Zapis do logu wywołań
        @file_put_contents(rtrim($gpioDir, '/') . '/script_kotlownia.log', date('Y-m-d H:i:s') . " [PHP exec] {$cmd} (ret={$returnVar})\n", FILE_APPEND);
        @file_put_contents(rtrim($gpioDir, '/') . '/script_' . $deviceId . '.log', date('Y-m-d H:i:s') . " [PHP exec] {$cmd} (ret={$returnVar})\n", FILE_APPEND);

        // Krótkie opóźnienie na ustabilizowanie stanu
        usleep(20000); // 20ms
        $newState = getDeviceState($deviceId);

        return [
            'success'    => ($returnVar === 0),
            'action'     => $scriptAction,
            'device'     => $deviceId,
            'cmd'        => $cmd,
            'output'     => implode("\n", $output),
            'state'      => $newState['state'],
            'is_on'      => $newState['is_on'],
            'label'      => $newState['label'],
            'badge'      => $newState['badge'],
            'raw'        => $newState['raw'],
            'message'    => sprintf("%s: %s (skrypt %s %s)", $dev['name'], $newState['is_on'] ? 'Włączony' : 'Wyłączony', $scriptName, $scriptAction)
        ];
    }

    // Bezpośrednie sterowanie GPIO (Piec CWU, Piec CO, itp.)
    $targetFile = file_exists($dev['file']) ? $dev['file'] : $dev['sysfs_file'];

    // Próba bezpośredniego zapisu do pliku GPIO
    $written = @file_put_contents($targetFile, $targetVal . "\n");

    if ($written === false) {
        // Fallback do powłoki / sh
        $cmd = sprintf("echo %s > %s 2>&1", escapeshellarg($targetVal), escapeshellarg($targetFile));
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            // Spróbuj przez sudo jeśli konieczne
            $sudoCmd = sprintf("sudo sh -c 'echo %s > %s' 2>&1", escapeshellarg($targetVal), escapeshellarg($targetFile));
            exec($sudoCmd, $sOut, $sRet);
            if ($sRet !== 0) {
                return [
                    'success' => false,
                    'error'   => "Błąd zapisu do $targetFile: " . implode(" ", $sOut ?: $out),
                    'device'  => $deviceId
                ];
            }
        }
    }

    // Weryfikacja zapisanego stanu
    usleep(10000); // 10ms
    $newState = getDeviceState($deviceId);

    return [
        'success'    => true,
        'action'     => $actionLower,
        'device'     => $deviceId,
        'state'      => $newState['state'],
        'is_on'      => $newState['is_on'],
        'label'      => $newState['label'],
        'badge'      => $newState['badge'],
        'raw'        => $newState['raw'],
        'message'    => sprintf("%s: %s", $dev['name'], $newState['is_on'] ? 'Włączony' : 'Wyłączony')
    ];
}

/**
 * Ustawia stan dla wszystkich urządzeń ('ALL')
 *
 * @param string $action 'on' lub 'off'
 * @return array Wyniki dla poszczególnych urządzeń
 */
function setAllDevicesState($action) {
    global $DEVICES;

    $results = [];
    $allSuccess = true;

    foreach ($DEVICES as $id => $dev) {
        $res = setDeviceState($id, $action);
        $results[$id] = $res;
        if (empty($res['success'])) {
            $allSuccess = false;
        }
        usleep(10000); // 10ms
    }

    return [
        'success' => $allSuccess,
        'action'  => $action,
        'devices' => $results,
        'message' => ($action === 'off' ? 'Wszystkie urządzenia zostały wyłączone' : 'Wszystkie urządzenia zostały włączone')
    ];
}

/**
 * Zwraca stany wszystkich urządzeń w formacie tablicy
 */
function getAllDevicesStatus() {
    global $DEVICES;

    $states = [];
    foreach ($DEVICES as $id => $dev) {
        $states[$id] = getDeviceState($id);
    }
    return $states;
}

// =============================================================================
// Funkcje obsługi magistrali 1-Wire i odczytu czujników DS18B20
// =============================================================================

/**
 * Odczytuje temperaturę z pojedynczego czujnika 1-Wire DS18B20
 *
 * @param string $sensorKeyOrId Klucz czujnika (np. 'cwu', 'gaz_plus') lub pełny ID (np. '28-000004d2f065')
 * @return array
 */
function readW1Sensor($sensorKeyOrId) {
    global $TEMP_SENSORS;

    $sensorMeta = null;
    $sensorId = $sensorKeyOrId;

    if (isset($TEMP_SENSORS[$sensorKeyOrId])) {
        $sensorMeta = $TEMP_SENSORS[$sensorKeyOrId];
        $sensorId = $sensorMeta['sensor_id'];
    } else {
        foreach ($TEMP_SENSORS as $k => $s) {
            if ($s['sensor_id'] === $sensorKeyOrId) {
                $sensorMeta = $s;
                break;
            }
        }
    }

    // Walidacja ID czujnika (zabezpieczenie ścieżki)
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $sensorId)) {
        return [
            'success'   => false,
            'sensor_id' => $sensorId,
            'temp'      => null,
            'formatted' => '--.-',
            'status'    => 'error',
            'error'     => 'Nieprawidłowy identyfikator czujnika'
        ];
    }

    $w1Dir = defined('W1_DEVICES_DIR') ? W1_DEVICES_DIR : '/sys/bus/w1/devices';
    $filePath = rtrim($w1Dir, '/') . '/' . $sensorId . '/w1_slave';

    // Obsługa fallback do mocków w środowisku deweloperskim / testowym
    if (!file_exists($filePath)) {
        $mockDir = __DIR__ . '/gpio/w1';
        $mockPath = $mockDir . '/' . $sensorId . '/w1_slave';
        if (file_exists($mockPath)) {
            $filePath = $mockPath;
        } else {
            return [
                'success'   => false,
                'sensor_id' => $sensorId,
                'temp'      => null,
                'formatted' => '--.-',
                'status'    => 'disconnected',
                'file'      => $filePath,
                'error'     => 'Brak pliku w1_slave (czujnik odłączony lub brak magistrali)'
            ];
        }
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return [
            'success'   => false,
            'sensor_id' => $sensorId,
            'temp'      => null,
            'formatted' => '--.-',
            'status'    => 'read_error',
            'file'      => $filePath,
            'error'     => 'Nie można odczytać danych z czujnika'
        ];
    }

    // Weryfikacja sumy kontrolnej CRC (DS18B20 zwraca YES w 1. linii)
    // Przykład: "70 01 4b 46 7f ff 0c 10 e5 : crc=e5 YES"
    if (!preg_match('/crc=[0-9a-fA-F]+\s+YES/', $raw)) {
        return [
            'success'   => false,
            'sensor_id' => $sensorId,
            'temp'      => null,
            'formatted' => '--.-',
            'status'    => 'crc_error',
            'file'      => $filePath,
            'error'     => 'Błąd sumy kontrolnej CRC (czujnik zajęty lub zakłócenia)'
        ];
    }

    // Wyciągnięcie temperatury: t=23000 lub t=-2500 w 2. linii
    if (preg_match('/t=(-?\d+)/', $raw, $matches)) {
        $rawT = (int)$matches[1];
        $tempC = round($rawT / 1000, 1);
        $tempExact = round($rawT / 1000, 2);

        return [
            'success'    => true,
            'sensor_id'  => $sensorId,
            'temp'       => $tempC,
            'temp_exact' => $tempExact,
            'formatted'  => number_format($tempC, 1, '.', ''),
            'raw'        => $rawT,
            'status'     => 'ok',
            'file'       => $filePath,
            'error'      => null
        ];
    }

    return [
        'success'   => false,
        'sensor_id' => $sensorId,
        'temp'      => null,
        'formatted' => '--.-',
        'status'    => 'parse_error',
        'file'      => $filePath,
        'error'     => 'Nie znaleziono parametru t= w odpowiedzi czujnika'
    ];
}

/**
 * Zwraca wszystkie temperatury z magistrali 1-Wire z podziałem na grupy (CO, Gaz, CWU)
 *
 * @return array
 */
function getAllTemperatures() {
    global $TEMP_SENSORS, $TEMP_GROUPS;

    $sensorResults = [];
    $totalSensors = count($TEMP_SENSORS);
    $activeCount = 0;

    foreach ($TEMP_SENSORS as $k => $sensorMeta) {
        $readResult = readW1Sensor($sensorMeta['sensor_id']);
        $sensorResults[$k] = array_merge($sensorMeta, $readResult);
        if (!empty($readResult['success'])) {
            $activeCount++;
        }
    }

    $grouped = [];
    foreach ($TEMP_GROUPS as $groupId => $groupMeta) {
        $groupData = $groupMeta;
        $groupSensors = [];
        $supplyTemp = null;
        $returnTemp = null;

        foreach ($groupMeta['sensors'] as $sensorKey) {
            if (isset($sensorResults[$sensorKey])) {
                $sData = $sensorResults[$sensorKey];
                $groupSensors[$sensorKey] = $sData;

                if (($sData['role'] ?? '') === 'supply' && !empty($sData['success']) && $sData['temp'] !== null) {
                    $supplyTemp = $sData['temp'];
                } elseif (($sData['role'] ?? '') === 'return' && !empty($sData['success']) && $sData['temp'] !== null) {
                    $returnTemp = $sData['temp'];
                }
            }
        }

        $groupData['sensor_items'] = $groupSensors;

        // Obliczenie różnicy Delta T = (Zasilanie) - (Powrót) dla obiegów CO i Gaz
        if (!empty($groupMeta['show_delta']) && $supplyTemp !== null && $returnTemp !== null) {
            $delta = round($supplyTemp - $returnTemp, 1);
            $groupData['delta'] = $delta;
            $groupData['delta_formatted'] = ($delta >= 0 ? '+' : '') . number_format($delta, 1, '.', '');
        } else {
            $groupData['delta'] = null;
            $groupData['delta_formatted'] = null;
        }

        $grouped[$groupId] = $groupData;
    }

    return [
        'success'      => true,
        'timestamp'    => time(),
        'date'         => date('Y-m-d H:i:s'),
        'active_count' => $activeCount,
        'total_count'  => $totalSensors,
        'groups'       => $grouped,
        'sensors'      => $sensorResults
    ];
}
