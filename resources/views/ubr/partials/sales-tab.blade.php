{{-- Karta firmy → Sprzedaż / Pipeline (prywatne dla organizacji). Dane: GET /companies/{id}/sales + /companies/{id}/clickup. --}}
<div class="space-y-6" x-data="{
	get s() { return $store.ubr.drawer.sales },
	get p() { return $store.ubr.drawer.sales?.prospect },
	get cu() { return $store.ubr.drawer.clickup },
	get contactsList() { const c = $store.ubr.drawer.contacts; return c ? [...(c.top || []), ...(c.others || [])] : [] },
	note: '',
	editing: null,
	editBody: '',
	activity: { type: 'call', description: '', occurred_at: window.ubrLocalInput() },
	followup: { title: '', due_at: window.ubrLocalInput(new Date(Date.now() + 86400000)), assigned_user_id: '', description: '' },
	lostReason: '',
	reconcileTask: '',
	confirmNew: false,
	confirmArchive: false,
	confirmDelete: null,
}" x-effect="lostReason = p?.lost_reason || ''">

	<template x-if="$store.ubr.drawer.salesLoading && !s">
		<div class="space-y-3"><div class="ubr-skeleton h-24"></div><div class="ubr-skeleton h-40"></div></div>
	</template>

	{{-- Firma spoza Prospects --}}
	<template x-if="s && !p">
		<div class="rounded-xl border border-line p-5 text-center">
			<p class="font-bold">Ta firma nie jest w Prospects Twojej organizacji.</p>
			<p class="text-[13px] text-ink-muted mt-1">Dodaj ją, aby prowadzić status, notatki, aktywności i follow-upy.</p>
			<button type="button" class="ubr-btn-primary mt-3" x-show="$store.ubr.can('prospects.manage')" @click="await $store.ubr.saveProspect($store.ubr.drawer.company); $store.ubr.loadSales()" :disabled="$store.ubr.drawer.saving">Zapisz do Prospects</button>
		</div>
	</template>

	<template x-if="p">
		<div class="space-y-6">
			<template x-if="p.archived_at">
				<div class="rounded-xl bg-gold-soft text-gold-deep px-4 py-3 text-[13px] flex flex-wrap items-center gap-3">
					<span class="flex-1">Zarchiwizowano <span x-text="$store.ubr.date(p.archived_at)"></span>. Historia jest zachowana.</span>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="$store.ubr.can('prospects.manage')" @click="$store.ubr.archiveProspect(true)">Przywróć</button>
				</div>
			</template>

			{{-- Status, priorytet, właściciel, kwalifikacja --}}
			<section class="grid gap-3 sm:grid-cols-2">
				<div>
					<label class="ubr-label" for="s-status">Status pipeline</label>
					<select id="s-status" class="ubr-input" :value="p.status" @change="$store.ubr.updateProspect({ status: $event.target.value }, 'Zmieniono status.')" :disabled="!s.can_manage || $store.ubr.drawer.saving || !!p.archived_at">
						<template x-for="st in s.statuses" :key="st.key"><option :value="st.key" x-text="st.label" :selected="st.key === p.status"></option></template>
					</select>
				</div>
				<div>
					<label class="ubr-label" for="s-prio">Priorytet sprzedażowy</label>
					<select id="s-prio" class="ubr-input" :value="p.priority" @change="$store.ubr.updateProspect({ priority: $event.target.value }, 'Zmieniono priorytet.')" :disabled="!s.can_manage || $store.ubr.drawer.saving || !!p.archived_at">
						<template x-for="(label, key) in s.priorities" :key="key"><option :value="key" x-text="label" :selected="key === p.priority"></option></template>
					</select>
					<p class="text-[11px] text-ink-muted mt-1">Niezależny od Opportunity / Relevance / Contact Priority Score.</p>
				</div>
				<div>
					<label class="ubr-label" for="s-owner">Właściciel</label>
					<select id="s-owner" class="ubr-input" @change="$store.ubr.updateProspect({ owner_user_id: $event.target.value ? Number($event.target.value) : null }, 'Zmieniono właściciela.')" :disabled="!s.can_manage || $store.ubr.drawer.saving || !!p.archived_at">
						<option value="">Bez właściciela</option>
						<template x-for="m in s.members" :key="m.id"><option :value="String(m.id)" x-text="m.name" :selected="m.id === p.owner_user_id"></option></template>
					</select>
				</div>
				<div>
					<label class="ubr-label" for="s-contact">Wybrany kontakt</label>
					<select id="s-contact" class="ubr-input" @change="$store.ubr.updateProspect({ selected_contact_id: $event.target.value ? Number($event.target.value) : null }, 'Zapisano wybrany kontakt.')" :disabled="!s.can_manage || $store.ubr.drawer.saving || !!p.archived_at">
						<option value="">— nie wybrano —</option>
						<template x-for="ct in contactsList" :key="ct.id"><option :value="String(ct.id)" x-text="ct.full_name + (ct.job_title ? ' — ' + ct.job_title : '')" :selected="ct.id === p.selected_contact_id"></option></template>
					</select>
					<p class="text-[11px] text-ink-muted mt-1" x-show="!contactsList.length">Brak kontaktów — wyszukaj decydentów w zakładce Decision Makers.</p>
				</div>
				<template x-if="s.reason_statuses.includes(p.status)">
					<div class="sm:col-span-2">
						<label class="ubr-label" for="s-lost">Powód (<span x-text="p.status_label"></span>)</label>
						<div class="flex gap-2">
							<input id="s-lost" class="ubr-input flex-1" maxlength="500" x-model="lostReason" placeholder="np. wybrali inną agencję, brak budżetu…" :disabled="!s.can_manage">
							<button type="button" class="ubr-btn-ghost" x-show="s.can_manage" :disabled="lostReason === (p.lost_reason || '') || $store.ubr.drawer.saving" @click="$store.ubr.updateProspect({ lost_reason: lostReason }, 'Zapisano powód.')">Zapisz</button>
						</div>
					</div>
				</template>
			</section>

			{{-- Kwalifikacja leada: sugestia z istniejących scoringów, decyzja zawsze ręczna --}}
			<section class="rounded-xl border border-line p-4 space-y-3">
				<div class="flex flex-wrap items-center gap-2">
					<h3 class="font-extrabold text-[14px] flex-1">Kwalifikacja leada</h3>
					<span x-show="p.qualification" :class="$store.ubr.qualificationBadge(p.qualification)" x-text="s.qualifications[p.qualification]"></span>
					<span x-show="!p.qualification" class="ubr-badge-unavailable">Bez decyzji</span>
				</div>
				<div class="rounded-lg bg-canvas px-3 py-2 text-[12px]">
					<div>Sugestia na podstawie danych: <strong x-text="s.suggestion.label"></strong></div>
					<ul class="list-disc pl-4 mt-1 text-ink-soft"><template x-for="r in s.suggestion.reasons" :key="r"><li x-text="r"></li></template></ul>
					<p class="text-ink-muted mt-1">To tylko podpowiedź — niski Opportunity Score nie blokuje pracy z firmą.</p>
				</div>
				<div class="flex flex-wrap gap-1.5" x-show="s.can_manage && !p.archived_at">
					<template x-for="(label, key) in s.qualifications" :key="key">
						<button type="button" class="ubr-btn-sm" :class="p.qualification === key ? 'ubr-btn-primary' : 'ubr-btn-ghost'" @click="$store.ubr.updateProspect({ qualification: p.qualification === key ? null : key }, 'Zapisano kwalifikację.')" x-text="label" :aria-pressed="p.qualification === key"></button>
					</template>
				</div>
			</section>

			<div class="grid grid-cols-3 gap-2 text-[12px]">
				<div class="rounded-xl border border-line p-3"><div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Dodano</div><div class="mt-1 font-semibold" x-text="$store.ubr.date(p.created_at)"></div></div>
				<div class="rounded-xl border border-line p-3"><div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Ostatnia aktywność</div><div class="mt-1 font-semibold" x-text="$store.ubr.date(p.last_activity_at)"></div></div>
				<div class="rounded-xl border border-line p-3"><div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Następne działanie</div><div class="mt-1 font-semibold" x-text="$store.ubr.date(p.next_action_at)"></div></div>
			</div>

			{{-- Follow-upy --}}
			<section class="space-y-3">
				<h3 class="font-extrabold text-[14px]">Follow-upy</h3>
				<template x-if="!s.followups.length"><p class="text-[13px] text-ink-muted">Brak zaplanowanych działań.</p></template>
				<template x-for="f in s.followups" :key="f.id">
					<div class="rounded-xl border p-3 flex flex-wrap items-start gap-2" :class="f.overdue ? 'border-danger/40 bg-danger-soft/40' : 'border-line'">
						<div class="flex-1 min-w-[200px]">
							<div class="font-semibold text-[13px]" :class="f.status !== 'open' ? 'line-through text-ink-muted' : ''" x-text="f.title"></div>
							<div class="text-[12px] text-ink-muted"><span :class="f.overdue ? 'text-danger font-bold' : ''" x-text="(f.overdue ? 'Zaległe · ' : '') + $store.ubr.date(f.due_at)"></span> · <span x-text="f.assigned ? f.assigned.name : 'nieprzypisane'"></span> · <span x-text="f.status_label"></span></div>
							<p class="text-[12px] text-ink-soft mt-1 whitespace-pre-line" x-show="f.description" x-text="f.description"></p>
						</div>
						<div class="flex gap-1.5" x-show="s.can_manage && f.status === 'open'">
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="$store.ubr.setFollowup(f, 'completed')">@include('ubr.partials.icon', ['name' => 'check', 'class' => 'size-3.5']) Wykonane</button>
							<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="$store.ubr.setFollowup(f, 'cancelled')">Anuluj</button>
						</div>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="s.can_manage && f.status !== 'open'" @click="$store.ubr.setFollowup(f, 'open')">Otwórz ponownie</button>
					</div>
				</template>
				<form class="rounded-xl bg-canvas p-3 grid gap-2 sm:grid-cols-[1fr_190px_170px]" x-show="s.can_manage && !p.archived_at"
					@submit.prevent="if (await $store.ubr.addFollowup({ ...followup, assigned_user_id: followup.assigned_user_id ? Number(followup.assigned_user_id) : null })) { followup.title = ''; followup.description = '' }">
					<input class="ubr-input" placeholder="Np. Zadzwonić do prezesa" maxlength="190" required x-model="followup.title" aria-label="Tytuł follow-upu">
					<input class="ubr-input" type="datetime-local" required x-model="followup.due_at" aria-label="Termin">
					<select class="ubr-input" x-model="followup.assigned_user_id" aria-label="Przypisz do">
						<option value="">Przypisz do mnie</option>
						<template x-for="m in s.members" :key="m.id"><option :value="String(m.id)" x-text="m.name"></option></template>
					</select>
					<textarea class="ubr-input sm:col-span-2 min-h-[38px]" rows="1" placeholder="Opis (opcjonalnie)" maxlength="2000" x-model="followup.description" aria-label="Opis"></textarea>
					<button class="ubr-btn-primary" :disabled="$store.ubr.drawer.saving">Zaplanuj</button>
				</form>
			</section>

			{{-- Aktywności (rejestrowane ręcznie — nic nie jest wysyłane) --}}
			<section class="space-y-3">
				<h3 class="font-extrabold text-[14px]">Aktywności</h3>
				<form class="rounded-xl bg-canvas p-3 grid gap-2 sm:grid-cols-[150px_1fr_190px_auto]" x-show="s.can_manage && !p.archived_at"
					@submit.prevent="if (await $store.ubr.addActivity(activity)) { activity.description = ''; activity.occurred_at = window.ubrLocalInput() }">
					<select class="ubr-input" x-model="activity.type" aria-label="Typ aktywności">
						<template x-for="(label, key) in s.activity_types" :key="key"><option :value="key" x-text="label"></option></template>
					</select>
					<input class="ubr-input" placeholder="Co się wydarzyło?" maxlength="2000" required x-model="activity.description" aria-label="Opis aktywności">
					<input class="ubr-input" type="datetime-local" x-model="activity.occurred_at" :max="window.ubrLocalInput()" aria-label="Kiedy">
					<button class="ubr-btn-primary" :disabled="$store.ubr.drawer.saving">Zapisz</button>
				</form>
				<p class="text-[11px] text-ink-muted">Rejestr ręczny — aplikacja nie wysyła e-maili ani wiadomości LinkedIn.</p>
				<template x-if="!s.activities.length"><p class="text-[13px] text-ink-muted">Brak aktywności.</p></template>
				<ul class="space-y-2">
					<template x-for="a in s.activities" :key="a.id">
						<li class="rounded-xl border border-line px-3 py-2 text-[13px]">
							<div class="flex items-center gap-2"><span class="ubr-badge-declared" x-text="a.type_label"></span><span class="text-[12px] text-ink-muted" x-text="$store.ubr.date(a.occurred_at) + ' · ' + (a.user ? a.user.name : '—')"></span></div>
							<p class="mt-1 whitespace-pre-line" x-text="a.description"></p>
						</li>
					</template>
				</ul>
			</section>

			{{-- Notatki (prywatne dla organizacji; nigdy nie trafiają do ClickUp) --}}
			<section class="space-y-3">
				<h3 class="font-extrabold text-[14px]">Notatki <span class="text-[11px] font-semibold text-ink-muted">prywatne dla organizacji · nie są eksportowane do ClickUp</span></h3>
				<form class="flex gap-2" x-show="s.can_manage && !p.archived_at" @submit.prevent="if (await $store.ubr.addNote(note)) note = ''">
					<textarea class="ubr-input flex-1 min-h-[60px]" maxlength="5000" placeholder="Dodaj notatkę…" x-model="note" required aria-label="Nowa notatka"></textarea>
					<button class="ubr-btn-primary self-end" :disabled="$store.ubr.drawer.saving || !note.trim()">Dodaj</button>
				</form>
				<template x-if="!s.notes.length"><p class="text-[13px] text-ink-muted">Brak notatek.</p></template>
				<template x-for="n in s.notes" :key="n.id">
					<div class="rounded-xl border border-line px-3 py-2">
						<div class="flex items-center gap-2 text-[12px] text-ink-muted">
							<span class="font-semibold text-ink" x-text="n.author ? n.author.name : '—'"></span>
							<span x-text="$store.ubr.date(n.created_at) + (n.updated_at ? ' (edytowano)' : '')"></span>
							<span class="ml-auto inline-flex gap-1">
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="n.mine && editing !== n.id" @click="editing = n.id; editBody = n.body">Edytuj</button>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="(n.mine || s.can_moderate) && confirmDelete !== n.id" @click="confirmDelete = n.id">Usuń</button>
								<button type="button" class="ubr-btn-danger ubr-btn-sm" x-show="confirmDelete === n.id" @click="await $store.ubr.deleteNote(n); confirmDelete = null">Potwierdź</button>
								<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="confirmDelete === n.id" @click="confirmDelete = null">Nie</button>
							</span>
						</div>
						<template x-if="editing !== n.id"><p class="mt-1 text-[13px] whitespace-pre-line" x-text="n.body"></p></template>
						<template x-if="editing === n.id">
							<form class="mt-2 flex gap-2" @submit.prevent="if (await $store.ubr.saveNote(n, editBody)) editing = null">
								<textarea class="ubr-input flex-1 min-h-[60px]" maxlength="5000" x-model="editBody" required aria-label="Edycja notatki"></textarea>
								<div class="flex flex-col gap-1.5"><button class="ubr-btn-primary ubr-btn-sm">Zapisz</button><button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="editing = null">Anuluj</button></div>
							</form>
						</template>
					</div>
				</template>
			</section>

			{{-- ClickUp --}}
			<section class="rounded-xl border border-line p-4 space-y-3">
				<div class="flex items-center gap-2">
					<h3 class="font-extrabold text-[14px] flex-1">ClickUp</h3>
					<span x-show="cu && cu.connection && cu.connection.status === 'connected'" class="ubr-badge-confirmed">Connected</span>
					<span x-show="cu && (!cu.connection || cu.connection.status !== 'connected')" class="ubr-badge-unavailable" x-text="cu && cu.connection && cu.connection.status === 'reconnect_required' ? 'Reconnect Required' : 'Not Connected'"></span>
				</div>
				<template x-if="!cu"><div class="ubr-skeleton h-10"></div></template>
				<template x-if="cu">
					<div class="space-y-3 text-[13px]">
						<template x-if="!cu.configured && !cu.connection"><p class="text-ink-muted">Integracja ClickUp nie jest skonfigurowana na serwerze (Settings → Integrations → ClickUp zawiera instrukcję).</p></template>
						<template x-if="cu.configured && (!cu.connection || cu.connection.status !== 'connected')">
							<p class="text-ink-muted">Połącz swoje konto w <a class="underline" :href="UBR.urls.settings + '#clickup'">Settings → Integrations → ClickUp</a>.</p>
						</template>
						<template x-if="cu.connection && cu.connection.status === 'connected' && !cu.connection.list_id">
							<p class="text-ink-muted">Wybierz listę docelową w <a class="underline" :href="UBR.urls.settings + '#clickup'">Settings</a>.</p>
						</template>

						<template x-if="cu.connection && cu.connection.list_id">
							<div class="space-y-2">
								<div class="text-[12px] text-ink-muted">Lista: <strong class="text-ink" x-text="cu.connection.list || cu.connection.list_id"></strong></div>
								<template x-if="cu.current">
									<div class="rounded-lg bg-canvas px-3 py-2 space-y-1">
										<div class="flex flex-wrap items-center gap-2">
											<span :class="$store.ubr.exportBadge(cu.current.status)" x-text="cu.current.status === 'success' ? 'Already exported' : cu.current.status_label"></span>
											<a x-show="cu.current.task_url" :href="cu.current.task_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold underline">Open in ClickUp @include('ubr.partials.icon', ['name' => 'external', 'class' => 'size-3'])</a>
										</div>
										<div class="text-[12px] text-ink-muted">Ostatni eksport: <span x-text="$store.ubr.date(cu.current.exported_at)"></span> · Ostatnia synchronizacja: <span x-text="$store.ubr.date(cu.current.last_synced_at)"></span></div>
										<div class="text-[12px] text-danger" x-show="cu.current.error" x-text="cu.current.error"></div>
									</div>
								</template>

								{{-- Rekoncyliacja stanu UNKNOWN (brak odpowiedzi ClickUp po wysłaniu żądania) --}}
								<template x-if="cu.current && cu.current.status === 'unknown' && cu.can_export">
									<div class="rounded-lg border border-danger/30 bg-danger-soft/40 p-3 space-y-2 text-[12px]">
										<p><strong>Stan nieznany.</strong> ClickUp nie potwierdził utworzenia zadania. Sprawdź listę w ClickUp i rozstrzygnij — nie tworzymy zadania ponownie automatycznie.</p>
										<form class="flex gap-2" @submit.prevent="$store.ubr.reconcileClickup(cu.current, 'set_task', reconcileTask)">
											<input class="ubr-input flex-1 h-8 text-[12px]" placeholder="Link lub ID zadania (https://app.clickup.com/t/…)" x-model="reconcileTask" required aria-label="Link do zadania">
											<button class="ubr-btn-ghost ubr-btn-sm">Zadanie istnieje</button>
										</form>
										<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="$store.ubr.reconcileClickup(cu.current, 'mark_not_created')">Zadanie nie powstało — pozwól wyeksportować ponownie</button>
									</div>
								</template>

								<div class="flex flex-wrap gap-1.5" x-show="cu.can_export && cu.connection.status === 'connected' && !p.archived_at">
									<template x-if="!cu.current || ['failed', 'cancelled'].includes(cu.current.status)">
										<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="$store.ubr.exportClickup($store.ubr.drawer.company.id, 'create')">Eksportuj do ClickUp</button>
									</template>
									<template x-if="cu.current && cu.current.status === 'success'">
										<span class="inline-flex flex-wrap gap-1.5">
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="$store.ubr.exportClickup($store.ubr.drawer.company.id, 'update')" title="Aktualizuje tylko sekcję Ugly but Rich i zmapowane pola — bez komentarzy, checklist, załączników i statusu">Update existing task</button>
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="!confirmNew" @click="confirmNew = true">Export as new task</button>
											<span class="inline-flex gap-1.5 items-center" x-show="confirmNew">
												<span class="text-[12px] text-gold-deep">Utworzy drugie zadanie dla tej firmy.</span>
												<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="confirmNew = false; $store.ubr.exportClickup($store.ubr.drawer.company.id, 'new')">Utwórz</button>
												<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="confirmNew = false">Nie</button>
											</span>
										</span>
									</template>
									<template x-if="cu.current && ['queued', 'running'].includes(cu.current.status)">
										<span class="text-[12px] text-ink-muted">Eksport w toku…</span>
									</template>
								</div>
							</div>
						</template>

						<template x-if="cu.exports.length > 1 || (cu.exports.length === 1 && (!cu.current || cu.exports[0].id !== cu.current.id))">
							<div>
								<div class="text-[11px] font-bold uppercase tracking-wider text-ink-muted mb-1">Zadania na innych listach / warianty</div>
								<template x-for="e in cu.exports.filter(e => !cu.current || e.id !== cu.current.id)" :key="e.id">
									<div class="text-[12px] flex flex-wrap gap-2 items-center"><span :class="$store.ubr.exportBadge(e.status)" x-text="e.status_label"></span><span x-text="e.list_name || e.list_id"></span><a x-show="e.task_url" :href="e.task_url" target="_blank" rel="noopener" class="underline">otwórz</a></div>
								</template>
							</div>
						</template>

						<details x-show="cu.history.length">
							<summary class="cursor-pointer text-[12px] font-semibold text-ink-muted">Historia eksportu (<span x-text="cu.history.length"></span>)</summary>
							<ul class="mt-2 space-y-1.5">
								<template x-for="j in cu.history" :key="j.id">
									<li class="text-[12px]">
										<span :class="$store.ubr.exportBadge(j.status)" x-text="j.status_label"></span>
										<span x-text="j.operation_label + ' · ' + $store.ubr.date(j.at) + (j.user ? ' · ' + j.user.name : '') + (j.trigger === 'rule' ? ' · reguła automatyczna' : '')"></span>
										<div class="text-ink-muted" x-show="j.error" x-text="j.error"></div>
										<template x-for="n in j.notes" :key="n"><div class="text-ink-muted" x-text="n"></div></template>
									</li>
								</template>
							</ul>
						</details>
					</div>
				</template>
			</section>

			{{-- Historia zmian --}}
			<details class="rounded-xl border border-line p-4">
				<summary class="cursor-pointer font-extrabold text-[14px]">Historia zmian (<span x-text="s.history.length"></span>)</summary>
				<ul class="mt-3 space-y-1.5">
					<template x-for="(h, i) in s.history" :key="i">
						<li class="text-[12px]"><span class="text-ink-muted" x-text="$store.ubr.date(h.at)"></span> · <span x-text="h.text"></span> <span class="text-ink-muted" x-text="h.user ? '(' + h.user.name + ')' : ''"></span></li>
					</template>
				</ul>
			</details>

			<div class="flex justify-end" x-show="$store.ubr.can('prospects.manage') && !p.archived_at">
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="!confirmArchive" @click="confirmArchive = true">Archiwizuj (usuń z Prospects)</button>
				<span class="inline-flex gap-1.5 items-center" x-show="confirmArchive">
					<span class="text-[12px] text-ink-muted">Historia zostanie zachowana.</span>
					<button type="button" class="ubr-btn-danger ubr-btn-sm" @click="confirmArchive = false; $store.ubr.archiveProspect(false)">Archiwizuj</button>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="confirmArchive = false">Nie</button>
				</span>
			</div>
		</div>
	</template>
</div>
