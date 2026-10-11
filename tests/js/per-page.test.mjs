/**
 * Testy wyboru liczby wyników na stronie (Discover / Prospects): dozwolone wartości, domyślne 25,
 * zapamiętywanie osobno dla list, odporność na niedostępny storage.
 * Uruchomienie: node tests/js/per-page.test.mjs
 */
import { PER_PAGE_OPTIONS, PER_PAGE_DEFAULT, normalizePerPage, storedPerPage, rememberPerPage } from '../../resources/js/ubr/per-page.js';

let pass = 0;
let fail = 0;
const ok = (cond, msg) => {
  if (cond) pass++;
  else fail++;
  console.log(`${cond ? '✓' : '✗'} ${msg}`);
};

const memory = () => {
  const data = {};
  return { getItem: (k) => (k in data ? data[k] : null), setItem: (k, v) => { data[k] = String(v); }, data };
};

ok(JSON.stringify(PER_PAGE_OPTIONS) === '[10,25,50,100]', 'opcje: 10, 25, 50, 100');
ok(PER_PAGE_DEFAULT === 25, 'domyślnie 25');
for (const v of [10, 25, 50, 100, '50', '100']) ok(normalizePerPage(v) === Number(v), `dozwolona wartość ${JSON.stringify(v)}`);
for (const v of [0, 5, 26, 101, 1000, -10, 'abc', '', null, undefined, '25; DROP', NaN]) ok(normalizePerPage(v) === 25, `niedozwolona wartość ${JSON.stringify(v)} → 25`);

const s = memory();
ok(storedPerPage('discover', s) === 25, 'brak zapisu → 25');
ok(rememberPerPage('discover', '50', s) === 50, 'zapis Discover = 50');
ok(storedPerPage('discover', s) === 50, 'odczyt Discover = 50');
ok(storedPerPage('prospects', s) === 25, 'Prospects niezależne od Discover');
rememberPerPage('prospects', 100, s);
ok(storedPerPage('prospects', s) === 100 && storedPerPage('discover', s) === 50, 'osobne klucze dla list');
ok(s.data.ubr_per_page_discover === '50' && s.data.ubr_per_page_prospects === '100', 'klucze ubr_per_page_discover / ubr_per_page_prospects');
s.setItem('ubr_per_page_discover', '9999');
ok(storedPerPage('discover', s) === 25, 'zmanipulowana wartość w storage → 25');
ok(rememberPerPage('discover', 7, s) === 25 && s.data.ubr_per_page_discover === '25', 'zapis niedozwolonej wartości normalizowany do 25');

const broken = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } };
ok(storedPerPage('discover', broken) === 25, 'zablokowany storage: odczyt → 25');
ok(rememberPerPage('discover', 100, broken) === 100, 'zablokowany storage: zmiana działa w pamięci');
ok(storedPerPage('discover', null) === 25 && rememberPerPage('prospects', 10, null) === 10, 'brak storage (null)');

console.log(`\n${pass}/${pass + fail} testów zaliczonych`);
process.exit(fail ? 1 : 0);
