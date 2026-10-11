{{-- Settings → Integrations → ClickUp. Połączenie per użytkownik (OAuth), token szyfrowany po stronie serwera, nigdy nie trafia do przeglądarki. --}}
<section id="clickup" class="ubr-card p-5 space-y-4 scroll-mt-24" x-data="ubrClickUp" x-show="canConnect">
	<div class="flex flex-wrap items-start gap-3">
		<div class="flex-1 min-w-[220px]">
			<h2 class="font-extrabold text-[16px]">ClickUp</h2>
			<p class="text-[12px] text-ink-muted">Eksport firm jako zadań do ClickUp przez Twoje własne konto (OAuth). Każdy członek organizacji łączy swoje konto osobno.</p>
		</div>
		<template x-if="status">
			<span :class="{ connected: 'ubr-badge-confirmed', reconnect_required: 'ubr-badge-danger', not_connected: 'ubr-badge-unavailable', not_configured: 'ubr-badge-unavailable' }[status.state] || 'ubr-badge-unavailable'"
				x-text="{ connected: 'Connected', reconnect_required: 'Expired — Reconnect Required', not_connected: 'Not Connected', not_configured: 'Not Configured' }[status.state] || status.state"></span>
		</template>
	</div>

	<template x-if="loading"><div class="ubr-skeleton h-16"></div></template>

	{{-- Brak konfiguracji serwera: instrukcja (bez udawania połączenia) --}}
	<template x-if="status && !status.configuration.configured">
		<div class="rounded-xl bg-canvas p-4 text-[13px] space-y-2">
			<p class="font-bold">Integracja nie jest jeszcze skonfigurowana na serwerze.</p>
			<p>Brakuje: <strong x-text="status.configuration.missing.join(', ')"></strong>.</p>
			<ol class="list-decimal pl-5 space-y-1 text-ink-soft">
				<li>W ClickUp: <strong>Settings → Apps → Create new app</strong> (wymaga uprawnień administratora Workspace).</li>
				<li>Jako <strong>Redirect URL</strong> podaj: <code class="px-1 rounded bg-white border border-line" x-text="status.configuration.redirect_uri"></code></li>
				<li>Skopiuj <strong>Client ID</strong> i <strong>Client Secret</strong> do <code>wp-config.php</code> (poza repozytorium) jako <code>UBR_CLICKUP_CLIENT_ID</code> i <code>UBR_CLICKUP_CLIENT_SECRET</code>.</li>
				<li>Zalecane: osobny klucz szyfrowania <code>UBR_ENCRYPTION_KEY</code> (wygeneruj: <code>wp ubr secrets key</code>).</li>
			</ol>
			<p class="text-[12px] text-ink-muted">Pełna instrukcja: AGENTS.md → „HOW TO CONNECT CLICKUP”.</p>
		</div>
	</template>

	<template x-if="status && status.configuration.configured">
		<div class="space-y-4">
			<ul class="text-[12px] text-ink-muted space-y-0.5" x-show="status.configuration.notes.length">
				<template x-for="n in status.configuration.notes" :key="n"><li x-text="'• ' + n"></li></template>
			</ul>

			{{-- Połączenie --}}
			<div class="rounded-xl border border-line p-4 space-y-3">
				<template x-if="!status.connected">
					<div class="flex flex-wrap items-center gap-3">
						<p class="text-[13px] flex-1" x-text="status.state === 'reconnect_required' ? 'Token ClickUp przestał działać (' + (status.last_error || 'brak dostępu') + '). Połącz ponownie.' : 'Twoje konto ClickUp nie jest połączone.'"></p>
						<button type="button" class="ubr-btn-primary" @click="connect()" :disabled="busy" x-text="status.state === 'reconnect_required' ? 'Reconnect ClickUp' : 'Connect ClickUp'"></button>
					</div>
				</template>
				<template x-if="status.connected">
					<div class="space-y-2">
						<div class="text-[13px]">Konto: <strong x-text="status.account?.username || '—'"></strong> · połączono <span x-text="$store.ubr.date(status.connected_at)"></span> · ostatni test <span x-text="$store.ubr.date(status.last_checked_at)"></span></div>
						<div class="text-[12px] text-ink-muted">Autoryzowane Workspace: <span x-text="(status.authorized_teams || []).map(t => t.name).join(', ') || '—'"></span></div>
						<div class="flex flex-wrap gap-2">
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="test()" :disabled="busy">Test Connection</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="!confirm.disconnect" @click="confirm.disconnect = true">Disconnect</button>
							<span class="inline-flex gap-1.5 items-center" x-show="confirm.disconnect">
								<span class="text-[12px] text-gold-deep">Reguły korzystające z tego połączenia zostaną wyłączone.</span>
								<button type="button" class="ubr-btn-danger ubr-btn-sm" @click="disconnect()" :disabled="busy">Rozłącz</button>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="confirm.disconnect = false">Nie</button>
							</span>
						</div>
						<template x-if="testResult"><p class="text-[12px] font-semibold" :class="testResult.ok ? 'text-money' : 'text-danger'" x-text="testResult.message"></p></template>
						<p class="text-[11px] text-ink-muted" x-text="status.revocation_note"></p>
					</div>
				</template>
			</div>

			{{-- Default Destination: Workspace → Space → Folder → List --}}
			<template x-if="status.connected">
				<div class="rounded-xl border border-line p-4 space-y-3">
					<div class="flex flex-wrap items-center gap-2">
						<h3 class="font-extrabold text-[14px] flex-1">Default Destination</h3>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="openPicker()" x-show="!picker.open" x-text="status.destination?.list_id ? 'Change destination' : 'Select destination'"></button>
					</div>
					<p class="text-[13px]" x-show="status.destination?.list_id">
						<span x-text="[status.destination?.team_name, status.destination?.space_name, status.destination?.folder_name, status.destination?.list_name].filter(Boolean).join(' → ')"></span>
					</p>
					<p class="text-[13px] text-ink-muted" x-show="!status.destination?.list_id && !picker.open">Nie wybrano listy — eksport jest niedostępny.</p>

					<template x-if="picker.open">
						<div class="grid gap-2 sm:grid-cols-2">
							<div>
								<label class="ubr-label" for="cu-team">Workspace</label>
								<select id="cu-team" class="ubr-input" x-model="picker.team" @change="pickTeam()">
									<option value="">— wybierz —</option>
									<template x-for="t in picker.teams" :key="t.id"><option :value="t.id" x-text="t.name"></option></template>
								</select>
							</div>
							<div>
								<label class="ubr-label" for="cu-space">Space</label>
								<select id="cu-space" class="ubr-input" x-model="picker.space" @change="pickSpace()" :disabled="!picker.team">
									<option value="">— wybierz —</option>
									<template x-for="t in picker.spaces" :key="t.id"><option :value="t.id" x-text="t.name"></option></template>
								</select>
							</div>
							<div>
								<label class="ubr-label" for="cu-folder">Folder (opcjonalnie)</label>
								<select id="cu-folder" class="ubr-input" x-model="picker.folder" @change="pickFolder()" :disabled="!picker.space">
									<option value="">— bez folderu (listy w Space) —</option>
									<template x-for="t in picker.folders" :key="t.id"><option :value="t.id" x-text="t.name"></option></template>
								</select>
							</div>
							<div>
								<label class="ubr-label" for="cu-list">List</label>
								<select id="cu-list" class="ubr-input" x-model="picker.list" :disabled="!picker.space">
									<option value="">— wybierz —</option>
									<template x-for="t in picker.lists" :key="t.id"><option :value="t.id" x-text="t.name"></option></template>
								</select>
							</div>
							<p class="sm:col-span-2 text-[12px] text-ink-muted" x-show="picker.loading">Pobieranie z ClickUp…</p>
							<p class="sm:col-span-2 text-[12px] text-danger" x-show="picker.error" x-text="picker.error"></p>
							<p class="sm:col-span-2 text-[12px] text-ink-muted" x-show="picker.team && !picker.loading && picker.teams.length && !picker.spaces.length">Brak dostępnych Space w tym Workspace.</p>
							<p class="sm:col-span-2 text-[12px] text-ink-muted" x-show="picker.space && !picker.loading && !picker.lists.length">Brak list w tym miejscu — wybierz folder albo utwórz listę w ClickUp.</p>
							<p class="sm:col-span-2 text-[12px] text-ink-muted" x-show="!picker.loading && !picker.teams.length && !picker.error">Brak autoryzowanych Workspace.</p>
							<div class="sm:col-span-2 flex gap-2">
								<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="saveDestination()" :disabled="busy || !picker.list">Zapisz miejsce</button>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="picker.open = false">Anuluj</button>
							</div>
						</div>
					</template>
				</div>
			</template>

			{{-- Default Task Name + Custom Field Mapping --}}
			<template x-if="status.connected && status.destination?.list_id">
				<div class="rounded-xl border border-line p-4 space-y-4">
					<form class="grid gap-2 sm:grid-cols-[1fr_auto] items-end" @submit.prevent="saveTemplate()">
						<div>
							<label class="ubr-label" for="cu-tpl">Default Task Name</label>
							<input id="cu-tpl" class="ubr-input" maxlength="150" x-model="template" required>
							<p class="text-[11px] text-ink-muted mt-1">Zmienne: {company} (wymagana), {domain}, {city}.</p>
						</div>
						<button class="ubr-btn-ghost" :disabled="busy || template === status.settings.task_name_template">Zapisz</button>
					</form>

					<div class="space-y-2">
						<div class="flex flex-wrap items-center gap-2">
							<h3 class="font-extrabold text-[14px] flex-1">Custom Field Mapping</h3>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="loadFields()" x-show="fields === null" :disabled="!!picker.loading">Pobierz pola listy</button>
						</div>
						<p class="text-[12px] text-ink-muted">Tylko istniejące pola wybranej listy o zgodnym typie. Aplikacja nie tworzy pól w ClickUp.</p>
						<template x-if="Object.keys(status.settings.field_mapping || {}).length && fields === null">
							<ul class="text-[12px]"><template x-for="(m, key) in status.settings.field_mapping" :key="key"><li x-text="key + ' → ' + m.name + ' (' + m.type + ')'"></li></template></ul>
						</template>
						<template x-if="fields !== null">
							<form class="grid gap-2 sm:grid-cols-2" @submit.prevent="saveMapping()">
								<template x-if="!fields.length"><p class="sm:col-span-2 text-[12px] text-ink-muted">Ta lista nie ma Custom Fields.</p></template>
								<template x-for="[key, label] in mappable" :key="key">
									<div x-show="fields.length">
										<label class="ubr-label" :for="'map-' + key" x-text="label"></label>
										<select :id="'map-' + key" class="ubr-input" x-model="mapping[key]">
											<option value="">— nie mapuj —</option>
											<template x-for="f in compatible(key)" :key="f.id"><option :value="f.id" x-text="f.name + ' (' + f.type + ')'" :selected="mapping[key] === f.id"></option></template>
										</select>
									</div>
								</template>
								{{-- Prompt 10.4.3: brakujące pola listy leadów — propozycja konfiguracji (aplikacja nie tworzy pól w ClickUp) --}}
								<div class="sm:col-span-2 rounded-lg bg-gold-soft text-gold-deep px-3 py-2 text-[12px]" x-show="fieldSuggestions && fieldSuggestions.missing.length" data-clickup-missing>
									<strong>Brak pól na liście:</strong>
									<template x-for="m in (fieldSuggestions?.missing || [])" :key="m.key"><span class="block" x-text="m.label + ' — proponowany typ pola: ' + m.proposed_type"></span></template>
									Utwórz je w ClickUp (lista docelowa) albo zmapuj istniejące pola — automatycznie niczego nie tworzymy.
								</div>
								<div class="sm:col-span-2" x-show="fields.length"><button class="ubr-btn-ghost ubr-btn-sm" :disabled="busy">Zapisz mapowanie</button></div>
							</form>
						</template>
					</div>
				</div>
			</template>

			{{-- Prompt 10.4.3: zadanie ClickUp po „Przejmij firmę” (ustawienie organizacji, domyślnie wyłączone) --}}
			<template x-if="status.claim_sync">
				<div class="rounded-xl border border-line p-4 space-y-2" data-clickup-claim-sync>
					<div class="flex flex-wrap items-center gap-2">
						<h3 class="font-extrabold text-[14px] flex-1">Zadania po przejęciu firmy</h3>
						<span :class="status.claim_sync.enabled ? 'ubr-badge-confirmed' : 'ubr-badge-unavailable'" x-text="status.claim_sync.enabled ? 'Włączone' : 'Wyłączone'"></span>
					</div>
					<p class="text-[12px] text-ink-muted">Po kliknięciu „Przejmij firmę” aplikacja tworzy jedno zadanie na liście docelowej pracownika (nazwa firmy, telefon firmowy, e-mail, strona, źródło, etap „Leads”), a po znalezieniu decydenta uzupełnia w nim kontakt. Ręczne zmiany w ClickUp nie są nadpisywane.</p>
					<template x-if="status.claim_sync.can_manage">
						<div class="space-y-2 text-[13px]">
							<label class="flex items-start gap-2"><input type="checkbox" class="mt-0.5" :checked="status.claim_sync.org_setting === true || (status.claim_sync.org_setting === null && status.claim_sync.enabled)" @change="saveClaimSync({ enabled: $event.target.checked })" :disabled="busy" data-claim-sync-toggle> <span>Twórz zadanie w ClickUp po przejęciu firmy (cała organizacja)</span></label>
							<label class="flex items-start gap-2" x-show="status.connected && status.destination?.list_id"><input type="checkbox" class="mt-0.5" :checked="status.claim_sync.fallback_is_mine" @change="saveClaimSync({ use_my_connection: $event.target.checked })" :disabled="busy"> <span>Używaj mojego połączenia i listy dla pracowników bez własnego połączenia ClickUp (zadanie bez przypisanej osoby)</span></label>
						</div>
					</template>
					<p class="text-[12px] text-ink-muted" x-show="!status.claim_sync.can_manage">Ustawienie zmienia właściciel lub administrator organizacji.</p>
				</div>
			</template>

			{{-- Automatic Export Rules (DISABLED BY DEFAULT) --}}
			<template x-if="status.connected">
				<div class="rounded-xl border border-line p-4 space-y-3">
					<div class="flex flex-wrap items-center gap-2">
						<h3 class="font-extrabold text-[14px] flex-1">Automatic Export Rules</h3>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="canRules && !ruleForm && status.destination?.list_id" @click="newRule()">@include('ubr.partials.icon', ['name' => 'plus', 'class' => 'size-3.5']) Nowa reguła</button>
					</div>
					<p class="text-[12px] text-ink-muted">Reguły są domyślnie wyłączone i działają tylko dla nowych zdarzeń (dodanie do Prospects, weryfikacja, analiza, kontakty, zmiana statusu) — przez połączenie autora reguły, z limitem dziennym. Nie uruchamiają płatnych analiz.</p>
					<p class="text-[12px] text-ink-muted" x-show="!canRules">Reguły tworzą właściciele i administratorzy organizacji.</p>

					{{-- Formularz reguły --}}
					<template x-if="ruleForm">
						<form class="rounded-xl bg-canvas p-3 grid gap-3 sm:grid-cols-2" @submit.prevent="saveRule()">
							<div class="sm:col-span-2"><label class="ubr-label" for="r-name">Nazwa</label><input id="r-name" class="ubr-input" maxlength="190" required x-model="ruleForm.name" placeholder="np. Gorące leady z decydentem"></div>
							<label class="flex items-center gap-2 text-[13px]"><input type="checkbox" class="ubr-checkbox" x-model="ruleForm.conditions.verified"> Firma VERIFIED</label>
							<label class="flex items-center gap-2 text-[13px]"><input type="checkbox" class="ubr-checkbox" x-model="ruleForm.conditions.domain_confirmed"> Domena CONFIRMED</label>
							<div><label class="ubr-label" for="r-rel">Relevance ≥</label><input id="r-rel" class="ubr-input" type="number" min="0" max="100" x-model="ruleForm.conditions.min_relevance"></div>
							<div><label class="ubr-label" for="r-opp">Opportunity ≥</label><input id="r-opp" class="ubr-input" type="number" min="0" max="100" x-model="ruleForm.conditions.min_opportunity"></div>
							<div><label class="ubr-label" for="r-cps">Contact Priority ≥</label><input id="r-cps" class="ubr-input" type="number" min="0" max="100" x-model="ruleForm.conditions.min_contact_score"></div>
							<div><label class="ubr-label" for="r-min">Min. decydentów</label><select id="r-min" class="ubr-input" x-model="ruleForm.conditions.min_contacts"><option value="">dowolnie</option><option value="1">1</option><option value="2">2</option></select></div>
							<fieldset class="sm:col-span-2">
								<legend class="ubr-label">Status pipeline (dowolny z zaznaczonych)</legend>
								<div class="flex flex-wrap gap-x-4 gap-y-1">
									<template x-for="(label, key) in rules.conditions.statuses || {}" :key="key">
										<label class="flex items-center gap-1.5 text-[13px]"><input type="checkbox" class="ubr-checkbox" :checked="ruleForm.conditions.prospect_statuses.includes(key)" @change="toggleIn(ruleForm.conditions.prospect_statuses, key)"> <span x-text="label"></span></label>
									</template>
								</div>
							</fieldset>
							<fieldset class="sm:col-span-2">
								<legend class="ubr-label">Priorytet (dowolny z zaznaczonych)</legend>
								<div class="flex flex-wrap gap-x-4 gap-y-1">
									<template x-for="(label, key) in rules.conditions.priority_options || {}" :key="key">
										<label class="flex items-center gap-1.5 text-[13px]"><input type="checkbox" class="ubr-checkbox" :checked="ruleForm.conditions.priorities.includes(key)" @change="toggleIn(ruleForm.conditions.priorities, key)"> <span x-text="label"></span></label>
									</template>
								</div>
							</fieldset>
							<div><label class="ubr-label" for="r-lim">Limit dzienny</label><input id="r-lim" class="ubr-input" type="number" min="1" max="100" required x-model.number="ruleForm.daily_limit"></div>
							<div class="text-[12px] text-ink-muted self-end">Lista docelowa: <strong class="text-ink" x-text="status.destination?.list_name"></strong> (Default Destination)</div>
							<div class="sm:col-span-2 flex gap-2">
								<button class="ubr-btn-primary ubr-btn-sm" :disabled="busy">Zapisz i pokaż podgląd</button>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="ruleForm = null">Anuluj</button>
							</div>
						</form>
					</template>

					<template x-if="!rules.rules.length && !ruleForm"><p class="text-[13px] text-ink-muted">Brak reguł.</p></template>
					<template x-for="r in rules.rules" :key="r.id">
						<div class="rounded-xl border border-line p-3 space-y-2">
							<div class="flex flex-wrap items-center gap-2">
								<strong class="text-[13px] flex-1" x-text="r.name"></strong>
								<span :class="r.enabled ? 'ubr-badge-confirmed' : 'ubr-badge-unavailable'" x-text="r.enabled ? 'Enabled' : 'Disabled'"></span>
							</div>
							<div class="text-[12px] text-ink-soft" x-text="r.conditions_text.join(' · ')"></div>
							<div class="text-[12px] text-ink-muted">
								Lista: <span x-text="r.list_name"></span> · autor: <span x-text="r.author?.name"></span> · limit <span x-text="r.today_count + '/' + r.daily_limit"></span> dziś ·
								Last Run: <span x-text="$store.ubr.date(r.last_run_at)"></span> · Exported: <strong x-text="r.exported_count"></strong> · Errors: <strong x-text="r.error_count"></strong>
							</div>
							<p class="text-[12px] text-danger" x-show="!r.connection_ok">Połączenie ClickUp autora jest nieaktywne — reguła nie może działać.</p>

							<template x-if="preview[r.id]">
								<div class="rounded-lg bg-canvas px-3 py-2 text-[12px] space-y-1">
									<p class="font-semibold" x-text="preview[r.id].message"></p>
									<ul class="list-disc pl-4"><template x-for="m in preview[r.id].sample" :key="m.company_id"><li><span x-text="m.name"></span> <span class="text-ink-muted" x-text="m.already_exported ? '(już w ClickUp)' : ''"></span></li></template></ul>
									<p class="text-ink-muted" x-show="preview[r.id].truncated">Sprawdzono pierwsze 500 firm.</p>
								</div>
							</template>

							<div class="flex flex-wrap gap-1.5">
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="dryRun(r)" :disabled="busy">Dry Run</button>
								<template x-if="r.can_edit && !r.enabled">
									<span class="inline-flex gap-1.5 items-center">
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="confirm['en' + r.id] !== true" @click="dryRun(r); confirm['en' + r.id] = true">Enable…</button>
										<template x-if="confirm['en' + r.id] === true">
											<span class="inline-flex gap-1.5 items-center">
												<span class="text-[12px] text-gold-deep">Reguła będzie eksportować nowe pasujące firmy automatycznie.</span>
												<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="enable(r)" :disabled="busy || !r.connection_ok">Potwierdź włączenie</button>
												<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="confirm['en' + r.id] = false">Nie</button>
											</span>
										</template>
									</span>
								</template>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="r.enabled && r.can_disable" @click="disable(r)" :disabled="busy">Disable</button>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="r.can_edit" @click="editRule(r)">Edytuj</button>
								<template x-if="r.can_edit && r.enabled">
									<span class="inline-flex gap-1.5 items-center">
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="confirm['bf' + r.id] !== true" @click="dryRun(r); confirm['bf' + r.id] = true">Eksportuj istniejące…</button>
										<template x-if="confirm['bf' + r.id] === true">
											<span class="inline-flex gap-1.5 items-center">
												<span class="text-[12px] text-gold-deep" x-text="'Wyeksportuje istniejące pasujące firmy (maks. ' + r.daily_limit + ' dziennie).'"></span>
												<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="backfill(r)" :disabled="busy">Potwierdź</button>
												<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="confirm['bf' + r.id] = false">Nie</button>
											</span>
										</template>
									</span>
								</template>
								<template x-if="r.can_disable">
									<span class="inline-flex gap-1.5">
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="confirm['rm' + r.id] !== true" @click="confirm['rm' + r.id] = true">Usuń</button>
										<button type="button" class="ubr-btn-danger ubr-btn-sm" x-show="confirm['rm' + r.id] === true" @click="remove(r)">Potwierdź usunięcie</button>
									</span>
								</template>
							</div>
						</div>
					</template>
				</div>
			</template>

			{{-- Historia eksportu organizacji --}}
			<template x-if="status.connected && recent.length">
				<details class="rounded-xl border border-line p-4">
					<summary class="cursor-pointer font-extrabold text-[14px]">Ostatnie operacje ClickUp (<span x-text="recent.length"></span>)</summary>
					<ul class="mt-3 space-y-1.5">
						<template x-for="j in recent" :key="j.id">
							<li class="text-[12px]">
								<span :class="$store.ubr.exportBadge(j.status)" x-text="j.status_label"></span>
								<button type="button" class="font-semibold hover:underline" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany(j.company.id)" x-text="j.company.name"></button>
								<span class="text-ink-muted" x-text="j.operation_label + ' · ' + $store.ubr.date(j.at) + (j.user ? ' · ' + j.user.name : '')"></span>
								<div class="text-ink-muted" x-show="j.error" x-text="j.error"></div>
							</li>
						</template>
					</ul>
				</details>
			</template>
		</div>
	</template>
</section>
