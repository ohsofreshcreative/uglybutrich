{{--
	DECYDENT: osoba (z bezpośrednim numerem, a bez niego — Top 1 wg Contact Priority), stanowisko, stan numeru osoby
	(Prompt 10.2: „potwierdzony” / „do weryfikacji” / „Brak numeru decydenta”; numer firmy nigdy nie jest przypisywany osobie;
	Apollo: UNAVAILABLE / UNKNOWN / ERROR — LOCKED tylko po potwierdzeniu przez źródło) i „Odblokuj telefon” (płatne,
	tylko po potwierdzeniu w oknie dialogowym; przycisk widoczny wyłącznie przy uprawnieniu i włączonej funkcji).
	Prompt 10.3: sygnał Apollo z zatwierdzonego dopasowania + „Sprawdź Apollo” (karta firmy, bez zapytań przy renderowaniu).
--}}
<template x-if="{{ $var }}.call?.decision_maker">
	<div class="min-w-0 text-[12px] leading-tight">
		<button type="button" class="font-bold text-left hover:underline line-clamp-1 [overflow-wrap:anywhere]" @click="$store.ubr.drawer.tab = 'contacts'; $store.ubr.openCompany({{ $var }}.id)" x-text="{{ $var }}.call.decision_maker.name" :title="{{ $var }}.call.decision_maker.name"></button>
		<div class="text-[11px] text-ink-muted line-clamp-2 [overflow-wrap:anywhere]" x-text="{{ $var }}.call.decision_maker.title || {{ $var }}.call.decision_maker.role_label || ''" :title="{{ $var }}.call.decision_maker.title || ''"></div>
		<template x-if="{{ $var }}.call.decision_maker.phone">
			<div class="mt-1">
				{{-- Prompt 10.2: potwierdzony ≠ prawdopodobny; numer firmy nigdy nie jest pokazywany jako numer osoby. --}}
				<template x-if="{{ $var }}.call.decision_maker.phone.numbers.length">
					<span class="text-[10px] font-bold uppercase tracking-wide" :class="{{ $var }}.call.decision_maker.phone.numbers[0].verified ? 'text-money' : 'text-gold-deep'"
						x-text="{{ $var }}.call.decision_maker.phone.numbers[0].verified ? 'Telefon decydenta: potwierdzony' : 'Telefon decydenta: do weryfikacji'"
						:title="{{ $var }}.call.decision_maker.phone.numbers[0].evidence_label || ''"></span>
				</template>
				<template x-if="!{{ $var }}.call.decision_maker.phone.numbers.length">
					<span class="text-[10px] font-bold uppercase tracking-wide"
						:class="{ UNAVAILABLE: 'text-ink-muted', UNKNOWN: 'text-ink-muted', ERROR: 'text-danger', LOCKED: 'text-gold-deep' }[{{ $var }}.call.decision_maker.phone.status] || 'text-ink-muted'"
						x-text="{{ $var }}.call.decision_maker.phone.pending ? {{ $var }}.call.decision_maker.phone.status_label : 'Brak numeru decydenta'" :title="{{ $var }}.call.decision_maker.phone.error || {{ $var }}.call.decision_maker.phone.status_label || ''"></span>
				</template>
				{{-- Prompt 10.3: zatwierdzone dopasowanie Apollo — sygnał dostępności (bez numeru ≠ telefon decydenta); „Sprawdź Apollo” otwiera kartę (tylko odczyt). --}}
				<div class="text-[10px] text-ink-muted" x-show="{{ $var }}.call.decision_maker.phone.apollo && !{{ $var }}.call.decision_maker.phone.numbers.some(n => n.verified)" x-text="{{ $var }}.call.decision_maker.phone.direct_label" data-apollo-signal></div>
				<button type="button" class="text-[10px] font-semibold text-ink-soft hover:underline" x-show="!{{ $var }}.call.decision_maker.phone.numbers.some(n => n.verified)"
					@click="$store.ubr.checkApollo({{ $var }}.id)" :aria-label="'Sprawdź Apollo: ' + {{ $var }}.call.decision_maker.name">Sprawdź Apollo</button>
				<div class="text-[10px] text-ink-muted" x-show="{{ $var }}.call.decision_maker.phone.to_verify > 0" x-text="{{ $var }}.call.decision_maker.phone.to_verify + ' numer(y) do ręcznej weryfikacji w karcie firmy'"></div>
				<button type="button" class="mt-1 ubr-btn-ghost ubr-btn-sm !h-7 !px-2 text-[11px]" x-show="{{ $var }}.call.decision_maker.phone.reveal_possible"
					@click="$store.ubr.openReveal({{ $var }}.call.decision_maker.contact_id, {{ $var }}.id)" :aria-label="'Odblokuj telefon: ' + {{ $var }}.call.decision_maker.name">
					@include('ubr.partials.icon', ['name' => 'lock', 'class' => 'size-3.5']) Odblokuj telefon
				</button>
			</div>
		</template>
		<template x-if="!{{ $var }}.call.decision_maker.phone">
			<div class="mt-1 text-[10px] font-bold uppercase tracking-wide text-ink-muted">Brak numeru decydenta</div>
		</template>
	</div>
</template>
<template x-if="!{{ $var }}.call?.decision_maker">
	<div class="text-[12px] text-ink-muted leading-tight">
		<span x-text="{{ $var }}.contacts?.label || 'Nie szukano'"></span>
		<div class="text-[10px] mt-0.5" x-text="{{ $var }}.call?.company_phone ? 'Cold call możliwy przez numer firmy.' : 'Brak decydenta i numeru firmy.'"></div>
	</div>
</template>
