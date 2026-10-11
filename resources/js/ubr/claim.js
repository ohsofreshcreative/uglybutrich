/**
 * Prompt 10.4.3 — przejęta firma: status analizy decydenta i synchronizacji ClickUp w karcie firmy,
 * „Ponów wyszukiwanie decydenta”, Website Age Intelligence (zlecenie analizy w tle). Odczyt niczego nie uruchamia.
 */
import { api } from './api.js';

export const CLAIM_TONES = {
  pending: 'muted', people: 'active', found: 'good', phones: 'active', direct: 'good', no_phone: 'warn',
  none: 'muted', review: 'warn', failed: 'danger', blocked: 'danger', not_started: 'muted',
};

export const CLICKUP_TONES = { needs_config: 'warn', disabled: 'muted', none: 'muted', queued: 'muted', running: 'active', added: 'good', updated: 'good', error: 'danger' };

/** Komunikat po kliknięciu „Przejmij firmę” (co uruchomiono w tle). */
export function automationMessage(res) {
  const parts = [];
  const auto = res && res.automation;
  if (!auto) return '';
  if (auto.research) {
    parts.push(auto.research.queued ? 'szukamy decydenta w tle' : ({ fresh: 'decydent z zapisanej analizy', blocked: 'bez analizy kontaktów (zakaz kontaktu / firma zablokowana)', in_progress: 'analiza decydenta już trwa' }[auto.research.reason] || 'analiza decydenta: ' + (auto.research.message || 'pominięta')));
  }
  if (auto.clickup) {
    parts.push({ queued: 'zadanie ClickUp w przygotowaniu', linked: 'zadanie ClickUp zostanie zaktualizowane', in_progress: 'synchronizacja ClickUp trwa', needs_config: 'ClickUp wymaga konfiguracji', disabled: 'bez ClickUp', limit: 'limit dzienny ClickUp', error: 'błąd ClickUp (spróbuj z karty firmy)' }[auto.clickup.status] || auto.clickup.message);
  }
  return parts.join(' · ');
}

export function claimStore() {
  return {
    claimTone(key) {
      return CLAIM_TONES[key] || 'muted';
    },
    clickupTone(key) {
      return CLICKUP_TONES[key] || 'muted';
    },

    async loadClaim(id = this.drawer.company?.id) {
      clearTimeout(this._claimTimer);
      if (!id || !this.drawer.open) return;
      try {
        const data = await api(`/companies/${id}/claim-status`);
        if (this.drawer.company?.id !== id) return;
        this.drawer.claim = data;
        const busy = data.research?.active || ['running', 'queued'].includes(data.clickup?.key);
        if (busy) this._claimTimer = setTimeout(() => this.loadClaim(id), 5000);
      } catch (e) {
        this.drawer.claim = { error: e.message };
      }
    },

    async retryClaimResearch() {
      const id = this.drawer.company?.id;
      if (!id || this.drawer.claimBusy) return;
      this.drawer.claimBusy = true;
      try {
        const res = await api(`/companies/${id}/claim-research`, { method: 'POST' });
        this.toast(res.message, res.queued ? 'info' : 'error');
        if (res.status && this.drawer.claim) this.drawer.claim.research = res.status;
        this.loadClaim(id);
      } catch (e) {
        this.error(e);
      } finally {
        this.drawer.claimBusy = false;
      }
    },

    async checkSiteAge(force = false) {
      const id = this.drawer.company?.id;
      if (!id || this.drawer.ageBusy) return;
      this.drawer.ageBusy = true;
      try {
        const res = await api(`/companies/${id}/site-age`, { method: 'POST', body: { force } });
        this.toast(res.message, res.queued ? 'info' : 'error');
        if (res.site_age && this.drawer.company?.id === id) this.drawer.company.site_age_detail = res.site_age;
      } catch (e) {
        this.error(e);
      } finally {
        this.drawer.ageBusy = false;
      }
    },

    async copyText(text, label = 'tekst') {
      try {
        await navigator.clipboard.writeText(String(text));
        this.toast(`Skopiowano ${label}.`);
      } catch (e) {
        this.toast('Nie udało się skopiować — zaznacz ręcznie.', 'error');
      }
    },
  };
}
