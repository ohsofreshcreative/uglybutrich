{{--
	Quick Actions (Action Center) — menu ⋯ dla pozycji priorytetu lub wiersza kolejki (zmienna `it` z ubrActionCenter).
	Pokazuje tylko akcje dostępne w danym stanie i dla roli użytkownika; każda akcja wywołuje ISTNIEJĄCY endpoint / metodę
	$store.ubr (bez nowej logiki). Notatka, follow-up i zmiana statusu otwierają istniejące formularze w karcie firmy (Sprzedaż).
	Nic nie wysyła wiadomości i nie eksportuje do ClickUp.
--}}
<div x-data="ubrRowMenu" class="relative inline-block" @scroll.window.passive="reposition()" @resize.window="reposition()">
	<button type="button" x-ref="btn" class="ubr-btn-ghost ubr-btn-sm px-2.5" :aria-expanded="open" aria-haspopup="menu" :aria-label="'Szybkie akcje: ' + it.company.name"
		@click="toggle()" @keydown.arrow-down.prevent="show()">
		<svg viewBox="0 0 24 24" class="size-4" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
	</button>
	<template x-teleport="body">
		<div x-show="open" x-cloak x-ref="menu" role="menu" :aria-label="'Szybkie akcje dla ' + it.company.name" :style="menuStyle"
			class="z-[55] rounded-xl border border-line bg-white p-1.5 shadow-[var(--shadow-pop)] text-[13px] max-h-[70vh] overflow-y-auto"
			@click.outside="outside($event)" @keydown.escape.stop.prevent="close(true)" @keydown.tab="close(false)"
			@keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.home.prevent="edge(false)" @keydown.end.prevent="edge(true)">
			<button type="button" role="menuitem" class="ubr-menu-item" @click="run(() => openCompany(it, 'company'))">@include('ubr.partials.icon', ['name' => 'building', 'class' => 'size-4']) Otwórz kartę firmy</button>
			<button type="button" role="menuitem" class="ubr-menu-item" @click="run(() => openCompany(it, 'lead'))">@include('ubr.partials.icon', ['name' => 'sparkle', 'class' => 'size-4']) Lead Intelligence</button>
			<button type="button" role="menuitem" class="ubr-menu-item" x-show="it.company.domain" @click="run(() => $store.ubr.openPreview(it.company))">@include('ubr.partials.icon', ['name' => 'globe', 'class' => 'size-4']) Oceń stronę (podgląd)</button>

			<div class="border-t border-line mt-1 pt-1">
				<button type="button" role="menuitem" class="ubr-menu-item" x-show="can('contacts.run')" :disabled="['queued', 'running'].includes(it.contacts_status) || !!busy[it.company.id]" @click="run(() => findDm(it))">
					@include('ubr.partials.icon', ['name' => 'users', 'class' => 'size-4']) <span x-text="['queued', 'running'].includes(it.contacts_status) ? 'Wyszukiwanie decydentów w toku…' : (it.contacts_status ? 'Znajdź ponownie decydentów' : 'Znajdź decydentów')"></span>
				</button>
				<button type="button" role="menuitem" class="ubr-menu-item" x-show="can('companies.verify')" :disabled="!!busy[it.company.id]" @click="run(() => verify(it))">
					@include('ubr.partials.icon', ['name' => 'check', 'class' => 'size-4']) Zweryfikuj firmę
				</button>
				<button type="button" role="menuitem" class="ubr-menu-item" x-show="can('analysis.run') && it.company.domain" :disabled="['queued', 'running'].includes(it.analysis_status) || !!busy[it.company.id]" @click="run(() => analyze(it))">
					@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-4']) <span x-text="['queued', 'running'].includes(it.analysis_status) ? 'Analiza w toku…' : (it.analysis_status ? 'Analizuj ponownie' : 'Analizuj stronę')"></span>
				</button>
				<button type="button" role="menuitem" class="ubr-menu-item" x-show="can('prospects.manage') && !it.prospect" :disabled="!!busy[it.company.id]" @click="run(() => addToProspects(it))">
					@include('ubr.partials.icon', ['name' => 'bookmark', 'class' => 'size-4']) Dodaj do Prospects
				</button>
			</div>

			<template x-if="it.prospect && can('pipeline.manage')">
				<div class="border-t border-line mt-1 pt-1">
					<button type="button" role="menuitem" class="ubr-menu-item" x-show="it.followup" :disabled="!!busy[it.company.id]" @click="run(() => completeFollowup(it))">@include('ubr.partials.icon', ['name' => 'check', 'class' => 'size-4']) Oznacz follow-up jako wykonany</button>
					<button type="button" role="menuitem" class="ubr-menu-item" @click="run(() => openCompany(it, 'sales'))">@include('ubr.partials.icon', ['name' => 'list', 'class' => 'size-4']) Dodaj notatkę</button>
					<button type="button" role="menuitem" class="ubr-menu-item" @click="run(() => openCompany(it, 'sales'))">@include('ubr.partials.icon', ['name' => 'clock', 'class' => 'size-4']) <span x-text="it.followup ? 'Przełóż / zaplanuj follow-up' : 'Zaplanuj follow-up'"></span></button>
					<button type="button" role="menuitem" class="ubr-menu-item" @click="run(() => openCompany(it, 'sales'))">@include('ubr.partials.icon', ['name' => 'board', 'class' => 'size-4']) Zmień status pipeline</button>
				</div>
			</template>

			<template x-if="it.snoozable && can('pipeline.manage')">
				<div class="border-t border-line mt-1 pt-1">
					<template x-if="!it.snoozed_until">
						<div>
							<div class="px-2.5 pt-1 pb-0.5 text-[11px] font-bold uppercase tracking-wider text-ink-muted">Odłóż działanie</div>
							<template x-for="d in (data?.snooze_days || [1, 3, 7])" :key="d">
								<button type="button" role="menuitem" class="ubr-menu-item" :disabled="!!busy[it.company.id]" @click="run(() => snooze(it, d))">
									@include('ubr.partials.icon', ['name' => 'clock', 'class' => 'size-4']) <span x-text="'Przypomnij za ' + d + (d === 1 ? ' dzień' : ' dni')"></span>
								</button>
							</template>
						</div>
					</template>
					<button type="button" role="menuitem" class="ubr-menu-item" x-show="it.snoozed_until" :disabled="!!busy[it.company.id]" @click="run(() => unsnooze(it))">@include('ubr.partials.icon', ['name' => 'refresh', 'class' => 'size-4']) Przywróć teraz</button>
				</div>
			</template>
		</div>
	</template>
</div>
