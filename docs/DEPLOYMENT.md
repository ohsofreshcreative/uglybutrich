# Wdrożenie na serwer (Hostinger lub inny hosting WordPress)

Instrukcja dla właściciela. Asystent **nie wdraża** i **nie wykonuje `git push`** bez wyraźnej zgody. Adresy (domena, REST, URL-e aplikacji) pochodzą z konfiguracji WordPressa (`home_url()`, `rest_url()`), nie ma ich w kodzie. Hostinger to obecne środowisko serwerowe, nie docelowa domena — zmiana domeny nie wymaga zmian w kodzie.

Ocena gotowości i lista ryzyk: `docs/QA-REPORT.md` (§7–§9).

## Co zmieniają ostatnie etapy
- **Etap 7 (audyt QA):** poprawki bezpieczeństwa (SSRF, XSS w linkach), niezawodności kolejek (blokady MySQL `GET_LOCK`, przerwane zadania, brak duplikatów ClickUp), kontrolowane migracje (`wp ubr migrate`, `UBR_AUTO_MIGRATE`), `wp ubr queue-work --all`. **Bez migracji bazy** (schemat nadal v9), bez nowych sekretów.
- **Etap 6:** M008 (`ubr_action_snoozes`) i M009 (`ubr_manual_reviews`, `ubr_manual_review_history`) — tylko nowe tabele, idempotentne.
- **Etap 5.1:** zmiany tylko w UI. Bez migracji.

## Wymagania serwera (sprawdź przed pierwszym wdrożeniem)
| Element | Wymaganie | Jak sprawdzić |
|---|---|---|
| PHP | ≥ 8.2 z rozszerzeniami `mbstring`, `dom`, `curl`, `json`, `sodium` (szyfrowanie tokenów ClickUp) | `php -v`, `php -m` |
| Baza | MySQL 5.7+/8 lub MariaDB 10.x z funkcją `GET_LOCK` (standard) | `wp db query "SELECT GET_LOCK('t',0)"` |
| WP-CLI | zalecane (migracje, kolejki, diagnostyka) | `wp --info` |
| Composer | katalog `vendor/` jest w repozytorium — `composer install` na serwerze nie jest potrzebne (sprawdź, że `vendor/` istnieje po wdrożeniu) | `ls vendor/autoload.php` |
| Assety | `public/build` w repozytorium — Node do budowania nie jest potrzebny na serwerze | — |
| HTTPS | wymagane (redirect URI ClickUp, cookies sesji) | — |
| Website Analyzer | `proc_open`, Node ≥ 18, `npm ci` w `tools/site-capture`, Chrome/Chromium (`UBR_NODE_BINARY`, `UBR_CHROME_PATH`) | `wp ubr doctor`, Settings → status analizatora |

**Website Analyzer na Hostingerze:** hosting współdzielony i Cloud zwykle nie pozwala uruchamiać Node/Chromium (brak binarek, `proc_open` wyłączone, brak przestrzeni nazw użytkownika dla sandboxa Chromium). Wtedy analiza nie przerywa działania aplikacji, ale kończy się statusem **PARTIAL**: pomiary z HTML (SEO, treść, technika, dostępność z kodu), bez screenshotów i metryk renderu; Lead Intelligence uwzględnia mniejsze pokrycie danych. Możliwe rozwiązania: (1) Hostinger VPS z Node i Chromium, (2) osobny serwer renderujący dla `tools/site-capture` (wymaga dodatkowej integracji — poza zakresem obecnego kodu), (3) świadoma akceptacja trybu PARTIAL. Nie wyłączaj sandboxa Chromium bez analizy ryzyka.

## Przed wdrożeniem
1. **Kopia zapasowa bazy i plików** w panelu Hostingera (sprawdź, że kopię da się pobrać). Zanotuj wersję schematu: `wp ubr migrate --status`.
2. Lokalnie: `npm ci && npm run build`. Testy: `wp eval-file wp-content/themes/uglybutrich/tests/run.php`, `node tests/js/preview-url.test.mjs`, `node tests/js/ssrf-ip.test.mjs`, `node tests/js/datetime.test.mjs` — wszystkie muszą przejść.
3. **Klucz SSH:** pliki `key` / `key.pub` usunięte z drzewa roboczego, ale klucz jest w historii Git. Przed wdrożeniem unieważnij go (`docs/SECURITY-KEY.md`). Nie wdrażaj go.
4. Commit zmian. Push wykonuje właściciel.

## Konfiguracja produkcyjna (`wp-config.php`, wykonuje właściciel)
```php
define('UBR_AUTO_MIGRATE', false);           // migracje tylko przez `wp ubr migrate` podczas wdrożenia
define('UBR_PRIVATE_DIR', '/home/<użytkownik>/ubr-private'); // screenshoty POZA public_html
define('DISABLE_WP_CRON', true);             // jeśli używasz crona systemowego (zalecane)
// Sekrety integracji (bez zmian względem wcześniejszych etapów): UBR_CLICKUP_CLIENT_ID, UBR_CLICKUP_CLIENT_SECRET,
// UBR_CLICKUP_REDIRECT_URI (https://…/app/clickup-callback/), UBR_ENCRYPTION_KEY, UBR_APOLLO_API_KEY, UBR_CEIDG_API_TOKEN, UBR_GUS_API_KEY
```
Bez `UBR_AUTO_MIGRATE=false` migracje uruchamiają się automatycznie przy pierwszym żądaniu WWW (z blokadą `GET_LOCK`; po błędzie kolejna próba najwcześniej po 10 minutach, komunikat w panelu WP).

## Wdrożenie
1. Pobierz kod na serwer jak dotychczas (Git w panelu Hostingera lub `git pull` w katalogu motywu).
2. **Migracje:** `wp ubr migrate` (kod wyjścia ≠ 0 przy błędzie; nic nie robi, gdy schemat jest aktualny). Potem `wp ubr migrate --status` → `Schemat: v9 (najnowsza v9) — aktualny`, „Ostatni błąd migracji: brak”. Etap 7 nie zmienia schematu.
3. **Cache:** wyczyść cache LiteSpeed / CDN dla `/app/*` i `/wp-content/themes/uglybutrich/public/build/*` (pliki mają hash, manifest musi być świeży). Transienty Action Center są wersjonowane. Przy object cache: `wp cache flush`.
4. **Workery i cron:**
   - zalecany cron systemowy co minutę: `wp cron event run --due-now` oraz `wp ubr queue-work --all` (Discovery + po jednym kroku weryfikacji, analiz, decydentów, ClickUp i Lead Intelligence — także gdy loopback HTTP jest zablokowany);
   - `wp ubr doctor` pokazuje test loopbacku i ostatni sygnał workera;
   - blokady kolejek są w bazie (`GET_LOCK`) i zwalniają się automatycznie po zakończeniu procesu — nie ma opcji blokad do ręcznego czyszczenia.
5. **Szybki test po wdrożeniu (bez płatnych zapytań):**
   - `/app/` po zalogowaniu: Overview, Today's Priorities, Follow-ups, Workspace, Recent Activity, Coverage — bez błędów w konsoli;
   - `GET /wp-json/ubr/v1/action-center`: 200 dla członka organizacji, 401 dla anonima;
   - karta firmy → Website Analysis → „Manual Website Review” widoczne;
   - nie uruchamiaj Apollo ani analiz tylko po to, żeby sprawdzić wdrożenie.

## Wycofanie (rollback)
1. Przywróć poprzednią wersję kodu (`git checkout <poprzedni commit>` w katalogu motywu albo poprzednie wdrożenie w panelu).
2. Etap 7 nie zmienia schematu — rollback kodu wystarczy. Starszy kod używa blokad w opcjach (`ubr_*_lock`) — po rollbacku nic nie trzeba czyścić.
3. Rollback etapu 6 (tylko jeśli konieczny, po kopii zapasowej): tabele M008/M009 mogą zostać (starszy kod ich nie używa). Usunięcie: `DROP TABLE wp_ubr_manual_review_history, wp_ubr_manual_reviews, wp_ubr_action_snoozes;` i `UPDATE wp_options SET option_value = 7 WHERE option_name = 'ubr_db_version';` — utrata ocen ręcznych i odłożeń. Nigdy nie cofaj `ubr_db_version` poniżej 7 (M003 zawiera korektę danych).
4. Przywrócenie całej bazy z kopii — tylko przy uszkodzeniu danych; cofa wszystkie zmiany od momentu kopii.
5. Wyczyść cache (punkt 3 wdrożenia).

## Logi i kopie zapasowe
- Błędy aplikacji trafiają do logu PHP (`[ubr] …`, sekrety redagowane). Na Hostingerze: panel → logi błędów; rozważ `WP_DEBUG_LOG` tylko na czas diagnozy (`WP_DEBUG_DISPLAY` zawsze `false` na produkcji).
- Tabele dzienników rosną bez retencji (`ubr_api_requests`, zdarzenia wyszukiwań, analizy, historia ocen) — monitoruj rozmiar bazy; czyszczenie do ustalenia (QA-REPORT A14).
- Kopie: codzienna kopia bazy (panel Hostingera) + kopia przed każdym wdrożeniem; katalog `UBR_PRIVATE_DIR` (screenshoty) poza `public_html` — uwzględnij w kopii plików, jeśli screenshoty mają być zachowane.

## Czego nie robić
Nie kopiuj lokalnej bazy na produkcję. Nie wdrażaj plików `key`, `*.sql` ani skryptów `deploy/*`. Nie uruchamiaj migracji ręcznie przez SQL. Nie wyłączaj sandboxa Chromium i nie osłabiaj ochrony SSRF, żeby analizator „działał” na hostingu.
