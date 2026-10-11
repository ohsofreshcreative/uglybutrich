{{--
	TELEFONY: numer firmy (główny — centrala > kontakt > stopka) i numery decydenta. Link tel: tylko gdy kontakt dozwolony
	(bez zakazu kontaktu / blokady / cudzego opiekuna); kliknięcie tel: NIE zapisuje rozmowy. Kopiowanie zawsze dostępne.
--}}
<div class="min-w-0 space-y-1.5 text-[12px] leading-tight">
	<template x-if="{{ $var }}.call?.company_phone">
		<div class="min-w-0">
			<div class="flex items-center gap-1">
				<template x-if="{{ $var }}.call.actions.can_call && $store.ubr.safeTel({{ $var }}.call.company_phone.tel)">
					<a :href="$store.ubr.safeTel({{ $var }}.call.company_phone.tel)" class="font-bold tabular-nums whitespace-nowrap hover:underline" x-text="{{ $var }}.call.company_phone.number" :aria-label="'Zadzwoń do firmy ' + {{ $var }}.name + ': ' + {{ $var }}.call.company_phone.number"></a>
				</template>
				<template x-if="!{{ $var }}.call.actions.can_call">
					<span class="font-bold tabular-nums whitespace-nowrap text-ink-soft" x-text="{{ $var }}.call.company_phone.number"></span>
				</template>
				<button type="button" class="shrink-0 grid place-items-center size-9 xl:size-7 rounded-md text-ink-muted hover:text-ink hover:bg-canvas" @click="$store.ubr.copyPhone({{ $var }}.call.company_phone)" :aria-label="'Kopiuj numer ' + {{ $var }}.call.company_phone.number">
					@include('ubr.partials.icon', ['name' => 'copy', 'class' => 'size-3.5'])
				</button>
			</div>
			<div class="text-[10px] text-ink-muted" x-text="'firma · ' + {{ $var }}.call.company_phone.type_label + ({{ $var }}.call.company_phone.branch ? ' · ' + {{ $var }}.call.company_phone.branch : '') + ({{ $var }}.call.company_phones_count > 1 ? ' · +' + ({{ $var }}.call.company_phones_count - 1) + ' inne' : '')" :title="'Źródło: ' + {{ $var }}.call.company_phone.source_label"></div>
		</div>
	</template>
	<template x-for="n in ({{ $var }}.call?.decision_maker?.phone?.numbers || [])" :key="n.e164">
		<div class="min-w-0">
			<div class="flex items-center gap-1">
				<template x-if="{{ $var }}.call.actions.can_call && $store.ubr.safeTel(n.tel)">
					<a :href="$store.ubr.safeTel(n.tel)" class="font-bold tabular-nums whitespace-nowrap hover:underline" :class="n.verified ? 'text-money' : 'text-gold-deep'" x-text="n.number" :aria-label="'Zadzwoń do ' + {{ $var }}.call.decision_maker.name + ': ' + n.number + (n.verified ? '' : ' (numer do weryfikacji)')"></a>
				</template>
				<template x-if="!{{ $var }}.call.actions.can_call">
					<span class="font-bold tabular-nums whitespace-nowrap text-ink-soft" x-text="n.number"></span>
				</template>
				<button type="button" class="shrink-0 grid place-items-center size-9 xl:size-7 rounded-md text-ink-muted hover:text-ink hover:bg-canvas" @click="$store.ubr.copyPhone(n)" :aria-label="'Kopiuj numer ' + n.number">
					@include('ubr.partials.icon', ['name' => 'copy', 'class' => 'size-3.5'])
				</button>
			</div>
			<div class="text-[10px]" :class="n.verified ? 'text-money' : 'text-gold-deep'" x-text="'decydent · ' + n.type_label" :title="n.evidence_label || ''"></div>
		</div>
	</template>
	<template x-if="!{{ $var }}.call?.company_phone && !({{ $var }}.call?.decision_maker?.phone?.numbers || []).length">
		<span class="text-ink-muted">brak numeru</span>
	</template>
</div>
