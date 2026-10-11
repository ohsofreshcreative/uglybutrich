{{--
	Prompt 10.3 — „Decydenci w Apollo” (karta firmy → Decision Makers).
	Otwarcie karty czyta kandydatów WYŁĄCZNIE z bazy. Zapytanie do Apollo (People API Search) tylko po kliknięciu
	„Szukaj decydentów w Apollo” z zaznaczoną zgodą (serwer bez `confirm` nic nie wysyła; najpierw pamięć podręczna).
	Zamaskowane nazwisko nie potwierdza tożsamości: STRONG / POSSIBLE wymagają decyzji użytkownika, NO_MATCH / CONFLICT
	nie mogą być zatwierdzone. Identyfikatory Apollo nie trafiają do przeglądarki. Tekst z Apollo wyłącznie przez x-text.
--}}
<section class="rounded-xl border border-line p-4" data-apollo-section x-data="{ get a() { return $store.ubr.drawer.apollo }, linkTo: {} }">
	<div class="flex flex-wrap items-start gap-3">
		<div class="min-w-0 flex-1">
			<h3 class="font-bold text-[13px]">Decydenci w Apollo</h3>
			<p class="text-[12px] text-ink-muted mt-0.5">Osoby z Apollo People API Search dla domeny firmy i ich dopasowanie do kontaktów. Apollo często maskuje nazwiska — maska nie potwierdza tożsamości, dlatego dopasowanie wymaga Twojej decyzji. Bez e-maili i numerów.</p>
		</div>
		<span class="text-[11px] font-semibold" :class="a?.enabled ? 'text-money' : 'text-ink-muted'" x-show="a && !a.error" x-text="a?.enabled ? 'Wyszukiwanie włączone' : (a?.configured ? 'Wyszukiwanie wyłączone' : 'Apollo nieskonfigurowane')"></span>
	</div>

	<template x-if="!a"><div class="ubr-skeleton h-12 mt-3"></div></template>
	<template x-if="a?.error"><p class="mt-3 text-[12px] text-danger" role="alert" x-text="a.error"></p></template>

	<template x-if="a && !a.error">
		<div class="mt-3 space-y-3">
			<div class="flex flex-wrap items-end gap-3" x-show="a.can_search">
				<label class="flex items-start gap-2 text-[12px] min-w-0 flex-1 basis-64">
					<input type="checkbox" class="ubr-checkbox mt-0.5" x-model="$store.ubr.drawer.apolloConsent" data-apollo-consent>
					<span x-text="'Zgoda na jedno zapytanie do Apollo. ' + a.cost_note"></span>
				</label>
				<button type="button" class="ubr-btn-ghost" :disabled="$store.ubr.drawer.apolloBusy" @click="$store.ubr.searchApollo(a.company.id)" data-apollo-search>
					@include('ubr.partials.icon', ['name' => 'users', 'class' => 'size-3.5'])
					<span x-text="$store.ubr.drawer.apolloBusy ? 'Szukanie…' : 'Szukaj decydentów w Apollo'"></span>
				</button>
			</div>
			<p class="text-[12px] rounded-lg px-3 py-2" x-show="a.search?.message" role="status"
				:class="{ error: 'bg-danger-soft text-danger', disabled: 'bg-canvas text-ink-soft', confirm_required: 'bg-gold-soft text-gold-deep' }[a.search?.state] || 'bg-money-soft text-ink-soft'" x-text="a.search?.message"></p>

			<p class="text-[12px] text-ink-muted" x-show="!a.candidates.length">Brak kandydatów z Apollo dla tej firmy.</p>

			<ul class="space-y-2" x-show="a.candidates.length">
				<template x-for="c in a.candidates" :key="c.id">
					<li class="rounded-lg border border-line px-3 py-2.5 text-[12px] min-w-0" :data-apollo-candidate="c.top?.level || ''">
						<div class="flex flex-wrap items-center gap-x-2 gap-y-1">
							<span class="font-bold [overflow-wrap:anywhere]" x-text="c.display_name"></span>
							<span class="text-[11px] text-ink-muted" x-show="c.name_masked" title="Apollo ukrywa część nazwiska — nie odtwarzamy go">(nazwisko zamaskowane przez Apollo)</span>
							<span class="text-ink-soft [overflow-wrap:anywhere]" x-text="c.title ? '· ' + c.title : '· bez stanowiska'"></span>
						</div>
						<div class="flex flex-wrap items-center gap-1.5 mt-1">
							<template x-if="c.top">
								<span :class="{ EXACT: 'ubr-badge-confirmed', STRONG: 'ubr-badge-declared', POSSIBLE: 'ubr-badge-uncertain', NO_MATCH: 'ubr-badge-unavailable', CONFLICT: 'ubr-badge-danger' }[c.top.level]" x-text="c.top.level_label"></span>
							</template>
							<template x-if="c.top && c.top.contact_id">
								<span class="text-ink-soft"><span x-text="c.top.status_label"></span>: <span class="font-semibold" x-text="c.top.contact_name"></span></span>
							</template>
							<span class="text-danger font-semibold" x-show="!c.belongs_to_company">Apollo przypisuje tę osobę do innej firmy</span>
						</div>
						<div class="text-[11px] text-ink-muted mt-1">
							<span x-text="c.phone_availability_label"></span>
							<span x-text="' · Źródło: Apollo · Sprawdzono: ' + $store.ubr.date(c.last_seen_at)"></span>
						</div>

						<div class="flex flex-wrap items-center gap-1.5 mt-2" x-show="a.can_decide && c.belongs_to_company">
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="c.top?.can_approve" @click="$store.ubr.apolloDecide(c.top, 'approve')" :aria-label="'Zatwierdź dopasowanie: ' + c.display_name + ' → ' + (c.top?.contact_name || '')">Zatwierdź</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="c.top?.can_reject && c.top?.status !== 'APPROVED'" @click="$store.ubr.apolloDecide(c.top, 'reject')">Odrzuć dopasowanie</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="c.top?.can_detach" @click="$store.ubr.apolloDecide(c.top, 'detach')">Odłącz</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="c.top" @click="$store.ubr.apolloDecide(c.top, 'recheck')" title="Ponowne porównanie z kontaktami — bez zapytania do Apollo">Sprawdź ponownie</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="c.approved" @click="$store.ubr.openReveal(c.approved.contact_id, a.company.id)">Sprawdź możliwość odblokowania telefonu</button>
							<template x-if="!c.approved && a.contacts.length">
								<span class="inline-flex flex-wrap items-center gap-1.5 max-w-full">
									<select class="ubr-input h-8 text-[12px] w-auto max-w-full" x-model="linkTo[c.id]" :aria-label="'Połącz kandydata Apollo z kontaktem: ' + c.display_name">
										<option value="">Połącz z kontaktem…</option>
										<template x-for="k in a.contacts" :key="k.id"><option :value="k.id" x-text="k.name + (k.title ? ' · ' + k.title : '')"></option></template>
									</select>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="!linkTo[c.id]" @click="$store.ubr.apolloLink(c, linkTo[c.id]); linkTo[c.id] = ''">Połącz</button>
								</span>
							</template>
						</div>

						<details class="mt-2">
							<summary class="cursor-pointer text-[11px] font-semibold text-ink-soft">Zobacz szczegóły</summary>
							<div class="mt-1 space-y-2">
								<template x-for="m in c.matches" :key="m.id">
									<div class="text-[11px]">
										<div><span class="font-semibold" x-text="m.contact_name || 'Brak kontaktu'"></span> — <span x-text="m.level_label"></span> · <span x-text="m.status_label"></span><span class="text-ink-muted" x-show="m.decided_by_system"> (automatycznie)</span><span class="text-ink-muted" x-show="m.source === 'MANUAL'"> (połączone ręcznie)</span></div>
										<ul class="list-disc pl-5 text-ink-muted"><template x-for="(e, i) in m.evidence" :key="i"><li x-text="e"></li></template></ul>
										<div class="text-ink-muted" x-text="'Decyzja: ' + (m.decided_at ? $store.ubr.date(m.decided_at) : '—') + ' · Weryfikacja: ' + (m.last_verified_at ? $store.ubr.date(m.last_verified_at) : '—')"></div>
										<div class="flex gap-1.5 mt-1" x-show="a.can_decide && m.id !== c.top?.id">
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="m.can_approve" @click="$store.ubr.apolloDecide(m, 'approve')">Zatwierdź</button>
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="m.can_reject && m.status !== 'APPROVED'" @click="$store.ubr.apolloDecide(m, 'reject')">Odrzuć</button>
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="m.can_detach" @click="$store.ubr.apolloDecide(m, 'detach')">Odłącz</button>
										</div>
									</div>
								</template>
								<div class="text-[11px] text-ink-muted" x-text="'Znaleziono: ' + $store.ubr.date(c.found_at)"></div>
							</div>
						</details>
					</li>
				</template>
			</ul>
		</div>
	</template>
</section>
