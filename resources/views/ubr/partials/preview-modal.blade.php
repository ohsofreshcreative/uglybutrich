{{--
	Website Preview — JEDEN wspólny modal (Discover, Prospects, karta firmy, zakładka analizy) sterowany przez $store.ubr.preview.
	LIVE: aktualna strona w iframe. Adres wyłącznie z zapisanych danych firmy, walidowany (tylko http/https, bez danych logowania).
	Nie obchodzimy X-Frame-Options / CSP frame-ancestors i nie używamy proxy — część stron nie pozwoli się osadzić (to normalne).
	Przeglądarka nie zawsze pozwala wykryć blokadę, więc NIE pokazujemy statusu „załadowano poprawnie”; jest stała informacja
	i ręczne przełączenie na SCREENSHOT lub „Otwórz stronę”.
	Sandbox iframe: allow-scripts allow-same-origin allow-forms allow-popups (bez allow-top-navigation, bez allow-modals,
	bez allow-downloads); allow="camera 'none'; microphone 'none'; geolocation 'none'…" — bez dostępu do kamery, mikrofonu, lokalizacji.
	allow-same-origin dotyczy origin STRONY FIRMY (inna domena), więc nie daje jej dostępu do Ugly but Rich.
	SCREENSHOT: ostatnie zrzuty Website Analyzera (nic nie jest generowane ani analizowane przy otwarciu).
	Dostępność: role=dialog, aria-modal, etykieta, pułapka fokusu, Escape, przywrócenie fokusu, blokada przewijania tła.
--}}
<div x-show="$store.ubr.preview.open" x-cloak class="fixed inset-0 z-[60] grid place-items-center p-0 sm:p-[2.5vh_2.5vw]" role="dialog" aria-modal="true" aria-labelledby="ubr-preview-title"
	@keydown.tab="$store.ubr.trapFocus($event, $el)">
	<div class="absolute inset-0 bg-ink/70" @click="$store.ubr.closePreview()" x-show="$store.ubr.preview.open" x-transition.opacity></div>

	<section class="relative w-full h-full bg-white sm:rounded-[16px] shadow-[var(--shadow-pop)] flex flex-col overflow-hidden" x-show="$store.ubr.preview.open" x-transition.scale.95>
		<header class="flex flex-wrap items-center gap-2 sm:gap-3 px-3 sm:px-4 py-3 border-b border-line">
			<div class="min-w-0 flex-1 basis-[220px]">
				<h2 id="ubr-preview-title" class="font-extrabold text-[15px] truncate" x-text="$store.ubr.preview.company?.name || 'Podgląd strony'"></h2>
				<p class="text-[12px] text-ink-muted truncate" :title="$store.ubr.preview.liveUrl || $store.ubr.preview.company?.website_url || ''">
					<span x-text="$store.ubr.preview.liveUrl || $store.ubr.preview.company?.website_url || 'brak adresu strony'"></span>
					<template x-if="$store.ubr.preview.mode === 'screenshot' && $store.ubr.preview.analysis"><span x-text="' · screenshot z ' + $store.ubr.date($store.ubr.preview.analysis.finished_at)"></span></template>
				</p>
			</div>

			<div class="inline-flex rounded-[10px] border border-line p-0.5" role="group" aria-label="Rodzaj podglądu">
				<button type="button" class="px-3 h-8 rounded-[8px] text-[12px] font-bold tracking-wide" :class="$store.ubr.preview.mode === 'live' ? 'bg-ink text-white' : 'text-ink-soft hover:bg-canvas'"
					:aria-pressed="$store.ubr.preview.mode === 'live'" :disabled="!$store.ubr.preview.liveUrl" @click="$store.ubr.setPreviewMode('live')">LIVE</button>
				<button type="button" class="px-3 h-8 rounded-[8px] text-[12px] font-bold tracking-wide" :class="$store.ubr.preview.mode === 'screenshot' ? 'bg-ink text-white' : 'text-ink-soft hover:bg-canvas'"
					:aria-pressed="$store.ubr.preview.mode === 'screenshot'" @click="$store.ubr.setPreviewMode('screenshot')">SCREENSHOT</button>
			</div>

			<div class="inline-flex rounded-[10px] border border-line p-0.5" role="group" aria-label="Szerokość podglądu">
				<template x-for="v in [{k: 'desktop', l: 'DESKTOP'}, {k: 'mobile', l: 'MOBILE'}]" :key="v.k">
					<button type="button" class="px-3 h-8 rounded-[8px] text-[12px] font-bold tracking-wide" :class="$store.ubr.preview.view === v.k ? 'bg-ink text-white' : 'text-ink-soft hover:bg-canvas'"
						:aria-pressed="$store.ubr.preview.view === v.k" @click="$store.ubr.preview.view = v.k; $store.ubr.preview.fit = true" x-text="v.l"></button>
				</template>
			</div>

			<div class="inline-flex items-center gap-1" x-show="$store.ubr.preview.mode === 'screenshot' && $store.ubr.preview.analysis?.screenshots?.[$store.ubr.preview.view]">
				<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2.5" @click="$store.ubr.zoomPreview(-0.25)" aria-label="Pomniejsz">−</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2.5 tabular-nums min-w-[64px]" @click="$store.ubr.preview.fit = !$store.ubr.preview.fit" :aria-label="$store.ubr.preview.fit ? 'Rozmiar rzeczywisty' : 'Dopasuj do okna'"
					x-text="$store.ubr.preview.fit ? 'Dopasuj' : Math.round($store.ubr.preview.zoom * 100) + '%'"></button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm px-2.5" @click="$store.ubr.zoomPreview(0.25)" aria-label="Powiększ">+</button>
			</div>

			<a :href="$store.ubr.preview.openUrl" target="_blank" rel="noopener noreferrer" class="ubr-btn-ghost ubr-btn-sm" x-show="$store.ubr.preview.openUrl">
				@include('ubr.partials.icon', ['name' => 'external', 'class' => 'size-3.5'])
				Otwórz stronę
			</a>
			<button type="button" class="ubr-btn-ghost ubr-btn-sm" data-preview-close @click="$store.ubr.closePreview()" aria-label="Zamknij podgląd">
				@include('ubr.partials.icon', ['name' => 'x', 'class' => 'size-3.5'])
				<span class="hidden sm:inline">Zamknij</span>
			</button>
		</header>

		{{-- Manual Website Review (Prompt 6) — w obu trybach podglądu; ten sam komponent co w karcie firmy --}}
		<template x-if="$store.ubr.preview.open && $store.ubr.preview.company?.id">
			<div class="px-4 py-1.5 bg-white border-b border-line">
				@include('ubr.partials.manual-review', ['idExpr' => '$store.ubr.preview.company?.id', 'compact' => true])
			</div>
		</template>

		{{-- LIVE --}}
		<template x-if="$store.ubr.preview.open && $store.ubr.preview.mode === 'live' && $store.ubr.preview.liveUrl">
			<div class="flex-1 min-h-0 flex flex-col bg-canvas">
				<p class="px-4 py-1.5 text-[11px] text-ink-muted bg-white border-b border-line">
					Niektóre strony blokują podgląd w aplikacji. Jeśli witryna się nie wyświetla, wybierz
					<button type="button" class="underline font-semibold hover:text-ink" @click="$store.ubr.setPreviewMode('screenshot')">Screenshot</button>
					lub otwórz ją w nowej karcie.
					<span x-show="$store.ubr.preview.upgraded">Adres strony zaczyna się od http:// — aplikacja działa na https, więc podgląd ładuje wersję https:// (przeglądarka blokuje treści http na stronach https).</span>
				</p>
				<div class="flex-1 min-h-0 flex justify-center" :class="$store.ubr.preview.view === 'mobile' ? 'py-4 overflow-auto' : ''">
					<iframe :key="$store.ubr.preview.frameKey" :src="$store.ubr.preview.liveUrl" :title="'Podgląd na żywo: ' + ($store.ubr.preview.company?.name || '')"
						class="bg-white h-full" :class="$store.ubr.preview.view === 'mobile' ? 'w-[390px] max-w-full min-h-[640px] rounded-[22px] border-8 border-ink shadow-[var(--shadow-card)]' : 'w-full'"
						sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
						allow="camera 'none'; microphone 'none'; geolocation 'none'; payment 'none'; usb 'none'; clipboard-read 'none'; display-capture 'none'"
						referrerpolicy="no-referrer" loading="eager"></iframe>
				</div>
			</div>
		</template>

		<template x-if="$store.ubr.preview.open && $store.ubr.preview.mode === 'live' && !$store.ubr.preview.liveUrl">
			<div class="flex-1 grid place-items-center p-8 text-center bg-canvas">
				<p class="font-bold max-w-[420px]">Brak prawidłowego adresu strony (dozwolone tylko adresy http/https) — podgląd na żywo niedostępny.</p>
			</div>
		</template>

		{{-- SCREENSHOT --}}
		<div class="flex-1 min-h-0 overflow-auto bg-canvas" tabindex="0" aria-label="Obszar podglądu (przewijany)" x-show="$store.ubr.preview.mode === 'screenshot'">
			<template x-if="$store.ubr.preview.loading">
				<div class="p-6"><div class="ubr-skeleton h-[60vh] max-w-[1100px] mx-auto"></div></div>
			</template>

			<template x-if="!$store.ubr.preview.loading && (!$store.ubr.preview.analysis || !$store.ubr.preview.analysis.screenshots[$store.ubr.preview.view])">
				<div class="h-full grid place-items-center p-8 text-center">
					<div class="max-w-[440px]">
						<div class="mx-auto grid place-items-center size-12 rounded-2xl bg-gold-soft text-gold-deep mb-3">@include('ubr.partials.icon', ['name' => 'globe', 'class' => 'size-5'])</div>
						<p class="font-bold" x-text="$store.ubr.preview.analysis ? 'Brak screenshotu ' + ($store.ubr.preview.view === 'mobile' ? 'mobilnego' : 'desktopowego') + '.' : ($store.ubr.preview.error || 'Brak zapisanych screenshotów tej strony.')"></p>
						<p class="text-[12px] text-ink-muted mt-1">Screenshoty powstają wyłącznie podczas analizy strony (Website Analyzer) — podgląd ich nie generuje.</p>
					</div>
				</div>
			</template>

			<template x-if="!$store.ubr.preview.loading && $store.ubr.preview.analysis && $store.ubr.preview.analysis.screenshots[$store.ubr.preview.view]">
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
		</div>
	</section>
</div>
