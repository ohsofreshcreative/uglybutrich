{{-- Szczegóły Relevance Score: które kryteria są potwierdzone, deklarowane, tylko z filtra, nieznane lub niezgodne. --}}
<template x-if="{{ $var }}">
	<div class="space-y-2">
		<div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
			<template x-for="cr in {{ $var }}.criteria" :key="cr.key">
				<div class="rounded-lg bg-white border border-line px-3 py-2">
					<div class="flex items-center justify-between gap-2">
						<span class="text-[11px] font-bold uppercase tracking-wider text-ink-muted" x-text="cr.label"></span>
						<span class="text-[11px] font-bold tabular-nums" x-text="cr.points + '/' + cr.max"></span>
					</div>
					<span class="mt-1 inline-block" :class="$store.ubr.criterionBadge(cr.state)" x-text="cr.state_label"></span>
					<p class="text-[11px] leading-snug text-ink-soft mt-1" x-text="cr.detail"></p>
				</div>
			</template>
		</div>
		<template x-for="p in {{ $var }}.penalties" :key="p.key">
			<p class="text-[11px] text-danger font-semibold" x-text="'−' + p.points + ' pkt: ' + p.label"></p>
		</template>
		<p class="text-[11px] text-ink-muted">Relevance Score ocenia zgodność z kryteriami wyszukiwania (branża 40, słowa kluczowe 20, lokalizacja 25, wielkość 15). To nie jest ocena potencjału sprzedażowego.</p>
	</div>
</template>
<template x-if="!{{ $var }}"><p class="text-[12px] text-ink-muted">Trafność jeszcze nie policzona.</p></template>
