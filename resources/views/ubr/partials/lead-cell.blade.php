{{-- Lead Intelligence na liście: wynik, pewność, rekomendacja (szczegóły w karcie firmy → Lead Intelligence). --}}
<template x-if="{{ $var }}.lead && {{ $var }}.lead.available">
	<button type="button" class="text-left group" @click="$store.ubr.drawer.tab = 'lead'; $store.ubr.openCompany({{ $var }}.id)" :title="'Lead Potential ' + ({{ $var }}.lead.score ?? '—') + ' · pewność ' + $store.ubr.leadConfLabel({{ $var }}.lead.confidence) + ' · kompletność ' + {{ $var }}.lead.coverage + '% · redesign: ' + $store.ubr.redesignLabel({{ $var }}.lead.redesign)">
		<span class="inline-flex items-center gap-1.5">
			<span :class="$store.ubr.leadClass({{ $var }}.lead.score)" x-text="{{ $var }}.lead.score ?? '—'"></span>
			<span class="text-[10px] font-bold text-ink-muted" x-text="$store.ubr.leadConfLabel({{ $var }}.lead.confidence)"></span>
		</span>
		<span class="block mt-1"><span class="text-[11px]" :class="$store.ubr.actionBadge({{ $var }}.lead.recommendation)" x-text="$store.ubr.actionLabel({{ $var }}.lead.recommendation)"></span></span>
		<span class="block text-[10px] text-ink-muted mt-0.5" x-text="'Redesign: ' + $store.ubr.redesignLabel({{ $var }}.lead.redesign) + ({{ $var }}.lead.pending ? ' · przeliczanie…' : '')"></span>
	</button>
</template>
<template x-if="!{{ $var }}.lead || !{{ $var }}.lead.available">
	<span class="text-[11px] text-ink-muted">liczenie…</span>
</template>
