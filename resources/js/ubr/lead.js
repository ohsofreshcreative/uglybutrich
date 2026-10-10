/**
 * Lead Intelligence — stan UI (Alpine). Ocena liczona po stronie serwera z zapisanych danych; tutaj tylko odczyt.
 */
import { api } from './api.js';

const ACTION_BADGE = {
  contact_now: 'ubr-badge-confirmed',
  find_decision_maker: 'ubr-badge-declared',
  review_website: 'ubr-badge-declared',
  research_further: 'ubr-badge-uncertain',
  verify_company: 'ubr-badge-uncertain',
  low_priority: 'ubr-badge-unavailable',
  do_not_contact: 'ubr-badge-danger',
};
const ACTION_SHORT = {
  contact_now: 'Contact now',
  find_decision_maker: 'Find decision maker',
  review_website: 'Review website',
  research_further: 'Research further',
  verify_company: 'Verify company',
  low_priority: 'Low priority',
  do_not_contact: 'Do not contact',
};
const REDESIGN = { strong: 'STRONG', moderate: 'MODERATE', weak: 'WEAK', insufficient_data: 'INSUFFICIENT DATA' };
const REDESIGN_BADGE = { strong: 'ubr-badge-confirmed', moderate: 'ubr-badge-declared', weak: 'ubr-badge-unavailable', insufficient_data: 'ubr-badge-uncertain' };

export const leadStore = () => ({
  leadClass(score) {
    const base = 'inline-grid place-items-center min-w-10 h-7 px-1.5 rounded-lg text-[13px] font-extrabold tabular-nums ';
    if (score === null || score === undefined) return base + 'bg-canvas text-ink-muted';
    if (score >= 75) return base + 'bg-ink text-gold';
    if (score >= 55) return base + 'bg-money-soft text-money';
    if (score >= 40) return base + 'bg-gold-soft text-gold-deep';
    return base + 'bg-canvas text-ink-soft';
  },
  actionBadge: (v) => ACTION_BADGE[v] || 'ubr-badge-unavailable',
  actionLabel: (v) => ACTION_SHORT[v] || v || '—',
  redesignLabel: (v) => REDESIGN[v] || '—',
  redesignBadge: (v) => REDESIGN_BADGE[v] || 'ubr-badge-unavailable',
  leadConfLabel: (v) => ({ high: 'HIGH', medium: 'MEDIUM', low: 'LOW' })[v] || '—',
  leadConfBadge: (v) => ({ high: 'ubr-badge-confirmed', medium: 'ubr-badge-declared', low: 'ubr-badge-uncertain' })[v] || 'ubr-badge-unavailable',

  async loadLead() {
    const id = this.drawer.company?.id;
    if (!id) return;
    this.drawer.leadLoading = true;
    try {
      const data = await api(`/companies/${id}/lead-intelligence`);
      if (this.drawer.company?.id === id) this.drawer.lead = data;
    } catch (e) {
      this.drawer.error = e.message;
    } finally {
      this.drawer.leadLoading = false;
    }
  },

  async recalcLead() {
    const id = this.drawer.company?.id;
    if (!id) return;
    this.drawer.leadLoading = true;
    try {
      this.drawer.lead = await api(`/companies/${id}/lead-intelligence/recalculate`, { method: 'POST' });
      this.toast('Przeliczono ocenę z zapisanych danych (bez nowych analiz).');
      window.dispatchEvent(new CustomEvent('ubr:lead-changed', { detail: { companyId: id } }));
    } catch (e) {
      this.error(e);
    } finally {
      this.drawer.leadLoading = false;
    }
  },
});
