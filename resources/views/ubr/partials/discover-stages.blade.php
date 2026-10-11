{{--
	Prompt 10.4 — niezależne statusy etapów firmy w Discover (bez nowych kolumn): odznaka weryfikacji istnienia firmy
	(Oczekuje / W toku / Zweryfikowana / Niepewna / Niezgodność / Nieaktywna) oraz kropki: strona WWW (Website Analyzer),
	telefony firmy, decydent. Firma jest widoczna od razu po zapisaniu — niepewność danych zawsze jawnie oznaczona.
--}}
<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px]" data-stages x-show="{{ $var }}.stages">
	<span class="!whitespace-normal" :class="$store.ubr.stageBadge({{ $var }}.stages?.verification?.tone)" x-text="{{ $var }}.stages?.verification?.label" :title="{{ $var }}.stages?.verification?.title + ({{ $var }}.verification?.source ? ' Źródło: ' + {{ $var }}.verification.source : '')" data-stage="verification" :data-stage-key="{{ $var }}.stages?.verification?.key"></span>
	<template x-for="st in [['WWW', 'website'], ['Telefony', 'phones'], ['Decydent', 'dm']]" :key="st[1]">
		<span class="inline-flex items-center gap-1 text-ink-soft whitespace-nowrap" :title="({{ $var }}.stages?.[st[1]]?.title || '')" :data-stage="st[1]" :data-stage-key="{{ $var }}.stages?.[st[1]]?.key">
			<span class="size-1.5 rounded-full shrink-0" :class="$store.ubr.stageDot({{ $var }}.stages?.[st[1]]?.tone)" aria-hidden="true"></span>
			<span x-text="st[0] + ': ' + ({{ $var }}.stages?.[st[1]]?.label || '—')"></span>
		</span>
	</template>
</div>
