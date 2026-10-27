<?php
/**
 * Konfiguracja sterowania roletami / przekaźnikami I2C - PI1 (10.0.0.4)
 */

// Ścieżka do skryptu wykonawczego script.sh
define('SCRIPT_PATH', __DIR__ . '/script.sh');
define('SCRIPT_ONE_PATH', __DIR__ . '/script_one.sh');

// Katalog z plikami konfiguracyjnymi i stanami (.state)
// Jeśli istnieje /var/www/gpio to użyj go, w przeciwnym razie użyj katalogu gpio w bieżącym folderze
define('GPIO_DIR', is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');

// Czy uruchamiać skrypt z uprawnieniami sudo?
define('USE_SUDO', false);

// Tryb deweloperski / symulacji (true = loguje polecenia bez fizycznego wywoływania, przydatne do testów)
define('SIMULATION_MODE', false);

// Definicje kondygnacji i stref dla PI1
$FLOORS = [
    'pietro_1' => [
        'name' => 'Piętro 1 (Salon)',
        'desc' => 'Duży pokój • 3 sekcje okienne (8 rolet)',
        'desc_full' => 'Salon • 8 pojedynczych przekaźników',
        'icon' => 'living_room',
    ],
    'piwnica' => [
        'name' => 'Piwnica',
        'desc' => 'Kondygnacja przyziemia • 4 pomieszczenia (5 rolet)',
        'desc_full' => 'Przyziemie • 5 pojedynczych przekaźników',
        'icon' => 'basement',
    ],
    'dach' => [
        'name' => 'Dach (Piętro 3)',
        'desc' => 'Wyjście na dach • 1 roleta dachowa',
        'desc_full' => 'Piętro 3 • 1 przekaźnik okna dachowego',
        'icon' => 'roof',
    ],
    'bramy' => [
        'name' => 'Bramy',
        'desc' => 'Bramy wjazdowe posesji • 2 przekaźniki',
        'desc_full' => 'Posesja • 2 przekaźniki impulsowe',
        'icon' => 'gate',
    ]
];

// Główna lista pomieszczeń / rolet serwera PI1 z akordeonami dla połączonych okien
$ROOMS = [
    // =========================================================================
    // 1. PIĘTRO 1 (Salon / Duży pokój - 8 rolet pogrupowane na 3 strony świata)
    // =========================================================================
    'duzy_wschod' => [
        'name' => 'Duży pokój - Wschód',
        'desc' => 'Salon strona wschodnia (2 rolety: lewe i prawe)',
        'floor' => 'pietro_1',
        'icon' => 'sunrise',
        'relays' => [
            'relay_pietro-1_duzy-pokoj_wschod_lewe',
            'relay_pietro-1_duzy-pokoj_wschod_prawe'
        ],
        'sub_relays' => [
            'pietro-1_duzy-pokoj_wschod_lewe' => [
                'name' => 'Duży wschód - lewe',
                'desc' => 'Okno wschodnie lewe',
                'relays' => ['relay_pietro-1_duzy-pokoj_wschod_lewe']
            ],
            'pietro-1_duzy-pokoj_wschod_prawe' => [
                'name' => 'Duży wschód - prawe',
                'desc' => 'Okno wschodnie prawe',
                'relays' => ['relay_pietro-1_duzy-pokoj_wschod_prawe']
            ]
        ]
    ],
    'duzy_poludnie' => [
        'name' => 'Duży pokój - Południe',
        'desc' => 'Salon strona południowa (3 rolety: lewe, środkowe, prawe)',
        'floor' => 'pietro_1',
        'icon' => 'sun',
        'relays' => [
            'relay_pietro-1_duzy-pokoj_poludnie_lewe',
            'relay_pietro-1_duzy-pokoj_poludnie_srodkowe',
            'relay_pietro-1_duzy-pokoj_poludnie_prawe'
        ],
        'sub_relays' => [
            'pietro-1_duzy-pokoj_poludnie_lewe' => [
                'name' => 'Duży południe - lewe',
                'desc' => 'Okno południowe lewe',
                'relays' => ['relay_pietro-1_duzy-pokoj_poludnie_lewe']
            ],
            'pietro-1_duzy-pokoj_poludnie_srodkowe' => [
                'name' => 'Duży południe - środkowe',
                'desc' => 'Okno południowe środkowe',
                'relays' => ['relay_pietro-1_duzy-pokoj_poludnie_srodkowe']
            ],
            'pietro-1_duzy-pokoj_poludnie_prawe' => [
                'name' => 'Duży południe - prawe',
                'desc' => 'Okno południowe prawe',
                'relays' => ['relay_pietro-1_duzy-pokoj_poludnie_prawe']
            ]
        ]
    ],
    'duzy_zachod' => [
        'name' => 'Duży pokój - Zachód',
        'desc' => 'Salon strona zachodnia (3 rolety: lewe, prawe, balkon)',
        'floor' => 'pietro_1',
        'icon' => 'sunset',
        'relays' => [
            'relay_pietro-1_duzy-pokoj_zachod_lewe',
            'relay_pietro-1_duzy-pokoj_zachod_prawe',
            'relay_pietro-1_duzy-pokoj_zachod_balkon'
        ],
        'sub_relays' => [
            'pietro-1_duzy-pokoj_zachod_lewe' => [
                'name' => 'Duży zachód - lewe',
                'desc' => 'Okno zachodnie lewe',
                'relays' => ['relay_pietro-1_duzy-pokoj_zachod_lewe']
            ],
            'pietro-1_duzy-pokoj_zachod_prawe' => [
                'name' => 'Duży zachód - prawe',
                'desc' => 'Okno zachodnie prawe',
                'relays' => ['relay_pietro-1_duzy-pokoj_zachod_prawe']
            ],
            'pietro-1_duzy-pokoj_zachod_balkon' => [
                'name' => 'Duży zachód - balkon',
                'desc' => 'Okno balkonowe zachodnie',
                'relays' => ['relay_pietro-1_duzy-pokoj_zachod_balkon']
            ]
        ]
    ],

    // =========================================================================
    // 2. PIWNICA (Kondygnacja przyziemia)
    // =========================================================================
    'kuchnia' => [
        'name' => 'Kuchnia',
        'desc' => 'Rolety kuchenne w piwnicy (2 rolety: lewe i prawe)',
        'floor' => 'piwnica',
        'icon' => 'kitchen',
        'relays' => [
            'relay_piwnica_kuchnia_lewe',
            'relay_piwnica_kuchnia_prawe'
        ],
        'sub_relays' => [
            'piwnica_kuchnia_lewe' => [
                'name' => 'Kuchnia - lewe',
                'desc' => 'Okno kuchenne lewe',
                'relays' => ['relay_piwnica_kuchnia_lewe']
            ],
            'piwnica_kuchnia_prawe' => [
                'name' => 'Kuchnia - prawe',
                'desc' => 'Okno kuchenne prawe',
                'relays' => ['relay_piwnica_kuchnia_prawe']
            ]
        ]
    ],
    'prasowalnia' => [
        'name' => 'Prasowalnia',
        'desc' => 'Prasowalnia w piwnicy',
        'floor' => 'piwnica',
        'icon' => 'iron',
        'relays' => [
            'relay_piwnica_prasowalnia'
        ]
    ],
    'piwnica_skladzik' => [
        'name' => 'Piwnica składzik',
        'desc' => 'Składzik w piwnicy',
        'floor' => 'piwnica',
        'icon' => 'box',
        'relays' => [
            'relay_piwnica_skladzik'
        ]
    ],
    'piwnica_wejscie' => [
        'name' => 'Piwnica wejście',
        'desc' => 'Wejście do piwnicy',
        'floor' => 'piwnica',
        'icon' => 'door',
        'relays' => [
            'relay_piwnica_wejscie'
        ]
    ],

    // =========================================================================
    // 3. DACH (Piętro 3)
    // =========================================================================
    'dach' => [
        'name' => 'Okno dachowe',
        'desc' => 'Wyjście na dach (piętro 3)',
        'floor' => 'dach',
        'icon' => 'roof',
        'relays' => [
            'relay_pietro-3_dach'
        ]
    ],

    // =========================================================================
    // 4. BRAMY POSESJI
    // =========================================================================
    'brama_wjazd' => [
        'name' => 'Brama wjazdowa',
        'desc' => 'Brama wjazd na posesję (impuls)',
        'floor' => 'bramy',
        'icon' => 'gate',
        'relays' => [
            'relay_brama_wjazd'
        ]
    ],
    'brama_garaz' => [
        'name' => 'Brama garażowa',
        'desc' => 'Brama do garażu (impuls)',
        'floor' => 'bramy',
        'icon' => 'garage',
        'relays' => [
            'relay_brama_garaz'
        ]
    ]
];

// =============================================================================
// LISTA WSZYSTKICH 16 FIZYCZNYCH PRZEKAŹNIKÓW (Dla podstrony index_full.php)
// Każdy przekaźnik z relay_setup.conf z osobnym sterowaniem
// =============================================================================
$INDIVIDUAL_ROOMS = [
    // -------------------------------------------------------------------------
    // 1. PIĘTRO 1 - SALON (8 przekaźników)
    // -------------------------------------------------------------------------
    'pietro-1_duzy-pokoj_wschod_lewe' => [
        'name' => 'Duży pokój - Wschód lewe',
        'desc' => 'Piętro 1 • wschód okno lewe',
        'floor' => 'pietro_1',
        'icon' => 'sunrise',
        'relays' => ['relay_pietro-1_duzy-pokoj_wschod_lewe']
    ],
    'pietro-1_duzy-pokoj_wschod_prawe' => [
        'name' => 'Duży pokój - Wschód prawe',
        'desc' => 'Piętro 1 • wschód okno prawe',
        'floor' => 'pietro_1',
        'icon' => 'sunrise',
        'relays' => ['relay_pietro-1_duzy-pokoj_wschod_prawe']
    ],
    'pietro-1_duzy-pokoj_poludnie_lewe' => [
        'name' => 'Duży pokój - Południe lewe',
        'desc' => 'Piętro 1 • południe okno lewe',
        'floor' => 'pietro_1',
        'icon' => 'sun',
        'relays' => ['relay_pietro-1_duzy-pokoj_poludnie_lewe']
    ],
    'pietro-1_duzy-pokoj_poludnie_srodkowe' => [
        'name' => 'Duży pokój - Południe środkowe',
        'desc' => 'Piętro 1 • południe okno środkowe',
        'floor' => 'pietro_1',
        'icon' => 'sun',
        'relays' => ['relay_pietro-1_duzy-pokoj_poludnie_srodkowe']
    ],
    'pietro-1_duzy-pokoj_poludnie_prawe' => [
        'name' => 'Duży pokój - Południe prawe',
        'desc' => 'Piętro 1 • południe okno prawe',
        'floor' => 'pietro_1',
        'icon' => 'sun',
        'relays' => ['relay_pietro-1_duzy-pokoj_poludnie_prawe']
    ],
    'pietro-1_duzy-pokoj_zachod_lewe' => [
        'name' => 'Duży pokój - Zachód lewe',
        'desc' => 'Piętro 1 • zachód okno lewe',
        'floor' => 'pietro_1',
        'icon' => 'sunset',
        'relays' => ['relay_pietro-1_duzy-pokoj_zachod_lewe']
    ],
    'pietro-1_duzy-pokoj_zachod_prawe' => [
        'name' => 'Duży pokój - Zachód prawe',
        'desc' => 'Piętro 1 • zachód okno prawe',
        'floor' => 'pietro_1',
        'icon' => 'sunset',
        'relays' => ['relay_pietro-1_duzy-pokoj_zachod_prawe']
    ],
    'pietro-1_duzy-pokoj_zachod_balkon' => [
        'name' => 'Duży pokój - Zachód balkon',
        'desc' => 'Piętro 1 • zachód okno balkonowe',
        'floor' => 'pietro_1',
        'icon' => 'sunset',
        'relays' => ['relay_pietro-1_duzy-pokoj_zachod_balkon']
    ],

    // -------------------------------------------------------------------------
    // 2. PIWNICA (5 przekaźników)
    // -------------------------------------------------------------------------
    'piwnica_kuchnia_lewe' => [
        'name' => 'Kuchnia - lewe',
        'desc' => 'Piwnica • kuchnia okno lewe',
        'floor' => 'piwnica',
        'icon' => 'kitchen',
        'relays' => ['relay_piwnica_kuchnia_lewe']
    ],
    'piwnica_kuchnia_prawe' => [
        'name' => 'Kuchnia - prawe',
        'desc' => 'Piwnica • kuchnia okno prawe',
        'floor' => 'piwnica',
        'icon' => 'kitchen',
        'relays' => ['relay_piwnica_kuchnia_prawe']
    ],
    'piwnica_prasowalnia' => [
        'name' => 'Prasowalnia',
        'desc' => 'Piwnica • prasowalnia',
        'floor' => 'piwnica',
        'icon' => 'iron',
        'relays' => ['relay_piwnica_prasowalnia']
    ],
    'piwnica_skladzik' => [
        'name' => 'Piwnica składzik',
        'desc' => 'Piwnica • składzik',
        'floor' => 'piwnica',
        'icon' => 'box',
        'relays' => ['relay_piwnica_skladzik']
    ],
    'piwnica_wejscie' => [
        'name' => 'Piwnica wejście',
        'desc' => 'Piwnica • wejście',
        'floor' => 'piwnica',
        'icon' => 'door',
        'relays' => ['relay_piwnica_wejscie']
    ],

    // -------------------------------------------------------------------------
    // 3. DACH (1 przekaźnik)
    // -------------------------------------------------------------------------
    'pietro-3_dach' => [
        'name' => 'Okno dachowe',
        'desc' => 'Piętro 3 • okno dachowe',
        'floor' => 'dach',
        'icon' => 'roof',
        'relays' => ['relay_pietro-3_dach']
    ],

    // -------------------------------------------------------------------------
    // 4. BRAMY (2 przekaźniki)
    // -------------------------------------------------------------------------
    'brama_wjazd' => [
        'name' => 'Brama wjazdowa',
        'desc' => 'Posesja • brama wjazdowa (impuls)',
        'floor' => 'bramy',
        'icon' => 'gate',
        'relays' => ['relay_brama_wjazd']
    ],
    'brama_garaz' => [
        'name' => 'Brama garażowa',
        'desc' => 'Posesja • brama garażowa (impuls)',
        'floor' => 'bramy',
        'icon' => 'garage',
        'relays' => ['relay_brama_garaz']
    ]
];

// Opcjonalne grupy zbiorcze (dostępne także przez parametr ?extra=1 i API)
$EXTRA_ROOMS = [
    'duzy_pokoj' => [
        'name' => 'Duży pokój (wszystkie)',
        'desc' => 'Wszystkie 8 rolet w salonie',
        'floor' => 'pietro_1',
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
    'piwnica_all' => [
        'name' => 'Wszystkie: Piwnica',
        'desc' => 'Wszystkie 5 rolet piwnicy',
        'floor' => 'piwnica',
        'icon' => 'basement',
        'relays' => [
            'relay_piwnica_kuchnia_lewe',
            'relay_piwnica_kuchnia_prawe',
            'relay_piwnica_prasowalnia',
            'relay_piwnica_skladzik',
            'relay_piwnica_wejscie'
        ]
    ],
    'bramy_all' => [
        'name' => 'Wszystkie: Bramy',
        'desc' => 'Brama wjazdowa i garażowa',
        'floor' => 'bramy',
        'icon' => 'gate',
        'relays' => [
            'relay_brama_wjazd',
            'relay_brama_garaz'
        ]
    ]
];

// Aliasy dla zgodności wstecznej (stare nazwy pokoi ze skryptów/Alexa/curl)
$ROOM_ALIASES = [
    'kuchnie' => 'kuchnia',
    'dach_wejscie' => 'dach',
    'bramy' => 'bramy_all'
];
