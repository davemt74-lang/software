import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const continuity=read('includes/cognitive-continuity-v2440.php');
const release=read('includes/cognitive-release-v2440.php');
const context=read('includes/cognitive-context-v2420.php');
const turn=read('includes/cognitive-turn-v2430.php');
const live=read('includes/cognitive-live-session-v2370.php');
const runtime=read('includes/agent-chat-runtime-v2160.php');
const workflows=read('includes/agent-workflow-runs-v1400.php');
const goals=read('includes/agent-goal-strategy-v1710.php');
const orchestration=read('includes/cognitive-orchestration-v560.php');
const meetings=read('includes/video-meetings-commitment-command-v18230.php');
const browser=read('includes/browser-transaction-continuity-v2260.php');
const drawerApi=read('api/chat-notifications-brain-v240.php');
const drawerJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_CONTINUITY_V2440.md');

const checks=[
  ['v24.40 loads between Working Context and Turn Orchestration',
    bootstrap.indexOf("cognitive-context-v2420.php")<bootstrap.indexOf("cognitive-continuity-v2440.php")
    &&bootstrap.indexOf("cognitive-continuity-v2440.php")<bootstrap.indexOf("cognitive-turn-v2430.php")
    &&bootstrap.includes("cognitive-release-v2440.php")],
  ['continuity creates no second durable store',
    !(continuity.match(/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length],
  ['existing goal authority is retained',
    /FROM agent_goals/.test(goals)&&/'goal_store'=>'agent_goals'/.test(continuity)],
  ['existing workflow authority is retained and Agent-scoped',
    /agent_id BIGINT UNSIGNED NULL/.test(workflows)
    &&/\$where=\$agentId>0\?'agent_id=\?':'agent_id IS NULL'/.test(continuity)],
  ['accepted Cognitive Plans remain namespace scoped',
    /agent_namespace/.test(orchestration)
    &&/WHERE owner_user_id=\? AND agent_namespace=\?/.test(continuity)],
  ['owner-global Goal Strategy is System-Agent only',
    /if\(\$namespace!=='system'.*agent_goal_strategy_schema_ready_v1710/s.test(continuity)],
  ['owner-global meeting commitments are System-Agent only',
    /function vp3_cognitive_continuity_meetings_v2440[\s\S]*\$namespace!=='system'/.test(continuity)
    &&/video_meeting_commitment_command_state_v18230/.test(continuity)
    &&/function video_meeting_commitment_command_state_v18230/.test(meetings)],
  ['owner-global Browser followthrough is System-Agent only',
    /function vp3_cognitive_continuity_browser_v2440[\s\S]*\$namespace!=='system'/.test(continuity)
    &&/browser_transaction_continuities_v2260/.test(browser)],
  ['Browser continuity retrieval is bounded and avoids detail-heavy list helper',
    /tracking_status='active'[\s\S]*LIMIT 8/.test(continuity)
    &&!/vp3_browser_continuity_list_v2260\(/.test(continuity)
    &&/GROUP BY continuity_id/.test(continuity)],
  ['unified continuity states cover approval user blocker and resume states',
    ['needs_approval','needs_user','blocked','repair_needed','working','ready','scheduled','tracking','paused']
      .every(state=>continuity.includes("'"+state+"'"))],
  ['continuity has a bounded focus projection',
    /VP3_COGNITIVE_CONTINUITY_MAX_ITEMS_V2440=16/.test(continuity)
    &&/function vp3_cognitive_continuity_resume_v2440/.test(continuity)],
  ['v24.20 Working Context includes one continuity section',
    /'live_session','continuity','conversation'/.test(context)
    &&/'continuity'=>1/.test(context)
    &&/vp3_cognitive_continuity_context_item_v2440/.test(context)],
  ['continuity remains data-only inside Working Context',
    /vp3_cognitive_context_item_v2420\([\s\S]*'continuity'/.test(continuity)
    &&/'instruction_authority'=>false/.test(context)],
  ['continue uses durable continuity when live session alone is insufficient',
    /vp3_cognitive_continuity_resume_v2440/.test(turn)
    &&/\$continuity\['resumable'\]/.test(turn)],
  ['approval and user-waiting continuity shape the next turn',
    /requires_approval'\]\)\)return 'request_approval'/.test(turn)
    &&/requires_user'\]\)\)return 'ask_user'/.test(turn)],
  ['v24.30 finalization preserves approval and user-waiting turn states',
    /\$preferred==='request_approval'/.test(turn)
    &&/\$preferred==='ask_user'/.test(turn)],
  ['observable turn metadata carries continuity refs without hidden reasoning',
    /'continuity_ref'=>/.test(turn)
    &&/'continuity_state'=>/.test(turn)
    &&/'model_reasoning_persisted'=>false/.test(turn)],
  ['live-session reference helpers accept canonical refs',
    /\$context\['project_ref'\]/.test(live)
    &&/\$context\['task_ref'\]/.test(live)
    &&/\$context\['goal_ref'\]/.test(live)],
  ['live-session action updates canonical project task and goal refs',
    /current_project_ref=\?/.test(live)
    &&/current_task_ref=\?/.test(live)
    &&/current_goal_ref=\?/.test(live)],
  ['Agent Chat flows finalized continuity refs back to live session',
    /'goal_ref'=>\(string\)\(\$turn\['goal_ref'\]/.test(runtime)
    &&/'task_ref'=>\(string\)\(\$turn\['task_ref'\]/.test(runtime)
    &&/'project_ref'=>\(string\)\(\$turn\['project_ref'\]/.test(runtime)],
  ['Agent Brain API uses the same v24.40 projection',
    /vp3_cognitive_continuity_activity_projection_v2440/.test(drawerApi)
    &&/'continuity'=>\$continuity/.test(drawerApi)],
  ['Agent Brain renders bounded Open Work without model reasoning',
    /<strong>Open Work<\/strong>/.test(drawerJs)
    &&/v24\.40 resumes existing goals/.test(drawerJs)
    &&/continuityItems/.test(drawerJs)],
  ['Chat cache-busts v24.40 Activity Center state',
    /\$cognitiveContinuityBuild = 'cognitive-continuity-v2440-20260922'/.test(chat)
    &&/'continuityBuild'=>\$cognitiveContinuityBuild/.test(chat)],
  ['release contract forbids parallel goal task and workflow stores',
    /'second_goal_store'=>false/.test(release)
    &&/'second_task_store'=>false/.test(release)
    &&/'second_workflow_queue'=>false/.test(release)],
  ['release contract keeps execution and approval authority outside continuity',
    /'continuity_grants_execution_authority'=>false/.test(release)
    &&/'continuity_grants_approval_authority'=>false/.test(release)],
  ['CI runs v24.40 gate', /cognitive-continuity-v2440\.mjs/.test(workflow)],
  ['Recovery Baseline retains v24.40 gate', /cognitive-continuity-v2440\.mjs/.test(recovery)],
  ['Production package requires v24.40 runtime and release gate',
    /cognitive-continuity-v2440\.php/.test(packageWorkflow)
    &&/cognitive-release-v2440\.php/.test(packageWorkflow)],
  ['docs explain projection-only architecture and performance bounds',
    /continuity projection/.test(docs)
    &&/## Performance bounds/.test(docs)
    &&/No duplicate task/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Continuity v24.40 gate: ${checks.length}/${checks.length} passed`);
