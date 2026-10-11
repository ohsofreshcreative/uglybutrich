{{-- STRONA WWW (Cold Calling): domena, status potwierdzenia i widoczny przycisk „Podgląd” (wspólny modal podglądu). --}}
<template x-if="{{ $var }}.domain">
	<div class="min-w-0">
		<a :href="$store.ubr.safeHref({{ $var }}.website_url)" target="_blank" rel="noopener noreferrer nofollow" class="block truncate text-[12px] text-ink-soft hover:underline" x-text="{{ $var }}.domain" :title="{{ $var }}.website_url || {{ $var }}.domain"></a>
		<div class="mt-1 flex flex-wrap items-center gap-1">
			<span class="!whitespace-normal" :class="$store.ubr.domainBadge({{ $var }}.domain_status.status)" x-text="{{ $var }}.domain_status.label"></span>
		</div>
		<button type="button" class="ubr-btn-ghost ubr-btn-sm mt-1.5 !h-7 !px-2 text-[11px]" @click="$store.ubr.openPreview({{ $var }})" :aria-label="'Podgląd strony: ' + {{ $var }}.name">
			@include('ubr.partials.icon', ['name' => 'globe', 'class' => 'size-3.5']) Podgląd
		</button>
	</div>
</template>
<span x-show="!{{ $var }}.domain" class="text-[12px] text-ink-muted">brak strony</span>
