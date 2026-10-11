{{--
	Prompt 10.4.3 — przejęta firma: analiza decydenta (Decision Maker Finder po przejęciu) i synchronizacja ClickUp.
	Statusy z istniejących danych (etap analizy, Top 1, powiązania telefonów, eksport ClickUp). Odczyt niczego nie uruchamia;
	„Ponów wyszukiwanie decydenta” — zabezpieczone przed wielokrotnym uruchomieniem (serwer).
--}}
<section class="rounded-xl border border-line p-4 space-y-3" x-show="c.prospect_id" data-claim-panel aria-label="Przejęta firma — decydent i ClickUp">
	<div class="flex flex-wrap items-center justify-between gap-2">
		<h3 class="font-extrabold text-[14px]">Decydent i ClickUp</h3>
		<template x-if="$store.ubr.drawer.claim?.research">
			<span :class="$store.ubr.stageBadge($store.ubr.claimTone($store.ubr.drawer.claim.research.key))" data-claim-status :data-claim-key="$store.ubr.drawer.claim.research.key" x-text="$store.ubr.drawer.claim.research.label"></span>
		</template>
	</div>

	<template x-if="!$store.ubr.drawer.claim">
		<p class="text-[12px] text-ink-muted">Wczytywanie statusu…</p>
	</template>
	<template x-if="$store.ubr.drawer.claim?.error">
		<p class="text-[12px] text-danger" x-text="$store.ubr.drawer.claim.error"></p>
	</template>

	<template x-if="$store.ubr.drawer.claim?.research">
		<div class="space-y-2 text-[13px]" x-data="{ get r() { return $store.ubr.drawer.claim.research } }">
			<p class="text-[12px] text-danger" x-show="r.blocked" x-text="r.blocked?.message"></p>
			<dl class="grid grid-cols-1 min-[480px]:grid-cols-2 gap-x-4 gap-y-2">
				<div>
					<dt class="text-ink-muted text-[12px]">Decydent</dt>
					<dd data-claim-dm>
						<template x-if="r.decision_maker">
							<span><strong x-text="r.decision_maker.name"></strong><span class="text-ink-soft" x-show="r.decision_maker.title" x-text="' — ' + r.decision_maker.title"></span></span>
						</template>
						<span x-show="!r.decision_maker" class="text-ink-muted">—</span>
					</dd>
				</div>
				<div>
					<dt class="text-ink-muted text-[12px]">Pewność / weryfikacja</dt>
					<dd x-text="r.decision_maker ? ((r.decision_maker.confidence || '—') + ' · ' + (r.decision_maker.verification?.label || '—')) : '—'"></dd>
				</div>
				<div>
					<dt class="text-ink-muted text-[12px]">Telefon bezpośredni</dt>
					<dd data-claim-direct>
						<template x-if="r.direct_phone">
							<span class="inline-flex flex-wrap items-center gap-1.5">
								<span class="font-mono" x-text="r.direct_phone.number"></span>
								<span :class="r.direct_phone.confirmed ? 'ubr-badge-confirmed' : 'ubr-badge-uncertain'" x-text="r.direct_phone.status_label"></span>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm !px-2" @click="$store.ubr.copyText(r.direct_phone.e164, 'numer')" :aria-label="'Kopiuj numer ' + r.direct_phone.number">Kopiuj</button>
							</span>
						</template>
						<span x-show="!r.direct_phone" class="text-ink-muted">nie znaleziono</span>
					</dd>
				</div>
				<div>
					<dt class="text-ink-muted text-[12px]">Telefon firmowy</dt>
					<dd class="font-mono" x-text="r.company_phone || '—'"></dd>
				</div>
				<div>
					<dt class="text-ink-muted text-[12px]">Źródła</dt>
					<dd class="text-[12px]">
						<template x-for="(s, i) in (r.decision_maker?.sources || [])" :key="i">
							<span class="mr-2"><a x-show="s.url" :href="s.url" target="_blank" rel="noopener noreferrer nofollow" class="underline" x-text="s.label"></a><span x-show="!s.url" x-text="s.label"></span></span>
						</template>
						<span x-show="!(r.decision_maker?.sources || []).length" class="text-ink-muted">—</span>
					</dd>
				</div>
				<div>
					<dt class="text-ink-muted text-[12px]">Ostatnia analiza</dt>
					<dd x-text="r.last_run_at ? $store.ubr.date(r.last_run_at) : (r.active ? 'w toku' : '—')"></dd>
				</div>
			</dl>
			<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="r.can_retry" :disabled="$store.ubr.drawer.claimBusy" @click="$store.ubr.retryClaimResearch()" data-claim-retry>Ponów wyszukiwanie decydenta</button>
			<p class="text-[11px] text-ink-muted">Bez płatnych zapytań Apollo. Numer bezpośredni trafia do ClickUp tylko z potwierdzonym powiązaniem z osobą.</p>
		</div>
	</template>

	<template x-if="$store.ubr.drawer.claim?.clickup">
		<div class="pt-3 border-t border-line flex flex-wrap items-center gap-2 text-[13px]" data-claim-clickup x-data="{ get k() { return $store.ubr.drawer.claim.clickup } }">
			<span class="font-semibold">ClickUp:</span>
			<span :class="$store.ubr.stageBadge($store.ubr.clickupTone(k.key))" :data-clickup-key="k.key" x-text="k.label"></span>
			<a x-show="k.task_url" :href="k.task_url" target="_blank" rel="noopener noreferrer" class="underline text-[12px]">Otwórz zadanie</a>
			<span x-show="k.message" class="text-[12px] text-ink-muted w-full" x-text="k.message"></span>
			<template x-for="(cf, i) in (k.conflicts || [])" :key="i">
				<span class="text-[11px] text-gold-deep w-full" x-text="'Konflikt: pole ' + cf.field + ' zmienione ręcznie w ClickUp — nie nadpisano.'"></span>
			</template>
		</div>
	</template>
</section>
