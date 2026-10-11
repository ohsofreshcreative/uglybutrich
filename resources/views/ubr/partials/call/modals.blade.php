{{--
	Okna Cold Calling (wspólne dla Discover, Prospects i karty firmy), sterowane przez $store.ubr.outcome / $store.ubr.reveal.
	Dostępność: role=dialog, aria-modal, etykieta, pułapka fokusu, Escape, przywrócenie fokusu na przycisk wywołujący.
--}}

{{-- Zapis wyniku rozmowy (świadomy — kliknięcie tel: niczego nie zapisuje). Trafia do istniejącej historii aktywności. --}}
<div x-show="$store.ubr.outcome.open" x-cloak class="fixed inset-0 z-[65] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="ubr-outcome-title"
	@keydown.escape.stop.prevent="$store.ubr.closeOutcome()" @keydown.tab="$store.ubr.trapFocus($event, $el)">
	<div class="absolute inset-0 bg-ink/60" @click="$store.ubr.closeOutcome()"></div>
	<form class="relative w-full max-w-[440px] rounded-[16px] bg-white shadow-[var(--shadow-pop)] p-5 space-y-4" @submit.prevent="$store.ubr.saveOutcome()">
		<header>
			<h2 id="ubr-outcome-title" class="font-extrabold text-[16px]">Wynik rozmowy</h2>
			<p class="text-[12px] text-ink-muted mt-0.5" x-text="$store.ubr.outcome.company?.name"></p>
		</header>
		<fieldset>
			<legend class="ubr-label">Jak poszło?</legend>
			<div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
				<template x-for="(label, key, i) in $store.ubr.callOutcomes" :key="key">
					<label class="flex items-center gap-2 rounded-xl border px-3 h-11 cursor-pointer text-[13px] font-semibold" :class="$store.ubr.outcome.value === key ? 'border-ink bg-canvas' : 'border-line hover:border-ink/30'">
						<input type="radio" name="ubr-outcome" class="ubr-checkbox !rounded-full" :value="key" x-model="$store.ubr.outcome.value" :data-outcome-first="key === 'no_answer' ? '' : null">
						<span x-text="label"></span>
					</label>
				</template>
			</div>
		</fieldset>
		<div>
			<label class="ubr-label" for="ubr-outcome-note">Notatka (opcjonalnie)</label>
			<textarea id="ubr-outcome-note" class="ubr-input min-h-[80px]" maxlength="3000" x-model="$store.ubr.outcome.note" placeholder="np. prosi o telefon w czwartek po 10:00"></textarea>
		</div>
		<p class="text-[11px] text-ink-muted leading-snug">Wynik zapisze się w historii aktywności firmy. Wolna firma zostanie przypisana do Ciebie, a etap pipeline przesunie się tylko do przodu (np. Nowy → Skontaktowano).</p>
		<template x-if="$store.ubr.outcome.error"><p class="rounded-lg bg-danger-soft text-danger px-3 py-2 text-[12px] font-semibold" role="alert" x-text="$store.ubr.outcome.error"></p></template>
		<div class="flex flex-wrap justify-end gap-2">
			<button type="button" class="ubr-btn-ghost" @click="$store.ubr.closeOutcome()">Anuluj</button>
			<button type="submit" class="ubr-btn-primary" :disabled="$store.ubr.outcome.saving || !$store.ubr.outcome.value" x-text="$store.ubr.outcome.saving ? 'Zapisywanie…' : 'Zapisz wynik'"></button>
		</div>
	</form>
</div>

{{-- „Odblokuj telefon” (Apollo, płatne): sprawdzenie warunków → informacja o koszcie → osobne potwierdzenie → zlecenie. --}}
<div x-show="$store.ubr.reveal.open" x-cloak class="fixed inset-0 z-[65] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="ubr-reveal-title"
	@keydown.escape.stop.prevent="$store.ubr.closeReveal()" @keydown.tab="$store.ubr.trapFocus($event, $el)">
	<div class="absolute inset-0 bg-ink/60" @click="$store.ubr.closeReveal()"></div>
	<section class="relative w-full max-w-[480px] rounded-[16px] bg-white shadow-[var(--shadow-pop)] p-5 space-y-4">
		<header class="flex items-start gap-3">
			<div class="min-w-0 flex-1">
				<h2 id="ubr-reveal-title" class="font-extrabold text-[16px]">Odblokuj telefon decydenta</h2>
				<p class="text-[12px] text-ink-muted mt-0.5">Źródło: Apollo. Zapytanie płatne — wysyłane dopiero po Twoim potwierdzeniu.</p>
			</div>
			<button type="button" data-reveal-close class="ubr-btn-ghost ubr-btn-sm px-2" @click="$store.ubr.closeReveal()" aria-label="Zamknij">@include('ubr.partials.icon', ['name' => 'x', 'class' => 'size-4'])</button>
		</header>

		<template x-if="$store.ubr.reveal.loading"><div class="ubr-skeleton h-24"></div></template>
		<template x-if="$store.ubr.reveal.error"><p class="rounded-lg bg-danger-soft text-danger px-3 py-2 text-[12px] font-semibold" role="alert" x-text="$store.ubr.reveal.error"></p></template>

		<template x-if="$store.ubr.reveal.data && !$store.ubr.reveal.result">
			<div class="space-y-3 text-[13px]">
				<div class="rounded-xl border border-line px-3 py-2.5">
					<div class="text-[11px] font-bold uppercase tracking-wide text-ink-muted">Stan numeru</div>
					<div class="font-semibold mt-0.5" x-text="$store.ubr.reveal.data.phone.status_label"></div>
				</div>
				<template x-if="$store.ubr.reveal.data.reasons.length">
					<ul class="rounded-xl bg-gold-soft text-gold-deep px-3 py-2.5 text-[12px] space-y-1 list-disc pl-6">
						<template x-for="r in $store.ubr.reveal.data.reasons" :key="r.code"><li x-text="r.message"></li></template>
					</ul>
				</template>
				<template x-if="$store.ubr.reveal.data.can_unlock">
					<div class="space-y-3">
						<div class="rounded-xl bg-danger-soft/60 px-3 py-2.5 text-[12px] leading-snug">
							<div class="font-bold text-danger" x-text="$store.ubr.reveal.data.cost.label || 'Koszt wymaga potwierdzenia'"></div>
							<p class="mt-0.5 text-ink-soft" x-text="$store.ubr.reveal.data.cost.message"></p>
							<p class="mt-1 text-ink-muted" x-text="$store.ubr.reveal.data.delivery"></p>
						</div>
						<label class="flex items-start gap-2 text-[12px]">
							<input type="checkbox" class="ubr-checkbox mt-0.5" x-model="$store.ubr.reveal.ack">
							<span>Rozumiem, że koszt wymaga potwierdzenia i odblokowanie może zużyć kredyty Apollo.</span>
						</label>
					</div>
				</template>
			</div>
		</template>

		<template x-if="$store.ubr.reveal.result">
			<div class="rounded-xl border border-line px-3 py-2.5 text-[13px]" role="status">
				<div class="font-semibold" x-text="$store.ubr.reveal.result.message"></div>
				<div class="text-[11px] text-ink-muted mt-1" x-show="$store.ubr.reveal.result.credits_consumed !== null" x-text="'Zużyte kredyty (wg Apollo): ' + $store.ubr.reveal.result.credits_consumed"></div>
			</div>
		</template>

		<div class="flex flex-wrap justify-end gap-2">
			<button type="button" class="ubr-btn-ghost" @click="$store.ubr.closeReveal()" x-text="$store.ubr.reveal.result ? 'Zamknij' : 'Anuluj'"></button>
			<button type="button" class="ubr-btn-primary" x-show="$store.ubr.reveal.data?.can_unlock && !$store.ubr.reveal.result"
				:disabled="!$store.ubr.reveal.ack || $store.ubr.reveal.busy" @click="$store.ubr.confirmReveal()" x-text="$store.ubr.reveal.busy ? 'Zlecanie…' : 'Odblokuj (płatne)'"></button>
		</div>
	</section>
</div>
