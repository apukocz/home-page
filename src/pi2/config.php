<?php
/**
 * Konfiguracja sterowania roletami / przekaźnikami I2C - PI2 (10.0.0.5)
 */

// Ścieżka do skryptu wykonawczego script.sh
define('SCRIPT_PATH', __DIR__ . '/script.sh');

// Katalog z plikami konfiguracyjnymi i stanami (.state)
// Jeśli istnieje /var/www/gpio to użyj go, w przeciwnym razie użyj katalogu gpio w bieżącym folderze
define('GPIO_DIR', is_dir('/var/www/gpio') ? '/var/www/gpio' : __DIR__ . '/gpio');

// Czy uruchamiać skrypt z uprawnieniami sudo?
define('USE_SUDO', false);

// Tryb deweloperski / symulacji (true = loguje polecenia bez fizycznego wywoływania, przydatne do testów)
define('SIMULATION_MODE', false);

// Definicje kondygnacji dla PI2 (Piętro 2 jako pierwsze, Parter jako drugi)
$FLOORS = [
    'pietro_2' => [
        'name' => 'Piętro 2',
        'desc' => 'Kondygnacja górna • 4 pomieszczenia (7 rolet)',
        'desc_full' => 'Kondygnacja górna • 7 pojedynczych przekaźników',
        'icon' => 'roof',
    ],
    'parter' => [
        'name' => 'Parter',
        'desc' => 'Kondygnacja dolna • 6 pomieszczeń (6 rolet)',
        'desc_full' => 'Kondygnacja dolna • 6 pojedynczych przekaźników',
        'icon' => 'living_room',
    ]
];

// Główna lista pomieszczeń / rolet serwera PI2 pogrupowana:
// 1. Piętro 2 (jako pierwsze)
// 2. Parter (jako drugi)
$ROOMS = [
    // =========================================================================
    // 1. PIĘTRO 2 (Kondygnacja górna - połączone okna w pokojach)
    // =========================================================================
    'lazienka_gora' => [
        'name' => 'Łazienka góra',
        'desc' => 'Piętro 2 - rolety prawe i lewe (2 przekaźniki)',
        'floor' => 'pietro_2',
        'icon' => 'bath',
        'relays' => [
            'relay_pietro-2_lazienka_prawe',
            'relay_pietro-2_lazienka_lewe'
        ],
        'sub_relays' => [
            'pietro-2_lazienka_prawe' => [
                'name' => 'Łazienka góra - prawe',
                'desc' => 'Roleta prawa',
                'relays' => ['relay_pietro-2_lazienka_prawe']
            ],
            'pietro-2_lazienka_lewe' => [
                'name' => 'Łazienka góra - lewe',
                'desc' => 'Roleta lewa',
                'relays' => ['relay_pietro-2_lazienka_lewe']
            ]
        ]
    ],
    'przemek' => [
        'name' => 'Przemek',
        'desc' => 'Pokój Przemka (piętro 2)',
        'floor' => 'pietro_2',
        'icon' => 'bed',
        'relays' => [
            'relay_pietro-2_przemek'
        ]
    ],
    'olek' => [
        'name' => 'Olek',
        'desc' => 'Pokój Olka - główne i balkon (2 przekaźniki)',
        'floor' => 'pietro_2',
        'icon' => 'bed',
        'relays' => [
            'relay_pietro-2_olek_glowne',
            'relay_pietro-2_olek_balkon'
        ],
        'sub_relays' => [
            'pietro-2_olek_glowne' => [
                'name' => 'Olek - główne',
                'desc' => 'Okno główne',
                'relays' => ['relay_pietro-2_olek_glowne']
            ],
            'pietro-2_olek_balkon' => [
                'name' => 'Olek - balkon',
                'desc' => 'Okno balkonowe',
                'relays' => ['relay_pietro-2_olek_balkon']
            ]
        ]
    ],
    'biblioteczka' => [
        'name' => 'Biblioteczka',
        'desc' => 'Biblioteczka - główne i balkon (2 przekaźniki)',
        'floor' => 'pietro_2',
        'icon' => 'book',
        'relays' => [
            'relay_pietro-2_biblioteczka_glowne',
            'relay_pietro-2_biblioteczka_balkon'
        ],
        'sub_relays' => [
            'pietro-2_biblioteczka_glowne' => [
                'name' => 'Biblioteczka - główne',
                'desc' => 'Okno główne',
                'relays' => ['relay_pietro-2_biblioteczka_glowne']
            ],
            'pietro-2_biblioteczka_balkon' => [
                'name' => 'Biblioteczka - balkon',
                'desc' => 'Okno balkonowe',
                'relays' => ['relay_pietro-2_biblioteczka_balkon']
            ]
        ]
    ],

    // =========================================================================
    // 2. PARTER (Kondygnacja dolna)
    // =========================================================================
    'mama' => [
        'name' => 'Mama',
        'desc' => 'Pokój Mamy (parter)',
        'floor' => 'parter',
        'icon' => 'bed',
        'relays' => [
            'relay_parter_mama'
        ]
    ],
    'lazienka_dol' => [
        'name' => 'Łazienka dół',
        'desc' => 'Łazienka na parterze',
        'floor' => 'parter',
        'icon' => 'bath',
        'relays' => [
            'relay_parter_lazienka'
        ]
    ],
    'komputerowka' => [
        'name' => 'Komputerówka',
        'desc' => 'Pokój komputerowy (parter)',
        'floor' => 'parter',
        'icon' => 'computer',
        'relays' => [
            'relay_parter_komputerowka'
        ]
    ],
    'ogrod' => [
        'name' => 'Ogród',
        'desc' => 'Wyjście na ogród (parter)',
        'floor' => 'parter',
        'icon' => 'garden',
        'relays' => [
            'relay_parter_ogrod'
        ]
    ],
    'wejscie_glowne' => [
        'name' => 'Wejście główne',
        'desc' => 'Główne wejście (parter)',
        'floor' => 'parter',
        'icon' => 'door',
        'relays' => [
            'relay_parter_wejscie_glowne'
        ]
    ],
    'wejscie_okno' => [
        'name' => 'Wejście okno',
        'desc' => 'Okno wejściowe (parter)',
        'floor' => 'parter',
        'icon' => 'window',
        'relays' => [
            'relay_parter_wejscie_okno'
        ]
    ]
];

// =============================================================================
// LISTA WSZYSTKICH 13 PRZEKAŹNIKÓW Z OSOBNA (Dla podstrony index_full.php)
// Rozdzielone okna dla Biblioteczki, Łazienki góra i Olka
// =============================================================================
$INDIVIDUAL_ROOMS = [
    // -------------------------------------------------------------------------
    // 1. PIĘTRO 2 (7 pojedynczych przekaźników)
    // -------------------------------------------------------------------------
    'pietro-2_lazienka_prawe' => [
        'name' => 'Łazienka góra - prawe',
        'desc' => 'Piętro 2 • roleta prawa łazienki',
        'floor' => 'pietro_2',
        'icon' => 'bath',
        'relays' => ['relay_pietro-2_lazienka_prawe']
    ],
    'pietro-2_lazienka_lewe' => [
        'name' => 'Łazienka góra - lewe',
        'desc' => 'Piętro 2 • roleta lewa łazienki',
        'floor' => 'pietro_2',
        'icon' => 'bath',
        'relays' => ['relay_pietro-2_lazienka_lewe']
    ],
    'pietro-2_przemek' => [
        'name' => 'Przemek',
        'desc' => 'Piętro 2 • pokój Przemka',
        'floor' => 'pietro_2',
        'icon' => 'bed',
        'relays' => ['relay_pietro-2_przemek']
    ],
    'pietro-2_olek_glowne' => [
        'name' => 'Olek - główne',
        'desc' => 'Piętro 2 • pokój Olka okno główne',
        'floor' => 'pietro_2',
        'icon' => 'bed',
        'relays' => ['relay_pietro-2_olek_glowne']
    ],
    'pietro-2_olek_balkon' => [
        'name' => 'Olek - balkon',
        'desc' => 'Piętro 2 • pokój Olka okno balkonowe',
        'floor' => 'pietro_2',
        'icon' => 'bed',
        'relays' => ['relay_pietro-2_olek_balkon']
    ],
    'pietro-2_biblioteczka_glowne' => [
        'name' => 'Biblioteczka - główne',
        'desc' => 'Piętro 2 • biblioteczka okno główne',
        'floor' => 'pietro_2',
        'icon' => 'book',
        'relays' => ['relay_pietro-2_biblioteczka_glowne']
    ],
    'pietro-2_biblioteczka_balkon' => [
        'name' => 'Biblioteczka - balkon',
        'desc' => 'Piętro 2 • biblioteczka okno balkonowe',
        'floor' => 'pietro_2',
        'icon' => 'book',
        'relays' => ['relay_pietro-2_biblioteczka_balkon']
    ],

    // -------------------------------------------------------------------------
    // 2. PARTER (6 pojedynczych przekaźników)
    // -------------------------------------------------------------------------
    'parter_mama' => [
        'name' => 'Mama',
        'desc' => 'Parter • pokój Mamy',
        'floor' => 'parter',
        'icon' => 'bed',
        'relays' => ['relay_parter_mama']
    ],
    'parter_lazienka' => [
        'name' => 'Łazienka dół',
        'desc' => 'Parter • łazienka dolna',
        'floor' => 'parter',
        'icon' => 'bath',
        'relays' => ['relay_parter_lazienka']
    ],
    'parter_komputerowka' => [
        'name' => 'Komputerówka',
        'desc' => 'Parter • pokój komputerowy',
        'floor' => 'parter',
        'icon' => 'computer',
        'relays' => ['relay_parter_komputerowka']
    ],
    'parter_ogrod' => [
        'name' => 'Ogród',
        'desc' => 'Parter • wyjście na ogród',
        'floor' => 'parter',
        'icon' => 'garden',
        'relays' => ['relay_parter_ogrod']
    ],
    'parter_wejscie_glowne' => [
        'name' => 'Wejście główne',
        'desc' => 'Parter • drzwi wejściowe',
        'floor' => 'parter',
        'icon' => 'door',
        'relays' => ['relay_parter_wejscie_glowne']
    ],
    'parter_wejscie_okno' => [
        'name' => 'Wejście okno',
        'desc' => 'Parter • okno przy wejściu',
        'floor' => 'parter',
        'icon' => 'window',
        'relays' => ['relay_parter_wejscie_okno']
    ]
];

// Opcjonalne grupy zbiorcze (dostępne przez parametr ?extra=1)
$EXTRA_ROOMS = [
    'pietro_2_all' => [
        'name' => 'Wszystkie: Piętro 2',
        'desc' => 'Wszystkie 7 rolet piętra 2',
        'floor' => 'pietro_2',
        'icon' => 'roof',
        'relays' => [
            'relay_pietro-2_lazienka_prawe',
            'relay_pietro-2_lazienka_lewe',
            'relay_pietro-2_przemek',
            'relay_pietro-2_olek_glowne',
            'relay_pietro-2_olek_balkon',
            'relay_pietro-2_biblioteczka_glowne',
            'relay_pietro-2_biblioteczka_balkon'
        ]
    ],
    'parter_all' => [
        'name' => 'Wszystkie: Parter',
        'desc' => 'Wszystkie 6 rolet parteru',
        'floor' => 'parter',
        'icon' => 'living_room',
        'relays' => [
            'relay_parter_mama',
            'relay_parter_lazienka',
            'relay_parter_ogrod',
            'relay_parter_komputerowka',
            'relay_parter_wejscie_glowne',
            'relay_parter_wejscie_okno'
        ]
    ]
];
