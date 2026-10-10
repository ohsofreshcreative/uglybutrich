// Test: wartości datetime-local wysyłane z offsetem strefy przeglądarki (audyt QA, Prompt 7).
// Uruchom w dwóch strefach: TZ=Europe/Warsaw node tests/js/datetime.test.mjs oraz TZ=UTC node tests/js/datetime.test.mjs
import assert from 'node:assert/strict';
import { isoFromLocalInput, localInput } from '../../resources/js/ubr/pipeline.js';

const now = new Date();
const local = localInput(now); // „teraz” w polu formularza (czas lokalny przeglądarki, bez strefy)
const iso = isoFromLocalInput(local);
assert.ok(/Z$/.test(iso), 'wynik w UTC (ISO z oznaczeniem strefy)');
assert.ok(Math.abs(new Date(iso).getTime() - now.getTime()) < 61000, '„teraz” z formularza = bieżąca chwila (bez przesunięcia o strefę)');
assert.equal(isoFromLocalInput(''), null);
assert.equal(isoFromLocalInput(null), null);
assert.equal(isoFromLocalInput('2026-10-14T10:00+02:00'), new Date('2026-10-14T10:00+02:00').toISOString(), 'wartość z offsetem zachowuje chwilę');
console.log(`datetime (${Intl.DateTimeFormat().resolvedOptions().timeZone}): 5/5 OK`);
