<!doctype html>
<html lang="pl">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Brak dostępu · Ugly but Rich</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;800&display=swap" rel="stylesheet">
	<script>window.UBR = @json($config);</script>
	@vite(['resources/css/ubr.css', 'resources/js/ubr.js'])
</head>

<body class="min-h-screen grid place-items-center p-6" x-data="ubrNoAccess">
	<main class="ubr-card max-w-[440px] w-full p-8 text-center">
		@if ($logoUrl)
			<img src="{{ $logoUrl }}" alt="Ugly but Rich" class="w-48 mx-auto mb-6" width="640" height="300">
		@endif
		<h1 class="text-[20px] font-extrabold">Nie należysz do żadnej organizacji</h1>
		<p class="text-[13px] text-ink-muted mt-2">Poproś właściciela organizacji o dodanie Twojego konta ({{ $user->user_email }}).</p>

		@if ($config['canCreateOrganization'])
			<form class="mt-6 flex gap-2" @submit.prevent="create()">
				<input class="ubr-input" x-model="name" placeholder="Nazwa nowej organizacji" required maxlength="190">
				<button class="ubr-btn-primary" :disabled="saving">Utwórz</button>
			</form>
			<p class="text-[12px] text-danger mt-2" x-show="error" x-text="error"></p>
		@endif

		<a href="{{ $logoutUrl }}" class="inline-block mt-6 text-[13px] underline text-ink-muted hover:text-ink">Wyloguj</a>
	</main>
</body>

</html>
