# Wdrożenie na serwer (Hostinger lub inny hosting WordPress)

Instrukcja dla właściciela. Asystent **nie wdraża** i **nie wykonuje `git push`** bez wyraźnej zgody. Adresy (domena, REST, URL-e aplikacji) pochodzą z konfiguracji WordPressa (`home_url()`, `rest_url()`), nie ma ich w kodzie. Hostinger to obecne środowisko serwerowe, nie docelowa domena — zmiana domeny nie wymaga zmian w kodzie.

Ocena gotowości i lista ryzyk: `docs/QA-REPORT.md` (§7–§9).

## Co zmieniają ostatnie etapy
- **Etap 10.5 (Sequences):** migracja **M015** (schemat v15): tylko nowe tabele `ubr_seq_campaigns`, `ubr_seq_campaign_steps`, `ubr_seq_sequences`, `ubr_seq_messages`, `ubr_seq_mailboxes`, `ubr_seq_suppressions`. Nowa pozycja menu „Sequences” i zakładka „Mailing” w karcie firmy; nowy status pipeline „Mailing”. **Automatyczna wysyłka jest WYŁĄCZONA** po instalacji — włącza ją owner/admin w Sequences → Settings dopiero po liście kontrolnej (skrzynka SMTP przetestowana, worker działa, harmonogram sprawdzony w trybie testowym, potwierdzenie zabezpieczeń). Do niezawodnej wysyłki **wymagany cron serwera** (hPanel → Cron Jobs, co 5 min): `cd <katalog WordPressa> && wp ubr queue-work --all` (zmiana crona — osobna decyzja właściciela). Wyłącznik awaryjny: `define('UBR_SEQUENCES_SENDING', false);`. Hasła skrzynek szyfrowane `UBR_ENCRYPTION_KEY` (zalecany osobny klucz; jego zmiana wymaga ponownego podania haseł). Skrzynki: SMTP z hasłem aplikacji (dane od dostawcy poczty); OAuth (Google / Microsoft) — do dodania po wyborze dostawcy.
- **Etap 10.4.3 (Discover + Website Age + „Przejmij firmę” + ClickUp):** migracja **M014** (schemat v14): tylko nowe kolumny w `ubr_companies` (e-mail firmowy, wynik Website Age), `ubr_contact_research_runs.stage`, `ubr_clickup_exports.synced_fields` / `conflicts` + indeksy; bez zmian danych. **Zmiany zachowania:** worker weryfikacji pracuje krótkimi krokami (8 s, `UBR_VERIFY_STEP_SECONDS`) z łańcuchowaniem i blokadą 60 s — usuwa przerwy ~2:50 na LiteSpeed; po weryfikacji w tle: Website Opportunity Score (limit 300/dzień, `UBR_AUTO_ANALYSIS=false` wyłącza) i Website Age Intelligence (Wayback Machine, bezpłatne, limit 150/dzień, `UBR_SITE_AGE=false` / `UBR_WAYBACK_ENABLED=false` wyłącza); „Przejmij firmę” zleca w tle Decision Maker Findera (bez płatnych zapytań Apollo, limit 100/dzień) i — tylko gdy włączone (domyślnie WYŁĄCZONE; właściciel / admin: Settings → ClickUp → „Zadania po przejęciu firmy”, albo `UBR_CLICKUP_ON_CLAIM`) — zadanie ClickUp. Pola ClickUp nie są tworzone automatycznie (propozycja w Settings → ClickUp). Ustawienia Apollo (`UBR_APOLLO_PEOPLE_ENABLED`, `UBR_APOLLO_PHONE_ENABLED`, `UBR_APOLLO_PHONE_DAILY_LIMIT`) bez zmian. Konfiguracja serwera (reguła noabort dla workera lub cron hPanel `wp ubr queue-work --all`) — osobna decyzja, zob. projekt „DIAG-prompt10-4-1”.
- **Etap 10.4 (Performance & Progressive Discovery):** migracja **M013** (schemat v13): nowa tabela `ubr_page_cache` (wspólna pamięć podręczna publicznych stron firm, czas życia 1 h, błędy połączenia 10 min; sprzątanie przez healthcheck WP-Cron). Tylko nowa tabela, bez zmian danych. Nowe (opcjonalne) stałe: `UBR_PAGE_CACHE` (domyślnie włączona; `false` wyłącza cache i równoległe pobieranie), `UBR_PAGE_CACHE_TTL` (s, domyślnie 3600), `UBR_PREFETCH_CONCURRENCY` (równoległe połączenia w jednym procesie, domyślnie 6, maks. 12), `UBR_VERIFY_BATCH` (firm „w toku” w kroku weryfikacji, domyślnie 6). Wymaga rozszerzenia PHP cURL (`curl_multi_*`) — bez niego weryfikacja działa jak wcześniej (sekwencyjnie). Nie zwiększa liczby procesów PHP ani zapytań do rejestrów; ustawienia Apollo (`UBR_APOLLO_PEOPLE_ENABLED`, `UBR_APOLLO_PHONE_ENABLED`, `UBR_APOLLO_PHONE_DAILY_LIMIT`) bez zmian. **Zmiany zachowania:** firmy nieaktywne w rejestrze → gotowość BLOCKED, domena innego podmiotu → RESEARCH; numery firmy zapisywane już przy weryfikacji; usunięte linki `tel:` / przycisk „Zadzwoń”.
- **Etap 10.3 (Apollo Contact Matching & Phone Unlock):** migracja **M012** (schemat v12): nowe tabele `ubr_apollo_people` (kandydaci z Apollo People API Search, bez e-maili i numerów), `ubr_apollo_matches` (dopasowania prywatne dla organizacji), `ubr_apollo_match_history`; nowa kolumna `ubr_phone_reveals.apollo_person_id`. Tylko nowe tabele / kolumna, idempotentnie, bez usuwania danych. Nowa (opcjonalna) stała: `UBR_APOLLO_PEOPLE_DAILY_LIMIT` (domyślnie 20 wyszukiwań firm / organizację / dzień). Płatne odblokowanie telefonów nadal wyłączone (`UBR_APOLLO_PHONE_ENABLED` niezdefiniowane); wyszukiwanie osób w Apollo wymaga `UBR_APOLLO_PEOPLE_ENABLED` (bez niej działa tylko odczyt zapisanych kandydatów i pamięci podręcznej). Retencja kandydatów: `wp ubr contacts purge --days=N` usuwa też kandydatów Apollo niewidzianych od N dni i bez zatwierdzonego dopasowania (tylko ręcznie).
- **Etap 10.2 (Direct Decision Maker Phones):** migracja **M011** (schemat v11): nowa tabela `ubr_contact_phone_links` (powiązanie numer ↔ osoba z dowodem: VERIFIED / LIKELY / UNCERTAIN / REJECTED; `org_key = 0` automat ze strony, `org_key = organizacja` — decyzja organizacji), nowe kolumny `ubr_company_phones.owner_class`, `owner_note`, `owner_checked_at` (właściciel numeru). Istniejące `contacts.phone_e164` → powiązania LIKELY (strona, dowód do ponownego sprawdzenia) lub VERIFIED (numer podany ręcznie). Bez usuwania danych; nazwy kolumn bez słów zastrzeżonych MySQL 8. **Zmiana zachowania:** READY_DIRECT wymaga potwierdzonego numeru decydenta (VERIFIED albo Apollo) — numer zapisany przy osobie bez dowodu daje co najwyżej CALL_COMPANY / RESEARCH. Nowe (opcjonalne) stałe: `UBR_DM_PHONES_DAILY_LIMIT` (domyślnie 80 sprawdzeń firm dziennie na organizację). Nowe trasy: `POST /companies/{id}/decision-phones/scan` (`dry_run`), `GET/POST /contacts/{id}/phone-links`, `DELETE /contacts/{id}/phone-links/{link_id}`, `POST /integrations/apollo/people-test` (jeden kontrolowany test, 0 kredytów wg dokumentacji). WP-CLI: `wp ubr decision-phones --org=<slug> [--limit] [--company] [--dry-run|--execute]`. Po wgraniu: `wp ubr migrate` → v11; przebudowane zasoby (`public/build`).
- **Etap 10 (Cold Calling Workspace):** migracja **M010** (schemat v10): nowe tabele `ubr_company_phones`, `ubr_phone_reveals`, `ubr_contact_phones`; nowe kolumny w `ubr_companies` (`main_phone_*`, `phones_count`, `phones_checked_at`), `ubr_contacts` (`phone_e164`, `external_provider`, `external_id`), `ubr_organization_prospects` (`do_not_contact*`, `last_contact_at`, `last_contact_outcome`), `ubr_prospect_activities` (`outcome`); indeksy; uzupełnienie `phone_e164` z istniejących numerów osób i `last_contact_at` z istniejących aktywności. Bez usuwania i nadpisywania danych. Nowe (opcjonalne) stałe: `UBR_APOLLO_PHONE_ENABLED` (domyślnie wyłączone — płatne odblokowanie telefonów), `UBR_APOLLO_PHONE_DAILY_LIMIT` (domyślnie 20). Nowe zdarzenie WP-Cron `ubr_phones_tick` (tylko odbiór wyników zatwierdzonych zleceń, 0 kredytów). Zgodność: MySQL 8.4 (sprawdzone na LocalWP) i MariaDB 10.11. **Uwaga:** przy automatycznej migracji nowy kod działa od pierwszego żądania — na produkcji `UBR_AUTO_MIGRATE=false` i `wp ubr migrate` od razu po wgraniu plików (przy błędzie migracji listy zwracają 500 do czasu jej powodzenia). Uzupełnienie numerów dla firm sprzed etapu 10: `wp ubr phones-backfill --org=<slug>` (próbnie), `--execute` (pobranie stron, bezpłatne).
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
// Etap 10 (opcjonalne, decyzja właściciela — płatne zapytania Apollo, tylko po potwierdzeniu w oknie dialogowym):
// define('UBR_APOLLO_PHONE_ENABLED', true);   // „Odblokuj telefon” (owner / admin organizacji); domyślnie wyłączone
// define('UBR_APOLLO_PHONE_DAILY_LIMIT', 20);  // maks. odblokowań na organizację dziennie
```
Przed włączeniem `UBR_APOLLO_PHONE_ENABLED` wykonaj bezpieczny test: `wp ubr apollo-phone-test --org=<slug> --contact=<id osoby z Apollo>` (tylko sprawdzenie warunków, bez zapytania). Zapytanie płatne wyłącznie świadomie: `… --execute --yes`, potem `wp ubr apollo-phone-test --org=<slug> --contact=<id> --poll` (odbiór wyniku, 0 kredytów).
Bez `UBR_AUTO_MIGRATE=false` migracje uruchamiają się automatycznie przy pierwszym żądaniu WWW (z blokadą `GET_LOCK`; po błędzie kolejna próba najwcześniej po 10 minutach, komunikat w panelu WP).

## Wdrożenie
1. Pobierz kod na serwer jak dotychczas (Git w panelu Hostingera lub `git pull` w katalogu motywu).
2. **Migracje:** `wp ubr migrate` (kod wyjścia ≠ 0 przy błędzie; nic nie robi, gdy schemat jest aktualny). Potem `wp ubr migrate --status` → `Schemat: v15 (najnowsza v15) — aktualny`, „Ostatni błąd migracji: brak”. Etap 10 dodaje M010, etap 10.2 — M011, etap 10.3 — M012, etap 10.4 — M013, etap 10.4.3 — M014, etap 10.5 — M015 (kopia bazy przed migracją — punkt „Przed wdrożeniem”).
3. **Cache:** wyczyść cache LiteSpeed / CDN dla `/app/*` i `/wp-content/themes/uglybutrich/public/build/*` (pliki mają hash, manifest musi być świeży). Transienty Action Center są wersjonowane. Przy object cache: `wp cache flush`.
4. **Workery i cron:**
   - zalecany cron systemowy co minutę: `wp cron event run --due-now` oraz `wp ubr queue-work --all` (Discovery + po jednym kroku weryfikacji, analiz, decydentów, ClickUp i Lead Intelligence — także gdy loopback HTTP jest zablokowany);
   - `wp ubr doctor` pokazuje test loopbacku i ostatni sygnał workera;
   - blokady kolejek są w bazie (`GET_LOCK`) i zwalniają się automatycznie po zakończeniu procesu — nie ma opcji blokad do ręcznego czyszczenia.
5. **Szybki test po wdrożeniu (bez płatnych zapytań):**
   - `/app/` po zalogowaniu: Overview, Today's Priorities, Follow-ups, Workspace, Recent Activity, Coverage — bez błędów w konsoli;
   - `GET /wp-json/ubr/v1/action-center`: 200 dla członka organizacji, 401 dla anonima;
   - karta firmy → Website Analysis → „Manual Website Review” widoczne;
   - Discover / Prospects: kolumny Firma, Wielkość, Strona WWW, Ocena, Decydent, Telefony, Opiekun / CRM, Status kontaktu, Akcje; bez poziomego przewijania;
   - Action Center: kolejka „Gotowe do telefonu”; KPI bez zmian;
   - nie uruchamiaj Apollo ani analiz tylko po to, żeby sprawdzić wdrożenie (przycisk „Odblokuj telefon” jest ukryty, dopóki `UBR_APOLLO_PHONE_ENABLED` nie jest włączone).

## Wycofanie (rollback)
1. Przywróć poprzednią wersję kodu (`git checkout <poprzedni commit>` w katalogu motywu albo poprzednie wdrożenie w panelu).
   **Etap 10.2:** M011 dodaje tabelę `ubr_contact_phone_links` i 3 kolumny NULL w `ubr_company_phones` — kod etapu 10 działa ze schematem v11 (nowe dane ignorowane; READY_DIRECT wróci do reguły z etapu 10). Rollback kodu wystarczy (wniosek z analizy schematu, nie testowano).
   **Etap 10:** M010 dodaje wyłącznie tabele i kolumny z wartościami domyślnymi / NULL, więc kod etapu 7 powinien działać ze schematem v10 (nowe dane są ignorowane) — rollback kodu wystarczy, M010 można zostawić (wniosek z analizy schematu, rollback nie był testowany). Usuwanie tabel / kolumn M010 tylko po kopii zapasowej i świadomie (utrata numerów ręcznych, odblokowanych numerów, zakazów kontaktu i wyników rozmów).
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
