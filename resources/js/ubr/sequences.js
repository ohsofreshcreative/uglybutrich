/**
 * Prompt 10.5 — Sequences: biblioteka kampanii, sekwencje firm (zakładka „Mailing” w karcie firmy), zaplanowane,
 * historia, skrzynki i ustawienia. Interfejs niczego nie wysyła — wysyłka wyłącznie w workerze po stronie serwera.
 * Otwarcie list / zmiana filtrów / paginacja = tylko odczyt.
 */
import { api } from './api.js';
import { PER_PAGE_OPTIONS } from './per-page.js';

export const SEQ_TONES = { draft: 'muted', scheduled: 'info', active: 'good', paused: 'warn', completed: 'muted', cancelled: 'muted', error: 'danger' };
export const MSG_TONES = { pending: 'muted', scheduled: 'info', sending: 'active', sent: 'good', failed: 'danger', cancelled: 'muted', unknown_result: 'warn' };
export const MAILBOX_TONES = { connected: 'good', untested: 'warn', auth_failed: 'danger', error: 'danger', reconnect: 'danger', disabled: 'muted' };
export const DAYS = [[1, 'Pn'], [2, 'Wt'], [3, 'Śr'], [4, 'Cz'], [5, 'Pt'], [6, 'So'], [7, 'Nd']];
export const MAX_STEPS = 30;

/* --------------------------------------------------------------- pomocnicze (testowane) */

export function newStep(prev = null) {
  return { subject: '', body: '', delay_days: prev ? 3 : 0, is_active: true, _key: Math.random().toString(36).slice(2) };
}

export function moveStep(steps, index, dir) {
  const to = index + dir;
  if (to < 0 || to >= steps.length) return steps;
  const copy = steps.slice();
  [copy[index], copy[to]] = [copy[to], copy[index]];
  return copy;
}

export function duplicateStep(steps, index) {
  if (steps.length >= MAX_STEPS || !steps[index]) return steps;
  const copy = steps.slice();
  copy.splice(index + 1, 0, { ...steps[index], id: undefined, _key: Math.random().toString(36).slice(2), subject: steps[index].subject });
  return copy;
}

export function removeStep(steps, index) {
  return steps.filter((_, i) => i !== index);
}

/** Łączna liczba dni od pierwszej do ostatniej aktywnej wiadomości (bez przesunięć do okna wysyłki). */
export function totalDays(steps) {
  let first = true;
  return steps.reduce((sum, s) => {
    if (!s.is_active) return sum;
    if (first) {
      first = false;
      return sum;
    }
    return sum + (Number(s.delay_days) || 0);
  }, 0);
}

export function placeholders(text) {
  return [...String(text || '').matchAll(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g)].map((m) => m[1].toLowerCase());
}

/** Błędy edytora przed zapisem (serwer i tak waliduje). */
export function validateSteps(steps, known = null) {
  const errors = [];
  if (!steps.length) errors.push('Dodaj co najmniej jedną wiadomość.');
  if (steps.length > MAX_STEPS) errors.push(`Maksymalnie ${MAX_STEPS} wiadomości.`);
  steps.forEach((s, i) => {
    if (!String(s.subject || '').trim() || !String(s.body || '').trim()) errors.push(`Wiadomość ${i + 1}: temat i treść są wymagane.`);
    const d = Number(s.delay_days);
    if (!Number.isInteger(d) || d < 0 || d > 365) errors.push(`Wiadomość ${i + 1}: odstęp 0–365 dni.`);
    if (known) {
      const unknown = [...placeholders(s.subject), ...placeholders(s.body)].filter((p) => !known.includes(p));
      if (unknown.length) errors.push(`Wiadomość ${i + 1}: nieznane zmienne ${[...new Set(unknown)].map((p) => '{{' + p + '}}').join(', ')}.`);
    }
  });
  if (steps.length && !steps.some((s) => s.is_active)) errors.push('Co najmniej jedna wiadomość musi być aktywna.');
  return errors;
}

export function cleanSteps(steps) {
  return steps.map((s) => ({ subject: s.subject, body: s.body, delay_days: Number(s.delay_days) || 0, is_active: !!s.is_active }));
}

export function windowLabel(w) {
  if (!w) return '—';
  const days = (w.days || []).join(',') === '1,2,3,4,5' ? 'Pn–Pt' : DAYS.filter(([d]) => (w.days || []).includes(d)).map(([, l]) => l).join(', ');
  return `${days} ${w.from}–${w.to} (${w.timezone})`;
}

/** Wstawienie zmiennej w miejscu kursora (pole tekstowe). */
export function insertAt(value, start, end, token) {
  const v = String(value || '');
  return v.slice(0, start) + token + v.slice(end);
}

const blankMailbox = () => ({ provider: 'smtp', email: '', from_name: '', host: '', port: 587, encryption: 'tls', username: '', password: '', limits: { per_day: 30, per_hour: 8, min_interval_minutes: 4 } });

/** Wspólne metody edytora kroków (kampania w bibliotece i kopia dla firmy). */
function stepEditor() {
  return {
    addStep() {
      if (this.form.steps.length >= MAX_STEPS) return;
      this.form.steps = [...this.form.steps, newStep(this.form.steps[this.form.steps.length - 1])];
    },
    move(i, dir) {
      this.form.steps = moveStep(this.form.steps, i, dir);
    },
    dup(i) {
      this.form.steps = duplicateStep(this.form.steps, i);
    },
    remove(i) {
      this.form.steps = removeStep(this.form.steps, i);
    },
    toggleDay(d) {
      const days = this.form.window.days.includes(d) ? this.form.window.days.filter((x) => x !== d) : [...this.form.window.days, d].sort();
      this.form.window = { ...this.form.window, days: days.length ? days : this.form.window.days };
    },
    insertVar(i, field, key) {
      const el = document.getElementById(`step-${field}-${this.editorId}-${i}`);
      const token = '{{' + key + '}}';
      const step = this.form.steps[i];
      const pos = el ? [el.selectionStart ?? step[field].length, el.selectionEnd ?? step[field].length] : [step[field].length, step[field].length];
      step[field] = insertAt(step[field], pos[0], pos[1], token);
      this.form.steps = this.form.steps.slice();
    },
    totalDays() {
      return totalDays(this.form.steps);
    },
    varToken(key) {
      return '{' + '{' + key + '}' + '}';
    },
  };
}

export function registerSequences(Alpine) {
  /* ------------------------------------------------------------- strona Sequences */
  Alpine.data('ubrSequences', () => ({
    ...stepEditor(),
    tab: 'campaigns',
    tabs: [
      { k: 'campaigns', l: 'Campaigns' }, { k: 'active', l: 'Active' }, { k: 'scheduled', l: 'Scheduled' },
      { k: 'sent', l: 'Sent' }, { k: 'mailboxes', l: 'Mailboxes' }, { k: 'settings', l: 'Settings' },
    ],
    overview: null,
    variables: {},
    loading: false,
    busy: false,
    error: null,
    editorId: 'lib',
    // Campaigns
    campaigns: [],
    campaignFilter: '',
    form: null,
    preview: null,
    previewErrors: [],
    // Listy
    list: { items: [], total: 0, page: 1, per_page: 25, filters: null },
    filters: { owner: '', campaign_id: '', status: '', mailbox_id: '', next_before: '', search: '' },
    perPageOptions: PER_PAGE_OPTIONS,
    message: null,
    // Mailboxes
    mailboxes: [],
    testAllowed: false,
    mbForm: null,
    // Settings
    settings: null,
    suppressions: { items: [] },
    supForm: { email: '', reason: 'manual', note: '' },
    enableForm: { confirm_duplicates: false },

    init() {
      const t = new URLSearchParams(window.location.search).get('tab');
      if (this.tabs.some((x) => x.k === t)) this.tab = t;
      this.loadOverview();
      this.loadTab();
      this.$watch('tab', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', this.tab);
        window.history.replaceState({}, '', url);
        this.list = { items: [], total: 0, page: 1, per_page: this.list.per_page, filters: this.list.filters };
        this.filters = { ...this.filters, status: '' };
        this.loadTab();
      });
    },
    can(ability) {
      return (window.UBR?.abilities || []).includes(ability);
    },
    tone(map, key) {
      return this.$store.ubr.stageBadge((map || {})[key] || 'muted');
    },
    seqTone(k) {
      return this.$store.ubr.stageBadge(SEQ_TONES[k] || 'muted');
    },
    msgTone(k) {
      return this.$store.ubr.stageBadge(MSG_TONES[k] || 'muted');
    },
    mbTone(k) {
      return this.$store.ubr.stageBadge(MAILBOX_TONES[k] || 'muted');
    },
    windowLabel,
    days: DAYS,

    async loadOverview() {
      try {
        this.overview = await api('/sequences/overview');
        this.variables = this.overview.variables || {};
      } catch (e) {
        this.error = e.message;
      }
    },
    async loadTab() {
      this.error = null;
      if (this.tab === 'campaigns') return this.loadCampaigns();
      if (this.tab === 'mailboxes') return this.loadMailboxes();
      if (this.tab === 'settings') return this.loadSettings();
      return this.loadList(1);
    },
    async run(fn, ok) {
      this.busy = true;
      try {
        const r = await fn();
        if (ok) this.$store.ubr.toast(typeof ok === 'function' ? ok(r) : ok);
        return r;
      } catch (e) {
        this.$store.ubr.error(e);
        return null;
      } finally {
        this.busy = false;
      }
    },

    /* --- Campaigns --- */
    async loadCampaigns() {
      this.loading = true;
      try {
        this.campaigns = (await api('/sequences/campaigns', { query: { status: this.campaignFilter || undefined } })).items;
      } catch (e) {
        this.error = e.message;
      } finally {
        this.loading = false;
      }
    },
    newCampaign() {
      this.preview = null;
      this.form = { id: null, name: '', description: '', scope: this.can('sequences.templates') ? 'shared' : 'personal', status: 'draft', window: { days: [1, 2, 3, 4, 5], from: '09:00', to: '16:00', timezone: 'Europe/Warsaw' }, steps: [newStep()], can_edit: true };
      this.$nextTick(() => document.getElementById('cmp-name')?.focus());
    },
    async editCampaign(c) {
      const full = await this.run(() => api(`/sequences/campaigns/${c.id}`));
      if (!full) return;
      this.preview = null;
      this.form = { ...full, steps: full.steps.map((s) => ({ ...s, _key: 's' + s.id })) };
    },
    closeEditor() {
      this.form = null;
      this.preview = null;
    },
    editorErrors() {
      return this.form ? validateSteps(this.form.steps, Object.keys(this.variables)) : [];
    },
    async saveCampaign(status = null) {
      const errs = this.editorErrors();
      if (!this.form.name.trim()) errs.unshift('Podaj nazwę kampanii.');
      if (errs.length) return this.$store.ubr.toast(errs[0], 'error');
      const body = { name: this.form.name, description: this.form.description, settings: { window: this.form.window }, steps: cleanSteps(this.form.steps) };
      let saved;
      if (this.form.id) {
        saved = await this.run(() => api(`/sequences/campaigns/${this.form.id}`, { method: 'POST', body: status ? { ...body, status } : body }), 'Zapisano kampanię.');
      } else {
        saved = await this.run(() => api('/sequences/campaigns', { method: 'POST', body: { ...body, scope: this.form.scope } }), 'Utworzono kampanię (szkic).');
        if (saved && status) saved = await this.run(() => api(`/sequences/campaigns/${saved.id}`, { method: 'POST', body: { status } }));
      }
      if (saved) {
        this.form = { ...saved, steps: saved.steps.map((s) => ({ ...s, _key: 's' + s.id })) };
        this.loadCampaigns();
        this.loadOverview();
      }
    },
    async setCampaignStatus(c, status) {
      const r = await this.run(() => api(`/sequences/campaigns/${c.id}`, { method: 'POST', body: { status } }), status === 'archived' ? 'Kampania zarchiwizowana.' : status === 'active' ? 'Kampania aktywna — można ją przypisywać firmom.' : 'Kampania przeniesiona do szkiców.');
      if (r) {
        this.loadCampaigns();
        if (this.form?.id === c.id) this.form = { ...this.form, status: r.status, status_label: r.status_label };
      }
    },
    async duplicateCampaign(c) {
      const r = await this.run(() => api(`/sequences/campaigns/${c.id}/duplicate`, { method: 'POST' }), 'Utworzono kopię kampanii (szkic).');
      if (r) {
        this.loadCampaigns();
        this.form = { ...r, steps: r.steps.map((s) => ({ ...s, _key: 's' + s.id })) };
      }
    },
    async previewCampaign() {
      this.previewErrors = this.editorErrors();
      if (this.previewErrors.length) return;
      const r = await this.run(() => api('/sequences/preview', { method: 'POST', body: { steps: cleanSteps(this.form.steps), window: this.form.window } }));
      if (r) this.preview = r.items;
    },

    /* --- Active / Scheduled / Sent --- */
    endpoint() {
      return { active: '/sequences/items', scheduled: '/sequences/scheduled', sent: '/sequences/sent' }[this.tab];
    },
    async loadList(page = this.list.page) {
      const ep = this.endpoint();
      if (!ep) return;
      this.loading = true;
      const tab = this.tab;
      try {
        const res = await api(ep, { query: { ...this.filters, page, per_page: this.list.per_page } });
        if (tab !== this.tab) return; // odpowiedź dla poprzedniej zakładki — pominięta
        this.list = { ...res };
      } catch (e) {
        this.error = e.message;
      } finally {
        this.loading = false;
      }
    },
    setPerPage(v) {
      this.list.per_page = Number(v);
      this.loadList(1);
    },
    pages() {
      return Math.max(1, Math.ceil(this.list.total / this.list.per_page));
    },
    resetFilters() {
      this.filters = { owner: '', campaign_id: '', status: '', mailbox_id: '', next_before: '', search: '' };
      this.loadList(1);
    },
    openCompany(companyId) {
      this.$store.ubr.drawer.tab = 'mailing';
      this.$store.ubr.openCompany(companyId);
    },
    async control(seq, action) {
      if (action === 'cancel' && !window.confirm('Anulować sekwencję? Przyszłe wiadomości nie zostaną wysłane (historia zostaje).')) return;
      const r = await this.run(() => api(`/sequences/items/${seq.sequence_id || seq.id}/${action}`, { method: 'POST' }), (res) => res.message);
      if (r) {
        this.loadList();
        this.loadOverview();
      }
    },
    async openMessage(m) {
      this.message = { loading: true };
      try {
        this.message = await api(`/sequences/messages/${m.id}`);
      } catch (e) {
        this.message = null;
        this.$store.ubr.error(e);
      }
    },
    async resolve(m, outcome) {
      const r = await this.run(() => api(`/sequences/messages/${m.id}/resolve`, { method: 'POST', body: { outcome } }), outcome === 'sent' ? 'Oznaczono jako wysłaną.' : 'Wiadomość zostanie wysłana ponownie.');
      if (r) {
        this.message = null;
        this.loadList();
        this.loadOverview();
      }
    },

    /* --- Mailboxes --- */
    async loadMailboxes() {
      this.loading = true;
      try {
        const r = await api('/sequences/mailboxes');
        this.mailboxes = r.items;
        this.testAllowed = r.test_allowed;
      } catch (e) {
        this.error = e.message;
      } finally {
        this.loading = false;
      }
    },
    newMailbox() {
      this.mbForm = blankMailbox();
    },
    editMailbox(m) {
      this.mbForm = { id: m.id, provider: m.provider, email: m.email, from_name: m.from_name || '', host: m.host || '', port: m.port || 587, encryption: m.encryption || 'tls', username: m.username || '', password: '', has_password: m.has_password, limits: { ...m.limits } };
    },
    async saveMailbox() {
      const f = this.mbForm;
      const body = { from_name: f.from_name, limits: f.limits };
      if (f.provider === 'smtp') Object.assign(body, { host: f.host, port: Number(f.port), encryption: f.encryption, username: f.username }, f.password ? { password: f.password } : {});
      const r = f.id
        ? await this.run(() => api(`/sequences/mailboxes/${f.id}`, { method: 'POST', body }), 'Zapisano skrzynkę.')
        : await this.run(() => api('/sequences/mailboxes', { method: 'POST', body: { ...body, provider: f.provider, email: f.email } }), 'Podłączono skrzynkę — wykonaj test połączenia.');
      if (r) {
        this.mbForm = null;
        this.loadMailboxes();
        this.loadOverview();
      }
    },
    async testMailbox(m) {
      const r = await this.run(() => api(`/sequences/mailboxes/${m.id}/test`, { method: 'POST' }));
      if (r) {
        this.$store.ubr.toast(r.message, r.ok ? 'info' : 'error');
        this.loadMailboxes();
      }
    },
    async dnsMailbox(m) {
      const r = await this.run(() => api(`/sequences/mailboxes/${m.id}/dns`, { method: 'POST' }));
      if (r) this.loadMailboxes();
    },
    async defaultMailbox(m) {
      const r = await this.run(() => api(`/sequences/mailboxes/${m.id}`, { method: 'POST', body: { is_default: true } }), 'Ustawiono domyślną skrzynkę.');
      if (r) this.loadMailboxes();
    },
    async disconnectMailbox(m) {
      if (!window.confirm(`Odłączyć ${m.email}? Hasło zostanie usunięte, a sekwencje używające tej skrzynki — wstrzymane.`)) return;
      const r = await this.run(() => api(`/sequences/mailboxes/${m.id}`, { method: 'DELETE' }), (res) => `Odłączono skrzynkę. Wstrzymane sekwencje: ${res.paused_sequences}.`);
      if (r) {
        this.loadMailboxes();
        this.loadOverview();
      }
    },

    /* --- Settings --- */
    async loadSettings() {
      this.loading = true;
      try {
        this.settings = await api('/sequences/settings');
        this.suppressions = await api('/sequences/suppressions');
      } catch (e) {
        this.error = e.message;
      } finally {
        this.loading = false;
      }
    },
    async saveSettings(extra = {}) {
      const s = this.settings.settings;
      const r = await this.run(() => api('/sequences/settings', { method: 'POST', body: { window: s.window, caps: s.caps, mailbox_defaults: s.mailbox_defaults, append_unsubscribe: s.append_unsubscribe, unsubscribe_text: s.unsubscribe_text, ...extra } }), 'Zapisano ustawienia.');
      if (r) {
        this.settings = r;
        this.loadOverview();
      }
    },
    async setSending(on) {
      if (on && !window.confirm('Włączyć automatyczną wysyłkę? Zatwierdzone sekwencje zaczną wysyłać wiadomości do odbiorców według harmonogramu.')) return;
      const r = await this.run(() => api('/sequences/settings', { method: 'POST', body: { sending_enabled: on, confirm_duplicates: this.enableForm.confirm_duplicates } }), on ? 'Automatyczna wysyłka włączona.' : 'Automatyczna wysyłka wyłączona.');
      if (r) {
        this.settings = r;
        this.loadOverview();
      } else {
        this.loadSettings();
      }
    },
    toggleSettingsDay(d) {
      const w = this.settings.settings.window;
      const days = w.days.includes(d) ? w.days.filter((x) => x !== d) : [...w.days, d].sort();
      this.settings.settings.window = { ...w, days: days.length ? days : w.days };
    },
    async addSuppression() {
      const r = await this.run(() => api('/sequences/suppressions', { method: 'POST', body: this.supForm }), (res) => `Adres zablokowany. Anulowane sekwencje: ${res.cancelled_sequences}.`);
      if (r) {
        this.supForm = { email: '', reason: 'manual', note: '' };
        this.suppressions = await api('/sequences/suppressions');
      }
    },
    async removeSuppression(s) {
      if (!window.confirm(`Odblokować ${s.email}? Adres będzie można ponownie dodać do sekwencji.`)) return;
      const r = await this.run(() => api('/sequences/suppressions', { method: 'DELETE', query: { email: s.email } }), 'Odblokowano adres.');
      if (r) this.suppressions = await api('/sequences/suppressions');
    },
  }));

  /* ------------------------------------------------------------- karta firmy → Mailing */
  Alpine.data('ubrMailing', () => ({
    ...stepEditor(),
    editorId: 'seq',
    loading: true,
    busy: false,
    data: null,
    detail: null,
    form: null,
    variables: {},
    assign: null,
    campaigns: [],
    recipients: null,
    mailboxes: [],
    approve: { confirm: false, test_mode: false },
    conflict: null,
    editing: null,
    poll: null,

    async init() {
      await this.load();
    },
    destroy() {
      clearTimeout(this.poll);
    },
    companyId() {
      return this.$store.ubr.drawer.company?.id;
    },
    seqTone(k) {
      return this.$store.ubr.stageBadge(SEQ_TONES[k] || 'muted');
    },
    msgTone(k) {
      return this.$store.ubr.stageBadge(MSG_TONES[k] || 'muted');
    },
    windowLabel,
    days: DAYS,
    async load(selectId = null) {
      const id = this.companyId();
      if (!id) return;
      this.loading = true;
      try {
        const [data, ov] = await Promise.all([api(`/companies/${id}/sequences`), Object.keys(this.variables).length ? null : api('/sequences/overview')]);
        if (this.companyId() !== id) return;
        this.data = data;
        if (ov) this.variables = ov.variables || {};
        const pick = selectId || this.detail?.id || data.sequences.find((s) => ['draft', 'scheduled', 'active', 'paused', 'error'].includes(s.status))?.id || data.sequences[0]?.id;
        if (pick) await this.open(pick);
        else this.detail = null;
      } catch (e) {
        this.data = { error: e.message };
      } finally {
        this.loading = false;
      }
    },
    async open(seqId) {
      clearTimeout(this.poll);
      try {
        this.detail = await api(`/sequences/items/${seqId}`);
        this.form = this.detail.status === 'draft' ? this.toForm(this.detail) : null;
        this.approve = { confirm: false, test_mode: !this.detail.mailbox || this.detail.mailbox.provider === 'test' };
        this.conflict = null;
        if (['scheduled', 'active'].includes(this.detail.status)) this.poll = setTimeout(() => this.$store.ubr.drawer.open && this.detail?.id === seqId && this.open(seqId), 30000);
      } catch (e) {
        this.$store.ubr.error(e);
      }
    },
    toForm(d) {
      return {
        name: d.name,
        window: { ...d.settings.window },
        start_at: d.settings.start_at ? this.localInput(d.settings.start_at, d.settings.window.timezone) : '',
        end_at: d.settings.end_at ? this.localInput(d.settings.end_at, d.settings.window.timezone) : '',
        steps: d.messages.filter((m) => m.editable).map((m) => ({ id: m.id, subject: m.subject_template, body: m.body_template, delay_days: m.delay_days, is_active: m.is_active, _key: 'm' + m.id })),
        variables: Object.fromEntries(d.variables.filter((v) => !v.system).map((v) => [v.key, v.override || ''])),
        mailbox_id: d.mailbox?.id || '',
      };
    },
    localInput(utcMysql, tz) {
      const d = new Date(utcMysql.replace(' ', 'T') + 'Z');
      const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(d).map((p) => [p.type, p.value]));
      return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
    },
    async startAssign() {
      const id = this.companyId();
      this.assign = { campaign_id: '', recipient_key: '', manual_email: '', manual_name: '', mailbox_id: '' };
      try {
        const [c, r, m] = await Promise.all([api('/sequences/campaigns', { query: { status: 'active' } }), api(`/companies/${id}/sequence-recipients`), api('/sequences/mailboxes')]);
        this.campaigns = c.items;
        this.recipients = r;
        this.mailboxes = m.items;
        this.assign.mailbox_id = (m.items.find((x) => x.is_default) || m.items[0])?.id || '';
        const first = r.options.find((o) => !o.blockers.length);
        this.assign.recipient_key = first ? first.source + ':' + (first.contact_id || first.email) : 'manual';
      } catch (e) {
        this.$store.ubr.error(e);
        this.assign = null;
      }
    },
    recipientPayload() {
      const a = this.assign;
      if (a.recipient_key === 'manual') return { source: 'manual', email: a.manual_email, name: a.manual_name };
      const opt = this.recipients.options.find((o) => o.source + ':' + (o.contact_id || o.email) === a.recipient_key);
      return opt ? { source: opt.source, email: opt.email, contact_id: opt.contact_id } : null;
    },
    async createSequence() {
      const recipient = this.recipientPayload();
      if (!recipient || (recipient.source === 'manual' && !recipient.email)) return this.$store.ubr.toast('Wybierz lub wpisz adres odbiorcy.', 'error');
      if (!this.assign.mailbox_id) return this.$store.ubr.toast('Podłącz własną skrzynkę w Sequences → Mailboxes.', 'error');
      this.busy = true;
      try {
        const d = await api(`/companies/${this.companyId()}/sequences`, { method: 'POST', body: { campaign_id: this.assign.campaign_id || null, recipient, mailbox_id: this.assign.mailbox_id } });
        this.$store.ubr.toast('Utworzono szkic sekwencji — sprawdź i dostosuj wiadomości. Nic nie zostało wysłane.');
        this.assign = null;
        await this.load(d.id);
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.busy = false;
      }
    },
    editorErrors() {
      return this.form ? validateSteps(this.form.steps, Object.keys(this.variables)) : [];
    },
    async saveDraft(quiet = false) {
      const errs = this.editorErrors();
      if (errs.length) {
        this.$store.ubr.toast(errs[0], 'error');
        return false;
      }
      this.busy = true;
      try {
        const body = { name: this.form.name, settings: { window: this.form.window, start_at: this.form.start_at || null, end_at: this.form.end_at || null }, variables: this.form.variables, steps: cleanSteps(this.form.steps) };
        if (this.form.mailbox_id) body.mailbox_id = this.form.mailbox_id;
        this.detail = await api(`/sequences/items/${this.detail.id}`, { method: 'POST', body });
        this.form = this.detail.status === 'draft' ? this.toForm(this.detail) : this.form;
        if (!quiet) this.$store.ubr.toast('Zapisano zmiany (dotyczą tylko tej firmy — szablon w bibliotece bez zmian).');
        return true;
      } catch (e) {
        this.$store.ubr.error(e);
        return false;
      } finally {
        this.busy = false;
      }
    },
    async ensureMailboxes() {
      if (!this.mailboxes.length) this.mailboxes = (await api('/sequences/mailboxes')).items;
    },
    async start(replace = false) {
      if (!this.approve.confirm) return this.$store.ubr.toast('Zaznacz potwierdzenie odbiorcy, treści i harmonogramu.', 'error');
      if (this.form && !(await this.saveDraft(true))) return;
      this.busy = true;
      try {
        const r = await api(`/sequences/items/${this.detail.id}/start`, { method: 'POST', body: { confirm: true, test_mode: this.approve.test_mode, replace } }).catch((e) => {
          throw e;
        });
        this.conflict = null;
        this.$store.ubr.toast(r.message);
        await this.load(this.detail.id);
      } catch (e) {
        if (e.status === 409 && e.data?.conflict) this.conflict = e.data;
        else this.$store.ubr.error(e);
      } finally {
        this.busy = false;
      }
    },
    async control(action) {
      if (action === 'cancel' && !window.confirm('Anulować sekwencję? Przyszłe wiadomości nie zostaną wysłane. Wysłanych wiadomości nie da się cofnąć.')) return;
      if (action === 'delete' && !window.confirm('Usunąć szkic sekwencji?')) return;
      this.busy = true;
      try {
        const r = action === 'delete' ? await api(`/sequences/items/${this.detail.id}`, { method: 'DELETE' }) : await api(`/sequences/items/${this.detail.id}/${action}`, { method: 'POST' });
        this.$store.ubr.toast(r.message || 'Usunięto szkic.');
        if (action === 'delete') this.detail = null;
        await this.load(action === 'delete' ? null : this.detail.id);
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.busy = false;
      }
    },
    editMessage(m) {
      this.editing = { id: m.id, subject: m.subject_template, body: m.body_template, delay_days: m.delay_days, is_active: m.is_active, scheduled: m.status === 'scheduled', scheduled_at: '' };
    },
    async saveMessage() {
      const e = this.editing;
      this.busy = true;
      try {
        await api(`/sequences/messages/${e.id}`, { method: 'POST', body: { subject: e.subject, body: e.body, delay_days: Number(e.delay_days), is_active: e.is_active, ...(e.scheduled_at ? { scheduled_at: e.scheduled_at } : {}) } });
        this.editing = null;
        this.$store.ubr.toast('Zapisano wiadomość.');
        await this.open(this.detail.id);
      } catch (err) {
        this.$store.ubr.error(err);
      } finally {
        this.busy = false;
      }
    },
    async resolve(m, outcome) {
      this.busy = true;
      try {
        this.detail = await api(`/sequences/messages/${m.id}/resolve`, { method: 'POST', body: { outcome } });
        this.$store.ubr.toast(outcome === 'sent' ? 'Oznaczono jako wysłaną — wznów sekwencję, aby kontynuować.' : 'Wiadomość zostanie wysłana ponownie po wznowieniu.');
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.busy = false;
      }
    },
    async unsubscribeRecipient() {
      const email = this.detail?.recipient?.email;
      if (!email || !window.confirm(`Wypisać ${email} z mailingu? Adres zostanie trwale zablokowany, aktywne sekwencje — anulowane.`)) return;
      this.busy = true;
      try {
        await api('/sequences/suppressions', { method: 'POST', body: { email, reason: 'unsubscribed', note: 'Rezygnacja zgłoszona pracownikowi.' } });
        this.$store.ubr.toast('Adres wypisany i zablokowany.');
        await this.load(this.detail.id);
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.busy = false;
      }
    },
    aiInfo() {
      this.$store.ubr.toast('„Spersonalizuj z AI” będzie dostępne po włączeniu AI Outreach Assistant (wymaga zgody na płatne API).', 'info');
    },
  }));
}
