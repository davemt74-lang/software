import assert from 'node:assert/strict';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';

const root = process.cwd();
const php = process.env.VP3_PHP_BINARY || 'php';
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'vp3-pa5-api-'));
const fixture = path.resolve('tests/fixtures/profile-conversation-checkout-pa5-db.php');
const config = path.join(temporary, 'fixture.json');
const providerLog = path.join(temporary, 'provider.jsonl');
const invoke = action => {
  const result = spawnSync(php, [fixture, action, config], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return result.stdout;
};
const snapshot = () => JSON.parse(invoke('state'));
const providerTraffic = () => fs.existsSync(providerLog)
  ? fs.readFileSync(providerLog, 'utf8').trim().split('\n').map(line => JSON.parse(line))
  : [];
const providerCalls = () => providerTraffic().filter(entry => entry.method !== 'GET');
const endpoint = '/api/profile-conversation-checkout-pa5.php';
const agentEndpoint = '/api/profile-agent.php';
const returnEndpoint = '/profile-conversation-checkout-return-pa5.php';
const actor = (session, browser) => ({
  cookie: `PHPSESSID=pa5${session}; sf_profile_visitor=${browser.repeat(64)}; sf_profile_conversation=${browser.repeat(64)}`,
});
const visitor = actor('visitor', 'b');
const foreign = actor('foreign', 'f');
const logoutVisitor = actor('logout', 'e');
let child;
let logs = '';
let checks = 0;
const check = (condition, label) => {
  assert.ok(condition, `${label}\n${logs.slice(-3800)}`);
  checks++;
  console.log('PASS ' + label);
};

try {
  const seeded = JSON.parse(invoke('init'));
  for (const folder of ['includes', 'api', 'sessions']) fs.mkdirSync(path.join(temporary, folder));
  for (const entry of fs.readdirSync('includes')) {
    if (entry !== 'bootstrap.php') fs.symlinkSync(path.resolve('includes', entry), path.join(temporary, 'includes', entry));
  }
  for (const entry of [endpoint, agentEndpoint, returnEndpoint]) fs.copyFileSync('.' + entry, temporary + entry);
  fs.copyFileSync('tests/fixtures/profile-conversation-checkout-pa5-bootstrap.php', path.join(temporary, 'includes/bootstrap.php'));
  fs.writeFileSync(path.join(temporary, 'boot.php'), `<?php
require __DIR__.'/includes/bootstrap.php';
header('Content-Type: application/json');
$profile=profile_by_username(db(),'fixture-owner');
$token=profile_chat_token(1);
$context=vp3_profile_agent_public_context_v110(db(),$profile,current_user());
echo json_encode([
    'username'=>'fixture-owner',
    'profile_token'=>$token,
    'conversation_namespace'=>hash('sha256','profile-widget-v1|'.$context['conversation_authority']['credential_hash']),
],JSON_THROW_ON_ERROR);
`);
  const reservation = net.createServer();
  reservation.listen(0, '127.0.0.1');
  await once(reservation, 'listening');
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  const base = `http://127.0.0.1:${port}`;
  child = spawn(php, ['-d', 'display_errors=0', '-d', 'session.save_path=' + path.join(temporary, 'sessions'), '-S', `127.0.0.1:${port}`, '-t', temporary], {
    env: { ...process.env, VP3_PA3_ROOT: root, VP3_PA3_CONFIG: config, VP3_PA5_PROVIDER_LOG: providerLog },
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  child.stderr.on('data', chunk => { logs += chunk; });
  async function request(route, who, fields = {}, { method = 'POST', extra = {}, rawBody } = {}) {
    const read = method === 'GET' || method === 'HEAD';
    const reply = await fetch(base + route + (read ? '?' + new URLSearchParams(fields) : ''), {
      method,
      headers: { 'Content-Type': 'application/json', Cookie: who.cookie, ...extra },
      ...(read ? {} : { body: rawBody ?? JSON.stringify(fields) }),
    });
    const raw = await reply.text();
    let data;
    try { data = raw ? JSON.parse(raw) : null; }
    catch { throw new Error(`Invalid fixture HTTP response (${reply.status}): ${raw}\n${logs.slice(-3800)}`); }
    return { status: reply.status, data, raw, headers: reply.headers };
  }
  const boot = (who, id) => request('/boot.php', who, {}, { method: 'GET', extra: { 'X-Fixture-Login': String(id) } });
  let booted;
  for (let i = 0; i < 80; i++) {
    try { booted = await boot(visitor, 2); break; }
    catch (error) {
      if (i === 79 || error.cause?.code !== 'ECONNREFUSED') throw error;
      await new Promise(resolve => setTimeout(resolve, 25));
    }
  }
  const visitorBase = booted.data;
  const foreignBase = (await boot(foreign, 3)).data;
  const logoutBase = (await boot(logoutVisitor, 2)).data;
  assert.ok(visitorBase?.profile_token && foreignBase?.profile_token && logoutBase?.profile_token, logs);
  const checkout = (action, fields = {}, who = visitor, auth = visitorBase, options = {}) => request(endpoint, who, {
    ...auth, action, conversation_id: 0, ...fields,
  }, options);
  const agent = (fields, who = visitor, auth = visitorBase, options = {}) => request(agentEndpoint, who, { ...auth, ...fields }, options);
  const selection = (requestId, overrides = {}) => ({
    product_slug: 'field-guide', quantity: 2, connection_id: seeded.seller, payer_email: 'buyer@example.test',
    request_id: requestId.repeat(32), ...overrides,
  });
  const before = snapshot();
  for (const method of ['GET', 'HEAD', 'PUT', 'DELETE']) {
    const denied = await checkout('prepare', selection('a'), visitor, visitorBase, { method });
    check(denied.status === 405 && denied.headers.get('allow') === 'POST', method + ' cannot prepare a purchase');
  }
  for (const proof of ['', 'bad', null, []]) {
    const denied = await checkout('prepare', selection('a', { profile_token: proof }));
    check(denied.status === 419, 'checkout rejects malformed or missing Profile token');
  }
  const wrongNamespace = await checkout('prepare', selection('a', { conversation_namespace: 'a'.repeat(64) }));
  check(wrongNamespace.status === 409 && wrongNamespace.data.code === 'PROFILE_SESSION_CHANGED', 'checkout rejects a stale browser namespace');
  const wrongMime = await checkout('prepare', selection('a'), visitor, visitorBase, { extra: { 'Content-Type': 'text/plain' } });
  check(wrongMime.status === 422, 'checkout rejects a cross-site simple body type');
  const oversize = await checkout('prepare', selection('a'), visitor, visitorBase, { rawBody: ' '.repeat(8193) });
  check(oversize.status === 413, 'checkout rejects oversized requests before processing');
  for (const slug of ['private-guide', 'foreign-guide', 'visit', 'physical', 'inactive', 'disconnected']) {
    const denied = await checkout('prepare', selection('a', { product_slug: slug }));
    check(denied.status >= 400 && denied.status < 500, 'prepare rejects unavailable product ' + slug);
  }
  check(JSON.stringify(snapshot()) === JSON.stringify(before), 'rejected calls write no conversation, purchase or receipt');
  const catalog = await checkout('catalog');
  check(catalog.status === 200 && catalog.data.contract === 'vp3.profile-conversation-checkout.v1'
    && catalog.data.products.map(product => product.slug).join() === 'field-guide', 'public purchase catalog is scoped to eligible seller inventory');
  check(catalog.headers.get('cache-control').includes('no-store') && catalog.headers.get('referrer-policy') === 'no-referrer'
    && !/PRIVATE_|acct_pa5|ciphertext/.test(catalog.raw), 'checkout responses forbid caching and omit private merchant details');

  const publicMessage = await agent({ action: 'message', client_request_id: 'b'.repeat(32), message: 'I want to buy the Field Guide' });
  const cid = publicMessage.data?.conversation_id;
  check(publicMessage.status === 200 && publicMessage.data.accepted && cid > 0
    && publicMessage.data.checkout_products?.map(product => product.slug).join() === 'field-guide', 'ordinary accepted Profile message offers the matched current product in its thread');
  check(snapshot().agent_commerce_orders_v800 === 0 && providerCalls().length === 0, 'offer suggestions have no financial effects');
  const prepared = await checkout('prepare', selection('c', { conversation_id: cid }));
  const review = prepared.data?.review;
  check(prepared.status === 200 && prepared.data.conversation_id === cid && review?.state === 'prepared' && review.total_cents === 5000, 'exact conversation receives a durable server-calculated review');
  const confirm = { conversation_id: cid, review_id: review.review_id, terms_accepted: true };
  invoke('age_messages');
  const yes = await agent({ action: 'message', conversation_id: cid, client_request_id: 'd'.repeat(32), message: 'Yes, go ahead', ...confirm });
  check(yes.status === 200 && snapshot().agent_commerce_orders_v800 === 0 && providerCalls().length === 0, 'chat prose and injected review fields cannot confirm a purchase');
  const forgedOwner = await checkout('confirm', confirm, foreign, foreignBase);
  check([403, 404].includes(forgedOwner.status) && !forgedOwner.data.review, 'foreign session with current valid proof cannot confirm the exact visitor review');
  const badConfirmation = await checkout('confirm', { ...confirm, terms_accepted: 'true' });
  check(badConfirmation.status === 422 && providerCalls().length === 0, 'forged nonboolean confirmation cannot start provider checkout');

  const failed = await checkout('confirm', confirm, visitor, visitorBase, { extra: { 'X-Fixture-Provider': 'uncertain' } });
  check(failed.status === 503 && !/PRIVATE_PROVIDER_DIAGNOSTIC|checkout\.stripe\.com|receipt_url/.test(failed.raw), 'lost provider response returns safe HTTP retry state');
  let saved = await checkout('status', { conversation_id: cid, payment_status: 'paid', paid: true, session_id: 'cs_forged' });
  check(saved.status === 200 && saved.data.reviews[0].state === 'checkout_pending' && !saved.data.reviews[0].checkout_url
    && snapshot().agent_commerce_payments_v800 === 0, 'forged return parameters cannot mark an uncertain checkout as paid');
  const ready = await checkout('confirm', confirm);
  const checkoutUrl = ready.data?.review?.checkout_url;
  const calls = providerCalls();
  check(ready.status === 200 && checkoutUrl?.startsWith('https://checkout.stripe.com/') && calls.length === 2
    && JSON.stringify(calls[0]) === JSON.stringify(calls[1]), 'HTTP retry restores the same hosted checkout and uses its original provider request');
  const duplicate = await checkout('confirm', confirm);
  check(duplicate.status === 200 && duplicate.data.review.checkout_url === checkoutUrl && providerCalls().length === 2
    && snapshot().agent_commerce_orders_v800 === 1, 'duplicate confirm returns the same checkout without creating a second order');

  const providerReturn = (who, extras, headers = {}) => fetch(base + returnEndpoint + '?' + new URLSearchParams({
    username: 'fixture-owner', review: review.review_id, ...extras,
  }), { redirect: 'manual', headers: { Cookie: who.cookie, ...headers } });
  for (const parameters of [{ cancel: '1' }, { session_id: 'cs_forged', payment_status: 'paid' }]) {
    const redirected = await providerReturn(visitor, parameters);
    check(redirected.status === 302 && redirected.headers.get('location') === '/fixture-owner?profile_checkout=' + cid
      && redirected.headers.get('referrer-policy') === 'no-referrer', 'provider return reopens only the exact buyer conversation and removes return parameters');
  }
  const foreignReturn = await providerReturn(foreign, { cancel: '1' });
  check(foreignReturn.status === 302 && foreignReturn.headers.get('location') === '/fixture-owner'
    && snapshot().agent_commerce_payments_v800 === 0 && providerTraffic().length === 2,
    'foreign provider return learns no conversation ID and forged or cancelled return has no payment or provider effects');

  const sessionId = checkoutUrl.split('/').at(-1);
  const verifiedReturn = await providerReturn(visitor, { session_id: sessionId }, { 'X-Fixture-Provider': 'settled' });
  const reads = providerTraffic().filter(entry => entry.method === 'GET').map(entry => entry.url);
  check(verifiedReturn.status === 302 && verifiedReturn.headers.get('location') === '/fixture-owner?profile_checkout=' + cid
    && snapshot().agent_commerce_payments_v800 === 1
    && reads.join('|') === `https://api.stripe.com/v1/checkout/sessions/${sessionId}|https://api.stripe.com/v1/payment_intents/${sessionId.replace('cs_', 'pi_')}`,
    'native provider return records one payment only after fetching exact checkout and payment proof');
  saved = await checkout('status', { conversation_id: cid });
  const paid = saved.data?.reviews[0];
  check(saved.status === 200 && paid?.order?.status?.key === 'paid' && paid.receipt_url && !paid.checkout_url,
    'verified PA2 payment returns exact buyer receipt and retires the checkout link');
  const polled = await agent({ action: 'poll', conversation_id: cid }, visitor, visitorBase, { method: 'GET' });
  check(polled.status === 200 && !/receipt_url|buyer@example.test|checkout\.stripe\.com/.test(polled.raw)
    && polled.data.messages.some(message => message.message === 'Yes, go ahead'), 'ordinary Chat polling preserves messages and omits private checkout state');
  const deniedReceipt = await checkout('status', { conversation_id: cid }, foreign, foreignBase);
  check([403, 404].includes(deniedReceipt.status) && !/receipt_url|checkout_url/.test(deniedReceipt.raw),
    'a different visitor cannot retrieve the paid receipt through checkout status');

  const replacement = await checkout('prepare', selection('e', { conversation_id: cid }));
  const changed = await checkout('confirm', { ...confirm, review_id: replacement.data.review.review_id }, visitor, visitorBase,
    { extra: { 'X-Fixture-Switch-During-Checkout': '3' } });
  check([403, 404, 409, 419].includes(changed.status) && !/receipt_url|checkout_url|checkout\.stripe\.com/.test(changed.raw),
    'account switch during provider I/O suppresses the old buyer response');
  const loggedOut = await checkout('prepare', selection('f'), logoutVisitor, logoutBase);
  check(loggedOut.status === 200 && loggedOut.data.conversation_id > 0, 'buyer can begin checkout directly from a fresh visitor conversation');
  const logout = await checkout('confirm', {
    conversation_id: loggedOut.data.conversation_id, review_id: loggedOut.data.review.review_id, terms_accepted: true,
  }, logoutVisitor, logoutBase, { extra: { 'X-Fixture-Switch-During-Checkout': 'logout' } });
  check(logout.status === 409 && logout.data.code === 'PROFILE_SESSION_CHANGED' && !logout.headers.get('set-cookie')
    && !/receipt_url|checkout_url|checkout\.stripe\.com/.test(logout.raw),
    `logout during provider I/O suppresses stale checkout and cannot reissue the old session (${logout.status} ${logout.data?.code}, cookie=${logout.headers.has('set-cookie')})`);
  check(providerCalls().length === 4 && snapshot().agent_commerce_payments_v800 === 1,
    'account transition loses no provider operations and invents no payment');
  console.log(`PROFILE_CONVERSATION_CHECKOUT_API_PA5=PASS (${checks} checks; ${seeded.driver})`);
} finally {
  if (child && child.exitCode === null) {
    const exited = once(child, 'exit');
    child.kill('SIGTERM');
    await exited;
  }
  invoke('cleanup');
  fs.rmSync(temporary, { recursive: true, force: true });
}
