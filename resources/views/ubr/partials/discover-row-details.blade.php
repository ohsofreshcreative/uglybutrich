{{-- Szczegóły wiersza Discover: pozostałe oceny (bez zmian w wartościach i interpretacji) + kryteria Relevance. --}}
<div class="space-y-3">
	<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
		<div class="rounded-lg bg-white border border-line px-3 py-2">
			<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Relevance</div>
			<span :class="$store.ubr.relevanceClass({{ $var }}.relevance)" x-text="{{ $var }}.relevance === null ? '—' : {{ $var }}.relevance"></span>
		</div>
		<div class="rounded-lg bg-white border border-line px-3 py-2">
			<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Opportunity</div>
			@include('ubr.partials.opportunity-cell', ['var' => $var])
		</div>
		<div class="rounded-lg bg-white border border-line px-3 py-2 min-w-0">
			<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Contact Priority (Top 1)</div>
			@include('ubr.partials.contacts-cell', ['var' => $var])
		</div>
		<div class="rounded-lg bg-white border border-line px-3 py-2 text-[12px]">
			<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Lead Intelligence</div>
			<template x-if="{{ $var }}.lead && {{ $var }}.lead.available">
				<div class="space-y-0.5">
					<div>Lead Potential: <strong x-text="({{ $var }}.lead.score ?? '—') + '/100'"></strong> · <span x-text="$store.ubr.leadConfLabel({{ $var }}.lead.confidence)"></span> · <span x-text="{{ $var }}.lead.coverage + '% danych'"></span></div>
					<div>Redesign: <strong x-text="$store.ubr.redesignLabel({{ $var }}.lead.redesign)"></strong></div>
					<div>Action: <strong x-text="$store.ubr.actionLabel({{ $var }}.lead.recommendation)"></strong></div>
					<button type="button" class="underline text-ink-soft hover:text-ink" @click="$store.ubr.drawer.tab = 'lead'; $store.ubr.openCompany({{ $var }}.id)">Pełna ocena</button>
				</div>
			</template>
			<span x-show="!{{ $var }}.lead || !{{ $var }}.lead.available" class="text-ink-muted">liczenie…</span>
		</div>
	</div>
	@include('ubr.partials.relevance-details', ['var' => $var . '.relevance_details'])
</div>
