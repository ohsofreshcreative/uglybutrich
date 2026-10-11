/**
 * Testy Cold Calling (Prompt 10): bezpieczny href tel:, numer do schowka, liczba aktywnych filtrów.
 * Uruchomienie: node tests/js/calling.test.mjs
 */
import { safeTel, clipboardNumber, activeCount, CALL_FILTERS } from '../../resources/js/ubr/calling.js';

let pass = 0;
let fail = 0;
const ok = (cond, msg) => {
  if (cond) pass++;
  else fail++;
  console.log(`${cond ? '✓' : '✗'} ${msg}`);
};

ok(safeTel('tel:+48123456789') === 'tel:+48123456789', 'tel:+48… dozwolony');
ok(safeTel('tel:+48221234567;ext=12') === 'tel:+48221234567;ext=12', 'numer wewnętrzny (RFC 3966) dozwolony');
for (const bad of ['javascript:alert(1)', 'tel:123', 'tel:+48 12 345', 'tel:+48123456789"onclick="x', 'sms:+48123456789', 'http://x.pl', '', null, undefined, 42, 'tel:+48123456789\njavascript:x']) {
  ok(safeTel(bad) === null, `odrzucony href: ${JSON.stringify(bad)}`);
}
ok(clipboardNumber({ number: '12 345 67 89', e164: '+48123456789' }) === '+48123456789', 'schowek: format E.164');
ok(clipboardNumber({ number: '22 123 45 67 wew. 12', e164: '+48221234567' }) === '22 123 45 67 wew. 12', 'schowek: numer wewnętrzny w zapisie czytelnym');
ok(clipboardNumber(null) === '', 'schowek: brak numeru');
ok(activeCount(CALL_FILTERS()) === 0, 'domyślne filtry: brak aktywnych');
ok(activeCount({ ...CALL_FILTERS(), phone: 'any', free_with_phone: '1' }) === 2, 'liczba aktywnych filtrów');

console.log(`\n${pass}/${pass + fail} testów zaliczonych`);
process.exit(fail ? 1 : 0);
