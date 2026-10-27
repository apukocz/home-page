<?php
/**
 * Konfiguracja sterowania roletami / przekaźnikami I2C
 */

// Ścieżka do skryptu wykonawczego script.sh
define('SCRIPT_PATH', __DIR__ . '/script.sh');

// Katalog z plikami konfiguracyjnymi i stanami (.state)
// Jeśli istnieje /var/www/gpio to użyj go, w przeciwnym razie użyj katalogu gpio w bieżącym folderze
define('GPIO_DIR', is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');

// Czy uruchamiać skrypt z uprawnieniami sudo?
// Jeśli www-data wymaga sudo dla i2cset/gpio, ustaw na true i skonfiguruj /etc/sudoers.d/
define('USE_SUDO', false);

// Tryb deweloperski / symulacji (true = loguje polecenia bez fizycznego wywoływania, przydatne do testów)
define('SIMULATION_MODE', false);

// Główna lista pomieszczeń / rolet (zgodnie z wymaganiem: 8 pozycji)
$ROOMS = [
    'kuchnie' => [
        'name' => 'Kuchnie',
        'desc' => 'Rolety kuchenne (lewe i prawe)',
        'icon' => 'kitchen',
        'relays' => [
            'relay_piwnica_kuchnia_lewe',
            'relay_piwnica_kuchnia_prawe'
        ]
    ],
    'duzy_pokoj' => [
        'name' => 'Duży pokój',
        'desc' => 'Wszystkie rolety w salonie (wschód, południe, zachód)',
        'icon' => 'living_room',
        'relays' => [
            'relay_pietro-1_duzy-pokoj_wschod_lewe',
            'relay_pietro-1_duzy-pokoj_wschod_prawe',
            'relay_pietro-1_duzy-pokoj_poludnie_lewe',
            'relay_pietro-1_duzy-pokoj_poludnie_srodkowe',
            'relay_pietro-1_duzy-pokoj_poludnie_prawe',
            'relay_pietro-1_duzy-pokoj_zachod_lewe',
            'relay_pietro-1_duzy-pokoj_zachod_prawe',
            'relay_pietro-1_duzy-pokoj_zachod_balkon'
        ]
    ],
    'duzy_wschod' => [
        'name' => 'Duży wschód',
        'desc' => 'Salon - strona wschodnia (lewe i prawe)',
        'icon' => 'sunrise',
        'relays' => [
            'relay_pietro-1_duzy-pokoj_wschod_lewe',
            'relay_pietro-1_duzy-pokoj_wschod_prawe'
        ]
    ],
    'duzy_poludnie' => [
        'name' => 'Duży południe',
        'desc' => 'Salon - strona południowa (lewe, środkowe, prawe)',
        'icon' => 'sun',
        'relays' => [
            'relay_pietro-1_duzy-pokoj_poludnie_lewe',
            'relay_pietro-1_duzy-pokoj_poludnie_srodkowe',
            'relay_pietro-1_duzy-pokoj_poludnie_prawe'
        ]
    ],
    'duzy_zachod' => [
        'name' => 'Duży zachód',
        'desc' => 'Salon - strona zachodnia (lewe, prawe, balkon)',
        'icon' => 'sunset',
        'relays' => [
            'relay_pietro-1_duzy-pokoj_zachod_lewe',
            'relay_pietro-1_duzy-pokoj_zachod_prawe',
            'relay_pietro-1_duzy-pokoj_zachod_balkon'
        ]
    ],
    'prasowalnia' => [
        'name' => 'Prasowalnia',
        'desc' => 'Piwnica - prasowalnia',
        'icon' => 'iron',
        'relays' => [
            'relay_piwnica_prasowalnia'
        ]
    ],
    'piwnica_wejscie' => [
        'name' => 'Piwnica wejście',
        'desc' => 'Wejście do piwnicy',
        'icon' => 'door',
        'relays' => [
            'relay_piwnica_wejscie'
        ]
    ],
    'dach_wejscie' => [
        'name' => 'Dach wejście',
        'desc' => 'Wyjście na dach (piętro 3)',
        'icon' => 'roof',
        'relays' => [
            'relay_pietro-3_dach'
        ]
    ]
];

// Dodatkowe przekaźniki z pliku relay_setup.conf (można łatwo włączyć w panelu)
$EXTRA_ROOMS = [
    'piwnica_skladzik' => [
        'name' => 'Piwnica składzik',
        'desc' => 'Składzik w piwnicy',
        'icon' => 'box',
        'relays' => ['relay_piwnica_skladzik']
    ],
    'parter_lazienka' => [
        'name' => 'Parter łazienka',
        'desc' => 'Łazienka na parterze',
        'icon' => 'bath',
        'relays' => ['relay_parter_lazienka']
    ],
    'parter_sypialka' => [
        'name' => 'Parter sypialnia',
        'desc' => 'Sypialnia na parterze',
        'icon' => 'bed',
        'relays' => ['relay_parter_sypialka']
    ],
    'parter_komputerowka' => [
        'name' => 'Parter komputerówka',
        'desc' => 'Pokój komputerowy',
        'icon' => 'computer',
        'relays' => ['relay_parter_komputerowka']
    ],
    'parter_wejscie' => [
        'name' => 'Parter wejście',
        'desc' => 'Główne wejście',
        'icon' => 'door',
        'relays' => ['relay_parter_wejscie']
    ],
    'brama_wjazd' => [
        'name' => 'Brama wjazdowa',
        'desc' => 'Brama wjazd na posesję',
        'icon' => 'gate',
        'relays' => ['relay_brama_wjazd']
    ],
    'brama_garaz' => [
        'name' => 'Brama garażowa',
        'desc' => 'Brama garaż',
        'icon' => 'garage',
        'relays' => ['relay_brama_garaz']
    ]
];
