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

## 11. Prompt 10 — Decision Maker Finder 2.0 + Phone Intelligence + Cold Calling Workspace (10.10.2026)

Środowisko testów: **kontener** (izolowana baza testowa; dane fikcyjne tylko tam). LocalWP: bez instalacji (prompt wyklucza wdrożenie) — **NOT TESTED**. Hostinger: nic nie wykonywano. Apollo: wyłącznie mocki — zapytania do prawdziwego API **NOT VERIFIED**.

### Wyniki testów
| Zestaw | Wynik | Status |
|---|---|---|
| PHP `tests/run.php` (pełna regresja) | **1012/1012** (po backfillu; 986 w pierwszej wersji etapu) (+150 w `tests/calling-p10.php`; 2 asercje w `discover-ui.php` dostosowane do wspólnych szybkich akcji) | PASSED |
| JS `tests/js/calling.test.mjs` (nowy: `tel:`, schowek, filtry) | 18/18 | PASSED |
| JS preview-url / ssrf-ip / datetime | 19/19 · 35/35 · 5/5 | PASSED |
| Playwright P10 (Discover, Prospects, karta firmy) | **71/71** | PASSED (kontener) |
| Test obciążeniowy 10 000 firm (P10) | listy 33–112 ms, filtry telefonów / gotowości 165–220 ms, „Gotowe do telefonu” 258 ms, Action Center bez cache ~390 ms (P7: ~300 ms) | PASSED / uwaga |

### Zakres testów `calling-p10.php`
Normalizacja (13 przypadków, w tym 9 fałszywych numerów), ekstrakcja ze strony (tel:, Schema.org, oddziały, typy, stopka, faks, deduplikacja), numer główny i numer zagraniczny, numery ręczne (prywatność między organizacjami, brak nadpisania, IDOR, 403 / 404), Apollo People Search na mocku (stanowiska EN + PL, filtr domeny, firma o podobnej nazwie odrzucona, zamaskowane nazwisko, Dyrektor Produkcji, identyfikator osoby, 0 kredytów), Phone Enrichment na mocku (wyłączone domyślnie, uprawnienia, brak identyfikatora, nieznany koszt, brak zapytań przed potwierdzeniem, token, podwójne kliknięcie, `result_pending`, wynik z kredytami, numer innej osoby pominięty, centrala jako numer firmy, e-mail niezapisany, izolacja organizacji, UNAVAILABLE, ERROR bez ponowienia, przerwane zlecenie, limit dzienny, brak LOCKED / AVAILABLE bez numeru), gotowość PHP = SQL dla 8 przypadków, wielkość firmy, niezmienność danych wejściowych Lead Potential, przejęcie / przekazanie / archiwum / wyścig (drugie połączenie z bazą) / izolacja, wynik rozmowy i historia (etapy, cudza firma, DNC, BLOCKED, brak równoległej historii), listy (dane wiersza, akcje, 10 filtrów, 5 sortowań, filtr na całym zbiorze), Action Center (kolejka, DNC, cudzy opiekun, licznik, KPI bez zmian), bezpieczeństwo (klucz API w odpowiedziach, 403 / 404 między organizacjami, XSS w notatce, SQL injection w filtrach).

### Wykryte w trakcie i poprawione
| # | Problem | Status |
|---|---|---|
| P10-a | Podzapytania gotowości w zapytaniu listy liczone dla całego zbioru przed sortowaniem (+90 ms przy 10 000 firm) → kolumny Cold Calling liczone osobnym zapytaniem tylko dla strony | FIXED (test obciążeniowy) |
| P10-b | Przy zmianie widoku bazowego Action Center przesunięcie parametrów w zapytaniu follow-upów (regresja wykryta testami 28–55) | FIXED |
| P10-c | Przycisk „Przejmij” nieaktywny (`disabled` z wartością `undefined`) | FIXED (Playwright) |
| P10-d | Dwa znaczniki „główny” w karcie firmy (ręczny i ze strony) | FIXED |
| P10-e | Osoba odrzucona przez organizację mogła dawać READY_DIRECT przez numer pobrany | FIXED |
| P10-g | **M010 nie przechodziła na MySQL 8.4 (LocalWP):** kolumna `manual` to słowo zastrzeżone w MySQL 8 (MariaDB w kontenerze je przyjmuje). Po nieudanej migracji nowy kod odwoływał się do brakujących tabel → listy i Action Center zwracały 500 przez ok. 4 min. Naprawa: nazwa kolumny w cudzysłowie (`` `manual` ``) w migracji i zapytaniach; migracja uruchomiona ponownie (odpowiednik `wp ubr migrate`) — schemat v10, bez błędów. | FIXED (LocalWP) |
| P10-h | Na LocalWP znacznik „Duże przedsiębiorstwo (1000+ os.)” przy kolumnie „Wielkość: Brak danych” — znacznik wynikał z przychodu z Apollo (≥ 250 mln), nie z zatrudnienia. Etykieta poprawiona na „1000+ os. lub przychód ≥ 250 mln wg źródła”; wielkość nadal „Brak danych” (bez zgadywania liczby pracowników) | FIXED |
| P10-i | Komórka Decydent: „Cold call możliwy przez numer firmy” także przy braku numeru; status „Nie wiadomo” → „Numer nieznany” | FIXED |
| P10-j | Backfill (pilot 5 firm, LocalWP): komórki osób (handlowcy, kierownicy) brane za numer firmy; „Recepcja” jako serwis (podpowiedź JSON-LD wygrywała z etykietą); etykiety w nagłówkach i w treści linku `tel:` pomijane; tekst sklejany przez `<br>` („Kowalczyktel.”); ogólne „biuro” (Biuro Projektowe) jako centrala → klasyfikacja z etykiety tuż przed numerem (stanowiska, imię i nazwisko, działy: kadry, magazyn, zakupy…), etykieta strony ma pierwszeństwo przed JSON-LD, teksty węzłów rozdzielane spacją | FIXED (LocalWP, testy P10-2b) |
| P10-k | Strona grupy (Alchemia): numer spółki zależnej (Huta Bankowa) jako główny → spółka z formą prawną w etykiecie ≠ firma → BRANCH „inna spółka: …”, nigdy numer główny | FIXED |
| P10-l | Tekst linku ≠ numer w `tel:` (ZAMET) → pewność „low” i dopisek „[tekst linku: …]” — do ręcznej weryfikacji | FIXED |
| P10-m | Ponowne odświeżenie zostawiało stare, błędne typy → `replaceExtracted`: numery ze stron pobranych teraz zastępują wcześniejsze z tych samych adresów; numery ręczne bez zmian | FIXED (test ponownego uruchomienia) |
| P10-n | Odświeżenie ignorowało robots.txt → `RobotsPolicy` (strona Kontakt pomijana przy zakazie) | FIXED |
| P10-o | Odmiany „telefonu / telefonem”, samo „T 0048…” nierozpoznawane; adres „51 40-698” ucinał etykietę; „Engineering” w nazwie brane za stanowisko | FIXED (P10-2c) |
| P10-p | Numer zagraniczny lub innej spółki jako główny (SECO/WARWICK: numer z Indii, kraj nieznany w mapie prefiksów) → wykluczenie z numeru głównego także po prefiksie kierunkowym; „HEADQUARTER” (l. poj.) = centrala | FIXED (LocalWP: SECO → 68 382 05 00, Centrala) |
| P10-q | Strona Kontakt z listą krajów (ISCAR) → jedna dodatkowa strona; najpierw „Polska / Poland / centrala”, dopiero potem ogólne „contact-…” (wcześniej krótszy adres `/uae` wygrywał) | FIXED (LocalWP: ISCAR → 32 735 77 00) |
| P10-r | `<base href>` ignorowany przy linku względnym do Kontaktu (NG Engineering) | FIXED |
| P10-s | „Infolinia:” w stopce pod adresami oddziałów oznaczana jako oddział (Eko-Okna) → etykieta z tego samego akapitu ma pierwszeństwo; „oddział” tylko z etykiety lub bloku oddziału | FIXED |
| P10-t | Kolejka „Gotowe do telefonu” na Dashboardzie bez numeru — trzeba było otwierać kartę firmy → numer główny z linkiem `tel:` i kopiowaniem w wierszu (tabela i karty) | FIXED (LocalWP) |
| P10-f | Przy 1280 px kolumna Firma miała ~60 px (stałe szerokości odbierały jej miejsce), etykiety wychodziły poza kolumny → kolumny procentowe (Firma ≥ 160 px przy 1280 px), zawijanie nagłówków i etykiet w tabeli | FIXED (pomiar szerokości, zrzuty) |

### Test na żywo — LocalWP (11.10.2026, MySQL 8.4.0)
Przed instalacją: kopia całej bazy (`ubr-backups/pre-p10-20261011-034855.sql.gz`, 46 tabel, 2120 wierszy, poza katalogiem publicznym), porównanie sum kontrolnych plików motywu (brak zmian na Macu poza plikami paczki). Instalacja paczki + poprawka P10-g, migracja M010 → schemat v10, „ostatni błąd migracji: brak”. Bez zapisów danych biznesowych, bez Apollo, bez nowych analiz; skrypty tymczasowe (kopia, migracja, inspekcja tylko do odczytu) usunięte.

| Obszar | Wynik | Status |
|---|---|---|
| Discover (10 wyszukiwań, 65 firm) i Prospects | 200, 49–66 ms; dane Cold Calling dla 65/65 firm; kolumny zgodne ze specyfikacją | PASSED |
| Telefony firm / decydentów | 0 numerów w danych (analizy i research sprzed etapu 10 nie przechowują HTML ani numerów; 9 kontaktów bez telefonu) — listy poprawnie pokazują „brak numeru” | PASSED (brak danych) |
| Wielkość firm | 0/65 firm ma dane o zatrudnieniu (Apollo / KRS / VAT ich nie dostarczyły) → „Brak danych” | PASSED (brak danych) |
| Przypisanie | 2 prospekty bez opiekuna (zapisane przed etapem 4) → „Wolna” + „Przejmij”; filtr „wolne” = 50/50 | PASSED |
| Podgląd na liście | przycisk „Podgląd” widoczny w kolumnie Strona WWW, otwiera wspólny modal (FAMUR) | PASSED |
| Filtry | gotowość: 18 + 31 + 1 = 50 (zgodne z rozkładem z listy); telefon: any 0 / none 50; wielkość unknown 50; opiekun free 50 / mine 0; etap „Nowy” 2; analiza 3; lokalizacja „śląskie” 6; sortowania 200 | PASSED |
| Dashboard / KPI | KPI identyczne jak przed instalacją (2 / 0 / 0 / 3 / 3 / 46), kolejki bez zmian + „Gotowe do telefonu” 0; priorytety NozziTec, FLOWAIR, ZAMET, FAMUR, PCO (bez zmian); 81 ms | PASSED |
| Responsywność | 5 ekranów (Discover, Prospects, Dashboard, Pipeline, karta firmy) × 1920 / 1440 / 1280 / 768 / 390 px: brak poziomego przewijania, tabela ≥ 1280, karty < 1280, brak błędów JS | PASSED |
| Karta firmy | sekcja „Telefony firmy” („strony jeszcze nie sprawdzano”), sekcja „Kontakt telefoniczny” w Sprzedaży | PASSED |
| „Odblokuj telefon” | wyłączone (`disabled`, brak tokenu, brak przycisku na listach) | PASSED |
| Strona główna WordPressa | 200 (zalogowany i anonimowy), bez błędu krytycznego. Błąd 500 występuje tylko w kontenerze (`sage.view` — środowisko kontenera). W logu LocalWP stare ostrzeżenie (od 09.10) w `sections/header.blade.php`: brak logo w ustawieniach motywu (`$logo` = null) — niezwiązane z etapem 10 | PASSED / uwaga |
| Testy JS na komputerze z LocalWP | 18/18, 19/19, 35/35, 5/5 | PASSED |

**Odzyskanie numerów bez crawlowania — niemożliwe:** 12 zapisanych analiz przechowuje wyniki pomiarów (`checks`, `pages`, `render`…), bez HTML i bez linków `tel:`; 10 researchy decydentów bez numerów; dowody źródeł (Apollo, KRS, VAT, Wikidata) bez pól telefonu; brak pamięci podręcznej odpowiedzi Apollo. **Sposób uzupełnienia (bezpłatny, bez analizy i bez Apollo):** `wp ubr phones-backfill --org=<slug> --limit=50` (domyślnie tylko lista kandydatów) → po akceptacji `… --execute` (2 zapytania HTTP na firmę: strona główna + Kontakt, ochrona SSRF, przerwa 1 s) albo pojedynczo przycisk „Odśwież ze strony” w karcie firmy. Kandydaci na LocalWP: firmy z domeną, niesprawdzone (do 50). Nie uruchomiono — wymaga zgody.

**Nie sprawdzono na żywo (bez zapisów w bazie użytkownika):** przejęcie firmy, wynik rozmowy, zakaz kontaktu, numer ręczny, odświeżenie ze strony, przekazanie — przetestowane w kontenerze (PHP + Playwright). Wyścig przejęcia, Apollo (prawdziwe API) — NOT VERIFIED na LocalWP.

### Company Phone Backfill — LocalWP (11.10.2026)
Za zgodą właściciela: istniejący mechanizm odświeżania (`POST /companies/{id}/phones/refresh` = `wp ubr phones-backfill --execute`: strona główna + Kontakt, maks. 1 dodatkowa podstrona, robots.txt, ochrona SSRF, blokada 10 min na firmę). Bez Website Analyzera, bez ponownego wyszukiwania firm, bez Apollo. Pilot na 5 firmach (Alchemia, Metal Expert, GGG, ZAMET, FAMUR) → poprawki P10-j…l → wszystkie 50 firm z domeną (3 przebiegi + ponowienia SECO, ISCAR, Eko-Okna po poprawkach P10-p…s).

| Wskaźnik | Wynik |
|---|---|
| Sprawdzone firmy (z domeną) | 50 |
| Firmy z numerem | **39 (78%)**; z numerem głównym 39; z numerem oznaczonym jako centrala 19 |
| Numery łącznie | 230 (200 krajowych, 30 zagranicznych — głównie oddziały SECO/WARWICK na świecie) |
| Typy | centrala 25 · sprzedaż 9 · serwis 25 · dział / osoba 73 · oddział 25 · nieokreślony 73 |
| Do ręcznej weryfikacji | 20 numerów głównych bez etykiety „centrala” lub komórkowych; 2 numery „low” (ZAMET: tekst linku ≠ `tel:`) |
| Duplikaty / brak źródła | 0 / 0 (każdy numer ze stroną źródłową) |
| Ponowne uruchomienie | bez duplikatów; testowy numer ręczny (GGG, zweryfikowany z ggg.pl/kontakt) zachowany i użyty jako główny, następnie usunięty |
| Firmy bez numeru (11) | błędna / zagraniczna domena (erg.eu, jukieurope.com), domena innego podmiotu (Factories — celowo pominięta), domena zaparkowana (ARM Robotics), portale / stowarzyszenia bez telefonu (Polski Przemysł, Przemysł 4.0, Federacja ZZMK), brak numeru na stronie (CoRobotics, SALVAMAC), brak linku do Kontaktu (SciTeeX), adres zablokowany przez ochronę SSRF (KSB) |

| Sprawdzenie na LocalWP | Wynik | Status |
|---|---|---|
| Discover (wyszukiwanie 7, 50 firm) | 28 firm z numerem; `tel:+48…` poprawne (0 błędnych), kopiowanie → E.164 (`+48323596764`) | PASSED |
| Prospects | 2/2 firm z numerem, tel: i kopiowanie | PASSED |
| Filtry telefonu (API i UI) | „Numer firmy” 28 = „Jakikolwiek numer” 28 (brak numerów decydentów), „Bez numeru” 22, 28 + 22 = 50; wyszukiwania 6 i 8 spójne | PASSED |
| Cold Call Readiness | 39 firm „Zadzwoń do firmy” (wcześniej 0) = firmy z numerem głównym | PASSED |
| „Gotowe do telefonu” | licznik 39 = lista 39 (te same ID), numer + tel: + kopiowanie w wierszu (P10-t) | PASSED |
| KPI | bez zmian (2 / 0 / 0 / 3 / 3 / 46), priorytety bez zmian | PASSED |
| Szerokości | Discover / Prospects 1440 / 1280 / 768 / 390, Dashboard 1920–390: brak poziomego przewijania, brak błędów JS | PASSED |
| Kliknięcie `tel:` bez zapisu rozmowy | nie klikano na LocalWP (otwiera aplikację telefonu w systemie) — test w kontenerze | NOT TESTED (LocalWP) |

### Ograniczenia i decyzje
- Apollo: People Search może wymagać klucza master; Phone Enrichment dostarcza numery asynchronicznie (webhook albo odpytywanie — aplikacja odpytuje); koszt zależy od planu → w UI „nieznany”. Wszystko na podstawie dokumentacji (10.2026), bez wykonania zapytania — NOT VERIFIED. Test do ręcznego zatwierdzenia: `wp ubr apollo-phone-test --org=<slug> --contact=<id>` (bez zapytania), `--execute --yes` (płatne), `--poll` (wynik, 0 kredytów).
- Status LOCKED nie jest przypisywany (API nie potwierdza go bez płatnego zapytania); bez numeru stan to UNKNOWN z możliwością odblokowania.
- Numery wewnętrzne: unikalność numeru w firmie po E.164 (dwa numery wewnętrzne pod tym samym numerem zapisują się jako jeden).
- Odświeżanie numerów: blokada 10 min na firmę, bez dziennego limitu organizacji (ręczne, pojedyncze firmy).
- Action Center bez cache ~390 ms przy 10 000 firm (liczenie gotowości dla licznika „Gotowe do telefonu”); wynik w cache 60 s.
- Publiczna strona główna WordPressa w kontenerze zwraca błąd 500 (`Class "sage.view" does not exist`); aplikacja `/app/` działa. Przyczyny nie badano (zmiany etapu 10 dotyczą tylko widoków `ubr/*`) — warto sprawdzić stronę główną na LocalWP po instalacji.

### Do wykonania przez właściciela
1. Zgoda na instalację paczki na LocalWP (uruchomi M010 — tylko nowe tabele / kolumny; kopia bazy LocalWP przed instalacją).
2. Decyzja o włączeniu `UBR_APOLLO_PHONE_ENABLED` i ewentualny jeden ręczny test płatny.
3. Bez zmian z etapu 7: unieważnienie klucza SSH, konfiguracja produkcyjna, Website Analyzer na Hostingerze.

## 12. Prompt 10.2 — Direct Decision Maker Phones (11.10.2026)

Środowiska: **kontener** (testy automatyczne, Playwright na danych testowych) i **LocalWP** (MySQL 8.4, dane rzeczywiste). Bez Apollo Phone Enrichment, bez płatnych zapytań, bez Git Push / Hostingera, bez ClickUp. Kopia bazy przed M011: `ubr-backups/pre-p102-20261011-081110.sql.gz` (37 tabel UBR + opcje `ubr_*`, 1990 wierszy).

### Wyniki testów
| Zestaw | Wynik | Status |
|---|---|---|
| PHP `tests/run.php` (pełna regresja) | **1082/1082** (+70 testów Prompt 10.2: 69 w nowym `tests/dm-phones-p102.php` i 1 w `calling-p10.php`; testy P10 i P7 dostosowane: READY_DIRECT wymaga powiązania z dowodem, `safeTel` w linkach) | PASSED |
| JS (kontener i komputer z LocalWP) | 18/18 · 19/19 · 35/35 · 5/5 | PASSED |
| Playwright P10.2 (kontener: Discover, Prospects, Dashboard × 1920 / 1440 / 1280 / 768 / 390, kolumny, tel:, kopiowanie, filtry, karta firmy — potwierdź / odrzuć / przypisz, kolejka „do decydenta”) | **55/55** | PASSED (kontener) |
| Playwright P10 (regresja) | **71/71** (Phone Enrichment na mocku w kontenerze — tymczasowy mu-plugin usunięty) | PASSED (kontener) |

### Wykryte w trakcie i poprawione
| # | Problem | Status |
|---|---|---|
| P102-a | Strona w innym kodowaniu niż UTF-8 (7 firm na LocalWP) → `preg_split` = false → błąd 500 w `PeopleExtractor::classify` (błąd istniejący, ujawniony przez nową analizę) → zabezpieczenie i `mb_scrub` | FIXED (LocalWP) |
| P102-b | Literówka na stronie Alchemii („Kornulik” obok 2× „Korniluk”) → dwa kontakty → strażnik podobnych nazwisk (to samo imię, różnica ≤ 2 znaki, najpierw częstsza pisownia) — bez łączenia i bez duplikatu; utworzony duplikat dezaktywowany (nie usunięty) | FIXED (LocalWP) |
| P102-c | Bloki osób w stopce („Michał Zipser – Sales Manager tel. …”) oznaczane jako numer ogólny → blok osoby w stopce = maks. LIKELY; stanowiska spoza katalogu ról rozpoznawane jako dowód struktury | FIXED |
| P102-d | Adresy e-mail osób w zapisanych fragmentach dowodów i opisach numerów → usuwane („[e-mail]”), także w danych zapisanych wcześniej w tej sesji na LocalWP | FIXED (LocalWP) |
| P102-e | 4 numery w jednym bloku osoby (prezes + sekretariat + dział + oddział) → UNCERTAIN; numery opisane jako centrala / dział / oddział nie liczą się do „wielu numerów” | FIXED |

### Walidacja na LocalWP (dane rzeczywiste)
Bez ponownego masowego backfillu: pobrane tylko strony źródłowe zapisanych numerów „dział / osoba” / sprzedaż / serwis (25 firm, maks. 3 na firmę) i maks. 2 podlinkowane podstrony zarząd / zespół / media — w końcowym przebiegu 40 stron (3 przebiegi z poprawkami; robots.txt, ochrona SSRF, blokada 10 min).

| Wskaźnik | Wynik |
|---|---|
| Przeanalizowane numery | 189 z 230 numerów firm sklasyfikowanych (strony ponownie pobrane); w tym **71 z 73** numerów „dział / osoba” (2 — strony źródłowe poza limitem) |
| 73 numery „dział / osoba” | numer osoby (PERSON_DIRECT) **40** · numer działu 6 · numer ogólny 9 · oddział 0 · właściciel nieustalony 16 · nie sprawdzono 2 |
| Osoby na stronach | 82 (z numerem: 51); powiązania osoba ↔ numer: potwierdzone 7, prawdopodobne 41, do weryfikacji 9, odrzucone 6 — wszystkie potwierdzone dotyczą osób spoza ról decydentów (rzeczniczka prasowa, kierownicy sprzedaży / magazynu / działu) |
| Decydenci na stronach | 8 osób z rolą decydenta: 7 nowych kontaktów ze źródłem (Alchemia: Prezes + 2 wiceprezesów; KGHM ZANAM: Prezes + 2 wiceprezesów; SECO/WARWICK: VP Marketing), 1 wariant z literówką (bez duplikatu); istniejący decydenci FAMUR / PCO — bez numerów na stronach |
| Telefon decydenta | **potwierdzony: 0 firm**; prawdopodobny: 1 (SECO/WARWICK — Katarzyna Sawka, VP Marketing, +48 512 811 648, blok „Or contact” na stronie Media Center; zgodność potwierdzona ręcznym odczytem strony, decyzja „Potwierdź” pozostawiona właścicielowi); odrzucony: 1 (KGHM ZANAM — wiceprezes przy numerze centrali) |
| Gotowość | „Gotowe do telefonu” 39 = bezpośrednio do decydenta 0 + przez centralę 39; READY_DIRECT 0 (bez potwierdzonego numeru); KPI bez zmian (2 / 0 / 0 / 3 / 3 / 46) |
| Filtry (wyszukiwanie z 50 firmami) | bezpośredni 1 + brak telefonu decydenta 49 = 50; potwierdzony 0; do weryfikacji 1; tylko telefon firmy 28; numer firmy 29; źródło „strona firmowa” 1; stanowisko „Dyrektor marketingu” 1 | 

| Sprawdzenie | Wynik | Status |
|---|---|---|
| Migracja M011 | schemat v11, tabela powiązań i kolumny właściciela numeru, brak błędów w logu | PASSED |
| Discover / Prospects | „Telefon decydenta: do weryfikacji” (SECO), numer decydenta złotym kolorem obok numeru firmy („firma · Centrala”), `tel:+48512811648`, kopiowanie → `+48512811648`; brak poziomego przewijania 1920–390 px; brak błędów JS | PASSED |
| Karta firmy (dane) | powiązanie ze statusem, źródłem, dowodem bez e-maili, datą pozyskania, przyciskami i listą 23 numerów do przypisania (sprawdzone w DOM / API; panel w karcie w tle przeglądarki nie zakończył animacji — wygląd sprawdzony w kontenerze) | PASSED (dane) / NOT TESTED (wygląd na LocalWP) |
| Dashboard | przełącznik „Bezpośrednio do decydenta (0) / Przez centralę firmy (39)”, 20 linków tel: na stronie listy, 1920–390 px bez przewijania | PASSED |
| Decyzje potwierdź / odrzuć / przypisz | nie wykonywane na danych właściciela (decyzje biznesowe) — testy PHP + Playwright w kontenerze | NOT TESTED (LocalWP) |
| Apollo People Search | jeden kontrolowany test (FAMUR): HTTP 200, 7 osób, nazwiska zamaskowane („Dr***z”), identyfikatory osób, `has_direct_phone` „Yes” / „Maybe”, bez e-maili i telefonów; zapis w dzienniku jako niepłatny (0 kredytów wg dokumentacji); nic nie zapisano | PASSED |
| Apollo Phone Enrichment | gotowy technicznie (Prompt 10), wyłączony; wymaga identyfikatora osoby Apollo — żaden kontakt na LocalWP go nie ma | BLOCKED (wymaga decyzji / identyfikatorów) |

### Ograniczenia
- Na stronach polskich firm przemysłowych numery osób to głównie handlowcy i kierownicy działów; zarząd rzadko publikuje bezpośrednie numery — stąd 0 potwierdzonych numerów decydentów.
- Wykrywanie stopki po klasach CSS bywa zbyt szerokie (SECO: blok „Or contact” w treści oznaczony jako stopka → LIKELY zamiast VERIFIED).
- Osoby bez stanowiska z katalogu ról nie są dodawane jako decydenci; ich numery opisane przy numerze firmy („Numer osoby: imię i nazwisko”).
- People Search zwraca zamaskowane nazwiska — kontaktów nie tworzymy; dopasowanie po imieniu i masce nazwiska (np. „Aleksandra Dr***z” = Aleksandra Drożdż, FAMUR) wymaga decyzji o regule.


## 13. Prompt 10.3 — Apollo Contact Matching & Phone Unlock (11.10.2026)

Środowiska: **kontener** (testy automatyczne na mockach Apollo, Playwright na danych testowych) i **LocalWP** (MySQL 8.4, dane rzeczywiste).
Bez płatnych zapytań Apollo, bez rzeczywistego Phone Enrichment, bez nowych zapytań People Search, bez Git Push / Hostingera / ClickUp.
Wyniki mockowane NIE potwierdzają działania rzeczywistego API.

### Audyt Apollo (dokumentacja docs.apollo.io, 10.2026 + jeden rzeczywisty test People Search z Prompt 10.2)
| Funkcja | W API | Na obecnym planie | Koszt | Zweryfikowana |
|---|---|---|---|---|
| People API Search (`POST /api/v1/mixed_people/api_search`) | tak | tak — klucz master (rzeczywisty test FAMUR: HTTP 200, 7 osób) | 0 kredytów wg dokumentacji | rzeczywiste API (1 zapytanie, Prompt 10.2); koszt — dokumentacja |
| Pełne dane osoby w wyniku wyszukiwania (pełne nazwisko, e-mail, telefon) | nie (wyszukiwanie nie zwraca e-maili ani numerów; nazwisko zamaskowane `last_name_obfuscated`) | nie | — | rzeczywiste API (FAMUR: 7/7 nazwisk zamaskowanych) |
| People Enrichment (`POST /api/v1/people/match`, po `id`) — pełne dane osoby | tak | niepotwierdzone (nie wywoływano) | 1–9 kredytów / osobę wg dokumentacji; naliczane tylko za znalezione dane | dokumentacja; mock |
| Phone Availability (sygnał przed odblokowaniem) | częściowo: pole `has_direct_phone` w wyniku wyszukiwania („Yes” / „Maybe: …”) — **pole nieudokumentowane** | tak (widoczne w rzeczywistej odpowiedzi) | 0 (część wyszukiwania) | rzeczywiste API (FAMUR: 5× „Yes”, 1× „Maybe”, 1× brak); znaczenie niepotwierdzone dokumentacją |
| Phone Enrichment (`reveal_phone_number=true`, asynchronicznie: webhook albo `poll_only` + `webhook_result`) | tak | niepotwierdzone (nie wywoływano) | wg dokumentacji +8 kredytów za numer komórkowy (łącznie do 9 / osobę); waterfall telefonów 8–25+ kredytów (nieużywany) | dokumentacja; mock |
| Saldo kredytów przez API | brak udokumentowanego endpointu salda; `usage_stats` (0 kredytów) zwraca limity zapytań | — | 0 | dokumentacja |

### Wyniki testów
| Zestaw | Wynik | Status |
|---|---|---|
| PHP `tests/run.php` (pełna regresja) | **1174/1174** (+92 testy w nowym `tests/apollo-matching-p103.php`; 1 test P10 dostosowany: koszt „wymaga potwierdzenia” ze stawkami z dokumentacji zamiast „bez liczby kredytów”) | PASSED (kontener) |
| JS (kontener) | 18/18 · 5/5 · 19/19 · 35/35 | PASSED |
| Playwright P10.3 (kontener: Discover / Prospects / Dashboard × 1920 / 1440 / 1280 / 768 / 390 bez zapytań Apollo, karta firmy — sekcja Apollo na każdej szerokości, maska, poziomy, dostępność, zatwierdź / odłącz, szczegóły, modal z powodem blokady) | **76/76** | PASSED (kontener) |
| Playwright P10.2 i P10 (regresja) | **55/55** · **71/71** (Phone Enrichment na mocku — tymczasowy mu-plugin usunięty) | PASSED (kontener) |

Pokrycie testów PHP (mocki): matching — pełne imię i nazwisko + firma (EXACT), maska (STRONG / POSSIBLE, nigdy EXACT), podobne nazwiska
(Nowak / Nowaczek), dwie osoby o tym samym nazwisku, podobna nazwa firmy z inną domeną, istniejący identyfikator Apollo, konflikt
identyfikatorów (409), odrzucone dopasowanie, ponowne wyszukiwanie bez nadpisania decyzji, brak duplikatów; API — sukces, dane zamaskowane,
brak osób, 403, 429, błąd sieci, brak informacji o telefonie, cache, blokada równoległa, limit dzienny, 24 h na firmę; Phone Unlock —
domyślna blokada, wywołanie REST z poprawnie podpisanym tokenem przy wyłączonej funkcji (422, 0 zapytań), obrona w głąb w `request()`,
brak podwójnego obciążenia, brak automatycznego wywołania (karta / listy / kandydaci = 0 zapytań), brak identyfikatora Apollo, numer już
zapisany, użytkownik bez uprawnień, „Nie kontaktować”, symulowany sukces (wynik tylko dla osoby ze zlecenia), symulowany błąd; IDOR / izolacja
organizacji, klucz API poza adresem i odpowiedzią, retencja kandydatów.

### Wykryte w trakcie i poprawione
| # | Problem | Status |
|---|---|---|
| P103-a | `applyResult` przy kontakcie bez identyfikatora przyjmowało numery dowolnej osoby z odpowiedzi → wynik przypisywany wyłącznie osobie z identyfikatora zapisanego w zleceniu (`phone_reveals.apollo_person_id`), bez identyfikatora — nic | FIXED |
| P103-b | Zapis kandydata: `has_email_signal = NULL` przekazywany jako pusty tekst do TINYINT (błąd w trybie ścisłym MySQL) | FIXED |
| P103-c | Klucze limitów wyszukiwania kolidowały z prefiksem pamięci podręcznej People Search (czyszczenie cache kasowało limit 24 h) → osobne klucze `ubr_apollo_search_*` | FIXED |

### Bezpieczeństwo
Uprawnienia: odczyt `contacts.view`, wyszukiwanie `contacts.run`, decyzje `contacts.manage`, odblokowanie `contacts.reveal` (owner / admin).
Izolacja: dopasowania z `organization_id`, firma przez `isVisibleTo` (IDOR → 404). CSRF: nonce REST WordPressa. XSS: dane z Apollo
wyłącznie przez `x-text`, notatki bez HTML. SQL: zapytania przygotowane. Limity: 24 h na firmę, dzienny limit organizacji, blokada
równoległa, limit tempa klienta HTTP. Klucz API tylko w nagłówku `x-api-key` (nie w adresie, logach, odpowiedziach ani frontendzie).
Identyfikatory Apollo nie trafiają do przeglądarki. Retencja: `wp ubr contacts purge --days=N`. Dane B2B; publiczna dostępność numeru
nie jest zgodą na kontakt marketingowy (status „Nie kontaktować” blokuje pobieranie numeru).

### Walidacja na LocalWP
<!-- P103-LOCALWP -->
**BLOCKED (11.10.2026):** strona `uglybutirch.local` nie odpowiadała w trakcie etapu (aplikacja Local zatrzymana / komputer uśpiony).
Kontrola rozbieżności wykonana: wszystkie pliki motywu na LocalWP poza 20 zmienianymi w tym etapie są identyczne z kontenerem
(537 sum kontrolnych), zmieniane pliki nie były edytowane po wdrożeniu 10.2. Paczka wdrożeniowa i skrypty (kopia bazy, walidacja z cache bez
zapytań do Apollo) przygotowane; wdrożenie i migracja M012 czekają na uruchomienie strony.
