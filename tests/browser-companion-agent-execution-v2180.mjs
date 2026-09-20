import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const execution=read('includes/browser-execution-v2180.php');
const api=read('api/extension-execution-v2180.php');
const upgrade=read('upgrade.php');

const executionVersion=String(manifest.version||'').split('.').map(Number);
must(executionVersion.length===3&&(executionVersion[0]>21||(executionVersion[0]===21&&executionVersion[1]>=8)),'v21.80+ manifest version missing');
must(/const VP3_EXTENSION_VERSION = '21\.(?:[8-9]|[1-9]\d+)\.\d+';/.test(background),'v21.80+ request version missing');
must(background.includes("authorizedFetch('/api/extension-execution-v2180.php'"),'Browser Execution API adapter missing');
must(background.includes("case 'execution_action': return browserExecutionActionV2180"),'Browser Execution message route missing');

// Reference-only durable state: execution ledger must never become browsing history.
const schemaStart=execution.indexOf('CREATE TABLE IF NOT EXISTS browser_execution_tickets_v2180');
const schemaEnd=execution.indexOf(') ENGINE=InnoDB',schemaStart);
const schema=execution.slice(schemaStart,schemaEnd);
must(schemaStart>=0&&schemaEnd>schemaStart,'Browser Execution schema missing');
for(const forbidden of ['page_url','source_url','canonical_url','page_title','selected_text','page_text','body','content','summary','domain','excerpt','prompt']){
  must(!schema.includes(forbidden),`execution ledger must not store ${forbidden}`);
}
for(const required of ['owner_user_id','agent_namespace','action_key','target_type','target_id','target_scope','status','risk_level','requires_confirmation','verification_state','attempt_count','confirmed_at','executed_at','verified_at','expires_at']){
  must(schema.includes(required),`execution ledger missing ${required}`);
}

// Explicit propose -> confirm -> execute -> verify lifecycle.
for(const action of ["$action==='candidates'","$action==='propose'","$action==='confirm'","$action==='execute'","$action==='complete'","$action==='cancel'"]){
  must(api.includes(action),`missing Browser Execution API action ${action}`);
}
must(api.includes("'lifecycle'=>'propose_confirm_execute_verify'"),'execution lifecycle declaration missing');
must(execution.includes("if((string)$ticket['status']!=='confirmed')throw new RuntimeException('Confirm this Browser action before execution.')"),'execution must fail closed without confirmation');
must(execution.includes("vp3_browser_execution_event_v2180($pdo,$ticket,'confirmed','user')"),'explicit user confirmation audit event missing');
must(execution.includes("verification_state='user_confirmed'"),'manual handoff completion must be explicitly user-confirmed');
must(execution.includes("if($following!==$follow)throw new RuntimeException('VP3 could not verify the requested Source follow state.')"),'server-side follow verification missing');

// Re-authorize current page at proposal and execution time.
must(api.includes('vp3_browser_context_validate_v2130($raw)'),'bounded page-context validation missing');
must(api.includes('vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session[\'capabilities\']??[]))'),'live current-page relationship authorization missing');
must(api.includes("vp3_browser_execution_find_candidate_v2180($candidates,$actionKey,$targetType,$targetId)"),'proposal must match a server-derived candidate');
must(api.includes("throw new RuntimeException('The current page or VP3 authorization changed. Prepare this action again.')"),'execution must reject stale page/authorization state');

// Real execution uses canonical Source Feed service; higher-risk writes remain handoffs.
must(execution.includes('vp3_browser_source_follow_source_v2050('),'reversible Source follow execution must reuse canonical service');
must(execution.includes("'kind'=>'agent_prompt'"),'Agent handoff execution mode missing');
must(execution.includes("'kind'=>'manual_flow'"),'manual final-action handoff mode missing');
must(execution.includes("'kind'=>'open'"),'safe navigation execution mode missing');
must(execution.includes('Do not create or assign anything until I explicitly approve it.'),'task handoff must preserve downstream approval');
must(execution.includes('Do not save it until I explicitly approve it.'),'Knowledge handoff must preserve downstream approval');

// Cross-time recovery hooks into existing Cognitive Orchestration without copying plan content.
must(execution.includes('cognitive_plan_runs_v560'),'execution continuity must project canonical Cognitive Orchestration runs');
must(execution.includes('plan_public_id,status,verification_state,current_step_key,completed_steps,total_steps'),'continuity projection must remain reference/status only');

// Durable-token and live Agent authorization.
must(api.includes('vp3_extension_session_authenticate_v2001($pdo)'),'durable-token auth missing');
must(api.includes("vp3_extension_session_has_capability_v2001($session,'agent.message')"),'live Agent capability gate missing');
must(api.includes("has_permission('chat.access',$user)"),'live Chat permission gate missing');
must(api.includes('vp3_agent_chat_runtime_default_agent_v2160($pdo,$user)'),'default Agent continuity missing');
must(api.includes('vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId)'),'Agent namespace isolation missing');

// Browser UI is an explicit execution center, not background autonomy.
for(const id of ['executionTab','executionView','executionStatus','executionPageLabel','executionCandidates','executionTickets','executionContinuity']){
  must(html.includes(`id="${id}"`),`missing Execution UI element ${id}`);
}
must(css.includes('.execution-boundary')&&css.includes('.execution-row'),'Execution Center styling missing');
must(panel.includes("function executionRequestV2180"),'Execution client adapter missing');
must(panel.includes("await executionRequestV2180('propose'"),'Prepare action missing');
must(panel.includes("await executionRequestV2180('confirm'"),'Confirm action missing');
must(panel.includes("await executionRequestV2180('execute'"),'Execute action missing');
must(panel.includes("await executionRequestV2180('complete'"),'Mark-complete action missing');
must(panel.includes("await executionRequestV2180('cancel'"),'Cancel action missing');
must(panel.includes("setView('agent');ui.agentMessageInput.value=String(handoff.prompt||'')"),'Agent handoff must prefill but not auto-send');
must(!panel.includes("sendAgentMessageV2160().catch(fail);/*v2180-auto*/"),'Execution must never auto-send Agent handoffs');
must(!panel.includes('localStorage')&&!panel.includes('sessionStorage'),'Execution Center must not create a Chrome-side durable ledger');

// Page changes refresh candidates and invalidate stale execution context.
must(panel.includes("['now','agent','delegation','execution','memory','this_page','live','alerts','search']")||panel.includes("['now','agent','execution','memory','this_page','live','alerts','search']"),'page watcher must include Execution');
must(panel.includes("if(activeView==='execution')await loadExecutionV2180();"),'page changes must refresh Execution candidates');
must(panel.includes("['now','agent','delegation','execution','memory'].includes(activeView)&&!c.has('agent.message')")||panel.includes("['now','agent','execution','memory'].includes(activeView)&&!c.has('agent.message')"),'Execution view must leave on Agent capability revocation');

// Upgrade integration.
must(upgrade.includes("require_once __DIR__ . '/includes/browser-execution-v2180.php';"),'upgrade include missing');
must(upgrade.includes('vp3_browser_execution_schema_ready_v2180()'),'upgrade readiness missing');
must(upgrade.includes('vp3_browser_execution_ensure_schema_v2180();'),'upgrade install missing');

console.log('VP3 Browser Companion Agent Execution & Follow-Through v21.80 contract passed.');
