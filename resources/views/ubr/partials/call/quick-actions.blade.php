{{--
	Szybkie akcje Cold Calling (wspólne: Discover, Prospects): Zapisz wynik (świadomie), Przejmij (tylko wolna firma)
	+ menu ⋯ (podgląd, karta, analiza, decydenci, Prospects). Prompt 10.4: bez przycisku „Zadzwoń” (tel:) — numery
	kopiuje się w kolumnie Telefony; aplikacja nie wykonuje połączeń.
	Dla firm innego opiekuna i z zakazem kontaktu akcje kontaktowe są niedostępne (uprawnienia także po stronie serwera).
	Parametry: $var, $save, $searchExpr, $compact (karta mobilna — przyciski z etykietą).
--}}
<div class="flex flex-wrap items-center gap-1 {{ ! empty($compact) ? 'justify-start [&_.ubr-btn-sm]:!h-10' : 'justify-end' }}">
	<button type="button" class="ubr-btn-ghost ubr-btn-sm !px-2.5" x-show="{{ $var }}.call?.actions.can_record_outcome" @click="$store.ubr.openOutcome({{ $var }}, {{ $var }}.call.decision_maker?.contact_id || null)" :aria-label="'Zapisz wynik rozmowy: ' + {{ $var }}.name" title="Zapisz wynik rozmowy">
		@include('ubr.partials.icon', ['name' => 'note', 'class' => 'size-3.5'])<span class="{{ ! empty($compact) ? '' : 'sr-only' }}">Wynik</span>
	</button>
	<button type="button" class="ubr-btn-ghost ubr-btn-sm !px-2.5" x-show="{{ $var }}.call?.actions.can_claim" :disabled="!!{{ $var }}._claiming" @click="$store.ubr.claimCompany({{ $var }}, {{ $searchExpr ?? 'null' }})" :aria-label="'Przejmij firmę: ' + {{ $var }}.name" title="Przejmij firmę (dodaj do moich Prospects)">
		@include('ubr.partials.icon', ['name' => 'hand', 'class' => 'size-3.5'])<span class="{{ ! empty($compact) ? '' : 'sr-only' }}">Przejmij</span>
	</button>
	@include('ubr.partials.row-actions', ['var' => $var, 'save' => $save ?? false, 'searchExpr' => $searchExpr ?? 'null'])
</div>
