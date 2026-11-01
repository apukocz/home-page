<?php
/**
 * Konfiguracja sterowania kotłownią / przekaźnikami GPIO - PI0 (10.0.0.2)
 *
 * Obsługiwane urządzenia:
 * - Piec Gazowy CWU (GPIO526 / pin 14)
 * - Piec Gazowy CO  (GPIO527 / pin 15)
 * - Pompa obiegowa  (GPIO530 / pin 18)
 */

// Katalog z plikami GPIO (symlinki do sysfs)
if (!defined('GPIO_DIR')) {
    define('GPIO_DIR', is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');
}

// Logika przekaźników (moduł przekaźnikowy sterowany stanem niskim):
// '0' = przekaźnik załączony (ON)
// '1' = przekaźnik rozłączony (OFF)
define('RELAY_ON', '0');
define('RELAY_OFF', '1');

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
