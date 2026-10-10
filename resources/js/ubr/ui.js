/**
 * Wspólne komponenty UI (Alpine): menu akcji wiersza (⋯) i bezpieczny adres podglądu strony.
 */

/**
 * Adres do podglądu na żywo: wyłącznie http/https, bez danych logowania w URL; inaczej null.
 * Adres pochodzi z zapisanych danych firmy (website_url), nigdy z pola tekstowego użytkownika.
 */
export function safeWebsiteUrl(value) {
  if (typeof value !== 'string' || value.trim() === '') return null;
  try {
    const u = new URL(value.trim());
    if (!['http:', 'https:'].includes(u.protocol)) return null;
    if (u.username || u.password || !u.hostname) return null;
    return u.href;
  } catch (e) {
    return null;
  }
}

/**
 * Adres iframe trybu LIVE: zwalidowany adres firmy; gdy aplikacja działa na https, a strona ma adres http, używamy wersji
 * https (przeglądarka i tak zablokowałaby iframe http jako mixed content). To nie omija zabezpieczeń witryny.
 * @returns {{ url: string|null, upgraded: boolean }}
 */
export function livePreviewUrl(value, pageProtocol) {
  const url = safeWebsiteUrl(value);
  if (url && pageProtocol === 'https:' && url.startsWith('http:')) return { url: 'https:' + url.slice(5), upgraded: true };
  return { url, upgraded: false };
}

export function registerUi(Alpine) {
  /**
   * Menu akcji wiersza: przycisk ⋯ + lista akcji renderowana w <body> (x-teleport), więc nie jest obcinana przez
   * kontener tabeli. Klawiatura: Enter/Space/↓ otwiera, ↑/↓/Home/End nawigacja, Escape i klik poza menu zamyka (menu podąża za przyciskiem przy przewijaniu),
   * fokus wraca na przycisk. Akcje wywołują istniejące metody $store.ubr (bez duplikowania logiki).
   */
  Alpine.data('ubrRowMenu', () => ({
    open: false,
    x: 0,
    y: 0,
    up: false,
    toggle() {
      if (this.open) this.close(true);
      else this.show();
    },
    show() {
      const r = this.$refs.btn.getBoundingClientRect();
      // Przycisk (częściowo) poza ekranem — najpierw przewiń go do widoku, inaczej menu wylądowałoby poza oknem.
      if (r.top < 0 || r.bottom > window.innerHeight) this.$refs.btn.scrollIntoView({ block: 'nearest', behavior: 'instant' });
      this.place();
      this.open = true;
      this.$nextTick(() => this.items()[0]?.focus());
    },
    /** Pozycja menu (position: fixed) liczona od przycisku i ograniczona do okna; otwarcie w górę przy braku miejsca. */
    place() {
      const r = this.$refs.btn.getBoundingClientRect();
      const w = 248;
      const h = window.innerHeight;
      this.x = Math.round(Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)));
      this.up = r.bottom + 300 > h && r.top > 300;
      this.y = Math.round(Math.min(h - 48, Math.max(8, this.up ? h - r.top + 4 : r.bottom + 4)));
    },
    /**
     * Przewinięcie / zmiana rozmiaru okna: menu podąża za przyciskiem; zamyka się dopiero, gdy przycisk zniknie z ekranu
     * (fokus wraca wtedy na ⋯ bez przewijania strony, jeżeli był w menu).
     */
    reposition() {
      if (!this.open) return;
      const r = this.$refs.btn.getBoundingClientRect();
      if (r.bottom < 0 || r.top > window.innerHeight) {
        this.close(!!this.$refs.menu?.contains(document.activeElement));
        return;
      }
      this.place();
    },
    close(focus = false) {
      if (!this.open) return;
      this.open = false;
      if (focus) this.$nextTick(() => this.$refs.btn?.focus({ preventScroll: true }));
    },
    items() {
      return [...(this.$refs.menu?.querySelectorAll('[role="menuitem"]:not([disabled])') || [])].filter((el) => el.offsetParent !== null);
    },
    move(delta) {
      const items = this.items();
      if (!items.length) return;
      const i = items.indexOf(document.activeElement);
      items[(i + delta + items.length) % items.length].focus();
    },
    edge(last) {
      const items = this.items();
      (last ? items[items.length - 1] : items[0])?.focus();
    },
    outside(e) {
      if (this.open && !this.$refs.btn.contains(e.target)) this.close(false);
    },
    run(fn) {
      // Fokus wraca na ⋯ synchronicznie, zanim akcja (np. podgląd) zapamięta element do przywrócenia fokusu.
      this.open = false;
      this.$refs.btn?.focus();
      fn();
    },
    get menuStyle() {
      return `position:fixed;left:${this.x}px;${this.up ? 'bottom' : 'top'}:${this.y}px;width:248px`;
    },
  }));
}
