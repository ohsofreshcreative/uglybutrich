{{--
	Zakładka „Decision Makers” w profilu firmy (GET /companies/{id}/contacts). Odczyt NIE uruchamia wyszukiwania.
	Top 1–3 + pozostali kandydaci + ukryci (nieaktualni / odrzuceni) + skład zarządu z KRS + historia.
--}}
<div class="space-y-6" x-data="{ get d() { return $store.ubr.drawer.contacts }, assign: {}, noteFor: null, noteText: '', addOpen: false, form: { full_name: '', job_title: '', source_url: '', email: '' } }">
	<template x-if="$store.ubr.drawer.contactsLoading && !d">
		<div class="space-y-3"><div class="ubr-skeleton h-20"></div><div class="ubr-skeleton h-40"></div></div>
	</template>

	<template x-if="d">
		<div class="space-y-6">
			{{-- Podsumowanie --}}
			<div class="rounded-xl border border-line p-4">
				<div class="flex flex-wrap items-start gap-4">
					<div>
						<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Znalezione osoby</div>
						<div class="text-[28px] font-extrabold leading-none mt-1" x-text="d.status ? d.count : '—'"></div>
					</div>
					<div>
						<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Status</div>
						<span class="mt-1 inline-block" :class="$store.ubr.contactsBadge(d.status)" x-text="d.status_label"></span>
					</div>
					<div>
						<div class="text-[10px] font-bold uppercase tracking-wider text-ink-muted">Ostatnie wyszukiwanie</div>
						<div class="text-[13px] mt-1" x-text="$store.ubr.date(d.researched_at)"></div>
					</div>
					<div class="ml-auto" x-show="d.can_run">
						<button type="button" class="ubr-btn-primary" :disabled="!!d.active" @click="$store.ubr.findDecisionMakers(d.company.id, !!d.status)">
							@include('ubr.partials.icon', ['name' => 'users', 'class' => 'size-3.5'])
							<span x-text="d.active ? 'Wyszukiwanie w toku…' : (d.status ? 'Znajdź ponownie' : 'Znajdź decydentów')"></span>
						</button>
						<p class="text-[11px] text-ink-muted mt-1 text-right" x-show="d.status && !d.active" x-text="'Ponowne wyszukiwanie możliwe co ' + d.limits.cooldown_hours + ' h.'"></p>
					</div>
				</div>
				<p class="text-[12px] text-ink-muted mt-3" x-show="!d.status && !d.active">Wyszukiwanie decydentów nie było jeszcze uruchamiane dla tej firmy. Źródła: KRS (skład zarządu) i oficjalna strona firmy (zarząd, zespół, kontakt). Nie zgadujemy osób ani adresów e-mail.</p>
				<p class="text-[12px] text-ink-muted mt-3" x-show="d.active">Szukamy w tle (zwykle 10–40 s). Możesz zamknąć panel — wynik zapisze się automatycznie.</p>
			</div>

			{{-- Prompt 10.2: telefony decydentów ze strony firmy (strony źródłowe numerów + maks. 2 podstrony zarząd/zespół). --}}
			<div class="rounded-xl border border-line p-4" x-show="d.phone_scan?.can_run">
				<div class="flex flex-wrap items-start gap-3">
					<div class="min-w-0 flex-1">
						<div class="font-bold text-[13px]">Telefony decydentów ze strony firmy</div>
						<p class="text-[12px] text-ink-muted mt-0.5">Łączymy numer z osobą tylko przy silnym dowodzie (ten sam blok: imię i nazwisko, stanowisko i numer). Numer w stopce, numer działu lub samo nazwisko na stronie nie wystarcza. Bez Apollo, bezpłatnie.</p>
					</div>
					<button type="button" class="ubr-btn-ghost" :disabled="$store.ubr.drawer.phoneScan?.busy" @click="$store.ubr.scanDecisionPhones(d.company.id)">
						@include('ubr.partials.icon', ['name' => 'phone', 'class' => 'size-3.5'])
						<span x-text="$store.ubr.drawer.phoneScan?.busy ? 'Sprawdzanie…' : 'Szukaj telefonów decydentów'"></span>
					</button>
				</div>
				<template x-if="$store.ubr.drawer.phoneScan?.result">
					<div class="mt-3 text-[12px] space-y-1" role="status">
						<p class="font-semibold" x-text="$store.ubr.drawer.phoneScan.result.message"></p>
						<template x-for="pp in $store.ubr.drawer.phoneScan.result.people.filter(x => x.phones.length)" :key="pp.name">
							<p class="text-ink-soft"><span class="font-semibold" x-text="pp.name"></span> <span class="text-ink-muted" x-text="pp.title ? '— ' + pp.title : '— bez stanowiska'"></span>: <span x-text="pp.phones.map(x => x.number + ' (' + x.status_label.toLowerCase() + ')').join(', ')"></span><span class="text-ink-muted" x-show="!pp.contact_id"> · poza bazą (nie decydent)</span></p>
						</template>
					</div>
				</template>
			</div>

			{{-- Prompt 10.3: kandydaci z Apollo People API Search i dopasowanie do kontaktów (prywatne dla organizacji). --}}
			@include('ubr.partials.apollo-candidates')

			{{-- Top 3 --}}
			<section x-show="['completed', 'partial'].includes(d.status) || d.top.length">
				<h3 class="text-[13px] font-extrabold mb-2">Top kontakty</h3>
				<div class="rounded-xl bg-gold-soft/60 px-4 py-2.5 text-[12px] mb-2" x-show="d.top1_note" x-text="d.top1_note"></div>
				<div class="space-y-3">
					<template x-for="ct in d.top" :key="ct.id">
						<div>@include('ubr.partials.contact-card')</div>
					</template>
					<template x-for="n in Math.max(0, 3 - d.top.length)" :key="'m' + n">
						<div class="rounded-xl border border-dashed border-line px-4 py-3 text-[12px] text-ink-muted" x-text="(d.top.length === 0 && n === 1 ? 'Nie znaleziono wiarygodnego kontaktu w dostępnych źródłach.' : d.missing_message)"></div>
					</template>
				</div>
			</section>

			{{-- Pozostali kandydaci --}}
			<section x-show="d.others.length">
				<h3 class="text-[13px] font-extrabold mb-2" x-text="'Other Candidates (' + d.others.length + ')'"></h3>
				<div class="space-y-3">
					<template x-for="ct in d.others" :key="ct.id">
						<div>@include('ubr.partials.contact-card')</div>
					</template>
				</div>
			</section>

			{{-- Ukryci: nieaktualni / odrzuceni / wykluczeni --}}
			<details x-show="d.hidden.length" class="rounded-xl border border-line px-4 py-3">
				<summary class="cursor-pointer text-[13px] font-semibold" x-text="'Nieaktualne, odrzucone lub wykluczone (' + d.hidden.length + ') — poza rankingiem'"></summary>
				<div class="space-y-3 mt-3">
					<template x-for="ct in d.hidden" :key="ct.id">
						<div>@include('ubr.partials.contact-card')</div>
					</template>
				</div>
			</details>

			{{-- Kontakty ogólne firmy --}}
			<section x-show="d.company_channels.length">
				<h3 class="text-[13px] font-extrabold mb-2">Ogólne kontakty firmy</h3>
				<ul class="text-[12px] space-y-1">
					<template x-for="ch in d.company_channels" :key="ch.value">
						<li><a :href="'mailto:' + ch.value" class="font-semibold hover:underline" x-text="ch.value"></a> <span class="text-ink-muted" x-text="'— ' + ch.label"></span></li>
					</template>
				</ul>
				<p class="text-[11px] text-ink-muted mt-1">Adresy ogólne / działów nie są przypisywane konkretnym osobom.</p>
			</section>

			{{-- Zarząd wg KRS (zanonimizowany) --}}
			<section x-show="d.board">
				<h3 class="text-[13px] font-extrabold mb-1">Zarząd wg KRS</h3>
				<p class="text-[11px] text-ink-muted mb-2" x-text="'Stan z ' + (d.board?.state_date || '—') + '. Publiczne API KRS anonimizuje imiona i nazwiska (widoczne inicjały) — KRS służy do potwierdzania osób znalezionych w innych źródłach.'"></p>
				<ul class="text-[12px] space-y-0.5">
					<template x-for="(m, i) in (d.board?.members || [])" :key="i">
						<li><span class="font-semibold" x-text="m.initials"></span> — <span x-text="m.function.toLowerCase()"></span><span class="text-danger" x-show="m.suspended"> (zawieszony)</span></li>
					</template>
				</ul>
				<p class="text-[11px] text-ink-muted mt-1" x-show="d.board?.proxies" x-text="'Prokurenci: ' + d.board?.proxies"></p>
				<details class="text-[11px] text-ink-muted mt-1" x-show="d.board?.representation"><summary class="cursor-pointer">Sposób reprezentacji</summary><p class="mt-1" x-text="d.board?.representation"></p></details>
			</section>

			{{-- Źródła ostatniego wyszukiwania --}}
			<section x-show="Object.keys(d.sources || {}).length">
				<h3 class="text-[13px] font-extrabold mb-2">Źródła</h3>
				<ul class="text-[12px] space-y-1">
					<template x-for="(s, key) in d.sources" :key="key">
						<li>
							<span class="font-semibold" x-text="{krs: 'KRS', website: 'Oficjalna strona firmy', apollo: 'Apollo People Search'}[key] || key"></span>:
							<span :class="s.state === 'error' ? 'text-danger' : 'text-ink-soft'" x-text="$store.ubr.sourceStateLabel(s.state)"></span>
							<span class="text-ink-muted" x-text="s.message ? '— ' + s.message : ''"></span>
						</li>
					</template>
				</ul>
			</section>

			{{-- Kontakt ręczny (prywatny, USER_PROVIDED) --}}
			<section x-show="d.can_manage">
				<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="addOpen = !addOpen" :aria-expanded="addOpen">@include('ubr.partials.icon', ['name' => 'plus', 'class' => 'size-3.5']) Dodaj kontakt ręcznie</button>
				<form class="mt-3 grid grid-cols-2 gap-2 text-[12px]" x-show="addOpen" @submit.prevent="if (await $store.ubr.addContact(form)) { addOpen = false; form = { full_name: '', job_title: '', source_url: '', email: '' } }">
					<label class="block">Imię i nazwisko<input class="ubr-input w-full mt-1" x-model="form.full_name" required></label>
					<label class="block">Stanowisko<input class="ubr-input w-full mt-1" x-model="form.job_title" required placeholder="np. Dyrektor Marketingu"></label>
					<label class="block col-span-2">Źródło (URL)<input class="ubr-input w-full mt-1" x-model="form.source_url" type="url" placeholder="https://… (strona, komunikat) — zalecane"></label>
					<label class="block col-span-2">Publiczny służbowy e-mail (opcjonalnie)<input class="ubr-input w-full mt-1" x-model="form.email" type="email"></label>
					<p class="col-span-2 text-[11px] text-ink-muted">Kontakt będzie oznaczony jako USER_PROVIDED, widoczny tylko w Twojej organizacji i nie jest traktowany jako automatycznie zweryfikowany.</p>
					<div class="col-span-2"><button type="submit" class="ubr-btn-primary ubr-btn-sm">Dodaj</button></div>
				</form>
			</section>

			{{-- Historia wyszukiwań --}}
			<section x-show="d.history.length">
				<h3 class="text-[13px] font-extrabold mb-2">Historia wyszukiwań</h3>
				<div class="space-y-2">
					<template x-for="h in d.history" :key="h.id">
						<details class="rounded-xl border border-line px-4 py-2.5 text-[12px]">
							<summary class="cursor-pointer flex flex-wrap items-center gap-2">
								<span x-text="$store.ubr.date(h.created_at)"></span>
								<span :class="$store.ubr.contactsBadge(h.status)" x-text="h.status_label"></span>
								<span class="text-ink-muted" x-text="h.candidates + ' kandydatów · Top: ' + h.top_count + (h.duration_ms ? ' · ' + Math.round(h.duration_ms / 1000) + ' s' : '')"></span>
								<span class="text-ink-muted" x-show="h.by_my_organization">· zlecone przez Twoją organizację</span>
							</summary>
							<div class="mt-2 space-y-1">
								<template x-for="t in h.top" :key="t.position"><div x-text="'Top ' + t.position + ': ' + t.name + ' — ' + t.title + ' (' + t.score + ')'"></div></template>
								<template x-for="(s, key) in h.sources" :key="key"><div class="text-ink-muted" x-text="key + ': ' + $store.ubr.sourceStateLabel(s.state) + (s.message ? ' — ' + s.message : '')"></div></template>
								<template x-for="(c, i) in h.changes" :key="'c' + i"><div x-text="'• ' + c"></div></template>
								<template x-for="(e, i) in h.errors" :key="'e' + i"><div class="text-danger" x-text="e"></div></template>
							</div>
						</details>
					</template>
				</div>
			</section>

			<p class="text-[11px] text-ink-muted" x-text="d.notice"></p>
		</div>
	</template>
</div>
