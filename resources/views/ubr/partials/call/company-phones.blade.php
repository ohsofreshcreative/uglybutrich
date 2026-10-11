{{--
	Telefony firmy (karta firmy): numery z oficjalnej strony (wspólne; źródło, strona, data, pewność, oddział) i numery dodane
	ręcznie przez organizację (prywatne, nigdy nie nadpisywane). Odświeżenie ręczne: strona główna + Kontakt (limit, SSRF).
--}}
<section class="rounded-xl border border-line p-4 space-y-3" x-data x-init="$store.ubr.loadPhones(c.id); $watch(() => c.id, (id) => id && $store.ubr.loadPhones(id))" aria-labelledby="ubr-phones-title">
	<div class="flex flex-wrap items-center gap-2">
		<h3 id="ubr-phones-title" class="font-extrabold text-[14px] flex-1">Telefony firmy</h3>
		<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="$store.ubr.can('analysis.run') && c.domain" :disabled="$store.ubr.phones.busy" @click="$store.ubr.refreshPhones()">@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-3.5']) Odśwież ze strony</button>
		<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="$store.ubr.phones.data?.can_manage" @click="$store.ubr.phones.adding = !$store.ubr.phones.adding" :aria-expanded="$store.ubr.phones.adding">@include('ubr.partials.icon', ['name' => 'plus', 'class' => 'size-3.5']) Dodaj numer</button>
	</div>
	<template x-if="$store.ubr.phones.loading && !$store.ubr.phones.data"><div class="ubr-skeleton h-12"></div></template>
	<form class="grid gap-2 sm:grid-cols-[1fr_140px_1fr_auto]" x-show="$store.ubr.phones.adding" x-cloak @submit.prevent="$store.ubr.addPhone()">
		<input class="ubr-input" required maxlength="40" placeholder="+48 12 345 67 89" x-model="$store.ubr.phones.form.number" aria-label="Numer telefonu">
		<select class="ubr-input" x-model="$store.ubr.phones.form.type" aria-label="Rodzaj numeru">
			<option value="MAIN">Centrala</option><option value="SALES">Sprzedaż</option><option value="BRANCH">Oddział</option><option value="SUPPORT">Serwis / wsparcie</option><option value="OTHER">Inny</option>
		</select>
		<input class="ubr-input" maxlength="120" placeholder="Etykieta (opcjonalnie)" x-model="$store.ubr.phones.form.label" aria-label="Etykieta">
		<button type="submit" class="ubr-btn-primary" :disabled="$store.ubr.phones.busy">Zapisz</button>
	</form>
	<template x-if="$store.ubr.phones.error"><p class="text-[12px] text-danger font-semibold" role="alert" x-text="$store.ubr.phones.error"></p></template>
	<template x-if="$store.ubr.phones.data && !$store.ubr.phones.data.phones.length">
		<p class="text-[13px] text-ink-muted">Nie znaleziono numeru na stronie firmy<span x-show="!$store.ubr.phones.data.checked_at"> (strony jeszcze nie sprawdzano)</span>. Możesz dodać numer ręcznie.</p>
	</template>
	<ul class="space-y-2" x-show="$store.ubr.phones.data?.phones.length">
		<template x-for="ph in ($store.ubr.phones.data?.phones || [])" :key="ph.id">
			<li class="rounded-xl border border-line px-3 py-2 text-[13px] flex flex-wrap items-start gap-2">
				<div class="min-w-0 flex-1">
					<div class="flex flex-wrap items-center gap-1.5">
						<span class="font-bold tabular-nums" x-text="ph.number"></span>
						<button type="button" class="grid place-items-center size-8 rounded-md text-ink-muted hover:text-ink hover:bg-canvas" @click="$store.ubr.copyPhone(ph)" :aria-label="'Kopiuj numer ' + ph.number">@include('ubr.partials.icon', ['name' => 'copy', 'class' => 'size-3.5'])</button>
						<span class="ubr-badge-declared" x-text="ph.type_label"></span>
						<span class="ubr-badge-confirmed" x-show="$store.ubr.phones.data?.main?.id === ph.id">główny</span>
						<span class="ubr-badge-unavailable" x-show="ph.manual">ręczny · tylko Twoja organizacja</span>
						<span class="text-[11px] text-ink-muted" x-show="ph.branch" x-text="ph.branch"></span>
					</div>
					<div class="text-[11px] text-ink-muted mt-0.5">
						<span x-text="'Źródło: ' + ph.source_label"></span>
						<template x-if="ph.source_url"><span> · <a :href="$store.ubr.safeHref(ph.source_url)" target="_blank" rel="noopener nofollow" class="hover:underline">strona</a></span></template>
						<span x-show="ph.last_seen_at" x-text="' · ' + $store.ubr.date(ph.last_seen_at)"></span>
						<span x-show="ph.confidence" x-text="' · pewność: ' + ({ high: 'wysoka', medium: 'średnia', low: 'niska' }[ph.confidence] || ph.confidence)"></span>
					</div>
				</div>
				<button type="button" class="grid place-items-center size-8 rounded-md text-ink-muted hover:text-ink hover:bg-canvas" @click="$store.ubr.copyPhone(ph)" :aria-label="'Kopiuj numer ' + ph.number">@include('ubr.partials.icon', ['name' => 'copy', 'class' => 'size-4'])</button>
				<button type="button" class="grid place-items-center size-8 rounded-md text-ink-muted hover:text-danger hover:bg-canvas" x-show="ph.manual && $store.ubr.phones.data?.can_manage" @click="$store.ubr.deletePhone(ph)" :aria-label="'Usuń numer dodany ręcznie ' + ph.number">@include('ubr.partials.icon', ['name' => 'trash', 'class' => 'size-4'])</button>
			</li>
		</template>
	</ul>
	<p class="text-[11px] text-ink-muted" x-show="$store.ubr.phones.data?.checked_at" x-text="'Strona sprawdzona: ' + $store.ubr.date($store.ubr.phones.data?.checked_at) + '. Kliknięcie numeru otwiera aplikację telefonu — rozmowa nie jest zapisywana automatycznie.'"></p>
</section>
