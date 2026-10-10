@extends('ubr.layouts.app')

@section('actions')
	@include('ubr.partials.view-switch', ['active' => 'board'])
@endsection

@section('content')
	<div x-data="ubrPipeline" class="space-y-4">
		{{-- Filtry --}}
		<div class="ubr-card p-4 flex flex-wrap items-end gap-3">
			<div class="flex-1 min-w-[200px]">
				<label class="ubr-label" for="b-search">Szukaj</label>
				<input id="b-search" class="ubr-input" type="search" placeholder="Nazwa, domena, miasto…" x-model="filters.search" @input.debounce.350ms="load()">
			</div>
			<div class="w-[170px]">
				<label class="ubr-label" for="b-owner">Właściciel</label>
				<select id="b-owner" class="ubr-input" x-model="filters.owner" @change="load()">
					<option value="">Wszyscy</option><option value="me">Moje</option><option value="none">Bez właściciela</option>
					<template x-for="m in members" :key="m.id"><option :value="String(m.id)" x-text="m.name"></option></template>
				</select>
			</div>
			<div class="w-[140px]">
				<label class="ubr-label" for="b-prio">Priorytet</label>
				<select id="b-prio" class="ubr-input" x-model="filters.priority" @change="load()">
					<option value="">Dowolny</option>
					<template x-for="(label, key) in priorities" :key="key"><option :value="key" x-text="label"></option></template>
				</select>
			</div>
			<div class="w-[180px]">
				<label class="ubr-label" for="b-qual">Kwalifikacja</label>
				<select id="b-qual" class="ubr-input" x-model="filters.qualification" @change="load()">
					<option value="">Dowolna</option><option value="good_candidate">Dobry kandydat</option><option value="needs_review">Wymaga weryfikacji</option><option value="low_potential">Niski potencjał</option><option value="not_a_fit">Nie pasuje</option><option value="none">Bez decyzji</option>
				</select>
			</div>
			<div class="w-[170px]">
				<label class="ubr-label" for="b-due">Następne działanie</label>
				<select id="b-due" class="ubr-input" x-model="filters.overdue" @change="load()">
					<option value="">Dowolnie</option><option value="yes">Zaległe</option><option value="planned">Zaplanowane</option><option value="none">Brak</option>
				</select>
			</div>
			<button type="button" class="ubr-btn-ghost" x-show="hasFilters" @click="filters = { search: '', owner: '', priority: '', qualification: '', overdue: '' }; load()">Wyczyść</button>
		</div>

		<template x-if="error"><div class="ubr-card p-4 text-danger font-semibold" x-text="error"></div></template>

		<p class="text-[12px] text-ink-muted" x-show="canManage">Przeciągnij kartę do innej kolumny albo użyj listy „Przenieś do” (klawiatura). Status jest prywatny dla Twojej organizacji.</p>

		{{-- Tablica: kolumny = statusy --}}
		<div class="overflow-x-auto pb-3 -mx-4 px-4 sm:mx-0 sm:px-0">
			<div class="flex gap-3 min-w-max items-start" :class="loading ? 'opacity-60' : ''">
				<template x-if="loading && columns.length === 0">
					<div class="flex gap-3"><div class="ubr-skeleton h-64 w-[280px]"></div><div class="ubr-skeleton h-64 w-[280px]"></div><div class="ubr-skeleton h-64 w-[280px]"></div></div>
				</template>

				<template x-for="col in columns" :key="col.key">
					<section class="w-[280px] shrink-0 rounded-2xl bg-canvas border border-line flex flex-col max-h-[calc(100vh-260px)] min-h-[160px]"
						:class="dragOver === col.key ? 'ring-2 ring-gold' : ''" :aria-label="'Kolumna ' + col.label"
						@dragover.prevent="dragOver = col.key" @dragleave="dragOver === col.key && (dragOver = null)" @drop.prevent="drop(col)">
						<header class="px-3 pt-3 pb-2 flex items-center gap-2">
							<h2 class="font-extrabold text-[13px] flex-1" x-text="col.label" :title="col.description"></h2>
							<span class="text-[12px] font-bold tabular-nums text-ink-muted" x-text="col.total"></span>
						</header>
						<div class="px-2 pb-2 space-y-2 overflow-y-auto flex-1">
							<template x-if="col.items.length === 0"><p class="text-[12px] text-ink-muted text-center py-6">Brak firm</p></template>
							<template x-for="card in col.items" :key="card.prospect_id">
								<article class="rounded-xl bg-white border border-line p-3 shadow-sm" :draggable="canManage" @dragstart="dragStart($event, card)" @dragend="dragEnd()"
									:class="[moving[card.prospect_id] ? 'opacity-50' : '', canManage ? 'cursor-grab active:cursor-grabbing' : '']">
									<div class="flex items-start gap-2">
										<button type="button" class="font-bold text-[13px] text-left leading-snug hover:underline flex-1 min-w-0" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany(card.company_id)" x-text="card.name"></button>
										<span :class="$store.ubr.priorityBadge(card.priority)" x-text="card.priority_label"></span>
									</div>
									<div class="text-[11px] text-ink-muted truncate" x-text="card.domain || 'brak domeny'"></div>
									<div class="flex items-center gap-1.5 mt-2">
										<span :class="$store.ubr.opportunityClass(card.opportunity)" x-text="card.opportunity ?? '—'" title="Opportunity Score"></span>
										<span :class="$store.ubr.relevanceClass(card.relevance)" x-text="card.relevance ?? '—'" title="Relevance Score"></span>
										<span :class="$store.ubr.contactScoreClass(card.top_contact?.score)" x-text="card.top_contact?.score ?? '—'" title="Contact Priority Score (Top 1)"></span>
									</div>
									<template x-if="card.top_contact">
										<div class="mt-1.5 text-[12px] leading-snug"><span class="font-semibold" x-text="card.top_contact.name"></span> <span class="text-ink-muted" x-text="card.top_contact.title ? '· ' + card.top_contact.title : ''"></span></div>
									</template>
									<div class="mt-2 flex items-center gap-1.5 text-[11px]" x-show="card.next_action_at">
										@include('ubr.partials.icon', ['name' => 'clock', 'class' => 'size-3.5'])
										<span :class="card.overdue ? 'text-danger font-bold' : 'text-ink-soft'" x-text="(card.overdue ? 'Zaległe: ' : '') + $store.ubr.date(card.next_action_at)"></span>
									</div>
									<div class="mt-2 grid grid-cols-2 gap-1.5" x-show="canManage">
										<label class="sr-only" :for="'mv-' + card.prospect_id">Przenieś do</label>
										<select :id="'mv-' + card.prospect_id" class="ubr-input h-7 text-[11px] px-1.5" @change="move(card, $event.target.value); $event.target.value = ''" :disabled="!!moving[card.prospect_id]">
											<option value="">Przenieś do…</option>
											<template x-for="s in statusOptions.filter(s => s.key !== card.status)" :key="s.key"><option :value="s.key" x-text="s.label"></option></template>
										</select>
										<label class="sr-only" :for="'ow-' + card.prospect_id">Właściciel</label>
										<select :id="'ow-' + card.prospect_id" class="ubr-input h-7 text-[11px] px-1.5" :value="card.owner ? String(card.owner.id) : ''" @change="assign(card, $event.target.value)">
											<option value="">Bez właściciela</option>
											<template x-for="m in members" :key="m.id"><option :value="String(m.id)" x-text="m.name" :selected="card.owner && card.owner.id === m.id"></option></template>
										</select>
									</div>
									<div class="mt-1.5 text-[11px] text-ink-muted" x-show="!canManage" x-text="card.owner ? 'Właściciel: ' + card.owner.name : 'Bez właściciela'"></div>
								</article>
							</template>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm w-full" x-show="col.items.length < col.total" @click="more(col)" :disabled="!!col.loadingMore" x-text="col.loadingMore ? 'Ładowanie…' : 'Pokaż więcej (' + (col.total - col.items.length) + ')'"></button>
						</div>
					</section>
				</template>
			</div>
		</div>
	</div>
@endsection
