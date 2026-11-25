@extends('ubr.layouts.app')

@section('actions')
	@include('ubr.partials.view-switch', ['active' => 'board'])
@endsection

@section('content')
	{{-- Pipeline = leady w statusie Mailing i ich sekwencje. Wysyłka wyłącznie po podglądzie i potwierdzeniu w zakładce Mailing karty firmy. --}}
	<div x-data="ubrPipeline" class="space-y-4">
		<div class="ubr-card p-4 flex flex-wrap items-end gap-3">
			<div class="flex-1 min-w-[200px]">
				<label class="ubr-label" for="m-search">Szukaj</label>
				<input id="m-search" class="ubr-input" type="search" placeholder="Nazwa, domena, miasto…" x-model="filters.search" @input.debounce.350ms="load()">
			</div>
			<div class="w-[170px]">
				<label class="ubr-label" for="m-owner">Opiekun</label>
				<select id="m-owner" class="ubr-input" x-model="filters.owner" @change="load()">
					<option value="">Wszyscy</option><option value="me">Moje</option><option value="none">Bez opiekuna</option>
					<template x-for="m in members" :key="m.id"><option :value="String(m.id)" x-text="m.name"></option></template>
				</select>
			</div>
			<button type="button" class="ubr-btn-ghost" x-show="filters.search || filters.owner" @click="filters = { search: '', owner: '' }; load()">Wyczyść</button>
		</div>

		<div class="flex flex-wrap gap-2" role="tablist" aria-label="Stan sekwencji">
			<template x-for="t in tabs" :key="t.key">
				<button type="button" role="tab" class="ubr-btn-ghost ubr-btn-sm" :class="tab === t.key ? '!bg-ink !text-white' : ''" :aria-selected="tab === t.key" @click="tab = t.key">
					<span x-text="t.label"></span> <span class="tabular-nums opacity-70" x-text="count(t.key)"></span>
				</button>
			</template>
		</div>

		<template x-if="error"><div class="ubr-card p-4 text-danger font-semibold" x-text="error"></div></template>

		<div class="ubr-card overflow-hidden" :class="loading ? 'opacity-60' : ''">
			<table class="ubr-table table-fixed w-full">
				<colgroup><col class="w-[26%]"><col class="w-[12%]"><col class="w-[34%]"><col class="w-[12%]"><col class="w-[16%]"></colgroup>
				<thead><tr><th>Firma</th><th>Opiekun</th><th>Sekwencja</th><th>Postęp</th><th class="text-right">Akcje</th></tr></thead>
				<tbody>
					<template x-if="loading && !items.length"><tr><td colspan="5"><div class="ubr-skeleton h-10"></div></td></tr></template>
					<template x-if="!loading && !visible.length">
						<tr><td colspan="5" class="text-center text-ink-muted py-8" x-text="items.length ? 'Brak leadów w tej grupie.' : 'Brak leadów w Mailing. Trafiają tu po zmianie statusu w ClickUp (np. „Cold mail”) albo po przeniesieniu w karcie firmy.'"></td></tr>
					</template>
					<template x-for="card in visible" :key="card.prospect_id">
						<tr :data-row-prospect="card.prospect_id">
							<td>
								<button type="button" class="font-semibold text-left hover:underline break-words" @click="$store.ubr.drawer.tab = 'company'; $store.ubr.openCompany(card.company_id)" x-text="card.name"></button>
								<div class="text-[12px] text-ink-muted truncate" x-text="card.domain || 'brak domeny'"></div>
							</td>
							<td class="truncate" x-text="card.owner ? card.owner.name : '—'"></td>
							<td>
								<template x-if="card.sequence">
									<div class="space-y-0.5">
										<div class="flex items-center gap-1.5 min-w-0">
											<span class="font-semibold truncate" x-text="card.sequence.campaign" :title="card.sequence.campaign"></span>
											<span :class="seqBadge(card.sequence)" x-text="card.sequence.status_label"></span>
											<span class="ubr-badge-uncertain" x-show="card.sequence.test_mode">test</span>
										</div>
										<div class="text-[12px] text-ink-muted font-mono break-all" x-text="card.sequence.recipient || 'brak odbiorcy'"></div>
										<div class="text-[12px] text-danger font-semibold" x-show="card.sequence.missing.length" x-text="'Brakuje: ' + card.sequence.missing.join(', ')"></div>
									</div>
								</template>
								<template x-if="!card.sequence && form?.prospect_id !== card.prospect_id">
									<span class="text-ink-muted">Brak sekwencji</span>
								</template>

								{{-- Przypisanie kampanii: tworzy SZKIC (nic nie wysyła) --}}
								<template x-if="form && form.prospect_id === card.prospect_id">
									<div class="space-y-2">
										<div>
											<label class="ubr-label" :for="'m-camp-' + card.prospect_id">Kampania</label>
											<select :id="'m-camp-' + card.prospect_id" class="ubr-input h-9" x-model="form.campaign_id">
												<option value="">Wybierz kampanię…</option>
												<template x-for="c in campaigns" :key="c.id"><option :value="String(c.id)" x-text="c.name"></option></template>
											</select>
										</div>
										<div>
											<label class="ubr-label" :for="'m-rcp-' + card.prospect_id">Odbiorca</label>
											<select :id="'m-rcp-' + card.prospect_id" class="ubr-input h-9" x-model="form.recipient_key" :disabled="form.loading">
												<template x-for="o in form.options" :key="optionKey(o)">
													<option :value="optionKey(o)" :disabled="o.blockers.length > 0" x-text="o.email + (o.name ? ' — ' + o.name : ' — ' + o.source_label) + (o.blockers.length ? ' (zablokowany: ' + o.blockers[0].message + ')' : '')"></option>
												</template>
												<option value="manual">Inny adres…</option>
											</select>
											<input type="email" class="ubr-input h-9 mt-1.5" x-show="form.recipient_key === 'manual'" x-model="form.manual_email" placeholder="adres służbowy odbiorcy" aria-label="Adres odbiorcy wpisany ręcznie">
										</div>
										<div x-show="mailboxes.length > 1">
											<label class="ubr-label" :for="'m-mb-' + card.prospect_id">Skrzynka</label>
											<select :id="'m-mb-' + card.prospect_id" class="ubr-input h-9" x-model="form.mailbox_id">
												<template x-for="m in mailboxes" :key="m.id"><option :value="String(m.id)" x-text="m.email"></option></template>
											</select>
										</div>
										<p class="text-[12px] text-danger font-semibold" x-show="!mailboxes.length">Nie masz podłączonej skrzynki — dodaj ją w Sequences → Mailboxes.</p>
										<div class="flex flex-wrap gap-2">
											<button type="button" class="ubr-btn-primary ubr-btn-sm" @click="createDraft(card)" :disabled="busy || form.loading">Utwórz szkic</button>
											<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="form = null" :disabled="busy">Anuluj</button>
										</div>
									</div>
								</template>
							</td>
							<td class="text-[12px]">
								<template x-if="card.sequence && card.sequence.status !== 'draft'">
									<div>
										<div class="tabular-nums" x-text="'Wysłano ' + card.sequence.sent + '/' + card.sequence.steps"></div>
										<div class="text-ink-muted" x-show="card.sequence.next_send_at && ['scheduled', 'active'].includes(card.sequence.status)" x-text="'Następna: ' + $store.ubr.date(card.sequence.next_send_at)"></div>
									</div>
								</template>
								<span class="text-ink-muted" x-show="!card.sequence || card.sequence.status === 'draft'">—</span>
							</td>
							<td class="text-right">
								<div class="flex flex-col items-end gap-1.5">
									<button type="button" class="ubr-btn-primary ubr-btn-sm" x-show="!card.sequence && card.can_sequence && form?.prospect_id !== card.prospect_id" @click="startAssign(card)">Przypisz sekwencję</button>
									<button type="button" class="ubr-btn-primary ubr-btn-sm" x-show="card.sequence?.status === 'draft' && card.sequence.can_manage" @click="openMailing(card)">Zaplanuj / wyślij…</button>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="card.sequence && (card.sequence.status !== 'draft' || !card.sequence.can_manage)" @click="openMailing(card)">Podgląd</button>
									<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="card.sequence && ['completed', 'cancelled'].includes(card.sequence.status) && card.can_sequence" @click="openMailing(card)">Nowa sekwencja…</button>
									<span class="text-[12px] text-ink-muted" x-show="!card.sequence && !card.can_sequence">Prowadzi opiekun</span>
								</div>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>

		<button type="button" class="ubr-btn-ghost ubr-btn-sm w-full" x-show="items.length < total" @click="more()" :disabled="loadingMore" x-text="loadingMore ? 'Ładowanie…' : 'Pokaż więcej (' + (total - items.length) + ')'"></button>
	</div>
@endsection
