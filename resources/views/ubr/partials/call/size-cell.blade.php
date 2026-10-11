{{-- WIELKOŚĆ: przedział zatrudnienia (lub dokładna liczba), źródło danych; „Brak danych”, gdy źródła nie podają. --}}
<div class="text-[12px] leading-tight">
	<span class="font-semibold tabular-nums" :class="{{ $var }}.call?.size.known ? 'text-ink' : 'text-ink-muted font-normal'" x-text="{{ $var }}.call?.size.label || 'Brak danych'"></span>
	<div class="text-[10px] text-ink-muted mt-0.5" x-show="{{ $var }}.call?.size.source" x-text="'źródło: ' + ({{ $var }}.call?.size.source || '')"></div>
</div>
