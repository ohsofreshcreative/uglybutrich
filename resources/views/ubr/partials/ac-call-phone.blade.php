{{--
	Action Center (wiersz kolejki): numer główny firmy do telefonu — link tel: (bez zapisu rozmowy) i kopiowanie.
	Bez numeru przy zakazie kontaktu. Gotowość z numerem ręcznym organizacji (bez numeru ze strony) → wskazówka „w karcie firmy”.
--}}
{{-- Prompt 10.2: potwierdzony numer decydenta (READY_DIRECT) przed numerem firmy; oba z tel: i kopiowaniem. --}}
<template x-if="it.call?.decision_maker?.phone && !it.call.dnc">
	<div class="mb-1 min-w-0">
		<div class="flex items-center gap-1 min-w-0">
			<template x-if="$store.ubr.safeTel(it.call.decision_maker.phone.tel)">
				<a :href="$store.ubr.safeTel(it.call.decision_maker.phone.tel)" class="font-bold tabular-nums whitespace-nowrap text-money hover:underline" x-text="it.call.decision_maker.phone.number" :aria-label="'Zadzwoń do ' + it.call.decision_maker.name + ': ' + it.call.decision_maker.phone.number"></a>
			</template>
			<button type="button" class="shrink-0 grid place-items-center size-9 xl:size-7 rounded-md text-ink-muted hover:text-ink hover:bg-canvas" @click="$store.ubr.copyPhone(it.call.decision_maker.phone)" :aria-label="'Kopiuj numer ' + it.call.decision_maker.phone.number">
				@include('ubr.partials.icon', ['name' => 'copy', 'class' => 'size-3.5'])
			</button>
		</div>
		<div class="text-[10px] text-money truncate" :title="it.call.decision_maker.name + (it.call.decision_maker.title ? ', ' + it.call.decision_maker.title : '')" x-text="'Decydent: ' + it.call.decision_maker.name + ' · ' + (it.call.decision_maker.phone.type_label || 'potwierdzony')"></div>
	</div>
</template>
<template x-if="it.call?.main_phone && !it.call.dnc">
	<div class="mb-1 flex items-center gap-1 min-w-0">
		<template x-if="$store.ubr.safeTel(it.call.main_phone.tel)">
			<a :href="$store.ubr.safeTel(it.call.main_phone.tel)" class="font-bold tabular-nums whitespace-nowrap hover:underline" x-text="it.call.main_phone.number" :aria-label="'Zadzwoń do firmy ' + it.company.name + ': ' + it.call.main_phone.number"></a>
		</template>
		<button type="button" class="shrink-0 grid place-items-center size-9 xl:size-7 rounded-md text-ink-muted hover:text-ink hover:bg-canvas" @click="$store.ubr.copyPhone(it.call.main_phone)" :aria-label="'Kopiuj numer ' + it.call.main_phone.number">
			@include('ubr.partials.icon', ['name' => 'copy', 'class' => 'size-3.5'])
		</button>
		<span class="text-[10px] text-ink-muted" x-text="it.call.decision_maker ? 'firma' : 'centrala firmy'"></span>
	</div>
</template>
<template x-if="queue.key === 'ready_to_call' && !it.call?.main_phone && !it.call?.decision_maker">
	<div class="mb-1 text-ink-muted" x-text="(it.call?.readiness?.label_pl || 'Gotowe do telefonu') + ' · numer w karcie firmy'"></div>
</template>
