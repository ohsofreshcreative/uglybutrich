{{-- Decydenci (Decision Maker Finder) w wierszu listy (zmienna: {{ $var }}): Top 1, Contact Priority, liczba osób, status. --}}
<div class="flex items-start gap-1.5 max-w-[230px]">
	<span :class="$store.ubr.contactScoreClass({{ $var }}.contacts?.top?.score)" :title="{{ $var }}.contacts?.top ? 'Contact Priority Top 1' : 'Brak Top 1'"
		x-text="{{ $var }}.contacts?.top ? {{ $var }}.contacts.top.score : '—'"></span>
	<div class="leading-tight min-w-0">
		<template x-if="{{ $var }}.contacts?.top">
			<div>
				<button type="button" class="text-[12px] font-bold hover:underline text-left truncate block max-w-[180px]" @click="$store.ubr.drawer.tab = 'contacts'; $store.ubr.openCompany({{ $var }}.id)" x-text="{{ $var }}.contacts.top.name"></button>
				<div class="text-[11px] text-ink-muted truncate max-w-[180px]" x-text="{{ $var }}.contacts.top.title"></div>
			</div>
		</template>
		<div class="mt-0.5 flex flex-wrap items-center gap-1">
			<span class="text-[11px]" :class="$store.ubr.contactsBadge({{ $var }}.contacts?.status)" x-text="{{ $var }}.contacts?.label || 'Nie szukano'"></span>
			<span class="text-[10px] text-ink-muted" x-show="{{ $var }}.contacts?.count !== null && {{ $var }}.contacts?.count !== undefined && ['completed','partial'].includes({{ $var }}.contacts?.status)"
				x-text="{{ $var }}.contacts?.count ? ({{ $var }}.contacts.count + (({{ $var }}.contacts.count === 1) ? ' osoba' : ' os.')) : 'brak osób'"></span>
			<span class="text-[10px] text-ink-muted" x-show="{{ $var }}.contacts?.top?.confidence" x-text="'pewność ' + ({{ $var }}.contacts?.top?.confidence_label || '').toLowerCase()"></span>
		</div>
	</div>
</div>
