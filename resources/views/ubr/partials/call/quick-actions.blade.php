{{--
	Szybkie akcje Cold Calling (wspólne: Discover, Prospects): Zadzwoń (tel:, bez dialera i bez automatycznego zapisu),
	Zapisz wynik (świadomie), Przejmij (tylko wolna firma) + menu ⋯ (podgląd, karta, analiza, decydenci, Prospects).
	Dla firm innego opiekuna i z zakazem kontaktu akcje kontaktowe są niedostępne (uprawnienia także po stronie serwera).
	Parametry: $var, $save, $searchExpr, $compact (karta mobilna — przyciski z etykietą).
--}}
<div class="flex flex-wrap items-center gap-1 {{ ! empty($compact) ? 'justify-start [&_.ubr-btn-sm]:!h-10' : 'justify-end' }}">
	<template x-if="{{ $var }}.call?.actions.can_call && $store.ubr.safeTel(({{ $var }}.call.decision_maker?.phone?.numbers || [])[0]?.tel || {{ $var }}.call.company_phone?.tel)">
		<a class="ubr-btn-primary ubr-btn-sm !px-2.5" :href="$store.ubr.safeTel(({{ $var }}.call.decision_maker?.phone?.numbers || [])[0]?.tel || {{ $var }}.call.company_phone?.tel)" :aria-label="'Zadzwoń: ' + {{ $var }}.name" title="Zadzwoń (otwiera aplikację telefonu; rozmowa nie jest zapisywana automatycznie)">
			@include('ubr.partials.icon', ['name' => 'phone', 'class' => 'size-3.5'])<span class="{{ ! empty($compact) ? '' : 'sr-only' }}">Zadzwoń</span>
		</a>
	</template>
	<button type="button" class="ubr-btn-ghost ubr-btn-sm !px-2.5" x-show="{{ $var }}.call?.actions.can_record_outcome" @click="$store.ubr.openOutcome({{ $var }}, {{ $var }}.call.decision_maker?.contact_id || null)" :aria-label="'Zapisz wynik rozmowy: ' + {{ $var }}.name" title="Zapisz wynik rozmowy">
		@include('ubr.partials.icon', ['name' => 'note', 'class' => 'size-3.5'])<span class="{{ ! empty($compact) ? '' : 'sr-only' }}">Wynik</span>
	</button>
	<button type="button" class="ubr-btn-ghost ubr-btn-sm !px-2.5" x-show="{{ $var }}.call?.actions.can_claim" :disabled="!!{{ $var }}._claiming" @click="$store.ubr.claimCompany({{ $var }}, {{ $searchExpr ?? 'null' }})" :aria-label="'Przejmij firmę: ' + {{ $var }}.name" title="Przejmij firmę (dodaj do moich Prospects)">
		@include('ubr.partials.icon', ['name' => 'hand', 'class' => 'size-3.5'])<span class="{{ ! empty($compact) ? '' : 'sr-only' }}">Przejmij</span>
	</button>
	@include('ubr.partials.row-actions', ['var' => $var, 'save' => $save ?? false, 'searchExpr' => $searchExpr ?? 'null'])
</div>
