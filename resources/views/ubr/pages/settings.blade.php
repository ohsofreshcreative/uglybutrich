@extends('ubr.layouts.app')

@section('content')
	<div x-data="ubrSettings" class="max-w-[960px] space-y-6">
		<template x-if="error"><div class="ubr-card p-4 text-danger font-semibold" x-text="error"></div></template>

		{{-- Organizacja --}}
		<section class="ubr-card p-5">
			<h2 class="font-extrabold text-[16px]">Organizacja</h2>
			<p class="text-[12px] text-ink-muted">Aktywna organizacja — dane (wyszukiwania, prospekty) są widoczne wyłącznie dla jej członków.</p>
			<template x-if="org">
				<form class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto] items-end" @submit.prevent="rename()">
					<div>
						<label class="ubr-label" for="o-name">Nazwa</label>
						<input id="o-name" class="ubr-input" x-model="orgName" :disabled="!can('organization.update')" maxlength="190">
					</div>
					<button class="ubr-btn-primary" x-show="can('organization.update')" :disabled="saving || orgName === org.name">Zapisz</button>
					<div class="sm:col-span-2 flex flex-wrap gap-x-6 gap-y-1 text-[12px] text-ink-muted">
						<span>Twoja rola: <strong class="text-ink" x-text="roleLabel(org.role)"></strong></span>
						<span>Plan: <strong class="text-ink" x-text="org.plan"></strong></span>
						<span>Utworzona: <strong class="text-ink" x-text="$store.ubr.date(org.created_at)"></strong></span>
					</div>
				</form>
			</template>
		</section>

		{{-- Członkowie --}}
		<section class="ubr-card overflow-hidden">
			<header class="px-5 pt-5 pb-3">
				<h2 class="font-extrabold text-[16px]">Członkowie</h2>
				<p class="text-[12px] text-ink-muted">Owner — pełna kontrola. Admin — zarządza członkami i ustawieniami. Member — wyszukuje i zapisuje prospekty.</p>
			</header>
			<div class="overflow-x-auto">
				<table class="ubr-table min-w-[600px]">
					<thead><tr><th>Użytkownik</th><th>Rola</th><th>Dołączył</th><th class="text-right"></th></tr></thead>
					<tbody>
						<template x-for="m in (org ? org.members : [])" :key="m.user_id">
							<tr>
								<td>
									<div class="font-semibold"><span x-text="m.name"></span> <span x-show="m.is_me" class="ubr-badge-unavailable">Ty</span></div>
									<div class="text-[12px] text-ink-muted" x-text="m.email || ''"></div>
								</td>
								<td>
									<template x-if="can('members.manage') && !m.is_me">
										<select class="ubr-input h-8 w-[130px] text-[13px]" :value="m.role" @change="changeRole(m, $event.target.value)">
											<option value="member">Member</option><option value="admin">Admin</option><option value="owner" :disabled="!can('members.manage_owners')">Owner</option>
										</select>
									</template>
									<template x-if="!(can('members.manage') && !m.is_me)"><span class="font-semibold" x-text="roleLabel(m.role)"></span></template>
								</td>
								<td class="text-[12px]" x-text="$store.ubr.date(m.joined_at)"></td>
								<td class="text-right">
									<button type="button" class="ubr-btn-danger ubr-btn-sm" x-show="(m.is_me && !(m.role === 'owner' && org.members.filter(x => x.role === 'owner').length < 2)) || (!m.is_me && can('members.manage'))" @click="removeMember(m)" x-text="m.is_me ? 'Opuść' : 'Usuń'"></button>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
			<form class="p-5 border-t border-line grid gap-3 sm:grid-cols-[1fr_160px_auto] items-end" x-show="can('members.manage')" @submit.prevent="addMember()">
				<div>
					<label class="ubr-label" for="m-email">Dodaj istniejącego użytkownika WordPress (e-mail)</label>
					<input id="m-email" class="ubr-input" type="email" required x-model="newMember.email" placeholder="osoba@firma.pl">
				</div>
				<div>
					<label class="ubr-label" for="m-role">Rola</label>
					<select id="m-role" class="ubr-input" x-model="newMember.role">
						<option value="member">Member</option><option value="admin">Admin</option><option value="owner" x-show="can('members.manage_owners')">Owner</option>
					</select>
				</div>
				<button class="ubr-btn-primary" :disabled="saving">@include('ubr.partials.icon', ['name' => 'plus']) Dodaj</button>
			</form>
		</section>

		{{-- Integracje → Apollo --}}
		<section class="ubr-card p-5" x-show="apollo">
			<div class="flex flex-wrap items-start justify-between gap-3">
				<div>
					<p class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Integracje</p>
					<h2 class="font-extrabold text-[16px]">Apollo</h2>
					<p class="text-[12px] text-ink-muted">Główne źródło Discovery. Klucz należy do operatora aplikacji i jest ustawiany w wp-config.php — nigdy nie jest wyświetlany.</p>
				</div>
				<span :class="apollo && apollo.configured ? 'ubr-badge-confirmed' : 'ubr-badge-danger'" x-text="apollo && apollo.configured ? 'Skonfigurowane' : 'Brak klucza'"></span>
			</div>

			<template x-if="apollo">
				<div class="mt-4 space-y-4">
					<dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2 text-[13px] [&>div]:min-w-0 [&_dd]:break-words">
						<div><dt class="text-[12px] text-ink-muted">Źródło klucza</dt><dd class="font-semibold" x-text="apollo.key_source || 'brak — dodaj define(\'UBR_APOLLO_API_KEY\', \'…\'); w wp-config.php'"></dd></div>
						<div><dt class="text-[12px] text-ink-muted">Endpoint wyszukiwania</dt><dd class="font-mono text-[12px]" x-text="apollo.endpoint"></dd></div>
						<div><dt class="text-[12px] text-ink-muted">Limit organizacji</dt><dd><strong x-text="apollo.budget.org_pages_used_today"></strong> / <span x-text="apollo.budget.org_pages_per_day"></span> stron dziś · <strong x-text="apollo.budget.org_pages_used_month"></strong> / <span x-text="apollo.budget.org_pages_per_month"></span> w miesiącu</dd></div>
						<div><dt class="text-[12px] text-ink-muted">Limit na wyszukiwanie</dt><dd><span x-text="apollo.budget.max_pages_per_search"></span> stron (do <span x-text="apollo.budget.max_pages_per_search * 100"></span> firm)</dd></div>
					</dl>
					<p class="text-[12px] text-ink-muted" x-text="apollo.pricing_note"></p>

					<div class="grid gap-3 sm:grid-cols-4">
						<div class="rounded-xl bg-canvas p-3"><div class="text-[11px] text-ink-muted">Zapytania (miesiąc)</div><div class="text-[20px] font-extrabold tabular-nums" x-text="apollo.stats_organization.requests_month"></div></div>
						<div class="rounded-xl bg-canvas p-3"><div class="text-[11px] text-ink-muted">Potencjalnie płatne</div><div class="text-[20px] font-extrabold tabular-nums" x-text="apollo.stats_organization.billable_month"></div></div>
						<div class="rounded-xl bg-canvas p-3"><div class="text-[11px] text-ink-muted">Firmy zwrócone / nowe</div><div class="text-[20px] font-extrabold tabular-nums" x-text="apollo.stats_organization.companies_month + ' / ' + apollo.stats_organization.new_companies_month"></div></div>
						<div class="rounded-xl bg-canvas p-3"><div class="text-[11px] text-ink-muted">Błędy API (miesiąc)</div><div class="text-[20px] font-extrabold tabular-nums" x-text="apollo.stats_organization.errors_month"></div></div>
					</div>
					<p class="text-[11px] text-ink-muted">Liczba zapytań to nie liczba kredytów — Apollo nie raportuje zużycia kredytów w odpowiedzi API. „Potencjalnie płatne” = zapytania przyjęte przez Apollo (bez cache i bez odrzuconych 401/403/429).</p>

					<template x-if="apollo.stats_global">
						<p class="text-[12px] text-ink-soft">Cała instalacja (widoczne tylko dla administratora WP): <strong x-text="apollo.stats_global.billable_today"></strong> potencjalnie płatnych zapytań dziś, <strong x-text="apollo.stats_global.billable_month"></strong> w miesiącu.</p>
					</template>

					<div class="flex flex-wrap gap-2" x-show="apollo.can_manage && apollo.configured">
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="testApollo()" :disabled="apolloBusy">Sprawdź połączenie (bez kredytów)</button>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="!confirmSearchTest" @click="confirmSearchTest = true" :disabled="apolloBusy">Sprawdź dostęp do wyszukiwania…</button>
						<span class="inline-flex items-center gap-2" x-show="confirmSearchTest">
							<span class="text-[12px] text-gold-deep font-semibold">Zużyje 1 kredyt Apollo.</span>
							<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="testApolloSearch()" :disabled="apolloBusy">Wykonaj test</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="confirmSearchTest = false">Anuluj</button>
						</span>
					</div>

					<template x-if="apolloResult">
						<ul class="space-y-1 text-[12px]">
							<template x-for="(r, i) in apolloResult" :key="i">
								<li class="rounded-lg px-3 py-2" :class="r.ok ? 'bg-money-soft text-money' : 'bg-danger-soft text-danger'"><strong x-text="r.label + ': '"></strong><span x-text="r.message"></span></li>
							</template>
						</ul>
					</template>

					<details class="group" x-show="apollo.stats_organization.recent.length">
						<summary class="cursor-pointer text-[12px] font-semibold text-ink-muted list-none inline-flex items-center gap-1">Ostatnie zapytania do Apollo</summary>
						<div class="mt-2 overflow-x-auto rounded-lg border border-line">
							<table class="ubr-table min-w-[640px]">
								<thead><tr><th>Czas (UTC)</th><th>Endpoint</th><th>HTTP</th><th>ms</th><th>Firmy</th><th>Nowe / duplikaty</th><th>Błąd</th></tr></thead>
								<tbody>
									<template x-for="(r, i) in apollo.stats_organization.recent" :key="i">
										<tr>
											<td class="whitespace-nowrap" x-text="r.created_at"></td>
											<td><span x-text="r.endpoint"></span> <span x-show="r.cached == 1" class="ubr-badge-unavailable">cache</span></td>
											<td x-text="r.http_status || '—'"></td>
											<td x-text="r.duration_ms"></td>
											<td x-text="r.results_count ?? '—'"></td>
											<td x-text="(r.new_count ?? '—') + ' / ' + (r.duplicate_count ?? '—')"></td>
											<td class="max-w-[220px] text-danger" x-text="r.error || ''"></td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
					</details>
				</div>
			</template>
		</section>

		{{-- Integracje → Decision Maker Finder (źródła, limity) --}}
		{{-- Integracje → ClickUp --}}
		@include('ubr.partials.clickup-settings')

		<section class="ubr-card p-5 space-y-3" x-show="contactsEnv">
			<div>
				<h2 class="font-extrabold text-[16px]">Decision Maker Finder</h2>
				<p class="text-[12px] text-ink-muted">Wyszukiwanie decydentów z oficjalnych źródeł. Bez zgadywania osób i adresów e-mail, bez płatnych API.</p>
			</div>
			<template x-if="contactsEnv">
				<div class="space-y-3">
					<ul class="text-[12px] space-y-1.5">
						<template x-for="s in contactsEnv.sources" :key="s.key">
							<li>
								<span class="font-semibold" x-text="s.label"></span>
								<span class="ml-1" :class="['enabled'].includes(s.state) ? 'ubr-badge-confirmed' : 'ubr-badge-unavailable'" x-text="{enabled: 'aktywne', disabled: 'wyłączone', not_configured: 'nieskonfigurowane', not_used: 'nieużywane', error: 'błąd'}[s.state] || s.state"></span>
								<div class="text-ink-muted" x-text="s.note || s.message"></div>
							</li>
						</template>
					</ul>
					<div class="text-[12px] text-ink-soft">
						Limity organizacji: <strong x-text="contactsEnv.usage.today + '/' + contactsEnv.usage.per_day"></strong> dziś, <strong x-text="contactsEnv.usage.month + '/' + contactsEnv.usage.per_month"></strong> w miesiącu ·
						maks. <span x-text="contactsEnv.usage.bulk_max"></span> firm w jednym zleceniu · <span x-text="contactsEnv.usage.active_max"></span> aktywne zadanie naraz ·
						ponowne wyszukiwanie po <span x-text="contactsEnv.usage.cooldown_hours"></span> h · maks. <span x-text="contactsEnv.usage.max_pages"></span> podstron na firmę.
					</div>
					<p class="text-[11px] text-ink-muted" x-text="'Wersje: research ' + contactsEnv.version.research + ', Contact Priority ' + contactsEnv.version.score + '. Konfiguracja: stałe UBR_CONTACTS_* w wp-config.php.'"></p>
				</div>
			</template>
		</section>

		{{-- Integracje → Website Analyzer (render w Chromium, AI Vision, limity) --}}
		<section class="ubr-card p-5 space-y-3" x-show="analyzer">
			<div class="flex flex-wrap items-start justify-between gap-3">
				<div>
					<h2 class="font-extrabold text-[16px]">Website Analyzer</h2>
					<p class="text-[12px] text-ink-muted">Analiza stron: HTML + render w Chromium (Playwright) + axe-core. Bezpłatne narzędzia lokalne — bez Apollo i bez płatnych API.</p>
				</div>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="can('integrations.manage')" @click="checkAnalyzer()" :disabled="analyzerBusy">Test środowiska</button>
			</div>
			<template x-if="analyzer">
				<div class="grid gap-3 sm:grid-cols-3 text-[12px]">
					<div class="rounded-lg bg-canvas p-3">
						<div class="font-bold mb-1">Render (screenshoty)</div>
						<template x-if="analyzer.renderer.checked !== false">
							<ul class="space-y-0.5">
								<li>Gotowe: <strong :class="analyzer.renderer.ready ? 'text-money' : 'text-danger'" x-text="analyzer.renderer.ready ? 'tak' : 'nie'"></strong></li>
								<li>Node.js: <strong x-text="analyzer.renderer.node_version || (analyzer.renderer.node_found ? 'znaleziony' : 'brak')"></strong></li>
								<li>Playwright: <strong x-text="analyzer.renderer.playwright || 'brak'"></strong> · axe-core: <strong x-text="analyzer.renderer.axe || 'brak'"></strong></li>
								<li>Przeglądarka: <strong x-text="analyzer.renderer.browser || 'brak'"></strong></li>
								<li x-show="analyzer.renderer.error" class="text-danger" x-text="analyzer.renderer.error"></li>
							</ul>
						</template>
						<p x-show="analyzer.renderer.checked === false" x-text="'Node.js: ' + (analyzer.renderer.node_found ? 'znaleziony' : 'nie znaleziono') + ' · zależności: ' + (analyzer.renderer.dependencies ? 'zainstalowane' : 'brak') + '. Kliknij „Test środowiska”.'"></p>
					</div>
					<div class="rounded-lg bg-canvas p-3">
						<div class="font-bold mb-1">AI Vision</div>
						<p><strong :class="analyzer.ai_vision.enabled ? 'text-money' : 'text-ink-muted'" x-text="analyzer.ai_vision.enabled ? 'włączone' : 'wyłączone'"></strong> · model: <span x-text="analyzer.ai_vision.model"></span></p>
						<p class="text-ink-muted mt-1" x-text="analyzer.ai_vision.note"></p>
						<p class="text-ink-muted mt-1" x-text="'Limit miesięczny: ' + analyzer.ai_vision.used_this_month + '/' + analyzer.ai_vision.monthly_limit + ' · prompt ' + analyzer.ai_vision.prompt_version"></p>
					</div>
					<div class="rounded-lg bg-canvas p-3">
						<div class="font-bold mb-1">Limity organizacji</div>
						<ul class="space-y-0.5">
							<li>Dziś: <strong x-text="analyzer.limits.today + '/' + analyzer.limits.per_day"></strong>, miesiąc: <strong x-text="analyzer.limits.month + '/' + analyzer.limits.per_month"></strong></li>
							<li>Jednorazowo maks.: <strong x-text="analyzer.limits.bulk_max"></strong> · równolegle: <strong x-text="analyzer.limits.concurrency"></strong></li>
							<li>Podstron na analizę: <strong x-text="analyzer.limits.pages"></strong> · limit czasu: <strong x-text="analyzer.limits.timeout_seconds + ' s'"></strong></li>
							<li>Maks. screenshot: <strong x-text="analyzer.limits.screenshot_max_kb + ' KB'"></strong> · ponowna analiza automatyczna po <strong x-text="analyzer.limits.fresh_days + ' dniach'"></strong></li>
							<li>Kolejka: <strong x-text="analyzer.queue.due + ' oczekuje, ' + analyzer.queue.running + ' w toku'"></strong></li>
						</ul>
					</div>
				</div>
			</template>
			<p class="text-[11px] text-ink-muted">Przedziały Opportunity Score: <span x-text="(analyzer?.levels || []).map(l => l.min + '+ ' + l.label).join(' · ')"></span>. Lighthouse / PageSpeed nie są używane — wydajność to jeden pomiar laboratoryjny w Chromium.</p>
		</section>

		<div class="grid gap-6 md:grid-cols-2">
			{{-- Organizacje użytkownika --}}
			<section class="ubr-card p-5">
				<h2 class="font-extrabold text-[16px]">Twoje organizacje</h2>
				<ul class="mt-3 space-y-1.5">
					<template x-for="o in (me ? me.organizations : [])" :key="o.id">
						<li class="flex items-center justify-between gap-2 rounded-xl border border-line px-3 py-2">
							<span class="min-w-0"><span class="block text-[13px] font-semibold truncate" x-text="o.name"></span><span class="text-[11px] text-ink-muted" x-text="roleLabel(o.role)"></span></span>
							<template x-if="me.active_organization && me.active_organization.id === o.id"><span class="ubr-badge-confirmed">Aktywna</span></template>
							<template x-if="!me.active_organization || me.active_organization.id !== o.id">
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="$store.ubr.switchOrganization(o.id)">Przełącz</button>
							</template>
						</li>
					</template>
				</ul>
				<form class="mt-4 flex gap-2" x-show="me && me.can_create_organization" @submit.prevent="createOrganization()">
					<input class="ubr-input" x-model="newOrgName" placeholder="Nazwa nowej organizacji" maxlength="190" required>
					<button class="ubr-btn-ghost" :disabled="saving">Utwórz</button>
				</form>
			</section>

			{{-- Profil --}}
			<section class="ubr-card p-5">
				<h2 class="font-extrabold text-[16px]">Twój profil</h2>
				<form class="mt-3 space-y-3" @submit.prevent="saveProfile()">
					<div>
						<label class="ubr-label" for="u-name">Nazwa wyświetlana</label>
						<input id="u-name" class="ubr-input" x-model="displayName" maxlength="120" required>
					</div>
					<div>
						<span class="ubr-label">E-mail</span>
						<p class="text-[13px] text-ink-soft" x-text="me ? me.user.email : ''"></p>
						<p class="text-[11px] text-ink-muted">E-mail i hasło zmienisz w profilu WordPress.</p>
					</div>
					<button class="ubr-btn-primary" :disabled="saving">Zapisz profil</button>
				</form>
			</section>
		</div>
	</div>
@endsection
