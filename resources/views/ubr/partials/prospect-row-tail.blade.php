{{-- Prospects: eksport do ClickUp i archiwizacja (z potwierdzeniem) — pod szybkimi akcjami Cold Calling. --}}
<div class="mt-1 flex flex-wrap items-center gap-1 {{ ! empty($compact) ? 'justify-start' : 'justify-end' }}">
	<template x-if="{{ $var }}.pipeline?.clickup_task_url">
		<a :href="$store.ubr.safeHref({{ $var }}.pipeline.clickup_task_url)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 ubr-badge-confirmed hover:underline text-[11px]">ClickUp @include('ubr.partials.icon', ['name' => 'external', 'class' => 'size-3'])</a>
	</template>
	<template x-if="!{{ $var }}.pipeline?.clickup_task_url">
		<button type="button" class="ubr-btn-ghost ubr-btn-sm !h-7 !px-2 text-[11px]" x-show="canExport && !{{ $var }}.pipeline?.archived" @click="$store.ubr.exportClickup({{ $var }}.id)" :aria-label="'Eksportuj „' + {{ $var }}.name + '” do ClickUp'">ClickUp</button>
	</template>
	<template x-if="{{ $var }}.pipeline?.archived">
		<button type="button" class="ubr-btn-ghost ubr-btn-sm !h-7 !px-2 text-[11px]" @click="restore({{ $var }})">Przywróć</button>
	</template>
	<template x-if="!{{ $var }}.pipeline?.archived && confirmId !== {{ $var }}.prospect_id">
		<button type="button" class="ubr-btn-ghost ubr-btn-sm !h-7 !px-2" @click="confirmId = {{ $var }}.prospect_id" title="Archiwizuj (usuń z listy)" :aria-label="'Archiwizuj: ' + {{ $var }}.name">@include('ubr.partials.icon', ['name' => 'trash', 'class' => 'size-3.5'])</button>
	</template>
	<template x-if="!{{ $var }}.pipeline?.archived && confirmId === {{ $var }}.prospect_id">
		<span class="inline-flex gap-1">
			<button type="button" class="ubr-btn-danger ubr-btn-sm !h-7" @click="remove({{ $var }})">Archiwizuj</button>
			<button type="button" class="ubr-btn-ghost ubr-btn-sm !h-7" @click="confirmId = null">Nie</button>
		</span>
	</template>
</div>
