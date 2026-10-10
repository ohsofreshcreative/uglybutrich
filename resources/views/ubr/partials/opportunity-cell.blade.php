{{-- Opportunity Score + pewność + status analizy (zmienna: {{ $var }}). INSUFFICIENT_DATA = brak wyniku, nie zero. --}}
<div class="flex items-center gap-1.5">
	<span :class="$store.ubr.opportunityClass({{ $var }}.analysis.opportunity)" :title="{{ $var }}.analysis.level ? $store.ubr.levelLabel({{ $var }}.analysis.level) : 'Brak wyniku'"
		x-text="{{ $var }}.analysis.opportunity !== null ? {{ $var }}.analysis.opportunity : ({{ $var }}.analysis.level === 'insufficient' ? '?' : '—')"></span>
	<div class="leading-tight">
		<span class="text-[11px]" :class="$store.ubr.analysisBadge({{ $var }}.analysis.status)" x-text="{{ $var }}.analysis.label"></span>
		<div class="text-[10px] text-ink-muted mt-0.5" x-show="{{ $var }}.analysis.confidence && {{ $var }}.analysis.opportunity !== null"
			x-text="'pewność ' + $store.ubr.confLabel({{ $var }}.analysis.confidence) + ' · ' + ({{ $var }}.analysis.coverage ?? '—') + '% danych'"></div>
		<div class="text-[10px] text-ink-muted mt-0.5" x-show="{{ $var }}.analysis.level === 'insufficient'">za mało danych</div>
		<div class="text-[10px] text-ink-muted mt-0.5" x-show="{{ $var }}.analysis.findings_count" x-text="{{ $var }}.analysis.findings_count + ' problemów'"></div>
	</div>
</div>
