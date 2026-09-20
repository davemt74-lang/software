import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const must=(v,m)=>assert.equal(Boolean(v),true,m);

const manifest=JSON.parse(read('browser-companion/manifest.json'));
const background=read('browser-companion/background.js');
const panel=read('browser-companion/sidepanel.js');
const html=read('browser-companion/sidepanel.html');
const css=read('browser-companion/sidepanel.css');
const runtime=read('includes/browser-agent-runtime-v2200.php');
const api=read('api/extension-agent-runtime-v2200.php');
const delegation=read('includes/browser-delegation-v2190.php');
const workflows=read('agent-workflows.php');
const upgrade=read('upgrade.php');

must(manifest.version==='22.0.0','v22.00 manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '22.0.0';"),'v22.00 request version missing');
must(background.includes("authorizedFetch('/api/extension-agent-runtime-v2200.php'"),'Browser Agent Runtime API adapter missing');
must(background.includes("case 'runtime_action': return browserAgentRuntimeActionV2200"),'Browser Agent Runtime message route missing');
must(background.includes("case 'runtime_navigate': return browserAgentRuntimeNavigateV2200"),'Browser Agent Runtime navigation route missing');

// Runtime is layered over the approved v21.90 delegation rather than replacing its authority model.
must(runtime.includes("require_once __DIR__.'/browser-delegation-v2190.php';"),'runtime must retain v21.90 authority boundary');
must(runtime.includes("'authority_source'=>'v21.90_delegation'")||api.includes("'authority_source'=>'v21.90_delegation'"),'runtime authority source declaration missing');
must(runtime.includes("'can_expand'=>false"),'runtime must not expand delegation authority');
must(runtime.includes('vp3_browser_delegation_next_v2190('),'runtime executor must reuse v21.90 delegation execution');
must(runtime.includes('vp3_browser_delegation_verify_navigation_v2190('),'runtime must reuse verified navigation boundary');
must(runtime.includes('vp3_browser_delegation_complete_checkpoint_v2190('),'runtime must preserve explicit checkpoints');

// Canonical server-side runtime session, timeline, observations and tab references.
for(const table of ['browser_agent_runtime_sessions_v2200','browser_agent_runtime_events_v2200','browser_agent_runtime_observations_v2200','browser_agent_runtime_tabs_v2200']){
  must(runtime.includes('CREATE TABLE IF NOT EXISTS '+table),`missing runtime table ${table}`);
}
const sessionSchema=runtime.slice(runtime.indexOf('CREATE TABLE IF NOT EXISTS browser_agent_runtime_sessions_v2200'),runtime.indexOf(') ENGINE=InnoDB',runtime.indexOf('CREATE TABLE IF NOT EXISTS browser_agent_runtime_sessions_v2200')));
const observationSchema=runtime.slice(runtime.indexOf('CREATE TABLE IF NOT EXISTS browser_agent_runtime_observations_v2200'),runtime.indexOf(') ENGINE=InnoDB',runtime.indexOf('CREATE TABLE IF NOT EXISTS browser_agent_runtime_observations_v2200')));
const tabSchema=runtime.slice(runtime.indexOf('CREATE TABLE IF NOT EXISTS browser_agent_runtime_tabs_v2200'),runtime.indexOf(') ENGINE=InnoDB',runtime.indexOf('CREATE TABLE IF NOT EXISTS browser_agent_runtime_tabs_v2200')));
for(const schema of [sessionSchema,observationSchema,tabSchema]){
  for(const forbidden of ['page_url','source_url','canonical_url','page_title','selected_text','page_text','excerpt','page_content']){
    must(!schema.includes(forbidden),`runtime durable schema must not store ${forbidden}`);
  }
}
for(const required of ['delegation_id','workflow_run_id','status','current_action_id','current_skill_key','plan_revision','replan_count','observation_count','recovery_code','expires_at']){
  must(sessionSchema.includes(required),`runtime session missing ${required}`);
}
must(tabSchema.includes('client_tab_key')&&!tabSchema.includes('url')&&!tabSchema.includes('title'),'cross-tab runtime must store opaque tab references, never URL/title history');

// Reusable skills registry with declarative permission/risk/verification contracts.
for(const skill of ['inspect_source','follow_source','unfollow_source','open_research','open_profile','open_contact','draft_task','draft_knowledge','share_team']){
  must(runtime.includes("'"+skill+"'=>["),`runtime skill missing ${skill}`);
}
for(const key of ['capability','risk_level','mode','verification_mode','requires_checkpoint','target_types']){
  must(runtime.includes("'"+key+"'"),`skill registry missing ${key}`);
}
must(runtime.includes('vp3_browser_runtime_skill_check_v2200'),'skill-level permission gate missing');
must(runtime.includes("'capability_required'"),'authority escalation state missing');
must(runtime.includes('vp3_browser_memory_target_v2170('),'every runtime target must be reauthorized');

// Observe → act → verify with task-scoped expiring observations.
must(runtime.includes('vp3_browser_runtime_observe_v2200'),'runtime observe phase missing');
must(runtime.includes('expires_at DATETIME NOT NULL'),'task-scoped observation expiry missing');
must(runtime.includes("vp3_browser_runtime_event_v2200($pdo,$runtime,'skill_started'"),'act timeline event missing');
must(runtime.includes("vp3_browser_runtime_event_v2200($pdo,$runtime,'skill_verified'"),'verify timeline event missing');
must(runtime.includes("last_verified_at=UTC_TIMESTAMP()"),'verification timestamp missing');
must(api.includes("$action==='tick'")&&api.includes("$action==='observe'")&&api.includes("$action==='verify_navigation'"),'runtime observe/act/verify API missing');

// Bounded dynamic replanning cannot switch Source authority or exceed the original skill/action/risk envelope.
must(runtime.includes('VP3_BROWSER_RUNTIME_MAX_REPLANS_V2200=3'),'bounded replan limit missing');
must(runtime.includes("if((int)$runtime['replan_count']>=(int)$runtime['max_replans'])"),'replan count gate missing');
must(runtime.includes("Runtime replanning cannot change the delegation Source authority."),'Source authority replan gate missing');
must(runtime.includes("'allowed_actions'=>vp3_browser_delegation_json_array_v2190($runtime['allowed_actions_json'])"),'replan must reuse approved action scope');
must(runtime.includes("'risk_budget'=>(string)$runtime['risk_budget']"),'replan must reuse approved risk budget');
must(runtime.includes('Browser Runtime replanned remaining work inside the original delegation authority.'),'canonical replan audit event missing');

// Recovery controls are explicit and resumable.
for(const action of ["$action==='retry'","$action==='skip'","$action==='replan'"]){must(api.includes(action),`runtime recovery API missing ${action}`);}
for(const action of ["'pause'","'resume'","'cancel'"]){must(runtime.includes(action),`runtime lifecycle action missing ${action}`);}
must(runtime.includes("'recovering'"),'runtime recovering state missing');
must(panel.includes("runtimeRequestV2200('replan'"),'Browser Runtime replan control missing');
must(panel.includes("runtimeRequestV2200('skip'"),'Browser Runtime skip control missing');

// Cross-tab navigation is VP3-only in the background and registered by target reference.
must(background.includes('async function browserAgentRuntimeNavigateV2200'),'runtime cross-tab navigator missing');
must(background.includes('url.origin!==base.origin'),'runtime navigation must remain limited to connected VP3 origin');
must(panel.includes("runtimeRequestV2200('tab_seen'"),'runtime tab registration missing');
must(panel.includes("runtimeRequestV2200('verify_navigation'"),'runtime tab navigation verification missing');

// Checkpoints/failures/completion reuse canonical notifications, which preserves Agent Voice behavior when enabled.
must(runtime.includes("create_notification($uid,'browser_runtime_approval_required_"),'runtime checkpoint notification missing');
must(runtime.includes("create_notification($uid,'browser_runtime_failed_"),'runtime failure notification missing');
must(runtime.includes("create_notification($uid,'browser_runtime_workflow_completed'"),'runtime completion notification missing');
must(runtime.includes("require_once __DIR__.'/extension-notifications-v2140.php';"),'runtime must reuse proactive notification/Agent Voice surface');

// Unified Agent Workflow visibility.
must(workflows.includes("require_once __DIR__ . '/includes/browser-agent-runtime-v2200.php';"),'Agent Workflows runtime include missing');
must(workflows.includes('Browser Agent Runtime'),'Agent Workflows runtime panel missing');
must(workflows.includes('vp3_browser_runtime_for_workflow_v2200'),'Agent Workflow/runtime resolver missing');

// Runtime UI remains inside the Delegate/Runtime surface, with live timeline and recovery state.
for(const id of ['runtimePanel','runtimeSessionBadge','runtimeCurrentSkill','runtimeRecovery','runtimeTimeline','runtimeTabs','runtimeReplanBtn','runtimeSkipBtn']){
  must(html.includes(`id="${id}"`),`missing Browser Runtime UI element ${id}`);
}
must(html.includes('id="delegationTab"')&&html.includes('>Runtime</button>'),'Delegate tab should evolve into Runtime surface');
must(css.includes('.runtime-timeline')&&css.includes('.runtime-status-grid'),'Runtime UI styling missing');
must(panel.includes('async function runRuntimeV2200'),'Browser Runtime runner missing');
must(panel.includes('VP3_RUNTIME_CLIENT_STEP_LIMIT_V2200'),'runtime client runaway guard missing');

// No Chrome-side durable runtime database or passive memory.
must(!panel.includes('localStorage')&&!panel.includes('sessionStorage'),'Browser Runtime must not create client durable state');
must(!background.includes('browser_agent_runtime_sessions_v2200'),'Chrome must not know server runtime schema internals');

// Upgrade integration.
must(upgrade.includes("require_once __DIR__ . '/includes/browser-agent-runtime-v2200.php';"),'upgrade runtime include missing');
must(upgrade.includes('vp3_browser_runtime_schema_ready_v2200()'),'upgrade runtime readiness missing');
must(upgrade.includes('vp3_browser_runtime_ensure_schema_v2200();'),'upgrade runtime install missing');

console.log('VP3 Browser Companion Browser Agent Runtime v22.00 contract passed.');
