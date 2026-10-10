{{-- Firma w wynikach Discover: nazwa (maks. 2 linie, pełna w title), branża / źródło, wielkość, flagi. --}}
<button type="button" class="font-bold text-left hover:underline line-clamp-2 [overflow-wrap:anywhere]" @click="$store.ubr.drawer.tab = 'company'; $store.ubr.openCompany({{ $var }}.id)" x-text="{{ $var }}.name" :title="{{ $var }}.name"></button>
<div class="text-[12px] text-ink-muted line-clamp-1 [overflow-wrap:anywhere]" x-text="({{ $var }}.industry || 'branża: brak danych') + ({{ $var }}.provider_label ? ' · źródło: ' + {{ $var }}.provider_label : '')" :title="({{ $var }}.industry || '') + ({{ $var }}.provider_label ? ' · źródło: ' + {{ $var }}.provider_label : '')"></div>
<div class="text-[11px] text-ink-muted mt-0.5">
	<span>Pracownicy: </span><span x-text="{{ $var }}.size.label || 'brak danych'" :class="{{ $var }}.size.label ? 'text-ink-soft' : ''"></span>
	<span x-show="{{ $var }}.size.label && {{ $var }}.size.confidence !== 'confirmed'" title="Deklaracja źródła, niepotwierdzona">(wg źródła)</span>
</div>
<div class="flex flex-wrap gap-1 mt-1" x-show="{{ $var }}.flags.filter(f => f.key !== 'no_website').length">
	<template x-for="f in {{ $var }}.flags.filter(f => f.key !== 'no_website')" :key="f.key"><span class="!whitespace-normal" :class="f.blocking ? 'ubr-badge-danger' : 'ubr-badge-unavailable'" x-text="f.label"></span></template>
</div>
