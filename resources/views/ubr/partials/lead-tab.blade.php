{{-- Karta firmy → Lead Intelligence. Dane: GET /companies/{id}/lead-intelligence (ocena z zapisanych danych, bez nowych analiz). --}}
<div class="space-y-6" x-data="{ get L() { return $store.ubr.drawer.lead } }">
	<template x-if="$store.ubr.drawer.leadLoading && !L">
		<div class="space-y-3"><div class="ubr-skeleton h-24"></div><div class="ubr-skeleton h-40"></div></div>
	</template>

	<template x-if="L && L.available">
		<div class="space-y-6">
			{{-- Góra: wynik, pewność, kompletność, redesign, rekomendacja --}}
			<section class="grid gap-2 grid-cols-2 sm:grid-cols-5">
				<div class="rounded-xl border border-line p-3 col-span-2 sm:col-span-1">
					<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Lead Potential</div>
					<div class="mt-1 flex items-baseline gap-1"><span class="text-[26px] font-extrabold tabular-nums leading-none" x-text="L.lead_score ?? '—'"></span><span class="text-[12px] text-ink-muted">/100</span></div>
					<div class="text-[11px] mt-1" :class="L.status === 'SCORED' ? 'text-money' : 'text-gold-deep'" x-text="{ SCORED: 'ocena pełna', PROVISIONAL: 'PROVISIONAL — wstępna', INSUFFICIENT_DATA: 'za mało danych' }[L.status]"></div>
				</div>
				<div class="rounded-xl border border-line p-3">
					<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Confidence</div>
					<span class="mt-1 inline-block" :class="$store.ubr.leadConfBadge(L.confidence)" x-text="L.confidence_label"></span>
					<div class="text-[11px] text-ink-muted mt-1">pewność danych</div>
				</div>
				<div class="rounded-xl border border-line p-3">
					<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Data Coverage</div>
					<div class="mt-1 text-[20px] font-extrabold tabular-nums leading-none" x-text="L.coverage + '%'"></div>
					<div class="mt-2 h-1.5 rounded-full bg-canvas overflow-hidden" aria-hidden="true"><div class="h-full bg-ink" :style="'width:' + L.coverage + '%'"></div></div>
				</div>
				<div class="rounded-xl border border-line p-3">
					<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Redesign</div>
					<span class="mt-1 inline-block" :class="$store.ubr.redesignBadge(L.redesign.key)" x-text="$store.ubr.redesignLabel(L.redesign.key)"></span>
					<div class="text-[11px] text-ink-muted mt-1 line-clamp-2" x-text="L.redesign.label"></div>
				</div>
				<div class="rounded-xl border border-line p-3">
					<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Recommended</div>
					<span class="mt-1 inline-block" :class="$store.ubr.actionBadge(L.recommendation.key)" x-text="L.recommendation.label"></span>
					<div class="text-[11px] text-ink-muted mt-1" x-text="L.recommendation.label_pl"></div>
				</div>
			</section>

			<div class="rounded-xl bg-canvas px-4 py-3 text-[13px]">
				<p><strong x-text="L.recommendation.label + ':'"></strong> <span x-text="L.recommendation.reason"></span></p>
				<p class="text-[12px] text-ink-muted mt-1" x-show="L.recommendation.also && L.recommendation.also.length" x-text="'Również do rozważenia: ' + (L.recommendation.also || []).map(a => $store.ubr.actionLabel(a)).join(', ')"></p>
				<p class="text-[11px] text-ink-muted mt-1">Sugestia — nie zmienia statusu, priorytetu ani kwalifikacji w pipeline.</p>
			</div>

			<template x-if="L.blockers.length">
				<div class="rounded-xl bg-danger-soft text-danger px-4 py-3 text-[13px] font-semibold space-y-1">
					<template x-for="b in L.blockers" :key="b.key"><p x-text="'Twarda przeszkoda: ' + b.label"></p></template>
				</div>
			</template>

			{{-- 1. Why this company? --}}
			<section>
				<h3 class="font-extrabold text-[14px] mb-2">Why this company?</h3>
				<ul class="list-disc pl-5 text-[13px] space-y-1"><template x-for="w in L.why" :key="w"><li x-text="w"></li></template></ul>
			</section>

			{{-- 2. Website Opportunity Insights --}}
			<section class="space-y-2">
				<h3 class="font-extrabold text-[14px]">Website Opportunity Insights</h3>
				<template x-if="!L.insights.length">
					<p class="text-[13px] text-ink-muted" x-text="L.analysis ? 'Analiza strony nie wykazała problemów, z których można wyciągnąć wnioski.' : 'Brak analizy strony — brak wniosków (to nie znaczy, że strona jest dobra).'"></p>
				</template>
				<template x-for="i in L.insights" :key="i.id">
					<details class="rounded-xl border border-line px-4 py-3">
						<summary class="cursor-pointer list-none flex flex-wrap items-center gap-2">
							<span :class="$store.ubr.severityBadge(i.severity)" x-text="i.severity.toUpperCase()"></span>
							<span class="text-[11px] font-bold uppercase tracking-wider text-ink-muted" x-text="i.category_label"></span>
							<span class="font-semibold text-[13px] flex-1" x-text="i.title"></span>
							<span class="text-[11px] text-ink-muted" x-text="'pewność: ' + $store.ubr.confLabel(i.confidence)"></span>
						</summary>
						<p class="text-[13px] mt-2" x-text="i.description"></p>
						<div class="mt-2 text-[12px]">
							<div class="font-semibold">Evidence</div>
							<ul class="mt-1 space-y-1">
								<template x-for="(e, idx) in i.evidence" :key="idx">
									<li class="text-ink-soft"><span class="font-semibold text-ink" x-text="e.measure"></span><span x-text="e.detail ? ': ' + e.detail : ''"></span> <span class="text-ink-muted" x-text="'(' + e.source + ', ' + $store.ubr.dateOnly(e.date) + ')'"></span></li>
								</template>
							</ul>
						</div>
						<p class="text-[12px] mt-2"><span class="font-semibold">Rekomendacja:</span> <span x-text="i.recommendation"></span></p>
						<p class="text-[11px] text-ink-muted mt-1" x-text="i.source + ' · ' + i.algorithm_version"></p>
					</details>
				</template>
			</section>

			{{-- 3. Sales Talking Points --}}
			<section class="space-y-2">
				<h3 class="font-extrabold text-[14px]">Potential sales angles</h3>
				<template x-if="!L.talking_points.length"><p class="text-[13px] text-ink-muted">Brak argumentów potwierdzonych pomiarami.</p></template>
				<ul class="space-y-2">
					<template x-for="t in L.talking_points" :key="t.insight_key">
						<li class="rounded-xl bg-canvas px-4 py-2.5 text-[13px]">
							<div class="font-semibold" x-text="t.angle"></div>
							<div class="text-[12px] text-ink-muted mt-0.5" x-text="'Na podstawie: ' + t.evidence"></div>
						</li>
					</template>
				</ul>
				<p class="text-[11px] text-ink-muted">Argumenty do przygotowania rozmowy — nie są gotowymi wiadomościami i nie opisują zmierzonych strat.</p>
			</section>

			{{-- 4. Decision makers --}}
			<section class="space-y-2">
				<h3 class="font-extrabold text-[14px]">Decision makers</h3>
				<template x-if="!L.contacts_researched"><p class="text-[13px] text-ink-muted">Decydentów jeszcze nie wyszukiwano (zakładka Decision Makers). Brak osoby nie obniża potencjału redesignu.</p></template>
				<template x-if="L.contacts_researched && !L.decision_makers.length"><p class="text-[13px] text-ink-muted">Nie znaleziono osoby decyzyjnej — firma nadal może być dobrym kandydatem.</p></template>
				<ul class="space-y-1 text-[13px]">
					<template x-for="(p, idx) in L.decision_makers" :key="idx">
						<li><span class="font-semibold" x-text="p.name"></span> <span class="text-ink-muted" x-text="(p.title ? '— ' + p.title : '') + ' · ' + p.status + (p.score !== null ? ' · CPS ' + p.score : '')"></span></li>
					</template>
				</ul>
			</section>

			{{-- 5. Risks & Missing Data --}}
			<section class="space-y-2">
				<h3 class="font-extrabold text-[14px]">Risks &amp; missing data</h3>
				<template x-if="!L.warnings.length && !L.blockers.length"><p class="text-[13px] text-ink-muted">Brak istotnych ostrzeżeń.</p></template>
				<ul class="space-y-1 text-[13px]">
					<template x-for="w in L.warnings" :key="w.key"><li class="flex gap-2"><span class="text-gold-deep">@include('ubr.partials.icon', ['name' => 'alert', 'class' => 'size-3.5 mt-0.5'])</span><span x-text="w.label"></span></li></template>
				</ul>
				<p class="text-[12px] text-ink-muted" x-show="L.coverage_detail && L.coverage_detail.missing.length" x-text="'Brakujące dane: ' + (L.coverage_detail ? L.coverage_detail.missing.join(', ') : '')"></p>
			</section>

			{{-- 6. Lead Research Summary --}}
			<section class="space-y-2">
				<h3 class="font-extrabold text-[14px]">Lead research summary</h3>
				<div class="rounded-xl border border-line divide-y divide-line">
					<template x-for="sec in L.summary" :key="sec.key">
						<div class="px-4 py-2.5 text-[13px]"><div class="text-[11px] font-bold uppercase tracking-wider text-ink-muted" x-text="sec.title"></div><p class="mt-0.5" x-text="sec.text"></p></div>
					</template>
				</div>
			</section>

			{{-- 7. Score Breakdown (rzeczywisty sposób liczenia) --}}
			<section class="space-y-2">
				<h3 class="font-extrabold text-[14px]">Score breakdown</h3>
				<div class="rounded-xl border border-line divide-y divide-line">
					<template x-for="comp in L.breakdown.components" :key="comp.label">
						<details class="px-4 py-2.5">
							<summary class="cursor-pointer list-none flex items-center gap-2 text-[13px]">
								<span class="font-semibold flex-1" x-text="comp.label"></span>
								<span class="tabular-nums font-bold" x-text="comp.measured ? (comp.points + ' / ' + comp.measured_max + (comp.measured_max < comp.max ? ' (z ' + comp.max + ')' : '')) : 'niezmierzone / ' + comp.max"></span>
							</summary>
							<ul class="mt-2 space-y-1 text-[12px]">
								<template x-for="it in comp.items" :key="it.key">
									<li class="flex gap-2"><span class="tabular-nums w-16 shrink-0 font-semibold" x-text="it.points === null ? '— / ' + it.max : it.points + ' / ' + it.max"></span><span><span class="font-semibold" x-text="it.label + ': '"></span><span class="text-ink-soft" x-text="it.detail"></span></span></li>
								</template>
							</ul>
						</details>
					</template>
					<div class="px-4 py-2.5 text-[12px] text-ink-soft" x-text="L.breakdown.explanation"></div>
					<div class="px-4 py-2.5 text-[12px] text-ink-soft">
						<span class="font-semibold">Kompletność danych (osobno od wyniku):</span> <span x-text="L.coverage + '%'"></span>.
						<span class="font-semibold">Ranking:</span> <span x-text="L.rank && L.rank.explanation ? L.rank.explanation.join(' ') : '—'"></span>
					</div>
				</div>
			</section>

			{{-- 8. Evaluation History --}}
			<section class="space-y-2">
				<div class="flex items-center gap-2">
					<h3 class="font-extrabold text-[14px] flex-1">Evaluation history</h3>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="$store.ubr.recalcLead()" :disabled="$store.ubr.drawer.leadLoading">@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-3.5']) Przelicz z zapisanych danych</button>
				</div>
				<ul class="space-y-1 text-[12px]">
					<template x-for="h in L.history" :key="h.id">
						<li class="flex flex-wrap gap-x-2">
							<span class="text-ink-muted" x-text="$store.ubr.date(h.at)"></span>
							<span class="font-semibold tabular-nums" x-text="(h.score ?? '—') + '/100'"></span>
							<span x-show="h.delta !== null" :class="h.delta > 0 ? 'text-money' : (h.delta < 0 ? 'text-danger' : 'text-ink-muted')" x-text="h.delta > 0 ? '+' + h.delta : (h.delta === 0 ? '±0' : h.delta)"></span>
							<span x-text="h.recommendation_label + ' · ' + h.confidence.toUpperCase() + ' · ' + h.coverage + '%'"></span>
							<span class="text-ink-muted" x-text="'— ' + h.reason + ' (' + h.version + ')'"></span>
						</li>
					</template>
				</ul>
				<p class="text-[11px] text-ink-muted" x-text="'Obliczono: ' + $store.ubr.date(L.calculated_at) + ' · wersje: ' + L.versions.lead + ', ' + L.versions.insights"></p>
				<ul class="text-[11px] text-ink-muted list-disc pl-4"><template x-for="n in L.notes" :key="n"><li x-text="n"></li></template></ul>
			</section>
		</div>
	</template>
</div>
