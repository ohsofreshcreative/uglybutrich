# Ugly but Rich — postęp prac

Stan na: 2026-10-09 (etap 1.2). Dokument opisuje wyłącznie to, co jest zaimplementowane i sprawdzone.

## Etap 1 — Foundation + SaaS + Discovery

**Zrobione**
- Moduł aplikacji w motywie Sage 11 (`app/Ubr`, provider `UbrServiceProvider`), bez pluginu.
- Tabele `ubr_*` + wersjonowane, idempotentne migracje (M001, M002), automatyczna inicjalizacja organizacji OhSoFresh.
- Multi-tenancy: organizacje, członkostwa, role Owner/Admin/Member, aktywna organizacja, izolacja danych w każdym zapytaniu.
- REST API `ubr/v1` z autoryzacją po stronie serwera (nonce WP = CSRF).
- Discovery: zadania w tle, deduplikacja, historia pochodzenia danych, weryfikacja stron WWW.
- Panel `/app`: Dashboard, Discover, Prospects (szukaj, filtruj, sortuj, paginuj, usuń), Settings.
- WP-CLI `wp ubr …`, testy `wp ubr test`.

**Rzeczywiste dane (LocalWP, przed naprawą kolejki):** wyszukiwanie #2 „Konstrukcje stalowe i obróbka metali, mazowieckie, 50–249” zwróciło z Wikidata 6 prawdziwych firm z potwierdzonymi, działającymi domenami (m.in. Mennica Polska — mennica.com.pl, Alchemia SA — alchemiasa.pl, GGG Sp. z o.o. — ggg.pl, Metal Expert — metalexpert.pl). OpenStreetMap: timeout Overpass (zapytanie było zbyt ciężkie — poprawione w 1.1). Cel 20 firm z domenami **nie został jeszcze osiągnięty** w teście na żywo.

## Etap 1.1 — naprawa kolejki Discovery + Apollo

**Przyczyna zawieszania się zadań („W kolejce”, 0%)** — patrz `docs/ARCHITECTURE.md` §7: 2 procesy PHP-FPM w LocalWP, WP-Cron zależny od ruchu (maks. raz na 60 s), długie zapytania do źródeł, ponowienia bez wyzwalacza, krok awaryjny w żądaniu przeglądarki. Dodatkowo Wikidata SPARQL → HTTP 500/504, Overpass → „Query timed out”.

**Zrobione**
- `AsyncWorker` (loopback admin-ajax, HMAC) — zadania kończą się bez otwartej karty; zapasowy WP-Cron + healthcheck co minutę.
- `QueueMonitor` — wykrywanie zaległych (wznowienie) i zablokowanych (> 15 min → błąd) zadań, wpisy w dzienniku i `error_log`.
- Ponowienie wyszukiwania (REST `POST /discovery/searches/{id}/retry`, przycisk „Ponów”).
- `wp ubr doctor [--providers]`, `wp ubr queue-work [--loop]`, poprawione `wp ubr discover --sync` (bez workerów HTTP, poprawna dokumentacja opcji).
- Wikidata: lekkie zapytania (CirrusSearch + `wbgetentities`) zamiast jednego ciężkiego SPARQL, odsiew klubów sportowych/miejscowości.
- Overpass: dopasowanie po nazwie + tagi branżowe (bez filtra regex po kluczach), zapasowy serwer przy ponowieniu, krótsze timeouty.
- **Apollo** jako główne źródło: `ApolloProvider` (Organization Search), filtry (Polska/miasto, województwo po pobraniu, słowa kluczowe, zatrudnienie), paginacja, mapowanie, deduplikacja (Apollo ID → domena → NIP/KRS → nazwa+miasto), kontrola kosztów (`ApolloBudget`: limity stron na wyszukiwanie / organizację / instalację, cache 12 h, brak ponowień dla zapytań mogących być płatnymi), dziennik `ubr_api_requests`.
- Settings → Integrations → Apollo (status, źródło klucza bez jego wartości, limity, statystyki, test połączenia, jawny test wyszukiwania za 1 kredyt).
- Discover: Apollo domyślnie, Wikidata/OSM opcjonalnie, szacunek kosztu, limity 25/50/100/250/500, przedziały zatrudnienia Apollo, kolumny Firma/Branża/Lokalizacja/Pracownicy/Domena/Źródło/Prospects, status: firmy / nowe / już w bazie / bieżące źródło / błędy.
- Google Places usunięte całkowicie.

**Testy automatyczne** (`wp ubr test`, środowisko testowe WordPress 6.8.3 + MariaDB 10.11 + PHP 8.3): **138/138**.
Obejmują: normalizację i walidację, migracje (idempotencja), organizacje i role, izolację danych (podmiana `organization_id`, cudze ID zasobów), deduplikację, weryfikację stron, zadania w tle (ponowienia, paginacja, anulowanie, limity), wykrywanie zablokowanych zadań, Apollo (brak klucza, nagłówek `x-api-key`, parametry, mapowanie, paginacja, cache, 401/403/429/timeout/5xx, limity kosztów, brak wycieku klucza w REST, pełne zadanie, deduplikacja Apollo↔Wikidata, prospekty, izolacja).
Odpowiedzi Apollo w testach są **mockowane** (kształt wg dokumentacji/obserwowanego API) — to nie jest test na żywym API.
Test end-to-end kolejki bez przeglądarki (środowisko testowe): zadanie przeszło 3 kroki z ponowieniami i zakończyło się samo, bez żadnego żądania z przeglądarki.

**Ograniczenia**
- Wyniki wyszukiwania Apollo nie zawierają lokalizacji, branży ani zatrudnienia. (W 1.1 województwo/przedział były przepisywane z filtrów — w 1.2 wycofane, patrz niżej.) Branża z kodów SIC/NAICS.
- Słowa kluczowe Apollo są angielskie — polskie frazy mogą zwracać mało wyników.
- Overpass (OSM): publiczne instancje nie odpowiadały w limicie czasu podczas testów — źródło działa technicznie (obsługa błędów, ponowienia, zapasowy serwer), ale nie dostarczyło wyników.
- Wikidata: małe pokrycie i słaba trafność dla wąskich branż (np. „maszyn*” dopasowuje „maszynistów”) — filtr klas poprawiony po teście.
- Miasto dla Apollo: nazwy polskie (np. „Kraków”) mogą nie pasować do formatu Apollo („Krakow”) — niezweryfikowane.
- LocalWP: tylko 2 procesy PHP-FPM — zalecane zwiększenie `pm.max_children`.

## Wyniki testu na żywo w LocalWP (etap 1.1, 09.10.2026)

Środowisko: PHP 8.5.3, WordPress 7.1.3, MySQL 8.4.0, LocalWP (`http://uglybutirch.local`), Chrome.

**Kolejka:** zadania startują od razu i kończą się bez otwartej karty (wyszukiwanie #9 i #10: karta zamknięta zaraz po starcie, ponowienia źródeł wykonane w tle, status końcowy zapisany). Kroki Apollo wykonują się w ~1 s od kliknięcia.

**Apollo (plan Free: 50/min, 200/h, 600/dzień wg `usage_stats`):**
- połączenie i klucz: OK (`auth/health`), statystyki API: OK (klucz master),
- #3 „Producenci maszyn i urządzeń”, Polska, 10 firm → 10 firm, 6 z domeną (np. FAMUR — famur.com, ZAMET Budowa Maszyn — zamet.com.pl, ALTEN Polska — altenpolska.pl), 1 strona,
- #8 to samo + województwo mazowieckie (filtr po stronie Apollo) → 10 firm z Mazowsza, 8 z domeną (np. Inter Cars — intercars.com, HENNLICH — hennlich.pl, SANY Polska — sanypoland.com, UNILOGO ROBOTICS — unilogo.com.pl); Apollo: 58 stron wyników zamiast 612 dla całej Polski,
- #6/#7 (50 firm) obsłużone z cache — 0 kosztu,
- #4/#5 (przed poprawką filtra województwa) — 6 płatnych stron bez wyników; przyczyna usunięta,
- łącznie wg dziennika: 13 zapytań do wyszukiwania (+2 testy połączenia bez kredytów), z czego 9 potencjalnie płatnych (w tym 1 jawny test wyszukiwania z Settings) i 2 z cache; 0 błędów API; klucz nie występuje w odpowiedziach REST.

**Wikidata:** przed naprawą 6 firm z potwierdzonymi domenami (#2); po zmianie na API wyszukiwania pierwsze zapytania odrzucał parametr `maxlag` (usunięty), potem zwróciło 4 wyniki, w tym 2 nietrafione (związki zawodowe — filtr klas poprawiony).

**OpenStreetMap:** timeout 60 s / HTTP 500 na obu publicznych instancjach (także po zmianie na filtr prostokątem) — brak wyników.

**Łącznie w bazie po testach:** 65 unikalnych, rzeczywistych firm z Discovery, 50 z domeną, 45 z domeną potwierdzoną (źródło + działająca strona z nazwą firmy). Zapis do Prospects z wyników Apollo — sprawdzony testem automatycznym (na żywo nie klikany).

## Etap 1.2 — Discovery Optimization + Company Verification (09.10.2026)

**Zrobione**
- Migracja M003: statusy weryfikacji firmy i domeny, dane rejestrowe (nazwa, forma prawna, PKD), pewność/źródło lokalizacji, kody SIC/NAICS, flagi jakości, trafność wyników, historia weryfikacji (`ubr_company_checks`), kolejka weryfikacji (`ubr_verification_queue`). Korekta danych 1.1: województwo/miasto/przedział zatrudnienia z filtra Apollo usunięte z pól firm (zachowane w historii jako `search_filter_*`).
- Lokalizacja: brak przypisywania z filtrów; `CityCatalog` (159 miast, warianty PL/ASCII/EN → województwo), warianty miasta w Apollo, walidacja sprzecznych kryteriów, stany `confirmed / declared / filtered / partial / unknown / mismatch`, diagnostyka wyszukiwania i wyjaśnienie przy 0 wyników.
- Branże: 17 w katalogu (rozdzielone konstrukcje stalowe i obróbka metali, nowe: opakowania, elektronika przemysłowa), rozpoznawanie polskich zapytań, wąskie tagi Apollo, prefiksy SIC/NAICS/PKD.
- Relevance Score 0–100 (deterministyczny, z rozbiciem na kryteria i stany), flagi jakości (urząd, stowarzyszenie, media, katalog, duża firma, oddział grupy, nieaktywna), domyślne sortowanie po trafności.
- Weryfikacja przedsiębiorstw: strona WWW → NIP/KRS/REGON → KRS + biała lista VAT (bezpłatne, bez klucza); CEIDG i GUS BIR zaimplementowane, aktywne po dodaniu kluczy (`UBR_CEIDG_API_TOKEN`, `UBR_GUS_API_KEY`). Statusy VERIFIED / UNCERTAIN / INACTIVE / SUSPENDED z powodami, rozbieżnościami, historią.
- Weryfikacja domen: CONFIRMED / UNCERTAIN / MISMATCH / UNAVAILABLE (parking, katalog/social, przekierowania, dane innego podmiotu, nazwa w tytule/stopce/JSON-LD + kontakt).
- Kolejka weryfikacji w tle (oddzielona od Discovery), retry z backoffem, limity, cache, ręczna ponowna weryfikacja (firma / całe wyszukiwanie), uprawnienie `companies.verify`.
- UI: Discover (trafność z rozwinięciem kryteriów, statusy firmy i domeny, stan lokalizacji, flagi, filtry i sortowanie, postęp weryfikacji, wyjaśnienia), Prospects (filtry statusu firmy/domeny i min. trafności, sortowanie po trafności), szuflada firmy (dane podstawowe, statusy, rejestr, rozbieżności, historia, „Zweryfikuj ponownie”).
- WP-CLI: `wp ubr verify`, `wp ubr relevance`.

**Testy automatyczne:** 204/204 (było 138). Nowe: lokalizacja/branże/diagnostyka, Relevance Score i flagi, weryfikacja (aktywna, zawieszona, wykreślona, brak danych, podobne nazwy, rejestr niedostępny + ponowienie, dane sprzeczne, „tylko Apollo ≠ VERIFIED”), domeny (poprawna, zła, przekierowanie, niedostępna, zaparkowana, katalog, sama zbieżność nazwy), SaaS (izolacja, uprawnienia, cooldown, limity), korekta migracji. Rejestry i strony w testach są mockowane (dane fikcyjne).

**Test na żywo (LocalWP, 09.10.2026, bez nowych zapytań Apollo — 0 kredytów):** 65 istniejących firm zweryfikowanych w KRS i białej liście VAT:
- przedsiębiorstwa: **25 VERIFIED** (23 KRS + VAT, 1 tylko VAT, 1 tylko KRS), 0 nieaktywnych/zawieszonych, 40 UNCERTAIN — w tym 34 bez NIP/KRS (17 nie ma w Apollo nawet domeny), 4 z NIP/KRS innego podmiotu (np. strona portalu z KRS wydawcy, Eneria → NIP spółki Bergerat Monnoye — nie przypisane), 1 z kilkoma pasującymi podmiotami, 1 nieznaleziony;
- domeny: 35 potwierdzonych, 12 niepewnych, 17 niedostępnych (brak domeny), 1 niezgodna (katalog „Factories”);
- lokalizacja: HENNLICH zwrócony przez Apollo dla filtra „mazowieckie” ma siedzibę w Knurowie (śląskie, KRS) → „niezgodna”, trafność 57 — dokładnie przypadek, którego nie da się wykryć bez rejestru;
- flagi jakości: urząd (UDT), media (Przemysł 4.0), katalog (Factories), 2 związki zawodowe (Wikidata), 13 możliwych oddziałów grup, 6 dużych przedsiębiorstw.

**Jakość wyników dla OhSoFresh (ocena):** z 10 firm wyszukiwania #8 (Apollo, maszyny, mazowieckie) realnymi prospektami redesignu są ok. 3–4 (UNILOGO ROBOTICS, HAVEC, HENNLICH — choć spoza Mazowsza, KSB/SANY to oddziały grup z centralnym marketingiem). Wikidata (#2) zwraca znane spółki niepasujące branżą (Mennica Polska, Alchemia) — trafność 54–64 to odzwierciedla. Wniosek: Apollo + rejestr daje wiarygodną bazę, ale tagi Apollo są szerokie (612 stron ≈ 61 200 rekordów dla całej Polski) — kolejny etap powinien zawężać przez PKD z rejestru i Opportunity Score.

**Ograniczenia**
- Brak wyszukiwania po nazwie w KRS/VAT (API nie pozwala) — firmy bez NIP/KRS na stronie zostają UNCERTAIN. GUS BIR i CEIDG wymagają kluczy (bezpłatnych, na wniosek) — kod gotowy, nieprzetestowany na żywo.
- Biała lista VAT: 100 zapytań/dzień na IP (aplikacja: 80).
- Strony renderowane JavaScriptem (np. bosch.pl, intercars.com) nie ujawniają NIP w HTML — status domeny może być potwierdzony, firma pozostaje niepewna.
- Status zawieszenia w KRS wykrywany heurystycznie po nazwach sekcji odpisu (bez oficjalnego schematu JSON) — przetestowany na danych testowych.

## Etap 2 — Website Analyzer + Website Preview + Opportunity Score (10.10.2026)

**Zrobione (szczegóły: ARCHITECTURE.md §10)**
- Migracja M004: tabela `ubr_website_analyses` (kolejka + historia; nigdy nie nadpisujemy poprzednich wyników), kolumny podsumowania w `ubr_companies`; uprawnienia `analysis.view`, `analysis.run`.
- Pipeline w tle (pętla zwrotna `ubr_job_worker` + WP-Cron `ubr_analysis_tick`, sloty współbieżności, ponowienie błędów przejściowych): pobranie HTML (strona główna + oferta + kontakt, maks. 3, wybór deterministyczny), render w Chromium przez helper Node `tools/site-capture` (Playwright-core 1.64, axe-core 4.14; sandbox Chromium włączony), screenshoty Desktop 1440 / Mobile 390, analizatory UX strukturalny, mobile, konwersja/treść, technika (wydajność laboratoryjna, nie CWV), SEO, dostępność (axe — bez deklaracji zgodności z WCAG), bezpieczeństwo (nieinwazyjne), potencjał biznesowy, wiarygodność firmy.
- Ochrona SSRF w PHP (`UrlGuard`: DNS, prywatne/link-local/metadata, każde przekierowanie) i w przeglądarce (każde żądanie i przekierowanie; naruszenie przerywa render bez screenshotów).
- Opportunity Score 0–100 (A 45 / B 20 / C 25 / D 10), brak punktów za brak danych, pokrycie i pewność, „maks. przy pełnych danych”, INSUFFICIENT_DATA, limit 10 dla firm nieaktywnych, poziomy konfigurowalne, wersjonowanie (`opp-2026-10-v1`). Niezależny od Relevance Score.
- Ocena wizualna: bez AI Vision kategorie wizualne mają status NOT_EVALUATED (0 pkt, 15 pkt niezmierzone). AI Vision przygotowane (`VisionService`, schemat JSON, wersja promptu, cache, limit miesięczny, log kosztów) i wyłączone — bez płatnych wywołań.
- Website Preview (lightbox ~95% ekranu): Desktop / Mobile / „Na żywo” (iframe w sandboxie tylko, gdy nagłówki strony na to pozwalają), zoom, przewijanie, ESC, X, „Otwórz stronę”, pułapka fokusu, powrót fokusu. Screenshoty serwowane wyłącznie przez `admin-ajax.php?action=ubr_screenshot` po sprawdzeniu dostępu, pliki poza katalogiem WWW.
- UI: Discover (kolumna Opportunity, status analizy, „Analizuj stronę”/„Analizuj ponownie”, „Podgląd strony”, zaznaczanie i analiza zbiorcza, filtry: min. Opportunity, status, pewność, liczba problemów; sortowanie po Opportunity obok trafności), Prospects (Opportunity, status, data, podgląd, ponowna analiza, filtry), zakładka „Website Analysis” w profilu firmy (przegląd, podgląd, UX/UI, technika, potencjał biznesowy, problemy z podziałem automatyczne/AI/do ręcznej oceny, historia z porównaniem), Settings → Website Analyzer (test środowiska, limity, status AI).
- Limity (konfigurowalne stałymi `UBR_ANALYSIS_*` i filtrem `ubr/analysis/limits`): 25 analiz/dzień i 300/miesiąc na organizację, 20 w jednej analizie zbiorczej, 1 równoległa (maks. 2), 75 s na stronę, 2,5 MB na screenshot, wynik aktualny 30 dni, ręczne ponowienie co 60 min (nie dotyczy wyników ze starszej wersji algorytmu). Apollo nie jest używane.
- WP-CLI: `wp ubr analyze [--check] [<id>...] [--work]`.

**Testy automatyczne:** 265/265 (było 204). Grupy oznaczone: `[mock]` — SSRF, analiza HTML, scenariusze Opportunity (wiele problemów, strona poprawna, „wizualnie przestarzała” z poprawnym JSON AI, nieosiągalna, brak danych biznesowych, nieaktywna, częściowa, za mało danych, progi), walidacja AI Vision, kolejka/ponowienia/historia/limity/multi-tenancy/wersje algorytmu, wybór podstron; `[integracja]` — prawdziwy Chromium na lokalnych fixture’ach (screenshoty, przekierowanie do metadata zablokowane, timeout → PARTIAL, przewijany kontener, własny baner cookies).

**Test na żywo (LocalWP, Mac: Node 26 z Homebrew, systemowy Google Chrome, 0 kredytów Apollo):** 3 firmy VERIFIED z potwierdzoną domeną i trafnością 92 — ZAMET Budowa Maszyn (zamet.com.pl), NozziTec (nozzitec.com), FLOWAIR (flowair.com). Wszystkie analizy COMPLETED w 11–21 s, screenshoty Desktop i Mobile poprawne, lightbox, zakładka profilu, historia i porównanie działają. Wyniki 32–37 („Low”) przy pokryciu 72% — to uczciwy wynik: 15 pkt za ocenę wizualną i część danych biznesowych (finanse, wielkość) nie są mierzone, a strony są technicznie przyzwoite.
Poprawki po teście na żywo (wersje algorytmu v2–v5; starsze wyniki zostały w historii):
- ZAMET: strona przewija wewnętrzny kontener → zrzut miał 1 ekran, a sekcje z animacją „przy przewinięciu” były puste; teraz helper przewija ten kontener (lazy loading, animacje) i rozwija go do zrzutu całej strony (4145 px); elementy animowane przy przewinięciu (AOS) były niewidoczne (opacity 0) — na zrzucie pokazywane w stanie końcowym.
- ZAMET: własny baner cookies tylko z „Akceptuj” zasłaniał zrzut → ukrywany na zrzucie (zgoda nie jest klikana; informacja w podglądzie).
- NozziTec: jako „oferta” wybierany był link „Zapytaj o ofertę” do /kontakt/, a potem `mailto:service@…` → ścieżka URL ma pierwszeństwo przed tekstem linku, oferta ≠ kontakt, linki mailto:/tel: nie są podstronami, bez duplikatów.
- Powrót fokusu po zamknięciu podglądu, gdy kliknięcie myszą nie ustawiło fokusu.

**Ograniczenia / do decyzji**
- Bez AI Vision Opportunity Score nie ocenia wyglądu strony — strony „brzydkie, ale poprawne technicznie” dostają niskie wyniki. Włączenie wymaga zgody (płatne API) i `UBR_AI_VISION_ENABLED`, `UBR_AI_VISION_API_KEY`, `UBR_AI_VISION_MONTHLY_LIMIT` w wp-config.php.
- Potencjał biznesowy: wielkość zatrudnienia często pusta (Apollo/rejestr); dane finansowe — tylko architektura (brak darmowego źródła), GUS/CEIDG po dodaniu kluczy.
- Ochrona SSRF w przeglądarce sprawdza DNS przed żądaniem; teoretyczne ryzyko DNS-rebinding między sprawdzeniem a połączeniem opisane w ARCHITECTURE.md.
- Na Macu `node_modules/.bin/playwright-core` (symlink) nie rozpakował się — nieużywany, helper ładuje bibliotekę bezpośrednio.
- **Krytyczne (z audytu):** w repozytorium jest śledzony prywatny klucz SSH (plik `key`). Nie był czytany ani używany. Wymaga rotacji klucza; usunięcie pliku z bieżącego commita nie usuwa go z historii Git (czyszczenie historii tylko za zgodą).

## Etap 3 — Decision Maker Finder + Contact Priority Score (10.10.2026)

**Zrobione (szczegóły: ARCHITECTURE.md §11)**
- Migracja M005: `ubr_contact_research_runs` (historia + kolejka), `ubr_contacts`, `ubr_contact_sources`, `ubr_contact_score_history`, `ubr_contact_reviews` (prywatne), `ubr_contact_suppressions`; kolumny podsumowania w `ubr_companies`; uprawnienia `contacts.view/run/manage`.
- Źródła: KRS dział 2 (skład zarządu i prokurenci — dane osobowe w API zanonimizowane, więc KRS potwierdza osoby), oficjalna strona firmy (zarząd / zespół / o nas / kontakt / media; robots.txt, limity, SSRF), Apollo People Search przygotowane i wyłączone.
- Ekstrakcja osób (pary imię–stanowisko, JSON-LD), normalizacja stanowisk PL/EN, deduplikacja, weryfikacja (VERIFIED / PROBABLE / UNCERTAIN / OUTDATED / REJECTED, COMPANY_MATCH_UNCERTAIN), confidence (4 składowe), Contact Priority Score (A–E, PROVISIONAL / INSUFFICIENT_DATA), ranking Top 1–3 z uzasadnieniem, historia zmian.
- Kolejka w tle (ten sam worker), pojedynczo i zbiorczo, cache 30 dni, odświeżenie po 24 h, limity organizacji, 1 aktywne zlecenie na organizację.
- UI: zakładka „Decision Makers” w karcie firmy (podsumowanie, Top 1–3, Other Candidates, ukryte, kanały ogólne, zarząd wg KRS, źródła, kontakt ręczny USER_PROVIDED, historia, przegląd ręczny: zatwierdź / odrzuć / nieaktualny / wyklucz / preferowany / notatka), Discover (kolumna Decydenci, „Znajdź decydentów”, zbiorczo, filtry, sortowanie), Prospects (kolumna, filtry, sortowanie), Settings (źródła i limity).
- WP-CLI: `wp ubr contacts research|show|suppress|purge`.

**Testy automatyczne:** 335/335 (było 265). Nowe grupy `[mock]`: parsowanie (imiona, stanowiska, maski KRS, strony, robots.txt) oraz 20 przypadków obowiązkowych (3 decydentów, 1 prezes, brak osób, były prezes, podobne nazwy, matka/córka, wiele źródeł, konflikt stanowisk, brak e-maila, ogólny e-mail, CEO dużej firmy vs dyrektor marketingu, nieznana wielkość, niepewna domena, MISMATCH, Apollo 403, limity, dostęp innej organizacji / IDOR, ponowne dodanie osoby, wykluczenie, brak Top 2/3) + SSRF, cache/cooldown, historia, preferencje prywatne, filtry list. Osoby w testach są fikcyjne.

**Test na żywo (LocalWP, 10.10.2026, 0 kredytów Apollo, bez płatnych API)** — 5 firm VERIFIED z potwierdzoną domeną:
| Firma | Osób | Top 1 | Top 2 | Top 3 | Uwagi |
|---|---|---|---|---|---|
| FAMUR (famur.com) | 5 | Mariusz Sośnierz — prezes zarządu (CPS 67, VERIFIED, pewność wysoka) | Robert Rogowski — wiceprezes zarządu (54, VERIFIED) | Aleksandra Drożdż — kierownik Działu Marketingu i PR (54, PROBABLE, średnia) | + 2 specjalistów marketingu i PR; brak osobistych e-maili — przy marketingu „marketing@famur.com” (adres działu); źródła: famur.com/o-nas, /media + KRS |
| PCO (pcosa.com.pl) | 4 | Marek Adamiak — prezes zarządu (65, VERIFIED, wysoka) | Bogumił Wodyński — członek zarządu ds. handlu i innowacji (60, VERIFIED) | Przemysław Sypniewski — członek zarządu ds. ekonomiczno-finansowych (44, VERIFIED) | Tomasz Drozd (ds. produkcji) jako pozostały kandydat; wszyscy 4 zgodni ze składem KRS; brak e-maili osób |
| ZAMET (zamet.com.pl) | 0 | — | — | — | strona nie publikuje nazwisk; KRS: 2 wiceprezesów (inicjały); kanały: zbm.zarzad@ (zarząd), zbm.marketing@ (marketing), zamet@ |
| NozziTec (nozzitec.com) | 0 | — | — | — | brak nazwisk na stronie (kontakt, „Poznaj nas”); KRS: prezes (inicjały) |
| FLOWAIR (flowair.com) | 0 | — | — | — | brak nazwisk na stronie; KRS: prezes + 2 prokurentów (inicjały); info@flowair.pl |
Wszystkie wyszukiwania COMPLETED w 1–32 s. Wszystkie wyniki CPS mają status PROVISIONAL, bo w bazie brak danych o liczbie pracowników tych firm (kryterium C nieoceniane). Poprawki po teście: przypisanie adresu działu wg działu osoby (nie wg położenia na stronie), wzorce „poznaj nas / o spółce”, sformułowania uzasadnień (wersja `dmf-2026-10-v2`). Zapisy testowe w Prospects zostały cofnięte.

**Ograniczenia**
- Nazwiska pochodzą tylko z oficjalnych stron firm — firmy, które ich nie publikują (3 z 5 testowanych), nie mają kontaktów; KRS bez nazwisk (anonimizacja API). Uzupełnienia wymagałyby CEIDG (JDG, klucz), płatnego KRS/eKRS lub Apollo People (decyzja właściciela).
- Strony renderowane wyłącznie JavaScriptem: crawler czyta HTML bez przeglądarki — osoby wstrzykiwane skryptem nie są widoczne.
- Dopasowanie do maski KRS jest statystyczne (inicjały + długości); przy kilku pasujących osobach — UNCERTAIN.
- Wielkość firmy często nieznana → Contact Priority wstępny (PROVISIONAL).

## Etap 4 — Sales Pipeline + ClickUp + Prospect Management + Automation Rules (10.10.2026)

**Zrobione (szczegóły: ARCHITECTURE.md §12)**
- Migracja M006 (pola sprzedażowe prospektu, historia, notatki, aktywności, follow-upy, połączenia / eksporty / kolejka / reguły ClickUp); uprawnienia `pipeline.*`, `clickup.*`.
- Pipeline prywatny dla organizacji: 10 statusów, priorytet, właściciel (tylko członek organizacji), kwalifikacja (sugestia + decyzja ręczna), wybrany kontakt, powód utraty, historia zmian, archiwizacja zamiast usuwania (z przywracaniem).
- Notatki (XSS-safe, soft delete, edycja autora, usuwanie autor / moderator), aktywności (rejestr ręczny), follow-upy (termin, przypisanie, wykonanie / anulowanie, następne działanie).
- UI: Prospects (List View) z kolumnami pipeline, filtrami, zaznaczaniem, eksportem zbiorczym; Pipeline (Board View, drag & drop + obsługa klawiaturą, lazy load); zakładka „Sprzedaż” w karcie firmy; Dashboard (kafelki, lejek, follow-upy); Settings → Integrations → ClickUp; link `?company=ID`.
- ClickUp: OAuth per użytkownik (state, CSRF, token szyfrowany libsodium), wybór Workspace → Space → Folder → List z API, Test Connection, Disconnect, szablon nazwy, mapowanie istniejących Custom Fields, eksport pojedynczy i zbiorczy w tle z postępem, deduplikacja (org + firma + lista), Already exported / Update existing task / Export as new task, UNKNOWN + rekoncyliacja, ponowienia z Retry-After, historia eksportu; reguły automatyczne (domyślnie wyłączone, Dry Run, potwierdzenie, tylko nowe zdarzenia, backfill za osobną zgodą, limity).
- WP-CLI: `wp ubr secrets status|key`.

**Testy automatyczne:** 467/467 (było 335): 14 przypadków pipeline + 36 przypadków ClickUp (15–50) na mockach HTTP `api.clickup.com` (OAuth, state, przejęcie autoryzacji, szyfrowanie, brak tokenów w logach, hierarchia list, mapowanie pól, treść zadania, duplikaty, równoległe kliknięcia, update bloku, edycja ręczna, wariant, timeout → UNKNOWN, rekoncyliacja, 429, 5xx, limit prób, 404, 401, multi-tenancy, cudzy token, bulk, limity, reguły, backfill, disconnect).

**Test na żywo (LocalWP):** M006 zastosowana, istniejące dane zachowane (2 prospekty → status „Nowy”, bez właściciela — rekordy sprzed M006). Sprawdzone: dodanie do Prospects, zmiana statusu i priorytetu, notatka (HTML usunięty), aktywność, follow-up, walidacja (zły status / obcy właściciel → 422), przeniesienie karty drag & drop (zapis po odświeżeniu), karta „Sprzedaż” z linku `?company=`, archiwizacja (notatki zachowane), Dashboard, Settings → ClickUp w stanie „Not Configured” z poprawnym Redirect URI, eksport bez połączenia odrzucony, wcześniejsze moduły (Discover, analiza, kontakty, Apollo) odpowiadają 200. Rekord testowy (prospekt ABJ wraz z historią) usunięty. Widoki mobilne 390 px bez poziomego przewijania (poprawione też 2 wcześniejsze przepełnienia: Dashboard, Settings).

**Ograniczenia / do zrobienia przez właściciela**
- ClickUp NIE był testowany na żywo: brak `UBR_CLICKUP_CLIENT_ID` / `UBR_CLICKUP_CLIENT_SECRET` (instrukcja: AGENTS.md → HOW TO CONNECT CLICKUP). Pierwszy realny eksport — dopiero po konfiguracji i potwierdzeniu.
- ClickUp: brak synchronizacji dwukierunkowej (zgodnie z zakresem); mapowanie priorytetu ClickUp nieudokumentowane — nieużywane; HTTP Redirect URI dopuszczony lokalnie, w produkcji wymagany HTTPS.
- Klucz SSH w repozytorium — plan: docs/SECURITY-KEY.md (rotacja i czyszczenie historii za zgodą).

## Etap 5 — Lead Intelligence + Smart Lead Ranking (10.10.2026)

**Zrobione (szczegóły: ARCHITECTURE.md §13)**
- M007: `ubr_lead_intelligence` (ocena per organizacja), `ubr_lead_insights`, `ubr_lead_intelligence_history`.
- Lead Potential Score (45/30/15/10, brak danych = niezmierzone, limit kompletności, limit 65 bez analizy strony), Data Coverage, Data Confidence, hard blockers / soft warnings, Website Opportunity Insights (7 kategorii, severity z progów, evidence), Redesign Assessment, Recommended Next Action (7 akcji), Smart Lead Ranking, Lead Research Summary, Potential Sales Angles, historia i wersjonowanie, przeliczanie w tle po zdarzeniach.
- UI: zakładka „Lead Intelligence” w karcie firmy, kolumna / filtry / sortowanie w Discover i Prospects, Smart Lead Ranking na Dashboardzie; link `?company=ID&tab=lead`.

**Testy automatyczne:** 533/533 (było 467): 50 przypadków Lead Intelligence (scoring 1–15, insights 16–24, rekomendacje 25–34, summary 35–39, dane i wydajność 40–50) + regresje z walidacji biznesowej.

**Test na żywo (LocalWP, 0 płatnych zapytań, bez nowych analiz):** M007 zastosowana; firmy organizacji (wyniki wyszukiwań + prospekty) ocenione automatycznie w tle — w sprawdzonych wyszukiwaniach #7 i #8 żadna firma nie czeka na ocenę; filtry i sortowanie (Smart Ranking, Lead, Redesign, rekomendacja, bez blokerów) działają w SQL (lista 50 firm ~80 ms); karta firmy, Dashboard i Prospects bez błędów konsoli.

| Firma | Relevance | Opportunity | Contact Priority | Lead Potential | Confidence | Coverage | Redesign | Rekomendacja |
|---|---|---|---|---|---|---|---|---|
| NozziTec | 92 | 33 | — (brak osób; kanał firmowy) | 75 SCORED | HIGH | 95% | MODERATE | Review website |
| FLOWAIR | 92 | 32 | — | 74 SCORED | HIGH | 95% | MODERATE | Review website |
| ZAMET | 92 | 36 | — (kanały działów) | 73 SCORED | HIGH | 95% | MODERATE | Review website |
| FAMUR | 92 | brak analizy | 67 | 65 PROVISIONAL | MEDIUM | 55% | INSUFFICIENT_DATA | Research further |
| PCO | 74 | brak analizy | 65 | 65 PROVISIONAL | MEDIUM | 55% | INSUFFICIENT_DATA | Research further |

**Walidacja biznesowa → poprawka:** w pierwszej wersji FAMUR i PCO (bez analizy strony) dostawały 77 — więcej niż firmy z potwierdzonymi problemami strony — bo normalizacja po zmierzonych danych nagradzała brak danych. Dodano limit 65 bez danych o stronie (`lead-2026-10-v2`) i test regresyjny. Firmy bez decydenta (ZAMET, NozziTec, FLOWAIR) nie są traktowane jako słabe (Contact Readiness 2/10, wynik 73–75).

**Ograniczenia:** brak oceny wizualnej (AI Vision wyłączone) — stąd brak STRONG dla testowanych stron i rekomendacja ręcznej oceny; wiele nieanalizowanych firm ma wynik 65 (limit) i rekomendację „Research further”; skuteczność rankingu niepotwierdzona wynikami sprzedaży.

## Etap 5.1 — Discover UI Redesign + Live Website Preview (10.10.2026)

**Zrobione (szczegóły: ARCHITECTURE.md §14; bez migracji, bez zmian w scoringu, wyszukiwaniu, workerach i ClickUp)**
- Discover: układ pionowy na pełną szerokość (Formularz → Status → Filtry → Wyniki), formularz w CSS Grid, zwijany („Zwiń” / „Rozwiń”), automatycznie zwijany po udanym starcie (wartości zachowane, stan w `localStorage`).
- Tabela bez przewijania w poziomie (`table-fixed`, procentowy `colgroup`): Firma · Weryfikacja · Domena · Lokalizacja · Oceny (Lead Potential + Confidence; rozwijane szczegóły z Relevance, Opportunity, Contact Priority, Redesign, rekomendacją) · ⋯. Poniżej 1024 px karty.
- Wspólne menu akcji ⋯ (Discover + Prospects): tylko akcje mające zastosowanie, uprawnienia z backendu, stany „w toku”, obsługa klawiatury i ARIA, renderowane w `<body>` (nieobcinane).
- Wspólny modal podglądu (Discover, Prospects, karta firmy): LIVE (iframe ze zwalidowanego adresu, sandbox, bez kamery/mikrofonu/lokalizacji, bez proxy) | SCREENSHOT (istniejące zrzuty, empty state), DESKTOP | MOBILE (390 px), „Otwórz stronę”, komunikat o stronach blokujących osadzanie, Escape, fokus, blokada przewijania tła.

**Testy automatyczne:** PHP 587/587 (było 533; +54 w `tests/discover-ui.php`: kontrakt danych tabeli i menu, filtry/sortowanie/paginacja, izolacja, uprawnienia, brak efektów ubocznych podglądu — brak nowych analiz, wyszukiwań decydentów, zapytań API i zadań WP-Cron — reguły iframe w szablonach). JS: `node tests/js/preview-url.test.mjs` 19/19 (walidacja adresu, schematy `javascript:` / `data:` / `file:`, dane logowania w URL, http→https na stronie https).

**Testy wizualne i interakcji (Playwright/Chromium w kontenerze, dane testowe):** 1920 / 1440 / 1280 / 768 / 390 px — brak poziomego przewijania strony, tabela mieści się w kontenerze (≥ 1024 px), karty < 1024 px, formularz nad wynikami, wyniki na pełną szerokość, brak błędów JS; zwijanie i zachowanie wartości; menu myszą i klawiaturą (Enter, strzałki, Escape z powrotem fokusu, klik poza, zmiana rozmiaru, przewijanie); podgląd LIVE / MOBILE 390 px / SCREENSHOT bez zrzutów / Escape / fokus / `javascript:` i błędny URL odrzucone; Prospects i karta firmy — ten sam modal.

**Test na żywo (LocalWP, wyszukiwanie #7, 0 płatnych zapytań, bez nowych analiz):** nowy układ i tabela bez przewijania (szerokość okna 2056 px CSS); menu ⋯ dla ZAMET (Analizuj ponownie / Znajdź ponownie decydentów / Zapisz do Prospects); podgląd LIVE ZAMET i NozziTec (wyświetlają się), FLOWAIR i Inter Cars blokują osadzanie → komunikat + przełączenie na SCREENSHOT działa; SCREENSHOT desktop i mobile ZAMET / FLOWAIR (zapisane zrzuty, proporcje zachowane); Prospects i karta firmy otwierają ten sam modal; Escape zamyka najpierw podgląd, potem kartę firmy; ostatnie analizy ZAMET / NozziTec / FLOWAIR bez zmian (10.10, 12:04–12:05), brak aktywnych analiz; Lead Potential bez zmian (NozziTec 75, FLOWAIR 74, ZAMET 73, FAMUR 65), filtry `min_lead`, `recommendation`, `lead_confidence`, `redesign` i sortowanie `rank` / `lead` działają.

**Ograniczenia:** strony z `X-Frame-Options` / CSP `frame-ancestors` nie wyświetlą się w LIVE (przeglądarka nie pozwala tego wiarygodnie wykryć — stały komunikat i ręczne przełączenie); sandbox może ograniczać część funkcji osadzonych stron; tryb MOBILE to węższy kontener, nie emulacja urządzenia; na LocalWP nie dało się zmienić szerokości okna Chrome — responsywność sprawdzona w Playwright; tabela Prospects ma nadal własne przewijanie poziome (poza zakresem 5.1).

## Etap 6 — Prospecting Dashboard & Action Center (10.10.2026)

Zakres: obie wersje promptu 6 (Action Center + Lead Recommendations / Sales Opportunity Workspace) połączone w jeden moduł. Szczegóły: ARCHITECTURE.md §15, wdrożenie: docs/DEPLOYMENT.md.

**Zrobione**
- Migracje: M008 (`ubr_action_snoozes`), M009 (`ubr_manual_reviews` + `ubr_manual_review_history`). Tylko nowe tabele.
- Action Priority Engine: deterministyczne kategorie 1–8, stabilne remisy, ta sama reguła w PHP i SQL. Odrębny od Lead Potential.
- Today's Priorities: maksymalnie 5 naraz, deduplikacja per firma, follow-upy grupowane.
- Listy robocze (filtry SQL, paginacja, sortowanie):
  - Top Opportunities (Smart Lead Ranking),
  - Needs Attention (jedna główna kategoria + braki pomocnicze),
  - Ready to Contact (decydent i kanały tylko z zapisanych danych),
  - Review Website, Needs Research, Verify Company, Find Decision Maker, Missing Website Analysis, High Potential, Blocked, Follow-ups.
- Overview KPI z definicjami i drill-down: Total Prospects, Follow-ups Due, Ready to Contact, High Potential, Needs Review, Missing Analysis.
- Follow-ups (Overdue / Today / Upcoming 7 dni, „Wykonane”) i Recent Activity.
- Prospecting Coverage: niezależne liczniki, bez fałszywego lejka.
- My Work / Team Work / Unassigned.
- Quick Actions przez istniejące endpointy, odłożenie działania, szybkie przejścia (Discover / Prospects / Pipeline / Review Websites / Follow-ups).
- Manual Website Review z historią w karcie firmy i w podglądzie strony. Nie zmienia scoringów.

**Testy automatyczne:** PHP 756/756 (było 533 po etapie 5; +54 w etapie 5.1; +169 w `tests/action-center.php`). Zakres nowych testów:
- reguły silnika i zgodność SQL ↔ PHP,
- kolejność i remisy, deduplikacja,
- KPI i liczniki, wszystkie listy (dokładne zbiory firm),
- filtry, sortowanie, paginacja,
- My/Team/Unassigned,
- odłożenie i przywrócenie, invalidation cache,
- strefa czasowa (UTC+14, Los Angeles),
- izolacja organizacji, IDOR, autoryzacja,
- puste stany,
- Manual Review (tworzenie, edycja, historia, walidacja, uprawnienia, multi-tenancy, brak wpływu na scoring),
- regresje Lead Intelligence / Dashboard / Prospects / Discover / Pipeline,
- brak nowych analiz, wyszukiwań, zapytań API i zadań ClickUp.

JS: `node tests/js/preview-url.test.mjs` 19/19.

**Testy wizualne i interakcji** (Playwright/Chromium w kontenerze, dane testowe):
- Szerokości 1920 / 1440 / 1280 / 768 / 390 px: brak poziomego przewijania, maksymalnie 5 priorytetów, 6 KPI, poprawna kolejność sekcji, tabela list mieści się w kontenerze (≥ 1024 px), karty poniżej 1024 px, brak błędów JS.
- Interakcje:
  - klik KPI → przefiltrowana lista,
  - „Wykonane” → odświeżone KPI i lista,
  - menu Quick Actions zależne od stanu,
  - odłożenie i przywrócenie działania,
  - przełącznik Team / My / Unassigned,
  - CTA otwiera kartę firmy (Sprzedaż),
  - zapis oceny ręcznej w karcie firmy (status, notatka, historia) i ta sama ocena w podglądzie strony (tryb SCREENSHOT).

**Test na żywo (LocalWP, 0 płatnych zapytań, bez nowych analiz)** — wykonany na pierwszej części etapu: M008, silnik, priorytety, listy, odłożenie. Wyniki:
- M008 zastosowana automatycznie.
- `GET /action-center`: 200, około 110 ms (65 ocenionych firm, 0 czekających na ocenę).
- Priorytety (60 działań): NozziTec 75, FLOWAIR 74, ZAMET 73 → Review website (pierwsze), FAMUR 65 i PCO 65 → Brak analizy strony („brak analizy nie oznacza słabej firmy”).
- Ready to Contact = 0 (żadnych fałszywych rekomendacji kontaktu). Blocked = 1 (Factories: domena innego podmiotu).
- Find Decision Maker = 2: prospekci bez wyszukanych decydentów nie są odrzucani.
- Odłożenie NozziTec o 1 dzień zdjęło pozycję z listy (60 → 59), przywrócenie ją przywróciło (stan bazy bez zmian).
- CTA „Podgląd strony” otwiera wspólny modal. KPI „Wykluczone” otwiera listę Blocked. Brak błędów konsoli. Brak poziomego przewijania przy szerokości 560 px.
- Brak follow-upów w danych LocalWP — follow-upy testowane tylko w kontenerze (nie tworzono fikcyjnych rekordów w bazie użytkownika).

**Wydajność (test syntetyczny, 10 000 ocenionych firm w kontenerze, dane usunięte po teście):** wszystkie liczniki 102 ms, Today's Priorities około 120 ms (także strona 200), Top Opportunities 73 ms, Needs Attention z filtrem 96 ms, coverage 39 ms.

**Test na żywo — druga część (LocalWP, 10.10.2026, po ponownym połączeniu komputera):**
- M009 zastosowana automatycznie; `GET /companies/{id}/manual-review` 200 (~50 ms) dla NozziTec, FLOWAIR, ZAMET — status „Brak oceny”, historia pusta.
- `GET /action-center` 200, ~70 ms. KPI: Total Prospects 2 (odkryte 65), Follow-ups Due 0, Ready to Contact 0, High Potential 3, Needs Review 3, Missing Analysis 46.
- Today's Priorities: 5 z 60 działań — NozziTec 75, FLOWAIR 74, ZAMET 73 (Review website, CTA „Podgląd strony”), FAMUR 65, PCO 65 (Brak analizy strony, CTA „Analizuj stronę”).
- Listy: Top Opportunities 27 (NozziTec, FLOWAIR, ZAMET, FAMUR, PCO, Mennica Polska…), Needs Attention 60, Find Decision Maker 2, Blocked 1 (Factories), Review Website 3, Ready to Contact 0; każda ~50–80 ms.
- Needs Attention dla FAMUR: decydent i kanały tylko z zapisanych danych (zarząd z KRS, e-mail firmowy), ocena ręczna „Brak oceny”.
- Karta firmy (Website Analysis) i podgląd strony (SCREENSHOT, prawdziwy zrzut NozziTec z analizy) pokazują panel oceny ręcznej z zastrzeżeniem, że nie zmienia scoringów; LIVE ładuje zwalidowany adres firmy w sandboxie. Brak błędów konsoli, brak poziomego przewijania (okno 560 px — większej szerokości okna nie dało się ustawić; szerokości 1920–390 px sprawdzone w Playwright).
- Zapis oceny ręcznej NIE był testowany na żywo — celowo, żeby nie tworzyć rekordów w bazie użytkownika; pokryty testami automatycznymi i Playwright.

**Ograniczenia:**
- Rekomendacje wynikają z Lead Intelligence (bez AI Vision), więc dominują Review website i Missing analysis.
- Brak trendów KPI (brak historii).
- Ręczna ocena nie wpływa na ranking. To decyzja na przyszłą wersję algorytmu.
- Tabela Prospects ma nadal własne przewijanie poziome (poza zakresem).

## Etap 7 — Audyt QA, testy i gotowość produkcyjna (10.10.2026)

Szczegóły: `docs/QA-REPORT.md`, ARCHITECTURE.md §16, wdrożenie: `docs/DEPLOYMENT.md`. Bez nowych modułów i bez migracji (schemat v9).

**Naprawione (z testami regresyjnymi):**
- bezpieczeństwo: obejście ochrony SSRF adresami IPv4 osadzonymi w IPv6 (PHP i przeglądarka), stored XSS przez link profilu osoby i niesprawdzane linki zewnętrzne, DNS rebinding w pobraniach PHP, weryfikacja firmy bez ochrony SSRF, WebSockety w rendererze, zapisy anonimowego pingu workera, niesprawdzany `search_id`;
- kolejki: nieatomowe blokady (teraz MySQL `GET_LOCK`), nieskończone ponawianie przerwanych zadań, możliwy duplikat zadania ClickUp po przerwaniu workera, blokowanie kolejki Lead Intelligence przez jedną firmę, brak zapasowego crona weryfikacji, `wp ubr queue-work --all`, zlecenia workerów wysyłane po odpowiedzi (zmiana statusu bez ~1 s czekania), osierocone procesy Chromium po timeoucie;
- migracje: ponawianie po błędzie przy każdym żądaniu, równoległe migracje, `wp ubr migrate` z kodem wyjścia, `UBR_AUTO_MIGRATE`;
- dane: ogólny e-mail firmy zapisywany jako e-mail osoby (ręcznie i z ekstraktora), ponowna analiza cofająca firmę do „Brak analizy”, brak przeliczenia po „not a fit”, przypisania usuniętego członka, KPI Total Prospects bez filtra właściciela, starzenie ocen w czasie, NIP/REGON-wypełniacze, filtry przy drill-down KPI, **strefa czasowa formularzy** (aktywność „teraz” odrzucana, przesunięte terminy follow-upów), pusta notatka oceny, archiwizacja przez `DELETE` bez odświeżenia liczników, znacznik „wstępny” na Dashboardzie, lazy load sekcji „Ostatnie wyszukiwania”, stopka karty firmy na 390 px, komunikaty błędów z treścią wyjątków bazy.

**Testy:** PHP 836/836 (było 756); JS 19/19 + 35/35 + 5/5 (3 strefy czasowe); Playwright w kontenerze: 43 + 12 + 30 + 7 + 138 (9 ekranów × 5 szerokości, podgląd, klawiatura i fokus, stany ładowania/błędu/puste, E2E Company → Prospect → Pipeline → Follow-up → Activity i ocena ręczna — zapis w bazie testowej kontenera, nie w bazie użytkownika). Test obciążeniowy 10 000 firm: wszystkie listy ≤ 130 ms, Dashboard bez cache ~300 ms.

**Nie naprawione (decyzje / poza zakresem):** klucz SSH w historii Git (unieważnienie — właściciel), wpływ researchu bez znalezionych osób na Lead Potential i pasma wielkości w Contact Priority (zmiany scoringu — decyzja właściciela), retencja tabel logów, model zaproszeń członków, dzienny limit ClickUp w UTC, pełna optymalizacja licznika kolejki Lead Intelligence przy dużej skali (M010).

**Test na żywo (LocalWP, 10.10.2026, po wgraniu etapu 7, tylko odczyt):** testy JS na komputerze z LocalWP 35/35, 19/19, 5/5; `GET /action-center` 200 / 90 ms, KPI i kolejki bez zmian (2 / 0 / 0 / 3 / 3 / 46; Top 27, Needs Attention 60, Blocked 1), priorytety NozziTec 75, FLOWAIR 74, ZAMET 73, FAMUR 65, PCO 65; listy i karta firmy 56–84 ms; `/dashboard` pobierane dopiero po rozwinięciu sekcji; drill-down KPI bez filtrów (46 = 46); w rzeczywistych kontaktach brak adresów ogólnych jako e-maili osób i brak linków spoza http/https; Discover, Prospects, Pipeline, Settings i karta firmy bez poziomego przewijania i bez błędów konsoli; stopka karty firmy zawija przyciski. Zapisów nie testowano na żywo (bez zmian danych użytkownika) — E2E w bazie testowej kontenera.

**Rekomendacja:** READY WITH CONDITIONS — warunki w `docs/QA-REPORT.md` §8.

## Etap 10 — Decision Maker Finder 2.0 + Phone Intelligence + Cold Calling Workspace (10.10.2026)

Szczegóły: ARCHITECTURE.md §17, `docs/QA-REPORT.md` §11, wdrożenie: `docs/DEPLOYMENT.md`. Migracja M010 (schemat v10, tylko nowe tabele / kolumny / indeksy). Scoringi bez zmian.

**Zrobione:**
- telefony firm ze strony (linki `tel:`, Schema.org, treść po „tel.”), normalizacja E.164, odrzucanie NIP / REGON / KRS / kodów pocztowych / rachunków / kodów produktów, deduplikacja, typy (centrala, oddział, sprzedaż, serwis), numer główny (centrala > Kontakt > stopka), ręczne numery organizacji (nigdy nie nadpisywane), ręczne odświeżenie ze strony — bez ponownej analizy i bez crawla;
- Apollo People Search: stanowiska EN + PL, powiązanie osoby z firmą tylko przez domenę / ID organizacji Apollo, zapisany identyfikator osoby;
- „Odblokuj telefon” (Apollo, płatne): wyłączone domyślnie, owner / admin, okno z nieznanym kosztem + osobne potwierdzenie, idempotencja i ochrona przed podwójnym kliknięciem, odbiór wyniku w tle (`poll_only`), limit dzienny, bez e-maili, bez automatycznego ponawiania; bezpieczny test `wp ubr apollo-phone-test` (domyślnie bez zapytania);
- Cold Call Readiness (READY_DIRECT / CALL_COMPANY / FIND_PHONE / RESEARCH / BLOCKED), wielkość firmy w pasmach z „Brak danych”;
- Company Ownership: „Przejmij firmę” (atomowo w bazie, wyścig → 409), przekazanie tylko z uprawnieniem, zakaz kontaktu, ostatni kontakt i wynik rozmowy w istniejącej historii aktywności;
- Discover i Prospects: nowe kolumny (Firma, Wielkość, Strona WWW z „Podgląd”, Ocena, Decydent, Telefony, Opiekun / CRM, Status kontaktu, Akcje), wspólne komponenty, karty < 1280 px, filtry na całym zbiorze i nowe sortowania, widok „Wolne firmy z telefonem”, szybkie akcje (tel:, kopiuj, przejmij, wynik rozmowy) — Prospects bez poziomego przewijania;
- Action Center: kolejka „Gotowe do telefonu”, DNC i firmy innych opiekunów poza moimi priorytetami; KPI bez zmian.

**Testy (kontener):** PHP 986/986 (+150 w `tests/calling-p10.php`); JS 18/18 (nowy `calling.test.mjs`) + 19/19 + 35/35 + 5/5; Playwright 71/71 (5 szerokości × Discover / Prospects, Podgląd, kopiowanie, tel: bez zapisu, przejęcie, wynik rozmowy z klawiaturą, filtr, sortowanie, „Odblokuj telefon” na mocku, telefony w karcie firmy, zakaz kontaktu). Test obciążeniowy 10 000 firm: listy 33–112 ms, filtry telefonów 165–220 ms, Action Center bez cache ~390 ms.

**LocalWP (11.10.2026):** zainstalowane za zgodą właściciela, kopia bazy przed migracją, M010 → v10 (po poprawce słowa zastrzeżonego `manual` w MySQL 8.4 — ok. 4 min błędów 500 po pierwszej próbie). Testy na żywo tylko do odczytu: listy, filtry, KPI bez zmian, podgląd, 5 ekranów × 5 szerokości, strona główna 200 — szczegóły w `docs/QA-REPORT.md` §11. W danych LocalWP brak numerów i danych o zatrudnieniu; numerów nie da się odzyskać z zapisanych analiz — `wp ubr phones-backfill` (próbnie / `--execute`) czeka na zgodę.

**Company Phone Backfill na LocalWP (11.10.2026, za zgodą):** istniejące odświeżanie numerów ze strony (bez Website Analyzera, bez wyszukiwania firm, bez Apollo) dla 50 firm z domeną, po pilocie na 5 firmach. Wynik: 39/50 firm z numerem (78%), 230 numerów (200 krajowych), 0 duplikatów, 0 bez źródła; 20 numerów głównych do ręcznej weryfikacji. Poprawki z danych rzeczywistych: klasyfikacja z etykiety (osoby, działy, centrala), spółki z grupy, `tel:` ≠ tekst, robots.txt, `<base href>`, dodatkowa podstrona (lista krajów), numery zagraniczne, „Infolinia” pod oddziałami, numer w kolejce „Gotowe do telefonu”. Testy: PHP 1012/1012. Szczegóły: `docs/QA-REPORT.md` §11.

**NOT VERIFIED:** zapytania do prawdziwego Apollo (People Search i Phone Enrichment); LOCKED w statusie telefonu nie jest używany (API nie potwierdza go bez zapytania).

## Etap 10.2 — Direct Decision Maker Phones (11.10.2026)

Szczegóły: ARCHITECTURE.md §18, `docs/QA-REPORT.md` §12. Migracja M011 (schemat v11). Scoringi bez zmian (telefon nie jest danymi wejściowymi).

**Zrobione:** dowód powiązania numer ↔ osoba (`PersonPhoneExtractor`: VERIFIED / LIKELY / UNCERTAIN / REJECTED), właściciel numerów firmy (osoba / dział / ogólny / oddział / nieustalony), wyszukiwanie telefonów decydentów ze stron źródłowych numerów + podstron zarząd / zespół (bez Apollo, limity, robots.txt), dopasowanie do kontaktów po firmie + imieniu i nazwisku, nowi decydenci przez ścieżkę Decision Maker Findera, strażnik literówek, decyzje organizacji (potwierdź / odrzuć / przypisz / usuń — prywatne, nienadpisywane), READY_DIRECT tylko z potwierdzonym numerem, filtry (potwierdzony / do weryfikacji / tylko firma / brak / źródło / stanowisko), listy i karta firmy z wiarygodnością i źródłem, Dashboard: „bezpośrednio do decydenta” / „przez centralę”, kontrolowany test Apollo People Search.

**Testy:** PHP 1082/1082, JS 18/18 + 19/19 + 35/35 + 5/5, Playwright 55/55 (nowe) + 71/71 (regresja P10) w kontenerze.

**LocalWP:** kopia bazy, M011 → v11, analiza 71 z 73 numerów „dział / osoba” (40 to numery osób), 7 nowych decydentów ze źródłem, 0 potwierdzonych numerów decydentów (1 prawdopodobny — SECO/WARWICK, do potwierdzenia), People Search działa (nazwiska zamaskowane), Phone Enrichment wyłączony.

## Etap 10.3 — Apollo Contact Matching & Phone Unlock (11.10.2026)

Szczegóły: ARCHITECTURE.md §19, `docs/QA-REPORT.md` §13. Migracja M012 (schemat v12). Scoringi i gotowość bez zmian.

**Zrobione:** audyt możliwości Apollo (dokumentacja + rzeczywisty test People Search z 10.2), kandydaci z People API Search przy firmie (bez e-maili i numerów, bez duplikatów), dopasowanie do kontaktów EXACT / STRONG / POSSIBLE / NO_MATCH / CONFLICT (maska nazwiska nigdy nie daje EXACT, bez zgadywania), identyfikator Apollo kontaktu = zatwierdzone dopasowanie organizacji (historia, zatwierdzający, daty, konflikty 409), „Szukaj decydentów w Apollo” w karcie firmy (zgoda, cache, 24 h na firmę, limit dzienny, blokada równoległa), widok kandydatów z akcjami (zatwierdź / odrzuć / odłącz / sprawdź ponownie / połącz / szczegóły / sprawdź odblokowanie), dostępność telefonu z sygnału Apollo (AVAILABLE / UNKNOWN / UNAVAILABLE; LOCKED nieużywany), Phone Unlock na zatwierdzonym identyfikatorze z blokadą po stronie serwera (preflight + `request()`), powody „Nie kontaktować” i „numer już potwierdzony”, koszt „wymaga potwierdzenia” (stawki tylko z dokumentacji), Discover / Prospects: sygnał Apollo i „Sprawdź Apollo” bez zapytań przy renderowaniu, retencja kandydatów.

**Testy:** PHP 1174/1174, JS 18/18 + 5/5 + 19/19 + 35/35, Playwright 76/76 (nowe) + 55/55 (P10.2) + 71/71 (P10) w kontenerze — Apollo wyłącznie na mockach.

**LocalWP:** zob. `docs/QA-REPORT.md` §13 (walidacja na danych rzeczywistych).

**NOT VERIFIED:** rzeczywiste People Enrichment / Phone Enrichment (płatne — zablokowane), koszt na koncie, znaczenie pola `has_direct_phone` (nieudokumentowane).

## Etap 10.4 — Performance Optimization & Progressive Discovery (11.10.2026)

Szczegóły: ARCHITECTURE.md §20, `docs/QA-REPORT.md` §14. Migracja M013 (schemat v13). Scoringi bez zmian.

**Zrobione:** weryfikacja zlecana po każdej stronie wyników (Progressive Discovery), okno firm w kroku weryfikacji z równoległym pobieraniem stron jednym procesem (curl_multi) i kolejnością wg gotowości, wykorzystanie przerw limitów rejestrów na transfery, atomowy limit częstotliwości dostawców, wspólna pamięć podręczna stron (M013) z zapamiętywaniem niedostępnych serwerów, numery firmy już przy weryfikacji, statusy etapów w Discover (weryfikacja / WWW / telefony / decydent), lekkie odświeżanie zmienionych wierszy, rzadsze sprawdzanie kolejki przy statusie, gotowość: nieaktywne → BLOCKED, niezgodność domeny → RESEARCH, usunięte linki tel: i przycisk „Zadzwoń”.

**Pomiary (kontener, mocki):** 50 firm 151,5 s → 83,4 s, 10 firm 31,6 s → 18,8 s, te same wyniki i liczba zapytań; DM Finder po weryfikacji 2,1 s → 1,2 s.

**Testy:** PHP 1222/1222, JS 18/18 + 5/5 + 19/19 + 35/35, Playwright 58/58 (nowe) + 76/76 + 55/55 + 71/71 w kontenerze.

**LocalWP:** zob. `docs/QA-REPORT.md` §14.4.

## Wyników na stronie — Discover i Prospects (11.10.2026)

**Zrobione:**
- Lista „Wyników na stronie:” (10 / 25 / 50 / 100, domyślnie 25) nad tabelą Discover, obok filtrów. W Prospects istniejący wybór „Na stronę” ujednolicony.
- Paginacja po stronie serwera (`per_page`, maks. 100). Zmiana: jedno zapytanie listy, powrót na 1. stronę, filtry i sortowanie bez zmian, bez przeładowania strony.
- Wybór zapamiętany w localStorage osobno dla każdej listy (`ubr_per_page_discover`, `ubr_per_page_prospects`). Wartość spoza listy daje 25.
- Odpowiedź starszego żądania (np. odświeżenia w tle) nie nadpisuje nowszej listy.
- Lekkie odświeżanie zmienionych wierszy (10.4) obejmuje do 100 widocznych wierszy.
- Paginacja pokazuje zakres („1–25 z 120”).
- Moduł `resources/js/ubr/per-page.js`.

**Testy (kontener):**
- JS `tests/js/per-page.test.mjs`: 31/31.
- Playwright: 95/95 (10→25→50→100, paginacja, zapamiętywanie, filtry i sortowanie, aktualizacja statusu przy 100 wierszach, brak zbędnych zapytań, 1920–390 px).
- Regresja: PHP 1222/1222, E2E 58/58 · 71/71 · 55/55 · 76/76.

## Etap 10.4.3 — Discover Optimization + Website Age Intelligence + Claim-Based Decision Maker Finder + ClickUp Auto-Sync (11.10.2026)

Szczegóły: ARCHITECTURE.md §21, `docs/QA-REPORT.md` §16. Migracja M014 (schemat v14). Wagi scoringów bez zmian.

**Zrobione:**
- Worker weryfikacji: kroki 8 s z łańcuchowaniem, blokada 60 s odnawiana, zwrot nieprzetworzonych pozycji (przyczyna przerw z diagnostyki 10.4.1).
- Discover: ogólny e-mail firmy z weryfikacji, Opportunity Score w tle, Website Age w tle, decydent „Po przejęciu”; listy nie zlecają Lead Intelligence.
- Website Age Intelligence: odcisk projektu, porównanie kopii Wayback, wykrywanie redesignu, pewność, status, dowody, cache, limity, ponowienia; sekcja w karcie firmy, etykieta na liście, filtr 5+/7+/10+/nieznany.
- „Przejmij firmę”: Decision Maker Finder w tle (bez płatnych zapytań), statusy w karcie, „Ponów wyszukiwanie decydenta” z ochroną przed duplikatami, blokada dla zakazu kontaktu / BLOCKED.
- ClickUp (domyślnie wyłączone — przełącznik właściciela w Settings → ClickUp): jedno zadanie po przejęciu i aktualizacja po znalezieniu decydenta, mapowanie pól (propozycja, bez tworzenia pól), ochrona ręcznych zmian z konfliktami, idempotencja, sprawdzenie wyniku po timeoucie, ograniczone ponowienia.

**Testy (kontener, usługi zewnętrzne mockowane):** PHP 1337/1337 (nowe `tests/discover-claim-p1043.php`), JS 35/35 (nowe `claim.test.mjs`) + 18 + 31 + 5 + 19 + 35, Playwright 82/82 (nowe) + regresja 58/58 · 71/71 · 55/55 · 76/76 · 95/95.

**Produkcja / LocalWP:** zob. `docs/QA-REPORT.md` §16.

## Etap 10.5 — Sequences (11.10.2026)

Szczegóły: ARCHITECTURE.md §22, `docs/QA-REPORT.md` §17. Migracja M015 (schemat v15). Wysyłka domyślnie wyłączona.

**Zrobione:** biblioteka kampanii (wspólne / prywatne, dowolna liczba kroków, kolejność, duplikowanie, archiwizacja, podgląd), przypisanie do firmy jako niezależna kopia z indywidualną edycją, personalizacja ze zmiennymi i wartościami ręcznymi, skrzynki SMTP pracowników (szyfrowanie, test, DNS, limity, odłączanie), harmonogram z oknem wysyłki i strefą czasową, trwała kolejka i worker (blokady, identyfikatory operacji, wynik nieznany, bezpieczne ponowienia, limity), jedna aktywna sekwencja na odbiorcę, wykluczenia i rezygnacja (link + List-Unsubscribe), pauza / wznowienie / anulowanie, widoki Active / Scheduled / Sent / Mailboxes / Settings, tryb testowy, przygotowanie pod AI Outreach Assistant, status pipeline „Mailing”.

**Testy (kontener):** PHP 1450/1450 (+113: `tests/sequences-p105.php`, w tym lokalny serwer SMTP), JS 34/34 (nowe) + 35 + 18 + 31 + 5 + 19 + 35, Playwright 79/79 (nowe) + regresja 82/82 · 58/58 · 71/71 · 55/55 · 76/76 · 95/95.

## Następny etap

Przed wdrożeniem na Hostinger: unieważnienie klucza SSH i commit jego usunięcia (`docs/SECURITY-KEY.md`), sprawdzenie wymagań serwera i konfiguracji produkcyjnej (`docs/DEPLOYMENT.md`), decyzja o Website Analyzerze na Hostingerze (VPS / osobny renderer / tryb PARTIAL). Do decyzji: zmiany scoringu D12/D13 z raportu QA, retencja logów, ClickUp Live (lista kontrolna w QA-REPORT §10), Apollo People Search, klucze CEIDG/GUS.

## Logowanie /app/login (12.10.2026)

Własny ekran logowania w stylu aplikacji, przekierowanie do aplikacji po zalogowaniu, limit prób, branding ekranów resetu hasła, poprawka linku „Wyloguj”. Szczegóły: ARCHITECTURE.md §23. Testy (kontener): PHP 1481/1481 (+31: `tests/login-app.php`), Playwright 32/32 (nowe) + kontrola ekranów aplikacji.
