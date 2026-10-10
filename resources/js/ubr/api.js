/**
 * Klient REST API ubr/v1. Uwierzytelnianie: cookie WordPress + nonce (X-WP-Nonce, ochrona CSRF).
 */
export class ApiError extends Error {
  constructor(message, status, code) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

export async function api(path, { method = 'GET', body, query } = {}) {
  const base = window.UBR.restUrl.replace(/\/$/, '');
  const url = new URL(base + path);

  if (query) {
    Object.entries(query).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
    });
  }

  let response;

  try {
    response = await fetch(url.toString(), {
      method,
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': window.UBR.nonce,
        Accept: 'application/json',
        ...(body ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    throw new ApiError('Brak połączenia z serwerem.', 0, 'network');
  }

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    const message = data?.message || `Błąd serwera (HTTP ${response.status}).`;

    if (data?.code === 'rest_cookie_invalid_nonce') {
      throw new ApiError('Sesja wygasła. Odśwież stronę.', response.status, data.code);
    }

    throw new ApiError(message, response.status, data?.code);
  }

  return data;
}
