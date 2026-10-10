{{-- Akcja Decision Maker Findera dla wiersza (zmienna: {{ $var }}): znajdź / znajdź ponownie. Wyszukiwanie zawsze zlecane świadomie. --}}
<template x-if="$store.ubr.can('contacts.run') && !['queued', 'running'].includes({{ $var }}.contacts?.status)">
	<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="$store.ubr.findDecisionMakers({{ $var }}.id, !!{{ $var }}.contacts?.status)"
		:aria-label="({{ $var }}.contacts?.status ? 'Znajdź ponownie decydentów: ' : 'Znajdź decydentów: ') + {{ $var }}.name"
		x-text="{{ $var }}.contacts?.status ? 'Znajdź ponownie' : 'Znajdź decydentów'"></button>
</template>
