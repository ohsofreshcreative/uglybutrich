{{--
	Karta firmy Cold Calling (< 1280 px; wspólna dla Discover i Prospects): firma, status kontaktu, telefony z dużymi celami
	dotyku, decydent, opiekun / CRM, wielkość, strona WWW (Podgląd), ocena i szybkie akcje z etykietami.
	Parametry: $var, $save, $searchExpr, $select (wyrażenie JS: czy pokazać checkbox; null = bez), $selectModel.
--}}
<div class="flex items-start gap-3">
	@if (! empty($select))
		<input type="checkbox" class="ubr-checkbox mt-1 shrink-0" x-show="{{ $select }}" :value="{{ $var }}.id" x-model.number="{{ $selectModel ?? 'selected' }}" :aria-label="'Zaznacz ' + {{ $var }}.name">
	@endif
	<div class="min-w-0 flex-1">@include('ubr.partials.discover-company-cell', ['var' => $var, 'hideSize' => true])</div>
	<div class="shrink-0">@include('ubr.partials.call.status-cell', ['var' => $var])</div>
</div>

<div class="mt-3 grid grid-cols-1 min-[480px]:grid-cols-2 md:grid-cols-3 gap-3">
	<div class="min-w-0">
		<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Telefony</div>
		@include('ubr.partials.call.phones-cell', ['var' => $var])
	</div>
	<div class="min-w-0">
		<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Decydent</div>
		@include('ubr.partials.call.dm-cell', ['var' => $var])
	</div>
	<div class="min-w-0">
		<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Opiekun / CRM</div>
		@include('ubr.partials.call.owner-cell', ['var' => $var])
	</div>
	<div class="min-w-0">
		<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Wielkość</div>
		@include('ubr.partials.call.size-cell', ['var' => $var])
	</div>
	<div class="min-w-0">
		<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Strona WWW</div>
		@include('ubr.partials.call.website-cell', ['var' => $var])
	</div>
	<div class="min-w-0">
		<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Ocena</div>
		@include('ubr.partials.lead-cell', ['var' => $var])
	</div>
</div>

<div class="mt-3">
	@include('ubr.partials.call.quick-actions', ['var' => $var, 'save' => $save ?? false, 'searchExpr' => $searchExpr ?? 'null', 'compact' => true])
</div>
