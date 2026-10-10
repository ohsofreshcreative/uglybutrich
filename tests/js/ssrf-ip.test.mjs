// Test jednostkowy ochrony SSRF komponentu przeglądarki (audyt QA, Prompt 7). Uruchom: node tests/js/ssrf-ip.test.mjs
// Import modułu nie uruchamia przechwytywania (start tylko przy wywołaniu jako proces z plikiem zadania).
import assert from 'node:assert/strict';
import { isPublicIp, ipv6Bytes } from '../../tools/site-capture/capture.mjs';

const blocked = [
  '127.0.0.1', '10.0.0.1', '169.254.169.254', '192.168.1.1', '100.64.0.1', '0.0.0.0',
  '::1', '::', '::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:a9fe:a9fe', '::ffff:a00:1', '0:0:0:0:0:ffff:7f00:0001',
  '::7f00:1', '64:ff9b::a00:1', '64:ff9b:1::1', '2002:7f00:1::', '2002:a9fe:a9fe::1', 'fe80::1', 'fec0::1', 'fd12:3456::1',
  'ff02::1', '2001:db8::1', '2001::1', '100::1', 'nie-adres',
];
const allowed = ['8.8.8.8', '93.184.216.34', '::ffff:8.8.8.8', '2a00:1450:4001:82a::200e', '2606:4700::6810:84e5', '64:ff9b::808:808', '2002:808:808::1'];

for (const ip of blocked) assert.equal(isPublicIp(ip), false, `${ip} powinien być zablokowany`);
for (const ip of allowed) assert.equal(isPublicIp(ip), true, `${ip} powinien być dozwolony`);
assert.deepEqual(ipv6Bytes('::ffff:7f00:1'), ipv6Bytes('::ffff:127.0.0.1'), 'ten sam adres w dwóch zapisach');
assert.equal(ipv6Bytes('1::2::3'), null);
console.log(`ssrf-ip: ${blocked.length + allowed.length + 2}/${blocked.length + allowed.length + 2} OK`);
