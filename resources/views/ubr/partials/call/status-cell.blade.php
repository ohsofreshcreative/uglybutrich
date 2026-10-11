{{-- STATUS KONTAKTU: Cold Call Readiness (odrębny od Lead Potential) + wynik ostatniej rozmowy. --}}
<div class="min-w-0 text-[12px] leading-tight" x-show="{{ $var }}.call">
	<span class="!whitespace-normal !rounded-md !leading-tight !py-1" :class="$store.ubr.readinessBadge({{ $var }}.call?.readiness.tone)" x-text="{{ $var }}.call?.readiness.label_pl" :title="{{ $var }}.call?.readiness.hint"></span>
	<div class="text-[11px] mt-1 font-semibold" x-show="{{ $var }}.call?.ownership.last_contact_outcome_label" x-text="{{ $var }}.call?.ownership.last_contact_outcome_label"></div>
	<div class="text-[10px] text-ink-muted mt-0.5" x-show="{{ $var }}.call?.actions.restricted_reason" x-text="{{ $var }}.call?.actions.restricted_reason"></div>
</div>
