import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root=process.cwd();
const workflowDir=path.join(root,'.github','workflows');
const archiveDir=path.join(root,'.github','workflow-archive');

const activeExpected=[
  "agent-event-infrastructure-v1920.yml",
  "authority-section14.yml",
  "browser-capture-routing-r4.yml",
  "browser-companion-transaction-control-v2280.yml",
  "browser-local-screenshots.yml",
  "client-release-intelligence-v100.yml",
  "cloud-hosting-v100.yml",
  "cognitive-loop-release-v2360.yml",
  "gmail-composer-v1.yml",
  "homeserver-runtime-journey.yml",
  "music-analytics-v1.yml",
  "music-chat-v1.yml",
  "music-song-metadata-v1.yml",
  "package-entitlements-v340.yml",
  "profile-commerce-secure-file-v1130.yml",
  "public-funnel-onboarding-continuity.yml",
  "recovery-baseline.yml",
  "section15-hardware-automation.yml",
  "section16-app-control.yml",
  "stem-editor-recovery.yml",
  "team-workspaces-v350.yml",
  "video-meetings-v18230.yml",
  "production-deploy-package.yml",
  "workspace-sync-v1.yml"
];

const yamlFiles=dir=>fs.readdirSync(dir).filter(name=>/\.ya?ml$/i.test(name)).sort();
const active=yamlFiles(workflowDir);
const archived=yamlFiles(archiveDir);

assert.deepEqual(active,[...activeExpected].sort(),'active GitHub workflow set drifted from the consolidated CI policy');
assert.equal(active.length,activeExpected.length,'normal CI should expose the reviewed active workflow definitions');
assert.ok(archived.length>=138,'historical workflow archive unexpectedly lost phase definitions');

const deploy=fs.readFileSync(path.join(workflowDir,'production-deploy-package.yml'),'utf8');
assert.doesNotMatch(deploy,/\npull_request:/,'Production Deploy must not build artifacts for pull requests');
assert.match(deploy,/push:\s*\n\s*branches:\s*\[main\]/,'Production Deploy must run on merged main');

const browser=fs.readFileSync(path.join(workflowDir,'browser-companion-transaction-control-v2280.yml'),'utf8');
assert.doesNotMatch(browser,/\n\s{2}package:\s*\n/,'historical v22.80 workflow must not remain an artifact authority');
assert.match(browser,/browser-companion-transaction-release-gate-v2280\.mjs/,'Browser cumulative regression release gate must remain');

const music=fs.readFileSync(path.join(workflowDir,'music-song-metadata-v1.yml'),'utf8');
assert.match(music,/pull_request:\s*\n\s+paths:/,'Music integration gate must remain path-filtered');
assert.match(music,/\n\s+workflow_dispatch:/,'Music integration gate must remain manually runnable');
assert.match(music,/image:\s*mysql:8\.0/,'Music integration gate must retain real MySQL acceptance');
assert.match(music,/MUSIC_TEST_MYSQL_DSN:\s*['"]?mysql:/,'Music MySQL acceptance must receive a configured DSN instead of silently skipping');
assert.match(music,/run:\s*php tests\/music-song-metadata-mysql-v1\.php/,'Music native MySQL migration and transaction checks must remain');
assert.match(music,/run:\s*node tests\/music-song-metadata-browser-v1\.mjs/,'Music desktop and phone editor acceptance must remain');
assert.match(music,/push:\s*\n\s+branches:\s*\[main\]/,'Music acceptance must verify relevant merged main changes');
assert.match(music,/node tests\/music-listening-api-v1\.mjs/,'Native listening must exercise the real HTTP boundary');
assert.match(music,/run:\s*php tests\/music-listening-mysql-v1\.php/,'Native listening must retain MySQL receipt and transaction acceptance');
assert.match(music,/run:\s*node tests\/music-listening-browser-v1\.mjs/,'Native playback recovery must run in a browser');
assert.doesNotMatch(music,/continue-on-error:\s*true/,'Music integration failures must not be downgraded to advisory checks');

const analytics=fs.readFileSync(path.join(workflowDir,'music-analytics-v1.yml'),'utf8');
assert.match(analytics,/pull_request:\s*\n\s+paths:/,'Artist analytics acceptance must remain path-filtered');
assert.match(analytics,/push:\s*\n\s+branches:\s*\[main\]/,'Artist analytics must verify relevant merged main changes');
assert.match(analytics,/\n\s+workflow_dispatch:/,'Artist analytics must remain manually runnable');
assert.match(analytics,/image:\s*mysql:8\.0/,'Artist analytics must retain real MySQL acceptance');
assert.match(analytics,/MUSIC_TEST_MYSQL_DSN:\s*['"]?mysql:/,'Analytics MySQL checks require a configured DSN instead of silently skipping');
assert.match(analytics,/run:\s*php tests\/music-analytics-v1\.php/,'Artist analytics must retain service and snapshot acceptance');
assert.match(analytics,/run:\s*node tests\/music-analytics-api-v1\.mjs/,'Artist analytics must exercise actual HTTP authority');
assert.match(analytics,/run:\s*php tests\/music-analytics-mysql-v1\.php/,'Artist analytics must retain native MySQL reporting acceptance');
assert.match(analytics,/browser:\s*\[chromium,\s*webkit\]/,'Artist analytics must retain Chromium and WebKit acceptance');
assert.match(analytics,/run:\s*node tests\/music-analytics-browser-v1\.mjs/,'Artist analytics must exercise the real workspace browser flow');
assert.doesNotMatch(analytics,/continue-on-error:\s*true/,'Artist analytics failures must not be downgraded to advisory checks');

const musicChat=fs.readFileSync(path.join(workflowDir,'music-chat-v1.yml'),'utf8');
assert.match(musicChat,/pull_request:\s*\n\s+paths:/,'Music Chat acceptance must remain path-filtered');
assert.match(musicChat,/push:\s*\n\s+branches:\s*\[main\]/,'Music Chat acceptance must verify relevant merged main changes');
assert.match(musicChat,/\n\s+workflow_dispatch:/,'Music Chat acceptance must remain manually runnable');
assert.match(musicChat,/image:\s*mysql:8\.0/,'Music Chat must retain real MySQL acceptance');
assert.match(musicChat,/MUSIC_TEST_MYSQL_DSN:\s*['"]?mysql:/,'Music Chat MySQL checks require a configured DSN instead of silently skipping');
assert.match(musicChat,/run:\s*php tests\/music-chat-library-mysql-v1\.php/,'Music Chat must retain native MySQL transactions and independent request races');
assert.match(musicChat,/php tests\/music-chat-intent-v1\.php/,'Music Chat must exercise explicit intent, exact counts and source selection');
assert.match(musicChat,/php tests\/music-chat-library-v1\.php/,'Music Chat must retain private SQLite storage and current authority acceptance');
assert.match(musicChat,/node tests\/music-chat-api-v1\.mjs/,'Music Chat must exercise the actual HTTP access and mutation boundary');
assert.match(musicChat,/node tests\/music-chat-runtime-v1\.mjs/,'Music Chat must exercise shared response assembly and restoration');
assert.match(musicChat,/php-version:\s*['"]8\.1['"]/,'Music Chat must retain its supported PHP compatibility gate');
assert.match(musicChat,/php tests\/agent-connection-youtube-chat-v1\.php/,'Music Chat must preserve existing YouTube request and history regressions');
assert.match(musicChat,/php tests\/agent-connection-youtube-playlists-v1\.php/,'Music Chat must preserve existing YouTube playlist regressions');
assert.match(musicChat,/browser:\s*\[chromium,\s*webkit\]/,'Music Chat must retain Chromium and WebKit acceptance');
assert.match(musicChat,/run:\s*node tests\/music-chat-browser-v1\.mjs/,'Music Chat must exercise real browser requests and source switching');
assert.match(musicChat,/actions\/upload-artifact@v4/,'Music Chat browser evidence must remain available for release review');
assert.match(musicChat,/includes\/music-history-\*\.php/,'Shared Music Chat gate must include history source changes');
assert.match(musicChat,/php tests\/music-history-intent-v1\.php/,'History requests must retain exact source/date/time intent checks');
assert.match(musicChat,/php tests\/music-history-v1\.php/,'History must retain real SQLite filters, repeated receipts and pagination');
assert.match(musicChat,/run:\s*php tests\/music-history-mysql-v1\.php/,'History must retain native prepared MySQL acceptance');
assert.match(musicChat,/node tests\/music-history-api-v1\.mjs/,'History must retain actual HTTP authority and exact selected actions');
assert.match(musicChat,/node tests\/music-history-runtime-v1\.mjs/,'History must retain typed and voice restoration checks');
assert.match(musicChat,/run:\s*node tests\/music-history-browser-v1\.mjs/,'History must retain real browser queries and selected playback');
assert.doesNotMatch(musicChat,/continue-on-error:\s*true/,'Music Chat failures must not be downgraded to advisory checks');

for(const name of archived){
  assert.ok(!active.includes(name),`historical workflow ${name} must not be both active and archived`);
}

console.log(`CI_WORKFLOW_CONSOLIDATION=PASS active=${active.length} archived=${archived.length}`);
