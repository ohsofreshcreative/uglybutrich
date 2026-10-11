<div class="flex items-center justify-between gap-3 px-5 py-3 text-[12px] text-ink-muted" x-show="{{ $state }}.total > {{ $state }}.per_page">
	<span>Strona <strong class="text-ink" x-text="{{ $state }}.page"></strong> z <strong class="text-ink" x-text="Math.max(1, Math.ceil({{ $state }}.total / {{ $state }}.per_page))"></strong><span class="hidden min-[480px]:inline" data-range x-text="' · ' + (({{ $state }}.page - 1) * {{ $state }}.per_page + 1) + '–' + Math.min({{ $state }}.total, {{ $state }}.page * {{ $state }}.per_page) + ' z ' + {{ $state }}.total"></span></span>
	<div class="flex gap-2">
		<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="{{ $state }}.page <= 1" @click="{{ $load }}({{ $state }}.page - 1)">Poprzednia</button>
		<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="{{ $state }}.page >= Math.ceil({{ $state }}.total / {{ $state }}.per_page)" @click="{{ $load }}({{ $state }}.page + 1)">Następna</button>
	</div>
</div>
