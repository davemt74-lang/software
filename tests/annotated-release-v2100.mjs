import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>{if(!v)throw new Error(m);};
const mustNot=(v,m)=>{if(v)throw new Error(m);};

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const service=read('includes/annotated-release-v2100.php');
const api=read('api/annotated-release-v2100.php');
const health=read('api/annotated-health-v2100.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const signup=read('signup.php');
const onboarding=read('includes/chat-onboarding-v241.php');
const device2000=read('includes/extension-device-auth-v2000.php');
const device2001=read('includes/extension-device-auth-v2001.php');
const connectRequest=read('api/extension-connect-request.php');
const connectStatus=read('api/extension-connect-status.php');
const extensionSession=read('api/extension-session.php');
const sourceService=read('includes/browser-source-feed-v2050.php');
const researchService=read('includes/research-projects-v2060.php');
const liveService=read('includes/live-rooms-v2070.php');
const searchService=read('includes/search-discovery-v2090.php');
const sourceApi=read('api/browser-source-feed-v2050.php');
const researchApi=read('api/research-projects-v2060.php');
const liveApi=read('api/live-rooms-v2070.php');
const trustApi=read('api/browser-trust-v2080.php');
const searchApi=read('api/search-v2090.php');
const shareApi=read('api/browser-share.php');
const mediaApi=read('api/browser-share-media-v2040.php');

must(manifest.manifest_version===3,'Phase 11A must remain Manifest V3');
must(manifest.version==='21.00.0','Phase 11A Browser Companion must be v21.00.0');
must(background.includes("const VP3_EXTENSION_VERSION = '21.00.0'"),'v21 runtime version missing');
must(JSON.stringify(manifest.permissions)===JSON.stringify(['activeTab','contextMenus','scripting','sidePanel','storage']),'Phase 11A must not expand Chrome permissions');
must(manifest.content_security_policy?.extension_pages==="script-src 'self'; object-src 'self'",'MV3 extension CSP must remain restrictive');

for(const table of ['annotated_onboarding_v2100','annotated_product_events_v2100','annotated_rate_buckets_v2100'])must(service.includes(table),'Phase 11A schema missing '+table);
for(const milestone of ['account_created','welcome_seen','annotated_introduced','extension_offer_seen','extension_offer_dismissed','extension_connected','first_annotation_created','source_followed','research_used','search_used','live_used'])must(service.includes("'"+milestone+"'"),'missing durable milestone '+milestone);
must(service.includes("return ['welcome_seen','annotated_introduced','extension_offer_seen','extension_offer_dismissed']"),'only presentation milestones may be manually asserted');
mustNot(api.includes("first_annotation_created','source_followed"),'release API must not let clients forge derived product milestones');

must(device2000.includes('user_id INT UNSIGNED NOT NULL'),'Browser Companion devices must remain attached to the canonical VP3 user');
must(device2000.includes('CONSTRAINT fk_extension_device_user FOREIGN KEY (user_id) REFERENCES users(id)'),'device identity must resolve to canonical VP3 users');
mustNot(service.includes('CREATE TABLE IF NOT EXISTS annotated_users'),'Annotated must not create a separate account identity');
mustNot(service.includes('CREATE TABLE IF NOT EXISTS extension_users'),'Browser Companion must not create a separate account identity');
must(device2001.includes("INNER JOIN users u ON u.id=d.user_id"),'extension sessions must live-revalidate canonical VP3 user identity');

must(service.includes("VP3_ANNOTATED_EXTENSION_CURRENT_V2100='21.00.0'"),'current extension compatibility version missing');
must(service.includes("VP3_ANNOTATED_EXTENSION_MIN_V2100='20.90.0'"),'minimum supported extension version missing');
must(service.includes('function vp3_annotated_extension_compatibility_v2100'),'extension compatibility policy missing');
must(connectRequest.includes("extension_update_required"),'connect flow must reject unsupported extensions');
must(extensionSession.includes("extension_update_required"),'session issuance must reject unsupported extensions');
must(connectStatus.includes("'annotated'=>")||connectStatus.includes("$payload['annotated']"),'connect completion must return canonical Annotated state');
must(extensionSession.includes("$session['user']['id']"),'session sync must resolve the VP3 identity from the canonical session user payload');
must(extensionSession.includes("'annotated'=>$annotated"),'session issue must return canonical Annotated state');
must(background.includes("release_state: payload.annotated || null"),'Browser Companion must cache same-account Annotated state');
must(background.includes("'/api/annotated-release-v2100.php?action=state'"),'Browser Companion must be able to refresh canonical release state');

must(signup.includes("vp3_annotated_mark_milestone_safe_v2100($pdo,$userId,'account_created'"),'new VP3 signup must seed Annotated account state');
must(onboarding.includes("'annotated'=>$annotated"),'existing onboarding playbook must expose Annotated state without a UI rewrite');
must(sourceService.includes("'first_annotation_created'"),'annotation publication must drive onboarding state');
must(sourceService.includes("'source_followed'"),'Source follow must drive onboarding state');
must(sourceService.includes("'research_used'"),'Add to Research must drive onboarding state');
must(researchService.includes("'research_used'"),'Research project use must drive onboarding state');
must(searchService.includes("'search_used'"),'Search use must drive onboarding state');
must(liveService.includes("'live_used'"),'Live use must drive onboarding state');
must(service.includes('vp3_annotated_sync_derived_milestones_v2100'),'state reads must reconcile milestone history from canonical data');

for(const scope of ['browser_share_create','media_upload','annotation_publish','annotation_comment','source_follow','research_write','live_create','live_send','source_observe','claim_create','report_create','search_read','search_write'])must(service.includes("'"+scope+"'"),'rate policy missing '+scope);
for(const source of [sourceApi,researchApi,liveApi,trustApi,searchApi,shareApi,mediaApi])must(source.includes('vp3_annotated_rate_limit_v2100'), 'an Annotated API write/read surface is missing shared rate limiting');
for(const source of [sourceApi,researchApi,liveApi,trustApi,searchApi,shareApi,mediaApi])must(source.includes('VP3AnnotatedRateLimitExceptionV2100'), 'rate-limited APIs must produce explicit 429 handling');
must(service.includes('subject_hash CHAR(64)'), 'rate buckets must store only a hashed subject');
mustNot(service.includes('request_ip VARCHAR'), 'Phase 11A rate ledger must not persist raw IP addresses');

must(service.includes('VP3_ANNOTATED_EPHEMERAL_RETENTION_DAYS_V2100=7'),'ephemeral retention policy missing');
must(service.includes('VP3_ANNOTATED_RECENT_SEARCH_RETENTION_DAYS_V2100=180'),'recent Search retention policy missing');
must(service.includes('VP3_ANNOTATED_EVENT_RETENTION_DAYS_V2100=365'),'onboarding event retention policy missing');
must(service.includes("'content_auto_delete'=>false"),'user-authored Annotated content must not be auto-deleted by housekeeping');
must(service.includes('DELETE FROM extension_sessions_v2000'),'expired session cleanup missing');
must(service.includes('DELETE FROM extension_connection_requests_v2000'),'expired connection cleanup missing');
must(service.includes('DELETE FROM search_recent_queries_v2090'),'recent Search cleanup missing');
mustNot(service.includes('DELETE FROM browser_shares_v2010'),'housekeeping must not delete annotations');
mustNot(service.includes('DELETE FROM browser_claims_v2080'),'housekeeping must not delete claims');
must(service.includes('function vp3_annotated_privacy_inventory_v2100'),'privacy inventory foundation missing');

must(service.includes('function vp3_annotated_request_id_v2100'),'request correlation IDs missing');
must(service.includes("header('X-VP3-Request-ID: '"),'request ID response header missing');
must(bootstrap.includes('vp3_annotated_request_boot_v2100();'),'request ID boot missing');
must(health.includes("has_permission('users.manage'"),'health telemetry must be admin-only');
must(health.includes('vp3_annotated_health_v2100'),'Annotated health telemetry missing');
must(service.includes('extension_versions'),'health state must expose extension version compatibility telemetry');

must(api.includes('vp3_extension_session_authenticate_v2001'),'extension release state must use canonical bearer auth');
must(api.includes('hash_equals(csrf_token()'),'web release writes must require CSRF');
must(api.includes("'privacy'"),'release API must expose privacy inventory to the signed-in user');
mustNot(api.includes('ensure_schema_v2100('),'public release API must not execute schema DDL');

must(bootstrap.includes("annotated-release-v2100.php"),'canonical bootstrap must load Phase 11A');
must(upgrade.includes('vp3_annotated_schema_ready_v2100()'),'upgrade readiness must include Phase 11A');
must(upgrade.includes('vp3_annotated_ensure_schema_v2100();'),'upgrade must install/backfill Phase 11A');
must(bootstrap.includes('vp3_annotated_housekeeping_maybe_v2100();'),'conservative housekeeping boot missing');

console.log('VP3 Phase 11A Annotated V1 Release Foundation v21.00 contract passed.');
