{{--
	Prompt 10.5 — karta firmy → Mailing / Sequences. Przypisanie kampanii tworzy SZKIC (niezależna kopia kroków dla tej
	firmy). Wysyłka dopiero po świadomym zatwierdzeniu odbiorcy, skrzynki, treści i harmonogramu. Status „Mailing”
	w pipeline niczego nie wysyła. Otwarcie zakładki = tylko odczyt.
--}}
<div x-data="ubrMailing" class="space-y-5" data-mailing-tab>
	<template x-if="loading && !data"><div class="ubr-skeleton h-24"></div></template>
	<template x-if="data?.error"><p class="text-danger text-[13px] font-semibold" x-text="data.error"></p></template>

	<template x-if="data && !data.error">
		<div class="space-y-5">
			{{-- Nagłówek: stan wysyłki i akcja przypisania --}}
			<div class="flex flex-wrap items-center gap-2">
				<h3 class="font-extrabold text-[15px] flex-1">Mailing / Sequences</h3>
				<span :class="data.sending_enabled ? 'ubr-badge-confirmed' : 'ubr-badge-unavailable'" x-text="data.sending_enabled ? 'Automatyczna wysyłka włączona' : 'Automatyczna wysyłka wyłączona'" data-sending-state></span>
				<button type="button" class="ubr-btn-primary ubr-btn-sm" x-show="data.can_manage && !assign" @click="startAssign()" data-assign-open>@include('ubr.partials.icon', ['name' => 'plus', 'class' => 'size-3.5']) Przypisz kampanię</button>
			</div>
			<p class="text-[12px] text-ink-muted" x-show="data.manage_hint" x-text="data.manage_hint"></p>

			{{-- Lista sekwencji firmy --}}
			<div class="flex flex-wrap gap-2" x-show="data.sequences.length > 1">
				<template x-for="s in data.sequences" :key="s.id">
					<button type="button" class="rounded-lg border px-2.5 py-1 text-[12px] text-left" :class="detail?.id === s.id ? 'border-ink' : 'border-line'" @click="open(s.id)">
						<span class="font-semibold" x-text="s.name"></span> · <span x-text="s.status_label"></span>
					</button>
				</template>
			</div>

			{{-- Kreator przypisania --}}
			<template x-if="assign">
				<section class="rounded-xl border border-ink/20 bg-canvas/40 p-4 space-y-3" data-assign>
					<h4 class="font-bold text-[14px]">Nowa sekwencja dla tej firmy</h4>
					<div class="grid gap-3 sm:grid-cols-2">
						<div>
							<label class="ubr-label" for="as-campaign">Kampania z biblioteki</label>
							<select id="as-campaign" class="ubr-input" x-model="assign.campaign_id" data-assign-campaign>
								<option value="">— pusta (indywidualna) —</option>
								<template x-for="c in campaigns" :key="c.id"><option :value="c.id" x-text="c.name + ' (' + c.active_steps + ' wiad., ' + c.scope_label.toLowerCase() + ')'"></option></template>
							</select>
						</div>
						<div>
							<label class="ubr-label" for="as-mailbox">Skrzynka nadawcza (Twoja)</label>
							<select id="as-mailbox" class="ubr-input" x-model="assign.mailbox_id" data-assign-mailbox>
								<template x-for="m in mailboxes" :key="m.id"><option :value="m.id" x-text="m.email + ' — ' + m.status_label"></option></template>
							</select>
							<p class="text-[11px] text-danger mt-1" x-show="!mailboxes.length">Brak skrzynki — podłącz ją w Sequences → Mailboxes.</p>
						</div>
					</div>
					<fieldset class="space-y-1.5" data-assign-recipients>
						<legend class="ubr-label">Odbiorca</legend>
						<template x-for="o in (recipients?.options || [])" :key="o.source + ':' + (o.contact_id || o.email)">
							<label class="flex items-start gap-2 text-[13px] rounded-lg border border-line px-3 py-2" :class="o.blockers.length ? 'opacity-60' : ''">
								<input type="radio" name="as-recipient" class="mt-1" :value="o.source + ':' + (o.contact_id || o.email)" x-model="assign.recipient_key" :disabled="o.blockers.length > 0">
								<span class="min-w-0">
									<span class="font-mono" x-text="o.email"></span>
									<span class="ubr-badge-declared ml-1" x-text="o.source_label"></span>
									<span class="block text-[12px] text-ink-muted" x-show="o.name || o.note" x-text="[o.name, o.note].filter(Boolean).join(' — ')"></span>
									<template x-for="b in o.blockers" :key="b.code"><span class="block text-[12px] text-danger" x-text="b.message"></span></template>
									<span class="block text-[12px] text-gold-deep" x-show="o.conflict" x-text="o.conflict ? 'Ma już aktywną sekwencję: ' + o.conflict.name + ' (' + o.conflict.company_name + ')' : ''"></span>
								</span>
							</label>
						</template>
						<label class="flex items-start gap-2 text-[13px] rounded-lg border border-line px-3 py-2">
							<input type="radio" name="as-recipient" class="mt-1" value="manual" x-model="assign.recipient_key" data-recipient-manual>
							<span class="flex-1 grid gap-2 sm:grid-cols-2">
								<input type="email" class="ubr-input h-9" placeholder="adres służbowy, np. jan.kowalski@firma.pl" x-model="assign.manual_email" @focus="assign.recipient_key = 'manual'" aria-label="Adres odbiorcy wpisany ręcznie" data-recipient-email>
								<input class="ubr-input h-9" placeholder="Imię i nazwisko (opcjonalnie)" x-model="assign.manual_name" @focus="assign.recipient_key = 'manual'" aria-label="Imię i nazwisko odbiorcy">
							</span>
						</label>
						<p class="text-[11px] text-ink-muted" x-text="recipients?.note"></p>
					</fieldset>
					<div class="flex flex-wrap gap-2">
						<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="createSequence()" :disabled="busy" data-assign-create>Utwórz szkic (bez wysyłki)</button>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="assign = null">Anuluj</button>
					</div>
				</section>
			</template>

			<template x-if="!detail && !assign && data.sequences.length === 0">
				<p class="text-[13px] text-ink-muted">Ta firma nie ma jeszcze sekwencji e-mail.</p>
			</template>

			{{-- Szczegóły wybranej sekwencji --}}
			<template x-if="detail">
				<section class="space-y-4" data-sequence :data-sequence-status="detail.status">
					<div class="rounded-xl border border-line p-4 space-y-3">
						<div class="flex flex-wrap items-center gap-2">
							<h4 class="font-extrabold text-[14px] flex-1 min-w-0 truncate" x-text="detail.name"></h4>
							<span x-show="detail.test_mode" class="ubr-badge-uncertain">Tryb testowy</span>
							<span :class="seqTone(detail.status)" x-text="detail.status_label" data-sequence-badge></span>
						</div>
						<dl class="grid grid-cols-2 min-[560px]:grid-cols-3 gap-x-4 gap-y-2 text-[13px]">
							<div><dt class="text-ink-muted text-[12px]">Kampania</dt><dd x-text="detail.campaign ? detail.campaign.name + ' (wersja ' + detail.campaign.version + ')' : 'Indywidualna'"></dd></div>
							<div><dt class="text-ink-muted text-[12px]">Skrzynka nadawcza</dt><dd class="break-all" x-text="detail.mailbox ? detail.mailbox.email : '—'"></dd></div>
							<div><dt class="text-ink-muted text-[12px]">Odbiorca</dt><dd class="break-all"><span class="font-mono" x-text="detail.recipient.email || '—'"></span> <span class="block text-[11px] text-ink-muted" x-text="detail.recipient.source_label || ''"></span></dd></div>
							<div><dt class="text-ink-muted text-[12px]">Wiadomości</dt><dd x-text="detail.progress.sent + ' / ' + detail.progress.total + ' wysłanych'" data-sequence-progress></dd></div>
							<div><dt class="text-ink-muted text-[12px]">Kolejna wiadomość</dt><dd x-text="detail.next_send_label || '—'"></dd></div>
							<div><dt class="text-ink-muted text-[12px]">Ostatnia wysyłka</dt><dd x-text="detail.last_sent_at ? $store.ubr.date(detail.last_sent_at) : '—'"></dd></div>
						</dl>
						<p class="text-[12px] text-danger font-semibold" x-show="detail.error" x-text="detail.error?.message"></p>
						<p class="text-[12px] text-ink-muted" x-text="'Okno wysyłki: ' + detail.window_label"></p>
						<div class="flex flex-wrap gap-2" x-show="detail.can_manage">
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="['scheduled','active','error'].includes(detail.status)" @click="control('pause')" :disabled="busy" data-seq-pause>@include('ubr.partials.icon', ['name' => 'pause', 'class' => 'size-3.5']) Wstrzymaj</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="['paused','error'].includes(detail.status)" @click="control('resume')" :disabled="busy" data-seq-resume>@include('ubr.partials.icon', ['name' => 'play', 'class' => 'size-3.5']) Wznów</button>
							<button type="button" class="ubr-btn-danger ubr-btn-sm" x-show="['scheduled','active','paused','error'].includes(detail.status)" @click="control('cancel')" :disabled="busy" data-seq-cancel>Anuluj sekwencję</button>
							<button type="button" class="ubr-btn-danger ubr-btn-sm" x-show="detail.status === 'draft'" @click="control('delete')" :disabled="busy">Usuń szkic</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="detail.recipient.email && !['completed','cancelled'].includes(detail.status)" @click="unsubscribeRecipient()" :disabled="busy" data-seq-unsubscribe>Wypisz odbiorcę</button>
						</div>
					</div>

					{{-- SZKIC: edycja kopii kampanii dla tej firmy --}}
					<template x-if="form && detail.status === 'draft'">
						<div class="space-y-4" data-draft-editor>
							<p class="text-[12px] rounded-lg bg-canvas px-3 py-2">Zmiany dotyczą <strong>tylko tej firmy</strong> — kampania w bibliotece pozostaje bez zmian.</p>
							<div class="grid gap-3 sm:grid-cols-2">
								<div>
									<label class="ubr-label" for="sq-name">Nazwa sekwencji</label>
									<input id="sq-name" class="ubr-input" x-model="form.name" maxlength="160">
								</div>
								<div x-init="ensureMailboxes()">
									<label class="ubr-label" for="sq-mailbox">Skrzynka nadawcza</label>
									<select id="sq-mailbox" class="ubr-input" x-model="form.mailbox_id">
										<template x-for="m in mailboxes" :key="m.id"><option :value="m.id" x-text="m.email + ' — ' + m.status_label" :selected="m.id == form.mailbox_id"></option></template>
									</select>
								</div>
							</div>

							@include('ubr.partials.seq-step-editor')

							<details class="rounded-xl border border-line p-3" open>
								<summary class="cursor-pointer font-semibold text-[13px]">Harmonogram</summary>
								<div class="mt-3 grid gap-3 sm:grid-cols-2 text-[13px]">
									<div class="sm:col-span-2 flex flex-wrap gap-1.5" role="group" aria-label="Dni wysyłki">
										<template x-for="d in days" :key="d[0]">
											<button type="button" class="rounded-lg border px-2.5 h-8 text-[12px] font-semibold" :class="form.window.days.includes(d[0]) ? 'bg-ink text-white border-ink' : 'border-line'" @click="toggleDay(d[0])" :aria-pressed="form.window.days.includes(d[0])" x-text="d[1]"></button>
										</template>
									</div>
									<label>Od godziny <input type="time" class="ubr-input h-9" x-model="form.window.from"></label>
									<label>Do godziny <input type="time" class="ubr-input h-9" x-model="form.window.to"></label>
									<label>Strefa czasowa
										<select class="ubr-input h-9" x-model="form.window.timezone"><option>Europe/Warsaw</option><option>Europe/London</option><option>Europe/Berlin</option><option>UTC</option></select>
									</label>
									<label>Start (opcjonalnie) <input type="datetime-local" class="ubr-input h-9" x-model="form.start_at" data-seq-start-at></label>
									<label>Koniec (opcjonalnie) <input type="datetime-local" class="ubr-input h-9" x-model="form.end_at"></label>
								</div>
							</details>

							<details class="rounded-xl border border-line p-3" :open="detail.missing.length > 0" data-variables>
								<summary class="cursor-pointer font-semibold text-[13px]">Personalizacja <span class="text-danger" x-show="detail.missing.length" x-text="'— brakuje: ' + detail.missing.length"></span></summary>
								<p class="text-[12px] text-ink-muted mt-2">Wartości z Ugly but Rich. Brakujące uzupełnij ręcznie — nie zgadujemy nazwisk, stanowisk ani faktów o firmie. Wiadomość z brakującą wartością nie zostanie wysłana.</p>
								<div class="mt-3 grid gap-2 sm:grid-cols-2">
									<template x-for="v in detail.variables.filter(x => !x.system)" :key="v.key">
										<label class="text-[12px]">
											<span class="font-semibold" x-text="varToken(v.key)"></span> <span class="text-ink-muted" x-text="v.label"></span>
											<input class="ubr-input h-9 mt-1" :class="detail.missing.includes(v.key) ? 'border-danger' : ''" x-model="form.variables[v.key]" :placeholder="v.auto ? 'auto: ' + v.auto : 'brak danych — wpisz ręcznie'" :data-var="v.key">
										</label>
									</template>
								</div>
							</details>

							<div class="flex flex-wrap gap-2">
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="saveDraft()" :disabled="busy" data-draft-save>Zapisz i odśwież podgląd</button>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="aiInfo()" title="Wkrótce: propozycja tematu i treści z AI (do zatwierdzenia)" data-ai-personalize>@include('ubr.partials.icon', ['name' => 'sparkle', 'class' => 'size-3.5']) Spersonalizuj z AI</button>
							</div>

							{{-- Podgląd z harmonogramem --}}
							<div class="rounded-xl border border-line p-3 space-y-3" data-preview>
								<h5 class="font-bold text-[13px]">Podgląd wiadomości i terminów</h5>
								<template x-for="p in (detail.preview || [])" :key="p.position">
									<div class="rounded-lg bg-canvas/60 p-3 text-[13px]" :class="p.active ? '' : 'opacity-50'">
										<div class="flex flex-wrap gap-2 text-[12px] text-ink-muted"><span x-text="'Wiadomość ' + p.position"></span><span x-show="p.at" x-text="'· ' + $store.ubr.date(p.at)"></span><span x-show="!p.active">· nieaktywna</span></div>
										<div class="font-semibold mt-1" x-text="p.subject"></div>
										<pre class="whitespace-pre-wrap font-sans text-[13px] mt-1" x-text="p.body"></pre>
										<p class="text-[12px] text-danger" x-show="p.missing.length" x-text="'Brakujące dane: ' + p.missing.map(k => varToken(k)).join(', ')"></p>
									</div>
								</template>
								<p class="text-[11px] text-ink-muted" x-show="detail.footer" x-text="'Do każdej wiadomości zostanie dodana stopka: ' + detail.footer"></p>
							</div>

							{{-- Zatwierdzenie --}}
							<div class="rounded-xl border-2 border-ink/80 p-4 space-y-2" data-approve>
								<template x-for="b in detail.blockers" :key="b.code"><p class="text-[12px] text-danger font-semibold" x-text="b.message"></p></template>
								<p class="text-[12px] text-gold-deep" x-show="detail.conflict" x-text="detail.conflict ? 'Ten adres ma już aktywną sekwencję: ' + detail.conflict.name + ' (' + detail.conflict.company_name + ').' : ''"></p>
								<label class="flex items-start gap-2 text-[13px]"><input type="checkbox" class="mt-1" x-model="approve.confirm" data-approve-confirm> <span>Potwierdzam odbiorcę <strong class="font-mono" x-text="detail.recipient.email"></strong>, skrzynkę, treść wszystkich wiadomości i harmonogram.</span></label>
								<label class="flex items-start gap-2 text-[13px]"><input type="checkbox" class="mt-1" x-model="approve.test_mode" data-approve-test> <span>Tryb testowy — wiadomości zostaną przetworzone według harmonogramu, ale <strong>nie wyjdą</strong> do odbiorcy.</span></label>
								<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="start()" :disabled="busy || !approve.confirm" data-approve-start>Zatwierdź i uruchom harmonogram</button>
								<template x-if="conflict">
									<div class="rounded-lg bg-gold-soft p-3 text-[13px] space-y-2" data-conflict>
										<p x-text="conflict.message"></p>
										<div class="flex flex-wrap gap-2">
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="conflict = null">Pozostaw obecną sekwencję</button>
											<button type="button" class="ubr-btn-danger ubr-btn-sm" @click="start(true)" data-conflict-replace>Zakończ obecną i uruchom nową</button>
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="conflict = null; approve.confirm = false">Anuluj</button>
										</div>
									</div>
								</template>
							</div>
						</div>
					</template>

					{{-- URUCHOMIONA: kroki, edycja niewysłanych, historia --}}
					<template x-if="detail.status !== 'draft'">
						<div class="space-y-2" data-messages>
							<template x-for="m in detail.messages" :key="m.id">
								<article class="rounded-xl border border-line p-3 text-[13px]" :data-message-status="m.status">
									<div class="flex flex-wrap items-center gap-2">
										<span class="font-bold" x-text="'Wiadomość ' + m.position"></span>
										<span :class="msgTone(m.status)" x-text="m.status_label"></span>
										<span x-show="!m.is_active" class="ubr-badge-unavailable">nieaktywna</span>
										<span class="text-[12px] text-ink-muted ml-auto" x-text="m.sent_at ? 'wysłano ' + $store.ubr.date(m.sent_at) : (m.scheduled_at ? 'plan: ' + $store.ubr.date(m.scheduled_at) : (m.position > 1 ? m.delay_days + ' dni po poprzedniej' : ''))"></span>
									</div>
									<div class="font-semibold mt-1" x-text="m.preview_subject"></div>
									<details class="mt-1"><summary class="cursor-pointer text-[12px] text-ink-muted">Treść</summary><pre class="whitespace-pre-wrap font-sans text-[13px] mt-1" x-text="m.preview_body"></pre></details>
									<p class="text-[11px] text-ink-muted break-all" x-show="m.message_id" x-text="'Message-ID: ' + m.message_id + (m.smtp_response ? ' · ' + m.smtp_response : '')"></p>
									<p class="text-[12px] text-danger" x-show="m.error && m.status !== 'sent'" x-text="m.error"></p>
									<template x-if="m.status === 'unknown_result' && detail.can_manage">
										<div class="mt-2 rounded-lg bg-gold-soft p-2 space-y-1" data-unknown>
											<p class="text-[12px]">Sprawdź folder <strong>Wysłane</strong> skrzynki <span x-text="m.from_email"></span>:</p>
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="resolve(m, 'sent')" data-unknown-sent>Wiadomość jest w Wysłanych</button>
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="resolve(m, 'not_sent')" data-unknown-not-sent>Nie wyszła — wyślij ponownie</button>
										</div>
									</template>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm mt-2" x-show="m.editable && detail.can_manage && !['completed','cancelled'].includes(detail.status)" @click="editMessage(m)" data-message-edit>Edytuj</button>
									<template x-if="editing && editing.id === m.id">
										<div class="mt-2 space-y-2" data-message-editor>
											<input class="ubr-input" x-model="editing.subject" aria-label="Temat">
											<textarea class="ubr-input !h-auto min-h-[120px] py-2" x-model="editing.body" aria-label="Treść"></textarea>
											<div class="flex flex-wrap items-center gap-3 text-[12px]">
												<label>Odstęp <input type="number" min="0" max="365" class="ubr-input h-8 w-[72px] inline-block" x-model.number="editing.delay_days"> dni</label>
												<label class="inline-flex items-center gap-1"><input type="checkbox" x-model="editing.is_active"> aktywna</label>
												<label x-show="editing.scheduled">Nowy termin <input type="datetime-local" class="ubr-input h-8 inline-block w-auto" x-model="editing.scheduled_at"></label>
											</div>
											<div class="flex gap-2"><button type="button" class="ubr-btn-primary ubr-btn-sm" @click="saveMessage()" :disabled="busy" data-message-save>Zapisz</button><button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="editing = null">Anuluj</button></div>
										</div>
									</template>
								</article>
							</template>
							<p class="text-[11px] text-ink-muted">„Przekazana do serwera poczty” oznacza przyjęcie przez serwer SMTP nadawcy — nie potwierdza dostarczenia do skrzynki odbiorcy. Odpowiedzi nie są wykrywane automatycznie — po kontakcie z klientem zatrzymaj sekwencję ręcznie.</p>
						</div>
					</template>
				</section>
			</template>
		</div>
	</template>
</div>
