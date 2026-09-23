import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const runtime=read('includes/cognitive-runtime-v500.php');
const attention=read('includes/cognitive-attention-v2410.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const extension=read('includes/extension-notifications-v2140.php');
const extensionApi=read('api/extension-notifications-v2140.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const release=read('includes/cognitive-release-v2410.php');
const upgrade=read('upgrade.php');
const setup=read('setup.php');
const docs=read('docs/VP3_COGNITIVE_ATTENTION_V2410.md');

const attentionCreate=(attention.match(/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)/ig)||[]).map(x=>x.toLowerCase());

const checks=[
 ['v24.10 loads after v24.00 memory and before presentation consumers',
   bootstrap.indexOf("cognitive-memory-promotion-v2400.php")<bootstrap.indexOf("cognitive-attention-v2410.php")
   &&bootstrap.indexOf("cognitive-attention-v2410.php")<bootstrap.indexOf("cognitive-feed-v530.php")],
 ['v24.10 creates only an attention receipt table',
   attentionCreate.length===1&&attentionCreate[0].includes('cognitive_attention_receipts_v2410')],
 ['attention receipts do not store notification title/body/voice text',
   !/title\s+varchar/i.test(attention)&&!/body\s+varchar/i.test(attention)&&!/voice_text\s+varchar/i.test(attention)],
 ['v5.00 presentation policy delegates to v24.10',
   /vp3_cognitive_attention_decide_v2410\(\$observation,\$context\)/.test(runtime)],
 ['v5.10 compatibility presentation owner remains intact',
   /function vp3_cognitive_presentation_owns_attention_v510\(\): bool\{return true;\}/.test(presentation)],
 ['v24.10 declares policy authority without replacing delivery authorities',
   /'policy_authority'=>'cognitive_attention_v2410'/.test(release)
   &&/cognitive_presentation_v510/.test(release)&&/extension_notifications_v2140/.test(release)],
 ['budget is three delivered interruptions per thirty minutes',
   /ATTENTION_MAX_INTERRUPTS_V2410=3/.test(attention)&&/ATTENTION_WINDOW_MINUTES_V2410=30/.test(attention)],
 ['planned reservations expire after five minutes',
   /ATTENTION_PLAN_TTL_MINUTES_V2410=5/.test(attention)
   &&/status='planned'/.test(attention)
   &&/DATE_SUB\\(UTC_TIMESTAMP\\(\\),INTERVAL [\\s\\S]*ATTENTION_PLAN_TTL_MINUTES_V2410[\\s\\S]* MINUTE\\)/.test(attention)],
 ['budget query is user-global rather than namespace-scoped',
   /WHERE owner_user_id=\? AND interruptive=1/.test(attention)
   &&!/WHERE owner_user_id=\? AND agent_namespace=\? AND interruptive=1[\s\S]{0,180}ATTENTION_WINDOW_MINUTES/.test(attention)],
 ['repeat cooldown is thirty minutes and user-global',
   /ATTENTION_REPEAT_COOLDOWN_MINUTES_V2410=30/.test(attention)
   &&/WHERE owner_user_id=\? AND signal_key=\?/.test(attention)],
 ['only delivered or fresh planned interruptions start repeat cooldown',
   /status='delivered'/.test(attention)
   &&/ATTENTION_REPEAT_COOLDOWN_MINUTES_V2410/.test(attention)
   &&/status='planned'/.test(attention)
   &&/ATTENTION_PLAN_TTL_MINUTES_V2410/.test(attention)],
 ['critical attention can bypass budget while noncritical respects it',
   /budget_bypass/.test(attention)&&/attention_budget_exhausted/.test(attention)&&/critical_attention/.test(attention)],
 ['focus and quiet state suppress noncritical interruptions',
   /focus_or_quiet_hours/.test(attention)&&/response_deferred_by_focus/.test(attention)],
 ['noninterruptible state blocks voice and defers required user response',
   /not_interruptible/.test(attention)&&/user_response_deferred_not_interruptible/.test(attention)
   &&/\$interruptible/.test(attention)],
 ['sensitive content is excluded from voice eligibility',
   /empty\(\$ctx\['sensitive_for_voice'\]\)/.test(attention)],
 ['direct user requests stay in chat',
   /direct_user_request[\s\S]{0,100}chat_response/.test(attention)],
 ['candidate preview and reservation are separate',
   /function vp3_cognitive_attention_preview_v2410/.test(attention)
   &&/bool \$reserve=false/.test(attention)
   &&/\$reserve[\s\S]{0,120}vp3_cognitive_attention_arbitrate_v2410/.test(attention)],
 ['released and context-deferred receipts can be reconsidered',
   /function vp3_cognitive_attention_reconsiderable_v2410/.test(attention)
   &&/\$status==='released'/.test(attention)
   &&/attention_budget_exhausted/.test(attention)],

 ['cross-surface reservations use a user-global advisory lock',
   /SELECT GET_LOCK/.test(attention)&&/vp3_attn_user_/.test(attention)&&/SELECT RELEASE_LOCK/.test(attention)],
 ['Browser candidates are ranked before v24.10 preview',
   extension.indexOf('Allocate the bounded interruption budget in true priority order')<extension.indexOf("vp3_cognitive_attention_extension_candidate_v2410")],
 ['Browser only reserves attention after obtaining a delivery claim',
   extension.indexOf("vp3_extension_notification_claim_v2140($pdo,$session,$candidate)")<extension.lastIndexOf("vp3_cognitive_attention_extension_candidate_v2410")],
 ['Browser releases claim if final reservation is denied',
   /if\(!\$approved\)[\s\S]{0,180}vp3_extension_notification_release_v2140/.test(extension)],
 ['Browser visual delivery reconciles central attention receipt',
   /vp3_cognitive_attention_mark_delivered_v2410/.test(extension)
   &&/visual_delivered_v2140\(\$pdo,\$session,\$eventKey,\$claimToken,\$user,\$namespace\)/.test(extensionApi)],
 ['Browser explicit release frees central attention reservation',
   /vp3_cognitive_attention_mark_released_v2410/.test(extension)
   &&/release_v2140\(\$pdo,\$session,\$eventKey,[\s\S]{0,120}\$user,\$namespace\)/.test(extensionApi)],

 ['Browser dismiss reconciles central attention receipt',
   /vp3_cognitive_attention_mark_dismissed_v2410/.test(extension)
   &&/dismiss_v2140\(\$pdo,\$session,\$eventKey,\$user,\$namespace\)/.test(extensionApi)],
 ['Agent Voice uses v24.10 arbitration',
   /vp3_cognitive_attention_notification_row_signal_v2410/.test(presentation)
   &&/vp3_cognitive_attention_arbitrate_v2410/.test(presentation)],
 ['return digest no longer has a pre-policy voice early return',
   !/if\(\$digest&&\(int\)\(\$digest\['idle_minutes'\][\s\S]{0,180}return \['through_id'=>\$maxId/.test(presentation)],
 ['web voice delivered and STOP reconcile attention receipt',
   /vp3_cognitive_attention_mark_latest_voice_v2410\(\$pdo,\$user,\$namespace,true\)/.test(presentation)
   &&/vp3_cognitive_attention_mark_latest_voice_v2410\(\$pdo,\$user,\$namespace,false\)/.test(presentation)],
 ['presentation state exposes attention budget diagnostics',
   /'attention'=>\$attentionStatus/.test(presentation)],
 ['proactive Now identifies v24.10 policy and existing delivery authority',
   /'attention_policy'=>function_exists\('vp3_cognitive_attention_owns_policy_v2410'\)/.test(proactive)
   &&/'delivery'=>'cognitive_presentation_v510_and_extension_notifications_v2140'/.test(proactive)],
 ['attention receipts are bounded to ninety days',
   /ATTENTION_RETENTION_DAYS_V2410=90/.test(attention)&&/vp3_cognitive_attention_prune_v2410/.test(attention)],
 ['normal upgrade installs and requires v24.10 schema',
   /vp3_cognitive_attention_schema_ready_v2410/.test(upgrade)&&/vp3_cognitive_attention_ensure_schema_v2410/.test(upgrade)],
 ['fresh setup installs v24.10 schema',
   /vp3_cognitive_attention_ensure_schema_v2410/.test(setup)],
 ['release contract requires deferral and stale-plan recovery',
   /'noninterruptible_user_response_is_deferred'=>true/.test(release)
   &&/'released_reservations_reconsiderable'=>true/.test(release)
   &&/'stale_planned_reservations_do_not_hold_budget'=>true/.test(release)],

 ['release contract forbids parallel notification and voice queues',
   /'second_notification_queue'=>false/.test(release)&&/'second_voice_queue'=>false/.test(release)],
 ['docs cover context-sensitive deferral and reservation recovery',
   /## Context-sensitive deferral/.test(docs)&&/## Reservation recovery/.test(docs)],

 ['docs explicitly separate policy from delivery',
   /owns \*\*attention policy\*\*/.test(docs)&&/own \*\*delivery mechanics\*\*/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Attention v24.10 gate: ${checks.length}/${checks.length} passed`);
