{{--
	Podgląd strony (lightbox ~95% ekranu). Podstawą jest screenshot (zawsze działa); interaktywny iframe tylko,
	gdy strona nie blokuje osadzania (X-Frame-Options / CSP frame-ancestors) — nie obchodzimy tych zabezpieczeń.
	Dostępność: role=dialog, aria-modal, etykieta, pułapka fokusu, ESC, przywrócenie fokusu po zamknięciu.
--}}
<div x-show="$store.ubr.preview.open" x-cloak class="fixed inset-0 z-[60] grid place-items-center p-[2.5vh_2.5vw]" role="dialog" aria-modal="true" aria-labelledby="ubr-preview-title"
	@keydown.tab="$store.ubr.trapFocus($event, $el)">
	<div class="absolute inset-0 bg-ink/70" @click="$store.ubr.closePreview()" x-show="$store.ubr.preview.open" x-transition.opacity></div>

	<section class="relative w-full h-full bg-white rounded-[16px] shadow-[var(--shadow-pop)] flex flex-col overflow-hidden" x-show="$store.ubr.preview.open" x-transition.scale.95>
		<header class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-line">
			<div class="min-w-0 flex-1">
				<h2 id="ubr-preview-title" class="font-extrabold text-[15px] truncate" x-text="'Podgląd strony — ' + ($store.ubr.preview.company?.name || '')"></h2>
				<p class="text-[12px] text-ink-muted truncate">
					<span x-text="$store.ubr.preview.company?.domain || ''"></span>
					<template x-if="$store.ubr.preview.analysis"><span x-text="' · screenshot z ' + $store.ubr.date($store.ubr.preview.analysis.finished_at)"></span></template>
				</p>
			</div>

			<div class="inline-flex rounded-[10px] border border-line p-0.5" role="group" aria-label="Widok">
				<template x-for="v in [{k: 'desktop', l: 'Desktop'}, {k: 'mobile', l: 'Mobile'}]" :key="v.k">
					<button type="button" class="px-3 h-8 rounded-[8px] text-[13px] font-semibold" :class="$store.ubr.preview.view === v.k ? 'bg-ink text-white' : 'text-ink-soft hover:bg-canvas'"
						:aria-pressed="$store.ubr.preview.view === v.k" :disabled="!$store.ubr.preview.analysis?.screenshots?.[v.k]"
						@click="$store.ubr.preview.view = v.k; $store.ubr.preview.fit = true" x-text="v.l"></button>
				</template>
				<button type="button" class="px-3 h-8 rounded-[8px] text-[13px] font-semibold" x-show="$store.ubr.preview.analysis?.preview?.embeddable"
					:class="$store.ubr.preview.view === 'live' ? 'bg-ink text-white' : 'text-ink-soft hover:bg-canvas'" :aria-pressed="$store.ubr.preview.view === 'live'"
					@click="$store.ubr.preview.view = 'live'" title="Interaktywny podgląd w ramce (jeśli strona na to pozwala)">Na żywo</button>
			</div>

			<div class="inline-flex items-center gap-1" x-show="$store.ubr.preview.view !== 'live' && $store.ubr.preview.analysis">
				<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2.5" @click="$store.ubr.zoomPreview(-0.25)" aria-label="Pomniejsz">−</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2.5 tabular-nums min-w-[64px]" @click="$store.ubr.preview.fit = !$store.ubr.preview.fit" :aria-label="$store.ubr.preview.fit ? 'Rozmiar rzeczywisty' : 'Dopasuj do okna'"
					x-text="$store.ubr.preview.fit ? 'Dopasuj' : Math.round($store.ubr.preview.zoom * 100) + '%'"></button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2.5" @click="$store.ubr.zoomPreview(0.25)" aria-label="Powiększ">+</button>
			</div>

			<a :href="$store.ubr.preview.company?.website_url" target="_blank" rel="noopener nofollow" class="ubr-btn-ghost ubr-btn-sm" x-show="$store.ubr.preview.company?.website_url">
				@include('ubr.partials.icon', ['name' => 'external', 'class' => 'size-3.5'])
				Otwórz stronę
			</a>
			<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2" data-preview-close @click="$store.ubr.closePreview()" aria-label="Zamknij podgląd">
				@include('ubr.partials.icon', ['name' => 'x'])
			</button>
		</header>

		<div class="flex-1 min-h-0 overflow-auto bg-canvas" tabindex="0" aria-label="Obszar podglądu (przewijany)">
			<template x-if="$store.ubr.preview.loading">
				<div class="p-6"><div class="ubr-skeleton h-[60vh] max-w-[1100px] mx-auto"></div></div>
			</template>

			<template x-if="!$store.ubr.preview.loading && $store.ubr.preview.error">
				<div class="h-full grid place-items-center p-8 text-center">
					<div class="max-w-[420px]">
						<div class="mx-auto grid place-items-center size-12 rounded-2xl bg-gold-soft text-gold-deep mb-3">@include('ubr.partials.icon', ['name' => 'globe', 'class' => 'size-5'])</div>
						<p class="font-bold" x-text="$store.ubr.preview.error"></p>
						<button type="button" class="ubr-btn-gold mt-4" x-show="!$store.ubr.preview.active && $store.ubr.can('analysis.run')"
							@click="$store.ubr.analyze($store.ubr.preview.company.id); $store.ubr.preview.error = 'Analiza zlecona — screenshoty będą dostępne za ok. minutę.'; $store.ubr.preview.active = true">Analizuj stronę</button>
					</div>
				</div>
			</template>

			<template x-if="!$store.ubr.preview.loading && $store.ubr.preview.analysis && $store.ubr.preview.view !== 'live'">
				<div class="p-4 sm:p-6">
					<div class="mx-auto" :style="$store.ubr.preview.fit
						? ($store.ubr.preview.view === 'mobile' ? 'width: min(100%, 430px)' : 'width: min(100%, 1440px)')
						: 'width: ' + Math.round(($store.ubr.preview.analysis.screenshots[$store.ubr.preview.view]?.width || 1440) * $store.ubr.preview.zoom) + 'px'">
						<img :src="$store.ubr.preview.analysis.screenshots[$store.ubr.preview.view]?.url" :alt="'Screenshot ' + ($store.ubr.preview.view === 'mobile' ? 'mobile' : 'desktop') + ' strony ' + ($store.ubr.preview.company?.domain || '')"
							class="block w-full h-auto rounded-md shadow-[var(--shadow-card)] bg-white" :class="$store.ubr.preview.view === 'mobile' ? 'rounded-[22px] border-8 border-ink' : ''" loading="lazy">
						<p class="text-[11px] text-ink-muted mt-2 text-center" x-show="$store.ubr.preview.analysis.screenshots[$store.ubr.preview.view]?.truncated"
							x-text="'Zrzut przycięty do ' + $store.ubr.preview.analysis.screenshots[$store.ubr.preview.view]?.height + ' px (strona ma ' + $store.ubr.preview.analysis.screenshots[$store.ubr.preview.view]?.full_height + ' px).'"></p>
						<p class="text-[11px] text-ink-muted mt-1 text-center" x-show="$store.ubr.preview.analysis.render?.cookie_banner === 'hidden'">Baner cookies został ukryty na zrzucie (nie odrzucono go przyciskiem). Na żywej stronie jest widoczny.</p>
					</div>
				</div>
			</template>

			<template x-if="!$store.ubr.preview.loading && $store.ubr.preview.view === 'live'">
				<div class="h-full flex flex-col">
					<p class="px-4 py-2 text-[11px] text-ink-muted bg-white border-b border-line">Interaktywna strona w ramce (sandbox; strona z innej domeny nie ma dostępu do Ugly but Rich). Jeśli ramka jest pusta, strona blokuje osadzanie — użyj widoku Desktop/Mobile.</p>
					<iframe :src="$store.ubr.preview.company?.website_url" class="flex-1 w-full bg-white" title="Podgląd na żywo" sandbox="allow-scripts allow-same-origin allow-forms allow-popups" referrerpolicy="no-referrer" loading="lazy"></iframe>
				</div>
			</template>
		</div>
	</section>
</div>
