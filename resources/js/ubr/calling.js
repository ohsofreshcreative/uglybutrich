/**
 * Cold Calling Workspace (Prompt 10): kopiowanie numeru, „Przejmij firmę”, wynik rozmowy, „Odblokuj telefon” (Apollo),
 * numery firmy w karcie, zakaz kontaktu. Logika i uprawnienia po stronie serwera — tu tylko stan UI i wywołania REST.
 *
 * Zasady: kliknięcie tel: NIE zapisuje rozmowy (wynik zapisuje się świadomie w oknie „Zapisz wynik”); odblokowanie numeru
 * wymaga okna potwierdzenia z informacją o nieznanym koszcie i osobnego potwierdzenia; podwójne kliknięcie nie wysyła
 * drugiego zlecenia (blokada w UI + idempotencja po stronie serwera).
 */
import { api } from './api.js';
import { automationMessage } from './claim.js';

/** Zdarzenie dla list: dane Cold Calling zmieniły się (przeładuj stronę wyników bez utraty filtrów). */
const changed = (companyId) => window.dispatchEvent(new CustomEvent('ubr:calling-changed', { detail: { companyId } }));

const READINESS_BADGE = { money: 'ubr-badge-confirmed', confirmed: 'ubr-badge-declared', uncertain: 'ubr-badge-uncertain', danger: 'ubr-badge-danger' };

/** Numer w formacie do schowka (E.164 jest jednoznaczny; z numerem wewnętrznym — zapis czytelny). */
export function clipboardNumber(phone) {
  if (!phone) return '';
  return phone.number && /wew\./.test(phone.number) ? phone.number : phone.e164 || phone.number || '';
}

/** Bezpieczny href tel: — tylko „tel:+cyfry” (opcjonalnie ;ext=cyfry); inaczej brak atrybutu. */
export function safeTel(value) {
  return typeof value === 'string' && /^tel:\+\d{6,15}(;ext=\d{1,6})?$/.test(value) ? value : null;
}

export function callingStore() {
  return {
    outcome: { open: false, company: null, contactId: null, value: '', note: '', saving: false, error: null, returnFocus: null },
    reveal: { open: false, loading: false, data: null, ack: false, busy: false, result: null, error: null, contactId: null, companyId: null, returnFocus: null },
    phones: { companyId: null, loading: false, data: null, adding: false, form: { number: '', type: 'MAIN', label: '' }, error: null, busy: false },
    callOutcomes: {
      no_answer: 'Nie odebrał',
      call_back: 'Oddzwonić później',
      not_interested: 'Nie zainteresowany',
      interested: 'Zainteresowany',
      meeting_booked: 'Umówiono spotkanie',
      wrong_number: 'Błędny numer',
    },

    readinessBadge(tone) {
      return READINESS_BADGE[tone] || 'ubr-badge-unavailable';
    },
    safeTel(value) {
      return safeTel(value);
    },
    async copyPhone(phone) {
      const text = clipboardNumber(phone);
      if (!text) return;
      try {
        await navigator.clipboard.writeText(text);
        this.toast(`Skopiowano numer ${phone.number || text}.`);
      } catch (e) {
        this.toast('Nie udało się skopiować — zaznacz numer ręcznie.', 'error');
      }
    },

    /** „Przejmij firmę”: wolna firma → moje Prospects; moja → bez duplikatu; cudza → komunikat serwera (409). */
    async claimCompany(company, searchId = null) {
      if (company._claiming) return;
      company._claiming = true;
      try {
        const res = await api(`/companies/${company.id}/claim`, { method: 'POST', body: { search_id: searchId } });
        company.prospect_id = res.prospect_id;
        const auto = automationMessage(res);
        this.toast(res.claimed ? `Przejęto „${company.name}” — firma jest w Twoich Prospects.${auto ? ' ' + auto[0].toUpperCase() + auto.slice(1) + '.' : ''}` : `„${company.name}” jest już Twoja.`);
        if (this.drawer.company?.id === company.id) {
          this.drawer.company.prospect_id = res.prospect_id;
          this.loadClaim(company.id);
          if (this.drawer.sales) this.loadSales();
        }
        changed(company.id);
      } catch (e) {
        this.error(e);
        changed(company.id);
      } finally {
        company._claiming = false;
      }
    },

    openOutcome(company, contactId = null) {
      const active = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
      this.outcome = { open: true, company: { id: company.id, name: company.name }, contactId, value: '', note: '', saving: false, error: null, returnFocus: active };
      setTimeout(() => document.querySelector('[data-outcome-first]')?.focus(), 30);
    },
    closeOutcome() {
      const back = this.outcome.returnFocus;
      this.outcome.open = false;
      if (back?.isConnected) back.focus({ preventScroll: true });
    },
    async saveOutcome() {
      if (this.outcome.saving) return;
      if (!this.outcome.value) {
        this.outcome.error = 'Wybierz wynik rozmowy.';
        return;
      }
      this.outcome.saving = true;
      this.outcome.error = null;
      try {
        const res = await api(`/companies/${this.outcome.company.id}/call-outcome`, {
          method: 'POST',
          body: { outcome: this.outcome.value, note: this.outcome.note, contact_id: this.outcome.contactId },
        });
        this.toast(`Zapisano wynik rozmowy: ${this.callOutcomes[this.outcome.value]}${res.claimed ? ' (firma przypisana do Ciebie)' : ''}.`);
        if (this.drawer.company?.id === this.outcome.company.id) this.drawer.sales = res;
        changed(this.outcome.company.id);
        this.closeOutcome();
      } catch (e) {
        this.outcome.error = e.message;
      } finally {
        this.outcome.saving = false;
      }
    },

    /** Okno „Odblokuj telefon”: najpierw sprawdzenie (bez zapytania do Apollo), potem świadome potwierdzenie. */
    async openReveal(contactId, companyId) {
      const active = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
      this.reveal = { open: true, loading: true, data: null, ack: false, busy: false, result: null, error: null, contactId, companyId, returnFocus: active };
      setTimeout(() => document.querySelector('[data-reveal-close]')?.focus(), 30);
      try {
        this.reveal.data = await api(`/contacts/${contactId}/phone-reveal`);
      } catch (e) {
        this.reveal.error = e.message;
      } finally {
        this.reveal.loading = false;
      }
    },
    closeReveal() {
      const back = this.reveal.returnFocus;
      this.reveal.open = false;
      if (back?.isConnected) back.focus({ preventScroll: true });
    },
    async confirmReveal() {
      const r = this.reveal;
      if (r.busy || r.result || !r.data?.can_unlock || !r.ack) return; // podwójne kliknięcie / brak potwierdzenia
      r.busy = true;
      r.error = null;
      try {
        r.result = await api(`/contacts/${r.contactId}/phone-reveal`, {
          method: 'POST',
          body: { confirm_token: r.data.confirm_token, acknowledge_unknown_cost: true },
        });
        this.toast(r.result.message, r.result.status === 'error' ? 'error' : 'info');
        changed(r.companyId);
      } catch (e) {
        r.error = e.message;
      } finally {
        r.busy = false;
      }
    },

    /* --------------------------- numery firmy (karta firmy) --------------------------- */

    async loadPhones(companyId) {
      this.phones = { ...this.phones, companyId, loading: true, error: null };
      try {
        const data = await api(`/companies/${companyId}/phones`);
        if (this.phones.companyId === companyId) this.phones.data = data;
      } catch (e) {
        this.phones.error = e.message;
      } finally {
        this.phones.loading = false;
      }
    },
    async refreshPhones() {
      if (this.phones.busy) return;
      this.phones.busy = true;
      try {
        const data = await api(`/companies/${this.phones.companyId}/phones/refresh`, { method: 'POST' });
        this.phones.data = data;
        this.toast(data.message || 'Odświeżono numery.');
        changed(this.phones.companyId);
      } catch (e) {
        this.error(e);
      } finally {
        this.phones.busy = false;
      }
    },
    async addPhone() {
      if (this.phones.busy) return;
      this.phones.busy = true;
      this.phones.error = null;
      try {
        this.phones.data = await api(`/companies/${this.phones.companyId}/phones`, { method: 'POST', body: this.phones.form });
        this.phones.form = { number: '', type: 'MAIN', label: '' };
        this.phones.adding = false;
        this.toast('Dodano numer (widoczny tylko w Twojej organizacji; nie zostanie nadpisany).');
        changed(this.phones.companyId);
      } catch (e) {
        this.phones.error = e.message;
      } finally {
        this.phones.busy = false;
      }
    },
    async deletePhone(phone) {
      if (this.phones.busy || !phone.manual) return;
      this.phones.busy = true;
      try {
        this.phones.data = await api(`/companies/${this.phones.companyId}/phones/${phone.id}`, { method: 'DELETE' });
        this.toast('Usunięto numer dodany ręcznie.');
        changed(this.phones.companyId);
      } catch (e) {
        this.error(e);
      } finally {
        this.phones.busy = false;
      }
    },

    async setDoNotContact(value, reason = '') {
      const p = this.drawer.sales?.prospect;
      if (!p) return;
      try {
        this.drawer.sales = await api(`/prospects/${p.id}/do-not-contact`, { method: 'POST', body: { value, reason } });
        this.toast(value ? 'Oznaczono: nie kontaktować (firma znika z kolejek telefonów).' : 'Zdjęto zakaz kontaktu.');
        changed(this.drawer.company?.id);
      } catch (e) {
        this.error(e);
      }
    },
    async transferProspect(userId) {
      const p = this.drawer.sales?.prospect;
      if (!p) return;
      try {
        this.drawer.sales = await api(`/prospects/${p.id}/transfer`, { method: 'POST', body: { user_id: userId || null } });
        this.toast('Zmieniono opiekuna firmy.');
        changed(this.drawer.company?.id);
      } catch (e) {
        this.error(e);
        this.loadSales();
      }
    },
  };
}

/** Domyślne filtry Cold Calling (wspólne dla Discover i Prospects). */
export const CALL_FILTERS = () => ({ phone: '', phone_source: '', dm_role: '', readiness: '', call_owner: '', size: '', contacted: '', pipeline_stage: '', analysis_state: '', industry_q: '', location_q: '', free_with_phone: '' });

/** Liczba aktywnych filtrów (wskaźnik przy przycisku). */
export const activeCount = (filters) => Object.values(filters).filter((v) => v !== '' && v !== null && v !== undefined).length;
