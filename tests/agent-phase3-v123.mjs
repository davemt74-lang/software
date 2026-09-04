import fs from 'node:fs';

const read=p=>fs.readFileSync(p,'utf8');
const memory=read('includes/agent-memory-lifecycle-v123.php');
const tasks=read('includes/agent-task-lifecycle-v123.php');
const context=read('includes/agent-brain-context-v142.php');
const proactive=read('includes/agent-proactive-v123.php');
const rescore=read('includes/agent-proactive-rescore-v123.php');
const api=read('api/agent-proactive-v93.php');
const bootstrap=read('includes/bootstrap.php');
const phase2=read('tests/conversation-phase2-v122.mjs');

const groups={
  '11 confidence-aware memory ranking':[
    memory.includes('agent_memory_v123_effective_confidence'),
    memory.includes('agent_memory_v123_type_half_life'),
    memory.includes('pow(0.5,$ageDays/$half)'),
    memory.includes('occurrence_count'),
    memory.includes('agent_memory_v123_rank'),
    memory.includes('agent_memory_v123_write_row($id,$meta,null,null)'),
    !memory.includes('agent_memory_v123_write_row($id,$meta,$effective,null)'),
    context.includes('agent_memory_v123_rank($row,$query,$context)'),
    context.includes('Confidence-ranked Agent Brain memory'),
    context.includes('lexical + semantic + confidence + recency + reinforcement'),
  ],
  '12 memory reconciliation and lifecycle':[
    memory.includes("$meta['lifecycle']='superseded'"),
    memory.includes("$meta['superseded_by']"),
    memory.includes("$meta['lifecycle']='stale'"),
    memory.includes('agent_memory_v123_reconcile_user'),
    memory.includes('agent_memory_v123_task_status_from_text'),
    memory.includes('status_evidence_at'),
    memory.includes('agent_memory_v123_overlap'),
    proactive.includes('agent_memory_v123_reconcile_user($user)'),
  ],
  '13 first-class task and commitment lifecycle':[
    tasks.includes("['open','in_progress','waiting','completed','cancelled']"),
    tasks.includes('agent_task_v123_update'),
    tasks.includes("$meta['status_history']"),
    tasks.includes("$meta['closed_at']"),
    memory.includes("memory_type IN ('commitment','task')"),
    memory.includes("'task_key'"),
    context.includes('Open task and commitment lifecycle'),
    api.includes("$action==='task_update'"),
    api.includes("$action==='tasks'"),
  ],
  '14 dynamic proactive scoring':[
    proactive.includes('agent_proactive_v123_score'),
    proactive.includes('agent_proactive_v123_evidence_candidates'),
    proactive.includes("'candidate_pool'=>'evidence-first'"),
    proactive.includes('agent_ecosystem_v118_scan'),
    proactive.includes("'confidence'=>"),
    proactive.includes("'urgency'=>"),
    proactive.includes("'recency'=>"),
    proactive.includes("'context_fit'=>"),
    proactive.includes("'actionability'=>"),
    proactive.includes("'feedback'=>"),
    proactive.includes("'interruptibility'=>"),
    proactive.includes("'priority'=>(int)round($score*200)"),
    !proactive.includes("$candidate['priority']"),
    rescore.includes('agent_proactive_v123_rescore_result'),
  ],
  '15 events are separate from recommended actions':[
    proactive.includes("'type'=>'event'"),
    proactive.includes("'event_kind'=>"),
    proactive.includes("'type'=>'recommended_action'"),
    proactive.includes("'event_id'=>(string)$event['id']"),
    proactive.includes("'action_id'=>'action-'"),
    proactive.includes("'events'=>$events,'suggestions'=>$actions"),
    rescore.includes("$result['events']"),
    rescore.includes("$result['suggestions']"),
    rescore.includes("$result['event_action_separation']=true"),
    api.includes("'runtime'=>'phase3-v123'") || api.includes("'runtime'=>'phase4-v124'"),
  ],
  'Phase 2 foundation remains intact':[
    fs.existsSync('conversation-voice-v122.js'),
    fs.existsSync('voice-lease-v122.js'),
    fs.existsSync('editor-voice-barge-v117.js'),
    phase2.includes('PHASE2_GROUPS'),
    bootstrap.includes("agent-brain-v122.php"),
    bootstrap.includes("agent-memory-lifecycle-v123.php"),
    bootstrap.includes("agent-brain-context-v142.php") && !bootstrap.includes("agent-brain-context-v123.php") && !bootstrap.includes("agent-brain-context-v122.php"),
    read('team-chat-v109.css').includes('.sf-online-rail-v109{display:none!important}'),
  ],
};

let pass=0,total=0;const failed=[];
for(const [name,checks] of Object.entries(groups)){
  const ok=checks.every(Boolean);pass+=ok?1:0;total++;
  console.log(`${ok?'PASS':'FAIL'} ${name} (${checks.filter(Boolean).length}/${checks.length})`);
  if(!ok)failed.push(name);
}
console.log(`PHASE3_GROUPS=${pass}/${total}`);
if(failed.length)throw new Error(`Failed: ${failed.join(', ')}`);
