@extends('ubr.layouts.app')

{{--
	Prompt 10.5 — Sequences: biblioteka kampanii (Campaigns), aktywne sekwencje (Active), zaplanowane wiadomości
	(Scheduled), historia (Sent), skrzynki (Mailboxes) i ustawienia (Settings). Widok tylko czyta dane i zmienia stan
	przez REST — wysyłka wyłącznie w workerze po stronie serwera.
--}}
@section('content')
	<div x-data="ubrSequences" class="space-y-4" data-sequences-page>
		<template x-if="error"><div class="ubr-card p-4 text-danger font-semibold" x-text="error"></div></template>

		{{-- Pasek stanu wysyłki --}}
		<div class="ubr-card p-4 flex flex-wrap items-center gap-3" x-show="overview">
			<span :class="overview?.sending_enabled ? 'ubr-badge-confirmed' : 'ubr-badge-uncertain'" x-text="overview?.sending_enabled ? 'Automatyczna wysyłka włączona' : 'Automatyczna wysyłka wyłączona'" data-sending-badge></span>
			<span class="text-[12px] text-ink-muted" x-show="!overview?.sending_enabled">Sekwencje można przygotowywać i testować (tryb testowy). Prawdziwe wiadomości wyjdą dopiero po włączeniu wysyłki w Settings.</span>
			<span class="ubr-badge-danger" x-show="overview?.counts?.attention" x-text="'Wymaga uwagi: ' + overview?.counts?.attention"></span>
			<span class="ubr-badge-uncertain" x-show="overview?.counts?.unknown" x-text="'Wynik nieznany: ' + overview?.counts?.unknown"></span>
		</div>

		{{-- Zakładki --}}
		<nav class="flex gap-1 border-b border-line overflow-x-auto" role="tablist" aria-label="Sekcje Sequences">
			<template x-for="t in tabs" :key="t.k">
				<button type="button" role="tab" class="px-3 h-10 -mb-px border-b-2 text-[13px] font-semibold whitespace-nowrap shrink-0" :aria-selected="tab === t.k" :data-tab="t.k"
					:class="tab === t.k ? 'border-ink text-ink' : 'border-transparent text-ink-muted hover:text-ink'" @click="tab = t.k">
					<span x-text="t.l"></span>
					<span class="text-[11px] text-ink-muted" x-show="overview && ({campaigns: overview.counts.campaigns, active: overview.counts.active, scheduled: overview.counts.scheduled, mailboxes: overview.counts.mailboxes})[t.k] !== undefined" x-text="'(' + ({campaigns: overview?.counts.campaigns, active: overview?.counts.active, scheduled: overview?.counts.scheduled, mailboxes: overview?.counts.mailboxes})[t.k] + ')'"></span>
				</button>
			</template>
		</nav>

		{{-- ============================ CAMPAIGNS ============================ --}}
		<section x-show="tab === 'campaigns'" class="space-y-4" data-panel="campaigns">
			<template x-if="!form">
				<div class="space-y-3">
					<div class="flex flex-wrap items-end gap-3">
						<div class="w-[180px]">
							<label class="ubr-label" for="cmp-filter">Status</label>
							<select id="cmp-filter" class="ubr-input" x-model="campaignFilter" @change="loadCampaigns()">
								<option value="">Aktywne i szkice</option><option value="active">Aktywne</option><option value="draft">Szkice</option><option value="archived">Zarchiwizowane</option>
							</select>
						</div>
						<button type="button" class="ubr-btn-primary ml-auto" @click="newCampaign()" data-campaign-new>@include('ubr.partials.icon', ['name' => 'plus', 'class' => 'size-4']) Nowa kampania</button>
					</div>
					<p class="text-[12px] text-ink-muted"><span x-show="$store.ubr.can('sequences.templates')">Kampanie wspólne są dostępne dla całego zespołu. </span><span x-show="!$store.ubr.can('sequences.templates')">Kampanie wspólne edytuje właściciel lub administrator — możesz je zduplikować albo utworzyć własną (prywatną). </span>Przypisanie kampanii do firmy tworzy niezależną kopię.</p>
					<template x-if="loading && !campaigns.length"><div class="ubr-skeleton h-24"></div></template>
					<template x-if="!loading && !campaigns.length"><div class="ubr-card p-6 text-[13px] text-ink-muted">Brak kampanii. Utwórz pierwszą — np. „Redesign strony WWW”.</div></template>
					<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
						<template x-for="c in campaigns" :key="c.id">
							<article class="ubr-card p-4 flex flex-col gap-2" :data-campaign="c.name">
								<div class="flex items-start gap-2">
									<h3 class="font-extrabold text-[15px] flex-1 min-w-0 break-words" x-text="c.name"></h3>
									<span :class="c.status === 'active' ? 'ubr-badge-confirmed' : (c.status === 'archived' ? 'ubr-badge-unavailable' : 'ubr-badge-uncertain')" x-text="c.status_label"></span>
								</div>
								<p class="text-[12px] text-ink-muted line-clamp-2" x-text="c.description || 'Bez opisu'"></p>
								<p class="text-[12px]"><span x-text="c.active_steps + ' wiadomości'"></span> · <span x-text="'ok. ' + c.total_days + ' dni'"></span> · <span x-text="c.scope_label"></span> · <span x-text="'v' + c.version"></span></p>
								<p class="text-[11px] text-ink-muted" x-show="c.live_sequences" x-text="'W użyciu: ' + c.live_sequences + ' sekwencji (kopie — zmiana szablonu ich nie zmienia)'"></p>
								<div class="flex flex-wrap gap-1.5 mt-auto pt-1">
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="editCampaign(c)" x-text="c.can_edit ? 'Edytuj' : 'Podgląd'"></button>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="duplicateCampaign(c)" :disabled="busy">Duplikuj</button>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="c.can_edit && c.status !== 'active'" @click="setCampaignStatus(c, 'active')" :disabled="busy">Aktywuj</button>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="c.can_edit && c.status !== 'archived'" @click="setCampaignStatus(c, 'archived')" :disabled="busy">Archiwizuj</button>
								</div>
							</article>
						</template>
					</div>
				</div>
			</template>

			{{-- Kreator kampanii --}}
			<template x-if="form">
				<div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]" data-campaign-editor>
					<div class="ubr-card p-5 space-y-4">
						<div class="flex flex-wrap items-center gap-2">
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="closeEditor(); loadCampaigns()">← Biblioteka</button>
							<h2 class="font-extrabold text-[16px] flex-1" x-text="form.id ? 'Kampania' : 'Nowa kampania'"></h2>
							<span x-show="form.id" :class="form.status === 'active' ? 'ubr-badge-confirmed' : 'ubr-badge-uncertain'" x-text="form.status_label"></span>
						</div>
						<fieldset :disabled="!form.can_edit" class="space-y-4">
							<div class="grid gap-3 sm:grid-cols-[1fr_160px]">
								<div>
									<label class="ubr-label" for="cmp-name">Nazwa</label>
									<input id="cmp-name" class="ubr-input" x-model="form.name" maxlength="160" placeholder="np. Redesign strony WWW" data-campaign-name>
								</div>
								<div>
									<label class="ubr-label" for="cmp-scope">Dostępność</label>
									<select id="cmp-scope" class="ubr-input" x-model="form.scope" :disabled="!!form.id">
										<option value="personal">Prywatna</option>
										<option value="shared" :disabled="!$store.ubr.can('sequences.templates')">Wspólna (zespół)</option>
									</select>
								</div>
							</div>
							<div>
								<label class="ubr-label" for="cmp-desc">Opis</label>
								<textarea id="cmp-desc" class="ubr-input !h-auto py-2" rows="2" x-model="form.description" maxlength="2000" placeholder="Dla jakich firm jest ta kampania?"></textarea>
							</div>

							@include('ubr.partials.seq-step-editor')

							<div class="rounded-xl border border-line p-3 space-y-2 text-[13px]">
								<h3 class="font-bold text-[13px]">Domyślne okno wysyłki</h3>
								<div class="flex flex-wrap gap-1.5" role="group" aria-label="Dni wysyłki">
									<template x-for="d in days" :key="d[0]">
										<button type="button" class="rounded-lg border px-2.5 h-8 text-[12px] font-semibold" :class="form.window.days.includes(d[0]) ? 'bg-ink text-white border-ink' : 'border-line'" @click="toggleDay(d[0])" :aria-pressed="form.window.days.includes(d[0])" x-text="d[1]"></button>
									</template>
								</div>
								<div class="grid gap-2 sm:grid-cols-3">
									<label>Od <input type="time" class="ubr-input h-9" x-model="form.window.from"></label>
									<label>Do <input type="time" class="ubr-input h-9" x-model="form.window.to"></label>
									<label>Strefa <select class="ubr-input h-9" x-model="form.window.timezone"><option>Europe/Warsaw</option><option>Europe/London</option><option>Europe/Berlin</option><option>UTC</option></select></label>
								</div>
							</div>
						</fieldset>
						<template x-for="e in editorErrors()" :key="e"><p class="text-[12px] text-danger" x-text="e"></p></template>
						<div class="flex flex-wrap gap-2" x-show="form.can_edit">
							<button type="button" class="ubr-btn-ghost" @click="saveCampaign()" :disabled="busy" data-campaign-save>Zapisz</button>
							<button type="button" class="ubr-btn-primary" @click="saveCampaign('active')" :disabled="busy" x-show="form.status !== 'active'" data-campaign-activate>Zapisz i aktywuj</button>
							<button type="button" class="ubr-btn-ghost" @click="previewCampaign()" :disabled="busy" data-campaign-preview>Podgląd sekwencji</button>
						</div>
					</div>

					<aside class="ubr-card p-4 space-y-3 self-start" data-campaign-preview-panel>
						<h3 class="font-bold text-[14px]">Podgląd</h3>
						<p class="text-[12px] text-ink-muted" x-show="!preview">Kliknij „Podgląd sekwencji”. Wartości w [nawiasach] to przykłady — przy firmie zostaną podstawione jej dane.</p>
						<template x-for="p in (preview || [])" :key="p.position">
							<div class="rounded-lg bg-canvas/60 p-3 text-[13px]" :class="p.active ? '' : 'opacity-50'">
								<div class="text-[12px] text-ink-muted" x-text="'Wiadomość ' + p.position + (p.at ? ' · ' + $store.ubr.date(p.at) : ' · nieaktywna')"></div>
								<div class="font-semibold mt-1" x-text="p.subject"></div>
								<pre class="whitespace-pre-wrap font-sans text-[12px] mt-1" x-text="p.body"></pre>
							</div>
						</template>
					</aside>
				</div>
			</template>
		</section>

		{{-- ============================ ACTIVE / SCHEDULED / SENT ============================ --}}
		<section x-show="['active', 'scheduled', 'sent'].includes(tab)" class="space-y-3" :data-panel="tab">
			<div class="ubr-card p-4 flex flex-wrap items-end gap-3">
				<div class="flex-1 min-w-[180px]">
					<label class="ubr-label" for="sq-search">Szukaj</label>
					<input id="sq-search" type="search" class="ubr-input" placeholder="Firma lub adres odbiorcy" x-model="filters.search" @input.debounce.400ms="loadList(1)">
				</div>
				<div class="w-[160px]" x-show="list.filters?.team">
					<label class="ubr-label" for="sq-owner">Pracownik</label>
					<select id="sq-owner" class="ubr-input" x-model="filters.owner" @change="loadList(1)">
						<option value="">Wszyscy</option><option value="me">Moje</option>
						<template x-for="m in (list.filters?.members || [])" :key="m.id"><option :value="m.id" x-text="m.name"></option></template>
					</select>
				</div>
				<div class="w-[170px]" x-show="tab === 'active'">
					<label class="ubr-label" for="sq-campaign">Kampania</label>
					<select id="sq-campaign" class="ubr-input" x-model="filters.campaign_id" @change="loadList(1)">
						<option value="">Dowolna</option>
						<template x-for="c in (list.filters?.campaigns || [])" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
					</select>
				</div>
				<div class="w-[160px]" x-show="tab !== 'scheduled'">
					<label class="ubr-label" for="sq-status">Status</label>
					<select id="sq-status" class="ubr-input" x-model="filters.status" @change="loadList(1)">
						<template x-if="tab === 'active'"><optgroup label="Sekwencje"><option value="">Aktywne (bez zakończonych)</option><option value="all">Wszystkie</option><template x-for="(l, k) in (list.filters?.statuses || {})" :key="k"><option :value="k" x-text="l"></option></template></optgroup></template>
						<template x-if="tab === 'sent'"><optgroup label="Wiadomości"><option value="">Wszystkie</option><option value="sent">Przekazane</option><option value="failed">Błędy</option><option value="unknown_result">Wynik nieznany</option></optgroup></template>
					</select>
				</div>
				<div class="w-[180px]" x-show="tab === 'active'">
					<label class="ubr-label" for="sq-mailbox">Skrzynka</label>
					<select id="sq-mailbox" class="ubr-input" x-model="filters.mailbox_id" @change="loadList(1)">
						<option value="">Dowolna</option>
						<template x-for="m in (list.filters?.mailboxes || [])" :key="m.id"><option :value="m.id" x-text="m.email"></option></template>
					</select>
				</div>
				<div class="w-[160px]" x-show="tab === 'active'">
					<label class="ubr-label" for="sq-next">Kolejna wysyłka do</label>
					<input id="sq-next" type="date" class="ubr-input" x-model="filters.next_before" @change="loadList(1)">
				</div>
				<button type="button" class="ubr-btn-ghost" @click="resetFilters()">Wyczyść</button>
			</div>

			<div class="ubr-card overflow-hidden">
				<div class="flex flex-wrap items-center gap-2 px-4 py-2 border-b border-line text-[12px]">
					<span class="text-ink-muted" x-text="list.total + ' pozycji'"></span>
					<label class="ml-auto inline-flex items-center gap-1.5">Wyników na stronie:
						<select class="ubr-input h-8 w-[78px] text-[13px]" :value="list.per_page" @change="setPerPage($event.target.value)"><template x-for="n in perPageOptions" :key="n"><option :value="n" x-text="n" :selected="n === list.per_page"></option></template></select>
					</label>
				</div>
				<div class="overflow-x-auto">
					{{-- Active --}}
					<template x-if="tab === 'active'">
					<table class="ubr-table min-w-[980px]">
						<thead><tr><th>Firma</th><th>Kampania</th><th>Pracownik</th><th>Skrzynka</th><th>Odbiorca</th><th>Postęp</th><th>Ostatnia</th><th>Kolejna</th><th>Status</th><th class="text-right">Akcje</th></tr></thead>
						<tbody>
							<template x-for="s in list.items" :key="s.id">
								<tr :data-row-sequence="s.id">
									<td><button type="button" class="font-semibold hover:underline text-left" @click="openCompany(s.company_id)" x-text="s.company_name"></button></td>
									<td><span x-text="s.campaign?.name || s.name"></span><span x-show="s.test_mode" class="ubr-badge-uncertain ml-1">test</span></td>
									<td x-text="s.owner?.name || '—'"></td>
									<td class="break-all" x-text="s.mailbox?.email || '—'"></td>
									<td class="font-mono text-[12px] break-all" x-text="s.recipient.email || '—'"></td>
									<td class="tabular-nums" x-text="s.progress.sent + '/' + s.progress.total"></td>
									<td class="text-[12px]" x-text="s.last_sent_at ? $store.ubr.date(s.last_sent_at) : '—'"></td>
									<td class="text-[12px]" x-text="s.next_send_label || '—'"></td>
									<td><span :class="seqTone(s.status)" x-text="s.status_label"></span><span class="block text-[11px] text-danger" x-show="s.error" x-text="s.error?.message"></span></td>
									<td class="text-right whitespace-nowrap">
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="openCompany(s.company_id)">Otwórz</button>
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="['scheduled','active','error'].includes(s.status)" @click="control(s, 'pause')" :disabled="busy">Wstrzymaj</button>
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="s.status === 'paused'" @click="control(s, 'resume')" :disabled="busy">Wznów</button>
										<button type="button" class="ubr-btn-danger ubr-btn-sm" x-show="['scheduled','active','paused','error'].includes(s.status)" @click="control(s, 'cancel')" :disabled="busy">Anuluj</button>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
					</template>
					{{-- Scheduled / Sent --}}
					<template x-if="tab === 'scheduled' || tab === 'sent'">
					<table class="ubr-table min-w-[900px]">
						<thead><tr><th>Firma</th><th>Krok</th><th>Temat</th><th>Nadawca</th><th>Odbiorca</th><th x-text="tab === 'sent' ? 'Wysłano' : 'Termin'"></th><th>Status</th><th class="text-right">Akcje</th></tr></thead>
						<tbody>
							<template x-for="m in list.items" :key="m.id">
								<tr :data-row-message="m.id">
									<td><button type="button" class="font-semibold hover:underline text-left" @click="openCompany(m.company_id)" x-text="m.company_name"></button><span class="block text-[11px] text-ink-muted" x-text="m.sequence_name"></span></td>
									<td class="tabular-nums" x-text="m.step + '/' + m.step_total"></td>
									<td class="max-w-[260px] truncate" x-text="m.subject" :title="m.subject"></td>
									<td class="break-all text-[12px]" x-text="m.from_email || '—'"></td>
									<td class="font-mono text-[12px] break-all" x-text="m.to_email || '—'"></td>
									<td class="text-[12px]" x-text="$store.ubr.date(tab === 'sent' ? (m.sent_at || m.scheduled_at) : m.scheduled_at)"></td>
									<td><span :class="msgTone(m.status)" x-text="m.status_label"></span><span x-show="m.test_mode" class="ubr-badge-uncertain ml-1">test</span><span class="block text-[11px] text-ink-muted" x-show="tab === 'scheduled' && m.sequence_status !== 'active' && m.sequence_status !== 'scheduled'" x-text="'Sekwencja: ' + m.sequence_status_label"></span></td>
									<td class="text-right whitespace-nowrap">
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="openMessage(m)" data-message-preview>Podgląd</button>
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="tab === 'scheduled' && m.editable" @click="openCompany(m.company_id)">Edytuj</button>
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="tab === 'scheduled' && ['scheduled','active'].includes(m.sequence_status)" @click="control(m, 'pause')" :disabled="busy">Wstrzymaj sekwencję</button>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
					</template>
				</div>
				<template x-if="!loading && !list.items.length"><p class="p-6 text-[13px] text-ink-muted" x-text="tab === 'active' ? 'Brak sekwencji dla wybranych filtrów.' : (tab === 'scheduled' ? 'Brak zaplanowanych wiadomości.' : 'Brak wysłanych wiadomości.')"></p></template>
				<div class="flex items-center justify-between gap-2 px-4 py-3 border-t border-line text-[12px]" x-show="list.total > list.per_page">
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="list.page <= 1" @click="loadList(list.page - 1)">Poprzednia</button>
					<span x-text="'Strona ' + list.page + ' z ' + pages()"></span>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="list.page >= pages()" @click="loadList(list.page + 1)">Następna</button>
				</div>
			</div>
			<p class="text-[11px] text-ink-muted" x-show="tab === 'sent'">„Przekazana do serwera poczty” = przyjęta przez serwer SMTP nadawcy; to nie jest potwierdzenie dostarczenia. Bez śledzenia otwarć, kliknięć i odpowiedzi.</p>
		</section>

		{{-- Podgląd wiadomości --}}
		<template x-if="message">
			<div class="fixed inset-0 z-50 bg-ink/40 grid place-items-center p-4" @click.self="message = null" @keydown.escape.window="message = null" role="dialog" aria-modal="true" aria-label="Podgląd wiadomości" data-message-modal>
				<div class="bg-white rounded-2xl max-w-[720px] w-full max-h-[90vh] overflow-y-auto p-5 space-y-3">
					<template x-if="message.loading"><div class="ubr-skeleton h-40"></div></template>
					<template x-if="!message.loading">
						<div class="space-y-3 text-[13px]">
							<div class="flex items-start gap-2"><h3 class="font-extrabold text-[15px] flex-1 break-words" x-text="message.preview_subject"></h3><button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="message = null" aria-label="Zamknij">@include('ubr.partials.icon', ['name' => 'x', 'class' => 'size-3.5'])</button></div>
							<dl class="grid grid-cols-2 gap-2 text-[12px]">
								<div><dt class="text-ink-muted">Firma</dt><dd x-text="message.sequence?.company_name"></dd></div>
								<div><dt class="text-ink-muted">Status</dt><dd><span :class="msgTone(message.status)" x-text="message.status_label"></span></dd></div>
								<div><dt class="text-ink-muted">Nadawca</dt><dd class="break-all" x-text="message.from_email || message.sequence?.mailbox?.email || '—'"></dd></div>
								<div><dt class="text-ink-muted">Odbiorca</dt><dd class="break-all" x-text="message.to_email || message.sequence?.recipient?.email || '—'"></dd></div>
								<div><dt class="text-ink-muted" x-text="message.sent_at ? 'Wysłano' : 'Termin'"></dt><dd x-text="$store.ubr.date(message.sent_at || message.scheduled_at)"></dd></div>
								<div><dt class="text-ink-muted">Message-ID</dt><dd class="break-all" x-text="message.message_id || '—'"></dd></div>
							</dl>
							<pre class="whitespace-pre-wrap font-sans rounded-lg bg-canvas/60 p-3" x-text="message.preview_body"></pre>
							<p class="text-[12px] text-ink-muted break-all" x-show="message.smtp_response" x-text="'Odpowiedź serwera: ' + message.smtp_response"></p>
							<p class="text-[12px] text-danger" x-show="message.error && message.status !== 'sent'" x-text="message.error"></p>
							<template x-if="message.status === 'unknown_result' && message.can_manage">
								<div class="rounded-lg bg-gold-soft p-3 space-y-2"><p>Sprawdź folder Wysłane skrzynki nadawczej i potwierdź wynik:</p>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="resolve(message, 'sent')">Wiadomość jest w Wysłanych</button>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="resolve(message, 'not_sent')">Nie wyszła — wyślij ponownie</button></div>
							</template>
						</div>
					</template>
				</div>
			</div>
		</template>

		{{-- ============================ MAILBOXES ============================ --}}
		<section x-show="tab === 'mailboxes'" class="space-y-3" data-panel="mailboxes">
			<div class="flex flex-wrap items-center gap-2">
				<p class="text-[12px] text-ink-muted flex-1 min-w-[240px]">Każdy pracownik wysyła ze <strong>swojej</strong> skrzynki. Połączenie SMTP z hasłem aplikacji (hasło szyfrowane, nigdy nie jest wyświetlane). Test sprawdza połączenie i logowanie — bez wysyłania wiadomości.</p>
				<button type="button" class="ubr-btn-primary" @click="newMailbox()" x-show="!mbForm" data-mailbox-new>@include('ubr.partials.icon', ['name' => 'plus', 'class' => 'size-4']) Podłącz skrzynkę</button>
			</div>

			<template x-if="mbForm">
				<form class="ubr-card p-5 grid gap-3 sm:grid-cols-2" @submit.prevent="saveMailbox()" data-mailbox-form>
					<h3 class="sm:col-span-2 font-extrabold text-[15px]" x-text="mbForm.id ? 'Edycja skrzynki' : 'Nowa skrzynka'"></h3>
					<div x-show="!mbForm.id && testAllowed">
						<label class="ubr-label" for="mb-provider">Typ</label>
						<select id="mb-provider" class="ubr-input" x-model="mbForm.provider"><option value="smtp">SMTP (hasło aplikacji)</option><option value="test">Testowa — nic nie wysyła</option></select>
					</div>
					<div>
						<label class="ubr-label" for="mb-email">Adres e-mail</label>
						<input id="mb-email" type="email" class="ubr-input" x-model="mbForm.email" :disabled="!!mbForm.id" required placeholder="imie@ohsofresh.pl" data-mailbox-email>
					</div>
					<div>
						<label class="ubr-label" for="mb-name">Nazwa nadawcy</label>
						<input id="mb-name" class="ubr-input" x-model="mbForm.from_name" maxlength="120" placeholder="Imię Nazwisko — OhSoFresh">
					</div>
					<template x-if="mbForm.provider === 'smtp'">
						<div class="sm:col-span-2 grid gap-3 sm:grid-cols-2">
							<div><label class="ubr-label" for="mb-host">Serwer SMTP</label><input id="mb-host" class="ubr-input" x-model="mbForm.host" placeholder="smtp.twojadomena.pl" data-mailbox-host></div>
							<div class="grid grid-cols-2 gap-2">
								<div><label class="ubr-label" for="mb-port">Port</label><select id="mb-port" class="ubr-input" x-model.number="mbForm.port" @change="mbForm.encryption = mbForm.port === 465 ? 'ssl' : 'tls'"><option :value="587">587</option><option :value="465">465</option><option :value="2525">2525</option><option :value="25">25</option></select></div>
								<div><label class="ubr-label" for="mb-enc">Szyfrowanie</label><select id="mb-enc" class="ubr-input" x-model="mbForm.encryption"><option value="tls">STARTTLS</option><option value="ssl">SSL/TLS</option></select></div>
							</div>
							<div><label class="ubr-label" for="mb-user">Login (domyślnie adres)</label><input id="mb-user" class="ubr-input" x-model="mbForm.username" autocomplete="off"></div>
							<div><label class="ubr-label" for="mb-pass" x-text="mbForm.has_password ? 'Nowe hasło (zostaw puste, aby nie zmieniać)' : 'Hasło aplikacji'"></label><input id="mb-pass" type="password" class="ubr-input" x-model="mbForm.password" autocomplete="new-password" data-mailbox-password></div>
						</div>
					</template>
					<fieldset class="sm:col-span-2 grid grid-cols-3 gap-2 text-[12px]">
						<legend class="ubr-label">Limity (ochrona skrzynki)</legend>
						<label>Dziennie <input type="number" min="1" class="ubr-input h-9" x-model.number="mbForm.limits.per_day"></label>
						<label>Na godzinę <input type="number" min="1" class="ubr-input h-9" x-model.number="mbForm.limits.per_hour"></label>
						<label>Odstęp (min) <input type="number" min="1" class="ubr-input h-9" x-model.number="mbForm.limits.min_interval_minutes"></label>
					</fieldset>
					<div class="sm:col-span-2 flex gap-2"><button class="ubr-btn-primary" :disabled="busy" data-mailbox-save>Zapisz</button><button type="button" class="ubr-btn-ghost" @click="mbForm = null">Anuluj</button></div>
				</form>
			</template>

			<template x-if="!loading && !mailboxes.length && !mbForm"><div class="ubr-card p-6 text-[13px] text-ink-muted">Nie masz jeszcze podłączonej skrzynki.</div></template>
			<div class="grid gap-3 md:grid-cols-2">
				<template x-for="m in mailboxes" :key="m.id">
					<article class="ubr-card p-4 space-y-2 text-[13px]" :data-mailbox="m.email">
						<div class="flex flex-wrap items-center gap-2">
							<h3 class="font-extrabold break-all flex-1" x-text="m.email"></h3>
							<span x-show="m.is_default" class="ubr-badge-declared">domyślna</span>
							<span :class="mbTone(m.status)" x-text="m.status_label" data-mailbox-status></span>
						</div>
						<p class="text-[12px] text-ink-muted" x-text="m.provider_label + (m.host ? ' · ' + m.host + ':' + m.port + ' (' + (m.encryption === 'ssl' ? 'SSL' : 'STARTTLS') + ')' : '') + ' · nadawca: ' + (m.from_name || '—')"></p>
						<p class="text-[12px]" x-text="'Limity: ' + m.limits.per_day + '/dzień, ' + m.limits.per_hour + '/godz., odstęp ' + m.limits.min_interval_minutes + ' min · wysłane (24 h): ' + m.sent_today"></p>
						<p class="text-[12px] text-danger" x-show="m.last_error" x-text="m.last_error"></p>
						<p class="text-[11px] text-ink-muted" x-show="m.last_tested_at" x-text="'Ostatni test: ' + $store.ubr.date(m.last_tested_at)"></p>
						<template x-if="m.dns_check">
							<div class="rounded-lg bg-canvas/60 p-2 text-[12px] space-y-0.5" data-mailbox-dns>
								<div><span :class="m.dns_check.spf.ok ? 'text-money' : 'text-danger'" x-text="m.dns_check.spf.ok ? '✓' : '✗'"></span> SPF <span class="text-ink-muted" x-text="m.dns_check.spf.note || ''"></span></div>
								<div><span :class="m.dns_check.dkim.ok ? 'text-money' : 'text-gold-deep'" x-text="m.dns_check.dkim.ok ? '✓' : '?'"></span> DKIM <span class="text-ink-muted" x-text="m.dns_check.dkim.ok ? '(' + m.dns_check.dkim.selectors.join(', ') + ')' : m.dns_check.dkim.note"></span></div>
								<div><span :class="m.dns_check.dmarc.ok ? 'text-money' : 'text-danger'" x-text="m.dns_check.dmarc.ok ? '✓' : '✗'"></span> DMARC <span class="text-ink-muted" x-text="m.dns_check.dmarc.note || ''"></span></div>
								<div class="text-ink-muted" x-text="m.dns_check.disclaimer"></div>
							</div>
						</template>
						<div class="flex flex-wrap gap-1.5">
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="testMailbox(m)" :disabled="busy" data-mailbox-test>Testuj połączenie</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="m.provider === 'smtp'" @click="dnsMailbox(m)" :disabled="busy">Sprawdź SPF / DKIM / DMARC</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="!m.is_default" @click="defaultMailbox(m)" :disabled="busy">Ustaw domyślną</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="editMailbox(m)">Edytuj</button>
							<button type="button" class="ubr-btn-danger ubr-btn-sm" @click="disconnectMailbox(m)" :disabled="busy" data-mailbox-disconnect>Odłącz</button>
						</div>
					</article>
				</template>
			</div>
		</section>

		{{-- ============================ SETTINGS ============================ --}}
		<section x-show="tab === 'settings'" class="space-y-4 max-w-[960px]" data-panel="settings">
			<template x-if="settings">
				<div class="space-y-4">
					<div class="ubr-card p-5 space-y-3" data-sending-settings>
						<div class="flex flex-wrap items-center gap-2">
							<h2 class="font-extrabold text-[16px] flex-1">Automatyczna wysyłka</h2>
							<span :class="settings.settings.sending_enabled ? 'ubr-badge-confirmed' : 'ubr-badge-uncertain'" x-text="settings.settings.sending_enabled ? 'Włączona' : 'Wyłączona'"></span>
						</div>
						<p class="text-[12px] text-ink-muted">Domyślnie wyłączona. Włączenie wymaga spełnienia listy kontrolnej. <span x-text="settings.cron_hint"></span></p>
						<ul class="space-y-1 text-[13px]" data-checklist>
							<template x-for="c in settings.checklist" :key="c.key">
								<li class="flex items-start gap-2" :data-check="c.key" :data-ok="c.ok ? '1' : '0'"><span :class="c.ok ? 'text-money' : 'text-danger'" x-text="c.ok ? '✓' : '✗'"></span><span><span class="font-semibold" x-text="c.label"></span> <span class="block text-[12px] text-ink-muted" x-text="c.detail"></span></span></li>
							</template>
						</ul>
						<template x-if="settings.can_manage">
							<div class="space-y-2">
								<label class="flex items-start gap-2 text-[13px]" x-show="!settings.settings.sending_enabled"><input type="checkbox" class="mt-1" x-model="enableForm.confirm_duplicates" data-confirm-duplicates> <span>Rozumiem zabezpieczenia: jedna aktywna sekwencja na adres, limity skrzynek, wynik nieznany bez automatycznego ponowienia, rezygnacja odbiorcy blokuje adres.</span></label>
								<div class="flex flex-wrap gap-2">
									<button type="button" class="ubr-btn-primary ubr-btn-sm" x-show="!settings.settings.sending_enabled" @click="setSending(true)" :disabled="busy" data-sending-enable>Włącz automatyczną wysyłkę</button>
									<button type="button" class="ubr-btn-danger ubr-btn-sm" x-show="settings.settings.sending_enabled" @click="setSending(false)" :disabled="busy" data-sending-disable>Wyłącz wysyłkę</button>
								</div>
							</div>
						</template>
					</div>

					<form class="ubr-card p-5 space-y-3 text-[13px]" @submit.prevent="saveSettings()" data-module-settings>
						<h2 class="font-extrabold text-[16px]">Domyślne ustawienia</h2>
						<fieldset :disabled="!settings.can_manage" class="space-y-3">
							<div>
								<span class="ubr-label">Domyślne okno wysyłki (nowe kampanie)</span>
								<div class="flex flex-wrap gap-1.5 mb-2">
									<template x-for="d in days" :key="d[0]">
										<button type="button" class="rounded-lg border px-2.5 h-8 text-[12px] font-semibold" :class="settings.settings.window.days.includes(d[0]) ? 'bg-ink text-white border-ink' : 'border-line'" @click="toggleSettingsDay(d[0])" x-text="d[1]"></button>
									</template>
								</div>
								<div class="grid gap-2 sm:grid-cols-3">
									<label>Od <input type="time" class="ubr-input h-9" x-model="settings.settings.window.from"></label>
									<label>Do <input type="time" class="ubr-input h-9" x-model="settings.settings.window.to"></label>
									<label>Strefa <select class="ubr-input h-9" x-model="settings.settings.window.timezone"><template x-for="tz in settings.timezones" :key="tz"><option :value="tz" x-text="tz" :selected="tz === settings.settings.window.timezone"></option></template></select></label>
								</div>
							</div>
							<div class="grid gap-2 sm:grid-cols-2">
								<label>Maks. limit dzienny skrzynki (zespół) <input type="number" min="1" class="ubr-input h-9" x-model.number="settings.settings.caps.per_day"></label>
								<label>Maks. limit godzinowy skrzynki (zespół) <input type="number" min="1" class="ubr-input h-9" x-model.number="settings.settings.caps.per_hour"></label>
							</div>
							<div class="grid gap-2 sm:grid-cols-3">
								<label>Domyślnie dziennie <input type="number" min="1" class="ubr-input h-9" x-model.number="settings.settings.mailbox_defaults.per_day"></label>
								<label>Domyślnie na godzinę <input type="number" min="1" class="ubr-input h-9" x-model.number="settings.settings.mailbox_defaults.per_hour"></label>
								<label>Domyślny odstęp (min) <input type="number" min="1" class="ubr-input h-9" x-model.number="settings.settings.mailbox_defaults.min_interval_minutes"></label>
							</div>
							<label class="flex items-start gap-2"><input type="checkbox" class="mt-1" x-model="settings.settings.append_unsubscribe"> <span>Dodawaj stopkę z linkiem rezygnacji (zalecane)</span></label>
							<label class="block">Tekst stopki <textarea class="ubr-input !h-auto py-2" rows="2" x-model="settings.settings.unsubscribe_text"></textarea></label>
							<button class="ubr-btn-primary ubr-btn-sm" x-show="settings.can_manage" :disabled="busy">Zapisz ustawienia</button>
						</fieldset>
					</form>

					<div class="ubr-card p-5 space-y-3 text-[13px]" data-suppressions>
						<h2 class="font-extrabold text-[16px]">Zablokowane adresy</h2>
						<p class="text-[12px] text-ink-muted">Rezygnacje, trwałe błędy dostarczenia i wykluczenia ręczne. Na te adresy nie wyjdzie żadna wiadomość.</p>
						<form class="grid gap-2 sm:grid-cols-[1fr_170px_1fr_auto] items-end" @submit.prevent="addSuppression()">
							<input type="email" class="ubr-input h-9" placeholder="adres@firma.pl" x-model="supForm.email" required aria-label="Adres do zablokowania">
							<select class="ubr-input h-9" x-model="supForm.reason" aria-label="Powód"><option value="manual">Wykluczony ręcznie</option><option value="unsubscribed">Rezygnacja</option></select>
							<input class="ubr-input h-9" placeholder="Notatka (opcjonalnie)" x-model="supForm.note" aria-label="Notatka">
							<button class="ubr-btn-ghost ubr-btn-sm" :disabled="busy">Zablokuj</button>
						</form>
						<ul class="divide-y divide-line">
							<template x-for="s in suppressions.items" :key="s.email">
								<li class="py-2 flex flex-wrap items-center gap-2"><span class="font-mono break-all" x-text="s.email"></span><span class="ubr-badge-declared" x-text="s.reason_label"></span><span class="text-[12px] text-ink-muted flex-1" x-text="s.note || ''"></span><button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="suppressions.can_remove" @click="removeSuppression(s)">Odblokuj</button></li>
							</template>
						</ul>
					</div>
				</div>
			</template>
		</section>
	</div>
@endsection
