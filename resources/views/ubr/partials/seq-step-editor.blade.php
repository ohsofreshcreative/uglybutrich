{{--
	Prompt 10.5 — edytor kroków sekwencji (wspólny: kampania w bibliotece i kopia kampanii dla firmy).
	Wymaga w zasięgu Alpine: form.steps, form.window, variables, editorId oraz metod stepEditor() z sequences.js.
	Treść wiadomości to tekst (bez HTML) — podgląd i lista wyświetlane przez x-text.
--}}
<div class="space-y-3" data-step-editor>
	<template x-for="(s, i) in form.steps" :key="s._key || s.id || i">
		<article class="rounded-xl border border-line p-3 space-y-2" :class="s.is_active ? '' : 'opacity-60'" :data-step="i + 1">
			<div class="flex flex-wrap items-center gap-2">
				<span class="inline-grid place-items-center size-7 rounded-full bg-ink text-white text-[12px] font-bold" x-text="i + 1"></span>
				<span class="text-[12px] text-ink-muted" x-text="i === 0 ? 'po uruchomieniu' : 'po poprzedniej'"></span>
				<label class="inline-flex items-center gap-1.5 text-[12px]">
					<input type="number" min="0" max="365" class="ubr-input h-8 w-[72px] text-[13px]" x-model.number="s.delay_days" :aria-label="'Odstęp w dniach — wiadomość ' + (i + 1)" data-step-delay>
					<span>dni</span>
				</label>
				<label class="inline-flex items-center gap-1.5 text-[12px] ml-auto"><input type="checkbox" class="ubr-checkbox" x-model="s.is_active"> aktywna</label>
				<div class="flex items-center gap-1">
					<button type="button" class="ubr-btn-ghost ubr-btn-sm !px-2" @click="move(i, -1)" :disabled="i === 0" :aria-label="'Przesuń wyżej wiadomość ' + (i + 1)">↑</button>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm !px-2" @click="move(i, 1)" :disabled="i === form.steps.length - 1" :aria-label="'Przesuń niżej wiadomość ' + (i + 1)">↓</button>
					<button type="button" class="ubr-btn-ghost ubr-btn-sm !px-2" @click="dup(i)" :aria-label="'Duplikuj wiadomość ' + (i + 1)" title="Duplikuj">@include('ubr.partials.icon', ['name' => 'copy', 'class' => 'size-3.5'])</button>
					<button type="button" class="ubr-btn-danger ubr-btn-sm !px-2" @click="remove(i)" :disabled="form.steps.length === 1" :aria-label="'Usuń wiadomość ' + (i + 1)" title="Usuń">@include('ubr.partials.icon', ['name' => 'trash', 'class' => 'size-3.5'])</button>
				</div>
			</div>
			<input class="ubr-input" :id="'step-subject-' + editorId + '-' + i" x-model="s.subject" maxlength="255" :placeholder="'Temat, np. Nowa strona internetowa dla ' + varToken('company_name')" :aria-label="'Temat wiadomości ' + (i + 1)" data-step-subject>
			<textarea class="ubr-input !h-auto min-h-[140px] py-2 leading-relaxed" :id="'step-body-' + editorId + '-' + i" x-model="s.body" rows="7" placeholder="Treść wiadomości (tekst)…" :aria-label="'Treść wiadomości ' + (i + 1)" data-step-body></textarea>
			<div class="flex flex-wrap items-center gap-1.5 text-[11px]">
				<span class="text-ink-muted">Wstaw:</span>
				<template x-for="(label, key) in variables" :key="key">
					<button type="button" class="rounded-md border border-line px-1.5 py-0.5 hover:border-ink/40" @click="insertVar(i, 'body', key)" :title="label" x-text="varToken(key)"></button>
				</template>
			</div>
		</article>
	</template>
	<div class="flex flex-wrap items-center gap-2">
		<button type="button" class="ubr-btn-ghost ubr-btn-sm" @click="addStep()" :disabled="form.steps.length >= 30" data-step-add>@include('ubr.partials.icon', ['name' => 'plus', 'class' => 'size-3.5']) Dodaj wiadomość</button>
		<span class="text-[12px] text-ink-muted" x-text="form.steps.length + ' wiadomości · rozpiętość ok. ' + totalDays() + ' dni (bez przesunięć do okna wysyłki)'"></span>
	</div>
</div>
