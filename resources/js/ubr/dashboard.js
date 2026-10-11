/**
 * Prospecting Dashboard & Action Center (Prompt 6) — stan UI (Alpine). Priorytety, liczniki i kolejki liczy serwer
 * (REST ubr/v1/action-center); tu tylko pobieranie, prezentacja i wywołania ISTNIEJĄCYCH akcji ($store.ubr / endpointy).
 * Nic nie uruchamia się automatycznie — analiza, wyszukiwanie decydentów i weryfikacja startują wyłącznie po kliknięciu.
 */
import { api } from './api.js';

const can = (ability) => (window.UBR?.abilities || []).includes(ability);

export const PRIORITY_BADGE = { critical: 'ubr-badge-danger', high: 'ubr-badge-uncertain', medium: 'ubr-badge-declared', low: 'ubr-badge-unavailable' };
export const PRIORITY_LABEL = { critical: 'Pilne', high: 'Wysoki', medium: 'Średni', low: 'Niski' };

/** Wspólny kształt pozycji dla menu akcji (priorytet z listy głównej albo wiersz kolejki). */
function normalize(it) {
  return Object.assign(it, {
    actionType: it.type || it.action?.type || null,
    cta: it.cta || it.action?.cta || null,
  });
}

export function registerDashboard(Alpine) {
  /**
   * Manual Website Review — wspólny komponent (karta firmy i podgląd strony). Ocena subiektywna, prywatna dla organizacji,
   * z historią; nie zmienia żadnego scoringu (serwer nie przelicza Lead Intelligence).
   */
  Alpine.data('ubrManualReview', (getId) => ({
    id: null,
    data: null,
    loading: false,
    saving: false,
    note: '',
    error: null,
    showHistory: false,
    init() {
      this.$watch(() => getId(), (v) => this.load(v));
      this.load(getId());
    },
    async load(id) {
      this.error = null;
      if (!id) { this.data = null; this.id = null; return; }
      this.id = id;
      this.loading = true;
      try {
        const d = await api(`/companies/${id}/manual-review`);
        if (this.id === id) { this.data = d; this.note = d.note || ''; }
      } catch (e) {
        if (this.id === id) this.error = e.message;
      } finally {
        this.loading = false;
      }
    },
    async save(status) {
      if (!this.id || this.saving) return;
      this.saving = true;
      try {
        const d = await api(`/companies/${this.id}/manual-review`, { method: 'POST', body: { review_status: status, review_note: this.note } });
        this.data = d;
        this.note = d.note || '';
        Alpine.store('ubr').toast('Zapisano ręczną ocenę strony.');
        window.dispatchEvent(new CustomEvent('ubr:manual-review-saved', { detail: { companyId: this.id } }));
      } catch (e) {
        Alpine.store('ubr').error(e);
      } finally {
        this.saving = false;
      }
    },
    tone(key) {
      return { good: 'ubr-badge-confirmed', average: 'ubr-badge-declared', poor: 'ubr-badge-uncertain', very_poor: 'ubr-badge-danger' }[key] || 'ubr-badge-unavailable';
    },
  }));

  Alpine.data('ubrActionCenter', () => ({
    loading: true,
    error: null,
    owner: 'all',
    data: null,
    priorities: { items: [], total: 0, page: 1, per_page: 10, loading: false },
    queue: { key: null, items: [], total: 0, page: 1, per_page: 20, loading: false, error: null, bucket: '', breakdown: null, filters: { scope: 'all', min_lead: '', confidence: '', search: '', sort: 'rank', industry: '', region: '', redesign: '', recommendation: '', manual_review: '', call_kind: '' } },
    primaryQueues: ['top_opportunities', 'needs_attention', 'ready_to_contact', 'followups'],
    busy: {},
    _refreshTimer: null,
    _searchTimer: null,

    can,
    priorityBadge: (p) => PRIORITY_BADGE[p] || 'ubr-badge-unavailable',
    priorityLabel: (p) => PRIORITY_LABEL[p] || p,

    async init() {
      try {
        const saved = localStorage.getItem('ubr_ac_owner');
        if (['all', 'mine', 'unassigned'].includes(saved)) this.owner = saved;
      } catch (e) { /* brak dostępu do storage — domyślnie Team */ }
      const params = new URLSearchParams(window.location.search);
      await this.load();
      const q = params.get('queue');
      this.openQueue(q && this.queueDef(q) ? q : this.defaultQueue(), params.get('bucket') || '', false);
      // Zmiany wykonane w karcie firmy (notatka, follow-up, status) lub akcje w tle → odśwież liczniki i listy.
      for (const ev of ['ubr:prospect-saved', 'ubr:analysis-queued', 'ubr:contacts-queued', 'ubr:company-verified', 'ubr:manual-review-saved']) window.addEventListener(ev, () => this.scheduleRefresh());
      this.$watch('$store.ubr.drawer.open', (open) => { if (!open) this.scheduleRefresh(); });
    },

    async load(silent = false) {
      if (!silent) this.loading = true;
      this.error = null;
      try {
        this.data = await api('/action-center', { query: { owner: this.owner } });
        this.priorities = { ...this.data.priorities, items: this.data.priorities.items.map(normalize), loading: false };
      } catch (e) {
        this.error = e.message;
      } finally {
        this.loading = false;
      }
    },

    scheduleRefresh() {
      clearTimeout(this._refreshTimer);
      this._refreshTimer = setTimeout(async () => {
        await this.load(true);
        if (this.queue.key) this.loadQueue(this.queue.page, true);
      }, 600);
    },

    setOwner(owner) {
      if (this.owner === owner) return;
      this.owner = owner;
      try { localStorage.setItem('ubr_ac_owner', owner); } catch (e) { /* ignoruj */ }
      this.load(true).then(() => this.loadQueue(1));
    },

    async loadPriorities(page) {
      this.priorities.loading = true;
      try {
        const res = await api('/action-center/priorities', { query: { owner: this.owner, page } });
        this.priorities = { ...res, items: res.items.map(normalize), loading: false };
      } catch (e) {
        this.$store.ubr.error(e);
        this.priorities.loading = false;
      }
    },

    get priorityPages() {
      return Math.max(1, Math.ceil(this.priorities.total / this.priorities.per_page));
    },

    /* ---------------------------- Smart Queues ---------------------------- */

    queueDef(key) {
      return (this.data?.queues || []).find((q) => q.key === key) || null;
    },
    defaultQueue() {
      const qs = this.data?.queues || [];
      return (qs.find((q) => q.key === 'top_opportunities' && q.count) || qs.find((q) => q.key === 'needs_attention' && q.count) || qs[0] || {}).key || null;
    },
    openKpi(k) {
      if (k.link === 'prospects') { window.location.href = window.UBR.urls.prospects; return; }
      // Drill-down KPI zawsze bez filtrów listy — inaczej suma listy różni się od liczby na kafelku.
      Object.assign(this.queue.filters, { scope: 'all', min_lead: '', confidence: '', search: '', industry: '', region: '', redesign: '', recommendation: '', manual_review: '', call_kind: '' });
      this.openQueue(k.queue, k.bucket || '');
    },
    get filtersActive() {
      const f = this.queue.filters;
      return !!(f.min_lead || f.confidence || f.search || f.industry || f.region || f.redesign || f.recommendation || f.manual_review || f.call_kind || f.scope !== 'all');
    },
    resetFilters() {
      Object.assign(this.queue.filters, { scope: 'all', min_lead: '', confidence: '', search: '', industry: '', region: '', redesign: '', recommendation: '', manual_review: '', call_kind: '' });
      this.loadQueue(1);
    },
    openQueue(key, bucket = '', scroll = true) {
      if (!key) return;
      if (this.queue.key !== key) {
        Object.assign(this.queue, { items: [], total: 0, page: 1, breakdown: null }); // inny kształt pozycji (follow-up vs firma)
        this.queue.filters.call_kind = '';
        this.queue.filters.sort = key === 'top_opportunities' ? 'rank' : 'priority'; // Top Opportunities = Smart Ranking
      }
      this.queue.key = key;
      this.queue.bucket = bucket;
      this.loadQueue(1);
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('queue', key);
        if (bucket) url.searchParams.set('bucket', bucket); else url.searchParams.delete('bucket');
        window.history.replaceState({}, '', url);
      } catch (e) { /* ignoruj */ }
      if (scroll) this.$nextTick(() => document.getElementById('ubr-queues')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
    async loadQueue(page = 1, silent = false) {
      if (!this.queue.key) return;
      if (!silent) this.queue.loading = true;
      this.queue.error = null;
      const f = this.queue.filters;
      const query = { owner: this.owner, page, per_page: this.queue.per_page, sort: f.sort, scope: f.scope, min_lead: f.min_lead, confidence: f.confidence, search: f.search, industry: f.industry, region: f.region, redesign: f.redesign, recommendation: f.recommendation, manual_review: f.manual_review, call_kind: this.queue.key === 'ready_to_call' ? f.call_kind : '', bucket: this.queue.bucket };
      try {
        const res = await api(`/action-center/queues/${this.queue.key}`, { query });
        Object.assign(this.queue, { items: res.items.map(normalize), total: res.total, page: res.page, per_page: res.per_page, breakdown: res.breakdown || null });
      } catch (e) {
        this.queue.error = e.message;
      } finally {
        this.queue.loading = false;
      }
    },
    searchQueue() {
      clearTimeout(this._searchTimer);
      this._searchTimer = setTimeout(() => this.loadQueue(1), 300);
    },
    get queuePages() {
      return Math.max(1, Math.ceil(this.queue.total / this.queue.per_page));
    },
    get isFollowupQueue() {
      return this.queue.key === 'followups';
    },

    /* ---------------------------- Quick Actions (istniejąca logika) ---------------------------- */

    openCompany(it, tab = 'company') {
      this.$store.ubr.drawer.tab = tab;
      this.$store.ubr.openCompany(it.company.id);
    },
    runCta(it) {
      const a = it.cta?.action;
      if (a === 'open_sales') return this.openCompany(it, 'sales');
      if (a === 'open_contacts') return this.openCompany(it, 'contacts');
      if (a === 'open_lead') return this.openCompany(it, 'lead');
      if (a === 'preview') return this.$store.ubr.openPreview(it.company);
      if (a === 'find_dm') return this.findDm(it);
      if (a === 'verify') return this.verify(it);
      if (a === 'analyze') return this.analyze(it);
      return this.openCompany(it);
    },
    ctaDisabled(it) {
      const a = it.cta?.action;
      if (a === 'find_dm') return !can('contacts.run') || ['queued', 'running'].includes(it.contacts_status) || !!this.busy[it.company.id];
      if (a === 'verify') return !can('companies.verify') || !!this.busy[it.company.id];
      if (a === 'analyze') return !can('analysis.run') || !it.company.domain || ['queued', 'running'].includes(it.analysis_status) || !!this.busy[it.company.id];
      if (a === 'preview') return !it.company.domain;
      return false;
    },
    ctaLabel(it) {
      if (it.cta?.action === 'analyze' && ['queued', 'running'].includes(it.analysis_status)) return 'Analiza w toku…';
      if (it.cta?.action === 'find_dm' && ['queued', 'running'].includes(it.contacts_status)) return 'Wyszukiwanie w toku…';
      return it.cta?.label || 'Otwórz';
    },
    async withBusy(it, fn) {
      this.busy[it.company.id] = true;
      try {
        await fn();
      } finally {
        delete this.busy[it.company.id];
        this.scheduleRefresh();
      }
    },
    analyze(it) {
      return this.withBusy(it, () => this.$store.ubr.analyze(it.company.id, !!it.analysis_status && !['queued', 'running'].includes(it.analysis_status)));
    },
    findDm(it) {
      return this.withBusy(it, () => this.$store.ubr.findDecisionMakers(it.company.id, !!it.contacts_status));
    },
    verify(it) {
      return this.withBusy(it, async () => {
        const res = await api(`/companies/${it.company.id}/verify`, { method: 'POST' }).catch((e) => { this.$store.ubr.error(e); return null; });
        if (res) this.$store.ubr.toast(res.message);
      });
    },
    addToProspects(it) {
      return this.withBusy(it, () => this.$store.ubr.saveProspect({ id: it.company.id, name: it.company.name }));
    },
    async completeFollowup(it) {
      if (!it.followup) return;
      await this.withBusy(it, async () => {
        try {
          await api(`/followups/${it.followup.id}`, { method: 'POST', body: { status: 'completed' } });
          this.$store.ubr.toast('Oznaczono jako wykonane.');
        } catch (e) {
          this.$store.ubr.error(e);
        }
      });
    },
    async snooze(it, days) {
      await this.withBusy(it, async () => {
        try {
          await api('/action-center/snoozes', { method: 'POST', body: { company_id: it.company.id, action_type: it.actionType, days } });
          this.$store.ubr.toast(`Odłożono „${it.company.name}” o ${days} ${days === 1 ? 'dzień' : 'dni'}.`);
        } catch (e) {
          this.$store.ubr.error(e);
        }
      });
    },
    async unsnooze(it) {
      await this.withBusy(it, async () => {
        try {
          await api('/action-center/snoozes', { method: 'DELETE', query: { company_id: it.company.id, action_type: it.actionType } });
          this.$store.ubr.toast('Przywrócono działanie.');
        } catch (e) {
          this.$store.ubr.error(e);
        }
      });
    },

    /* ---------------------------- prezentacja ---------------------------- */

    dueLabel(iso) {
      if (!iso) return '';
      const d = new Date(iso);
      const diff = Math.round((d - Date.now()) / 60000);
      const abs = Math.abs(diff);
      const span = abs < 60 ? `${abs} min` : abs < 1440 ? `${Math.round(abs / 60)} godz.` : `${Math.round(abs / 1440)} dni`;
      return diff < 0 ? `po terminie ${span}` : `za ${span}`;
    },
    statusLabel(s) {
      return { queued: 'w kolejce', running: 'w toku', completed: 'zakończona', partial: 'częściowa', failed: 'błąd' }[s] || 'brak';
    },
  }));
}
