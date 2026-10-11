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

## 13. Lead Intelligence (Prompt 5)

Warstwa rekomendacji nad istniejącymi danymi. **Nie zmienia** Relevance / Opportunity / Contact Priority Score, nie uruchamia analiz, crawlera, Decision Maker Findera ani płatnych API, nie zmienia pipeline. Liczy wyłącznie zapisane dane.

### Komponenty
- `Domain/Lead/InsightRuleRegistry` — reguły: grupy findingów (jedna przyczyna = jeden wniosek), 7 kategorii (Performance, Mobile, Accessibility, UX, Content Structure, Technical, Conversion), opisy, rekomendacje, argumenty sprzedażowe, mapowanie tej samej przyczyny z dwóch metod pomiaru (np. `a11y_labels` + `axe_label`).
- `WebsiteInsightGenerator` — wnioski z findingów ostatniej zakończonej analizy (każdy finding ma dowód; finding bez dowodu, `manual_review` i kategorie business/company/design są pomijane). Severity z progów: maks. severity findingów grupy; CRITICAL tylko z krytycznego findingu; +1 poziom (maks. HIGH) dopiero przy ≥ 3 różnych przyczynach ≥ MEDIUM. Evidence: pomiar, wykryty problem, źródło (axe-core / render Chromium / analiza HTML), data analizy.
- `RedesignAssessor` — STRONG / MODERATE / WEAK / INSUFFICIENT_DATA (progi w klasie; sama wydajność/technika nigdy nie daje STRONG; warstwa wizualna nieoceniana — AI Vision wyłączone).
- `LeadPotentialCalculator` — Lead Potential Score + Data Coverage.
- `LeadRecommender` — hard blockers, soft warnings, Data Confidence, Recommended Next Action, Smart Ranking.
- `LeadResearchSummaryBuilder` — podsumowanie (7 sekcji), „Why this company?”, Potential Sales Angles (tylko z wniosków ≥ MEDIUM, z dowodem).
- `LeadIntelligenceService` (snapshot danych → obliczenie → zapis + historia + prezentacja), `LeadIntelligenceRunner` (batch w tle), `LeadIntelligenceRepository`, `LeadFilters` (SQL filtrów/sortowania na listach).

### Semantyka Opportunity Score (audyt)
`OpportunityScoreCalculator`: **wysoki = słaba strona + duży potencjał firmy** (A słabość strony 45, B technika 20, C potencjał biznesowy 25, D wiarygodność 10). Lead Intelligence **nie używa łącznego wyniku** (zawiera C i D, które w Lead Potential są liczone osobno jako Business Fit i Company Trust — groziłoby podwójne liczenie). Używane są tylko kryteria A i B (bez kryterium AI `a_visual_ai`), z ich zachowaną semantyką: więcej punktów = większa słabość = większa szansa na redesign.

### Lead Potential Score (0–100), wersja `lead-2026-10-v2`
| Składowa | Maks. | Podkryteria |
|---|---|---|
| Website Redesign Opportunity | 45 | słabość strony i techniki (22,5: udział zdobytych pkt kryteriów A+B bez AI, pełna pula od 60%) + niezależne kategorie wniosków ≥ MEDIUM (22,5: 0/1/2/3/4+ → 0 / 35 / 60 / 80 / 100%) — dwie miary tej samej warstwy uśredniane, nie sumowane |
| Business Fit | 30 | Relevance organizacji (15, liniowo), segment z PKD (rejestr) albo SIC (9; instytucje/media/katalogi = 0, konsumencki = 5 bo agencja robi też B2C/WooCommerce), wielkość (6; 1–9: 3, 10–49: 5, 50–249: 6, 250–999: 5, 1000+: 4 — skala serwisu, nie budżet) |
| Company Trust | 15 | rejestr (VERIFIED 9, UNCERTAIN 4, INACTIVE/SUSPENDED 0), domena (CONFIRMED 6, UNCERTAIN 3, UNAVAILABLE 1, MISMATCH 0) |
| Contact Readiness | 10 | wiarygodne osoby (≥2: 5, 1: 3), Contact Priority Top 1 (≥70: 3, ≥50: 2, >0: 1), oficjalny kanał (2) — w widoku organizacji (odrzucone/wykluczone nie liczą się) |

**Zmiana wag względem propozycji (40/25/15/10/10):** punkt E „Data Completeness” usunięty z wyniku i raportowany osobno jako Data Coverage + Confidence (prompt §6: nie mieszać wartości biznesowej z kompletnością); jego 10 pkt przesunięte: +5 do strony (główne pytanie biznesowe), +5 do Business Fit.

**Brak danych:** podkryterium bez danych = niezmierzone (nie 0) i wypada z mianownika: wynik = zdobyte / zmierzone × 100, ograniczony limitem `55 + 45 × w` (w = udział zmierzonych wag) — jeden sygnał nie daje 100/100; **bez danych o stronie limit 65** (walidacja biznesowa: firma bez analizy nie może wyprzedzić firmy z potwierdzonymi problemami tylko dzięki normalizacji). `w < 0,35` albo brak zarówno strony jak i Business Fit → `INSUFFICIENT_DATA` (wynik null). `PROVISIONAL`: Data Coverage < 70% albo brak analizy albo `w < 0,85`.

**Data Coverage** (osobno, %): weryfikacja firmy 15, domeny 15, analiza strony 25, aktualna analiza ≤ 180 dni 10, render 5, Relevance 10, branża 7, wielkość 5, research decydentów 8.
**Data Confidence:** HIGH = coverage ≥ 80 + VERIFIED + domena CONFIRMED + aktualna analiza; MEDIUM = coverage ≥ 55 i firma weryfikowana; inaczej LOW.

### Hard blockers / soft warnings
- Hard: firma INACTIVE / SUSPENDED, domena MISMATCH (domena innego podmiotu = sprzeczność identyfikacji), wykluczenie przez organizację (prospekt `not_a_fit` — status lub kwalifikacja; prywatne). Skutek: nigdy CONTACT_NOW, ranking 0 (koniec listy), potencjał nadal widoczny.
- Soft: UNCERTAIN / niezweryfikowana, domena niepotwierdzona / brak domeny, brak / nieudana / stara (>180 dni) / bez renderu analiza, coverage < 60%, brak researchu / brak osób, rekord niekomercyjny, rozbieżności danych. Wpływają na confidence, ranking i rekomendację — nie na potencjał.

### Recommended Next Action (pierwsza pasująca reguła; pozostałe trafione = „również do rozważenia”)
1. DO_NOT_CONTACT — nieaktywna / zawieszona / wykluczona; 2. VERIFY_COMPANY — MISMATCH; 3. LOW_PRIORITY — rekord niekomercyjny; 4. RESEARCH_FURTHER — INSUFFICIENT_DATA; 5. VERIFY_COMPANY — firma lub domena niepotwierdzona; 6–7. RESEARCH_FURTHER — brak analizy / za mało pomiarów; 8. LOW_PRIORITY — wynik < 40; 9. REVIEW_WEBSITE — WEAK przy dobrym dopasowaniu (≥ 60% Business Fit; wygląd nieoceniony); 10. LOW_PRIORITY — WEAK; 11. REVIEW_WEBSITE — MODERATE; 12. RESEARCH_FURTHER — STRONG przy coverage < 60% / LOW; 13. FIND_DECISION_MAKER — STRONG bez researchu albo bez osoby i kanału; 14. CONTACT_NOW — STRONG, wynik ≥ 55; domyślnie REVIEW_WEBSITE. Brak osobistego e-maila nie blokuje CONTACT_NOW.

### Smart Lead Ranking
`rank = Lead Potential × (0,6 + 0,4 × coverage) × (0,9 gdy analiza nieaktualna)`; blokery = 0 (koniec), brak wyniku = poza rankingiem. Sortowanie w SQL: `has_blocker ASC, rank IS NULL, rank DESC, lead_score DESC, coverage DESC, company_id ASC` — deterministyczne, bez losowości. Wyjaśnienie rankingu zapisywane przy ocenie.

### Model danych (M007), cache, przeliczanie
- `ubr_lead_intelligence` — bieżąca ocena **per organizacja** (UNIQUE org+company; Relevance, wykluczenia i decyzje o kontaktach są prywatne): kolumny do filtrów/sortowania (lead_score, confidence, coverage, redesign, recommendation, rank_score, has_blocker), `input_snapshot`, `input_hash`, `result`, `algorithm_version`, `stale`.
- `ubr_lead_insights` — wnioski per firma (dane publiczne z analizy), UNIQUE company+insight_key; przepisywane tylko przy nowej analizie lub wersji reguł.
- `ubr_lead_intelligence_history` — wpis tylko przy zmianie `input_hash` (dane wejściowe + wersja) lub wyniku/rekomendacji; zmiana wersji algorytmu = nowy wpis z przyczyną „nowa wersja algorytmu”.
- Przeliczanie: `ubr/company/changed` (verification_done, analysis_done, contacts_done, contacts_reviewed, relevance_changed, prospect_added, pipeline_changed) → `stale = 1` → `LeadIntelligenceRunner` (worker `ubr_job_worker` → 'lead', WP-Cron `ubr_lead_tick`, healthcheck; batch 50, łańcuchowo). Firmy widoczne bez oceny oraz starsza wersja algorytmu są dobierane automatycznie. Listy (Discover / Prospects) tylko czytają kolumny (LEFT JOIN, bez N+1); profil firmy liczy ocenę od razu, jeśli jest nieaktualna.

### REST i UI
`GET /companies/{id}/lead-intelligence`, `POST …/recalculate`, `GET /lead-intelligence/top` (uprawnienie `companies.view`, widoczność firmy → IDOR 404). Discover/Prospects: kolumna „Lead”, filtry `min_lead`, `lead_confidence`, `redesign`, `recommendation`, `no_blockers`, sortowanie `rank` / `lead` / `redesign`. Karta firmy: zakładka „Lead Intelligence” (wynik, confidence, coverage, redesign, rekomendacja; Why this company?, insights z dowodami, sales angles, decydenci, ryzyka i braki, summary, score breakdown zgodny z obliczeniem, historia). Dashboard: Smart Lead Ranking (top 5).

### Ograniczenia
Brak oceny wizualnej (AI Vision wyłączone) — WEAK nie znaczy „dobra strona”; brak Lighthouse/CrUX — wydajność to jeden pomiar laboratoryjny; wielkość firmy często nieznana; Relevance zależy od kryteriów wyszukiwań organizacji; skuteczność sprzedażowa niezweryfikowana (brak wyników prospectingu).

## 14. Discover UI + wspólny podgląd strony (Prompt 5.1)

Zmiana wyłącznie warstwy UI — bez migracji, bez zmian w scoringu, wyszukiwaniu, ClickUp i endpointach.

### Układ Discover (`resources/views/ubr/pages/discover.blade.php`)
Pionowo, na pełną szerokość obszaru treści (sidebar bez zmian): **Formularz → Status → Filtry → Wyniki**.
- Formularz „Czego szukamy?”: CSS Grid (`xl:grid-cols-12`, dostawcy `md:2 / xl:3` kolumny), sekcja zwijana („Zwiń” / „Rozwiń”, `aria-expanded`, `aria-controls`), po udanym starcie wyszukiwania zwija się automatycznie; wartości pól zostają w stanie Alpine (x-show, nie x-if), stan zwinięcia w `localStorage` (`ubr_discover_form`, try/catch). Zwinięty nagłówek pokazuje podsumowanie kryteriów.
- Filtry i sortowanie: `flex-wrap` — zawijają się do kolejnych wierszy, bez przewijania w poziomie.
- Tabela (≥ 1024 px): `table-fixed` + `colgroup` z udziałami procentowymi, bez `min-width` i bez `overflow-x-hidden`. Kolumny: wybór · Firma (2 linie, pełna nazwa w `title`) · Weryfikacja · Domena (skracana, pełna w `title` / linku) · Lokalizacja · Oceny (Lead Potential + Confidence; przycisk „Szczegóły” rozwija wiersz z Relevance, Opportunity, Contact Priority, Redesign, rekomendacją i szczegółami dopasowania) · ⋯.
- < 1024 px: karty (`ul` z tymi samymi danymi i tym samym menu ⋯).
- Partiale: `discover-company-cell`, `discover-domain-cell`, `discover-row-details`.

### Menu akcji wiersza (`partials/row-actions.blade.php`, `ubrRowMenu` w `resources/js/ubr/ui.js`)
Wspólne dla Discover i Prospects (zastąpiło `analysis-actions` / `contacts-actions`). Lista renderowana w `<body>` (`x-teleport`) z `position: fixed` liczonym od przycisku — nie jest obcinana przez kontener tabeli; przycisk poza ekranem jest najpierw przewijany do widoku, pozycja ograniczona do okna, otwarcie w górę przy braku miejsca. Akcje (tylko mające zastosowanie): Podgląd strony (gdy jest domena), Lead Intelligence, Karta firmy, Analizuj stronę / Analizuj ponownie / „Analiza w toku…” (`analysis.run`), Znajdź decydentów / ponownie / „w toku…” (`contacts.run`), Zapisz do Prospects / „Zapisano” (`prospects.manage`, tylko Discover). Akcje wołają istniejące metody `$store.ubr` (te same endpointy, walidacja i komunikaty błędów). Dostępność: `aria-haspopup`, `aria-expanded`, `role=menu/menuitem`, Enter/Spacja/↓ otwiera, ↑/↓/Home/End, Escape i klik poza zamykają, Tab zamyka, fokus wraca na ⋯; przy przewinięciu i zmianie rozmiaru menu podąża za przyciskiem, a zamyka się, gdy przycisk zniknie z ekranu.

### Wspólny podgląd strony (`partials/preview-modal.blade.php`, `openPreview()` w `$store.ubr`)
Jeden komponent dla Discover, Prospects i karty firmy (miniatury w zakładce Website Analysis otwierają tryb SCREENSHOT).
- **LIVE (domyślny):** `iframe` z zapisanym `website_url` firmy, po walidacji `safeWebsiteUrl()` (tylko `http:`/`https:`, bez danych logowania w URL, poprawny host). Niepoprawny adres → brak iframe, LIVE wyłączony, komunikat. Gdy aplikacja działa na https (produkcja), a zapisany adres to http://, iframe ładuje wersję https:// tej samej strony (`livePreviewUrl()`; iframe http byłby zablokowany przez przeglądarkę jako mixed content) — z widoczną informacją; „Otwórz stronę” zawsze używa zapisanego adresu. `sandbox="allow-scripts allow-same-origin allow-forms allow-popups"` (bez `allow-top-navigation`, `allow-modals`, `allow-downloads`), `allow` blokuje kamerę, mikrofon, lokalizację, płatności, USB, schowek i przechwytywanie ekranu, `referrerpolicy="no-referrer"`. Bez proxy i bez obchodzenia `X-Frame-Options` / CSP `frame-ancestors` — strony blokujące osadzanie wyświetlą pusty obszar / błąd przeglądarki; stały komunikat wskazuje Screenshot lub „Otwórz stronę”. Uwaga: `sandbox` może ograniczyć część stron (np. wymagających okien modalnych, pobierania plików lub nawigacji całej karty) — wtedy „Otwórz stronę”. Nie ufamy `iframe.onload` (nie odróżnia blokady od sukcesu) — nie pokazujemy „załadowano”.
- **SCREENSHOT:** ostatnie zrzuty Website Analyzera z `GET /companies/{id}/analysis` (pobierane dopiero przy otwarciu modalu); brak zrzutów → empty state. Podgląd nigdy nie generuje zrzutów ani nie uruchamia analizy / Decision Maker Findera / Apollo.
- **DESKTOP | MOBILE:** mobile = obszar 390 px wyśrodkowany.
- Nagłówek: nazwa, adres, przełączniki, „Otwórz stronę” (`target=_blank`, `rel="noopener noreferrer"`), „Zamknij”. Dostępność: `role=dialog`, `aria-modal`, etykieta, pułapka fokusu, fokus na „Zamknij”, Escape, przywrócenie fokusu na element wywołujący, blokada przewijania tła (`overflow-hidden` na `<html>`).

### Wydajność
Brak zapytań per wiersz: tabela korzysta z pól zwracanych już przez `/discovery/searches/{id}/results` i `/prospects`; dane podglądu ładowane tylko po otwarciu modalu.

## 15. Prospecting Dashboard & Action Center (Prompt 6)

Dashboard `/app/` = centrum codziennej pracy. Tylko odczyt zapisanych danych: Lead Intelligence, firmy, Pipeline. Nie uruchamia analiz, crawlera, Apollo, Decision Maker Findera ani ClickUp. Jedyne zapisy to ręczne odłożenie działania (M008) i ręczna ocena strony (M009).

Pliki:
- Domena: `app/Ubr/Domain/ActionCenter/ActionPriorityEngine.php`, `QueueCatalog.php`.
- Read model: `ActionCenterRepository`.
- Serwisy: `ActionCenterService`, `ManualReviewService` + `ManualReviewRepository`.
- REST: `ActionCenterController`.
- UI: `pages/dashboard.blade.php`, `partials/ac-action-menu.blade.php`, `partials/manual-review.blade.php`, `resources/js/ubr/dashboard.js`.
- Testy: `tests/action-center.php`.

### Zakres danych (tenant-private)
- „Pula organizacji” = firmy z wyników wyszukiwań organizacji + jej aktywni prospekci. To ten sam zbiór, który ocenia Lead Intelligence (wiersze `ubr_lead_intelligence` organizacji), nigdy cała globalna baza firm.
- Dane firm (weryfikacja, analiza, decydenci) są wspólne. Prywatne dla organizacji są: zapis do Prospects, pipeline, notatki, follow-upy, przypisania, wykluczenia, odłożenia i oceny ręczne.
- Zarchiwizowani prospekci są pomijani (decyzja operatora). Firmy jeszcze nieocenione przez Lead Intelligence są liczone jako „czeka na ocenę” i nie trafiają do list (bez zgadywania).
- Filtr zakresu: `all` (odkryte), `prospects` (zapisane), `not_prospects` (niezapisane). Lista Follow-ups obejmuje wyłącznie prospektów organizacji.

### Action Priority Engine (odrębny od Lead Potential)
Lead Potential mówi, JAK atrakcyjna jest firma. Action Priority mówi, CO zrobić teraz. Silnik nie liczy żadnego wyniku: stosuje kategorie w stałej kolejności i stabilne remisy.

Kategorie:

| Tier | Typ | Priorytet | Skąd |
|---|---|---|---|
| 1 | FOLLOW_UP_OVERDUE | critical | otwarty follow-up z terminem < teraz |
| 2 | FOLLOW_UP_DUE | high | termin od teraz do końca dnia (strefa WP) |
| 3 | CONTACT_READY | high | rekomendacja Contact now **i** zakończona analiza (COMPLETED/PARTIAL) |
| 4 | FIND_DECISION_MAKER | medium | rekomendacja Find decision maker |
| 5 | REVIEW_WEBSITE | medium | rekomendacja Review website |
| 6 | VERIFY_COMPANY | medium | rekomendacja Verify company |
| 7 | ANALYSIS_MISSING | low | Contact now bez analizy albo Research further + domena bez analizy |
| 8 | RESEARCH_FURTHER | low | pozostałe Research further |

Brak działania z rekomendacji, gdy firma:
- nie ma oceny Lead Intelligence,
- jest zarchiwizowanym prospektem,
- ma hard blocker (lista Blocked),
- ma status zamknięty (Won / Lost / Not a fit) albo trwa rozmowa (Contacted, Follow up, In discussion, Proposal sent) — wtedy pracą sterują follow-upy,
- ma otwarty follow-up (dedup: działanie już zaplanowane),
- ma rekomendację Low priority / Do not contact,
- ma działanie odłożone ręcznie.

Ta sama reguła istnieje w PHP (`classify()`) i w SQL (`sqlType()`); test porównuje obie na danych testowych.

Remisy w kategorii, w tej kolejności: priorytet prospekta (urgent → high → pozostałe), Smart Ranking (`rank_score`), Lead Potential, Data Coverage, prospekt przed nie-prospektem, ID firmy. Follow-upy porządkuje termin rosnąco, potem ID.

Zasady merytoryczne:
- Wysoki Lead Potential nigdy nie wypiera zaległego follow-upu.
- Bez analizy firma nigdy nie jest „gotowa do kontaktu”.
- Brak decydenta nie obniża potencjału. Prospekci przed kontaktem bez wyszukanych decydentów trafiają do listy Find Decision Maker.

### Today's Priorities i deduplikacja
- Maksymalnie 5 działań naraz (kolejne strony na żądanie). Najpierw follow-upy zaległe i dzisiejsze, potem działania z rekomendacji w kolejności silnika.
- Firma występuje najwyżej raz. Follow-upy są grupowane per firma (najwcześniejszy termin + „i jeszcze N”). Firma z otwartym follow-upem nie dostaje działania z rekomendacji.
- Każda pozycja ma: firmę i domenę, typ, przyczynę (uzasadnienie z Lead Intelligence), priorytet, Lead Potential / Confidence, termin tylko dla follow-upów (bez wymyślonych terminów), jedno CTA do istniejącego workflow i link do karty firmy.

### Listy robocze (Smart Queues)
Wszystkie są przefiltrowanymi widokami tej samej puli, bez kopii rekordów. Definicje znajdują się w `QueueCatalog::QUEUES` (wyświetlane w UI i API):

| Lista | Definicja |
|---|---|
| Top Opportunities | Smart Lead Ranking (kolejność `LeadFilters::RANK_ORDER`, bez nowego scoringu), bez blokerów i zamkniętych. Domyślnie 10 na stronę. |
| Needs Attention | Działania typu Find DM / Review / Verify / Missing analysis / Research — jedna główna kategoria na firmę. Pozostałe braki (soft warnings Lead Intelligence) są informacją pomocniczą. |
| Ready to Contact | Kategoria CONTACT_READY. Pokazuje najlepszego decydenta, stanowisko i znalezione kanały firmowe wyłącznie z zapisanych danych (bez generowania e-maili i telefonów). Ma komunikat pustego stanu. |
| Review Website / Needs Research / Verify Company | Odpowiednie kategorie silnika. |
| Find Decision Maker | Rekomendacja Find decision maker lub prospekt przed kontaktem bez wyszukanych decydentów. |
| Missing Website Analysis | Domena bez zakończonej analizy, bez blokera, poza statusami zamkniętymi. Analizę uruchamia operator przyciskiem (istniejące limity). |
| High Potential | Lead Potential ≥ 70 (powyżej limitu 65 dla firm bez danych o stronie), bez blokera, poza statusami zamkniętymi. |
| Blocked | Firmy z hard blocker. |
| Follow-ups | Otwarte follow-upy. Przedziały: zaległe, dziś, zaległe + dziś, najbliższe 7 dni, wszystkie. |

Filtry: zakres (zapisane / niezapisane), minimalny Lead Potential, Confidence, województwo, branża, Redesign, rekomendacja, ocena ręczna, wyszukiwanie. Sortowanie: Smart Ranking, priorytet działania, Lead Potential, kompletność, nazwa. Filtry działają w SQL, są paginowane i dotyczą tylko bieżącej listy (nie ukrywają follow-upów ani aktywności).

### KPI (Overview)
| KPI | Definicja | Drill-down |
|---|---|---|
| Total Prospects | aktywni prospekci organizacji (odkryte firmy pokazane osobno w opisie) | Prospects |
| Follow-ups Due | zaległe + na dziś (rozbicie w opisie) | Follow-ups (zaległe + dziś) |
| Ready to Contact | jak lista | lista |
| High Potential | jak lista | lista |
| Needs Review | rekomendacja Review website | lista |
| Missing Analysis | jak lista | lista |

Bez trendów i procentów: brak danych historycznych do ich rzetelnego liczenia.

### Prospecting Coverage zamiast lejka
- Historia przejść statusów istnieje dopiero od Prompt 4, a etapy nie są liniowe (np. analiza bywa przed zapisem do Prospects). Dlatego pokazujemy niezależne liczniki aktualnego stanu z definicjami, bez współczynników konwersji.
- Liczniki: pula (odkryte), zweryfikowane, domena potwierdzona, strona przeanalizowana, decydenci wyszukani, decydent znaleziony, ocenione, zakwalifikowane, zapisane, w kontakcie, wygrane, utracone. Do tego statusy pipeline i liczba eksportów do ClickUp.

### Follow-ups i Recent Activity
- Istniejące follow-upy i zdarzenia Pipeline (bez drugiego systemu).
- Granice dnia liczone w strefie czasowej WordPressa, zapisane w UTC (`PipelineRepository::todayBounds()`, `ActionCenterRepository::bounds()`). Upcoming = 7 pełnych dni lokalnych po dzisiejszym.
- „Wykonane” wywołuje istniejący `POST /followups/{id}`, po czym widok odświeża się natychmiast (wersjonowany cache).
- Recent Activity: 10 ostatnich zdarzeń prospektów i zarejestrowanych aktywności organizacji (bez logów technicznych).

### My Work / Team Work / Unassigned
- Model przypisań już istnieje (`owner_user_id` prospekta, `assigned_user_id` follow-upu).
- My Work = follow-upy przypisane mi + prospekci, których jestem właścicielem.
- Unassigned = firmy spoza Prospects + prospekci bez właściciela (follow-upy bez przypisania).
- Team = cała organizacja. Wszystkie widoki w obrębie organizacji z kontekstu (nigdy z parametru).

### Quick Actions
Menu ⋯ (partial `ac-action-menu`) pokazuje tylko akcje dostępne w danym stanie i roli. Wywołuje istniejące endpointy i metody `$store.ubr`:
- otwórz kartę / Lead Intelligence,
- oceń stronę (podgląd),
- znajdź decydentów,
- zweryfikuj,
- analizuj,
- dodaj do Prospects,
- oznacz follow-up jako wykonany,
- notatka / follow-up / zmiana statusu (istniejące formularze w karcie firmy → Sprzedaż),
- odłóż (1 / 3 / 7 / 14 dni) / przywróć.

Bez wysyłania wiadomości i bez eksportu do ClickUp.

### Odłożenie działania (M008, `ubr_action_snoozes`)
- `POST` / `DELETE /action-center/snoozes`, uprawnienie `pipeline.manage`, nonce REST.
- Firma musi należeć do puli organizacji (IDOR → 404).
- Termin to koniec dnia za N dni (strefa WP). UNIQUE (organizacja, firma, typ), więc ponowne odłożenie aktualizuje termin.
- Follow-upów nie odkłada się — przekłada się ich termin.

### Manual Website Review (M009)
- `GET` / `POST /companies/{id}/manual-review`. Odczyt wymaga `companies.view`, zapis `pipeline.manage`. Firma musi być w puli (IDOR → 404).
- Statusy: NOT_REVIEWED / GOOD / AVERAGE / POOR / VERY_POOR. Notatka bez HTML, do 1000 znaków.
- Bieżąca ocena w `ubr_manual_reviews` (UNIQUE organizacja + firma) i pełna historia w `ubr_manual_review_history` (kto, kiedy, poprzednia → nowa, notatka).
- Prywatna dla organizacji.
- Subiektywna: nie wywołuje `ubr/company/changed`, nie zmienia Opportunity Score, Lead Potential, Redesign ani rekomendacji. Listy pokazują ją zdaniem („Strona została oceniona ręcznie jako bardzo słaba.”) i pozwalają filtrować.
- UI: wspólny partial `manual-review` w karcie firmy (Website Analysis) i w podglądzie strony (oba tryby).

### REST
- `GET /action-center?owner=` — overview: KPI, listy z licznikami, 5 priorytetów, follow-upy, aktywność, coverage, opcje filtrów.
- `GET /action-center/priorities?page=&per_page=`
- `GET /action-center/queues/{key}?…`
- Odczyt: `companies.view`. Follow-upy dodatkowo wymagają `pipeline.view`.

### Wydajność i cache
- Jedno zapytanie agregujące (SUM po warunkach) dla wszystkich liczników.
- Listy z LIMIT/OFFSET i JOIN-ami po kluczach unikalnych. Uzasadnienia (`JSON_EXTRACT` z oceny) tylko dla wierszy strony.
- Bez N+1: użytkownicy przez cache `get_userdata`.
- Nowe indeksy tylko w nowych tabelach.
- Liczniki w transiencie (60 s) z kluczem wersjonowanym:
  - wersja globalna rośnie przy `ubr/company/changed` dla danych wspólnych,
  - wersja organizacji rośnie przy zmianach pipeline i follow-upów (`PipelineService::forgetStats`), zapisie oceny Lead Intelligence, odłożeniu i ocenie ręcznej.
- Dashboard nie czeka na Apollo, crawler, screenshoty ani ClickUp.
- Lokalnie (LocalWP, około 65 ocenionych firm): `GET /action-center` około 110 ms.
- Test syntetyczny na 10 000 ocenionych firm (kontener MariaDB, dane tymczasowe usunięte po teście): wszystkie liczniki 102 ms, priorytety 5 (strona 1 i 200) około 120 ms, Top Opportunities 10 — 73 ms, Needs Attention z filtrem regionu 96 ms, wyszukiwanie 28 ms, coverage 39 ms.

### Ograniczenia
- Rekomendacje są tak dobre jak Lead Intelligence (brak AI Vision → dużo Review website i Missing analysis).
- Firmy nieocenione nie trafiają do list do czasu przeliczenia w tle.
- Brak historycznych trendów KPI.
- Odłożenie działa per typ działania. Gdy rekomendacja firmy się zmieni, nowe działanie nie jest odłożone.

## 16. Audyt QA i stabilizacja (Prompt 7)

Bez nowych modułów i bez migracji (schemat v9). Pełna lista błędów i wyników: `docs/QA-REPORT.md`.

### Ochrona SSRF (PHP i przeglądarka)
- `UrlGuard::isPublicIp()` i `capture.mjs isPublicIp()` oceniają adresy IPv6 na bajtach: IPv4-mapped (`::ffff:0:0/96` w każdym zapisie), IPv4-compatible (`::/96`), NAT64 (`64:ff9b::/96`), 6to4 (`2002::/16`) → decyduje osadzony IPv4; blokowane ULA, link-local, site-local, multicast, Teredo, dokumentacja.
- `UrlGuard::check()` zwraca sprawdzone adresy (`ips`); `PageFetcher::pin()` przypina połączenie cURL (`CURLOPT_RESOLVE`) na czas jednego żądania — ochrona przed DNS rebinding.
- `PageFetcher`, `WebsiteInspector` (weryfikacja firmy) i `WebsiteChecker` obsługują przekierowania ręcznie, każdy krok przez `UrlGuard` + przypięcie IP.
- Renderer (`tools/site-capture/capture.mjs`): `page.route()` dla HTTP(S), `context.routeWebSocket()` zamyka wszystkie WebSockety, WebRTC bez UDP poza proxy. Import modułu (testy) nie uruchamia przechwytywania. Ryzyko szczątkowe: Chromium sam rozwiązuje DNS (rebinding w przeglądarce) — sprawdzenie każdego żądania ogranicza je, ale nie eliminuje.
- Timeout analizy: `SIGTERM` (Playwright zamyka Chromium), po 3 s `SIGKILL`.

### Linki zewnętrzne (XSS)
`UrlGuard::httpUrl()` (serwer: profile osób, źródła kontaktów, linki ClickUp) i `$store.ubr.safeHref()` (front, wszystkie zewnętrzne `:href`) — tylko http/https z hostem; inaczej brak atrybutu `href`. Profil osoby z JSON-LD tylko z pełnego wzorca adresu profilu.

### Kolejki i blokady
- `App\Ubr\Support\Lock` — `GET_LOCK` / `RELEASE_LOCK` z nazwą prefiksowaną bazą i prefiksem tabel; atomowe i zwalniane automatycznie po zakończeniu połączenia (śmierć procesu). Używane przez sloty analizy i decydentów, ClickUp, Lead Intelligence i migrator. Weryfikacja zachowuje własną blokadę CAS.
- `failExhausted(max)` w repozytoriach analiz, researchu, weryfikacji i ClickUp: pozycje `running` z wygasłą blokadą i wyczerpanymi próbami → `failed` (wywoływane przez runner przed `claim()`); analiza/research synchronizują stan firmy.
- ClickUp `create`: eksport w stanie `running` na początku próby = poprzedni worker przerwany po/w trakcie POST → UNKNOWN (rekoncyliacja w karcie firmy), bez ponownego POST.
- Lead Intelligence: nieudane przeliczenie przesuwa ocenę na koniec kolejki (`deferFailed`); raz na dobę oceny starsze niż 7 dni oznaczane do przeliczenia (`markAged`, przyczyna `aging`); szybki licznik kolejki (`countPending`, LIMIT + NOT EXISTS).
- `AsyncWorker::dispatchJob()` przy żądaniach WWW deduplikuje zlecenia (typ + ID) i wysyła je po wysłaniu odpowiedzi (`fastcgi_finish_request` / `litespeed_finish_request`; filtr `ubr/queue/defer_dispatch`). W WP-CLI i bez tych funkcji — wysyłka od razu.
- `VerificationRunner::kick()` planuje zapasowe zdarzenie WP-Cron. `wp ubr queue-work --all` wykonuje krok każdej kolejki bez loopbacku.
- Publiczny ping workera (`wp_ajax_nopriv_ubr_worker_ping`) tylko odpowiada — bez zapisów.

### Migracje
`Migrator::migrate()`: blokada `GET_LOCK`, wersja czytana z bazy po uzyskaniu blokady, błąd zapisywany w `ubr_migration_error` (komunikat w panelu WP, `wp ubr migrate --status`). `Bootstrapper::maybeRun()` na `init`: pomija żądania, gdy `UBR_AUTO_MIGRATE=false`, w WP-CLI (migracje tylko przez `wp ubr migrate` / `install`) i przez 10 min po błędzie. `RestRegistrar::authorize()` zamienia każdy wyjątek na kontrolowany 500.

### Spójność danych
- Action Center: „przeanalizowana” = `companies.analyzed_at IS NOT NULL` (zakończona analiza) lub status `completed/partial` — ponowna/nieudana analiza nie cofa firmy (PHP `classify()` i SQL `sqlType()` / `QueueCatalog::where()` / coverage zgodne).
- Archiwizacja prospektu jedną ścieżką (`PipelineService::archive`, także `DELETE /prospects/{id}`): zdarzenie, liczniki, Lead Intelligence. Zmiana kwalifikacji przelicza Lead Intelligence bez wyzwalania reguł ClickUp.
- Usunięcie członka organizacji: jego prospekty i follow-upy → „Unassigned”, jego reguły ClickUp wyłączone.
- KPI Total Prospects respektuje My Work / Unassigned; drill-down z kafelka KPI bez filtrów listy.
- Formularze: `isoFromLocalInput()` wysyła `datetime-local` jako ISO z offsetem przeglądarki (serwer: `PipelineService::parseDate`).
- Adresy ogólne/działów (`PeopleExtractor::GENERIC_LOCALS`, `isGenericEmail`) nie są e-mailami osób; dopasowanie nazwiska jako podciągu od 3 liter.
- NIP/REGON z powtórzeń jednej cyfry / samych zer odrzucane przez `CompanyNormalizer`.

## 17. Decision Maker Finder 2.0 + Phone Intelligence + Cold Calling Workspace (Prompt 10)

Migracja **M010** (schemat v10, tylko nowe tabele / kolumny / indeksy, bez usuwania i nadpisywania danych). Scoringi (Lead Potential, Opportunity, Contact Priority) bez zmian — telefony nie są danymi wejściowymi Lead Intelligence (test: hash `snapshot()` przed i po dodaniu numerów).

### Dane
| Tabela / kolumna | Zakres | Uwagi |
|---|---|---|
| `ubr_company_phones` | `org_key = 0` — numery z oficjalnej strony (wspólne); `org_key = <org>` — numery ręczne i centrala z Apollo (prywatne organizacji) | UNIQUE (firma, org_key, e164); źródło (TEL_LINK / SCHEMA / TEXT / MANUAL), URL, strona (home / contact / footer), kontekst, oddział, pewność, daty `found_at` / `last_seen_at`, `is_primary` |
| `ubr_companies.main_phone_*`, `phones_count`, `phones_checked_at` | denormalizacja numeru głównego ze strony | listy i filtry bez N+1 |
| `ubr_contacts.phone_e164` | znormalizowany `public_business_phone` | odróżnienie numeru osoby od numeru firmy |
| `ubr_contacts.external_provider` / `external_id` | np. identyfikator osoby w Apollo | dowód powiązania, podstawa odblokowania telefonu |
| `ubr_phone_reveals` | zlecenia „Odblokuj telefon” per organizacja | UNIQUE `idempotency_key` = `org:osoba:N`, stan (reserved / pending / completed / unavailable / error), `request_id`, próby, `credits_consumed` (z odpowiedzi Apollo, gdy podany) |
| `ubr_contact_phones` | numery osób pobrane przez organizację | prywatne dla organizacji, typ MOBILE / DIRECT / OTHER |
| `ubr_organization_prospects.do_not_contact*` | zakaz kontaktu (powód, kto, kiedy) | niezależny od etapu pipeline |
| `ubr_organization_prospects.last_contact_at` / `last_contact_outcome` | ostatni ŚWIADOMIE zapisany kontakt | z istniejących aktywności (backfill w M010), bez nowej historii |
| `ubr_prospect_activities.outcome` | wynik rozmowy dla aktywności „Telefon” | Nie odebrał / Oddzwonić później / Nie zainteresowany / Zainteresowany / Umówiono spotkanie / Błędny numer |

### Telefony firmy
- `PhoneNumber::parse()` — E.164 (domyślnie PL): `+48`, `0048`, stary zapis z zerem, `tel:`, numery wewnętrzne (`wew.`, `ext.`, `;ext=`), numery zagraniczne z kodem kraju. Odrzuca NIP, REGON, KRS, kody pocztowe, rachunki, kody produktów i nieistniejące prefiksy PL (whitelista stref, infolinie tylko 800/801/804).
- `CompanyPhoneExtractor::extract(html, url, pageKind)` — linki `tel:` / `callto:` (wysoka pewność), Schema.org (`telephone`, `ContactPoint.contactType`, oddziały z `department` / `subOrganization` / `location`), `itemprop=telephone`, tekst tylko po słowie „tel./telefon/phone…” (bez kontekstu fax / NIP / REGON / KRS / konto). Deduplikacja po E.164 + numer wewnętrzny, typ MAIN / BRANCH / SALES / SUPPORT / OTHER / UNKNOWN, znacznik stopki.
- `PhonePicker::primary()` — numer główny: centrala > strona Kontakt > stopka > pozostałe; oddział i numer zagraniczny nigdy jako główny polskiej firmy; numer niewidziany 180 dni na końcu.
- Źródła: dokumenty pobrane przez Website Analyzer (strona główna + Kontakt) i Decision Maker Findera — **bez ponownej analizy i bez dodatkowego crawla**. Ręczne odświeżenie: `POST /companies/{id}/phones/refresh` (strona główna + Kontakt przez `PageFetcher` z ochroną SSRF i przypięciem IP, timeout 10 s, blokada 10 min na firmę, pominięcie domeny innego podmiotu).
- Uzupełnienie dla firm sprzed etapu 10 (analizy nie przechowują HTML): `wp ubr phones-backfill --org=<slug> [--limit=n] [--execute]` — kandydaci z domeną i `phones_checked_at IS NULL`, `CompanyPhoneService::refresh` z przerwą 1 s; domyślnie bez pobierania.
- Klasyfikacja z etykiety (backfill LocalWP, 11.10.2026): typ z tekstu tuż przed numerem — treść linku `tel:` → etykieta z tego samego akapitu → tekst poprzednich węzłów (bez `script` / `style`, ucięty na poprzednim pełnym numerze ≥ 9 cyfr). Kolejność: oddział (etykieta / blok oddziału / węzeł JSON-LD) → etykieta strony → podpowiedź JSON-LD (`contactType`) → kontekst (bez wnioskowania „oddziału” z sąsiedniego bloku). Słowa: `MAIN_WORD` (centrala, recepcja, sekretariat, siedziba, headquarter…), `PERSON_ROLE` / imię i nazwisko i `DEPARTMENT` (kadry, magazyn, zakupy…) → OTHER („Dział / osoba”).
- Spółki: nazwa z formą prawną w etykiecie (`entityIn`) różna od firmy → BRANCH z `branch_label = "inna spółka: …"`; ta sama spółka → MAIN. Tekst linku ≠ numer w `tel:` → pewność `low` + „[tekst linku: …]”.
- `PhonePicker`: MAIN 100 · UNKNOWN 40 (komórka −15) · SALES 35 · SUPPORT 20 · OTHER 10 · BRANCH 5, `low` −20; wyklucza „inna spółka” i numery zagraniczne (kraj z numeru albo — gdy nieznany — prefiks inny niż +48); remis → kolejność na stronie (id).
- `CompanyPhoneService::refresh`: robots.txt (`RobotsPolicy`), `<base href>` (`baseHref`), nazwa firmy do ekstraktora, maks. 1 dodatkowa podstrona, gdy Kontakt nie ma numerów (najpierw „Polska / Poland / centrala / siedziba”, potem „biuro / office / contact-…”), `CompanyPhoneRepository::replaceExtracted` (numery ze stron pobranych teraz zastępują wcześniejsze z tych samych adresów; ręczne bez zmian; upsert nie obniża typu poza UNKNOWN → konkretny).
- Action Center: `call.main_phone` = `{number, e164, tel}`; wiersz kolejki pokazuje numer z `tel:` i kopiowaniem (`partials/ac-call-phone`), bez numeru przy zakazie kontaktu.
- Kolumna `manual` jest słowem zastrzeżonym w MySQL 8 — w SQL zawsze `` `manual` ``.
- Numer ręczny organizacji: prywatny, nigdy nie nadpisywany ekstrakcją; ręczny numer MAIN ma pierwszeństwo dla tej organizacji (`CompanyPhoneService::mainFor`).

### Apollo
- **People API Search** (`ApolloPeopleSource`, 0 kredytów wg dokumentacji, może wymagać klucza master; nie zwraca e-maili ani telefonów): stanowiska EN + PL (`TITLES`) z `include_similar_titles`, filtr domeny; osoba przyjmowana tylko przy zgodnej domenie lub identyfikatorze organizacji Apollo — podobna nazwa firmy nie wystarcza; zamaskowane nazwisko = brak kontaktu; rola przez `RoleCatalog` (produkcja / serwis / IT / magazyn nie są decydentami). Zapisywany `external_id`. Włączenie: `UBR_APOLLO_PEOPLE_ENABLED` (bez zmian). Działanie z prawdziwym kluczem: **NOT VERIFIED**.
- **Phone Enrichment** (`ApolloPhoneService`, płatne, tylko na żądanie): `POST /people/match?id=…&reveal_phone_number=true&poll_only=true&reveal_personal_emails=false` → `request_id`; wynik odbiera `ApolloPhoneRunner` (`GET /webhook_result/{request_id}`, 0 kredytów; `result_pending` + `retry_after_seconds`; maks. 40 prób; worker `phones`, cron `ubr_phones_tick`, healthcheck, `wp ubr queue-work --all`). Webhook nie jest używany (LocalWP / hosting bez publicznego HTTPS dla Apollo).
- Kontrola kosztów: flaga `UBR_APOLLO_PHONE_ENABLED` (domyślnie wyłączone) + filtr `ubr/apollo/phone_enabled`, uprawnienie `contacts.reveal` (owner / admin), osoba z identyfikatorem Apollo, brak już pobranego numeru (ponownie tylko trybem `refresh`), dzienny limit organizacji `UBR_APOLLO_PHONE_DAILY_LIMIT` (domyślnie 20), token potwierdzenia HMAC (10 min, związany z użytkownikiem, organizacją, osobą i trybem) + osobne potwierdzenie nieznanego kosztu, idempotencja (UNIQUE klucz; podwójne kliknięcie zwraca to samo zlecenie). Koszt w UI: „nieznany” (bez liczby kredytów); `credits_consumed` zapisywany z odpowiedzi.
- Bez automatycznego ponawiania płatnego zapytania: błąd HTTP → ERROR; zlecenie przerwane między rezerwacją a odpowiedzią (> 10 min w `reserved`) → ERROR z prośbą o sprawdzenie w Apollo.
- Statusy telefonu osoby: AVAILABLE (tylko z prawdziwym numerem), UNAVAILABLE (Apollo nie zwróciło numeru), UNKNOWN (nie sprawdzano / w trakcie), ERROR. **LOCKED nie jest przypisywany** — obecne API nie potwierdza go bez zapytania.
- Wynik: numery osoby tylko dla identyfikatora, dla którego zlecono (inne osoby z odpowiedzi pomijane); numer centrali (`work_hq`) → numer firmy organizacji, nie osoby; e-maile z odpowiedzi nie są zapisywane.
- Bezpieczny test ręczny: `wp ubr apollo-phone-test --org=<slug> --contact=<id> [--user=<id>]` — domyślnie tylko sprawdzenie warunków (bez zapytania); zapytanie płatne dopiero z `--execute --yes`; `--poll` odbiera wyniki (0 kredytów).

### Cold Call Readiness
`CallReadiness::classify()` (PHP) = `CallFilters::readiness()` (SQL), pierwsza pasująca reguła: **BLOCKED** (hard blocker Lead Intelligence, zakaz kontaktu, status / kwalifikacja „nie pasuje”) → **READY_DIRECT** (decydent z bezpośrednim numerem: numer osoby inny niż numery firmy i bez odrzucenia w organizacji, albo numer pobrany przez organizację) → **CALL_COMPANY** (numer firmy ze strony lub ręczny) → **RESEARCH** (brak numeru, firma / domena niepotwierdzona) → **FIND_PHONE**. Role decydentów: `CallReadiness::DECISION_ROLES`. Odrębna od scoringów; brak decydenta nie blokuje cold calla, gdy jest numer firmy.

### Company Ownership
- Statusy łączą się: `UNASSIGNED` / `MINE` / `ASSIGNED_OTHER` + flagi `IN_PROGRESS` (etapy rozmowy) i `DO_NOT_CONTACT`.
- `OwnershipService::claim()` — atomowo: `INSERT IGNORE` prospektu (UNIQUE organizacja + firma) i warunkowy `UPDATE … SET owner = ja WHERE owner IS NULL` (zarchiwizowany prospekt najpierw traci opiekuna). Z dwóch równoczesnych przejęć wygrywa jedno; drugie dostaje 409 `ubr_assigned_other` z nazwą opiekuna. Moja firma → bez duplikatu. Firma z zakazem kontaktu → 409.
- Przekazanie: `POST /prospects/{id}/transfer` = `PipelineService::update(owner)`; zmiana CUDZEGO opiekuna wymaga `prospects.transfer` (owner / admin). „Zapisz do Prospects” przypisuje dodającego tylko, gdy nikt nie przejął firmy w międzyczasie (`assignIfFree`).
- Wynik rozmowy (`POST /companies/{id}/call-outcome`): odrzucany przy BLOCKED i firmie innego opiekuna; wolna firma → przypisana do dzwoniącego; aktywność „Telefon” z wynikiem w istniejącej historii; etap tylko do przodu (Nowy / Zakwalifikowany / Do kontaktu → Skontaktowano; „Oddzwonić później” → Ponowienie; „Umówiono spotkanie” → W rozmowach; bez automatycznego „Utracony”). Kliknięcie `tel:` niczego nie zapisuje.

### Listy (Discover, Prospects)
- `CallFilters::select()` dodaje do zapytań list gotowość, osobę z numerem i pola prospektu; `CallPresenter::attach()` ładuje zbiorczo dla strony: osoby, numery firmy, numery pobrane, ostatnie zlecenia, opiekunów → `item.call` (readiness, size, decision_maker + stan telefonu, company_phone, ownership, actions). Akcje kontaktowe niedostępne przy DNC / BLOCKED / cudzym opiekunie (także po stronie serwera).
- Filtry na całym zbiorze (WHERE): telefon (do decydenta / firmy / jakikolwiek / brak), gotowość, opiekun (wolne / moje / innych / z kontaktem / DNC), wielkość (pasma 1–10 … 1000+, „brak danych”), ostatni kontakt (7 / 30 / 90 dni / nigdy), etap pipeline, analiza, branża i lokalizacja (LIKE z escapowaniem), widok „Wolne firmy z telefonem”. Sortowanie: gotowość, wielkość, ostatni kontakt, ostatnia analiza, nazwa A–Z (+ istniejące).
- UI: wspólne partiale `partials/call/*` (komórki, karta mobilna, szybkie akcje, filtry, okna „Wynik rozmowy” i „Odblokuj telefon”), store `resources/js/ubr/calling.js`. Tabela ≥ 1280 px (`table-fixed`, bez przewijania poziomego — także Prospects, wcześniej `min-w-[2170px]`), poniżej 1280 px karty. Menu ⋯ (`row-actions`) jest częścią szybkich akcji.

### Action Center
- Kolejka `ready_to_call` („Gotowe do telefonu”): READY_DIRECT / CALL_COMPANY, poza statusami zamkniętymi, bez firm innego opiekuna (`viewer_id` w widoku bazowym). Nie jest nowym KPI.
- Zakaz kontaktu wykluczony w `ActionPriorityEngine::classify()` i `sqlType()`; „Today's Priorities” i liczniki działań pomijają firmy innych opiekunów (follow-upy przypisane do mnie zostają). Klucz cache podsumowania zawiera użytkownika.

### API (nowe)
`GET/POST /companies/{id}/phones`, `POST /companies/{id}/phones/refresh`, `DELETE /companies/{id}/phones/{phone_id}`, `GET/POST /contacts/{id}/phone-reveal`, `POST /companies/{id}/claim`, `POST /companies/{id}/call-outcome`, `POST /prospects/{id}/transfer`, `POST /prospects/{id}/do-not-contact`. Sales payload: `do_not_contact*`, `last_contact_*`, `call_outcomes`, `can_transfer`, `activities[].outcome`.

## 18. Direct Decision Maker Phones (Prompt 10.2)

Cel: bezpośredni służbowy numer właściwego decydenta z dowodem powiązania numeru z osobą — bez zgadywania, bez Apollo.

### Dane (M011, schemat v11)
- `ubr_contact_phone_links` — powiązanie numer ↔ osoba: `contact_id`, `company_id`, `org_key` (0 = automat ze strony, wspólne;
  organizacja = decyzja organizacji), `e164`, `company_phone_id`, `link_status` (VERIFIED / LIKELY / UNCERTAIN / REJECTED),
  `evidence` (SAME_BLOCK, TABLE_ROW, PERSON_PAGE, FOOTER_BLOCK, SAME_BLOCK_NO_TITLE, SEQUENCE, NEARBY, MANY_NUMBERS, ALSO_ELSEWHERE, COMPANY_BLOCK,
  GENERAL_NUMBER, BRANCH_NUMBER, FOOTER_NUMBER, DEPARTMENT_LABEL, SHARED_NUMBER, MANUAL_CONFIRM / REJECT / ASSIGN, LEGACY_BLOCK),
  `source_type` (WEBSITE / MANUAL), `source_url`, `evidence_excerpt`, `observed_name`, `observed_title`, `found_at`, `last_seen_at`,
  `verified_at`, `verified_by`. UNIQUE (contact, org_key, e164) — idempotentnie.
- `ubr_company_phones.owner_class` (PERSON_DIRECT / DEPARTMENT / COMPANY_GENERAL / BRANCH / UNCERTAIN) + `owner_note` + `owner_checked_at`.
- Migracja: istniejące `contacts.phone_e164` → LIKELY (`LEGACY_BLOCK`) albo VERIFIED (`MANUAL_ASSIGN` dla osób dodanych ręcznie).

### Ekstrakcja (`Domain\Phones\PersonPhoneExtractor`)
- Wejście: HTML już pobranej strony + numery ogólne / oddziałów firmy. Numery: linki `tel:` i tekst tylko po „tel./kom./telefon…”
  albo z prefiksem +48 / 0048 (bez NIP / REGON / KRS / faksu — `CompanyPhoneExtractor::notPhoneBefore`). Osoby: `PeopleExtractor::classify`
  (imię i nazwisko, stanowisko w tej samej linii lub po separatorze „·”, „|”, „–”); stanowisko spoza katalogu ról
  (`genericTitle`: kierownik, manager, specjalista…) liczy się jako dowód struktury bloku, ale nie jako rola decydenta.
  Tekst przez `mb_scrub` (strony w innym kodowaniu). Blok osoby w stopce / nagłówku: maks. LIKELY (`FOOTER_BLOCK`).
- Blok: najmniejszy przodek numeru (maks. 6 poziomów, ≤ 600 znaków) zawierający osobę; jedna osoba + stanowisko = VERIFIED (wiersz
  tabeli = TABLE_ROW), bez stanowiska = LIKELY; > 3 możliwe numery osoby = UNCERTAIN; blok z adresem / NIP / formą prawną = UNCERTAIN;
  duży blok albo numer przed nazwiskiem = o poziom niżej. Kilka osób w bloku → lista płaska (najbliższa osoba przed numerem, ≤ 6 linii):
  ze stanowiskiem LIKELY, bez — UNCERTAIN. Indywidualna podstrona osoby (jedna osoba, nazwisko w adresie) = PERSON_PAGE.
- Odrzucenia: numer ogólny / oddziału, numer ze stopki / nagłówka / nawigacji, etykieta przy numerze „centrala / sekretariat / dział …”
  (bez stanowiska samej osoby), ten sam numer przy kilku osobach; ten sam numer w innym miejscu strony → UNCERTAIN (ALSO_ELSEWHERE).
- Wynik: osoby (z tytułem, rolą, liczbą wystąpień na stronie) z numerami oraz właściciel każdego numeru na stronie.

### Usługa (`Services\DecisionPhoneService`)
- `scan()` (REST `POST /companies/{id}/decision-phones/scan`, `dry_run`; CLI `wp ubr decision-phones`): pobiera strony źródłowe
  zapisanych numerów (maks. 3, najpierw te z numerami „dział / osoba”) + maks. 2 podlinkowane podstrony zarząd / zespół / media
  (`ContactPageCrawler::candidates`), robots.txt, `PageFetcher` (SSRF), blokada 10 min na firmę, limit dzienny organizacji
  (`UBR_DM_PHONES_DAILY_LIMIT`, domyślnie 80). Bez Apollo i bez Website Analyzera.
- `process()`: dopasowanie do kontaktów firmy po kluczu imienia i nazwiska (także z `full_name`); nowa osoba tylko z rolą decydenta
  przez `DecisionMakerFinderService::persistWebsiteCandidate()` (bez telefonu w ocenie); wariant nazwiska różniący się o ≤ 2 znaki przy
  tym samym imieniu = możliwy duplikat (bez łączenia i bez nowego kontaktu; najpierw pisownia częściej występująca na stronie);
  różne stanowisko z innego działu → VERIFIED obniżone do LIKELY. Adresy e-mail z fragmentów stron są usuwane (`redact` → „[e-mail]”).
  `fromDocuments()` — te same reguły dla stron pobranych przez Decision Maker Findera (bez tworzenia osób).
- Decyzje organizacji: `decide()` (confirm / reject / assign istniejącego numeru firmy), `remove()` (decyzja organizacji → usunięta;
  automat → odrzucony dla organizacji). Numer firmy nigdy nie jest usuwany. REST `GET/POST /contacts/{id}/phone-links`, `DELETE …/{link_id}`.

### Gotowość i listy
- `CallFilters::link($org, $statuses, $source)` — efektywny status dla organizacji (decyzja wygrywa z automatem), osoba z rolą decydenta.
- READY_DIRECT = `link(VERIFIED)` lub numer z Apollo (`CallFilters::apollo`, bez numerów odrzuconych przez organizację);
  RESEARCH także gdy jest tylko numer LIKELY (bez numeru firmy). PHP: `CallReadiness::classify(['likely_phone' => …])`.
- Filtry (`phone`): direct (potwierdzony lub prawdopodobny), dm_verified, dm_review, company_only, no_dm, company, any, none;
  `phone_source` (website / manual / apollo); `dm_role` (role decydenta).
- Listy: `ApolloPhoneService::state()` przyjmuje powiązania (VERIFIED / LIKELY z etykietą i źródłem) + numery Apollo; UNCERTAIN tylko
  jako liczba „do weryfikacji”; „Brak numeru decydenta”. Karta firmy: sekcja „Telefon decydenta” (status, źródło, dowód, daty,
  Potwierdź / Odrzuć / Usuń powiązanie / Przypisz istniejący numer) i „Szukaj telefonów decydentów”.
- Action Center: `ready_to_call` z filtrem `call_kind` (direct / company), `breakdown` (liczby obu ścieżek) i potwierdzonym numerem
  decydenta w wierszu. KPI bez zmian.

### Apollo
- People API Search (`/mixed_people/api_search`): wg dokumentacji 0 kredytów, bez e-maili i telefonów, dostęp kluczem z uprawnieniem
  do endpointu lub kluczem master. Funkcja nadal wyłączona (`UBR_APOLLO_PEOPLE_ENABLED`); kontrolowany test
  `POST /integrations/apollo/people-test` (owner / admin, potwierdzenie, 1 / h, bez zapisu, zapis w dzienniku jako niepłatny).
- Phone Enrichment bez zmian (Prompt 10): wyłączony, tylko świadome odblokowanie; numer z Apollo traktowany jako potwierdzony
  (identyfikator osoby), chyba że organizacja go odrzuci.

## 19. Apollo Contact Matching & Phone Unlock (Prompt 10.3)

Cel: powiązać osoby z Apollo People API Search z kontaktami firmy (bez zgadywania nazwisk) i przygotować kontrolowane, płatne
odblokowanie numeru decydenta — w tym etapie zablokowane po stronie serwera.

### Dane (M012, schemat v12)
- `ubr_apollo_people` — kandydaci z People API Search przy firmie (wspólni, jak kontakty automatyczne): `apollo_person_id`,
  `apollo_org_id`, `first_name`, `last_name` (TYLKO gdy Apollo podało pełne), `last_name_obfuscated`, `title`, `normalized_role`,
  `org_domain`, `belongs_to_company`, `phone_signal` (surowe `has_direct_phone`), `phone_availability` (AVAILABLE / UNKNOWN /
  UNAVAILABLE; LOCKED nieużywany), `has_email_signal`, `apollo_refreshed_at`, `found_at`, `last_seen_at`. UNIQUE (firma, osoba Apollo).
  Bez e-maili i numerów.
- `ubr_apollo_matches` — dopasowanie kandydat ↔ kontakt PRYWATNE dla organizacji: `match_level` (EXACT / STRONG / POSSIBLE / NO_MATCH /
  CONFLICT), `match_status` (SUGGESTED / APPROVED / REJECTED / DETACHED / OBSOLETE), `match_source` (PEOPLE_SEARCH / MANUAL), `evidence`
  (JSON), `matched_at`, `decided_by`, `decided_at`, `last_verified_at`. `contact_id = 0` = brak kontaktu. UNIQUE (organizacja, kandydat, kontakt).
- `ubr_apollo_match_history` — każda zmiana (suggest, auto_approve, approve, reject, detach, manual_link, recheck, rematch, level_change,
  obsolete): z jakiego statusu, na jaki, poziom, kontakt, użytkownik, notatka (bez HTML).
- `ubr_phone_reveals.apollo_person_id` — identyfikator osoby, dla którego wysłano zlecenie (wynik przypisywany tylko tej osobie).
- Identyfikator Apollo kontaktu w organizacji = dopasowanie APPROVED (`ApolloContactService::apolloIdFor`), a bez niego
  `contacts.external_id` (provider apollo), o ile organizacja go nie odrzuciła / nie odłączyła. Kontakty wspólne nie są modyfikowane.

### Dopasowanie (`Domain\Contacts\ApolloMatcher`, czysta logika)
- Osoba spoza firmy (inna domena / organizacja Apollo — `ApolloPeopleSource::belongsToCompany`) → CONFLICT, bez kontaktu.
- Ten sam identyfikator Apollo przy kontakcie → EXACT.
- Imię musi być zgodne (`PersonName::fold`, bez polskich znaków). Pełne nazwisko z Apollo równe → EXACT (STRONG przy stanowisku z innego
  obszaru); nazwisko zgodne z maską (`maskMatches`: początek + koniec, dłuższe od widocznych liter) → STRONG przy zgodnym obszarze
  stanowiska, inaczej POSSIBLE. Maska NIGDY nie daje EXACT. Sprzeczne nazwisko → brak dopasowania.
- Kontakt z innym identyfikatorem Apollo albo identyfikator zatwierdzony w organizacji dla innego kontaktu → CONFLICT.
- Kilka kontaktów pasujących do jednego kandydata (np. Nowak / Nowaczek do „No***k”) → wszystkie POSSIBLE.

### Serwis (`Services\ApolloContactService`)
- `search(ctx, firma, confirm)` — `contacts.run`; domena firmy ustalona i nie „mismatch”; najpierw pamięć podręczna (transient 1 dzień,
  ten sam adres co test kontrolny, `per_page=10`); zapytanie sieciowe tylko gdy `UBR_APOLLO_PEOPLE_ENABLED` + klucz + `confirm`;
  24 h przerwy na firmę, dzienny limit organizacji (`UBR_APOLLO_PEOPLE_DAILY_LIMIT`, domyślnie 20), atomowa blokada równoległych zapytań
  (`add_option`), błędy 401/403/422/429/sieć bez ponawiania. Zapis kandydatów → `rematch`.
- `rematch(org, firma)` — bez zapytań do Apollo; EXACT zatwierdzane automatycznie (historia `auto_approve`), STRONG / POSSIBLE jako
  propozycje; decyzje użytkownika (APPROVED / REJECTED / DETACHED) bez zmian (aktualizowany tylko poziom + wpis `level_change`);
  propozycje, których dopasowanie już nie zwraca → OBSOLETE (nie usuwane).
- `decide(approve | reject | detach | recheck | link)` — `contacts.manage`; NO_MATCH / CONFLICT nie do zatwierdzenia (422); konflikt
  identyfikatorów → 409; ręczne połączenie tylko, gdy imię i nazwisko kontaktu nie przeczą danym Apollo.
- `candidates()` — tylko odczyt; identyfikatory Apollo nie są wysyłane do przeglądarki. `approvedFor()` — jedno zapytanie dla listy.
- REST: `GET /companies/{id}/apollo-candidates` (contacts.view), `POST /companies/{id}/apollo-candidates/search` (contacts.run, `confirm`),
  `POST /apollo-matches/{id}` (contacts.manage, `action`), `POST /apollo-candidates/{id}/link` (contacts.manage, `contact_id`),
  `GET /apollo-matches/{id}/history` (contacts.view). Firma sprawdzana `isVisibleTo`, dopasowanie — `organization_id` (IDOR).

### Phone Unlock (`ApolloPhoneService`)
- Preflight: wyłączone (`UBR_APOLLO_PHONE_ENABLED`, domyślnie brak), `contacts.reveal`, zatwierdzony identyfikator Apollo, „Nie kontaktować”
  (prospekt organizacji), potwierdzony numer już zapisany, trwające zlecenie, numer już pobrany, dzienny limit. Koszt: `known=false`,
  etykieta „Koszt wymaga potwierdzenia”, stawki z dokumentacji (1–9 kredytów) jako informacja z dokumentacji.
- Obrona w głąb: `request()` sprawdza ponownie `enabled()` i identyfikator — bez nich nic nie jest wysyłane. Token HMAC (10 min),
  osobne potwierdzenie kosztu, idempotentna rezerwacja (podwójne kliknięcie = to samo zlecenie).
- Wynik przypisywany wyłącznie osobie z identyfikatora zapisanego w zleceniu (inne osoby z odpowiedzi pomijane).

### Interfejs
- Karta firmy → Decision Makers → „Decydenci w Apollo”: zgoda + „Szukaj decydentów w Apollo”, kandydaci (maska, stanowisko, poziom,
  status, kontakt, dostępność telefonu, data sprawdzenia), akcje: Zatwierdź, Odrzuć dopasowanie, Odłącz, Sprawdź ponownie, Połącz z kontaktem,
  Zobacz szczegóły (dowody, historia decyzji), Sprawdź możliwość odblokowania telefonu (modal z powodami blokady).
- Karta kontaktu: wiersz „Apollo” przy zatwierdzonym dopasowaniu. Discover / Prospects: sygnał Apollo pod decydentem
  („Apollo: numer bezpośredni dostępny (płatny, nie pobrano)” / „Dostępność telefonu nieznana”) i „Sprawdź Apollo” (karta firmy, odczyt z bazy).
- Gotowość bez zmian: identyfikator Apollo ani sygnał dostępności bez numeru nie dają READY_DIRECT.

## 20. Performance Optimization & Progressive Discovery (Prompt 10.4)

Cel: firmy widoczne na liście Discover od razu, weryfikacja istnienia w tle bez blokowania przez wolne strony, bez
dodatkowych zapytań do stron i bez dodatkowego zużycia kredytów Apollo.

### Progressive Discovery
- Wynik trafia do `ubr_discovery_results` zaraz po zapisaniu firmy (tak było wcześniej) — lista go pokazuje od razu,
  ze statusem „Oczekuje na weryfikację”.
- NOWE: firmy z każdej strony wyników dostawcy trafiają do kolejki weryfikacji od razu (`VerificationService::enqueueCompanies`
  w `DiscoveryRunner::fetchStep`), a nie dopiero po zakończeniu całego wyszukiwania. Zakończenie wyszukiwania tylko uzupełnia
  kolejkę (firmy już w kolejce pomijane, bez duplikatów — UNIQUE `company_id`).

### Weryfikacja: okno firm + równoległe pobieranie stron (jeden proces)
- `VerificationRunner::tick` trzyma do `UBR_VERIFY_BATCH` (domyślnie 6) firm „w toku”. Ich strony pobiera `ParallelFetcher`
  (curl_multi w tym samym procesie PHP — LocalWP ma 2 procesy PHP-FPM, hosting współdzielony ogranicza procesy), maks.
  `UBR_PREFETCH_CONCURRENCY` (domyślnie 6, maks. 12) połączeń, każdy adres i krok przekierowania przez UrlGuard + przypięcie IP.
- Pobierane są dokładnie te strony, które pobrałaby inspekcja (`WebsiteInspector::nextSubpage` odtwarza jej reguły:
  strona główna, http przy błędzie https, kolejne podstrony tylko dopóki brak NIP/KRS) — bez dodatkowych zapytań.
- Weryfikowana jest najpierw firma, której strony są już pobrane; niedostępna strona (timeout) nie blokuje pozostałych.
- Przerwy wymuszone limitami rejestrów (KRS 1,1 s, biała lista VAT 1,5 s) wykorzystywane na transfery w tle
  (`ProviderHttpClient::idle` → akcja `ubr/http/idle`). Limit częstotliwości dostawcy atomowy (blokada MySQL `rl_*`).
- Rejestry nadal sekwencyjnie (limity) — to one wyznaczają dolną granicę czasu (~1,5 s / firmę z NIP).
- Numery firmy odczytywane od razu po weryfikacji ze stron pobranych do weryfikacji (`CompanyVerifier::extractPhones`,
  bez nowych zapytań; pomijane przy domenie innego podmiotu, stronie zaparkowanej / niedostępnej).

### Wspólna pamięć podręczna stron (M013, `ubr_page_cache`)
- `PageCache` + `PageFetcher::hop()` — każdy krok GET (także przekierowanie) przez cache; korzystają: WebsiteInspector
  (weryfikacja), PageFetcher (Website Analyzer, robots.txt, Decision Maker Finder, telefony decydentów), ParallelFetcher.
- TTL: 2xx–4xx `UBR_PAGE_CACHE_TTL` (domyślnie 3600 s), 5xx 300 s, błąd połączenia — wpis „serwer nie odpowiada” na 600 s
  (kolejne moduły nie czekają ponownie na timeout). Odpowiedzi > 2 MB nie są zapisywane; zapis base64(gzip).
- Tylko anonimowe GET publicznych stron, nagłówki techniczne (bez Set-Cookie) — bez danych organizacji (izolacja zachowana).
- Odświeżenie: `PageFetcher::fetch($url, $timeout, fresh: true)` — „Odśwież numery” zawsze pobiera aktualną stronę i nadpisuje cache.
- Czas strony z cache = oryginalny czas pobrania (Analyzer nie zaniża metryk). Sprzątanie: healthcheck `purgeExpired(500)`.
- Wyłączenie: `define('UBR_PAGE_CACHE', false)` (wtedy też bez równoległego pobierania).

### Statusy etapów (Discover) i gotowość
- `CompanyPresenter::stages()` → `stages.verification` (Oczekuje / W toku / Zweryfikowana / Niepewna / Niezgodność / Nieaktywna),
  `stages.website` (Oczekuje / Analizowanie / Gotowe / Błąd / Nie analizowano), `stages.phones` (Oczekuje / Wyszukiwanie /
  Znaleziono / Nie znaleziono / Błąd), `stages.dm` (Oczekuje / Wyszukiwanie / Znaleziono / Nie znaleziono / Błąd / Nie szukano).
  Lista dołącza status kolejki weryfikacji (`vq.status`). UI: `partials/discover-stages` (w komórce firmy i na karcie mobilnej).
- Gotowość: firma nieaktywna / zawieszona w rejestrze → BLOCKED; domena innego podmiotu (MISMATCH) → RESEARCH — nigdy
  automatycznie w kolejce „gotowe do telefonu” (`CallReadiness::classify` + `CallFilters::readiness`). Scoringi bez zmian.

### Odświeżanie listy
- `GET /discovery/searches/{id}/results?ids=…&changed_since=…` — tylko wskazane (widoczne) firmy zmienione od czasu
  `server_time` (companies / kolejka weryfikacji / Lead Intelligence / prospekt); bez COUNT, te same filtry i sortowanie.
- Front: `refreshChanged()` podmienia zmienione wiersze w miejscu (bez migotania, zachowane filtry, sortowanie,
  rozwinięte wiersze i przewinięcie); pełna lista tylko przy nowych firmach lub końcu wyszukiwania. Zdarzenia z karty firmy
  odświeżają lekko. Status wyszukiwania co 2,5 s w trakcie, co 6 s przy weryfikacji; `QueueMonitor::recover` przy statusie
  najwyżej co 10 s na wyszukiwanie.

### Bez połączeń telefonicznych
- Usunięte linki `tel:` i przycisk „Zadzwoń” (Discover, Prospects, karta firmy, karta kontaktu, Action Center). Numery,
  kopiowanie, źródła, weryfikacja, wynik rozmowy, Cold Call Readiness i pipeline bez zmian.

## 21. Discover Optimization + Website Age Intelligence + Claim-Based Decision Maker Finder + ClickUp Auto-Sync (Prompt 10.4.3)

Migracja **M014** (schemat v14): nowe kolumny `ubr_companies` (`general_email*`, `site_age_*`, `site_launch_*`, `site_last_redesign_year`,
`site_modernity`), `ubr_contact_research_runs.stage`, `ubr_clickup_exports.synced_fields` / `conflicts`. Tylko nowe kolumny i indeksy.

### Worker weryfikacji (przyczyna przerw ~2:50 na Hostingerze, diagnostyka 10.4.1)
LiteSpeed przerywał proces pętli zwrotnej po ~10 s, a blokada weryfikacji (170 s) zostawała. Teraz `VerificationRunner::tick()`:
krok `UBR_VERIFY_STEP_SECONDS` (domyślnie 8 s) z łańcuchowaniem kolejnego kroku, blokada 60 s odnawiana po każdej firmie,
zajęcie pozycji kolejki na 90 s, nieprzetworzone pozycje zwracane do kolejki (`VerificationQueueRepository::release`).
Konfiguracja serwera (noabort / cron hPanel) — osobna decyzja właściciela (DIAG-prompt10-4-1).

### Discover — tylko szybkie dane firmy
- Weryfikacja zapisuje dodatkowo **ogólny e-mail firmy** (`GeneralEmailPicker`: biuro@ / kontakt@ / info@ …, potem działowe;
  nigdy adres osobowy, noreply, RODO/faktury ani adres spoza domeny; nie przy domenie innego podmiotu). Bez dodatkowych zapytań —
  adresy z tych samych stron (`WebsiteInspector` → `emails`).
- Po weryfikacji: **Website Opportunity Score w tle** (`AnalysisService::queueAuto`, trigger `auto`, najniższy priorytet w kolejce
  analiz, limit `UBR_AUTO_ANALYSIS_PER_DAY` = 300, wyłącznik `UBR_AUTO_ANALYSIS`). Po analizie → kolejka Website Age.
- Decision Maker Finder / Apollo People / Matching / Phone Enrichment **nie są uruchamiane w Discover** (etap „Decydent: Po przejęciu”).
- Lead Intelligence: listy Discover / Prospects już **nie zlecają** przeliczeń (usunięte `kick` z `DiscoveryService::results`
  i `ProspectService::list`); przeliczenie po zmianie danych firmy (zdarzenia) i po zakończeniu wyszukiwania (`DiscoveryRunner`).

### Website Age Intelligence
- `Domain/Analysis/Age`: `FingerprintExtractor` (odcisk PROJEKTU: zasoby CSS/JS bez wersji, motyw/szablon, platforma, klasy
  nagłówka/nawigacji/stopki, szkielet DOM, menu — bez treści), `FingerprintComparator` (Jaccard: zasoby .3, klasy .3,
  struktura .25, menu .15; `same` ≥ 0,8, `minor` ≥ 0,45, inaczej `major`; zmiana motywu/platformy = `major`; < 8 cech = `uncertain`),
  `TechSignals` (viewport, doctype, Flash, układ tabelowy, IE, stary jQuery/Bootstrap, MooTools/Prototype, lazy, WebP, bloki WP,
  rok w stopce; daty z meta / Last-Modified tylko jako daty treści) → nowoczesność **osobno** od wieku, `AgeEstimator`.
- `WaybackClient`: CDX (`collapse=timestamp:6`, tylko HTML 200, cache 14 dni) + kopie `id_` (bez paska archiwum), odstęp
  `UBR_WAYBACK_INTERVAL_MS` (≥ 1000, domyślnie 2000), krótkie timeouty, 429/5xx/timeout = ponowienie później, dziennik `wayback` w `ubr_api_requests`.
- `WebsiteAgeService::step()` — automat stanów zapisywany po każdym zapytaniu (transient `ubr_site_age_work_{id}`): obecna strona →
  lista kopii → kopie reprezentatywne (najnowsza, ~1/3/5/8/12 lat, najstarsza; maks. 7) → doprecyzowanie granicy wyszukiwaniem
  połówkowym (maks. 3 kopie; do ≤ 13 mies. i jednego roku) → wynik. Kopie z tym samym skrótem CDX bez pobierania, odciski kopii w cache 180 dni.
- Wynik: wdrożenie (rok albo przedział, „co najmniej od” gdy brak wcześniejszego projektu w archiwum), wiek, ostatni redesign,
  pewność HIGH (granica ≤ 13 mies. w jednym roku, jednoznaczne porównania) / MEDIUM / LOW (sprzeczne sygnały obniżają) / UNKNOWN,
  status NEW (≤ 2 lata) / CURRENT / AGING / OUTDATED / UNKNOWN, dowody i źródła. Najstarsza kopia ≠ data rejestracji domeny
  ani wdrożenia. Brak danych → UNKNOWN, bez wymyślania dat. Wiek nie zmienia Opportunity Score (propozycja wag — QA §16).
- `WebsiteAgeRunner` — najniższy priorytet: ustępuje weryfikacji (kolejka lub blokada), jeden worker (blokada `ubr_site_age`),
  krok jak weryfikacja; ponowienia 30 min / 3 h, maks. 3 próby → UNKNOWN; limit dzienny `UBR_SITE_AGE_DAILY_LIMIT` (150);
  wynik aktualny 90 dni, „Sprawdź ponownie” po 24 h. Strona chwilowo nieosiągalna → ponowienie (nie zapisuje „nieznany” na 90 dni).
- Nigdy przy renderowaniu list / paginacji / filtrach. Filtr Discover `site_age` = 5 / 7 / 10 (minimalny wiek projektu) / `unknown`.
- REST: `POST /companies/{id}/site-age` (`analysis.run`, 202 = zlecone).

### „Przejmij firmę” → Decision Maker Finder i ClickUp w tle
- `OwnershipService::claim` — atomowe przypisanie jak wcześniej; tylko przy faktycznej zmianie właściciela `afterClaim()`:
  `ClickUpExportService::queueForClaim` + `ContactResearchService::requestForClaim` (wyjątki łapane — przejęcie nie jest cofane).
- `requestForClaim`: blokada `ubr_claim_dm_{id}`, bez duplikatów (trwająca analiza), aktualna analiza nie jest powtarzana,
  zakaz kontaktu / „nie pasuje” / nieaktywna / zawieszona → bez analizy, brak źródeł, limit `UBR_CLAIM_RESEARCH_PER_DAY` (100),
  „Ponów” z czasem ochronnym `UBR_CLAIM_RETRY_COOLDOWN_MINUTES` (60; po błędzie maks. 10 min) liczonym od ostatniej analizy.
- Bez płatnych zapytań: przy przejęciu Apollo tylko z zapisanych wyników (`ApolloPeopleSource::saved`, wyłącznie pełne nazwiska);
  nowe People Search przy przejęciu tylko za zgodą (`UBR_CLAIM_APOLLO_PEOPLE`). Phone Enrichment nigdy automatycznie.
- Etapy w `stage`: queued → people → phones → done/failed. `claimStatus()`: Oczekuje / Szukamy decydenta / Szukamy numeru /
  Znaleziono numer bezpośredni / Znaleziono decydenta bez telefonu / Nie znaleziono / Wymaga ręcznej weryfikacji (kandydaci bez
  potwierdzenia, np. tylko Apollo) / Błąd / Wstrzymana. REST: `GET /companies/{id}/claim-status`, `POST /companies/{id}/claim-research`.

### ClickUp — dwuetapowa synchronizacja jednego zadania
- **Domyślnie wyłączone** — włącza właściciel / admin w Settings → ClickUp („Zadania po przejęciu firmy”, `POST /clickup/claim-sync`,
  ustawienie organizacji `clickup_on_claim`) albo stała `UBR_CLICKUP_ON_CLAIM`. Połączenie pracownika z listą docelową, inaczej
  połączenie wskazane przez właściciela (`clickup_claim_connection_id`; zadanie bez przypisanej osoby + notatka o braku mapowania).
  Karta firmy pokazuje „Wyłączone” z informacją, kto włącza.
- Etap 1 (przejęcie): nazwa = firma; Contact number = numer główny firmy; Lead email; Website; Source = „Ugly but Rich”;
  Funnel step = „Leads”; przypisany pracownik; link do karty; Lead Potential — tylko pola istniejące i zmapowane.
- Etap 2 (`contacts_done` → `queueClaimUpdate`): Contact = imię i nazwisko decydenta (Top 1, zweryfikowany/prawdopodobny,
  bez zamaskowanych), Decision maker phone = WYŁĄCZNIE numer z potwierdzonym powiązaniem z osobą (VERIFIED, ≠ numer firmy).
- `syncFields`: pole zmienione ręcznie w ClickUp nie jest nadpisywane — konflikt zapisany (`conflicts`, ostatnie 10) i widoczny
  w karcie; puste pole uzupełniane. `synced_fields` przechowuje ostatnio wysłane wartości.
- Idempotencja: znacznik `ubr-ref: o{org}-c{firma}-e{eksport}` w opisie; timeout / niejednoznaczna odpowiedź przy tworzeniu →
  stan `unknown` + zadanie `reconcile` (szukanie po znaczniku); 2 negatywne sprawdzenia → jednorazowe ponowne utworzenie.
- Pola nie są tworzone automatycznie: `ClickUpDestinationService::suggest()` proponuje mapowanie po nazwach i listę brakujących
  pól (np. „Decision maker phone”, typ Phone) do utworzenia przez właściciela.

## 22. Sequences — biblioteka kampanii, sekwencje firm i automatyczna wysyłka (Prompt 10.5)

Moduł w motywie (bez osobnej wtyczki): `App\Ubr\Domain\Sequences`, `App\Ubr\Services\Sequences`, `App\Ubr\Infrastructure\Mail`,
`SequenceController`, strona `/app/sequences/` (zakładki Campaigns / Active / Scheduled / Sent / Mailboxes / Settings) i zakładka
„Mailing” w karcie firmy. Migracja **M015** (schemat v15) — tylko nowe tabele `ubr_seq_*`. Moduł nie synchronizuje niczego z ClickUp.

### Dane
- `ubr_seq_campaigns` / `ubr_seq_campaign_steps` — szablony: zakres `shared` (zespół; edycja `sequences.templates` = owner/admin)
  albo `personal` (pracownik; widoczne też dla adminów). Status draft / active / archived; `version` +1 przy każdej zmianie kroków.
- `ubr_seq_sequences` — sekwencja firmy = NIEZALEŻNA KOPIA kroków (snapshot) z `campaign_id`, `campaign_name`, `campaign_version`
  jako informacją o źródle. Edycja kopii nie zmienia szablonu; zmiana szablonu nie zmienia sekwencji. Odbiorca (adres, źródło,
  kontakt) zapisany w sekwencji — zmiana e-maila firmy w Discover go nie zmienia. `active_recipient = org|adres` dla stanów
  zaplanowana / aktywna / wstrzymana / błąd (UNIQUE) → jedna aktywna sekwencja na adres w organizacji, także między firmami.
- `ubr_seq_messages` — kroki sekwencji = harmonogram = trwała kolejka = historia: `status`, `scheduled_at`, `send_token`
  (UNIQUE, identyfikator operacji wysyłki), `message_id`, wysłany temat / treść, nadawca, odbiorca, transport, odpowiedź SMTP, błąd.
- `ubr_seq_mailboxes` — skrzynki pracowników (SMTP: host, port 465/587, szyfrowanie, login, hasło aplikacji zaszyfrowane
  `SecretBox`; limity; wynik kontroli DNS). `ubr_seq_suppressions` — adresy wypisane / z trwałym błędem / wykluczone ręcznie.

### Statusy
Sekwencja: Szkic → Zaplanowana → Aktywna → Zakończona; Wstrzymana, Anulowana, Błąd (wymaga uwagi: wynik nieznany, brak danych,
skrzynka, nieudane ponowienia). Wiadomość: Oczekuje, Zaplanowana, Wysyłanie, Przekazana do serwera poczty (≠ dostarczona),
Błąd wysyłki, Anulowana, Wynik nieznany. Pipeline: nowy status „Mailing” — sam status niczego nie wysyła.

### Przypisanie i uruchomienie
Karta firmy → Mailing → „Przypisz kampanię”: kampania (albo pusta), odbiorca (ogólny e-mail firmy z zastrzeżeniem, e-mail
decydenta `public_business_email`, adres ręczny), własna skrzynka → SZKIC. W szkicu: edycja / dodawanie / usuwanie / kolejność
kroków, odstępy, okno wysyłki (dni, godziny, strefa), start i koniec, wartości ręczne zmiennych, podgląd z terminami.
Start wymaga `confirm` i przechodzi kontrolę: własna przetestowana skrzynka (albo tryb testowy), adres poprawny technicznie
(składnia + MX/A), brak blokad (rezygnacja, trwały błąd, DO_NOT_CONTACT / „nie pasuje”, BLOCKED, kontakt wyłączony), komplet
zmiennych, konflikt adresu (409: zostaw / zakończ obecną i uruchom / anuluj). Uprawnienia: sekwencje firm, których prospekt należy
do pracownika (owner/admin — zespół, `sequences.team`); wysyłka wyłącznie z WŁASNEJ skrzynki.

### Personalizacja
`TemplateRenderer`: `{{company_name}}`, `{{company_website}}`, `{{company_domain}}`, `{{company_city}}`, `{{contact_first_name}}`,
`{{contact_last_name}}`, `{{contact_full_name}}`, `{{contact_position}}`, `{{sender_first_name}}`, `{{sender_last_name}}`,
`{{sender_name}}`, `{{sender_email}}`, `{{unsubscribe_link}}`. Wiadomości jako tekst (text/plain) — wartości bez HTML / znaków
sterujących, temat w jednej linii (ochrona nagłówków). Brak wartości lub nieznana zmienna → blokada startu i wysyłki (nigdy
surowy placeholder). Wartości ręczne mają pierwszeństwo; nic nie jest zgadywane.

### Harmonogram (`SendWindow`)
Wszystkie terminy w UTC, okno w strefie sekwencji (domyślnie Pn–Pt 09:00–16:00 Europe/Warsaw). Termin poza oknem → najbliższy
dozwolony moment (deterministycznie, z DST). Pierwszy krok: start (lub data rozpoczęcia) + odstęp; kolejne: FAKTYCZNA wysyłka
poprzedniego + odstęp. Planowana jest tylko jedna (kolejna) wiadomość — po przestoju workera / wznowieniu wychodzi najwyżej jedna,
następne liczone od niej. Data zakończenia → sekwencja zakończona bez pozostałych wiadomości.

### Kolejka i worker (`SequenceRunner`)
Trwałe zadania w MySQL (`ubr_seq_messages`), krok 8 s, blokada MySQL `ubr_seq_send` (jeden worker; zwalnia się po przerwaniu
procesu) + blokada sekwencji `ubr_seq_{id}` (wyklucza równoległą edycję i wysyłkę). Uruchamianie: cron serwera
`wp ubr queue-work --all` (zalecane — nie tylko WP-Cron), łańcuchowo pętlą zwrotną (`job=sequences`), zapasowo WP-Cron
`ubr_sequences_tick` i healthcheck. Nigdy w żądaniu użytkownika. Przed wysyłką: włączona wysyłka (albo tryb testowy), okno,
koniec, skrzynka, blokady, limity skrzynki (dziennie / godzinowo / minimalny odstęp — wiadomość przesuwana), komplet zmiennych.
Zajęcie atomowe `scheduled → sending` z zapisanym `send_token` i Message-ID (`<ubr.{id}.{token}@domena-nadawcy>`) PRZED wysyłką.

### Wynik wysyłki i duplikaty
`SmtpTransport` (PHPMailer z WordPressa, bez `wp_mail()` — poczta systemowa i wtyczki SMTP nietknięte) śledzi etap rozmowy SMTP:
błąd przed treścią / 4xx → `retry` (ponowienie 15 min / 1 h / 4 h z nowym identyfikatorem, potem błąd); 5xx → `failed`
(550 / 5.1.x przy RCPT → adres zablokowany jako trwały błąd); 535 → `auth` (skrzynka „Błędne dane logowania”, wiadomość czeka);
brak odpowiedzi PO przekazaniu treści → `unknown` = „Wynik nieznany”: bez automatycznego ponowienia, sekwencja w stanie błędu;
pracownik sprawdza folder Wysłane i potwierdza „wysłana” (kolejny krok) albo „nie wyszła” (ponowna wysyłka po wznowieniu).
Proces przerwany w trakcie (`sending` po czasie zajęcia 180 s) → też „Wynik nieznany”. `LogTransport` (tryb testowy, skrzynka
testowa) buduje wiadomość i zapisuje historię bez wysyłki.

### Rezygnacja i wykluczenia
Stopka z `{{unsubscribe_link}}` (domyślnie włączona, tekst w ustawieniach) i nagłówki `List-Unsubscribe` +
`List-Unsubscribe-Post: One-Click`. Link `/?ubr_unsubscribe=<org>.<sekwencja>.<HMAC>` (bez adresu w URL): GET — strona
z potwierdzeniem, POST — trwała blokada adresu w organizacji i anulowanie jego aktywnych sekwencji. Pracownik może wypisać
odbiorcę ręcznie; odblokowanie — owner/admin. Odpowiedzi nie są wykrywane (brak IMAP) — kampanię zatrzymuje się ręcznie.

### Ustawienia i bezpieczeństwo
Automatyczna wysyłka DOMYŚLNIE WYŁĄCZONA (`ubr_organizations.settings.sequences.sending_enabled`); włącza owner/admin po liście
kontrolnej: skrzynka SMTP przetestowana (30 dni), worker uruchomiony w ostatnich 15 min, wiadomość w trybie testowym
przetworzona, potwierdzenie zabezpieczeń przed duplikatami; `UBR_SEQUENCES_SENDING=false` w wp-config.php blokuje wysyłkę zawsze.
Limity skrzynek domyślnie 30/dzień, 8/godz., odstęp 4 min (górne limity zespołu 100 / 25 — admin). Kontrola SPF / DKIM
(typowe selektory) / DMARC — informacyjna. Hasła nigdy w odpowiedziach / logach; nieczytelne (zmiana klucza) → „Wymaga ponownego
podania hasła”; odłączenie usuwa hasło i wstrzymuje sekwencje skrzynki (historia zostaje). Skrzynki testowe tylko poza produkcją
lub z `UBR_MAIL_TEST_MAILBOXES`.

### AI Outreach Assistant (przygotowanie)
Interfejs `OutreachAssistant` (domyślnie `DisabledOutreachAssistant` → 501), endpoint `POST /sequences/messages/{id}/ai-suggest`
i przycisk „Spersonalizuj z AI” w edytorze. Przyszła implementacja zaproponuje temat / treść kroku z zapisanych danych (analiza
strony, Opportunity, Website Age, branża, wielkość); pracownik zatwierdza; płatne wywołania tylko za zgodą.
