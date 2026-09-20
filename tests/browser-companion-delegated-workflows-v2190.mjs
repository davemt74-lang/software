import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const delegation=read('includes/browser-delegation-v2190.php');
const api=read('api/extension-delegation-v2190.php');
const upgrade=read('upgrade.php');

must(manifest.version==='21.9.0','v21.90 manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '21.9.0';"),'v21.90 request version missing');
must(background.includes("authorizedFetch('/api/extension-delegation-v2190.php'"),'delegation API adapter missing');
must(background.includes("case 'delegation_action': return browserDelegationActionV2190"),'delegation message route missing');
must(background.includes("case 'delegation_navigate': return browserDelegationNavigateV2190"),'bounded navigation route missing');

// Delegation envelope persists task scope and VP3 references, never passive browsing history/page content.
const schemaStart=delegation.indexOf('CREATE TABLE IF NOT EXISTS browser_delegations_v2190');
const schemaEnd=delegation.indexOf(') ENGINE=InnoDB',schemaStart);
const schema=delegation.slice(schemaStart,schemaEnd);
must(schemaStart>=0&&schemaEnd>schemaStart,'delegation schema missing');
for(const forbidden of ['page_url','source_url','canonical_url','page_title','selected_text','page_text','excerpt','page_content']){
  must(!schema.includes(forbidden),`delegation schema must not store ${forbidden}`);
}
for(const required of ['agent_namespace','workflow_run_id','source_type','source_id','plan_hash','allowed_domains_json','allowed_actions_json','max_steps','risk_budget','status','auto_step_count','expires_at']){
  must(schema.includes(required),`delegation schema missing ${required}`);
}

// Natural-language intent is canonical Agent Workflow state, not a browser-history record.
must(delegation.includes("'browser_delegation','browser_delegation'"),'canonical Agent Workflow origin missing');
must(delegation.includes("'browser','browser.delegation'"),'browser execution target/capability missing');
must(delegation.includes("goal,decision_summary"),'user-authored delegation intent must live in canonical workflow goal');
must(delegation.includes("User approved a bounded Browser Companion delegation plan."),'explicit delegation approval event missing');

// Server derives the plan from the live authorized page and v21.80 candidates.
must(api.includes('vp3_browser_context_validate_v2130($raw)'),'bounded context validation missing');
must(api.includes('vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session[\'capabilities\']??[]))'),'live relationship reauthorization missing');
must(delegation.includes('vp3_browser_execution_candidates_v2180('),'delegation must reuse v21.80 authorized action candidates');
must(delegation.includes("throw new RuntimeException('This page must be connected to an authorized VP3 Source"),'delegation must require durable VP3 Source anchoring');
must(delegation.includes("hash_equals((string)$plan['plan_hash'],$expectedHash)"),'create must reject changed plans');

// Bounded authority and consequential checkpoints.
must(delegation.includes('VP3_BROWSER_DELEGATION_MAX_STEPS_V2190=8'),'hard max-step boundary missing');
must(delegation.includes('max(15,min(1440'),'delegation expiry boundary missing');
must(delegation.includes("'low_risk_auto_only'=>true"),'low-risk autonomous boundary missing');
must(delegation.includes("'consequential_actions_require_checkpoint'=>true"),'consequential checkpoint boundary missing');
must(delegation.includes("in_array($action,['draft_task','draft_knowledge','share_team'],true)"),'write-oriented actions must be checkpointed');
must(delegation.includes("status='checkpoint'"),'checkpoint lifecycle state missing');

// Every durable target is reauthorized before execution.
must(delegation.includes('vp3_browser_memory_target_v2170('),'step execution must reauthorize durable VP3 targets');
must(delegation.includes('vp3_browser_source_follow_source_v2050('),'reversible Source state change must reuse canonical Source Feed service');
must(delegation.includes("if(!array_key_exists('following',$result)||((bool)$result['following'])!==$follow)"),'Source mutation verification missing');

// Navigation is generated from an authorized VP3 object and verified against the active tab.
must(delegation.includes("'state'=>'navigate'"),'navigation handoff missing');
must(delegation.includes('vp3_browser_delegation_url_matches_v2190'),'navigation verification helper missing');
must(api.includes("$action==='verify_navigation'"),'navigation verification API missing');
must(panel.includes("await msg('delegation_navigate',{url:absolute(result.navigation.url)})"),'Chrome delegated navigation missing');
must(panel.includes("await delegationRequestV2190('verify_navigation'"),'Chrome post-navigation verification missing');

// Pause/resume/cancel/recovery/retry.
for(const action of ["$action==='pause'","$action==='resume'","$action==='cancel'"]){
  must(delegation.includes(action),`delegation lifecycle action missing ${action}`);
}
must(api.includes("$action==='retry'"),'bounded retry API missing');
must(delegation.includes("if((int)$step['attempt_count']>=3)"),'retry cap missing');
must(html.includes('id="delegationRecent"'),'recoverable recent delegation list missing');
must(panel.includes("delegationRequestV2190('detail'"),'delegation recovery detail load missing');

// Explicit user checkpoint completion.
must(api.includes("$action==='complete_checkpoint'"),'checkpoint completion API missing');
must(delegation.includes("User confirmed the delegated checkpoint was completed."),'checkpoint audit message missing');
must(panel.includes("delegationRequestV2190('complete_checkpoint'"),'checkpoint completion control missing');

// Browser workspace controls.
for(const id of ['delegationTab','delegationView','delegationInstruction','delegationPreviewBtn','delegationStartBtn','delegationPlan','delegationActive','delegationSteps','delegationPauseBtn','delegationResumeBtn','delegationCancelBtn','delegationRunBtn']){
  must(html.includes(`id="${id}"`),`missing Delegation UI element ${id}`);
}
must(css.includes('.delegation-boundary')&&css.includes('.delegation-step'),'Delegation UI styling missing');
must(panel.includes('async function runDelegationV2190'),'delegated runner missing');
must(panel.includes('for(let guard=0;guard<VP3_DELEGATION_CLIENT_STEP_LIMIT_V2190;guard++)'),'client runaway guard missing');
must(panel.includes("['now','agent','delegation','execution','memory'].includes(activeView)&&!c.has('agent.message')"),'Delegation view must leave on capability revocation');

// No Chrome-side durable task ledger.
must(!panel.includes('localStorage')&&!panel.includes('sessionStorage'),'sidepanel must not create durable browser task storage');
must(!background.includes('browser_delegations_v2190'),'Chrome must not know delegation database internals');

// Upgrade integration.
must(upgrade.includes("require_once __DIR__ . '/includes/browser-delegation-v2190.php';"),'upgrade include missing');
must(upgrade.includes('vp3_browser_delegation_schema_ready_v2190()'),'upgrade readiness missing');
must(upgrade.includes('vp3_browser_delegation_ensure_schema_v2190();'),'upgrade install missing');

console.log('VP3 Browser Companion Delegated Browser Workflows v21.90 contract passed.');
