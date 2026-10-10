// Test jednostkowy walidacji adresu podglądu (Prompt 5.1). Uruchom: node tests/js/preview-url.test.mjs
import assert from 'node:assert/strict';
import { safeWebsiteUrl, livePreviewUrl } from '../../resources/js/ubr/ui.js';

const cases = [
  ['https://zamet.com.pl/', 'https://zamet.com.pl/'],
  ['http://www.zamet.com.pl', 'http://www.zamet.com.pl/'],
  ['  https://firma.pl/o-nas?x=1  ', 'https://firma.pl/o-nas?x=1'],
  ['javascript:alert(1)', null],
  ['data:text/html,<script>alert(1)</script>', null],
  ['file:///etc/passwd', null],
  ['ftp://firma.pl/', null],
  ['https://user:pass@firma.pl/', null],
  ['//firma.pl', null],
  ['firma.pl', null],
  ['not a url', null],
  ['', null],
  [null, null],
  [undefined, null],
  [42, null],
];
for (const [input, expected] of cases) assert.equal(safeWebsiteUrl(input), expected, `safeWebsiteUrl(${JSON.stringify(input)})`);

assert.deepEqual(livePreviewUrl('http://www.zamet.com.pl/', 'https:'), { url: 'https://www.zamet.com.pl/', upgraded: true });
assert.deepEqual(livePreviewUrl('http://www.zamet.com.pl/', 'http:'), { url: 'http://www.zamet.com.pl/', upgraded: false });
assert.deepEqual(livePreviewUrl('https://flowair.com/', 'https:'), { url: 'https://flowair.com/', upgraded: false });
assert.deepEqual(livePreviewUrl('javascript:alert(1)', 'https:'), { url: null, upgraded: false });
console.log(`preview-url: ${cases.length + 4}/${cases.length + 4} OK`);
