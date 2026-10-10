@extends('ubr.layouts.app')

@section('actions')
	<a href="{{ $config['urls']['discover'] }}" class="ubr-btn-gold">
		@include('ubr.partials.icon', ['name' => 'search'])
		<span class="hidden sm:inline">Nowe wyszukiwanie</span>
	</a>
@endsection

{{--
	Prospecting Dashboard & Action Center (Prompt 6). Hierarchia: Overview (KPI) → Today's Priorities (maks. 5) + Follow-ups →
	Workspace (Top Opportunities / Needs Attention / Ready to Contact / Follow-ups / pozostałe kolejki) → Recent Activity +
	Prospecting Coverage → skróty. Wszystko liczy serwer (REST /action-center) z zapisanych danych organizacji;
	nic nie uruchamia analiz ani integracji przy wyświetlaniu.
--}}
@section('content')
	<div class="space-y-6 w-full min-w-0">
		<div x-data="ubrActionCenter" class="space-y-6 min-w-0">
			{{-- Zakres pracy + szybkie przejścia --}}
			<div class="flex flex-wrap items-center justify-between gap-3">
				<div class="inline-flex rounded-xl border border-line bg-white p-1" role="group" aria-label="Zakres pracy">
					<template x-for="o in [{ k: 'all', l: 'Team Work' }, { k: 'mine', l: 'My Work' }, { k: 'unassigned', l: 'Unassigned' }]" :key="o.k">
						<button type="button" class="px-3 h-8 rounded-lg text-[13px] font-semibold transition" :class="owner === o.k ? 'bg-ink text-white' : 'text-ink-muted hover:text-ink'" :aria-pressed="owner === o.k" @click="setOwner(o.k)" x-text="o.l"></button>
					</template>
				</div>
				<nav class="flex flex-wrap items-center gap-1.5 text-[12px]" aria-label="Szybkie przejścia">
					<a href="{{ $config['urls']['discover'] }}" class="ubr-btn-ghost ubr-btn-sm">Discover Companies</a>
					<a href="{{ $config['urls']['prospects'] }}" class="ubr-btn-ghost ubr-btn-sm">View Prospects</a>
					<a href="{{ $config['urls']['pipeline'] }}" class="ubr-btn-ghost ubr-btn-sm">Open Pipeline</a>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="openQueue('review_website')">Review Websites</button>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="openQueue('followups')">Follow-ups</button>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="scheduleRefresh()" aria-label="Odśwież dane" :title="data ? 'Stan na ' + $store.ubr.date(data.generated_at) : ''">@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-4'])</button>
				</nav>
			</div>

			<template x-if="error">
				<div class="ubr-card p-4 flex flex-wrap items-center justify-between gap-3" role="alert">
					<span class="text-danger font-semibold" x-text="'Nie udało się wczytać Action Center: ' + error"></span>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="load()">Spróbuj ponownie</button>
				</div>
			</template>

			{{-- 1. OVERVIEW — KPI z definicjami (klik = przefiltrowana lista) --}}
			<section aria-labelledby="ubr-kpi-title" class="min-w-0">
				<h2 id="ubr-kpi-title" class="sr-only">Overview</h2>
				<div class="grid gap-3 grid-cols-2 sm:grid-cols-3 xl:grid-cols-6">
					<template x-if="loading"><div class="ubr-skeleton h-[96px] col-span-2 sm:col-span-3 xl:col-span-6"></div></template>
					<template x-for="k in (data?.kpis || [])" :key="k.key">
						<button type="button" class="ubr-stat py-3! text-left hover:border-ink/25 transition min-w-0" @click="openKpi(k)" :title="k.definition" :aria-label="k.label + ': ' + k.value + '. ' + k.definition">
							<div class="text-[11px] font-bold uppercase tracking-wider text-ink-muted leading-tight" x-text="k.label"></div>
							<div class="mt-1.5 text-[28px] font-extrabold tabular-nums leading-none" :class="{ 'text-danger': k.tone === 'danger', 'text-money': k.tone === 'money' }" x-text="k.value"></div>
							<div class="mt-1.5 text-[11px] text-ink-muted line-clamp-2" x-text="k.detail || k.definition"></div>
						</button>
					</template>
				</div>
				<p class="mt-2 text-[11px] text-ink-muted" x-show="data && data.coverage.awaiting_evaluation" x-text="(data?.coverage.awaiting_evaluation || 0) + ' firm czeka na ocenę Lead Intelligence (przeliczanie w tle) — nie są jeszcze uwzględnione w listach.'"></p>
			</section>

			<div class="grid gap-6 xl:grid-cols-12 min-w-0">
				{{-- 2. TODAY'S PRIORITIES (maks. 5 naraz) --}}
				<section class="ubr-card xl:col-span-8 min-w-0" aria-labelledby="ubr-prio-title">
					<header class="px-5 pt-5 pb-3 flex flex-wrap items-end justify-between gap-2">
						<div>
							<h2 id="ubr-prio-title" class="font-extrabold text-[17px]">Today's Priorities</h2>
							<p class="text-[12px] text-ink-muted mt-0.5">Konkretne działania: zaległe follow-upy → na dziś → gotowe do kontaktu → przygotowanie kontaktu → uzupełnienie danych. Lead Potential porządkuje tylko w obrębie kategorii.</p>
						</div>
						<span class="text-[12px] text-ink-muted" x-show="!loading" x-text="priorities.total + ' działań'"></span>
					</header>

					<template x-if="loading"><div class="px-5 pb-5 space-y-2"><div class="ubr-skeleton h-16"></div><div class="ubr-skeleton h-16"></div><div class="ubr-skeleton h-16"></div></div></template>

					<template x-if="!loading && !error && priorities.total === 0">
						<div class="px-5 pb-8 pt-4 text-center">
							<p class="font-semibold">Brak działań na dziś.</p>
							<p class="text-[13px] text-ink-muted mt-1">Nie ma zaległych follow-upów ani rekomendowanych działań w tym zakresie. Uruchom wyszukiwanie w Discover albo sprawdź odłożone działania w kolejkach.</p>
						</div>
					</template>

					<ol class="divide-y divide-line" x-show="!loading && priorities.items.length" :aria-busy="priorities.loading">
						<template x-for="it in priorities.items" :key="it.key">
							<li class="px-5 py-3.5 flex flex-col md:flex-row md:items-center gap-3 min-w-0" :class="priorities.loading ? 'opacity-50' : ''">
								<div class="min-w-0 flex-1">
									<div class="flex flex-wrap items-center gap-1.5 mb-1">
										<span :class="priorityBadge(it.priority)" x-text="priorityLabel(it.priority)"></span>
										<span class="text-[12px] font-bold" x-text="it.type_label_pl"></span>
										<span class="text-[11px] text-ink-muted" x-text="'· ' + it.type_label"></span>
										<template x-if="it.due_at"><span class="text-[12px] font-semibold" :class="it.type === 'follow_up_overdue' ? 'text-danger' : 'text-ink'" x-text="'· ' + $store.ubr.date(it.due_at) + ' (' + dueLabel(it.due_at) + ')'"></span></template>
									</div>
									<button type="button" class="font-semibold text-left hover:underline line-clamp-1 break-all" :title="it.company.name" @click="openCompany(it, it.followup ? 'sales' : 'lead')" x-text="it.company.name"></button>
									<div class="text-[12px] text-ink-muted truncate" x-text="(it.company.domain || 'brak domeny') + (it.prospect ? ' · Prospect: ' + it.prospect.status_label + (it.prospect.owner ? ' · ' + it.prospect.owner.name : '') : '')"></div>
									<template x-if="it.followup"><div class="text-[13px] mt-1"><span class="font-semibold" x-text="it.followup.title"></span><span class="text-ink-muted" x-show="it.followup.more" x-text="' · i jeszcze ' + it.followup.more"></span><span class="text-ink-muted" x-show="it.followup.assigned" x-text="' · ' + (it.followup.assigned?.name || '')"></span></div></template>
									<p class="text-[12px] text-ink-muted mt-1" x-text="it.reason"></p>
								</div>
								<div class="flex items-center gap-2 shrink-0">
									<template x-if="it.lead && it.lead.score !== null">
										<span class="inline-flex items-center gap-1" :title="'Lead Potential ' + it.lead.score + ' · Confidence ' + $store.ubr.leadConfLabel(it.lead.confidence) + ' · kompletność ' + it.lead.coverage + '%'">
											<span :class="$store.ubr.leadClass(it.lead.score)" x-text="it.lead.score"></span>
											<span :class="$store.ubr.leadConfBadge(it.lead.confidence)" x-text="$store.ubr.leadConfLabel(it.lead.confidence)"></span>
										</span>
									</template>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm whitespace-nowrap" :disabled="ctaDisabled(it)" @click="runCta(it)" x-text="ctaLabel(it)"></button>
									@include('ubr.partials.ac-action-menu')
								</div>
							</li>
						</template>
					</ol>

					<nav class="px-5 py-3 border-t border-line flex items-center justify-between text-[12px]" x-show="!loading && priorityPages > 1" aria-label="Kolejne działania">
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="priorities.page <= 1 || priorities.loading" @click="loadPriorities(priorities.page - 1)">Poprzednie 5</button>
						<span x-text="(priorities.page - 1) * priorities.per_page + 1 + '–' + Math.min(priorities.total, priorities.page * priorities.per_page) + ' z ' + priorities.total"></span>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="priorities.page >= priorityPages || priorities.loading" @click="loadPriorities(priorities.page + 1)">Kolejne 5</button>
					</nav>
				</section>

				{{-- 3. FOLLOW-UPS (Sales Pipeline) --}}
				<section class="ubr-card p-5 xl:col-span-4 min-w-0" x-show="!data || data.followups" aria-labelledby="ubr-fu-title">
					<header class="flex items-center justify-between mb-3">
						<h2 id="ubr-fu-title" class="font-extrabold">Follow-ups</h2>
						<div class="flex items-center gap-3 text-[12px]">
							<button type="button" class="underline text-ink-muted hover:text-ink" @click="openQueue('followups')">Wszystkie</button>
							<a href="{{ $config['urls']['pipeline'] }}" class="underline text-ink-muted hover:text-ink">Pipeline</a>
						</div>
					</header>
					<template x-if="loading"><div class="space-y-2"><div class="ubr-skeleton h-10"></div><div class="ubr-skeleton h-10"></div></div></template>
					<template x-for="b in [{ k: 'overdue', l: 'Overdue', c: 'text-danger' }, { k: 'today', l: 'Today', c: '' }, { k: 'upcoming', l: 'Upcoming (7 dni)', c: '' }]" :key="b.k">
						<div class="mb-3" x-show="data">
							<button type="button" class="text-[11px] font-bold uppercase tracking-wider mb-1 hover:underline" :class="b.c || 'text-ink-muted'" @click="openQueue('followups', b.k)" x-text="b.l + ' (' + (data?.followups?.[b.k]?.total || 0) + ')'"></button>
							<template x-if="!(data?.followups?.[b.k]?.items?.length)"><p class="text-[12px] text-ink-muted">—</p></template>
							<template x-for="f in (data?.followups?.[b.k]?.items || [])" :key="f.id">
								<div class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-canvas min-w-0">
									<button type="button" class="min-w-0 flex-1 text-left" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany(f.company.id)">
										<div class="text-[13px] font-semibold truncate" x-text="f.title"></div>
										<div class="text-[11px] text-ink-muted truncate" x-text="f.company.name + ' · ' + $store.ubr.date(f.due_at) + (f.assigned ? ' · ' + f.assigned.name : '')"></div>
									</button>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm shrink-0" x-show="can('pipeline.manage')" :disabled="!!busy[f.company.id]" @click="completeFollowup({ company: f.company, followup: f })" :aria-label="'Oznacz jako wykonane: ' + f.title">@include('ubr.partials.icon', ['name' => 'check', 'class' => 'size-4'])</button>
								</div>
							</template>
						</div>
					</template>
				</section>
			</div>

			{{-- 4. WORKSPACE: Top Opportunities / Needs Attention / Ready to Contact / Follow-ups / pozostałe kolejki --}}
			<section id="ubr-queues" class="ubr-card min-w-0 scroll-mt-4" aria-labelledby="ubr-queues-title">
				<header class="px-5 pt-5 pb-3 space-y-3">
					<div class="flex flex-wrap items-end justify-between gap-2">
						<h2 id="ubr-queues-title" class="font-extrabold text-[17px]" x-text="queueDef(queue.key)?.label || 'Workspace'"></h2>
						<span class="text-[12px] text-ink-muted">Pula organizacji: firmy odkryte w Twoich wyszukiwaniach + zapisane do Prospects (dane prywatne organizacji)</span>
					</div>
					<div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Listy robocze">
						<template x-for="q in (data?.queues || [])" :key="q.key">
							<button type="button" role="tab" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-full border text-[12px] font-semibold transition" :aria-selected="queue.key === q.key"
								:class="[queue.key === q.key ? 'bg-ink text-white border-ink' : 'bg-white border-line hover:border-ink/30', primaryQueues.includes(q.key) ? '' : 'font-medium']" @click="openQueue(q.key, '', false)">
								<span x-text="q.label"></span><span class="tabular-nums rounded-full px-1.5 text-[11px]" :class="queue.key === q.key ? 'bg-white/20' : 'bg-canvas'" x-text="q.count"></span>
							</button>
						</template>
					</div>
					<p class="text-[12px] text-ink-muted" x-show="queueDef(queue.key)" x-text="queueDef(queue.key)?.definition"></p>

					{{-- Filtry (dotyczą wyłącznie bieżącej listy; nie ukrywają follow-upów ani aktywności) — zawijają się --}}
					<div class="flex flex-wrap items-end gap-2" x-show="queue.key">
						<template x-if="isFollowupQueue">
							<div class="flex flex-wrap gap-1.5" role="group" aria-label="Termin follow-upu">
								<template x-for="b in [{ k: '', l: 'Wszystkie otwarte' }, { k: 'due', l: 'Zaległe + dziś' }, { k: 'overdue', l: 'Zaległe' }, { k: 'today', l: 'Dziś' }, { k: 'upcoming', l: 'Najbliższe 7 dni' }]" :key="b.k">
									<button type="button" class="h-8 px-3 rounded-lg border text-[12px] font-semibold" :class="queue.bucket === b.k ? 'bg-ink text-white border-ink' : 'bg-white border-line'" :aria-pressed="queue.bucket === b.k" @click="queue.bucket = b.k; loadQueue(1)" x-text="b.l"></button>
								</template>
							</div>
						</template>
						<template x-if="!isFollowupQueue">
							<div class="flex flex-wrap items-end gap-2 w-full">
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Zakres
									<select class="ubr-input mt-1 block w-auto pr-8 max-w-[170px]" x-model="queue.filters.scope" @change="loadQueue(1)">
										<option value="all">Wszystkie odkryte</option><option value="prospects">Zapisane (Prospects)</option><option value="not_prospects">Niezapisane</option>
									</select>
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Lead Potential
									<select class="ubr-input mt-1 block w-auto pr-8 max-w-[170px]" x-model="queue.filters.min_lead" @change="loadQueue(1)">
										<option value="">Dowolny</option><option value="50">≥ 50</option><option value="60">≥ 60</option><option value="70">≥ 70</option>
									</select>
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Confidence
									<select class="ubr-input mt-1 block w-auto pr-8 max-w-[170px]" x-model="queue.filters.confidence" @change="loadQueue(1)">
										<option value="">Dowolny</option><option value="high">HIGH</option><option value="medium">MEDIUM</option><option value="low">LOW</option>
									</select>
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Województwo
									<select class="ubr-input mt-1 block w-auto pr-8 max-w-[180px]" x-model="queue.filters.region" @change="loadQueue(1)">
										<option value="">Dowolne</option>
										<template x-for="r in (data?.filter_options?.regions || [])" :key="r"><option :value="r" x-text="r"></option></template>
									</select>
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Redesign
									<select class="ubr-input mt-1 block w-auto pr-8 max-w-[170px]" x-model="queue.filters.redesign" @change="loadQueue(1)">
										<option value="">Dowolny</option>
										<template x-for="o in (data?.filter_options?.redesign || [])" :key="o.key"><option :value="o.key" x-text="o.label"></option></template>
									</select>
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Rekomendacja
									<select class="ubr-input mt-1 block w-auto pr-8 max-w-[170px]" x-model="queue.filters.recommendation" @change="loadQueue(1)">
										<option value="">Dowolna</option>
										<template x-for="o in (data?.filter_options?.recommendations || [])" :key="o.key"><option :value="o.key" x-text="o.label"></option></template>
									</select>
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Ocena ręczna
									<select class="ubr-input mt-1 block w-auto pr-8 max-w-[170px]" x-model="queue.filters.manual_review" @change="loadQueue(1)">
										<option value="">Dowolna</option>
										<template x-for="o in (data?.filter_options?.manual_review || [])" :key="o.key"><option :value="o.key" x-text="o.label"></option></template>
									</select>
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Sortuj
									<select class="ubr-input mt-1 block w-auto pr-8 max-w-[170px]" x-model="queue.filters.sort" @change="loadQueue(1)">
										<option value="rank">Smart Ranking</option><option value="priority">Priorytet działania</option><option value="lead">Lead Potential</option><option value="coverage">Kompletność danych</option><option value="name">Nazwa</option>
									</select>
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted min-w-[150px] flex-1 max-w-[220px]">Branża
									<input type="search" class="ubr-input mt-1 block w-full" placeholder="np. maszyny" x-model="queue.filters.industry" @input="searchQueue()">
								</label>
								<label class="text-[11px] font-bold uppercase tracking-wider text-ink-muted min-w-[150px] flex-1 max-w-[260px]">Szukaj
									<input type="search" class="ubr-input mt-1 block w-full" placeholder="Nazwa lub domena" x-model="queue.filters.search" @input="searchQueue()">
								</label>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="filtersActive" @click="resetFilters()">Wyczyść filtry</button>
							</div>
						</template>
					</div>
				</header>

				<template x-if="queue.loading && !queue.items.length"><div class="px-5 pb-5 space-y-2"><div class="ubr-skeleton h-12"></div><div class="ubr-skeleton h-12"></div><div class="ubr-skeleton h-12"></div></div></template>
				<template x-if="queue.error"><div class="px-5 pb-5 text-danger font-semibold text-[13px]" role="alert" x-text="queue.error"></div></template>
				<template x-if="!queue.loading && !queue.error && queue.key && !queue.items.length">
					<div class="px-5 pb-8 pt-2 text-center text-[13px] text-ink-muted">
						<template x-if="queue.key === 'ready_to_contact' && !filtersActive">
							<div><p class="font-semibold text-ink">Nie ma jeszcze firm gotowych do kontaktu.</p><p class="mt-1">Sprawdź firmy wymagające ręcznej oceny lub uzupełnienia danych.</p>
								<div class="mt-3 flex justify-center gap-2"><button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="openQueue('review_website', '', false)">Review Website</button><button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="openQueue('needs_attention', '', false)">Needs Attention</button></div></div>
						</template>
						<template x-if="queue.key !== 'ready_to_contact' || filtersActive"><p>Ta lista jest pusta dla wybranych filtrów.</p></template>
					</div>
				</template>

				{{-- Lista firm: tabela (≥ 1024 px, bez przewijania w poziomie) / karty --}}
				<template x-if="!isFollowupQueue && queue.items.length">
					<div :class="queue.loading ? 'opacity-60' : ''">
						<table class="ubr-table table-fixed hidden lg:table">
							<colgroup><col class="w-[24%]"><col class="w-[13%]"><col><col class="w-[19%]"><col class="w-[150px]"></colgroup>
							<thead><tr><th>Firma</th><th>Lead</th><th>Działanie / rekomendacja</th><th x-text="queue.key === 'ready_to_contact' ? 'Kontakt i status' : 'Status'"></th><th class="text-right">Akcje</th></tr></thead>
							<tbody>
								<template x-for="it in queue.items" :key="it.company.id">
									<tr>
										<td class="min-w-0">
											<button type="button" class="font-semibold text-left hover:underline line-clamp-2 break-words" :title="it.company.name" @click="openCompany(it, 'lead')" x-text="it.company.name"></button>
											<div class="text-[12px] text-ink-muted truncate" :title="it.industry || ''" x-text="[it.industry, it.location].filter(Boolean).join(' · ') || 'brak danych o branży'"></div>
											<div class="text-[12px] text-ink-muted truncate" :title="it.company.domain || ''" x-text="it.company.domain || 'brak domeny'"></div>
										</td>
										<td>
											<div class="flex flex-wrap items-center gap-1">
												<span :class="$store.ubr.leadClass(it.lead.score)" x-text="it.lead.score ?? '—'"></span>
												<span :class="$store.ubr.leadConfBadge(it.lead.confidence)" x-text="$store.ubr.leadConfLabel(it.lead.confidence)"></span>
											</div>
											<div class="text-[11px] text-ink-muted mt-0.5" x-text="(it.lead.coverage ?? 0) + '% danych' + (it.lead.status === 'PROVISIONAL' ? ' · wstępny' : '') + (it.lead.stale ? ' · przeliczanie' : '')"></div>
											<div class="mt-1"><span :class="$store.ubr.redesignBadge(it.lead.redesign)" x-text="$store.ubr.redesignLabel(it.lead.redesign)"></span></div>
										</td>
										<td class="min-w-0">
											<div class="flex flex-wrap items-center gap-1.5">
												<template x-if="it.action"><span class="text-[12px] font-bold" x-text="it.action.label_pl"></span></template>
												<span :class="$store.ubr.actionBadge(it.lead.recommendation)" x-text="it.lead.recommendation_label"></span>
												<template x-if="it.has_blocker"><span class="ubr-badge-danger">Hard blocker</span></template>
												<template x-if="it.snoozed_until"><span class="ubr-badge-unavailable" x-text="'Odłożone do ' + $store.ubr.date(it.snoozed_until)"></span></template>
											</div>
											<p class="text-[12px] text-ink-muted mt-0.5 line-clamp-2" :title="it.reason" x-text="it.reason"></p>
											<template x-if="it.manual_review?.sentence"><p class="text-[12px] font-semibold mt-0.5" x-text="it.manual_review.sentence"></p></template>
											<template x-if="it.issues?.length"><p class="text-[11px] text-ink-muted mt-0.5 line-clamp-1" :title="it.issues.join(' · ')" x-text="'Także: ' + it.issues.join(' · ')"></p></template>
										</td>
										<td class="text-[12px] min-w-0">
											<template x-if="queue.key === 'ready_to_contact' || it.contact">
												<div class="mb-1 min-w-0">
													<div class="font-semibold truncate" x-text="it.contact ? it.contact.name : 'Brak wskazanej osoby'"></div>
													<div class="text-ink-muted truncate" x-show="it.contact?.title" x-text="it.contact?.title"></div>
													<div class="text-ink-muted truncate" x-show="it.channels?.length" :title="(it.channels || []).join(', ')" x-text="(it.channels || []).join(', ')"></div>
												</div>
											</template>
											<div x-text="it.prospect ? 'Zapisana · ' + it.prospect.status_label : 'Niezapisana'"></div>
											<div class="text-ink-muted" x-text="'Analiza: ' + statusLabel(it.analysis_status)"></div>
											<div class="text-ink-muted" x-show="queue.key !== 'ready_to_contact'" x-text="'Decydenci: ' + (it.contacts_count ? it.contacts_count : (it.contacts_status ? 'brak wyników' : 'nie szukano'))"></div>
										</td>
										<td>
											<div class="flex items-center justify-end gap-1.5">
												<template x-if="it.cta"><button type="button" class="ubr-btn-ghost ubr-btn-sm whitespace-nowrap max-w-[110px] truncate" :disabled="ctaDisabled(it)" :title="ctaLabel(it)" @click="runCta(it)" x-text="ctaLabel(it)"></button></template>
												<template x-if="!it.cta && !it.prospect && can('prospects.manage')"><button type="button" class="ubr-btn-ghost ubr-btn-sm whitespace-nowrap" :disabled="!!busy[it.company.id]" @click="addToProspects(it)">Zapisz</button></template>
												@include('ubr.partials.ac-action-menu')
											</div>
										</td>
									</tr>
								</template>
							</tbody>
						</table>
						<ul class="lg:hidden divide-y divide-line" aria-label="Lista (karty)">
							<template x-for="it in queue.items" :key="it.company.id">
								<li class="px-5 py-3.5 space-y-1.5 min-w-0">
									<div class="flex items-start justify-between gap-2">
										<div class="min-w-0">
											<button type="button" class="font-semibold text-left hover:underline line-clamp-2 break-words" @click="openCompany(it, 'lead')" x-text="it.company.name"></button>
											<div class="text-[12px] text-ink-muted truncate" x-text="[it.company.domain || 'brak domeny', it.location].filter(Boolean).join(' · ')"></div>
										</div>
										@include('ubr.partials.ac-action-menu')
									</div>
									<div class="flex flex-wrap items-center gap-1.5">
										<span :class="$store.ubr.leadClass(it.lead.score)" x-text="it.lead.score ?? '—'"></span>
										<span :class="$store.ubr.leadConfBadge(it.lead.confidence)" x-text="$store.ubr.leadConfLabel(it.lead.confidence)"></span>
										<span :class="$store.ubr.actionBadge(it.lead.recommendation)" x-text="it.lead.recommendation_label"></span>
										<template x-if="it.has_blocker"><span class="ubr-badge-danger">Hard blocker</span></template>
										<template x-if="it.snoozed_until"><span class="ubr-badge-unavailable" x-text="'Odłożone do ' + $store.ubr.date(it.snoozed_until)"></span></template>
									</div>
									<template x-if="it.action"><p class="text-[12px] font-bold" x-text="it.action.label_pl"></p></template>
									<p class="text-[12px] text-ink-muted" x-text="it.reason"></p>
									<template x-if="it.manual_review?.sentence"><p class="text-[12px] font-semibold" x-text="it.manual_review.sentence"></p></template>
									<template x-if="it.contact"><p class="text-[12px]" x-text="'Kontakt: ' + it.contact.name + (it.contact.title ? ', ' + it.contact.title : '')"></p></template>
									<div class="flex flex-wrap items-center justify-between gap-2">
										<span class="text-[12px] text-ink-muted" x-text="(it.prospect ? 'Zapisana · ' + it.prospect.status_label : 'Niezapisana') + ' · analiza: ' + statusLabel(it.analysis_status)"></span>
										<template x-if="it.cta"><button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="ctaDisabled(it)" @click="runCta(it)" x-text="ctaLabel(it)"></button></template>
									</div>
								</li>
							</template>
						</ul>
					</div>
				</template>

				{{-- Follow-upy --}}
				<template x-if="isFollowupQueue && queue.items.length">
					<ul class="divide-y divide-line" :class="queue.loading ? 'opacity-60' : ''" aria-label="Follow-upy">
						<template x-for="it in queue.items" :key="it.key">
							<li class="px-5 py-3 flex flex-col sm:flex-row sm:items-center gap-2 min-w-0">
								<div class="min-w-0 flex-1">
									<div class="text-[12px] font-semibold" :class="it.bucket === 'overdue' ? 'text-danger' : 'text-ink'" x-text="$store.ubr.date(it.due_at) + ' · ' + dueLabel(it.due_at)"></div>
									<div class="font-semibold truncate" x-text="it.followup?.title"></div>
									<button type="button" class="text-[12px] text-ink-muted hover:underline text-left" @click="openCompany(it, 'sales')" x-text="it.company.name + (it.prospect ? ' · ' + it.prospect.status_label : '') + (it.followup?.assigned ? ' · ' + it.followup.assigned.name : '')"></button>
								</div>
								<div class="flex items-center gap-1.5 shrink-0">
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="can('pipeline.manage')" :disabled="!!busy[it.company.id]" @click="completeFollowup(it)">Wykonane</button>
									@include('ubr.partials.ac-action-menu')
								</div>
							</li>
						</template>
					</ul>
				</template>

				<nav class="px-5 py-3 border-t border-line flex items-center justify-between text-[12px]" x-show="queue.total > queue.per_page" aria-label="Strony listy">
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="queue.page <= 1 || queue.loading" @click="loadQueue(queue.page - 1)">Poprzednie</button>
					<span x-text="'Strona ' + queue.page + ' z ' + queuePages + ' · ' + queue.total + ' pozycji'"></span>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="queue.page >= queuePages || queue.loading" @click="loadQueue(queue.page + 1)">Następne</button>
				</nav>
			</section>

			<div class="grid gap-6 xl:grid-cols-12 min-w-0">
				{{-- 5. RECENT ACTIVITY (zdarzenia Sales Pipeline, bez logów technicznych) --}}
				<section class="ubr-card p-5 xl:col-span-5 min-w-0" x-show="!data || data.followups" aria-labelledby="ubr-act-title">
					<h2 id="ubr-act-title" class="font-extrabold mb-3">Recent Activity</h2>
					<template x-if="data && !(data?.activity?.length)"><p class="text-[13px] text-ink-muted py-4">Brak zarejestrowanej aktywności sprzedażowej.</p></template>
					<ul class="space-y-2">
						<template x-for="(a, i) in (data?.activity || [])" :key="i">
							<li class="text-[13px] min-w-0">
								<button type="button" class="font-semibold hover:underline text-left" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany(a.company.id)" x-text="a.company.name"></button>
								<span class="text-ink" x-text="' — ' + a.text"></span>
								<div class="text-[11px] text-ink-muted" x-text="$store.ubr.date(a.at) + (a.user ? ' · ' + a.user.name : '')"></div>
							</li>
						</template>
					</ul>
				</section>

				{{-- 6. PROSPECTING COVERAGE (niezależne liczniki — nie lejek konwersji) --}}
				<section class="ubr-card p-5 xl:col-span-7 min-w-0" x-show="data" aria-labelledby="ubr-cov-title">
					<header class="flex flex-wrap items-end justify-between gap-2 mb-3">
						<h2 id="ubr-cov-title" class="font-extrabold">Prospecting Coverage</h2>
						<span class="text-[12px] text-ink-muted" x-text="data?.coverage.exported ? data.coverage.exported + ' firm wyeksportowanych do ClickUp' : ''"></span>
					</header>
					<dl class="grid gap-x-4 gap-y-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4">
						<template x-for="c in (data?.coverage.items || [])" :key="c.key">
							<div class="min-w-0" :title="c.definition">
								<dt class="text-[11px] font-bold uppercase tracking-wider text-ink-muted leading-tight" x-text="c.label"></dt>
								<dd class="text-[22px] font-extrabold tabular-nums leading-tight" x-text="c.value"></dd>
								<dd class="text-[11px] text-ink-muted line-clamp-2" x-text="c.definition"></dd>
							</div>
						</template>
					</dl>
					<p class="text-[12px] text-ink-muted mt-4" x-text="data?.coverage.note"></p>
					<details class="mt-3">
						<summary class="text-[12px] font-semibold cursor-pointer">Prospekci według obecnego statusu pipeline</summary>
						<ul class="mt-2 grid gap-1 grid-cols-2 sm:grid-cols-3 text-[12px]">
							<template x-for="s in (data?.coverage.by_status || [])" :key="s.key"><li class="flex justify-between gap-2 rounded-lg bg-canvas px-2 py-1"><span class="truncate" x-text="s.label"></span><span class="font-bold tabular-nums" x-text="s.count"></span></li></template>
						</ul>
					</details>
				</section>
			</div>
		</div>

		{{-- Skróty (dotychczasowe sekcje Dashboardu) --}}
		<details x-data="ubrDashboard" @toggle="$el.open && load()" class="ubr-card min-w-0 group">
			<summary class="px-5 py-4 font-extrabold cursor-pointer select-none">Ostatnie wyszukiwania i ostatnio znalezione firmy</summary>
			<div class="grid gap-6 lg:grid-cols-5 px-5 pb-5 min-w-0">
				<section class="lg:col-span-2 min-w-0 space-y-2" aria-label="Ostatnie wyszukiwania">
					<template x-if="loading"><div class="space-y-2"><div class="ubr-skeleton h-14"></div><div class="ubr-skeleton h-14"></div></div></template>
					<template x-if="error"><p class="text-[13px] text-danger" x-text="error"></p></template>
					<template x-if="!loading && !error && data.recent_searches.length === 0">
						<p class="text-[13px] text-ink-muted py-6 text-center">Brak wyszukiwań. Zacznij od ekranu Discover.</p>
					</template>
					<template x-for="s in data.recent_searches" :key="s.id">
						<a :href="UBR.urls.discover + (UBR.urls.discover.includes('?') ? '&' : '?') + 'search=' + s.id" class="block rounded-xl border border-line px-4 py-3 hover:border-ink/25 transition">
							<div class="flex items-center justify-between gap-2">
								<span class="font-semibold text-[13px] truncate" x-text="s.label"></span>
								<span :class="$store.ubr.statusBadge(s.status)" x-text="$store.ubr.statusLabel(s.status)"></span>
							</div>
							<div class="text-[12px] text-ink-muted mt-0.5"><span x-text="s.results_count"></span> firm · <span x-text="s.with_domain_count"></span> z domeną · <span x-text="$store.ubr.date(s.created_at)"></span></div>
						</a>
					</template>
				</section>
				<section class="lg:col-span-3 min-w-0" aria-label="Ostatnio znalezione firmy">
					<template x-if="!loading && data.recent_companies.length === 0">
						<p class="text-[13px] text-ink-muted py-10 text-center">Tu pojawią się firmy z Twoich wyszukiwań.</p>
					</template>
					<table class="ubr-table table-fixed" x-show="!loading && data.recent_companies.length">
						<colgroup><col><col class="w-[38%]"><col class="w-[90px]"></colgroup>
						<thead><tr><th>Firma</th><th>Lokalizacja</th><th></th></tr></thead>
						<tbody>
							<template x-for="c in data.recent_companies" :key="c.id">
								<tr class="cursor-pointer" @click="$store.ubr.openCompany(c.id)">
									<td class="min-w-0">
										<div class="font-semibold truncate" :title="c.name" x-text="c.name"></div>
										<div class="text-[12px] text-ink-muted truncate" x-text="c.domain || 'brak domeny'"></div>
									</td>
									<td class="text-[13px] truncate" x-text="[c.city, c.region].filter(Boolean).join(', ') || '—'"></td>
									<td class="text-right"><span x-show="c.prospect_id" class="ubr-badge-confirmed">Prospect</span></td>
								</tr>
							</template>
						</tbody>
					</table>
				</section>
			</div>
		</details>
	</div>
@endsection
