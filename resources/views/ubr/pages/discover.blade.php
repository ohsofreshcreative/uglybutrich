@extends('ubr.layouts.app')

@section('content')
	{{--
		Discover (Prompt 5.1): układ pionowy na pełną szerokość — formularz (zwijany) → status wyszukiwania → filtry → wyniki.
		Logika wyszukiwania, filtrów i sortowania bez zmian (te same pola i parametry REST). Tabela bez przewijania poziomego
		(table-fixed, zawijanie tekstu); poniżej 1024 px wyniki jako karty. Akcje wiersza w menu ⋯ (partials/row-actions).
	--}}
	<div x-data="ubrDiscover" class="space-y-5 w-full min-w-0">

		{{-- 1. Formularz wyszukiwania (zwijany; wartości zostają w stanie komponentu) --}}
		<form class="ubr-card" @submit.prevent="start()" aria-labelledby="ubr-form-title">
			<header class="flex flex-wrap items-center gap-3 px-5 py-4" :class="formOpen ? 'border-b border-line' : ''">
				<div class="min-w-0 flex-1 basis-[240px]">
					<h2 id="ubr-form-title" class="font-extrabold text-[16px]">Czego szukamy?</h2>
					<p class="text-[12px] text-ink-muted mt-0.5" x-show="formOpen">Wybierz branżę lub opisz działalność. Wyniki pochodzą wyłącznie z prawdziwych źródeł.</p>
					<p class="text-[12px] text-ink-muted mt-0.5 truncate" x-show="!formOpen" x-text="formSummary" :title="formSummary"></p>
				</div>
				<label class="sr-only" for="ubr-history">Historia wyszukiwań</label>
				<select id="ubr-history" class="ubr-input h-8 text-[12px] w-auto max-w-full sm:max-w-[320px]" x-show="history.length" @change="$event.target.value && open(Number($event.target.value))">
					<option value="" x-text="'Historia wyszukiwań (' + history.length + ')'"></option>
					<template x-for="s in history" :key="s.id"><option :value="s.id" :selected="search && search.id === s.id" x-text="'#' + s.id + ' · ' + s.label + ' · ' + s.results_count + ' firm · ' + $store.ubr.statusLabel(s.status)"></option></template>
				</select>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="toggleForm()" :aria-expanded="formOpen" aria-controls="ubr-form-body">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3.5 transition" :class="formOpen ? 'rotate-180' : ''" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
					<span x-text="formOpen ? 'Zwiń' : 'Rozwiń'"></span>
				</button>
			</header>

			<div id="ubr-form-body" x-show="formOpen" class="p-5 space-y-4">
				<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-12">
					<div class="xl:col-span-4">
<div>
					<label class="ubr-label" for="ubr-industry">Branża</label>
					<select id="ubr-industry" class="ubr-input" x-model="form.industry">
						<option value="">— dowolna (użyj frazy) —</option>
						<template x-for="i in options.industries" :key="i.key"><option :value="i.key" x-text="i.label"></option></template>
					</select>
				</div>
					</div>
					<div class="md:col-span-2 xl:col-span-8">
<div>
					<label class="ubr-label" for="ubr-phrase">Fraza opisująca działalność</label>
					<input id="ubr-phrase" class="ubr-input" type="text" maxlength="160" x-model="form.phrase" placeholder="np. obróbka metali, produkcja opakowań, automatyka przemysłowa">
					<p class="text-[11px] text-ink-muted mt-1" x-show="form.providers.includes('apollo')">Polskie zapytania z katalogu (np. „obróbka metali”) są tłumaczone na angielskie tagi Apollo. Inne frazy trafiają do Apollo dosłownie — po polsku mogą dać mało wyników.</p>
				</div>
					</div>
					<div class="xl:col-span-3">
						<label class="ubr-label" for="ubr-region">Województwo</label>
						<select id="ubr-region" class="ubr-input" x-model="form.region">
							<option value="">Cała Polska</option>
							<template x-for="r in options.regions" :key="r.key"><option :value="r.key" x-text="r.label"></option></template>
						</select>
					</div>
					<div class="xl:col-span-3">
						<label class="ubr-label" for="ubr-city">Miejscowość</label>
						<input id="ubr-city" class="ubr-input" type="text" maxlength="80" x-model="form.city" placeholder="opcjonalnie">
					</div>
					<div class="xl:col-span-3">
						<label class="ubr-label" for="ubr-size">Wielkość</label>
						<select id="ubr-size" class="ubr-input" x-model="form.size">
							<template x-for="s in options.sizes" :key="s.key"><option :value="s.key" x-text="s.label"></option></template>
						</select>
					</div>
					<div class="xl:col-span-3">
						<label class="ubr-label" for="ubr-limit">Maks. wyników</label>
						<select id="ubr-limit" class="ubr-input" x-model.number="form.limit">
							<template x-for="l in options.limits" :key="l"><option :value="l" x-text="l" :selected="l === form.limit"></option></template>
						</select>
					</div>
				</div>

				<label class="flex items-start gap-2 text-[12px] text-ink-soft" x-show="form.size !== 'any'">
					<input type="checkbox" class="ubr-checkbox mt-0.5" x-model="form.include_unknown_size">
					<span>Uwzględnij firmy bez danych o zatrudnieniu <span class="text-ink-muted">(większość źródeł ich nie podaje)</span></span>
				</label>

				<template x-if="options.loaded && !options.apollo.configured">
					<div class="rounded-xl bg-gold-soft text-gold-deep px-3 py-2.5 text-[12px] leading-snug">
						<strong>Apollo nie jest skonfigurowane.</strong> Główne źródło wymaga klucza API (stała <code>UBR_APOLLO_API_KEY</code> w wp-config.php). Do tego czasu możesz użyć źródeł opcjonalnych.
					</div>
				</template>

				<fieldset>
					<legend class="ubr-label">Źródła danych</legend>
					<div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
						<template x-for="p in options.providers" :key="p.key">
							<label class="flex items-start gap-2.5 rounded-xl border border-line px-3 py-2.5" :class="p.available ? 'cursor-pointer hover:border-ink/25' : 'opacity-60'">
								<input type="checkbox" class="ubr-checkbox mt-0.5" :value="p.key" x-model="form.providers" :disabled="!p.available">
								<span class="min-w-0">
									<span class="flex flex-wrap items-center gap-1.5 text-[13px] font-bold"><span x-text="p.label"></span><span x-show="p.primary" class="ubr-badge-confirmed">główne</span><span x-show="!p.primary" class="ubr-badge-unavailable">opcjonalne</span><span x-show="!p.available" class="ubr-badge-danger">wymaga klucza</span></span>
									<span class="block text-[11px] leading-snug text-ink-muted mt-0.5" x-text="p.description"></span>
								</span>
							</label>
						</template>
					</div>
				</fieldset>

				<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_260px] lg:items-end">
					<div class="space-y-3 min-w-0">
				<template x-if="form.providers.includes('apollo')">
					<div class="rounded-xl border border-line bg-canvas px-3 py-2.5 text-[12px] leading-snug text-ink-soft">
						<div class="font-bold text-ink">Koszt Apollo</div>
						<p class="mt-0.5">Szacunek wg dokumentacji Apollo (1 kredyt za stronę wyników, do 100 firm na stronę): <strong x-text="apolloEstimate"></strong>.</p>
						<p class="mt-0.5 text-ink-muted">Województwo, miasto i wielkość filtruje samo Apollo. Wyniki Apollo nie zawierają adresu ani zatrudnienia — nie przypisujemy ich z filtra. Lokalizację potwierdza dopiero weryfikacja w rejestrze (KRS / biała lista VAT).</p>
						<p class="mt-0.5 text-ink-muted" x-text="options.apollo.note"></p>
						<p class="mt-0.5 text-ink-muted" x-show="options.apollo.budget">Limit organizacji na dziś: pozostało <strong x-text="options.apollo.budget ? options.apollo.budget.org_pages_left_today : ''"></strong> stron.</p>
					</div>
				</template>
				<template x-if="formError"><p class="rounded-lg bg-danger-soft text-danger px-3 py-2 text-[12px] font-semibold" x-text="formError"></p></template>
					</div>
				<button type="submit" class="ubr-btn-primary w-full h-11" :disabled="starting || !options.loaded">
					<svg x-show="starting" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
					<span x-text="starting ? 'Uruchamianie…' : 'Uruchom Discovery'"></span>
				</button>
				</div>
			</div>
		</form>

		{{-- 2. Status wyszukiwania --}}
		<template x-if="!search">
			<div class="ubr-card p-8 text-center">
				<div class="mx-auto grid place-items-center size-14 rounded-2xl bg-gold-soft text-gold-deep mb-4">@include('ubr.partials.icon', ['name' => 'search', 'class' => 'size-6'])</div>
				<h2 class="font-extrabold text-[18px]">Odkryj polskie firmy</h2>
				<p class="text-[13px] text-ink-muted mt-1 max-w-[460px] mx-auto">Ustaw kryteria powyżej i uruchom wyszukiwanie albo wybierz wcześniejsze z historii. Zadanie działa w tle — możesz zamknąć stronę i wrócić później.</p>
			</div>
		</template>

			<template x-if="search">
			<div class="ubr-card p-5">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div class="min-w-0">
							<div class="flex items-center gap-2 flex-wrap">
								<span :class="$store.ubr.statusBadge(search.status)" x-text="$store.ubr.statusLabel(search.status)"></span>
								<span class="text-[12px] text-ink-muted" x-text="'#' + search.id + ' · ' + $store.ubr.date(search.created_at)"></span>
							</div>
							<h2 class="font-extrabold text-[16px] mt-1.5 break-words" x-text="search.label"></h2>
						</div>
						<div class="flex gap-2">
							<button type="button" class="ubr-btn-danger ubr-btn-sm" x-show="isActive" @click="cancel()" :disabled="cancelling">Anuluj</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="!isActive" @click="retry()" :disabled="starting">@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-3.5']) Ponów</button>
						</div>
					</div>

					<div class="mt-4">
						<div class="h-2 rounded-full bg-canvas overflow-hidden">
							<div class="h-full rounded-full transition-all duration-700" :class="search.status === 'failed' ? 'bg-danger' : (search.status === 'completed' ? 'bg-money' : 'bg-gold')" :style="'width:' + Math.max(3, search.progress) + '%'"></div>
						</div>
						<div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-[12px] text-ink-muted">
							<span x-text="phaseLabel"></span>
							<span><strong class="text-ink tabular-nums" x-text="search.results_count"></strong> firm</span>
							<span><strong class="text-ink tabular-nums" x-text="search.new_count"></strong> nowych</span>
							<span><strong class="text-ink tabular-nums" x-text="search.duplicate_count"></strong> już w bazie</span>
							<span><strong class="text-ink tabular-nums" x-text="search.with_domain_count"></strong> z domeną</span>
							<span x-show="search.returned"><strong class="text-ink tabular-nums" x-text="search.returned"></strong> zwróconych przez źródła</span>
						</div>
					</div>

					{{-- Wyjaśnienie: nigdy 0 wyników bez powodu --}}
					<template x-if="search.explanation && search.explanation.length && search.results_count === 0 && !isActive">
						<div class="mt-3 rounded-xl bg-gold-soft text-gold-deep px-4 py-3 text-[12px] leading-snug">
							<div class="font-bold text-[13px] mb-1">Dlaczego brak wyników?</div>
							<ul class="list-disc pl-4 space-y-0.5"><template x-for="(line, i) in search.explanation" :key="i"><li x-text="line"></li></template></ul>
						</div>
					</template>
					<template x-if="search.rejected_label && search.results_count > 0">
						<p class="mt-2 text-[12px] text-ink-muted"><strong class="text-ink-soft">Odrzucone przez aplikację:</strong> <span x-text="search.rejected_label"></span></p>
					</template>

					{{-- Weryfikacja (osobna kolejka w tle) --}}
					<template x-if="search.diagnostics && search.results_count > 0">
						<div class="mt-3 rounded-xl border border-line px-4 py-3">
							<div class="flex flex-wrap items-center justify-between gap-2">
								<div class="text-[12px] font-bold text-ink-soft flex items-center gap-2">
									Weryfikacja w rejestrach i domen
									<span x-show="verifying" class="ubr-badge-uncertain"><svg class="size-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg> w toku</span>
								</div>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="canVerify && !verifying" @click="verifyAll()" :disabled="verifyBusy">@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-3.5']) Zweryfikuj firmy</button>
							</div>
							<div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-ink-muted">
								<span><strong class="text-money tabular-nums" x-text="search.diagnostics.results.v_verified"></strong> zweryfikowanych</span>
								<span><strong class="text-gold-deep tabular-nums" x-text="search.diagnostics.results.v_uncertain"></strong> niepewnych</span>
								<span><strong class="text-danger tabular-nums" x-text="search.diagnostics.results.v_inactive + search.diagnostics.results.v_suspended"></strong> nieaktywnych / zawieszonych</span>
								<span><strong class="text-ink tabular-nums" x-text="search.diagnostics.results.v_pending"></strong> czeka</span>
								<span>· domeny: <strong class="text-money tabular-nums" x-text="search.diagnostics.results.d_confirmed"></strong> potwierdzonych, <strong class="text-danger tabular-nums" x-text="search.diagnostics.results.d_mismatch"></strong> niezgodnych</span>
							</div>
							<p class="mt-1.5 text-[11px] text-ink-muted">Bezpłatne rejestry: KRS, biała lista VAT (CEIDG / GUS po skonfigurowaniu kluczy). Wyszukiwanie nie czeka na weryfikację — statusy uzupełniają się w tle.</p>
						</div>
					</template>

					<template x-if="search.queue_warning">
						<div class="mt-3 rounded-lg bg-gold-soft text-gold-deep px-3 py-2 text-[12px]" x-text="search.queue_warning"></div>
					</template>

					<template x-if="search.error">
						<div class="mt-3 rounded-lg bg-danger-soft text-danger px-3 py-2 text-[12px]"><strong>Problemy ze źródłami:</strong> <span x-text="search.error"></span></div>
					</template>

					<details class="mt-3 group" x-show="search.events && search.events.length">
						<summary class="cursor-pointer text-[12px] font-semibold text-ink-muted list-none inline-flex items-center gap-1">
							@include('ubr.partials.icon', ['name' => 'chevron', 'class' => 'size-3.5 transition group-open:rotate-90'])
							Dziennik zadania
						</summary>
						<ol class="mt-2 space-y-1 text-[12px] font-mono bg-canvas rounded-lg p-3 max-h-48 overflow-y-auto">
							<template x-for="(e, i) in search.events" :key="i">
								<li :class="e.level === 'error' ? 'text-danger' : (e.level === 'warning' ? 'text-gold-deep' : 'text-ink-soft')"><span class="text-ink-muted" x-text="e.created_at.slice(11, 19)"></span> <span x-text="e.message"></span></li>
							</template>
						</ol>
					</details>
				</div>
			</template>

		{{-- 3. Filtry wyników (zawijają się do kolejnych rzędów — bez przewijania poziomego) --}}
		<template x-if="search">
			<section class="ubr-card px-4 sm:px-5 py-3" aria-label="Filtry i sortowanie wyników">
				<div class="flex flex-wrap items-center gap-x-4 gap-y-2">
					<h2 class="font-extrabold">Wyniki <span class="text-ink-muted font-semibold" x-text="'(' + results.total + ')'"></span></h2>
						<div class="flex flex-wrap items-center gap-2">
							<select class="ubr-input h-8 text-[12px] w-auto" x-model="resultFilter" @change="loadResults(1)" aria-label="Filtr wyników">
								<option value="all">Wszystkie</option>
								<option value="relevant">Trafne (≥ 60)</option>
								<option value="verified">Zweryfikowane w rejestrze</option>
								<option value="location_confirmed">Lokalizacja znana</option>
								<option value="location_unknown">Lokalizacja tylko z filtra / brak</option>
								<option value="with_domain">Z domeną</option>
								<option value="problems">Problemy (nieaktywne, zła domena, inna lokalizacja)</option>
								<option value="not_analyzed">Analiza: nieanalizowane</option>
								<option value="analyzed">Analiza: gotowe / częściowe</option>
								<option value="analysis_active">Analiza: w kolejce / w toku</option>
								<option value="analysis_failed">Analiza: błąd</option>
								<option value="opportunity_high">Opportunity ≥ 60</option>
								<option value="contacts_found">Decydenci: znaleziony Top 1</option>
								<option value="contacts_none">Decydenci: brak wiarygodnych osób</option>
								<option value="contacts_active">Decydenci: w kolejce / w toku</option>
							</select>
							<select class="ubr-input h-8 text-[12px] w-auto" x-model="analysisFilters.min_opportunity" @change="loadResults(1)" aria-label="Minimalny Opportunity Score">
								<option value="">Opportunity: dowolny</option>
								<option value="40">Opportunity ≥ 40</option>
								<option value="60">Opportunity ≥ 60</option>
								<option value="80">Opportunity ≥ 80</option>
							</select>
							<select class="ubr-input h-8 text-[12px] w-auto" x-model="analysisFilters.confidence" @change="loadResults(1)" aria-label="Pewność analizy">
								<option value="">Pewność: dowolna</option>
								<option value="high">Pewność wysoka</option>
								<option value="medium">Pewność średnia</option>
								<option value="low">Pewność niska</option>
							</select>
							<select class="ubr-input h-8 text-[12px] w-auto" x-model="analysisFilters.min_findings" @change="loadResults(1)" aria-label="Liczba problemów">
								<option value="">Problemy: dowolnie</option>
								<option value="3">≥ 3 problemy</option>
								<option value="6">≥ 6 problemów</option>
								<option value="10">≥ 10 problemów</option>
							</select>
							<details class="relative">
								<summary class="ubr-input h-8 text-[12px] w-auto inline-flex items-center gap-1 cursor-pointer list-none" :class="Object.values(contactFilters).some(Boolean) ? 'border-ink' : ''">Decydenci: filtry<span x-show="Object.values(contactFilters).some(Boolean)" class="font-bold">•</span></summary>
								<div class="absolute right-0 z-20 mt-1 w-64 rounded-xl border border-line bg-white shadow-[var(--shadow-pop)] p-3 space-y-2">
									<select class="ubr-input h-8 text-[12px] w-full" x-model="contactFilters.has_contact" @change="loadResults(1)" aria-label="Ma decydenta">
										<option value="">Decydent: dowolnie</option>
										<option value="yes">Ma decydenta (Top 1)</option>
										<option value="no">Brak decydenta (wyszukano)</option>
										<option value="not_researched">Nie wyszukiwano</option>
									</select>
									<select class="ubr-input h-8 text-[12px] w-full" x-model="contactFilters.min_contact_score" @change="loadResults(1)" aria-label="Minimalny Contact Priority Score">
										<option value="">Contact Priority: dowolny</option>
										<option value="50">Contact Priority ≥ 50</option>
										<option value="70">Contact Priority ≥ 70</option>
										<option value="85">Contact Priority ≥ 85</option>
									</select>
									<select class="ubr-input h-8 text-[12px] w-full" x-model="contactFilters.contact_confidence" @change="loadResults(1)" aria-label="Pewność danych Top 1">
										<option value="">Pewność Top 1: dowolna</option>
										<option value="high">Wysoka</option>
										<option value="medium">Średnia</option>
										<option value="low">Niska</option>
									</select>
									<select class="ubr-input h-8 text-[12px] w-full" x-model="contactFilters.contact_role" @change="loadResults(1)" aria-label="Stanowisko Top 1">
										<option value="">Stanowisko Top 1: dowolne</option>
										<option value="leadership">Właściciel / prezes / dyrektor generalny</option>
										<option value="marketing">Marketing / digital</option>
										<option value="board">Zarząd / prokurent</option>
									</select>
									<select class="ubr-input h-8 text-[12px] w-full" x-model="contactFilters.min_contacts" @change="loadResults(1)" aria-label="Liczba kontaktów">
										<option value="">Liczba kontaktów: dowolna</option>
										<option value="2">≥ 2 kontakty</option>
										<option value="3">≥ 3 kontakty</option>
									</select>
								</div>
							</details>
							<details class="relative">
								<summary class="ubr-input h-8 text-[12px] w-auto inline-flex items-center gap-1 cursor-pointer list-none" :class="Object.values(leadFilters).some(Boolean) ? 'border-ink' : ''">Lead: filtry<span x-show="Object.values(leadFilters).some(Boolean)" class="font-bold">•</span></summary>
								<div class="absolute right-0 z-20 mt-1 w-64 rounded-xl border border-line bg-white shadow-[var(--shadow-pop)] p-3 space-y-2">
									<select class="ubr-input h-8 text-[12px] w-full" x-model="leadFilters.min_lead" @change="loadResults(1)" aria-label="Minimalny Lead Potential">
										<option value="">Lead Potential: dowolny</option><option value="40">≥ 40</option><option value="55">≥ 55</option><option value="70">≥ 70</option>
									</select>
									<select class="ubr-input h-8 text-[12px] w-full" x-model="leadFilters.lead_confidence" @change="loadResults(1)" aria-label="Pewność oceny">
										<option value="">Pewność: dowolna</option><option value="high">HIGH</option><option value="medium">MEDIUM</option><option value="low">LOW</option>
									</select>
									<select class="ubr-input h-8 text-[12px] w-full" x-model="leadFilters.redesign" @change="loadResults(1)" aria-label="Redesign Opportunity">
										<option value="">Redesign: dowolnie</option><option value="strong">STRONG</option><option value="moderate">MODERATE</option><option value="weak">WEAK</option><option value="insufficient_data">INSUFFICIENT DATA</option>
									</select>
									<select class="ubr-input h-8 text-[12px] w-full" x-model="leadFilters.recommendation" @change="loadResults(1)" aria-label="Rekomendacja">
										<option value="">Rekomendacja: dowolna</option><option value="contact_now">Contact now</option><option value="review_website">Review website</option><option value="find_decision_maker">Find decision maker</option><option value="research_further">Research further</option><option value="verify_company">Verify company</option><option value="low_priority">Low priority</option><option value="do_not_contact">Do not contact</option>
									</select>
									<label class="flex items-center gap-2 text-[12px]"><input type="checkbox" class="ubr-checkbox" :checked="!!leadFilters.no_blockers" @change="leadFilters.no_blockers = $event.target.checked ? '1' : ''; loadResults(1)"> Tylko bez twardych przeszkód</label>
								</div>
							</details>
							<select class="ubr-input h-8 text-[12px] w-auto" x-model="resultSort" @change="loadResults(1)" aria-label="Sortowanie">
								<option value="relevance">Sortuj: trafność</option>
								<option value="readiness">Sortuj: gotowość do telefonu</option>
								<option value="size">Sortuj: wielkość firmy</option>
								<option value="last_contact">Sortuj: ostatni kontakt</option>
								<option value="last_analysis">Sortuj: ostatnia analiza</option>
								<option value="name">Sortuj: nazwa A–Z</option>
								<option value="rank">Sortuj: Smart Ranking</option>
								<option value="lead">Sortuj: Lead Potential</option>
								<option value="redesign">Sortuj: Redesign Opportunity</option>
								<option value="opportunity">Sortuj: Opportunity Score</option>
								<option value="contact_priority">Sortuj: Contact Priority</option>
								<option value="position">Sortuj: kolejność źródła</option>
							</select>
						</div>
				</div>
				{{-- Cold Calling: telefony, gotowość, opiekun, wielkość, CRM (Prompt 10) --}}
				<div class="mt-2 pt-2 border-t border-line flex flex-wrap items-center gap-2" aria-label="Filtry Cold Calling">
					@include('ubr.partials.call.filters', ['model' => 'callFilters', 'reload' => 'loadResults(1)'])
				</div>
			</section>
		</template>

		{{-- 4. Wyniki --}}
		<template x-if="search">
			<section class="ubr-card overflow-hidden" aria-label="Wyniki wyszukiwania">
					{{-- Analiza zbiorcza: świadomie uruchamiana przez użytkownika (bez automatycznej analizy wszystkich wyników). --}}
					<div class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 bg-gold-soft/60 border-b border-line text-[12px]" x-show="(canAnalyze || canFindContacts) && selected.length" x-cloak>
						<span><strong x-text="selected.length"></strong> zaznaczonych firm</span>
						<span class="flex gap-2">
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="selected = []">Odznacz</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="canFindContacts" @click="findSelected()" :disabled="contactsBusy">@include('ubr.partials.icon', ['name' => 'users', 'class' => 'size-3.5']) Znajdź decydentów</button>
							<button type="button" class="ubr-btn-primary ubr-btn-sm" x-show="canAnalyze" @click="analyzeSelected()" :disabled="analyzeBusy">@include('ubr.partials.icon', ['name' => 'sparkle', 'class' => 'size-3.5']) Analizuj zaznaczone</button>
						</span>
					</div>

					<template x-if="results.loading && results.items.length === 0">
						<div class="p-5 space-y-2"><div class="ubr-skeleton h-12"></div><div class="ubr-skeleton h-12"></div><div class="ubr-skeleton h-12"></div></div>
					</template>

					<template x-if="!results.loading && results.items.length === 0">
						<p class="py-12 text-center text-[13px] text-ink-muted" x-text="isActive ? 'Szukam… wyniki pojawią się tutaj.' : 'Brak wyników dla tych kryteriów.'"></p>
					</template>

				{{--
					Cold Calling Workspace (Prompt 10): ≥ 1280 px tabela dopasowana do szerokości (table-fixed, zawijanie, bez przewijania
					poziomego); poniżej 1280 px karty. Kolumny: Firma, Wielkość, Strona WWW, Ocena, Decydent, Telefony, Opiekun / CRM,
					Status kontaktu, Akcje. Szczegóły ocen (Relevance, Opportunity, decydenci) — rozwijane pod wierszem.
				--}}
				<table class="ubr-table ubr-call-table table-fixed hidden xl:table" x-show="results.items.length">
					<colgroup>
						{{-- Firma = reszta (≈ 21% − 40 px): wszystkie pozostałe kolumny procentowo, żeby przy 1280 px nazwa firmy miała ≥ 150 px. --}}
						<col class="w-10">
						<col>
						<col class="w-[7%]">
						<col class="w-[9%]">
						<col class="w-[9%]">
						<col class="w-[12%]">
						<col class="w-[12%]">
						<col class="w-[10%]">
						<col class="w-[9%]">
						<col class="w-[11%]">
					</colgroup>
					<thead>
						<tr>
							<th class="!px-3"><input type="checkbox" class="ubr-checkbox" x-show="canAnalyze || canFindContacts" :checked="allSelected" @change="toggleAll()" aria-label="Zaznacz wszystkie firmy na stronie"></th>
							<th>Firma</th><th>Wielkość</th><th>Strona WWW</th><th>Ocena</th><th>Decydent</th><th>Telefony</th><th>Opiekun / CRM</th><th>Status kontaktu</th><th class="text-right">Akcje</th>
						</tr>
					</thead>
					<template x-for="c in results.items" :key="c.id">
						<tbody x-data="{ more: false }">
							<tr>
								<td class="!px-3"><input type="checkbox" class="ubr-checkbox" x-show="canAnalyze || canFindContacts" :value="c.id" x-model.number="selected" :aria-label="'Zaznacz ' + c.name"></td>
								<td class="min-w-0">
									@include('ubr.partials.discover-company-cell', ['var' => 'c', 'hideSize' => true])
									<div class="mt-1 flex flex-wrap items-center gap-1 text-[11px]">
										<span class="!whitespace-normal" :class="$store.ubr.verificationBadge(c.verification.status)" x-text="c.verification.label" :title="c.verification.source || ''"></span>
										<span class="text-ink-muted [overflow-wrap:anywhere]" x-text="[c.city, c.region].filter(Boolean).join(', ') || (c.location.match === 'filtered' ? filterLocationLabel : 'lokalizacja: brak danych')"></span>
									</div>
								</td>
								<td class="min-w-0">@include('ubr.partials.call.size-cell', ['var' => 'c'])</td>
								<td class="min-w-0">@include('ubr.partials.call.website-cell', ['var' => 'c'])</td>
								<td class="min-w-0">
									@include('ubr.partials.lead-cell', ['var' => 'c'])
									<button type="button" class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-ink-soft hover:text-ink" @click="more = !more" :aria-expanded="more" :aria-label="'Szczegóły ocen: ' + c.name">
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3 transition" :class="more ? 'rotate-90' : ''" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
										Szczegóły
									</button>
								</td>
								<td class="min-w-0">@include('ubr.partials.call.dm-cell', ['var' => 'c'])</td>
								<td class="min-w-0">@include('ubr.partials.call.phones-cell', ['var' => 'c'])</td>
								<td class="min-w-0">@include('ubr.partials.call.owner-cell', ['var' => 'c'])</td>
								<td class="min-w-0">@include('ubr.partials.call.status-cell', ['var' => 'c'])</td>
								<td class="text-right !px-2">@include('ubr.partials.call.quick-actions', ['var' => 'c', 'save' => true, 'searchExpr' => 'search.id'])</td>
							</tr>
							<tr x-show="more" x-cloak class="bg-canvas/50">
								<td colspan="10" class="!py-3">@include('ubr.partials.discover-row-details', ['var' => 'c'])</td>
							</tr>
						</tbody>
					</template>
				</table>

				{{-- < 1280 px: karty (te same dane i akcje) --}}
				<ul class="xl:hidden divide-y divide-line" x-show="results.items.length" aria-label="Wyniki (karty)">
					<template x-for="c in results.items" :key="c.id">
						<li class="p-4" x-data="{ more: false }">
							@include('ubr.partials.call.card', ['var' => 'c', 'save' => true, 'searchExpr' => 'search.id', 'select' => 'canAnalyze || canFindContacts'])
							<button type="button" class="mt-3 inline-flex items-center gap-1 h-9 text-[12px] font-semibold text-ink-soft hover:text-ink" @click="more = !more" :aria-expanded="more">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3.5 transition" :class="more ? 'rotate-90' : ''" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
								<span x-text="more ? 'Mniej danych' : 'Więcej danych (oceny, weryfikacja, decydenci)'"></span>
							</button>
							<div x-show="more" x-cloak class="mt-2 rounded-xl bg-canvas/60 p-3">
								<div class="text-[12px] mb-3">
									<span class="font-semibold">Weryfikacja:</span> <span :class="$store.ubr.verificationBadge(c.verification.status)" x-text="c.verification.label"></span>
									<span class="ml-1" :class="$store.ubr.locationBadge(c.location.match || c.location.confidence)" x-text="$store.ubr.locationLabel(c.location.match || c.location.confidence)"></span>
									<div class="text-[11px] text-ink-muted mt-0.5" x-show="c.verification.source" x-text="'Źródło weryfikacji: ' + c.verification.source"></div>
								</div>
								@include('ubr.partials.discover-row-details', ['var' => 'c'])
							</div>
						</li>
					</template>
				</ul>

				@include('ubr.partials.pagination', ['state' => 'results', 'load' => 'loadResults'])
			</section>
		</template>
	</div>
@endsection
