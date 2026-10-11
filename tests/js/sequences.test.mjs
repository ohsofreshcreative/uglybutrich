/**
 * Testy Prompt 10.5 — edytor sekwencji (dodawanie, kolejność, duplikowanie, usuwanie kroków, walidacja, zmienne)
 * i etykiety okna wysyłki. Uruchomienie: node tests/js/sequences.test.mjs
 */
import { newStep, moveStep, duplicateStep, removeStep, totalDays, placeholders, validateSteps, cleanSteps, windowLabel, insertAt, MAX_STEPS, SEQ_TONES, MSG_TONES } from '../../resources/js/ubr/sequences.js';

let pass = 0;
let fail = 0;
const ok = (cond, msg) => {
  if (cond) pass++;
  else fail++;
  console.log(`${cond ? '✓' : '✗'} ${msg}`);
};

const s = (subject, delay = 3, active = true) => ({ subject, body: 'Treść ' + subject, delay_days: delay, is_active: active });
const steps = [s('A', 0), s('B', 17), s('C', 21), s('D', 14)];

ok(newStep().delay_days === 0 && newStep(steps[0]).delay_days === 3 && newStep().is_active === true, 'nowy krok: pierwszy po uruchomieniu (0 dni), kolejne domyślnie 3 dni');
ok(moveStep(steps, 1, -1).map((x) => x.subject).join('') === 'BACD', 'zmiana kolejności w górę');
ok(moveStep(steps, 3, 1) === steps && moveStep(steps, 0, -1) === steps, 'krańcowe przesunięcie bez zmian');
ok(steps.map((x) => x.subject).join('') === 'ABCD', 'oryginalna lista nie jest modyfikowana (kopie)');
const dup = duplicateStep(steps, 1);
ok(dup.length === 5 && dup[2].subject === 'B' && dup[2]._key !== dup[1]._key && dup[2].id === undefined, 'duplikat kroku tuż za oryginałem (nowy klucz, bez id)');
ok(duplicateStep(Array.from({ length: MAX_STEPS }, (_, i) => s('X' + i)), 0).length === MAX_STEPS, `limit ${MAX_STEPS} kroków`);
ok(removeStep(steps, 3).length === 3 && removeStep(steps, 3).every((x) => x.subject !== 'D'), 'usunięcie kroku');
ok(totalDays(steps) === 52, 'rozpiętość: 17 + 21 + 14 = 52 dni (pierwszy krok bez odstępu)');
ok(totalDays([s('A', 0), s('B', 17, false), s('C', 21)]) === 21, 'nieaktywne kroki nie liczą się do rozpiętości');
ok(JSON.stringify(placeholders('Hej {{ contact_first_name }} z {{company_name}}')) === '["contact_first_name","company_name"]', 'wykrywanie zmiennych {{…}}');
ok(validateSteps(steps, ['company_name']).length === 0, 'poprawne kroki — brak błędów');
ok(validateSteps([]).some((e) => e.includes('co najmniej jedną')), 'pusta kampania — błąd');
ok(validateSteps([{ subject: '', body: 'x', delay_days: 0, is_active: true }]).some((e) => e.includes('temat i treść')), 'brak tematu — błąd');
ok(validateSteps([s('A', -1)]).some((e) => e.includes('0–365')) && validateSteps([s('A', 400)]).some((e) => e.includes('0–365')), 'odstęp poza zakresem — błąd');
ok(validateSteps([{ subject: '{{contakt}}', body: 'x', delay_days: 0, is_active: true }], ['company_name']).some((e) => e.includes('{{contakt}}')), 'nieznana zmienna (literówka) wskazana przed zapisem');
ok(validateSteps([s('A', 0, false)]).some((e) => e.includes('aktywna')), 'wszystkie kroki nieaktywne — błąd');
ok(JSON.stringify(cleanSteps([{ ...s('A', '5'), _key: 'x', id: 7 }])) === '[{"subject":"A","body":"Treść A","delay_days":5,"is_active":true}]', 'dane do API bez pól pomocniczych, odstęp jako liczba');
ok(windowLabel({ days: [1, 2, 3, 4, 5], from: '09:00', to: '16:00', timezone: 'Europe/Warsaw' }) === 'Pn–Pt 09:00–16:00 (Europe/Warsaw)', 'etykieta okna: dni robocze');
ok(windowLabel({ days: [2, 4], from: '10:00', to: '12:00', timezone: 'UTC' }) === 'Wt, Cz 10:00–12:00 (UTC)', 'etykieta okna: wybrane dni');
ok(insertAt('Dzień dobry ,', 12, 12, '{{contact_first_name}}') === 'Dzień dobry {{contact_first_name}},', 'wstawienie zmiennej w miejscu kursora');
for (const k of ['draft', 'scheduled', 'active', 'paused', 'completed', 'cancelled', 'error']) ok(typeof SEQ_TONES[k] === 'string', `ton statusu sekwencji: ${k}`);
for (const k of ['pending', 'scheduled', 'sending', 'sent', 'failed', 'cancelled', 'unknown_result']) ok(typeof MSG_TONES[k] === 'string', `ton statusu wiadomości: ${k}`);

console.log(`\n${pass}/${pass + fail} testów zaliczonych`);
process.exit(fail ? 1 : 0);
