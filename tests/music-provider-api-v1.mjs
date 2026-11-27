import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import {artistOrigin, providerOrigin, providerRoute, manageRoute, protocol, musicProviderFixture, beginInput, eventInput} from './music-provider-fixture-v1.mjs';

let checks = 0;
const check = (condition, message) => {assert.ok(condition, message); checks++;};
const equal = (actual, expected, message) => {assert.deepEqual(actual, expected, message); checks++;};
const frozen = value => {
  const copy = structuredClone(value);
  delete copy.correlation_id;
  return copy;
};

async function runTests() {
  const fixture = await musicProviderFixture({install: false});
  const checked = async (response, status, message) => {
    const data = await fixture.json(response);
    equal(response.status, status, `${message}: ${JSON.stringify(data)}\n${fixture.logs}`);
    check(/no-store/i.test(response.headers.get('cache-control') || ''), message + ': response must not be shared-cached');
    return data;
  };
  const rejected = async (response, message, allowed = [400, 401, 403, 404, 409, 410, 419, 422]) => {
    const data = await fixture.json(response);
    check(allowed.includes(response.status), `${message}: expected refusal, got ${response.status} ${JSON.stringify(data)}`);
    check(data.ok === false, message + ': refusal is not an okay response');
    check(!JSON.stringify(data).includes(fixture.root), message + ': storage path leaked');
    return data;
  };
  const mediaUrl = receipt => receipt.media.url;
  const media = (receipt, {method = 'GET', origin = artistOrigin, headers = {}} = {}) => fetch(fixture.localUrl(mediaUrl(receipt)), {
    method, headers: {'X-User': '0', ...(origin === null ? {} : {Origin: origin}), ...headers},
  });
  const managed = (input, options) => fixture.ownerPost({workspace_id: 1, ...input}, options);
  const backend = (credential, input, options = {}) => fixture.request(input, {origin: null, token: credential, ...options});
  const counts = () => fixture.readDb('SELECT COUNT(*) AS count FROM track_play_sessions')[0].count;
  const metadataState = async () => {
    const response = await fetch(fixture.base + '/api/music-song-metadata-v1.php?workspace_id=1&id=10', {headers: fixture.initial.headers});
    const data = await fixture.json(response);
    equal(response.status, 200, 'Read canonical owner metadata through existing HTTP route');
    return data;
  };
  const metadataPublish = (state, published) => fetch(fixture.base + '/api/music-song-metadata-v1.php', {
    method: 'POST', headers: {...fixture.initial.headers, 'Content-Type': 'application/json'},
    body: JSON.stringify({csrf_token: fixture.initial.csrf, action: 'save', workspace_id: 1, id: 10,
      title: state.catalog.title, album_id: state.catalog.album_id || 0, description: state.catalog.description || '',
      genre: state.catalog.genre || '', duration_seconds: state.catalog.duration_seconds || 0,
      track_number: state.catalog.track_number || 0, cover_photo_id: state.catalog.cover_photo_id || 0,
      visibility: state.catalog.visibility, is_published: published ? 1 : 0,
      revision: state.revision, metadata_revision: state.recording.revision, request_key: crypto.randomBytes(16).toString('hex')}),
  });
  try {
    const beforeUpgrade = fixture.snapshot();
    await checked(await managed({action: 'site.list'}), 503, 'Unupgraded owner API is explicit');
    equal(fixture.snapshot(), beforeUpgrade, 'An unupgraded request ran DDL or changed history');
    const installed = fixture.migration('--migrate');
    check(installed.ready === true || installed.report?.ready === true, 'Explicit provider migration reports readiness');
    const afterUpgrade = fixture.snapshot();
    fixture.migration('--migrate');
    equal(fixture.snapshot(), afterUpgrade, 'Provider migration is rerunnable without a new installation identity');
    const bytesBeforeReport = fs.readFileSync(path.join(fixture.root, 'data.sqlite'));
    const report = fixture.migration('--report');
    check(report.ready === true || report.report?.ready === true, 'Read-only provider report is ready');
    equal(fixture.snapshot(), afterUpgrade, 'Provider report changed database rows');
    equal(fs.readFileSync(path.join(fixture.root, 'data.sqlite')), bytesBeforeReport, 'Provider report changed SQLite bytes');
    equal(counts(), 1, 'Migration manufactured listening history');
    equal(fixture.productionBootstrapSmoke(), {ready: true, session_status: 1, protocol}, 'Actual production provider bootstrap loads without a personal session');

    await rejected(await managed({action: 'site.list'}, {user: 0}), 'Anonymous visitor cannot list artist sites', [401]);
    await rejected(await managed({action: 'site.list'}, {csrf: 'wrong-csrf'}), 'Owner mutations/read POST require CSRF', [419, 403]);
    await rejected(await managed({action: 'site.list'}, {user: 13}), 'Production role does not manage streaming connections', [403]);
    await rejected(await managed({action: 'site.list', workspace_id: 2}), 'Owner cannot access a foreign workspace', [403]);
    const credential = 'vmp1_' + crypto.randomBytes(32).toString('hex');
    const createInput = {action: 'site.create', workspace_id: 1, request_id: crypto.randomUUID(),
      origin: artistOrigin, label: 'Artist streaming site', environment: 'production', credential};
    const created = await checked(await fixture.ownerPost(createInput), 200, 'Owner registers a pending site');
    equal(created.site.status, 'pending', 'Registration alone does not verify site control');
    const repeatedCreate = await checked(await fixture.ownerPost(createInput), 200, 'Owner repeats a lost create response');
    equal(frozen(repeatedCreate), frozen(created), 'Create retry changed its result or duplicated a site');
    await rejected(await fixture.ownerPost({...createInput, label: 'Conflicting label'}), 'Create key cannot describe a different site', [409]);
    const first = fixture.identities.find(item => Number(item.catalog_track_id) === 10);
    const foreign = fixture.identities.find(item => Number(item.catalog_track_id) === 20);
    const pendingPlay = beginInput(created.site.site_id, first.recording_id);
    await rejected(await fixture.request(pendingPlay), 'Unverified site cannot start visitor playback');
    const verified = fixture.verifySite(created.site);
    const site = verified.site;
    equal(site.status, 'active', 'Challenge verification activates the site');
    check(!JSON.stringify(site).includes(credential), 'Site projections never echo backend credentials');

    const catalog = await checked(await backend(credential, {action: 'catalog.read'}), 200, 'Backend reads its workspace catalog');
    check(catalog.items.some(item => item.recording_id === first.recording_id), 'Authorized recording appears in catalog');
    check(!catalog.items.some(item => item.recording_id === foreign.recording_id), 'Foreign private recording is absent');
    await rejected(await backend(credential, {action: 'catalog.read'}, {origin: artistOrigin}), 'Management credential cannot be used from the visitor browser', [403]);
    await rejected(await backend('mps1_' + 'a'.repeat(64), {action: 'catalog.read'}), 'Playback key does not enumerate artist catalog', [401, 403]);
    await rejected(await backend(credential, {action: 'catalog.read', workspace_id: 2}), 'Backend cannot change its workspace through input');
    await rejected(await backend(credential, {action: 'selection.update', request_id: crypto.randomUUID(),
      expected_selection_revision: site.selection_revision, recording_ids: [foreign.recording_id]}), 'Foreign selection is refused');
    const selectionInput = {action: 'selection.update', request_id: crypto.randomUUID(),
      expected_selection_revision: site.selection_revision, recording_ids: [first.recording_id]};
    const selected = await checked(await backend(credential, selectionInput), 200, 'Explicit recording selection is accepted');
    equal((await checked(await backend(credential, selectionInput), 200, 'Selection retry')).recording_ids,
      selected.recording_ids, 'Selection retry changed membership');
    await rejected(await backend(credential, {...selectionInput, request_id: crypto.randomUUID()}), 'Stale selection cannot overwrite current membership', [409]);
    const manifest = await checked(await backend(credential, {action: 'import.manifest'}), 200, 'Selected import manifest');
    equal(manifest.recording_ids, [first.recording_id], 'Manifest silently expanded selected catalog');
    equal(fixture.productionBootstrapSmoke(credential), {ready: true, session_status: 1, protocol, items: 1}, 'Production bootstrap checks actual persisted roles and plugin entitlement without session adapters');
    fixture.execute('CREATE TABLE user_plugin_installations (user_id INTEGER,plugin_key TEXT,status TEXT,PRIMARY KEY(user_id,plugin_key))');
    fixture.execute('INSERT INTO user_plugin_installations VALUES (11,?,?)', ['music_workspace', 'disabled']);
    equal(fixture.productionBootstrapSmoke(credential), {ready: true, session_status: 1, protocol, code: 'connection_inactive', status: 403}, 'Production bootstrap ignores explicit disabled Music Workspace');
    fixture.execute('DROP TABLE user_plugin_installations');
    const serializedManifest = JSON.stringify(manifest);
    for (const secret of ['audio_path', 'stored_path', 'rights_notes', 'contact_email', 'credential_hash', credential, fixture.root]) {
      check(!serializedManifest.includes(secret), `Manifest exposed private field/value ${secret}`);
    }
    const discovered = await checked(await fetch(fixture.base + providerRoute, {headers: {'X-User': '0'}}), 200, 'Cookie-free protocol discovery');
    equal(discovered.protocol, protocol, 'Discovery announces the implemented protocol');
    equal(discovered.source_page_profile, 'public_music_pages_v2', 'Discovery describes additive public page coverage');
    await rejected(await fixture.request({...pendingPlay, protocol: 'unknown_provider_v99'}), 'Unknown protocol cannot downgrade');
    await rejected(await fixture.request({...pendingPlay, unsupported_mandatory_field: true}), 'Unknown provider fields cannot be silently ignored', [422]);
    await rejected(await fixture.request({...pendingPlay, source_url: 'x'.repeat(33000)}), 'Oversized request is rejected before parsing', [413]);
    await rejected(await fetch(fixture.base + providerRoute, {method: 'POST', headers: {'Content-Type': 'text/plain'}, body: '{}'}), 'Provider does not parse ambiguous content types', [415]);
    await rejected(await fetch(fixture.base + providerRoute, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '[]'}), 'Provider requires a JSON object', [422]);

    const preflight = await fetch(fixture.base + providerRoute + '?site_id=' + site.site_id, {method: 'OPTIONS', headers: {
      Origin: artistOrigin, 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'content-type,authorization',
    }});
    check([200, 204].includes(preflight.status), 'Verified origin preflight is accepted');
    equal(preflight.headers.get('access-control-allow-origin'), artistOrigin, 'Preflight origin is exact');
    check(!preflight.headers.get('access-control-allow-credentials'), 'Visitor CORS does not require or enable credentials');
    const wrongPreflight = await fetch(fixture.base + providerRoute + '?site_id=' + site.site_id, {method: 'OPTIONS', headers: {
      Origin: 'https://other.example.test', 'Access-Control-Request-Method': 'POST',
    }});
    check(!wrongPreflight.headers.get('access-control-allow-origin'), 'Unregistered origin gained CORS access');
    const forbiddenHeader = await fetch(fixture.base + providerRoute + '?site_id=' + site.site_id, {method: 'OPTIONS', headers: {
      Origin: artistOrigin, 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'x-owner-identity',
    }});
    equal(forbiddenHeader.status, 403, 'Unknown visitor request headers are not allowed by preflight');

    const cover = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jx1kAAAAASUVORK5CYII=', 'base64');
    const coverDirectory = path.join(fixture.root, 'uploads/artist-media/1/photos');
    fs.mkdirSync(coverDirectory, {recursive: true});fs.writeFileSync(path.join(coverDirectory, 'provider-cover.png'), cover);
    fixture.execute('INSERT INTO artist_catalog_photos_v181 (id,workspace_id,image_path,alt_text,visibility,is_published) VALUES (90,1,?,?,?,?)',
      ['/uploads/artist-media/1/photos/provider-cover.png', 'Public recording artwork', 'members', 1]);
    fixture.execute('UPDATE artist_catalog_tracks_v181 SET cover_photo_id=90 WHERE id=10');
    const artworkRoute = '/api/music-provider-artwork-v1.php?site_id=' + site.site_id + '&recording_id=' + first.recording_id;
    await rejected(await fetch(fixture.base + artworkRoute, {headers: {Origin: artistOrigin}}), 'Private referenced artwork is unavailable', [404]);
    const privateArtworkManifest = await checked(await backend(credential, {action: 'import.manifest'}), 200, 'Manifest excludes private artwork');
    equal(privateArtworkManifest.items[0].artwork, null, 'Private image became a public artwork reference');
    fixture.execute('UPDATE artist_catalog_photos_v181 SET visibility=? WHERE id=90', ['public']);
    const artwork = await fetch(fixture.base + artworkRoute, {headers: {Origin: artistOrigin}});
    equal(artwork.status, 200, 'Explicitly published public artwork is delivered');
    equal(Buffer.from(await artwork.arrayBuffer()), cover, 'Artwork bytes changed');
    equal(artwork.headers.get('content-type'), 'image/png', 'Artwork uses validated MIME');
    check(/no-store/i.test(artwork.headers.get('cache-control') || ''), 'Artwork does not outlive revocation in shared cache');
    equal((await fetch(fixture.base + artworkRoute, {method: 'HEAD', headers: {Origin: artistOrigin}})).status, 200, 'Artwork supports HEAD');
    const foreignArtwork = artworkRoute.replace(first.recording_id, foreign.recording_id);
    await rejected(await fetch(fixture.base + foreignArtwork, {headers: {Origin: artistOrigin, ...fixture.initial.headers}}), 'Owner cookie cannot expose another workspace artwork');

    const input = beginInput(site.site_id, first.recording_id);
    const beforePlay = counts();
    const startResponse = await fixture.request(input);
    equal(startResponse.headers.get('access-control-allow-origin'), artistOrigin, 'Begin response has exact visitor origin');
    equal(startResponse.headers.get('set-cookie'), null, 'Provider does not initialize a third-party cookie');
    const started = await checked(startResponse, 200, 'Anonymous cookie-free visitor begins an attempt');
    equal(started.asset_id, first.asset_id, 'Attempt is bound to canonical asset version');
    equal(counts(), beforePlay, 'Provider allocation manufactured native personal history');
    const savedAttempt = fixture.snapshot();
    const again = await checked(await fixture.request(input), 200, 'Lost begin response is repeatable');
    equal(frozen(again), frozen(started), 'Begin retry changed session, ticket, source or asset');
    equal(fixture.snapshot(), savedAttempt, 'Begin retry wrote duplicate provider rows');
    await rejected(await fixture.request({...input, source_surface: 'embed'}), 'Conflicting begin body is refused', [409]);
    await rejected(await fixture.request({...input, playback_key: 'mps1_' + 'f'.repeat(64)}), 'Different visitor key cannot seize a play identifier');
    await rejected(await fixture.request(beginInput(site.site_id, foreign.recording_id)), 'Private foreign recording is unavailable');
    await checked(await fixture.request(beginInput(site.site_id, first.recording_id), {origin: null}), 200, 'Public server transport can omit Origin without gaining wider authority');
    await rejected(await fixture.request(beginInput(site.site_id, first.recording_id), {origin: 'https://other.example.test'}), 'Foreign origin cannot borrow a verified site');
    const source = JSON.stringify(started.source);
    check(!source.includes('PRIVATE-'), 'Source attribution retained query or fragment secrets');
    check(source.includes(artistOrigin), 'Source records registered origin');
    const unsafe = await checked(await fixture.request(beginInput(site.site_id, first.recording_id, {
      source_url: artistOrigin + '/account/reset/PRIVATE-PATH?token=PRIVATE-QUERY',
    })), 200, 'Authorized music tolerates a source page that must be withheld');
    check(!JSON.stringify(unsafe.source).includes('PRIVATE-'), 'Sensitive source path was stored or returned');
    for (const pathname of ['/track/vp3-song_123', '/release/public-album/', '/stonefellow-v120.php']) {
      const attempt = beginInput(site.site_id, first.recording_id, {source_url: artistOrigin + pathname + '?token=PRIVATE-QUERY#PRIVATE-FRAGMENT'});
      const page = await checked(await fixture.request(attempt), 200, 'Public Stoneward source page ' + pathname);
      equal(page.source.url, artistOrigin + pathname, 'Retain the initiating public page without query/fragment');
      equal(page.source_page_profile, 'public_music_pages_v2', 'Saved begin receipt identifies its additive source policy');
      equal(page.source.page_verified, false, 'Public page remains browser-reported context');
      equal(frozen(await checked(await fixture.request(attempt), 200, 'Public page lost-ack retry')), frozen(page), 'Public page retry preserves one receipt');
    }
    for (const pathname of ['/track/%2e%2e/music', '/track/song%2fsecret', '/track/../music', '/release/album/secret', '/track/' + 'a'.repeat(161)]) {
      const omitted = await checked(await fixture.request(beginInput(site.site_id, first.recording_id, {source_url: artistOrigin + pathname})), 200, 'Unsafe public-looking route is omitted');
      equal(omitted.source.url, null, 'Route validation must not normalize an unsafe path into public attribution');
      equal(omitted.source.verified_origin, artistOrigin, 'Omitted page retains registered site attribution');
    }
    const restrictedBegin = beginInput(site.site_id, first.recording_id, {source_url: artistOrigin + '/unsupported-page'});
    const restrictedReceipt = await checked(await fixture.request(restrictedBegin), 200, 'Current source policy saves an omitted-page begin');
    equal(restrictedReceipt.source_page_profile, 'public_music_pages_v2', 'Omitted page retains current policy marker');
    await rejected(await fixture.request({...restrictedBegin, source_url: artistOrigin + '/track/changed-page'}),
      'Current policy cannot reuse the historical compatibility exception to change a source', [409]);
    const noReferrer = await checked(await fixture.request(beginInput(site.site_id, first.recording_id, {source_url: ''})), 200, 'Missing referrer does not block authorized listening');
    check(noReferrer.source.url === null || noReferrer.source.source_url === null, 'Missing page context was invented');

    const expectedAudio = fs.readFileSync(path.join(fixture.root, 'uploads/artist-music/1/legacy.wav'));
    const full = await media(started);
    equal(full.status, 200, 'Pinned media GET');
    equal(Buffer.from(await full.arrayBuffer()), expectedAudio, 'GET delivered different bytes');
    check(/no-store/i.test(full.headers.get('cache-control') || ''), 'Media is not stored by shared caches');
    equal(full.headers.get('access-control-allow-origin'), artistOrigin, 'Media CORS has exact verified origin');
    equal(full.headers.get('cross-origin-resource-policy'), 'cross-origin', 'Provider resource policy permits authorized cross-origin media');
    equal(full.headers.get('x-content-type-options'), 'nosniff', 'Media MIME policy');
    check(/^"[^"]+"$/.test(full.headers.get('etag') || ''), 'Pinned media needs a strong version validator');
    const etag = full.headers.get('etag');
    for (const [range, expected, label] of [
      ['bytes=0-9', expectedAudio.subarray(0, 10), 'bounded range'],
      ['bytes=-7', expectedAudio.subarray(expectedAudio.length - 7), 'suffix range'],
      ['bytes=10-', expectedAudio.subarray(10), 'open range'],
    ]) {
      const response = await media(started, {headers: {Range: range}});
      equal(response.status, 206, label + ' status');
      equal(Buffer.from(await response.arrayBuffer()), expected, label + ' exact bytes');
      equal(Number(response.headers.get('content-length')), expected.length, label + ' content length');
      check(/^bytes \d+-\d+\/\d+$/.test(response.headers.get('content-range') || ''), label + ' Content-Range');
    }
    const head = await media(started, {method: 'HEAD', headers: {Range: 'bytes=0-9'}});
    equal(head.status, 200, 'HEAD ignores Range and describes the full pinned representation');
    equal((await head.arrayBuffer()).byteLength, 0, 'HEAD returned audio body');
    equal(Number(head.headers.get('content-length')), expectedAudio.length, 'HEAD describes complete pinned length');
    for (const range of ['bytes=999999999-', 'bytes=0-1,4-5', 'bytes=-0', 'bytes=4-3']) {
      const invalid = await media(started, {headers: {Range: range}});
      equal(invalid.status, 416, 'Invalid/unsupported byte range is explicit');
      equal(invalid.headers.get('content-range'), 'bytes */' + expectedAudio.length, '416 retains pinned length');
    }
    const matching = await media(started, {headers: {Range: 'bytes=0-9', 'If-Range': etag}});
    equal(matching.status, 206, 'Matching If-Range keeps pinned partial delivery');
    const changed = await media(started, {headers: {Range: 'bytes=0-9', 'If-Range': '"different-asset"'}});
    equal(changed.status, 200, 'Stale If-Range returns complete same pinned asset');
    equal(Buffer.from(await changed.arrayBuffer()), expectedAudio, 'Stale validator switched bytes');
    equal((await media(started, {origin: null})).status, 200, 'Audio media read works without an Origin header');
    const deniedMedia = await media(started, {origin: 'https://other.example.test'});
    check([403, 404].includes(deniedMedia.status), 'Mismatched media Origin is denied');
    check(!deniedMedia.headers.get('access-control-allow-origin'), 'Denied origin received media CORS');
    equal(counts(), beforePlay, 'HEAD/ranges created personal listens');
    const beforeEvents = fixture.readDb('SELECT * FROM music_provider_sessions_v1 WHERE session_id=?', [started.session_id])[0];
    equal(Number(beforeEvents.accepted_played_ms), 0, 'Delivered bytes accrued heard playback');

    const renewInput = {action: 'playback.renew', site_id: site.site_id, session_id: started.session_id, request_id: crypto.randomUUID()};
    const renewed = await checked(await fixture.request(renewInput, {token: input.playback_key}), 200, 'Renew visitor delivery');
    equal(renewed.session_id, started.session_id, 'Renewal created another playback session');
    equal(renewed.asset_id, started.asset_id, 'Renewal changed the pinned asset');
    equal(frozen(await checked(await fixture.request(renewInput, {token: input.playback_key}), 200, 'Renewal retry')), frozen(renewed), 'Renewal retry changed its receipt');
    await rejected(await fixture.request(renewInput, {token: credential}), 'Management credential cannot impersonate a listener');
    const resume = eventInput(started.session_id, {site_id: site.site_id});
    const resumed = await checked(await fixture.request(resume, {token: input.playback_key}), 200, 'Actual playing transition');
    equal(frozen(await checked(await fixture.request(resume, {token: input.playback_key}), 200, 'Event receipt retry')), frozen(resumed), 'Event retry is not immutable');
    await rejected(await fixture.request({...resume, position_ms: 1}, {token: input.playback_key}), 'Conflicting event receipt is rejected', [409]);
    await rejected(await fixture.request(eventInput(started.session_id, {site_id: site.site_id, seq: 3}), {token: input.playback_key}), 'Sequence gap is rejected', [409]);
    equal(counts(), beforePlay, 'Provider evidence was added to a private VP3 account history');

    // A repaired rendition affects new sessions while this session keeps the
    // original bytes and validator. These are real retained files, not mocks.
    fixture.run(`$old=STONEFELLOW_ROOT.'/uploads/artist-music/1/legacy.wav';
      $bytes=file_get_contents($old);$bytes[strlen($bytes)-1]="\\1";
      file_put_contents(STONEFELLOW_ROOT.'/uploads/artist-music/1/replacement.wav',$bytes);
      db()->exec("UPDATE tracks SET audio_path='/uploads/artist-music/1/replacement.wav' WHERE id=42");
      db()->exec("UPDATE artist_catalog_tracks_v181 SET audio_path='/uploads/artist-music/1/replacement.wav' WHERE id=10");
      $r=music_song_metadata_v1_row(db(),1,10);$c=music_song_metadata_v1_catalog(db(),1,10);
      $a=music_song_metadata_v1_asset(db(),1,$r['recording_id'],$c);
      db()->prepare('UPDATE music_recordings_v1 SET current_asset_id=? WHERE recording_id=?')->execute([$a,$r['recording_id']]);`);
    const oldRange = await media(renewed, {headers: {Range: 'bytes=-8'}});
    equal(oldRange.status, 206, 'Active old asset remains available after replacement');
    equal(Buffer.from(await oldRange.arrayBuffer()), expectedAudio.subarray(expectedAudio.length - 8), 'Active session mixed replacement bytes');
    equal(oldRange.headers.get('etag'), etag, 'Active session validator changed with catalog replacement');
    const nextPlay = await checked(await fixture.request(beginInput(site.site_id, first.recording_id)), 200, 'New attempt follows replacement rendition');
    check(nextPlay.asset_id !== started.asset_id, 'New attempt did not receive new audio version');
    fs.appendFileSync(path.join(fixture.root, 'uploads/artist-music/1/legacy.wav'), 'changed-in-place');
    check([404, 409, 410, 503].includes((await media(started)).status), 'In-place corruption of pinned asset was delivered');
    fs.writeFileSync(path.join(fixture.root, 'uploads/artist-music/1/legacy.wav'), expectedAudio);

    fixture.execute('UPDATE artist_catalog_tracks_v181 SET is_published=0 WHERE id=10');
    await rejected(await fixture.request(beginInput(site.site_id, first.recording_id), {headers: fixture.initial.headers}), 'Owner cookie cannot turn an unpublished recording into visitor audio');
    await rejected(await fixture.request(renewInput, {token: input.playback_key}), 'Unpublishing invalidates even renewal retries');
    await rejected(await fixture.request(resume, {token: input.playback_key}), 'Unpublishing invalidates even event retries');
    check([403, 404, 410].includes((await media(started)).status), 'Unpublishing left old ticket delivery active');
    fixture.execute('UPDATE artist_catalog_tracks_v181 SET is_published=1 WHERE id=10');

    const catalogBeforeWithdrawal = await metadataState();
    const beforeFailedWithdrawal = fixture.snapshot();
    fixture.execute("CREATE TRIGGER provider_catalog_receipt_failure BEFORE INSERT ON music_catalog_requests_v1 BEGIN SELECT RAISE(ABORT,'fixture late catalog receipt failure'); END");
    const failedWithdrawal = await metadataPublish(catalogBeforeWithdrawal, false);
    equal(failedWithdrawal.status, 500, 'Late catalog failure must fail the actual HTTP save');
    fixture.execute('DROP TRIGGER provider_catalog_receipt_failure');
    equal(fixture.snapshot(), beforeFailedWithdrawal, 'Late failed canonical save left song or provider sessions changed');
    equal((await media(started)).status, 200, 'Rolled-back canonical withdrawal still revoked playback');
    const unpublished = await metadataPublish(catalogBeforeWithdrawal, false);
    equal(unpublished.status, 200, 'Canonical song editor withdraws public access');
    check([403, 404, 410].includes((await media(started)).status), 'Canonical withdrawal did not stop issued tickets');
    const republished = await metadataPublish(await metadataState(), true);
    equal(republished.status, 200, 'Canonical song editor republishes recording');
    check([403, 404, 410].includes((await media(started)).status), 'Republishing revived a withdrawn old session');
    await checked(await fixture.request(beginInput(site.site_id, first.recording_id)), 200, 'Republished recording permits a fresh attempt only');

    const removed = await checked(await backend(credential, {action: 'selection.update', request_id: crypto.randomUUID(),
      expected_selection_revision: selected.site.selection_revision, recording_ids: []}), 200, 'Owner withdraws site selection');
    equal(removed.recording_ids, [], 'Selection removal did not persist');
    await rejected(await fixture.request(beginInput(site.site_id, first.recording_id)), 'Withdrawn recording cannot begin');
    check([403, 404, 410].includes((await media(started)).status), 'Withdrawn selection retained old media authority');
    const reselected = await checked(await backend(credential, {action: 'selection.update', request_id: crypto.randomUUID(),
      expected_selection_revision: removed.site.selection_revision, recording_ids: [first.recording_id]}), 200, 'Explicit selection restoration');
    const activeInput = beginInput(site.site_id, first.recording_id);
    const active = await checked(await fixture.request(activeInput), 200, 'Restored selection supports a new attempt');
    fixture.execute('UPDATE users SET is_active=0 WHERE id=11');
    await rejected(await backend(credential, {action: 'import.manifest'}), 'Suspended artist loses backend access');
    check([403, 404, 410].includes((await media(active)).status), 'Suspended artist retained ticket delivery');
    fixture.execute('UPDATE users SET is_active=1 WHERE id=11');
    const revoked = await checked(await managed({action: 'site.revoke', site_id: site.site_id,
      expected_revision: reselected.site.revision, request_id: crypto.randomUUID()}), 200, 'Owner revokes site connection');
    equal(revoked.site.status, 'revoked', 'Site revocation state');
    await rejected(await backend(credential, {action: 'catalog.read'}), 'Revoked credential cannot read catalog');
    await rejected(await fixture.request({action: 'playback.renew', site_id: site.site_id, session_id: active.session_id, request_id: crypto.randomUUID()}, {token: activeInput.playback_key}), 'Revoked site cannot renew existing playback');
    check([403, 404, 410].includes((await media(active)).status), 'Revoked site retained old media authority');
    equal(counts(), beforePlay, 'Provider lifecycle altered legacy personal history');
    check(!fixture.logs.includes(credential) && !fixture.logs.includes(input.playback_key), 'Provider credentials leaked to application logs');

    console.log(`MUSIC_PROVIDER_API_V1=PASS ${checks} real HTTP/SQLite migration, owner/backend/visitor separation, cookie-free origin policy, pinned GET/HEAD/ranges, exact retries, replacement and revocation checks`);
  } finally { fixture.close(); }
}

await runTests();
