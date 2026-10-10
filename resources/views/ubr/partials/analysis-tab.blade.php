{{-- Zakładka „Website Analysis” w karcie firmy. Dane z GET /companies/{id}/analysis — profil tylko CZYTA zapisany wynik. --}}
<div class="space-y-6" x-data="{ get d() { return $store.ubr.drawer.analysis }, get a() { return $store.ubr.drawer.analysis?.latest }, get o() { return $store.ubr.drawer.analysis?.latest?.opportunity }, get c() { return $store.ubr.drawer.company }, findingSource: '' }">
	<template x-if="$store.ubr.drawer.analysisLoading && !d">
		<div class="space-y-3"><div class="ubr-skeleton h-28"></div><div class="ubr-skeleton h-48"></div></div>
	</template>

	<template x-if="d">
		<div class="space-y-6">
			{{-- 1. Overview --}}
			<section class="rounded-xl border border-line p-4" aria-labelledby="wa-overview">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div>
						<h3 id="wa-overview" class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Opportunity Score</h3>
						<template x-if="a && o && o.score !== null">
							<div class="flex items-end gap-2 mt-1">
								<span class="text-[40px] leading-none font-extrabold tabular-nums" x-text="o.score"></span>
								<span class="text-ink-muted font-bold mb-1">/100</span>
								<span class="mb-1.5" :class="$store.ubr.opportunityClass(o.score)" x-text="$store.ubr.levelLabel(o.level?.key)"></span>
							</div>
						</template>
						<template x-if="a && o && o.score === null">
							<div class="mt-1">
								<span class="text-[22px] font-extrabold">INSUFFICIENT_DATA</span>
								<p class="text-[12px] text-ink-muted" x-text="o.insufficient_reason"></p>
							</div>
						</template>
						<template x-if="!a">
							<p class="mt-1 text-[13px] text-ink-muted">Strona nie była jeszcze analizowana.</p>
						</template>
					</div>
					<div class="flex flex-col items-end gap-2">
						<span x-show="a" :class="$store.ubr.analysisBadge(a?.status)" x-text="{completed: 'Gotowa', partial: 'Częściowa', failed: 'Błąd', queued: 'W kolejce', running: 'Analizowanie'}[a?.status] || ''"></span>
						<template x-if="d.can_run && c.domain && !d.active">
							<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="$store.ubr.analyze(c.id, !!a)">
								@include('ubr.partials.icon', ['name' => 'sparkle', 'class' => 'size-3.5'])
								<span x-text="a ? 'Analizuj ponownie' : 'Analizuj stronę'"></span>
							</button>
						</template>
					</div>
				</div>

				<template x-if="d.active">
					<p class="mt-3 rounded-lg bg-gold-soft text-gold-deep px-3 py-2 text-[12px] flex items-center gap-2">
						<svg class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
						<span x-text="d.active.status === 'running' ? 'Analiza w toku (render strony w przeglądarce, do ~1,5 min)…' : 'Analiza w kolejce — wykona się w tle.'"></span>
					</p>
				</template>

				<template x-if="a && o">
					<dl class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3 text-[12px]">
						<div><dt class="text-ink-muted">Pewność</dt><dd class="font-bold" x-text="$store.ubr.confLabel(o.confidence)"></dd></div>
						<div><dt class="text-ink-muted">Pokrycie danych</dt><dd class="font-bold" x-text="o.coverage + '%'"></dd></div>
						<div x-show="o.max_possible !== null"><dt class="text-ink-muted">Maks. przy pełnych danych</dt><dd class="font-bold" x-text="o.max_possible"></dd></div>
						<div><dt class="text-ink-muted">Ostatnia analiza</dt><dd class="font-bold" x-text="$store.ubr.date(a.finished_at)"></dd></div>
					</dl>
				</template>
				<template x-if="o && o.caps && o.caps.length"><p class="mt-2 text-[12px] text-danger font-semibold" x-text="o.caps.join(' ')"></p></template>
				<p class="mt-3 text-[11px] text-ink-muted">Wysoki wynik = słaba strona i duży potencjał firmy (szansa sprzedaży redesignu), nie jakość strony. Kryteria bez pomiaru dostają 0 pkt — wynik nie jest „dociągany” do brakujących danych.</p>
				<p class="mt-1 text-[11px] text-ink-muted" x-show="d.usage" x-text="'Limit organizacji: ' + d.usage.today + '/' + d.usage.per_day + ' dziś, ' + d.usage.month + '/' + d.usage.per_month + ' w miesiącu.'"></p>
			</section>

			{{-- Manual Website Review (Prompt 6) — ocena człowieka, nie pomiar --}}
			@include('ubr.partials.manual-review', ['idExpr' => '$store.ubr.drawer.company?.id'])

			{{-- 2. Website Preview --}}
			<template x-if="a && Object.keys(a.screenshots || {}).length">
				<section aria-labelledby="wa-preview">
					<h3 id="wa-preview" class="text-[11px] font-bold uppercase tracking-wider text-ink-muted mb-2">Website Preview</h3>
					<div class="flex gap-3 items-start">
						<template x-if="a.screenshots.desktop">
							<button type="button" class="flex-1 min-w-0 rounded-lg border border-line overflow-hidden hover:ring-2 hover:ring-gold/50 text-left" @click="$store.ubr.openPreview(c, 'desktop', 'screenshot')" aria-label="Pełny podgląd: desktop">
								<img :src="a.screenshots.desktop.url" alt="Screenshot strony — desktop" class="w-full h-[220px] object-cover object-top" loading="lazy">
								<span class="block px-2 py-1 text-[11px] text-ink-muted">Desktop 1440 px</span>
							</button>
						</template>
						<template x-if="a.screenshots.mobile">
							<button type="button" class="w-[130px] shrink-0 rounded-lg border border-line overflow-hidden hover:ring-2 hover:ring-gold/50 text-left" @click="$store.ubr.openPreview(c, 'mobile', 'screenshot')" aria-label="Pełny podgląd: mobile">
								<img :src="a.screenshots.mobile.url" alt="Screenshot strony — mobile" class="w-full h-[220px] object-cover object-top" loading="lazy">
								<span class="block px-2 py-1 text-[11px] text-ink-muted">Mobile 390 px</span>
							</button>
						</template>
					</div>
				</section>
			</template>

			{{-- Rozbicie Opportunity Score --}}
			<template x-if="o">
				<section aria-labelledby="wa-breakdown" class="space-y-2">
					<h3 id="wa-breakdown" class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Z czego wynika wynik</h3>
					<template x-for="(cat, key) in o.categories" :key="key">
						<details class="rounded-lg border border-line group">
							<summary class="cursor-pointer list-none flex items-center gap-3 px-3 py-2">
								<span class="flex-1 text-[13px] font-bold" x-text="cat.label"></span>
								<span class="text-[11px] text-ink-muted" x-text="'zmierzono ' + cat.coverage + '%'"></span>
								<span class="text-[13px] font-extrabold tabular-nums" x-text="cat.points + '/' + cat.max"></span>
							</summary>
							<ul class="px-3 pb-3 space-y-1.5">
								<template x-for="(cr, ck) in cat.criteria" :key="ck">
									<li class="text-[12px] rounded-md bg-canvas px-2.5 py-1.5">
										<div class="flex items-center gap-2">
											<span class="flex-1 font-semibold" x-text="cr.label"></span>
											<span x-show="cr.state === 'not_evaluated'" class="ubr-badge-unavailable">NOT_EVALUATED</span>
											<span x-show="cr.source === 'ai'" class="ubr-badge-declared">AI</span>
											<span class="tabular-nums font-bold" x-text="(cr.points === null ? '—' : cr.points) + '/' + cr.max"></span>
										</div>
										<p class="text-ink-muted mt-0.5" x-text="cr.detail"></p>
										<ul class="mt-0.5 list-disc pl-4 text-ink-soft" x-show="cr.evidence.length"><template x-for="(ev, i) in cr.evidence" :key="i"><li x-text="ev"></li></template></ul>
									</li>
								</template>
							</ul>
						</details>
					</template>
				</section>
			</template>

			{{-- 3. UX/UI --}}
			<template x-if="a">
				<section aria-labelledby="wa-ux" class="rounded-xl border border-line p-4 space-y-2">
					<h3 id="wa-ux" class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">UX/UI</h3>
					<template x-if="a.visual?.status !== 'evaluated'">
						<p class="text-[12px] rounded-lg bg-canvas px-3 py-2"><strong>Analiza wizualna: NOT_EVALUATED.</strong> AI Vision jest wyłączone, więc nowoczesność designu, hierarchia, typografia i spójność marki NIE zostały ocenione (0 pkt). Poniżej tylko obiektywne sygnały strukturalne (nawigacja, CTA, mobile, treść) — to nie jest pełna analiza wizualna.</p>
					</template>
					<template x-if="a.visual?.status === 'evaluated'">
						<ul class="grid grid-cols-2 gap-1 text-[12px]"><template x-for="(score, cat) in a.visual.categories" :key="cat"><li class="flex justify-between rounded bg-canvas px-2 py-1"><span x-text="cat"></span><strong x-text="score"></strong></li></template></ul>
					</template>
					<ul class="grid grid-cols-2 gap-x-4 gap-y-1 text-[12px]">
						<li>Linki w menu: <strong x-text="a.checks.ux?.nav_links ?? '—'"></strong></li>
						<li>H1: <strong x-text="(a.checks.ux?.h1 || []).length"></strong></li>
						<li>CTA w 1. ekranie (desktop): <strong x-text="a.findings.some(f => f.key === 'conv_no_cta_fold') ? 'brak' : (a.render?.available ? 'jest' : '—')"></strong></li>
						<li>Telefon / e-mail / formularz: <strong x-text="[a.checks.conversion?.tel_link ? 'tel' : null, a.checks.conversion?.mailto_link ? 'e-mail' : null, a.checks.conversion?.forms ? 'formularz' : null].filter(Boolean).join(', ') || 'brak'"></strong></li>
						<li>Strona oferty: <strong x-text="a.checks.conversion?.offer_page ? 'znaleziona' : 'nie znaleziono'"></strong></li>
						<li>Rok w stopce: <strong x-text="a.checks.conversion?.copyright_year ?? '—'"></strong></li>
					</ul>
				</section>
			</template>

			{{-- 4. Technical --}}
			<template x-if="a">
				<section aria-labelledby="wa-tech" class="rounded-xl border border-line p-4">
					<h3 id="wa-tech" class="text-[11px] font-bold uppercase tracking-wider text-ink-muted mb-2">Technical</h3>
					<div class="grid sm:grid-cols-2 gap-3 text-[12px]">
						<div>
							<div class="font-bold mb-1">Performance <span class="font-normal text-ink-muted">(1 pomiar laboratoryjny, nie Core Web Vitals)</span></div>
							<template x-if="a.checks.technical?.lab">
								<ul class="space-y-0.5">
									<li>Load: <strong x-text="(a.checks.technical.lab.load_ms / 1000).toFixed(1) + ' s'"></strong></li>
									<li>Transfer: <strong x-text="(a.checks.technical.lab.bytes / 1048576).toFixed(1) + ' MB'"></strong>, żądań: <strong x-text="a.checks.technical.lab.requests"></strong></li>
									<li>Błędne zasoby: <strong x-text="(a.checks.technical.lab.failed || 0) + (a.checks.technical.lab.http_errors || 0)"></strong></li>
								</ul>
							</template>
							<p x-show="!a.checks.technical?.lab" class="text-ink-muted">Brak pomiaru w przeglądarce.</p>
							<p class="text-ink-muted mt-0.5" x-show="a.checks.technical?.response_ms" x-text="'Odpowiedź HTML: ' + a.checks.technical.response_ms + ' ms'"></p>
						</div>
						<div>
							<div class="font-bold mb-1">Mobile</div>
							<ul class="space-y-0.5">
								<li>Meta viewport: <strong x-text="a.checks.mobile?.meta_viewport ? 'jest' : 'brak'"></strong></li>
								<li x-show="a.checks.mobile?.mobile">Układ desktopowy pomniejszony: <strong x-text="a.checks.mobile?.mobile?.scaled_desktop_layout ? 'tak' : 'nie'"></strong></li>
								<li x-show="a.checks.mobile?.mobile">Poziomy overflow: <strong x-text="(a.checks.mobile?.mobile?.horizontal_overflow_px ?? '—') + ' px'"></strong></li>
								<li x-show="a.checks.mobile?.mobile">Małe cele dotykowe: <strong x-text="(a.checks.mobile?.mobile?.small_tap_targets ?? '—') + '/' + (a.checks.mobile?.mobile?.interactive_count ?? '—')"></strong></li>
							</ul>
						</div>
						<div>
							<div class="font-bold mb-1">SEO</div>
							<ul class="space-y-0.5">
								<li class="truncate">Title: <strong x-text="a.checks.seo?.title || 'brak'"></strong></li>
								<li>Meta description: <strong x-text="a.checks.seo?.meta_description ? 'jest' : 'brak'"></strong></li>
								<li>Canonical: <strong x-text="a.checks.seo?.canonical ? 'jest' : 'brak'"></strong>, dane strukturalne: <strong x-text="(a.checks.seo?.structured_data || []).join(', ') || 'brak'"></strong></li>
								<li x-show="a.checks.seo?.robots" x-text="'robots: ' + a.checks.seo?.robots"></li>
							</ul>
						</div>
						<div>
							<div class="font-bold mb-1">Accessibility <span class="font-normal text-ink-muted">(test automatyczny, nie zgodność z WCAG)</span></div>
							<ul class="space-y-0.5">
								<li x-show="a.checks.accessibility?.axe">axe-core: <strong x-text="Object.entries(a.checks.accessibility?.axe?.by_impact || {}).filter(e => e[1]).map(e => e[0] + ' ' + e[1]).join(', ') || 'brak naruszeń'"></strong></li>
								<li>Obrazy bez alt: <strong x-text="(a.checks.accessibility?.images_without_alt ?? '—') + '/' + (a.checks.accessibility?.images ?? '—')"></strong></li>
								<li>Pola bez etykiet: <strong x-text="a.checks.accessibility?.unlabeled_fields ?? '—'"></strong></li>
								<li>HTTPS: <strong x-text="a.checks.technical?.https ? 'tak' : 'nie'"></strong><span x-show="a.checks.technical?.jquery" x-text="' · jQuery ' + a.checks.technical?.jquery"></span></li>
							</ul>
						</div>
					</div>
				</section>
			</template>

			{{-- 5. Business Potential --}}
			<template x-if="a && a.checks.business">
				<section aria-labelledby="wa-biz" class="rounded-xl border border-line p-4 text-[12px]">
					<h3 id="wa-biz" class="text-[11px] font-bold uppercase tracking-wider text-ink-muted mb-2">Business Potential</h3>
					<p><strong>Dostępne dane:</strong> <span x-text="(a.checks.business.available || []).join(', ') || 'brak'"></span></p>
					<p class="mt-1"><strong>Brakujące dane:</strong> <span x-text="(a.checks.business.missing || []).join(', ') || 'brak'"></span></p>
					<p class="mt-1 text-ink-muted">Wielkość i przychód to potencjał zakupowy, nie dowód budżetu ani gotowości do zakupu. Za brakujące dane nie przyznajemy punktów.</p>
				</section>
			</template>

			{{-- 6. Findings --}}
			<template x-if="a && a.findings.length">
				<section aria-labelledby="wa-findings">
					<div class="flex items-center justify-between gap-2 mb-2">
						<h3 id="wa-findings" class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Findings (<span x-text="a.findings.length"></span>)</h3>
						<select class="ubr-input h-8 text-[12px] w-auto" x-model="findingSource" aria-label="Źródło problemów">
							<option value="">Wszystkie źródła</option><option value="automated">Pomiar automatyczny</option><option value="ai">Ocena AI</option><option value="manual_review">Do ręcznej weryfikacji</option>
						</select>
					</div>
					<ul class="space-y-2">
						<template x-for="(f, i) in a.findings.filter(f => !findingSource || f.source === findingSource)" :key="f.key + i">
							<li class="rounded-lg border border-line p-3 text-[12px]">
								<div class="flex flex-wrap items-center gap-1.5">
									<span :class="$store.ubr.severityBadge(f.severity)" x-text="$store.ubr.severityLabel(f.severity)"></span>
									<strong class="text-[13px]" x-text="f.title"></strong>
								</div>
								<p class="mt-1 text-ink-soft" x-text="f.description"></p>
								<p class="mt-1" x-show="f.recommendation"><span class="font-semibold">Rekomendacja:</span> <span x-text="f.recommendation"></span></p>
								<details class="mt-1" x-show="f.evidence.length">
									<summary class="cursor-pointer text-ink-muted" x-text="'Dowód · ' + $store.ubr.sourceLabel(f.source)"></summary>
									<ul class="mt-1 list-disc pl-4 font-mono text-[11px] break-all"><template x-for="(ev, j) in f.evidence" :key="j"><li x-text="ev"></li></template></ul>
								</details>
							</li>
						</template>
					</ul>
				</section>
			</template>

			{{-- 7. Analysis History --}}
			<template x-if="d.history.length">
				<section aria-labelledby="wa-history">
					<div class="flex items-center justify-between gap-2 mb-2">
						<h3 id="wa-history" class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Analysis History</h3>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="$store.ubr.drawer.compareIds.length === 2" @click="$store.ubr.runCompare()">Porównaj zaznaczone</button>
					</div>
					<ul class="space-y-1">
						<template x-for="h in d.history" :key="h.id">
							<li class="flex flex-wrap items-center gap-2 text-[12px] rounded-lg bg-canvas px-3 py-1.5">
								<input type="checkbox" class="ubr-checkbox" :checked="$store.ubr.drawer.compareIds.includes(h.id)" @change="$store.ubr.toggleCompare(h.id)" :disabled="!['completed', 'partial'].includes(h.status)" :aria-label="'Porównaj analizę #' + h.id">
								<span class="tabular-nums text-ink-muted" x-text="$store.ubr.date(h.finished_at || h.created_at)"></span>
								<span :class="$store.ubr.analysisBadge(h.status)" x-text="h.status"></span>
								<strong x-text="h.opportunity_score !== null ? h.opportunity_score + '/100' : (h.opportunity_level === 'insufficient' ? 'INSUFFICIENT_DATA' : '—')"></strong>
								<span class="text-ink-muted" x-text="(h.coverage !== null ? h.coverage + '% danych · ' : '') + h.findings_count + ' problemów · ' + h.algorithm_version + (h.by_my_organization ? ' · Twoja organizacja' : '')"></span>
							</li>
						</template>
					</ul>
					<template x-if="$store.ubr.drawer.compare">
						<div class="mt-2 rounded-lg border border-line p-3 text-[12px] space-y-1">
							<p class="font-bold" x-text="'Porównanie: ' + ($store.ubr.drawer.compare.old.opportunity_score ?? '—') + ' → ' + ($store.ubr.drawer.compare.new.opportunity_score ?? '—')"></p>
							<p x-show="$store.ubr.drawer.compare.algorithm_changed" class="text-gold-deep">Uwaga: różne wersje algorytmu — porównanie przybliżone.</p>
							<template x-for="cat in $store.ubr.drawer.compare.categories" :key="cat.key"><p x-text="cat.label + ': ' + (cat.old ?? '—') + ' → ' + (cat.new ?? '—') + ' /' + cat.max"></p></template>
							<p x-show="$store.ubr.drawer.compare.resolved.length" x-text="'Rozwiązane: ' + $store.ubr.drawer.compare.resolved.join('; ')"></p>
							<p x-show="$store.ubr.drawer.compare.new_findings.length" x-text="'Nowe problemy: ' + $store.ubr.drawer.compare.new_findings.join('; ')"></p>
						</div>
					</template>
				</section>
			</template>

			{{-- Błędy i narzędzia --}}
			<template x-if="a">
				<details class="text-[12px]">
					<summary class="cursor-pointer text-[11px] font-bold uppercase tracking-wider text-ink-muted">Szczegóły techniczne analizy</summary>
					<ul class="mt-2 space-y-0.5">
						<li x-text="'Algorytm: ' + a.algorithm_version + ' · czas: ' + (a.duration_ms ? (a.duration_ms / 1000).toFixed(1) + ' s' : '—')"></li>
						<li x-text="'Render: ' + (a.tools.renderer ? a.tools.renderer.name + ', ' + a.tools.renderer.browser : 'niedostępny') + (a.tools.axe ? ' · axe-core ' + a.tools.axe : '')"></li>
						<li x-text="'AI Vision: ' + (typeof a.tools.ai_vision === 'string' ? a.tools.ai_vision : a.tools.ai_vision?.model) + ' · Lighthouse: nie używany'"></li>
						<li x-text="'Przeanalizowane strony: ' + (a.pages || []).map(p => p.kind + ' (' + (p.status || 'błąd') + ')').join(', ')"></li>
						<li x-show="a.render?.cookie_banner" x-text="'Baner cookies: ' + ({rejected: 'odrzucony przyciskiem', hidden: 'ukryty na zrzucie', none_detected: 'nie wykryto'}[a.render?.cookie_banner] || '—')"></li>
						<template x-for="(e, i) in a.errors" :key="i"><li class="text-danger" x-text="e.stage + ': ' + e.message"></li></template>
					</ul>
				</details>
			</template>
		</div>
	</template>
</div>
