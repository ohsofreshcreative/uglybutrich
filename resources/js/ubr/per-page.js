/**
 * Liczba wyników na stronie list (Discover, Prospects): 10 / 25 / 50 / 100, domyślnie 25.
 * Wybór zapamiętany lokalnie, osobno dla każdej listy (wygoda użytkownika — bez zapisu w bazie).
 * Paginacja pozostaje po stronie serwera (parametr `per_page`).
 */
export const PER_PAGE_OPTIONS = [10, 25, 50, 100];

export const PER_PAGE_DEFAULT = 25;

/** Dozwolona wartość albo domyślne 25 (np. wartość spoza listy zapisana ręcznie w localStorage). */
export function normalizePerPage(value) {
  const n = Number.parseInt(value, 10);

  return PER_PAGE_OPTIONS.includes(n) ? n : PER_PAGE_DEFAULT;
}

function storage(store) {
  if (store !== undefined) return store;
  try {
    return typeof window !== 'undefined' ? window.localStorage : null;
  } catch (e) {
    return null; // tryb prywatny / zablokowany storage
  }
}

/** Zapamiętana liczba wyników dla listy (`key`: 'discover' | 'prospects'). */
export function storedPerPage(key, store) {
  try {
    const s = storage(store);

    return normalizePerPage(s ? s.getItem(`ubr_per_page_${key}`) : null);
  } catch (e) {
    return PER_PAGE_DEFAULT;
  }
}

/** Zapisuje wybór (zwraca znormalizowaną wartość). Błąd storage nie przerywa zmiany — stan zostaje w pamięci. */
export function rememberPerPage(key, value, store) {
  const n = normalizePerPage(value);

  try {
    const s = storage(store);
    if (s) s.setItem(`ubr_per_page_${key}`, String(n));
  } catch (e) {
    /* tylko w pamięci */
  }

  return n;
}
