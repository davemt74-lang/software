import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const must = (condition, message) => assert.equal(Boolean(condition), true, message);

const manifest = JSON.parse(read('browser-companion/manifest.json'));
const background = read('browser-companion/background.js');
const panelHtml = read('browser-companion/sidepanel.html');
const panelJs = read('browser-companion/sidepanel.js');
const mediaService = read('includes/browser-share-media-v2040.php');
const mediaApi = read('api/browser-share-media-v2040.php');
const worker = read('jobs/browser-share-media-worker-v2040.php');
const card = read('browser-share-card-v2020.js');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');

must(manifest.version === '20.40.0', 'rich capture extension version must be v20.40.0');
must(manifest.manifest_version === 3, 'rich capture must remain Manifest V3');
must(!manifest.host_permissions?.includes('<all_urls>'), 'rich capture must not add required all-URL access');
must(!manifest.permissions?.includes('tabCapture'), 'v20.40 must not capture/download third-party tab media');

for (const source of [background, panelJs, card]) {
  must(!/\beval\s*\(/.test(source), 'rich capture code must not use eval');
  must(!/new\s+Function\s*\(/.test(source), 'rich capture code must not construct executable code');
  must(!/innerHTML\s*=/.test(source), 'rich capture code must remain DOM-safe');
}

must(background.includes('async function selectScreenshotRegion()'), 'region screenshot capture helper missing');
must(background.includes('chrome.tabs.captureVisibleTab'), 'visible-tab screenshot capture missing');
must(background.includes('new OffscreenCanvas'), 'screenshot crop must happen locally in the extension');
must(background.includes("type: 'image/png'"), 'screenshot derivative must use PNG');
must(background.includes("kind: isYoutube ? 'youtube_clip'"), 'YouTube media detection missing');
must(background.includes("tag === 'audio' ? 'audio_reference' : 'video_reference'"), 'generic web media detection missing');
must(background.includes('VP3_MEDIA_CLIP_MAX_SECONDS = 90'), 'media clip ceiling must be 90 seconds');
must(background.includes("'/api/browser-share-media-v2040.php'"), 'rich media API integration missing');
must(background.includes('async function createMediaReference'), 'source-media reference path missing');
must(background.includes('async function uploadBinaryMedia'), 'private binary attachment path missing');
must(background.includes('fallbackSelection'), 'non-text captures require a readable text fallback');
must(background.includes("share_type: 'selection'"), 'v20.40 must preserve the canonical Browser Share creation contract');
must(!background.includes('tabCapture'), 'rich capture must not record protected tab media');
must(!background.includes('captureStream('), 'rich capture must not rip media element streams');

for (const label of ['Screenshot region','Media moment','Voice commentary']) {
  must(panelHtml.includes(label), `side panel is missing ${label}`);
}
must(panelJs.includes('navigator.mediaDevices.getUserMedia({ audio: true })'), 'voice commentary must use explicit microphone capture');
must(panelJs.includes('new MediaRecorder'), 'voice commentary recorder missing');
must(panelJs.includes('CLIP_MAX_SECONDS = 90'), 'panel must enforce the 90-second capture ceiling');
must(panelJs.includes('COMMENTARY_MAX_BYTES = 16 * 1024 * 1024'), 'voice commentary client byte limit missing');
must(panelJs.includes("message('capture_region')"), 'screenshot region UI wiring missing');
must(panelJs.includes('source timestamps only'), 'media UI must explain source-reference behavior');
must(panelJs.includes('media_errors'), 'partial rich-media upload failures must be visible to the user');

must(mediaService.includes("table_exists('browser_share_media_v2040')"), 'rich media schema readiness missing');
must(mediaService.includes('browser_share_media_jobs_v2040'), 'media processing queue table missing');
must(mediaService.includes('vp3_browser_share_by_public_id_v2010'), 'media authorization must reuse canonical live Browser Share access');
must(mediaService.includes("['image/png','image/jpeg','image/webp']"), 'screenshot MIME allowlist missing');
must(mediaService.includes("['audio/webm','audio/ogg','audio/mp4','audio/mpeg','audio/wav','audio/x-wav']"), 'commentary MIME allowlist missing');
must(mediaService.includes('VP3_BROWSER_SHARE_MEDIA_CLIP_MAX_SECONDS_V2040 = 90.0'), 'server media-reference ceiling must be 90 seconds');
must(mediaService.includes('browser_share_private_root'), 'private storage override missing');
must(mediaService.includes("dirname(STONEFELLOW_ROOT).DIRECTORY_SEPARATOR.'vp3-private'"), 'default media storage must be outside the application web root');
must(!mediaService.includes('base64'), 'binary media must not be persisted as base64 in the database');
must(mediaService.includes('UNIQUE KEY uq_browser_share_media_dedupe (browser_share_id,media_kind,sha256)'), 'attachment retry dedupe constraint missing');
must(mediaService.includes('function vp3_browser_share_media_existing_v2040'), 'attachment replay lookup missing');
must(mediaService.includes("$sha=hash('sha256',$bytes)"), 'binary attachment fingerprint missing');
must(mediaService.includes("$sha=hash('sha256',$encoded)"), 'source-reference fingerprint missing');
must(mediaService.includes("$metadata,'processing'"), 'binary attachments must remain processing until inspection succeeds');
must(mediaService.includes("(string)$e->getCode()==='23000'"), 'concurrent duplicate attachment retries must converge safely');

must(mediaApi.includes('vp3_extension_apply_cors_v2001()'), 'media API must use hardened extension CORS');
must(mediaApi.includes('vp3_extension_session_authenticate_v2001($pdo)'), 'media API must authenticate extension bearer sessions');
must(mediaApi.includes("$cap=$write?'team.share.create':'team.chat.read'"), 'extension media access capability gates missing');
must(mediaApi.includes('current_user()'), 'web cards must require a live signed-in VP3 user');
must(mediaApi.includes("if($write)throw new VP3BrowserShareMediaExceptionV2040"), 'web sessions must not gain media upload authority');
must(mediaApi.includes("class_exists('finfo')"), 'server MIME inspection missing');
must(mediaApi.includes("header('X-Content-Type-Options: nosniff')"), 'private media endpoint must disable MIME sniffing');
must(mediaApi.includes("header('Cache-Control: private, no-store')"), 'private media bytes must not be publicly cached');
must(!mediaApi.includes('ensure_schema'), 'public media API must never run DDL');

must(worker.includes("job_status='queued'"), 'media worker queue claim missing');
must(worker.includes('getimagesize($path)'), 'screenshot inspection missing');
must(worker.includes("media_status='ready'"), 'worker ready transition missing');
must(worker.includes("attempts>=3"), 'worker retry/failure ceiling missing');

must(card.includes("'/api/browser-share-media-v2040.php'"), 'Browser Share card rich-media loader missing');
must(card.includes("item.kind==='screenshot'"), 'Browser Share screenshot renderer missing');
must(card.includes("item.kind==='commentary_audio'"), 'Browser Share commentary renderer missing');
must(card.includes("item.kind==='youtube_clip'"), 'Browser Share media-reference renderer missing');
must(card.includes('encodeURIComponent(id)'), 'Browser Share media lookup must encode public IDs');
must(card.includes("a.rel='noopener noreferrer'"), 'external media links must be isolated');

must(bootstrap.includes("require_once __DIR__.'/browser-share-media-v2040.php';"), 'bootstrap must load the rich-media authority');
must(upgrade.includes('vp3_browser_share_media_schema_ready_v2040()'), 'upgrade readiness must include rich-media schema');
must(upgrade.includes('vp3_browser_share_media_ensure_schema_v2040();'), 'upgrade must install rich-media schema');

console.log('VP3 Browser Companion rich capture v20.40 contract passed.');
