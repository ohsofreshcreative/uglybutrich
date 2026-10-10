# Ugly but Rich — architektura (etapy 1–1.2 i Prompt 2: Foundation, SaaS, Discovery, weryfikacja, Website Analyzer)

Dokument opisuje stan zaimplementowanego kodu. Rzeczy planowane są oznaczone jako **[przyszłość]**.

## 1. Kontekst i decyzje

- Aplikacja działa **wewnątrz motywu Sage 11** (Acorn 5 = komponenty Laravel 12 w WordPressie). Bez osobnego pluginu i bez osobnego frameworka.
- Moduł jest jednym providerem Acorn: `App\Ubr\Providers\UbrServiceProvider` (zarejestrowany w `functions.php`). Provider podpina się w cykl życia WordPressa: `init` (migracje, rewrite), `template_redirect` (ekrany `/app`), `rest_api_init` (REST), hook WP-Cron (zadania w tle), `WP_CLI` (komendy).
- **REST API WordPressa** (`/wp-json/ubr/v1/...`) zamiast routingu Acorn: natywne uwierzytelnianie cookie + nonce (CSRF), działa w WP-CLI i zadaniach bez renderowania frontendu, nie wymaga dodatkowej konfiguracji.
- **Ekrany panelu** to szkielety Blade renderowane pod `/app/*` (rewrite rules). Dane ładuje Alpine.js z REST API — widoki nie zawierają logiki biznesowej.
- **Własne tabele MySQL** (`{prefix}ubr_*`, InnoDB, fizyczne klucze obce między tabelami aplikacji). Nic nie jest trzymane w `wp_posts`/`wp_postmeta`.

## 2. Struktura katalogów

```
app/Ubr/
  Domain/
    Tenancy/        Role (macierz uprawnień), TenantContext
    Companies/      CompanyNormalizer (nazwy, NIP/REGON/KRS, domeny, klasyfikacja), Confidence
    Discovery/      DiscoveryCriteria, DiscoveryProvider (interfejs), CompanyCandidate, ProviderPage,
                    ProviderException, IndustryCatalog, RegionCatalog
  Infrastructure/
    Database/       Db (wrapper $wpdb), Tables, Migrator, Migrations/M001InitialSchema
    Repositories/   Organization, Member, Company, Prospect, Search, Result, Event
    Database/Migrations/M002ApiUsageAndCompanyFacts   dziennik zapytań API + rok założenia/przychody/LinkedIn
    Discovery/      ProviderRegistry, Providers/{ApolloProvider, WikidataProvider, OverpassProvider}
    Http/           ProviderHttpClient (throttling, klasyfikacja błędów), WebsiteChecker
    Queue/          AsyncWorker (loopback admin-ajax), JobDispatcher (worker + zapasowy WP-Cron)
    Repositories/ApiRequestRepository              historia wykorzystania API
  Services/         TenantResolver, OrganizationService, Bootstrapper, DiscoveryService, DiscoveryRunner,
                    CompanyIngestService, ProspectService, UsageLimits, ApolloBudget, QueueMonitor, CompanyPresenter
  Http/             RestRegistrar, AppPages, WorkerEndpoint, Controllers/* (w tym IntegrationController)
  Console/          UbrCommand (wp ubr …)
  Providers/        UbrServiceProvider
  Support/          UbrException
routes/api.php      mapa tras REST → kontroler + wymagane uprawnienie
routes/app.php      ekrany panelu
resources/views/ubr layout, ekrany, partiale (szuflada firmy, ikony, paginacja, toasty)
resources/js/ubr.js + resources/js/ubr/api.js   komponenty Alpine i klient API
resources/css/ubr.css                           Tailwind 4 (osobny entry Vite)
tests/run.php       testy integracyjne (wp ubr test)
```

## 3. Model danych

| Tabela | Rola | Klucze / indeksy |
|---|---|---|
| `ubr_organizations` | organizacje (tenant). `plan` i `settings` pod przyszłe abonamenty | PK, UNIQUE `uuid`, UNIQUE `slug` |
| `ubr_organization_members` | użytkownik WP ↔ organizacja + rola (`owner`/`admin`/`member`) | UNIQUE (`organization_id`,`user_id`), FK→organizations CASCADE |
| `ubr_companies` | **globalna baza firm** (tylko dane publiczne, bez danych organizacji) | UNIQUE `domain`, UNIQUE `nip`, UNIQUE `krs`, idx `normalized_name`, `city`, `region` |
| `ubr_company_external_ids` | identyfikatory u dostawców (Q-id Wikidata, `node/123` OSM, place_id) | UNIQUE (`provider`,`external_id`), FK→companies |
| `ubr_company_evidence` | **historia pochodzenia**: każda wartość pola z każdego źródła, pewność, URL źródła, daty | UNIQUE (`company_id`,`provider`,`field`,`value_hash`) |
| `ubr_organization_prospects` | **prywatna lista prospektów** organizacji (status pod przyszły pipeline) | UNIQUE (`organization_id`,`company_id`), FK→organizations CASCADE, FK→companies RESTRICT |
| `ubr_discovery_searches` | zadania Discovery: kryteria (JSON), status, postęp, stan wznawiania, błędy, próby, blokada, anulowanie | idx (`organization_id`,`created_at`), (`status`,`next_run_at`) |
| `ubr_discovery_results` | wynik = firma znaleziona w danym wyszukiwaniu (organizacja zdenormalizowana do szybkiego zawężania) | UNIQUE (`search_id`,`company_id`), idx (`organization_id`,`company_id`) |
| `ubr_discovery_events` | dziennik zadania (info/warning/error) | FK→searches CASCADE |
| `ubr_usage_events` | zdarzenia użycia (np. `discovery.search`) — podstawa limitów i przyszłego billingu | idx (`organization_id`,`metric`,`created_at`) |
| `ubr_api_requests` | **historia wykorzystania API** (Apollo i in.): endpoint, HTTP, czas, cache, „potencjalnie płatne”, zwrócone/nowe/duplikaty, nagłówki limitów, błąd | idx (`provider`,`created_at`), (`organization_id`,`provider`,`created_at`) |

| `ubr_company_checks` (M003) | **historia weryfikacji** (przedsiębiorstwo / domena): status, pewność, źródło, powody, rozbieżności, kto zlecił (prywatne) | idx (`company_id`,`kind`,`created_at`), FK→companies CASCADE |
| `ubr_verification_queue` (M003) | kolejka weryfikacji (jedna pozycja na firmę): priorytet, wymuszenie, próby, termin, blokada, błąd | UNIQUE `company_id`, idx (`status`,`next_attempt_at`,`priority`) |

`ubr_companies` (M003) ma pola weryfikacji: `verification_status` (pending/verified/uncertain/inactive/suspended), `verification_confidence`, `verification_source`, `verification_checked_at`, `verification_summary` (JSON: powody, rozbieżności, rekordy rejestrów), `domain_status` (unchecked/confirmed/uncertain/mismatch/unavailable), `domain_checked_at`, `domain_summary`, `registry_name`, `legal_form`, `pkd_main`, `pkd_description`, `industry_codes` (SIC/NAICS dostawcy), `location_confidence` + `location_source`, `fit_flags`. `ubr_discovery_results` (M003): `relevance_score`, `relevance` (JSON kryteriów), `location_match`.

`ubr_companies` (od M002) ma też `founded_year`, `revenue_estimate` (+`revenue_source`), `linkedin_url` — wypełniane tylko, gdy źródło je podaje.

Daty w bazie są w **UTC**. Do `wp_users` odwołujemy się tylko indeksem (bez FK) — sprzątanie robi hook `deleted_user`.

**Migracje:** `Migrator` uruchamia kolejne klasy `Migration` o wersji większej niż `ubr_db_version`, pod blokadą (opcja `ubr_migration_lock`). Każda migracja jest idempotentna (`CREATE TABLE IF NOT EXISTS`) i nie kasuje danych. Wywołanie: automatycznie na `init` (tanie, gdy aktualne) lub `wp ubr install`.

## 4. Multi-tenancy i autoryzacja

1. Użytkownik loguje się standardowo w WordPressie (`wp-login.php`).
2. `TenantResolver` ustala aktywną organizację: preferencja w user meta `ubr_active_organization_id`, **zawsze weryfikowana** z tabelą członków (fallback: pierwsza organizacja użytkownika). Brak członkostwa → brak dostępu.
3. Każda trasa REST ma `permission_callback` sprawdzający: zalogowanie → członkostwo → uprawnienie roli (`Role::ABILITIES`). Kontrolery i serwisy pobierają kontekst ponownie i każde zapytanie filtrują po `organization_id` z kontekstu.
4. Identyfikator organizacji z żądania jest ignorowany (wyjątek: przełączenie organizacji — weryfikowane członkostwem, odpowiedź 404 dla obcej organizacji).
5. Firma z globalnej bazy jest widoczna dla organizacji tylko, jeśli pojawiła się w jej wynikach lub prospektach (brak enumeracji globalnej bazy przez ID).
6. CSRF: REST z cookie wymaga nagłówka `X-WP-Nonce` (bez niego WordPress traktuje żądanie jako anonimowe → 401).

| Uprawnienie | Owner | Admin | Member |
|---|:-:|:-:|:-:|
| podgląd organizacji, członków, discovery, firm, prospektów | ✓ | ✓ | ✓ |
| uruchamianie/anulowanie Discovery, zapis/usuwanie prospektów | ✓ | ✓ | ✓ |
| zmiana nazwy organizacji, dodawanie/usuwanie członków | ✓ | ✓ | – |
| nadawanie/odbieranie roli Owner | ✓ | – | – |

Dodatkowo: nie można usunąć/zdegradować ostatniego Ownera; nowe organizacje domyślnie tworzą tylko użytkownicy z capability `manage_options` (filtr `ubr/can_create_organization` pod przyszły self-service).

**Inicjalizacja:** `Bootstrapper` tworzy organizację `ohsofresh` (jeśli nie istnieje — sprawdzane po slugu) i dodaje administratorów WP jako Ownerów. Ponowne uruchomienie niczego nie duplikuje.

**Limity / przyszły billing:** `UsageLimits` — maks. równoległych wyszukiwań (2), dziennie (40), odstęp między wyszukiwaniami użytkownika (10 s). Wartości zależą od `organization.plan` przez filtr `ubr/limits`. Każde wyszukiwanie zapisuje zdarzenie w `ubr_usage_events`. **[przyszłość]** plany, rozliczenia, płatności.

## 5. Discovery Engine

### Przepływ

```
UI / WP-CLI ──POST /discovery/searches──► DiscoveryService::start
   walidacja kryteriów, limity, zapis zadania (status queued), zdarzenie użycia
   JobDispatcher::dispatch → AsyncWorker (nieblokujący loopback admin-ajax) + zapasowe zdarzenie WP-Cron
                                   │
                                   ▼
                      DiscoveryRunner::tick (budżet 20 s, blokada wiersza = budżet + 120 s)
   fetch:  dla każdego dostawcy → ProviderPage → CompanyIngestService (deduplikacja, evidence) → wynik → RelevanceScorer
   koniec: diagnostyka + wyjaśnienie (szczególnie przy 0 wyników) → firmy do ubr_verification_queue → status completed
   zapis stanu (wznawialne) → kolejny krok planowany natychmiast albo z backoffem
                                   │
VerificationRunner (osobny worker async + healthcheck co minutę) — patrz §9; NIE blokuje wyszukiwania
UI polling GET /discovery/searches/{id} (co 2,5 s) ◄──┘  + samonaprawa kolejki (kick)
```

- **Statusy:** `queued` → `running` → `completed` / `failed` / `cancelled`. Postęp: 0–95% pobieranie; weryfikacja ma własny postęp (kolejka).
- **Ponowienia:** Wikidata/Overpass: błąd sieci / 429 / 5xx / timeout → ponowienie po 15 s, 45 s (lub `Retry-After`, maks. 120 s), maks. 3 próby; Overpass przy ponowieniu przełącza się na zapasowy serwer. Apollo: tylko 429. Potem źródło pomijane, zadanie kontynuuje z pozostałymi. Zadanie `failed`, gdy wszystkie źródła zawiodły i brak wyników.
- **Anulowanie:** flaga `cancel_requested` sprawdzana przed każdym krokiem; zadanie z kolejki anulowane od razu.
- **Limity zapytań:** `ProviderHttpClient` wymusza odstęp między zapytaniami do dostawcy (Overpass 5 s, Wikidata 2 s), wspólny dla procesów (transient).
- **Samonaprawa:** patrz sekcja 7 (QueueMonitor, healthcheck, przycisk „Ponów”).

### Źródła danych

| Dostawca | Status | Co daje | Pewność domeny | Ograniczenia |
|---|---|---|---|---|
| **Wikidata** (API wyszukiwania + wbgetentities, CC0) | opcjonalny, bez klucza | firmy z `P17=Polska` i oficjalną stroną `P856`, branża `P452`, siedziba, zatrudnienie `P1128` (gdy podane), NIP z numeru VAT UE `P3608` (z walidacją sumy) | `confirmed` (deklaracja „oficjalna strona”) | głównie większe/znane firmy; małe pokrycie MŚP |
| **OpenStreetMap** (Overpass API, ODbL; filtr prostokątem województwa) | opcjonalny, bez klucza — **na żywo w 10.2026 publiczne instancje Overpass nie odpowiadały w limicie czasu** | obiekty w Polsce (województwo/miasto) z nazwą **i stroną WWW**, dopasowane słowami kluczowymi i tagami branżowymi (`craft`, `industrial`, `office`, `shop`, `product`) | `uncertain` → `confirmed` po weryfikacji strony | brak danych o zatrudnieniu; jakość zależy od społeczności; wymagana atrybucja (jest w stopce panelu) |
| **Apollo** (główne, etap 1.1) | wymaga klucza `UBR_APOLLO_API_KEY` i planu z dostępem do API | firmy B2B z domeną, branżą, szacowanym zatrudnieniem, rokiem założenia | `uncertain` → weryfikacja | płatne (kredyty); patrz sekcja 8 |

Rozważone i odrzucone na tym etapie: scraping Google (niedozwolony), katalogi typu Panorama Firm/Aleo (brak API, regulaminy), CEIDG API i GUS BIR (wymagają rejestracji/klucza — sensowne jako **wzbogacanie** NIP/REGON w kolejnym etapie), API KRS (wyszukiwanie tylko po numerze KRS — **[przyszłość]** wzbogacanie). Nowy dostawca = klasa implementująca `DiscoveryProvider` + filtr `ubr/discovery/providers`.

### Dopasowanie i jakość danych

- Kryteria: branża (katalog z rdzeniami PL/EN i tagami OSM) i/lub fraza (prosty stemming, pomijanie słów ogólnych typu „producenci”, „Polska”), województwo, miejscowość, wielkość (filtr tylko gdy źródło podaje zatrudnienie; opcja uwzględnienia firm bez danych), limit wyników, wybór źródeł.
- Dopasowanie słów od początku wyrazu (żeby „stal” nie łapało „instalacji”).
- **Deduplikacja** (`CompanyIngestService`): identyfikator u dostawcy → domena → NIP → KRS → znormalizowana nazwa + miasto (bez sprzecznych identyfikatorów). Istniejące wartości nie są nadpisywane danymi o niższej pewności.
- **Domeny:** normalizacja (schemat, `www`, wielkość liter, IDN), klasyfikacja: oficjalna / social / katalog / marketplace / subdomena kreatora stron. Tylko domena oficjalna trafia do `companies.domain`; pozostałe zostają w historii jako `website_non_official`. Konflikt domeny (zajęta przez inną firmę) jest zapisywany jako `domain_conflict`, bez przypisania.
- **Weryfikacja strony** (`WebsiteChecker`): GET z limitem rozmiaru i przekierowań; status `reachable` / `redirected` / `http_error` / `unreachable`, tytuł, dopasowanie nazwy firmy w treści lub domenie. Firma nie jest odrzucana, gdy strona chwilowo nie odpowiada. Przekierowanie na katalog/social obniża pewność domeny.
- **Pewność:** `confirmed` / `uncertain` / `unavailable` dla domeny i wielkości — widoczna w UI.

## 6. Interfejs

- `/app` Dashboard (liczniki, ostatnie wyszukiwania i firmy), `/app/discover` (formularz, status zadania z postępem i dziennikiem, wyniki z paginacją i filtrem „tylko z domeną”, zapis do prospektów, historia), `/app/prospects` (wyszukiwanie, filtry, sortowanie, paginacja, usuwanie z potwierdzeniem), `/app/settings` (organizacja, członkowie i role, przełączanie/tworzenie organizacji, profil).
- Szuflada szczegółów firmy (źródła, historia pochodzenia, status strony, pewność) wspólna dla wszystkich ekranów.
- Tailwind 4 (`@utility` komponenty), fonty Manrope/Fredoka, kolory z logo (granat `#141b2d`, złoto `#f4b223`, zieleń `#0b6b43`).

## 7. Uruchamianie zadań w tle (po naprawie z etapu 1.1)

**Przyczyna problemu „W kolejce / 0%” w LocalWP:** Local uruchamia PHP-FPM z `pm = static, pm.max_children = 2`. WP-Cron wykonuje zadania tylko przy ruchu na stronie, maks. raz na 60 s (blokada `doing_cron`), a długie zapytanie do źródła (Wikidata SPARQL / Overpass ~60–100 s) zajmowało jeden z dwóch procesów. Ponowienia zaplanowane „za 30/90 s” nie miały kto uruchomić bez otwartej karty, a awaryjny krok w żądaniu statusu blokował drugi proces.

**Obecny mechanizm:**
- **AsyncWorker** — po utworzeniu zadania i po każdym kroku wysyłane jest nieblokujące żądanie loopback `POST admin-ajax.php?action=ubr_worker` (podpis HMAC z solą WP, ważny 10 min). Worker wykonuje jeden krok (budżet 20 s) i zleca kolejny. Nie zależy od ruchu na stronie ani od otwartej karty.
- **Ponowienia** (np. 429, chwilowy błąd źródła): worker czeka na termin w krokach po maks. 10 s (jeden „czekający” proces na zadanie), krok nie wykona się przed `next_run_at`, niezależnie od tego, kto go zlecił.
- **Siatka bezpieczeństwa:** zapasowe zdarzenie WP-Cron dla każdego kroku (+45 s) i healthcheck WP-Cron co minutę (`ubr_queue_healthcheck`).
- **QueueMonitor:** zadanie zaległe > 20 s bez blokady → ponowne zlecenie workera (z wpisem w dzienniku); brak postępu > 15 min → status `failed` z komunikatem; użytkownik może kliknąć **Ponów** (nowe zadanie z tymi samymi kryteriami). Wywoływany z healthchecku, odpytywania statusu i `wp ubr queue-work`.
- **Brak kroków w żądaniu przeglądarki** — usunięto awaryjne wykonywanie kroku w REST (blokowało proces PHP). Gdy loopback nie działa, UI pokazuje ostrzeżenie (`queue_warning`), a `wp ubr doctor` diagnozuje przyczynę.
- **CLI:** `wp ubr discover … --sync` (cały proces w CLI, bez workerów HTTP), `wp ubr queue-work [--loop]` (dla systemowego crona), `wp ubr doctor [--providers]`.
- Zalecenie dla LocalWP: zwiększyć `pm.max_children` (np. do 6) w `conf/php/php-fpm.d/www.conf.hbs` i zrestartować stronę — aplikacja działa i przy 2, ale panel jest wtedy wolniejszy, gdy worker pracuje.

## 8. Apollo Discovery (etap 1.1)

**Endpoint:** `POST https://api.apollo.io/api/v1/mixed_companies/search` (Organization Search), klucz w nagłówku `x-api-key`. Parametry w query string (format tablic `name[]`).

**Wg aktualnej dokumentacji Apollo (docs.apollo.io, październik 2026):**
- zużywa **1 kredyt za stronę** wyników; maks. **100 firm na stronę**, limit wyświetlania 50 000 rekordów (500 stron),
- wymaga konta Apollo z firmowym adresem e-mail; dostęp do API zależy od planu („Access to Apollo API depends on your Apollo plan”); 403 = plan lub zakres klucza nie obejmuje endpointu,
- limity wyszukiwania: Free 50/min, 200/h, 600/dzień; płatne plany 200/min, 6 000/h, 50 000/dzień (per zespół). Nagłówki `x-*-requests-left`, `retry-after` przy 429,
- API **nie raportuje** zużytych kredytów w odpowiedzi — aplikacja liczy tylko zapytania „potencjalnie płatne”,
- filtr lokalizacji dotyczy siedziby (HQ) i przyjmuje miasta, stany USA i kraje — **województw nie ma**,
- schemat odpowiedzi nie jest udokumentowany na stronie endpointu. **Zaobserwowana odpowiedź (żywe API, plan Free, 10.2026):** klucze `breadcrumbs, partial_results_only, pagination{page, per_page, total_entries, total_pages}, accounts, organizations, …`; rekord organizacji zawiera m.in. `id, name, website_url, primary_domain, linkedin_url, founded_year, sic_codes, naics_codes, organization_revenue (0 = brak danych), phone` — **nie zawiera** miasta, województwa, kraju, branży, słów kluczowych ani liczby pracowników. Mapowanie jest defensywne (jeśli pola się pojawią, zostaną użyte); telefon nie jest zapisywany.

**Używane filtry (wszystkie wykonuje Apollo):** `organization_locations[]` — `poland`, województwo w formacie Apollo (np. `Masovian Voivodeship, Poland`, `RegionCatalog::APOLLO_LOCATIONS`; zweryfikowane na żywo: 58 stron zamiast 612 dla całej Polski) albo `{miasto}, Poland`; `q_organization_keyword_tags[]` (tagi EN z katalogu branż + fraza użytkownika); `organization_num_employees_ranges[]` (1–10, 11–50, 51–200, 201–500, 501–1000, 1000+); `page`, `per_page`. Ponieważ wyniki nie zawierają lokalizacji ani zatrudnienia, województwo/miasto i przedział zatrudnienia firmy pochodzą z filtra przekazanego do Apollo (pewność „niepewna”). Branża = grupa SIC (`IndustryCodes`) + kody SIC/NAICS. Niezaimplementowane (dostępne w API): przychody, technologie, finansowanie, rekrutacje.
Uwaga: pierwsza wersja filtrowała województwo po pobraniu — na żywym API odrzucała wszystko (brak pola `state`) i zużyła 6 kredytów bez wyników; poprawione.

**Mapowanie:** domena z `website_url`/`primary_domain` (pewność „niepewna” do czasu weryfikacji strony), zatrudnienie = `estimated_num_employees` (szacunek → „niepewne”), województwo z `state`, rok założenia, przychody, LinkedIn. Firmy spoza Polski są odrzucane. Kontakty osób **nie są pobierane**, enrichment **nie jest wykonywany**.

**Kontrola kosztów (`ApolloBudget` + `ApiRequestRepository`):**
- liczba stron = ⌈limit / 100⌉ (limit 25/50/100/250/500 → 1/1/1/3/5 stron), twardy limit 5 stron na wyszukiwanie,
- limity organizacji (domyślnie 30 stron/dzień, 300/miesiąc) i globalny limit instalacji (100 stron/dzień) — sprawdzane PRZED każdym płatnym zapytaniem; zmiana: stałe `UBR_APOLLO_ORG_PAGES_PER_DAY`, `UBR_APOLLO_ORG_PAGES_PER_MONTH`, `UBR_APOLLO_GLOBAL_PAGES_PER_DAY` lub filtr `ubr/apollo/limits` (per plan organizacji),
- cache identycznych zapytań 12 h (transient) — powtórzenie nie kosztuje,
- automatyczne ponowienie **tylko** dla 429 (zapytanie odrzucone); timeout i 5xx **nie są ponawiane** (mogły zostać naliczone) i liczą się zachowawczo jako „potencjalnie płatne”; 401/403/422 kończą źródło z czytelnym komunikatem,
- każde zapytanie zapisywane w `ubr_api_requests` (endpoint, HTTP, czas, cache, zwrócone/nowe/duplikaty, nagłówki limitów, błąd).

**Konfiguracja:** `define('UBR_APOLLO_API_KEY', '…');` w `wp-config.php` (lub zmienna środowiskowa). Klucz nigdy nie trafia do HTML, JS ani REST — Settings pokazuje tylko „skonfigurowane” i źródło klucza. Jeden klucz operatora SaaS; użytkownicy nie wpisują własnych.

**Settings → Integrations → Apollo:** status, źródło klucza, limity organizacji, statystyki (zapytania, potencjalnie płatne, firmy zwrócone/nowe, błędy, ostatnie zapytania), test połączenia `GET /auth/health` + `POST /usage_stats/api_usage_stats` (0 kredytów, wymaga klucza master — 403 nie blokuje wyszukiwania) oraz jawny test wyszukiwania (1 strona × 1 firma = 1 kredyt, z potwierdzeniem). Statystyki całej instalacji widzi tylko administrator WP.

**Google Places** — usunięte w etapie 1.1 (provider, konfiguracja, UI, testy, dokumentacja).


## 9. Etap 1.2 — Discovery Optimization + Company Verification

### Lokalizacja (dlaczego województwo dawało 0 wyników)

Przyczyna 0 wyników w wyszukiwaniach #4/#5 (etap 1.1): aplikacja filtrowała województwo PO pobraniu po polu `state`, którego wyniki wyszukiwania Apollo w ogóle nie zawierają — każdy rekord był odrzucany. Poprawka 1.1: województwo idzie do Apollo jako lokalizacja siedziby (`Masovian Voivodeship, Poland`). Błąd wtórny (naprawiony w 1.2): firmy dostawały województwo/miasto/przedział zatrudnienia **z filtra**, mimo że Apollo ich nie podało.

Teraz:
- pola firmy wypełnia tylko rekord źródła (`Confidence::DECLARED`) albo rejestr (`CONFIRMED`); migracja M003 usunęła z pól firm wartości z filtrów 1.1 (zostały w historii jako `search_filter_*`),
- `CityCatalog`: 159 miast (PL / bez znaków / EN) → województwo; Apollo dostaje warianty („Kraków, Poland”, „Krakow, Poland”, „Cracow, Poland”); sprzeczne miasto + województwo → 422 przed zapytaniem (bez kosztu),
- stan lokalizacji wyniku: `confirmed` (rejestr), `declared` (rekord źródła), `filtered` (tylko filtr Apollo / prostokąt OSM), `partial`, `unknown`, `mismatch`, `not_required`; brak danych ≠ niezgodność,
- diagnostyka wyszukiwania: zwrócone przez źródło, zapisane, nowe/duplikaty, odrzucone wg powodów (`country_mismatch`, `location_mismatch`, `location_unknown`, `size_mismatch`, `not_a_company`, …), filtry wykonane po stronie Apollo, liczba trafień Apollo, rozkład stanów lokalizacji i weryfikacji; przy 0 wyników zawsze wyjaśnienie z filtrami i podpowiedzią.

### Branże

`IndustryCatalog` (17 branż, m.in. rozdzielone konstrukcje stalowe / obróbka metali, nowe: opakowania, elektronika przemysłowa): polskie frazy (`phrases` → `IndustryCatalog::detect`), wąskie angielskie tagi Apollo, prefiksy SIC/NAICS (deklaracja Apollo) i PKD 2007 (potwierdzenie w rejestrze). Polska fraza katalogowa nie trafia do Apollo jako tag (tłumaczona); inne frazy — dosłownie.

### Relevance Score (`RelevanceScorer`)

Deterministyczna zgodność z kryteriami (0–100): branża 40 (PKD z rejestru 40 / SIC-NAICS 30 / słowa w danych 26 / tylko filtr 14–16 / brak 8 / PKD niezgodne 4), słowa kluczowe 20, lokalizacja 25 (potwierdzona 25 / deklarowana 20 / częściowa 10–14 / tylko filtr 8–12 / brak 5 / niezgodna 0), wielkość 15 (zgodna 15 / tylko filtr 8 / brak 5 / niezgodna 0). Kryterium niepodane = pełna pula. Kary (`FitFlags`): urząd/uczelnia, stowarzyszenie/izba, portal/media, katalog −30; nieaktywna ≤ 10; zawieszona ≤ 20. Flagi informacyjne: duże przedsiębiorstwo, możliwy oddział grupy, brak strony. Przeliczany po weryfikacji. Domyślne sortowanie wyników i prospektów. To nie jest Opportunity Score.

### Weryfikacja przedsiębiorstwa (`CompanyVerifier` + `CompanyStatusDecider`)

1. `WebsiteInspector`: strona główna + maks. 3 podstrony (dane firmowe/impressum, RODO/polityka prywatności, kontakt, o firmie) → NIP/KRS/REGON z etykietą i sumą kontrolną, JSON-LD, tytuł, og:site_name, stopka/©, e-mail w domenie, kody pocztowe/miasta, parking domeny.
2. Rejestry WYŁĄCZNIE po identyfikatorach (ze strony lub rekordu źródła): KRS (`api-krs.ms.gov.pl`, rejestr P/S), biała lista VAT (`wl-api.mf.gov.pl` / od 12.10.2026 `wykazpodatnikowvat.mf.gov.pl`, podaje KRS → odpis), CEIDG (token `UBR_CEIDG_API_TOKEN`) i GUS BIR 1.1 (`UBR_GUS_API_KEY`) — dwa ostatnie wyłączone bez kluczy. Maks. 6 zapytań na firmę, cache 7 dni (404: 1 dzień), dzienne limity (VAT 80), log w `ubr_api_requests` (billable = 0).
3. Dopasowanie (`NameMatcher`): identyczne 1,0 / podzbiór słów 0,9 / bez spacji 0,85 / Jaccard. Rekord pasuje, gdy nazwa ≥ 0,85, rejestr podaje tę domenę, albo identyfikator jest ze strony firmy i nazwa ≥ 0,5. Niepasujący rekord = rozbieżność, identyfikator NIE jest przypisywany. Kilka różnych pasujących podmiotów → UNCERTAIN.
4. Status: KRS/CEIDG/GUS aktywny → **VERIFIED**; wykreślenie/rozwiązanie/likwidacja/upadłość → **INACTIVE**; zawieszenie bez wznowienia → **SUSPENDED**; tylko VAT „Czynny/Zwolniony” → VERIFIED (pewność średnia); wszystko inne → **UNCERTAIN** (z powodem). Dane z rejestru zapisywane jako potwierdzone (nazwa rejestrowa, forma prawna, NIP/KRS/REGON jeśli wolne, adres siedziby, województwo, PKD); rozbieżności (np. województwo Apollo ≠ KRS, VAT wykreślony przy aktywnym KRS, możliwy duplikat) w `verification_summary`. Bez danych osobowych i rachunków bankowych.

### Weryfikacja domeny (`DomainDecider`, niezależna od statusu firmy)

UNAVAILABLE (brak strony / brak odpowiedzi) · MISMATCH (parking/sprzedaż, katalog/social/marketplace, przekierowanie na inną domenę bez nazwy, dane innego podmiotu bez nazwy firmy, treść bez nazwy) · CONFIRMED (NIP/KRS ze strony należy w rejestrze do firmy, albo rejestr podaje domenę, albo nazwa w tytule/stopce/JSON-LD + dane kontaktowe) · UNCERTAIN (pozostałe — np. sama zbieżność nazwy domeny).

### Kolejka weryfikacji (`VerificationRunner`)

Po zakończeniu pobierania wyszukiwanie od razu ma status `completed`, a firmy (bez świeżej weryfikacji < 30 dni) trafiają do `ubr_verification_queue`. Jeden worker naraz (blokada w opcji), loopback `admin-ajax.php?action=ubr_job_worker` (HMAC), budżet 20 s na krok, łańcuch kolejnych kroków + healthcheck WP-Cron co minutę + zapasowe zdarzenie `ubr_verification_tick`. Rejestr niedostępny → status UNCERTAIN + ponowienie po 2 min / 10 min / 1 h / 6 h (maks. 4 próby). Ręcznie: `POST /companies/{id}/verify` (cooldown 10 min, limit organizacji 150/dzień), `POST /discovery/searches/{id}/verify` (`force` = ponowna weryfikacja), uprawnienie `companies.verify`. Profil firmy tylko czyta zapisane statusy.

### SaaS

Rejestry i statusy są danymi publicznymi wspólnymi dla bazy firm; organizacja widzi je tylko dla firm ze swoich wyników/prospektów (`isVisibleTo`), trafność — tylko ze swoich wyszukiwań, w historii weryfikacji — tylko informację „zlecone przez moją organizację”.


## 10. Prompt 2 — Website Analyzer, Website Preview, Opportunity Score

### Komponenty

| Warstwa | Klasa | Odpowiedzialność |
|---|---|---|
| Domain | `Domain/Analysis/HtmlDocument` | parsowanie HTML (DOMDocument) i selektory dla analizatorów |
| Domain | `Analyzers/*` (Visual, UxStructure, Mobile, ConversionContent, Technical, Seo, Accessibility, BusinessPotential, CompanyConfidence) | kryteria (pkt / NOT_EVALUATED), metryki, findings z dowodami |
| Domain | `OpportunityScoreCalculator`, `Criterion`, `Finding` | wynik 0–100, coverage, confidence, INSUFFICIENT_DATA, przedziały |
| Domain | `Vision/VisionSchema` | schemat i walidacja odpowiedzi AI, wersjonowany prompt |
| Infrastructure | `Http/UrlGuard`, `Http/PageFetcher` | SSRF (DNS, porty, przekierowania), pobieranie HTML (2 MB, 12 s) |
| Infrastructure | `Analysis/SiteCapture` + `tools/site-capture/capture.mjs` | render w Chromium (Playwright), screenshoty, metryki, axe-core — proces Node przez proc_open |
| Infrastructure | `Analysis/ScreenshotStorage`, `Http/ScreenshotEndpoint` | prywatne przechowywanie i serwowanie z autoryzacją |
| Infrastructure | `Ai/VisionService` | AI Vision (Anthropic Messages API) — przygotowane, wyłączone |
| Infrastructure | `Repositories/AnalysisRepository` | `ubr_website_analyses` = historia + kolejka |
| Services | `WebsiteAnalyzerService` | potok jednej analizy, statusy, zapis, synchronizacja pól firmy |
| Services | `WebsiteAnalysisRunner`, `AnalysisLimits`, `AnalysisService` | kolejka (sloty równoległości), limity, przypadki użycia API |

### Przepływ

`POST /companies/{id}/analyze` lub `POST /analyses` (zbiorczo, maks. 20) → uprawnienie `analysis.run`, widoczność firmy, limity → rekord `queued` → `AsyncWorker::dispatchJob('analyze')` (+ zdarzenie WP-Cron, healthcheck co minutę). Worker: slot równoległości (domyślnie 1, maks. 2), jedna analiza na krok:
1. HTML strony głównej (PageFetcher, każdy krok przekierowania przez UrlGuard), podstrony oferty i kontaktu — wybór deterministyczny (najpierw dopasowanie w ścieżce URL, potem w tekście linku, krótsza ścieżka; oferta nie może być stroną kontaktu; mailto:/tel: i duplikaty pomijane), maks. 3 strony; sprawdzenie http→https,
2. render strony głównej (Desktop 1440×900, Mobile 390×844 @2x): load + network idle (limit), odrzucenie banera cookies przyciskiem „odrzuć / tylko niezbędne” (nigdy „akceptuj”), inaczej ukrycie znanych banerów CMP oraz własnych banerów (element fixed/sticky z tekstem o cookies) na zrzucie (zapisane w metadanych), przewinięcie strony (lazy loading), wyłączenie animacji (reduced motion + CSS), rozwinięcie przewijanego kontenera, gdy strona przewija wewnętrzny element zamiast dokumentu (`scroll_container_expanded`), screenshot JPEG (jakość 72→45, maks. wysokość 6000/7000 px, limit rozmiaru 2,5 MB), metryki DOM, czasy laboratoryjne, sieć, axe-core WCAG 2 A/AA,
3. AI Vision — tylko gdy włączone (domyślnie nie),
4. analizatory → kryteria → Opportunity Score → findings (posortowane wg severity),
5. zapis NOWEGO rekordu (historia nienadpisywana) + zdenormalizowane pola firmy (`opportunity_score`, `analysis_status`, `analyzed_at`, …).

Statusy: QUEUED → RUNNING → COMPLETED (HTML + oba screenshoty) / PARTIAL (HTML jest, renderu brak lub błąd) / FAILED (strona nie odpowiada, adres zablokowany). Błąd przejściowy (timeout, brak odpowiedzi) → ponowienie po 5 min (maks. 2 próby).

### Opportunity Score

| Kategoria | Max | Podkryteria (max) | Źródło |
|---|---|---|---|
| A. Słabość strony | 45 | design AI 15 · nawigacja 5 · struktura 3 · viewport 3 · mobile render 7 · kontakt/CTA 4 · CTA w 1. ekranie 3 · treść 5 | AI / HTML / render |
| B. Słabość techniczna | 20 | wydajność serwer 2 · wydajność przeglądarka 5 · SEO 5 · dostępność HTML 2 · axe 3 · bezpieczeństwo 3 | HTML / render |
| C. Potencjał biznesowy | 25 | wielkość 10 · branża 8 · B2B 4 · dane finansowe 3 | rejestr (PKD), Apollo (SIC, przychód — deklaracja), strona (sygnał B2B) |
| D. Wiarygodność firmy | 10 | status w rejestrze 5 · domena 3 · aktualność weryfikacji 2 | weryfikacja 1.2 |

Wyższe punkty = słabsza strona / większy potencjał. Korekta wag względem promptu: kategorie bez zmian, ale A rozbite tak, by 15 pkt „designu” zależało wyłącznie od analizy obrazu, a reszta od obiektywnych pomiarów. Wielkość: 50–249 os. = 10 (segment docelowy), 10–49 = 6, 250–999 = 8, ≥ 1000 = 4 (marketing centralny), < 10 = 2. Branża: produkcja 8, hurt/logistyka 7, budownictwo/usługi techniczne 6, …, administracja/stowarzyszenia/media 0.

Brakujące dane: kryterium bez pomiaru = NOT_EVALUATED, 0 pkt; wynik NIE jest przeskalowywany; pokazujemy coverage (% wag zmierzonych), confidence (wysoka ≥ 90%, średnia ≥ 65%, niska) i „maks. przy pełnych danych” (wynik + punkty nieocenione). INSUFFICIENT_DATA (brak liczby): strona niedostępna albo coverage < 50%. Firma nieaktywna/zawieszona → maks. 10. Bez AI Vision maksymalne pokrycie = 85% (pewność co najwyżej średnia). Przedziały (filtr `ubr/opportunity/levels`): 0–39 Low, 40–59 Moderate, 60–79 High, 80–100 Very High.

### UX/UI bez AI

Bez AI Vision kategorie `visual_quality, visual_hierarchy, typography, navigation, mobile_experience, conversion_clarity, brand_consistency` mają status NOT_EVALUATED, a findings zawierają sugestię ręcznej oceny (`manual_review`). Analizatory HTML/renderu to „sygnały strukturalne” (menu, H1, CTA w pierwszym ekranie, telefon/formularz, oferta, rok w stopce, elementy zaufania, overflow, cele dotykowe, mały tekst) — nie są przedstawiane jako analiza wizualna.

### Website Preview

Lightbox ~95% ekranu (Discover, Prospects, karta firmy): screenshot Desktop/Mobile (podstawa — zawsze działa), przewijanie, powiększanie (25–200%, „Dopasuj”), ESC, X, „Otwórz stronę”, tryb „Na żywo” (iframe z sandboxem) tylko gdy nagłówki strony nie blokują osadzania (`X-Frame-Options`, `frame-ancestors`). Dostępność: `role=dialog`, `aria-modal`, etykieta, pułapka fokusu, przywrócenie fokusu.

### Bezpieczeństwo

SSRF (PHP i przeglądarka): http/https, porty 80/443, bez danych logowania w URL, DNS (A + AAAA) wyłącznie na publiczne IP (bez loopback, prywatnych, link-local/metadata, CGNAT, multicast, ULA), każdy krok przekierowania; w przeglądarce każde żądanie przez `page.route`, a kroki przekierowań (których route nie przechwytuje) w zdarzeniu `request` — naruszenie przerywa render i nie zapisuje screenshotów. Ryzyko rezydualne: DNS rebinding między sprawdzeniem a połączeniem przeglądarki oraz żądanie wykonane do adresu z przekierowania zanim zostanie wykryte (odpowiedź nie jest zapisywana). Chromium z włączonym sandboxem, bez rozszerzeń, bez pobierania plików, service workers zablokowane, limit pamięci JS, twardy timeout procesu. URL zawsze z rekordu firmy (domena niezgodna → analiza odrzucona). Screenshoty poza webrootem, nazwy generowane (HMAC), serwowane z kontrolą dostępu, bez ścieżek w API.

### Wymagania środowiskowe

Node.js ≥ 18 (wykrywany: Homebrew, /usr/local, nvm, volta; lub `UBR_NODE_BINARY`), `npm install` w `tools/site-capture` (playwright-core 1.64.0, axe-core 4.14.0 — bez pobierania przeglądarek), przeglądarka: Google Chrome / Chromium (wykrywana lub `UBR_CHROME_PATH`), PHP `proc_open`. Limity: `UBR_ANALYSIS_ORG_PER_DAY` (25), `UBR_ANALYSIS_ORG_PER_MONTH` (300), `UBR_ANALYSIS_BULK_MAX` (20), `UBR_ANALYSIS_CONCURRENCY` (1), `UBR_ANALYSIS_TIMEOUT_MS` (75000), `UBR_SCREENSHOT_MAX_BYTES` (2,5 MB), filtr `ubr/analysis/limits` (per organizacja — miejsce na plany SaaS).

## 11. Decision Maker Finder + Contact Priority Score (Prompt 3)

Cel: ustalić, kto w firmie może zdecydować o zakupie nowej strony WWW — bez wymyślania osób i bez zgadywania adresów e-mail.

### Komponenty

| Warstwa | Klasa | Odpowiedzialność |
|---|---|---|
| Domain | `Contacts/PersonName`, `FirstNames` | walidacja imienia i nazwiska (pierwszy człon = znane imię), klucz deduplikacji, dopasowanie do maski KRS |
| Domain | `Contacts/RoleCatalog` | normalizacja stanowisk PL/EN → role (owner, founder, ceo, managing_director, vice_president, board_member, proxy, marketing_director, digital_head, marketing_manager, marketing_specialist, sales_director, other_director); stanowiska bez związku z decyzją pomijane |
| Domain | `Contacts/PeopleExtractor` | osoby z HTML oficjalnej strony (pary „imię ↔ stanowisko”, linia „Jan Kowalski – Prezes Zarządu”, JSON-LD Person), e-mail / telefon / profil tylko z bloku osoby, wzmianki o dawnej funkcji, funkcja w innym podmiocie, kanały ogólne firmy |
| Domain | `Contacts/ContactMerger` | deduplikacja (ContactDeduplication) — jedna osoba, wiele źródeł; konflikt stanowisk oznaczany, nie nadpisywany |
| Domain | `Contacts/ContactVerifier` | dopasowanie do firmy, potwierdzenie w KRS, aktualność, status VERIFIED / PROBABLE / UNCERTAIN / OUTDATED / REJECTED z uzasadnieniem |
| Domain | `Contacts/ContactConfidence` | pewność (tożsamość, aktualność stanowiska, dopasowanie firmy, dane kontaktowe) → HIGH / MEDIUM / LOW |
| Domain | `Contacts/ContactPriorityCalculator` | Contact Priority Score 0–100 z rozbiciem (wersja `cps-2026-10-v1`) |
| Domain | `Contacts/ContactRanking` | Top 1 / Top 2 / Top 3 + uzasadnienia, uwzględnia prywatne decyzje organizacji |
| Infrastructure | `Contacts/ContactPageCrawler`, `RobotsPolicy` | wybrane podstrony firmy (bez zgadywania adresów), robots.txt, limity, SSRF przez `PageFetcher`/`UrlGuard` |
| Infrastructure | `Contacts/ApolloPeopleSource` | Apollo People API Search — przygotowane, domyślnie wyłączone |
| Infrastructure | `Registry/KrsClient::board()` | skład zarządu i prokurenci z działu 2 odpisu (te same, buforowane zapytanie co weryfikacja firmy) |
| Infrastructure | `Repositories/ContactRunRepository`, `ContactRepository`, `ContactFilters` | historia + kolejka, kontakty, źródła, historia wyniku, decyzje prywatne, wykluczenia, filtry list |
| Services | `DecisionMakerFinderService` | jedno wyszukiwanie (pipeline poniżej), wersja `dmf-2026-10-v2` |
| Services | `ContactResearchRunner` | kolejka (ContactResearchJob): worker `ubr_job_worker` / zadanie `contacts`, WP-Cron `ubr_contacts_tick`, healthcheck |
| Services | `ContactResearchService`, `ContactLimits` | przypadki użycia (zlecenie, widok, przegląd ręczny, kontakty ręczne), limity |
| Http | `ContactController` | REST (poniżej) |

### Źródła danych — stan faktyczny

- **KRS (API MS, bezpłatne)** — DZIAŁA. Dział 2: organ reprezentacji (funkcje: „PREZES ZARZĄDU”, „WICEPREZES ZARZĄDU”, „CZŁONEK ZARZĄDU”, zawieszenie), prokurenci, sposób reprezentacji, data stanu. **API anonimizuje imiona i nazwiska** (pierwsza litera + gwiazdki do pełnej długości, np. „Marek Adamiak” → „M**** A******”; PESEL zamaskowany). KRS nie dostarcza więc nazwisk — **potwierdza** osoby znalezione w innych źródłach (zgodne inicjały i długości + funkcja). Zapisujemy wyłącznie maski/inicjały i funkcje; rada nadzorcza pomijana.
- **Oficjalna strona firmy** — DZIAŁA. Linki opublikowane na stronie głównej (i drugi poziom z „o nas” / „relacje inwestorskie”) do podstron: zarząd / władze / management (100), zespół / team (80), relacje inwestorskie (60), o nas / o firmie / poznaj nas (55), kontakt (50), media / press (30). Pomijane: kariera, polityki, sklep, pliki, wpisy blogowe. Maks. 6 podstron, 10 zapytań, 45 s; robots.txt; 403/429 = koniec bez obchodzenia.
- **Apollo People API Search** — PRZYGOTOWANE, WYŁĄCZONE. Wg dokumentacji (docs.apollo.io, 10.2026) `POST /api/v1/mixed_people/api_search` nie zużywa kredytów, ale wymaga klucza master i nie zwraca e-maili/telefonów (to robi płatny enrichment — nieużywany). Włączenie: `UBR_APOLLO_PEOPLE_ENABLED` w wp-config.php (decyzja właściciela). Kandydat tylko z Apollo = UNCERTAIN; zamaskowane nazwisko = brak kontaktu.
- **Biała lista VAT** — pola reprezentantów są dla spółek puste; nie używane jako źródło osób (minimalizacja danych).
- **CEIDG / GUS** — przygotowane w module weryfikacji, bez kluczy; brak nie blokuje modułu.
- **LinkedIn / inne platformy** — nie pobierane (regulaminy, brak autoryzowanego API). Zapisujemy tylko link do profilu, jeśli firma opublikowała go przy osobie.

### Przepływ

`POST /companies/{id}/contacts/research` lub `POST /contacts/research` (zbiorczo, maks. 20) → uprawnienie `contacts.run`, widoczność firmy, limity, cache → rekord `queued` → worker:
1. KRS: `KrsClient::board()` (cache 7 dni wspólny z weryfikacją),
2. strona firmy (nie przy domenie MISMATCH): crawler → `PeopleExtractor` → kandydaci + kanały ogólne,
3. Apollo People — tylko gdy włączone,
4. osoby wyłączone z researchu (`ubr_contact_suppressions`) pomijane,
5. `ContactMerger` → `ContactVerifier` (+ źródło „KRS” przy potwierdzeniu) → przypisanie adresu działu wg działu osoby → `ContactConfidence` → `ContactPriorityCalculator`,
6. `ContactRanking` (globalny),
7. zapis: kontakty (upsert po `company_id + org_key + person_key`), źródła (fragment ≤ 400 znaków, data), historia wyniku/pozycji; osoby nieznalezione w pełnym wyszukiwaniu → `active = 0` (bez kasowania),
8. rekord wyszukiwania: źródła i ich stan, odwiedzone strony, skład zarządu (inicjały), migawka Top 3, lista zmian (nowe osoby, zmiany stanowisk, pozycji, statusów, zniknięcia), błędy, kanały firmy,
9. podsumowanie przy firmie (`contacts_status`, `contacts_count`, `top_contact_*`, `company_channels`) — do list.

Status wyszukiwania: COMPLETED (źródła odpowiedziały — również gdy nie znaleziono osób), PARTIAL (część włączonych źródeł zawiodła), FAILED (żadne źródło nie odpowiedziało; błąd przejściowy → ponowienie po 5 min, maks. 2 próby). Status firmy w UI: Nie szukano / W kolejce / Wyszukiwanie / Gotowe / Częściowe / Błąd.

### Model danych (M005)

- `ubr_contact_research_runs` — historia + kolejka (status, wersja, próby, termin, blokada, czasy, liczba kandydatów, sources, pages, snapshot, changes, errors, company_channels; organization_id/user_id = kto zlecił, prywatne),
- `ubr_contacts` — `org_key = 0` wynik automatyczny (globalny), `org_key = id organizacji` kontakt ręczny (prywatny, USER_PROVIDED); pola: imię, nazwisko, stanowisko, funkcja z KRS, rola, dział, seniority, decyzyjność, Contact Priority (+ status, breakdown), confidence (+ składowe), status weryfikacji + uzasadnienie, dopasowanie firmy, zatrudnienie (current/former/unknown), publiczny służbowy e-mail / telefon / profil, źródło główne, daty, pozycja i uzasadnienie, needs_review, flagi, aktywność, wersja,
- `ubr_contact_sources` — wiele źródeł na osobę (typ, URL, tytuł, obserwowane stanowisko, fragment, pewność, data pobrania),
- `ubr_contact_score_history` — wynik, status, pozycja, stanowisko w każdym wyszukiwaniu,
- `ubr_contact_reviews` — PRYWATNE decyzje organizacji: approved / rejected / outdated / excluded, preferred, notatka,
- `ubr_contact_suppressions` — wykluczenia z researchu (globalne, decyzja administratora: `wp ubr contacts suppress`),
- `ubr_companies`: `contacts_status, contacts_researched_at, contacts_count, last_contact_run_id, top_contact_id/name/title/role/score/confidence, company_channels`.

### Weryfikacja i confidence

- VERIFIED: funkcja w zarządzie / prokura potwierdzona w aktualnym KRS spółki (jednoznaczne dopasowanie maski) + osoba na oficjalnej stronie, firma dopasowana.
- PROBABLE: oficjalna strona firmy, firma dopasowana, bez twardego potwierdzenia aktualności (np. dyrektor marketingu; członek zarządu bez dostępnego KRS); kontakt ręczny.
- UNCERTAIN: funkcja w innym podmiocie (spółka matka / córka / podobna nazwa — porównanie pełnego zestawu słów nazwy) → `company_match_uncertain`; kilka osób o tej samej masce; maska o innej długości; konflikt stanowisk bez KRS; tylko Apollo.
- OUTDATED: „były / była / do 20xx / w latach…” w opisie osoby albo funkcja zarządu, której nie ma w aktualnym składzie KRS ani wśród prokurentów.
- REJECTED: domena niezgodna (osoby nie są przypisywane).
- Konflikt strona ↔ KRS (np. „Prezes” vs „Członek Zarządu”): funkcja z KRS zachowana dosłownie, PROBABLE, needs_review, pewność maks. średnia.
- Confidence: tożsamość (strona 80, + KRS 95, Apollo 60, ręczny 50), aktualność (KRS ≤ 365 dni 95, strona „zarząd” 75 / zespół-kontakt 70 / media 50, dawna funkcja 10), dopasowanie firmy (95 / 70 / 35 / 0), dane kontaktowe (osobny wskaźnik — brak e-maila NIE obniża pewności co do osoby). Ogólnie = 0,35·T + 0,35·A + 0,30·F; HIGH ≥ 80, MEDIUM ≥ 55; dowolna składowa < 40 → LOW.

### Contact Priority Score (0–100)

| Kryterium | Max | Zasada |
|---|---|---|
| A. Decyzyjność | 35 | właściciel / prezes 35, założyciel 33, dyrektor generalny 32, wiceprezes 28, członek zarządu 22 (+4 obszar marketing/handel), dyrektor marketingu 26, head of digital 22, marketing manager 16, dyrektor sprzedaży 15, prokurent 12, inny dyrektor 12, specjalista 6 |
| B. Związek ze stroną WWW | 30 | dyrektor marketingu / digital 30, marketing manager 27, zarząd z obszarem marketingu 24, właściciel 22, prezes / dyrektor generalny 20, specjalista 15, wiceprezes 14, członek zarządu 12 |
| C. Wielkość firmy | 15 | mała < 50 / średnia 50–249 / duża 250+: zarząd-właściciel 15/11/5, dyrekcja marketingu 10/14/15, marketing manager 10/13/13; nieznana wielkość = NIE oceniane (0 pkt), wynik PROVISIONAL |
| D. Dostępność kontaktu | 10 | osobisty służbowy e-mail opublikowany przy osobie 10, telefon 7, profil zawodowy 5, e-mail działu 4, tylko ogólny kontakt firmy 2 |
| E. Pewność danych | 10 | confidence / 10 |

Firma nieaktywna/zawieszona → maks. 10. INSUFFICIENT_DATA: confidence < 40 lub zamaskowane nazwisko (poza rankingiem). Wielkość wyłącznie z danych o zatrudnieniu (nie z przychodu ani z flagi „duże przedsiębiorstwo”).

### Ranking Top 3

Poza rankingiem: OUTDATED, REJECTED, INSUFFICIENT_DATA, odrzuceni / nieaktualni / wykluczeni przez organizację (lista „ukrytych” w UI). Top 1 wymaga VERIFIED lub PROBABLE (albo zatwierdzenia organizacji), dopasowanej firmy i aktualnej funkcji; nigdy w firmie nieaktywnej. Wartość rankingowa = Contact Priority + preferencja biznesowa (właściciel/prezes: +15 mała/nieznana, +12 średnia, 0 duża; dyrekcja marketingu: +8 duża, +2 średnia) + status (VERIFIED +6, PROBABLE +2, UNCERTAIN −10) − 10 przy niepewnym dopasowaniu firmy; preferowany przez organizację = Top 1. Top 2/3: +8 za grupę ról jeszcze niewybraną (prezes + marketing zamiast samego zarządu). Remisy: wynik, pewność, liczba źródeł, klucz osoby — kolejność stabilna. Brak kandydata = puste miejsce z komunikatem „Nie znaleziono kolejnego wiarygodnego kontaktu.”; brak Top 1 = komunikat i pozycje 2–3. Uzasadnienie generowane wyłącznie z danych (źródło, decyzyjność, związek z marketingiem, dlaczego nie prezes, dostępność e-maila).

### Dane kontaktowe

Zapisujemy tylko: publiczny służbowy e-mail opublikowany przy osobie (nazwa lokalna musi odpowiadać tej osobie), telefon z jej bloku (numer powtarzający się przy kilku osobach = numer firmy), link do profilu zawodowego opublikowany przez firmę. Adresy działów (marketing@, zarzad@, sekretariat@) i ogólne (biuro@, info@ — `COMPANY_GENERAL_EMAIL`) są kanałami firmy, nie osób; adres działu pokazywany przy osobach z tego działu jako „E-mail działu (nie osobisty)”. Adresy spoza domeny firmy pomijane. Brak e-maila → „Brak publicznego adresu e-mail”. **Brak zgadywania e-maili i generowania wariantów.**

### REST

`GET /companies/{id}/contacts` (contacts.view) · `POST /companies/{id}/contacts/research` (contacts.run) · `POST /contacts/research` (zbiorczo) · `POST /companies/{id}/contacts` (ręczny, contacts.manage) · `POST /contacts/{id}/review` (approve/reject/outdated/exclude/reset/prefer/unprefer/note) · `GET /contacts/status`. Listy: `has_contact` (yes/no/not_researched), `min_contacts`, `min_contact_score`, `contact_confidence`, `contact_role` (leadership/marketing/board), filtry Discover `contacts_found/contacts_none/contacts_active`, sortowanie `contact_priority`.

### Limity i cache

`ContactLimits` (stałe `UBR_CONTACTS_*`, filtr `ubr/contacts/limits` z organization_id): 20 firm w zleceniu, 50 dziennie i 500 miesięcznie na organizację, 1 aktywne zlecenie na organizację, równoległość 1 (maks. 2), wynik świeży 30 dni (bez ponownego wyszukiwania), ręczne odświeżenie po 24 h, 6 podstron / 10 zapytań / 45 s na firmę, 2 próby, retencja nieaktywnych kontaktów 365 dni (`wp ubr contacts purge`). Wynik starszej wersji researchu nie blokuje ponownego wyszukiwania. Brak automatycznego researchu wszystkich firm.

### Multi-tenancy i prywatność

Wspólne: publiczne dane zawodowe z automatycznego researchu, źródła, skład zarządu (inicjały). Prywatne dla organizacji: notatki, decyzje (zatwierdź / odrzuć / nieaktualny / wyklucz / preferowany), kontakty ręczne, informacja kto zlecił. Ranking i Top 1 na listach są przeliczane z decyzjami organizacji; prywatna decyzja nie zmienia danych globalnych. Każdy endpoint sprawdza uprawnienie i widoczność firmy; `contact_id` innej organizacji lub firmy spoza jej wyników → 404 (ochrona IDOR).

Prywatność: dane osób pełniących funkcje zawodowe są danymi osobowymi. Minimalizacja (bez prywatnych numerów i adresów, bez danych rodzinnych i wrażliwych, bez PESEL, fragment źródła ≤ 400 znaków), data pozyskania i źródło przy każdej obserwacji, retencja, wykluczenie osoby z researchu (czyści dane kontaktowe, blokuje ponowne zebranie), dziennik wyszukiwań. Moduł nie wysyła wiadomości; opublikowany e-mail nie jest zgodą na marketing — ocena obowiązków z RODO i przepisów o komunikacji elektronicznej należy do administratora. Moduł nie deklaruje zgodności prawnej.

## 12. Sales Pipeline + ClickUp (Prompt 4)

### Model danych (M006)
- `ubr_organization_prospects` (rozszerzona): `status` (pipeline), `owner_user_id`, `priority` (low/medium/high/urgent), `qualification`, `selected_contact_id`, `lost_reason`, `status_changed_at`, `last_activity_at`, `next_action_at`, `archived_at`, `archived_by`. Wszystko prywatne dla organizacji; globalna firma (`ubr_companies`) bez zmian.
- `ubr_prospect_events` — historia zmian (status, priorytet, właściciel, kwalifikacja, kontakt, notatki, follow-upy, eksport ClickUp).
- `ubr_prospect_notes` (soft delete), `ubr_prospect_activities` (CALL/EMAIL/LINKEDIN/MEETING/FOLLOW_UP/NOTE/OTHER — tylko rejestr ręczny), `ubr_prospect_followups` (OPEN/COMPLETED/CANCELLED, termin, przypisany członek).
- `ubr_clickup_connections` (UNIQUE org+user; `token_ciphertext` + `key_id`, konto ClickUp, autoryzowane Workspace, `destination`, `settings` z szablonem nazwy i mapowaniem pól), `ubr_clickup_exports` (UNIQUE org+firma+lista+wariant; `task_id`, `task_url`, `export_status`, `managed_hash`, `exported_at`, `last_synced_at`, `rule_id`), `ubr_clickup_jobs` (kolejka i historia: create/update/evaluate, próby, `next_attempt_at`, blokada, klasyfikacja błędu), `ubr_clickup_rules` (warunki JSON, lista, `enabled` = 0 domyślnie, limit dzienny, liczniki).

### Statusy
`PipelineCatalog::STATUSES`: NEW, QUALIFIED, TO_CONTACT, CONTACTED, FOLLOW_UP, IN_DISCUSSION, PROPOSAL_SENT, WON, LOST, NOT_A_FIT. Kod pobiera listę przez `PipelineCatalog::statuses($orgId)` (filtr `ubr/pipeline/statuses`) — miejsce na statusy konfigurowane per organizacja bez zmiany schematu (status to VARCHAR).

### REST (wybrane)
`GET /pipeline/board` (kolumny + liczniki, `status`+`offset` = doładowanie kolumny), `GET /pipeline/agenda`, `GET /companies/{id}/sales`, `POST /prospects/{id}` (status, priorytet, właściciel, kwalifikacja, kontakt, powód), `POST /prospects/{id}/archive`, notatki / aktywności / follow-upy; ClickUp: `/clickup/status|oauth/start|test|disconnect|options|destination|settings`, `POST /clickup/exports` (202, kolejka), `/clickup/batches/{uuid}`, `/clickup/exports/{id}/reconcile`, `/companies/{id}/clickup`, `/clickup/rules…` (preview, enable, disable, backfill). Uprawnienia: `pipeline.view/manage/moderate`, `clickup.connect/export/rules` (Role::ABILITIES), sprawdzane w backendzie; ID z URL zawsze filtrowane po organizacji (IDOR → 404).

### UI
Prospects = List View (kolumny status, priorytet, właściciel, następne działanie, ClickUp; filtry; zaznaczanie + eksport zbiorczy; archiwizacja / przywracanie), Pipeline = Board View (Kanban: drag & drop + lista „Przenieś do” z klawiatury, przypisanie właściciela, filtry, lazy load kolumn), karta firmy → zakładka „Sprzedaż” (status, priorytet, właściciel, wybrany kontakt, kwalifikacja z sugestią, follow-upy, aktywności, notatki, ClickUp z historią i rekoncyliacją, historia zmian), Dashboard (kafelki, lejek, follow-upy zaległe / dziś / nadchodzące; cache 60 s, unieważniany przy zmianach), Settings → Integrations → ClickUp. Link `?company=ID` otwiera kartę firmy (używany w zadaniach ClickUp).

### ClickUp — OAuth i bezpieczeństwo
Authorization Code wg dokumentacji ClickUp (`https://app.clickup.com/api?client_id&redirect_uri&state` → `POST /api/v2/oauth/token`; token bez wygasania i bez endpointu revoke). Start tylko przez REST (nonce WP = CSRF); `state` 256 bit → transient (skrót) z user_id + organization_id, 10 min, jednorazowy; callback `/app/clickup-callback/` wymaga tego samego zalogowanego użytkownika i jego członkostwa — `organization_id`/`user_id` z URL są ignorowane. Token szyfrowany `SecretBox` (libsodium secretbox, format `v1:key_id:…`, klucz `UBR_ENCRYPTION_KEY` lub wyprowadzony z `AUTH_KEY`+`SECURE_AUTH_KEY`), odszyfrowywany tylko na czas wywołania API. Dziennik `ubr_api_requests` (provider `clickup`) z identyfikatorami w ścieżce zamienionymi na `{id}` i zredagowanymi błędami. 401 / zmiana klucza → `reconnect_required`.

### ClickUp — eksport
`ClickUpExportService::request` (maks. 20 firm) rezerwuje rekord eksportu (unikalny klucz = idempotencja i ochrona przed równoległymi kliknięciami) i tworzy zadania kolejki; wykonuje `ClickUpRunner` (worker `ubr_job_worker` → 'clickup', WP-Cron `ubr_clickup_tick`, healthcheck). Treść (`ClickUpTaskBuilder`): COMPANY / SCORES / WEBSITE / DECISION MAKERS / CONTACT / LINKS z danych organizacji, w bloku między znacznikami; Custom Fields tylko zmapowane, istniejące, o zgodnym typie (dropdown = opcja o tej samej nazwie). Duplikat → SKIPPED_DUPLICATE + link; „Update existing task” (GET, podmiana bloku tylko przy zgodnym hashu, PUT `markdown_content`, pola); „Export as new task” = kolejny wariant. Błędy: 401 (reconnect), 403/404 (trwałe), 429 (Retry-After / X-RateLimit-Reset), 5xx/timeout przy odczycie (ponowienie, maks. 3), timeout / 5xx po POST tworzącym = UNKNOWN (bez ponowienia; rekoncyliacja: wskazanie zadania z weryfikacją listy albo „nie powstało”). Throttling 650 ms na token (limit 100/min). Dzienny limit nowych zadań na organizację (`UBR_CLICKUP_DAILY_NEW_TASKS`, domyślnie 100).

### Reguły automatyczne
`ClickUpRuleService`: warunki AND (VERIFIED, domena CONFIRMED, Relevance/Opportunity/Contact Priority ≥ X, min. 1/2 decydentów wg widoku organizacji, status pipeline, priorytet). Tworzy i włącza wyłącznie autor (połączenie autora), domyślnie wyłączona; Dry Run; włączenie z potwierdzeniem działa tylko dla nowych zdarzeń: `do_action('ubr/company/changed', $companyId, $reason, $orgId)` po dodaniu do Prospects, zakończeniu weryfikacji, analizy, wyszukiwania kontaktów i zmianie statusu/priorytetu → zadanie `evaluate` w kolejce → ewentualnie `create`. Backfill tylko po osobnym potwierdzeniu, w granicach limitu dziennego reguły. Rozłączenie konta wyłącza jego reguły. Reguły nie uruchamiają analiz.
