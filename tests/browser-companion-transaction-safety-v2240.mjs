import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const delegation=read('includes/browser-delegation-v2190.php');
const runtime=read('includes/browser-agent-runtime-v2200.php');
const web=read('includes/browser-web-interaction-v2210.php');
const safety=read('includes/browser-transaction-safety-v2240.php');
const api=read('api/extension-transaction-safety-v2240.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

must(manifest.version==='22.4.0','v22.40 manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '22.4.0';"),'v22.40 request version missing');
must(!manifest.permissions.includes('cookies'),'Transaction Safety must not request cookies permission');

// Authority chain and explicit opt-in.
must(delegation.includes("'transaction_submit'"),'transaction_submit delegation action missing');
must(delegation.includes("Transaction & Submission Safety requires a Medium risk budget"),'medium-risk plan gate missing');
must(html.includes('data-delegation-action="transaction_submit"'),'v22.40 delegation checkbox missing');
must(runtime.includes("'transaction_submit'=>["),'v22.00 transaction_submit skill missing');
must(runtime.includes("'verification_mode'=>'review_hash_and_user_confirmation'"),'exact-review verification mode missing');
must(api.includes("'authority_source'=>'v21.90_delegation'"),'v21.90 authority declaration missing');
must(api.includes("'runtime_source'=>'v22.00_browser_agent_runtime'"),'v22.00 runtime declaration missing');
must(api.includes("'interaction_source'=>'v22.10_controlled_web'"),'v22.10 interaction declaration missing');
must(api.includes("'submission_source'=>'v22.40_transaction_safety'"),'v22.40 submission declaration missing');

// v22.40 is a second lock on top of the existing v22.10 submit checkpoint.
must(web.includes("'submit'=>["),'v22.10 submit action missing');
must(web.includes("'requires_checkpoint'=>true"),'v22.10 submit checkpoint missing');
must(safety.includes("A consequential v22.10 form-action checkpoint is required before final submission review."),'v22.10 final-action checkpoint dependency missing');
must(safety.includes("(string)$web['status']!=='approval_pending'"),'v22.10 approval-pending precondition missing');
must(safety.includes("UPDATE browser_web_interactions_v2210 SET status='approved'"),'v22.40 approval must unlock the exact v22.10 submit proposal');
must(web.includes('Consequential form finalization requires the v22.40 exact-form review checkpoint.'),'generic v22.10 final-action confirmation must be blocked');
must(panel.includes("const transactionRequired=action==='submit'||"),'native submit / dangerous click v22.40 routing missing');
must(panel.includes('Boolean(item.submit_like)'),'submit-like click v22.40 routing missing');
must(panel.includes("Boolean(item.dangerous)"),'dangerous form button v22.40 routing missing');
must(background.includes("action_key:String(payload.web_action_key||'submit')"),'reviewed dangerous-click execution primitive missing');

// Exact form-state binding.
for(const key of ['page_fingerprint','form_fingerprint','submit_fingerprint','review_hash','duplicate_key']){
  must(safety.includes(key),'missing submission binding '+key);
}
must(background.includes('browserTransactionCaptureV2240'),'local final form capture missing');
must(background.includes("review_hash:reviewHash"),'review hash missing from local capture');
must(background.includes("semantic_text:clip([labelFor(submit),heading"),'server semantics must exclude field labels/values');
must(background.includes("target_host:String(review.target_host||'')"),'final target host must be sent for server boundary validation');
must(background.includes("form_fingerprint:formFingerprint"),'form fingerprint missing from local capture');
must(background.includes("page_fingerprint:pageFingerprint"),'page fingerprint missing from local capture');
must(background.includes("submit_fingerprint:submitFingerprint"),'submit fingerprint missing from local capture');
must(background.includes("fields.push({index,name,type,tag,value:exactValue})"),'exact form values must participate in local review hash');
must(background.includes("reviewHash=await sha(JSON.stringify"),'exact review hash calculation missing');
must(safety.includes("The page or reviewed form changed after approval."),'server fail-closed changed-state gate missing');
must(safety.includes('v22.40 final form actions are limited to the current approved domain.'),'same-domain finalization gate missing');
must(background.includes("review_state_changed_after_permit"),'post-permit local recheck missing');

// Values stay local; secrets are masked.
must(api.includes("'raw_field_values_persisted'=>false"),'raw-value privacy declaration missing');
must(api.includes("'credentials_persisted'=>false"),'credential privacy declaration missing');
const schemaSection=safety.slice(safety.indexOf('CREATE TABLE IF NOT EXISTS browser_submission_intents_v2240'),safety.indexOf(') ENGINE=InnoDB',safety.indexOf('CREATE TABLE IF NOT EXISTS browser_submission_intents_v2240')));
for(const forbidden of ['field_value','raw_value','password_value','card_number','cvv','cvc','otp','mfa_code','cookie_value','access_token']){
  must(!schemaSection.toLowerCase().includes(forbidden),'v22.40 schema must not persist '+forbidden);
}
must(background.includes("displayValue=exactValue?'•••••• · value entered':'No value entered'"),'sensitive review masking missing');
must(background.includes("type==='hidden'"),'hidden form tokens must be masked');
must(html.includes('Sensitive values are masked here and are never sent to VP3.'),'review privacy copy missing');

// One-time approval, stale-state protection, duplicate prevention.
must(safety.includes('VP3_BROWSER_TRANSACTION_PERMIT_SECONDS_V2240=60'),'short final-submit permit missing');
must(safety.includes("status='executing',permit_hash=?"),'one-time execution claim missing');
must(safety.includes("permit_hash=NULL,permit_expires_at=NULL"),'permit consumption missing');
must(safety.includes("WHERE id=? AND status='executing' AND permit_hash=?"),'single-use conditional permit consumption missing');
must(safety.includes('VP3_BROWSER_TRANSACTION_DUPLICATE_WINDOW_SECONDS_V2240=600'),'duplicate window missing');
must(safety.includes('browser_submission_dispatch_guards_v2240'),'atomic dispatch guard table missing');
must(safety.includes('PRIMARY KEY (guard_key)'),'atomic dispatch guard uniqueness missing');
must(safety.includes("status IN ('executing','completed','uncertain')")||safety.includes("'uncertain'"),'uncertain submissions must remain duplicate-blocking');
must(safety.includes('Duplicate final submission blocked.'),'duplicate-submit block missing');
must(api.includes("'approval_reusable'=>false"),'non-reusable approval declaration missing');
must(background.includes("for(const key of ['page_fingerprint','form_fingerprint','submit_fingerprint','review_hash'])"),'Chrome post-claim exact-state check missing');

// Consequence handling.
for(const kind of ['financial','transfer','booking','communication','publishing','application','account_change','destructive','agreement']){
  must(safety.includes("'"+kind+"'"),'missing consequence classification '+kind);
}
must(safety.includes("array_intersect($flags,['transfer','destructive'])"),'high-impact manual-only gate missing');
must(safety.includes('This high-impact submission is manual-only.'),'manual-only enforcement missing');
must(panel.includes("transaction.intent.manual_only"),'manual-only UI handling missing');
must(panel.includes('could not bind this consequential control to a standard form review'),'non-form consequential action manual-only fallback missing');
must(panel.includes("Confirm exact form & submit"),'explicit exact-form confirmation label missing');

// Dispatch receipt semantics must not overclaim downstream success.
must(safety.includes('submission_dispatch_uncertain'),'uncertain dispatch state missing');
must(safety.includes('Do not retry until the destination state is reviewed.'),'uncertain retry warning missing');
must(background.includes('Submission dispatch may have started but could not be verified.'),'Chrome uncertainty warning missing');
must(workflows.includes('an uncertain receipt means dispatch may have occurred and must be reviewed before retrying'),'workflow uncertain receipt disclaimer missing');
must(workflows.includes('Transaction & Submission Safety'),'workflow receipt panel missing');

// Upgrade and Chrome wiring.
must(upgrade.includes("require_once __DIR__ . '/includes/browser-transaction-safety-v2240.php';"),'upgrade include missing');
must(upgrade.includes('vp3_browser_transaction_schema_ready_v2240()'),'upgrade readiness missing');
must(upgrade.includes('vp3_browser_transaction_ensure_schema_v2240();'),'upgrade schema install missing');
must(background.includes("authorizedFetch('/api/extension-transaction-safety-v2240.php'"),'v22.40 API adapter missing');
must(background.includes("case 'transaction_review'"),'transaction review message route missing');
must(background.includes("case 'transaction_execute'"),'transaction execute message route missing');
for(const id of ['runtimeTransactionReview','runtimeTransactionKind','runtimeTransactionWarning','runtimeTransactionFields']){
  must(html.includes('id="'+id+'"'),'missing v22.40 UI '+id);
}
must(css.includes('.runtime-transaction-review')&&css.includes('.runtime-transaction-field'),'v22.40 review styling missing');

console.log('VP3 Browser Companion Transaction & Submission Safety v22.40 contract passed.');
