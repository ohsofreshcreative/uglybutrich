{{--
	Prompt 10.4.3 — Website Age Intelligence: szacowany wiek OBECNEGO projektu strony (Wayback Machine + poszlaki HTML).
	Wiek i jakość projektu są rozdzielone; daty bez wystarczających dowodów = „wiek nieznany”. Analiza tylko w tle.
--}}
<section class="rounded-xl border border-line p-4 space-y-2" x-show="c.domain" data-site-age aria-label="Website Age Intelligence" x-data="{ get a() { return c.site_age_detail || {} } }">
	<div class="flex flex-wrap items-center justify-between gap-2">
		<h3 class="font-extrabold text-[14px]">Website Age Intelligence</h3>
		<span class="ubr-badge-unavailable" x-show="a.state === 'queued' || a.state === 'running'" x-text="a.state_label"></span>
	</div>
	<template x-if="a.status">
		<dl class="grid grid-cols-1 min-[480px]:grid-cols-2 gap-x-4 gap-y-2 text-[13px]">
			<div><dt class="text-ink-muted text-[12px]">Szacowane wdrożenie</dt><dd data-age-launch x-text="a.launch_label || 'nieznane'"></dd></div>
			<div><dt class="text-ink-muted text-[12px]">Wiek strony</dt><dd x-text="a.age_label || 'nieznany'"></dd></div>
			<div><dt class="text-ink-muted text-[12px]">Ostatni redesign</dt><dd x-text="a.last_redesign_label"></dd></div>
			<div><dt class="text-ink-muted text-[12px]">Aktualność projektu</dt><dd><span :class="$store.ubr.stageBadge(['AGING','OUTDATED'].includes(a.status) ? 'warn' : (a.status === 'UNKNOWN' ? 'muted' : 'good'))" data-age-status x-text="a.status_label"></span></dd></div>
			<div><dt class="text-ink-muted text-[12px]">Pewność</dt><dd x-text="a.confidence_label || '—'"></dd></div>
			<div><dt class="text-ink-muted text-[12px]">Technologia</dt><dd x-text="a.modernity_label || '—'"></dd></div>
			<div class="min-[480px]:col-span-2"><dt class="text-ink-muted text-[12px]">Źródła</dt><dd x-text="(a.sources || []).join(', ') || '—'"></dd></div>
			<div class="min-[480px]:col-span-2"><dt class="text-ink-muted text-[12px]">Ostatnia analiza</dt><dd x-text="a.checked_at ? $store.ubr.date(a.checked_at) : '—'"></dd></div>
		</dl>
	</template>
	<p class="text-[12px] text-ink-muted" x-show="!a.status && !(a.state === 'queued' || a.state === 'running')">Wiek strony nie był jeszcze sprawdzany (analiza w tle po ocenie strony).</p>
	<details class="text-[12px]" x-show="(a.evidence || []).length">
		<summary class="cursor-pointer font-semibold">Dowody i uzasadnienie</summary>
		<ul class="mt-1 list-disc pl-5 space-y-0.5 text-ink-soft">
			<template x-for="(e, i) in a.evidence" :key="i"><li x-text="e.text"></li></template>
		</ul>
	</details>
	{{-- Historia w Web Archive: oś czasu z listy kopii pobranej przez analizę wieku (bez nowych zapytań do archiwum) --}}
	<div class="rounded-lg bg-canvas p-3 space-y-2 text-[12px]" x-show="a.timeline || a.archive_url" data-age-timeline>
		<div class="flex flex-wrap items-center justify-between gap-2">
			<p class="font-semibold">Historia w Web Archive</p>
			<a class="underline font-semibold" :href="$store.ubr.safeHref(a.archive_url)" target="_blank" rel="noopener noreferrer" x-show="a.archive_url">Wszystkie kopie (kalendarz)</a>
		</div>
		<template x-if="a.timeline">
			<div class="space-y-2">
				<p class="text-ink-muted" x-text="a.timeline.captures + ' kopii miesięcznych · od ' + a.timeline.first + ' do ' + a.timeline.last"></p>
				<ul class="space-y-1">
					<template x-for="(e, i) in a.timeline.events" :key="i">
						<li class="flex gap-2">
							<span class="tabular-nums text-ink-muted shrink-0 w-[60px]" x-text="e.date"></span>
							<span class="min-w-0" :class="e.kind === 'redesign' ? 'font-semibold' : (e.kind === 'gap' ? 'text-gold-deep' : '')" x-text="e.text"></span>
							<a class="underline shrink-0 ml-auto" :href="$store.ubr.safeHref(e.url)" target="_blank" rel="noopener noreferrer">kopia</a>
						</li>
					</template>
				</ul>
				<details>
					<summary class="cursor-pointer font-semibold">Aktywność według lat</summary>
					<ul class="mt-1 grid grid-cols-2 min-[480px]:grid-cols-3 gap-x-4 gap-y-0.5 text-ink-soft">
						<template x-for="y in a.timeline.years" :key="y.year">
							<li><span class="tabular-nums" x-text="y.year"></span>: <span x-text="y.captures + ' kopii, zmiany w ' + y.changes + ' mies.'"></span></li>
						</template>
					</ul>
					<p class="mt-1 text-ink-muted">„Zmiana” = inna treść strony głównej niż w poprzedniej miesięcznej kopii (także drobna).</p>
				</details>
			</div>
		</template>
		<p class="text-ink-muted" x-show="!a.timeline">Oś czasu pojawi się po (ponownej) analizie wieku strony.</p>
	</div>
	<p class="text-[11px] text-ink-muted">Wiek nie przesądza o jakości strony — ocena jakości: Website Opportunity Score.</p>
	<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="c.can_check_site_age && !(a.state === 'queued' || a.state === 'running')" :disabled="$store.ubr.drawer.ageBusy" @click="$store.ubr.checkSiteAge(!!a.checked_at)" x-text="a.checked_at ? 'Sprawdź ponownie' : 'Sprawdź wiek strony'" data-age-check></button>
</section>
