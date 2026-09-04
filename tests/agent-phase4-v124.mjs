import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const actions=read('includes/agent-action-system-v124.php');
const proactive=read('includes/agent-proactive-v123.php');
const api=read('api/agent-proactive-v93.php');
const bootstrap=read('includes/bootstrap.php');
const phase3=read('tests/agent-phase3-v123.mjs');

const groups={
  '16 suppression and cooldown lifecycle':[
    actions.includes('agent_action_v124_suppression'),
    actions.includes("'dismissed-cooldown'"),
    actions.includes("'acted-cooldown'"),
    actions.includes("'repeat-cooldown'"),
    actions.includes('21*86400'),
    actions.includes('agent_action_v124_feedback($uid,(string)($action[\'hash\']??\'\'))'),
    actions.includes('agent_action_v124_source_feedback'),
    !actions.includes('(suggestion_hash=? OR source_kind=?)'),
    actions.includes("$result['suppressed_actions']"),
  ],
  '17 fresh incremental proactive scans':[
    actions.includes("'scan_cursor'"),
    actions.includes('agent_action_v124_scan_since'),
    actions.includes('agent_action_v124_advance_scan'),
    actions.includes('$ts-300'),
    proactive.includes('agent_action_v124_scan_since($user,$surface)'),
    proactive.includes('agent_ecosystem_v118_scan($user,$scanSince)'),
    api.includes('$scanStartedAt=date'),
    api.includes('agent_action_v124_enrich_result($result,$user,$surface,$context,$scanStartedAt)'),
  ],
  '18 executable approval-aware action plans':[
    actions.includes('agent_action_v124_plan'),
    actions.includes("'id'=>'inspect'"),
    actions.includes("'id'=>'dependencies'"),
    actions.includes("'id'=>'prepare'"),
    actions.includes("'id'=>'execute'"),
    actions.includes("'id'=>'verify'"),
    actions.includes('agent_action_v124_risk'),
    actions.includes("'requires_approval'=>$risk['requires_approval']"),
    actions.includes("'plan_id'=>'plan-'"),
  ],
  '19 dependency graph and blocker propagation':[
    actions.includes('agent_action_v124_dependency_graph'),
    actions.includes("'type'=>'task'"),
    actions.includes("'type'=>'action'"),
    actions.includes("$blocking?'blocks':'relates_to'"),
    actions.includes("'blocked_actions'"),
    actions.includes("$edge['type']==='blocks'"),
    actions.includes("'depends_on'=>$dependencies"),
    actions.includes("$result['dependency_graph']"),
  ],
  '20 outcome learning changes future ranking':[
    actions.includes('agent_action_v124_source_feedback'),
    actions.includes('agent_action_v124_outcome_factor'),
    actions.includes("$feedback['acted']"),
    actions.includes("$feedback['dismissed']"),
    actions.includes("$action['base_score']"),
    actions.includes("$action['outcome_factor']"),
    actions.includes("$action['source_outcomes']"),
    actions.includes("$result['outcome_learning']='v124'"),
    actions.includes('agent_action_v124_record_outcome'),
    api.includes('agent_action_v124_record_outcome'),
  ],
  'Phase 3 foundation remains intact':[
    phase3.includes('PHASE3_GROUPS'),
    bootstrap.includes('agent-action-system-v124.php'),
    bootstrap.indexOf('agent-action-system-v124.php')<bootstrap.indexOf('agent-proactive-v123.php'),
    fs.existsSync('includes/agent-memory-lifecycle-v123.php'),
    fs.existsSync('includes/agent-task-lifecycle-v123.php'),
    fs.existsSync('includes/agent-brain-context-v123.php'),
    proactive.includes("'candidate_pool'=>'evidence-first'"),
    api.includes("'runtime'=>'phase4-v124'"),
    read('team-chat-v109.css').includes('.sf-online-rail-v109{display:none!important}'),
  ],
};

let pass=0,total=0;const failed=[];
for(const [name,checks] of Object.entries(groups)){
  const ok=checks.every(Boolean);pass+=ok?1:0;total++;
  console.log(`${ok?'PASS':'FAIL'} ${name} (${checks.filter(Boolean).length}/${checks.length})`);
  if(!ok)failed.push(name);
}
console.log(`PHASE4_GROUPS=${pass}/${total}`);
if(failed.length)throw new Error(`Failed: ${failed.join(', ')}`);
