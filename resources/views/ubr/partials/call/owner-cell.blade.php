{{-- OPIEKUN / CRM: stan przypisania (wolna / moja / ma opiekuna), opiekun, etap pipeline, ostatni kontakt, zakaz kontaktu. --}}
<div class="min-w-0 text-[12px] leading-tight" x-show="{{ $var }}.call">
	<div class="flex flex-wrap items-center gap-1">
		<span :class="{ UNASSIGNED: 'ubr-badge-unavailable', MINE: 'ubr-badge-confirmed', ASSIGNED_OTHER: 'ubr-badge-uncertain' }[{{ $var }}.call?.ownership.state]" x-text="{{ $var }}.call?.ownership.label"></span>
		<span class="ubr-badge-danger" x-show="{{ $var }}.call?.ownership.dnc" :title="{{ $var }}.call?.ownership.dnc_reason || ''">Nie kontaktować</span>
	</div>
	<div class="mt-1 font-semibold line-clamp-1" x-show="{{ $var }}.call?.ownership.owner_name" x-text="{{ $var }}.call?.ownership.owner_name" :title="'Opiekun: ' + ({{ $var }}.call?.ownership.owner_name || '')"></div>
	<button type="button" class="mt-0.5 text-[11px] text-ink-soft hover:underline text-left" x-show="{{ $var }}.call?.ownership.pipeline_label" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany({{ $var }}.id)" x-text="{{ $var }}.call?.ownership.pipeline_label" title="Otwórz sekcję Sprzedaż"></button>
	<div class="text-[10px] text-ink-muted mt-0.5" x-text="{{ $var }}.call?.ownership.last_contact_at ? 'Kontakt: ' + $store.ubr.date({{ $var }}.call.ownership.last_contact_at) : 'Bez kontaktu'"></div>
</div>
