@extends('ubr.layouts.app')

@section('actions')
	<a href="{{ $config['urls']['discover'] }}" class="ubr-btn-gold">
		@include('ubr.partials.icon', ['name' => 'search'])
		<span class="hidden sm:inline">Nowe wyszukiwanie</span>
	</a>
@endsection

@section('content')
	<div x-data="ubrDashboard" class="space-y-6 max-w-[1200px]">
		<template x-if="error">
			<div class="ubr-card p-4 text-danger font-semibold" x-text="error"></div>
		</template>

		<div class="grid gap-4 sm:grid-cols-3">
			<div class="ubr-stat">
				<div class="text-[12px] font-bold uppercase tracking-wider text-ink-muted">Odkryte firmy</div>
				<div class="mt-2 text-[34px] font-extrabold tabular-nums leading-none" x-text="loading ? '–' : data.stats.discovered_companies"></div>
				<div class="mt-2 text-[12px] text-ink-muted">we wszystkich wyszukiwaniach organizacji</div>
			</div>
			<div class="ubr-stat">
				<div class="text-[12px] font-bold uppercase tracking-wider text-ink-muted">Prospects</div>
				<div class="mt-2 text-[34px] font-extrabold tabular-nums leading-none text-money" x-text="loading ? '–' : data.stats.prospects"></div>
				<div class="mt-2 text-[12px] text-ink-muted"><a href="{{ $config['urls']['prospects'] }}" class="underline hover:text-ink">Przejdź do listy</a></div>
			</div>
			<a href="{{ $config['urls']['discover'] }}" class="ubr-stat bg-ink! text-white border-ink! group relative overflow-hidden">
				<div class="text-[12px] font-bold uppercase tracking-wider text-gold">Discover</div>
				<div class="mt-2 text-[18px] font-extrabold leading-snug">Znajdź firmy z potencjałem<br>i słabą stroną WWW</div>
				<div class="mt-3 inline-flex items-center gap-1 text-[13px] font-semibold text-white/70 group-hover:text-white">Uruchom wyszukiwanie @include('ubr.partials.icon', ['name' => 'chevron', 'class' => 'size-4'])</div>
				@if ($iconUrl)
					<img src="{{ $iconUrl }}" alt="" class="absolute -right-3 -bottom-4 w-28 opacity-90 rotate-[-8deg] transition group-hover:rotate-0" width="128" height="128">
				@endif
			</a>
		</div>

		{{-- Sales Pipeline: statystyki z rzeczywistych danych organizacji (cache 60 s) --}}
		<template x-if="!loading && data.pipeline">
			<div class="space-y-6">
				<div class="grid gap-3 grid-cols-2 sm:grid-cols-4 lg:grid-cols-6">
					<template x-for="t in [
						{ l: 'W pipeline', v: data.pipeline.total },
						{ l: 'Nowe', v: statusCount('new') },
						{ l: 'Zakwalifikowane', v: statusCount('qualified') },
						{ l: 'Kontakt nawiązany', v: statusCount('contacted') },
						{ l: 'W rozmowach', v: statusCount('in_discussion') },
						{ l: 'Oferty wysłane', v: statusCount('proposal_sent') },
						{ l: 'Wygrane', v: statusCount('won'), c: 'text-money' },
						{ l: 'Przegrane', v: statusCount('lost') },
						{ l: 'Zaległe follow-upy', v: data.pipeline.overdue, c: data.pipeline.overdue ? 'text-danger' : '' },
						{ l: 'Na dziś', v: data.pipeline.today },
						{ l: 'Nadchodzące', v: data.pipeline.upcoming },
						{ l: 'W ClickUp', v: data.pipeline.exported },
					]" :key="t.l">
						<div class="ubr-stat py-3!">
							<div class="text-[11px] font-bold uppercase tracking-wider text-ink-muted" x-text="t.l"></div>
							<div class="mt-1 text-[24px] font-extrabold tabular-nums leading-none" :class="t.c || ''" x-text="t.v"></div>
						</div>
					</template>
				</div>

				<div class="grid gap-6 lg:grid-cols-5">
					<section class="ubr-card p-5 lg:col-span-3 min-w-0">
						<header class="flex items-center justify-between mb-3">
							<h2 class="font-extrabold">Lejek sprzedaży</h2>
							<a href="{{ $config['urls']['pipeline'] }}" class="text-[12px] underline text-ink-muted hover:text-ink">Otwórz tablicę</a>
						</header>
						<ul class="space-y-1.5">
							<template x-for="st in data.pipeline.by_status" :key="st.key">
								<li class="grid grid-cols-[130px_1fr_40px] items-center gap-2 text-[12px]">
									<span class="font-semibold truncate" x-text="st.label"></span>
									<span class="h-3 rounded-full bg-canvas overflow-hidden" aria-hidden="true"><span class="block h-full rounded-full" :class="st.key === 'won' ? 'bg-money' : (['lost', 'not_a_fit'].includes(st.key) ? 'bg-ink-muted' : 'bg-gold')" :style="'width:' + Math.round(100 * st.count / funnelMax()) + '%'"></span></span>
									<span class="text-right tabular-nums font-bold" x-text="st.count"></span>
								</li>
							</template>
						</ul>
					</section>

					<section class="ubr-card p-5 lg:col-span-2 min-w-0" x-show="data.agenda">
						<h2 class="font-extrabold mb-3">Follow-upy</h2>
						<template x-for="b in [{ k: 'overdue', l: 'Zaległe', c: 'text-danger' }, { k: 'today', l: 'Dziś', c: '' }, { k: 'upcoming', l: 'Nadchodzące', c: '' }]" :key="b.k">
							<div class="mb-3">
								<div class="text-[11px] font-bold uppercase tracking-wider mb-1" :class="b.c || 'text-ink-muted'" x-text="b.l + ' (' + (data.agenda?.[b.k]?.length || 0) + ')'"></div>
								<template x-if="!(data.agenda?.[b.k]?.length)"><p class="text-[12px] text-ink-muted">—</p></template>
								<template x-for="f in (data.agenda?.[b.k] || [])" :key="f.id">
									<button type="button" class="w-full text-left rounded-lg px-2 py-1.5 hover:bg-canvas" @click="$store.ubr.drawer.tab = 'sales'; $store.ubr.openCompany(f.company.id)">
										<div class="text-[13px] font-semibold truncate" x-text="f.title"></div>
										<div class="text-[11px] text-ink-muted truncate" x-text="f.company.name + ' · ' + $store.ubr.date(f.due_at) + (f.assigned ? ' · ' + f.assigned.name : '')"></div>
									</button>
								</template>
							</div>
						</template>
					</section>
				</div>
			</div>
		</template>

		<div class="grid gap-6 lg:grid-cols-5">
			<section class="ubr-card lg:col-span-2 min-w-0">
				<header class="px-5 pt-5 pb-3 flex items-center justify-between">
					<h2 class="font-extrabold">Ostatnie wyszukiwania</h2>
				</header>
				<div class="px-5 pb-5 space-y-2">
					<template x-if="loading"><div class="space-y-2"><div class="ubr-skeleton h-14"></div><div class="ubr-skeleton h-14"></div></div></template>
					<template x-if="!loading && data.recent_searches.length === 0">
						<p class="text-[13px] text-ink-muted py-6 text-center">Brak wyszukiwań. Zacznij od ekranu Discover.</p>
					</template>
					<template x-for="s in data.recent_searches" :key="s.id">
						<a :href="UBR.urls.discover + (UBR.urls.discover.includes('?') ? '&' : '?') + 'search=' + s.id" class="block rounded-xl border border-line px-4 py-3 hover:border-ink/25 transition">
							<div class="flex items-center justify-between gap-2">
								<span class="font-semibold text-[13px] truncate" x-text="s.label"></span>
								<span :class="$store.ubr.statusBadge(s.status)" x-text="$store.ubr.statusLabel(s.status)"></span>
							</div>
							<div class="text-[12px] text-ink-muted mt-0.5"><span x-text="s.results_count"></span> firm · <span x-text="s.with_domain_count"></span> z domeną · <span x-text="$store.ubr.date(s.created_at)"></span></div>
						</a>
					</template>
				</div>
			</section>

			<section class="ubr-card lg:col-span-3 overflow-hidden min-w-0">
				<header class="px-5 pt-5 pb-3"><h2 class="font-extrabold">Ostatnio znalezione firmy</h2></header>
				<template x-if="loading"><div class="px-5 pb-5 space-y-2"><div class="ubr-skeleton h-10"></div><div class="ubr-skeleton h-10"></div><div class="ubr-skeleton h-10"></div></div></template>
				<template x-if="!loading && data.recent_companies.length === 0">
					<p class="text-[13px] text-ink-muted py-10 text-center">Tu pojawią się firmy z Twoich wyszukiwań.</p>
				</template>
				<div class="overflow-x-auto" x-show="!loading && data.recent_companies.length">
					<table class="ubr-table">
						<thead><tr><th>Firma</th><th>Lokalizacja</th><th></th></tr></thead>
						<tbody>
							<template x-for="c in data.recent_companies" :key="c.id">
								<tr class="cursor-pointer" @click="$store.ubr.openCompany(c.id)">
									<td>
										<div class="font-semibold" x-text="c.name"></div>
										<div class="text-[12px] text-ink-muted" x-text="c.domain || 'brak domeny'"></div>
									</td>
									<td class="text-[13px]" x-text="[c.city, c.region].filter(Boolean).join(', ') || '—'"></td>
									<td class="text-right"><span x-show="c.prospect_id" class="ubr-badge-confirmed">Prospect</span></td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
			</section>
		</div>
	</div>
@endsection
