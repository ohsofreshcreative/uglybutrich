/**
 * Testy Prompt 10.4.3 — komunikat po „Przejmij firmę” (co uruchomiono w tle), tony statusów decydenta / ClickUp,
 * odpytywanie statusu karty tylko w trakcie pracy w tle.
 * Uruchomienie: node tests/js/claim.test.mjs
 */
let pass = 0;
let fail = 0;
const ok = (cond, msg) => {
  if (cond) pass++;
  else fail++;
  console.log(`${cond ? '✓' : '✗'} ${msg}`);
};

const calls = [];
let nextResponse = null;
globalThis.window = globalThis.window || { UBR: { restUrl: 'http://ubr.test/wp-json/ubr/v1', nonce: 'x' } };
globalThis.fetch = async (url, opts = {}) => {
  calls.push({ url: String(url), method: opts.method || 'GET' });
  const body = typeof nextResponse === 'function' ? nextResponse(url) : nextResponse;
  return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => body, text: async () => JSON.stringify(body) };
};

const { automationMessage, claimStore, CLAIM_TONES, CLICKUP_TONES } = await import('../../resources/js/ubr/claim.js');

ok(automationMessage({}) === '', 'brak automatyzacji → pusty komunikat');
ok(automationMessage({ automation: { research: { queued: true }, clickup: { status: 'queued' } } }) === 'szukamy decydenta w tle · zadanie ClickUp w przygotowaniu', 'analiza w tle + zadanie ClickUp');
ok(automationMessage({ automation: { research: { queued: false, reason: 'blocked' } } }).includes('zakaz kontaktu'), 'zakaz kontaktu / BLOCKED → bez analizy kontaktów');
ok(automationMessage({ automation: { research: { queued: false, reason: 'fresh' } } }) === 'decydent z zapisanej analizy', 'aktualna analiza → bez ponownego uruchamiania');
ok(automationMessage({ automation: { research: { queued: false, reason: 'limit', message: 'Dzienny limit' } } }) === 'analiza decydenta: Dzienny limit', 'nieznany powód → komunikat serwera');
ok(automationMessage({ automation: { clickup: { status: 'needs_config' } } }) === 'ClickUp wymaga konfiguracji', 'ClickUp bez konfiguracji');
ok(automationMessage({ automation: { research: { queued: true }, clickup: { status: 'disabled' } } }) === 'szukamy decydenta w tle · bez ClickUp', 'zadania ClickUp po przejęciu wyłączone');
ok(automationMessage({ automation: { clickup: { status: 'linked' } } }).includes('zaktualizowane'), 'istniejące zadanie → aktualizacja, nie nowe');
ok(automationMessage({ automation: { clickup: { status: 'error' } } }).includes('błąd ClickUp'), 'błąd ClickUp nie cofa przejęcia (komunikat)');

for (const key of ['pending', 'people', 'found', 'phones', 'direct', 'no_phone', 'none', 'review', 'failed', 'blocked', 'not_started']) {
  ok(typeof CLAIM_TONES[key] === 'string', `ton statusu decydenta: ${key}`);
}
for (const key of ['needs_config', 'disabled', 'none', 'queued', 'running', 'added', 'updated', 'error']) {
  ok(typeof CLICKUP_TONES[key] === 'string', `ton statusu ClickUp: ${key}`);
}

const store = Object.assign(claimStore(), { drawer: { open: true, company: { id: 7 }, claim: null, claimBusy: false, ageBusy: false }, toasts: [], toast(m, t) { this.toasts.push([m, t]); }, error(e) { this.toasts.push([e.message, 'error']); } });
ok(store.claimTone('nieznany') === 'muted' && store.clickupTone('added') === 'good', 'nieznany status → neutralny ton');

const realSetTimeout = globalThis.setTimeout;
const timers = [];
globalThis.setTimeout = (fn, ms) => { timers.push(ms); return timers.length; };
globalThis.clearTimeout = () => {};

nextResponse = { research: { key: 'done', active: false }, clickup: { key: 'added' } };
await store.loadClaim(7);
ok(store.drawer.claim.clickup.key === 'added' && timers.length === 0, 'status zakończony → bez odpytywania');

nextResponse = { research: { key: 'people', active: true }, clickup: { key: 'added' } };
await store.loadClaim(7);
ok(timers.length === 1 && timers[0] === 5000, 'analiza w toku → odpytywanie co 5 s');

timers.length = 0;
store.drawer.open = false;
calls.length = 0;
await store.loadClaim(7);
ok(calls.length === 0 && timers.length === 0, 'zamknięta karta → brak zapytań');

store.drawer.open = true;
store.drawer.company = { id: 8 };
nextResponse = { research: { key: 'people', active: true } };
await store.loadClaim(7);
ok(store.drawer.claim.clickup.key === 'added', 'odpowiedź dla innej (poprzedniej) firmy ignorowana');

calls.length = 0;
nextResponse = { queued: true, message: 'Zlecono', status: { key: 'pending' } };
store.drawer.claim = { research: { key: 'none' } };
await store.retryClaimResearch();
ok(calls.some((c) => c.method === 'POST' && c.url.includes('/companies/8/claim-research')) && store.drawer.claim.research.key === 'pending', '„Ponów wyszukiwanie decydenta” → POST + status oczekujący');

store.drawer.claimBusy = true;
calls.length = 0;
await store.retryClaimResearch();
ok(calls.length === 0, 'ponowienie w toku → bez drugiego zapytania (podwójne kliknięcie)');

globalThis.setTimeout = realSetTimeout;
console.log(`\n${pass}/${pass + fail} testów zaliczonych`);
process.exit(fail ? 1 : 0);
