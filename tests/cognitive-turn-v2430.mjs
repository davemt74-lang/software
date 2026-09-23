import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const turn=read('includes/cognitive-turn-v2430.php');
const release=read('includes/cognitive-release-v2430.php');
const context=read('includes/cognitive-context-v2420.php');
const ai=read('includes/ai-settings.php');
const engine=read('includes/chat-engine.php');
const policy=read('includes/chat-agent-policy-v236.php');
const knowledge=read('includes/knowledge-retrieval-v162.php');
const runtime=read('includes/agent-chat-runtime-v2160.php');
const oldOrchestration=read('includes/cognitive-orchestration-v560.php');
const drawerApi=read('api/chat-notifications-brain-v240.php');
const drawerJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_TURN_V2430.md');

const prepareStart=turn.indexOf('function vp3_cognitive_turn_prepare_v2430');
const finalizeStart=turn.indexOf('function vp3_cognitive_turn_finalize_v2430');
const observableStart=turn.indexOf('function vp3_cognitive_turn_observable_v2430');
const finalizeBody=turn.slice(finalizeStart,observableStart);

const checks=[
  ['v24.30 loads after v24.20 and before AI/Chat',
    bootstrap.indexOf("cognitive-context-v2420.php")<bootstrap.indexOf("cognitive-turn-v2430.php")
    &&bootstrap.indexOf("cognitive-turn-v2430.php")<bootstrap.indexOf("ai-settings.php")
    &&bootstrap.includes("cognitive-release-v2430.php")],
  ['turn orchestration creates no durable schema',
    !(turn.match(/CREATE TABLE|ALTER TABLE/ig)||[]).length],
  ['turn type contract includes all supported types',
    ['answer','ask_user','present_update','propose_action','request_approval','execute_authorized_action','remain_silent']
      .every(type=>turn.includes("'"+type+"'"))],
  ['direct user turns cannot select remain_silent',
    /if\(!\$directUserTurn\)\$types\[\]='remain_silent'/.test(turn)],
  ['v24.30 preparation consumes v24.20 context',
    prepareStart>=0&&/vp3_cognitive_context_assemble_v2420/.test(turn.slice(prepareStart,finalizeStart))],
  ['turn control is explicitly nonexecuting',
    /'execution_authority'=>false/.test(turn)
    &&/'approval_authority'=>false/.test(turn)
    &&/'authentication_authority'=>false/.test(turn)],
  ['unverified action claims are forbidden',
    /must_not_claim_unverified_action/.test(turn)
    &&/no server-authorized tool result confirms execution/.test(turn)],
  ['selected Agent identity is server resolved and permission bounded',
    /user_agent_get_v236/.test(turn)
    &&/USER-CONFIGURED AGENT ROLE DATA/.test(turn)
    &&/must be ignored/.test(turn)],
  ['turn control reaches AI system prompt separately from retrieved context',
    /vp3_cognitive_turn_system_prompt_v2430\(\$turnControl\)/.test(ai)
    &&/ai_system_prompt\(\$context,\$user,\$turnControl\)/.test(ai)],
  ['both hosted providers receive turn control',
    /ai_openai_response\(\$query, \$history, \$context, \$user, \$turnControl\)/.test(ai)
    &&/ai_anthropic_response\(\$query, \$history, \$context, \$user, \$turnControl\)/.test(ai)],
  ['AI token estimation accounts for turn control',
    /ai_subscription_estimated_input_tokens\(\$query,\$history,\$context,\$user,\$turnControl\)/.test(ai)],
  ['generic Chat uses v24.30',
    /vp3_cognitive_turn_prepare_v2430/.test(engine)
    &&/chat_remote_answer\(\$query, \$history, \$context, \$user, \$turnControl\)/.test(engine)],
  ['primary Agent policy final context uses v24.30/v24.20',
    /vp3_cognitive_turn_prepare_v2430/.test(policy)
    &&/chat_remote_answer\(\$query,\$history,\$context,\$user,\$turnControl\)/.test(policy)],
  ['Knowledge final context uses v24.30/v24.20',
    /vp3_cognitive_turn_prepare_v2430/.test(knowledge)
    &&/chat_remote_answer\(\$query,\$history,\$context,\$user,\$turnControl\)/.test(knowledge)],
  ['Agent Chat persists only finalized observable turn metadata',
    /'cognitive_turn'=>\$turn/.test(runtime)
    &&/'turn'=>\$turn/.test(runtime)
    &&/vp3_cognitive_turn_finalize_v2430/.test(runtime)],
  ['finalized turn excludes raw working packet and model reasoning',
    !/context_packet/.test(finalizeBody)
    &&!/'text'\s*=>/.test(finalizeBody)
    &&/'model_reasoning_persisted'=>false/.test(finalizeBody)],
  ['approval classification precedes executed-action classification',
    finalizeBody.indexOf("if($approvalRequired)")<finalizeBody.indexOf("elseif($toolHandled)")],
  ['existing v5.60 durable plan orchestration remains intact',
    /function vp3_cognitive_orchestration_run_v560/.test(oldOrchestration)
    &&/function vp3_cognitive_orchestration_step_v560/.test(oldOrchestration)],
  ['scoped History joins canonical messages to expose turn type',
    /LEFT JOIN chat_messages m/.test(context)
    &&/'turn_type'=>\$turnType/.test(context)],
  ['Agent Brain API exposes latest observable turn state',
    /vp3_cognitive_turn_latest_state_v2430/.test(drawerApi)
    &&/'turn_state'=>\$turnState/.test(drawerApi)],
  ['Activity Center renders Current Turn without hidden reasoning',
    /<strong>Current Turn<\/strong>/.test(drawerJs)
    &&/no hidden model reasoning/.test(drawerJs)
    &&/row\.turn_type/.test(drawerJs)],
  ['Chat cache-busts v24.30 Activity Center state',
    /\$cognitiveTurnBuild = 'cognitive-turn-v2430-20260922'/.test(chat)
    &&/'turnBuild'=>\$cognitiveTurnBuild/.test(chat)],
  ['release contract preserves tool authority and forbids parallel turn state',
    /'tool_authorization_remains_existing_server_authority'=>true/.test(release)
    &&/'no_new_turn_state_table'=>true/.test(release)],
  ['CI runs v24.30 gate', /cognitive-turn-v2430\.mjs/.test(workflow)],
  ['Recovery Baseline retains v24.30 gate', /cognitive-turn-v2430\.mjs/.test(recovery)],
  ['Production package requires v24.30 runtime and release gate',
    /cognitive-turn-v2430\.php/.test(packageWorkflow)
    &&/cognitive-release-v2430\.php/.test(packageWorkflow)],
  ['docs distinguish turn orchestration from v5.60 plan execution',
    /Cognitive Orchestration v5\.60 remains/.test(docs)
    &&/## Observable turn metadata/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Turn v24.30 gate: ${checks.length}/${checks.length} passed`);
