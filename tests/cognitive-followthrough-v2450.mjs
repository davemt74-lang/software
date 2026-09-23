import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const follow=read('includes/cognitive-followthrough-v2450.php');
const release=read('includes/cognitive-release-v2450.php');
const turn=read('includes/cognitive-turn-v2430.php');
const continuity=read('includes/cognitive-continuity-v2440.php');
const attention=read('includes/cognitive-attention-v2410.php');
const context=read('includes/cognitive-context-v2420.php');
const extension=read('includes/extension-notifications-v2140.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const presentationJs=read('chat-cognitive-presentation-v510.js');
const drawerApi=read('api/chat-notifications-brain-v240.php');
const drawerJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_FOLLOWTHROUGH_V2450.md');

const checks=[
  ['v24.50 loads after v24.30',
    bootstrap.indexOf("cognitive-turn-v2430.php")<bootstrap.indexOf("cognitive-followthrough-v2450.php")
    &&bootstrap.includes("cognitive-release-v2450.php")],
  ['follow-through creates no new durable queue or ledger',
    !(follow.match(/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length],
  ['candidate source is v24.40 continuity',
    /vp3_cognitive_continuity_snapshot_v2440/.test(follow)
    &&/continuity'=>'cognitive_continuity_v2440'/.test(follow)],
  ['event key is stable by namespace ref and semantic state',
    /function vp3_cognitive_followthrough_event_key_v2450/.test(follow)
    &&/\$namespace,[\s\S]*\$item\['ref'\][\s\S]*\$item\['state'\]/.test(follow)
    &&!/updated_at/.test(follow.slice(follow.indexOf('function vp3_cognitive_followthrough_event_key_v2450'),follow.indexOf('function vp3_cognitive_followthrough_candidate_v2450')))],
  ['closed work disappears because candidates only consume open v24.40 projection',
    /foreach\(\(array\)\(\$snapshot\['items'\]/.test(follow)
    &&/status NOT IN \('completed','cancelled'\)/.test(continuity)],
  ['follow-through attention signals go through v24.10 preview',
    /vp3_cognitive_attention_preview_v2410/.test(follow)
    &&/vp3_cognitive_presentation_context_v500/.test(follow)],
  ['focus preparation goes through v24.30 and therefore v24.20',
    /vp3_cognitive_turn_prepare_v2430/.test(follow)
    &&/context_build/.test(follow)
    &&/vp3_cognitive_context_assemble_v2420/.test(turn)],
  ['proactive turn policy can request approval ask user update or silence',
    /return 'request_approval'/.test(turn)
    &&/return 'ask_user'/.test(turn)
    &&/return 'present_update'/.test(turn)
    &&/return 'remain_silent'/.test(turn)],
  ['follow-through projection never grants execution or approval authority',
    /'execution_authority'=>false/.test(follow)
    &&/'approval_authority'=>false/.test(follow)],
  ['Browser Companion reuses v21.40 delivery candidates and ledger',
    /vp3_cognitive_followthrough_extension_candidates_v2450/.test(extension)
    &&/extension_notification_delivery_v2140/.test(extension)
    &&/vp3_extension_notification_claim_v2140/.test(extension)],
  ['Browser final delivery still reserves central v24.10 attention',
    /vp3_cognitive_attention_extension_candidate_v2410/.test(extension)
    &&/true\s*\)\s*;/.test(extension.slice(extension.indexOf('function vp3_extension_notification_claim_next_v2140')))],
  ['stable Browser event key prevents repeated state delivery',
    /'event_key'=>\(string\)\$candidate\['event_key'\]/.test(follow)
    &&/INSERT IGNORE INTO extension_notification_delivery_v2140/.test(extension)],
  ['handoff metadata contains source target reason status and delivery surface',
    ['source_surface','target_surface','handoff_reason','handoff_status','delivery_surface'].every(k=>follow.includes("'"+k+"'"))],
  ['meaningful-away summary uses Presentation last meaningful timestamp',
    /lastMeaningfulAt/.test(follow)
    &&/last_meaningful_at/.test(presentation)
    &&/away_followthrough/.test(presentation)],
  ['Agent Brief renders follow-through and away summary',
    /brief\['followthrough'\]/.test(presentation)
    &&/While you were away/.test(presentationJs)
    &&/Follow-through/.test(presentationJs)],
  ['Proactive Now reuses v24.50 projection',
    /vp3_cognitive_followthrough_activity_projection_v2450/.test(proactive)
    &&/'followthrough'=>/.test(proactive)],
  ['Agent Brain API reuses v24.50 projection',
    /vp3_cognitive_followthrough_activity_projection_v2450/.test(drawerApi)
    &&/'followthrough'=>\$followthrough/.test(drawerApi)],
  ['Agent Brain shows source to target handoff state',
    /followthroughByRef/.test(drawerJs)
    &&/source_surface/.test(drawerJs)
    &&/target_surface/.test(drawerJs)
    &&/handoff_status/.test(drawerJs)],
  ['Chat cache-busts v24.50 follow-through UI',
    /\$cognitiveFollowthroughBuild = 'cognitive-followthrough-v2450-20260922'/.test(chat)
    &&/'followthroughBuild'=>\$cognitiveFollowthroughBuild/.test(chat)],
  ['release contract forbids parallel delivery and queue authority',
    /'second_notification_queue'=>false/.test(release)
    &&/'second_delivery_ledger'=>false/.test(release)
    &&/'second_task_queue'=>false/.test(release)
    &&/'second_attention_engine'=>false/.test(release)],
  ['release contract preserves privacy and execution authority',
    /'execution_authority_remains_existing_runtime'=>true/.test(release)
    &&/'model_reasoning_persisted'=>false/.test(release)],
  ['CI runs v24.50 gate', /cognitive-followthrough-v2450\.mjs/.test(workflow)],
  ['Recovery Baseline retains v24.50 gate', /cognitive-followthrough-v2450\.mjs/.test(recovery)],
  ['Production package requires v24.50 runtime and release gate',
    /cognitive-followthrough-v2450\.php/.test(packageWorkflow)
    &&/cognitive-release-v2450\.php/.test(packageWorkflow)],
  ['docs distinguish projection from delivery authority',
    /follow-through policy and handoff projection/.test(docs)
    &&/There is no new notification queue/.test(docs)
    &&/## While you were away/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Follow-Through v24.50 gate: ${checks.length}/${checks.length} passed`);
