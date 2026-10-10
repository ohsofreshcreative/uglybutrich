{{-- Domena + status potwierdzenia; długi adres skracany (pełny w title i po kliknięciu). --}}
<template x-if="{{ $var }}.domain">
	<div class="min-w-0">
		<a :href="$store.ubr.safeHref({{ $var }}.website_url)" target="_blank" rel="noopener noreferrer nofollow" class="block truncate text-ink-soft hover:underline" x-text="{{ $var }}.domain" :title="{{ $var }}.website_url || {{ $var }}.domain"></a>
		<div class="mt-1"><span :class="$store.ubr.domainBadge({{ $var }}.domain_status.status)" x-text="{{ $var }}.domain_status.label"></span></div>
	</div>
</template>
<span x-show="!{{ $var }}.domain" class="text-ink-muted">brak domeny</span>
