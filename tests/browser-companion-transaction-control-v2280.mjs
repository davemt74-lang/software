import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const control=read('includes/browser-transaction-control-v2280.php');
const api=read('api/extension-transaction-control-v2280.php');
const intelligence=read('includes/browser-transaction-intelligence-v2270.php');
const continuity=read('includes/browser-transaction-continuity-v2260.php');
const outcome=read('includes/browser-transaction-outcome-v2250.php');
const safety=read('includes/browser-transaction-safety-v2240.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

must(manifest.version==='22.8.0','v22.80 extension version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '22.8.0';"),'v22.80 background version missing');
must(manifest.description.includes('Transaction Trust, Control & Production Hardening'),'v22.80 manifest description missing');

for(const table of [
  'browser_transaction_control_settings_v2280',
  'browser_transaction_scan_leases_v2280',
  'browser_transaction_control_receipts_v2280',
  'browser_transaction_notification_state_v2280',
  'browser_transaction_control_pauses_v2280'
]) must(control.includes(table),'missing v22.80 schema '+table);

must(control.includes("require_once __DIR__.'/browser-transaction-intelligence-v2270.php';"),'v22.80 must layer on v22.70');
must(api.includes("'server_authoritative'=>true"),'server-authoritative control declaration missing');
must(api.includes("'transaction_family_frozen_at'=>'v22.80'"),'transaction family freeze declaration missing');
must(api.includes("'automatic_external_writes'=>false"),'v22.80 automatic external write prohibition missing');
must(api.includes("'external_write_requires_fresh_v2240'=>true"),'fresh v22.40 authorization boundary missing');

// Global controls + explicit stop/resume/forget semantics.
must(control.includes('vp3_browser_control_stop_all_v2280'),'global stop missing');
must(control.includes('vp3_browser_control_resume_all_v2280'),'global resume missing');
must(control.includes('vp3_browser_control_tracker_action_v2280'),'per-tracker control missing');
must(control.includes('vp3_browser_control_forget_v2280'),'forget control missing');
must(control.includes("if((string)$row['tracking_status']!=='closed')"),'forget must require stopped tracker');
must(control.includes("DELETE FROM browser_transaction_continuities_v2260"),'forget must delete continuity root so v22.60-v22.80 descendants cascade');
must(control.includes("'submission_history_retained'=>true"),'forget must explicitly preserve v22.40/v22.50 history');
must(control.includes("closure_reason='user_closed'"),'global stop closure boundary missing');
must(control.includes("pause_scope='global'"),'global pause scope missing');
must(control.includes("pause_scope='tracker'"),'per-tracker pause scope missing');
must(control.includes("INNER JOIN browser_transaction_control_pauses_v2280"),'global resume must target only globally paused trackers');
must(control.includes("match_mode='reference' AND")&&control.includes("reference_hash<>''"),'resume must require reusable reference authority');
must(control.includes("Resume global transaction monitoring before resuming this tracker."),'global pause must block per-tracker resume');

// Retention + bounded settings.
for(const constant of [
  'VP3_BROWSER_CONTROL_RETENTION_MIN_V2280=7',
  'VP3_BROWSER_CONTROL_RETENTION_MAX_V2280=365',
  'VP3_BROWSER_CONTROL_SCAN_MIN_MIN_V2280=30',
  'VP3_BROWSER_CONTROL_SCAN_MIN_MAX_V2280=3600',
  'VP3_BROWSER_CONTROL_NOTIFY_MIN_V2280=5',
  'VP3_BROWSER_CONTROL_NOTIFY_MAX_V2280=1440'
]) must(control.includes(constant),'missing bounded control '+constant);
must(!control.includes("array_key_exists('monitoring_enabled',$input)"),'settings update must not bypass Stop/Resume monitoring semantics');
must(control.includes('vp3_browser_control_prune_v2280'),'retention prune missing');
must(control.includes("tracking_status='closed'")&&control.includes('closed_at<DATE_SUB'),'prune must target old closed trackers only');

// Cross-device consistency and scan suppression.
must(control.includes('vp3_browser_control_scan_permit_v2280'),'scan permit missing');
must(control.includes('leased_to_another_device'),'cross-device lease suppression missing');
must(control.includes('scan_cooldown'),'minimum scan cadence suppression missing');
must(control.includes("SELECT 1 FROM browser_transaction_continuities_v2260 WHERE owner_user_id=? AND domain=? AND tracking_status='active'"),'scan permit must require active owner/domain tracker');
must(control.includes('vp3_browser_control_device_hash_v2280'),'device identity hashing missing');
must(control.includes("'device_identity_persisted_as_hash'=>true"),'device hash privacy declaration missing');
must(control.includes("'minimal_forget_control_receipt_retained'=>true"),'minimal forget audit disclosure missing');
must(background.includes("browserTransactionControlApiV2280('scan_permit',{domain})"),'Chrome scan must request v22.80 permit before page capture');
must(background.indexOf("browserTransactionControlApiV2280('scan_permit',{domain})")<background.indexOf('browserTransactionContinuityCaptureV2260(tab.id)'),'scan permit must happen before page capture');
must(background.includes("browserTransactionControlApiV2280('scan_result'"),'scan result receipt missing');
must(background.includes('browserTransactionNextScanByDomainV2280'),'client-side scan throttle missing');
must(background.includes("reason:'control_unavailable'"),'v22.80 control-unavailable scan must fail closed');
must(background.includes("reason:'local_scan_cooldown'"),'local scan cooldown missing');
must(control.includes("'next_scan_after_seconds'=>$min"),'server scan cadence handoff missing');
must(background.includes("resultCode=/auth|reconnect/"),'scan failure classification missing');

// Failure recovery + health.
must(control.includes('consecutive_failures'),'failure counter missing');
must(control.includes("'scan_failure'"),'scan failure receipt missing');
must(control.includes("'failing_scopes'"),'control health summary missing');

// Notification/noise suppression and monitoring gate.
must(intelligence.includes('vp3_browser_intelligence_monitoring_enabled_v2270'),'v22.70 must honor v22.80 monitoring state');
must(intelligence.includes('notification_cooldown_minutes'),'notification cooldown integration missing');
must(intelligence.includes('browser_transaction_notification_state_v2280'),'notification dedupe state integration missing');
must(intelligence.includes("$age<($cooldown*60)&&$score<=(int)$prior['last_priority_score']"),'notification suppression/escalation rule missing');
must(intelligence.includes("if(!vp3_browser_intelligence_monitoring_enabled_v2270($pdo,$uid))return [];"),'per-continuity monitoring stop missing');

// Full audit chain.
for(const phase of ['v22.40','v22.50','v22.60','v22.70','v22.80'])must(control.includes("'phase'=>'"+phase+"'"),'audit phase missing '+phase);
must(control.includes('browser_submission_intents_v2240'),'v22.40 audit source missing');
must(control.includes('browser_transaction_outcomes_v2250'),'v22.50 audit source missing');
must(control.includes('browser_transaction_continuities_v2260'),'v22.60 audit source missing');
must(control.includes('browser_transaction_intelligence_cases_v2270'),'v22.70 audit source missing');
must(control.includes('browser_transaction_control_receipts_v2280'),'v22.80 audit source missing');

// Privacy boundaries.
for(const declaration of [
  "'raw_page_text_persisted'=>false",
  "'raw_url_persisted'=>false",
  "'raw_reference_values_persisted'=>false"
]) must(control.includes(declaration),'v22.80 privacy declaration missing '+declaration);
const schemaStart=control.indexOf('CREATE TABLE IF NOT EXISTS browser_transaction_control_settings_v2280');
const schemaEnd=control.indexOf('function vp3_browser_control_uuid_v2280');
const schema=control.slice(schemaStart,schemaEnd).toLowerCase();
for(const forbidden of ['page_text','raw_text','raw_url','source_url','order_number','booking_number','reference_value','password','cookie','card_number']){
  must(!schema.includes(forbidden),'v22.80 schema must not persist '+forbidden);
}

// Control center UI.
for(const id of [
  'runtimeControlPanel','runtimeControlState','runtimeControlSummary','runtimeControlRetention',
  'runtimeControlCooldown','runtimeControlScanInterval','runtimeControlHealth','runtimeControlTrackers',
  'runtimeControlSaveBtn','runtimeControlStopAllBtn','runtimeControlResumeAllBtn','runtimeControlPruneBtn',
  'runtimeControlAudit'
]) must(html.includes('id="'+id+'"'),'missing v22.80 UI '+id);
must(css.includes('.runtime-control-panel')&&css.includes('.runtime-control-tracker')&&css.includes('.runtime-control-audit-item'),'v22.80 styles missing');
for(const fn of [
  'renderRuntimeControlV2280','loadRuntimeControlV2280','saveRuntimeControlV2280',
  'stopAllRuntimeControlV2280','resumeAllRuntimeControlV2280','pruneRuntimeControlV2280','trackerRuntimeControlV2280'
]) must(panel.includes(fn),'missing v22.80 controller '+fn);
must(panel.includes("action==='control_tracker_forget'"),'Forget tracker UI action missing');
must(panel.includes('Original submission/outcome history remains')||panel.includes('original v22.40/v22.50 transaction history remains'),'Forget disclosure missing');

// API/routes/upgrade.
must(background.includes("authorizedFetch('/api/extension-transaction-control-v2280.php'"),'v22.80 API adapter missing');
must(background.includes("case 'transaction_control_status'"),'v22.80 status route missing');
must(background.includes("case 'transaction_control_action'"),'v22.80 action route missing');
for(const action of ['settings_update','stop_all','resume_all','tracker_action','forget','prune','scan_permit','scan_result','audit']){
  must(api.includes("$action==='"+action+"'"),'v22.80 API action missing '+action);
}
must(upgrade.includes("require_once __DIR__ . '/includes/browser-transaction-control-v2280.php';"),'upgrade v22.80 include missing');
must(upgrade.includes('vp3_browser_control_schema_ready_v2280()'),'upgrade v22.80 readiness missing');
must(upgrade.includes('vp3_browser_control_ensure_schema_v2280();'),'upgrade v22.80 install missing');

// Agent Workflows / production closure.
must(workflows.includes("browser-transaction-control-v2280.php"),'Agent Workflows v22.80 include missing');
must(workflows.includes('Transaction Trust, Control & Production Hardening'),'Agent Workflows v22.80 panel missing');
must(workflows.includes('v22.40 → v22.80'),'full audit-chain label missing');
must(workflows.includes('final transaction-family control layer'),'final-family boundary missing');

// Retained architecture: no bypass of earlier transaction gates.
must(safety.includes('review_hash'),'v22.40 exact-form safety authority missing');
must(outcome.includes("'confirmed','pending','rejected','ambiguous','external_redirect'"),'v22.50 outcome model changed unexpectedly');
must(continuity.includes('reference_hash'),'v22.60 reference-only continuity missing');
must(intelligence.includes("'external_actions_executed'=>0"),'v22.70 batch review external-action boundary missing');
must(!api.includes("action==='submit'")&&!api.includes("action==='purchase'")&&!api.includes("action==='reschedule_external'"),'v22.80 must not expose external execution');

console.log('VP3 Browser Companion Transaction Trust, Control & Production Hardening v22.80 contract passed.');
