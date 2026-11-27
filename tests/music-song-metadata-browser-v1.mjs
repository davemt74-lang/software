import assert from 'node:assert/strict';
import {readFile, mkdir} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {resolve} from 'node:path';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const origin = 'https://music-metadata.test';
const endpoint = '/api/music-song-metadata-v1.php';
const createEndpoint = '/api/chat-create-v76.php';
const root = process.cwd();
const source = await readFile('chat-legacy-v108.php', 'utf8');
const chatSource = await readFile('chat.js', 'utf8');
const librarySource = await readFile('music-library.php', 'utf8');
const libraryCss = librarySource.match(/<style>([\s\S]*?)<\/style>/)?.[1];
assert.ok(libraryCss, 'actual Music Library shell CSS is available');
const workspaceSource = await readFile('music-workspace.php', 'utf8');
const workspaceCss = await readFile('assets/css/music-analytics-v1.css', 'utf8');
const workspaceBody = workspaceSource.match(/<div class="music-wrap">([\s\S]*?)<\/div><\/main>/)?.[1];
assert.ok(workspaceCss && workspaceBody, 'actual Music Workspace layout is available');
const chatFormSource = source.match(/<form\s+class="chat-create-form"\s+data-chat-create-form="track"[\s\S]*?<\/form>/)?.[0];
assert.ok(chatFormSource, 'the active Add Track PHP form is available');
const actualFunction = (start, end) => {
  const from = chatSource.indexOf(start), to = chatSource.indexOf(end, from + start.length);
  assert.ok(from >= 0 && to > from, `active Chat controller boundary: ${start}`);
  return chatSource.slice(from, to);
};
// Exercise the production submit/status functions without booting unrelated Chat services.
const controller = actualFunction('  function setCreateModalStatus(', '  function closeCreateModal(')
  + actualFunction('  async function submitCreateForm(', '  function closeCreateDropdown(');
const [css, chatCss, script] = await Promise.all([
  'assets/css/music-song-metadata-form-v1.css', 'chat.css', 'assets/js/music-song-metadata-form-v1.js'
].map(path => readFile(path, 'utf8')));
const partyA = {party_id: '11111111-1111-4111-8111-111111111111', name: 'Artist One', ipi: '00123456789', pro: 'Private Society One'};
const partyB = {party_id: '22222222-2222-4222-8222-222222222222', name: 'Artist Two', ipi: '00987654321', pro: 'Private Society Two'};
const workA = {work_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', work_revision: 3, work_title: 'Saved composition', iswc: 'T1234567890', iswc_status: 'provided', writers: [{...partyA, role: 'Composer'}], publishers: []};
const workB = {work_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', work_revision: 5, work_title: 'Other workspace composition', iswc: '', iswc_status: 'unknown', writers: [{...partyB, role: 'Composer'}], publishers: []};
const requestKey = 'c'.repeat(32);
const php = String.raw`
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url($path):string{return $path;}
function csrf_field():string{return '<input type="hidden" name="csrf_token" value="metadata-browser-fixture">';}
function visibility_options():array{return ['public'=>'Public','members'=>'Members','private'=>'Private'];}
function csrf_token():string{return 'metadata-browser-fixture';}
function current_user():array{return ['id'=>71,'display_name'=>'Music artist'];}
function user_has_role($role,$user):bool{return $role==='artist';}
function has_permission($key,$user):bool{return false;}
function user_role_labels($user):array{return ['Artist'];}
function user_avatar_url($user):string{return '';}
function user_initials($user):string{return 'MA';}
function member_navigation_section_label($key):string{return 'Music';}
function member_navigation_menu_links($user):array{return [
 ['key'=>'home','label'=>'Home','url'=>'/home.php','group'=>'agent'],
 ['key'=>'music_workspace','label'=>'Music Workspace','url'=>'/music-workspace.php','group'=>'creator'],
 ['key'=>'account','label'=>'Account','url'=>'/account.php','group'=>'identity']
];}
function notification_recent($user,$limit):array{return [];}
function notification_unread_count($user):int{return 0;}
function member_agent_voice_toggle_html($user):string{return '';}
function music_workspace_resources_v330_can_manage($pdo,$workspaceId,$resource,$user):bool{return true;}
$fixture=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
require $fixture['renderer'];
if($fixture['mode']==='shell'){
  $shellRoot=dirname(dirname($fixture['renderer']));
  $workspaceSidebarUser=current_user();$workspaceSidebarActive='music_workspace';
  ob_start();require $shellRoot.'/includes/workspace-sidebar-v82.php';$sidebar=ob_get_clean();
  $memberHeaderTitle='Music Library';$memberHeaderSubtitle='Studio catalog';$memberHeaderShowAgentOrb=false;
  ob_start();require $shellRoot.'/includes/member-header.php';$header=ob_get_clean();
  echo json_encode(['sidebar'=>$sidebar,'header'=>$header],JSON_THROW_ON_ERROR);
}elseif($fixture['mode']==='workspace'){
  $pdo=null;$user=current_user();$workspaceId=11;$workspace=['id'=>11,'workspace_name'=>'Studio catalog'];$workspaces=[$workspace];
  $role='owner';$counts=['tracks'=>12,'albums'=>3,'releases'=>2,'team'=>2];$canManageSongs=true;$metadataReady=true;$canListening=false;$profileUrl='/artist.php?user=71';
  $canAnalytics=false;$libraryUrl='/music-library.php?workspace=11';
  eval('?>'.$fixture['workspace_body']);
}elseif($fixture['mode']==='chat'){
  $chatMusicMetadataReady=true;
  $chatMusicWorkspaces=[['id'=>11,'workspace_name'=>'Workspace One'],['id'=>22,'workspace_name'=>'Workspace Two']];
  $chatMusicAlbums=[['id'=>101,'workspace_id'=>11,'title'=>'Album One'],['id'=>202,'workspace_id'=>22,'title'=>'Album Two']];
  eval('?>'.$fixture['chat_source']);
}else{
  $options=$fixture['options'];
  $state=array_replace(music_song_metadata_form_blank_v1(true,$options['values']),$fixture['state']);
  $state['metadata']=array_replace(music_song_metadata_v1_defaults($options['values']),$fixture['state']['metadata']??[]);
  $options['state']=$state;
  music_song_catalog_editor_v1($options);
}`;
function render(mode, state = {}, options = {}) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', ['-d', 'display_errors=1', '-r', php], {
    cwd: root, encoding: 'utf8', input: JSON.stringify({mode, state, renderer: resolve('includes/music-song-metadata-form-v1.php'), chat_source: chatFormSource, workspace_body: workspaceBody,
      options: {kind: 'track', values: {title: 'Draft recording'}, editing: null, revision: '', workspace_id: 11, albums: [], photos: [], error: '', can_media: true, prefix: 'editor', action_url: '/music-library.php?workspace=11', cancel_url: '/music-library.php?workspace=11', reload_url: '/music-library.php?workspace=11&edit=9', ...options}})
  });
  assert.equal(result.status, 0, `real PHP form renderer executes: ${result.stderr || result.error || result.stdout}`);
  assert.ok(!/Warning:|Fatal error:|Deprecated:/.test(result.stdout), result.stdout);
  return result.stdout;
}
const defaults = {parties: [partyA], works: [workA], request_key: requestKey};
// Use the actual shared PHP shell and its emitted CSS ordering. Only unrelated
// voice/HomeServer services are omitted; the shipped navigation controller runs.
const rawShell = JSON.parse(render('shell'));
const navigationScriptsOnly = html => html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, tag => /src="[^\"]*\/(?:member-shell-v77|agent-ui-v034)\.js(?:\?|\")/.test(tag) ? tag : '');
const shell = {sidebar: navigationScriptsOnly(rawShell.sidebar), header: navigationScriptsOnly(rawShell.header)};
const pages = {
  '/new': render('editor', defaults),
  '/saved': render('editor', {...defaults, metadata_revision: 7, recording_id: 'recording-stable', metadata: {...workA, rights_notes: 'Private saved notes', contact_email: 'private@example.test'}, readiness: {catalog: {state: 'ready', ready: true, reasons: []}, playback: {state: 'ready', ready: true, reasons: []}}}, {editing: {id: 9, title: 'Saved recording'}, revision: 'catalog-row-revision'}),
  '/error': render('editor', {...defaults, metadata_revision: 4, metadata: {work_title: 'Unsaved work title', work_revision: 2, rights_notes: 'Unsaved private notes <script>window.attack=true</script>', contact_email: 'unsaved@example.test'}}, {editing: {id: 9, title: 'Saved title'}, values: {title: 'Unsaved song title'}, revision: 'stale-catalog-revision', error: 'This song changed. Review its latest saved version before retrying.'}),
  '/upgrade': render('editor', {...defaults, ready: false, error: 'Song metadata needs a database upgrade before songs can be saved.'}),
  '/chat': render('chat'),
  '/workspace': render('workspace'),
};
let checks = 0, failPost = true, failLookup = false, holdLookup = null;
const errors = [], posts = [];
const check = (value, message) => {assert.ok(value, message); checks++;};
const deferred = () => {let resolve; const promise = new Promise(done => {resolve = done;}); return {promise, resolve};};
const browser = await chromium.launch({headless: true, args: ['--no-sandbox'], executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
try {
  const page = await browser.newPage({viewport: {width: 1280, height: 960}});
  page.setDefaultTimeout(10000);
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.fallback() : route.fulfill({status: 404, body: ''}));
  await page.route(origin + '/**', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === endpoint) {
      const id = Number(url.searchParams.get('workspace_id'));
      if (holdLookup && id === 11) {const gate = holdLookup; holdLookup = null; gate.started.resolve(); await gate.release.promise;}
      if (failLookup) return route.fulfill({status: 403, json: {ok: false, message: 'Workspace access changed. Reload your account.'}});
      return route.fulfill({json: {ok: true, parties: id === 11 ? [partyA] : [partyB], works: id === 11 ? [workA] : [workB], recording: null, catalog: null}}).catch(() => {});
    }
    if (url.pathname === createEndpoint) {
      posts.push(request.postData());
      return route.fulfill(failPost ? {status: 409, json: {ok: false, message: 'This song changed. Your entered fields are kept.'}} : {json: {ok: true, type: 'track', id: 9, open_url: '/music-library.php?workspace=22&tab=tracks&edit=9#editor', message: 'Song draft saved.'}});
    }
    if (url.pathname === '/metadata.js') return route.fulfill({contentType: 'text/javascript', body: script});
    if (url.pathname === '/metadata.css') return route.fulfill({contentType: 'text/css', body: css});
    if (url.pathname === '/chat.css') return route.fulfill({contentType: 'text/css', body: chatCss});
    if (/\.(css|js)$/.test(url.pathname)) {
      const path = resolve(root, '.' + url.pathname);
      if (path.startsWith(root + '/')) {
        try {return await route.fulfill({contentType: path.endsWith('.css') ? 'text/css' : 'text/javascript', body: await readFile(path)});} catch (_) {}
      }
      return route.fulfill({status: 404, body: ''});
    }
    if (url.pathname.startsWith('/api/')) return route.fulfill({json: {ok: true, unread: 0, items: [], state: {}}});
    const chat = url.pathname === '/chat';
    const workspace = url.pathname === '/workspace';
    const content = pages[url.pathname];
    if (!content) return route.fulfill({status: 404, body: ''});
    const body = chat ? `<div class="chat-create-modal"><section class="chat-create-modal-card"><header class="chat-create-modal-head"><h2>Add Track</h2><a id="chatCreateAdvanced" href="#">Full editor</a></header><div class="chat-create-modal-status" id="chatCreateModalStatus" role="status" tabindex="-1" hidden></div><div class="chat-create-modal-body">${content}</div></section></div>` : `<div class="chat-app">${shell.sidebar}<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main ${workspace ? 'music-main' : 'ml-main'}">${workspace ? shell.header.replace('Music Library', 'Music Workspace') : shell.header}<div class="${workspace ? 'music-wrap' : 'ml-wrap'}">${content}</div></main></div>`;
    const bindings = chat ? `<script>const cfg={createEndpoint:'${createEndpoint}'};const createModalStatus=document.getElementById('chatCreateModalStatus');const createAdvanced=document.getElementById('chatCreateAdvanced');${controller}const form=document.querySelector('[data-chat-create-form]');form.hidden=false;form.addEventListener('submit',event=>{event.preventDefault();submitCreateForm(form);});</script>` : '';
    return route.fulfill({contentType: 'text/html', body: `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"><link rel="stylesheet" href="/metadata.css"><style>${workspace ? workspaceCss : libraryCss}</style></head><body${workspace ? ' class="music-analytics-page"' : ''}>${body}<script src="/metadata.js"></script>${bindings}</body></html>`});
  });
  const settleClosedNavigation = async () => {
    await page.waitForFunction(() => {
      const sidebar = document.getElementById('chatSidebar');
      return sidebar && !document.body.classList.contains('chat-nav-open') && sidebar.getBoundingClientRect().right <= 0
        && sidebar.getAnimations().every(animation => animation.playState !== 'running');
    });
  };
  const hitTarget = locator => locator.evaluate(node => {
    const rect = node.getBoundingClientRect();
    if (rect.top < 0 || rect.bottom > innerHeight || rect.left < 0 || rect.right > innerWidth) return false;
    const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
    return hit === node || node.contains(hit);
  });
  const checkReadableCreateStatus = async state => {
    const metrics = await page.locator('#chatCreateModalStatus').evaluate(status => {
      const style = getComputedStyle(status);
      const rgb = value => (value.match(/[\d.]+/g) || []).map(Number);
      const foreground = rgb(style.color), background = rgb(style.backgroundColor);
      const luminance = channels => channels.slice(0, 3).map(value => {
        const channel = value / 255;
        return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
      }).reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);
      const fg = luminance(foreground), bg = luminance(background);
      return {fontSize: parseFloat(style.fontSize), contrast: (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05),
        opaque: (foreground[3] ?? 1) === 1 && (background[3] ?? 1) === 1, color: style.color, background: style.backgroundColor};
    });
    check(metrics.fontSize >= 14, `${state} create status uses at least 14px text: ${JSON.stringify(metrics)}`);
    check(metrics.opaque && metrics.contrast >= 4.5, `${state} create status has at least 4.5:1 computed text contrast: ${JSON.stringify(metrics)}`);
  };
  const exerciseNavigation = async () => {
    await page.getByRole('button', {name: 'Open VP3 navigation', exact: true}).click();
    await page.waitForFunction(() => document.body.classList.contains('chat-nav-open') && document.getElementById('chatSidebar').getBoundingClientRect().left >= -0.5);
    check(await hitTarget(page.getByRole('button', {name: 'Close menu', exact: true})), 'real mobile navigation opens with an unobstructed close control');
    await page.getByRole('button', {name: 'Close menu', exact: true}).click();
    await settleClosedNavigation();
    check(await hitTarget(page.getByRole('button', {name: 'Open VP3 navigation', exact: true})), 'real member shell closes navigation and restores the header hit target');
  };
  await page.goto(origin + '/new');
  check(!await page.locator('[name="is_published"]').isChecked(), 'new songs begin as unpublished drafts');
  check(!await page.locator('[name="audio_file"]').evaluate(input => input.required), 'audio is optional for a draft');
  for (let i = 0; i < 3; i++) {
    await page.locator('[data-party-add="writers"]').click();
    await page.locator('[data-party-list="writers"] [data-party-field="name"]').last().fill(`Writer ${i}`);
  }
  await page.locator('[data-party-list="writers"] [data-party-remove]').nth(1).click();
  const indices = await page.locator('form').evaluate(form => {
    window.VP3MusicMetadataFormV1.prepare(form);
    return [...new FormData(form).keys()].filter(name => /^music_metadata\[writers\]\[\d+\]\[name\]$/.test(name));
  });
  check(JSON.stringify(indices) === JSON.stringify(['music_metadata[writers][0][name]', 'music_metadata[writers][1][name]']), 'removed rows submit a contiguous PHP list');
  const firstWriter = page.locator('[data-party-list="writers"] [data-party-row]').first();
  await firstWriter.locator('[data-party-select]').selectOption(partyA.party_id);
  check(await firstWriter.locator('[data-party-field="name"]').inputValue() === partyA.name && await firstWriter.locator('[data-party-field="ipi"]').inputValue() === partyA.ipi, 'saved contributors populate canonical workspace details');
  check(await firstWriter.locator('[data-party-field="name"]').evaluate(input => input.readOnly), 'reused party identity is read only');
  await firstWriter.locator('[data-party-field="role"]').fill('Lyricist');
  await page.locator('[data-work-select]').selectOption(workA.work_id);
  check(await page.locator('[name="music_metadata[work_title]"]').inputValue() === workA.work_title && await page.locator('[data-party-add="writers"]').isDisabled(), 'work reuse locks canonical work and composition contributors');
  const reused = await page.locator('form').evaluate(form => {window.VP3MusicMetadataFormV1.prepare(form); return Object.fromEntries(new FormData(form));});
  check(reused['music_metadata[iswc_status]'] === 'provided' && reused['music_metadata[writers][0][party_id]'] === partyA.party_id, 'locked selections retain submission values');
  await page.locator('[data-work-select]').selectOption('');
  check(!await page.locator('[name="music_metadata[work_title]"]').evaluate(input => input.readOnly) && await page.locator('[data-party-row]').count() === 2, 'leaving reuse restores the manually entered work and contributors');
  await firstWriter.locator('[data-party-select]').selectOption('');
  await firstWriter.locator('[data-party-field="name"]').fill('<img src=x onerror="window.attack=true">');
  await page.locator('summary').click();
  await page.locator('[name="music_metadata[rights_notes]"]').fill('PRIVATE-RIGHTS-NOTE');
  await page.locator('[name="music_metadata[contact_email]"]').fill('private@example.test');
  const preview = await page.locator('[data-credit-preview]').textContent();
  check(!preview.includes(partyA.ipi) && !preview.includes(partyA.pro) && !preview.includes('PRIVATE-RIGHTS-NOTE') && !preview.includes('private@example.test'), 'credit preview excludes private identifiers, affiliations, notes and contact');
  check(await page.locator('[data-credit-preview] img').count() === 0 && await page.evaluate(() => !window.attack), 'credit names render as text');
  // Keep the security assertion above, then use readable sample values in review images.
  await firstWriter.locator('[data-party-field="name"]').fill('Avery Lane');
  await page.locator('[data-party-list="writers"] [data-party-field="name"]').nth(1).fill('Sam Rivera');
  await page.locator('[name="title"]').fill('Evening Light (acoustic demo)');
  await page.locator('[name="music_metadata[work_title]"]').fill('Evening Light');
  await page.locator('[name="music_metadata[rights_notes]"]').fill('Follow up on contributor confirmation before release.');
  await page.locator('[name="music_metadata[contact_email]"]').fill('studio@example.test');
  const artifacts = process.env.MUSIC_METADATA_ARTIFACTS || 'artifacts/music-song-metadata-v1';
  await mkdir(artifacts, {recursive: true});
  await page.locator('.ml-wrap').evaluate(wrap => {wrap.scrollTop = 0;});
  await page.screenshot({path: `${artifacts}/desktop.png`, fullPage: true});
  await page.setViewportSize({width: 390, height: 844});
  await settleClosedNavigation();
  await page.locator('.ml-wrap').evaluate(wrap => {wrap.scrollTop = 0;});
  await exerciseNavigation();
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'shared editor fits a 390px phone viewport');
  check(await page.locator('[data-song-metadata]').evaluate(root => [...root.querySelectorAll('*')].filter(node => node.tagName !== 'TEXTAREA' && ['auto', 'scroll'].includes(getComputedStyle(node).overflowY) && node.scrollHeight > node.clientHeight + 1).length === 0), 'shared metadata form adds no nested vertical scroller');
  check(await hitTarget(page.locator('[name="title"]')), 'collapsed real sidebar leaves the top-of-form title field unobstructed');
  await page.screenshot({path: `${artifacts}/mobile.png`, fullPage: true});
  const saveSong = page.getByRole('button', {name: 'Save song', exact: true});
  await saveSong.click({trial: true});
  check(await hitTarget(saveSong) && await page.locator('.ml-wrap').evaluate(wrap => wrap.scrollTop > 0), 'real Music Library content scroller reaches a clickable, unobstructed Save action');
  check(await page.locator('.ml-main').evaluate(main => main.scrollTop === 0 && main.scrollHeight <= main.clientHeight + 1) && await hitTarget(page.getByRole('button', {name: 'Open VP3 navigation', exact: true})), 'Music Library keeps one content scroll owner and its real header reachable at Save');
  await exerciseNavigation();
  await saveSong.click({trial: true});
  await page.screenshot({path: `${artifacts}/mobile-save.png`, fullPage: true});
  await page.goto(origin + '/workspace');
  await settleClosedNavigation();
  check(await page.locator('.music-main').evaluate(main => main.scrollHeight <= main.clientHeight + 1) && await hitTarget(page.getByRole('link', {name: 'Add song', exact: true})), 'Music Workspace uses the real header with a usable top action');
  await page.screenshot({path: `${artifacts}/workspace-mobile.png`, fullPage: true});
  await page.locator('.music-ownership').scrollIntoViewIfNeeded();
  check(await page.evaluate(() => scrollY > 0) && await page.locator('.music-wrap').evaluate(wrap => wrap.scrollTop === 0 && !['auto','scroll'].includes(getComputedStyle(wrap).overflowY)) && await page.locator('.music-ownership').evaluate(note => note.getBoundingClientRect().bottom <= innerHeight) && await hitTarget(page.getByRole('button', {name: 'Open VP3 navigation', exact: true})), 'Music Workspace uses normal page flow through its final content while keeping navigation reachable');
  await exerciseNavigation();
  await page.screenshot({path: `${artifacts}/workspace-mobile-bottom.png`, fullPage: true});
  await page.goto(origin + '/saved');
  check(await page.getByText('Saved catalog identity is ready.').count() === 1 && await page.getByText('Listening audio is ready for its selected audience.').count() === 1, 'ready saved state does not instruct users to establish an existing record');
  await page.locator('[data-work-select]').selectOption('');
  check(await page.locator('[data-work-revision]').inputValue() === '3' && !await page.locator('[name="music_metadata[work_title]"]').evaluate(input => input.readOnly), 'editing the current work preserves its saved revision');
  await page.goto(origin + '/error');
  check(await page.locator('[data-song-error-summary]').evaluate(summary => summary === document.activeElement), 'server validation/conflict summary receives keyboard focus');
  check(await page.locator('[name="title"]').inputValue() === 'Unsaved song title' && (await page.locator('[name="music_metadata[rights_notes]"]').inputValue()).includes('Unsaved private notes'), 'failed render preserves song and private metadata fields');
  check(await page.locator('[name="revision"]').inputValue() === 'stale-catalog-revision' && await page.locator('[name="metadata_revision"]').inputValue() === '4' && await page.locator('[data-work-revision]').inputValue() === '2' && await page.locator('[data-song-request-key]').inputValue() === requestKey, 'conflicts retain stale catalog/metadata/work revisions and the intended request key');
  check(await page.evaluate(() => !window.attack), 'failed private content remains escaped');
  await page.goto(origin + '/upgrade');
  check(await page.locator('[data-song-save]').first().isDisabled() && await page.getByText('Song metadata needs a database upgrade before songs can be saved.').count() > 0, 'missing metadata schema presents a blocked upgrade state');
  await page.goto(origin + '/chat');
  check(await page.locator('[data-song-workspace]').inputValue() === '' && await page.locator('[data-song-save]').isDisabled(), 'main Add Track requires an explicit workspace');
  await page.locator('[name="title"]').fill('Retained draft title');
  await page.locator('[data-song-workspace]').selectOption('11');
  await page.waitForFunction(() => !document.querySelector('[data-song-save]').disabled);
  await page.locator('[data-song-album]').selectOption('101');
  await page.locator('[data-work-select]').selectOption(workA.work_id);
  await page.locator('[data-label-select]').selectOption(partyA.party_id);
  await page.locator('summary').click();
  await page.locator('[name="music_metadata[rights_notes]"]').fill('Manually entered private note');
  await page.locator('[data-song-workspace]').selectOption('22');
  await page.waitForFunction(() => !document.querySelector('[data-song-save]').disabled);
  check(await page.locator('[data-work-select]').inputValue() === '' && await page.locator('[data-party-row]').count() === 0 && await page.locator('[data-label-name]').inputValue() === '', 'workspace switching withdraws old reusable work, party and label defaults');
  check(await page.locator('[name="title"]').inputValue() === 'Retained draft title' && await page.locator('[name="music_metadata[rights_notes]"]').inputValue() === 'Manually entered private note', 'workspace switching retains manually typed draft fields');
  check(await page.locator('[data-song-album]').inputValue() === '0' && await page.locator('[data-song-album] option[value="101"]').evaluate(option => option.disabled) && !await page.locator('[data-song-album] option[value="202"]').evaluate(option => option.disabled), 'album selection uses only the newly chosen workspace catalog');
  const gate = {started: deferred(), release: deferred()}; holdLookup = gate;
  await page.locator('[data-song-workspace]').selectOption('11'); await gate.started.promise;
  await page.locator('[data-song-workspace]').selectOption('22');
  await page.waitForFunction(() => !document.querySelector('[data-song-save]').disabled);
  gate.release.resolve();
  await page.locator('[data-work-select] option', {hasText: workB.work_title}).waitFor({state: 'attached'});
  check(await page.locator('[data-work-select] option', {hasText: workA.work_title}).count() === 0, 'late prior-workspace response cannot restore its private defaults');
  failLookup = true;
  await page.locator('[data-song-workspace]').selectOption('11');
  await page.locator('[data-metadata-retry]').waitFor();
  check(await page.locator('[data-song-save]').isDisabled() && await page.locator('[name="title"]').inputValue() === 'Retained draft title', 'lookup access failure blocks save while preserving manually entered fields');
  failLookup = false;
  await page.locator('[data-metadata-retry]').click();
  await page.waitForFunction(() => !document.querySelector('[data-song-save]').disabled);
  await page.locator('[data-song-workspace]').selectOption('22');
  await page.waitForFunction(() => !document.querySelector('[data-song-save]').disabled);
  await page.locator('[data-party-add="performers"]').click();
  await page.locator('[data-party-field="name"]').fill('Draft performer');
  await page.locator('[name="audio_file"]').setInputFiles({name: 'draft.wav', mimeType: 'audio/wav', buffer: Buffer.from('RIFF-fixture')});
  const beforeRequest = await page.locator('[data-song-request-key]').inputValue();
  await page.locator('[data-song-save]').click();
  await page.getByText('This song changed. Your entered fields are kept.', {exact: true}).waitFor();
  await checkReadableCreateStatus('Error');
  check(await page.locator('#chatCreateModalStatus').evaluate(status => status === document.activeElement), 'actual Chat submit controller focuses API errors');
  check(await page.locator('[name="title"]').inputValue() === 'Retained draft title' && await page.locator('[data-party-field="name"]').inputValue() === 'Draft performer' && await page.locator('[name="audio_file"]').evaluate(input => input.files.length) === 1 && await page.locator('[data-song-request-key]').inputValue() === beforeRequest, 'actual failed Chat save keeps text, metadata, file and request key');
  check(posts.length === 1 && posts[0].includes('music_metadata[performers][0][name]'), 'actual Chat FormData submits reindexed contributors');
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1) && await page.locator('.chat-create-modal-card').evaluate(card => card.getBoundingClientRect().right <= innerWidth + 1), 'active Add Track modal fits 390px without horizontal overflow');
  await page.screenshot({path: `${artifacts}/chat-mobile.png`, fullPage: true});
  failPost = false;
  await page.locator('[data-song-save]').click();
  await page.getByText('Song draft saved.', {exact: true}).waitFor();
  await checkReadableCreateStatus('Success');
  check(await page.locator('[name="title"]').inputValue() === '' && await page.locator('[data-party-row]').count() === 0 && await page.locator('[data-song-workspace]').inputValue() === '' && await page.locator('[data-song-request-key]').inputValue() !== beforeRequest, 'successful Chat save resets draft details and renews the next request key');
  check(await page.locator('[data-song-save]').isDisabled() && (await page.locator('#chatCreateAdvanced').getAttribute('href')).includes('edit=9#editor'), 'saved song links to its authorized editor while the next song needs a workspace');
  check(errors.length === 0, 'no browser errors: ' + errors.join('; '));
  console.log(`MUSIC_SONG_METADATA_BROWSER_V1=PASS (${checks} behavioral checks; desktop and 390px phone)`);
} finally {
  await browser.close();
}
