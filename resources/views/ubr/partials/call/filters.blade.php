{{--
	Filtry Cold Calling (wspólne: Discover, Prospects). Działają na CAŁYM zbiorze (WHERE w SQL), nie na bieżącej stronie.
	Parametry: $model (obiekt filtrów w komponencie, np. 'callFilters'), $reload (wywołanie przeładowania, np. 'loadResults(1)').
--}}
<button type="button" class="ubr-input h-8 text-[12px] w-auto inline-flex items-center gap-1.5 font-semibold" :class="{{ $model }}.free_with_phone ? '!border-ink bg-ink text-white' : ''"
	:aria-pressed="!!{{ $model }}.free_with_phone" @click="{{ $model }}.free_with_phone = {{ $model }}.free_with_phone ? '' : '1'; {{ $reload }}"
	title="Firmy bez opiekuna, z numerem telefonu, bez zakazu kontaktu i blokad">
	@include('ubr.partials.icon', ['name' => 'phone', 'class' => 'size-3.5']) Wolne firmy z telefonem
</button>
<select class="ubr-input h-8 text-[12px] w-auto" x-model="{{ $model }}.phone" @change="{{ $reload }}" aria-label="Telefon">
	<option value="">Telefon: dowolnie</option>
	<option value="direct">Ma bezpośredni telefon decydenta</option>
	<option value="dm_verified">Ma potwierdzony telefon decydenta</option>
	<option value="dm_review">Telefon decydenta do weryfikacji</option>
	<option value="company_only">Ma tylko telefon firmy</option>
	<option value="no_dm">Brak telefonu decydenta</option>
	<option value="company">Numer firmy</option>
	<option value="any">Jakikolwiek numer</option>
	<option value="none">Bez numeru</option>
</select>
<details class="relative">
	<summary class="ubr-input h-8 text-[12px] w-auto inline-flex items-center gap-1 cursor-pointer list-none" :class="[{{ $model }}.phone_source, {{ $model }}.dm_role].some(Boolean) ? 'border-ink' : ''">Decydent: filtry<span x-show="[{{ $model }}.phone_source, {{ $model }}.dm_role].some(Boolean)" class="font-bold">•</span></summary>
	<div class="absolute left-0 z-20 mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-xl border border-line bg-white shadow-[var(--shadow-pop)] p-3 space-y-2">
		<select class="ubr-input h-8 text-[12px] w-full" x-model="{{ $model }}.phone_source" @change="{{ $reload }}" aria-label="Źródło telefonu decydenta">
			<option value="">Źródło telefonu: dowolne</option>
			<option value="website">Strona firmowa</option>
			<option value="manual">Ręcznie potwierdzony</option>
			<option value="apollo">Apollo</option>
		</select>
		<select class="ubr-input h-8 text-[12px] w-full" x-model="{{ $model }}.dm_role" @change="{{ $reload }}" aria-label="Stanowisko decydenta">
			<option value="">Stanowisko decydenta: dowolne</option>
			@foreach (\App\Ubr\Domain\Calling\CallReadiness::DECISION_ROLES as $role)
				<option value="{{ $role }}">{{ \App\Ubr\Domain\Contacts\RoleCatalog::label($role) }}</option>
			@endforeach
		</select>
	</div>
</details>
<select class="ubr-input h-8 text-[12px] w-auto" x-model="{{ $model }}.readiness" @change="{{ $reload }}" aria-label="Gotowość do telefonu">
	<option value="">Gotowość: dowolna</option>
	@foreach (\App\Ubr\Domain\Calling\CallReadiness::LABELS as $key => $l)
		<option value="{{ $key }}">{{ $l['label_pl'] }}</option>
	@endforeach
</select>
<select class="ubr-input h-8 text-[12px] w-auto" x-model="{{ $model }}.call_owner" @change="{{ $reload }}" aria-label="Opiekun">
	<option value="">Opiekun: dowolny</option>
	<option value="free">Wolne (bez opiekuna)</option>
	<option value="mine">Moje</option>
	<option value="others">Innych pracowników</option>
	<option value="contacted">Z historią kontaktu</option>
	<option value="dnc">Nie kontaktować</option>
</select>
<select class="ubr-input h-8 text-[12px] w-auto" x-model="{{ $model }}.size" @change="{{ $reload }}" aria-label="Wielkość firmy">
	<option value="">Wielkość: dowolna</option>
	@foreach (array_keys(\App\Ubr\Domain\Calling\CompanySize::BANDS) as $band)
		<option value="{{ $band }}">{{ str_replace('-', '–', $band) }} os.</option>
	@endforeach
	<option value="unknown">Brak danych</option>
</select>
<details class="relative">
	<summary class="ubr-input h-8 text-[12px] w-auto inline-flex items-center gap-1 cursor-pointer list-none" :class="[{{ $model }}.contacted, {{ $model }}.pipeline_stage, {{ $model }}.analysis_state, {{ $model }}.industry_q, {{ $model }}.location_q].some(Boolean) ? 'border-ink' : ''">CRM i firma: filtry<span x-show="[{{ $model }}.contacted, {{ $model }}.pipeline_stage, {{ $model }}.analysis_state, {{ $model }}.industry_q, {{ $model }}.location_q].some(Boolean)" class="font-bold">•</span></summary>
	<div class="absolute right-0 z-20 mt-1 w-72 max-w-[calc(100vw-2rem)] rounded-xl border border-line bg-white shadow-[var(--shadow-pop)] p-3 space-y-2">
		<select class="ubr-input h-8 text-[12px] w-full" x-model="{{ $model }}.contacted" @change="{{ $reload }}" aria-label="Ostatni kontakt">
			<option value="">Ostatni kontakt: dowolnie</option>
			<option value="7">W ostatnich 7 dniach</option>
			<option value="30">W ostatnich 30 dniach</option>
			<option value="90">W ostatnich 90 dniach</option>
			<option value="never">Nigdy</option>
		</select>
		<select class="ubr-input h-8 text-[12px] w-full" x-model="{{ $model }}.pipeline_stage" @change="{{ $reload }}" aria-label="Etap pipeline">
			<option value="">Etap pipeline: dowolny</option>
			@foreach (\App\Ubr\Domain\Pipeline\PipelineCatalog::STATUSES as $key => $s)
				<option value="{{ $key }}">{{ $s['label'] }}</option>
			@endforeach
		</select>
		<select class="ubr-input h-8 text-[12px] w-full" x-model="{{ $model }}.analysis_state" @change="{{ $reload }}" aria-label="Analiza strony">
			<option value="">Analiza strony: dowolnie</option>
			<option value="analyzed">Przeanalizowane</option>
			<option value="not_analyzed">Nieanalizowane</option>
			<option value="active">W kolejce / w toku</option>
			<option value="failed">Błąd analizy</option>
		</select>
		<input type="search" class="ubr-input h-8 text-[12px] w-full" maxlength="80" placeholder="Branża zawiera…" x-model="{{ $model }}.industry_q" @input.debounce.400ms="{{ $reload }}" aria-label="Branża">
		<input type="search" class="ubr-input h-8 text-[12px] w-full" maxlength="80" placeholder="Miasto lub województwo…" x-model="{{ $model }}.location_q" @input.debounce.400ms="{{ $reload }}" aria-label="Lokalizacja">
	</div>
</details>
