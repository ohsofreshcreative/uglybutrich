{{--
	Menu akcji wiersza (⋯) — wspólne dla Discover i Prospects. Parametry: $var (firma), $save (pokaż „Zapisz do Prospects”),
	$searchExpr (wyrażenie JS z ID wyszukiwania dla zapisu). Pokazuje tylko akcje, które mają zastosowanie, z uprawnieniami
	z backendu (UBR.abilities) — endpointy i walidacja bez zmian (wywołania istniejących metod $store.ubr).
--}}
<div x-data="ubrRowMenu" class="relative inline-block" @scroll.window.passive="reposition()" @resize.window="reposition()">
	<button type="button" x-ref="btn" class="ubr-btn-ghost ubr-btn-sm px-2.5" :aria-expanded="open" aria-haspopup="menu" :aria-label="'Akcje: ' + {{ $var }}.name"
		@click="toggle()" @keydown.arrow-down.prevent="show()">
		<svg viewBox="0 0 24 24" class="size-4" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
		<span class="sr-only">Akcje</span>
	</button>
	<template x-teleport="body">
		<div x-show="open" x-cloak x-ref="menu" role="menu" :aria-label="'Akcje dla ' + {{ $var }}.name" :style="menuStyle"
			class="z-[55] rounded-xl border border-line bg-white p-1.5 shadow-[var(--shadow-pop)] text-[13px]"
			@click.outside="outside($event)" @keydown.escape.stop.prevent="close(true)" @keydown.tab="close(false)"
			@keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.home.prevent="edge(false)" @keydown.end.prevent="edge(true)">
			<button type="button" role="menuitem" class="ubr-menu-item" x-show="{{ $var }}.domain" @click="run(() => $store.ubr.openPreview({{ $var }}))">
				@include('ubr.partials.icon', ['name' => 'globe', 'class' => 'size-4']) Podgląd strony
			</button>
			<button type="button" role="menuitem" class="ubr-menu-item" @click="run(() => { $store.ubr.drawer.tab = 'lead'; $store.ubr.openCompany({{ $var }}.id) })">
				@include('ubr.partials.icon', ['name' => 'sparkle', 'class' => 'size-4']) Lead Intelligence
			</button>
			<button type="button" role="menuitem" class="ubr-menu-item" @click="run(() => { $store.ubr.drawer.tab = 'company'; $store.ubr.openCompany({{ $var }}.id) })">
				@include('ubr.partials.icon', ['name' => 'building', 'class' => 'size-4']) Karta firmy
			</button>
			<a role="menuitem" class="ubr-menu-item" x-show="{{ $var }}.call?.ownership?.in_prospects && window.UBR?.page !== 'prospects'" :href="(window.UBR?.urls?.prospects || '#') + '?search=' + encodeURIComponent({{ $var }}.name)">
				@include('ubr.partials.icon', ['name' => 'bookmark', 'class' => 'size-4']) Otwórz w Prospects
			</a>
			<button type="button" role="menuitem" class="ubr-menu-item" x-show="{{ $var }}.call?.ownership?.in_prospects" @click="run(() => { $store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany({{ $var }}.id) })">
				@include('ubr.partials.icon', ['name' => 'board', 'class' => 'size-4']) Sprzedaż i historia kontaktu
			</button>

			<template x-if="$store.ubr.can('analysis.run') && {{ $var }}.domain">
				<div class="border-t border-line mt-1 pt-1">
					<button type="button" role="menuitem" class="ubr-menu-item" x-show="!['queued', 'running'].includes({{ $var }}.analysis?.status)"
						@click="run(() => $store.ubr.analyze({{ $var }}.id, !!{{ $var }}.analysis?.status))">
						@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-4']) <span x-text="{{ $var }}.analysis?.status ? 'Analizuj ponownie' : 'Analizuj stronę'"></span>
					</button>
					<button type="button" role="menuitem" class="ubr-menu-item" disabled x-show="['queued', 'running'].includes({{ $var }}.analysis?.status)">
						@include('ubr.partials.icon', ['name' => 'clock', 'class' => 'size-4']) Analiza w toku…
					</button>
				</div>
			</template>

			<template x-if="$store.ubr.can('contacts.run')">
				<div>
					<button type="button" role="menuitem" class="ubr-menu-item" x-show="!['queued', 'running'].includes({{ $var }}.contacts?.status)"
						@click="run(() => $store.ubr.findDecisionMakers({{ $var }}.id, !!{{ $var }}.contacts?.status))">
						@include('ubr.partials.icon', ['name' => 'users', 'class' => 'size-4']) <span x-text="{{ $var }}.contacts?.status ? 'Znajdź ponownie decydentów' : 'Znajdź decydentów'"></span>
					</button>
					<button type="button" role="menuitem" class="ubr-menu-item" disabled x-show="['queued', 'running'].includes({{ $var }}.contacts?.status)">
						@include('ubr.partials.icon', ['name' => 'clock', 'class' => 'size-4']) Wyszukiwanie decydentów w toku…
					</button>
				</div>
			</template>

			@if (! empty($save))
				<template x-if="$store.ubr.can('prospects.manage')">
					<div class="border-t border-line mt-1 pt-1">
						<button type="button" role="menuitem" class="ubr-menu-item" x-show="!{{ $var }}.prospect_id" :disabled="$store.ubr.drawer.saving"
							@click="run(() => $store.ubr.saveProspect({{ $var }}, {{ $searchExpr ?? 'null' }}))">
							@include('ubr.partials.icon', ['name' => 'plus', 'class' => 'size-4']) Zapisz do Prospects
						</button>
						<button type="button" role="menuitem" class="ubr-menu-item text-money" disabled x-show="{{ $var }}.prospect_id">
							@include('ubr.partials.icon', ['name' => 'check', 'class' => 'size-4']) Zapisano w Prospects
						</button>
					</div>
				</template>
			@endif
		</div>
	</template>
</div>
