# Podsumowanie Wdrożenia: Sterowanie Roletami PI1 i PI2 (PHP + Apache + I2C)

Niniejszy dokument zawiera podsumowanie architektury środowiska multi-server, konfiguracji przekaźników i paneli WWW dla serwerów **PI1** (`10.0.0.4`) oraz **PI2** (`10.0.0.5`), pełną składnię poleceń `curl` do zdalnego sterowania przez API oraz chronologiczny rejestr wykonanych prac i ustaleń w ramach konwersacji.

---

## 1. Architektura Środowiska Multi-Server

W systemie domowym funkcjonują 3 serwery Apache/Linux połączone w sieci lokalnej:

1. **`cubie` (`10.0.0.2` | katalog `src/cubie/`):**
   - Sterowanie piecem, CWU, pompą ciepła, pompami obiegowymi, zbiornikiem, elektrozaworami wody i rekuperacją/wentylacją.
   - *Status:* Pozostawiony bez zmian per wytyczne użytkownika.
2. **`pi1` (`10.0.0.4` | katalog `src/pi1/`):**
   - Sterowanie roletami i bramami: **Piętro 1 (Salon - 8 rolet)**, **Piwnica (5 rolet)**, **Dach (1 roleta)** oraz **Bramy (wjazdowa i garażowa - 2 przekaźniki)**.
   - Sprzęt: PCA9685 I2C (`RELAY_POWER=526` na Raspberry Pi / `14` legacy), skrypt `script.sh` dla rolet oraz `script_one.sh` dla bram z czasem powrotu (90s).
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
| **Bramy** | `brama_wjazd` | Brama wjazdowa | `relay_brama_wjazd` | Impulsowa (1s impuls, 90s auto-powrót) |
| **Bramy** | `brama_garaz` | Brama garażowa | `relay_brama_garaz` | Impulsowa (1s impuls) |

### Widoki na serwerze PI1:
- **Główny ([`src/pi1/index.php`](file:///home/user/home-page/src/pi1/index.php)):** 10 pozycji z akordeonami rozwijania pojedynczych skrzydeł (przycisk globalny **`Rozwiń szczegóły (4)`**), przyciski grupowe sekcji oraz pełna obsługa AJAX.
- **Wszystkie przekaźniki ([`src/pi1/index_full.php`](file:///home/user/home-page/src/pi1/index_full.php)):** 16 niezależnych fizycznych przekaźników z osobnymi przyciskami Otwórz/Zamknij i Stop.
- **Pasek Multi-Server (`<nav class="server-nav">`):** bezpośrednie linki do serwerów **PI2** (`10.0.0.5`) oraz **Cubie** (`10.0.0.2`).
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
- **Pasek Multi-Server (`<nav class="server-nav">`):** bezpośrednie linki do serwerów **PI1** (`10.0.0.4`) oraz **Cubie** (`10.0.0.2`).

---

## 4. Składnia Poleceń `curl` i Zdalne API

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

# 5. Bramy posesji:
curl -X POST http://10.0.0.4/index.php -d "room=brama_wjazd&action=open"
curl -X POST http://10.0.0.4/index.php -d "room=brama_garaz&action=open"

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
curl -s "http://10.0.0.4/wentylacja.php?api=temperatures&target=1"   # tylko Wentylacja 1 (10.0.0.8)
curl -s "http://10.0.0.4/wentylacja.php?api=temperatures&target=2"   # tylko Wentylacja 2 (10.0.0.9)

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

---

## 5. Struktura Plików w Repozytorium

```
/home/user/home-page/
├── GEMINI.md                    # Niniejsza dokumentacja i historia konwersacji
├── src/
│   ├── cubie/                   # Serwer 10.0.0.2 (bez zmian per wytyczne)
│   ├── pi1/                     # Serwer 10.0.0.4 (Piętro 1, Piwnica, Dach, Bramy, Wentylacja)
│   │   ├── config.php           # Konfiguracja pomieszczeń, sekcji i przekaźników
│   │   ├── index.php            # Główny panel WWW PI1 (10 pokoi + akordeony)
│   │   ├── index_full.php       # Szczegółowy panel WWW PI1 (16 przekaźników)
│   │   ├── wentylacja.php       # Panel i API sterowania dwiema jednostkami wentylacji (10.0.0.8 i 10.0.0.9)
│   │   ├── rekuperacja.php      # Alias kompatybilności wstecznej (dołącza wentylacja.php)
│   │   ├── script.sh            # Skrypt sterujący roletami I2C (RELAY_POWER=14)
│   │   ├── script_one.sh        # Skrypt impulsowy dla bram (z auto-powrotem 90s)
│   │   ├── gpio/
│   │   │   └── relay_setup.conf # Konfiguracja fizycznych rejestrów PI1
│   │   └── alexa/               # Skrypty integracji głosowej
│   └── pi2/                     # Serwer 10.0.0.5 (Piętro 2 i Parter)
│       ├── config.php           # Konfiguracja pomieszczeń, kondygnacji i przekaźników
│       ├── index.php            # Główny panel WWW PI2 (10 pokoi + akordeony)
│       ├── index_full.php       # Szczegółowy panel WWW PI2 (13 przekaźników)
│       ├── script.sh            # Skrypt sterujący roletami I2C (RELAY_POWER=535)
│       └── gpio/
│           ├── relay_setup.conf # Konfiguracja fizycznych rejestrów PI2
│           └── relay_power.state
```

---

## 6. Rejestr Konwersacji i Historia Zadań

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
