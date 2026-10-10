/**
 * Sales Pipeline + ClickUp — stan UI (Alpine). Logika, uprawnienia i walidacja są po stronie serwera (REST ubr/v1);
 * tutaj tylko wywołania API i prezentacja. Treści użytkownika wyświetlamy wyłącznie przez x-text (bez x-html).
 */
import { api } from './api.js';

const can = (ability) => (window.UBR?.abilities || []).includes(ability);

export const PRIORITY_BADGE = { low: 'ubr-badge-unavailable', medium: 'ubr-badge-declared', high: 'ubr-badge-uncertain', urgent: 'ubr-badge-danger' };
export const EXPORT_BADGE = {
  success: 'ubr-badge-confirmed',
  queued: 'ubr-badge-uncertain',
  running: 'ubr-badge-uncertain',
  failed: 'ubr-badge-danger',
  unknown: 'ubr-badge-danger',
  skipped_duplicate: 'ubr-badge-unavailable',
  skipped: 'ubr-badge-unavailable',
  cancelled: 'ubr-badge-unavailable',
};
const QUAL_BADGE = { good_candidate: 'ubr-badge-confirmed', needs_review: 'ubr-badge-uncertain', low_potential: 'ubr-badge-unavailable', not_a_fit: 'ubr-badge-danger' };
const CALLBACK = {
  connected: ['Połączono konto ClickUp.', 'info'],
  invalid_state: ['Autoryzacja ClickUp wygasła albo jest nieprawidłowa — spróbuj ponownie.', 'error'],
  wrong_user: ['Autoryzację rozpoczął inny użytkownik — zaloguj się na właściwe konto i spróbuj ponownie.', 'error'],
  not_member: ['Nie należysz już do organizacji, dla której rozpoczęto autoryzację.', 'error'],
  denied: ['Autoryzacja ClickUp została anulowana.', 'error'],
  missing_code: ['ClickUp nie zwrócił kodu autoryzacji.', 'error'],
  not_configured: ['Integracja ClickUp nie jest skonfigurowana na serwerze.', 'error'],
  exchange_failed: ['Nie udało się wymienić kodu autoryzacji na token ClickUp. Spróbuj ponownie.', 'error'],
};

/** Lokalny czas (YYYY-MM-DDTHH:mm) dla pól datetime-local. */
/**
 * Wartość <input type="datetime-local"> (czas lokalny PRZEGLĄDARKI, bez strefy) → ISO z offsetem. Serwer przyjmuje
 * ISO z offsetem; bez niego interpretował wartość w strefie WordPressa — przy innej strefie przeglądarki (np. WP w UTC,
 * użytkownik w Warszawie) termin przesuwał się o różnicę, a aktywność „teraz” była odrzucana jako „w przyszłości”.
 */
export const isoFromLocalInput = (value) => {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(value)) return value || null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toISOString();
};

export const localInput = (date = new Date()) => {
  const d = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return d.toISOString().slice(0, 16);
};

/* ======================================================================================
 * Metody sklepu $store.ubr (szuflada firmy: zakładka „Sprzedaż” + ClickUp, eksport zbiorczy).
 * ==================================================================================== */
export const salesStore = () => ({
  clickupBatch: null,

  priorityBadge: (v) => PRIORITY_BADGE[v] || 'ubr-badge-unavailable',
  exportBadge: (v) => EXPORT_BADGE[v] || 'ubr-badge-unavailable',
  qualificationBadge: (v) => QUAL_BADGE[v] || 'ubr-badge-unavailable',
  dateOnly(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' });
  },

  async loadSales() {
    const id = this.drawer.company?.id;
    if (!id) return;
    this.drawer.salesLoading = true;
    try {
      const data = await api(`/companies/${id}/sales`);
      if (this.drawer.company?.id === id) this.drawer.sales = data;
    } catch (e) {
      this.drawer.error = e.message;
    } finally {
      this.drawer.salesLoading = false;
    }
    this.loadClickup();
    // Lista kontaktów do wyboru „wybranego kontaktu” (widok organizacji — bez odrzuconych / wykluczonych).
    if (!this.drawer.contacts) this.loadContacts();
  },

  async loadClickup(poll = false) {
    const id = this.drawer.company?.id;
    if (!id) return;
    try {
      const data = await api(`/companies/${id}/clickup`);
      if (this.drawer.company?.id !== id) return;
      this.drawer.clickup = data;
      clearTimeout(this._clickupTimer);
      const active = (data.history || []).some((j) => ['queued', 'running'].includes(j.status));
      if (active && this.drawer.open) this._clickupTimer = setTimeout(() => this.loadClickup(true), 4000);
      else if (poll) this.loadSales();
    } catch (e) {
      this.drawer.clickup = null;
    }
  },

  async salesCall(fn, success) {
    this.drawer.saving = true;
    try {
      const data = await fn();
      if (data && data.company_id === this.drawer.company?.id) this.drawer.sales = data;
      if (success) this.toast(success);
      window.dispatchEvent(new CustomEvent('ubr:pipeline-changed', { detail: { companyId: this.drawer.company?.id } }));
      return true;
    } catch (e) {
      this.error(e);
      return false;
    } finally {
      this.drawer.saving = false;
    }
  },

  updateProspect(fields, success = 'Zapisano.') {
    const pid = this.drawer.sales?.prospect?.id;
    if (!pid) return false;
    return this.salesCall(() => api(`/prospects/${pid}`, { method: 'POST', body: fields }), success);
  },
  addNote(body) {
    return this.salesCall(() => api(`/prospects/${this.drawer.sales.prospect.id}/notes`, { method: 'POST', body: { body } }), 'Dodano notatkę (prywatna dla organizacji).');
  },
  saveNote(note, body) {
    return this.salesCall(() => api(`/notes/${note.id}`, { method: 'POST', body: { body } }), 'Zapisano notatkę.');
  },
  deleteNote(note) {
    return this.salesCall(() => api(`/notes/${note.id}`, { method: 'DELETE' }), 'Usunięto notatkę.');
  },
  addActivity(form) {
    const body = { ...form, occurred_at: isoFromLocalInput(form.occurred_at) };
    return this.salesCall(() => api(`/prospects/${this.drawer.sales.prospect.id}/activities`, { method: 'POST', body }), 'Zapisano aktywność.');
  },
  addFollowup(form) {
    const body = { ...form, due_at: isoFromLocalInput(form.due_at) };
    return this.salesCall(() => api(`/prospects/${this.drawer.sales.prospect.id}/followups`, { method: 'POST', body }), 'Zaplanowano działanie.');
  },
  setFollowup(f, status) {
    return this.salesCall(() => api(`/followups/${f.id}`, { method: 'POST', body: { status } }), status === 'completed' ? 'Oznaczono jako wykonane.' : 'Zapisano.');
  },
  archiveProspect(restore = false) {
    const pid = this.drawer.sales?.prospect?.id;
    return this.salesCall(() => api(`/prospects/${pid}/archive`, { method: 'POST', body: { restore } }), restore ? 'Przywrócono do Prospects.' : 'Zarchiwizowano.');
  },

  /** Eksport do ClickUp — zawsze w tle; zbiorczy z paskiem postępu. mode: create | update | new */
  async exportClickup(companyIds, mode = 'create') {
    const ids = [].concat(companyIds);
    try {
      const res = await api('/clickup/exports', { method: 'POST', body: { company_ids: ids, mode } });
      const dup = res.skipped.find((s) => s.reason === 'already_exported');
      this.toast(ids.length === 1 && dup ? 'Already exported — zadanie już istnieje w ClickUp.' : res.message, res.queued.length || dup ? 'info' : 'error');
      if (res.batch_id) this.watchBatch(res.batch_id, res.queued.length);
      if (this.drawer.open && this.drawer.company && ids.includes(this.drawer.company.id)) this.loadClickup();
      window.dispatchEvent(new CustomEvent('ubr:clickup-changed', { detail: { companyIds: ids } }));
      return res;
    } catch (e) {
      this.error(e);
      return null;
    }
  },

  async watchBatch(batchId, total) {
    this.clickupBatch = { id: batchId, total, counts: {}, done: false };
    for (let i = 0; i < 120; i++) {
      await new Promise((r) => setTimeout(r, 3000));
      if (this.clickupBatch?.id !== batchId) return;
      try {
        const b = await api(`/clickup/batches/${batchId}`);
        this.clickupBatch = { id: batchId, total: b.total, counts: b.counts, done: b.done };
        if (b.done) {
          window.dispatchEvent(new CustomEvent('ubr:clickup-changed', { detail: {} }));
          return;
        }
      } catch (e) {
        return;
      }
    }
  },

  async reconcileClickup(exp, action, task = '') {
    try {
      this.drawer.clickup = await api(`/clickup/exports/${exp.id}/reconcile`, { method: 'POST', body: { action, task } });
      this.toast('Zapisano rozstrzygnięcie.');
      window.dispatchEvent(new CustomEvent('ubr:clickup-changed', { detail: {} }));
    } catch (e) {
      this.error(e);
    }
  },
});

/* ======================================================================================
 * Komponenty stron.
 * ==================================================================================== */
export function registerPipeline(Alpine) {
  /* -------------------------- Tablica (Board View) -------------------------- */
  Alpine.data('ubrPipeline', () => ({
    filters: { search: '', owner: '', priority: '', qualification: '', overdue: '' },
    columns: [],
    members: [],
    priorities: {},
    loading: true,
    error: null,
    dragging: null,
    dragOver: null,
    moving: {},
    perColumn: 20,

    get canManage() {
      return can('pipeline.manage');
    },
    get hasFilters() {
      return Object.values(this.filters).some(Boolean);
    },
    get statusOptions() {
      return this.columns.map((c) => ({ key: c.key, label: c.label }));
    },

    query(extra = {}) {
      const f = { ...this.filters };
      if (f.owner === 'me') f.owner = String(window.UBR.userId);
      return { ...f, per_column: this.perColumn, ...extra };
    },

    init() {
      this.load();
      window.addEventListener('ubr:pipeline-changed', () => this.load(true));
      window.addEventListener('ubr:clickup-changed', () => this.load(true));
    },

    async load(silent = false) {
      if (!silent) this.loading = true;
      this.error = null;
      try {
        const data = await api('/pipeline/board', { query: this.query() });
        this.columns = data.columns;
        this.members = data.members;
        this.priorities = data.priorities;
      } catch (e) {
        this.error = e.message;
      } finally {
        this.loading = false;
      }
    },

    /** Doładowanie kolejnych kart kolumny (lazy load). */
    async more(col) {
      col.loadingMore = true;
      try {
        const data = await api('/pipeline/board', { query: this.query({ status: col.key, offset: col.items.length }) });
        const fresh = data.columns[0];
        const known = new Set(col.items.map((i) => i.prospect_id));
        col.items.push(...fresh.items.filter((i) => !known.has(i.prospect_id)));
        col.total = fresh.total;
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        col.loadingMore = false;
      }
    },

    dragStart(e, card) {
      if (!this.canManage) return;
      this.dragging = card;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(card.prospect_id));
    },
    dragEnd() {
      this.dragging = null;
      this.dragOver = null;
    },
    drop(col) {
      const card = this.dragging;
      this.dragEnd();
      if (card && card.status !== col.key) this.move(card, col.key);
    },

    /** Zmiana statusu (drag & drop albo lista „Przenieś do” z klawiatury) — zapis w backendzie, przy błędzie cofnięcie. */
    async move(card, status) {
      const from = this.columns.find((c) => c.key === card.status);
      const to = this.columns.find((c) => c.key === status);
      if (!from || !to || from === to || this.moving[card.prospect_id]) return;
      const index = from.items.indexOf(card);
      this.moving[card.prospect_id] = true;
      from.items.splice(index, 1);
      from.total--;
      card.status = status;
      to.items.unshift(card);
      to.total++;
      try {
        await api(`/prospects/${card.prospect_id}`, { method: 'POST', body: { status } });
        this.$store.ubr.toast(`„${card.name}” → ${to.label}.` + (['lost', 'not_a_fit'].includes(status) ? ' Możesz dodać powód w karcie firmy (zakładka Sprzedaż).' : ''));
      } catch (e) {
        to.items.splice(to.items.indexOf(card), 1);
        to.total--;
        card.status = from.key;
        from.items.splice(index, 0, card);
        from.total++;
        this.$store.ubr.error(e);
      } finally {
        delete this.moving[card.prospect_id];
      }
    },

    async assign(card, ownerId) {
      try {
        await api(`/prospects/${card.prospect_id}`, { method: 'POST', body: { owner_user_id: ownerId ? Number(ownerId) : null } });
        card.owner = this.members.find((m) => String(m.id) === String(ownerId)) || null;
        this.$store.ubr.toast(card.owner ? `Przypisano: ${card.owner.name}.` : 'Usunięto właściciela.');
      } catch (e) {
        this.$store.ubr.error(e);
        this.load(true);
      }
    },
  }));

  /* -------------------------- Settings → Integrations → ClickUp -------------------------- */
  Alpine.data('ubrClickUp', () => ({
    status: null,
    loading: true,
    busy: false,
    testResult: null,
    picker: { open: false, teams: [], spaces: [], folders: [], lists: [], team: '', space: '', folder: '', list: '', loading: '', error: null },
    template: '',
    fields: null,
    mapping: {},
    mappable: [
      ['company_name', 'Company Name'],
      ['website', 'Website'],
      ['relevance_score', 'Relevance Score'],
      ['opportunity_score', 'Opportunity Score'],
      ['contact_priority_score', 'Contact Priority Score'],
      ['pipeline_status', 'Pipeline Status'],
      ['priority', 'Priority'],
    ],
    rules: { rules: [], can_manage: false, conditions: {} },
    ruleForm: null,
    preview: {},
    confirm: {},
    recent: [],

    get canConnect() {
      return can('clickup.connect');
    },
    get canRules() {
      return can('clickup.rules');
    },
    get connected() {
      return this.status?.connected;
    },

    async init() {
      const params = new URLSearchParams(window.location.search);
      const code = params.get('clickup');
      if (code && CALLBACK[code]) {
        this.$store.ubr.toast(...CALLBACK[code]);
        params.delete('clickup');
        history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params : '') + window.location.hash);
      }
      await this.load();
      // Link „Settings → Integrations → ClickUp” (#clickup): przewinięcie po wczytaniu treści.
      if (window.location.hash === '#clickup') this.$nextTick(() => this.$el.scrollIntoView({ block: 'start' }));
    },

    async load() {
      if (!this.canConnect) {
        this.loading = false;
        return;
      }
      try {
        this.status = await api('/clickup/status');
        this.template = this.status.settings.task_name_template;
        this.mapping = Object.fromEntries(Object.entries(this.status.settings.field_mapping || {}).map(([k, m]) => [k, m.field_id]));
        if (this.connected) {
          const [rules, recent] = await Promise.all([api('/clickup/rules'), api('/clickup/exports/recent')]);
          this.rules = rules;
          this.recent = recent;
        }
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.loading = false;
      }
    },

    async run(fn, success) {
      this.busy = true;
      try {
        const r = await fn();
        if (success) this.$store.ubr.toast(success);
        return r;
      } catch (e) {
        this.$store.ubr.error(e);
        return null;
      } finally {
        this.busy = false;
      }
    },

    async connect() {
      const r = await this.run(() => api('/clickup/oauth/start', { method: 'POST' }));
      // Przekierowanie do ClickUp (state jednorazowy, ważny 10 min, przypisany do Ciebie i organizacji).
      if (r?.authorize_url) window.location.href = r.authorize_url;
    },
    async test() {
      this.testResult = await this.run(() => api('/clickup/test', { method: 'POST' }));
      await this.load();
    },
    async disconnect() {
      const r = await this.run(() => api('/clickup/disconnect', { method: 'POST' }), 'Rozłączono konto ClickUp. Reguły korzystające z tego połączenia zostały wyłączone.');
      if (r) {
        this.status = r;
        this.confirm = {};
        this.rules = { rules: [], can_manage: false, conditions: {} };
      }
    },

    /* --- Miejsce eksportu: Workspace → Space → Folder (opcjonalny) → List (dane na żywo z ClickUp) --- */
    async options(level, parentId = '') {
      this.picker.loading = level;
      this.picker.error = null;
      try {
        return (await api('/clickup/options', { query: { level, parent_id: parentId } })).items;
      } catch (e) {
        this.picker.error = e.message;
        return [];
      } finally {
        this.picker.loading = '';
      }
    },
    async openPicker() {
      this.picker = { open: true, teams: [], spaces: [], folders: [], lists: [], team: '', space: '', folder: '', list: '', loading: '', error: null };
      this.picker.teams = await this.options('teams');
    },
    async pickTeam() {
      Object.assign(this.picker, { spaces: [], folders: [], lists: [], space: '', folder: '', list: '' });
      if (this.picker.team) this.picker.spaces = await this.options('spaces', this.picker.team);
    },
    async pickSpace() {
      Object.assign(this.picker, { folders: [], lists: [], folder: '', list: '' });
      if (!this.picker.space) return;
      const [folders, lists] = await Promise.all([this.options('folders', this.picker.space), this.options('folderless_lists', this.picker.space)]);
      this.picker.folders = folders;
      this.picker.lists = lists;
    },
    async pickFolder() {
      Object.assign(this.picker, { lists: [], list: '' });
      this.picker.lists = this.picker.folder ? await this.options('folder_lists', this.picker.folder) : await this.options('folderless_lists', this.picker.space);
    },
    async saveDestination() {
      const p = this.picker;
      const name = (arr, id) => arr.find((x) => x.id === id)?.name || '';
      const r = await this.run(
        () =>
          api('/clickup/destination', {
            method: 'POST',
            body: { team_id: p.team, team_name: name(p.teams, p.team), space_id: p.space, space_name: name(p.spaces, p.space), folder_id: p.folder, folder_name: name(p.folders, p.folder), list_id: p.list, list_name: name(p.lists, p.list) },
          }),
        'Zapisano miejsce eksportu.',
      );
      if (r) {
        this.status = r;
        this.picker.open = false;
        this.fields = null;
        this.mapping = {};
      }
    },

    async saveTemplate() {
      const r = await this.run(() => api('/clickup/settings', { method: 'POST', body: { task_name_template: this.template } }), 'Zapisano szablon nazwy zadania.');
      if (r) this.status = r;
    },
    async loadFields() {
      if (!this.status?.destination?.list_id) return;
      this.fields = await this.options('fields', this.status.destination.list_id);
    },
    compatible(key) {
      const types = {
        company_name: ['short_text', 'text'],
        website: ['url', 'short_text', 'text'],
        relevance_score: ['number', 'short_text', 'text'],
        opportunity_score: ['number', 'short_text', 'text'],
        contact_priority_score: ['number', 'short_text', 'text'],
        pipeline_status: ['short_text', 'text', 'drop_down'],
        priority: ['short_text', 'text', 'drop_down'],
      }[key];
      return (this.fields || []).filter((f) => types.includes(f.type));
    },
    async saveMapping() {
      const r = await this.run(() => api('/clickup/settings', { method: 'POST', body: { field_mapping: this.mapping } }), 'Zapisano mapowanie pól (tylko istniejące pola listy).');
      if (r) this.status = r;
    },

    /* --- Reguły automatycznego eksportu (domyślnie wyłączone) --- */
    newRule() {
      this.ruleForm = { id: null, name: '', daily_limit: 10, conditions: { verified: true, domain_confirmed: true, min_relevance: '', min_opportunity: '', min_contact_score: '', min_contacts: '', prospect_statuses: [], priorities: [] } };
    },
    editRule(r) {
      this.ruleForm = { id: r.id, name: r.name, daily_limit: r.daily_limit, conditions: { verified: false, domain_confirmed: false, min_relevance: '', min_opportunity: '', min_contact_score: '', min_contacts: '', prospect_statuses: [], priorities: [], ...r.conditions } };
    },
    async saveRule() {
      const f = this.ruleForm;
      const body = { name: f.name, daily_limit: f.daily_limit, conditions: f.conditions };
      const r = await this.run(() => (f.id ? api(`/clickup/rules/${f.id}`, { method: 'POST', body }) : api('/clickup/rules', { method: 'POST', body })), f.id ? 'Zapisano regułę (wymaga ponownego włączenia, jeśli zmieniono warunki).' : 'Utworzono regułę — jest WYŁĄCZONA. Wykonaj podgląd i potwierdź włączenie.');
      if (r) {
        this.ruleForm = null;
        await this.loadRules();
        await this.dryRun(r);
      }
    },
    async loadRules() {
      this.rules = await api('/clickup/rules');
    },
    async dryRun(rule) {
      const r = await this.run(() => api(`/clickup/rules/${rule.id}/preview`, { method: 'POST' }));
      if (r) this.preview = { ...this.preview, [rule.id]: r };
    },
    async enable(rule) {
      const r = await this.run(() => api(`/clickup/rules/${rule.id}/enable`, { method: 'POST', body: { confirm: true } }), 'Reguła włączona — działa dla NOWYCH zdarzeń.');
      this.confirm = {};
      if (r) await this.loadRules();
    },
    async disable(rule) {
      const r = await this.run(() => api(`/clickup/rules/${rule.id}/disable`, { method: 'POST' }), 'Reguła wyłączona.');
      if (r) await this.loadRules();
    },
    async remove(rule) {
      const r = await this.run(() => api(`/clickup/rules/${rule.id}`, { method: 'DELETE' }), 'Usunięto regułę.');
      this.confirm = {};
      if (r) await this.loadRules();
    },
    async backfill(rule) {
      const r = await this.run(() => api(`/clickup/rules/${rule.id}/backfill`, { method: 'POST', body: { confirm: true } }));
      this.confirm = {};
      if (r) {
        this.$store.ubr.toast(r.message);
        await this.loadRules();
      }
    },
    toggleIn(list, value) {
      const i = list.indexOf(value);
      if (i === -1) list.push(value);
      else list.splice(i, 1);
    },
  }));
}
