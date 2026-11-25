# Ugly but Rich — pełny audyt projektu (2026-11-01)

Audyt przed kolejnym etapem rozwoju. Kodu aplikacji nie zmieniano. Ten plik jest jedynym nowym plikiem w projekcie.

Stan kodu: `main` @ `0d05e59` + 5 niezacommitowanych plików (logowanie, link „Panel WordPress”).

## Jak zbierano dowody

| Środowisko | Co zrobiono | Czego nie zrobiono |
|---|---|---|
| Kod i dokumentacja | Przegląd w 6 niezależnych obszarach; najpoważniejsze ustalenia sprawdzone ponownie bezpośrednio w kodzie | Nie każdy plik przeczytano linia po linii |
| LocalWP | Strona była zatrzymana (`502`). Nie uruchamiano jej. Katalog MySQL skopiowano do scratchpada | Oryginalna baza nie była dotykana |
| Izolowana kopia | Prywatny MySQL 8.4 + kopia WordPressa bez kluczy API, z blokadą poczty i wyjścia HTTP. Tam uruchomiono testy i odczyty danych. Po audycie kopię usunięto | — |
| Produkcja `uglybutrich.ohsofresh.top` | Wyłącznie publiczne żądania statusów (głównie `HEAD`) i licznik z publicznego `/wp/v2/users` | Bez logowania, bez SSH, bez bazy, bez pobierania plików. Klucza nie pobrano |
| GitHub | Publiczne API: widoczność repozytorium | — |

**Ograniczenia:** UI nie zostało obejrzane w przeglądarce, bo LocalWP nie działał; audyt UX to przegląd kodu widoków. Schemat bazy, flagi, cron i dane produkcji są nieznane. Na Hostingerze niczego nie mierzono. Nie wykonano `EXPLAIN` ani testów obciążeniowych. Płatnych API nie wywoływano, e-maili nie wysyłano, ClickUp nie był dotykany.

**Testy w scratchpadzie:** projekt zabrania migracji, a `tests/run.php` sam je uruchamia (`tests/run.php:402-403`). Dlatego testy poszły wyłącznie na kopii. Harness renderu poprawiono tylko w kopii, bo ma wpisane ścieżki kontenera (zob. sekcja I).

---

## A. Executive Summary

Aplikacja jest obszerna i funkcjonalnie dojrzalsza, niż zwykle bywa projekt tej wielkości: 227 plików PHP, 43 950 linii, 48 tabel, 15 migracji, 14 modułów. Fundament jest dobry: izolacja organizacji, SQL przez `prepare`, ochrona SSRF, atomowe claimy kolejek i limity Apollo. W audycie nie potwierdzono żadnego IDOR-a ani SQL Injection.

**Najpilniejszy problem nie dotyczy kodu aplikacji.** Prywatny klucz SSH jest publicznie dostępny w dwóch miejscach:

- na produkcji pod `/wp-content/themes/uglybutrich/key`: odpowiedź `200`, 464 bajty, data 10.10.2026, czyli rozmiar identyczny jak klucz z historii Gita (treści nie pobierano, więc tożsamość wynika z nazwy, rozmiaru i daty),
- w historii **publicznego** repozytorium GitHub `ohsofreshcreative/uglybutrich` (commity `9b24093`, `ac21be0`).

Dokumentacja traktuje klucz jako problem tylko historii prywatnego Gita. To nieaktualne: należy przyjąć, że klucz jest skompromitowany.

Pozostałe główne wnioski:

- **Testy przechodzą w 100% (PHP 1481/1481, JS 177/177),** ale tylko po poprawieniu harnessu i bez prawdziwego ClickUp, SMTP-TLS, CEIDG, GUS, Wayback ani płatnych ścieżek Apollo. Testy E2E Playwright cytowane w dokumentacji nie istnieją w repozytorium.
- **`wp ubr test` nie jest bezpieczny na prawdziwej bazie:** może wysłać realne e-maile i resetuje wersję schematu.
- **Sequences nie powinny być włączone** przed naprawą blokad i dodaniem obsługi odpowiedzi. Domyślnie wysyłka jest wyłączona i dobrze zabezpieczona, ale nie widzi odpowiedzi odbiorcy, więc follow-upy pójdą też do osoby, która odpisała „nie”.
- **Apollo:** w obecnej konfiguracji kredyty są pod kontrolą. Płatne telefony omijają jednak `ApolloBudget`, co ma znaczenie po włączeniu flagi.
- **Złożoność jest większa niż dane:** w LocalWP 65 firm, 2 prospekty, 1 użytkownik, 0 kampanii, 0 połączeń ClickUp. Około 14 punktacji i etykiet w UI, 32 filtry w Prospects, a 829-liniowy silnik reguł ClickUp nigdy nie dotknął prawdziwego API.

**Werdykt:** architektura nadaje się do rozbudowy. Nowe funkcje można zacząć po krótkim sprincie P0 i kilku pozycjach P1 (sekcja L). Nie trzeba niczego przepisywać.

---

## B. Module Inventory

Statusy jak w poleceniu. **WORKING** przyznano tylko przy dowodzie niezależnym od mocków: rzeczywisty przebieg w tym audycie albo zapis w bazie LocalWP. Data dowodu ma znaczenie, bo część kodu zmieniono później. Kolumna „Mock” mówi o testach na atrapach.

| Moduł / funkcja | Status | Dowód rzeczywisty | Mock | Klucz / konfiguracja |
|---|---|---|---|---|
| Organizacje, role, izolacja | IMPLEMENTED | LocalWP ma 1 użytkownika; wielu użytkowników nigdy na żywo | tak, przez prawdziwy REST | — |
| Migracje M001–M015 | WORKING | LocalWP v15 na MySQL 8.4; ponowne przejście w tym audycie | — | — |
| Logowanie `/app/login` | IMPLEMENTED; limit prób PARTIAL | Produkcja odpowiada `200` | tak | — |
| Discover: Apollo Organization Search | WORKING (10.2026) | 8 płatnych wywołań `200` w `ubr_api_requests`, 2 z cache | tak | `UBR_APOLLO_API_KEY` |
| Discover: Wikidata | WORKING (10.2026) | Firmy w bazie, wpis w PROGRESS | tak | — |
| Discover: Overpass/OSM | PARTIAL | Timeouty na żywo, brak dowodu po naprawie | tak | — |
| Import jako osobny krok | NOT IMPLEMENTED | Ingest odbywa się w `DiscoveryRunner::fetchStep` | — | — |
| Deduplikacja, limity, paginacja | IMPLEMENTED; możliwy duplikat przy wyścigu (F-D6) | Brak duplikatów w 65 firmach | tak | — |
| Weryfikacja domen | WORKING (10.2026) | 35 confirmed, 12 uncertain, 1 mismatch, 17 unavailable | tak | — |
| KRS, Biała lista VAT | WORKING (10.2026) | 27 + 32 odpowiedzi `200` w logu; 25 firm VERIFIED | tak | — |
| CEIDG, GUS BIR | IMPLEMENTED, nigdy nie uruchomione | Brak | **brak testu ścieżki pozytywnej** | `UBR_CEIDG_API_TOKEN`, `UBR_GUS_API_KEY` |
| Company Identity Finder | NOT IMPLEMENTED | Pod żadną nazwą | — | — |
| Website Analyzer + render | WORKING lokalnie | 12 analiz `completed`; 8 testów na prawdziwym Chrome w tym audycie | tak | Node + Chrome |
| Website Analyzer na Hostingerze | UNKNOWN | Oczekiwany tryb PARTIAL, niezmierzony | — | `proc_open`, Node |
| Opportunity Score | IMPLEMENTED; zawężona skala | 3 firmy 32–37 pkt | tak | — |
| Website Age | IMPLEMENTED | Brak żywego przebiegu; `UBR_WAYBACK_*` domyślnie włączone | tak | — |
| AI Vision | IMPLEMENTED, wyłączone | Brak | schemat | `UBR_AI_VISION_*` |
| Lead Intelligence | WORKING (10.2026) | 65 ocen w bazie | tak | — |
| Decision Maker Finder (KRS + strona) | WORKING (10.2026) | 10 uruchomień `completed`, 17 kontaktów | tak | — |
| Telefony firm | WORKING (10.2026) | 230 numerów w bazie | tak | — |
| Telefony decydentów | IMPLEMENTED | 2 powiązania, 0 potwierdzonych wg PROGRESS | tak | — |
| Apollo People Search | IMPLEMENTED, wyłączone | 1 test `200` (11.10), `billable=0` wg dokumentacji Apollo | tak | `UBR_APOLLO_PEOPLE_ENABLED` |
| Apollo Phone Enrichment | IMPLEMENTED, wyłączone | Brak, 0 odblokowań | tak | `UBR_APOLLO_PHONE_ENABLED` |
| Prospects, Pipeline, notatki, follow-upy | IMPLEMENTED | Zapisy klikane na żywo tylko w etapie 4 | tak | — |
| Przejęcie firmy, wynik rozmowy, DNC | PARTIAL (P1-1, P1-5) | Brak żywego przebiegu | tak | — |
| Dashboard / Action Center | IMPLEMENTED | Odczyty w QA-REPORT; 0 follow-upów w danych | tak | — |
| ClickUp | IMPLEMENTED, nieskonfigurowane | 0 połączeń, nigdy prawdziwe API | tylko | OAuth + `UBR_ENCRYPTION_KEY` |
| Sequences: kampanie, harmonogram, kolejka | IMPLEMENTED | 0 kampanii | mock + lokalny SMTP bez TLS | — |
| Sequences: blokady przy wysyłce | PARTIAL | — | tak | — |
| Sequences: odpowiedzi, odbicia asynchroniczne | NOT IMPLEMENTED | Brak jakiegokolwiek kodu IMAP / In-Reply-To / DSN | — | — |
| AI Outreach Assistant | NOT IMPLEMENTED (stub, 501) | — | — | — |
| WP-CLI `wp ubr` | IMPLEMENTED | `analyze --check` działa | częściowo | — |

**Implementowane, ale nieużywane:** `WebsiteChecker`, `partials/discover-domain-cell`, `ClickUpRunner::setFields`, `ClickUpTaskBuilder::fieldValues`, `ApiRequestRepository::countForSearch`, kolumna `credits_reported` (zawsze NULL), `safeTel`, `transferProspect`, `callFilterCount`, `funnelMax`, `statusCount`, `top_leads`, `websiteStatusLabel`.

**API bez UI:** `/pipeline/agenda`, `/lead-intelligence/top`, `/sequences/messages/{id}/ai-suggest`, `/apollo-matches/{id}/history`, `/analyses/{id}`, `/prospects/{id}/transfer`, `/integrations/apollo/people-test` (celowo). Nie znaleziono wywołań nieistniejących tras.

**Dublowane:** `DELETE /prospects/{id}` i `/archive` (ten sam kod), `/dashboard` i Action Center, `IN_PROGRESS_STATUSES` = `CONVERSATION_STATUSES`, 4 warianty pasm wielkości firmy.

---

## C. Architecture Review

**Ocena: dobra, z punktowymi nadużyciami.** Nie ma powodu do przepisywania.

| Warstwa | Stan | Dowód |
|---|---|---|
| Domain | Czysta | 0 SQL, 0 HTTP, 0 importów Infrastructure |
| Http | Cienka | 15 kontrolerów, 1295 linii; jeden wyciek SQL `IntegrationController.php:86-87` |
| Services | Granica repozytoriów nieszczelna | SQL w `SequenceService` (54), `ApolloContactService` (19), `SequenceRunner` (10), `OwnershipService` (7) |
| Kontener | Service locator zamiast wstrzykiwania | 198 wywołań `app(`; `ContactResearchService` ma 12 zależności w konstruktorze + 10 `app()` |
| Kierunek zależności | 2 odwrócenia | `MemberRepository.php:86` woła Services; `ApolloProvider.php:16` importuje `ApolloBudget` |

**Przerośnięte klasy (publiczne / prywatne metody):**

| Klasa | Linie | Metody | Decyzja |
|---|---|---|---|
| `SequenceService` | 1255 | 30 / 29 | Podzielić; tu leżą też błędy stanów (F-Q5) |
| `UbrCommand` | 948 | — | Podzielić przy okazji, 55 × `app()` |
| `ClickUpRuleService` | 829 | 14 / 12 | Nie rozbudowywać przed pierwszym realnym eksportem |
| `PersonPhoneExtractor` | 776 | 3 / 18 | Spójna, zostawić |
| `ContactResearchService` | 750 | 12 / 6 | Kolejka + widok + orkiestracja; rozdzielić, gdy będzie potrzeba |

`ContactResearchService`, `DecisionMakerFinderService`, `CompanyPhoneService` i `DecisionPhoneService` wyglądają na duplikaty, ale nimi nie są. Wspólny jest tylko szkielet (cooldown, robots, fetch).

**Pozostałości motywu bazowego:** 55 plików w `app/Blocks`, 180 śledzonych plików bloków, 9220 linii PHP spoza `app/Ubr`, 77 publicznych widoków. Tylko strona główna przekierowuje do logowania; publiczne podstrony motywu pozostają dostępne, a bez ACF Pro działają z błędem krytycznym. Zależności bez użycia: `react`, `react-dom`, `@vitejs/plugin-react`, pakiet `gsap` (ładowany z CDN), `spatie/pdf-to-image`, `sage-woocommerce`. Są trzy lockfile’e.

**Spójność kolejek:** 9 kolejek ma różne statusy końcowe (`completed`, usunięcie wiersza, `success`, `sent`, `unknown` / `unknown_result`) i różne mechanizmy blokad. Claimy są atomowe, więc to problem utrzymania, nie integralności.

**Dokumentacja rozjechana z rzeczywistością:**

| Dokument | Twierdzenie | Fakt |
|---|---|---|
| `DEPLOYMENT.md:24` | `vendor/` jest w repozytorium | Ignorowane, 0 plików śledzonych |
| `DEPLOYMENT.md:33` | `npm ci && npm run build` | Aktualny jest `yarn.lock`; `package-lock` ma starszego Vite |
| `DEPLOYMENT.md:33` | Uruchom testy przed wdrożeniem | Testy są niebezpieczne na prawdziwych danych (P0-3) |
| `QA-REPORT.md` §17 | Produkcja niewdrożona | Na produkcji są pliki z 12.10, w tym `ubr-p105-files.tar.gz` |
| `QA-REPORT.md` §17 | M015 na LocalWP nietestowane | LocalWP ma `ubr_db_version = 15` |
| `AGENTS.md:247` | Wdrożone M001–M009 | M001–M015 |
| `AGENTS.md:295,331,355` | Ścieżki `h2otwock` | Inna strona, inny socket |
| `SECURITY-KEY.md`, `AGENTS.md` | Klucz w historii wymaga unieważnienia | Klucz też na serwerze i w publicznym repo |

---

## D. Functional Audit

### Discover

- Zapytania Apollo, cache 12 h, limity i deduplikacja działają. Nie znaleziono ścieżki przypisującej firmie dane z filtra.
- **F-D1** `DiscoveryRunner.php:178-187`: `attempts` nie zeruje się między stronami, więc 3 rozproszone odpowiedzi 429 pomijają dostawcę.
- **F-D2** `DiscoveryCriteria::LIMITS` jest tylko doradcze; API przyjmuje 10–500.
- **F-D3** Filtr „przeanalizowane” używa `analysis_status` zamiast `analyzed_at` (`ResultRepository.php:213-214`, `ProspectRepository.php:166-167`), wbrew regule z Prompt 7.
- **F-D4** Smart Ranking: `li.has_blocker ASC` przy LEFT JOIN stawia firmy bez oceny na górze (`LeadFilters.php:69`). Sprawdzone w kodzie.
- **F-D5** `QueueMonitor` oznacza zdrowe zadanie jako błąd po przerwie crona ponad 15 min (`QueueMonitor.php:56` przed `:70`), a zadanie zabijane w kółko jest wznawiane bez końca (`update()` odświeża `updated_at`, `SearchRepository.php:64`). Sprawdzone w kodzie.
- **F-D6** `CompanyIngestService.php:44-57`: ponowienie po wyścigu nie zadziała bez klucza unikalnego; możliwe duplikaty (PLAUSIBLE).

### Company Verification

- VERIFIED tylko z rejestru, zapytania wyłącznie po identyfikatorze. Reguły są dotrzymane.
- **F-V1 (P2)** `CompanyVerifier.php:444-450` + `CompanyStatusDecider.php:49-63`: awaria rejestru przy ponownej weryfikacji obniża `verified`/`inactive` do `uncertain` i ustawia datę sprawdzenia, więc firma jest „świeża” przez 30 dni.
- **F-V2 (P2)** Limit VAT 80/dzień: odłożenie zużywa próby (`RegistryHttp.php:43-44`); przy większym wyszukiwaniu firmy zostają UNCERTAIN (mechanika potwierdzona, skala niezmierzona).
- **F-V3 (P3)** `CeidgClient.php:55`: 401 traktowane jak „brak podmiotu”. `GusBirClient.php:87-91`: pusta odpowiedź po wygaśnięciu sesji mogłaby trafić do cache na 7 dni (PLAUSIBLE).
- **F-V4 (P3, PLAUSIBLE)** `KrsClient.php:236-245`: wzorzec `/zawiesz/` łapie `czyZawieszona` członka zarządu.
- **F-V5 (P3)** `CompanyVerifier.php:354-371`: `location_confidence=CONFIRMED` przy samym mieście.
- 40 z 65 firm w LocalWP to UNCERTAIN, głównie bez NIP/KRS. Brak Company Identity Finder to główne ograniczenie jakości danych.

### Website Analyzer i Website Age

- Wagi A45/B20/C25/D10, brak przeskalowania i próg 50% pokrycia są zgodne z regułami.
- Render: prawdziwy Chrome na fixture’ach przechodzi 8/8, łącznie z blokadą przekierowania na metadata.
- **F-A1 (P3)** Niektóre maksima są nieosiągalne (`a_ux_navigation` 3/5, `a_ux_structure` 2/3). Bez AI Vision sufit to 85, a próg „high” jest niedostępny. Realne wyniki 32–37 sprawiają, że filtr `opportunity_high ≥ 60` jest pusty.
- **F-A2 (P3)** Opportunity się starzeje: limit dla nieaktywnych firm liczony tylko podczas analizy (`WebsiteAnalyzerService.php:201`).
- **F-A3 (P2)** `WebsiteAgeRunner.php:82-84` + `WebsiteAgeService.php:114,141`: po limicie dziennym `countDue` nadal liczy firmy, więc runner może łańcuchować puste kroki do północy. Na 2 procesach PHP to realny koszt. Nie sprawdzono, czy `WorkerEndpoint` to wyhamowuje.
- **F-A4 (P3)** `checkHttpsRedirect` (`WebsiteAnalyzerService.php:361-380`) woła `wp_remote_get` bez przypięcia IP i bez `PageFetcher`, wbrew regule.

### Lead Intelligence

- **F-L1 (P3)** Wersjonowanie nie działa zgodnie z regułą: porównywany jest tylko `LeadIntelligenceService::VERSION` (`LeadIntelligenceRepository.php:72,74,110`), więc podbicie wersji kalkulatora nic nie przelicza. `RESEARCH_VERSION` nie zmienił się od `1349fb3`, mimo zmian ekstraktora.
- **F-L2 (P3)** `PeopleExtractor.php:236` → `ContactPriorityCalculator.php:102` (+7 pkt) i `ContactConfidence.php:73`: telefon wpływa na scoring, wbrew regule Prompt 10.
- **F-L3 (P3, decyzja)** Lead Potential i Opportunity mierzą w ok. 85–90% to samo.

### Decision Maker Finder

- Nie znaleziono ścieżki zgadywania osób ani e-maili. Maski KRS tylko potwierdzają.
- **F-C1 (P3, PLAUSIBLE)** `ContactVerifier.php:73-90`: osoba spoza zarządu o tych samych inicjałach i długościach może dostać funkcję z KRS i VERIFIED. `:107` stawia KRS przed sprawdzeniem `mismatch` domeny.
- **F-C2 (P3)** `DecisionPhoneService.php:105-106`: limit i blokada zużywane przed pobraniem, także przy dry-run.
- 3 z 5 firm w teście na żywo nie publikuje nazwisk, więc moduł bez płatnego źródła ma ograniczone pokrycie.

### Prospects, Pipeline, Calling

Główne problemy to P1-1, P1-5 i P2-2 do P2-5 w sekcji M. Dodatkowo:

- `recordCallOutcome` nie jest atomowe: błędny kontakt lub data zostawiają wykonane przejęcie (`OwnershipService.php:173-175`).
- Zmiany właściciela w `ProspectService.php:49`, `OwnershipService.php:56`, `MemberRepository.php:76` nie tworzą `prospect_event`, wbrew regule.
- `touch()` cofa `last_activity_at` (`PipelineService.php:330`).
- Spójność reguł `classify`/`sqlType` i `CallReadiness`/`CallFilters` jest utrzymana, ale test (`tests/action-center.php:201`) nie obejmuje gałęzi `analyzed_at` ani DNC.

### ClickUp

Pełna implementacja na mockach, która nigdy nie dotknęła prawdziwego API. Tokeny szyfrowane, OAuth poprawny, deduplikacja przez rekord eksportu. Luki: P2-8, P2-9, P2-10 w sekcji M. Drobne: F-I1–F-I3 w sekcji F.

### Sequences

Ścieżka kampania → kopia → start → harmonogram → claim → wysyłka jest kompletna. Zabezpieczenia przed podwójną wysyłką są mocne: atomowy claim, `send_token`, Message-ID przed wysyłką, `sending → unknown_result` bez ponowień, jedna sekwencja na odbiorcę, blokada drugiego workera, sprawdzenie wykluczeń w momencie wysyłki.

| Element | Status | Uwaga |
|---|---|---|
| Kampanie, szablony, kroki | IMPLEMENTED | |
| Harmonogram, okno, DST | IMPLEMENTED | |
| Skrzynki SMTP | IMPLEMENTED | TLS nigdy nie testowany; testy tylko `none` na 127.0.0.1 |
| Kolejka i tryb testowy | IMPLEMENTED | Tryb testowy naprawdę nie wysyła |
| Podwójna wysyłka | IMPLEMENTED | Solidne |
| Rezygnacja | IMPLEMENTED | `mailto:` w List-Unsubscribe nikt nie przetwarza; stopkę można wyłączyć (P2-7) |
| Blokady | PARTIAL | P1-2 |
| Odbicia | PARTIAL | Tylko synchroniczne przy RCPT |
| Odpowiedzi | NOT IMPLEMENTED | P1-3 |
| Cron / heartbeat | PARTIAL | Pętla zwrotna pod LiteSpeed może przerywać wysyłkę; zalecany CLI |
| Zapis podstawy prawnej | NOT IMPLEMENTED | Wymaga oceny prawnej przed włączeniem; audyt nie rozstrzyga tej kwestii |

**F-Q5, błędy brzegowe maszyny stanów:** `resolveUnknown('sent')` na ostatnim kroku kończy się stanem `paused`, którego nie da się wznowić (`SequenceService.php:455,812`); `update()` w stanie `error`/`sending` może dać dwie wiadomości `scheduled` i pominąć odstęp (`:874`); `fail()` i `afterSent` mogą zmienić stan anulowanej sekwencji.

Limity skrzynek pomijają `unknown_result`, czyli wiadomości prawdopodobnie wysłane (`SequenceRunner.php:361`). Po włączeniu wysyłki rusza cały zatwierdzony zaległy backlog bez podglądu; limity skrzynek go spowalniają.

---

## E. UX/UI Audit

Przegląd kodu; nic nie widziano wyrenderowanego. Liczby kliknięć odczytane z kodu, szerokości szacunkowe.

### Dzienna ścieżka handlowca

| Zadanie | Kliknięcia | Tarcie |
|---|---|---|
| Znajdź firmy | ok. 4 | Formularz + czekanie |
| Szybka ocena | 2 | Podgląd bez wyniku analizy |
| Pełna ocena | 5 | Szuflada, 11–14 sekcji |
| Przejmij | 1 z wiersza | **W szufladzie brak** |
| Numer decydenta | +3 | Szuflada → zakładka → skan |
| Wynik rozmowy z wiersza | 3 | Modal |
| Wynik z Dashboardu | 4–5 | Brak skrótu, także w „Ready to Call” |
| Oddzwonienie | +3 + tytuł | Wiersz → szuflada → Sprzedaż → przewijanie |
| Mailing | ok. 8 | Tylko z szuflady |

Razem ścieżka szczęśliwa to ok. 11 kliknięć, z oddzwonieniem ok. 14. Główny skok: decydent i telefony są w jednych zakładkach, a wynik i follow-up w innej.

### Najważniejsze problemy

| # | Problem | Dowód | Uproszczenie | Rozmiar |
|---|---|---|---|---|
| U1 | „Oddzwonić później” nie tworzy follow-upu; termin ląduje w notatce, więc oddzwonienie nie trafia do priorytetów | `resources/js/ubr/calling.js:92-114`, `OwnershipService.php:175-186` | Opcjonalne pole terminu w oknie wyniku | S–M |
| U2 | Dashboard nie ma Wyniku ani Przejmij | `partials/ac-action-menu`, `dashboard.blade.php:297` | Dołączyć `call/quick-actions` | S |
| U3 | Stopka szuflady bez Przejmij i Wyniku | `company-drawer.blade.php:247-267` | Przypięty pasek: numer, decydent, akcje | M |
| U4 | „Zapisz” i „Przejmij” to prawie to samo, z ukrytą różnicą automatyzacji | `ProspectService.php:49`, `OwnershipService.php:94` | Jedna akcja „Przejmij” | M |
| U5 | Po zapisie w szufladzie panel wisi na „Wczytywanie statusu…” | `ubr.js:500-514`, `claim-panel.blade.php:14` | Wywołać `loadClaim` | S |
| U6 | Po zapisie wiersz Discover nadal pokazuje „Przejmij” | `ubr.js:750-753` | Odświeżyć `call` | S |
| U7 | 27 filtrów w Discover, 32 w Prospects; brak „Wyczyść”; filtry przechodzą na nowe wyszukiwanie i dają fałszywe „Brak wyników” | `discover.blade.php:216-343`, `ubr.js:785-790` | Chipy + Wyczyść + 4 presety | M |
| U8 | Błąd `poll()` trwale kończy odpytywanie; pasek zostaje „W toku” | `ubr.js:806-810` | Backoff + baner | S |
| U9 | Mieszane języki, mimo że API ma `label_pl`; ta sama pewność jako HIGH i „Wysoka” | `lead.js:15-24`, `QueueCatalog.php:19-85`, `discover.blade.php:253,303` | Słownik PL | M |
| U10 | Domyślne sortowanie po Trafności, której nie ma w wierszu | `discover.blade.php:315` | Domyślnie gotowość albo Lead | S |

### Przeciążenie informacją

- **Ok. 14 punktacji i etykiet:** Relevance, Opportunity, Lead Potential, Contact Priority, Confidence, Coverage, Action Priority, Readiness, Redesign, Smart Ranking, Priorytet sprzedażowy, Kwalifikacja, Rekomendacja, Manual Review. Znaczenie w UI wyjaśniają tylko KPI, Lead i Trafność; reszta ma `title`, niedostępny na dotyku. Progi kolorów różnią się między ekranami (70/40, 80/60/40, 75/55/40, 75/50).
- **Dashboard:** 7 bloków, 12 kolejek, 10 filtrów stale widocznych; follow-upy w 5 miejscach.
- **Karta firmy:** 6 zakładek, szerokość skacze 560↔860 px; Firma ok. 14 sekcji, Lead ok. 11, Sprzedaż 10.
- **Prospects i Pipeline** to dwa widoki tych samych danych w osobnych pozycjach menu.

**Rekomendacja nadrzędna:** jedna liczba główna z powodem na każdym ekranie (Lead Potential), reszta w „Szczegóły oceny”. To jedno uproszczenie usuwa większość hałasu bez utraty funkcji.

### Pozostałe

- **Dostępność:** `aria-modal` w szufladzie bez pułapki i powrotu fokusu (`company-drawer.blade.php:2`). Kontrast: gold-deep na gold-soft 3,14:1, `text-white/35` ok. 3:1. Cele dotyku 28–36 px. Role `tab` bez strzałek, brak `aria-sort`. Zalety: pułapka fokusu w 3 modalach, menu ⋯ z klawiaturą, alternatywa dla drag & drop.
- **Stany:** pusta zakładka Lead przy `available:false`; połknięte błędy Settings (`ubr.js:1025,1033,1047`); puste stany bez CTA.
- **Teksty:** „Aplikacja nie wysyła e-maili” jest nieaktualne (`sales-tab.blade.php:174`). Surowe enumy i wersje algorytmów w UI.
- **Responsywność:** kod ma `xl`, dokumentacja 1024 i 1280 w różnych miejscach. Ryzyko najazdu kolumny Telefony i paneli `right-0 w-64` na mobile wymaga sprawdzenia wizualnego.
- **Utrzymanie:** `ubr.js` to jeden store + 5 komponentów; listy filtrów Lead/Decydent powielone w dwóch widokach. Podział na moduły — L, bez pilności.

---

## F. Integrations Audit

### Apollo: wszystkie endpointy

| Endpoint | Kredyty | Wyzwalacz | Zabezpieczenia | Ponowienia |
|---|---|---|---|---|
| POST `/mixed_companies/search` | 1 na stronę wg dokumentacji Apollo | Klik → worker | `assertCanRequest`, cache 12 h, 5 stron/search, 30/dzień org, 300/mies. org, 100/dzień instalacja, 40 searchy/dzień | Tylko 429, maks. 3 |
| to samo (test) | 1 | Klik owner/admin | Budżet; potwierdzenie tylko w UI | Brak |
| GET `/auth/health` | 0 | Klik, CLI | Log | Brak |
| POST `/usage_stats/api_usage_stats` | 0 | Klik | Log | Brak |
| POST `/mixed_people/api_search` | 0 wg dokumentacji, niezweryfikowane | Klik, DMF, test 1/h | Flaga, cache 24 h, 20/dzień org tylko w `search()`; **bez budżetu** | Brak |
| POST `/people/match` | 1–9 wg dokumentacji | Dwuetapowe potwierdzenie | Flaga, `contacts.reveal`, 20/dzień org; **bez `assertCanRequest`** | Brak |
| GET `/webhook_result/{id}` | 0 | Worker | Maks. 40 odpytań | Backoff |

Rzeczywiste zużycie w LocalWP: 9 płatnych wywołań, wszystkie z 9–11.10.2026.

**Czy może niekontrolowanie zużywać kredyty? W obecnej konfiguracji nie.** Aktywne płatne ścieżki to Discovery i test. Pułapy obowiązują, retry jest ręczny i idzie przez cache, wznowienie po awarii wraca do cache, listy niczego nie wywołują, a błąd bazy blokuje wywołanie. Uwagi:

- Licznik to strony, nie kredyty (`credits_reported` zawsze NULL). Faktycznego kosztu na koncie aplikacja nie zna.
- Rola `member` może zużyć cały budżet organizacji.
- Sprawdzenie budżetu nie jest atomowe (`ApolloBudget.php:63-85`); przekroczenie rzędu liczby workerów.
- `ApolloProvider.php:180` loguje przed zapisem cache `:194`: wyjątek przy logu powoduje ponowną opłatę, maks. 3 razy.
- `ApolloProvider.php:193-194`: `200` z niepoprawnym JSON zapisuje `[]` w cache na 12 h.
- Po włączeniu flag ochrona słabnie (P1-4, P2-6).

### Wszystkie integracje

| Integracja | Kod | Skonfig. w LocalWP | Używana | Koszt | Testy | Błędy | Duplikaty |
|---|---|---|---|---|---|---|---|
| Apollo Discovery | tak | tak | tak | tak | mock + log na żywo | dobra | niskie |
| Apollo People | tak | flaga wył. | nie | 0? | mock + 1 test | dobra | cache |
| Apollo Phone | tak | flaga wył. | nie | tak | mock | średnia | idempotentne; przerwane zlecenie może blokować osobę bez `queue-work --all` |
| ClickUp | tak | nie | UI gotowe | 0 | tylko mock | dobra | P2-8 |
| KRS | tak | bez klucza | tak | 0 | mock + log | dobra | cache 7 dni |
| Biała lista VAT | tak | bez klucza | tak | 0, limit 80 | mock + log | dobra | cache 7 dni |
| CEIDG | tak | nie | nie | 0 | brak | F-V3 | cache |
| GUS BIR | tak | nie | nie | 0 | brak | F-V3 | cache |
| Wikidata | tak | bez klucza | tak | 0 | mock | retry | bez cache |
| Overpass | tak | bez klucza | tak | 0 | mock | retry + zapasowy serwer | bez cache |
| Wayback | tak | domyślnie wł. | tak, 150/dzień | 0 | mock | dobra | cache 14 dni; F-A3 |
| AI Vision | tak | wył. | nie | tak | schemat | `invalid` płatne bez cache | hash |
| SMTP | tak | 0 skrzynek | UI | 0 | mock + lokalny bez TLS | `unknown_result` | chronione |

### ClickUp: drobne

- **F-I1 (P3)** Ręczne powiązanie nie ustawia `managed_hash` (`ClickUpExportService.php:242`), więc kontrola hasha jest pomijana (`ClickUpTaskBuilder.php:315`).
- **F-I2 (P3, PLAUSIBLE)** `str_contains` na `ubr-ref` (`ClickUpRunner.php:264,310`): `-e3` pasuje do `-e31`.
- **F-I3 (P3)** `preview` i `backfill` robią do 500 `forCompany` synchronicznie w żądaniu (`ClickUpRuleService.php:152-153,240,244`).

---

## G. Security Audit

### Potwierdzone na produkcji (2026-11-01)

| Adres | Status | Znaczenie |
|---|---|---|
| `…/themes/uglybutrich/key` | **200, 464 B** | P0-1 |
| `…/key.pub` | 200, 102 B | Jw. |
| `…/.ubr-tmp/ubr-p105-files.tar.gz` | 200, 358 373 B | Paczka źródeł, rozmiar jak lokalnie |
| `…/AGENTS.md`, `/docs/ARCHITECTURE.md`, `/composer.json`, `/tools/site-capture/capture.mjs` | 200 | Reguły i architektura publicznie |
| `…/tests/run.php`, `sequences-p105.php`, `app/Ubr/Support/Lock.php`, `vendor/autoload.php` | 200 | PHP wykonywany bezpośrednio (guard `ABSPATH` kończy) |
| `…/tests/login-app.php` | **500** | Brak guardu, fatal bez skutków |
| `…/resources/views/ubr/pages/login.blade.php` | 200 | Surowy Blade |
| `…/.git/HEAD`, `/local-db.sql`, `/wp-content/debug.log` | 403 | Zablokowane (dobrze) |
| `/wp-json/wp/v2/users` | 200, 1 użytkownik | Enumeracja loginu |
| `/wp-json/ubr/v1` | 200 | Publiczna mapa tras (bez danych); `/me` poprawnie 401 |
| `/readme.html` | 200 | Ujawnia WordPress |
| Nagłówek `x-powered-by` | Acorn 5.0.5, Laravel 12.16.0 | Ujawnia stos |

Plan `deploy-db.sh` robi `wp db export backup-before-import-*.sql` w `public_html`. Pliki `.sql` wyglądają na blokowane regułą (403), ale backupy nie są sprzątane. Skrypt nadpisuje produkcję lokalną bazą, wbrew `DEPLOYMENT.md:82`.

### Ustalenia z kodu

| ID | Waga | Problem | Dowód | Status |
|---|---|---|---|---|
| S1 | P2 | Host SMTP to SSRF i skaner portów: każdy członek z `sequences.manage` (wszystkie role) | `MailboxService.php:281-291`, `SmtpTransport.php:75,120-127` | CONFIRMED |
| S2 | P2 | Limit logowania nie obejmuje POST `/wp-login.php` ani XML-RPC; brak filtra `authenticate` | `AppLogin.php:83`, grep | CONFIRMED |
| S3 | P2 | Usunięcie z organizacji nie zatrzymuje sekwencji; hasło SMTP i token zostają | `OrganizationService.php:102-133`, `MemberRepository.php:72-87`, `SequenceRunner.php:213` | CONFIRMED |
| S4 | P2 | DNS rebinding w Chromium (tylko hosty z Node) | `capture.mjs:179,469` | PLAUSIBLE |
| S5 | P3 | Zmiana hosta SMTP zachowuje hasło | `MailboxService.php:128-129` | CONFIRMED |
| S6 | P3 | Retencja i usuwanie danych osób tylko przez CLI; `retention_days` nic nie robi samo | `UbrCommand.php:552-569` | PLAUSIBLE |
| S7 | P3 | Zapis pod uprawnieniem odczytu `/lead-intelligence/recalculate`, `/claim-research` | `routes/api.php:101,109` | CONFIRMED, dziś bez skutku |
| S8 | P3 | `LoginThrottle` używa `REMOTE_ADDR`; za proxy wspólna blokada; IPv6 per adres | `LoginThrottle.php:53-58` | CONFIRMED |
| S9 | P3 | `claim-panel.blade.php:59,78` `:href` bez `safeHref` (serwer sanitizuje) | Widok | CONFIRMED |

### Sprawdzone i solidne

- Każda trasa REST ma `permission_callback`, nonce przez core, organizacja tylko z `TenantResolver`.
- Podejrzane trasy okazały się poprawne: `DELETE /organization/members/{id}`, `POST /organizations`, `DELETE /clickup/rules/{id}`, `GET /companies/{id}/phones` mają właściwą kontrolę w serwisach.
- Worker z HMAC, unsubscribe z HMAC i POST, screenshoty po zalogowaniu z kontrolą widoczności, OAuth ClickUp z jednorazowym `state`.
- SQL przez `prepare`, sortowanie z whitelist. Brak `x-html`/`innerHTML`.
- SSRF w PHP: ocena IP na bajtach, kontrola przekierowań, przypięcie `CURLOPT_RESOLVE`; `capture.mjs` analogicznie.
- `SecretBox` (libsodium), hasła i tokeny nie wracają w odpowiedziach. Historia Gita: poza kluczem nie znaleziono wzorców sekretów.

Nie sprawdzono: `ContactFilters`, `QueueCatalog::where`, `login.blade.php` pod kątem XSS, `ClickUpDestinationService::saveSettings`, `sequences/preview`, kompletności kasowania danych osób.

---

## H. Performance Audit

Przy obecnej skali (65 firm) wydajność nie jest problemem. Poniżej ryzyka skali. Pomiary z 10 000 firm w QA-REPORT pochodzą z kontenera i częściowo sprzed `CallFilters`, więc nie obejmują obecnych zapytań. Hostinger niezmierzony.

| ID | Problem | Dowód | Skutek | Rozmiar |
|---|---|---|---|---|
| H1 | Readiness w kolumnie tabeli pochodnej Action Center: CASE z 4+ skorelowanymi EXISTS dla całej puli, przy każdym wywołaniu; `ready_to_call` 6× na stronę | `ActionCenterRepository.php:50`, `ActionCenterService.php:280,288-290` | Największe ryzyko SQL | M |
| H2 | Odpytywanie bez `visibilityState`; Prospects co 6 s pełna lista przy `lead.pending`; Discover 2,5 s + 4 s | `ubr.js:667,813,958-971` | Ok. 1 żądanie PHP/s na kartę przy limicie procesów współdzielonego hostingu | S |
| H3 | Brak globalnego limitu równoległości workerów; healthcheck kopie 8 kolejek | `UbrServiceProvider.php:108-125`, `WorkerEndpoint.php:58` | Wypełnienie procesów wejściowych Hostingera (PLAUSIBLE) | M |
| H4 | Brak retencji: `api_requests`, zdarzenia, analizy (5 LONGTEXT), historia lead | Migracje | Rozmiar bazy i backupów | M |
| H5 | `recentActivity` bez indeksu `(organization_id, created_at)` | `ActionCenterRepository.php:357` | Filesort | S |
| H6 | Tablica Pipeline: 23 zapytania, zbędny COUNT | `PipelineRepository.php:254,296` | Małe | S |
| H7 | N+1 w `applyOrganizationView` | `ContactResearchService.php:466-472` | Małe | S |
| H8 | `SELECT c.*` z LONGTEXT + filesort, LIKE `%x%` na 4 kolumnach, COUNT na każdej stronie | `ResultRepository.php:246`, `ProspectRepository.php:131` | Ograniczone do organizacji (PLAUSIBLE) | M |
| H9 | Cache Action Center unieważniany każdym zdarzeniem w tle; `bump` nieatomowy | `ActionCenterService.php:500-509` | Niski hit rate przy pracy workerów | S |

**Karta firmy jest leniwa:** 1–2 wywołania REST, ok. 9 zapytań aplikacji na każde. Bez problemu.

**Hostinger (współdzielony):** bez pętli zwrotnej postęp wymaga crona; `sendJob` nie umie tego wykryć. Bez `proc_open` analizy kończą się PARTIAL. `DISABLE_WP_CRON` bez crona systemowego po cichu zatrzymuje łańcuchy. `queue-work --all` pomija sprzątanie cache i starzenie ocen (`UbrCommand.php:283-295`), a dokumentacja podaje raz 5, raz 1 minutę. Działanie `GET_LOCK` za ewentualnym proxy połączeń: nieznane. Weryfikacja nadal używa blokady w opcji WP zamiast `Lock` (`VerificationRunner.php:304-345`, sprawdzone), wbrew `AGENTS.md`.

---

## I. Test Coverage

### Wyniki z tego audytu

| Zestaw | Wynik | Rodzaj |
|---|---|---|
| PHP przebieg 1, bez zmian, kopia z prawdziwymi danymi | 1473/1474, render pominięty | 1 błąd; najpewniej izolacja testu |
| PHP przebieg 2, render włączony | 7 błędów renderu | Wyłącznie harness: `runuser -u claude` nie istnieje na macOS |
| PHP przebieg 3, harness poprawiony w kopii | **1481/1481** | MySQL 8.4 + prawdziwy Chrome |
| `calling` / `claim` / `per-page` | 18/18, 35/35, 31/31 | Logika JS |
| `preview-url` / `sequences` / `ssrf-ip` | 19/19, 34/34, 35/35 | Logika JS |
| `datetime` | 5/5 × 3 strefy | Logika JS |
| `php -l` | Bez błędów | Składnia |
| Wyjścia sieciowe | 0 zewnętrznych | Tylko 6 prób do `uglybutirch.local` (zablokowane) |

Test 48 („wykluczenie w organizacji B”) zawiódł raz. W kolejnych przebiegach przechodzi, a diagnostyka pokazuje poprawne wartości. Najpewniejsza przyczyna: `tick(50)` bez organizacji konkuruje z prawdziwymi rekordami w kolejce, więc test zależy od stanu bazy (`tests/lead-intelligence.php:297`).

### Co faktycznie weryfikują testy

| Pytanie | Odpowiedź |
|---|---|
| PHPUnit | Brak. Własny harness `UbrT`, integracyjny, na prawdziwej bazie, przez prawdziwy dispatch REST. To mocna strona |
| Testy pozorne | `tests/run.php:1421` oraz `tests/pipeline-clickup.php:160`: `ok(true, 'POMINIĘTO…')` liczy pominięcie jako sukces |
| Render na innej maszynie | Warunek `/opt/pw-browsers` i `runuser` w `tests/run.php:1420,1428`: poza kontenerem nigdy się nie wykona |
| E2E | **Brak w repozytorium.** Setki wyników Playwright z PROGRESS i QA-REPORT są niepowtarzalne |
| Integracje | ClickUp, Apollo, Wayback, rejestry: wyłącznie mocki. Kształt odpowiedzi nie jest sprawdzany względem API |
| SMTP | Lokalny serwer bez TLS; ścieżka TLS produkcyjna nietestowana |
| Workery | Kroki wołane ręcznie; pętla zwrotna, cron i Hostinger nietestowane; dwa workery tylko dla Sequences |
| Migracje | Idempotencja tak; ścieżka od pustej bazy i na danych produkcyjnych nie |

Brak testu ścieżki pozytywnej: CEIDG, GUS BIR. Brak testów UI w repo. `wp ubr` poza `analyze --check` bez testu. W scenariuszu `action-center.php:201` brak gałęzi DNC i `analyzed_at`.

**Sprzątanie testów nie jest pełne.** Porównanie sum kontrolnych 48 tabel przed i po 3 przebiegach:

- `wp_ubr_api_requests`: 138 → 258 wierszy, czyli ok. 40 pozostałości na przebieg (mimo komunikatu „pozostałe rekordy testowe = 0”),
- `wp_ubr_lead_intelligence`: 65 wierszy przeliczonych,
- reszta bez zmian, w tym firmy i dowody (obecne dane LocalWP nie mają rekordów podatnych na korektę M003: 0 firm).

---

## J. Technical Debt

- Pozostałości bazowego motywu i bloków ACF w aplikacji SaaS.
- Trzy lockfile’e, nieużywane zależności, `.ubr-tmp/` i `.yarn/install-state.gz` w repozytorium.
- Brak zautomatyzowanego i opisanego wdrożenia: „Automatyczny deploy” to tylko komunikat commita, dokumentacja nie pasuje do rzeczywistości.
- `AGENTS.md` miesza zasady aplikacji z dziedzictwem motywu `h2otwock`.
- Serwisy z SQL, service locator, brak jednego katalogu statusów (`mailing` poza grupami, P2-3).
- 4 warianty pasm wielkości, dublowana definicja „przeanalizowana” w 5+ miejscach.
- Wersjonowanie algorytmów działa inaczej, niż mówią reguły (F-L1).
- Brak retencji logów i danych osób.
- `ubr.js` i duże widoki; surowe `window.confirm` obok modali.

---

## K. Critical Issues

1. **P0-1 Klucz SSH do pobrania z produkcji.**
2. **P0-2 Klucz w historii publicznego repozytorium.**
3. **P0-3 `wp ubr test` na prawdziwej bazie może wysyłać e-maile i uruchamia migracje.**
4. Blokady Sequences i DNC omijane przez archiwizację (P1-1, P1-2): muszą być naprawione przed włączeniem wysyłki.
5. Brak obsługi odpowiedzi (P1-3): blokuje włączenie Sequences.

Nic w kodzie nie jest dziś aktywnie zepsute w sposób blokujący pracę. Trzy pozycje P0 wynikają z higieny wdrożenia i testów, nie z logiki biznesowej.

---

## L. Development Readiness

1. **Czy architektura nadaje się do rozbudowy?** Tak. Warstwy, rejestr tras, migracje i wzorzec kolejek są wystarczające.
2. **Czy są krytyczne problemy przed nowymi funkcjami?** Tak: trzy P0.
3. **Najbardziej skomplikowane moduły:** Sequences, ClickUp (eksport + reguły), Discovery z weryfikacją i kolejkami, telefony + Apollo Matching.
4. **Co uprościć:** liczba punktacji w UI, filtry, Prospects vs Pipeline, „Zapisz” vs „Przejmij”, reguły ClickUp (zamrozić).
5. **Niepotrzebne funkcje / duplikaty:** tak, wymienione w sekcji B; większość do usunięcia małym kosztem.
6. **Czy kolejki i integracje są bezpieczne?** Kolejki: spójne dane, ryzyka operacyjne na Hostingerze. Integracje: koszty kontrolowane, duże pole niezweryfikowane na żywo.
7. **Czy Apollo może niekontrolowanie zużywać kredyty?** W obecnej konfiguracji nie. Przed włączeniem telefonów trzeba zrobić P1-4.
8. **Czy Sequences może wysyłać bez świadomej konfiguracji?** Normalną ścieżką nie: serwerowa lista kontrolna, potwierdzenie sekwencji, połączona skrzynka, wyłącznik awaryjny. Wyjątek: `wp ubr test` (P0-3). Stanu `sending_enabled` na produkcji nie sprawdzono.
9. **Czy można bezpiecznie dodawać moduły?** Tak, po P0 i przy zasadzie: nowe tabele + nowy runner + wpis w `routes/api.php` + `failExhausted`. Warto najpierw mieć bezpieczne testy i powtarzalne E2E.
10. **Co poprawić przed następnym etapem:** P0-1 do P0-3, P1-1, P1-5, dokumentacja wdrożenia, a przed włączeniem odpowiednio Sequences i telefonów: P1-2, P1-3, P1-4.

---

## M. Recommendations

### P0 — Critical

**P0-1. Prywatny klucz SSH dostępny publicznie na produkcji**
- **Problem:** `/wp-content/themes/uglybutrich/key` jest serwowany bez logowania.
- **Dowód:** `HEAD` → `200`, `content-length: 464`, `last-modified: 2026-10-10`; blob `key` w Gicie też ma 464 B; `key.pub` 102 B; nieistniejący plik → `404`. Treści nie pobierano.
- **Skutek:** dostęp SSH do każdego systemu, gdzie klucz jest autoryzowany. Co otwiera, nie wiadomo: audyt nie sprawdzał klucza.
- **Rozwiązanie:** (1) unieważnić klucz wszędzie, gdzie jest w `authorized_keys`, oraz na GitHub deploy keys; (2) usunąć `key` i `key.pub` z serwera; (3) przejrzeć logi SSH od 10.10.2026; (4) reguła deny na serwerze.
- **Złożoność:** S. **Zależności:** brak, zrobić natychmiast, przed wszystkim innym.

**P0-2. Klucz w historii publicznego repozytorium GitHub**
- **Problem:** repozytorium jest publiczne (`visibility: public`), a `main` zawiera klucz w `9b24093` i `ac21be0`. Publicznie są też `AGENTS.md`, cały kod, raporty QA i `SECURITY-KEY.md` opisujący wyciek.
- **Dowód:** publiczne API GitHub; `git log main -- key`.
- **Skutek:** klucz trzeba uznać za ujawniony od 09.10.2026 niezależnie od P0-1. Skanery repozytoriów wykrywają takie pliki automatycznie.
- **Rozwiązanie:** ustawić repozytorium jako prywatne. Przepisanie historii jest opcjonalne i wymaga decyzji właściciela; po rotacji klucza nie jest już pilne.
- **Złożoność:** S. **Zależności:** rotacja z P0-1.

**P0-3. Testy na prawdziwej bazie mogą wysyłać e-maile i uruchamiają migracje**
- **Problem:** `tests/sequences-p105.php:212` włącza wysyłkę filtrem dla wszystkich organizacji, a `SequenceRunner::due()` (`:152-163`) nie filtruje organizacji. `tests/run.php:402-403` zeruje wersję schematu i uruchamia wszystkie migracje, w tym korektę danych M003 (`M003CompanyVerification.php:122-140`). `wp ubr test` jest dostępny w kodzie produkcyjnym (`UbrCommand.php:893`), a `DEPLOYMENT.md:33` każe uruchamiać testy przed wdrożeniem.
- **Dowód:** kod sprawdzony. Na obecnych danych LocalWP M003 nie zmieniło nic (0 podatnych firm); to zagrożenie warunkowe. Dotyczy firm z nowymi dowodami `city`/`region` z Apollo. Tylko `UBR_SEQUENCES_SENDING=false` powstrzymuje wysyłkę.
- **Skutek:** realne wiadomości do klientów z sekwencji, utrata miast i województw, ponowne dodanie usuniętych powiązań telefonów (M011).
- **Rozwiązanie:** twarda odmowa uruchomienia poza dedykowaną bazą testową (osobna stała, nie `WP_ENVIRONMENT_TYPE`); usunąć `wp ubr test` z produkcji albo chronić go tym samym warunkiem; ograniczyć `tick` do organizacji testowych; zmienić instrukcję wdrożenia.
- **Złożoność:** S. **Zależności:** brak.

### P1 — High

**P1-1. Archiwizacja kasuje skutki zakazu kontaktu**
- **Problem:** warunek `archived_at IS NULL` w sprawdzaniu DNC.
- **Dowód:** `CallFilters.php:111`, `OwnershipService.php:50`, `CallPresenter.php:197`, `ApolloPhoneService.php:435`.
- **Skutek:** firma z DNC po archiwizacji staje się „Wolna”, można ją przejąć i wykonać do niej płatne odblokowanie.
- **Rozwiązanie:** pominąć warunek archiwizacji dla DNC i `not_a_fit`.
- **Złożoność:** S. **Zależności:** razem z P1-2.

**P1-2. Dziury w blokadach Sequences**
- **Problem:** ta sama przyczyna przy wysyłce; decyzje organizacji o kontaktach (odrzucony / nieaktualny / wykluczony) są ignorowane; archiwizacja nie zatrzymuje sekwencji.
- **Dowód:** `SequenceService.php:537,549-555,1205`; `PipelineService.php:214-226`.
- **Skutek:** e-mail do firmy z zakazem kontaktu lub do odrzuconej osoby.
- **Rozwiązanie:** zapytanie bez filtra archiwum, sprawdzenie `CONTACT_REVIEWS`, zatrzymanie przy archiwizacji.
- **Złożoność:** S. **Zależności:** warunek włączenia Sequences.

**P1-3. Brak obsługi odpowiedzi i odbić asynchronicznych**
- **Problem:** w kodzie nie ma IMAP, In-Reply-To ani DSN; `List-Unsubscribe` deklaruje `mailto:`, którego nikt nie czyta (`SequenceRunner.php:278`).
- **Dowód:** grep `app/Ubr` pusty.
- **Skutek:** follow-upy po odpowiedzi, także po sprzeciwie; rezygnacja mailem bez skutku; złe adresy niewidoczne.
- **Rozwiązanie:** minimum: obowiązkowy ręczny „Odpowiedział” + usunięcie `mailto:`. Docelowo IMAP per skrzynka.
- **Złożoność:** S (minimum) / L (IMAP). **Zależności:** warunek włączenia Sequences.

**P1-4. Płatne telefony omijają budżet Apollo**
- **Problem:** brak `assertCanRequest`; tylko limit dzienny organizacji.
- **Dowód:** `ApolloPhoneService.php:196-233`, `:223` liczy też 401/403/429.
- **Skutek:** wg dokumentacji Apollo do 180 kredytów/dzień na organizację, bez pułapu miesięcznego; błędy zjadają budżet Discovery. Dotyczy tylko włączonej flagi.
- **Rozwiązanie:** dodać budżet z osobnymi limitami i poprawić `billable`.
- **Złożoność:** S. **Zależności:** warunek włączenia `UBR_APOLLO_PHONE_ENABLED`.

**P1-5. Wynik rozmowy gubi oddzwonienia, a follow-upy omijają DNC**
- **Problem:** `call_back` zmienia etap bez follow-upu (U1); zaległy follow-up firmy DNC lub `lost` jest nadal krytyczny i pierwszy w priorytetach.
- **Dowód:** `calling.js:92-114`, `OwnershipService.php:175-186`, `ActionCenterRepository.php:259-263`, `PipelineRepository.php:193`, `PipelineService.php:106,648`.
- **Skutek:** zgubione oddzwonienia; priorytety wskazują firmę, do której nie wolno dzwonić.
- **Rozwiązanie:** pole terminu w oknie wyniku; filtr DNC/zamknięte albo anulowanie follow-upów.
- **Złożoność:** S–M. **Zależności:** brak.

**P1-6. Dokumentacja wdrożenia jest fałszywa, a proces niepowtarzalny**
- **Problem:** rozjazdy z sekcji C; nikt nie wie, jaki schemat ma produkcja.
- **Dowód:** `DEPLOYMENT.md:24,33`, `.gitignore:41`, stan produkcji z sekcji G.
- **Skutek:** `git pull` wg instrukcji daje `wp_die` na czystym serwerze; błędy wdrożeń.
- **Rozwiązanie:** opisać realny proces, wdrażać bez `.ubr-tmp`, `tests`, `docs`, `key`, `*.md`; `wp ubr migrate --status` na produkcji (wykona właściciel).
- **Złożoność:** S. **Zależności:** po P0-1.

**P1-7. Język i pętle błędów w UI blokujące codzienną pracę**
- **Problem:** U2, U3, U5, U6, U7, U8.
- **Dowód:** sekcja E.
- **Skutek:** fałszywe puste listy, przestarzałe stany, dodatkowe skoki przy każdym telefonie.
- **Rozwiązanie:** szybkie akcje na Dashboardzie, przypięty pasek szuflady, chipy filtrów, backoff.
- **Złożoność:** M. **Zależności:** brak.

### P2 — Medium

| ID | Problem | Dowód | Skutek | Rozwiązanie | Zł. | Zależy od |
|---|---|---|---|---|---|---|
| P2-1 | Publicznie dostępne artefakty i dokumenty | Sekcja G | Ułatwia rekonesans | `git rm .ubr-tmp`, deny dla `tests/`, `docs/`, `*.md`, `resources/`, `app/`, ukrycie `readme.html`, `x-powered-by`, `/wp/v2/users` | S | P0-1 |
| P2-2 | Host SMTP bez `UrlGuard` (S1), brak limitu logowania na `wp-login.php` (S2) | `MailboxService.php:281-291`, `AppLogin.php:83` | Skan sieci, brute force | `UrlGuard`, filtr `authenticate`, wyłączenie XML-RPC | S–M | — |
| P2-3 | Status `mailing` poza grupami | `PipelineCatalog.php:17`, `OwnershipService.php:180-182` | CONTACT_READY dla firmy w mailingu; rozmowa nie przesuwa etapu | Atrybut `group` jako jedno źródło | S | — |
| P2-4 | Zmiana właściciela omija atomowy zapis | `PipelineService.php:142-160,195`, `pipeline.js:303` | Nadpisanie przejęcia | Warunkowy UPDATE, 409 | M | — |
| P2-5 | Były pracownik dalej wysyła (S3) | `MemberRepository.php:72-87` | Maile z konta osoby spoza firmy | Pauza, kasowanie sekretów, kontrola członkostwa | M | Warunek Sequences |
| P2-6 | People Search w DMF poza `search()`; test-search bez `confirm` | `DecisionMakerFinderService.php:140`, `IntegrationController.php:51` | Automatyczne zapytania po włączeniu flagi; kredyt bez potwierdzenia | DMF tylko `saved()`; `confirm` po stronie serwera | S | — |
| P2-7 | Stopkę rezygnacji można wyłączyć | `SequenceAdminService.php:91`, `SequenceRunner.php:244` | Mail bez widocznej rezygnacji | Wymóg przy starcie i wysyłce | S | Warunek Sequences |
| P2-8 | ClickUp sam tworzy zadanie ponownie po 2 sprawdzeniach; lista tylko strona 0 | `ClickUpRunner.php:334-341`, `ClickUpClient.php:157` | Duplikat, sprzeczność z `AGENTS.md` | Stan UNKNOWN, paginacja | S | — |
| P2-9 | Eksport utknięty w `queued` po rozłączeniu | `ClickUpOAuthService.php:286`, `ClickUpRepository.php:117-126`, `ClickUpExportService.php:147,437` | Firma trwale zablokowana na liście | `failed` przy anulowaniu | S | — |
| P2-10 | DNC trafia do ClickUp | `ClickUpExportService.php:95`, `ClickUpRuleService.php:349` | Zakazana firma w CRM | Pomijać DNC/BLOCKED | S | P1-1 |
| P2-11 | Weryfikacja: blokada w opcji WP; degradacja statusu (F-V1); limit VAT (F-V2) | `VerificationRunner.php:304-345`, `CompanyVerifier.php:444-450` | Wyścig, fałszywy spadek zaufania | `Lock`, zachowanie statusu | S | — |
| P2-12 | `QueueMonitor` (F-D5), pętla Website Age (F-A3) | Sekcja D | Zadania bez końca, wysycenie workerów | Licznik wznowień, łańcuch tylko przy `processed > 0` | S | — |
| P2-13 | Sequences pod LiteSpeed przez pętlę zwrotną | `WorkerEndpoint.php`, ARCHITECTURE:923 | `unknown_result` i zatrzymane sekwencje (PLAUSIBLE) | Wysyłka tylko z CLI, cron 1 min | S | Warunek Sequences |
| P2-14 | Action Center readiness (H1), polling (H2), równoległość (H3) | Sekcja H | Obciążenie przy skali | Readiness tylko gdy potrzebne, `visibilityState`, limit globalny | M | Pomiar |
| P2-15 | Retencja logów i danych osób (H4, S6) | Sekcja G, H | Rozmiar bazy, ryzyko RODO | Cron czyszczący, usuwanie w panelu | M | — |
| P2-16 | Smart Ranking `has_blocker` (F-D4) | `LeadFilters.php:69` | Firmy bez oceny na górze | `COALESCE` | S | — |
| P2-17 | Testy: `ok(true)` za pominięcie, harness kontenerowy, brak E2E w repo, brak sprzątania `api_requests` | Sekcja I | Fałszywe poczucie pokrycia | Licznik „skipped”, wykrywanie Chrome, E2E do repo | M | P0-3 |
| P2-18 | Uproszczenie modelu ocen | Sekcja E, F-L3 | Przeciążenie użytkownika | Jedna liczba główna, reszta w szczegółach | M | Decyzja właściciela |
| P2-19 | Podział `SequenceService` i naprawa stanów F-Q5 | `SequenceService.php:455,812,874` | Niespójne stany, np. `paused` po ostatnim kroku | 5 klas + testy przejść | M | Przed rozbudową Sequences |
| P2-20 | Prospects + Pipeline, „Zapisz” vs „Przejmij”, język PL | U4, U9 | Dublowanie | Jedna pozycja menu, jedna akcja, słownik | M | — |

### P3 — Low

| ID | Problem | Dowód | Rozwiązanie | Zł. |
|---|---|---|---|---|
| P3-1 | Martwy kod i API bez UI | Sekcja B | Usunąć lub zostawić świadomie | S |
| P3-2 | Nieużywane zależności, 3 lockfile’e | Sekcja C | Sprzątanie | S |
| P3-3 | Nieaktualne `AGENTS.md` | `:247,:295,:331,:355` | Aktualizacja, oddzielenie dziedzictwa | S |
| P3-4 | Wersjonowanie algorytmów (F-L1) | `LeadIntelligenceRepository.php:72` | Wersja złożona | S |
| P3-5 | Telefon wpływa na scoring (F-L2) | `PeopleExtractor.php:236` | Usunąć wejście, podbić wersje | S |
| P3-6 | Nieosiągalne maksima, starzenie Opportunity | F-A1, F-A2 | Korekta skali | S |
| P3-7 | Zdublowane segmenty i pasma | `BusinessPotentialAnalyzer`, `LeadPotentialCalculator`, `CompanySize` | Jeden katalog | S |
| P3-8 | Drobiazgi Apollo (kolejność logu i cache, pusty JSON) | `ApolloProvider.php:180,193-194` | Zamiana kolejności, walidacja | S |
| P3-9 | Indeks `recentActivity`, zbędny COUNT, N+1 | H5–H7 | Poprawki punktowe | S |
| P3-10 | Brak `prospect_event` przy zmianie właściciela, `touch()` | Sekcja D | Dopisać zdarzenia | S |
| P3-11 | Limity skrzynek pomijają `unknown_result` | `SequenceRunner.php:361` | Liczyć je | S |
| P3-12 | Wykluczenia nadpisywane, brak audytu `unsuppress` | `SequenceService.php:476,511` | Historia | S |
| P3-13 | Dostępność, kontrast, cele dotyku | Sekcja E | Korekty tokenów, fokus w szufladzie | S–M |
| P3-14 | Nieaktualne teksty, surowe enumy, sprzeczne progi kolorów | Sekcja E | Porządek | S |
| P3-15 | `tests/login-app.php` bez guardu `ABSPATH` | Produkcja `500` | Dodać guard (znika po P2-1) | S |
| P3-16 | Test 48 zależy od stanu bazy | `tests/lead-intelligence.php:297` | `tick` z organizacją | S |

### Proponowana kolejność

1. **Dziś:** P0-1, P0-2.
2. **Sprint porządkowy (ok. 1–2 dni pracy):** P0-3, P1-1, P1-5, P1-6, P2-1, P2-2, P2-16, P2-17.
3. **Przed włączeniem Sequences:** P1-2, P1-3, P2-5, P2-7, P2-13, ocena prawna.
4. **Przed włączeniem telefonów Apollo:** P1-4, P2-6.
5. **Przed pierwszym eksportem ClickUp:** P2-8, P2-9, P2-10.
6. **Równolegle z nowymi funkcjami:** P1-7, P2-18, P2-20.
