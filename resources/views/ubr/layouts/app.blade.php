<!doctype html>
<html lang="pl">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>{{ $title }} · Ugly but Rich</title>
	@if ($iconUrl)
		<link rel="icon" type="image/png" href="{{ $iconUrl }}">
	@endif
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<script>window.UBR = @json($config);</script>
	@vite(['resources/css/ubr.css', 'resources/js/ubr.js'])
</head>

<body class="min-h-screen" x-data="ubrShell" @keydown.escape.window="$store.ubr.escape()">

	{{-- Sidebar --}}
	<aside
		class="fixed inset-y-0 left-0 z-40 w-[248px] bg-ink text-white flex flex-col transition-transform lg:translate-x-0"
		:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">
		<div class="px-5 pt-5 pb-4">
			<a href="{{ $config['urls']['dashboard'] }}" class="block rounded-xl bg-white/[0.04] p-2.5">
				@if ($logoUrl)
					<img src="{{ $logoUrl }}" alt="Ugly but Rich" class="w-full h-auto" width="640" height="300">
				@else
					<span class="font-display text-xl">Ugly but Rich</span>
				@endif
			</a>
		</div>

		<nav class="px-3 flex-1 space-y-1" aria-label="Nawigacja główna">
			@foreach ($nav as $item)
				<a href="{{ $item['url'] }}" class="ubr-nav-link" @if ($item['active']) aria-current="page" @endif>
					@include('ubr.partials.icon', ['name' => $item['icon'], 'class' => 'size-[18px]'])
					{{ $item['title'] }}
				</a>
			@endforeach

		</nav>

		{{-- Organizacja --}}
		<div class="p-3 border-t border-white/10" x-data="{ open: false }">
			<button type="button" @click="open = !open" class="w-full flex items-center gap-3 rounded-[10px] px-3 py-2.5 hover:bg-white/5 text-left">
				<span class="grid place-items-center size-8 rounded-lg bg-money text-white font-bold text-[13px]">{{ mb_strtoupper(mb_substr($ctx?->organization['name'] ?? '?', 0, 1)) }}</span>
				<span class="min-w-0 flex-1">
					<span class="block text-[13px] font-bold truncate">{{ $ctx?->organization['name'] ?? 'Brak organizacji' }}</span>
					<span class="block text-[11px] text-white/45">{{ $ctx?->role->label() }} · {{ $user->display_name }}</span>
				</span>
				@include('ubr.partials.icon', ['name' => 'chevron-down', 'class' => 'size-4 text-white/40'])
			</button>
			<div x-show="open" x-cloak @click.outside="open = false" class="mt-1 rounded-xl bg-ink-soft p-1.5 space-y-0.5">
				<div class="px-2.5 pt-1 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-white/35">Organizacje</div>
				@foreach ($memberships as $m)
					<button type="button" class="w-full flex items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-[13px] hover:bg-white/5 text-left"
						@click="$store.ubr.switchOrganization({{ (int) $m['id'] }})">
						<span class="truncate">{{ $m['name'] }}</span>
						@if ($ctx && $ctx->organizationId === (int) $m['id'])
							@include('ubr.partials.icon', ['name' => 'check', 'class' => 'size-4 text-gold'])
						@endif
					</button>
				@endforeach
				<a href="{{ $logoutUrl }}" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-[13px] text-white/60 hover:bg-white/5 hover:text-white">
					@include('ubr.partials.icon', ['name' => 'logout'])
					Wyloguj
				</a>
			</div>
		</div>
	</aside>

	<div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-30 bg-ink/40 lg:hidden"></div>

	{{-- Treść --}}
	<div class="lg:pl-[248px] min-h-screen flex flex-col">
		<header class="sticky top-0 z-20 bg-canvas/85 backdrop-blur border-b border-line/60">
			<div class="flex items-center gap-3 px-4 sm:px-8 h-16">
				<button type="button" class="lg:hidden ubr-btn-ghost ubr-btn-sm px-2" @click="sidebarOpen = true" aria-label="Menu">
					@include('ubr.partials.icon', ['name' => 'menu'])
				</button>
				<h1 class="text-[20px] font-extrabold tracking-tight">{{ $title }}</h1>
				<div class="ml-auto flex items-center gap-2">@yield('actions')</div>
			</div>
		</header>

		<main class="flex-1 px-4 sm:px-8 py-6 sm:py-8">
			@yield('content')
		</main>

		<footer class="px-4 sm:px-8 py-5 text-[12px] text-ink-muted flex flex-wrap gap-x-4 gap-y-1">
			<span>Ugly but Rich · OhSoFresh Creative</span>
			<span>Dane: <a class="underline hover:text-ink" href="https://www.wikidata.org" target="_blank" rel="noopener">Wikidata</a> (CC0), © <a class="underline hover:text-ink" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a> (ODbL)</span>
		</footer>
	</div>

	@include('ubr.partials.company-drawer')
	@include('ubr.partials.preview-modal')
	@include('ubr.partials.call.modals')
	@include('ubr.partials.toasts')
</body>

</html>
