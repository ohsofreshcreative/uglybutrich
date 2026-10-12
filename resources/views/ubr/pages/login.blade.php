<!doctype html>
<html lang="pl">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Logowanie · Ugly but Rich</title>
	@if ($iconUrl)
		<link rel="icon" type="image/png" href="{{ $iconUrl }}">
	@endif
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	@vite(['resources/css/ubr.css'])
</head>

<body class="min-h-screen bg-canvas">
	<div class="min-h-screen grid lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">

		{{-- Strona marki (desktop) --}}
		<aside class="relative hidden lg:flex flex-col justify-between overflow-hidden bg-ink text-white p-12 xl:p-16" aria-hidden="true">
			<div class="pointer-events-none absolute -right-28 -bottom-28 size-[460px] rounded-full bg-money/40 blur-3xl"></div>
			<div class="pointer-events-none absolute -left-20 top-1/3 size-[260px] rounded-full bg-gold/15 blur-3xl"></div>

			<div class="relative w-[300px] rounded-2xl bg-white/[0.04] p-3">
				@if ($logoUrl)
					<img src="{{ $logoUrl }}" alt="" class="w-full h-auto" width="640" height="300">
				@else
					<span class="font-display text-3xl">Ugly but Rich</span>
				@endif
			</div>

			<div class="relative max-w-[460px]">
				<p class="font-display text-[46px] leading-[1.05] tracking-tight">Brzydkie strony.<br><span class="text-gold">Bogate firmy.</span></p>
				<p class="mt-5 text-[15px] leading-relaxed text-white/60">Znajdujemy firmy, które stać na nową stronę — i docieramy do nich, zanim zrobi to konkurencja.</p>
			</div>

			<p class="relative text-[12px] text-white/35">OhSoFresh Creative · narzędzie wewnętrzne</p>
		</aside>

		{{-- Formularz --}}
		<main class="flex items-center justify-center px-4 py-10 sm:px-8">
			<div class="w-full max-w-[400px]">
				@if ($logoUrl)
					<img src="{{ $logoUrl }}" alt="Ugly but Rich" class="lg:hidden w-52 mx-auto mb-8" width="640" height="300">
				@endif

				<h1 class="text-[26px] font-extrabold tracking-tight">Zaloguj się</h1>
				<p class="mt-1 text-[14px] text-ink-muted">Panel Ugly but Rich — dostęp tylko dla zespołu.</p>

				@if ($notice)
					<p class="mt-5 rounded-xl bg-money-soft px-4 py-3 text-[13px] font-semibold text-money" role="status" data-login-notice>{{ $notice }}</p>
				@endif

				@if ($error)
					<p class="mt-5 rounded-xl bg-danger-soft px-4 py-3 text-[13px] font-semibold text-danger" role="alert" id="login-error" data-login-error>{{ $error }}</p>
				@endif

				<form method="post" action="{{ $action }}" class="mt-6 space-y-4" data-login-form novalidate>
					<input type="hidden" name="_ubr_login" value="{{ $nonce }}">
					@if ($redirect)
						<input type="hidden" name="redirect_to" value="{{ $redirect }}">
					@endif

					<div>
						<label class="ubr-label" for="ubr-log">E-mail lub login</label>
						<input id="ubr-log" name="log" type="text" class="ubr-input h-11" value="{{ $login }}" autocomplete="username" autocapitalize="none" spellcheck="false" required
							@if ($error) aria-describedby="login-error" @endif @if (! $login) autofocus @endif>
					</div>

					<div>
						<div class="flex items-baseline justify-between gap-3">
							<label class="ubr-label" for="ubr-pwd">Hasło</label>
							<a href="{{ $lostUrl }}" class="text-[12px] font-semibold text-ink-muted underline-offset-2 hover:text-ink hover:underline">Nie pamiętasz hasła?</a>
						</div>
						<div class="relative">
							<input id="ubr-pwd" name="pwd" type="password" class="ubr-input h-11 pr-20" autocomplete="current-password" required
								@if ($error) aria-describedby="login-error" @endif @if ($login) autofocus @endif>
							<button type="button" class="absolute inset-y-0 right-0 px-3 text-[12px] font-semibold text-ink-muted hover:text-ink" aria-controls="ubr-pwd" aria-pressed="false" data-toggle-password>Pokaż</button>
						</div>
					</div>

					<label class="flex items-center gap-2.5 text-[13px] text-ink-soft select-none">
						<input type="checkbox" name="rememberme" value="1" class="ubr-checkbox">
						Nie wylogowuj mnie przez 14 dni
					</label>

					<button type="submit" class="ubr-btn-primary w-full h-11 text-[14px]" data-login-submit>Zaloguj się</button>
				</form>

				<p class="mt-8 text-[12px] text-ink-muted">Nie masz konta albo nie możesz się zalogować? Napisz do administratora organizacji.</p>
			</div>
		</main>
	</div>

	<script>
		(function () {
			var toggle = document.querySelector('[data-toggle-password]');
			var input = document.getElementById('ubr-pwd');
			if (toggle && input) {
				toggle.addEventListener('click', function () {
					var show = input.type === 'password';
					input.type = show ? 'text' : 'password';
					toggle.textContent = show ? 'Ukryj' : 'Pokaż';
					toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
					input.focus();
				});
			}
			var form = document.querySelector('[data-login-form]');
			var submit = document.querySelector('[data-login-submit]');
			if (form && submit) {
				form.addEventListener('submit', function () {
					submit.disabled = true;
					submit.textContent = 'Logowanie…';
				});
			}
		})();
	</script>
</body>

</html>
