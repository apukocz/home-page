<?php
/**
 * Konfiguracja sterowania kotłownią / przekaźnikami GPIO - PI0 (10.0.0.118)
 *
 * Obsługiwane urządzenia:
 * - Piec Gazowy CWU (GPIO526 / pin 14)
 * - Piec Gazowy CO  (GPIO527 / pin 15)
 * - Pompa obiegowa  (GPIO530 / pin 18)
 */

// Katalog z plikami GPIO (symlinki do sysfs)
define('GPIO_DIR', is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');

// Logika przekaźników (moduł przekaźnikowy sterowany stanem niskim):
// '0' = przekaźnik załączony (ON)
// '1' = przekaźnik rozłączony (OFF)
define('RELAY_ON', '0');
define('RELAY_OFF', '1');

// Lista urządzeń i mapowanie GPIO
$DEVICES = [
    'piec_cwu' => [
        'id'          => 'piec_cwu',
        'name'        => 'Piec Gazowy CWU',
        'short_name'  => 'Piec CWU',
        'desc'        => 'Ciepła Woda Użytkowa • Sterowanie zasobnikiem',
        'gpio'        => '526',
        'pin'         => '14',
        'file'        => GPIO_DIR . '/gpio526',
        'sysfs_file'  => '/sys/class/gpio/gpio526/value',
        'icon'        => 'flame_water',
        'accent'      => '#f97316', // Pomarańczowy
        'legacy_sw'   => 'sw1',
    ],
    'piec_co' => [
        'id'          => 'piec_co',
        'name'        => 'Piec Gazowy CO',
        'short_name'  => 'Piec CO',
        'desc'        => 'Centralne Ogrzewanie • Sterowanie obwodem grzejnikowym',
        'gpio'        => '527',
        'pin'         => '15',
        'file'        => GPIO_DIR . '/gpio527',
        'sysfs_file'  => '/sys/class/gpio/gpio527/value',
        'icon'        => 'flame',
        'accent'      => '#ef4444', // Czerwony
        'legacy_sw'   => 'sw2',
    ],
    'pompa' => [
        'id'          => 'pompa',
        'name'        => 'Pompa obiegowa',
        'short_name'  => 'Pompa obiegowa',
        'desc'        => 'Wymuszanie cyrkulacji wody w instalacji grzewczej',
        'gpio'        => '530',
        'pin'         => '18',
        'file'        => GPIO_DIR . '/gpio530',
        'sysfs_file'  => '/sys/class/gpio/gpio530/value',
        'icon'        => 'pump',
        'accent'      => '#06b6d4', // Cyjan
        'legacy_sw'   => 'sw3',
    ]
];

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

    if (file_exists($dev['file']) && is_readable($dev['file'])) {
        $filePath = $dev['file'];
        $raw = @file_get_contents($dev['file']);
    } elseif (file_exists($dev['sysfs_file']) && is_readable($dev['sysfs_file'])) {
        $filePath = $dev['sysfs_file'];
        $raw = @file_get_contents($dev['sysfs_file']);
    }

    if ($raw !== false && $raw !== null) {
        $val = trim($raw);
        $isOn = ($val === RELAY_ON);
        return [
            'id'       => $dev['id'],
            'name'     => $dev['name'],
            'gpio'     => $dev['gpio'],
            'pin'      => $dev['pin'],
            'raw'      => $val,
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
 * @param string $action 'on', 'off', 'toggle'
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

    $actionLower = strtolower(trim($action));
    if ($actionLower === 'on' || $actionLower === '1' || $actionLower === 'open' || $actionLower === 'start') {
        $targetVal = RELAY_ON;
    } elseif ($actionLower === 'off' || $actionLower === '0' || $actionLower === 'close' || $actionLower === 'stop') {
        $targetVal = RELAY_OFF;
    } elseif ($actionLower === 'toggle') {
        $targetVal = ($current['is_on'] ? RELAY_OFF : RELAY_ON);
    } else {
        return ['success' => false, 'error' => "Nieprawidłowa akcja: $action (dozwolone: on, off, toggle)"];
    }

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
