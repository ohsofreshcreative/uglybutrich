<?php
/**
 * Testy Prompt 10.5 — Sequences: biblioteka kampanii, kopie kampanii dla firm, personalizacja, skrzynki, harmonogram,
 * worker wysyłki (blokady, wynik nieznany, ponowienia, limity), wykluczenia, rezygnacja, uprawnienia.
 *
 * Dołączane z tests/run.php. Wysyłka WYŁĄCZNIE przez mock transportu albo lokalny testowy serwer SMTP
 * (tests/tools/fake_smtp.py, 127.0.0.1) — żadna wiadomość nie wychodzi poza kontener. Firmy i adresy FIKCYJNE.
 */

use App\Ubr\Domain\Sequences\EmailAddress;
use App\Ubr\Domain\Sequences\SendWindow;
use App\Ubr\Domain\Sequences\TemplateRenderer;
use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Mail\MailTransport;
use App\Ubr\Infrastructure\Mail\OutgoingMessage;
use App\Ubr\Infrastructure\Mail\SmtpTransport;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Services\OrganizationService;
use App\Ubr\Services\Sequences\SequenceRunner;
use App\Ubr\Services\Sequences\SequenceService;
use App\Ubr\Services\Sequences\UnsubscribeService;

if (! defined('ABSPATH')) {
	exit(1);
}

$utc = fn (string $s) => new DateTimeImmutable($s, new DateTimeZone('UTC'));
$wWarsaw = ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '16:00', 'timezone' => 'Europe/Warsaw'];

/* ------------------------------------------------------------------ */
UbrT::group('P10.5 — Harmonogram (okno wysyłki) i personalizacja — jednostkowe');

// 25. Pierwsza wiadomość: start w poniedziałek 08:00 czasu PL → 09:00 tego dnia (07:00 UTC, czas letni).
UbrT::eq('2026-10-12 07:00:00', SendWindow::mysql(SendWindow::next($utc('2026-10-12 06:00:00'), $wWarsaw)), '25. pierwsza wiadomość: przed oknem → początek okna tego samego dnia (strefa Europe/Warsaw)');
UbrT::eq('2026-10-12 08:30:00', SendWindow::mysql(SendWindow::next($utc('2026-10-12 08:30:00'), $wWarsaw)), '25. w oknie → bez przesunięcia');
// 26. Kolejne: +17 dni od faktycznej wysyłki.
UbrT::eq('2026-10-29 08:30:00', SendWindow::mysql(SendWindow::due($utc('2026-10-12 08:30:00'), 17, $wWarsaw)), '26. kolejna wiadomość: +17 dni od wysyłki, ta sama godzina lokalna (zmiana czasu 25.10 uwzględniona)');
// 27. Weekend → poniedziałek 09:00.
UbrT::eq('2026-10-19 07:00:00', SendWindow::mysql(SendWindow::next($utc('2026-10-17 10:00:00'), $wWarsaw)), '27. sobota → poniedziałek 09:00 (bez wysyłki w weekend)');
// 28. Po godzinach → następny dzień roboczy 09:00; piątek po 16:00 → poniedziałek.
UbrT::eq('2026-10-14 07:00:00', SendWindow::mysql(SendWindow::next($utc('2026-10-13 15:30:00'), $wWarsaw)), '28. po godzinach (17:30 PL) → następny dzień 09:00');
UbrT::eq('2026-10-19 07:00:00', SendWindow::mysql(SendWindow::next($utc('2026-10-16 14:05:00'), $wWarsaw)), '28. piątek 16:05 → poniedziałek 09:00');
// 29. Strefa czasowa: to samo okno w Londynie daje inny moment UTC; czas zimowy po 25.10.
UbrT::eq('2026-10-12 08:00:00', SendWindow::mysql(SendWindow::next($utc('2026-10-12 06:00:00'), ['timezone' => 'Europe/London'] + $wWarsaw)), '29. strefa Europe/London: 09:00 lokalnie = 08:00 UTC');
UbrT::eq('2026-10-26 08:00:00', SendWindow::mysql(SendWindow::next($utc('2026-10-26 05:00:00'), $wWarsaw)), '29. czas zimowy (po 25.10): 09:00 PL = 08:00 UTC');
UbrT::ok(SendWindow::next($utc('2026-10-17 10:00:00'), $wWarsaw) == SendWindow::next($utc('2026-10-17 10:00:00'), $wWarsaw), '29. przesunięcie deterministyczne (te same dane → ten sam termin)');
UbrT::ok(SendWindow::normalize(['days' => [9], 'from' => '25:00', 'to' => '08:00', 'timezone' => 'Mars/Base'])['timezone'] === 'Europe/Warsaw', 'okno: niepoprawne wartości → domyślne (bez błędów)');

$missing = [];
$r = TemplateRenderer::render("Dzień dobry {{contact_first_name}}, firma {{company_name}}.", ['company_name' => '<b>ABC</b> & Syn<script>x</script>', 'contact_first_name' => ''], $missing);
UbrT::ok(! str_contains($r, '<b>') && ! str_contains($r, '<script>') && str_contains($r, 'ABC & Syn') && $missing === ['contact_first_name'], '8. personalizacja: wartości bez HTML (tekst), brakująca zmienna wykryta');
$m2 = [];
$subj = TemplateRenderer::render("Oferta {{company_name}}", ['company_name' => "ABC\r\nBcc: ofiara@example.test"], $m2, true);
UbrT::ok(! str_contains($subj, "\n") && ! str_contains($subj, "\r"), 'personalizacja: brak wstrzyknięcia nagłówków w temacie (nowe linie usunięte)');
UbrT::eq(['contakt_name'], TemplateRenderer::missing(['Cześć {{contakt_name}} {{company_name}} {{unsubscribe_link}}'], ['company_name' => 'X']), '45. nieznana zmienna (literówka) = brak — nigdy surowy placeholder; link rezygnacji uzupełniany automatycznie');
UbrT::ok(! EmailAddress::syntaxOk('jan..kowalski@firma.pl') && ! EmailAddress::syntaxOk('jan@firma') && ! EmailAddress::syntaxOk('<a>@x.pl') && EmailAddress::syntaxOk('Jan.Kowalski+oferta@Firma.PL'), '44. niepoprawny adres technicznie odrzucony (składnia)');

/* ------------------------------------------------------------------ */
UbrT::group('P10.5 — Biblioteka kampanii');

add_filter('ubr/mail/test_mailboxes_allowed', '__return_true');
add_filter('ubr/mail/domain_accepts', fn ($v, $domain) => $domain === 'nomx.ubrtest.pl' ? false : true, 10, 2);
$uS = $mk('ownerS105');
$uS1 = $mk('memberS105a');
$uS2 = $mk('memberS105b');
$orgS = app(OrganizationService::class)->create('ubrtest Org S 10.5', $uS);
$created['orgs'][] = $orgS;
app(MemberRepository::class)->add($orgS, $uS1, Role::Member);
app(MemberRepository::class)->add($orgS, $uS2, Role::Member);
update_user_meta($uS1, 'first_name', 'Anna');
update_user_meta($uS1, 'last_name', 'Testowa');
$GLOBALS['ubrt_seq_orgs'][] = $orgS;
$steps3 = [
	['subject' => 'Nowa strona internetowa dla {{company_name}}', 'body' => "Dzień dobry,\n\nzwróciłam uwagę na stronę {{company_website}}.\n\n{{sender_first_name}} {{sender_last_name}}", 'delay_days' => 0],
	['subject' => 'Kilka pomysłów dotyczących strony {{company_name}}', 'body' => "Dzień dobry,\nwracam z pomysłami.\n{{sender_name}}", 'delay_days' => 17],
	['subject' => 'Czy temat strony WWW jest aktualny?', 'body' => 'Krótkie pytanie. {{sender_name}}', 'delay_days' => 21],
];
$denied = ubrt_rest($uS1, 'POST', '/sequences/campaigns', ['name' => 'Wspólna od pracownika', 'scope' => 'shared', 'steps' => $steps3]);
UbrT::eq(403, $denied['status'], '1/uprawnienia. pracownik nie tworzy kampanii WSPÓLNEJ (tylko owner/admin)');
$own = ubrt_rest($uS1, 'POST', '/sequences/campaigns', ['name' => 'Moja kampania', 'scope' => 'personal', 'steps' => $steps3]);
UbrT::ok($own['status'] === 201 && $own['data']['scope'] === 'personal' && count($own['data']['steps']) === 3, '1. pracownik tworzy prywatną kampanię (3 wiadomości)');
$cr = ubrt_rest($uS, 'POST', '/sequences/campaigns', ['name' => 'Redesign strony WWW', 'description' => 'Strony przestarzałe', 'scope' => 'shared', 'steps' => $steps3]);
$cmp = $cr['data'];
UbrT::ok($cr['status'] === 201 && $cmp['status'] === 'draft' && $cmp['version'] === 1 && $cmp['steps'][1]['delay_days'] === 17, '1. owner tworzy wspólną kampanię jako szkic (dowolna liczba kroków, własne odstępy)');
$s4 = $steps3;
$s4[] = ['subject' => 'Ostatnia wiadomość w sprawie strony', 'body' => 'Zamykam temat. {{sender_name}}', 'delay_days' => 14];
$ed = ubrt_rest($uS, 'POST', '/sequences/campaigns/' . $cmp['id'], ['name' => 'Redesign strony WWW', 'steps' => $s4]);
UbrT::ok(count($ed['data']['steps']) === 4 && $ed['data']['version'] === 2, '2/4. edycja i dodanie kroku → wersja szablonu +1');
$reordered = [$s4[0], $s4[2], $s4[1], $s4[3]];
$ro = ubrt_rest($uS, 'POST', '/sequences/campaigns/' . $cmp['id'], ['steps' => $reordered]);
UbrT::eq('Czy temat strony WWW jest aktualny?', $ro['data']['steps'][1]['subject'], '5. zmiana kolejności kroków');
$ro = ubrt_rest($uS, 'POST', '/sequences/campaigns/' . $cmp['id'], ['steps' => $s4]);
$rm = ubrt_rest($uS, 'POST', '/sequences/campaigns/' . $cmp['id'], ['steps' => array_slice($s4, 0, 3)]);
UbrT::ok(count($rm['data']['steps']) === 3, '3. usunięcie kroku');
$rm = ubrt_rest($uS, 'POST', '/sequences/campaigns/' . $cmp['id'], ['steps' => $s4]);
UbrT::eq(422, ubrt_rest($uS, 'POST', '/sequences/campaigns/' . $cmp['id'], ['steps' => [['subject' => '', 'body' => 'x']]])['status'] >= 400 ? 422 : 0, 'walidacja: krok bez tematu odrzucony');
UbrT::eq(403, ubrt_rest($uS1, 'POST', '/sequences/campaigns/' . $cmp['id'], ['name' => 'Przejęta'])['status'], 'uprawnienia: pracownik nie edytuje wspólnej kampanii');
$act = ubrt_rest($uS, 'POST', '/sequences/campaigns/' . $cmp['id'], ['status' => 'active']);
UbrT::eq('active', $act['data']['status'], 'aktywacja kampanii');
$dup = ubrt_rest($uS1, 'POST', '/sequences/campaigns/' . $cmp['id'] . '/duplicate');
UbrT::ok($dup['status'] === 201 && $dup['data']['scope'] === 'personal' && $dup['data']['status'] === 'draft' && count($dup['data']['steps']) === 4 && str_contains($dup['data']['name'], '(kopia)'), '6. duplikat wspólnej kampanii przez pracownika → prywatny szkic z kompletem kroków');
$arch = ubrt_rest($uS1, 'POST', '/sequences/campaigns/' . $dup['data']['id'], ['status' => 'archived']);
$listMember = ubrt_rest($uS1, 'GET', '/sequences/campaigns')['data']['items'];
UbrT::ok($arch['data']['status'] === 'archived' && ! in_array($dup['data']['id'], array_column($listMember, 'id'), true), '7. archiwizacja (domyślnie poza listą)');
UbrT::ok(! in_array($own['data']['id'], array_column(ubrt_rest($uS2, 'GET', '/sequences/campaigns')['data']['items'], 'id'), true) && in_array($own['data']['id'], array_column(ubrt_rest($uS, 'GET', '/sequences/campaigns')['data']['items'], 'id'), true), 'uprawnienia: prywatna kampania niewidoczna dla innego pracownika (widoczna dla admina)');
$pv = ubrt_rest($uS1, 'POST', '/sequences/preview', ['steps' => $s4, 'window' => $wWarsaw]);
$items = $pv['data']['items'];
UbrT::ok(count($items) === 4 && str_contains($items[0]['subject'], '[Nazwa firmy]') && strtotime($items[1]['at']) - strtotime($items[0]['at']) >= 17 * DAY_IN_SECONDS - 7200, '8. podgląd całej sekwencji: przykładowe wartości i terminy (17 dni po pierwszej)');

/* ------------------------------------------------------------------ */
UbrT::group('P10.5 — Skrzynki pracowników');

// Mock transportu: kolejka odpowiedzi; zapis „wysłanych” (nic nie wychodzi z kontenera).
$GLOBALS['ubrt_mail'] = ['queue' => [], 'sent' => [], 'tests' => []];
$mailMock = new class () implements MailTransport {
	public function send(OutgoingMessage $message, array $mailbox, ?string $secret): array
	{
		$next = array_shift($GLOBALS['ubrt_mail']['queue']) ?? ['status' => 'sent', 'response' => '250 2.0.0 OK queued as MOCK'];
		$GLOBALS['ubrt_mail']['sent'][] = ['to' => $message->toEmail, 'subject' => $message->subject, 'body' => $message->body, 'secret' => $secret, 'status' => $next['status'], 'message_id' => $message->messageId, 'headers' => $message->headers];

		return $next + ['response' => null, 'error' => null, 'bounce' => false];
	}

	public function test(array $mailbox, ?string $secret): array
	{
		$GLOBALS['ubrt_mail']['tests'][] = $secret;

		return $secret === 'dobre-haslo-aplikacji' ? ['ok' => true, 'status' => 'connected', 'message' => 'OK'] : ['ok' => false, 'status' => 'auth_failed', 'message' => 'Logowanie nie powiodło się.'];
	}
};
$mailFilter = fn ($t, $mailbox) => ($mailbox['provider'] ?? '') === 'smtp' && str_ends_with((string) $mailbox['host'], 'mock.ubrtest.pl') ? $mailMock : $t;
add_filter('ubr/mail/transport', $mailFilter, 10, 2);

$mbBad = ubrt_rest($uS1, 'POST', '/sequences/mailboxes', ['email' => 'anna@sender.ubrtest.pl', 'host' => 'smtp.mock.ubrtest.pl', 'port' => 587, 'password' => 'zle-haslo']);
UbrT::ok($mbBad['status'] === 201 && $mbBad['data']['status'] === 'untested' && $mbBad['data']['has_password'] === true && ! str_contains(wp_json_encode($mbBad['data']), 'zle-haslo'), '19. podłączenie skrzynki SMTP — hasło nie wraca w odpowiedzi');
$stored = (string) $db->value("SELECT secret_ciphertext FROM {$db->table(Tables::SEQ_MAILBOXES)} WHERE id = %d", $mbBad['data']['id']);
UbrT::ok($stored !== '' && ! str_contains($stored, 'zle-haslo'), '10/bezpieczeństwo. hasło zaszyfrowane w bazie (SecretBox)');
$t1 = ubrt_rest($uS1, 'POST', '/sequences/mailboxes/' . $mbBad['data']['id'] . '/test');
UbrT::ok($t1['data']['ok'] === false && $t1['data']['mailbox']['status'] === 'auth_failed', '20. niepoprawne dane logowania → status „Błędne dane logowania”');
$t2up = ubrt_rest($uS1, 'POST', '/sequences/mailboxes/' . $mbBad['data']['id'], ['password' => 'dobre-haslo-aplikacji']);
$t2 = ubrt_rest($uS1, 'POST', '/sequences/mailboxes/' . $mbBad['data']['id'] . '/test');
UbrT::ok($t2up['data']['status'] === 'untested' && $t2['data']['ok'] === true && $t2['data']['mailbox']['status'] === 'connected' && end($GLOBALS['ubrt_mail']['tests']) === 'dobre-haslo-aplikacji', '24. test połączenia po poprawie hasła → „Połączona” (bez wysyłania wiadomości)');
$mbS1 = $t2['data']['mailbox']['id'];
UbrT::eq(404, ubrt_rest($uS2, 'POST', '/sequences/mailboxes/' . $mbS1 . '/test')['status'], '22. brak dostępu do cudzej skrzynki (test)');
UbrT::eq(404, ubrt_rest($uS, 'POST', '/sequences/mailboxes/' . $mbS1, ['from_name' => 'Przejęta'])['status'], '22. admin też nie zmienia cudzej skrzynki');
UbrT::ok(! in_array($mbS1, array_column(ubrt_rest($uS2, 'GET', '/sequences/mailboxes')['data']['items'], 'id'), true), '22. lista skrzynek — tylko własne');
$mbTest = ubrt_rest($uS1, 'POST', '/sequences/mailboxes', ['provider' => 'test', 'email' => 'anna.test@sender.ubrtest.pl'])['data'];
$mbS2 = ubrt_rest($uS2, 'POST', '/sequences/mailboxes', ['email' => 'piotr@sender.ubrtest.pl', 'host' => 'smtp.mock.ubrtest.pl', 'port' => 465, 'password' => 'dobre-haslo-aplikacji'])['data'];
ubrt_rest($uS2, 'POST', '/sequences/mailboxes/' . $mbS2['id'] . '/test');
UbrT::eq('ssl', $mbS2['encryption'], 'port 465 → SSL');
UbrT::ok(ubrt_rest($uS1, 'POST', '/sequences/mailboxes', ['email' => 'x@sender.ubrtest.pl', 'host' => 'nie ma hosta', 'port' => 587, 'password' => 'a'])['status'] >= 400, 'walidacja serwera SMTP');
add_filter('ubr/mail/dns_txt', function ($v, $name) {
	return match ($name) {
		'sender.ubrtest.pl' => ['v=spf1 include:_spf.mock.ubrtest.pl ~all'],
		'_dmarc.sender.ubrtest.pl' => ['v=DMARC1; p=none'],
		'google._domainkey.sender.ubrtest.pl' => ['v=DKIM1; k=rsa; p=MIIB'],
		default => [],
	};
}, 10, 2);
$dns = ubrt_rest($uS1, 'POST', '/sequences/mailboxes/' . $mbS1 . '/dns')['data'];
UbrT::ok($dns['spf']['ok'] && $dns['dmarc']['ok'] && $dns['dkim']['selectors'] === ['google'] && str_contains($dns['disclaimer'], 'nie gwarantują'), '17. kontrola SPF / DKIM / DMARC (informacyjna, bez obietnicy dostarczalności)');

/* ------------------------------------------------------------------ */
UbrT::group('P10.5 — Przypisanie kampanii do firmy (niezależna kopia)');

$sS = $searches->create(['organization_id' => $orgS, 'user_id' => $uS, 'label' => 'ubrtest P10.5', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'completed']);
$sMk = function (string $key, string $name, array $fields = [], int $claimer = 0) use ($ingest, $orgS, $sS, $companiesRepo, $uS1) {
	$cid = $ingest->ingest(ubrt_candidate(['id' => 'p105-' . $key, 'name' => 'Ubrtest S ' . $name, 'website' => 'https://' . $key . '.p105.ubrtest.pl/']))['company_id'];
	app(ResultRepository::class)->add($sS, $orgS, $cid, 'ubrtest', 10, []);
	$companiesRepo->update($cid, $fields + ['verification_status' => 'verified', 'domain_status' => 'confirmed', 'verification_checked_at' => Db::now(), 'website_status' => 'reachable']);
	add_filter('ubr/claim/automation', '__return_false');
	ubrt_rest($claimer ?: $uS1, 'POST', '/companies/' . $cid . '/claim');
	remove_filter('ubr/claim/automation', '__return_false');

	return $cid;
};
$cA = $sMk('alfa', 'Alfa', ['general_email' => 'biuro@alfa.p105.ubrtest.pl']);
$cB = $sMk('beta', 'Beta', ['general_email' => 'biuro@alfa.p105.ubrtest.pl']); // ten sam adres w innej firmie
$noPerm = ubrt_rest($uS2, 'POST', '/companies/' . $cA . '/sequences', ['campaign_id' => $cmp['id'], 'recipient' => ['source' => 'company_general']]);
UbrT::eq(403, $noPerm['status'], 'uprawnienia: pracownik nie przypisze kampanii firmie innego pracownika');
$rec = ubrt_rest($uS1, 'GET', '/companies/' . $cA . '/sequence-recipients')['data'];
UbrT::ok(($rec['options'][0]['source'] ?? '') === 'company_general' && str_contains((string) $rec['options'][0]['note'], 'nie jest adresem decydenta'), '11/odbiorcy. ogólny e-mail firmy z wyraźnym zastrzeżeniem (nie decydent)');
$as = ubrt_rest($uS1, 'POST', '/companies/' . $cA . '/sequences', ['campaign_id' => $cmp['id'], 'recipient' => ['source' => 'company_general'], 'mailbox_id' => $mbS1]);
$seqA = $as['data'];
UbrT::ok($as['status'] === 201 && $seqA['status'] === 'draft' && $seqA['campaign']['id'] === $cmp['id'] && $seqA['campaign']['version'] === $act['data']['version'] && count($seqA['messages']) === 4 && $seqA['recipient']['email'] === 'biuro@alfa.p105.ubrtest.pl', '9. przypisanie kampanii → szkic (kopia 4 wiadomości, źródło: id i wersja szablonu)');
UbrT::eq(0, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND status <> 'pending'", $seqA['id']), '9. samo przypisanie niczego nie planuje ani nie wysyła');
UbrT::ok(str_contains($seqA['preview'][0]['subject'], 'Ubrtest S Alfa') && str_contains($seqA['preview'][0]['body'], 'alfa.p105.ubrtest.pl') && str_contains($seqA['preview'][0]['body'], 'Anna Testowa'), '8. personalizacja z danych Ugly but Rich (firma, strona, nadawca)');
$editSteps = array_map(fn ($m) => ['subject' => $m['subject_template'], 'body' => $m['body_template'], 'delay_days' => $m['delay_days'], 'is_active' => $m['is_active']], $seqA['messages']);
$editSteps[0]['subject'] = 'Strona {{company_name}} — propozycja tylko dla Alfa';
$editSteps[1]['body'] = "Treść drugiej wiadomości dla Alfa.\n{{sender_name}}";
$editSteps[2]['delay_days'] = 10;
array_pop($editSteps); // 15. usunięcie czwartej
$editSteps[] = ['subject' => 'Dodatkowa wiadomość dla Alfa', 'body' => 'P.S. {{sender_name}}', 'delay_days' => 5]; // 14. nowa
$up = ubrt_rest($uS1, 'POST', '/sequences/items/' . $seqA['id'], ['steps' => $editSteps, 'settings' => ['window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '00:00', 'to' => '23:59', 'timezone' => 'UTC']]]);
$msgs = $up['data']['messages'];
UbrT::ok($msgs[0]['subject_template'] === 'Strona {{company_name}} — propozycja tylko dla Alfa', '11. edycja tematu pierwszej wiadomości (tylko ta firma)');
UbrT::ok(str_contains($msgs[1]['body_template'], 'dla Alfa'), '12. edycja treści drugiej wiadomości');
UbrT::eq(10, $msgs[2]['delay_days'], '13. zmiana odstępu przed trzecią wiadomością');
UbrT::ok(count($msgs) === 4 && $msgs[3]['subject_template'] === 'Dodatkowa wiadomość dla Alfa', '14/15. usunięcie czwartej i dodanie nowej wiadomości');
$tpl = ubrt_rest($uS, 'GET', '/sequences/campaigns/' . $cmp['id'])['data'];
UbrT::ok($tpl['steps'][0]['subject'] === 'Nowa strona internetowa dla {{company_name}}' && count($tpl['steps']) === 4 && $tpl['steps'][2]['delay_days'] === 21 && $tpl['version'] === $act['data']['version'], '16. oryginalny szablon w bibliotece bez zmian');
$asB0 = ubrt_rest($uS1, 'POST', '/companies/' . $cB . '/sequences', ['campaign_id' => $cmp['id'], 'recipient' => ['source' => 'manual', 'email' => 'zarzad@beta.p105.ubrtest.pl', 'name' => 'Jan Nowak'], 'mailbox_id' => $mbS1])['data'];
UbrT::ok($asB0['messages'][0]['subject_template'] === 'Nowa strona internetowa dla {{company_name}}' && count($asB0['messages']) === 4, '16. kolejna firma dostaje oryginalną wersję z biblioteki');
ubrt_rest($uS1, 'DELETE', '/sequences/items/' . $asB0['id']);

/* ------------------------------------------------------------------ */
UbrT::group('P10.5 — Uruchomienie, harmonogram i worker (mock transportu)');

add_filter('ubr/sequences/sending_enabled', '__return_true', 50);
$seqWork = fn () => app(SequenceRunner::class)->tick(20, false);
$pastOnly = fn (int $sid, int $sec = 60) => $db->execute("UPDATE {$db->table(Tables::SEQ_MESSAGES)} SET scheduled_at = %s WHERE sequence_id = %d AND status = 'scheduled'", gmdate('Y-m-d H:i:s', time() - $sec), $sid);
// Termin w przeszłości + „postarzenie” historii wysyłek o 2 dni (limity skrzynek nie wpływają na kolejne przypadki).
$past = function (int $sid, int $sec = 60) use ($db, $pastOnly) {
	$db->execute("UPDATE {$db->table(Tables::SEQ_MESSAGES)} SET sent_at = DATE_SUB(sent_at, INTERVAL 2 DAY) WHERE sent_at IS NOT NULL AND sent_at > %s AND sequence_id <> %d", gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS), $sid);

	return $pastOnly($sid, $sec);
};
$noConfirm = ubrt_rest($uS1, 'POST', '/sequences/items/' . $seqA['id'] . '/start', []);
UbrT::ok($noConfirm['status'] >= 400 && str_contains((string) $noConfirm['data']['message'], 'Potwierdź'), '11/zatwierdzenie. bez świadomego potwierdzenia sekwencja nie startuje');
UbrT::eq(404, ubrt_rest($uS2, 'POST', '/sequences/items/' . $seqA['id'] . '/start', ['confirm' => true])['status'], 'uprawnienia: cudza sekwencja niedostępna (404)');
$st = ubrt_rest($uS1, 'POST', '/sequences/items/' . $seqA['id'] . '/start', ['confirm' => true]);
$sched = $db->row("SELECT * FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND status = 'scheduled'", $seqA['id']);
UbrT::ok($st['status'] === 200 && $st['data']['started'] === true && $st['data']['sequence']['status'] === 'scheduled' && (int) $sched['position'] === 1 && empty($GLOBALS['ubrt_mail']['sent']), '25. uruchomienie: pierwsza wiadomość zaplanowana (w żądaniu nic nie zostało wysłane)');
$GLOBALS['ubrt_mail']['sent'] = [];
$tickA = $seqWork();
$sent1 = $GLOBALS['ubrt_mail']['sent'][0] ?? null;
UbrT::ok($tickA['sent'] === 1 && $sent1 && $sent1['to'] === 'biuro@alfa.p105.ubrtest.pl' && $sent1['subject'] === 'Strona Ubrtest S Alfa — propozycja tylko dla Alfa' && $sent1['secret'] === 'dobre-haslo-aplikacji', '25. worker wysyła pierwszą wiadomość (spersonalizowany temat, skrzynka pracownika)');
UbrT::ok(str_contains($sent1['body'], 'Jeśli nie chcesz otrzymywać') && str_contains($sent1['body'], 'ubr_unsubscribe=') && str_contains($sent1['headers']['List-Unsubscribe'] ?? '', 'ubr_unsubscribe=') && ($sent1['headers']['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click', '43. stopka i nagłówki rezygnacji (List-Unsubscribe, one-click)');
UbrT::ok((bool) preg_match('/^<ubr\.\d+\.[a-f0-9]{12}@sender\.ubrtest\.pl>$/', $sent1['message_id']), '13/duplikaty. unikalny Message-ID zapisany przed wysyłką');
$row1 = $db->row("SELECT * FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 1", $seqA['id']);
$row2 = $db->row("SELECT * FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 2", $seqA['id']);
UbrT::ok($row1['status'] === 'sent' && $row1['sent_subject'] === $sent1['subject'] && $row1['message_id'] === $sent1['message_id'] && strlen((string) $row1['send_token']) === 32, '15/historia. zapisane: wysłana treść, Message-ID, identyfikator operacji');
UbrT::ok($row2['status'] === 'scheduled' && abs(strtotime($row2['scheduled_at'] . ' UTC') - (strtotime($row1['sent_at'] . ' UTC') + 17 * DAY_IN_SECONDS)) < 120, '26. kolejna wiadomość: +17 dni od FAKTYCZNEJ wysyłki poprzedniej');
UbrT::eq(0, $seqWork()['sent'], '26. przed terminem kolejnej wiadomości nic nie wychodzi');

// 17/18. Zmiana szablonu po przypisaniu — bez wpływu na aktywną sekwencję.
ubrt_rest($uS, 'POST', '/sequences/campaigns/' . $cmp['id'], ['steps' => [['subject' => 'ZMIENIONY SZABLON', 'body' => 'Nowa treść {{sender_name}}', 'delay_days' => 0]]]);
$afterTpl = ubrt_rest($uS1, 'GET', '/sequences/items/' . $seqA['id'])['data'];
UbrT::ok(ubrt_rest($uS, 'GET', '/sequences/campaigns/' . $cmp['id'])['data']['version'] === $act['data']['version'] + 1, '17. zmiana szablonu po przypisaniu do firmy → nowa wersja szablonu');
UbrT::ok($afterTpl['messages'][1]['subject_template'] === 'Kilka pomysłów dotyczących strony {{company_name}}' && count($afterTpl['messages']) === 4 && $afterTpl['campaign']['version'] === $act['data']['version'], '18. aktywna sekwencja bez zmian (kopia + zapamiętana wersja źródła)');

// 30. Pauza.
$past($seqA['id']);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $seqA['id'] . '/pause');
$GLOBALS['ubrt_mail']['sent'] = [];
UbrT::ok($seqWork()['sent'] === 0 && empty($GLOBALS['ubrt_mail']['sent']) && $db->value("SELECT status FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $seqA['id']) === 'paused', '30. wstrzymana sekwencja — brak kolejnych wysyłek');
// 31. Wznowienie po „długiej pauzie”: zaległe nie wychodzą hurtem — najwyżej jedna, kolejna liczona od jej wysyłki.
$db->execute("UPDATE {$db->table(Tables::SEQ_MESSAGES)} SET scheduled_at = %s WHERE sequence_id = %d AND status = 'scheduled'", gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS), $seqA['id']);
$db->execute("UPDATE {$db->table(Tables::SEQ_MESSAGES)} SET sent_at = %s WHERE sequence_id = %d AND position = 1", gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS), $seqA['id']);
$res = ubrt_rest($uS1, 'POST', '/sequences/items/' . $seqA['id'] . '/resume');
$schedNow = $db->row("SELECT position, scheduled_at FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND status = 'scheduled'", $seqA['id']);
UbrT::ok($res['data']['resumed'] === true && (int) $schedNow['position'] === 2 && strtotime($schedNow['scheduled_at'] . ' UTC') >= time() - 5, '31. wznowienie: zaplanowana tylko kolejna wiadomość, termin ≥ teraz');
$GLOBALS['ubrt_mail']['sent'] = [];
$tickR = $seqWork();
$row3 = $db->row("SELECT * FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 3", $seqA['id']);
UbrT::ok($tickR['sent'] === 1 && count($GLOBALS['ubrt_mail']['sent']) === 1 && $row3['status'] === 'scheduled' && strtotime($row3['scheduled_at'] . ' UTC') >= time() + 9 * DAY_IN_SECONDS, '33. zaległa wiadomość: wysłana JEDNA, następna za 10 dni od tej wysyłki (bez serii zaległych)');

// 34. Restart workera w trakcie wysyłki: wiadomość „sending” po czasie zajęcia → wynik nieznany, bez automatycznego ponowienia.
$past($seqA['id']);
$db->execute("UPDATE {$db->table(Tables::SEQ_MESSAGES)} SET status = 'sending', locked_until = %s, send_token = %s WHERE sequence_id = %d AND position = 3", gmdate('Y-m-d H:i:s', time() - 10), md5('ubrt-crash'), $seqA['id']);
$GLOBALS['ubrt_mail']['sent'] = [];
$tickC = $seqWork();
$row3 = $db->row("SELECT status FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 3", $seqA['id']);
$seqRow = $db->row("SELECT status, error_code FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $seqA['id']);
UbrT::ok($tickC['recovered'] === 1 && $row3['status'] === 'unknown_result' && $seqRow['status'] === 'error' && $seqRow['error_code'] === 'unknown_result' && empty($GLOBALS['ubrt_mail']['sent']), '34/37. przerwany proces wysyłki → „wynik nieznany”, sekwencja wymaga weryfikacji, brak ponownej wysyłki');
UbrT::ok(ubrt_rest($uS1, 'POST', '/sequences/items/' . $seqA['id'] . '/resume')['status'] >= 400, '37. wznowienie zablokowane do czasu potwierdzenia wyniku');
$msg3 = (int) $db->value("SELECT id FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 3", $seqA['id']);
$resolved = ubrt_rest($uS1, 'POST', '/sequences/messages/' . $msg3 . '/resolve', ['outcome' => 'sent']);
$row4 = $db->row("SELECT status FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 4", $seqA['id']);
UbrT::ok($resolved['data']['messages'][2]['status'] === 'sent' && $row4['status'] === 'scheduled' && $resolved['data']['status'] === 'paused', '37. pracownik potwierdza „wysłana” → historia + kolejny krok (wznowienie ręczne)');
ubrt_rest($uS1, 'POST', '/sequences/items/' . $seqA['id'] . '/resume');

// 32. Anulowanie: przyszłe wiadomości anulowane, historia zostaje, adres zwolniony.
$can = ubrt_rest($uS1, 'POST', '/sequences/items/' . $seqA['id'] . '/cancel');
$cnt = $db->row("SELECT SUM(status = 'sent') sent, SUM(status = 'cancelled') cancelled FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d", $seqA['id']);
UbrT::ok($can['data']['cancelled'] === true && (int) $cnt['sent'] === 3 && (int) $cnt['cancelled'] === 1 && $db->value("SELECT active_recipient FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $seqA['id']) === null, '32. anulowanie: przyszłe anulowane, 3 wysłane w historii, adres zwolniony');
UbrT::ok(ubrt_rest($uS1, 'POST', '/sequences/messages/' . $msg3, ['subject' => 'Zmiana po wysłaniu'])['status'] >= 400, 'wysłanej wiadomości nie można edytować');

/* ------------------------------------------------------------------ */
UbrT::group('P10.5 — Jedna aktywna sekwencja na odbiorcę, wykluczenia, limity');

$mkSeq = function (int $cid, array $recipient, int $mailbox, int $user = 0) use ($uS1, $cmp, $steps3) {
	$s = ubrt_rest($user ?: $uS1, 'POST', '/companies/' . $cid . '/sequences', ['campaign_id' => null, 'recipient' => $recipient, 'mailbox_id' => $mailbox])['data'];
	ubrt_rest($user ?: $uS1, 'POST', '/sequences/items/' . $s['id'], ['steps' => $steps3, 'settings' => ['window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '00:00', 'to' => '23:59', 'timezone' => 'UTC']]]);

	return $s['id'];
};
$x1 = $mkSeq($cA, ['source' => 'company_general'], $mbS1);
UbrT::eq(true, ubrt_rest($uS1, 'POST', '/sequences/items/' . $x1 . '/start', ['confirm' => true])['data']['started'], 'start sekwencji dla biuro@alfa…');
$x2 = $mkSeq($cB, ['source' => 'company_general'], $mbS1); // inna firma, ten sam adres
$conf = ubrt_rest($uS1, 'POST', '/sequences/items/' . $x2 . '/start', ['confirm' => true]);
UbrT::ok($conf['status'] === 409 && $conf['data']['started'] === false && $conf['data']['conflict']['id'] === $x1 && isset($conf['data']['options']['replace']), '39. duplikat odbiorcy (ten sam adres w innej firmie) → ostrzeżenie z opcjami');
$repl = ubrt_rest($uS1, 'POST', '/sequences/items/' . $x2 . '/start', ['confirm' => true, 'replace' => true]);
UbrT::ok($repl['data']['started'] === true && $db->value("SELECT status FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $x1) === 'cancelled', '39. „Zakończ obecną i uruchom nową” → poprzednia anulowana');
$dupKey = false;

try {
	$db->update(Tables::SEQ_SEQUENCES, ['active_recipient' => $orgS . '|biuro@alfa.p105.ubrtest.pl'], ['id' => $x1]);
} catch (\App\Ubr\Infrastructure\Database\DatabaseException $e) {
	$dupKey = $e->duplicate;
}

UbrT::ok($dupKey, '39/35. ograniczenie bazy (UNIQUE) uniemożliwia dwie aktywne sekwencje dla adresu — także przy wyścigu');
ubrt_rest($uS1, 'POST', '/sequences/items/' . $x2 . '/cancel');

// 40. Limit skrzynki: 1 wiadomość dziennie → kolejna odroczona (bez wysyłki).
$db->update(Tables::SEQ_MAILBOXES, ['limits' => wp_json_encode(['per_day' => 1, 'per_hour' => 8, 'min_interval_minutes' => 1])], ['id' => $mbS1]);
$l1 = $mkSeq($cA, ['source' => 'manual', 'email' => 'jan.kowalski@alfa.p105.ubrtest.pl'], $mbS1);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $l1 . '/start', ['confirm' => true]);
$pastOnly($l1);
$GLOBALS['ubrt_mail']['sent'] = [];
$tl = $seqWork();
$lMsg = $db->row("SELECT status, scheduled_at FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 1", $l1);
UbrT::ok($tl['deferred'] >= 1 && empty($GLOBALS['ubrt_mail']['sent']) && $lMsg['status'] === 'scheduled' && strtotime($lMsg['scheduled_at'] . ' UTC') > time() + 3600, '40. przekroczony limit dzienny skrzynki → wiadomość przesunięta (bez wysyłki)');
$db->update(Tables::SEQ_MAILBOXES, ['limits' => wp_json_encode(['per_day' => 30, 'per_hour' => 8, 'min_interval_minutes' => 1])], ['id' => $mbS1]);
$db->update(Tables::SEQ_MAILBOXES, ['last_sent_at' => null], ['id' => $mbS1]);

// 42. DO_NOT_CONTACT po uruchomieniu → worker anuluje przed wysyłką.
$prospectA = (int) $db->value("SELECT id FROM {$db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND company_id = %d", $orgS, $cA);
$db->execute("UPDATE {$db->table(Tables::SEQ_MESSAGES)} SET sent_at = %s WHERE mailbox_id = %d", gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS), $mbS1);
ubrt_rest($uS1, 'POST', '/prospects/' . $prospectA . '/do-not-contact', ['value' => true, 'reason' => 'test 10.5']);
$past($l1);
$GLOBALS['ubrt_mail']['sent'] = [];
$seqWork();
UbrT::ok(empty($GLOBALS['ubrt_mail']['sent']) && $db->value("SELECT status FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $l1) === 'cancelled', '42. DO_NOT_CONTACT → brak wysyłki, sekwencja zatrzymana');
$dncStart = ubrt_rest($uS1, 'POST', '/sequences/items/' . $mkSeq($cA, ['source' => 'manual', 'email' => 'inna@alfa.p105.ubrtest.pl'], $mbS1) . '/start', ['confirm' => true]);
UbrT::ok($dncStart['status'] === 422 && str_contains((string) $dncStart['data']['message'], 'DO_NOT_CONTACT'), '42. uruchomienie dla firmy z zakazem kontaktu odrzucone');
ubrt_rest($uS1, 'POST', '/prospects/' . $prospectA . '/do-not-contact', ['value' => false]);

// 41. BLOCKED (firma nieaktywna w rejestrze).
$cInactive = $sMk('nieaktywna', 'Nieaktywna', ['general_email' => 'biuro@nieaktywna.p105.ubrtest.pl']);
$companiesRepo->update($cInactive, ['verification_status' => 'inactive']);
$bl = ubrt_rest($uS1, 'POST', '/sequences/items/' . $mkSeq($cInactive, ['source' => 'company_general'], $mbS1) . '/start', ['confirm' => true]);
UbrT::ok($bl['status'] === 422 && str_contains((string) $bl['data']['message'], 'BLOCKED'), '41. firma BLOCKED → uruchomienie odrzucone');

// 44. Niepoprawny technicznie adres (domena bez MX).
$nm = ubrt_rest($uS1, 'POST', '/sequences/items/' . $mkSeq($cB, ['source' => 'manual', 'email' => 'jan@nomx.ubrtest.pl'], $mbS1) . '/start', ['confirm' => true]);
UbrT::ok($nm['status'] === 422 && str_contains((string) $nm['data']['message'], 'MX'), '44. domena bez rekordu MX → brak wysyłki');
UbrT::ok(ubrt_rest($uS1, 'POST', '/companies/' . $cB . '/sequences', ['recipient' => ['source' => 'manual', 'email' => 'zly adres@'], 'mailbox_id' => $mbS1])['status'] >= 400, '44. adres o złej składni odrzucony przy wyborze odbiorcy');

// 45. Brakująca zmienna: blokada startu; uzupełnienie ręczne → start; usunięcie danych po starcie → worker nie wysyła.
$v1 = ubrt_rest($uS1, 'POST', '/companies/' . $cB . '/sequences', ['recipient' => ['source' => 'manual', 'email' => 'kontakt@beta.p105.ubrtest.pl'], 'mailbox_id' => $mbS1])['data']['id'];
$vSave = ubrt_rest($uS1, 'POST', '/sequences/items/' . $v1, ['steps' => [['subject' => 'Pytanie do {{contact_first_name}}', 'body' => 'Dzień dobry {{contact_first_name}}, {{company_name}}.', 'delay_days' => 0]], 'settings' => ['window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '00:00', 'to' => '23:59', 'timezone' => 'UTC']]]);
UbrT::eq(['contact_first_name'], $vSave['data']['missing'], '45. ostrzeżenie o brakujących danych przed uruchomieniem');
$vStart = ubrt_rest($uS1, 'POST', '/sequences/items/' . $v1 . '/start', ['confirm' => true]);
UbrT::ok($vStart['status'] === 422 && str_contains((string) $vStart['data']['message'], '{{contact_first_name}}'), '45. brak danych → sekwencja nie startuje (nie zgadujemy imion)');
ubrt_rest($uS1, 'POST', '/sequences/items/' . $v1, ['variables' => ['contact_first_name' => 'Ewa']]);
UbrT::eq(true, ubrt_rest($uS1, 'POST', '/sequences/items/' . $v1 . '/start', ['confirm' => true])['data']['started'], '45. ręczne uzupełnienie wartości dla firmy → start');
$db->update(Tables::SEQ_SEQUENCES, ['variables' => wp_json_encode([])], ['id' => $v1]);
$past($v1);
$GLOBALS['ubrt_mail']['sent'] = [];
$seqWork();
UbrT::ok(empty($GLOBALS['ubrt_mail']['sent']) && $db->value("SELECT error_code FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $v1) === 'missing_variables', '45. worker nie wysyła wiadomości z brakującą wartością (sekwencja „wymaga uwagi”)');
ubrt_rest($uS1, 'POST', '/sequences/items/' . $v1 . '/cancel');

// 43. Rezygnacja odbiorcy przez link (token bez adresu w URL) — blokada adresu i anulowanie.
$u1 = $mkSeq($cB, ['source' => 'manual', 'email' => 'rezygnacja@beta.p105.ubrtest.pl'], $mbS1);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $u1 . '/start', ['confirm' => true]);
$unsub = app(UnsubscribeService::class);
$url = $unsub->url($orgS, $u1);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
UbrT::ok(! str_contains($url, 'rezygnacja') && $unsub->verify($q['ubr_unsubscribe']) !== null && $unsub->verify(preg_replace('/.$/', '0', $q['ubr_unsubscribe']) === $q['ubr_unsubscribe'] ? $q['ubr_unsubscribe'] . 'x' : preg_replace('/.$/', '0', $q['ubr_unsubscribe'])) === null && $unsub->verify($orgS . '.' . $u1 . '.' . str_repeat('a', 32)) === null, '43. link rezygnacji podpisany (HMAC), bez adresu e-mail w URL; podrobiony token odrzucony');
$cancelled = app(SequenceService::class)->suppress($orgS, 'rezygnacja@beta.p105.ubrtest.pl', 'unsubscribed', null, $u1, 'test');
UbrT::ok($cancelled === 1 && $db->value("SELECT status FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $u1) === 'cancelled', '43. rezygnacja → aktywna sekwencja anulowana');
$again = ubrt_rest($uS1, 'POST', '/sequences/items/' . $mkSeq($cB, ['source' => 'manual', 'email' => 'rezygnacja@beta.p105.ubrtest.pl'], $mbS1) . '/start', ['confirm' => true]);
UbrT::ok($again['status'] === 422 && str_contains((string) $again['data']['message'], 'Rezygnacja'), '43. adres po rezygnacji trwale zablokowany (nowa sekwencja odrzucona)');
UbrT::eq(403, ubrt_rest($uS1, 'DELETE', '/sequences/suppressions', ['email' => 'rezygnacja@beta.p105.ubrtest.pl'])['status'], 'odblokowanie adresu tylko owner/admin');

/* ------------------------------------------------------------------ */
UbrT::group('P10.5 — Bezpieczeństwo wysyłki: worker, ponowienia, nieznany wynik, wyłączona wysyłka');

$w1 = $mkSeq($cB, ['source' => 'manual', 'email' => 'worker@beta.p105.ubrtest.pl'], $mbS1);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w1 . '/start', ['confirm' => true]);
$past($w1);
// 35. Podwójne uruchomienie workera: drugi proces (osobne połączenie z bazą) trzyma blokadę → ten krok nic nie robi.
$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$lockKey = substr(md5(DB_NAME . '|' . $wpdb->prefix) . ':' . SequenceRunner::LOCK, 0, 64);
$other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lockKey));
$GLOBALS['ubrt_mail']['sent'] = [];
$blocked = $seqWork();
$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lockKey));
$other->close();
UbrT::ok($blocked['processed'] === 0 && empty($GLOBALS['ubrt_mail']['sent']), '35. drugi worker (blokada zajęta przez inny proces) nie wysyła');
// Atomowe zajęcie: wiadomość już „sending” nie zostanie zajęta drugi raz.
$GLOBALS['ubrt_mail']['queue'] = [['status' => 'retry', 'response' => '451 4.3.0 Temporary failure', 'error' => 'Serwer odrzucił adres odbiorcy.']];
$GLOBALS['ubrt_mail']['sent'] = [];
$tr = $seqWork();
$wMsg = $db->row("SELECT status, scheduled_at, attempts, send_token, error FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 1", $w1);
UbrT::ok($tr['deferred'] === 1 && $wMsg['status'] === 'scheduled' && (int) $wMsg['attempts'] === 1 && $wMsg['send_token'] === null && strtotime($wMsg['scheduled_at'] . ' UTC') >= time() + 840 && str_contains((string) $wMsg['error'], 'Ponowienie 1/3'), '38. chwilowy błąd PRZED przekazaniem treści → bezpieczne ponowienie za 15 min (nowy identyfikator operacji)');
for ($i = 0; $i < 3; $i++) {
	$GLOBALS['ubrt_mail']['queue'] = [['status' => 'retry', 'response' => '451 4.3.0', 'error' => 'Chwilowy błąd.']];
	$past($w1);
	$seqWork();
}
$wMsg = $db->row("SELECT status, attempts FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 1", $w1);
UbrT::ok($wMsg['status'] === 'failed' && (int) $wMsg['attempts'] === 4 && $db->value("SELECT error_code FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $w1) === 'failed', '38. po 3 ponowieniach — błąd (bez nieskończonych prób)');
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w1 . '/cancel');

// 37. Wynik nieznany zgłoszony przez transport → bez automatycznego ponowienia; „nie wyszła” → ponowna wysyłka.
$w2 = $mkSeq($cB, ['source' => 'manual', 'email' => 'nieznany@beta.p105.ubrtest.pl'], $mbS1);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w2 . '/start', ['confirm' => true]);
$past($w2);
$GLOBALS['ubrt_mail']['queue'] = [['status' => 'unknown', 'response' => null, 'error' => 'Brak potwierdzenia serwera po przekazaniu treści.']];
$tu = $seqWork();
$past($w2);
$GLOBALS['ubrt_mail']['sent'] = [];
$seqWork();
$u2 = $db->row("SELECT id, status FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 1", $w2);
UbrT::ok($tu['unknown'] === 1 && $u2['status'] === 'unknown_result' && empty($GLOBALS['ubrt_mail']['sent']), '37. wynik nieznany → status „Wynik nieznany”, brak automatycznego ponowienia');
ubrt_rest($uS1, 'POST', '/sequences/messages/' . $u2['id'] . '/resolve', ['outcome' => 'not_sent']);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w2 . '/resume');
$past($w2);
$GLOBALS['ubrt_mail']['sent'] = [];
$seqWork();
UbrT::ok(count($GLOBALS['ubrt_mail']['sent']) === 1 && $db->value("SELECT status FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE id = %d", $u2['id']) === 'sent', '37. „Nie wyszła — wyślij ponownie” → jedna ponowna wysyłka po wznowieniu');
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w2 . '/cancel');

// Trwałe odrzucenie adresu (550 5.1.1) → adres zablokowany (bounce), sekwencja zatrzymana.
$w3 = $mkSeq($cB, ['source' => 'manual', 'email' => 'nieistnieje@beta.p105.ubrtest.pl'], $mbS1);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w3 . '/start', ['confirm' => true]);
$past($w3);
$GLOBALS['ubrt_mail']['queue'] = [['status' => 'failed', 'response' => '550 5.1.1 User unknown', 'error' => 'Serwer odrzucił adres odbiorcy.', 'bounce' => true]];
$seqWork();
UbrT::ok($db->value("SELECT reason FROM {$db->table(Tables::SEQ_SUPPRESSIONS)} WHERE organization_id = %d AND email = %s", $orgS, 'nieistnieje@beta.p105.ubrtest.pl') === 'bounced' && $db->value("SELECT status FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $w3) === 'cancelled', '18/trwały błąd. odrzucony adres → blokada (trwały błąd dostarczenia)');

// 23. „Wygasły token” — hasła nie da się odszyfrować (zmiana klucza) → skrzynka wymaga ponownego podania hasła.
$w4 = $mkSeq($cB, ['source' => 'manual', 'email' => 'token@beta.p105.ubrtest.pl'], $mbS1);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w4 . '/start', ['confirm' => true]);
$origSecret = $db->value("SELECT secret_ciphertext FROM {$db->table(Tables::SEQ_MAILBOXES)} WHERE id = %d", $mbS1);
$db->update(Tables::SEQ_MAILBOXES, ['secret_ciphertext' => 'v1:uszkodzone'], ['id' => $mbS1]);
$past($w4);
$GLOBALS['ubrt_mail']['sent'] = [];
$seqWork();
UbrT::ok(empty($GLOBALS['ubrt_mail']['sent']) && $db->value("SELECT status FROM {$db->table(Tables::SEQ_MAILBOXES)} WHERE id = %d", $mbS1) === 'reconnect' && $db->value("SELECT status FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 1", $w4) === 'scheduled', '23. nieczytelne / wygasłe dane logowania → „Wymaga ponownego podania hasła”, wiadomość czeka (nie wysłana)');
$db->update(Tables::SEQ_MAILBOXES, ['secret_ciphertext' => $origSecret, 'status' => 'connected'], ['id' => $mbS1]);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w4 . '/cancel');

// Wyłączona wysyłka (domyślnie): prawdziwe sekwencje czekają, testowe są przetwarzane (nic nie wychodzi).
remove_filter('ubr/sequences/sending_enabled', '__return_true', 50);
$w5 = $mkSeq($cB, ['source' => 'manual', 'email' => 'wylaczona@beta.p105.ubrtest.pl'], $mbS1);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w5 . '/start', ['confirm' => true]);
$past($w5);
$GLOBALS['ubrt_mail']['sent'] = [];
$seqWork();
UbrT::ok(empty($GLOBALS['ubrt_mail']['sent']) && $db->value("SELECT status FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 1", $w5) === 'scheduled', '21/30. automatyczna wysyłka domyślnie WYŁĄCZONA — zaplanowana wiadomość czeka');
ubrt_rest($uS1, 'POST', '/sequences/items/' . $w5 . '/cancel');
$tm = $mkSeq($cB, ['source' => 'manual', 'email' => 'testowy@beta.p105.ubrtest.pl'], $mbTest['id']);
$tmStart = ubrt_rest($uS1, 'POST', '/sequences/items/' . $tm . '/start', ['confirm' => true, 'test_mode' => true]);
$past($tm);
$GLOBALS['ubrt_mail']['sent'] = [];
$seqWork();
$tmRow = $db->row("SELECT status, transport, smtp_response FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE sequence_id = %d AND position = 1", $tm);
UbrT::ok($tmStart['data']['started'] && empty($GLOBALS['ubrt_mail']['sent']) && $tmRow['status'] === 'sent' && $tmRow['transport'] === 'test' && str_contains((string) $tmRow['smtp_response'], 'TRYB TESTOWY'), '28/E2E. tryb testowy: harmonogram przetworzony, wiadomość NIE wyszła (transport testowy)');
UbrT::ok(ubrt_rest($uS1, 'POST', '/sequences/items/' . $mkSeq($cB, ['source' => 'manual', 'email' => 'zly@beta.p105.ubrtest.pl'], $mbTest['id']) . '/start', ['confirm' => true])['status'] === 422, 'skrzynka testowa nie wyśle prawdziwej sekwencji');

// Ustawienia: włączenie wysyłki tylko po liście kontrolnej i tylko przez owner/admin.
UbrT::eq(403, ubrt_rest($uS1, 'POST', '/sequences/settings', ['sending_enabled' => true])['status'], 'ustawienia modułu — tylko owner/admin');
$en = ubrt_rest($uS, 'POST', '/sequences/settings', ['sending_enabled' => true]);
UbrT::ok($en['status'] === 422 && str_contains((string) $en['data']['message'], 'Potwierdzone zabezpieczenia'), 'włączenie wysyłki zablokowane bez spełnionej listy kontrolnej');
$ck = array_column(ubrt_rest($uS, 'GET', '/sequences/settings')['data']['checklist'], 'ok', 'key');
UbrT::ok($ck['mailbox'] === true && $ck['schedule'] === true && $ck['worker'] === true && $ck['duplicates'] === false, 'lista kontrolna: skrzynka przetestowana, harmonogram w trybie testowym, worker działa; brak potwierdzenia duplikatów');
$en2 = ubrt_rest($uS, 'POST', '/sequences/settings', ['sending_enabled' => true, 'confirm_duplicates' => true]);
UbrT::ok($en2['status'] === 200 && $en2['data']['settings']['sending_enabled'] === true, 'po spełnieniu listy kontrolnej owner włącza automatyczną wysyłkę');
ubrt_rest($uS, 'POST', '/sequences/settings', ['sending_enabled' => false]);
UbrT::eq(false, ubrt_rest($uS, 'GET', '/sequences/settings')['data']['settings']['sending_enabled'], 'wyłączenie wysyłki zawsze możliwe');

// Listy i uprawnienia: pracownik widzi swoje, admin — zespół; otwarcie list nic nie wysyła.
$cPiotr = $sMk('gamma', 'Gamma Piotra', ['general_email' => 'biuro@gamma.p105.ubrtest.pl'], $uS2);
$mbS2Seq = $mkSeq($cPiotr, ['source' => 'company_general'], $mbS2['id'], $uS2);
$GLOBALS['ubrt_mail']['sent'] = [];
$la = ubrt_rest($uS1, 'GET', '/sequences/items', ['status' => 'all', 'per_page' => 100])['data'];
$lb = ubrt_rest($uS, 'GET', '/sequences/items', ['status' => 'all', 'per_page' => 100])['data'];
ubrt_rest($uS1, 'GET', '/sequences/scheduled');
ubrt_rest($uS1, 'GET', '/sequences/sent', ['page' => 2]);
UbrT::ok(! in_array(0, array_map(fn ($s) => (int) ($s['owner']['id'] === $uS1), $la['items']), true) && $lb['total'] > $la['total'] && empty($GLOBALS['ubrt_mail']['sent']), '26/uprawnienia. pracownik widzi własne sekwencje, admin — zespół; listy niczego nie wysyłają');
UbrT::eq(404, ubrt_rest($uS1, 'GET', '/sequences/items/' . $mbS2Seq)['status'], 'uprawnienia: sekwencja innego pracownika — 404');
$sentList = ubrt_rest($uS1, 'GET', '/sequences/sent', ['per_page' => 100])['data'];
UbrT::ok($sentList['total'] >= 4 && ! empty($sentList['items'][0]['message_id']) && isset($sentList['items'][0]['smtp_response']), '15. historia wysyłki: nadawca, odbiorca, temat, Message-ID, status przekazania');
UbrT::eq(501, ubrt_rest($uS1, 'POST', '/sequences/messages/' . $msg3 . '/ai-suggest', [])['status'], 'AI Outreach Assistant przygotowany, domyślnie wyłączony (bez płatnych wywołań)');

// 21. Odłączenie skrzynki: hasło usunięte, sekwencje wstrzymane.
$d1 = $mkSeq($cB, ['source' => 'manual', 'email' => 'odlaczenie@beta.p105.ubrtest.pl'], $mbS1);
ubrt_rest($uS1, 'POST', '/sequences/items/' . $d1 . '/start', ['confirm' => true]);
$disc = ubrt_rest($uS1, 'DELETE', '/sequences/mailboxes/' . $mbS1);
$mbRow = $db->row("SELECT status, secret_ciphertext FROM {$db->table(Tables::SEQ_MAILBOXES)} WHERE id = %d", $mbS1);
UbrT::ok($disc['data']['disconnected'] && $disc['data']['paused_sequences'] >= 1 && $mbRow['status'] === 'disabled' && $mbRow['secret_ciphertext'] === null && $db->value("SELECT status FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d", $d1) === 'paused', '21. odłączenie skrzynki: dane logowania usunięte, sekwencje wstrzymane, historia zachowana');
remove_filter('ubr/mail/transport', $mailFilter, 10);

/* ------------------------------------------------------------------ */
UbrT::group('P10.5 — Lokalny testowy serwer SMTP (prawdziwe połączenie, 127.0.0.1)');

$port = 25000 + wp_rand(100, 900);
$modeFile = sys_get_temp_dir() . '/ubrt_smtp_mode_' . $port;
$outFile = sys_get_temp_dir() . '/ubrt_smtp_out_' . $port . '.jsonl';
file_put_contents($modeFile, 'ok');
@unlink($outFile);
$proc = proc_open(['python3', __DIR__ . '/tools/fake_smtp.py', (string) $port, $modeFile, $outFile], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
usleep(400000);
add_filter('ubr/mail/smtp_timeouts', fn () => [3, 2]);
$smtpBox = ['provider' => 'smtp', 'email' => 'anna@sender.ubrtest.pl', 'host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'anna@sender.ubrtest.pl'];
$msg = fn (string $to = 'odbiorca@beta.p105.ubrtest.pl') => new OutgoingMessage('anna@sender.ubrtest.pl', 'Anna Testowa', $to, 'Jan Nowak', 'Temat ąę — test', "Treść ąę\n.linia z kropką\nKoniec", '<ubr.1.abc@sender.ubrtest.pl>', ['List-Unsubscribe' => '<https://example.test/u>']);
$smtp = new SmtpTransport();
$ok = $smtp->send($msg(), $smtpBox, 'haslo');
$got = json_decode((string) (file($outFile)[0] ?? ''), true);
UbrT::ok($ok['status'] === 'sent' && str_contains((string) $ok['response'], 'queued as TEST') && $got['to'] === ['odbiorca@beta.p105.ubrtest.pl'] && $got['user'] === 'anna@sender.ubrtest.pl' && str_contains($got['data'], 'Message-ID: <ubr.1.abc@sender.ubrtest.pl>') && str_contains($got['data'], 'List-Unsubscribe:') && str_contains($got['data'], 'text/plain'), 'SMTP: wiadomość przyjęta przez serwer (250), Message-ID i List-Unsubscribe w nagłówkach, tekst UTF-8');
UbrT::ok($smtp->test($smtpBox, 'haslo')['ok'] === true && count(file($outFile)) === 1, '24. SMTP: test połączenia loguje się bez wysyłania wiadomości');
file_put_contents($modeFile, 'auth_fail');
UbrT::eq('auth', $smtp->send($msg(), $smtpBox, 'zle')['status'], '20. SMTP: 535 → błędne dane logowania (nie wysłano)');
file_put_contents($modeFile, 'reject_rcpt');
$rr = $smtp->send($msg(), $smtpBox, 'haslo');
UbrT::ok($rr['status'] === 'failed' && $rr['bounce'] === true, 'SMTP: 550 5.1.1 przy RCPT → trwały błąd (adres do zablokowania)');
file_put_contents($modeFile, 'temp_rcpt');
UbrT::eq('retry', $smtp->send($msg(), $smtpBox, 'haslo')['status'], '38. SMTP: 451 przed treścią → bezpieczne ponowienie');
file_put_contents($modeFile, 'reject_after_data');
UbrT::eq('failed', $smtp->send($msg(), $smtpBox, 'haslo')['status'], 'SMTP: 554 po treści → jawne odrzucenie (nie „nieznany”)');
file_put_contents($modeFile, 'timeout_after_data');
$t0 = microtime(true);
$to = $smtp->send($msg(), $smtpBox, 'haslo');
UbrT::ok($to['status'] === 'unknown' && microtime(true) - $t0 < 15, '36. SMTP: brak odpowiedzi po przekazaniu treści (timeout) → wynik NIEZNANY (bez automatycznego ponowienia)');
UbrT::eq('retry', $smtp->send($msg(), ['port' => $port + 1] + $smtpBox, 'haslo')['status'], 'SMTP: serwer niedostępny (połączenie odrzucone) → bezpieczne ponowienie');
proc_terminate($proc);
proc_close($proc);
@unlink($modeFile);
@unlink($outFile);
