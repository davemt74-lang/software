import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const web=read('includes/browser-web-interaction-v2210.php');
const api=read('api/extension-web-interaction-v2210.php');
const delegation=read('includes/browser-delegation-v2190.php');
const runtime=read('includes/browser-agent-runtime-v2200.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

const webVersion=String(manifest.version||'').split('.').map(Number);
must(webVersion.length===3&&(webVersion[0]>22||(webVersion[0]===22&&webVersion[1]>=1)),'v22.10+ manifest version missing');
must(background.includes(`const VP3_EXTENSION_VERSION = '${manifest.version}';`),'v22.10+ request version must match manifest');
must(background.includes("authorizedFetch('/api/extension-web-interaction-v2210.php'"),'Controlled Web Interaction API adapter missing');
must(background.includes("case 'web_interaction_observe': return browserWebInteractionObserveV2210"),'Web observation message route missing');
must(background.includes("case 'web_interaction_execute': return browserWebInteractionExecuteV2210"),'Web execution message route missing');

// v21.90 remains the authority envelope and v22.00 remains the runtime owner.
must(web.includes("require_once __DIR__.'/browser-agent-runtime-v2200.php';"),'v22.10 must layer over v22.00 runtime');
must(api.includes("'authority_source'=>'v21.90_delegation'"),'v21.90 authority declaration missing');
must(api.includes("'runtime_source'=>'v22.00_browser_agent_runtime'"),'v22.00 runtime declaration missing');
must(web.includes('vp3_browser_web_authority_v2210'),'server interaction authority gate missing');
must(web.includes("vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json']"),'Web skills must be constrained by approved delegation actions');
must(web.includes('vp3_browser_web_allowed_domain_v2210'),'approved-domain gate missing');
must(web.includes("if((string)$runtime['status']==='paused')"),'paused runtime gate missing');
must(web.includes("in_array((string)$runtime['status'],['completed','cancelled','expired'],true)"),'terminal runtime gate missing');

// Declarative Web skill registry.
for(const skill of ['click','focus','type','clear','select','toggle','scroll','open_link','submit']){
  must(web.includes("'"+skill+"'=>["),`Controlled Web skill missing ${skill}`);
}
for(const delegationAction of ['web_click','web_focus','web_type','web_clear','web_select','web_toggle','web_scroll','web_open_link','web_submit']){
  must(delegation.includes("'"+delegationAction+"'"),`delegation authority missing ${delegationAction}`);
}
must(web.includes("'type'=>[")&&web.includes("'risk_level'=>'medium'"),'field mutation must use medium risk');
must(web.includes("'submit'=>[")&&web.includes("'requires_checkpoint'=>true"),'submit must be an explicit checkpoint skill');

// Bounded observation: semantic controls only, no full DOM/page text transport.
must(background.includes('const selector=\'a[href],button,input,textarea,select'), 'semantic interactive selector registry missing');
must(background.includes('if(elements.length>=80)break;'),'DOM observation hard cap missing');
must(web.includes('VP3_BROWSER_WEB_MAX_ELEMENTS_V2210=80'),'server DOM observation cap missing');
must(background.includes('MutationObserver'),'SPA/mutation awareness missing');
must(background.includes('mutation_epoch'),'mutation epoch missing');
must(background.includes('dom_fingerprint'),'DOM fingerprint missing');
must(background.includes('page_fingerprint'),'page fingerprint missing');

const schemaStart=web.indexOf('CREATE TABLE IF NOT EXISTS browser_web_interactions_v2210');
const schemaEnd=web.indexOf(') ENGINE=InnoDB',schemaStart);
const schema=web.slice(schemaStart,schemaEnd);
must(schemaStart>=0&&schemaEnd>schemaStart,'v22.10 interaction schema missing');
for(const forbidden of ['selector','css_selector','xpath','page_text','page_content','raw_dom','html_content','typed_value','field_value','input_value','element_label']){
  must(!schema.includes(forbidden),`interaction schema must not persist ${forbidden}`);
}
for(const required of ['runtime_session_id','action_key','domain','page_fingerprint','dom_fingerprint','element_fingerprint','semantic_hash','risk_level','requires_checkpoint','value_length','status','permit_hash','verified','result_code']){
  must(schema.includes(required),`interaction schema missing ${required}`);
}
must(api.includes("'raw_dom_persisted'=>false"),'API raw-DOM privacy declaration missing');
must(api.includes("'typed_values_persisted'=>false"),'API typed-value privacy declaration missing');

// Raw values remain Chrome-local. Preview sends only length/fingerprints/semantics.
must(panel.includes('value_length:value.length'),'preview must send value length, not raw value');
must(panel.includes("value:local.value")&&panel.includes("'web_interaction_execute'"),'raw value should go only to the local Chrome executor');
const previewCall=panel.slice(panel.indexOf("webInteractionRequestV2210('preview'"),panel.indexOf("webInteractionRequestV2210('preview'")+1800);
must(!previewCall.includes('value:value')&&!previewCall.includes('value:local.value'),'preview must not send raw typed value to VP3');

// Sensitive-field and consequential-action boundaries are rechecked server-side and Chrome-side.
must(web.includes('function vp3_browser_web_sensitive_v2210'),'server sensitive-field classifier missing');
must(web.includes('Sensitive fields require manual entry'),'server sensitive mutation block missing');
must(background.includes('sensitive_manual_only'),'Chrome sensitive mutation block missing');
must(web.includes('function vp3_browser_web_dangerous_v2210'),'server consequential-action classifier missing');
must(web.includes("$checkpoint=!empty($action['requires_checkpoint'])||$danger"),'dynamic checkpoint escalation missing');
must(background.includes('checkpoint_mismatch'),'Chrome must reject consequential controls without checkpoint authorization');
for(const word of ['purchase','delete','publish','transfer','update account']){
  must(web.toLowerCase().includes(word),`consequential semantic classifier missing ${word}`);
}

// One-time short-lived server permit before every mutation.
must(web.includes('VP3_BROWSER_WEB_PERMIT_SECONDS_V2210=90'),'permit expiry boundary missing');
must(web.includes('permit_hash CHAR(64)'),'hashed execution permit missing');
must(web.includes("status='executing',permit_hash=?"),'atomic permit claim missing');
must(web.includes("hash_equals((string)$row['permit_hash'],hash('sha256',$permitToken))"),'permit verification missing');
must(background.includes("browserWebInteractionApiV2210('claim'"),'Chrome must claim server permit before execution');
must(background.includes("browserWebInteractionApiV2210('complete'"),'Chrome must report verification after execution');
must(background.includes("await failClaim('permit_contract_mismatch')"),'permit contract mismatch must be failed immediately');
must(background.includes("await failClaim('value_changed_after_preview')"),'changed local value must fail the claimed permit immediately');

// Hardcoded primitives only. No user-supplied selectors or arbitrary script execution.
const webExecutor=background.slice(background.indexOf('async function browserWebInteractionExecuteV2210'),background.indexOf('async function browserAgentRuntimeActionV2200'));
must(webExecutor.includes("args.action_key==='click'"),'hardcoded click primitive missing');
must(webExecutor.includes("args.action_key==='type'"),'hardcoded type primitive missing');
must(webExecutor.includes("args.action_key==='select'"),'hardcoded select primitive missing');
must(webExecutor.includes("args.action_key==='toggle'"),'hardcoded toggle primitive missing');
must(webExecutor.includes("args.action_key==='scroll'"),'hardcoded scroll primitive missing');
must(webExecutor.includes("args.action_key==='open_link'"),'hardcoded open-link primitive missing');
must(webExecutor.includes("args.action_key==='submit'"),'hardcoded submit primitive missing');
must(!webExecutor.includes('eval(')&&!webExecutor.includes('new Function')&&!webExecutor.includes('Function('),'Controlled Web executor must not evaluate arbitrary JavaScript');
must(!panel.includes('css_selector')&&!panel.includes('xpath'),'sidepanel must not send arbitrary selectors');

// Stale-element reacquisition is semantic and ambiguity fails closed.
must(background.includes("result_code:matches.length>1?'stale_ambiguous':'stale_missing'"),'stale element fail-closed result missing');
must(background.includes("if(matches.length!==1)"),'stale reacquisition must require exactly one semantic match');
must(background.includes('element_fingerprint'),'semantic element fingerprint missing');

// Verification is action-specific.
for(const code of ['focus_verified','viewport_verified','value_verified','option_verified','checked_verified','click_verified','navigation_verified','submission_verified']){
  must(background.includes(code),`verification result missing ${code}`);
}
must(web.includes("'interaction_verified'")&&web.includes("'interaction_failed'"),'runtime verification events missing');
must(web.includes('last_verified_at=UTC_TIMESTAMP()'),'v22.00 runtime verification timestamp integration missing');

// Same-domain link boundary in v22.10.
must(web.includes("if($targetHost!==$domain)"),'server same-domain link gate missing');
must(background.includes("url.hostname.toLowerCase()!==currentHost"),'Chrome same-domain navigation gate missing');
must(web.includes('v22.10 form submission is limited to the current approved domain.'),'server same-domain form submission gate missing');
must(background.includes('submission_domain_blocked'),'Chrome same-domain form submission gate missing');
must(background.includes('navigation_outside_scope'),'unexpected cross-domain navigation must fail verification');
must(web.includes('Multi-site navigation belongs to the next runtime phase.'),'v22.10 multi-site boundary missing');

// Interaction count is bounded from the approved delegation step budget.
must(web.includes('VP3_BROWSER_WEB_MAX_INTERACTIONS_V2210=24'),'hard interaction cap missing');
must(web.includes("max(4,min(VP3_BROWSER_WEB_MAX_INTERACTIONS_V2210,max(1,(int)($runtime['max_steps']??1))*4))"),'interaction cap must derive from approved step budget');
must(web.includes('vp3_browser_web_remaining_v2210'),'remaining interaction gate missing');

// Runtime UI: scan, semantic selection, local-only values, preview, checkpoint, receipts.
for(const id of ['runtimeWebPanel','runtimeWebScanBtn','runtimeWebElements','runtimeWebComposer','runtimeWebActionSelect','runtimeWebValueInput','runtimeWebPreviewBtn','runtimeWebProposal','runtimeWebCheckpointNotice','runtimeWebRunBtn','runtimeWebConfirmRunBtn','runtimeWebRecent']){
  must(html.includes(`id="${id}"`),`missing v22.10 Runtime UI element ${id}`);
}
must(html.includes('No arbitrary scripts or selectors.'),'Web interaction boundary copy missing');
must(html.includes('stays local in Chrome')||panel.includes('stays local in Chrome'),'local-only value disclosure missing');
must(css.includes('.runtime-web-panel')&&css.includes('.runtime-web-element')&&css.includes('.runtime-web-checkpoint'),'Web interaction UI styling missing');
must(panel.includes('async function scanRuntimeWebControlsV2210'),'scan controller missing');
must(panel.includes('async function previewRuntimeWebInteractionV2210'),'preview controller missing');
must(panel.includes('async function executeRuntimeWebProposalV2210'),'execution controller missing');

// Consequential checkpoints and failed verification reuse the existing notification / Agent Voice surface.
must(web.includes("create_notification("),'Web interaction notification integration missing');
must(web.includes("'browser_web_interaction_approval_required'"),'Web checkpoint notification missing');
must(web.includes("'browser_web_interaction_action_required'"),'Web failure notification missing');
must(web.includes("'Browser Agent needs interaction approval'"),'checkpoint notification copy missing');
const notificationBlock=web.slice(web.indexOf('function vp3_browser_web_notify_v2210'),web.indexOf('function vp3_browser_web_preview_v2210'));
must(!notificationBlock.includes('typed value')&&!notificationBlock.includes('field value:'),'notification path must not expose typed values');

// Agent Workflows / upgrade integration.
must(workflows.includes("require_once __DIR__ . '/includes/browser-web-interaction-v2210.php';"),'Agent Workflows v22.10 include missing');
must(workflows.includes('Controlled Web Interaction Receipts'),'Agent Workflows receipt panel missing');
must(workflows.includes('Fingerprint-only · no typed values'),'Agent Workflows privacy disclosure missing');
must(upgrade.includes("require_once __DIR__ . '/includes/browser-web-interaction-v2210.php';"),'upgrade v22.10 include missing');
must(upgrade.includes('vp3_browser_web_schema_ready_v2210()'),'upgrade v22.10 readiness missing');
must(upgrade.includes('vp3_browser_web_ensure_schema_v2210();'),'upgrade v22.10 install missing');

// No new Chrome-side durable runtime store.
must(!panel.includes('localStorage')&&!panel.includes('sessionStorage'),'sidepanel must not create durable Web interaction state');
must(!background.includes('browser_web_interactions_v2210'),'Chrome must not know server interaction table internals');

console.log('VP3 Browser Companion Controlled Web Interaction Runtime v22.10 contract passed.');
