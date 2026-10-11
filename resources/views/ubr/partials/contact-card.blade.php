{{--
	Karta kontaktu (zmienna Alpine: ct). Wszystkie treści pochodzą z API — uzasadnienia wynikają z danych.
	Brak e-maila pokazujemy wprost („Brak publicznego adresu e-mail”), adresów nie zgadujemy.
--}}
<article class="rounded-xl border p-4" :class="ct.rank_position === 1 ? 'border-ink shadow-[var(--shadow-card)]' : 'border-line'">
	<div class="flex items-start gap-3">
		<div class="min-w-0 flex-1">
			<div class="flex flex-wrap items-center gap-1.5">
				<span class="text-[10px] font-extrabold uppercase tracking-wider px-1.5 py-0.5 rounded" :class="ct.rank_position === 1 ? 'bg-ink text-gold' : 'bg-canvas text-ink-soft'" x-show="ct.rank_position" x-text="'Top ' + ct.rank_position"></span>
				<h4 class="font-extrabold text-[15px]" x-text="ct.full_name"></h4>
				<span class="ubr-badge-unavailable" x-show="ct.user_provided" title="Kontakt dodany ręcznie przez Twoją organizację">USER_PROVIDED</span>
			</div>
			<p class="text-[13px] text-ink-soft" x-text="ct.job_title"></p>
			<p class="text-[11px] text-ink-muted" x-show="ct.registry_role && ct.registry_role.toLowerCase() !== (ct.job_title || '').toLowerCase()" x-text="'Funkcja w KRS: ' + ct.registry_role"></p>
		</div>
		<div class="text-right shrink-0">
			<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Contact Priority</div>
			<div class="flex items-center justify-end gap-1 mt-0.5">
				<span :class="$store.ubr.contactScoreClass(ct.score)" x-text="ct.score ?? '—'"></span><span class="text-[11px] text-ink-muted">/100</span>
			</div>
			<div class="text-[10px] text-ink-muted mt-0.5" x-show="ct.score_status === 'PROVISIONAL'" title="Część kryteriów nie mogła zostać oceniona (np. nieznana wielkość firmy)">wynik wstępny</div>
		</div>
	</div>

	<div class="flex flex-wrap gap-1.5 mt-2.5 text-[11px]">
		<span :class="$store.ubr.contactConfBadge(ct.confidence.level)" x-text="'Pewność: ' + ct.confidence.label"></span>
		<span :class="$store.ubr.contactStatusBadge(ct.verification.status)" x-text="ct.verification.label"></span>
		<span class="ubr-badge-danger" x-show="ct.company_match_uncertain">Niepewne dopasowanie firmy</span>
		<span class="ubr-badge-uncertain" x-show="ct.needs_review && !ct.company_match_uncertain">Do ponownej weryfikacji</span>
		<span class="ubr-badge-confirmed" x-show="ct.review.status === 'approved'">Zatwierdzony (Twoja organizacja)</span>
		<span class="ubr-badge-confirmed" x-show="ct.review.preferred">Preferowany kontakt</span>
		<span class="ubr-badge-danger" x-show="['rejected','outdated','excluded'].includes(ct.review.status)" x-text="{rejected: 'Odrzucony przez Ciebie', outdated: 'Oznaczony jako nieaktualny', excluded: 'Wykluczony przez Ciebie'}[ct.review.status]"></span>
	</div>

	<dl class="grid grid-cols-[88px_1fr] gap-x-3 gap-y-1 mt-3 text-[12px]">
		<dt class="text-ink-muted">Źródło</dt>
		<dd class="flex flex-wrap gap-x-2 gap-y-0.5">
			<template x-for="(s, i) in ct.sources" :key="i">
				<span>
					<a x-show="s.url" :href="$store.ubr.safeHref(s.url)" target="_blank" rel="noopener nofollow" class="font-semibold hover:underline" x-text="s.label"></a>
					<span x-show="!s.url" class="font-semibold" x-text="s.label"></span>
				</span>
			</template>
		</dd>
		<dt class="text-ink-muted">E-mail</dt>
		<dd>
			<a x-show="ct.email" :href="'mailto:' + ct.email" class="hover:underline" x-text="ct.email"></a>
			<span x-show="!ct.email" class="text-ink-muted" x-text="ct.email_note"></span>
			<div x-show="ct.role_email" class="text-[11px] text-ink-muted" x-text="ct.role_email ? ct.role_email.label + ': ' + ct.role_email.value : ''"></div>
		</dd>
		{{-- Prompt 10.2: bezpośredni telefon z dowodem powiązania (potwierdzony / prawdopodobny / do weryfikacji / odrzucony),
			źródłem, datami i decyzjami organizacji. Numer firmy nie jest numerem osoby, dopóki nie zostanie świadomie przypisany. --}}
		<dt class="text-ink-muted">Telefon decydenta</dt>
		<dd class="min-w-0">
			<template x-if="!(ct.phone_links || []).length"><span class="text-ink-muted">Brak numeru decydenta</span></template>
			<template x-for="l in (ct.phone_links || [])" :key="l.id">
				<div class="mb-2 last:mb-0 min-w-0" :data-phone-link="l.status">
					<div class="flex flex-wrap items-center gap-1.5">
						<template x-if="l.direct">
							<span class="font-bold tabular-nums" :class="l.status === 'VERIFIED' ? 'text-money' : 'text-gold-deep'" x-text="l.number"></span>
						</template>
						<template x-if="!l.direct"><span class="font-bold tabular-nums text-ink-muted" :class="l.status === 'REJECTED' ? 'line-through' : ''" x-text="l.number"></span></template>
						<button type="button" x-show="l.direct" class="grid place-items-center size-8 rounded-md text-ink-muted hover:text-ink hover:bg-canvas" @click="$store.ubr.copyPhone(l)" :aria-label="'Kopiuj numer ' + l.number">@include('ubr.partials.icon', ['name' => 'copy', 'class' => 'size-3.5'])</button>
						<span :class="{ VERIFIED: 'ubr-badge-confirmed', LIKELY: 'ubr-badge-declared', UNCERTAIN: 'ubr-badge-uncertain', REJECTED: 'ubr-badge-danger' }[l.status]" x-text="l.status_label"></span>
						<span class="text-[11px] text-ink-muted" x-text="l.source_label + (l.origin_label && l.origin_label !== l.source_label ? ' (dowód: ' + l.origin_label.toLowerCase() + ')' : '')"></span>
					</div>
					<div class="text-[11px] text-ink-soft" x-text="l.evidence_label"></div>
					<div class="text-[11px] text-ink-muted line-clamp-2 [overflow-wrap:anywhere]" x-show="l.excerpt" :title="l.excerpt" x-text="'„' + l.excerpt + '”'"></div>
					<div class="text-[11px] text-ink-muted">
						<span x-text="'Pozyskano: ' + $store.ubr.date(l.found_at) + ' · Weryfikacja: ' + (l.verified_at ? $store.ubr.date(l.verified_at) : '—')"></span>
						<a x-show="l.source_url" :href="$store.ubr.safeHref(l.source_url)" target="_blank" rel="noopener nofollow" class="ml-1 underline">źródło</a>
					</div>
					<div class="flex flex-wrap gap-1.5 mt-1" x-show="d.can_manage">
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="l.can_confirm && l.status !== 'REJECTED'" @click="$store.ubr.phoneLink(ct, { action: 'confirm', e164: l.e164 })">Potwierdź</button>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="l.can_reject" @click="$store.ubr.phoneLink(ct, { action: 'reject', e164: l.e164 })">Odrzuć</button>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="l.can_remove && l.status !== 'REJECTED'" @click="$store.ubr.removePhoneLink(ct, l)" title="Numer firmy pozostaje w karcie „Telefony firmy”">Usuń powiązanie</button>
						<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="l.status === 'REJECTED' && l.decided_by_organization" @click="$store.ubr.removePhoneLink(ct, l)">Cofnij odrzucenie</button>
					</div>
				</div>
			</template>
			<template x-if="d.can_manage && (d.company_numbers || []).length">
				<div class="flex flex-wrap items-center gap-1.5 mt-2">
					<select class="ubr-input h-8 text-[12px] w-auto max-w-full" x-model="assign[ct.id]" :aria-label="'Numer firmy do przypisania: ' + ct.full_name">
						<option value="">Przypisz istniejący numer firmy…</option>
						<template x-for="n in d.company_numbers" :key="n.id"><option :value="n.id" x-text="n.number + ' · ' + (n.owner_note || n.owner_label || n.type_label)"></option></template>
					</select>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm" :disabled="!assign[ct.id]" @click="$store.ubr.phoneLink(ct, { action: 'assign', company_phone_id: Number(assign[ct.id]) }); assign[ct.id] = ''">Przypisz</button>
				</div>
			</template>
		</dd>
		{{-- Prompt 10.3: zatwierdzone dopasowanie Apollo (prywatne dla organizacji). Sygnał dostępności ≠ numer. --}}
		<template x-if="($store.ubr.drawer.apollo?.candidates || []).some(c => c.approved && c.approved.contact_id === ct.id)">
			<dt class="text-ink-muted">Apollo</dt>
		</template>
		<template x-for="ac in ($store.ubr.drawer.apollo?.candidates || []).filter(c => c.approved && c.approved.contact_id === ct.id).slice(0, 1)" :key="'ap' + ac.id">
			<dd class="min-w-0" data-apollo-contact>
				<span class="ubr-badge-confirmed">Dopasowanie zatwierdzone</span>
				<span class="text-[11px] text-ink-muted" x-text="ac.approved.level_label"></span>
				<div class="text-[11px] text-ink-soft" x-text="ac.phone_availability_label"></div>
				<div class="text-[11px] text-ink-muted" x-text="'Sprawdzono w Apollo: ' + $store.ubr.date(ac.last_seen_at)"></div>
			</dd>
		</template>
		<template x-if="ct.profile_url"><dt class="text-ink-muted">Profil</dt></template>
		<template x-if="ct.profile_url"><dd><a :href="$store.ubr.safeHref(ct.profile_url)" target="_blank" rel="noopener nofollow" class="hover:underline">Profil zawodowy</a> <span class="text-[11px] text-ink-muted">(link opublikowany przez źródło)</span></dd></template>
		<dt class="text-ink-muted">Weryfikacja</dt>
		<dd class="text-ink-muted" x-text="$store.ubr.date(ct.last_verified_at)"></dd>
	</dl>

	<p class="mt-3 text-[12px] leading-relaxed" x-show="ct.rank_reason"><span class="font-bold" x-text="'Dlaczego Top ' + ct.rank_position + '? '"></span><span x-text="ct.rank_reason"></span></p>

	<details class="mt-2 text-[12px]">
		<summary class="cursor-pointer font-semibold text-ink-soft">Dlaczego ta osoba? Szczegóły oceny i źródła</summary>
		<div class="mt-2 space-y-3">
			<p class="text-ink-soft" x-text="ct.verification.reason"></p>
			<div>
				<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Contact Priority Score — składowe</div>
				<template x-for="(b, key) in ct.breakdown" :key="key">
					<div class="flex items-start justify-between gap-3 py-0.5" x-show="b.max > 0">
						<span><span class="font-semibold" x-text="{decision_authority: 'A. Decyzyjność', role_relevance: 'B. Związek ze stroną WWW', company_size_fit: 'C. Wielkość firmy', contactability: 'D. Dostępność kontaktu', data_confidence: 'E. Pewność danych'}[key] || key"></span> — <span class="text-ink-muted" x-text="b.note"></span></span>
						<span class="tabular-nums whitespace-nowrap" :class="b.state === 'not_measured' ? 'text-ink-muted' : 'font-bold'" x-text="b.state === 'not_measured' ? 'nie oceniono' : b.points + '/' + b.max"></span>
					</div>
				</template>
				<p class="text-danger" x-show="ct.breakdown.cap" x-text="ct.breakdown.cap?.note"></p>
			</div>
			<div>
				<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Pewność danych (0–100)</div>
				<div class="grid grid-cols-2 gap-x-4 text-ink-soft">
					<span>Tożsamość: <strong x-text="ct.confidence.identity ?? '—'"></strong></span>
					<span>Aktualność stanowiska: <strong x-text="ct.confidence.role_currency ?? '—'"></strong></span>
					<span>Dopasowanie firmy: <strong x-text="ct.confidence.company_match ?? '—'"></strong></span>
					<span>Dane kontaktowe: <strong x-text="ct.confidence.contact_data ?? '—'"></strong></span>
				</div>
				<template x-for="n in ct.confidence.notes"><p class="text-ink-muted mt-1" x-text="n"></p></template>
			</div>
			<div>
				<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Dowody</div>
				<template x-for="(s, i) in ct.sources" :key="'e' + i">
					<div class="py-1 border-t border-line first:border-0">
						<div><span class="font-semibold" x-text="s.label"></span><span class="text-ink-muted" x-text="' · ' + (s.title || '') + ' · ' + $store.ubr.date(s.retrieved_at)"></span></div>
						<div class="text-ink-muted" x-show="s.observed_role" x-text="'Stanowisko w źródle: ' + s.observed_role"></div>
						<div class="italic text-ink-soft" x-show="s.excerpt" x-text="'„' + s.excerpt + '”'"></div>
						<a x-show="s.url" :href="$store.ubr.safeHref(s.url)" target="_blank" rel="noopener nofollow" class="text-[11px] hover:underline">Otwórz źródło</a>
					</div>
				</template>
			</div>
			<div x-show="ct.history.length > 1">
				<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted mb-1">Historia</div>
				<template x-for="(h, i) in ct.history" :key="'h' + i">
					<div class="text-ink-muted" x-text="$store.ubr.date(h.at) + ' — ' + (h.position ? 'Top ' + h.position : 'poza Top 3') + ', wynik ' + (h.score ?? '—') + ', ' + h.status + (h.title ? ', ' + h.title : '')"></div>
				</template>
			</div>
		</div>
	</details>

	<template x-if="d.can_manage">
		<div class="mt-3 pt-3 border-t border-line">
			<div class="flex flex-wrap gap-1.5">
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="ct.review.status !== 'approved'" @click="$store.ubr.reviewContact(ct, 'approve')">Zatwierdź</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="!ct.review.preferred" @click="$store.ubr.reviewContact(ct, 'prefer')">Preferowany</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="ct.review.preferred" @click="$store.ubr.reviewContact(ct, 'unprefer')">Usuń preferencję</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="ct.review.status !== 'outdated'" @click="$store.ubr.reviewContact(ct, 'outdated')">Nieaktualny</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="ct.review.status !== 'rejected'" @click="$store.ubr.reviewContact(ct, 'reject')">Odrzuć</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="ct.review.status !== 'excluded'" @click="$store.ubr.reviewContact(ct, 'exclude')" title="Nie pokazuj tej osoby w Twojej organizacji">Wyklucz</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" x-show="ct.review.status" @click="$store.ubr.reviewContact(ct, 'reset')">Cofnij decyzję</button>
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="noteFor = noteFor === ct.id ? null : ct.id; noteText = ct.review.note || ''">Notatka</button>
			</div>
			<p class="text-[12px] text-ink-soft mt-2 whitespace-pre-line" x-show="ct.review.note && noteFor !== ct.id" x-text="'Notatka: ' + ct.review.note"></p>
			<div class="mt-2" x-show="noteFor === ct.id">
				<label class="sr-only" :for="'note-' + ct.id">Notatka (prywatna dla organizacji)</label>
				<textarea :id="'note-' + ct.id" class="ubr-input w-full h-20 text-[12px]" x-model="noteText" placeholder="Notatka widoczna tylko w Twojej organizacji"></textarea>
				<button type="button" class="ubr-btn-primary ubr-btn-sm mt-1" @click="$store.ubr.reviewContact(ct, 'note', noteText); noteFor = null">Zapisz notatkę</button>
			</div>
		</div>
	</template>
</article>
