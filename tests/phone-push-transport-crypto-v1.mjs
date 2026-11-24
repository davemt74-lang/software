import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createDecipheriv, createECDH, createPublicKey, hkdfSync, verify } from 'node:crypto';
import { fileURLToPath } from 'node:url';

// Cross-runtime interoperability: decrypt the actual PHP adapter's wire request
// with Node/OpenSSL and verify its ES256 VAPID signature. No provider is contacted.
// The receiver private key/auth secret are the public RFC 8291 appendix A fixture.
const php = process.env.PHP_BINARY || 'php';
const test = fileURLToPath(new URL('./phone-push-transport-v1.php', import.meta.url));
const run = spawnSync(php, [test, '--crypto-fixture'], { encoding: 'utf8', timeout: 30000, maxBuffer: 1024 * 1024 });
assert.equal(run.status, 0, run.stderr || run.stdout || String(run.error));
if (run.stdout.startsWith('Phone push transport PHP 8.1 guard:')) {
  console.log('Phone push crypto interoperability requires the PHP 8.2+ worker; PHP 8.1 guard passed.');
  process.exit(0);
}
const fixture = JSON.parse(run.stdout);
const decode = value => Buffer.from(value, 'base64url');
const userKey = createECDH('prime256v1');
userKey.setPrivateKey(decode(fixture.user_private));
assert.deepEqual(userKey.getPublicKey(), decode(fixture.subscription.keys.p256dh));
const vapidRaw = decode(fixture.vapid_public);
const vapid = createPublicKey({ format: 'jwk', key: { kty: 'EC', crv: 'P-256',
  x: vapidRaw.subarray(1, 33).toString('base64url'), y: vapidRaw.subarray(33).toString('base64url') } });
const salts = [], serverKeys = [];
const authorizations = [];
for (const request of fixture.requests) {
  const body = Buffer.from(request.body, 'base64');
  const salt = body.subarray(0, 16), size = body.readUInt32BE(16), keyLength = body[20];
  assert.equal(size, 4096); assert.equal(keyLength, 65); assert.equal(body.length, 2151);
  const sender = body.subarray(21, 21 + keyLength);
  const encrypted = body.subarray(21 + keyLength);
  const secret = userKey.computeSecret(sender);
  const ikm = hkdfSync('sha256', secret, decode(fixture.subscription.keys.auth),
    Buffer.concat([Buffer.from('WebPush: info\0'), userKey.getPublicKey(), sender]), 32);
  const key = hkdfSync('sha256', ikm, salt, Buffer.from('Content-Encoding: aes128gcm\0'), 16);
  const nonce = hkdfSync('sha256', ikm, salt, Buffer.from('Content-Encoding: nonce\0'), 12);
  const decrypt = (input) => {
    const cipher = createDecipheriv('aes-128-gcm', key, nonce);
    cipher.setAuthTag(input.subarray(-16));
    return Buffer.concat([cipher.update(input.subarray(0, -16)), cipher.final()]);
  };
  const plain = decrypt(encrypted);
  assert.equal(plain.length, 2049);
  let end = plain.length - 1;
  while (plain[end] === 0) --end;
  assert.equal(plain[end], 2);
  assert.equal(plain.subarray(0, end).toString('utf8'), fixture.payload);
  const corrupted = Buffer.from(encrypted); corrupted[0] ^= 1;
  assert.throws(() => decrypt(corrupted), 'authenticated encryption rejects a modified record');
  const headers = Object.fromEntries(Object.entries(request.headers).map(([name, values]) => [name.toLowerCase(), values[0]]));
  assert.equal(headers['content-encoding'], 'aes128gcm');
  const auth = /^vapid t=([A-Za-z0-9_.-]+), ?k=([A-Za-z0-9_-]+)$/.exec(headers.authorization);
  assert.ok(auth); assert.equal(auth[2], fixture.vapid_public);
  authorizations.push(headers.authorization);
  const [header, payload, signature] = auth[1].split('.');
  assert.deepEqual(JSON.parse(decode(header)), { typ: 'JWT', alg: 'ES256' });
  const claims = JSON.parse(decode(payload));
  assert.equal(claims.aud, new URL(request.endpoint).origin);
  assert.equal(claims.sub, fixture.subject);
  assert.ok(claims.exp > Date.now() / 1000 && claims.exp <= Date.now() / 1000 + 86400);
  assert.equal(decode(signature).length, 64);
  assert.ok(verify('sha256', Buffer.from(`${header}.${payload}`), { key: vapid, dsaEncoding: 'ieee-p1363' }, decode(signature)));
  assert.notDeepEqual(sender, vapidRaw, 'ECDH and signing keys are distinct');
  salts.push(salt); serverKeys.push(sender);
}
assert.notDeepEqual(salts[0], salts[1], 'each attempt receives a fresh encryption salt');
assert.notDeepEqual(serverKeys[0], serverKeys[1], 'each attempt receives a fresh ephemeral encryption key');
assert.equal(authorizations[0], authorizations[1], 'the durable VAPID authorization is reused while payload encryption remains fresh');
console.log('Phone push crypto interoperability: 2 authenticated encrypted requests decrypted; VAPID signatures and RFC claims verified.');
