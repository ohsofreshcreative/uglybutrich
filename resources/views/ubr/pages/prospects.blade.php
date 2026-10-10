@extends('ubr.layouts.app')

@section('actions')
	@include('ubr.partials.view-switch', ['active' => 'list'])
@endsection

@section('content')
	<div x-data="ubrProspects" class="max-w-[1400px] space-y-4">
		<div class="ubr-card p-4 flex flex-wrap items-end gap-3">
			<div class="flex-1 min-w-[220px]">
				<label class="ubr-label" for="p-search">Szukaj</label>
				<input id="p-search" class="ubr-input" type="search" placeholder="Nazwa, domena, miasto, NIP…" x-model="filters.search" @input.debounce.350ms="load(1)">
			</div>
			<div class="w-[180px]">
				<label class="ubr-label" for="p-region">Województwo</label>
				<select id="p-region" class="ubr-input" x-model="filters.region" @change="load(1)">
					<option value="">Wszystkie</option>
					<template x-for="r in regions" :key="r"><option :value="r" x-text="r"></option></template>
				</select>
			</div>
			<div class="w-[160px]">
				<label class="ubr-label" for="p-domain">Domena</label>
				<select id="p-domain" class="ubr-input" x-model="filters.has_domain" @change="load(1)">
					<option value="">Dowolnie</option><option value="yes">Z domeną</option><option value="no">Bez domeny</option>
				</select>
			</div>
			<div class="w-[170px]">
				<label class="ubr-label" for="p-verif">Status firmy</label>
				<select id="p-verif" class="ubr-input" x-model="filters.verification" @change="load(1)">
					<option value="">Wszystkie</option><option value="verified">Zweryfikowane</option><option value="uncertain">Niepewne</option><option value="pending">Do weryfikacji</option><option value="suspended">Zawieszone</option><option value="inactive">Nieaktywne</option>
				</select>
			</div>
			<div class="w-[160px]">
				<label class="ubr-label" for="p-dstat">Status domeny</label>
				<select id="p-dstat" class="ubr-input" x-model="filters.domain_status" @change="load(1)">
					<option value="">Wszystkie</option><option value="confirmed">Potwierdzona</option><option value="uncertain">Niepewna</option><option value="mismatch">Niezgodna</option><option value="unavailable">Niedostępna</option><option value="unchecked">Niesprawdzona</option>
				</select>
			</div>
			<div class="w-[140px]">
				<label class="ubr-label" for="p-rel">Min. trafność</label>
				<select id="p-rel" class="ubr-input" x-model="filters.min_relevance" @change="load(1)">
					<option value="">Dowolna</option><option value="40">≥ 40</option><option value="60">≥ 60</option><option value="80">≥ 80</option>
				</select>
			</div>
			<div class="w-[150px]">
				<label class="ubr-label" for="p-opp">Potencjał</label>
				<select id="p-opp" class="ubr-input" x-model="filters.min_opportunity" @change="load(1)">
					<option value="">Dowolny</option><option value="40">Opportunity ≥ 40</option><option value="60">Opportunity ≥ 60</option><option value="80">Opportunity ≥ 80</option>
				</select>
			</div>
			<div class="w-[190px]">
				<label class="ubr-label" for="p-an">Analiza strony</label>
				<select id="p-an" class="ubr-input" x-model="filters.analysis" @change="load(1)">
					<option value="">Wszystkie</option><option value="analyzed">Przeanalizowane</option><option value="not_analyzed">Nieanalizowane</option><option value="review">Do ręcznego sprawdzenia</option><option value="failed">Błąd analizy</option>
				</select>
			</div>
			<div class="w-[170px]">
				<label class="ubr-label" for="p-dm">Decydent</label>
				<select id="p-dm" class="ubr-input" x-model="filters.has_contact" @change="load(1)">
					<option value="">Wszystkie</option><option value="yes">Ma decydenta</option><option value="no">Nie ma decydenta</option><option value="not_researched">Nie wyszukiwano</option>
				</select>
			</div>
			<div class="w-[150px]">
				<label class="ubr-label" for="p-dmc">Pewność decydenta</label>
				<select id="p-dmc" class="ubr-input" x-model="filters.contact_confidence" @change="load(1)">
					<option value="">Dowolna</option><option value="high">Wysoka</option><option value="medium">Średnia</option><option value="low">Niska</option>
				</select>
			</div>
			<div class="w-[160px]">
				<label class="ubr-label" for="p-dms">Contact Priority</label>
				<select id="p-dms" class="ubr-input" x-model="filters.min_contact_score" @change="load(1)">
					<option value="">Dowolny</option><option value="50">≥ 50</option><option value="70">≥ 70</option><option value="85">≥ 85</option>
				</select>
			</div>
			<div class="w-[170px]">
				<label class="ubr-label" for="p-pst">Status pipeline</label>
				<select id="p-pst" class="ubr-input" x-model="filters.pipeline_status" @change="load(1)">
					<option value="">Wszystkie</option>
					<template x-for="s in statuses" :key="s.key"><option :value="s.key" x-text="s.label"></option></template>
				</select>
			</div>
			<div class="w-[130px]">
				<label class="ubr-label" for="p-prio">Priorytet</label>
				<select id="p-prio" class="ubr-input" x-model="filters.priority" @change="load(1)">
					<option value="">Dowolny</option><option value="urgent">Pilny</option><option value="high">Wysoki</option><option value="medium">Średni</option><option value="low">Niski</option>
				</select>
			</div>
			<div class="w-[160px]">
				<label class="ubr-label" for="p-own">Właściciel</label>
				<select id="p-own" class="ubr-input" x-model="filters.owner" @change="load(1)">
					<option value="">Wszyscy</option><option value="me">Moje</option><option value="none">Bez właściciela</option>
					<template x-for="m in members" :key="m.id"><option :value="String(m.id)" x-text="m.name"></option></template>
				</select>
			</div>
			<div class="w-[150px]">
				<label class="ubr-label" for="p-arch">Archiwum</label>
				<select id="p-arch" class="ubr-input" x-model="filters.archived" @change="selected = []; load(1)">
					<option value="">Aktywne</option><option value="1">Zarchiwizowane</option>
				</select>
			</div>
			<div class="w-[150px]">
				<label class="ubr-label" for="p-lead">Lead Potential</label>
				<select id="p-lead" class="ubr-input" x-model="filters.min_lead" @change="load(1)">
					<option value="">Dowolny</option><option value="40">≥ 40</option><option value="55">≥ 55</option><option value="70">≥ 70</option>
				</select>
			</div>
			<div class="w-[140px]">
				<label class="ubr-label" for="p-lconf">Pewność oceny</label>
				<select id="p-lconf" class="ubr-input" x-model="filters.lead_confidence" @change="load(1)">
					<option value="">Dowolna</option><option value="high">HIGH</option><option value="medium">MEDIUM</option><option value="low">LOW</option>
				</select>
			</div>
			<div class="w-[180px]">
				<label class="ubr-label" for="p-rec">Rekomendacja</label>
				<select id="p-rec" class="ubr-input" x-model="filters.recommendation" @change="load(1)">
					<option value="">Dowolna</option><option value="contact_now">Contact now</option><option value="review_website">Review website</option><option value="find_decision_maker">Find decision maker</option><option value="research_further">Research further</option><option value="verify_company">Verify company</option><option value="low_priority">Low priority</option><option value="do_not_contact">Do not contact</option>
				</select>
			</div>
			<div class="w-[160px]">
				<label class="ubr-label" for="p-red">Redesign</label>
				<select id="p-red" class="ubr-input" x-model="filters.redesign" @change="load(1)">
					<option value="">Dowolnie</option><option value="strong">STRONG</option><option value="moderate">MODERATE</option><option value="weak">WEAK</option><option value="insufficient_data">INSUFFICIENT DATA</option>
				</select>
			</div>
			<label class="flex items-center gap-2 text-[12px] h-10"><input type="checkbox" class="ubr-checkbox" :checked="!!filters.no_blockers" @change="filters.no_blockers = $event.target.checked ? '1' : ''; load(1)"> Bez twardych przeszkód</label>
			<div class="w-[110px]">
				<label class="ubr-label" for="p-per">Na stronę</label>
				<select id="p-per" class="ubr-input" x-model.number="list.per_page" @change="load(1)">
					<option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>
				</select>
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

			<div class="overflow-x-auto" x-show="list.items.length" :class="list.loading ? 'opacity-60' : ''">
				<table class="ubr-table min-w-[2170px]">
					<thead>
						<tr>
							<th class="w-8"><input type="checkbox" class="ubr-checkbox" :checked="allSelected" @change="toggleAll()" aria-label="Zaznacz wszystkie na stronie"></th>
							@foreach (['pipeline_status' => 'Status', 'priority' => 'Priorytet', 'rank' => 'Lead (ranking)', 'opportunity' => 'Opportunity', 'contact_priority' => 'Decydent (Top 1)', 'relevance' => 'Trafność', 'name' => 'Firma', 'verification' => 'Status firmy', 'analyzed_at' => 'Analiza', 'industry' => 'Branża', 'region' => 'Województwo', 'next_action_at' => 'Następne działanie', 'created_at' => 'Zapisano'] as $key => $label)
								<th>
									<button type="button" class="inline-flex items-center gap-1 uppercase hover:text-ink" @click="sortBy('{{ $key }}')">
										{{ $label }}
										<span x-show="sort === '{{ $key }}'" x-text="dir === 'asc' ? '↑' : '↓'"></span>
									</button>
								</th>
							@endforeach
							<th>Właściciel</th>
							<th>ClickUp</th>
							<th class="text-right">Akcje</th>
						</tr>
					</thead>
					<tbody>
						<template x-for="c in list.items" :key="c.prospect_id">
							<tr>
								<td><input type="checkbox" class="ubr-checkbox" :value="c.id" x-model.number="selected" :aria-label="'Zaznacz ' + c.name"></td>
								<td class="whitespace-nowrap text-[12px]">
									<button type="button" class="ubr-badge-declared hover:underline" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany(c.id)" x-text="c.pipeline ? c.pipeline.label : '—'" title="Otwórz sekcję Sprzedaż"></button>
								</td>
								<td class="whitespace-nowrap"><span :class="$store.ubr.priorityBadge(c.pipeline?.priority)" x-text="c.pipeline?.priority_label || '—'"></span></td>
								<td class="whitespace-nowrap">@include('ubr.partials.lead-cell', ['var' => 'c'])</td>
								<td class="whitespace-nowrap">@include('ubr.partials.opportunity-cell', ['var' => 'c'])</td>
								<td>@include('ubr.partials.contacts-cell', ['var' => 'c'])</td>
								<td class="whitespace-nowrap"><span :class="$store.ubr.relevanceClass(c.relevance)" x-text="c.relevance === null ? '—' : c.relevance" title="Najwyższa trafność firmy w wyszukiwaniach organizacji"></span></td>
								<td class="min-w-[240px] max-w-[300px]">
									<button type="button" class="font-bold text-left hover:underline" @click="$store.ubr.openCompany(c.id)" x-text="c.name"></button>
									<div class="flex items-center gap-1.5 mt-0.5">
										<template x-if="c.domain"><a :href="$store.ubr.safeHref(c.website_url)" target="_blank" rel="noopener nofollow" class="text-[12px] text-ink-soft hover:underline" x-text="c.domain"></a></template>
										<span x-show="!c.domain" class="text-[12px] text-ink-muted">brak domeny</span>
										<span x-show="c.domain" :class="$store.ubr.domainBadge(c.domain_status.status)" x-text="c.domain_status.label"></span>
									</div>
									<div class="flex flex-wrap gap-1 mt-1" x-show="c.flags.filter(f => f.blocking).length">
										<template x-for="f in c.flags.filter(f => f.blocking)" :key="f.key"><span class="ubr-badge-danger" x-text="f.label"></span></template>
									</div>
								</td>
								<td class="whitespace-nowrap text-[12px]">
									<span :class="$store.ubr.verificationBadge(c.verification.status)" x-text="c.verification.label"></span>
									<div class="text-[11px] text-ink-muted mt-0.5" x-show="c.verification.checked_at" x-text="$store.ubr.date(c.verification.checked_at)"></div>
								</td>
								<td class="text-[12px] whitespace-nowrap" x-text="c.analysis.analyzed_at ? $store.ubr.date(c.analysis.analyzed_at) : '—'"></td>
								<td class="max-w-[220px] text-[12px] text-ink-soft"><span class="line-clamp-2" x-text="c.industry || '—'"></span></td>
								<td class="text-[12px]" x-text="c.region || '—'"></td>
								<td class="text-[12px] whitespace-nowrap"><span :class="c.pipeline?.overdue ? 'text-danger font-bold' : ''" x-text="c.pipeline?.next_action_at ? (c.pipeline.overdue ? 'Zaległe: ' : '') + $store.ubr.date(c.pipeline.next_action_at) : '—'"></span></td>
								<td class="text-[12px] whitespace-nowrap" x-text="$store.ubr.date(c.saved_at)"></td>
								<td class="text-[12px] whitespace-nowrap" x-text="c.pipeline?.owner?.name || '—'"></td>
								<td class="text-[12px] whitespace-nowrap">
									<template x-if="c.pipeline?.clickup_task_url">
										<a :href="$store.ubr.safeHref(c.pipeline.clickup_task_url)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 ubr-badge-confirmed hover:underline">Exported @include('ubr.partials.icon', ['name' => 'external', 'class' => 'size-3'])</a>
									</template>
									<template x-if="!c.pipeline?.clickup_task_url">
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="canExport && !c.pipeline?.archived" @click="$store.ubr.exportClickup(c.id)" :title="'Eksportuj „' + c.name + '” do ClickUp'">Eksportuj</button>
									</template>
								</td>
								<td class="text-right whitespace-nowrap">
									<span class="inline-flex align-middle mr-1.5">@include('ubr.partials.row-actions', ['var' => 'c'])</span>
									<template x-if="c.pipeline?.archived">
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="restore(c)">Przywróć</button>
									</template>
									<template x-if="!c.pipeline?.archived && confirmId !== c.prospect_id">
										<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2" @click="confirmId = c.prospect_id" title="Archiwizuj (usuń z listy)" aria-label="Archiwizuj">@include('ubr.partials.icon', ['name' => 'trash', 'class' => 'size-3.5'])</button>
									</template>
									<template x-if="!c.pipeline?.archived && confirmId === c.prospect_id">
										<span class="inline-flex gap-1.5">
											<button type="button" class="ubr-btn-danger ubr-btn-sm" @click="remove(c)">Archiwizuj</button>
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="confirmId = null">Nie</button>
										</span>
									</template>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>

			@include('ubr.partials.pagination', ['state' => 'list', 'load' => 'load'])
		</div>
		<p class="text-[12px] text-ink-muted">Archiwizacja usuwa firmę z aktywnej listy, ale zachowuje historię sprzedaży (notatki, aktywności) — można ją przywrócić. Nie usuwa firmy z globalnej bazy ani z list innych organizacji.</p>
	</div>
@endsection
