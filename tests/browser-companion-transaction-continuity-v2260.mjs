import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const continuity=read('includes/browser-transaction-continuity-v2260.php');
const api=read('api/extension-transaction-continuity-v2260.php');
const outcome=read('includes/browser-transaction-outcome-v2250.php');
const safety=read('includes/browser-transaction-safety-v2240.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

const versionParts=String(manifest.version||'').split('.').map(Number);
must(versionParts.length===3&&versionParts[0]===22&&versionParts[1]>=6,'v22.60-or-later manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '"+manifest.version+"';"),'Browser Companion request version must match manifest');
must(!manifest.permissions.includes('cookies'),'Continuity must not request cookies permission');

// v22.60 layers on verified v22.50 outcomes and never creates an execution bypass.
must(continuity.includes("require_once __DIR__.'/browser-transaction-outcome-v2250.php';"),'v22.60 must layer over v22.50');
must(api.includes("'authority_source'=>'v22.50_verified_transaction'"),'v22.50 authority declaration missing');
must(api.includes("'continuity_source'=>'v22.60_reference_only_followthrough'"),'v22.60 continuity declaration missing');
must(api.includes("'automatic_external_writes'=>false"),'automatic external writes must be disabled');
must(api.includes("'external_write_requires_fresh_v2240'=>true"),'fresh v22.40 boundary missing');
must(safety.includes('review_hash'),'v22.40 exact-form authority must remain present');
for(const forbiddenAction of ["action==='submit'","action==='retry'","action==='purchase'","action==='cancel_external'","action==='reschedule_external'"]){
  must(!api.includes(forbiddenAction),'v22.60 API must not expose external write action '+forbiddenAction);
}

// Schema: durable reference/state hashes only, no raw browsing/page/reference content.
for(const table of [
  'browser_transaction_continuities_v2260',
  'browser_transaction_continuity_events_v2260',
  'browser_transaction_followthrough_proposals_v2260'
]) must(continuity.includes('CREATE TABLE IF NOT EXISTS '+table),'missing v22.60 table '+table);

const schemaStart=continuity.indexOf('CREATE TABLE IF NOT EXISTS browser_transaction_continuities_v2260');
const schemaEnd=continuity.indexOf('function vp3_browser_continuity_sha_v2260');
const schema=continuity.slice(schemaStart,schemaEnd);
for(const forbidden of ['raw_text','page_text','raw_url','source_url','reference_value','order_number','booking_number','confirmation_value','cookie','password','card_number','access_token']){
  must(!schema.toLowerCase().includes(forbidden),'v22.60 schema must not persist '+forbidden);
}
for(const required of ['reference_hash','last_page_fingerprint','last_content_hash','schedule_hash','amount_hash','tracking_status','closure_reason']){
  must(schema.includes(required),'v22.60 schema missing '+required);
}
must(api.includes("'raw_page_text_persisted'=>false"),'raw page privacy declaration missing');
must(api.includes("'raw_url_persisted'=>false"),'raw URL privacy declaration missing');
must(api.includes("'raw_reference_values_persisted'=>false"),'raw reference privacy declaration missing');

// Continuity can begin only after a completed/confirmed transaction and only with a reusable hashed reference.
must(continuity.includes("if((string)$intent['status']!=='completed')"),'completed transaction prerequisite missing');
must(continuity.includes("outcome_state='confirmed'"),'confirmed v22.50 outcome prerequisite missing');
must(continuity.includes("resolution_key']==='confirmed_completed'"),'explicit completed recovery path missing');
must(continuity.includes("resolution_key']==='confirmed_not_submitted'"),'not-submitted exclusion missing');
must(continuity.includes('Transaction continuity requires a reusable hashed v22.50 reference.'),'reference-only eligibility gate missing');
must(continuity.includes("$matchMode='reference'"),'reference-only match mode missing');
must(!continuity.includes("$matchMode=$referenceHash!==''?'reference':'manual'"),'manual continuity fallback must not exist');

// Return-page privacy gate: list tracked domain first, and only capture page text when an active reference tracker exists.
const scanStart=background.indexOf('async function browserTransactionContinuityScanV2260');
const scanEnd=background.indexOf('async function browserTransactionContinuityListV2260',scanStart);
const scan=background.slice(scanStart,scanEnd);
must(scan.includes("browserTransactionContinuityApiV2260('list',{domain})"),'domain preflight missing');
must(scan.includes('activeReference'),'active reference preflight missing');
must(scan.includes("if(!activeReference)return"),'page capture must stop when no active reference tracker exists');
must(scan.indexOf("browserTransactionContinuityApiV2260('list',{domain})")<scan.indexOf('browserTransactionContinuityCaptureV2260(tab.id)'),'preflight must occur before page capture');
must(panel.includes("pageWatch")&&panel.includes("scanRuntimeContinuityV2260(true)"),'page-change continuity scan missing');
must(panel.includes("refreshState().then(async()=>{await scanRuntimeContinuityV2260(true)"),'initial continuity scan missing');

// Local reference extraction and hashes; raw page/reference data must not cross the v22.60 API.
const captureStart=background.indexOf('async function browserTransactionContinuityCaptureV2260');
const captureEnd=background.indexOf('async function browserTransactionContinuityScanV2260',captureStart);
const capture=background.slice(captureStart,captureEnd);
must(capture.includes("bodyText=clip(document.body?.innerText"),'local lifecycle classifier missing');
must(capture.includes("const hash=await sha(kind+'|'+value)"),'local reference hashing missing');
must(capture.includes("masked:'••••'+value.slice(-4)"),'local masked reference display missing');
must(capture.includes('scheduleHash'),'local schedule fingerprint missing');
must(capture.includes('amountHash'),'local amount fingerprint missing');
must(capture.includes('observationFingerprint'),'local observation fingerprint missing');

const observeStart=scan.indexOf("browserTransactionContinuityApiV2260('observe'");
const observeEnd=scan.indexOf("});\n  return",observeStart);
const observePayload=scan.slice(observeStart,observeEnd>observeStart?observeEnd:scan.length);
must(!observePayload.includes('bodyText'),'raw page text must not be sent to v22.60 API');
must(!observePayload.includes('masked_references'),'masked reference display must remain local');
must(!observePayload.includes('referenceValue'),'raw reference value must not be sent to v22.60 API');

// Exact owner + approved domain + reference fingerprint matching; never browsing-history fallback.
must(continuity.includes("WHERE owner_user_id=? AND domain=? AND tracking_status='active' AND match_mode='reference' AND reference_hash IN"),'server reference/domain matcher missing');
must(continuity.includes("if(count($matches)===0)return ['matched'=>false,'reason'=>'no_reference_match'"),'no-match boundary missing');
must(continuity.includes("if(count($matches)>1)return ['matched'=>false,'reason'=>'ambiguous_reference_match'"),'ambiguous reference fail-closed boundary missing');
must(panel.includes('General browsing history and page content are not used as fallback matching.'),'no browsing-history fallback disclosure missing');

// Bounded event storage and deduplication.
must(continuity.includes('VP3_BROWSER_CONTINUITY_MAX_REFERENCE_CANDIDATES_V2260=12'),'reference candidate bound missing');
must(continuity.includes('VP3_BROWSER_CONTINUITY_MAX_EVENTS_V2260=80'),'event bound missing');
must(schema.includes('UNIQUE KEY uq_browser_continuity_observation_v2260'),'observation deduplication key missing');
must(continuity.includes('This transaction reached its bounded continuity event limit.'),'bounded event enforcement missing');
must(continuity.includes("'deduplicated'=>true"),'deduplicated continuity receipt missing');

// Supported lifecycle families and structured states.
for(const family of ['commerce','booking','application','communication','account','generic']){
  must(continuity.includes("'"+family+"'"),'missing lifecycle family '+family);
}
for(const state of ['ordered','processing','shipped','out_for_delivery','delivered','cancelled','refunded','confirmed','changed','upcoming','completed','submitted','under_review','needs_action','approved','rejected','sent','replied','resolved','active','pending']){
  must(continuity.includes("'"+state+"'"),'missing lifecycle state '+state);
}
for(const change of ['state_changed','schedule_changed','amount_changed','needs_action','cancellation','delivery_progress','decision_received','reply_received']){
  must(continuity.includes("'"+change+"'"),'missing change code '+change);
}

// Follow-through is proposal-first and bounded to internal review/navigation.
must(continuity.includes("'calendar_review'"),'booking calendar review proposal missing');
must(continuity.includes("'task_review'"),'task follow-up proposal missing');
must(continuity.includes("'reply_review'"),'reply follow-through proposal missing');
must(continuity.includes("'corrective_action_review'"),'corrective action review proposal missing');
must(continuity.includes("'transaction_review','amount_changed',false"),'amount-change review proposal missing');
must(continuity.includes("in_array($action,['acknowledge','dismiss'],true)"),'proposal resolution must be acknowledgement/dismissal only');
must(!continuity.includes("'apply_external'"),'proposal runtime must not execute external changes');
must(panel.includes('Any external write requires a fresh v22.40 review.'),'fresh v22.40 proposal disclosure missing');

// Meaningful changes notify the existing notification/Agent Voice path.
must(continuity.includes('create_notification('),'continuity notification integration missing');
must(continuity.includes("'browser_transaction_booking_update'"),'booking update notification missing');
must(continuity.includes("'browser_transaction_order_update'"),'order update notification missing');
must(continuity.includes("'browser_transaction_message_update'"),'message update notification missing');
must(continuity.includes("'browser_transaction_needs_attention'"),'attention notification missing');
must(continuity.includes("vp3_browser_runtime_event_v2200"),'Cognitive Runtime event integration missing');

// Terminal lifecycle states automatically close tracking; user can explicitly close/reopen reference trackers.
must(continuity.includes("$newTracking=$terminal?'closed':'active'"),'terminal auto-close missing');
must(continuity.includes("$closure=$terminal?'terminal_state_observed':''"),'terminal closure receipt missing');
must(!continuity.includes("['close_tracking','terminal_state'"),'redundant terminal closure proposal must not exist');
must(continuity.includes("function vp3_browser_continuity_close_v2260"),'explicit close action missing');
must(continuity.includes("function vp3_browser_continuity_reopen_v2260"),'explicit reopen action missing');
must(continuity.includes("Automatic return-page matching cannot reopen without a hashed transaction reference."),'reopen reference boundary missing');

// v22.50 confirmation automatically attempts continuity creation but safely ignores ineligible no-reference outcomes.
must(background.includes("if(String(response&&response.outcome&&response.outcome.outcome_state||'')==='confirmed')"),'automatic post-confirmation continuity hook missing');
must(background.includes('browserTransactionContinuityEnsureV2260'), 'continuity ensure adapter missing');
must(workflows.includes('No reusable reference was available, so cross-time transaction tracking was not started.'),'no-reference completed outcome handling missing');

// UI and durable Agent Workflow receipts.
for(const id of ['runtimeContinuityPanel','runtimeContinuityState','runtimeContinuitySummary','runtimeContinuityMatch','runtimeContinuityChanges','runtimeContinuityProposals','runtimeContinuityScanBtn','runtimeContinuityCloseBtn','runtimeContinuityReopenBtn']){
  must(html.includes('id="'+id+'"'),'missing v22.60 UI '+id);
}
must(css.includes('.runtime-continuity-panel')&&css.includes('.runtime-continuity-boundary'),'v22.60 styling missing');
must(panel.includes('function renderRuntimeContinuityV2260'),'continuity renderer missing');
must(panel.includes('async function scanRuntimeContinuityV2260'),'continuity scan controller missing');
must(panel.includes('async function continuityTrackerActionV2260'),'continuity close/reopen controller missing');
must(panel.includes('async function continuityProposalActionV2260'),'proposal action controller missing');
must(workflows.includes('Transaction Continuity & Follow-Through'),'Agent Workflow v22.60 panel missing');
must(workflows.includes('browser_continuity_close')&&workflows.includes('browser_continuity_reopen'),'durable close/reopen actions missing');
must(workflows.includes('browser_followthrough_ack')&&workflows.includes('browser_followthrough_dismiss'),'durable proposal receipt actions missing');

// Upgrade and message routing.
must(upgrade.includes("require_once __DIR__ . '/includes/browser-transaction-continuity-v2260.php';"),'upgrade v22.60 include missing');
must(upgrade.includes('vp3_browser_continuity_schema_ready_v2260()'),'upgrade v22.60 readiness missing');
must(upgrade.includes('vp3_browser_continuity_ensure_schema_v2260();'),'upgrade v22.60 install missing');
must(background.includes("authorizedFetch('/api/extension-transaction-continuity-v2260.php'"),'v22.60 API adapter missing');
must(background.includes("case 'transaction_continuity_scan'"),'continuity scan route missing');
must(background.includes("case 'transaction_continuity_list'"),'continuity list route missing');
must(background.includes("case 'transaction_continuity_action'"),'continuity action route missing');

console.log('VP3 Browser Companion Transaction Continuity & Follow-Through v22.60 contract passed.');
