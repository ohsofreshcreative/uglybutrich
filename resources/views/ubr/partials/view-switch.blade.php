{{-- Przełącznik List View / Board View (Prospects ↔ Pipeline) --}}
<div class="inline-flex rounded-xl border border-line bg-white p-0.5" role="group" aria-label="Widok">
	<a href="{{ $config['urls']['prospects'] }}" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-[13px] font-semibold {{ $active === 'list' ? 'bg-ink text-white' : 'text-ink-muted hover:text-ink' }}" @if ($active === 'list') aria-current="page" @endif>
		@include('ubr.partials.icon', ['name' => 'list', 'class' => 'size-3.5']) Lista
	</a>
	<a href="{{ $config['urls']['pipeline'] }}" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-[13px] font-semibold {{ $active === 'board' ? 'bg-ink text-white' : 'text-ink-muted hover:text-ink' }}" @if ($active === 'board') aria-current="page" @endif>
		@include('ubr.partials.icon', ['name' => 'board', 'class' => 'size-3.5']) Tablica
	</a>
</div>
