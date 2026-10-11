/**
 * Ugly but Rich — panel SaaS (Alpine.js). Logika biznesowa jest po stronie serwera (REST ubr/v1);
 * tutaj tylko stan UI i wywołania API.
 */
import Alpine from 'alpinejs';
import { api } from './ubr/api.js';
import { salesStore, registerPipeline, localInput } from './ubr/pipeline.js';
import { leadStore } from './ubr/lead.js';
import { registerUi, livePreviewUrl, safeWebsiteUrl } from './ubr/ui.js';
import { registerDashboard } from './ubr/dashboard.js';
import { callingStore, CALL_FILTERS, activeCount } from './ubr/calling.js';

const CONFIDENCE = { confirmed: 'Potwierdzona', declared: 'Wg źródła', uncertain: 'Niepewna', unavailable: 'Brak' };
const LOCATION = {
  confirmed: 'potwierdzona',
  declared: 'wg danych źródła',
  filtered: 'tylko filtr źródła',
  partial: 'częściowo zgodna',
  unknown: 'brak danych',
  unavailable: 'brak danych',
  mismatch: 'niezgodna',
  not_required: 'wg danych źródła',
};
const CRITERION_BADGE = {
  confirmed: 'ubr-badge-confirmed',
  declared: 'ubr-badge-declared',
  filtered: 'ubr-badge-uncertain',
  partial: 'ubr-badge-uncertain',
  unknown: 'ubr-badge-unavailable',
  unavailable: 'ubr-badge-unavailable',
  mismatch: 'ubr-badge-danger',
  not_required: 'ubr-badge-unavailable',
};
const can = (ability) => (window.UBR?.abilities || []).includes(ability);
const LEVELS = { very_high: 'Bardzo wysoka szansa', high: 'Wysoka szansa', moderate: 'Umiarkowana szansa', low: 'Niska szansa', insufficient: 'Za mało danych' };
const CONF = { high: 'wysoka', medium: 'średnia', low: 'niska' };
const SEVERITY = { critical: 'Krytyczny', high: 'Wysoki', medium: 'Średni', low: 'Niski', info: 'Info' };
const SOURCE = { automated: 'pomiar automatyczny', ai: 'ocena AI', manual_review: 'do ręcznej weryfikacji' };
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex="-1"])';
const WEBSITE_STATUS = {
  unchecked: 'Strona nie została jeszcze sprawdzona',
  reachable: 'Strona odpowiada',
  redirected: 'Przekierowanie na inną domenę',
  http_error: 'Strona zwraca błąd HTTP',
  unreachable: 'Strona nie odpowiada',
};
const STATUS = { queued: 'W kolejce', running: 'W toku', completed: 'Gotowe', failed: 'Błąd', cancelled: 'Anulowane' };
const CONTACT_STATUS = { verified: 'ubr-badge-confirmed', probable: 'ubr-badge-declared', uncertain: 'ubr-badge-uncertain', outdated: 'ubr-badge-unavailable', rejected: 'ubr-badge-danger' };
const SOURCE_STATE = { ok: 'działa', partial: 'częściowo', error: 'błąd', not_applicable: 'nie dotyczy', skipped: 'pominięte', disabled: 'wyłączone', not_configured: 'nieskonfigurowane', not_found: 'brak danych', enabled: 'włączone' };

const withQuery = (url, params) => {
  const u = new URL(url, window.location.origin);
  Object.entries(params).forEach(([k, v]) => (v ? u.searchParams.set(k, v) : u.searchParams.delete(k)));
  return u.pathname + u.search;
};

document.addEventListener('alpine:init', () => {
  Alpine.store('ubr', {
    ...salesStore(),
    ...leadStore(),
    ...callingStore(),
    toasts: [],
    drawer: { open: false, loading: false, saving: false, error: null, company: null, tab: 'company', analysis: null, analysisLoading: false, compare: null, compareIds: [], contacts: null, contactsLoading: false, sales: null, salesLoading: false, clickup: null, lead: null, leadLoading: false, phoneScan: null, apollo: null, apolloBusy: false, apolloConsent: false, apolloFocus: false },
    preview: { open: false, loading: false, company: null, liveUrl: null, openUrl: null, upgraded: false, analysis: null, mode: 'live', view: 'desktop', zoom: 1, fit: true, error: null, frameKey: 0, returnFocus: null },

    toast(message, type = 'info') {
      const id = Date.now() + Math.random();
      this.toasts.push({ id, message, type });
      setTimeout(() => this.dismiss(id), type === 'error' ? 7000 : 3500);
    },
    dismiss(id) {
      this.toasts = this.toasts.filter((t) => t.id !== id);
    },
    error(e) {
      this.toast(e?.message || 'Wystąpił błąd.', 'error');
    },

    confidenceLabel: (v) => CONFIDENCE[v] || CONFIDENCE.unavailable,
    confidenceBadge: (v) => CRITERION_BADGE[v] || 'ubr-badge-unavailable',
    locationLabel: (v) => 'Lokalizacja: ' + (LOCATION[v] || LOCATION.unknown),
    locationBadge: (v) => CRITERION_BADGE[v] || 'ubr-badge-unavailable',
    criterionBadge: (v) => CRITERION_BADGE[v] || 'ubr-badge-unavailable',
    relevanceClass(score) {
      const base = 'inline-grid place-items-center min-w-9 h-7 px-1.5 rounded-lg text-[13px] font-extrabold tabular-nums ';
      if (score === null || score === undefined) return base + 'bg-canvas text-ink-muted';
      if (score >= 70) return base + 'bg-money-soft text-money';
      if (score >= 40) return base + 'bg-gold-soft text-gold-deep';
      return base + 'bg-danger-soft text-danger';
    },
    verificationBadge: (v) =>
      ({ verified: 'ubr-badge-confirmed', uncertain: 'ubr-badge-uncertain', inactive: 'ubr-badge-danger', suspended: 'ubr-badge-danger' })[v] || 'ubr-badge-unavailable',
    // Prompt 10.4 — statusy etapów przetwarzania firmy (Discover): odznaka weryfikacji i kropki pozostałych etapów.
    stageBadge: (tone) =>
      ({ good: 'ubr-badge-confirmed', warn: 'ubr-badge-uncertain', danger: 'ubr-badge-danger', active: 'ubr-badge-declared', info: 'ubr-badge-declared' })[tone] || 'ubr-badge-unavailable',
    stageDot: (tone) =>
      ({ good: 'bg-money', warn: 'bg-gold', danger: 'bg-danger', active: 'bg-ink-soft animate-pulse', info: 'bg-ink-soft' })[tone] || 'bg-ink-muted/40',
    domainBadge: (v) =>
      ({ confirmed: 'ubr-badge-confirmed', uncertain: 'ubr-badge-uncertain', mismatch: 'ubr-badge-danger', unavailable: 'ubr-badge-danger' })[v] || 'ubr-badge-unavailable',
    can,
    websiteStatusLabel: (v) => WEBSITE_STATUS[v] || v,
    statusLabel: (v) => STATUS[v] || v,
    statusBadge: (v) =>
      ({ completed: 'ubr-badge-confirmed', running: 'ubr-badge-uncertain', queued: 'ubr-badge-uncertain', failed: 'ubr-badge-danger' })[v] ||
      'ubr-badge-unavailable',
    date(iso) {
      if (!iso) return '—';
      return new Date(iso).toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    },

    levelLabel: (v) => LEVELS[v] || '—',
    confLabel: (v) => CONF[v] || '—',
    severityLabel: (v) => SEVERITY[v] || v,
    sourceLabel: (v) => SOURCE[v] || v,
    severityBadge: (v) => ({ critical: 'ubr-badge-danger', high: 'ubr-badge-danger', medium: 'ubr-badge-uncertain', low: 'ubr-badge-declared' })[v] || 'ubr-badge-unavailable',
    analysisBadge: (v) => ({ completed: 'ubr-badge-confirmed', partial: 'ubr-badge-uncertain', queued: 'ubr-badge-uncertain', running: 'ubr-badge-uncertain', failed: 'ubr-badge-danger' })[v] || 'ubr-badge-unavailable',
    opportunityClass(score) {
      const base = 'inline-grid place-items-center min-w-10 h-7 px-1.5 rounded-lg text-[13px] font-extrabold tabular-nums ';
      if (score === null || score === undefined) return base + 'bg-canvas text-ink-muted';
      if (score >= 80) return base + 'bg-ink text-gold';
      if (score >= 60) return base + 'bg-gold text-ink';
      if (score >= 40) return base + 'bg-gold-soft text-gold-deep';
      return base + 'bg-canvas text-ink-soft';
    },

    /* ---------------------------- Decision Maker Finder ---------------------------- */
    contactStatusBadge: (v) => CONTACT_STATUS[v] || 'ubr-badge-unavailable',
    contactsBadge: (v) => ({ completed: 'ubr-badge-confirmed', partial: 'ubr-badge-uncertain', queued: 'ubr-badge-uncertain', running: 'ubr-badge-uncertain', failed: 'ubr-badge-danger' })[v] || 'ubr-badge-unavailable',
    contactConfBadge: (v) => ({ high: 'ubr-badge-confirmed', medium: 'ubr-badge-declared', low: 'ubr-badge-uncertain' })[v] || 'ubr-badge-unavailable',
    sourceStateLabel: (v) => SOURCE_STATE[v] || v,
    contactScoreClass(score) {
      const base = 'inline-grid place-items-center min-w-10 h-7 px-1.5 rounded-lg text-[13px] font-extrabold tabular-nums ';
      if (score === null || score === undefined) return base + 'bg-canvas text-ink-muted';
      if (score >= 75) return base + 'bg-money-soft text-money';
      if (score >= 50) return base + 'bg-gold-soft text-gold-deep';
      return base + 'bg-canvas text-ink-soft';
    },

    /** Zlecenie wyszukiwania decydentów (pojedyncze lub zbiorcze) — w tle, bez blokowania UI. */
    async findDecisionMakers(companyIds, force = false) {
      const ids = [].concat(companyIds);
      try {
        const res = ids.length === 1
          ? await api(`/companies/${ids[0]}/contacts/research`, { method: 'POST', body: { force } })
          : await api('/contacts/research', { method: 'POST', body: { company_ids: ids, force } });
        this.toast(res.message, res.queued.length ? 'info' : 'error');
        window.dispatchEvent(new CustomEvent('ubr:contacts-queued', { detail: { companyIds: res.queued.map((q) => q.company_id) } }));
        if (this.drawer.open && this.drawer.company && ids.includes(this.drawer.company.id)) this.loadContacts(true);
        return res;
      } catch (e) {
        this.error(e);
        return null;
      }
    },

    async loadContacts(poll = false) {
      const id = this.drawer.company?.id;
      if (!id) return;
      if (!poll) this.drawer.contactsLoading = true;
      try {
        const data = await api(`/companies/${id}/contacts`);
        if (this.drawer.company?.id !== id) return;
        this.drawer.contacts = data;
        if (!poll && !this.drawer.apollo) this.loadApollo(id);
        clearTimeout(this._contactsTimer);
        if (data.active && this.drawer.open) {
          this._contactsTimer = setTimeout(() => this.loadContacts(true), 5000);
        } else if (poll) {
          window.dispatchEvent(new CustomEvent('ubr:contacts-finished', { detail: { companyId: id } }));
        }
      } catch (e) {
        this.drawer.error = e.message;
      } finally {
        this.drawer.contactsLoading = false;
      }
    },

    /** Przegląd ręczny (prywatny dla organizacji): zatwierdź / odrzuć / nieaktualny / wyklucz / preferowany / notatka. */
    async reviewContact(contact, action, note = null) {
      try {
        this.drawer.contacts = await api(`/contacts/${contact.id}/review`, { method: 'POST', body: { action, note } });
        window.dispatchEvent(new CustomEvent('ubr:contacts-finished', { detail: { companyId: this.drawer.company?.id } }));
        this.toast(action === 'note' ? 'Zapisano notatkę (widoczna tylko w Twojej organizacji).' : 'Zapisano decyzję (tylko dla Twojej organizacji).');
      } catch (e) {
        this.error(e);
      }
    },

    // Prompt 10.2 — powiązania numer ↔ decydent (decyzje prywatne organizacji; numer firmy nigdy nie jest usuwany).
    async phoneLink(contact, body) {
      try {
        const r = await api(`/contacts/${contact.id}/phone-links`, { method: 'POST', body });
        contact.phone_links = r.phone_links;
        window.dispatchEvent(new CustomEvent('ubr:contacts-finished', { detail: { companyId: this.drawer.company?.id } }));
        this.toast(r.message);
      } catch (e) {
        this.error(e);
      }
    },

    async removePhoneLink(contact, link) {
      try {
        const r = await api(`/contacts/${contact.id}/phone-links/${link.id}`, { method: 'DELETE' });
        contact.phone_links = r.phone_links;
        window.dispatchEvent(new CustomEvent('ubr:contacts-finished', { detail: { companyId: this.drawer.company?.id } }));
        this.toast(r.message);
      } catch (e) {
        this.error(e);
      }
    },

    async scanDecisionPhones(companyId) {
      if (this.drawer.phoneScan?.busy) return;
      this.drawer.phoneScan = { busy: true, result: null };
      try {
        const result = await api(`/companies/${companyId}/decision-phones/scan`, { method: 'POST' });
        this.drawer.phoneScan = { busy: false, result };
        this.drawer.contacts = await api(`/companies/${companyId}/contacts`);
        window.dispatchEvent(new CustomEvent('ubr:contacts-finished', { detail: { companyId } }));
        this.toast(result.message);
      } catch (e) {
        this.drawer.phoneScan = { busy: false, result: null };
        this.error(e);
      }
    },

    // Prompt 10.3 — Apollo Contact Matching: kandydaci z bazy (bez zapytań do Apollo przy otwarciu karty).
    async loadApollo(companyId) {
      try {
        const data = await api(`/companies/${companyId}/apollo-candidates`);
        if (this.drawer.company?.id === companyId) this.drawer.apollo = { ...data, search: this.drawer.apollo?.search || null };
      } catch (e) {
        if (this.drawer.company?.id === companyId) this.drawer.apollo = { error: e.message, candidates: [] };
      }
    },

    /** „Szukaj decydentów w Apollo” — zapytanie tylko po zaznaczeniu zgody (confirm); bez zgody serwer nic nie wysyła. */
    async searchApollo(companyId) {
      if (this.drawer.apolloBusy) return;
      this.drawer.apolloBusy = true;
      try {
        const data = await api(`/companies/${companyId}/apollo-candidates/search`, { method: 'POST', body: { confirm: !!this.drawer.apolloConsent } });
        if (this.drawer.company?.id !== companyId) return;
        this.drawer.apollo = data;
        this.drawer.apolloConsent = false;
        this.toast(data.search?.message || 'Gotowe.', data.search?.state === 'error' ? 'error' : 'info');
      } catch (e) {
        this.error(e);
      } finally {
        this.drawer.apolloBusy = false;
      }
    },

    async apolloDecide(match, action) {
      const companyId = this.drawer.company?.id;
      try {
        const data = await api(`/apollo-matches/${match.id}`, { method: 'POST', body: { action } });
        this.drawer.apollo = { ...data, search: this.drawer.apollo?.search || null };
        this.toast({ approve: 'Dopasowanie zatwierdzone — identyfikator Apollo przypisany do kontaktu (tylko w Twojej organizacji).', reject: 'Dopasowanie odrzucone.', detach: 'Identyfikator Apollo odłączony od kontaktu.', recheck: 'Sprawdzono ponownie (bez zapytania do Apollo).' }[action] || 'Zapisano.');
        window.dispatchEvent(new CustomEvent('ubr:contacts-finished', { detail: { companyId } }));
      } catch (e) {
        this.error(e);
      }
    },

    async apolloLink(candidate, contactId) {
      if (!contactId) return;
      const companyId = this.drawer.company?.id;
      try {
        const data = await api(`/apollo-candidates/${candidate.id}/link`, { method: 'POST', body: { contact_id: Number(contactId) } });
        this.drawer.apollo = { ...data, search: this.drawer.apollo?.search || null };
        this.toast('Połączono kandydata Apollo z kontaktem (tylko w Twojej organizacji).');
        window.dispatchEvent(new CustomEvent('ubr:contacts-finished', { detail: { companyId } }));
      } catch (e) {
        this.error(e);
      }
    },

    /** Lista → karta firmy, sekcja Apollo (tylko odczyt z bazy). */
    checkApollo(companyId) {
      this.drawer.tab = 'contacts';
      this.openCompany(companyId).then(() => {
        this.drawer.apolloFocus = true;
        setTimeout(() => document.querySelector('[data-apollo-section]')?.scrollIntoView({ block: 'start', behavior: 'smooth' }), 400);
      });
    },

    async addContact(form) {
      try {
        this.drawer.contacts = await api(`/companies/${this.drawer.company.id}/contacts`, { method: 'POST', body: form });
        this.toast('Dodano kontakt (USER_PROVIDED — widoczny tylko w Twojej organizacji).');
        return true;
      } catch (e) {
        this.error(e);
        return false;
      }
    },

    escape() {
      if (this.outcome.open) return this.closeOutcome();
      if (this.reveal.open) return this.closeReveal();
      if (this.preview.open) return this.closePreview();
      this.closeDrawer();
    },

    /** Zlecenie analizy (pojedynczej lub zbiorczej) — odpowiedź natychmiast, analiza w tle. */
    async analyze(companyIds, force = false) {
      const ids = [].concat(companyIds);
      try {
        const res = ids.length === 1 ? await api(`/companies/${ids[0]}/analyze`, { method: 'POST', body: { force } }) : await api('/analyses', { method: 'POST', body: { company_ids: ids, force } });
        this.toast(res.message, res.queued.length ? 'info' : 'error');
        window.dispatchEvent(new CustomEvent('ubr:analysis-queued', { detail: { companyIds: res.queued.map((q) => q.company_id) } }));
        if (this.drawer.open && this.drawer.company && ids.includes(this.drawer.company.id)) this.loadAnalysis(true);
        return res;
      } catch (e) {
        this.error(e);
        return null;
      }
    },

    async loadAnalysis(poll = false) {
      const id = this.drawer.company?.id;
      if (!id) return;
      if (!poll) this.drawer.analysisLoading = true;
      try {
        const data = await api(`/companies/${id}/analysis`);
        if (this.drawer.company?.id !== id) return;
        this.drawer.analysis = data;
        if (data.active && this.drawer.open) {
          clearTimeout(this._analysisTimer);
          this._analysisTimer = setTimeout(() => this.loadAnalysis(true), 5000);
        } else if (poll) {
          window.dispatchEvent(new CustomEvent('ubr:analysis-finished', { detail: { companyId: id } }));
        }
      } catch (e) {
        this.drawer.error = e.message;
      } finally {
        this.drawer.analysisLoading = false;
      }
    },

    setTab(tab) {
      this.drawer.tab = tab;
      if (tab === 'analysis' && !this.drawer.analysis) this.loadAnalysis();
      if (tab === 'contacts' && !this.drawer.contacts) this.loadContacts();
      if (tab === 'sales' && !this.drawer.sales) this.loadSales();
      if (tab === 'lead' && !this.drawer.lead) this.loadLead();
    },

    toggleCompare(id) {
      const ids = this.drawer.compareIds;
      this.drawer.compareIds = ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id].slice(-2);
      this.drawer.compare = null;
    },

    async runCompare() {
      const [a, b] = this.drawer.compareIds;
      try {
        this.drawer.compare = await api('/analyses/compare', { query: { a, b } });
      } catch (e) {
        this.error(e);
      }
    },

    /**
     * Podgląd strony (wspólny modal dla Discover, Prospects i karty firmy). Domyślnie LIVE: aktualna strona w iframe
     * (adres z zapisanych danych firmy, tylko http/https). SCREENSHOT: ostatnie zrzuty Website Analyzera — dane analizy
     * pobierane dopiero po otwarciu modala; nic nie jest analizowane ani generowane.
     */
    /** Bezpieczny zewnętrzny link do :href — tylko http/https (javascript:, data: itp. → brak atrybutu href). */
    safeHref(url) {
      return safeWebsiteUrl(url);
    },
    async openPreview(company, view = 'desktop', mode = 'live') {
      // Element, do którego wraca fokus po zamknięciu: aktywny element, a gdy kliknięcie myszą go nie ustawiło — kliknięty przycisk.
      const active = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
      const clicked = window.event?.target?.closest?.('button, a, [tabindex]') || null;
      // Aplikacja na https → podgląd wersji https strony (iframe http byłby zablokowany); „Otwórz stronę” używa zapisanego adresu.
      const { url: liveUrl, upgraded } = livePreviewUrl(company.website_url, window.location.protocol);
      this.preview = {
        open: true,
        loading: true,
        company: { id: company.id, name: company.name, website_url: company.website_url, domain: company.domain },
        liveUrl,
        openUrl: safeWebsiteUrl(company.website_url),
        upgraded,
        analysis: null,
        mode: liveUrl ? mode : 'screenshot',
        view: ['desktop', 'mobile'].includes(view) ? view : 'desktop',
        zoom: 1,
        fit: true,
        error: null,
        frameKey: Date.now(),
        returnFocus: active || clicked,
      };
      document.documentElement.classList.add('overflow-hidden');
      // Fokus na „Zamknij” — ponawiane, bo przycisk staje się widoczny dopiero po starcie przejścia x-show.
      const focusClose = (n) => {
        const b = document.querySelector('[data-preview-close]');
        b?.focus();
        if (this.preview.open && document.activeElement !== b && n > 0) setTimeout(() => focusClose(n - 1), 40);
      };
      setTimeout(() => focusClose(25), 0);
      try {
        const data = await api(`/companies/${company.id}/analysis`);
        if (this.preview.company?.id !== company.id) return;
        this.preview.analysis = data.latest && Object.keys(data.latest.screenshots || {}).length ? data.latest : null;
        this.preview.active = data.active;
        if (!this.preview.analysis) this.preview.error = data.active ? 'Analiza strony trwa — screenshoty pojawią się po jej zakończeniu.' : 'Brak zapisanych screenshotów tej strony.';
      } catch (e) {
        this.preview.error = e.message;
      } finally {
        this.preview.loading = false;
      }
    },
    setPreviewMode(mode) {
      if (mode === 'live' && !this.preview.liveUrl) return;
      this.preview.mode = mode;
      this.preview.fit = true;
    },
    closePreview() {
      const back = this.preview.returnFocus;
      this.preview.open = false;
      document.documentElement.classList.remove('overflow-hidden');
      // Bez requestAnimationFrame: w karcie w tle klatki są wstrzymane, a fokus musi wrócić od razu.
      if (back && typeof back.focus === 'function' && back.isConnected) back.focus({ preventScroll: true });
    },
    zoomPreview(delta) {
      this.preview.fit = false;
      this.preview.zoom = Math.min(2, Math.max(0.25, Math.round((this.preview.zoom + delta) * 100) / 100));
    },
    /** Pułapka fokusu w modalu (Tab / Shift+Tab nie wychodzą poza okno). */
    trapFocus(e, root) {
      const items = [...root.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        last.focus();
        e.preventDefault();
      } else if (!e.shiftKey && document.activeElement === last) {
        first.focus();
        e.preventDefault();
      }
    },

    async openCompany(id) {
      clearTimeout(this._analysisTimer);
      clearTimeout(this._contactsTimer);
      clearTimeout(this._clickupTimer);
      this.drawer = { open: true, loading: true, saving: false, error: null, company: null, tab: this.drawer.tab || 'company', analysis: null, analysisLoading: false, compare: null, compareIds: [], contacts: null, contactsLoading: false, sales: null, salesLoading: false, clickup: null, lead: null, leadLoading: false, phoneScan: null, apollo: null, apolloBusy: false, apolloConsent: false, apolloFocus: false };
      try {
        this.drawer.company = await api(`/companies/${id}`);
        if (this.drawer.tab === 'analysis') this.loadAnalysis();
        if (this.drawer.tab === 'contacts') this.loadContacts();
        if (this.drawer.tab === 'sales') this.loadSales();
        if (this.drawer.tab === 'lead') this.loadLead();
      } catch (e) {
        this.drawer.error = e.message;
      } finally {
        this.drawer.loading = false;
      }
    },
    closeDrawer() {
      clearTimeout(this._analysisTimer);
      clearTimeout(this._contactsTimer);
      clearTimeout(this._clickupTimer);
      this.drawer.open = false;
    },

    /** Ręczna ponowna weryfikacja — zlecenie do kolejki w tle; profil odświeża się po chwili. */
    async reverify(company) {
      this.drawer.saving = true;
      try {
        const res = await api(`/companies/${company.id}/verify`, { method: 'POST' });
        this.toast(res.message);
        const id = company.id;
        for (const delay of [4000, 8000, 15000]) {
          await new Promise((r) => setTimeout(r, delay));
          if (!this.drawer.open || this.drawer.company?.id !== id) return;
          const fresh = await api(`/companies/${id}`);
          if (this.drawer.company?.id === id) this.drawer.company = fresh;
          if (!fresh.verification.queue) break;
        }
        window.dispatchEvent(new CustomEvent('ubr:company-verified', { detail: { companyId: id } }));
      } catch (e) {
        this.error(e);
      } finally {
        this.drawer.saving = false;
      }
    },

    /** Zapis do prospektów — aktualizuje obiekt firmy w miejscu (tabela + szuflada). */
    async saveProspect(company, searchId = null) {
      this.drawer.saving = true;
      try {
        const res = await api('/prospects', { method: 'POST', body: { company_id: company.id, search_id: searchId } });
        company.prospect_id = res.prospect_id;
        if (this.drawer.company && this.drawer.company.id === company.id) this.drawer.company.prospect_id = res.prospect_id;
        window.dispatchEvent(new CustomEvent('ubr:prospect-saved', { detail: { companyId: company.id, prospectId: res.prospect_id } }));
        this.toast(res.created ? `Zapisano „${company.name}” do Prospects.` : 'Ta firma jest już w Prospects.');
        if (this.drawer.sales && this.drawer.company?.id === company.id) this.loadSales();
      } catch (e) {
        this.error(e);
      } finally {
        this.drawer.saving = false;
      }
    },

    async switchOrganization(id) {
      try {
        await api('/me/active-organization', { method: 'POST', body: { organization_id: id } });
        window.location.href = window.UBR.urls.dashboard;
      } catch (e) {
        this.error(e);
      }
    },
  });

  Alpine.data('ubrShell', () => ({
    sidebarOpen: false,
    init() {
      // Link z zadania ClickUp / udostępniony: ?company=ID otwiera kartę firmy (dostęp sprawdza backend).
      const params = new URLSearchParams(window.location.search);
      const id = Number(params.get('company'));
      if (id > 0) {
        if (['company', 'lead', 'sales', 'contacts', 'analysis'].includes(params.get('tab'))) this.$store.ubr.drawer.tab = params.get('tab');
        this.$store.ubr.openCompany(id);
      }
    },
  }));

  registerPipeline(Alpine);
  registerUi(Alpine);
  registerDashboard(Alpine);

  Alpine.data('ubrDashboard', () => ({
    loading: true,
    error: null,
    data: { stats: {}, recent_searches: [], recent_companies: [], pipeline: null, agenda: null, top_leads: [] },
    funnelMax() {
      return Math.max(1, ...(this.data.pipeline?.by_status || []).map((s) => s.count));
    },
    statusCount(key) {
      return (this.data.pipeline?.by_status || []).find((s) => s.key === key)?.count ?? 0;
    },
    loaded: false,
    /** Sekcja jest zwinięta (<details>) — /dashboard pobieramy dopiero przy pierwszym rozwinięciu, nie przy wejściu na stronę. */
    init() {
      if (this.$el.open) this.load();
    },
    async load() {
      if (this.loaded) return;
      this.loaded = true;
      try {
        this.data = await api('/dashboard');
      } catch (e) {
        this.error = e.message;
        this.loaded = false;
      } finally {
        this.loading = false;
      }
    },
  }));

  Alpine.data('ubrDiscover', () => ({
    options: { loaded: false, industries: [], regions: [], sizes: [], providers: [], limits: [25, 50, 100, 250, 500], apollo: { configured: false } },
    form: { industry: '', phrase: '', region: '', city: '', size: 'any', include_unknown_size: true, limit: 50, providers: [] },
    formError: null,
    starting: false,
    cancelling: false,
    history: [],
    search: null,
    results: { items: [], total: 0, page: 1, per_page: 25, loading: false },
    resultFilter: 'all',
    resultSort: 'relevance',
    analysisFilters: { min_opportunity: '', confidence: '', min_findings: '' },
    contactFilters: { has_contact: '', min_contact_score: '', contact_confidence: '', contact_role: '', min_contacts: '' },
    leadFilters: { min_lead: '', lead_confidence: '', redesign: '', recommendation: '', no_blockers: '' },
    callFilters: CALL_FILTERS(),
    get callFilterCount() {
      return activeCount(this.callFilters);
    },
    // Zwijany formularz: stan zapamiętany lokalnie (wygoda użytkownika), wartości pól zostają w stanie komponentu.
    formOpen: (() => {
      try {
        return window.localStorage.getItem('ubr_discover_form') !== 'collapsed';
      } catch (e) {
        return true;
      }
    })(),
    toggleForm(open = !this.formOpen) {
      this.formOpen = open;
      try {
        window.localStorage.setItem('ubr_discover_form', open ? 'open' : 'collapsed');
      } catch (e) {
        /* tryb prywatny / zablokowany storage — stan tylko w pamięci */
      }
    },
    get formSummary() {
      const f = this.form;
      const industry = this.options.industries.find((i) => i.key === f.industry)?.label;
      const region = this.options.regions.find((r) => r.key === f.region)?.label;
      const parts = [industry || f.phrase || 'dowolna branża', f.city || (region ? 'woj. ' + region : 'cała Polska'), 'maks. ' + f.limit, (f.providers || []).join(', ') || 'brak źródeł'];
      return parts.join(' · ');
    },
    contactsBusy: false,
    selected: [],
    analyzeBusy: false,
    verifyBusy: false,
    timer: null,
    resultsTimer: null,
    refreshing: false,
    refreshAgain: null,

    get canAnalyze() {
      return can('analysis.run');
    },
    get canFindContacts() {
      return can('contacts.run');
    },
    async findSelected() {
      this.contactsBusy = true;
      const res = await this.$store.ubr.findDecisionMakers(this.selected);
      this.contactsBusy = false;
      if (res) {
        this.selected = [];
        await this.loadResults(this.results.page || 1, true);
      }
    },
    get allSelected() {
      const ids = this.results.items.map((c) => c.id);
      return ids.length > 0 && ids.every((id) => this.selected.includes(id));
    },
    toggleAll() {
      const ids = this.results.items.map((c) => c.id);
      this.selected = this.allSelected ? this.selected.filter((id) => !ids.includes(id)) : [...new Set([...this.selected, ...ids])];
    },
    async analyzeSelected() {
      this.analyzeBusy = true;
      const res = await this.$store.ubr.analyze(this.selected);
      this.analyzeBusy = false;
      if (res) {
        this.selected = [];
        await this.loadResults(this.results.page || 1, true);
      }
    },
    /**
     * Prompt 10.4 — dopóki któryś etap firm na stronie jest w toku (weryfikacja, telefony, analiza, decydenci, ocena),
     * odświeżaj LEKKO: tylko zmienione wiersze widocznej strony (bez ponownego pobierania listy, bez migotania,
     * z zachowaniem filtrów, sortowania, rozwiniętych wierszy i pozycji przewijania).
     */
    watchAnalyses() {
      clearTimeout(this.resultsTimer);
      const busy = (c) => ['queued', 'running'].includes(c.analysis?.status) || ['queued', 'running'].includes(c.contacts?.status) || c.lead?.pending
        || ['pending', 'running'].includes(c.stages?.verification?.key) || ['pending', 'running'].includes(c.stages?.phones?.key);
      if (this.results.items.some(busy)) {
        this.resultsTimer = setTimeout(() => this.refreshChanged(), 4000);
      }
    },

    /** Lekkie odświeżenie: wiersze widocznej strony zmienione od ostatniego odświeżenia (`all` — wszystkie widoczne). */
    async refreshChanged(all = false) {
      if (!this.search || !this.results.items.length) return;
      if (this.refreshing) {
        this.refreshAgain = this.refreshAgain || (all ? 'all' : 'changed'); // nie gubimy odświeżenia po akcji użytkownika
        return;
      }
      this.refreshing = true;
      try {
        const data = await api(`/discovery/searches/${this.search.id}/results`, {
          query: { ids: this.results.items.map((c) => c.id).join(','), changed_since: all ? '' : this.results.server_time || '', per_page: 100, filter: this.resultFilter, sort: this.resultSort, ...this.analysisFilters, ...this.contactFilters, ...this.leadFilters, ...this.callFilters },
        });
        if (data.server_time) this.results.server_time = data.server_time;
        for (const row of data.items || []) {
          const i = this.results.items.findIndex((c) => c.id === row.id);
          if (i >= 0) this.results.items.splice(i, 1, row);
        }
      } catch (e) {
        // ciche — kolejne odświeżenie spróbuje ponownie
      } finally {
        this.refreshing = false;
        const again = this.refreshAgain;
        this.refreshAgain = null;
        if (again) this.refreshChanged(again === 'all');
        else this.watchAnalyses();
      }
    },

    get canVerify() {
      return can('companies.verify');
    },
    /** Kolejka weryfikacji firm z tego wyszukiwania jeszcze pracuje. */
    get verifying() {
      const q = this.search?.diagnostics?.verification_queue;
      return !!q && (q.queued || 0) + (q.running || 0) > 0;
    },
    /** Lokalizacja z filtra wyszukiwania (pokazywana jako filtr, nie jako dana firmy). */
    get filterLocationLabel() {
      const c = this.search?.criteria || {};
      const region = this.options.regions.find((r) => r.key === c.region)?.label;
      const where = c.city || (region ? 'woj. ' + region : '');
      return where ? `${where} (filtr)` : '—';
    },

    get isActive() {
      return this.search && ['queued', 'running'].includes(this.search.status);
    },
    get apolloEstimate() {
      const perPage = Math.min(this.options.apollo.per_page || 100, this.form.limit);
      const pages = Math.min(this.options.apollo.max_pages || 5, Math.ceil(this.form.limit / perPage));
      return `do ${pages} ${pages === 1 ? 'kredytu' : 'kredytów'} (${pages} ${pages === 1 ? 'strona' : 'strony'} × do ${perPage} firm)`;
    },

    get phaseLabel() {
      if (!this.search) return '';
      if (this.search.status === 'queued') return 'Czeka na uruchomienie w tle…';
      if (this.search.status === 'running') {
        const label = { apollo: 'Apollo', wikidata: 'Wikidata', osm: 'OpenStreetMap' }[this.search.current_provider];
        return label ? `Pobieranie: ${label}…` : 'Pobieranie firm ze źródeł…';
      }
      return { completed: 'Zakończono', failed: 'Zakończono z błędem', cancelled: 'Anulowano' }[this.search.status] || '';
    },

    async init() {
      try {
        const [options, history] = await Promise.all([api('/discovery/options'), api('/discovery/searches')]);
        this.options = { ...options, loaded: true };
        // Domyślnie: Apollo (główne), jeśli skonfigurowane; w przeciwnym razie źródła opcjonalne.
        const apollo = options.providers.find((p) => p.key === 'apollo' && p.available);
        this.form.providers = apollo ? ['apollo'] : options.providers.filter((p) => p.available).map((p) => p.key);
        this.history = history.items;
      } catch (e) {
        this.formError = e.message;
      }

      const fromUrl = new URLSearchParams(window.location.search).get('search');
      const initial = fromUrl || this.history.find((s) => ['queued', 'running'].includes(s.status))?.id;
      if (initial) this.open(Number(initial));

      window.addEventListener('ubr:prospect-saved', (e) => {
        const row = this.results.items.find((c) => c.id === e.detail.companyId);
        if (row) row.prospect_id = e.detail.prospectId;
      });
      window.addEventListener('ubr:company-verified', () => this.search && this.refreshChanged(true));
      window.addEventListener('ubr:analysis-queued', () => this.search && this.loadResults(this.results.page || 1, true));
      window.addEventListener('ubr:analysis-finished', () => this.search && this.refreshChanged(true));
      window.addEventListener('ubr:contacts-queued', () => this.search && this.loadResults(this.results.page || 1, true));
      window.addEventListener('ubr:contacts-finished', () => this.search && this.refreshChanged(true));
      window.addEventListener('ubr:lead-changed', () => this.search && this.refreshChanged(true));
      window.addEventListener('ubr:calling-changed', () => this.search && this.refreshChanged(true));
      window.addEventListener('ubr:pipeline-changed', () => this.search && this.refreshChanged(true));
    },

    destroy() {
      clearTimeout(this.timer);
      clearTimeout(this.resultsTimer);
    },

    async start() {
      this.formError = null;
      this.starting = true;
      try {
        const search = await api('/discovery/searches', { method: 'POST', body: this.form });
        this.history.unshift(search);
        this.$store.ubr.toast('Wyszukiwanie uruchomione w tle.');
        this.toggleForm(false); // po udanym starcie formularz zwija się (wartości zostają)
        await this.open(search.id);
      } catch (e) {
        this.formError = e.message;
      } finally {
        this.starting = false;
      }
    },

    async open(id) {
      clearTimeout(this.timer);
      this.results = { items: [], total: 0, page: 1, per_page: 25, loading: true };
      history.replaceState(null, '', withQuery(window.location.href, { search: id }));
      await this.poll(id, true);
    },

    async poll(id, first = false) {
      try {
        const previousCount = this.search?.id === id ? this.search.results_count : -1;
        const wasActive = this.search?.id === id && this.isActive;
        this.search = await api(`/discovery/searches/${id}`);
        const entry = this.history.find((s) => s.id === id);
        if (entry) Object.assign(entry, { status: this.search.status, results_count: this.search.results_count });

        // Nowe firmy (lub koniec wyszukiwania) → lista; inaczej tylko lekkie odświeżenie zmienionych wierszy.
        if (first || this.search.results_count !== previousCount || (wasActive && !this.isActive)) {
          await this.loadResults(this.results.page || 1, true);
        } else {
          await this.refreshChanged();
        }
      } catch (e) {
        this.$store.ubr.error(e);
        if (e.status === 404) this.search = null;
        return;
      }

      if (this.isActive) {
        this.timer = setTimeout(() => this.poll(id), 2500);
      } else if (this.verifying) {
        // Wyszukiwanie gotowe, weryfikacja w tle trwa — status (panel weryfikacji) co 6 s; wiersze odświeża lekko poll().
        this.timer = setTimeout(() => this.poll(id), 6000);
      }
    },

    async verifyAll() {
      this.verifyBusy = true;
      try {
        const res = await api(`/discovery/searches/${this.search.id}/verify`, { method: 'POST' });
        this.$store.ubr.toast(res.message);
        clearTimeout(this.timer);
        await this.poll(this.search.id);
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.verifyBusy = false;
      }
    },

    async loadResults(page = 1, silent = false) {
      if (!this.search) return;
      if (!silent) this.results.loading = true;
      try {
        const data = await api(`/discovery/searches/${this.search.id}/results`, {
          query: { page, per_page: this.results.per_page, filter: this.resultFilter, sort: this.resultSort, ...this.analysisFilters, ...this.contactFilters, ...this.leadFilters, ...this.callFilters },
        });
        this.results = { ...this.results, ...data, loading: false };
        this.watchAnalyses();
      } catch (e) {
        this.results.loading = false;
        this.$store.ubr.error(e);
      }
    },

    async retry() {
      this.starting = true;
      try {
        const search = await api(`/discovery/searches/${this.search.id}/retry`, { method: 'POST' });
        this.history.unshift(search);
        this.$store.ubr.toast('Wyszukiwanie ponowione.');
        await this.open(search.id);
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.starting = false;
      }
    },

    async cancel() {
      this.cancelling = true;
      try {
        this.search = { ...this.search, ...(await api(`/discovery/searches/${this.search.id}/cancel`, { method: 'POST' })) };
        this.$store.ubr.toast('Zażądano anulowania.');
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.cancelling = false;
      }
    },
  }));

  Alpine.data('ubrProspects', () => ({
    filters: { search: '', region: '', has_domain: '', verification: '', domain_status: '', min_relevance: '', min_opportunity: '', analysis: '', has_contact: '', contact_confidence: '', min_contact_score: '', pipeline_status: '', priority: '', owner: '', archived: '', min_lead: '', lead_confidence: '', redesign: '', recommendation: '', no_blockers: '' },
    callFilters: CALL_FILTERS(),
    moreFilters: false,
    pollTimer: null,
    selected: [],
    exportBusy: false,

    get canExport() {
      return can('clickup.export');
    },
    get allSelected() {
      const ids = this.list.items.map((c) => c.id);
      return ids.length > 0 && ids.every((id) => this.selected.includes(id));
    },
    toggleAll() {
      const ids = this.list.items.map((c) => c.id);
      this.selected = this.allSelected ? this.selected.filter((id) => !ids.includes(id)) : [...new Set([...this.selected, ...ids])];
    },
    async exportSelected() {
      this.exportBusy = true;
      const res = await this.$store.ubr.exportClickup(this.selected.slice(0, 20));
      this.exportBusy = false;
      if (res) this.selected = [];
    },
    sort: 'relevance',
    dir: 'desc',
    list: { items: [], total: 0, page: 1, per_page: 25, loading: true },
    regions: [],
    members: [],
    statuses: [],
    error: null,
    confirmId: null,

    get hasFilters() {
      return Object.values(this.filters).some(Boolean) || activeCount(this.callFilters) > 0;
    },

    init() {
      // Wejście z Discover („Otwórz w Prospects”): ?search=<nazwa firmy>.
      const q = new URLSearchParams(window.location.search).get('search');
      if (q) this.filters.search = q.slice(0, 120);
      this.load(1);
      window.addEventListener('ubr:calling-changed', () => this.load(this.list.page));
      window.addEventListener('ubr:company-verified', () => this.load(this.list.page));
      window.addEventListener('ubr:analysis-queued', () => this.load(this.list.page));
      window.addEventListener('ubr:analysis-finished', () => this.load(this.list.page));
      window.addEventListener('ubr:contacts-queued', () => this.load(this.list.page));
      window.addEventListener('ubr:contacts-finished', () => this.load(this.list.page));
      window.addEventListener('ubr:pipeline-changed', () => this.load(this.list.page));
      window.addEventListener('ubr:lead-changed', () => this.load(this.list.page));
      window.addEventListener('ubr:clickup-changed', () => setTimeout(() => this.load(this.list.page), 2500));
    },

    sortBy(key) {
      this.dir = this.sort === key && this.dir === 'asc' ? 'desc' : 'asc';
      this.sort = key;
      this.load(1);
    },

    async load(page = 1) {
      this.list.loading = true;
      this.error = null;
      try {
        const filters = { ...this.filters, owner: this.filters.owner === 'me' ? String(window.UBR.userId) : this.filters.owner };
        const data = await api('/prospects', { query: { ...filters, ...this.callFilters, sort: this.sort, dir: this.dir, page, per_page: this.list.per_page } });
        this.list = { ...this.list, ...data, loading: false };
        this.regions = data.regions;
        this.members = data.members || [];
        this.statuses = data.statuses || [];
        clearTimeout(this.pollTimer);
        if (data.items.some((c) => ['queued', 'running'].includes(c.analysis?.status) || ['queued', 'running'].includes(c.contacts?.status) || c.lead?.pending)) this.pollTimer = setTimeout(() => this.load(this.list.page), 6000);
      } catch (e) {
        this.error = e.message;
        this.list.loading = false;
      }
    },

    async restore(c) {
      try {
        await api(`/prospects/${c.prospect_id}/archive`, { method: 'POST', body: { restore: true } });
        this.$store.ubr.toast(`Przywrócono „${c.name}” do Prospects.`);
        await this.load(this.list.page);
      } catch (e) {
        this.$store.ubr.error(e);
      }
    },

    async remove(c) {
      try {
        await api(`/prospects/${c.prospect_id}`, { method: 'DELETE' });
        this.$store.ubr.toast(`Zarchiwizowano „${c.name}” (historia sprzedaży zachowana — można przywrócić).`);
        this.confirmId = null;
        await this.load(this.list.items.length === 1 && this.list.page > 1 ? this.list.page - 1 : this.list.page);
      } catch (e) {
        this.$store.ubr.error(e);
      }
    },
  }));

  Alpine.data('ubrSettings', () => ({
    me: null,
    org: null,
    orgName: '',
    displayName: '',
    newMember: { email: '', role: 'member' },
    newOrgName: '',
    saving: false,
    error: null,
    apollo: null,
    apolloBusy: false,
    apolloResult: null,
    confirmSearchTest: false,

    analyzer: null,
    analyzerBusy: false,
    contactsEnv: null,

    async init() {
      await this.refresh();
      await this.loadApollo();
      await this.loadAnalyzer();
      try {
        this.contactsEnv = await api('/contacts/status');
      } catch (e) {
        this.contactsEnv = null;
      }
    },

    async loadAnalyzer(check = false) {
      try {
        this.analyzer = await api('/analyzer/status', { query: check ? { check: 1 } : {} });
      } catch (e) {
        this.analyzer = null;
      }
    },

    async checkAnalyzer() {
      this.analyzerBusy = true;
      await this.loadAnalyzer(true);
      this.analyzerBusy = false;
    },

    async loadApollo() {
      try {
        this.apollo = await api('/integrations/apollo');
      } catch (e) {
        this.apollo = null;
      }
    },

    async testApollo() {
      this.apolloBusy = true;
      try {
        const r = await api('/integrations/apollo/test', { method: 'POST' });
        this.apolloResult = [{ label: 'Połączenie (auth/health)', ok: r.health.ok, message: r.health.message }];
        if (r.usage) this.apolloResult.push({ label: 'Limity API (usage stats)', ok: r.usage.ok, message: r.usage.message });
        await this.loadApollo();
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.apolloBusy = false;
      }
    },

    async testApolloSearch() {
      this.apolloBusy = true;
      this.confirmSearchTest = false;
      try {
        const r = await api('/integrations/apollo/test-search', { method: 'POST' });
        this.apolloResult = [{ label: 'Wyszukiwanie firm', ok: r.ok, message: r.message }];
        await this.loadApollo();
      } catch (e) {
        this.$store.ubr.error(e);
      } finally {
        this.apolloBusy = false;
      }
    },

    async refresh() {
      try {
        const [me, org] = await Promise.all([api('/me'), api('/organization')]);
        this.me = me;
        this.org = org;
        this.orgName = org.name;
        this.displayName = me.user.name;
      } catch (e) {
        this.error = e.message;
      }
    },

    can(ability) {
      return !!this.org && this.org.abilities.includes(ability);
    },
    roleLabel: (r) => ({ owner: 'Owner', admin: 'Admin', member: 'Member' })[r] || r,

    async run(fn, success) {
      this.saving = true;
      try {
        await fn();
        if (success) this.$store.ubr.toast(success);
        await this.refresh();
      } catch (e) {
        this.$store.ubr.error(e);
        await this.refresh();
      } finally {
        this.saving = false;
      }
    },

    rename() {
      return this.run(() => api('/organization', { method: 'POST', body: { name: this.orgName } }), 'Zapisano nazwę organizacji.');
    },
    addMember() {
      return this.run(async () => {
        await api('/organization/members', { method: 'POST', body: this.newMember });
        this.newMember.email = '';
      }, 'Dodano członka.');
    },
    changeRole(m, role) {
      return this.run(() => api(`/organization/members/${m.user_id}`, { method: 'POST', body: { role } }), 'Zmieniono rolę.');
    },
    removeMember(m) {
      return this.run(async () => {
        await api(`/organization/members/${m.user_id}`, { method: 'DELETE' });
        if (m.is_me) window.location.href = window.UBR.urls.dashboard;
      }, m.is_me ? 'Opuszczono organizację.' : 'Usunięto członka.');
    },
    saveProfile() {
      return this.run(() => api('/me/profile', { method: 'POST', body: { display_name: this.displayName } }), 'Zapisano profil.');
    },
    async createOrganization() {
      this.saving = true;
      try {
        await api('/organizations', { method: 'POST', body: { name: this.newOrgName } });
        window.location.href = window.UBR.urls.dashboard;
      } catch (e) {
        this.$store.ubr.error(e);
        this.saving = false;
      }
    },
  }));

  Alpine.data('ubrNoAccess', () => ({
    name: '',
    saving: false,
    error: null,
    async create() {
      this.saving = true;
      try {
        await api('/organizations', { method: 'POST', body: { name: this.name } });
        window.location.href = window.UBR.urls.dashboard;
      } catch (e) {
        this.error = e.message;
        this.saving = false;
      }
    },
  }));
});

window.ubrLocalInput = localInput;
window.Alpine = Alpine;
Alpine.start();
