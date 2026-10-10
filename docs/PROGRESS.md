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

## Następny etap

Kolejne moduły wg decyzji właściciela (nie rozpoczęte). Do zrobienia przy ClickUp: konfiguracja aplikacji OAuth i pierwszy kontrolowany eksport. Do decyzji: włączenie Apollo People Search (klucz master, bez enrichmentu), klucze CEIDG/GUS, rotacja klucza SSH z repozytorium.
