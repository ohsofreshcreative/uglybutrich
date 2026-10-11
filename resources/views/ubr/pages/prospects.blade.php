@extends('ubr.layouts.app')

@section('actions')
	@include('ubr.partials.view-switch', ['active' => 'list'])
@endsection

@section('content')
	<div x-data="ubrProspects" class="w-full min-w-0 space-y-4">
		{{-- Filtry: najczęstsze widoczne, pozostałe po „Więcej filtrów”; Cold Calling w osobnym rzędzie. Działają na całym zbiorze. --}}
		<div class="ubr-card p-4 space-y-3">
			<div class="flex flex-wrap items-end gap-3">
			<div class="flex-1 min-w-[220px]">
				<label class="ubr-label" for="p-search">Szukaj</label>
				<input id="p-search" class="ubr-input" type="search" placeholder="Nazwa, domena, miasto, NIP…" x-model="filters.search" @input.debounce.350ms="load(1)">
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-pst">Status pipeline</label>
				<select id="p-pst" class="ubr-input" x-model="filters.pipeline_status" @change="load(1)">
					<option value="">Wszystkie</option>
					<template x-for="s in statuses" :key="s.key"><option :value="s.key" x-text="s.label"></option></template>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-own">Właściciel</label>
				<select id="p-own" class="ubr-input" x-model="filters.owner" @change="load(1)">
					<option value="">Wszyscy</option><option value="me">Moje</option><option value="none">Bez właściciela</option>
					<template x-for="m in members" :key="m.id"><option :value="String(m.id)" x-text="m.name"></option></template>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-per">Wyników na stronie:</label>
				{{-- Paginacja po stronie serwera; wybór zapamiętany lokalnie osobno dla Prospects --}}
				<select id="p-per" class="ubr-input" data-per-page @change="setPerPage($event.target.value)">
					<option value="10" :selected="list.per_page === 10">10</option>
					<option value="25" :selected="list.per_page === 25">25</option>
					<option value="50" :selected="list.per_page === 50">50</option>
					<option value="100" :selected="list.per_page === 100">100</option>
				</select>
			</div>
				<button type="button" class="ubr-btn-ghost h-10" @click="moreFilters = !moreFilters" :aria-expanded="moreFilters" aria-controls="p-more-filters">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3.5 transition" :class="moreFilters ? 'rotate-180' : ''" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
					<span x-text="moreFilters ? 'Mniej filtrów' : 'Więcej filtrów'"></span>
				</button>
				<div class="w-full min-[480px]:w-[230px]">
					<label class="ubr-label" for="p-sort">Sortowanie</label>
					<div class="flex gap-1">
						<select id="p-sort" class="ubr-input" x-model="sort" @change="load(1)">
							<option value="readiness">Gotowość do telefonu</option>
							<option value="rank">Lead (ranking)</option>
							<option value="size">Wielkość firmy</option>
							<option value="last_contact">Ostatni kontakt</option>
							<option value="last_analysis">Ostatnia analiza</option>
							<option value="name">Nazwa A–Z</option>
							<option value="relevance">Trafność</option>
							<option value="opportunity">Opportunity</option>
							<option value="contact_priority">Contact Priority</option>
							<option value="pipeline_status">Status pipeline</option>
							<option value="priority">Priorytet</option>
							<option value="next_action_at">Następne działanie</option>
							<option value="created_at">Data zapisu</option>
						</select>
						<button type="button" class="ubr-btn-ghost h-10 px-3 shrink-0" @click="dir = dir === 'asc' ? 'desc' : 'asc'; load(1)" :aria-label="dir === 'asc' ? 'Kierunek: rosnąco' : 'Kierunek: malejąco'" x-text="dir === 'asc' ? '↑' : '↓'"></button>
					</div>
				</div>
			</div>
			<div id="p-more-filters" class="flex flex-wrap items-end gap-3" x-show="moreFilters" x-cloak>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-region">Województwo</label>
				<select id="p-region" class="ubr-input" x-model="filters.region" @change="load(1)">
					<option value="">Wszystkie</option>
					<template x-for="r in regions" :key="r"><option :value="r" x-text="r"></option></template>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-domain">Domena</label>
				<select id="p-domain" class="ubr-input" x-model="filters.has_domain" @change="load(1)">
					<option value="">Dowolnie</option><option value="yes">Z domeną</option><option value="no">Bez domeny</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-verif">Status firmy</label>
				<select id="p-verif" class="ubr-input" x-model="filters.verification" @change="load(1)">
					<option value="">Wszystkie</option><option value="verified">Zweryfikowane</option><option value="uncertain">Niepewne</option><option value="pending">Do weryfikacji</option><option value="suspended">Zawieszone</option><option value="inactive">Nieaktywne</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-dstat">Status domeny</label>
				<select id="p-dstat" class="ubr-input" x-model="filters.domain_status" @change="load(1)">
					<option value="">Wszystkie</option><option value="confirmed">Potwierdzona</option><option value="uncertain">Niepewna</option><option value="mismatch">Niezgodna</option><option value="unavailable">Niedostępna</option><option value="unchecked">Niesprawdzona</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-rel">Min. trafność</label>
				<select id="p-rel" class="ubr-input" x-model="filters.min_relevance" @change="load(1)">
					<option value="">Dowolna</option><option value="40">≥ 40</option><option value="60">≥ 60</option><option value="80">≥ 80</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-opp">Potencjał</label>
				<select id="p-opp" class="ubr-input" x-model="filters.min_opportunity" @change="load(1)">
					<option value="">Dowolny</option><option value="40">Opportunity ≥ 40</option><option value="60">Opportunity ≥ 60</option><option value="80">Opportunity ≥ 80</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-an">Analiza strony</label>
				<select id="p-an" class="ubr-input" x-model="filters.analysis" @change="load(1)">
					<option value="">Wszystkie</option><option value="analyzed">Przeanalizowane</option><option value="not_analyzed">Nieanalizowane</option><option value="review">Do ręcznego sprawdzenia</option><option value="failed">Błąd analizy</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-dm">Decydent</label>
				<select id="p-dm" class="ubr-input" x-model="filters.has_contact" @change="load(1)">
					<option value="">Wszystkie</option><option value="yes">Ma decydenta</option><option value="no">Nie ma decydenta</option><option value="not_researched">Nie wyszukiwano</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-dmc">Pewność decydenta</label>
				<select id="p-dmc" class="ubr-input" x-model="filters.contact_confidence" @change="load(1)">
					<option value="">Dowolna</option><option value="high">Wysoka</option><option value="medium">Średnia</option><option value="low">Niska</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-dms">Contact Priority</label>
				<select id="p-dms" class="ubr-input" x-model="filters.min_contact_score" @change="load(1)">
					<option value="">Dowolny</option><option value="50">≥ 50</option><option value="70">≥ 70</option><option value="85">≥ 85</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-prio">Priorytet</label>
				<select id="p-prio" class="ubr-input" x-model="filters.priority" @change="load(1)">
					<option value="">Dowolny</option><option value="urgent">Pilny</option><option value="high">Wysoki</option><option value="medium">Średni</option><option value="low">Niski</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-arch">Archiwum</label>
				<select id="p-arch" class="ubr-input" x-model="filters.archived" @change="selected = []; load(1)">
					<option value="">Aktywne</option><option value="1">Zarchiwizowane</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-lead">Lead Potential</label>
				<select id="p-lead" class="ubr-input" x-model="filters.min_lead" @change="load(1)">
					<option value="">Dowolny</option><option value="40">≥ 40</option><option value="55">≥ 55</option><option value="70">≥ 70</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-lconf">Pewność oceny</label>
				<select id="p-lconf" class="ubr-input" x-model="filters.lead_confidence" @change="load(1)">
					<option value="">Dowolna</option><option value="high">HIGH</option><option value="medium">MEDIUM</option><option value="low">LOW</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-rec">Rekomendacja</label>
				<select id="p-rec" class="ubr-input" x-model="filters.recommendation" @change="load(1)">
					<option value="">Dowolna</option><option value="contact_now">Contact now</option><option value="review_website">Review website</option><option value="find_decision_maker">Find decision maker</option><option value="research_further">Research further</option><option value="verify_company">Verify company</option><option value="low_priority">Low priority</option><option value="do_not_contact">Do not contact</option>
				</select>
			</div>
			<div class="w-full min-[480px]:w-[170px]">
				<label class="ubr-label" for="p-red">Redesign</label>
				<select id="p-red" class="ubr-input" x-model="filters.redesign" @change="load(1)">
					<option value="">Dowolnie</option><option value="strong">STRONG</option><option value="moderate">MODERATE</option><option value="weak">WEAK</option><option value="insufficient_data">INSUFFICIENT DATA</option>
				</select>
			</div>
			<label class="flex items-center gap-2 text-[12px] h-10"><input type="checkbox" class="ubr-checkbox" :checked="!!filters.no_blockers" @change="filters.no_blockers = $event.target.checked ? '1' : ''; load(1)"> Bez twardych przeszkód</label>
			</div>
			<div class="pt-3 border-t border-line flex flex-wrap items-center gap-2" aria-label="Filtry Cold Calling">
				@include('ubr.partials.call.filters', ['model' => 'callFilters', 'reload' => 'load(1)'])
			</div>
		</div>

		{{-- Akcje zbiorcze: eksport do ClickUp (w tle, maks. 20 naraz) --}}
		<div class="ubr-card px-4 py-3 flex flex-wrap items-center gap-3" x-show="selected.length" x-cloak>
			<span class="text-[13px] font-semibold">Zaznaczono: <span x-text="selected.length"></span></span>
			<button type="button" class="ubr-btn-primary ubr-btn-sm" x-show="canExport" @click="exportSelected()" :disabled="exportBusy || selected.length > 20">Eksportuj do ClickUp</button>
			<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="$store.ubr.can('analysis.run')" @click="$store.ubr.analyze(selected); selected = []">Analizuj strony</button>
			<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="$store.ubr.can('contacts.run')" @click="$store.ubr.findDecisionMakers(selected); selected = []">Znajdź decydentów</button>
			<span class="text-[12px] text-gold-deep" x-show="selected.length > 20">Eksport zbiorczy: maks. 20 firm naraz.</span>
			<button type="button" class="ubr-btn-ghost ubr-btn-sm ml-auto" @click="selected = []">Odznacz</button>
		</div>

		<div class="ubr-card overflow-hidden">
			<template x-if="error"><div class="p-4 text-danger font-semibold" x-text="error"></div></template>

			<template x-if="list.loading && list.items.length === 0">
				<div class="p-5 space-y-2"><div class="ubr-skeleton h-12"></div><div class="ubr-skeleton h-12"></div><div class="ubr-skeleton h-12"></div></div>
			</template>

			<template x-if="!list.loading && list.items.length === 0">
				<div class="py-14 text-center">
					<div class="mx-auto grid place-items-center size-12 rounded-2xl bg-gold-soft text-gold-deep mb-3">@include('ubr.partials.icon', ['name' => 'bookmark', 'class' => 'size-5'])</div>
					<p class="font-bold">Brak prospectów</p>
					<p class="text-[13px] text-ink-muted mt-1" x-text="hasFilters ? 'Żadna firma nie pasuje do filtrów.' : 'Zapisuj firmy z ekranu Discover.'"></p>
				</div>
			</template>

			{{-- ≥ 1280 px: tabela dopasowana do szerokości (bez przewijania poziomego); poniżej karty — wspólne komponenty z Discover. --}}
			<div x-show="list.items.length" :class="list.loading ? 'opacity-60' : ''">
				<table class="ubr-table ubr-call-table table-fixed hidden xl:table">
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
							<th class="!px-3"><input type="checkbox" class="ubr-checkbox" :checked="allSelected" @change="toggleAll()" aria-label="Zaznacz wszystkie na stronie"></th>
							@foreach (['name' => 'Firma', 'size' => 'Wielkość', null => 'Strona WWW', 'rank' => 'Ocena', 'contact_priority' => 'Decydent', '_phones' => 'Telefony', 'last_contact' => 'Opiekun / CRM', 'readiness' => 'Status kontaktu'] as $key => $label)
								<th>
									@if ($key && $key !== '_phones')
										<button type="button" class="inline-flex items-center gap-1 uppercase hover:text-ink text-left" @click="sortBy('{{ $key }}')" :aria-label="'Sortuj: {{ $label }}'">
											{{ $label }}<span x-show="sort === '{{ $key }}'" x-text="dir === 'asc' ? '↑' : '↓'"></span>
										</button>
									@else
										{{ $label }}
									@endif
								</th>
							@endforeach
							<th class="text-right">Akcje</th>
						</tr>
					</thead>
					<tbody>
						<template x-for="c in list.items" :key="c.prospect_id">
							<tr>
								<td class="!px-3"><input type="checkbox" class="ubr-checkbox" :value="c.id" x-model.number="selected" :aria-label="'Zaznacz ' + c.name"></td>
								<td class="min-w-0">
									@include('ubr.partials.discover-company-cell', ['var' => 'c', 'hideSize' => true])
									<div class="mt-1 flex flex-wrap items-center gap-1 text-[11px]">
										<button type="button" class="ubr-badge-declared hover:underline" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany(c.id)" x-text="c.pipeline ? c.pipeline.label : '—'" title="Otwórz sekcję Sprzedaż"></button>
										<span :class="$store.ubr.priorityBadge(c.pipeline?.priority)" x-text="c.pipeline?.priority_label || ''" x-show="c.pipeline?.priority_label"></span>
										<span :class="$store.ubr.verificationBadge(c.verification.status)" x-text="c.verification.label"></span>
									</div>
									<div class="text-[11px] mt-0.5" x-show="c.pipeline?.next_action_at" :class="c.pipeline?.overdue ? 'text-danger font-bold' : 'text-ink-muted'" x-text="(c.pipeline?.overdue ? 'Zaległe: ' : 'Następne działanie: ') + $store.ubr.date(c.pipeline?.next_action_at)"></div>
								</td>
								<td class="min-w-0">@include('ubr.partials.call.size-cell', ['var' => 'c'])</td>
								<td class="min-w-0">@include('ubr.partials.call.website-cell', ['var' => 'c'])</td>
								<td class="min-w-0">
									@include('ubr.partials.lead-cell', ['var' => 'c'])
									<div class="mt-1">@include('ubr.partials.opportunity-cell', ['var' => 'c'])</div>
								</td>
								<td class="min-w-0">@include('ubr.partials.call.dm-cell', ['var' => 'c'])</td>
								<td class="min-w-0">@include('ubr.partials.call.phones-cell', ['var' => 'c'])</td>
								<td class="min-w-0">@include('ubr.partials.call.owner-cell', ['var' => 'c'])</td>
								<td class="min-w-0">@include('ubr.partials.call.status-cell', ['var' => 'c'])</td>
								<td class="text-right !px-2">
									@include('ubr.partials.call.quick-actions', ['var' => 'c'])
									@include('ubr.partials.prospect-row-tail', ['var' => 'c'])
								</td>
							</tr>
						</template>
					</tbody>
				</table>

				<ul class="xl:hidden divide-y divide-line" aria-label="Prospects (karty)">
					<template x-for="c in list.items" :key="c.prospect_id">
						<li class="p-4">
							@include('ubr.partials.call.card', ['var' => 'c', 'select' => 'true'])
							<div class="mt-2 flex flex-wrap items-center gap-1 text-[11px]">
								<button type="button" class="ubr-badge-declared hover:underline" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany(c.id)" x-text="c.pipeline ? c.pipeline.label : '—'"></button>
								<span :class="$store.ubr.priorityBadge(c.pipeline?.priority)" x-text="c.pipeline?.priority_label || ''" x-show="c.pipeline?.priority_label"></span>
								<span class="text-ink-muted" x-show="c.pipeline?.next_action_at" :class="c.pipeline?.overdue ? '!text-danger font-bold' : ''" x-text="(c.pipeline?.overdue ? 'Zaległe: ' : 'Następne działanie: ') + $store.ubr.date(c.pipeline?.next_action_at)"></span>
							</div>
							<div class="mt-2">@include('ubr.partials.prospect-row-tail', ['var' => 'c', 'compact' => true])</div>
						</li>
					</template>
				</ul>
			</div>

			@include('ubr.partials.pagination', ['state' => 'list', 'load' => 'load'])
		</div>
		<p class="text-[12px] text-ink-muted">Archiwizacja usuwa firmę z aktywnej listy, ale zachowuje historię sprzedaży (notatki, aktywności) — można ją przywrócić. Nie usuwa firmy z globalnej bazy ani z list innych organizacji.</p>
	</div>
@endsection
