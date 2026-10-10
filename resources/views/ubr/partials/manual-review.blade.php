{{--
	Manual Website Review — wspólny komponent (karta firmy → Website Analysis, podgląd strony). Parametry: $idExpr (wyrażenie JS
	zwracające ID firmy), $compact (wersja jednowierszowa do podglądu). Ocena ręczna jest subiektywna, prywatna dla organizacji,
	ma historię i NIE zmienia Opportunity Score, Lead Potential ani Redesign Assessment.
--}}
@php($compact = $compact ?? false)
<div x-data="ubrManualReview(() => {{ $idExpr }})" class="{{ $compact ? 'flex flex-wrap items-center gap-2 text-[12px]' : 'rounded-xl border border-line p-4 space-y-2' }}" aria-label="Ręczna ocena strony">
	@if ($compact)
		<span class="font-bold">Ocena ręczna:</span>
		<template x-if="loading && !data"><span class="text-ink-muted">wczytywanie…</span></template>
		<template x-if="error"><span class="text-danger" x-text="error"></span></template>
		<template x-if="data">
			<div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Wybierz ocenę ręczną">
				<template x-for="s in data.statuses.filter((x) => x.key !== 'not_reviewed')" :key="s.key">
					<button type="button" class="h-7 px-2.5 rounded-lg border text-[12px] font-semibold transition" :class="data.status === s.key ? 'bg-ink text-white border-ink' : 'bg-white border-line hover:border-ink/30'"
						:aria-pressed="data.status === s.key" :disabled="!data.can_edit || saving" @click="save(s.key)" x-text="s.label"></button>
				</template>
				<span class="text-ink-muted" x-show="data.reviewed_at" x-text="'· ' + (data.reviewer?.name || '') + ', ' + $store.ubr.date(data.reviewed_at)"></span>
			</div>
		</template>
	@else
		<div class="flex flex-wrap items-center justify-between gap-2">
			<h3 class="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Manual Website Review</h3>
			<template x-if="data"><span :class="tone(data.status)" x-text="data.label"></span></template>
		</div>
		<template x-if="loading && !data"><div class="ubr-skeleton h-10"></div></template>
		<template x-if="error"><p class="text-[12px] text-danger" x-text="error"></p></template>
		<template x-if="data">
			<div class="space-y-2">
				<p class="text-[12px]" x-text="data.sentence + (data.reviewed_at ? ' (' + (data.reviewer?.name || '') + ', ' + $store.ubr.date(data.reviewed_at) + ')' : '')"></p>
				<div class="flex flex-wrap gap-1.5" role="group" aria-label="Wybierz ocenę ręczną">
					<template x-for="s in data.statuses" :key="s.key">
						<button type="button" class="h-8 px-3 rounded-lg border text-[12px] font-semibold transition" :class="data.status === s.key ? 'bg-ink text-white border-ink' : 'bg-white border-line hover:border-ink/30'"
							:aria-pressed="data.status === s.key" :disabled="!data.can_edit || saving" @click="save(s.key)" x-text="s.label"></button>
					</template>
				</div>
				<template x-if="data.can_edit">
					<label class="block text-[11px] font-bold uppercase tracking-wider text-ink-muted">Notatka (opcjonalnie, zapisuje się z oceną)
						<textarea class="ubr-input mt-1 block w-full min-h-[60px]" maxlength="1000" x-model="note" placeholder="np. przestarzały wygląd, brak wersji mobilnej, słabe zdjęcia"></textarea>
					</label>
				</template>
				<p class="text-[11px] text-ink-muted" x-text="data.disclaimer"></p>
				<template x-if="data.history.length">
					<div>
						<button type="button" class="text-[12px] font-semibold underline" @click="showHistory = !showHistory" :aria-expanded="showHistory" x-text="(showHistory ? 'Ukryj' : 'Pokaż') + ' historię (' + data.history.length + ')'"></button>
						<ul class="mt-1 space-y-1 text-[12px]" x-show="showHistory">
							<template x-for="(h, i) in data.history" :key="i">
								<li><span class="font-semibold" x-text="(h.previous_label ? h.previous_label + ' → ' : '') + h.label"></span><span class="text-ink-muted" x-text="' · ' + (h.reviewer?.name || '') + ', ' + $store.ubr.date(h.at)"></span><span x-show="h.note" x-text="' — ' + h.note"></span></li>
							</template>
						</ul>
					</div>
				</template>
			</div>
		</template>
	@endif
</div>
