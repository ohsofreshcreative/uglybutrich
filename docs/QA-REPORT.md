# Raport QA — audyt, testy i gotowość produkcyjna (Prompt 7, 10.10.2026)

Statusy: **PASSED** (test wykonany, wynik poprawny) · **FAILED** · **NOT TESTED** · **BLOCKED** (brak dostępu / zależność zewnętrzna) · **FIXED** (błąd wykryty i naprawiony, z testem regresyjnym).

Środowiska:
- **kontener** — izolowana kopia aplikacji z osobną bazą testową (WordPress 6.8, MariaDB 10.11, PHP 8.x, Chromium + Playwright);
- **LocalWP** — instalacja właściciela na Macu (`http://uglybutirch.local`), tylko testy bez modyfikacji danych biznesowych;
- **Hostinger** — bez dostępu z tej sesji, nic nie było tam wykonywane.

## 1. Zakres audytu

Przeczytane: AGENTS.md, ARCHITECTURE.md, PROGRESS.md, DEPLOYMENT.md, SECURITY-KEY.md, migracje M001–M009, routing (79 tras REST), kontrolery, serwisy, repozytoria, workery, komponent przeglądarki `tools/site-capture`.

Audyt wykonano w trzech niezależnych przeglądach kodu (bezpieczeństwo · architektura, migracje i wydajność · logika biznesowa), następnie każdy zgłoszony problem zweryfikowano w kodzie i — jeśli był prawdziwy — naprawiono z testem regresyjnym.

Poza zakresem (zgodnie z promptem): nowe moduły, AI Vision, Apollo People Search, nowe źródła danych, outreach, billing, nowe funkcje ClickUp, zmiany scoringów bez udokumentowanego błędu.

## 2. Wyniki testów

| Zestaw | Przed | Po | Status |
|---|---|---|---|
| PHP (`tests/run.php`) | 756/756 | **836/836** (+80: 78 w nowym `tests/qa-p7.php`, 2 w `tests/pipeline-clickup.php`) | PASSED |
| JS `tests/js/preview-url.test.mjs` | 19/19 | 19/19 | PASSED |
| JS `tests/js/ssrf-ip.test.mjs` (nowy) | — | 35/35 | PASSED |
| JS `tests/js/datetime.test.mjs` (nowy, strefy Europe/Warsaw, UTC, America/Los_Angeles) | — | 5/5 × 3 | PASSED |
| Playwright Discover + podgląd (5.1) | 43/43 | 43/43 | PASSED (kontener) |
| Playwright Prospects + podgląd (5.1) | 12/12 | 12/12 | PASSED (kontener) |
| Playwright Dashboard (6) | 30/30 + 7/7 | 30/30 + 7/7 | PASSED (kontener) |
| Playwright QA P7: 9 ekranów × 5 szerokości, podgląd, klawiatura, stany, E2E | — | **138/138** | PASSED (kontener) |

Testy integracyjne (w zestawie PHP, mocki HTTP — bez zapytań zewnętrznych): Discovery z dostawcą testowym i Apollo (mock), weryfikacja KRS/VAT (mock), Website Analyzer z prawdziwym Chromium na lokalnym serwerze fikstur (screenshoty, SSRF, timeout), Decision Maker Finder (mock), ClickUp (mock OAuth i API), Lead Intelligence, Action Center.

### E2E (Playwright, baza testowa kontenera)
Company → Prospect → Pipeline → Follow-up → Activity: zapis z menu ⋯ w Discover → widoczny w Prospects → zmiana statusu w karcie firmy → follow-up → aktywność → notatka → karta na tablicy Kanban → follow-up w sekcji Follow-ups Dashboardu → historia zmian. Ocena ręczna: zapis, odczyt, zmiana z historią poprzedniego stanu, ta sama ocena w podglądzie strony, brak zmiany Lead Potential / Redesign, odrzucenie zapisu bez nonce (CSRF). Wszystko **PASSED**.

### Testy wizualne i UX (Playwright, kontener)
Szerokości 1920 / 1440 / 1280 / 768 / 390 px dla: Dashboard, Discover (z wynikami i pusty), Prospects, Pipeline, Settings, Company Profile (zakładki Firma, Sprzedaż, Website Analysis), Website Preview.
- brak poziomego przewijania strony — PASSED na wszystkich ekranach;
- brak błędów JS — PASSED;
- modal podglądu mieści się w oknie, blokada przewijania tła, Escape — PASSED;
- klawiatura: menu ⋯ (Enter, strzałki) → karta firmy → Escape, fokus wraca na ⋯; pułapka fokusu w modalu (25× Tab) — PASSED;
- stany: szkielety podczas ładowania, komunikat przy błędzie API, pusty Discover — PASSED.
- Wykryty i naprawiony problem: przyciski w stopce karty firmy wychodziły poza ekran przy 390 px (FIXED).

## 3. Wykryte błędy i poprawki

### Bezpieczeństwo
| # | Waga | Problem | Status |
|---|---|---|---|
| S1 | Wysoka | **SSRF:** ochrona adresów rozpoznawała tylko zapis `::ffff:1.2.3.4`; zapis szesnastkowy `::ffff:7f00:1` (127.0.0.1) lub `::ffff:a9fe:a9fe` (metadata chmury) przechodził w PHP i w komponencie przeglądarki (parser URL Chromium zawsze używa zapisu szesnastkowego). Przekierowanie strony firmy pozwalało pobrać/zrzucić stronę wewnętrzną. Teraz analiza na bajtach adresu (IPv4-mapped, IPv4-compatible, NAT64, 6to4, Teredo, site-local). | FIXED (P7-1, ssrf-ip.test.mjs) |
| S2 | Wysoka | **Stored XSS:** profil osoby z JSON-LD `sameAs` przyjmowany, gdy *zawierał* `linkedin.com/in/` (np. `javascript:…//linkedin.com/in/x`), i wstawiany do `href`. Także linki ClickUp (`task_url` z API) i źródła kontaktów bez kontroli schematu. Teraz: ścisły wzorzec profilu, `UrlGuard::httpUrl()` po stronie serwera, `$store.ubr.safeHref()` we wszystkich zewnętrznych `:href`. | FIXED (P7-4) |
| S3 | Średnia | **DNS rebinding** w pobraniach PHP (sprawdzenie DNS, potem cURL rozwiązuje nazwę ponownie). Teraz połączenie przypięte do sprawdzonych adresów (`CURLOPT_RESOLVE`). | FIXED (P7-2) |
| S4 | Średnia | Weryfikacja firmy (`WebsiteInspector`) i `WebsiteChecker` używały `wp_safe_remote_get` — nie blokuje 169.254.0.0/16, 100.64.0.0/10, IPv6, sprawdza tylko pierwszy rekord A. Teraz przekierowania krok po kroku przez `UrlGuard` + przypięcie IP. | FIXED (P7-3) |
| S5 | Średnia | Renderer: `page.route()` nie przechwytuje WebSocketów (`ws://127.0.0.1:port`). Teraz WebSockety blokowane, WebRTC bez UDP poza proxy. | FIXED (P7-20) |
| S6 | Niska | Publiczny ping workera zapisywał opcję przy każdym wywołaniu (zapisy anonimowe, fałszowanie „worker żyje”). | FIXED (P7-19) |
| S7 | Niska | `POST /prospects` zapisywał `search_id` bez sprawdzenia organizacji. | FIXED (P7-8) |
| S8 | Info | Tokeny workera (HMAC) ważne 10 min — możliwe powtórzenie, ale krok kolejki przetwarza tylko istniejącą pracę. | bez zmian (akceptowalne) |
| S9 | Niska | `addMember` ujawnia administratorowi organizacji, czy konto WP z danym e-mailem istnieje, i dodaje je bez zgody użytkownika. | NIE NAPRAWIONE — decyzja właściciela (model zaproszeń) |
| S10 | Info | Trasy zapisu z uprawnieniem „view” (np. przeliczenie Lead Intelligence) — obecnie każda rola ma te same uprawnienia operacyjne, brak roli „tylko odczyt”. | bez zmian |
| S11 | Wysoka | **Klucz SSH w historii Git** (commit `9b24093`) — usunięcie pliku nie unieważnia klucza. | NIE NAPRAWIONE — wymaga właściciela (checklista w §8) |

Zweryfikowane jako bezpieczne: izolacja organizacji i IDOR dla wszystkich 79 tras (ID z URL i z body sprawdzane względem organizacji), kontekst organizacji wyłącznie z `TenantResolver`, brak `is_admin()` w autoryzacji, CSRF (cookie + `X-WP-Nonce`, OAuth `state` jednorazowy 256-bit), SQL (wszystko przez `prepare`, whitelisty sortowania/filtrów), brak `x-html`/`innerHTML` w aplikacji, screenshoty poza katalogiem publicznym z kontrolą członkostwa, tokeny ClickUp szyfrowane (libsodium), klucz Apollo tylko w nagłówku i nigdy w odpowiedziach/logach, brak uploadów, brak `eval`/`unserialize`/powłoki.

### Architektura, kolejki, migracje
| # | Waga | Problem | Status |
|---|---|---|---|
| A1 | Wysoka | **Migracje przy każdym żądaniu:** błąd migracji powtarzany przy każdym żądaniu (DDL + zalew logów), blokada w opcji nieatomowa (dwa procesy mogły migrować równolegle), wersja czytana z cache żądania, `wp ubr install` po cichu po migracji wykonanej na `init`. Teraz: blokada `GET_LOCK`, odczyt wersji z bazy po blokadzie, wstrzymanie ponowień na 10 min po błędzie + komunikat w panelu WP, `wp ubr migrate` (kod wyjścia ≠ 0 przy błędzie, `--status`), stała `UBR_AUTO_MIGRATE=false` dla produkcji, w WP-CLI migracje tylko przez `wp ubr migrate/install`. | FIXED (P7-14) |
| A2 | Wysoka | Globalne blokady kolejek (sloty analizy/decydentów, ClickUp, Lead Intelligence) na opcjach — `add_option` w WP to upsert, przejęcie nieatomowe → możliwe 2 przeglądarki naraz. Teraz `GET_LOCK` (atomowe, zwalniane po śmierci procesu). | FIXED (P7-13) |
| A3 | Wysoka | Zadania przerwane twardo (timeout PHP, OOM, restart) podejmowane w nieskończoność (analiza blokowała jedyny slot); **ClickUp mógł utworzyć duplikat zadania** po przerwaniu między POST a zapisem wyniku. Teraz pozycje z wyczerpanym limitem prób → `failed`; eksport „running” na początku próby → UNKNOWN (bez ponownego POST). | FIXED (P7-12, testy P7 w pipeline-clickup) |
| A4 | Średnia | Lead Intelligence: firma, której ocena zawsze kończy się błędem, blokowała początek kolejki. | FIXED (P7-15) |
| A5 | Średnia | Kolejka weryfikacji bez zapasowego zdarzenia WP-Cron (przy zablokowanym loopbacku stała do kolejnego zlecenia); brak polecenia CLI dla wszystkich kolejek. Teraz zapasowy cron + `wp ubr queue-work --all`. | FIXED |
| A6 | Średnia | Autoryzacja REST łapała tylko `UbrException` — błąd bazy (np. w oknie migracji) kończył się fatal error / stack trace. | FIXED (P7-18) |
| A7 | Średnia | Treść wyjątków bazy (nazwy tabel, „Duplicate entry…”) zapisywana w zdarzeniach wyszukiwania i pokazywana użytkownikowi. | FIXED |
| A8 | Średnia | Dashboard pobierał przy każdym wejściu także `/dashboard` dla zwiniętej sekcji. Teraz dopiero po rozwinięciu. | FIXED |
| A9 | Średnia | `DELETE /prospects/{id}` nie odświeżał liczników Action Center/pipeline ani Lead Intelligence (inna ścieżka niż „Archiwizuj”). Teraz jedna ścieżka. | FIXED (P7-10) |
| A10 | Niska | Znacznik „wstępny” na Dashboardzie nigdy się nie pokazywał (`'provisional'` vs `'PROVISIONAL'`). | FIXED |
| A11 | Średnia | Każde zlecenie workera to żądanie HTTP z limitem 0,5 s — zmiana statusu prospektu czekała ~1 s (dwa zlecenia). Teraz zlecenia deduplikowane i wysyłane po wysłaniu odpowiedzi (`fastcgi_finish_request` / `litespeed_finish_request`). | FIXED (P7-21) |
| A12 | Średnia | Timeout analizy: `SIGKILL` dla Node zostawiał osierocone procesy Chromium. Teraz `SIGTERM`, po 3 s `SIGKILL`. | FIXED |
| A13 | Średnia | Licznik kolejki Lead Intelligence (co minutę) — pełny UNION wszystkich wyników i prospektów. Przepisany na zapytania z `LIMIT`/`NOT EXISTS`; zmierzony czas przy 10 000 firm ~25 ms (bez istotnej różnicy dla pustej kolejki). | CZĘŚCIOWO — przy większej skali potrzebna flaga „do oceny” (M010) |
| A14 | Niska | Brak retencji tabel logów (`ubr_api_requests`, zdarzenia, analizy z LONGTEXT, historia ocen). | NIE NAPRAWIONE — rekomendacja |
| A15 | Niska | Niespójna definicja „firma widoczna dla organizacji” (archiwalni prospekci liczeni w jednym miejscu, w innym nie). | NIE NAPRAWIONE — udokumentowane |
| A16 | Niska | `composer.json` nie deklaruje rozszerzeń `ext-mbstring`, `ext-dom`, `ext-sodium`. | NIE NAPRAWIONE (zmiana wymaga aktualizacji lock) — w DEPLOYMENT.md |

### Logika biznesowa
| # | Waga | Problem | Status |
|---|---|---|---|
| D1 | Średnio-wysoka | Ręcznie dodana osoba mogła dostać adres `biuro@` jako „służbowy e-mail osoby” (liczony w Contact Priority, eksportowany do ClickUp). Teraz odrzucany z komunikatem. | FIXED (P7-5) |
| D2 | Średnia | Ekstraktor dopasowywał nazwisko jako podciąg (≥ 2 litery): „sekretariat@” → adres Jana Kreta, „marketing@” → Anny Ma. Teraz blokada adresów ogólnych/działów i min. 3 litery. | FIXED (P7-5) |
| D3 | Średnia | Ponowne zlecenie analizy (lub nieudana ponowna analiza) usuwało firmę z Ready to Contact i dodawało do Missing Analysis, mimo zakończonej wcześniej analizy. Teraz „przeanalizowana” = istnieje zakończona analiza (`analyzed_at`). | FIXED (P7-6) |
| D4 | Średnia | Zmiana kwalifikacji na „not a fit” (hard blocker) nie przeliczała Lead Intelligence. | FIXED (P7-9) |
| D5 | Średnio-niska | Usunięty członek zostawał właścicielem prospektów / osobą przypisaną do follow-upów (znikały z „Unassigned”), jego reguły ClickUp działały dalej. | FIXED (P7-11) |
| D6 | Niska | KPI Total Prospects ignorował przełącznik My Work / Unassigned. | FIXED (P7-7) |
| D7 | Niska | Świeżość analizy (> 180 dni), kara w rankingu i coverage zależą od czasu, a ocena przeliczała się tylko przy zdarzeniach. Teraz raz na dobę oceny starsze niż 7 dni są przeliczane. | FIXED (P7-15) |
| D8 | Niska | NIP `0000000000` / REGON `000000000` (wypełniacze szablonów) przechodziły sumę kontrolną. | FIXED (P7-16) |
| D9 | Niska | Drill-down z kafelka KPI zachowywał filtry poprzedniej listy (suma ≠ liczba na kafelku). | FIXED |
| D10 | Średnia (wykryte w E2E) | **Strefa czasowa formularzy:** wartość `datetime-local` (czas przeglądarki) interpretowana w strefie WordPressa. Przy innej strefie (np. WP w UTC, użytkownik w Warszawie) aktywność „teraz” była odrzucana jako „w przyszłości”, a terminy follow-upów przesuwały się o różnicę stref. Teraz wysyłane jako ISO z offsetem. | FIXED (datetime.test.mjs, E2E 5) |
| D11 | Niska | Pusta notatka oceny ręcznej zapisywana jako `''` zamiast NULL. | FIXED (P7-17) |
| D12 | Średnia | Firma z wykonanym researchem bez znalezionych osób dostaje niższy Lead Potential niż firma bez researchu (składnik kontaktów liczony jako zmierzone 0). Zgodne z walidacją z etapu 5, ale niespójne z zasadą „brak decydenta ≠ niski potencjał”. | NIE ZMIENIONE — zmiana algorytmu wymaga decyzji właściciela |
| D13 | Niska | Contact Priority: wielkość firmy z `employees_max` (zakres Apollo 11–50 → „średnia”). | NIE ZMIENIONE — scoring |
| D14 | Niska | Pierwsze ponowienie weryfikacji po błędzie sieci po 5 min, dokumentacja mówi o 2 min. | NIE ZMIENIONE — rozbieżność dokumentacji |
| D15 | Niska | Dzienny limit ClickUp liczony od północy UTC, a nie w strefie WordPressa. | NIE ZMIENIONE (bez zmian ClickUp w tym etapie) |
| D16 | Info | „Maks. możliwe Opportunity” dla firmy nieaktywnej ignoruje limit 10 (tylko prezentacja). | NIE ZMIENIONE |
| U1 | Niska | Stopka karty firmy przy 390 px — przyciski poza ekranem. | FIXED |

Odłożone działania pozostają w kolejkach i KPI (oznaczone), a znikają tylko z Today's Priorities — zachowanie spójne, do potwierdzenia przez właściciela.

## 4. Wydajność (zmierzone)

Izolowany test obciążeniowy w kontenerze (`ubrperf`): 10 000 firm, 2 000 prospektów, 10 000 ocen Lead Intelligence; dane usunięte po teście. Czas = najlepszy z 3 pomiarów, cache Action Center wyczyszczony przed każdym.

| Endpoint | Czas | Zapytania SQL |
|---|---|---|
| `GET /action-center` (bez cache) | 304 ms | 19 |
| `GET /action-center/priorities?page=200` | 286 ms | 11 |
| `GET /action-center/queues/top_opportunities` | 79 ms | 2 |
| `GET /action-center/queues/needs_attention` (filtr regionu) | 84 ms | 2 |
| `GET /discovery/searches/{id}/results` (Smart Ranking) | 108 ms | 4 |
| `… results` (min_lead + confidence) | 127 ms | 4 |
| `… results` (strona 100) | 103 ms | 4 |
| `GET /prospects` | 47 ms | 5 |
| `GET /prospects` (sort lead, strona 20) | 57 ms | 5 |
| `GET /pipeline/board` | 39 ms | 22 (jedno zapytanie na kolumnę statusu — ograniczone) |
| `GET /companies/{id}` | 2 ms | 9 |
| licznik kolejki Lead Intelligence (co minutę) | 25 ms | 4 |

Brak N+1 (liczba zapytań stała względem liczby wierszy), paginacja i filtry w SQL, indeksy na kolumnach WHERE/ORDER BY ścieżek krytycznych (EXPLAIN), cache liczników wersjonowany (60 s). Pomiary LocalWP — §6.

## 5. Migracje

M001–M009: kolejność poprawna, każda idempotentna (`CREATE TABLE IF NOT EXISTS`, sprawdzanie kolumn/indeksów przed `ALTER`), schemat w bazie zgodny z kodem (indeksy i klucze obce), test ponownego uruchomienia wszystkich migracji od v0 przechodzi. Uwagi:
- M003 zawiera korektę danych — ręczne cofnięcie `ubr_db_version` poniżej 3 uruchomiłoby ją ponownie (rollback w DEPLOYMENT.md cofa najwyżej do v7);
- usunięcie wiersza organizacji kaskadowo usuwa jej dane (brak takiej ścieżki w aplikacji);
- `contact_reviews`, `clickup_jobs`, `contact_suppressions` bez kluczy obcych do organizacji (porządkowane w kodzie).

Nowa migracja nie była potrzebna (M010 pozostaje wolna).

## 6. Testy na LocalWP

| Test | Status |
|---|---|
| Stan bazowy przed audytem (Chrome, tylko odczyt): `GET /action-center` 200, 224 ms; KPI 2 / 0 / 0 / 3 / 3 / 46; kolejki: Top 27, Needs Attention 60, Ready 0, Review 3, Research 15, Verify 0, Find DM 2, Missing 46, High 3, Blocked 1; coverage: pula 65, zweryfikowane 25, domena potwierdzona 35, przeanalizowane 3 | PASSED (liczby bez zmian względem raportu etapu 6) |
| Wgranie poprawek etapu 7 (paczka bez `key`, `*.sql`, `deploy/`; brak zmian lokalnych od ostatniego wdrożenia) | PASSED |
| Testy JS na komputerze z LocalWP: ssrf-ip 35/35, preview-url 19/19, datetime 5/5 (Europe/Warsaw) | PASSED |
| `GET /action-center` po wdrożeniu: 200, 90 ms; KPI i kolejki identyczne jak przed audytem; priorytety NozziTec 75, FLOWAIR 74, ZAMET 73 (Review website), FAMUR 65, PCO 65 (Brak analizy); coverage bez zmian (pula 65, przeanalizowane 3) | PASSED |
| Total Prospects z przełącznikiem: Team 2, Unassigned 2, My Work 0 (prospekci bez właściciela) | PASSED |
| Czasy: Prospects 74 ms, wyniki wyszukiwania #7 (Smart Ranking, 50) 56 ms, karta firmy 84 ms, Lead Intelligence 67 ms, `/dashboard` 66 ms | PASSED |
| Sekcja „Ostatnie wyszukiwania”: `/dashboard` nie jest pobierane przy wejściu, tylko po rozwinięciu | PASSED |
| Drill-down KPI „Missing Analysis” czyści filtry listy: lista 46 = kafelek 46 | PASSED |
| Dane rzeczywiste: 4 firmy z wynikami researchu — 0 adresów ogólnych zapisanych jako e-mail osoby, 7 linków zewnętrznych, wszystkie http/https; adresy office@/service@/sales@ NozziTec pokazane jako ogólne kanały firmy | PASSED |
| Discover (#7), Prospects, Pipeline, Settings, karta firmy (NozziTec): brak poziomego przewijania (okno 560 px), brak błędów konsoli; stopka karty firmy zawija przyciski w oknie | PASSED |
| Podgląd LIVE: zwalidowany adres firmy w sandboxie, tryb LIVE domyślny | PASSED |
| Fokus na „Zamknij” po otwarciu podglądu wywołanym skryptem w tle | NOT TESTED na LocalWP (karta Chrome bez fokusu okna); zweryfikowane w Playwright w kontenerze |
| Zapisy (zmiana statusu, follow-up, aktywność, ocena ręczna) | NOT TESTED na LocalWP — celowo, bez zmian danych biznesowych; E2E w bazie testowej kontenera |
| Szerokości 1920–390 px na LocalWP | NOT TESTED (okna Chrome nie da się poszerzyć ponad 560 px); zweryfikowane w Playwright w kontenerze |

## 7. Gotowość Hostinger i ryzyka

Szczegóły i procedura: `docs/DEPLOYMENT.md`. Nic nie było sprawdzane na serwerze — poniższe punkty to wymagania do weryfikacji (**NOT TESTED / BLOCKED**).

| Obszar | Wymaganie | Ryzyko na Hostingerze |
|---|---|---|
| PHP | ≥ 8.2, `mbstring`, `dom`, `curl`, `sodium` (ClickUp), `json` | niskie |
| Composer | `vendor/` w repozytorium (bez `composer install` na serwerze) — sprawdzić | niskie |
| Assety | `public/build` w repozytorium (`npm run build` lokalnie) | niskie |
| Website Analyzer | `proc_open`, Node ≥ 18, `tools/site-capture/node_modules` (`npm ci`), Chrome/Chromium | **wysokie** — hosting współdzielony/Cloud zwykle nie pozwala uruchamiać Chromium; bez tego analiza kończy się PARTIAL (HTML bez screenshotów i metryk renderu). Opcje: VPS, osobny serwer renderujący, akceptacja PARTIAL |
| Workery | loopback HTTP do samego siebie | średnie — jeśli zablokowany: `DISABLE_WP_CRON` + cron systemowy co minutę (`wp cron event run --due-now` i `wp ubr queue-work --all`) |
| Migracje | `wp ubr migrate` przy wdrożeniu, `UBR_AUTO_MIGRATE=false` | niskie po zmianach z tego etapu |
| Screenshoty | `UBR_PRIVATE_DIR` poza `public_html` (domyślny katalog zapasowy chroniony `.htaccess` — działa na LiteSpeed/Apache) | średnie |
| Sekrety | stałe w `wp-config.php` (ClickUp, klucz szyfrowania, Apollo, CEIDG/GUS) | niskie |
| HTTPS | wymagane (redirect URI ClickUp, cookies) | niskie |
| Klucz SSH | nadal w historii Git | **wysokie** do czasu unieważnienia |

## 8. Checklista produkcyjna

1. Unieważnij klucz SSH z historii repozytorium (GitHub → Deploy keys / SSH keys, Hostinger → SSH Access), wygeneruj nowy poza repozytorium, rozważ przepisanie historii (`git filter-repo`) — `docs/SECURITY-KEY.md`. Commit usunięcia `key`/`key.pub`.
2. Kopia bazy i plików w panelu Hostingera.
3. Lokalnie: `npm ci && npm run build`, testy PHP i JS (wszystkie PASSED).
4. Na serwerze: sprawdź wersję PHP i rozszerzenia (`php -m`), `wp ubr doctor`, `wp ubr migrate --status`.
5. W `wp-config.php` (właściciel): `define('UBR_AUTO_MIGRATE', false);`, `UBR_PRIVATE_DIR` poza `public_html`, sekrety integracji.
6. Wdrożenie kodu → `wp ubr migrate` (oczekiwane `schemat v9`).
7. Cron systemowy co minutę: `wp cron event run --due-now` i `wp ubr queue-work --all`; `define('DISABLE_WP_CRON', true);`.
8. Website Analyzer: `wp ubr doctor` / Settings → status analizatora; jeśli brak Node/Chromium — decyzja: VPS, zewnętrzny renderer albo tryb PARTIAL.
9. Testy po wdrożeniu bez płatnych zapytań (DEPLOYMENT.md).
10. Plan retencji logów (A14) i decyzje D12/D13 (scoring) — przed szerszym użyciem.

## 9. Testy wymagające środowiska serwerowego (NOT TESTED)

- dostępność `proc_open`, Node i Chromium na Hostingerze, realny czas analizy i zużycie pamięci;
- działanie loopbacku HTTP (workery) i WP-Cron, cron systemowy;
- `fastcgi_finish_request` / `litespeed_finish_request` (odroczone zlecenia) na serwerze LiteSpeed;
- `GET_LOCK` na bazie Hostingera (MySQL/MariaDB — standardowo dostępne);
- ochrona katalogu screenshotów (`.htaccess` lub `UBR_PRIVATE_DIR`);
- HTTPS i redirect URI ClickUp; pierwszy kontrolowany eksport ClickUp na prawdziwym koncie;
- wydajność na prawdziwym wolumenie danych produkcyjnych;
- kopie zapasowe i odtworzenie (rollback) w panelu Hostingera.

## 10. ClickUp — lista do testu na żywo (bez wykonania w tym etapie)

Implementacja (audyt kodu, mocki): OAuth z jednorazowym `state` powiązanym z użytkownikiem i organizacją, token szyfrowany libsodium, brak odświeżania (tokeny ClickUp nie wygasają; 401 → „Reconnect required”), throttling 650 ms, 429 z `Retry-After` / `X-RateLimit-Reset`, timeout/5xx po POST → UNKNOWN, mapowanie pól i sekcja zarządzana z hashem. Do sprawdzenia na prawdziwym koncie:
1. wymiana kodu: `client_id/secret/code` jako JSON w body (dokumentacja ClickUp pokazuje parametry zapytania);
2. 401 z kodem `OAUTH_*` dla jednego workspace oznacza całe połączenie do ponownej autoryzacji — sprawdzić rzeczywistą odpowiedź;
3. `POST /list/{id}/task` zwraca `url`, `markdown_content` renderuje się poprawnie;
4. `GET /task/{id}?include_markdown_description=true` zwraca znaczniki sekcji (hash);
5. formaty wartości pól własnych (dropdown — ID opcji, liczba, URL);
6. `X-RateLimit-Reset` w sekundach epoki;
7. odmowa dostępu → `?error=` + `state` → komunikat „denied”;
8. odebranie dostępu w ClickUp → 401 → „Reconnect required”;
9. przerwanie workera w trakcie eksportu → UNKNOWN (nie duplikat) — zachowanie potwierdzone testem z mockiem;
10. redirect URI HTTPS na produkcji; dwóch użytkowników eksportujących jednocześnie (throttling).

## 11. Końcowa ocena

**READY WITH CONDITIONS.**

Uzasadnienie: wszystkie testy automatyczne, integracyjne (mocki), E2E i wizualne w kontenerze przechodzą (836/836 PHP, 59/59 JS, 230 kontroli Playwright); wykryte błędy wysokiej i średniej wagi w kodzie zostały naprawione z testami regresyjnymi; izolacja organizacji i autoryzacja zweryfikowane dla wszystkich tras; wydajność przy 10 000 firm w akceptowalnych granicach. Warunki przed pracą produkcyjną (etap 7 jest wgrany i sprawdzony na LocalWP):
1. unieważnienie klucza SSH z historii Git (ryzyko wysokie, poza kodem);
2. weryfikacja wymagań serwera Hostinger (PHP + rozszerzenia, WP-CLI, cron) i konfiguracja z `DEPLOYMENT.md` (`UBR_AUTO_MIGRATE=false`, `UBR_PRIVATE_DIR`, cron systemowy);
3. świadoma decyzja o Website Analyzerze na Hostingerze (bez Node/Chromium analizy będą PARTIAL — bez screenshotów).
