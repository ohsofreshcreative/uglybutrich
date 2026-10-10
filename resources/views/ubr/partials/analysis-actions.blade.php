{{-- Akcje Website Analyzera dla wiersza (zmienna: {{ $var }}): podgląd, analiza / ponowna analiza. --}}
<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="{{ $var }}.domain" @click="$store.ubr.openPreview({{ $var }})" :aria-label="'Podgląd strony ' + {{ $var }}.name">
	@include('ubr.partials.icon', ['name' => 'globe', 'class' => 'size-3.5']) Podgląd strony
</button>
<template x-if="$store.ubr.can('analysis.run') && {{ $var }}.domain && !['queued', 'running'].includes({{ $var }}.analysis.status)">
	<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="$store.ubr.analyze({{ $var }}.id, !!{{ $var }}.analysis.status)"
		x-text="{{ $var }}.analysis.status ? 'Analizuj ponownie' : 'Analizuj stronę'"></button>
</template>
