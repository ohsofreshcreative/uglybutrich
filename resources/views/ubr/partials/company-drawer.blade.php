{{-- Szczegóły firmy (wspólne dla wszystkich ekranów). Dane z GET /companies/{id}. --}}
<div x-show="$store.ubr.drawer.open" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Szczegóły firmy">
	<div class="absolute inset-0 bg-ink/30" @click="$store.ubr.closeDrawer()" x-show="$store.ubr.drawer.open" x-transition.opacity></div>

	<section class="absolute inset-y-0 right-0 w-full bg-white shadow-[var(--shadow-pop)] flex flex-col transition-[max-width] duration-200" :class="['analysis', 'contacts', 'sales', 'lead'].includes($store.ubr.drawer.tab) ? 'max-w-[860px]' : 'max-w-[560px]'"
		x-show="$store.ubr.drawer.open"
		x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
		x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">

		<header class="flex items-start gap-3 px-6 pt-6 pb-4 border-b border-line">
			<div class="grid place-items-center size-11 rounded-xl bg-gold-soft text-gold-deep shrink-0">
				@include('ubr.partials.icon', ['name' => 'building', 'class' => 'size-5'])
			</div>
			<div class="min-w-0 flex-1">
				<template x-if="$store.ubr.drawer.loading">
					<div class="space-y-2 pt-1"><div class="ubr-skeleton h-5 w-56"></div><div class="ubr-skeleton h-3 w-32"></div></div>
				</template>
				<template x-if="!$store.ubr.drawer.loading && $store.ubr.drawer.company">
					<div>
						<h2 class="text-[18px] font-extrabold leading-snug" x-text="$store.ubr.drawer.company.name"></h2>
						<p class="text-[13px] text-ink-muted" x-text="[$store.ubr.drawer.company.city, $store.ubr.drawer.company.region && ('woj. ' + $store.ubr.drawer.company.region)].filter(Boolean).join(' · ') || 'Lokalizacja niedostępna'"></p>
					</div>
				</template>
			</div>
			<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2" @click="$store.ubr.closeDrawer()" aria-label="Zamknij">
				@include('ubr.partials.icon', ['name' => 'x'])
			</button>
		</header>

		<nav class="flex gap-1 px-6 border-b border-line overflow-x-auto" role="tablist" aria-label="Sekcje karty firmy" x-show="!$store.ubr.drawer.loading && $store.ubr.drawer.company">
			<template x-for="t in [{k: 'company', l: 'Firma'}, {k: 'lead', l: 'Lead Intelligence'}, {k: 'sales', l: 'Sprzedaż'}, {k: 'contacts', l: 'Decision Makers'}, {k: 'analysis', l: 'Website Analysis'}]" :key="t.k">
				<button type="button" role="tab" class="px-3 h-10 -mb-px border-b-2 text-[13px] font-semibold whitespace-nowrap shrink-0" :aria-selected="$store.ubr.drawer.tab === t.k"
					:class="$store.ubr.drawer.tab === t.k ? 'border-ink text-ink' : 'border-transparent text-ink-muted hover:text-ink'" @click="$store.ubr.setTab(t.k)" x-text="t.l"></button>
			</template>
		</nav>

		<div class="flex-1 overflow-y-auto px-6 py-5 space-y-6">
			<template x-if="$store.ubr.drawer.error">
				<div class="rounded-xl bg-danger-soft text-danger px-4 py-3 text-[13px] font-semibold" x-text="$store.ubr.drawer.error"></div>
			</template>

			<template x-if="$store.ubr.drawer.loading">
				<div class="space-y-3"><div class="ubr-skeleton h-20"></div><div class="ubr-skeleton h-32"></div></div>
			</template>

			<template x-if="!$store.ubr.drawer.loading && $store.ubr.drawer.company && $store.ubr.drawer.tab === 'lead'">
				@include('ubr.partials.lead-tab')
			</template>

			<template x-if="!$store.ubr.drawer.loading && $store.ubr.drawer.company && $store.ubr.drawer.tab === 'sales'">
				@include('ubr.partials.sales-tab')
			</template>

			<template x-if="!$store.ubr.drawer.loading && $store.ubr.drawer.company && $store.ubr.drawer.tab === 'contacts'">
				@include('ubr.partials.decision-makers-tab')
			</template>

			<template x-if="!$store.ubr.drawer.loading && $store.ubr.drawer.company && $store.ubr.drawer.tab === 'analysis'">
				@include('ubr.partials.analysis-tab')
			</template>

			<template x-if="!$store.ubr.drawer.loading && $store.ubr.drawer.company && $store.ubr.drawer.tab === 'company'">
				<div class="space-y-6" x-data="{ get c() { return $store.ubr.drawer.company } }">
					{{-- Statusy: przedsiębiorstwo, domena, trafność (zapisane — profil NIE uruchamia weryfikacji) --}}
					<div class="grid grid-cols-3 gap-2">
						<div class="rounded-xl border border-line p-3">
							<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Firma</div>
							<span class="mt-1 inline-block" :class="$store.ubr.verificationBadge(c.verification.status)" x-text="c.verification.label"></span>
							<div class="text-[11px] text-ink-muted mt-1" x-text="c.verification.checked_at ? $store.ubr.date(c.verification.checked_at) : 'nie sprawdzano'"></div>
						</div>
						<div class="rounded-xl border border-line p-3">
							<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Domena</div>
							<span class="mt-1 inline-block" :class="$store.ubr.domainBadge(c.domain_status.status)" x-text="c.domain_status.label"></span>
							<div class="text-[11px] text-ink-muted mt-1" x-text="c.domain_status.checked_at ? $store.ubr.date(c.domain_status.checked_at) : 'nie sprawdzano'"></div>
						</div>
						<div class="rounded-xl border border-line p-3">
							<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Trafność</div>
							<span class="mt-1" :class="$store.ubr.relevanceClass(c.relevance)" x-text="c.relevance === null || c.relevance === undefined ? '—' : c.relevance"></span>
							<div class="text-[11px] text-ink-muted mt-1 line-clamp-1" x-show="c.relevance_search" x-text="c.relevance_search ? 'wyszukiwanie #' + c.relevance_search.id : ''"></div>
						</div>
					</div>

					<template x-if="c.verification.queue">
						<p class="rounded-lg bg-gold-soft text-gold-deep px-3 py-2 text-[12px]">
							Weryfikacja w kolejce<span x-show="c.verification.queue.attempts" x-text="' (próba ' + c.verification.queue.attempts + ')'"></span><span x-show="c.verification.queue.last_error" x-text="' — ' + c.verification.queue.last_error"></span>.
						</p>
					</template>

					<div class="flex flex-wrap gap-1" x-show="c.flags.length">
						<template x-for="f in c.flags" :key="f.key"><span :class="f.blocking ? 'ubr-badge-danger' : 'ubr-badge-unavailable'" x-text="f.label"></span></template>
					</div>

					@include('ubr.partials.call.company-phones')

					{{-- Dane podstawowe --}}
					<dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-[13px]">
						<div class="col-span-2">
							<dt class="text-ink-muted text-[12px]">Strona WWW</dt>
							<dd>
								<template x-if="c.domain">
									<a :href="$store.ubr.safeHref(c.website_url)" target="_blank" rel="noopener nofollow" class="inline-flex items-center gap-1.5 font-bold hover:underline">
										<span x-text="c.domain"></span>
										@include('ubr.partials.icon', ['name' => 'external', 'class' => 'size-3.5 text-ink-muted'])
									</a>
								</template>
								<span x-show="!c.domain" class="text-ink-muted">brak oficjalnej domeny w źródłach</span>
							</dd>
						</div>
						<div class="col-span-2"><dt class="text-ink-muted text-[12px]">Branża / działalność</dt><dd class="font-semibold" x-text="c.industry || '—'"></dd></div>
						<div class="col-span-2" x-show="c.pkd_main"><dt class="text-ink-muted text-[12px]">PKD przeważające (rejestr)</dt><dd x-text="c.pkd_main + (c.pkd_description ? ' — ' + c.pkd_description : '')"></dd></div>
						<div class="col-span-2" x-show="c.description"><dt class="text-ink-muted text-[12px]">Opis ze źródła</dt><dd x-text="c.description"></dd></div>
						<div class="col-span-2">
							<dt class="text-ink-muted text-[12px]">Lokalizacja</dt>
							<dd class="flex items-center gap-1.5 flex-wrap">
								<span x-text="[c.street, [c.postal_code, c.city].filter(Boolean).join(' '), c.region && ('woj. ' + c.region)].filter(Boolean).join(', ') || 'brak danych'"></span>
								<span :class="$store.ubr.locationBadge(c.location.confidence)" x-text="$store.ubr.confidenceLabel(c.location.confidence) + (c.location.source ? ' · ' + c.location.source : '')"></span>
							</dd>
						</div>
						<div>
							<dt class="text-ink-muted text-[12px]">Zatrudnienie</dt>
							<dd class="flex items-center gap-1.5 flex-wrap"><span class="font-semibold" x-text="c.size.label || 'brak danych'"></span><span x-show="c.size.label" :class="$store.ubr.confidenceBadge(c.size.confidence)" x-text="$store.ubr.confidenceLabel(c.size.confidence)"></span></dd>
						</div>
						<div><dt class="text-ink-muted text-[12px]">Źródło danych</dt><dd x-text="(c.sources || []).map(s => s.provider_label).join(', ') || '—'"></dd></div>
						<div x-show="c.registry_name" class="col-span-2"><dt class="text-ink-muted text-[12px]">Nazwa w rejestrze</dt><dd x-text="c.registry_name + (c.legal_form ? ' (' + c.legal_form.toLowerCase() + ')' : '')"></dd></div>
						<div><dt class="text-ink-muted text-[12px]">NIP</dt><dd class="font-semibold tabular-nums" x-text="c.nip || '—'"></dd></div>
						<div x-show="c.krs"><dt class="text-ink-muted text-[12px]">KRS</dt><dd class="tabular-nums" x-text="c.krs"></dd></div>
						<div x-show="c.regon"><dt class="text-ink-muted text-[12px]">REGON</dt><dd class="tabular-nums" x-text="c.regon"></dd></div>
					</dl>

					{{-- Weryfikacja przedsiębiorstwa --}}
					<div class="rounded-xl border border-line p-4 space-y-2">
						<div class="flex items-center justify-between gap-2">
							<span class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Weryfikacja przedsiębiorstwa</span>
							<span class="text-[11px] text-ink-muted" x-show="c.verification.source" x-text="c.verification.source + (c.verification.confidence ? ' · pewność: ' + ({high: 'wysoka', medium: 'średnia', low: 'niska'}[c.verification.confidence] || c.verification.confidence) : '')"></span>
						</div>
						<template x-if="!c.verification.checked_at"><p class="text-[12px] text-ink-muted">Jeszcze nie weryfikowano — firma czeka w kolejce w tle.</p></template>
						<ul class="space-y-1 text-[12px]"><template x-for="(r, i) in c.verification.reasons" :key="'r' + i"><li x-text="r"></li></template></ul>
						<template x-if="c.verification.discrepancies.length">
							<div class="rounded-lg bg-gold-soft text-gold-deep px-3 py-2 text-[12px]">
								<div class="font-bold">Rozbieżności</div>
								<ul class="list-disc pl-4"><template x-for="(d, i) in c.verification.discrepancies" :key="'d' + i"><li x-text="d"></li></template></ul>
							</div>
						</template>
						<template x-for="(rec, i) in c.verification.records" :key="'rec' + i">
							<div class="text-[12px] rounded-lg bg-canvas px-3 py-2">
								<strong x-text="({krs: 'KRS', vat: 'Biała lista VAT', ceidg: 'CEIDG', gus: 'GUS / REGON'})[rec.source] || rec.source"></strong>:
								<span x-text="rec.name"></span>
								<span class="text-ink-muted" x-text="rec.status_detail ? ' — ' + rec.status_detail : ''"></span>
								<a x-show="rec.source_url" :href="$store.ubr.safeHref(rec.source_url)" target="_blank" rel="noopener" class="underline font-semibold ml-1">rejestr</a>
							</div>
						</template>
					</div>

					{{-- Weryfikacja domeny --}}
					<div class="rounded-xl border border-line p-4 space-y-1.5" x-show="c.domain">
						<span class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Weryfikacja domeny</span>
						<ul class="space-y-1 text-[12px]"><template x-for="(r, i) in c.domain_status.reasons" :key="'dr' + i"><li x-text="r"></li></template></ul>
						<template x-if="c.domain_status.signals && c.domain_status.signals.final_url">
							<p class="text-[11px] text-ink-muted">
								<span x-text="'HTTP ' + (c.domain_status.signals.http_code || '—')"></span>
								<span x-show="c.domain_status.signals.redirected_domain" x-text="' · przekierowanie: ' + c.domain_status.signals.final_domain"></span>
								<span x-text="' · nazwa na stronie: ' + ({strong: 'tytuł/stopka', medium: 'w treści', weak: 'częściowo', none: 'brak'}[c.domain_status.signals.name_match] || '—')"></span>
								<span x-show="c.domain_status.signals.identifiers && (c.domain_status.signals.identifiers.nip.length || c.domain_status.signals.identifiers.krs.length)" x-text="' · na stronie: ' + [].concat((c.domain_status.signals.identifiers || {}).nip || []).map(v => 'NIP ' + v).concat(((c.domain_status.signals.identifiers || {}).krs || []).map(v => 'KRS ' + v)).join(', ')"></span>
							</p>
						</template>
					</div>

					{{-- Trafność --}}
					<details class="group" x-show="c.relevance_details">
						<summary class="cursor-pointer text-[11px] font-bold uppercase tracking-wider text-ink-muted list-none flex items-center gap-1">
							@include('ubr.partials.icon', ['name' => 'chevron', 'class' => 'size-3.5 transition group-open:rotate-90'])
							Dlaczego taka trafność?
						</summary>
						<div class="mt-2">@include('ubr.partials.relevance-details', ['var' => 'c.relevance_details'])</div>
					</details>

					{{-- Historia weryfikacji --}}
					<details class="group" x-show="c.checks && c.checks.length">
						<summary class="cursor-pointer text-[11px] font-bold uppercase tracking-wider text-ink-muted list-none flex items-center gap-1">
							@include('ubr.partials.icon', ['name' => 'chevron', 'class' => 'size-3.5 transition group-open:rotate-90'])
							Historia weryfikacji (<span x-text="(c.checks || []).length"></span>)
						</summary>
						<ul class="mt-2 space-y-1">
							<template x-for="ch in c.checks" :key="ch.id">
								<li class="text-[12px] rounded-lg bg-canvas px-3 py-1.5 flex flex-wrap items-center gap-2">
									<span class="text-ink-muted tabular-nums" x-text="$store.ubr.date(ch.created_at)"></span>
									<span x-text="ch.kind === 'domain' ? 'Domena' : 'Firma'"></span>
									<span :class="ch.kind === 'domain' ? $store.ubr.domainBadge(ch.status) : $store.ubr.verificationBadge(ch.status)" x-text="ch.status"></span>
									<span class="text-ink-muted" x-text="(ch.trigger === 'manual' ? 'ręcznie' : 'automatycznie') + (ch.by_my_organization ? ' · Twoja organizacja' : '')"></span>
								</li>
							</template>
						</ul>
					</details>

					{{-- Źródła --}}
					<div>
						<h3 class="text-[11px] font-bold uppercase tracking-wider text-ink-muted mb-2">Źródła danych</h3>
						<ul class="space-y-1.5">
							<template x-for="s in c.sources" :key="s.provider + s.external_id">
								<li class="flex items-center justify-between gap-2 text-[13px] rounded-lg bg-canvas px-3 py-2">
									<span><strong x-text="s.provider_label"></strong> <span class="text-ink-muted" x-text="s.external_id"></span></span>
									<a x-show="s.url" :href="$store.ubr.safeHref(s.url)" target="_blank" rel="noopener" class="text-[12px] font-semibold underline">Zobacz</a>
								</li>
							</template>
						</ul>
					</div>

					{{-- Historia pochodzenia --}}
					<details class="group">
						<summary class="cursor-pointer text-[11px] font-bold uppercase tracking-wider text-ink-muted list-none flex items-center gap-1">
							@include('ubr.partials.icon', ['name' => 'chevron', 'class' => 'size-3.5 transition group-open:rotate-90'])
							Historia pochodzenia informacji (<span x-text="c.evidence.length"></span>)
						</summary>
						<div class="mt-2 overflow-x-auto rounded-lg border border-line">
							<table class="ubr-table">
								<thead><tr><th>Pole</th><th>Wartość</th><th>Źródło</th><th>Pewność</th></tr></thead>
								<tbody>
									<template x-for="(e, i) in c.evidence" :key="i">
										<tr>
											<td class="font-semibold" x-text="e.field"></td>
											<td class="max-w-[200px] break-words" x-text="e.value"></td>
											<td x-text="e.provider_label"></td>
											<td><span :class="'ubr-badge-' + e.confidence" x-text="$store.ubr.confidenceLabel(e.confidence)"></span></td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
					</details>
				</div>
			</template>
		</div>

		<footer class="px-4 sm:px-6 py-4 border-t border-line flex flex-wrap items-center gap-2" x-show="!$store.ubr.drawer.loading && $store.ubr.drawer.company">
			<template x-if="$store.ubr.drawer.company && !$store.ubr.drawer.company.prospect_id">
				<button type="button" class="ubr-btn-gold" @click="$store.ubr.saveProspect($store.ubr.drawer.company)" :disabled="$store.ubr.drawer.saving">
					@include('ubr.partials.icon', ['name' => 'bookmark'])
					Zapisz do Prospects
				</button>
			</template>
			<template x-if="$store.ubr.drawer.company && $store.ubr.drawer.company.prospect_id">
				<span class="ubr-badge-confirmed text-[13px] px-3 py-1.5">@include('ubr.partials.icon', ['name' => 'check', 'class' => 'size-3.5']) W Twoich prospectach</span>
			</template>
			<button type="button" class="ubr-btn-ghost" x-show="$store.ubr.drawer.company && $store.ubr.drawer.company.domain" @click="$store.ubr.openPreview($store.ubr.drawer.company)">
				@include('ubr.partials.icon', ['name' => 'globe', 'class' => 'size-3.5'])
				Podgląd strony
			</button>
			<template x-if="$store.ubr.drawer.company && $store.ubr.drawer.company.verification.can_reverify">
				<button type="button" class="ubr-btn-ghost ml-auto" @click="$store.ubr.reverify($store.ubr.drawer.company)" :disabled="$store.ubr.drawer.saving || !!$store.ubr.drawer.company.verification.queue">
					@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-3.5'])
					Zweryfikuj ponownie
				</button>
			</template>
		</footer>
	</section>
</div>
