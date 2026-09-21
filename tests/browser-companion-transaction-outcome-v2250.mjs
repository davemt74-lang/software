import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const safety=read('includes/browser-transaction-safety-v2240.php');
const outcome=read('includes/browser-transaction-outcome-v2250.php');
const api=read('api/extension-transaction-outcome-v2250.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

must(manifest.version==='22.5.0','v22.50 manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '22.5.0';"),'v22.50 request version missing');
must(!manifest.permissions.includes('cookies'),'Outcome verification must not request cookies permission');

// Layer over the exact reviewed v22.40 transaction; no new external-action authority.
must(outcome.includes("require_once __DIR__.'/browser-transaction-safety-v2240.php';"),'v22.50 must layer over v22.40');
must(outcome.includes("in_array('transaction_submit',$actions,true)"),'original transaction authority must remain required');
must(api.includes("'authority_source'=>'v22.40_reviewed_submission'"),'v22.40 authority declaration missing');
must(api.includes("'runtime_source'=>'v22.00_browser_agent_runtime'"),'v22.00 runtime declaration missing');
must(api.includes("'outcome_source'=>'v22.50_destination_verification'"),'v22.50 outcome declaration missing');
must(api.includes("'automatic_retry'=>false"),'automatic retry must be explicitly disabled');
must(api.includes("'recovery_requires_user_resolution'=>true"),'user resolution boundary missing');

// Durable recovery remains available after a runtime ends, without reopening execution authority.
const runtimeHelper=outcome.slice(outcome.indexOf('function vp3_browser_outcome_runtime_v2250'),outcome.indexOf('function vp3_browser_outcome_intent_v2250'));
must(runtimeHelper.includes('vp3_browser_runtime_row_v2200'),'v22.50 must read the original runtime directly');
must(!runtimeHelper.includes('vp3_browser_transaction_runtime_v2240'),'v22.50 recovery must not require an active v22.40 runtime');
must(runtimeHelper.includes("'transaction_submit'"),'original transaction delegation must still be present');

// Privacy: durable state is fingerprints + structured evidence, never raw destination content or raw references.
must(api.includes("'raw_page_text_persisted'=>false"),'raw page privacy declaration missing');
must(api.includes("'raw_reference_values_persisted'=>false"),'raw reference privacy declaration missing');
const schemaStart=outcome.indexOf('CREATE TABLE IF NOT EXISTS browser_transaction_outcomes_v2250');
const schemaEnd=outcome.indexOf(') ENGINE=InnoDB',schemaStart);
const schema=outcome.slice(schemaStart,schemaEnd);
must(schemaStart>=0&&schemaEnd>schemaStart,'v22.50 outcome schema missing');
for(const forbidden of ['page_text','raw_text','raw_url','confirmation_value','reference_value','order_number','card_number','password','cookie','access_token']){
  must(!schema.toLowerCase().includes(forbidden),'v22.50 schema must not persist '+forbidden);
}
for(const required of ['page_fingerprint','content_hash','observation_fingerprint','outcome_state','evidence_strength','evidence_codes_json','reference_kind','reference_hash']){
  must(schema.includes(required),'v22.50 outcome schema missing '+required);
}
must(background.includes("bodyText=clip(document.body?.innerText"),'local destination text classifier missing');
must(background.includes("contentHash=await sha(bodyText)"),'local destination content hash missing');
must(background.includes("referenceHash=await sha(referenceKind+'|'+referenceValue)"),'local reference hashing missing');
must(background.includes("maskedReference='••••'+referenceValue.slice(-4)"),'local masked reference display missing');
const observeCall=background.slice(background.indexOf("browserTransactionOutcomeApiV2250('observe'"),background.indexOf("browserTransactionOutcomeApiV2250('observe'")+1800);
must(!observeCall.includes('bodyText')&&!observeCall.includes('referenceValue')&&!observeCall.includes('masked_reference'),'raw local receipt data must not be sent to VP3');

// Cross-domain redirect is fingerprint-only and not inspected.
const capture=background.slice(background.indexOf('async function browserTransactionOutcomeCaptureV2250'),background.indexOf('async function browserTransactionOutcomeObserveV2250'));
const changedDomain=capture.indexOf("outcome_state:'external_redirect'");
const bodyRead=capture.indexOf("bodyText=clip(document.body?.innerText");
must(changedDomain>=0&&bodyRead>changedDomain,'cross-domain branch must exit before page text inspection');
must(capture.includes("observedDomainHash=await sha(host)"),'changed domain must be fingerprinted locally');
must(outcome.includes('Cross-domain destination pages are not inspected by v22.50.'),'server cross-domain inspection boundary missing');

// Confirmed means explicit confirmation plus an independent signal, not a single thank-you phrase.
must(background.includes("const auxiliary=unique.filter(code=>['confirmation_url_hint','reference_present','receipt_keyword','status_region','form_absent']"),'independent client evidence set missing');
must(background.includes("explicit&&auxiliary.length>=1"),'client confirmation threshold missing');
must(outcome.includes("$auxiliary=array_intersect($evidence,['confirmation_url_hint','reference_present','receipt_keyword','form_absent','status_region'])"),'server independent evidence set missing');
must(outcome.includes('explicit confirmation message plus an independent local signal'),'server confirmation threshold missing');
for(const state of ['confirmed','pending','rejected','ambiguous','external_redirect']){
  must(outcome.includes("'"+state+"'"),'missing outcome state '+state);
}

// Bounded, deduplicated observations.
must(outcome.includes('VP3_BROWSER_OUTCOME_MAX_OBSERVATIONS_V2250=12'),'bounded outcome recheck limit missing');
must(schema.includes('UNIQUE KEY uq_browser_outcome_observation_v2250'),'observation deduplication key missing');
must(outcome.includes('This transaction reached its bounded destination recheck limit.'),'bounded recheck enforcement missing');
must(outcome.includes("'deduplicated'=>true"),'deduplicated outcome response missing');

// v22.40 uncertain dispatch is automatically followed by a read-only v22.50 check.
must(background.includes('browserTransactionOutcomeObserveV2250({'),'automatic post-dispatch destination check missing');
must(background.includes('outcome_verification:outcomeVerification'),'outcome verification result must return to sidepanel');
must(background.includes('outcome_confirmed:outcomeConfirmed'),'v22.50 confirmation result integration missing');
must(panel.includes('renderRuntimeOutcomeV2250(result.outcome_verification)'),'sidepanel automatic outcome rendering missing');

// Recovery never resubmits. "Nothing submitted" only releases the duplicate guard, then requires a fresh v22.40 review.
must(outcome.includes("if($resolution==='confirmed_completed')"),'completed user resolution missing');
must(outcome.includes("elseif($resolution==='confirmed_not_submitted')"),'not-submitted user resolution missing');
must(outcome.includes("'reviewed_destination_not_submitted'"),'explicit no-submission acknowledgement missing');
must(outcome.includes('DELETE FROM browser_submission_dispatch_guards_v2240'),'guard release path missing');
must(outcome.includes("SET status='failed',result_code='v2250_user_confirmed_not_submitted'"),'old uncertain intent must be terminal before retry unlock');
must(outcome.includes("$retryAllowed=true"),'fresh retry eligibility receipt missing');
must(outcome.includes('any retry requires a fresh v22.40 exact-form review'),'fresh-review recovery boundary missing');
must(!api.includes("action==='retry'")&&!api.includes("action==='submit'"),'v22.50 API must not expose retry or submit actions');
must(!background.includes("case 'transaction_outcome_retry'"),'Chrome must not expose automatic outcome retry');
must(panel.includes('I confirmed nothing was submitted')||html.includes('I confirmed nothing was submitted'),'explicit no-submission UI missing');
must(panel.includes('globalThis.confirm(messageText)'),'recovery resolution confirmation missing');

// Outcome UI and workflow receipts.
for(const id of ['runtimeOutcomePanel','runtimeOutcomeState','runtimeOutcomeSummary','runtimeOutcomeEvidence','runtimeOutcomeReference','runtimeOutcomeRecheckBtn','runtimeOutcomeCompletedBtn','runtimeOutcomeNotSubmittedBtn']){
  must(html.includes('id="'+id+'"'),'missing v22.50 UI '+id);
}
must(css.includes('.runtime-outcome-panel')&&css.includes('.runtime-outcome-boundary'),'v22.50 UI styling missing');
must(panel.includes('async function recheckRuntimeOutcomeV2250'),'outcome recheck controller missing');
must(panel.includes('async function resolveRuntimeOutcomeV2250'),'outcome resolution controller missing');
must(panel.includes("resolution==='confirmed_not_submitted'"),'no-submission resolution UI path missing');
must(workflows.includes('Transaction Outcome Verification & Recovery'),'Agent Workflow v22.50 panel missing');
must(workflows.includes('VP3 never retries automatically.'),'workflow no-auto-retry disclosure missing');

// Upgrade integration.
must(upgrade.includes("require_once __DIR__ . '/includes/browser-transaction-outcome-v2250.php';"),'upgrade v22.50 include missing');
must(upgrade.includes('vp3_browser_outcome_schema_ready_v2250()'),'upgrade v22.50 readiness missing');
must(upgrade.includes('vp3_browser_outcome_ensure_schema_v2250();'),'upgrade v22.50 install missing');
must(background.includes("authorizedFetch('/api/extension-transaction-outcome-v2250.php'"),'v22.50 API adapter missing');
must(background.includes("case 'transaction_outcome_recheck'"),'v22.50 recheck message route missing');
must(background.includes("case 'transaction_outcome_resolve'"),'v22.50 resolve message route missing');

// Retain the v22.40 duplicate guard semantics while v22.50 resolves outcomes.
must(safety.includes('browser_submission_dispatch_guards_v2240'),'v22.40 duplicate guard missing');
must(safety.includes('submission_dispatch_uncertain'),'v22.40 uncertain state missing');

console.log('VP3 Browser Companion Transaction Outcome Verification & Recovery v22.50 contract passed.');
