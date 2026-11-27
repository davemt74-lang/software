import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {musicAnalyticsFixture,totalsRoute,totalsProtocol} from './music-analytics-fixture-v1.mjs';

const playwright = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browserName = process.env.MUSIC_ANALYTICS_BROWSER || 'chromium';
assert.ok(['chromium','webkit'].includes(browserName), 'Choose Chromium or WebKit.');
const artifacts = path.resolve(process.env.MUSIC_LISTENING_TOTALS_ARTIFACT_DIR || `artifacts/music-listening-totals-v1/${browserName}`);
fs.mkdirSync(artifacts, {recursive:true});
const fixture = await musicAnalyticsFixture();
let browser, checks = 0;
const evidence = [], errors = [];
const equal = (actual, expected, message) => {assert.deepEqual(actual, expected, message); checks++; evidence.push(message);};
const check = (actual, message) => {assert.ok(actual, message); checks++; evidence.push(message);};
const launch = {headless:true};
if (browserName === 'chromium' && process.env.MUSIC_ANALYTICS_CHROMIUM_EXECUTABLE) launch.executablePath = process.env.MUSIC_ANALYTICS_CHROMIUM_EXECUTABLE;
const ready = async page => {
  await page.waitForFunction(() => ['ready','error'].includes(document.getElementById('musicListeningTotals')?.dataset.reportState));
  assert.equal(await page.locator('#musicListeningTotals').getAttribute('data-report-state'), 'ready', await page.locator('#musicListeningTotalsStatus').textContent());
};
const metrics = page => page.locator('#musicListeningTotalsMetrics strong').allTextContents();
const apply = async page => {await page.locator('#musicListeningTotalsApply').click(); await ready(page);};
const refresh = async page => {await page.locator('#musicListeningTotalsRefresh').click(); await ready(page);};
const layout = page => page.evaluate(() => ({
  viewport:innerWidth, documentWidth:document.documentElement.scrollWidth,
  scrollers:[...document.querySelectorAll('#musicListeningTotals,#musicListeningTotals *,.music-main,.music-wrap,.admin-content')]
    .filter(element => /auto|scroll/.test(getComputedStyle(element).overflowY) && element.scrollHeight > element.clientHeight + 1).length,
}));
const watchErrors = page => page.on('pageerror', error => errors.push(error.message));
try {
  browser = await playwright[browserName].launch(launch);
  const context = await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Phoenix',extraHTTPHeaders:{'X-User':'11'}});
  const page = await context.newPage(); watchErrors(page);
  const requests = [];
  page.on('request', request => {if (request.url().endsWith(totalsRoute) && request.method() === 'POST') requests.push(request.postDataJSON());});
  equal((await page.goto(fixture.base + '/music-workspace.php?workspace=1')).status(), 200, 'Actual workspace page renders for its owner');
  await ready(page);
  equal(await metrics(page), ['2','2','40 s'], 'Lifetime overview combines measured native and provider listens');
  equal(requests.at(-1).scope, 'workspace', 'Artist page requests workspace-scoped authority');
  equal(requests.at(-1).traffic, 'audience', 'Lifetime default excludes known previews and staging');
  equal(requests.at(-1).group, 'tracks', 'Artist lifetime breakdown groups canonical songs');
  check(!('period' in requests.at(-1)) && !('start_date' in requests.at(-1)), 'Lifetime request has no date cutoff');
  check((await page.locator('#musicListeningTotalsSources').textContent()).includes('VP3 player1 listen')
    && (await page.locator('#musicListeningTotalsSources').textContent()).includes('Connected sites1 listen'), 'Source subtotals account for both measurement channels');
  check((await page.locator('#musicListeningTotals').textContent()).includes('All-time listens (10s+)'), 'Primary total states the ten-second listen definition');
  check((await page.locator('#musicListeningTotals .mlt-intro').textContent()).includes('History before tracking began or records that were removed is unknown'), 'Visible coverage caveat does not promise unknown lifetime history');
  check((await page.locator('#musicListeningTotals').textContent()).includes('not unique people'), 'Session totals are not described as unique listeners');
  check((await page.locator('#musicListeningTotalsFreshness').textContent()).includes('UTC')
    && !(await page.locator('#musicListeningTotalsFreshness').textContent()).includes('Unavailable'), 'Lifetime snapshot is displayed with a valid UTC timestamp');
  check(!(await page.locator('#musicListeningTotals').textContent()).includes('PRIVATE'), 'Lifetime aggregate does not expose listener identity or private metadata');
  for (const label of ['Add song','Music Library','Choose a track','Open releases','Open player','Messages','Manage team','Manage music sites'])
    check(await page.getByRole('link',{name:label,exact:true}).count() > 0, 'Existing artist navigation remains: ' + label);
  check(await page.getByRole('link',{name:'All-time listens',exact:true}).count() === 1, 'Section navigation reaches lifetime totals');
  await page.waitForFunction(() => document.getElementById('musicAnalytics')?.dataset.reportState === 'ready');
  equal(await page.locator('#musicAnalyticsMetrics strong').allTextContents(), ['2','2','40 s','100%'], 'Existing dated ML3 report remains functional');
  await page.locator('#musicAnalyticsPeriod').selectOption('custom');
  await page.locator('#musicAnalyticsStart').fill('2020-01-01');
  await page.locator('#musicAnalyticsEnd').fill('2020-01-07');
  await page.locator('#musicAnalyticsApply').click();
  await page.waitForFunction(() => document.getElementById('musicAnalytics').dataset.reportState === 'ready');
  equal(await page.locator('#musicAnalyticsMetrics strong').allTextContents(), ['0','0','0 s','Unavailable'], 'Dated report can show an empty historical window');
  equal(await metrics(page), ['2','2','40 s'], 'Changing the dated report never changes lifetime totals');
  const requestCount = requests.length;
  await page.locator('#musicAnalyticsReset').click();
  await page.waitForFunction(() => document.getElementById('musicAnalytics').dataset.reportState === 'ready');
  equal(requests.length, requestCount, 'Resetting dated filters does not refetch or reset lifetime filters');

  await page.locator('#musicListeningTotalsChannel').selectOption('provider');
  equal(await page.locator('#musicListeningTotals').getAttribute('data-report-state'), 'changed', 'Changed lifetime filters mark the report pending');
  check(!await page.locator('#musicListeningTotalsData').isVisible(), 'Old lifetime figures are hidden after filter changes');
  await apply(page); equal(await metrics(page), ['1','1','20 s'], 'Provider filter uses measured connected-site listens');
  await page.locator('#musicListeningTotalsTraffic').selectOption('tests');
  await apply(page); equal(await metrics(page), ['1','1','10 s'], 'Known staging listening can be inspected separately');
  await page.locator('#musicListeningTotalsChannel').selectOption('native');
  await apply(page); equal(await metrics(page), ['1','1','15 s'], 'Native previews remain separate from audience totals');
  await page.locator('#musicListeningTotalsChannel').selectOption('all');
  await page.locator('#musicListeningTotalsTraffic').selectOption('all');
  await apply(page); equal(await metrics(page), ['4','4','1 m 5 s'], 'All observed traffic combines measured preview and audience sessions');
  await page.locator('#musicListeningTotalsCoverage summary').click();
  check((await page.locator('#musicListeningTotalsCoverageNotes').textContent()).includes('Separate legacy history: 1 session, 45 s'), 'Unversioned legacy listening is labeled separately from measured totals');
  await page.locator('#musicListeningTotalsReset').click(); await ready(page);
  equal(await metrics(page), ['2','2','40 s'], 'Reset returns to audience totals without legacy double counting');

  fixture.run('music_analytics_test_native(42,16,1577966400000,10000);');
  await refresh(page);
  equal(await metrics(page), ['3','3','50 s'], 'Lifetime totals include a measured session more than a year old');
  check((await page.locator('#musicListeningTotalsHistory').textContent()).includes('2020'), 'Retained history bounds include old measured sessions');
  await page.locator('#musicAnalyticsRefresh').click();
  await page.waitForFunction(() => document.getElementById('musicAnalytics').dataset.reportState === 'ready');
  equal(await page.locator('#musicAnalyticsMetrics strong').allTextContents(), ['2','2','40 s','100%'], 'Older lifetime evidence does not change current thirty-day figures');

  fixture.run(`$pdo=db();$pdo->beginTransaction();
    for($i=0;$i<26;$i++){
      $id=1000+$i;$title='Pagination song '.str_pad((string)$i,2,'0',STR_PAD_LEFT);
      $pdo->prepare('INSERT INTO artist_catalog_tracks_v181 (id,workspace_id,source_track_id,title,audio_path,visibility,is_published) VALUES (?,1,NULL,?,?,?,1)')->execute([$id,$title,'','public']);
      $q=$pdo->prepare('SELECT * FROM artist_catalog_tracks_v181 WHERE id=?');$q->execute([$id]);music_song_metadata_v1_import_legacy($pdo,$q->fetch());
    }$pdo->commit();`);
  await refresh(page);
  equal(await page.locator('#musicListeningTotalsTable tbody tr').count(), 25, 'Catalog list presents one bounded page');
  check(await page.locator('#musicListeningTotalsNext').isEnabled(), 'Additional songs remain discoverable with Next');
  equal(await metrics(page), ['3','3','50 s'], 'Zero-play catalog additions do not alter listening totals');
  await page.locator('#musicListeningTotalsNext').click(); await ready(page);
  equal(requests.at(-1).offset, 25, 'Song paging submits the next explicit offset');
  check((await page.locator('#musicListeningTotalsPageLabel').textContent()).startsWith('26–'), 'Song page reports its true row range');
  check((await page.locator('#musicListeningTotalsTable').textContent()).includes('Pagination song'), 'Later canonical catalog songs remain accessible');
  equal(await metrics(page), ['3','3','50 s'], 'Paging does not change the lifetime headline totals');
  await page.locator('#musicListeningTotalsPrevious').click(); await ready(page);
  equal(requests.at(-1).offset, 0, 'Previous returns to the first song page');

  let failNext = true;
  await page.route('**' + totalsRoute, async route => {
    if (failNext) {failNext = false; return route.fulfill({status:503,json:{ok:false,protocol:totalsProtocol,code:'temporarily_unavailable'}});}
    return route.continue();
  });
  await page.locator('#musicListeningTotalsRefresh').click();
  await page.waitForFunction(() => document.getElementById('musicListeningTotals').dataset.reportState === 'error');
  check(!await page.locator('#musicListeningTotalsData').isVisible(), 'Failed refresh never presents stale totals as current');
  check(await page.locator('#musicListeningTotalsRetry').isVisible(), 'Temporary report errors provide a retry');
  await page.locator('#musicListeningTotalsRetry').click(); await ready(page);
  equal(await metrics(page), ['3','3','50 s'], 'Retry restores an actual lifetime report');
  await page.unroute('**' + totalsRoute);
  await page.route('**' + totalsRoute, route => route.fulfill({status:502,contentType:'text/html',body:'<h1>Upstream unavailable</h1>'}));
  await page.locator('#musicListeningTotalsRefresh').click();
  await page.waitForFunction(() => document.getElementById('musicListeningTotals').dataset.reportState === 'error');
  check((await page.locator('#musicListeningTotalsStatus').textContent()).includes('temporarily unavailable'), 'Malformed upstream output gives a useful message without parser internals');
  await page.unroute('**' + totalsRoute);
  await page.locator('#musicListeningTotalsRetry').click(); await ready(page);
  let releaseSlow, slowStarted, slow = true;
  const started = new Promise(resolve => {slowStarted = resolve;});
  const release = new Promise(resolve => {releaseSlow = resolve;});
  await page.route('**' + totalsRoute, async route => {
    if (slow) {slow = false; const response = await route.fetch(); slowStarted(); await release; try {await route.fulfill({response});} catch {} return;}
    return route.continue();
  });
  await page.locator('#musicListeningTotalsRefresh').click(); await started;
  await page.locator('#musicListeningTotalsChannel').selectOption('provider'); await apply(page);
  releaseSlow(); await page.unroute('**' + totalsRoute);
  equal(await metrics(page), ['1','1','20 s'], 'A late response cannot overwrite a new source selection');
  await page.locator('#musicListeningTotalsReset').click(); await ready(page);

  fixture.execute('UPDATE artist_catalog_tracks_v181 SET title=? WHERE id=10', ['<img src=x onerror="window.totalsInjected=1">']);
  await refresh(page);
  check((await page.locator('#musicListeningTotalsTable').textContent()).includes('<img src=x'), 'Song titles are rendered literally');
  equal(await page.locator('#musicListeningTotalsTable img').count(), 0, 'Song titles cannot create image elements');
  equal(await page.evaluate(() => window.totalsInjected), undefined, 'Catalog text cannot execute scripts');
  fixture.execute('UPDATE artist_catalog_tracks_v181 SET title=? WHERE id=10', ['Fixture recording']);
  await refresh(page);

  const desktopLayout = await layout(page);
  check(desktopLayout.documentWidth <= desktopLayout.viewport + 1, 'Desktop lifetime workspace has no horizontal overflow');
  equal(desktopLayout.scrollers, 0, 'Desktop lifetime workflow uses normal document scrolling');
  await page.screenshot({path:path.join(artifacts,'workspace-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  const phoneLayout = await layout(page);
  check(phoneLayout.documentWidth <= phoneLayout.viewport + 1, '390px lifetime workspace has no horizontal overflow');
  equal(phoneLayout.scrollers, 0, '390px lifetime workflow has no nested scrollers');
  for (const id of ['Refresh','Channel','Traffic','Apply','Reset','Next'])
    check(await page.locator('#musicListeningTotals' + id).evaluate(element => element.getBoundingClientRect().height >= 44), 'Phone lifetime control has a 44px target: ' + id);
  await page.locator('#musicListeningTotalsNext').scrollIntoViewIfNeeded();
  check(await page.evaluate(() => scrollY > 0), 'The document scrolls to the lifetime paging controls');
  await page.locator('#musicListeningTotalsChannel').focus(); await page.keyboard.press('Tab');
  equal(await page.evaluate(() => document.activeElement.id), 'musicListeningTotalsTraffic', 'Lifetime filters support keyboard traversal');
  await page.getByRole('button',{name:'Open VP3 navigation',exact:true}).click();
  await page.waitForFunction(() => document.body.classList.contains('chat-nav-open'));
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.body.classList.contains('chat-nav-open'));
  check(await page.getByRole('button',{name:'Open VP3 navigation',exact:true}).isVisible(), 'Shared phone navigation remains usable with lifetime reporting');
  await page.evaluate(() => scrollTo(0,0));
  await page.screenshot({path:path.join(artifacts,'workspace-mobile.png'),fullPage:true});
  await page.locator('#musicPayments').scrollIntoViewIfNeeded();
  check(await page.evaluate(() => scrollY > 0), 'Normal page scrolling reaches preserved payments setup section');

  const csrf = await page.locator('#musicListeningTotals').getAttribute('data-csrf');
  await page.locator('#musicListeningTotals').evaluate(element => {element.dataset.csrf = 'expired';});
  await page.locator('#musicListeningTotalsRefresh').click();
  await page.waitForFunction(() => document.getElementById('musicListeningTotals').dataset.reportState === 'error');
  check((await page.locator('#musicListeningTotalsStatus').textContent()).includes('session or reporting access changed'), 'Expired CSRF requests a fresh authorized page');
  check(!await page.locator('#musicListeningTotalsRetry').isVisible(), 'Expired access does not invite an unchanged retry');
  await page.locator('#musicListeningTotals').evaluate((element, token) => {element.dataset.csrf = token;}, csrf);
  await refresh(page);

  const managerContext = await browser.newContext({extraHTTPHeaders:{'X-User':'12'}});
  const manager = await managerContext.newPage(); watchErrors(manager);
  equal((await manager.goto(fixture.base + '/music-workspace.php?workspace=1')).status(), 200, 'Workspace manager can open lifetime totals');
  await ready(manager);
  equal(await metrics(manager), ['3','3','50 s'], 'Workspace manager sees the same authorized lifetime totals');
  equal((await manager.goto(fixture.base + '/admin/listening.php')).status(), 403, 'Workspace manager does not inherit platform listening authority');
  equal(await manager.locator('#musicListeningTotals').count(), 0, 'Denied admin page does not mount platform reporting controls');
  await manager.goto(fixture.base + '/music-workspace.php?workspace=1'); await ready(manager);
  fixture.execute('DELETE FROM artist_team_members WHERE artist_user_id=11 AND member_user_id=12');
  await manager.locator('#musicListeningTotalsRefresh').click();
  await manager.waitForFunction(() => document.getElementById('musicListeningTotals').dataset.reportState === 'error');
  check(!await manager.locator('#musicListeningTotalsData').isVisible(), 'Revoked workspace membership removes lifetime data on the next read');
  await managerContext.close();
  const producerContext = await browser.newContext({extraHTTPHeaders:{'X-User':'13'}});
  const producer = await producerContext.newPage(); watchErrors(producer);
  await producer.goto(fixture.base + '/music-workspace.php?workspace=1');
  equal(await producer.locator('#musicListeningTotals').count(), 0, 'Producer receives no lifetime reporting controls');
  check(await producer.getByRole('link',{name:'Choose a track',exact:true}).count() === 1, 'Producer keeps its authorized production tools');
  await producerContext.close();
  equal((await page.goto(fixture.base + '/music-workspace.php?workspace=2')).status(), 403, 'Foreign workspace page remains forbidden');

  const adminContext = await browser.newContext({viewport:{width:1440,height:1000},extraHTTPHeaders:{'X-User':'91'}});
  const admin = await adminContext.newPage(); watchErrors(admin);
  const adminRequests = [];
  admin.on('request', request => {if (request.url().endsWith(totalsRoute) && request.method() === 'POST') adminRequests.push(request.postDataJSON());});
  equal((await admin.goto(fixture.base + '/admin/listening.php')).status(), 200, 'Current platform listening viewer opens actual admin report');
  await ready(admin);
  equal(adminRequests.at(-1).scope, 'platform', 'Admin report uses explicit global listening authority');
  equal(adminRequests.at(-1).group, 'workspaces', 'Platform report groups artist workspaces');
  equal(await metrics(admin), ['3','3','50 s'], 'Platform audience headline includes all available native and provider measurements');
  check((await admin.locator('#musicListeningHistoryTitle').textContent()).includes('Native VP3 & legacy history'), 'Original admin report is explicitly labeled as native and legacy');
  check(await admin.getByRole('heading',{name:'Track Performance',exact:true}).count() === 1
    && await admin.getByRole('heading',{name:'Recent Listening Sessions',exact:true}).count() === 1, 'Existing admin performance and session history are preserved');
  check(await admin.locator('.analytics-range').getByRole('link',{name:'All Time',exact:true}).count() === 1, 'Existing native All Time range remains available');
  check(await admin.getByRole('link',{name:'Listening Analytics',exact:true}).count() > 0, 'Admin navigation remains present');
  check(!(await admin.locator('#musicListeningTotals').textContent()).includes('PRIVATE'), 'New platform aggregate contains no listener identity');
  await admin.getByRole('button',{name:'View songs for First Studio',exact:true}).click(); await ready(admin);
  equal(adminRequests.at(-1).scope, 'platform', 'Admin song drilldown preserves platform authority');
  equal(adminRequests.at(-1).workspace_id, 1, 'Admin song drilldown narrows to the chosen workspace');
  equal(adminRequests.at(-1).group, 'tracks', 'Admin chosen workspace displays paged song totals');
  check((await admin.locator('#musicListeningTotalsScopeLabel').textContent()).includes('First Studio'), 'Selected workspace scope remains visible above totals');
  equal(await metrics(admin), ['3','3','50 s'], 'Admin workspace drilldown matches artist totals');
  check(await admin.locator('#musicListeningTotalsNext').isEnabled(), 'Admin song drilldown retains catalog pagination');
  await admin.locator('#musicListeningTotalsBack').click(); await ready(admin);
  equal(adminRequests.at(-1).group, 'workspaces', 'Back restores the platform workspace breakdown');
  check(!('workspace_id' in adminRequests.at(-1)), 'Back removes workspace narrowing');
  await admin.locator('.analytics-range').getByRole('link',{name:'All Time',exact:true}).click(); await ready(admin);
  equal(await metrics(admin), ['3','3','50 s'], 'Native history range changes do not narrow combined platform totals');

  fixture.run(`$pdo=db();$pdo->beginTransaction();
    for($i=0;$i<26;$i++){
      $id=2000+$i;$name='Pagination artist '.str_pad((string)$i,2,'0',STR_PAD_LEFT);
      $pdo->prepare('INSERT INTO users (id,role,is_active,enabled,display_name) VALUES (?,?,?,?,?)')->execute([$id,'customer',1,1,$name]);
      $pdo->prepare('INSERT INTO artist_workspaces_v181 (id,artist_user_id,workspace_name) VALUES (?,?,?)')->execute([$id,$id,$name]);
      $pdo->prepare('INSERT INTO artist_catalog_tracks_v181 (id,workspace_id,source_track_id,title,audio_path,visibility,is_published) VALUES (?,?,NULL,?,?,?,1)')->execute([$id,$id,'Unplayed catalog song','','public']);
      $q=$pdo->prepare('SELECT * FROM artist_catalog_tracks_v181 WHERE id=?');$q->execute([$id]);music_song_metadata_v1_import_legacy($pdo,$q->fetch());
    }$pdo->commit();`);
  await refresh(admin);
  equal(await admin.locator('#musicListeningTotalsTable tbody tr').count(), 25, 'Large platform workspace list is paged');
  check(await admin.locator('#musicListeningTotalsNext').isEnabled(), 'Additional artist workspaces remain discoverable');
  await admin.locator('#musicListeningTotalsNext').click(); await ready(admin);
  equal(adminRequests.at(-1).offset, 25, 'Artist workspace pagination sends its offset');
  equal(await metrics(admin), ['3','3','50 s'], 'Platform totals remain independent of the workspace page');
  await admin.locator('#musicListeningTotalsPrevious').click(); await ready(admin);
  fixture.execute('UPDATE artist_workspaces_v181 SET workspace_name=? WHERE id=1', ['<svg onload="window.workspaceTotalsInjected=1">']);
  await refresh(admin);
  check((await admin.locator('#musicListeningTotalsTable').textContent()).includes('<svg onload='), 'Artist names are rendered as literal text');
  equal(await admin.locator('#musicListeningTotalsTable svg').count(), 0, 'Artist names cannot create SVG elements');
  equal(await admin.evaluate(() => window.workspaceTotalsInjected), undefined, 'Artist workspace labels cannot execute scripts');
  fixture.execute('UPDATE artist_workspaces_v181 SET workspace_name=? WHERE id=1', ['First Studio']);
  await refresh(admin);
  const adminDesktopLayout = await layout(admin);
  check(adminDesktopLayout.documentWidth <= adminDesktopLayout.viewport + 1, 'Desktop admin lifetime report has no document overflow');
  equal(adminDesktopLayout.scrollers, 0, 'Desktop admin lifetime panel has no nested workflow scrollers');
  await admin.screenshot({path:path.join(artifacts,'platform-desktop.png'),fullPage:true});
  await admin.setViewportSize({width:390,height:844});
  const adminPhoneLayout = await layout(admin);
  check(adminPhoneLayout.documentWidth <= adminPhoneLayout.viewport + 1, '390px admin report has no document overflow');
  equal(adminPhoneLayout.scrollers, 0, '390px admin lifetime panel has no nested workflow scrollers');
  check(await admin.locator('#musicListeningTotalsChannel').evaluate(element => element.getBoundingClientRect().height >= 44), 'Admin mobile source selector retains a 44px target');
  await admin.locator('#musicListeningTotalsNext').scrollIntoViewIfNeeded();
  check(await admin.evaluate(() => scrollY > 0), 'Admin document scroll reaches lifetime pagination');
  await admin.evaluate(() => scrollTo(0,0));
  await admin.screenshot({path:path.join(artifacts,'platform-mobile.png'),fullPage:true});

  await page.goto(fixture.base + '/music-workspace.php?workspace=1'); await ready(page);
  fixture.execute('ALTER TABLE music_provider_sessions_v1 RENAME TO music_provider_sessions_unavailable');
  await refresh(page);
  equal(await metrics(page), ['2','2','30 s'], 'Available native lifetime evidence remains visible when provider measurement is missing');
  check((await page.locator('#musicListeningTotalsStatus').textContent()).includes('Partial total'), 'A missing measurement source is explicitly marked as partial');
  await page.locator('#musicListeningTotalsChannel').selectOption('provider');
  await page.locator('#musicListeningTotalsApply').click();
  await page.waitForFunction(() => document.getElementById('musicListeningTotals').dataset.reportState === 'error');
  check(!await page.locator('#musicListeningTotalsData').isVisible(), 'Explicit unavailable source hides the previous report');
  check(!await page.locator('#musicListeningTotalsMetrics').isVisible(), 'Unavailable provider measurement never presents stale or zero listen counts');
  check(!await page.locator('#musicListeningTotalsTable').isVisible(), 'Explicit unavailable source hides per-song measurements');
  check(await page.locator('#musicListeningTotalsStatus').isVisible()
    && (await page.locator('#musicListeningTotalsStatus').textContent()).includes('temporarily unavailable'), 'Explicit unavailable source displays safe unavailability guidance');
  check(await page.locator('#musicListeningTotalsRetry').isVisible(), 'Explicit unavailable source offers a fresh-report retry');
  fixture.execute('ALTER TABLE music_provider_sessions_unavailable RENAME TO music_provider_sessions_v1');
  await page.locator('#musicListeningTotalsReset').click(); await ready(page);
  fixture.execute("UPDATE music_listening_sessions_v1 SET listen_class='owner_or_manager_preview' WHERE listen_class='audience'");
  fixture.execute("UPDATE music_provider_sessions_v1 SET listen_class='staging_test' WHERE listen_class='public_anonymous_unclassified'");
  await refresh(page);
  equal(await metrics(page), ['0','0','0 s'], 'Measured empty history is displayed as a true zero');
  check((await page.locator('#musicListeningTotalsStatus').textContent()).includes('No matching measured listening'), 'Empty measured history has an explicit explanation');
  equal(await page.locator('#musicListeningTotalsTable tbody tr').count(), 25, 'Zero-listen catalog songs remain available in the paged table');
  equal(errors, [], 'Lifetime workspace and platform surfaces have no unexpected browser errors');
  fs.writeFileSync(path.join(artifacts,'evidence.json'), JSON.stringify({passed:true,browser:browserName,checks,
    desktop:desktopLayout,phone:phoneLayout,admin_desktop:adminDesktopLayout,admin_phone:adminPhoneLayout,evidence,unexpected_errors:errors,
    scope:'Actual Music Workspace/admin listening PHP, shared totals JS/CSS, lifetime HTTP service and SQLite. Explicit test authentication and unrelated shared chrome adapters. No live-host or physical-device certification.'},null,2) + '\n');
  await adminContext.close(); await context.close();
  console.log(`MUSIC_LISTENING_TOTALS_BROWSER_V1=PASS ${checks} ${browserName} actual PHP, lifetime/date independence, source/traffic, song/artist pagination, stale recovery, safe text, role gates, desktop/390px and normal scrolling`);
} finally {await browser?.close(); fixture.close();}
