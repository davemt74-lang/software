import fs from 'node:fs';

function read(path){return fs.readFileSync(path,'utf8');}
function must(value,message){if(!value)throw new Error(message);}
function mustNot(value,message){if(value)throw new Error(message);}

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.html');
const panelJs=read('browser-companion/sidepanel.js');
const service=read('includes/browser-source-feed-v2050.php');
const api=read('api/browser-source-feed-v2050.php');
const media=read('includes/browser-share-media-v2040.php');
const mediaApi=read('api/browser-share-media-v2040.php');
const chatFeed=read('includes/browser-share-chat-feed-v2020.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const sourcePage=read('source.php');
const annotationPage=read('annotation.php');

must(manifest.manifest_version===3,'Phase 6 must remain Manifest V3');
must(manifest.version==='20.50.0','Phase 6 extension version must be v20.50.0');
must(background.includes("case 'this_page'"),'This Page transport missing');
must(background.includes("case 'following'"),'Following transport missing');
must(background.includes("case 'source_action'"),'source interaction transport missing');
must(background.includes("case 'media_data'"),'authorized feed media proxy missing');
must(background.includes("page_text_sha256"),'source-version page fingerprint missing');
must(background.includes("crypto.subtle.digest('SHA-256'"),'page version fingerprint must use SHA-256');
mustNot(background.includes('page_text:'),'raw page text must not be sent to VP3');

for(const id of ['thisPageTab','followingTab','visibilitySelect','visibilityTeamSelect','thisPageFeed','followingFeed','followCurrentSourceBtn']){
  must(panel.includes('id="'+id+'"'),'sidebar control missing: '+id);
}
for(const action of ['comment','reply','save','research','follow_source','follow_user','read','share_team','knowledge','ask','context']){
  must(panelJs.includes("'"+action+"'")||panelJs.includes('"'+action+'"'),'sidebar action missing: '+action);
}
must(panelJs.includes('IntersectionObserver'),'Following/This Page must support progressive infinite loading');
must(panelJs.includes("visibility_team_id"),'Team visibility must be distinct from delivery destination');

for(const table of [
  'browser_sources_v2050','browser_source_versions_v2050','browser_share_sources_v2050','browser_share_publications_v2050',
  'browser_source_follows_v2050','browser_user_follows_v2050','browser_share_comments_v2050','browser_share_saves_v2050',
  'browser_research_queue_v2050','browser_share_reads_v2050'
]) must(service.includes(table),'Phase 6 schema missing '+table);

must(service.includes("str_starts_with($key,'utm_')"),'URL normalization must remove UTM tracking parameters');
must(service.includes("'fbclid'=>true"),'URL normalization must remove common click trackers');
must(service.includes('if(hash_equals($pageComparable,$canonicalComparable))$preferred=$canonical;'),'page-controlled canonicals must not alias unrelated origins');
must(service.includes("['private','team','public']"),'Private/Team/Public publication policy missing');
must(service.includes("vp3_human_team_authorized_v370"),'Team publication must use live server-side team authorization');
must(service.includes("Legacy Phase 4/5 Browser Shares"),'legacy Browser Share authorization/backfill boundary missing');
must(service.includes("page_text_sha256"),'source version basis missing');
must(service.includes("vp3_browser_source_backfill_v2050"),'Phase 4/5 canonical Source backfill missing');
must(service.includes("$scannedCount<count($ids)||count($ids)===$scan"),'feed cursors must preserve additional authorized items beyond the first page');

must(api.includes("action==='this_page'"),'This Page API missing');
must(api.includes("action==='following'"),'Following API missing');
for(const action of ['publish','follow_source','follow_user','comment','save','research','read','share_team']){
  must(api.includes("action==='"+action+"'"),'Phase 6 API action missing: '+action);
}
mustNot(api.includes('ensure_schema_v2050('),'public Source Feed API must never execute DDL');

must(media.includes("vp3_browser_source_share_authorized_v2050"),'rich media must honor Phase 6 publication visibility');
must(mediaApi.includes('Anonymous GET is allowed'),'public annotation media must reach object-level authorization');
must(chatFeed.includes("vp3_browser_source_share_authorized_v2050"),'Ask VP3/Knowledge/Task must honor Phase 6 publication visibility');

must(bootstrap.includes("browser-source-feed-v2050.php"),'Phase 6 service must load from canonical bootstrap');
must(upgrade.includes("vp3_browser_source_feed_schema_ready_v2050()"),'upgrade readiness gate missing Phase 6');
must(upgrade.includes("vp3_browser_source_feed_ensure_schema_v2050();"),'upgrade installer missing Phase 6');

must(sourcePage.includes('vp3_browser_source_this_page_v2050'),'website Source page must use canonical This Page service');
must(annotationPage.includes('vp3_browser_source_item_v2050'),'annotation detail must use canonical source item');
must(annotationPage.includes('vp3_browser_source_comment_v2050'),'website annotation comments must use canonical comment service');

console.log('VP3 Browser Companion Phase 6 This Page + Following v20.50 contract passed.');
