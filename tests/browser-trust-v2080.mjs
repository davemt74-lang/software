import fs from 'node:fs';

const read=path=>fs.readFileSync(path,'utf8');
const must=(value,message)=>{if(!value)throw new Error(message);};
const mustNot=(value,message)=>{if(value)throw new Error(message);};

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.html');
const panelJs=read('browser-companion/sidepanel.js');
const service=read('includes/browser-trust-v2080.php');
const api=read('api/browser-trust-v2080.php');
const sourceService=read('includes/browser-source-feed-v2050.php');
const liveService=read('includes/live-rooms-v2070.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const sourcePage=read('source.php');
const claimsPage=read('claims.php');
const claimPage=read('claim.php');
const moderationPage=read('moderation.php');
const prefsPage=read('notification-settings.php');
const annotationPage=read('annotation.php');

must(manifest.manifest_version===3,'Phase 9 must remain Manifest V3');
must(manifest.version==='20.80.0','Phase 9 Browser Companion version must be v20.80.0');
must(background.includes("const VP3_EXTENSION_VERSION = '20.80.0'"),'Phase 9 runtime version missing');

for(const table of ['browser_source_change_events_v2080','browser_notification_preferences_v2080','browser_notifications_v2080','browser_claims_v2080','browser_claim_events_v2080','browser_moderation_reports_v2080','browser_moderation_actions_v2080']){
  must(service.includes(table),'Phase 9 schema missing '+table);
}
must(service.includes("require_once __DIR__.'/live-rooms-v2070.php'"),'Phase 9 must layer on Phase 8');
must(service.includes("version_basis='page_text_sha256'"),'source change intelligence must reuse canonical source versions');
must(service.includes('vp3_browser_trust_source_access_v2080'),'source observation/history must have an authorization gate');
must(service.includes('Follow or annotate this source before observing changes.'),'arbitrary authenticated clients must not inject change events into unrelated sources');
must(service.includes('vp3_browser_trust_compare_change_v2080'),'source change version comparison service missing');
must(service.includes('vp3_browser_trust_version_annotations_v2080'),'version comparison must show only authorized annotations pinned to each version');
must(service.includes('UNIQUE KEY uq_browser_change_transition'),'source change events must dedupe identical transitions');
must(service.includes('UNIQUE KEY uq_browser_notification_event'),'Phase 9 notifications must be deduplicated');
must(service.includes('dismissed_at DATETIME NULL'),'Phase 9 notification dismissal state missing');
must(service.includes('vp3_browser_trust_sync_canonical_read_v2080'),'Chrome read/dismiss state must sync to canonical VP3 notifications');
must(service.includes("browser_source_follows_v2050 WHERE source_id=?"),'source change notification fanout must target source followers');
must(service.includes("create_notification($userId,'browser_'.$type"),'Phase 9 alerts must bridge into canonical VP3 notifications');
must(service.includes("'claim-created:'"),'new visible claims must notify authorized source followers');
mustNot(service.includes('page_text_excerpt'),'Phase 9 must not persist potentially personalized page text');
mustNot(service.includes('LONGTEXT NOT NULL')&&service.includes('browser_source_change_events_v2080'),'source change ledger should store fingerprints/metadata, not copied page bodies');

must(service.includes("['private','team','public']"),'claim visibility boundary missing');
must(service.includes('vp3_human_team_authorized_v370'),'Team claim visibility must revalidate Team membership');
must(service.includes('source_version_id BIGINT UNSIGNED NOT NULL'),'claims must pin an immutable source version');
must(service.includes("['open','under_review','resolved','disputed','withdrawn']"),'claim lifecycle states missing');
must(service.includes("Only a moderator can change this claim status."),'claim status changes must fail closed');
must(service.includes("user_has_role('admin',$user)"),'moderator authority must be server-side Admin role');
must(service.includes('vp3_browser_trust_validate_report_target_v2080'),'moderation reports must validate target visibility');
must(service.includes('vp3_browser_source_share_authorized_v2050'),'annotation reports must preserve annotation authorization');
must(service.includes('vp3_live_room_require_v2070'),'Live report validation must preserve room authorization');
must(service.includes('vp3_browser_trust_claim_access_v2080'),'claim reports must preserve claim visibility');
mustNot(service.includes('UPDATE browser_shares_v2010 SET deleted_at'),'basic moderation must not destructively hide annotations');
mustNot(service.includes('UPDATE live_room_messages_v2070 SET deleted_at'),'basic moderation must not destructively hide Live messages');

for(const action of ['observe_source','notifications','source_compare','source_history','claims_for_source','claim_create','claim_status','report_create','preference','notification_read','notification_dismiss']){
  must(api.includes(action),'Phase 9 API missing '+action);
}
must(api.includes('vp3_extension_session_authenticate_v2001'),'Phase 9 extension API must use canonical bearer auth');
mustNot(api.includes('ensure_schema_v2080('),'public Phase 9 API must never execute schema DDL');
must(api.includes("vp3_browser_trust_api_cap_v2080($auth,'team.share.create')"),'claim writes must require share capability');

must(sourceService.includes('vp3_browser_trust_notify_comment_v2080'),'annotation comments must feed unified notifications');
must(sourceService.includes('vp3_browser_trust_notify_comment_mentions_v2080'),'annotation @mentions must feed unified notifications');
must(sourceService.includes('vp3_browser_trust_notify_follow_v2080'),'new follows must feed unified notifications');
must(liveService.includes('vp3_browser_trust_notify_live_v2080'),'Live activity must feed unified notifications');
must(liveService.includes('vp3_browser_trust_notify_live_mentions_v2080'),'Live @mentions must feed unified notifications');
must(service.includes("$pdo,$uid,'mentions'"),'mentions must use their own notification preference');
must(service.includes('vp3_browser_source_share_authorized_v2050($pdo,$share,$uid)'),'annotation mentions must not expand content visibility');
must(service.includes('vp3_live_room_access_v2070($pdo,$room,$uid,false)'),'Live mentions must not expand room visibility');

for(const id of ['alertsTab','alertsView','sourceChangeBadge','sourceHistoryList','sourceClaimsList','notificationsList','fileClaimBtn','claimDialog','claimStatement','claimRationale','claimVisibility','claimSubmitBtn']){
  must(panel.includes('id="'+id+'"'),'Browser Companion Phase 9 UI missing '+id);
}
must(background.includes("'/api/browser-trust-v2080.php'"),'Browser Companion Phase 9 transport missing');
must(background.includes("case 'trust_observe'"),'source observation transport missing');
must(background.includes("case 'trust_notifications'"),'notification transport missing');
must(background.includes("case 'trust_claims'"),'claims transport missing');
must(background.includes("case 'trust_action'"),'Phase 9 action transport missing');
must(panelJs.includes("setView('alerts')"),'Alerts tab navigation missing');
must(panelJs.includes("notification_dismiss"),'sidebar notification dismiss action missing');
must(panelJs.includes("trustAction('claim_create'"),'sidebar claim filing missing');
must(panelJs.includes("target_type:'annotation'"),'sidebar annotation reporting missing');
must(panelJs.includes("target_type:'comment'"),'sidebar comment reporting missing');
must(panelJs.includes("target_type:'live_message'"),'sidebar Live reporting missing');
must(panelJs.includes("target_type:'claim'"),'sidebar claim reporting missing');
must(panelJs.includes("msg('trust_observe'"),'sidebar source observation missing');

must(sourcePage.includes('vp3_browser_trust_source_history_v2080'),'Source page must surface version history');
must(sourcePage.includes('Compare versions'),'Source page must link to version comparison');
must(sourcePage.includes('vp3_browser_trust_claims_for_source_v2080'),'Source page must surface claims');
must(sourcePage.includes('File a claim'),'Source page claim entry point missing');
must(claimsPage.includes('vp3_browser_trust_claim_create_v2080'),'web claim creation must use canonical Phase 9 service');
must(claimsPage.includes('browser_share_id'),'web claim creation must preserve optional annotation evidence');
must(annotationPage.includes("'&annotation='"),'annotation pages must offer evidence-linked claim filing');
must(panelJs.includes("browser_share_id:claimShareId"),'sidebar claims must pin selected annotation evidence when filed from a feed card');
must(claimPage.includes('vp3_browser_trust_claim_status_v2080'),'claim lifecycle page missing');
must(moderationPage.includes("require_permission('users.manage')"),'moderation queue must require server-side admin permission');
must(moderationPage.includes('vp3_browser_trust_report_action_v2080'),'moderation action ledger missing from web surface');
must(prefsPage.includes('vp3_browser_trust_set_preference_v2080'),'notification preferences page missing');

must(bootstrap.includes("browser-trust-v2080.php"),'canonical bootstrap must load Phase 9');
must(upgrade.includes('vp3_browser_trust_schema_ready_v2080()'),'upgrade readiness must include Phase 9');
must(upgrade.includes('vp3_browser_trust_ensure_schema_v2080();'),'upgrade must install Phase 9');

console.log('VP3 Phase 9 Source Change Intelligence + Claims + Moderation v20.80 contract passed.');
