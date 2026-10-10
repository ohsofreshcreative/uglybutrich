<div class="fixed bottom-4 right-4 z-[60] flex flex-col gap-2 w-[min(360px,calc(100vw-2rem))]" aria-live="polite">
	{{-- Postęp eksportu zbiorczego do ClickUp (w tle) --}}
	<template x-if="$store.ubr.clickupBatch">
		<div class="rounded-xl px-4 py-3 text-[13px] bg-white border border-line shadow-[var(--shadow-pop)]">
			<div class="flex items-center gap-2">
				<span class="font-bold flex-1" x-text="$store.ubr.clickupBatch.done ? 'Eksport do ClickUp zakończony' : 'Eksport do ClickUp w toku…'"></span>
				<button type="button" class="opacity-60 hover:opacity-100" @click="$store.ubr.clickupBatch = null" aria-label="Zamknij">×</button>
			</div>
			<div class="mt-2 h-1.5 rounded-full bg-canvas overflow-hidden" role="progressbar" :aria-valuenow="($store.ubr.clickupBatch.total - ($store.ubr.clickupBatch.counts.queued || 0) - ($store.ubr.clickupBatch.counts.running || 0))" aria-valuemin="0" :aria-valuemax="$store.ubr.clickupBatch.total">
				<div class="h-full bg-money transition-all" :style="'width:' + Math.round(100 * ($store.ubr.clickupBatch.total - ($store.ubr.clickupBatch.counts.queued || 0) - ($store.ubr.clickupBatch.counts.running || 0)) / Math.max(1, $store.ubr.clickupBatch.total)) + '%'"></div>
			</div>
			<div class="mt-1.5 text-[12px] text-ink-muted">
				Sukces: <strong class="text-ink" x-text="$store.ubr.clickupBatch.counts.success || 0"></strong> ·
				Błędy: <strong class="text-ink" x-text="($store.ubr.clickupBatch.counts.failed || 0) + ($store.ubr.clickupBatch.counts.unknown || 0)"></strong> ·
				Z <span x-text="$store.ubr.clickupBatch.total"></span>
			</div>
		</div>
	</template>
	<template x-for="t in $store.ubr.toasts" :key="t.id">
		<div class="rounded-xl px-4 py-3 text-[13px] font-semibold shadow-[var(--shadow-pop)] flex items-start gap-2"
			:class="t.type === 'error' ? 'bg-danger text-white' : 'bg-ink text-white'">
			<span class="flex-1" x-text="t.message"></span>
			<button type="button" class="opacity-60 hover:opacity-100" @click="$store.ubr.dismiss(t.id)" aria-label="Zamknij">×</button>
		</div>
	</template>
</div>
