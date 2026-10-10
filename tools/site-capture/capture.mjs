#!/usr/bin/env node
/**
 * Ugly but Rich — site-capture: pomocniczy komponent Website Analyzera (uruchamiany przez PHP, proc_open).
 *
 * Wejście: ścieżka do pliku JSON z zadaniem (argv[2]) albo `--check` (diagnostyka środowiska).
 * Wyjście: jeden obiekt JSON na stdout. Screenshoty zapisywane do katalogu wskazanego przez PHP.
 *
 * Co robi: renderuje stronę w Chromium (Playwright) w dwóch widokach (Desktop 1440 px, Mobile 390 px),
 * czeka na wyrenderowanie (load + network idle z limitem), przewija stronę (lazy loading), próbuje odrzucić
 * baner cookies (przycisk „odrzuć / tylko niezbędne”), w przeciwnym razie ukrywa znane banery CMP na zrzucie
 * (zapisuje to w metadanych), wyłącza animacje, robi screenshot JPEG z limitem wysokości i rozmiaru,
 * zbiera obiektywne metryki (requesty, transfer, błędy, czasy laboratoryjne, overflow, małe elementy
 * dotykowe, mały tekst, CTA w pierwszym ekranie, nawigacja) oraz wyniki axe-core.
 *
 * Bezpieczeństwo (SSRF): każde żądanie przeglądarki jest sprawdzane — tylko http(s), porty 80/443,
 * host musi rozwiązywać się WYŁĄCZNIE na publiczne adresy IP (bez localhost, sieci prywatnych, link-local,
 * metadata 169.254.169.254, CGNAT, multicast). Przekierowania (których Playwright nie przechwytuje w route)
 * są sprawdzane w zdarzeniu `request`; naruszenie przerywa analizę i NIE zapisuje screenshotów.
 * Sandbox Chromium pozostaje włączony. Nie obchodzimy CAPTCHA, logowania ani zabezpieczeń antybotowych.
 */
import { readFileSync, writeFileSync, existsSync, mkdirSync, statSync } from 'node:fs';
import { promises as dns } from 'node:dns';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);

const CHROME_CANDIDATES = [
  process.env.UBR_CHROME_PATH,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean);

const CTA_RE = /(kontakt|skontaktuj|zapytaj|zapytanie|wycen|zam[oó]w|napisz|zadzwo[nń]|um[oó]w|wy[sś]lij|oferta|sprawd[zź] ofert|bezp[lł]atn|konsultacj|demo|contact|get a quote|request|quote|book|call us|order)/i;

const VIEWS = {
  desktop: { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, isMobile: false, hasTouch: false },
  mobile: {
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1 UglyButRichBot/0.3',
  },
};

const COOKIE_HIDE_CSS = `
#CybotCookiebotDialog, #CybotCookiebotDialogBodyUnderlay, #onetrust-banner-sdk, #onetrust-consent-sdk, .onetrust-pc-dark-filter,
#cookie-law-info-bar, .cli-modal, #cmplz-cookiebanner-container, .cmplz-cookiebanner, #moove_gdpr_cookie_info_bar,
.cc-window, .cc-banner, #cookie-notice, .cookie-notice-container, #cookieConsent, #cookie-consent, .cookie-consent,
#CookieBanner, .cookie-banner, #cookies-banner, .cookies-banner, #gdpr-cookie-message, .gdpr-banner, #usercentrics-root,
#didomi-host, .didomi-popup-open, #truste-consent-track, .qc-cmp2-container, #ez-cookie-dialog-wrapper, .iubenda-cs-container
{ display: none !important; visibility: hidden !important; }
html, body { overflow: auto !important; }`;

const NO_MOTION_CSS = `*, *::before, *::after { animation-duration: 0s !important; animation-delay: 0s !important; transition: none !important; scroll-behavior: auto !important; caret-color: transparent !important; }
/* Biblioteki animacji „przy przewinięciu” (AOS, WOW, ScrollReveal): elementy zostają niewidoczne, gdy animacje są wyłączone.
   Na zrzucie pokazujemy je w stanie końcowym — tak, jak widzi je użytkownik po przewinięciu. */
[data-aos], .aos-init, .wow, [data-sr-id], [data-scroll-reveal] { opacity: 1 !important; transform: none !important; visibility: visible !important; }`;

function out(obj) {
  process.stdout.write(JSON.stringify(obj));
}

function findChrome(explicit) {
  for (const p of [explicit, ...CHROME_CANDIDATES]) {
    if (p && existsSync(p)) return p;
  }
  // Przeglądarki Playwright (np. środowisko CI) — tylko jeśli są.
  const base = process.env.PLAYWRIGHT_BROWSERS_PATH;
  if (base && existsSync(base)) {
    try {
      const { readdirSync } = require('node:fs');
      for (const d of readdirSync(base).filter((d) => d.startsWith('chromium-')).sort().reverse()) {
        const p = join(base, d, 'chrome-linux', 'chrome');
        if (existsSync(p)) return p;
      }
    } catch {}
  }
  return null;
}

/* ---------------------------------- SSRF ---------------------------------- */

function ipv4ToInt(ip) {
  return ip.split('.').reduce((a, o) => (a << 8) + Number(o), 0) >>> 0;
}

function inCidr4(ip, cidr) {
  const [base, bits] = cidr.split('/');
  const mask = bits === '0' ? 0 : (~0 << (32 - Number(bits))) >>> 0;
  return (ipv4ToInt(ip) & mask) === (ipv4ToInt(base) & mask);
}

const PRIVATE_V4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];

export function isPublicIp(ip) {
  if (net.isIPv4(ip)) return !PRIVATE_V4.some((c) => inCidr4(ip, c));
  if (net.isIPv6(ip)) {
    const v = ip.toLowerCase();
    const mapped = v.match(/^::ffff:(\d+\.\d+\.\d+\.\d+)$/);
    if (mapped) return isPublicIp(mapped[1]);
    if (v === '::' || v === '::1') return false;
    if (/^f[cd]/.test(v)) return false; // fc00::/7 unique local
    if (/^fe[89ab]/.test(v)) return false; // fe80::/10 link-local
    if (/^ff/.test(v)) return false; // multicast
    if (/^(2001:db8|64:ff9b|100::)/.test(v)) return false;
    return true;
  }
  return false;
}

function makeGuard(allowHosts) {
  const cache = new Map();
  const allow = new Set((allowHosts || []).map((h) => h.toLowerCase()));

  return async function check(rawUrl) {
    let u;
    try {
      u = new URL(rawUrl);
    } catch {
      return { ok: false, reason: 'nieprawidłowy URL' };
    }
    if (u.protocol === 'data:' || u.protocol === 'blob:' || u.protocol === 'about:') return { ok: true };
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return { ok: false, reason: 'niedozwolony protokół ' + u.protocol };
    const hostPort = u.host.toLowerCase();
    if (allow.has(hostPort)) return { ok: true };
    const host = u.hostname.toLowerCase().replace(/^\[|\]$/g, '');
    const port = u.port ? Number(u.port) : u.protocol === 'https:' ? 443 : 80;
    if (port !== 80 && port !== 443) return { ok: false, reason: 'niedozwolony port ' + port };
    if (host === 'localhost' || /\.(localhost|local|internal|lan|home|corp|intranet)$/.test(host) || !host.includes('.') && !net.isIP(host)) {
      return { ok: false, reason: 'host wewnętrzny ' + host };
    }
    if (cache.has(host)) return cache.get(host);
    let result;
    if (net.isIP(host)) {
      result = isPublicIp(host) ? { ok: true } : { ok: false, reason: 'prywatny adres IP ' + host };
    } else {
      try {
        const addrs = await dns.lookup(host, { all: true, verbatim: true });
        const bad = addrs.find((a) => !isPublicIp(a.address));
        result = addrs.length === 0 ? { ok: false, reason: 'brak rekordu DNS' } : bad ? { ok: false, reason: `${host} → adres niepubliczny ${bad.address}` } : { ok: true };
      } catch (e) {
        result = { ok: false, reason: 'DNS: ' + (e.code || e.message), dns: true };
      }
    }
    cache.set(host, result);
    return result;
  };
}

/* --------------------------------- capture --------------------------------- */

async function settle(page, budgetMs) {
  const until = Date.now() + budgetMs;
  try {
    await page.waitForLoadState('load', { timeout: Math.max(1000, until - Date.now()) });
  } catch {}
  try {
    await page.waitForLoadState('networkidle', { timeout: Math.max(500, Math.min(4000, until - Date.now())) });
  } catch {}
}

async function handleCookies(page) {
  // 1) Preferujemy odrzucenie / tylko niezbędne (wariant najbardziej prywatny). Nigdy nie akceptujemy śledzenia.
  const reject = page.locator('button, a[role="button"], [role="button"]').filter({ hasText: /odrzu[cć]|tylko (niezb[eę]dne|wymagane|konieczne)|reject( all)?|decline|nie zgadzam|only (necessary|essential)/i });
  try {
    const n = await reject.count();
    for (let i = 0; i < Math.min(n, 3); i++) {
      const el = reject.nth(i);
      if (await el.isVisible({ timeout: 300 })) {
        await el.click({ timeout: 1500 });
        await page.waitForTimeout(600);
        return 'rejected';
      }
    }
  } catch {}
  // 2) Brak przycisku odrzucenia — ukrywamy znane banery tylko na potrzeby zrzutu (informacja w metadanych).
  const present = await page
    .evaluate(() => !!document.querySelector('#CybotCookiebotDialog, #onetrust-banner-sdk, #cookie-law-info-bar, #cmplz-cookiebanner-container, .cc-window, #cookie-notice, .cookie-consent, #cookieConsent, .cookie-banner, #cookie-banner, #usercentrics-root, #didomi-host, .qc-cmp2-container, .iubenda-cs-container, [id*="cookie" i][class*="banner" i], [class*="cookie" i][class*="consent" i]'))
    .catch(() => false);
  await page.addStyleTag({ content: COOKIE_HIDE_CSS }).catch(() => {});
  // 3) Własne banery bez znanego CMP: widoczny element fixed/sticky z tekstem o cookies, nie zasłaniający całej strony.
  //    Tylko ukrycie na zrzucie — nie klikamy „Akceptuj” (nie wyrażamy zgody w imieniu nikogo).
  const heuristic = await page
    .evaluate(() => {
      const re = /cookie|ciasteczk|pliki cookies|polityk[aęi] prywatno|rodo|gdpr/i;
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      let hidden = 0;
      for (const el of document.querySelectorAll('body *')) {
        if (hidden >= 3) break;
        const s = getComputedStyle(el);
        if (s.position !== 'fixed' && s.position !== 'sticky') continue;
        if (s.display === 'none' || s.visibility === 'hidden') continue;
        const r = el.getBoundingClientRect();
        if (r.width < 120 || r.height < 30) continue;
        const text = (el.innerText || '').trim();
        if (!text || text.length > 1500 || !re.test(text)) continue;
        // Nie ukrywamy nagłówka strony ani elementów zajmujących prawie cały ekran (to raczej układ strony niż baner).
        if (el.matches('header, nav, [role=navigation]') || el.querySelector('nav')) continue;
        if (r.width * r.height > vw * vh * 0.85 && text.length > 600) continue;
        el.setAttribute('data-ubr-hidden', 'cookie');
        el.style.setProperty('display', 'none', 'important');
        hidden++;
      }
      return hidden;
    })
    .catch(() => 0);
  return present || heuristic > 0 ? 'hidden' : 'none_detected';
}

/**
 * Strony z przewijanym kontenerem zamiast dokumentu (html/body o wysokości 100vh i overflow hidden):
 * fullPage zwraca wtedy tylko jeden ekran. Rozwijamy największy przewijany kontener na potrzeby zrzutu.
 * Zwraca true, gdy kontener został rozwinięty.
 */
async function expandScrollContainer(page) {
  return page
    .evaluate(() => {
      const doc = document.documentElement;
      if (doc.scrollHeight > window.innerHeight + 40) return false;
      let best = null;
      let bestGain = 0;
      for (const el of document.querySelectorAll('body, body *')) {
        const gain = el.scrollHeight - el.clientHeight;
        if (gain < 200 || el.clientWidth < window.innerWidth * 0.6) continue;
        const oy = getComputedStyle(el).overflowY;
        if (!['auto', 'scroll', 'overlay', 'hidden'].includes(oy)) continue;
        if (gain > bestGain) {
          best = el;
          bestGain = gain;
        }
      }
      if (!best) return false;
      const loosen = (el) => {
        el.style.setProperty('height', 'auto', 'important');
        el.style.setProperty('max-height', 'none', 'important');
        el.style.setProperty('overflow', 'visible', 'important');
      };
      for (let el = best; el && el !== doc; el = el.parentElement) loosen(el);
      loosen(doc);
      return doc.scrollHeight > window.innerHeight + 40;
    })
    .catch(() => false);
}

async function autoScroll(page) {
  await page
    .evaluate(async () => {
      const step = Math.max(400, Math.floor(window.innerHeight * 0.9));
      // Gdy dokument się nie przewija, a strona przewija wewnętrzny kontener — przewijamy ten kontener
      // (lazy loading i animacje „przy przewinięciu” reagują na jego zdarzenia scroll).
      let scroller = null;
      if (document.documentElement.scrollHeight <= window.innerHeight + 40) {
        let gainMax = 0;
        for (const el of document.querySelectorAll('body, body *')) {
          const gain = el.scrollHeight - el.clientHeight;
          if (gain > 200 && gain > gainMax && el.clientWidth >= window.innerWidth * 0.6 && ['auto', 'scroll', 'overlay'].includes(getComputedStyle(el).overflowY)) {
            scroller = el;
            gainMax = gain;
          }
        }
      }
      const max = Math.min(scroller ? scroller.scrollHeight : document.documentElement.scrollHeight, 20000);
      const to = (y) => (scroller ? scroller.scrollTo(0, y) : window.scrollTo(0, y));
      for (let y = 0, i = 0; y < max && i < 18; y += step, i++) {
        to(y);
        await new Promise((r) => setTimeout(r, 180));
      }
      to(0);
    })
    .catch(() => {});
  await page.waitForTimeout(500);
}

async function domMetrics(page, view) {
  return page.evaluate(
    ({ ctaSource, view }) => {
      const cta = new RegExp(ctaSource, 'i');
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const visible = (el) => {
        const r = el.getBoundingClientRect();
        const s = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0.05;
      };
      const doc = document.documentElement;
      const metaViewport = document.querySelector('meta[name="viewport"]')?.getAttribute('content') || null;

      // Elementy interaktywne i małe cele dotykowe (< 24×24 px — minimum WCAG 2.2 AA 2.5.8).
      const interactive = [...document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button]')].filter(visible);
      const small = interactive.filter((el) => {
        const r = el.getBoundingClientRect();
        return r.width < 24 || r.height < 24;
      });

      // Mały tekst (< 12 px) — próbka elementów z bezpośrednim tekstem.
      let textEls = 0;
      let smallText = 0;
      const walker = document.createTreeWalker(document.body || doc, NodeFilter.SHOW_ELEMENT);
      let node;
      let scanned = 0;
      while ((node = walker.nextNode()) && scanned < 4000) {
        scanned++;
        const hasText = [...node.childNodes].some((c) => c.nodeType === 3 && c.textContent.trim().length > 2);
        if (!hasText || !visible(node)) continue;
        textEls++;
        if (parseFloat(getComputedStyle(node).fontSize) < 12) smallText++;
      }

      // CTA w pierwszym ekranie.
      const aboveFold = interactive.filter((el) => {
        const r = el.getBoundingClientRect();
        return r.top < vh && r.bottom > 0 && r.left < vw && r.right > 0;
      });
      // CTA = wyraźny element (min. 80×32 px, np. przycisk) z tekstem wezwania albo klikalny telefon. Drobne linki tekstowe się nie liczą.
      const ctaAbove = aboveFold
        .filter((el) => {
          const r = el.getBoundingClientRect();
          const prominent = r.width >= 80 && r.height >= 32;
          return (prominent && cta.test((el.innerText || el.value || el.getAttribute('aria-label') || '').trim())) || (el.getAttribute('href') || '').startsWith('tel:');
        })
        .slice(0, 5)
        .map((el) => (el.innerText || el.getAttribute('aria-label') || el.getAttribute('href') || '').trim().slice(0, 60));

      // Nawigacja.
      const nav = document.querySelector('header nav, nav, [role=navigation]');
      const navLinks = nav ? [...nav.querySelectorAll('a[href]')].filter(visible).length : 0;
      const hamburger = [...document.querySelectorAll('button, [role=button], a')].filter(visible).some((el) => {
        const t = `${el.className} ${el.id} ${el.getAttribute('aria-label') || ''}`.toLowerCase();
        return /(burger|menu-toggle|nav-toggle|menu-button|toggle-menu|mobile-menu|menu)/.test(t) && el.getBoundingClientRect().top < vh;
      });
      const h1 = [...document.querySelectorAll('h1')].filter(visible);
      const h1Above = h1.some((el) => el.getBoundingClientRect().top < vh);

      return {
        view,
        viewport: { width: vw, height: vh },
        // Brak meta viewport na mobile: przeglądarka renderuje układ desktopowy (np. 980 px) i go pomniejsza.
        screen_width: window.screen.width,
        scaled_desktop_layout: vw > window.screen.width * 1.15,
        document: { width: doc.scrollWidth, height: doc.scrollHeight },
        horizontal_overflow_px: Math.max(0, doc.scrollWidth - vw),
        meta_viewport: metaViewport,
        interactive_count: interactive.length,
        small_tap_targets: small.length,
        text_elements: textEls,
        small_text_elements: smallText,
        cta_above_fold: ctaAbove,
        nav_links_visible: navLinks,
        hamburger_visible: hamburger,
        h1_visible_count: h1.length,
        h1_above_fold: h1Above,
        title: document.title.slice(0, 200),
      };
    },
    { ctaSource: CTA_RE.source, view }
  );
}

async function shoot(page, file, maxBytes, maxHeight) {
  const expanded = await expandScrollContainer(page);
  if (expanded) await page.waitForTimeout(400);
  const dims = await page.evaluate(() => ({ w: window.innerWidth, h: document.documentElement.scrollHeight })).catch(() => ({ w: 0, h: 0 }));
  let height = Math.max(1, Math.min(dims.h || 1, maxHeight));
  const attempts = [
    { quality: 72, height },
    { quality: 55, height },
    { quality: 50, height: Math.min(height, Math.floor(maxHeight * 0.6)) },
    { quality: 45, height: Math.min(height, 3000) },
  ];
  let buf = null;
  let used = null;
  for (const a of attempts) {
    buf = await page.screenshot({ type: 'jpeg', quality: a.quality, fullPage: true, clip: { x: 0, y: 0, width: dims.w, height: a.height }, animations: 'disabled', caret: 'hide', timeout: 20000 });
    used = a;
    if (buf.length <= maxBytes) break;
  }
  if (buf.length > maxBytes) {
    return { ok: false, error: `screenshot za duży (${buf.length} B > ${maxBytes} B)` };
  }
  writeFileSync(file, buf);
  return { ok: true, bytes: buf.length, quality: used.quality, height: used.height, full_height: dims.h, truncated: dims.h > used.height, width: dims.w, scroll_container_expanded: expanded };
}

async function run(job) {
  const started = Date.now();
  const result = {
    ok: false,
    tool: { name: 'site-capture', version: '1.0.0', playwright: null, browser: null },
    url: job.url,
    final_url: null,
    redirects: [],
    status: null,
    headers: {},
    views: {},
    network: { requests: 0, failed: 0, http_errors: 0, bytes: 0, by_type: {}, blocked: [], third_party_hosts: 0 },
    timing: null,
    console_errors: 0,
    axe: null,
    cookie_banner: null,
    errors: [],
    security_violation: null,
    duration_ms: 0,
  };

  const executablePath = findChrome(job.executablePath);
  if (!executablePath) {
    result.errors.push({ stage: 'launch', message: 'Nie znaleziono przeglądarki Chrome/Chromium (ustaw UBR_CHROME_PATH).' });
    return result;
  }

  const { chromium } = require('playwright-core');
  result.tool.playwright = require('playwright-core/package.json').version;
  const guard = makeGuard(job.allowHosts);
  const initial = await guard(job.url);
  if (!initial.ok) {
    result.security_violation = initial.reason;
    result.errors.push({ stage: 'ssrf', message: 'Adres zablokowany: ' + initial.reason });
    return result;
  }

  mkdirSync(job.outDir, { recursive: true });
  const browser = await chromium.launch({
    executablePath,
    headless: true,
    timeout: 20000,
    // Sandbox Chromium pozostaje włączony (bez --no-sandbox).
    args: ['--disable-dev-shm-usage', '--no-first-run', '--no-default-browser-check', '--mute-audio', '--disable-extensions', '--disable-sync', '--disable-background-networking', '--disable-component-update', '--js-flags=--max-old-space-size=512'],
  });
  result.tool.browser = browser.version();
  const deadline = started + (job.timeoutMs || 75000);
  const pageHost = new URL(job.url).hostname.replace(/^www\./, '');

  try {
    for (const view of job.views || ['desktop', 'mobile']) {
      if (Date.now() > deadline - 8000) {
        result.errors.push({ stage: view, message: 'Przekroczono budżet czasu analizy — widok pominięty.' });
        continue;
      }
      const context = await browser.newContext({
        ...VIEWS[view],
        locale: 'pl-PL',
        timezoneId: 'Europe/Warsaw',
        reducedMotion: 'reduce',
        serviceWorkers: 'block',
        acceptDownloads: false,
        javaScriptEnabled: true,
        ignoreHTTPSErrors: false,
        userAgent: VIEWS[view].userAgent || 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 UglyButRichBot/0.3',
      });
      const page = await context.newPage();
      let violation = null;
      const collect = view === 'desktop';

      await page.route('**/*', async (route) => {
        const r = route.request();
        const g = await guard(r.url());
        if (!g.ok) {
          if (collect) result.network.blocked.push({ url: r.url().slice(0, 200), reason: g.reason });
          if (r.isNavigationRequest() && r.frame() === page.mainFrame()) violation = g.reason;
          return route.abort('blockedbyclient');
        }
        return route.continue();
      });

      // Przekierowania nie przechodzą przez route — sprawdzamy każdy krok łańcucha.
      page.on('request', async (r) => {
        if (r.redirectedFrom()) {
          const g = await guard(r.url());
          if (!g.ok) violation = 'przekierowanie na ' + g.reason;
        }
      });

      if (collect) {
        page.on('requestfinished', async (r) => {
          result.network.requests++;
          const type = r.resourceType();
          result.network.by_type[type] = (result.network.by_type[type] || 0) + 1;
          try {
            const s = await r.sizes();
            result.network.bytes += (s.responseBodySize || 0) + (s.responseHeadersSize || 0);
          } catch {}
          try {
            const resp = await r.response();
            if (resp && resp.status() >= 400) result.network.http_errors++;
          } catch {}
        });
        page.on('requestfailed', (r) => {
          if (r.failure()?.errorText !== 'net::ERR_BLOCKED_BY_CLIENT') result.network.failed++;
        });
        page.on('console', (m) => {
          if (m.type() === 'error') result.console_errors++;
        });
      }

      let response = null;
      try {
        response = await page.goto(job.url, { waitUntil: 'domcontentloaded', timeout: Math.min(25000, Math.max(5000, deadline - Date.now() - 5000)) });
      } catch (e) {
        result.errors.push({ stage: view, message: 'Nawigacja: ' + String(e.message).split('\n')[0].slice(0, 200) });
      }

      if (violation) {
        result.security_violation = violation;
        result.errors.push({ stage: 'ssrf', message: 'Zablokowano: ' + violation + ' — analiza przerwana, screenshoty nie zostały zapisane.' });
        await context.close();
        break;
      }

      if (!response) {
        await context.close();
        continue;
      }

      if (collect) {
        result.status = response.status();
        result.final_url = page.url();
        const chain = [];
        let req = response.request().redirectedFrom();
        while (req) {
          chain.unshift(req.url());
          req = req.redirectedFrom();
        }
        result.redirects = chain.slice(0, 10);
        const h = response.headers();
        result.headers = {
          'content-type': h['content-type'] || null,
          'x-frame-options': h['x-frame-options'] || null,
          'content-security-policy': h['content-security-policy'] ? h['content-security-policy'].slice(0, 500) : null,
          'strict-transport-security': h['strict-transport-security'] || null,
          server: h.server ? h.server.slice(0, 80) : null,
          'x-powered-by': h['x-powered-by'] ? h['x-powered-by'].slice(0, 80) : null,
        };
      }

      if (response.status() >= 400) {
        result.errors.push({ stage: view, message: `Strona zwróciła HTTP ${response.status()}.` });
      }

      await settle(page, 10000);
      const cookies = await handleCookies(page);
      if (collect) result.cookie_banner = cookies;
      await autoScroll(page);
      await page.addStyleTag({ content: NO_MOTION_CSS }).catch(() => {});
      await page.waitForTimeout(300);

      if (violation) {
        result.security_violation = violation;
        result.errors.push({ stage: 'ssrf', message: 'Zablokowano: ' + violation + ' — analiza przerwana.' });
        await context.close();
        break;
      }

      const viewResult = { ok: false };
      try {
        viewResult.metrics = await domMetrics(page, view);
      } catch (e) {
        result.errors.push({ stage: view, message: 'Metryki DOM: ' + String(e.message).slice(0, 160) });
      }

      if (collect) {
        result.timing = await page
          .evaluate(() => {
            const n = performance.getEntriesByType('navigation')[0];
            if (!n) return null;
            return { ttfb_ms: Math.round(n.responseStart - n.startTime), dom_content_loaded_ms: Math.round(n.domContentLoadedEventEnd - n.startTime), load_ms: Math.round(n.loadEventEnd - n.startTime), transfer_bytes: n.transferSize || null, lab: true };
          })
          .catch(() => null);
        const hosts = await page.evaluate(() => [...new Set(performance.getEntriesByType('resource').map((e) => { try { return new URL(e.name).hostname; } catch { return null; } }).filter(Boolean))]).catch(() => []);
        result.network.third_party_hosts = hosts.filter((h) => !h.replace(/^www\./, '').endsWith(pageHost)).length;

        if (job.axePath && existsSync(job.axePath) && Date.now() < deadline - 10000) {
          try {
            const source = readFileSync(job.axePath, 'utf8');
            await page.evaluate(source);
            result.axe = await page.evaluate(async () => {
              const r = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] }, resultTypes: ['violations'] });
              return {
                version: window.axe.version,
                violations: r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, targets: v.nodes.slice(0, 3).map((n) => String(n.target).slice(0, 120)) })),
              };
            });
          } catch (e) {
            result.errors.push({ stage: 'axe', message: 'axe-core: ' + String(e.message).slice(0, 160) });
          }
        }
      }

      try {
        const file = join(job.outDir, `${job.prefix}-${view}.jpg`);
        const shot = await shoot(page, file, job.maxBytes || 2500000, view === 'mobile' ? 7000 : 6000);
        if (shot.ok) {
          viewResult.ok = true;
          viewResult.screenshot = { file: `${job.prefix}-${view}.jpg`, ...shot };
        } else {
          result.errors.push({ stage: view, message: shot.error });
        }
      } catch (e) {
        result.errors.push({ stage: view, message: 'Screenshot: ' + String(e.message).split('\n')[0].slice(0, 200) });
      }

      result.views[view] = viewResult;
      await context.close();
    }
  } finally {
    await browser.close().catch(() => {});
  }

  result.duration_ms = Date.now() - started;
  result.ok = !result.security_violation && Object.values(result.views).some((v) => v.ok);
  return result;
}

async function check() {
  const info = { node: process.version, platform: process.platform, arch: process.arch, playwright: null, axe: null, chrome: findChrome(process.env.UBR_CHROME_PATH), helper: here };
  try {
    info.playwright = require('playwright-core/package.json').version;
  } catch {}
  try {
    info.axe = require('axe-core/package.json').version;
  } catch {}
  return info;
}

const arg = process.argv[2];
const killer = setTimeout(() => {
  out({ ok: false, errors: [{ stage: 'timeout', message: 'Przekroczono maksymalny czas działania komponentu.' }] });
  process.exit(3);
}, 110000);

try {
  if (arg === '--check') {
    out(await check());
  } else {
    const job = JSON.parse(readFileSync(arg, 'utf8'));
    if (!job.axePath) {
      try {
        job.axePath = require.resolve('axe-core/axe.min.js');
      } catch {}
    }
    out(await run(job));
  }
  clearTimeout(killer);
  process.exit(0);
} catch (e) {
  clearTimeout(killer);
  out({ ok: false, errors: [{ stage: 'fatal', message: String(e && e.message ? e.message : e).split('\n')[0].slice(0, 300) }] });
  process.exit(2);
}
