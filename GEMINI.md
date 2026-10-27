# Podsumowanie Wdrożenia: Sterowanie Roletami (PHP + Apache + I2C)

Niniejszy plik zawiera podsumowanie ustaleń, struktury plików, konfiguracji oraz pełną składnię poleceń `curl` do zdalnego sterowania serwerem Apache.

---

## 1. Architektura i Działanie

Stworzona aplikacja PHP ([`index.php`](file:///home/user/home_page/index.php)) zarządza przekaźnikami I2C PCA9685 poprzez wywoływanie skryptu [`script.sh`](file:///home/user/home_page/script.sh).

### Dwa przyciski dla każdego wiersza:
1. **Przycisk 1 (Stanowy - Otwórz / Zamknij):**
   - Dynamicznie odzwierciedla aktualny stan:
     - Gdy roleta jest **zamknięta**: przycisk wyświetla napis **"Otwórz"** (akcja: `open`).
     - Gdy roleta jest **otwarta**: przycisk wyświetla napis **"Zamknij"** (akcja: `close`).
     - Gdy roleta jest **w trakcie ruchu**: wyświetla **"Otwieranie..."** lub **"Zamykanie..."** wraz ze spinnerem.
2. **Przycisk 2 (Stop):**
   - Zawsze dostępny czerwony przycisk **"Stop"** (akcja: `stop`).
   - Natychmiast ubija procesy `open`/`close` w tle i zatrzymuje silnik.

> [!NOTE]
> Akcje `open` i `close` trwają 16–31 sekund (zgodnie z czasem w `relay_setup.conf`). Wykonywane są w tle (`nohup script.sh ... > /dev/null 2>&1 &`), dzięki czemu interfejs WWW nie ulega zablokowaniu, a przycisk **Stop** może w każdej chwili przerwać ruch rolety.

---

## 2. Lista Pomieszczeń i Przekaźników

Poniższa tabela przedstawia mapowanie identyfikatorów (`room`) na fizyczne przekaźniki z pliku [`relay_setup.conf`](file:///home/user/home_page/gpio/relay_setup.conf):

| Identyfikator (`room`) | Nazwa w panelu | Przekaźniki w `relay_setup.conf` |
|---|---|---|
| `kuchnie` | Kuchnie | `relay_piwnica_kuchnia_lewe`, `relay_piwnica_kuchnia_prawe` |
| `duzy_pokoj` | Duży pokój (wszystkie) | Wszystkie 8 rolet w salonie (wschód, południe, zachód) |
| `duzy_wschod` | Duży wschód | `relay_pietro-1_duzy-pokoj_wschod_lewe`, `relay_pietro-1_duzy-pokoj_wschod_prawe` |
| `duzy_poludnie` | Duży południe | `relay_pietro-1_duzy-pokoj_poludnie_lewe`, `relay_pietro-1_duzy-pokoj_poludnie_srodkowe`, `relay_pietro-1_duzy-pokoj_poludnie_prawe` |
| `duzy_zachod` | Duży zachód | `relay_pietro-1_duzy-pokoj_zachod_lewe`, `relay_pietro-1_duzy-pokoj_zachod_prawe`, `relay_pietro-1_duzy-pokoj_zachod_balkon` |
| `prasowalnia` | Prasowalnia | `relay_piwnica_prasowalnia` |
| `piwnica_wejscie` | Piwnica wejście | `relay_piwnica_wejscie` |
| `dach_wejscie` | Dach wejście | `relay_pietro-3_dach` |
| `ALL` | Wszystkie | Wykonuje daną akcję dla wszystkich pomieszczeń |

---

## 3. Składnia Poleceń `curl` (Zdalne Sterowanie)

Aplikacja obsługuje zarówno zapytania **POST**, jak i **GET**, a odpowiedzi zwracane są w formacie **JSON**.

Zamień `<IP_SERWERA>` na adres IP maszyny z serwerem Apache (np. `192.168.1.50`).

### A. Metoda POST (Zalecana)

```bash
# 1. Dach wejście - Otwórz
curl -X POST http://<IP_SERWERA>/index.php -d "room=dach_wejscie&action=open"

# 2. Dach wejście - Zamknij
curl -X POST http://<IP_SERWERA>/index.php -d "room=dach_wejscie&action=close"

# 3. Dach wejście - Stop
curl -X POST http://<IP_SERWERA>/index.php -d "room=dach_wejscie&action=stop"

# 4. Przełączenie stanu (toggle: jeśli zamknięte to otwórz, jeśli otwarte to zamknij)
curl -X POST http://<IP_SERWERA>/index.php -d "room=dach_wejscie&action=toggle"

# 5. Kuchnie - Otwórz
curl -X POST http://<IP_SERWERA>/index.php -d "room=kuchnie&action=open"

# 6. Duży pokój (wszystkie rolety w salonie) - Zamknij
curl -X POST http://<IP_SERWERA>/index.php -d "room=duzy_pokoj&action=close"

# 7. Wszystkie pomieszczenia naraz - Stop
curl -X POST http://<IP_SERWERA>/index.php -d "room=ALL&action=stop"

# 8. Wszystkie pomieszczenia naraz - Zamknij
curl -X POST http://<IP_SERWERA>/index.php -d "room=ALL&action=close"
```

### B. Metoda GET (Szybkie wywołanie / Webhook / Przeglądarka)

Możesz wywołać akcję bezpośrednio w pasku adresu przeglądarki lub poprzez prosty `curl`:

```bash
# Otwórz dach wejście
curl "http://<IP_SERWERA>/index.php?room=dach_wejscie&action=open"

# Zamknij dach wejście
curl "http://<IP_SERWERA>/index.php?room=dach_wejscie&action=close"

# Zatrzymaj dach wejście
curl "http://<IP_SERWERA>/index.php?room=dach_wejscie&action=stop"

# Zatrzymanie wszystkich rolet
curl "http://<IP_SERWERA>/index.php?room=ALL&action=stop"
```

### C. Pobieranie statusu wszystkich rolet (JSON)

```bash
curl -s "http://<IP_SERWERA>/index.php?api=status"
```

Przykładowa odpowiedź JSON:
```json
{
  "success": true,
  "timestamp": 1793055029,
  "statuses": {
    "dach_wejscie": {
      "state": "closed",
      "label": "Zamknięty",
      "badge": "badge-secondary",
      "next_action": "open",
      "next_label": "Otwórz",
      "is_moving": false
    }
  }
}
```

---

## 4. Struktura Plików

- **Pliki aplikacji:**
  - [`/home/user/home_page/index.php`](file:///home/user/home_page/index.php) – główny interfejs WWW + obsługa API.
  - [`/home/user/home_page/config.php`](file:///home/user/home_page/config.php) – konfiguracja ścieżek i definicje pokoi.
  - [`/home/user/home_page/script.sh`](file:///home/user/home_page/script.sh) – skrypt wykonawczy operujący na I2C.
  - [`/home/user/home_page/gpio/relay_setup.conf`](file:///home/user/home_page/gpio/relay_setup.conf) – adresy rejestrów i czasy otwierania.

- **Środowisko Apache:**
  - `/var/www/html/` – pliki serwowane przez serwer WWW Apache.
  - `/var/www/gpio/` – pliki konfiguracyjne i pliki `.state` przekaźników.
