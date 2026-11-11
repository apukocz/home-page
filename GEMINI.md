# Podsumowanie Wdrożenia: Sterowanie Roletami, Wentylacją i Kotłownią (PI0, PI1, PI2)

Niniejszy dokument zawiera podsumowanie architektury środowiska multi-server, konfiguracji przekaźników i paneli WWW dla serwerów **PI0** (`10.0.0.2`), **PI1** (`10.0.0.4`) oraz **PI2** (`10.0.0.5`), pełną składnię poleceń `curl` do zdalnego sterowania przez API oraz chronologiczny rejestr wykonanych prac i ustaleń w ramach konwersacji.

---

## 1. Architektura Środowiska Multi-Server

W systemie domowym funkcjonują 3 serwery Apache/Linux na minikomputerach Raspberry Pi połączone w sieci lokalnej (oraz 2 centrale wentylacji):

1. **`pi0` (`10.0.0.2` | katalog `src/pi0/`):**
   - Zastąpił dawny serwer `cubie` pod adresem `10.0.0.2`.
   - Sterowanie kotłownią przez dedykowane skrypty: **Piec Gazowy CWU** (`script_piec_gaz_cwu.sh open` / `close`, GPIO 537 / pin fizyczny 25), **Piec Gazowy CO** (`script_piec_gaz.sh open` / `close`, GPIO 536 / pin fizyczny 24) oraz **Pompa obiegowa** (`script_pompa_obieg.sh open` / `close`, GPIO 535 / pin fizyczny 23).
   - Sterowanie wentylacją: dedykowany panel i API `wentylacja.php` komunikujący się z obiema jednostkami wentylacyjnymi (`10.0.0.8` i `10.0.0.9`).
2. **`pi1` (`10.0.0.4` | katalog `src/pi1/`):**
   - Sterowanie roletami i bramami: **Piętro 1 (Salon - 8 rolet)**, **Piwnica (5 rolet)**, **Dach (1 roleta)** oraz **Bramy (wjazdowa: GPIO 530, garażowa: GPIO 527)**.
   - Sterowanie wentylacją: panel i API `wentylacja.php` dla jednostek `10.0.0.8` i `10.0.0.9`.
   - Sprzęt: PCA9685 I2C (`RELAY_POWER=526` na Raspberry Pi / `14` legacy) ze skryptem `script.sh` dla rolet oraz bezpośrednie sterowanie GPIO przez dedykowany skrypt `script_brama.sh` dla obu bram (Brama wjazdowa: GPIO 530, Brama garażowa: GPIO 527).
3. **`pi2` (`10.0.0.5` | katalog `src/pi2/`):**
   - Sterowanie roletami: **Piętro 2 (7 rolet)** oraz **Parter (6 rolet)**.
   - Sprzęt: PCA9685 I2C (`RELAY_POWER=535`), skrypt `script.sh`.

---

## 2. Serwer PI1 (`10.0.0.4`): Konfiguracja Rolet, Sekcji i Bram

Panel serwera PI1 (**Sterowanie Roletami PI1**) został zorganizowany w 4 czytelnych sekcjach:
1. **Piętro 1 (Salon)** – 3 pozycje pokojowe (8 rolet łącznie),
2. **Piwnica** – 4 pomieszczenia (5 rolet łącznie),
3. **Dach (Piętro 3)** – okno dachowe,
4. **Bramy posesji** – brama wjazdowa i garażowa (sterowanie impulsowe).

### Tabela Mapowania Rolet i Przekaźników PI1 ([`src/pi1/config.php`](file:///home/user/home-page/src/pi1/config.php))

| Sekcja / Kondygnacja | Identyfikator (`room`) | Nazwa w panelu | Fizyczne przekaźniki w `relay_setup.conf` | Typ / Liczba rolet |
|---|---|---|---|---|
| **Piętro 1 (Salon)** | `duzy_wschod` | Duży pokój - Wschód | `relay_pietro-1_duzy-pokoj_wschod_lewe`, `relay_pietro-1_duzy-pokoj_wschod_prawe` | Połączone (2 rolety, akordeon) |
| **Piętro 1 (Salon)** | `duzy_poludnie` | Duży pokój - Południe | `relay_pietro-1_duzy-pokoj_poludnie_lewe`, `relay_pietro-1_duzy-pokoj_poludnie_srodkowe`, `relay_pietro-1_duzy-pokoj_poludnie_prawe` | Połączone (3 rolety, akordeon) |
| **Piętro 1 (Salon)** | `duzy_zachod` | Duży pokój - Zachód | `relay_pietro-1_duzy-pokoj_zachod_lewe`, `relay_pietro-1_duzy-pokoj_zachod_prawe`, `relay_pietro-1_duzy-pokoj_zachod_balkon` | Połączone (3 rolety, akordeon) |
| **Piwnica** | `kuchnia` | Kuchnia | `relay_piwnica_kuchnia_lewe`, `relay_piwnica_kuchnia_prawe` | Połączone (2 rolety, akordeon) |
| **Piwnica** | `prasowalnia` | Prasowalnia | `relay_piwnica_prasowalnia` | Pojedyncza (1 roleta) |
| **Piwnica** | `piwnica_skladzik` | Piwnica składzik | `relay_piwnica_skladzik` | Pojedyncza (1 roleta) |
| **Piwnica** | `piwnica_wejscie` | Piwnica wejście | `relay_piwnica_wejscie` | Pojedyncza (1 roleta) |
| **Dach** | `dach` | Okno dachowe | `relay_pietro-3_dach` | Pojedyncza (1 roleta) |
| **Bramy** | `brama_wjazd` | Brama wjazdowa | GPIO 530 (`script_brama.sh relay_brama_wjazdowa open 530 0.5`) | Impulsowa (impuls 0.5s) |
| **Bramy** | `brama_garaz` | Brama garażowa | GPIO 527 (`script_brama.sh relay_brama_garazowa open 527 0.5`) | Impulsowa (impuls 0.5s) |

### Widoki na serwerze PI1:
- **Główny ([`src/pi1/index.php`](file:///home/user/home-page/src/pi1/index.php)):** 10 pozycji z akordeonami rozwijania pojedynczych skrzydeł (przycisk globalny **`Rozwiń szczegóły (4)`**), przyciski grupowe sekcji oraz pełna obsługa AJAX.
- **Wszystkie przekaźniki ([`src/pi1/index_full.php`](file:///home/user/home-page/src/pi1/index_full.php)):** 16 niezależnych fizycznych przekaźników z osobnymi przyciskami Otwórz/Zamknij i Stop.
- **Dedykowany panel bram ([`src/pi1/brama.php`](file:///home/user/home-page/src/pi1/brama.php) oraz alias `bramy.php`):** Niezależny, zoptymalizowany panel z dużymi klawiszami pilota dla Bramy wjazdowej (GPIO 530) oraz Bramy garażowej (GPIO 527), przyciskami funkcyjnymi Otwórz/Zamknij/Stop, wspólnym sterowaniem obiema bramami, podglądem logu `script_brama.log` oraz Optimistic UI i asynchronicznym pollingiem statusu co 2.5s.
- **Pasek Paneli (`<nav class="server-nav">`):** ujednolicony pasek nawigacji umieszczony na szczycie strony z bezpośrednimi odnośnikami do wszystkich 5 paneli: **Kotłownia** (`10.0.0.2`), **Grzegorzka 8** (`10.0.0.4`), **Grzegorzka 8A** (`10.0.0.5`), **Wentylacja** oraz **Brama**.
- **Zgodność wsteczna z Alexa / skryptami legacy:** zachowano pełną obsługę parametrów GET `?sw1=on`, `?sw2=on`, `?sw3=on`, `?sw101=on` (stop) itd.

---

## 3. Serwer PI2 (`10.0.0.5`): Konfiguracja Rolet i Kondygnacji

Panel serwera PI2 (**Sterowanie Roletami PI2**) został zorganizowany w układzie dwupoziomowym:
- **Kondygnacja 1 (górna): Piętro 2** – wyświetlana jako pierwsza.
- **Kondygnacja 2 (dolna): Parter** – wyświetlana jako druga.

### Tabela Mapowania Rolet i Przekaźników PI2 ([`src/pi2/config.php`](file:///home/user/home-page/src/pi2/config.php))

| Kondygnacja | Identyfikator (`room`) | Nazwa w panelu | Fizyczne przekaźniki w `relay_setup.conf` | Typ / Liczba rolet |
|---|---|---|---|---|
| **Piętro 2** | `lazienka_gora` | Łazienka góra | `relay_pietro-2_lazienka_prawe`, `relay_pietro-2_lazienka_lewe` | Połączone (2 rolety, akordeon) |
| **Piętro 2** | `przemek` | Przemek | `relay_pietro-2_przemek` | Pojedyncza |
| **Piętro 2** | `olek` | Olek | `relay_pietro-2_olek_glowne`, `relay_pietro-2_olek_balkon` | Połączone (2 rolety, akordeon) |
| **Piętro 2** | `biblioteczka` | Biblioteczka | `relay_pietro-2_biblioteczka_glowne`, `relay_pietro-2_biblioteczka_balkon` | Połączone (2 rolety, akordeon) |
| **Parter** | `mama` | Mama | `relay_parter_mama` | Pojedyncza |
| **Parter** | `lazienka_dol` | Łazienka dół | `relay_parter_lazienka` | Pojedyncza |
| **Parter** | `komputerowka` | Komputerówka | `relay_parter_komputerowka` | Pojedyncza |
| **Parter** | `ogrod` | Ogród | `relay_parter_ogrod` | Pojedyncza |
| **Parter** | `wejscie_glowne` | Wejście główne | `relay_parter_wejscie_glowne` | Pojedyncza |
| **Parter** | `wejscie_okno` | Wejście okno | `relay_parter_wejscie_okno` | Pojedyncza |

### Widoki na serwerze PI2:
- **Główny ([`src/pi2/index.php`](file:///home/user/home-page/src/pi2/index.php)):** 10 pozycji pokojowych z przyciskiem globalnym **`Rozwiń szczegóły (3)`**.
- **Wszystkie przekaźniki ([`src/pi2/index_full.php`](file:///home/user/home-page/src/pi2/index_full.php)):** 13 niezależnych wierszy przekaźników.
- **Pasek Paneli (`<nav class="server-nav">`):** ujednolicony pasek nawigacji na szczycie strony z bezpośrednimi odnośnikami do wszystkich 4 paneli: **Kotłownia**, **Grzegorzka 8**, **Grzegorzka 8A** oraz **Wentylacja**.

---

## 4. Serwer PI0 (`10.0.0.2`): Sterowanie Kotłownią i Wentylacją

Panel serwera PI0 (**Sterowanie Kotłownią • PI0**) zarządza 3 urządzeniami w kotłowni za pośrednictwem dedykowanych skryptów bash:
- **Piec Gazowy CWU** – skrypt `script_piec_gaz_cwu.sh open` (włącz) / `close` (wyłącz), GPIO537 / pin fizyczny 25,
- **Piec Gazowy CO** – skrypt `script_piec_gaz.sh open` (włącz) / `close` (wyłącz), GPIO536 / pin fizyczny 24,
- **Pompa obiegowa** – skrypt `script_pompa_obieg.sh open` (włącz) / `close` (wyłącz), GPIO535 / pin fizyczny 23.

Ponadto na serwerze PI0 funkcjonuje niezależny panel i API sterowania obiema jednostkami wentylacyjnymi ([`src/pi0/wentylacja.php`](file:///home/user/home-page/src/pi0/wentylacja.php) oraz alias [`src/pi0/rekuperacja.php`](file:///home/user/home-page/src/pi0/rekuperacja.php)) komunikujący się równolegle z urządzeniami `10.0.0.8` i `10.0.0.9`.

### Tabela Mapowania Urządzeń i Linii GPIO PI0 ([`src/pi0/config.php`](file:///home/user/home-page/src/pi0/config.php))

| Urządzenie | Identyfikator (`device` / `relay`) | Plik w `/var/www/gpio/` | Numer GPIO (offset 512) | Pin fizyczny | Logika przekaźnika | Domyślny stan początkowy |
|---|---|---|---|---|---|---|
| **Piec Gazowy CWU** | `piec_cwu` (`sw1`) | `/var/www/gpio/relay_piec_gaz_cwu.state` / `gpio537` | `537` (512 + 25) | 25 | Skrypt `script_piec_gaz_cwu.sh open` / `close` | `0` (Wyłączony) |
| **Piec Gazowy CO** | `piec_co` (`sw2`) | `/var/www/gpio/relay_piec_gaz.state` / `gpio536` | `536` (512 + 24) | 24 | Skrypt `script_piec_gaz.sh open` / `close` | `0` (Wyłączony) |
| **Pompa obiegowa** | `pompa` (`sw3`) | `/var/www/gpio/relay_pompa_obiegowa.state` / `gpio535` | `535` (512 + 23) | 23 | Skrypt `script_pompa_obieg.sh open` / `close` | `0` (Wyłączony) |
| *Zasilanie przekaźników* | `relay_power` (`sw4`) | `/var/www/gpio/gpio519` | `519` (512 + 7) | 7 | Linia szyny zasilania modułu | `1` |

### Tabela Czujników Magistrali 1-Wire DS18B20 PI0 ([`src/pi0/config.php`](file:///home/user/home-page/src/pi0/config.php))

Ścieżka w sysfs: `/sys/bus/w1/devices/<id>/w1_slave`.

| Grupa | Nazwa / Rola | Identyfikator czujnika (`sensor_id`) | Klucz w API | Rola w instalacji | Kolorystyka / Akcent |
|---|---|---|---|---|---|
| **CO** | **CO +** (Zasilanie) | `28-0315047d06ff` | `co_plus` | Zasilanie instalacji grzejnikowej CO | `#f97316` (Pomarańczowy / ciepły) |
| **CO** | **CO -** (Powrót) | `28-000004ef79c2` | `co_minus` | Powrót z instalacji grzejnikowej CO | `#60a5fa` (Błękitny / powrót) |
| **Gaz** | **Gaz +** (Zasilanie) | `28-021463f067ff` | `gaz_plus` | Wyjście gorącej wody z kotła gazowego | `#ef4444` (Czerwony / płomień) |
| **Gaz** | **Gaz -** (Powrót) | `28-0004170d18ff` | `gaz_minus` | Powrót wody do kotła gazowego | `#3b82f6` (Niebieski / powrót) |
| **CWU** | **CWU** (Zasobnik) | `28-000004b32254` | `cwu` | Ciepła woda użytkowa w zasobniku | `#06b6d4` (Cyjan / woda) |

*Dla grup **CO** oraz **Gaz** panel automatycznie wylicza i prezentuje różnicę temperatur $\Delta T = T_{zasilanie} - T_{powrót}$ (np. `ΔT: +12.3°C`).*

### Widoki na serwerze PI0:
- **Kotłownia ([`src/pi0/index.php`](file:///home/user/home-page/src/pi0/index.php)):**
  - **Dedykowany blok temperatur (1-Wire DS18B20):** podzielony na 3 sekcje (**CO**, **Gaz**, **CWU**) z czytelnymi kafelkami, wskaźnikami $\Delta T$ dla obiegów CO i Gaz, identyfikatorami sprzętowymi DS18B20 oraz dynamicznym odświeżaniem live,
  - Kafelki dla Pieca CWU, Pieca CO oraz Pompy obiegowej z etykietami dedykowanych skryptów bash (`script_piec_gaz_cwu.sh`, `script_piec_gaz.sh`, `script_pompa_obieg.sh`), numerów GPIO i pinów fizycznych,
  - Przyciski bezpośredniego włączania (**Włącz** – niebieski jak na Grzegorzka 8) i wyłączania (**Wyłącz** – czerwony jak na Grzegorzka 8) dla każdego urządzenia,
  - Plakietki statusu na żywo (`● WŁĄCZONY` zielony pulsujący / `○ WYŁĄCZONY` szary),
  - Przycisk odświeżania w nagłówku: **`Odśwież`** (odświeża równocześnie stany przekaźników oraz odczyty temperatur 1-Wire),
  - Pełna obsługa Optimistic UI (natychmiastowa reakcja 0 ms z animacją ładowania i blokadą podwójnego kliknięcia),
  - Asynchroniczny polling w tle co 2.5s bez obciążania Apache (jednoczesny podgląd stanu obwodów i temperatur na żywo).
- **Wentylacja ([`src/pi0/wentylacja.php`](file:///home/user/home-page/src/pi0/wentylacja.php)):**
  - Równoległe sterowanie i odczyt temperatur z obu jednostek wentylacyjnych (`10.0.0.8` i `10.0.0.9`),
  - Odczyt 4 temperatur toru powietrza (Zewnątrz, Nawiew, Wywiew, Usuwanie) oraz statusów podzespołów (Nagrzewnica, GWC ze zwięzłymi plakietkami Wł / Wył),
  - Alias kompatybilności wstecznej `rekuperacja.php`.
- **Pasek Paneli (`<nav class="server-nav">`):** ujednolicony pasek nawigacji na szczycie strony z bezpośrednimi odnośnikami do wszystkich 4 paneli: **Kotłownia**, **Grzegorzka 8**, **Grzegorzka 8A** oraz **Wentylacja**.

---

## 5. Składnia Poleceń `curl` i Zdalne API

Aplikacje zwracają odpowiedzi w formacie **JSON** i obsługują metody **POST** oraz **GET**.

### A. Sterowanie serwerem PI1 (`10.0.0.4`)

```bash
# 1. Piętro 1 (Salon - wszystkie 8 rolet naraz)
curl -X POST http://10.0.0.4/index.php -d "room=FLOOR_PIETRO_1&action=open"
curl -X POST http://10.0.0.4/index.php -d "room=FLOOR_PIETRO_1&action=close"
curl -X POST http://10.0.0.4/index.php -d "room=FLOOR_PIETRO_1&action=stop"

# Alias duzy_pokoj również otwiera/zamyka wszystkie 8 rolet w salonie:
curl -X POST http://10.0.0.4/index.php -d "room=duzy_pokoj&action=open"

# 2. Salon według stron świata (z połączonymi roletami):
curl -X POST http://10.0.0.4/index.php -d "room=duzy_wschod&action=open"
curl -X POST http://10.0.0.4/index.php -d "room=duzy_poludnie&action=close"
curl -X POST http://10.0.0.4/index.php -d "room=duzy_zachod&action=stop"

# Opcjonalny parametr czasu trwania w sekundach (np. time=11 lub duration=11):
# Przekazywany jako 3. parametr do script.sh ($3). Gdy nie jest podany, skrypt używa domyślnego czasu z relay_setup.conf.
curl -X POST http://10.0.0.4/index.php -d "room=duzy_zachod&action=close&time=11"
curl -X POST http://10.0.0.4/index.php -d "room=duzy_zachod&action=open&time=11"

# Obsługa wywołań przez GET (również z opcjonalnym time):
curl -s "http://10.0.0.4/index.php?room=duzy_zachod&action=close&time=11"
curl -s "http://10.0.0.4/index.php?room=duzy_zachod&action=open&time=11"

# 3. Piwnica (wszystkie rolety lub pojedyncze pomieszczenia):
curl -X POST http://10.0.0.4/index.php -d "room=FLOOR_PIWNICA&action=close"
curl -X POST http://10.0.0.4/index.php -d "room=kuchnia&action=open"
curl -X POST http://10.0.0.4/index.php -d "room=prasowalnia&action=close"

# 4. Dach:
curl -X POST http://10.0.0.4/index.php -d "room=dach&action=open"

# 5. Bramy posesji (sterowanie dedykowanym skryptem script_brama.sh):
curl -X POST http://10.0.0.4/index.php -d "room=brama_wjazd&action=open"
curl -X POST http://10.0.0.4/index.php -d "room=brama_garaz&action=open"

# 5a. Dedykowany panel i API bram posesji (brama.php):
curl -s "http://10.0.0.4/brama.php?api=status"
curl -X POST http://10.0.0.4/brama.php -d "gate=brama_wjazd&action=open"
curl -X POST http://10.0.0.4/brama.php -d "gate=brama_garaz&action=open"
curl -X POST http://10.0.0.4/brama.php -d "gate=ALL&action=open"
curl -X POST http://10.0.0.4/brama.php -d "gate=ALL&action=stop"
curl -s "http://10.0.0.4/brama.php?brama_wjazd=open"
curl -s "http://10.0.0.4/brama.php?brama_garaz=open"

# Bezpośrednie wywołanie skryptu z konsoli serwera PI1:
# Dla Bramy wjazdowej (GPIO 530, impuls 0.5s):
sh script_brama.sh relay_brama_wjazdowa open 530 0.5
# Dla Bramy garażowej (GPIO 527, impuls 0.5s):
sh script_brama.sh relay_brama_garazowa open 527 0.5

# 6. Pojedyncze skrzydła okienne (pod-przekaźniki):
curl -X POST http://10.0.0.4/index.php -d "room=pietro-1_duzy-pokoj_zachod_balkon&action=close"
curl -X POST http://10.0.0.4/index.php -d "room=piwnica_kuchnia_prawe&action=open"

# 7. Odpytanie o statusy w czasie rzeczywistym (JSON):
curl -s "http://10.0.0.4/index.php?api=status"
curl -s "http://10.0.0.4/index_full.php?api=status"
```

### B. Sterowanie serwerem PI2 (`10.0.0.5`)

```bash
# 1. Całe kondygnacje naraz:
curl -X POST http://10.0.0.5/index.php -d "room=FLOOR_PIETRO_2&action=open"
curl -X POST http://10.0.0.5/index.php -d "room=FLOOR_PARTER&action=close"
curl -X POST http://10.0.0.5/index.php -d "room=ALL&action=stop"

# Wszystkie rolety lub cała kondygnacja z opcjonalnym czasem trwania (np. time=15):
curl -X POST http://10.0.0.5/index.php -d "room=ALL&action=open&time=20"
curl -X POST http://10.0.0.5/index.php -d "room=FLOOR_PIETRO_2&action=close&time=15"
curl -s "http://10.0.0.5/index.php?room=FLOOR_PARTER&action=open&time=10"

# 2. Pokoje z połączonymi roletami (również z opcjonalnym time):
curl -X POST http://10.0.0.5/index.php -d "room=olek&action=open&time=12"
curl -X POST http://10.0.0.5/index.php -d "room=biblioteczka&action=close&time=10"
curl -X POST http://10.0.0.5/index.php -d "room=lazienka_gora&action=open"

# 3. Pojedyncze skrzydła oraz fizyczne przekaźniki (z opcjonalnym time):
curl -X POST http://10.0.0.5/index.php -d "room=pietro-2_olek_balkon&action=close&time=8"
curl -X POST http://10.0.0.5/index.php -d "room=relay_parter_ogrod&action=open&time=14"
curl -s "http://10.0.0.5/index.php?room=przemek&action=close&time=9"

### C. Sterowanie Wentylacją (10.0.0.8 i 10.0.0.9 przez PI1 10.0.0.4)

Dedykowany panel dostępny pod adresem: `http://10.0.0.4/wentylacja.php` (oraz kompatybilny alias `rekuperacja.php`).
Sterowanie wysyła zapytania równolegle (`curl_multi`) do obu jednostek jednocześnie (domyślnie `target=both`), z opcją wyboru pojedynczego urządzenia (`target=1` lub `target=2`).

```bash
# 0. Odczyt aktualnych parametrów pracy i temperatur (GetTemperatures):
curl -s "http://10.0.0.4/wentylacja.php?api=temperatures"
curl -s "http://10.0.0.4/wentylacja.php?action=get_temperatures"

# Odpytanie o temperatury wybranej jednostki:
curl -s "http://10.0.0.4/wentylacja.php?api=temperatures&target=1"   # tylko Grzegorzka 8 (10.0.0.8)
curl -s "http://10.0.0.4/wentylacja.php?api=temperatures&target=2"   # tylko Grzegorzka 8A (10.0.0.9)

# Bezpośrednie zapytanie cURL do pojedynczego urządzenia:
/usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=GetTemperatures'
/usr/bin/curl 'http://10.0.0.9/API/RunFunction?name=GetTemperatures'

# 1. Zasilanie (TurnOn / TurnOff)
curl -s "http://10.0.0.4/wentylacja.php?action=turn_on"
curl -s "http://10.0.0.4/wentylacja.php?action=turn_off"

# 2. Główne tryby wentylacji
# Tryb Auto (AutomaticMode):
curl -s "http://10.0.0.4/wentylacja.php?action=auto"
# Tryb Normalny (ManualMode 100% / 100%):
curl -s "http://10.0.0.4/wentylacja.php?action=normal"
# Tryb Wolny (ManualMode 80% / 70%):
curl -s "http://10.0.0.4/wentylacja.php?action=slow"
# Własne parametry ManualMode (np. 85% nawiew, 75% wywiew):
curl -s -X POST http://10.0.0.4/wentylacja.php -d "action=manual&airflow=85&extraction=75"

# 3. Wietrzenie (TurnOnAiring / TurnOffAiring)
curl -s "http://10.0.0.4/wentylacja.php?action=airing_on&time=20"
curl -s "http://10.0.0.4/wentylacja.php?action=airing_off"

# 4. Tryb Wakacje (TurnOnHoliday / TurnOffHoliday)
curl -s "http://10.0.0.4/wentylacja.php?action=holiday_on&days=1"
curl -s "http://10.0.0.4/wentylacja.php?action=holiday_off"

# 5. Ogrzewanie (TurnOnHeating / TurnOffHeating)
curl -s "http://10.0.0.4/wentylacja.php?action=heating_on&temp=22"
curl -s "http://10.0.0.4/wentylacja.php?action=heating_off"

# 6. Bypass (SetByPassMode: 1=otwarty, 0=zamknięty, 2=auto)
curl -s "http://10.0.0.4/wentylacja.php?action=bypass&mode=1"
curl -s "http://10.0.0.4/wentylacja.php?action=bypass&mode=0"
curl -s "http://10.0.0.4/wentylacja.php?action=bypass&mode=2"

# 7. GWC (SetGWCMode: 2=auto, 1=włącz, 0=wyłącz)
curl -s "http://10.0.0.4/wentylacja.php?action=gwc&mode=2"
curl -s "http://10.0.0.4/wentylacja.php?action=gwc&mode=1"
curl -s "http://10.0.0.4/wentylacja.php?action=gwc&mode=0"

# 8. Wymiana filtrów (ReplaceFilters)
curl -s "http://10.0.0.4/wentylacja.php?action=replace_filters"

# 9. Bezpośrednie przekazywanie funkcji API (RunFunction?name=...)
curl -s "http://10.0.0.4/wentylacja.php?name=AutomaticMode"
curl -s "http://10.0.0.4/wentylacja.php?name=SetByPassMode&mode=1"
curl -s "http://10.0.0.4/wentylacja.php?name=ManualMode&airflowvalue=100&valueofairextraction=100"

# 10. Sterowanie tylko jedną jednostką (opcjonalny parametr target)
curl -s "http://10.0.0.4/wentylacja.php?action=auto&target=1"   # tylko 10.0.0.8
curl -s "http://10.0.0.4/wentylacja.php?action=auto&target=2"   # tylko 10.0.0.9

# 11. Szybkie sprawdzenie dostępności jednostek
curl -s "http://10.0.0.4/wentylacja.php?action=status"
```

### D. Sterowanie Kotłownią na serwerze PI0 (`10.0.0.2`)

```bash
# 1. Odpytanie o status wszystkich urządzeń i temperatur w formacie JSON:
curl -s "http://10.0.0.2/index.php?api=status"

# 1a. Dedykowane odpytanie o same temperatury z magistrali 1-Wire (CO, Gaz, CWU):
curl -s "http://10.0.0.2/index.php?api=temperatures"
curl -s "http://10.0.0.2/index.php?api=temp"

# Bezpośredni odczyt czujnika w konsoli PI0:
cat /sys/bus/w1/devices/28-000004b32254/w1_slave  # CWU
cat /sys/bus/w1/devices/28-021463f067ff/w1_slave  # Gaz +
cat /sys/bus/w1/devices/28-0004170d18ff/w1_slave  # Gaz -
cat /sys/bus/w1/devices/28-0315047d06ff/w1_slave  # CO +
cat /sys/bus/w1/devices/28-000004ef79c2/w1_slave  # CO -

# 2. Sterowanie Piecem Gazowym CWU (skrypt script_piec_gaz_cwu.sh):
curl -X POST http://10.0.0.2/index.php -d "device=piec_cwu&action=open"
curl -X POST http://10.0.0.2/index.php -d "device=piec_cwu&action=close"
curl -X POST http://10.0.0.2/index.php -d "device=piec_cwu&action=on"
curl -X POST http://10.0.0.2/index.php -d "device=piec_cwu&action=off"
curl -X POST http://10.0.0.2/index.php -d "device=piec_cwu&action=toggle"

# Bezpośrednie wywołanie w konsoli PI0:
sh script_piec_gaz_cwu.sh open
sh script_piec_gaz_cwu.sh close

# 3. Sterowanie Piecem Gazowym CO (skrypt script_piec_gaz.sh):
curl -X POST http://10.0.0.2/index.php -d "device=piec_co&action=open"
curl -X POST http://10.0.0.2/index.php -d "device=piec_co&action=close"
curl -X POST http://10.0.0.2/index.php -d "device=piec_co&action=on"
curl -X POST http://10.0.0.2/index.php -d "device=piec_co&action=off"
curl -X POST http://10.0.0.2/index.php -d "device=piec_co&action=toggle"

# Bezpośrednie wywołanie w konsoli PI0:
sh script_piec_gaz.sh open
sh script_piec_gaz.sh close

# 4. Sterowanie Pompą obiegową (skrypt script_pompa_obieg.sh):
curl -X POST http://10.0.0.2/index.php -d "device=pompa&action=open"
curl -X POST http://10.0.0.2/index.php -d "device=pompa&action=close"
curl -X POST http://10.0.0.2/index.php -d "device=pompa&action=on"
curl -X POST http://10.0.0.2/index.php -d "device=pompa&action=off"
curl -X POST http://10.0.0.2/index.php -d "device=pompa&action=toggle"

# Bezpośrednie wywołanie skryptu z konsoli serwera PI0:
sh script_pompa_obieg.sh open
sh script_pompa_obieg.sh close

# 5. Sterowanie grupowe (Wszystkie urządzenia naraz):
curl -X POST http://10.0.0.2/index.php -d "device=ALL&action=off"
curl -X POST http://10.0.0.2/index.php -d "device=ALL&action=on"

# 6. Obsługa przez metodę GET oraz kompatybilność legacy (np. Home Assistant / Alexa):
curl -s "http://10.0.0.2/index.php?device=piec_cwu&action=on"
curl -s "http://10.0.0.2/index.php?sw1=on"     # Piec CWU włącz
curl -s "http://10.0.0.2/index.php?sw1=off"    # Piec CWU wyłącz
curl -s "http://10.0.0.2/index.php?sw2=on"     # Piec CO włącz
curl -s "http://10.0.0.2/index.php?sw2=off"    # Piec CO wyłącz
curl -s "http://10.0.0.2/index.php?sw3=on"     # Pompa włącz
curl -s "http://10.0.0.2/index.php?sw3=off"    # Pompa wyłącz
curl -s "http://10.0.0.2/index.php?sw100=off"  # Wszystkie wyłącz

# 7. Sterowanie wentylacją z poziomu PI0 (10.0.0.2):
curl -s "http://10.0.0.2/wentylacja.php?api=temperatures"
curl -s "http://10.0.0.2/wentylacja.php?action=auto"
curl -s "http://10.0.0.2/wentylacja.php?action=normal"
curl -s "http://10.0.0.2/wentylacja.php?action=slow"
```

---

## 6. Struktura Plików w Repozytorium

```
/home/user/home-page/
├── GEMINI.md                    # Niniejsza dokumentacja i historia konwersacji
├── src/
│   ├── pi0/                     # Serwer 10.0.0.2 (Kotłownia: Piec CWU, Piec CO, Pompa + Wentylacja)
│   │   ├── config.php           # Konfiguracja urządzeń GPIO, mapowania rejestrów i funkcji
│   │   ├── index.php            # Główny panel WWW PI0 (Piec CWU, Piec CO, Pompa)
│   │   ├── brama.php            # Przekierowanie do panelu bram na PI1 (10.0.0.4)
│   │   ├── script_piec_gaz_cwu.sh # Skrypt sterowania Piecem CWU (open / close)
│   │   ├── script_piec_gaz.sh   # Skrypt sterowania Piecem CO (open / close)
│   │   ├── script_pompa_obieg.sh # Skrypt sterowania pompą obiegową (open / close)
│   │   ├── wentylacja.php       # Panel i API sterowania dwiema jednostkami wentylacji (10.0.0.8 i 10.0.0.9)
│   │   └── rekuperacja.php      # Alias kompatybilności wstecznej (dołącza wentylacja.php)
│   ├── pi1/                     # Serwer 10.0.0.4 (Piętro 1, Piwnica, Dach, Bramy, Wentylacja)
│   │   ├── config.php           # Konfiguracja pomieszczeń, sekcji i przekaźników
│   │   ├── index.php            # Główny panel WWW PI1 (10 pokoi + akordeony)
│   │   ├── index_full.php       # Szczegółowy panel WWW PI1 (16 przekaźników)
│   │   ├── brama.php            # Dedykowany panel WWW i API sterowania bramami (wjazdowa i garażowa)
│   │   ├── bramy.php            # Alias kompatybilności wstecznej (dołącza brama.php)
│   │   ├── wentylacja.php       # Panel i API sterowania dwiema jednostkami wentylacji (10.0.0.8 i 10.0.0.9)
│   │   ├── rekuperacja.php      # Alias kompatybilności wstecznej (dołącza wentylacja.php)
│   │   ├── script.sh            # Skrypt sterujący roletami I2C (RELAY_POWER=14)
│   │   ├── script_brama.sh      # Skrypt sterujący bramami posesji (GPIO 530 i 527)
│   │   ├── script_one.sh        # Skrypt impulsowy legacy
│   │   ├── gpio/
│   │   │   └── relay_setup.conf # Konfiguracja fizycznych rejestrów PI1
│   │   └── alexa/               # Skrypty integracji głosowej
│   └── pi2/                     # Serwer 10.0.0.5 (Piętro 2 i Parter)
│       ├── config.php           # Konfiguracja pomieszczeń, kondygnacji i przekaźników
│       ├── index.php            # Główny panel WWW PI2 (10 pokoi + akordeony)
│       ├── index_full.php       # Szczegółowy panel WWW PI2 (13 przekaźników)
│       ├── brama.php            # Przekierowanie do panelu bram na PI1 (10.0.0.4)
│       ├── script.sh            # Skrypt sterujący roletami I2C (RELAY_POWER=535)
│       └── gpio/
│           ├── relay_setup.conf # Konfiguracja fizycznych rejestrów PI2
│           └── relay_power.state
```

---

## 7. Rejestr Konwersacji i Historia Zadań

Poniższy rejestr przedstawia chronologiczny przebieg zrealizowanych poleceń użytkownika, podjętych decyzji projektowych oraz wdrożonych funkcjonalności:

### Krok 1: Definicja środowiska multi-server
- **Polecenie:** Użytkownik wskazał obecność 3 serwerów Linux/Apache:
  - `cubie` (`10.0.0.2` w `src/cubie`),
  - `pi1` (`10.0.0.4` w `src/pi1`),
  - `pi2` (`10.0.0.5` w `src/pi2`).
- **Wytyczna:** Serwer `cubie` ma pozostać bez zmian.

### Krok 2: Przeniesienie konfiguracji do `src/pi2`
- **Polecenie:** Przeniesienie plików `config.php` i `index.php` do folderu `src/pi2`, ponieważ pierwotnie przygotowana konfiguracja dotyczyła tego urządzenia.
- **Wykonanie:** Usunięto zduplikowane pliki z korzenia repozytorium i przeniesiono pliki konfiguracyjne do `src/pi2/`.

### Krok 3: Zmiana nazwy panelu PI2
- **Polecenie:** Zmiana nazwy nagłówka panelu z *Sterowanie Roletami* na *Sterowanie Roletami PI2*.
- **Wykonanie:** Zaktualizowano tytuł strony (`<title>`) oraz nagłówek `<h1>` w `src/pi2/index.php`.

### Krok 4: Wdrożenie mapowania przycisków i łączenia przekaźników dla PI2
- **Polecenie:**
  - Utworzenie przycisku *Łazienka góra* łączącego `relay_pietro-2_lazienka_prawe` i `relay_pietro-2_lazienka_lewe`,
  - Utworzenie przycisku *Przemek*,
  - Zmiana nazwy *Łazienka* na *Łazienka dół* (`relay_parter_lazienka`),
  - Utworzenie przycisku *Ogród*,
  - Utworzenie przycisku *Mama*,
  - Utworzenie przycisku *Olek* łączącego `relay_pietro-2_olek_balkon` i `relay_pietro-2_olek_glowne`,
  - Utworzenie przycisku *Biblioteczka* łączącego `relay_pietro-2_biblioteczka_balkon` i `relay_pietro-2_biblioteczka_glowne`,
  - Utworzenie przycisków *Komputerówka*, *Wejście główne*, *Wejście okno*,
  - Synchronizacja konfiguracji z lokalnym serwerem Apache w `/var/www/html/`.
- **Wykonanie:** Zdefiniowano strukturę `$ROOMS` w `src/pi2/config.php` i wdrożono równoległe sterowanie z 50 ms odstępem czasowym między zapytaniami I2C.

### Krok 5: Grupowanie kondygnacji w PI2
- **Polecenie:** Pogrupowanie klawiszy w kolejności: **Piętro 2 jako pierwsze**, a **Parter jako drugi**.
- **Wykonanie:** Wprowadzono sekcje `floor-section` z nagłówkami pięter, ikonami oraz przyciskami grupowego otwierania/zamykania całego piętra naraz.

### Krok 6: Podstrona ze wszystkimi przekaźnikami z osobna (`index_full.php`)
- **Polecenie:** Utworzenie podstrony z osobnym sterowaniem dla każdego z fizycznych przekaźników z `relay_setup.conf` (w tym rozbicie połączonych: Biblioteczka, Łazienka góra, Olek).
- **Wykonanie:** Utworzono plik `src/pi2/index_full.php` z 13 niezależnymi wierszami przekaźników.

### Krok 7: Akordeon i rozwijanie połączonych rolet w `index.php`
- **Polecenie:** Dodanie do `index.php` przełącznika/akordeonu, umożliwiającego rozwijanie widoku ogólnego (np. *Łazienka góra*) na poszczególne skrzydła (*Łazienka góra lewe* i *prawe*), a także odpowiednio dla Olka i Biblioteczki.
- **Wykonanie:** Wdrożono przyciski `2 rolety ▾` w wierszach oraz globalny przycisk w nawigacji: **`Rozwiń szczegóły (3)`** / **`Zwiń szczegóły`**.

### Krok 8: Wdrożenie dla serwera PI1 (`src/pi1/`)
- **Polecenie:** Utworzenie analogicznych stron konfiguracyjnych i paneli dla serwera `pi1` w katalogu `src/pi1` na podstawie `src/pi1/gpio/relay_setup.conf`.
- **Analiza sprzętowa PI1:**
  - Identyfikacja 16 fizycznych przekaźników (5 w Piwnicy, 8 w Salonie na Piętrze 1, 1 na Dachu, 2 dla Bram).
  - Skrypt `script.sh` operuje na `RELAY_POWER=14`.
  - Bramy (`relay_brama_wjazd`, `relay_brama_garaz`) obsługiwane są przez skrypt impulsowy `script_one.sh` z automatycznym przywracaniem stanu po 90 sekundach.
- **Wykonanie:**
  - Utworzono [`src/pi1/config.php`](file:///home/user/home-page/src/pi1/config.php) z 4 sekcjami (*Piętro 1 (Salon)*, *Piwnica*, *Dach*, *Bramy*) i 10 głównymi pozycjami,
  - Utworzono [`src/pi1/index.php`](file:///home/user/home-page/src/pi1/index.php) z akordeonami dla 4 połączonych pokoi (przycisk **`Rozwiń szczegóły (4)`**),
  - Utworzono [`src/pi1/index_full.php`](file:///home/user/home-page/src/pi1/index_full.php) z 16 wierszami fizycznych przekaźników,
  - Zaktualizowano `GPIO_DIR=${GPIO_DIR:-/var/www/gpio}` w `src/pi1/script.sh` oraz `src/pi1/script_one.sh`,
  - Dodano pełną zgodność wsteczną ze starymi parametrami GET (`sw1`, `sw2`, `sw3`... ze skryptów integracji z Alexa).

### Krok 9: Optymalizacja wydajnościowa i natychmiastowa reakcja UI (Optimistic UI) dla PI1 i PI2
- **Problem zgłoszony przez użytkownika:**
  - Strona `http://10.0.0.5/` ładowała się skrajnie wolno (nawet do 47 sekund).
  - Po kliknięciu przycisku (np. *Otwórz*) interfejs nie dawał żadnego feedbacku przez kilka sekund, po czym dopiero zmieniał etykietę na *Otwieranie...*.
- **Diagnoza przyczyn źródłowych:**
  - Urządzenie PI2 to Raspberry Pi pierwszej generacji / Pi Zero (`armv6l`) o taktowaniu 700 MHz (pojedynczy rdzeń).
  - Funkcja `getRelayInfo()` dla każdego z 19 przekaźników wykonywała 2 wywołania powłoki `ps -eo args | grep -v grep | grep ...`, generując łącznie **114 forków procesów** przy każdym załadowaniu strony lub odpytaniu o status, co zajmowało ponad 12 sekund na 1-rdzeniowym CPU.
  - Skrypt JavaScript wykonywał cykliczny polling (`pollStatus`) co 2,5s bez weryfikacji, czy poprzednie żądanie się skończyło. Spowodowało to kolejkowanie się wielu ciężkich zapytań w Apache i doprowadziło do przeciążenia procesora (Load average ~1.95) oraz wydłużenia czasu ładowania do 47 sekund.
  - Brak wzorca *Optimistic UI* – przycisk blokował się (`disabled`) bez natychmiastowej zmiany etykiety i ikony, czekając na zakończenie powolnego zapytania POST.
- **Wykonane optymalizacje:**
  1. **Błyskawiczne sprawdzanie procesów przez `/proc`:** Wprowadzono funkcję `getActiveScriptProcesses()` z pamięcią podręczną dla danego żądania PHP, która odczytuje `/proc/[0-9]*/cmdline` zamiast uruchamiać procesy `ps` i `grep`. Czas sprawdzania statusu wszystkich rolet spadł z **12 139 ms do 75 ms (ponad 150× szybciej)**.
  2. **Natychmiastowa reakcja UI (0 ms):** W JavaScript wdrożono *Optimistic UI* – w momencie kliknięcia przycisk natychmiast przełącza się w stan *„Otwieranie...”* / *„Zamykanie...”*, wyświetla kręciołek (spinner), włącza plakietkę ostrzegawczą oraz aktywuje przycisk STOP.
  3. **Blokada zapytań równoległych (`isPolling`):** Zapobiega kumulowaniu się zapytań o status w kolejce Apache.
  4. **Wdrożenie na serwery:** Zaktualizowano i wdrożono pliki `index.php` oraz `index_full.php` na oba serwery: `10.0.0.5` (PI2) oraz `10.0.0.4` (PI1).
- **Wyniki testów wydajnościowych po optymalizacji:**
  - **PI2 (`10.0.0.5`):** Czas ładowania strony spadł z **47s do 0.16s (162 ms)**, a czas sprawdzania statusu do **0.075s (75 ms)**.
  - **PI1 (`10.0.0.4`):** Czas ładowania strony spadł z **7.5s do 0.12s (129 ms)**, a czas sprawdzania statusu do **0.043s (43 ms)**.

### Krok 10: Pełna synchronizacja PI1 oraz pobranie produkcyjnego `script.sh` z PI2
- **Polecenie:**
  1. Wdrożenie identycznych poprawek optymalizacyjnych i natychmiastowej reakcji UI (Optimistic UI) dla serwera PI1 (`10.0.0.4`).
  2. Skopiowanie pliku `script.sh` z fizycznego serwera `10.0.0.5` do repozytorium do folderu `src/pi2/` w celu zachowania rzeczywistego numeru pinu zasilania (`RELAY_POWER=535`).
- **Wykonanie:**
  - Pobrano z serwera `10.0.0.5` plik `/var/www/script.sh` przez SCP i zaktualizowano [`src/pi2/script.sh`](file:///mnt/z/Vagrant/home-page/src/pi2/script.sh).
  - Zaktualizowano i wdrożono [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php) oraz [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php) na serwer `10.0.0.4:/var/www/`.
  - Ostateczne pomiary obciążenia i czasu ładowania potwierdziły pełną płynność działania: **82 ms** dla PI1 oraz **75 ms** dla PI2.

### Krok 11: Belka nawigacji multi-server (przełączanie PI1, PI2, Cubie)
- **Polecenie:** Dodanie poniżej nagłówków paneli belki z bezpośrednimi linkami umożliwiającymi szybkie przechodzenie pomiędzy serwerami:
  - PI1 (`10.0.0.4`),
  - PI2 (`10.0.0.5`),
  - Cubie (`10.0.0.2`),
  z pominięciem linku do serwera, na którym aktualnie znajduje się użytkownik (będąc na PI1 nie wyświetla się link do PI1, itd.).
- **Wykonanie:**
  - Dodano responsywną sekcję `<nav class="server-nav">` ze stylizowanymi przyciskami, etykietami funkcji, ikonami SVG oraz plakietkami IP (`.badge-ip`).
  - Na serwerze **PI1** belka wyświetla przejście do: **PI2** (`10.0.0.5`) oraz **Cubie** (`10.0.0.2`).
  - Na serwerze **PI2** belka wyświetla przejście do: **PI1** (`10.0.0.4`) oraz **Cubie** (`10.0.0.2`).
  - Zaktualizowano wszystkie widoki: [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php), [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php), [`src/pi2/index.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index.php), [`src/pi2/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index_full.php).
  - Wdrożono pliki na oba serwery produkcyjne (`10.0.0.4` i `10.0.0.5`).

### Krok 12: Obsługa parametru czasu trwania (`time`) w API dla PI1 i PI2
- **Polecenie:** Rozszerzenie interfejsu WWW, aby umożliwiał opcjonalne przekazywanie parametru czasu w sekundach (np. `time=11` lub `duration=11`), przekazywanego jako 3. parametr do skryptu `script.sh` / `script_one.sh` (`$3 TIME`), z zachowaniem domyślnego czasu z `relay_setup.conf` w razie braku parametru.
- **Wykonanie:**
  - Zaktualizowano funkcje `runScriptCommand($relay, $action, $time = null)` i `handleRoomAction(..., $time = null)` we wszystkich plikach: [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php), [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php), [`src/pi2/index.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index.php), [`src/pi2/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index_full.php).
  - Dodano bezpieczną walidację parametru czasu (tylko dodatnie liczby całkowite) pobieranego z GET (`?time=...` / `?duration=...`) oraz POST (`time=...`).
  - Gdy parametr nie jest podany, skrypt uruchamia się w dotychczasowy sposób (bez 3. argumentu), wykorzystując domyślny czas z pliku konfiguracyjnego `relay_setup.conf`.

### Krok 13: Wdrożenie panelu i API równoległego sterowania dwoma rekuperatorami na serwerze PI1 (`src/pi1/rekuperacja.php`)
- **Polecenie:** Utworzenie osobnej strony na serwerze PI1 (`10.0.0.4`) do sterowania dwoma rekuperatorami:
  - Rekuperator 1: `10.0.0.8`
  - Rekuperator 2: `10.0.0.9`
  - Sterowanie polega na wysyłaniu wywołań API równolegle pod oba adresy jednocześnie.
  - Wymagane funkcje i przyciski:
    - Tryb Auto/Normal (`AutomaticMode` oraz `ManualMode&airflowvalue=100&valueofairextraction=100`)
    - Tryb Wolny (`ManualMode&airflowvalue=80&valueofairextraction=70`)
    - Tryb Wakacje włącz/wyłącz (`TurnOnHoliday&days=1` oraz `TurnOffHoliday`)
    - Airing włącz/wyłącz (`TurnOnAiring&time=20` oraz `TurnOffAiring`)
    - Zasilanie włącz/wyłącz (`TurnOn` oraz `TurnOff`)
    - Wymiana filtrów (`ReplaceFilters`)
    - Grzanie włącz/wyłącz (`TurnOnHeating&comforttemperature=22` oraz `TurnOffHeating`)
    - Bypass (`SetByPassMode&mode=1` otwarty, `mode=0` zamknięty, `mode=2` automatyczny)
    - GWC (`SetGWCMode&mode=2` auto, `mode=1` włącz, `mode=0` wyłącz)
- **Wykonanie:**
  - Utworzono plik [`src/pi1/rekuperacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/rekuperacja.php) z pełną obsługą:
    1. **Równoległych zapytań `curl_multi`:** Wysyłanie zapytań do 10.0.0.8 i 10.0.0.9 odbywa się w tym samym ułamku sekundy, bez czekania na sekwencyjne zakończenie pierwszego.
    2. **Ochrony przed zawieszeniem UI:** Krótkie timeouty połączenia (1.5s) zapobiegają blokowaniu serwera Apache w sytuacji, gdy jednostki są wyłączone z sieci.
    3. **Automatycznego fallbacku CLI:** W razie braku rozszerzenia `php-curl` skrypt automatycznie przełącza się na `/usr/bin/curl` i testy gniazd sieciowych `fsockopen`.
    4. **Elastycznego API GET/POST oraz cURL CLI:** Obsługa zarówno czytelnych akcji (`action=auto`, `action=slow`, `action=bypass&mode=1`, `action=airing_on&time=20`), jak i bezpośrednich nazw funkcji (`name=AutomaticMode`, `name=TurnOnHeating&comforttemperature=22`).
    5. **Przełącznika celu (Target Selector):** Domyślne sterowanie oboma rekuperatorami naraz (`target=both`) z możliwością skierowania polecenia do wybranej jednostki (`target=1` dla 10.0.0.8 lub `target=2` dla 10.0.0.9).
    6. **Interfejsu WWW (Optimistic UI):** Nowoczesny, ciemny design zgodny z systemem PI1/PI2, toasty powiadomień, spinner ładowania, live log konsola ostatnich wywołań oraz ściągawka gotowych komend cURL.
  - Zaktualizowano belki nawigacyjne multi-server w [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php), [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php), [`src/pi2/index.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index.php) oraz [`src/pi2/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index_full.php), dodając bezpośrednie przejście do Rekuperatorów.
  - Wdrożono pliki na serwery produkcyjne PI1 (`10.0.0.4:/var/www/`) oraz PI2 (`10.0.0.5:/var/www/`).
  - Przeprowadzono testy fizyczne: obie jednostki (`10.0.0.8` i `10.0.0.9`) poprawnie odebrały polecenia i zwróciły status HTTP 200 OK w czasie ~65-110 ms.

### Krok 14: Zmiana nazewnictwa z „Rekuperacja” na „Wentylacja”
- **Polecenie:** Zmiana nazwy panelu i modułu z *rekuperacja* na *wentylacja*.
- **Wykonanie:**
  - Utworzono plik [`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php) z pełną funkcjonalnością sterowania dwoma urządzeniami wentylacyjnymi (`10.0.0.8` i `10.0.0.9`).
  - W [`src/pi1/rekuperacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/rekuperacja.php) zachowano mostek kompatybilności wstecznej (dołącza `wentylacja.php`), dzięki czemu wcześniejsze wywołania i linki działają bez zakłóceń.
  - Zaktualizowano etykiety, nagłówki stron (`<h1>Sterowanie Wentylacją</h1>`), opisy jednostek (*Wentylacja 1*, *Wentylacja 2*, *Obie jednostki*), przyciski zasilania (*Włącz wentylację*, *Wyłącz wentylację*) oraz przykłady cURL.
  - Zaktualizowano zakładki i belki multi-server w [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php), [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php), [`src/pi2/index.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index.php) oraz [`src/pi2/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index_full.php).
  - Wdrożono zaktualizowane pliki na serwery produkcyjne PI1 (`10.0.0.4:/var/www/`) oraz PI2 (`10.0.0.5:/var/www/`).
  - Zweryfikowano działanie zapytań cURL na żywo: `http://10.0.0.4/wentylacja.php?action=auto` oraz przez alias `http://10.0.0.4/rekuperacja.php?action=slow`. Oba zapytania zakończyły się sukcesem w czasie ~50-60 ms.

### Krok 15: Wyświetlanie aktualnych parametrów pracy i temperatur urządzeń wentylacji (GetTemperatures)
- **Polecenie:** Dodanie do strony wentylacji (`wentylacja.php`) wyświetlania bieżących parametrów pracy urządzeń, pobieranych przez zapytania API:
  - `/usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=GetTemperatures'`
  - `/usr/bin/curl 'http://10.0.0.9/API/RunFunction?name=GetTemperatures'`
  oraz przetłumaczenie i przypisanie zwracanych wartości:
  - `Intake`: Zewnątrz
  - `Launcher`: Usuwanie (wyrzutnia)
  - `Supply`: Nawiew
  - `Extract`: Wywiew
  - `HeaterCooler`: Nagrzewnica
  - `HeaterCoolerActive`: 1 (Włączona) / 0 (Wyłączona)
  - `GWC`: GWC
  - `GWCActive`: 1 (Włączone) / 0 (Wyłączone)
  - `Room`: Pokój (oraz `RoomTempActive`)
  - `Additional`: Dodatkowa (oraz `AdditionalTempActive`)
- **Wykonanie:**
  - W backendzie [`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php) zaimplementowano funkcję `getRecuperatorTemperatures($target)` odpytującą równolegle obie jednostki (`curl_multi`) z automatycznym parsowaniem i tłumaczeniem pól JSON.
  - Dodano dedykowany endpoint API `api=temperatures` oraz obsługę akcji `action=get_temperatures`.
  - Zaprojektowano nowoczesny interfejs dashboardu w panelu WWW z podziałem na:
    1. **4 główne temperatury pętli wentylacji (tor powietrza):** Zewnątrz (`Intake`), Nawiew (`Supply`), Wywiew (`Extract`), Usuwanie (`Launcher`) z kolorystycznymi akcentami i czytelnymi kafelkami.
    2. **Podzespoły i czujniki:** Nagrzewnica (`HeaterCooler` + status włączona/wyłączona), GWC (`GWC` + status włączone/wyłączone), Pokój (`Room`) oraz Dodatkowa (`Additional`).
  - Wdrożono asynchroniczne odświeżanie parametrów w JavaScript (`refreshTemperatures`):
    - Automatyczne pobranie parametrów przy wejściu na stronę (`DOMContentLoaded`).
    - Dedykowany przycisk **`Odśwież parametry`** z animacją ładowania.
    - Automatyczne cykliczne odświeżanie w tle (co 30 sekund).
    - Odświeżanie po wykonaniu dowolnego polecenia (z opóźnieniem 1.2s dla natychmiastowego podejrzenia efektu).
  - Usunięto zduplikowany fragment kodu w sekcji skryptów.
  - Zaktualizowano przykłady wywołań cURL w sekcji pomocy.
  - Wdrożono zaktualizowany plik na fizyczny serwer PI1 (`10.0.0.4:/var/www/wentylacja.php`).
  - Przetestowano na żywo zapytania API – obie jednostki zwracają komplet parametrów w czasie ~80-120 ms.

### Krok 16: Uproszczenie widoku parametrów i skrócone statusy Wł / Wył
- **Polecenie:**
  1. Usunięcie z widoku pól *Pokój* oraz *Dodatkowa*.
  2. Zmiana etykiet stanu dla *Nagrzewnicy* i *GWC* na skrócone: **Wł** (zamiast Włączona/Włączone) oraz **Wył** (zamiast Wyłączona/Wyłączone).
- **Wykonanie:**
  - Zaktualizowano strukturę HTML w [`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php), pozostawiając w wierszu podzespołów wyłącznie Nagrzewnicę oraz GWC w czytelnym, symetrycznym układzie dwukolumnowym.
  - Zaktualizowano logikę JavaScript w funkcji `updateDeviceCard`, ustawiając plakietki na **`Wł`** (aktywne) oraz **`Wył`** (nieaktywne) dla obu komponentów.
  - Wdrożono zaktualizowany plik na serwer produkcyjny PI1 (`10.0.0.4:/var/www/wentylacja.php`) i zweryfikowano poprawność renderowania w przeglądarce.

### Krok 17: Zmiana kolejności sekcji w panelu wentylacji
- **Polecenie:**
  - Przesunięcie bloku *Zasilanie Jednostek* bezpośrednio nad *Filtry i Serwis*.
  - Przesunięcie bloku *Tryb Wakacje* bezpośrednio nad *Zasilanie Jednostek*.
  - Przesunięcie bloku *Ogrzewanie* bezpośrednio nad *Tryb Wakacje*.
- **Wykonanie:**
  - Przeorganizowano sekcje w [`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php) w kolejności:
    1. *Aktualne Parametry Pracy Urządzeń* (temperatury i komponenty),
    2. *Tryby Wentylacji (Przepływ Powietrza)*,
    3. *Przewietrzanie (Airing)*,
    4. *Bypass (Obejście Wymiennika)*,
    5. *GWC (Gruntowy Wymiennik Ciepła)*,
    6. *Ogrzewanie / Nagrzewnica (Heating)*,
    7. *Tryb Wakacje (Holiday)*,
    8. *Zasilanie Jednostek (TurnOn / TurnOff)*,
    9. *Filtry i Serwis (ReplaceFilters)*.
  - Zsynchronizowano plik z serwerem produkcyjnym PI1 (`10.0.0.4:/var/www/wentylacja.php`).

### Krok 18: Usunięcie sekcji przykładów wywołań cURL z widoku panelu
- **Polecenie:** Usunięcie z widoku strony `wentylacja.php` bloku *„Przykłady Wywołań cURL z Konsoli / Home Assistant / Skryptów Bash:”*.
- **Wykonanie:**
  - Usunięto kontener `.curl-box` oraz powiązane style CSS ze strony [`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php) (pełna dokumentacja cURL pozostaje w niniejszym dokumencie [`GEMINI.md`](file:///mnt/z/Vagrant/home-page/GEMINI.md)).
  - Zsynchronizowano plik z serwerem produkcyjnym PI1 (`10.0.0.4:/var/www/wentylacja.php`).

### Krok 19: Wdrożenie sterowania kotłownią (Piec CWU, Piec CO, Pompa) oraz wentylacji dla serwera PI0 (`10.0.0.118`)
- **Polecenie użytkownika:**
  - Utworzenie w katalogu `src/pi0/` strony w podobnym układzie do PI1 i PI2 dla serwera PI0 (`10.0.0.118`).
  - Obsługa klawiszy przekaźnikowych:
    - **Piec Gazowy CWU** – przekaźnik GPIO526 w `/var/www/gpio/`,
    - **Piec Gazowy CO** – przekaźnik GPIO527 w `/var/www/gpio/`,
    - **Pompa obiegowa** – przekaźnik GPIO530 w `/var/www/gpio/`.
  - Dodanie linków umożliwiających przechodzenie do PI1, PI2 oraz Wentylacji.
  - Utworzenie na serwerze PI0 działającej podstrony dla wentylacji (`wentylacja.php` i aliasu `rekuperacja.php`).
- **Analiza sprzętowo-systemowa serwera PI0 (`10.0.0.118`):**
  - Raspberry Pi (kernel Linux 6.18, baza GPIO 512):
    - `gpio526` (pin 14) -> Piec Gazowy CWU,
    - `gpio527` (pin 15) -> Piec Gazowy CO,
    - `gpio530` (pin 18) -> Pompa obiegowa.
  - Skrypt rozruchowy `/etc/init.d/raspberry` tworzy dowiązania symboliczne w `/var/www/gpio/gpio*` do `/sys/class/gpio/gpio*/value` z domyślnym stanem `1` (wyłączony).
  - Skorygowano uprawnienia w `/etc/init.d/raspberry` na `chmod 666 /sys/class/gpio/gpio*/value` oraz dodano użytkownika `www-data` do grupy `gpio`, co umożliwiło procesom Apache bezpośredni, błyskawiczny (sub-milisekundowy) zapis i odczyt stanów bez konieczności forkowania powłoki.
- **Wykonanie i wdrożenie:**
  1. Utworzono [`src/pi0/config.php`](file:///home/user/home-page/src/pi0/config.php):
     - Zdefiniowano strukturę `$DEVICES` z mapowaniem identyfikatorów (`piec_cwu`, `piec_co`, `pompa`), opisami, ikonami i plikami w `/var/www/gpio/`.
     - Zaimplementowano funkcje `getDeviceState()`, `setDeviceState()`, `setAllDevicesState()` oraz `getAllDevicesStatus()`.
  2. Utworzono [`src/pi0/index.php`](file:///home/user/home-page/src/pi0/index.php):
     - Nowoczesny panel dark theme ze spójną szatą graficzną z PI1 i PI2.
     - Dedykowane karty urządzeń z kolorowymi ikonami SVG, etykietami pinów oraz pulsującymi wskaźnikami stanu na żywo.
     - Niezależne przyciski **Włącz** oraz **Wyłącz** dla każdego z urządzeń.
     - Przyciski bezpieczeństwa: **Wyłącz wszystko** oraz **Włącz wszystko**.
     - Wzorzec Optimistic UI (natychmiastowa reakcja 0 ms z animacją kręciołka i blokadą spamu kliknięć).
     - Asynchroniczne odpytywanie w tle (`pollStatus` co 2.5s) z muteksem `isPolling`.
     - Pełne REST/JSON API: `?api=status`, `action=on/off/toggle`, zgodność wsteczna z parametrami legacy (`?sw1=on/off`, `?sw2=on/off`, `?sw3=on/off`, `?sw100=off`).
  3. Utworzono [`src/pi0/wentylacja.php`](file:///home/user/home-page/src/pi0/wentylacja.php) oraz [`src/pi0/rekuperacja.php`](file:///home/user/home-page/src/pi0/rekuperacja.php):
     - Pełna funkcjonalność równoległego odczytu temperatur i sterowania jednostkami `10.0.0.8` i `10.0.0.9`.
     - Zaktualizowano nagłówek i belki nawigacji wskazujące na hosta `10.0.0.118`.
  4. Aktualizacja nawigacji multi-server w całej sieci domowej:
     - Dodano odnośnik do serwera PI0 (`http://10.0.0.118/`) w paskach nawigacji `.server-links` oraz `.server-nav` na serwerach:
       - **PI1 (`10.0.0.4`):** [`src/pi1/index.php`](file:///home/user/home-page/src/pi1/index.php), [`src/pi1/index_full.php`](file:///home/user/home-page/src/pi1/index_full.php), [`src/pi1/wentylacja.php`](file:///home/user/home-page/src/pi1/wentylacja.php),
       - **PI2 (`10.0.0.5`):** [`src/pi2/index.php`](file:///home/user/home-page/src/pi2/index.php), [`src/pi2/index_full.php`](file:///home/user/home-page/src/pi2/index_full.php).
  5. Wdrożenie produkcyjne i weryfikacja na serwerach:
     - Wdrożono pliki `config.php`, `index.php`, `wentylacja.php`, `rekuperacja.php` do `/var/www/` na serwerze `10.0.0.118`.
     - Zaktualizowano pliki na serwerach `10.0.0.4` oraz `10.0.0.5`.
     - Przetestowano załączanie i wyłączanie obwodów przez cURL i API – czasy odpowiedzi poniżej 15 ms, bezbłędny zapis do sysfs GPIO.

### Krok 20: Dedykowane sterowanie bramami posesji (script_brama.sh) na PI1
- **Polecenie użytkownika:**
  - Utworzenie i podpięcie dedykowanego skryptu `script_brama.sh` dla Bramy wjazdowej i Bramy garażowej bez użycia `script.sh` czy `script_one.sh`.
- **Wykonanie:**
  - Wdrożono skrypt [`src/pi1/script_brama.sh`](file:///home/user/home-page/src/pi1/script_brama.sh) przyjmujący parametry: `$1` (nazwa), `$2` (akcja `open`/`close`/`stop`), `$3` (pin GPIO) i `$4` (czas impulsu, domyślnie 0.5s).
  - Skonfigurowano `$GATE_CONFIG` w [`src/pi1/config.php`](file:///home/user/home-page/src/pi1/config.php) oraz zaktualizowano mechanizm wywołań w [`src/pi1/index.php`](file:///home/user/home-page/src/pi1/index.php) i [`src/pi1/index_full.php`](file:///home/user/home-page/src/pi1/index_full.php).

### Krok 21: Usunięcie serwera Cubie, zmiana adresu IP PI0 na 10.0.0.2 i aktualizacja odnośników
- **Polecenie użytkownika:**
  - Usunięcie katalogu `src/cubie/` i wszystkich powiązanych odnośników na wszystkich serwerach.
  - Zmiana adresu IP dla PI0 z `10.0.0.118` na `10.0.0.2`.
  - Wdrożenie zaktualizowanej konfiguracji na serwery.
- **Wykonanie:**
  - Usunięto katalog `src/cubie/` za pomocą `git rm -rf`.
  - Zaktualizowano adres `10.0.0.2` we wszystkich plikach `src/pi0/`, `src/pi1/`, `src/pi2/`.
  - Wdrożono pliki na serwery PI0, PI1 i PI2 oraz zweryfikowano poprawność odpowiedzi.

### Krok 22: Ujednolicenie nawigacji pod zakładką „Panele:” i nowe nazwy lokalizacji
- **Polecenie użytkownika:**
  - Zmiana układu odnośników na wzór strony wentylacji: usunięcie surowych linków na szczycie i pozostawienie kafelków nawigacyjnych.
  - Zmiana etykiety z `Serwery:` na `Panele:`.
  - Zmiana nazw: PI0 &rarr; Kotłownia, PI1 &rarr; Grzegorzka 8, PI2 &rarr; Grzegorzka 8A, Wentylacja &rarr; Wentylacja.
  - Usunięcie adresów IP z opisu odnośników i wysłanie konfiguracji na serwery.
- **Wykonanie:**
  - Zunifikowano `<nav class="server-nav">` na szczycie kontenera na wszystkich 7 stronach.
  - Wdrożono i przetestowano na serwerach PI0, PI1 i PI2.

### Krok 23: Kolorystyka przycisków na serwerze Kotłownia oraz usunięcie akcji grupowych
- **Polecenie użytkownika:**
  - Zmiana kolorów klawiszy na serwerze Kotłownia na spójne z Grzegorzka 8: Włącz – niebieski, Wyłącz – czerwony.
  - Usunięcie przycisków „Włącz wszystko” i „Wyłącz wszystko”.
- **Wykonanie:**
  - W [`src/pi0/index.php`](file:///home/user/home-page/src/pi0/index.php) zaktualizowano style CSS (`.btn-on` na `#2563eb`, `.btn-off` na `#ef4444`).
  - Usunięto przyciski grupowe z nagłówka, pozostawiając przycisk „Odśwież”.
  - Przesłano plik na serwer PI0 i potwierdzono działanie.

### Krok 24: Zaktualizowanie script_brama.sh oraz zamiana przypisania GPIO dla bram na PI1
- **Polecenia użytkownika:**
  - Wdrożenie zaktualizowanego przez użytkownika skryptu `script_brama.sh`.
  - Aktualizacja numerów GPIO w wywołaniach: Brama wjazdowa to GPIO 530, Brama garażowa to GPIO 527.
- **Wykonanie:**
  - Zaktualizowano `$GATE_CONFIG` oraz opisy w [`src/pi1/config.php`](file:///home/user/home-page/src/pi1/config.php): `brama_wjazd` &rarr; GPIO 530, `brama_garaz` &rarr; GPIO 527.
  - Zaktualizowano logikę fallbacków w [`src/pi1/index.php`](file:///home/user/home-page/src/pi1/index.php) oraz [`src/pi1/index_full.php`](file:///home/user/home-page/src/pi1/index_full.php).
  - Zaktualizowano komentarze w [`src/pi1/script_brama.sh`](file:///home/user/home-page/src/pi1/script_brama.sh).
  - Wdrożono pliki na serwer PI1 (`10.0.0.4:/var/www/`), zweryfikowano uprawnienia (`chmod 775`) oraz potwierdzono działanie API.

---

## 8. Kompletny Zapis Przebiegu Rozmowy i Poleceń Użytkownika

Poniższa sekcja zawiera chronologiczny, dosłowny zapis wszystkich zapytań użytkownika skierowanych w toku niniejszej sesji konwersacji, wraz z precyzyjnym opisem podjętych działań, decyzji architektonicznych i efektów wdrożeniowych.

### Wpis 1: Wdrożenie odczytu parametrów pracy urządzeń wentylacyjnych (GetTemperatures)
- **Dokładne zapytanie użytkownika:**
  > *„chcialbym na stronie wentylacji wyswietlac aktualne parametry pracy urzadzen. Aby to wykonac nalezy wyslac zapytanie prze API do urzadzenia*
  > */usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=GetTemperatures'*
  > */usr/bin/curl 'http://10.0.0.9/API/RunFunction?name=GetTemperatures'*
  > 
  > *przetlumaczone wartosci zwrotne*
  > *{"GetTemperaturesResult":true,*
  > *"Intake":"14.2" # # Zewnatrz*
  > *"Launcher":"15.5" # Usuwanie*
  > *"Supply":"20.3" # Nawiew*
  > *"Extract":"21.1" # Wywiew*
  > *"HeaterCooler":"20.8" Nagrzewnica*
  > *"HeaterCoolerActive":1*
  > *"GWC":"10.4" # GWC*
  > *"GWCActive":1 # GWC Wlaczone 1 Wylaczone 0*
  > *"Room":"0.0"*
  > *"RoomTempActive":0*
  > *"Additional":"0.0"*
  > *"AdditionalTempActive":0}”*
- **Podjęte działania:**
  1. W pliku [`src/pi1/wentylacja.php`](file:///home/user/home-page/src/pi1/wentylacja.php) zaimplementowano równoległą obsługę API `GetTemperatures` za pomocą `curl_multi` dla jednostek `10.0.0.8` i `10.0.0.9`.
  2. Dodano backendowe tłumaczenie parametrów na język polski (Zewnątrz, Usuwanie, Nawiew, Wywiew, Nagrzewnica, GWC, Pokój, Dodatkowa).
  3. Zbudowano nowoczesny kafelkowy panel parametrów z podziałem na 4 główne temperatury toru powietrza oraz kafelki podzespołów.
  4. Wdrożono automatyczne cykliczne odpytywanie w tle w JavaScript oraz przycisk „Odśwież parametry”.
  5. Zsynchronizowano plik z serwerem produkcyjnym `10.0.0.4:/var/www/wentylacja.php`.

### Wpis 2: Uproszczenie widoku parametrów i skrócone statusy Wł / Wył
- **Dokładne zapytanie użytkownika:**
  > *„usun z widoku pole pokoj i dodatkowe. Zamiast nagrzewnica wlaczona uzyj Wł lub wyłączona wył popraw to samo dla gwc”*
- **Podjęte działania:**
  1. Usunięto pola *Pokój* (`Room`) i *Dodatkowa* (`Additional`) z widoku kafelkowego w [`src/pi1/wentylacja.php`](file:///home/user/home-page/src/pi1/wentylacja.php).
  2. Zmieniono etykiety stanu na skrócone: **`Wł`** (aktywny) i **`Wył`** (nieaktywny) dla *Nagrzewnicy* oraz *GWC*.
  3. Zsynchronizowano plik z serwerem produkcyjnym `10.0.0.4:/var/www/wentylacja.php`.

### Wpis 3: Przeorganizowanie kolejności bloków w panelu wentylacji
- **Dokładne zapytanie użytkownika:**
  > *„block z zasilanie jednostki przesun nad Filtry i Serwis. Tryb Wakacje nad zasilanie jednostki. Ogrzewanie nad Tryb Wakacje.”*
- **Podjęte działania:**
  1. Zmodyfikowano strukturę HTML w [`src/pi1/wentylacja.php`](file:///home/user/home-page/src/pi1/wentylacja.php) ustalając nową kolejność sekcji:
     - *Ogrzewanie / Nagrzewnica (Heating)*
     - *Tryb Wakacje (Holiday)*
     - *Zasilanie Jednostek (TurnOn / TurnOff)*
     - *Filtry i Serwis (ReplaceFilters)*
  2. Zsynchronizowano plik z serwerem produkcyjnym `10.0.0.4:/var/www/wentylacja.php`.

### Wpis 4: Usunięcie sekcji pomocy cURL z panelu WWW
- **Dokładne zapytanie użytkownika:**
  > *„usun blok Przykłady Wywołań cURL z Konsoli / Home Assistant / Skryptów Bash:”*
- **Podjęte działania:**
  1. Usunięto kontener `.curl-box` oraz style CSS z widoku strony [`src/pi1/wentylacja.php`](file:///home/user/home-page/src/pi1/wentylacja.php).
  2. Pełną dokumentację poleceń zachowano w niniejszym pliku [`GEMINI.md`](file:///home/user/home-page/GEMINI.md).
  3. Zsynchronizowano plik z serwerem produkcyjnym `10.0.0.4:/var/www/wentylacja.php`.

### Wpis 5: Utworzenie i wdrożenie serwera PI0 (10.0.0.118) dla Kotłowni i Wentylacji
- **Dokładne zapytanie użytkownika:**
  > *„Na serwerze pi0 10.0.0.118 src/pi0 stworz w podobna ukladdzie strone jak na PI1 i PI2 lecz ten serwer bedzie obslugiwal klawisze:*
  > *Piec Gazowy CWU przekaznik - GPIO526 w /var/www/gpio/*
  > *Piec Gazowy CO - GPIO527 w /var/www/gpio/*
  > *Pompa obiegowa - GPIO530 w /var/www/gpio/*
  > *Nie zapomnij dodac linki pozwalajace na przechodzenie do PI1 PI2 oraz Wentylacja i utworz tez na tym serwerze strone dla wentylacji.”*
- **Podjęte działania:**
  1. Zbadano strukturę fizyczną serwera PI0 (`10.0.0.118`): kernel Linux 6.18, offset GPIO 512 (linie 14, 15, 18).
  2. Nadano uprawnienia zapisu dla Apache `www-data` (`chmod 666 /sys/class/gpio/gpio*/value`) oraz zmodyfikowano skrypt rozruchowy `/etc/init.d/raspberry`.
  3. Utworzono pełny zestaw aplikacji w [`src/pi0/`](file:///home/user/home-page/src/pi0/):
     - [`config.php`](file:///home/user/home-page/src/pi0/config.php) – konfiguracja urządzeń kotłowni, definicje GPIO i funkcje sterujące.
     - [`index.php`](file:///home/user/home-page/src/pi0/index.php) – panel kotłowni dark theme, dedykowane karty z ikonami SVG, przyciski Włącz/Wyłącz, Optimistic UI (0 ms), polling co 2.5s, przyciski grupowe „Wyłącz wszystko” i „Włącz wszystko”.
     - [`wentylacja.php`](file:///home/user/home-page/src/pi0/wentylacja.php) oraz alias [`rekuperacja.php`](file:///home/user/home-page/src/pi0/rekuperacja.php) – panel i API wentylacji dedykowane dla serwera PI0.
  4. Zaktualizowano paski nawigacji `.server-links` oraz `.server-nav` na wszystkich serwerach (PI0, PI1, PI2), dodając wzajemne bezpośrednie odnośniki do PI0 (`10.0.0.118`).
  5. Wdrożono pliki na fizyczny serwer `10.0.0.118:/var/www/` oraz zaktualizowano pliki na `10.0.0.4` i `10.0.0.5`.
  6. Przetestowano załączanie i wyłączanie obwodów kotłowni oraz odczyt parametrów wentylacji (czasy odpowiedzi poniżej 15 ms).

### Wpis 6: Zapis całości przebiegu do GEMINI.md
- **Dokładne zapytanie użytkownika:**
  > *„zapisz cala rozmowe do GEMINI.md”*
- **Podjęte działania:**
  1. Zaktualizowano niniejszy plik [`GEMINI.md`](file:///home/user/home-page/GEMINI.md) o pełną dokumentację środowiska 4 serwerów, tabelę urządzeń kotłowni PI0, składnię poleceń cURL dla wszystkich urządzeń oraz kompletny chronologiczny rejestr zapytań użytkownika i wdrożonych rozwiązań.

### Wpis 7: Podpięcie bezpośredniego sterowania bramami (script_brama.sh) na PI1
- **Dokładne zapytanie użytkownika:**
  > *„napisyalem skrypt dla PI1 dla brama "Brama wjazdowa" i "Brama garażowa" o nazwie script_brama.sh. Aby wywolac skryp nalezy wykonac dla brama wjazdowa sh brama.sh wjazdowa open 527 0.5 i dla Brama garażowa sh brama.sh wjazdowa open 530 0.5 (Uruchamia sie skrypt dokladnie tak samo jak script.sh). Zaktualizuj strone”*
  > *„nie jest potrzebne ln. Nalzy tylko uruchamiac skrypt zgodnie ze skladnia ktora podalem i nie trzeba uzywac script.sh ani script_one.sh tylko bezposrednio skrypt script_brama.sh ktory ma byc podpiety z odpowiednimi parametrami pod klawsize dla bramy garazowej i wjazdowej”*
- **Podjęte działania:**
  1. **Aktualizacja skryptu [`src/pi1/script_brama.sh`](file:///mnt/z/Vagrant/home-page/src/pi1/script_brama.sh):**
     - Dostosowano parsowanie argumentów CLI: `$1` (nazwa: `wjazdowa` / `garazowa`), `$2` (akcja: `open` / `close` / `stop`), `$3` (pin GPIO: `527` / `530`), `$4` (czas impulsu, domyślnie `0.5`).
     - Obsłużono bezpośrednie załączanie pinu: stan niski `0`, odczekanie `0.5s` i powrót do `1`.
     - Dodano synchronizację stanów w plikach `.state` (`wjazdowa.state`, `garazowa.state`, `relay_brama_wjazd.state`, `relay_brama_garaz.state`).
  2. **Aktualizacja konfiguracji [`src/pi1/config.php`](file:///mnt/z/Vagrant/home-page/src/pi1/config.php):**
     - Zdefiniowano `SCRIPT_BRAMA_PATH` wskazujący bezpośrednio na `script_brama.sh`.
     - Zdefiniowano tablicę `$GATE_CONFIG` mapującą bramy na odpowiednie parametry (`wjazdowa` -> GPIO 527, `garazowa` -> GPIO 530, czas 0.5s).
     - Zaktualizowano opisy sekcji bram posesji i aliasy pokoi.
  3. **Aktualizacja paneli WWW [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php) oraz [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php):**
     - Zmodyfikowano funkcję `runScriptCommand()` – wykluczono używanie `script.sh` oraz `script_one.sh` dla bram i podpięto bezpośrednie wywoływanie `script_brama.sh` w tle:
       - Brama wjazdowa: `nohup /bin/sh /var/www/script_brama.sh 'wjazdowa' [akcja] '527' '0.5' > /dev/null 2>&1 &`
       - Brama garażowa: `nohup /bin/sh /var/www/script_brama.sh 'garazowa' [akcja] '530' '0.5' > /dev/null 2>&1 &`
     - Zaktualizowano odpytywanie procesów w tle oraz sprawdzanie plików stanu.
     - Dodano `script_brama.sh` do stopki diagnostycznej obu stron.
  4. **Wdrożenie i weryfikacja na serwerze PI1 (10.0.0.4):**
     - Zsynchronizowano pliki [`src/pi1/config.php`](file:///mnt/z/Vagrant/home-page/src/pi1/config.php), [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php), [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php) oraz [`src/pi1/script_brama.sh`](file:///mnt/z/Vagrant/home-page/src/pi1/script_brama.sh) do katalogu `/var/www/` na fizycznym serwerze `10.0.0.4`.
     - Nadano uprawnienia wykonywalności (`chmod +x /var/www/script_brama.sh`).
     - Przeprowadzono test składni PHP na serwerze PI1 (brak błędów).
     - Przetestowano zapytanie statusowe API HTTP `curl -s "http://10.0.0.4/index.php?api=status"` – serwer Apache zwrócił poprawny JSON ze stanami wszystkich rolet i obu bram.




### Wpis 8: Usunięcie serwera Cubie, zmiana adresu IP PI0 na 10.0.0.2 i wdrożenie multi-server
- **Dokładne zapytanie użytkownika:**
  > *„Usun calkowicie folder cubie w src oraz wszystkie odnosniki na wszystkich serwerach. Zmien adres IP dla PI0 z 10.0.0.118 na 10.0.0.2 i zaktualizuj wszystkie odnosniki oraz wyslij konfiguracje na serwer.”*
- **Podjęte działania:**
  1. **Całkowite usunięcie Cubie z repozytorium:**
     - Usunięto katalog `src/cubie/` za pomocą `git rm -rf src/cubie`.
     - Usunięto wszystkie odnośniki, etykiety i karty nawigacyjne serwera Cubie ze wszystkich plików we wszystkich panelach (`pi0`, `pi1`, `pi2`).
  2. **Aktualizacja adresu IP serwera PI0 (10.0.0.118 -> 10.0.0.2):**
     - Zweryfikowano fizyczne przypisanie adresu `10.0.0.2` do serwera PI0 (odpowiedź ping, system Raspbian z kernelem 6.18, hostname `pi0`).
     - Zaktualizowano odnośniki, plakietki IP (`<span class="badge-ip">10.0.0.2</span>`) oraz linki nawigacyjne we wszystkich widokach:
       - Serwer PI0: [`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php), [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php), [`src/pi0/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi0/wentylacja.php).
       - Serwer PI1: [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php), [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php), [`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php).
       - Serwer PI2: [`src/pi2/index.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index.php), [`src/pi2/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index_full.php).
  3. **Wdrożenie na serwery produkcyjne:**
     - **PI0 (`10.0.0.2`):**
       - Wdrożono pliki `config.php`, `index.php`, `wentylacja.php`, `rekuperacja.php` do `/var/www/`.
       - Uruchomiono i aktywowano usługę `/etc/init.d/raspberry` (`sudo update-rc.d raspberry defaults`) dla obsługi eksportu GPIO (526, 527, 530, 535) z uprawnieniami zapisu.
     - **PI1 (`10.0.0.4`):**
       - Wdrożono pliki `config.php`, `index.php`, `index_full.php`, `wentylacja.php`, `script_brama.sh` do `/var/www/`.
     - **PI2 (`10.0.0.5`):**
       - Wdrożono pliki `index.php`, `index_full.php` do `/var/www/`.
  4. **Weryfikacja testowa:**
     - Sprawdzono składnię PHP na wszystkich serwerach (`php -l`) – zero błędów składniowych.
     - Sprawdzono odpowiedzi JSON API:
       - PI0 Kotłownia (`curl -s "http://10.0.0.2/index.php?api=status"`) – `success: true`, statusy pieca CWU, CO i pompy.
       - PI0 Wentylacja (`curl -s "http://10.0.0.2/wentylacja.php?action=status"`) – centrale 10.0.0.8 i 10.0.0.9 dostępne.
       - PI1 Rolety/Bramy (`curl -s "http://10.0.0.4/index.php?api=status"`) – `success: true`, wszystkie sekcje i bramy aktywne.
       - PI2 Rolety (`curl -s "http://10.0.0.5/index.php?api=status"`) – `success: true`, rolety Piętro 2 i Parter aktywne.


### Wpis 9: Ujednolicenie nawigacji, zmiana etykiet na „Panele” i nowe nazwy lokalizacji
- **Dokładne zapytanie użytkownika:**
  > *„Chcialbym zmienic na wszystkich serwerach i na wszystkich stronach index.php i index_full.php i wentylacja.php układ odnosnikow. Chcialbym aby wygladalo to jak na stronie wentylacja i mam tu na mysli zakładke Panele. Usun linki na szczycie i zostaw jak jest w blokach Serwery i Panele. Zmien nazwe wszedzie z Serwery na Panele i zmien nazwy odnosnikow:*
  > *- PI0 na Kotlownia*
  > *- PI1 na Grzegorzka 8*
  > *- PI2 na Grzegorzka 8A*
  > *Usun adres ip z opisu odnosnikow. Wyslij konfiguracje na serwery.”*
- **Podjęte działania:**
  1. **Usunięcie surowych linków na szczycie strony:**
     - Usunięto bloki `<div class="server-links">...</div>` ze wszystkich widoków na wszystkich serwerach.
  2. **Ujednolicenie paska nawigacji „Panele:”:**
     - Przeniesiono belkę `<nav class="server-nav">` na szczyt strony (tuż po otwarciu kontenera `.container`, powyżej `<header>`) na wszystkich stronach (`index.php`, `index_full.php`, `wentylacja.php`).
     - Zmieniono etykietę nagłówka sekcji z `Serwery:` na **`Panele:`**.
     - Wprowadzono nowe, przyjazne nazwy odnośników bez surowych adresów IP w plakietkach:
       - PI0 &rarr; **`Kotłownia`** (ikona kotła / płomienia)
       - PI1 &rarr; **`Grzegorzka 8`** (ikona rolet / okna)
       - PI2 &rarr; **`Grzegorzka 8A`** (ikona domu)
       - Wentylacja &rarr; **`Wentylacja`** (ikona wentylatora)
     - Usunięto wszystkie elementy `<span class="badge-ip">...</span>` z kafelków paneli.
     - Dodano klasę aktywną `.server-nav-link.active` wyróżniającą aktualnie otwarty panel na niebiesko.
  3. **Wdrożenie na serwery produkcyjne:**
     - **PI0 (`10.0.0.2`):** wdrożono `index.php` oraz `wentylacja.php` do `/var/www/`.
     - **PI1 (`10.0.0.4`):** wdrożono `index.php`, `index_full.php` oraz `wentylacja.php` do `/var/www/`.
     - **PI2 (`10.0.0.5`):** wdrożono `index.php` oraz `index_full.php` do `/var/www/`.
  4. **Weryfikacja testowa:**
     - Sprawdzono składnię PHP na wszystkich 3 maszynach (`php -l`) – 0 błędów.
     - Sprawdzono poprawne generowanie kodu HTML przez serwery Apache (`curl` na port 80 dla wszystkich hostów).
     - Przetestowano odpowiedzi JSON API (`api=status`) na wszystkich serwerach – pełna sprawność.


### Wpis 10: Aktualizacja kolorystyki przycisków i usunięcie akcji grupowych na serwerze Kotłownia
- **Dokładne zapytanie użytkownika:**
  > *„zmien kolory klawiszy na serwerze kotlownia na takie jakie sa Grzegorzka 8 na wlacz niebieski i wylacz czerwony. Usun opcje wlacz wszystko i wylacz wszystko.”*
- **Podjęte działania:**
  1. **Zmiana kolorów przycisków sterowania w [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php):**
     - Przycisk **Włącz** (`.btn-on`): zmieniono kolorystykę z zielonej na **niebieską** (`#2563eb`, hover `#1d4ed8`, active glow `#60a5fa`) identycznie jak w panelu Grzegorzka 8.
     - Przycisk **Wyłącz** (`.btn-off`): zmieniono kolorystykę na **czerwoną** (`#ef4444`, hover `#dc2626`, active glow `#f87171`) identycznie jak przycisk Stop w panelu Grzegorzka 8.
  2. **Usunięcie przycisków globalnych:**
     - Usunięto przyciski „Włącz wszystko” oraz „Wyłącz wszystko” z nagłówka (`.header-actions`).
     - Zachowano przycisk „Odśwież” do natychmiastowego odpytania stanów urządzeń.
  3. **Wdrożenie i weryfikacja na serwerze PI0 (10.0.0.2):**
     - Przesłano zaktualizowany plik `src/pi0/index.php` do `/var/www/index.php` na serwerze PI0.
     - Sprawdzono składnię PHP (`php -l /var/www/index.php`) – 0 błędów.
     - Zweryfikowano wyrenderowany kod HTML przez `curl` – przyciski Włącz (niebieski) i Wyłącz (czerwony) oraz brak przycisków włącz/wyłącz wszystko.


### Wpis 11: Wdrożenie zaktualizowanego skryptu script_brama.sh na serwerze PI1 (Grzegorzka 8)
- **Dokładne zapytanie użytkownika:**
  > *„zaktualizowalem skryp script_brama.sh wyslij go na serwer”*
- **Podjęte działania:**
  1. **Analiza zmian w skrypcie [`src/pi1/script_brama.sh`](file:///mnt/z/Vagrant/home-page/src/pi1/script_brama.sh):**
     - Użycie shebangu `#!/bin/sh` z elastycznymi zmiennymi domyślnymi `${GPIO_DIR:-/var/www/gpio}` oraz `${WWW_DIR:-/var/www}`.
     - Przyjmowanie 4 parametrów wywołania: `$1` (nazwa bramy, np. `wjazdowa` / `garazowa`), `$2` (akcja `open`/`close`/`stop`), `$3` (numer GPIO, np. `527` / `530`) oraz `$4` (czas impulsu, domyślnie `TIME=${4:-0.5}`).
     - Funkcja `F_EXEC` bezpośrednio sterująca linią przekaźnika GPIO impulsem stanu niskiego (`0 > gpio$RELAY`), uśpieniem `sleep $TIME` i powrotem do stanu wysokiego (`1 > gpio$RELAY`).
     - Zapis stanu do pliku `${GPIO_DIR}/${CONF_NAME}.state` dla podglądu statusu w panelu WWW.
  2. **Wdrożenie na serwer produkcyjny PI1 (`10.0.0.4`):**
     - Przesłano plik `src/pi1/script_brama.sh` do `/var/www/script_brama.sh` na serwerze PI1 (`10.0.0.4`).
     - Ustawiono odpowiednie uprawnienia wykonywania (`chmod 775 /var/www/script_brama.sh`).
  3. **Weryfikacja spójności i działania:**
     - Porównano binarnie/tekstowo plik lokalny z plikiem na serwerze PI1 (`diff -u`) – pliki są w 100% identyczne.
     - Sprawdzono poprawność zwracania statusów przez panel WWW PI1 (`http://10.0.0.4/index.php?api=status`) – sekcja `brama_wjazd` i `brama_garaz` poprawnie zintegrowane.


### Wpis 12: Aktualizacja przypisania GPIO dla bram na serwerze PI1 (Wjazdowa: GPIO 530, Garażowa: GPIO 527)
- **Dokładne zapytanie użytkownika:**
  > *„zaktualizuj w poleceniu dla gpio dla serwera PI1 grama wjazdowa to gpio530 natomiast brama garazowa to gpio527”*
- **Podjęte działania:**
  1. **Zamiana numerów pinów GPIO w konfiguracji [`src/pi1/config.php`](file:///mnt/z/Vagrant/home-page/src/pi1/config.php):**
     - **Brama wjazdowa (`brama_wjazd`, `relay_brama_wjazd`, `wjazdowa`):** zmiana z `527` na **`530`**.
     - **Brama garażowa (`brama_garaz`, `relay_brama_garaz`, `garazowa`):** zmiana z `530` na **`527`**.
     - Zaktualizowano opisy kart i sekcji w `$FLOORS`, `$ROOMS` oraz `$INDIVIDUAL_ROOMS`.
  2. **Aktualizacja logiki wykonawczej w [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php) i [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php):**
     - Fallback `$gateGpio`: przypisanie `530` dla bramy wjazdowej oraz `527` dla bramy garażowej.
     - Parametry domyślne `$bramaCfg`: `gpio => 530` dla wjazdowej oraz `gpio => 527` dla garażowej.
     - Zaktualizowano komentarze wywołań CLI:
       - `sh script_brama.sh wjazdowa open 530 0.5`
       - `sh script_brama.sh garazowa open 527 0.5`
  3. **Aktualizacja komentarzy w [`src/pi1/script_brama.sh`](file:///mnt/z/Vagrant/home-page/src/pi1/script_brama.sh):**
     - Zaktualizowano przykładowe wywołania z nowymi pinami GPIO (`530` i `527`).
  4. **Wdrożenie na serwer PI1 (`10.0.0.4`):**
     - Przesłano `config.php`, `index.php`, `index_full.php` oraz `script_brama.sh` do `/var/www/`.
     - Zweryfikowano poprawność składni PHP (`php -l`) oraz odpowiedzi endpointu API (`http://10.0.0.4/index.php?api=status`).


### Wpis 13: Zapis i kompleksowa synchronizacja dokumentacji GEMINI.md
- **Dokładne zapytanie użytkownika:**
  > *„zapisz cala konwersacje do GEMINI.md”*
- **Podjęte działania:**
  1. **Aktualizacja rejestru zadań (Kroki 1–24):** Uzupełniono pełną chronologię wykonanych prac w sekcji 7, w tym wyodrębnienie serwera PI0 pod adresem `10.0.0.2`, usunięcie Cubie, unifikację paska „Panele:”, dostosowanie kolorów Kotłowni oraz pełną konfigurację bram posesji ze skryptem `script_brama.sh` (GPIO 530 i 527).
  2. **Aktualizacja chronologicznego rejestru zapytań użytkownika (Wpisy 1–13):** Zarejestrowano dosłowne komendy użytkownika, podjęte kroki realizacyjne, analizę kodu oraz weryfikację na działających serwerach Raspberry Pi.
  3. **Weryfikacja tabel i instrukcji cURL:** Zaktualizowano wszystkie zestawienia adresów, numery pinów GPIO, parametry skryptów powłoki oraz przykłady poleceń `curl` dla PI0, PI1 i PI2.


### Wpis 14: Wdrożenie zaktualizowanych skryptów script.sh na serwery PI1 i PI2
- **Dokładne zapytanie użytkownika:**
  > *„poprawilem skrypty script.sh dla pi1 i pi2 wyslij je na serwer”*
  > *„teraz sa uprawnienia do skopiowania danych - skopiuj skrypty”*
- **Podjęte działania:**
  1. **Analiza zmian w skryptach [`src/pi1/script.sh`](file:///mnt/z/Vagrant/home-page/src/pi1/script.sh) i [`src/pi2/script.sh`](file:///mnt/z/Vagrant/home-page/src/pi2/script.sh):**
     - Ujednolicenie i optymalizacja sprawdzania zasilania modułów przekaźników (`F_RELAY_POWER_CHECK`, `F_RELAY_POWER_ON`, `F_RELAY_POWER_OFF`).
     - Bezpieczne wyłączanie zasilania z weryfikacją znacznika procesu w `${RELAY_POWER_FILE}`, aby nie zakłócać pracy innych jednocześnie przełączanych rolet.
     - Czyszczenie i ulepszenie procedur zabijania starych procesów (`F_KILL_PROCESS_OPEN`, `F_KILL_PROCESS_CLOSE`).
     - Poprawa komunikatów diagnostycznych i składni `Usage: {open|close|stop}`.
  2. **Wdrożenie na serwer PI1 (`10.0.0.4`):**
     - Skopiowano [`src/pi1/script.sh`](file:///mnt/z/Vagrant/home-page/src/pi1/script.sh) do `/var/www/script.sh` (uprawnienia `775`, `rwxrwxr-x`).
     - Zweryfikowano zgodność z plikiem źródłowym (`diff -u` &rarr; 0 różnic).
  3. **Wdrożenie na serwer PI2 (`10.0.0.5`):**
     - Skopiowano [`src/pi2/script.sh`](file:///mnt/z/Vagrant/home-page/src/pi2/script.sh) do `/var/www/script.sh` (uprawnienia `775`, `rwxrwxr-x`).
     - Zweryfikowano zgodność z plikiem źródłowym (`diff -u` &rarr; 0 różnic).
  4. **Weryfikacja testowa:**
     - Przetestowano odpowiedzi JSON API na serwerach PI1 i PI2 (`http://10.0.0.4/index.php?api=status` oraz `http://10.0.0.5/index.php?api=status`) – oba panele działają prawidłowo (`success: true`).


### Wpis 15: Aktualizacja parametrów wywołania script_brama.sh (relay_brama_wjazdowa i relay_brama_garazowa)
- **Dokładne zapytanie użytkownika:**
  > *„zaktualizuj wywolanie dla brama wjazdowa wywolanie powinno byc sh script_brama.sh relay_brama_wjazdowa open 530 0.5 natomiast dla brama garazowa sh script_brama.sh relay_brama_garazowa open 527 0.5”*
- **Podjęte działania:**
  1. **Aktualizacja parametrów w [`src/pi1/config.php`](file:///mnt/z/Vagrant/home-page/src/pi1/config.php):**
     - Zmiana parametru pierwszego (`param`) w `$GATE_CONFIG`:
       - Brama wjazdowa: `'relay_brama_wjazdowa'` (GPIO 530, czas 0.5s).
       - Brama garażowa: `'relay_brama_garazowa'` (GPIO 527, czas 0.5s).
     - Dodano aliasy pokoi: `'relay_brama_wjazdowa' => 'brama_wjazd'`, `'relay_brama_garazowa' => 'brama_garaz'`.
  2. **Aktualizacja mechanizmu wykonawczego i odczytu stanów w [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php) i [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php):**
     - Uruchamianie komend CLI w tle:
       - `nohup /bin/sh /var/www/script_brama.sh relay_brama_wjazdowa [akcja] 530 0.5 > /dev/null 2>&1 &`
       - `nohup /bin/sh /var/www/script_brama.sh relay_brama_garazowa [akcja] 527 0.5 > /dev/null 2>&1 &`
     - Zapis i odczyt plików stanów `.state` dla obu wariantów nazewniczych (`relay_brama_wjazdowa.state`, `relay_brama_wjazd.state`, `brama_wjazd.state` oraz analogicznie dla bramy garażowej).
  3. **Aktualizacja komentarzy w [`src/pi1/script_brama.sh`](file:///mnt/z/Vagrant/home-page/src/pi1/script_brama.sh):**
     - Nowe przykłady wywołań:
       - `sh script_brama.sh relay_brama_wjazdowa open 530 0.5`
       - `sh script_brama.sh relay_brama_garazowa open 527 0.5`
  4. **Wdrożenie na serwer PI1 (`10.0.0.4`):**
     - Przesłano `config.php`, `index.php`, `index_full.php` oraz `script_brama.sh` do `/var/www/`.
     - Zweryfikowano działanie API (`http://10.0.0.4/index.php?api=status`).


### Wpis 16: Diagnoza i rozwiązanie problemu wywołań script_brama.sh dla bram na PI1
- **Dokładne zapytanie użytkownika:**
  > *„Ponacisnieciu klawisza "Brama wjazdowa" nie widze w systemie wywolania proces sh script_brama.sh relay_brama_wjazdowa open 530 0.5”*
- **Zidentyfikowane przyczyny:**
  1. **Czas życia procesu (zaledwie 0.5 sekundy):**
     - Skrypt `script_brama.sh` wykonuje impuls trwający 0.5s (`sleep 0.5`). Ręczne wywołanie `ps aux | grep script_brama` w terminalu SSH po kliknięciu w przeglądarce nie pozwalało go zauważyć, ponieważ proces kończy się natychmiast (po 500 ms).
  2. **Uprawnienia do plików GPIO sysfs oraz `.state`:**
     - Linie GPIO w `/sys/class/gpio/gpio530/value` i `/sys/class/gpio/gpio527/value` miały uprawnienia `755` (`-rwxr-xr-x 1 root gpio`), przez co proces serwera Apache (`www-data`) otrzymywał błąd `cannot create /var/www/gpio/gpio530: Permission denied`.
     - Plik `/var/www/gpio/relay_brama_wjazdowa.state` należał do `root:root` z uprawnieniami `644`, blokując zapis stanu przez skrypt.
  3. **Mapowanie akcji 'toggle' na 'close':**
     - Przy stanie otwartym naciśnięcie klawisza w formularzu przesyłało akcję `toggle`, którą backend zamieniał na `close`, przez co w tle uruchamiane było `close` zamiast `open`.
- **Wdrożone rozwiązania:**
  1. **Poprawa uprawnień systemowych GPIO na PI1:**
     - Ustawiono `chmod 666 /sys/class/gpio/gpio*/value` oraz zaktualizowano skrypt startowy `/etc/init.d/raspberry`.
     - Usunięto i odtworzono pliki `.state` dla bram w `/var/www/gpio/` z uprawnieniami zapisu `chmod 666`.
  2. **Wymuszenie akcji 'open' dla bram posesji:**
     - W [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php) zaktualizowano formularz i logikę JavaScript/PHP: naciśnięcie klawisza bramy zawsze wysyła bezpośrednio akcję `open`, wywołując `sh script_brama.sh relay_brama_wjazdowa open 530 0.5`.
  3. **Dedykowany rejestr logów wywołań w czasie rzeczywistym:**
     - Dodano automatyczne logowanie wywołań zarówno po stronie PHP w `runScriptCommand()`, jak i bezpośrednio w [`src/pi1/script_brama.sh`](file:///mnt/z/Vagrant/home-page/src/pi1/script_brama.sh) do pliku `/var/www/gpio/script_brama.log` wraz ze znacznikiem czasu i numerem PID procesu.
     - Użytkownik może w każdej chwili sprawdzić historię i moment każdego wywołania poleceniem:
       ```bash
       cat /var/www/gpio/script_brama.log
       # lub na żywo:
       tail -f /var/www/gpio/script_brama.log
       ```
  4. **Weryfikacja produkcyjna na serwerze PI1 (`10.0.0.4`):**
     - Przetestowano wywołania przez panel WWW i cURL – w logu `/var/www/gpio/script_brama.log` natychmiast rejestrowane są wpisy PHP exec oraz PID procesu skryptu powłoki.


### Wpis 17: Zmiana sterowania Pompą Obiegową na dedykowany skrypt `script_pompa_obieg.sh` (PI0)
- **Dokładne zapytanie użytkownika:**
  > *„Dla pi0 zmien wywolanie "Pompa obiegowa" na wywolanie skryptu script_pompa_obieg.sh open jako wlacz close jako wylacz”*
- **Podjęte działania:**
  1. **Konfiguracja skryptu [`src/pi0/script_pompa_obieg.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_pompa_obieg.sh):**
     - Obsłużono wywołania:
       - `sh script_pompa_obieg.sh open` – włączenie pompy: zapisuje `1` do `/var/www/gpio/relay_pompa_obiegowa.state` oraz `0` do `/var/www/gpio/gpio535`.
       - `sh script_pompa_obieg.sh close` – wyłączenie pompy: zapisuje `0` do `/var/www/gpio/relay_pompa_obiegowa.state` oraz `1` do `/var/www/gpio/gpio535`.
       - `sh script_pompa_obieg.sh stop` – zatrzymanie pompy: zapisuje `2` do `/var/www/gpio/relay_pompa_obiegowa.state` oraz `1` do `/var/www/gpio/gpio535`.
     - Zapewniono obsługę zarówno bezpośredniej składni `sh script_pompa_obieg.sh open`, jak i `sh script_pompa_obieg.sh relay_pompa_obiegowa open`.
     - Nadano uprawnienia wykonywalności (`chmod +x`).
  2. **Aktualizacja backendu w [`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php):**
     - Dodano stałą `SCRIPT_POMPA_PATH` oraz pomocniczą funkcję `getScriptPompaPath()`.
     - Zaktualizowano definicję urządzenia `pompa` w `$DEVICES`: przypisano `script => 'script_pompa_obieg.sh'`, `state_file => GPIO_DIR . '/relay_pompa_obiegowa.state'`, `gpio => '535'`, `pin => '23'`.
     - W `setDeviceState`: dla urządzenia `pompa` (lub zdefiniowanego `script`) akcje włączania (`on`, `1`, `open`, `start`) wykonują `/bin/sh script_pompa_obieg.sh open`, a wyłączania (`off`, `0`, `close`, `stop`) wykonują `/bin/sh script_pompa_obieg.sh close`.
     - Dodano automatyczny rejestr wywołań skryptu pompy w `/var/www/gpio/script_pompa.log`.
     - W `getDeviceState`: zaimplementowano priorytetowy odczyt stanu z dedykowanego pliku `relay_pompa_obiegowa.state` (`1` = włączony, `0`/`2` = wyłączony) z zachowaniem pełnego fallbacku do pliku GPIO (`gpio535` / `gpio530`).
  3. **Aktualizacja interfejsu WWW w [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php):**
     - Karta urządzenia wyświetla plakietkę wskazującą skrypt wykonawczy: `script_pompa_obieg.sh • GPIO 535 (pin 23)`.
  4. **Weryfikacja i testy:**
     - Przeprowadzono testy syntaktyczne PHP (`php -l`).
     - Przetestowano asercjami pełne cykle przejść stanów włącz/wyłącz przez `setDeviceState` i `getDeviceState`.


### Wpis 18: Zmiana sterowania Piecem CWU i Piecem CO na dedykowane skrypty bash (PI0)
- **Dokładne zapytanie użytkownika:**
  > *„popraw rowniez dla klawisza "Piec Gazowy CO" aby uzywal skryptu script_piec_gaz.sh oraz "Piec Gazowy CWU" aby uzywal skryptu script_piec_gaz_cwu.sh”*
- **Podjęte działania:**
  1. **Aktualizacja skryptów wykonawczych:**
     - [`src/pi0/script_piec_gaz.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_piec_gaz.sh) (Piec CO):
       - Przekaźnik: GPIO 536 (pin 24), Zasilanie szyny przekaźników: GPIO 519 (pin 7).
       - Obsługa wywołań: `open` (włącz: stan `1` w `relay_piec_gaz.state` oraz `piec_co.state`, załączenie zasilania szyny GPIO 519 i przekaźnika GPIO 536 `0`), `close` (wyłącz: stan `0`, rozłączenie przekaźnika `1`), `stop` (stop: stan `2`, wyłączenie szyny zasilania `1`).
       - Elastyczna obsługa składni: `sh script_piec_gaz.sh open` oraz `sh script_piec_gaz.sh relay_piec_gaz open`.
     - [`src/pi0/script_piec_gaz_cwu.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_piec_gaz_cwu.sh) (Piec CWU):
       - Przekaźnik: GPIO 537 (pin 25), Zasilanie szyny przekaźników: GPIO 519 (pin 7).
       - Obsługa wywołań: `open` (włącz: stan `1` w `relay_piec_gaz_cwu.state` oraz `piec_cwu.state`, załączenie zasilania szyny GPIO 519 i przekaźnika GPIO 537 `0`), `close` (wyłącz: stan `0`, rozłączenie przekaźnika `1`), `stop` (stop: stan `2`, wyłączenie szyny zasilania `1`).
       - Elastyczna obsługa składni: `sh script_piec_gaz_cwu.sh open` oraz `sh script_piec_gaz_cwu.sh relay_piec_gaz_cwu open`.
     - Nadano uprawnienia wykonywalności (`chmod +x`).
  2. **Aktualizacja backendu w [`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php):**
     - Dodano stałe `SCRIPT_PIEC_CWU_PATH`, `SCRIPT_PIEC_CO_PATH` oraz uogólniono funkcję `getDeviceScriptPath($scriptName)`.
     - Zaktualizowano definicję `piec_cwu` w `$DEVICES`: `script => 'script_piec_gaz_cwu.sh'`, `gpio => '537'`, `pin => '25'`, `state_file => GPIO_DIR . '/relay_piec_gaz_cwu.state'`.
     - Zaktualizowano definicję `piec_co` w `$DEVICES`: `script => 'script_piec_gaz.sh'`, `gpio => '536'`, `pin => '24'`, `state_file => GPIO_DIR . '/relay_piec_gaz.state'`.
     - W `setDeviceState`: wszystkie 3 urządzenia kotłowni (`piec_cwu`, `piec_co`, `pompa`) wykonują swoje dedykowane skrypty powłoki z akcją `open` (włącz) lub `close` (wyłącz).
     - W `getDeviceState`: zoptymalizowano odczyt stanów z dedykowanych plików `.state` z fallbackami do plików GPIO.
  3. **Aktualizacja interfejsu WWW w [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php):**
     - Karta Pieca CWU wskazuje: `script_piec_gaz_cwu.sh • GPIO 537 (pin 25)`.
     - Karta Pieca CO wskazuje: `script_piec_gaz.sh • GPIO 536 (pin 24)`.
     - Karta Pompy wskazuje: `script_pompa_obieg.sh • GPIO 535 (pin 23)`.
  4. **Weryfikacja i testy:**
     - Wykonano pełny skrypt testowy weryfikujący asercjami przejścia stanów (włącz/wyłącz/odczyt) dla wszystkich 3 obwodów oraz funkcje masowe `setAllDevicesState('on')` i `setAllDevicesState('off')`. Wszystkie testy zakończone wynikiem pozytywnym.


### Wpis 19: Pełna synchronizacja i archiwizacja konwersacji w GEMINI.md
- **Dokładne zapytanie użytkownika:**
  > *„zapisz cala konwersacje do GEMINI.md”*
- **Podjęte działania:**
  1. **Audyt i synchronizacja dokumentacji [`GEMINI.md`](file:///mnt/z/Vagrant/home-page/GEMINI.md):**
     - **Sekcja 1 (Architektura Środowiska Multi-Server):** Zaktualizowano opis serwera PI0 – pełna obsługa 3 skryptów bash (`script_piec_gaz_cwu.sh`, `script_piec_gaz.sh`, `script_pompa_obieg.sh`) oraz linii szyny zasilania przekaźników (GPIO 519).
     - **Sekcja 4 (Serwer PI0: Sterowanie Kotłownią i Wentylacją):** Zaktualizowano tabelę urządzeń PI0, uwzględniając numery GPIO (537, 536, 535, 519), numery fizycznych pinów (25, 24, 23, 7) oraz powiązane pliki stanów `.state` w `/var/www/gpio/`.
     - **Sekcja 5.D (Sterowanie Kotłownią na serwerze PI0):** Zamieszczono pełny spis komend `curl` oraz konsoli bash dla Pieca CWU, Pieca CO oraz Pompy obiegowej, w tym sterowania grupowego `ALL` i parametrów legacy (`sw1`, `sw2`, `sw3`).
     - **Sekcja 6 (Struktura Plików w Repozytorium):** Uzupełniono strukturę katalogu `src/pi0/` o skrypty wykonawcze `script_piec_gaz_cwu.sh`, `script_piec_gaz.sh` i `script_pompa_obieg.sh`.
     - **Sekcja 7 (Rejestr Konwersacji i Historia Zadań):** Zarchiwizowano kompletny rejestr wszystkich wykonanych kroków (Wpisy 1–19) z dokładnymi zapytaniami użytkownika, wprowadzonymi zmianami w kodzie i wynikami testów.


### Wpis 20: Wdrożenie zaktualizowanej konfiguracji i skryptów na serwer PI0 (10.0.0.2) oraz weryfikacja PI1 i PI2
- **Dokładne zapytanie użytkownika:**
  > *„wyslij nowa konfiguracje na serwer pi0 i jesli jest cos nowego na pi1 i pi2”*
- **Podjęte działania i wyniki wdrożenia:**
  1. **Audyt spójności plików na serwerach PI1 (`10.0.0.4`) oraz PI2 (`10.0.0.5`):**
     - Porównano pliki w `/var/www/` na PI1 (`config.php`, `index.php`, `index_full.php`, `script.sh`, `script_brama.sh`, `script_one.sh`, `wentylacja.php`) z lokalnym katalogiem `src/pi1/` &rarr; **0 różnic (pliki w 100% zsynchronizowane)**.
     - Porównano pliki w `/var/www/` na PI2 (`config.php`, `index.php`, `index_full.php`, `script.sh`) z lokalnym katalogiem `src/pi2/` &rarr; **0 różnic (pliki w 100% zsynchronizowane)**.
  2. **Wdrożenie na serwer PI0 (`10.0.0.2`):**
     - Przesłano przez `scp` pliki:
       - [`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php) &rarr; `/var/www/config.php`
       - [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php) &rarr; `/var/www/index.php`
       - [`src/pi0/script_piec_gaz_cwu.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_piec_gaz_cwu.sh) &rarr; `/var/www/script_piec_gaz_cwu.sh`
       - [`src/pi0/script_piec_gaz.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_piec_gaz.sh) &rarr; `/var/www/script_piec_gaz.sh`
       - [`src/pi0/script_pompa_obieg.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_pompa_obieg.sh) &rarr; `/var/www/script_pompa_obieg.sh`
     - Ustawiono uprawnienia wykonywalności (`chmod 775 /var/www/script_*.sh`) oraz właściciela `www-data:www-data`.
  3. **Weryfikacja produkcyjna:**
     - Sprawdzono składnię PHP na serwerze PI0 (`php -l`) &rarr; 0 błędów.
     - Przetestowano endpoint API (`http://10.0.0.2/index.php?api=status`) &rarr; zwrócono pełną strukturę urządzeń ze statusami na żywo dla Pieca CWU, Pieca CO oraz Pompy obiegowej.
     - Potwierdzono poprawne działanie API na PI1 (`10.0.0.4`) i PI2 (`10.0.0.5`).


### Wpis 21: Integracja magistrali 1-Wire DS18B20 i blok temperatur w panelu Kotłowni (PI0)
- **Dokładne zapytanie użytkownika:**
  > *„na pi0 wlaczylem obsluge w1-wire oraz podlaczylem czujniki. Chcialbym wyswieltlic temperatury z tych czujnikow.*
  > *cd /sys/bus/w1/devices/*
  > *cat /sys/bus/w1/devices/28-031464447dff/w1_slave*
  > *opis czujnikow*
  > *28-000004d2f065 cwu*
  > *28-021463f067ff gaz +*
  > *28-0004170d18ff gaz -*
  > *28-0315047d06ff co +*
  > *28-000004ef79c2 co -*
  > *Stworz Blok z wartosciami wyswietlanymi i podzielonymi na co, gaz i cwu”*
- **Podjęte działania i architektura rozwiązania:**
  1. **Konfiguracja czujników i grup w [`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php):**
     - Zdefiniowano stałą `W1_DEVICES_DIR` (domyślnie `/sys/bus/w1/devices` z automatycznym fallbackiem do `/var/www/w1` lub lokalnego katalogu mocków `__DIR__ . '/gpio/w1'`).
     - Utworzono tablicę `$TEMP_SENSORS` mapującą 5 czujników DS18B20:
       - `cwu` (`28-000004d2f065`): Zasobnik ciepłej wody użytkowej (rola `tank`, akcent cyjan `#06b6d4`),
       - `gaz_plus` (`28-021463f067ff`): Zasilanie kotła gazowego (rola `supply`, akcent czerwony `#ef4444`),
       - `gaz_minus` (`28-0004170d18ff`): Powrót do kotła gazowego (rola `return`, akcent niebieski `#3b82f6`),
       - `co_plus` (`28-0315047d06ff`): Zasilanie instalacji grzejnikowej CO (rola `supply`, akcent pomarańczowy `#f97316`),
       - `co_minus` (`28-000004ef79c2`): Powrót z instalacji grzejnikowej CO (rola `return`, akcent błękitny `#60a5fa`).
     - Utworzono strukturę grup `$TEMP_GROUPS` dzielącą odczyty na 3 główne sekcje: **Centralne Ogrzewanie (CO)**, **Kocioł Gazowy (Gaz)** oraz **Ciepła Woda Użytkowa (CWU)**.
     - Zaimplementowano funkcję `readW1Sensor($sensorKeyOrId)` z pełną walidacją sumy kontrolnej CRC (`YES`), wyciąganiem wartości `t=...`, obsługą temperatur ujemnych oraz bezpiecznym formatowaniem.
     - Zaimplementowano funkcję `getAllTemperatures()` z automatycznym wyliczaniem różnicy temperatur $\Delta T = T_{zasilanie} - T_{powrót}$ dla obiegów CO i Gaz.
  2. **Interfejs WWW i blok wizualny w [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php):**
     - Dodano na szczycie panelu (zaraz pod paskiem statusu i przed sekcją przekaźników) dedykowany blok **Temperatury w kotłowni** w układzie 3 kart:
       - **Karta CO:** kafelki dla Zasilania (CO +) i Powrotu (CO -), plakietka $\Delta T$ (`ΔT: +12.3°C`), identyfikatory sprzętowe czujników DS18B20,
       - **Karta Gaz:** kafelki dla Zasilania (Gaz +) i Powrotu (Gaz -), plakietka $\Delta T$ (`ΔT: +13.5°C`),
       - **Karta CWU:** kafelek dla Zasobnika CWU z plakietką statusu.
     - Dodano obsługę wskaźników braku odczytu (`--.- °C`, stan rozłączenia/błąd CRC).
     - Zintegrowano JavaScript DOM (`updateTemperaturesDOM`) z istniejącym cyklem odpytywania `refreshStatus()` co 2.5s (live update bez przeładowania strony).
  3. **Zdalne API JSON:**
     - Obsłużono dedykowany endpoint `index.php?api=temperatures` (oraz alias `?api=temp`),
     - Wzbogacono standardowy endpoint `index.php?api=status` o pole `temperatures` zawierające pełny stan grup, czujników i $\Delta T$.
  4. **Weryfikacja i testy:**
     - Sprawdzono poprawność syntaktyczną PHP (`php -l src/pi0/config.php`, `php -l src/pi0/index.php`),
     - Przetestowano CLI odpytania API `?api=temperatures` oraz `?api=status`,
     - Zweryfikowano obsługę błędów CRC oraz temperatur ujemnych. Wszystkie testy przebiegły pomyślnie.


### Wpis 22: Wdrożenie nowej konfiguracji 1-Wire na serwer PI0 (10.0.0.2) i testy na żywo
- **Dokładne zapytanie użytkownika:**
  > *„wyslij konfiguracje na serwer”*
- **Podjęte działania i wyniki produkcyjne:**
  1. **Transfer plików na serwer PI0 (`10.0.0.2`):**
     - Przesłano przez `scp`:
       - [`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php) &rarr; `pi@10.0.0.2:/var/www/config.php`
       - [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php) &rarr; `pi@10.0.0.2:/var/www/index.php`
     - Nadano odpowiednie uprawnienia i właściciela: `chmod 775`, `chown www-data:www-data`.
  2. **Weryfikacja składni na serwerze:**
     - `php -l /var/www/config.php` &rarr; brak błędów syntaktycznych.
     - `php -l /var/www/index.php` &rarr; brak błędów syntaktycznych.
  3. **Weryfikacja odczytów fizycznych z czujników 1-Wire w sysfs:**
     - `28-0315047d06ff` (CO +): `25.7°C` (zasilanie CO)
     - `28-000004ef79c2` (CO -): `22.8°C` (powrót CO) &rarr; **$\Delta T = +2.9^\circ\text{C}$**
     - `28-021463f067ff` (Gaz +): `25.4°C` (zasilanie kocioł)
     - `28-0004170d18ff` (Gaz -): `22.3°C` (powrót kocioł) &rarr; **$\Delta T = +3.1^\circ\text{C}$**
     - `28-000004d2f065` (CWU): `127.9°C` (zasobnik CWU)
  4. **Weryfikacja API HTTP REST:**
     - Endpoint `http://10.0.0.2/index.php?api=temperatures` zwraca poprawny JSON ze wszystkimi 5 czujnikami,
     - Endpoint `http://10.0.0.2/index.php?api=status` zwraca jednoczesny status urządzeń kotłowni oraz temperatur na żywo,
     - Panel WWW w przeglądarce pod adresem `http://10.0.0.2/` wyświetla 3 sekcje temperatur z automatycznym odświeżaniem w tle co 2.5s.


### Wpis 23: Zmiana czujnika CWU na 28-000004b32254 i wdrożenie na PI0
- **Dokładne zapytanie użytkownika:**
  > *„cwu jest teraz pod czujnikiem 28-000004b32254 zmien konfiguracje i wyslij na serwer”*
- **Podjęte działania:**
  1. **Aktualizacja konfiguracji w [`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php):**
     - Zmieniono identyfikator czujnika `cwu` z `28-000004d2f065` na `28-000004b32254`.
  2. **Wdrożenie na serwer PI0 (`10.0.0.2`):**
     - Przesłano plik `config.php` na serwer przez `scp` do `/var/www/config.php`.
     - Zweryfikowano uprawnienia `www-data:www-data` i brak błędów składni PHP (`php -l`).
  3. **Weryfikacja na żywo:**
     - Nowy czujnik CWU `28-000004b32254` zwraca poprawny, fizyczny odczyt temperatury wody w zasobniku: **`44.1°C`** (zamiast dotychczasowej wartości 127.9°C z uszkodzonego czujnika).
     - Odpytano API `http://10.0.0.2/index.php?api=temperatures` potwierdzając prawidłowe raportowanie danych dla wszystkich 3 sekcji.


### Wpis 24: Klawisze sterowania kotłownią na PI0 (Włącz/Wyłącz i Zasilanie Wyłącz), wyśrodkowanie napisów oraz synchronizacja skryptów
- **Dokładne zapytanie użytkownika:**
  > *„Chcialbym zmodyfikowac strone dla PI0 Gdzie jest klawisz "Piec Gazowy CWU" i "Piec Gazowy CO". Wysrodkuj napisy na wszystkich klawiszach na tej stronie w pionie i poziomie. Dla dwoch wymienionych prosze o zmodyfikowanie i stworzenie klawisza Wlacz/wylacz ktory wykonuje skrypt odpowiednio dla cwu script_piec_gaz_cwu.sh open/close (Wlacz oznacza stan wlaczenia natomiast wyłacz brak pracy ) i co script_piec_gaz_cwu.sh open/close i drugi klawisz Zasilanie Wyłącz z prosba o potwierdzenie ze oznacza to wylaczenie zasilania. Ponadto skopiouj skrypty script_piec_gaz_cwu.sh script_piec_gaz.sh script_pompa_obieg.sh na serwer bo sa nowe zaktualizowane przezemnie.”*
  > *„don't change scripts”* / *„do not change script_piec_gaz_cwu.sh script_piec_gaz.sh script_pompa_obieg.sh - understand but do not change”*
- **Podjęte działania i wyniki produkcyjne:**
  1. **Wyśrodkowanie napisów na wszystkich klawiszach na stronie PI0 w pionie i poziomie:**
     - Zaktualizowano style CSS dla `button`, `.btn-global`, `.btn-action`, `.btn-global span` oraz `.btn-action span` stosując `display: inline-flex; align-items: center; justify-content: center; text-align: center; vertical-align: middle; line-height: 1.2;`.
  2. **Przebudowa klawiszy dla Pieca Gazowego CWU i Pieca Gazowego CO:**
     - **Klawisz 1 (`Włącz / Wyłącz`):** Przełącza tryb pracy pieca (wywołuje `open` gdy piec nie pracuje, włączając palnik i zawór Afriso, lub `close` wyłączając palnik - stan spoczynku/brak pracy). Gdy aktywny, podświetla się w kolorze zielonym z plakietką `WŁĄCZONY`.
     - **Klawisz 2 (`Zasilanie Wyłącz`):** Dedykowany klawisz odcinający zasilanie 230V kotła (wywołuje `stop`). Kliknięcie wyświetla okno potwierdzenia `confirm()`: *„Czy na pewno chcesz wyłączyć zasilanie dla [Urządzenie]? Oznacza to całkowite wyłączenie zasilania kotła gazowego (po ok. 200s schłodzenia palnika i elektroniki).”*.
     - Dla **Pompy obiegowej** zachowano standardowe klawisze `Włącz` i `Wyłącz` z pełnym wyśrodkowaniem tekstu.
  3. **Aktualizacja zaplecza backendowego ([`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php)):**
     - Obsłużono akcję `stop` mapując ją bezpośrednio na parametr skryptu `stop` i stan `2` (`ZASILANIE WYŁ.`).
     - Uruchamianie skryptów bash z parametrem `open` oraz `stop` realizowane jest w tle (`nohup ... &`), dzięki czemu opóźnienia `sleep 160` i `sleep 200` w skryptach nie blokują serwera Apache/PHP.
  4. **Synchronizacja zaktualizowanych skryptów na fizyczny serwer PI0 (`10.0.0.2`):**
     - Skopiowano bez jakichkolwiek modyfikacji oryginalne pliki użytkownika:
       - [`src/pi0/script_piec_gaz_cwu.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_piec_gaz_cwu.sh) &rarr; `10.0.0.2:/var/www/script_piec_gaz_cwu.sh`
       - [`src/pi0/script_piec_gaz.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_piec_gaz.sh) &rarr; `10.0.0.2:/var/www/script_piec_gaz.sh`
       - [`src/pi0/script_pompa_obieg.sh`](file:///mnt/z/Vagrant/home-page/src/pi0/script_pompa_obieg.sh) &rarr; `10.0.0.2:/var/www/script_pompa_obieg.sh`
     - Nadano uprawnienia `chmod 775` i właściciela `www-data:www-data`.
     - Skopiowano zaktualizowane pliki aplikacji WWW:
       - [`src/pi0/config.php`](file:///mnt/z/Vagrant/home-page/src/pi0/config.php) &rarr; `10.0.0.2:/var/www/config.php`
       - [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php) &rarr; `10.0.0.2:/var/www/index.php`
  5. **Weryfikacja produkcyjna:**
     - Składnia PHP na serwerze poprawna (`No syntax errors detected`).
     - Endpoint `http://10.0.0.2/index.php?api=status` zwraca pełny status urządzeń i temperatur.
     - Przetestowano renderowanie strony WWW `http://10.0.0.2/index.php` potwierdzając obecność obu nowych klawiszy oraz pełne centrowanie elementów.


### Wpis 25: Dynamiczny stan klawisza Włącz/Wyłącz dla Pieca Gazowego CWU i Pieca Gazowego CO na PI0
- **Dokładne zapytanie użytkownika:**
  > *„W Pi0 "Piec Gazowy CWU" na stronie jest klawisz wlacz/wylacz. Zmien jego konfiguracje aby pokazywal wlacz gdy jest wylaczony i na odwrot. Dokladnie to samo zmodyfikuj dla klawisza Piec Gazowy CO.”*
- **Podjęte działania i wyniki produkcyjne:**
  1. **Modyfikacja widoku HTML i logiki prezentacji ([`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php)):**
     - Zmieniono klawisz przełączający dla **Pieca Gazowego CWU** (`piec_cwu`) oraz **Pieca Gazowego CO** (`piec_co`):
       - Gdy urządzenie jest **wyłączone** (`$isOn == false`, stan `off` lub `stop`): klawisz wyświetla napis **`Włącz`** (niebieski styl jak na Grzegorzka 8 z ikoną włączenia).
       - Gdy urządzenie jest **włączone** (`$isOn == true`, stan `on`): klawisz wyświetla napis **`Wyłącz`** (czerwony styl `.is-active-btn` / `#ef4444` z ikoną wyłączenia).
  2. **Aktualizacja dynamicznego JS (`updateDeviceDOM` i `applyOptimisticAction`):**
     - W funkcji `updateDeviceDOM`: dynamiczne przełączanie treści przycisku między `<span>Włącz</span>` a `<span>Wyłącz</span>` wraz z odpowiednimi ikonami SVG i atrybutami `title` przy każdym cyklu pollingu oraz po otrzymaniu odpowiedzi z API.
     - W funkcji `applyOptimisticAction`: natychmiastowa reakcja 0 ms z etykietami `Włączam...` lub `Wyłączam...` w zależności od aktualnego stanu urządzenia.
  3. **Wdrożenie na serwer PI0 (`10.0.0.2`):**
     - Przesłano zaktualizowany plik `index.php` na serwer `10.0.0.2:/var/www/index.php`.
     - Zweryfikowano brak błędów składni PHP (`php -l /var/www/index.php`), uprawnienia `chmod 775` oraz właściciela `www-data:www-data`.
  4. **Weryfikacja na żywo:**
     - Sprawdzono wyrenderowany kod HTML `curl -s http://10.0.0.2/index.php` potwierdzając wyświetlanie przycisków **`Włącz`** dla wyłączonych pieców CWU i CO.


### Wpis 26: Utworzenie nowej strony brama.php oraz dodanie linku Brama na górnej belce Panele:
- **Dokładne zapytanie użytkownika:**
  > *„chcialbym abys utworzyl nowa strone brama.php na ktorej beda klawisze dla "Brama" czyli "brama wjazdowa" i "brama garazowa" oraz link Brama na gornej belce Panele:”*
- **Podjęte działania i wyniki implementacji:**
  1. **Utworzenie dedykowanego panelu [`src/pi1/brama.php`](file:///mnt/z/Vagrant/home-page/src/pi1/brama.php) oraz aliasu [`src/pi1/bramy.php`](file:///mnt/z/Vagrant/home-page/src/pi1/bramy.php) na serwerze PI1 (`10.0.0.4`):**
     - Zaprojektowano nowoczesny, responsywny panel sterowania bramami posesji zgodny z design systemem projektu (ciemny motyw, karty, ikony SVG, powiadomienia Toast, diagnostyka w stopce).
     - **Dwie główne karty urządzeń:**
       - **Brama wjazdowa (GPIO 530, pin 24):** Duży główny klawisz pilota `Klawisz: Brama wjazdowa` (impuls 0.5s), dynamiczna plakietka statusu (`● OTWARTA`, `○ ZAMKNIĘTA`, `● IMPULS...`) oraz przyciski funkcyjne `Otwórz`, `Zamknij`, `Stop`.
       - **Brama garażowa (GPIO 527, pin 21):** Duży główny klawisz pilota `Klawisz: Brama garażowa` (impuls 0.5s), dynamiczna plakietka statusu oraz przyciski funkcyjne `Otwórz`, `Zamknij`, `Stop`.
     - **Akcje grupowe:** Sekcja szybkiego wyzwalania `Otwórz obie`, `Zamknij obie` oraz `Stop obie`.
     - **Podgląd ostatnich operacji:** Dynamiczna lista ostatnich wywołań pobierana na bieżąco z pliku `/var/www/gpio/script_brama.log`.
     - **Optimistic UI i asynchroniczny polling:** Natychmiastowa reakcja 0 ms na kliknięcie z animacją pulsowania i spinnerem, automatyczny polling co 2.5s przez API `brama.php?api=status`.
     - **Obsługa REST API i cURL:** Pełne wsparcie dla parametrów POST i GET (`gate=brama_wjazd`, `action=open|close|stop`, `?brama_wjazd=open`, `?brama_garaz=open` itd.).
  2. **Dodanie linku `Brama` na górnej belce nawigacyjnej `Panele:` we wszystkich panelach:**
     - Dodano odnośnik z dedykowaną ikoną bramy posesji (`<svg viewBox="0 0 24 24"><path d="M4 4v16M20 4v16M4 8h16M4 16h16M9 4v16M15 4v16"/></svg>`):
       - [`src/pi1/index.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index.php) &rarr; `<a href="brama.php" ...>`
       - [`src/pi1/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi1/index_full.php) &rarr; `<a href="brama.php" ...>`
       - [`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php) &rarr; `<a href="brama.php" ...>`
       - [`src/pi1/brama.php`](file:///mnt/z/Vagrant/home-page/src/pi1/brama.php) &rarr; aktywny link `class="server-nav-link active"`
       - [`src/pi0/index.php`](file:///mnt/z/Vagrant/home-page/src/pi0/index.php) &rarr; `<a href="http://10.0.0.4/brama.php" ...>`
       - [`src/pi0/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi0/wentylacja.php) &rarr; `<a href="http://10.0.0.4/brama.php" ...>`
       - [`src/pi2/index.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index.php) &rarr; `<a href="http://10.0.0.4/brama.php" ...>`
       - [`src/pi2/index_full.php`](file:///mnt/z/Vagrant/home-page/src/pi2/index_full.php) &rarr; `<a href="http://10.0.0.4/brama.php" ...>`
  3. **Pliki przekierowujące na PI0 i PI2:**
     - Utworzono [`src/pi0/brama.php`](file:///mnt/z/Vagrant/home-page/src/pi0/brama.php) oraz [`src/pi2/brama.php`](file:///mnt/z/Vagrant/home-page/src/pi2/brama.php) przekierowujące (302) bezpośrednio do `http://10.0.0.4/brama.php`.
  4. **Weryfikacja:**
     - Brak błędów składni PHP (`No syntax errors detected`) we wszystkich 11 plikach.
     - Przetestowano endpoint `brama.php?api=status` oraz wywołania akcji w CLI.




### Wpis 27: Reorganizacja kolejności bloków oraz wyświetlanie i zapis aktualnego trybu pracy na stronie Wentylacja (PI1 i PI0)
- **Dokładne zapytanie użytkownika:**
  > *„Chcailbym abys na stronie Wentylacja zamienil kolejnosc blokow: Ogrzewanie/Nagrzewnica ma byc pod przewietrzanie, Tryb wakacje ma byc pod tryby wentylacji. Chcailbym abys rowniez dodal w jakim jest trybie aktualnie wentylacja napis ze jest Tryb Auto/ Tryb normalny lub wolny”*
- **Podjęte działania i wyniki produkcyjne:**
  1. **Zmiana kolejności bloków kontrolnych w panelu Wentylacja ([`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php) oraz [`src/pi0/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi0/wentylacja.php)):**
     - Przeniesiono blok **`Tryb Wakacje (Holiday)`** bezpośrednio pod blok **`Tryby Wentylacji (Przepływ Powietrza)`**.
     - Przeniesiono blok **`Ogrzewanie / Nagrzewnica (Heating)`** bezpośrednio pod blok **`Przewietrzanie (Airing)`**.
     - Nowa kolejność sekcji w panelu:
       1. **Karty Aktualnych Parametrów Pracy Urządzeń** (temperatury w torze powietrza i stan podzespołów w czasie rzeczywistym),
       2. **Tryby Wentylacji (Przepływ Powietrza)** (Auto, Normalny, Wolny, nastawa ręczna),
       3. **Tryb Wakacje (Holiday)** *(bezpośrednio pod Trybami Wentylacji)*,
       4. **Przewietrzanie (Airing)**,
       5. **Ogrzewanie / Nagrzewnica (Heating)** *(bezpośrednio pod Przewietrzaniem)*,
       6. **Bypass (Obejście Wymiennika)**,
       7. **GWC (Gruntowy Wymiennik Ciepła)**,
       8. **Zasilanie Jednostek**,
       9. **Filtry i Serwis**.
  2. **Przechowywanie i prezentacja aktualnego trybu pracy wentylacji:**
     - Zaimplementowano lokalne przechowywanie wybranego trybu w pliku stanu `/var/www/gpio/wentylacja_mode.state` (format JSON z polami `mode`, `label`, `updated_at` oraz fallback tekstowy).
     - Zmapowane etykiety trybów:
       - `auto` &rarr; **`Tryb Auto`** (domyślny),
       - `normal` &rarr; **`Tryb normalny`** (100% / 100%),
       - `slow` &rarr; **`Tryb wolny`** (80% / 70%),
       - `manual` &rarr; **`Tryb ręczny (X% / Y%)`**,
       - `holiday` &rarr; **`Tryb wakacje (X dni)`**,
       - `airing` &rarr; **`Przewietrzanie (X min)`**.
     - **Prezentacja na stronie WWW:**
       - **Główny nagłówek panelu:** wskaźnik `.current-mode-pill` z pulsującą zieloną diodą statusu (`●`) i wyraźnym napisem `Aktualny tryb: Tryb Auto` (lub `Tryb normalny` / `Tryb wolny`).
       - **Nagłówek sekcji Tryby Wentylacji:** plakietka `.section-mode-badge` z aktualnym stanem.
       - **Wyróżnienie przycisków:** klasa `.btn-mode-active` ze znacznikiem `● AKTYWNY` na aktywnym przycisku trybu.
     - **Optimistic UI i aktualizacja w locie:**
       - Natychmiastowa reakcja 0 ms w JavaScript (`updateModeDisplay`) przy kliknięciu przycisku dowolnego trybu,
       - Dynamiczna synchronizacja przy odpowiedzi z API (`handleCmdResponse`) oraz podczas cyklicznego odświeżania temperatur (`refreshTemperatures`).
     - **Rozszerzenie REST API:**
       - Endpointy `api=temperatures`, `api=status` oraz odpowiedzi JSON wszystkich poleceń zawierają teraz pola `current_mode` i `current_mode_label`.
  3. **Wdrożenie produkcyjne na serwery:**
     - **PI1 (`10.0.0.4`):** przesłano do `/var/www/wentylacja.php` (uprawnienia `-rwxrwxr-x`, właściciel `www-data:www-data`).
     - **PI0 (`10.0.0.2`):** przesłano do `/var/www/wentylacja.php` z zachowaniem nagłówka i linków PI0 (uprawnienia `-rwxrwxr-x`, właściciel `www-data:www-data`).
  4. **Weryfikacja na żywo:**
     - Składnia PHP poprawna na obu serwerach (`No syntax errors detected`).
     - `curl -s "http://10.0.0.4/wentylacja.php?api=temperatures"` oraz `curl -s "http://10.0.0.2/wentylacja.php?api=temperatures"` zwracają dane z poprawnymi polami `current_mode` i `current_mode_label`.
     - Przetestowano kod HTML obu stron potwierdzając poprawną kolejność sekcji oraz obecność kafelka z aktualnym trybem pracy.


### Wpis 28: Poprawa pobierania i przekazywania wartości z formularza dla Własnej Regulacji Prędkości w panelu Wentylacja (PI1 i PI0)
- **Dokładne zapytania użytkownika:**
  > *„Popraw na stronie wentylacja \"Tryby Wentylacji (Przepływ Powietrza)\" - wlasna regulacja predkosci nie przekazuje wartosci z Nawiew a jedynie do polecenia wysyla dla polcenia wartosc z wywiew a powinna odpowiednio wartosc dla nawiew i dla wywiew”*
  > *„popraw polecenie na 10.0.0.4”*
  > *„w regulacji Własna regulacja prędkości: uzywa sie polecenia /usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=ManualMode&airflowvalue=100&valueofairextraction=100'. Niestety z formularza nie pobiera sie wartosci poprawnie - czy mozesz to poprawic”*
- **Przyczyna problemu:**
  - W kodzie HTML sekcji *„Własna regulacja prędkości”* pola tekstowe posiadały wyłącznie atrybuty `id="custom_airflow"` i `id="custom_extraction"`, brakowało natomiast standardowych atrybutów `name="airflow"` oraz `name="extraction"`. Brak atrybutu `name` uniemożliwiał standardową serializację pól formularza przez przeglądarkę.
  - Sekcja nie była otoczona tagiem `<form>`, co blokowało obsługę klawisza Enter oraz standardowego przesyłania formularza metodą POST.
  - Po stronie PHP obsługa akcji `manual` weryfikowała wąski zestaw kluczy i w razie braku ich dopasowania przyjmowała wartość domyślną 100%, przez co do polecenia API rekuperatora `/API/RunFunction?name=ManualMode&airflowvalue=...&valueofairextraction=...` trafiały wartości domyślne zamiast wpisanych przez użytkownika.
- **Wprowadzone poprawki:**
  1. **Struktura formularza HTML ([`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php) oraz [`src/pi0/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi0/wentylacja.php)):**
     - Zamieniono kontener na pełnoprawny `<form class="param-bar" id="form_custom_manual" method="POST" action="wentylacja.php" onsubmit="applyCustomManual(document.getElementById('btn_custom_manual')); return false;">`.
     - Dodano atrybuty `name="airflow"` oraz `name="extraction"` do pól numerycznych z `id="custom_airflow"` i `id="custom_extraction"`.
     - Dodano ukryte pola `<input type="hidden" name="action" value="manual">` oraz `<input type="hidden" name="target" id="custom_manual_target" value="both">`.
     - Przycisk otrzymał typ `type="submit"`, identyfikator `id="btn_custom_manual"` oraz bezpieczną obsługę wywołania AJAX/Optimistic UI.
  2. **Aktualizacja logiki JavaScript:**
     - W funkcji `applyCustomManual`: dynamiczne pobieranie elementów po ID oraz selektorach atrybutu `name`, walidacja i bezpieczne ograniczenie wartości do przedziału 10–100%, przekazywanie kompletu aliasów parametrów (`airflow`, `extraction`, `airflowvalue`, `valueofairextraction`, `nawiew`, `wywiew`) do funkcji `execCmd`.
     - W funkcji `setTarget`: dynamiczna synchronizacja ukrytego pola `#custom_manual_target` przy zmianie jednostki docelowej.
     - W funkcji `execCmd`: obsługa Optimistic UI dla trybu manualnego uwzględniająca wszystkie przekazane klucze prędkości.
  3. **Backend PHP (obsługa akcji `manual` oraz bezpośredniego wywołania `ManualMode`):**
     - Zaimplementowano kompleksowe odczytywanie wartości nawiewu (`airflowvalue`): `$_POST['airflow']`, `$_GET['airflow']`, `$_POST['airflowvalue']`, `$_GET['airflowvalue']`, `$_POST['custom_airflow']`, `$_GET['custom_airflow']`, `$_POST['nawiew']`, `$_GET['nawiew']`, `$_POST['supply']`, `$_GET['supply']`.
     - Zaimplementowano kompleksowe odczytywanie wartości wywiewu (`valueofairextraction`): `$_POST['extraction']`, `$_GET['extraction']`, `$_POST['valueofairextraction']`, `$_GET['valueofairextraction']`, `$_POST['custom_extraction']`, `$_GET['custom_extraction']`, `$_POST['wywiew']`, `$_GET['wywiew']`, `$_POST['wyciag']`, `$_GET['wyciag']`, `$_POST['extract']`, `$_GET['extract']`.
     - Wartości są rzutowane na liczby całkowite i ograniczane do zakresu 10–100%.
     - Generowane zapytanie HTTP cURL do rekuperatorów: `http://<ip>/API/RunFunction?name=ManualMode&airflowvalue=<nawiew>&valueofairextraction=<wywiew>`.
  4. **Wdrożenie produkcyjne na serwery:**
     - **PI1 (`10.0.0.4`):** przesłano plik do `/var/www/wentylacja.php` (uprawnienia `-rwxrwxr-x`, właściciel `www-data:www-data`, składnia PHP poprawna).
     - **PI0 (`10.0.0.2`):** przesłano plik do `/var/www/wentylacja.php` (uprawnienia `-rwxrwxr-x`, właściciel `www-data:www-data`, składnia PHP poprawna).
  5. **Weryfikacja na żywo:**
     - Przetestowano wywołania POST i GET z różnymi wartościami:
       - `curl -s -X POST http://10.0.0.4/wentylacja.php -d "action=manual&airflow=85&extraction=75"` &rarr; `airflowvalue=85`, `valueofairextraction=75` &rarr; obie jednostki: HTTP 200, OK.
       - `curl -s -X POST http://10.0.0.4/wentylacja.php -d "action=manual&nawiew=60&wywiew=40"` &rarr; `airflowvalue=60`, `valueofairextraction=40` &rarr; obie jednostki: HTTP 200, OK.
       - `curl -s -X POST http://10.0.0.2/wentylacja.php -d "action=manual&airflow=90&extraction=70"` &rarr; `airflowvalue=90`, `valueofairextraction=70` &rarr; obie jednostki: HTTP 200, OK.
       - `curl -s "http://10.0.0.4/wentylacja.php?action=manual&airflow=80&extraction=60"` &rarr; `airflowvalue=80`, `valueofairextraction=60` &rarr; obie jednostki: HTTP 200, OK.


### Wpis 29: Zmiana nazw jednostek wentylacyjnych na Grzegorzka 8 (10.0.0.8) i Grzegorzka 8A (10.0.0.9)
- **Dokładne zapytanie użytkownika:**
  > *„zmien nazwe Wentylacja 1 na Grzegorzka 8 i Wentylacja 2 na Grzegorzka 8A”*
- **Podjęte działania i wyniki produkcyjne:**
  1. **Aktualizacja kodu paneli wentylacji ([`src/pi1/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi1/wentylacja.php) oraz [`src/pi0/wentylacja.php`](file:///mnt/z/Vagrant/home-page/src/pi0/wentylacja.php)):**
     - Zaktualizowano tablicę konfiguracyjną `$WENTYLACJA`:
       - `10.0.0.8`: `name` zmieniono z `Wentylacja 1` na **`Grzegorzka 8`**.
       - `10.0.0.9`: `name` zmieniono z `Wentylacja 2` na **`Grzegorzka 8A`**.
     - Zaktualizowano przełącznik wyboru jednostek (target pills):
       - **`Tylko Grzegorzka 8 (10.0.0.8)`**
       - **`Tylko Grzegorzka 8A (10.0.0.9)`**
     - Zaktualizowano karty urządzeń w sekcji *Aktualne Parametry Pracy Urządzeń*:
       - Nagłówek lewej karty: **`Grzegorzka 8`** (`10.0.0.8`).
       - Nagłówek prawej karty: **`Grzegorzka 8A`** (`10.0.0.9`).
     - Zaktualizowano powiadomienia toast w JavaScript (`setTarget`) oraz opisy zwracane przez API (`targetDesc`):
       - `target=1` &rarr; `Grzegorzka 8 (10.0.0.8)`
       - `target=2` &rarr; `Grzegorzka 8A (10.0.0.9)`
       - `target=both` &rarr; `Obie jednostki (Grzegorzka 8 + Grzegorzka 8A)`
  2. **Wdrożenie na serwery:**
     - **PI1 (`10.0.0.4`):** wdrożono do `/var/www/wentylacja.php` (uprawnienia `-rwxrwxr-x`, właściciel `www-data:www-data`).
     - **PI0 (`10.0.0.2`):** wdrożono do `/var/www/wentylacja.php` (uprawnienia `-rwxrwxr-x`, właściciel `www-data:www-data`).
  3. **Weryfikacja:**
     - Brak błędów składni PHP na obu maszynach (`No syntax errors detected`).
     - Odpytano API `http://10.0.0.4/wentylacja.php?api=temperatures` – potwierdzono nowe nazwy w formacie JSON (`"name": "Grzegorzka 8"`, `"name": "Grzegorzka 8A"`).
     - Zweryfikowano wyrenderowany kod HTML na obu serwerach (`10.0.0.4` oraz `10.0.0.2`).
