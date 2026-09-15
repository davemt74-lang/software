import fs from 'node:fs';
import assert from 'node:assert/strict';

const proactive=fs.readFileSync('includes/agent-proactive-objectives-v178.php','utf8');
const chat=fs.readFileSync('includes/release-chat-v105.php','utf8');
const memory=fs.readFileSync('includes/agent-objective-memory-v177.php','utf8');
const objective=fs.readFileSync('includes/agent-objective-plans-v175.php','utf8');
const ranking=fs.readFileSync('includes/agent-proactive-v123.php','utf8');
const engine=fs.readFileSync('includes/agent-job-engine-v1900.php','utf8');

assert.match(proactive,/VP3_AGENT_PROACTIVE_OBJECTIVES_V178\s*=\s*'agent-proactive-objectives-v178-20260915'/,'Phase 17.8 needs a stable build marker');
assert.match(proactive,/agent_proactive_v123_suggestions\(/,'Proactive objectives must reuse the evidence-first ranking engine');
assert.match(ranking,/agent_proactive_v123_score/,'The retained proactive engine must still own evidence scoring');
assert.match(proactive,/VP3_AGENT_PROACTIVE_OBJECTIVE_MIN_SCORE_V178/,'Objective proposals need an explicit confidence floor');
assert.match(proactive,/agent_proactive_objective_source_eligible_v178/,'Single-step/low-value sources must be filtered before objective proposal');
assert.match(proactive,/array_slice\(\$proposals,0,VP3_AGENT_PROACTIVE_OBJECTIVE_LIMIT_V178\)/,'The proactive objective surface must remain bounded');

assert.doesNotMatch(proactive,/CREATE TABLE|ALTER TABLE|DROP TABLE|TRUNCATE TABLE/i,'Phase 17.8 must not create a parallel proposal/work table');
assert.match(proactive,/agent_proactive_events/,'Proposal persistence must reuse the existing proactive event ledger');
assert.match(proactive,/agent_proactive_v93_event\([^;]*'shown'/s,'Shown proposals must be auditable in the existing proactive ledger');
assert.match(proactive,/agent_proactive_v93_event\([^;]*'acted'/s,'Accepted proposals must be auditable in the existing proactive ledger');
assert.match(proactive,/agent_proactive_v93_event\([^;]*'dismissed'/s,'Dismissed proposals must reuse proactive suppression');
assert.match(proactive,/WHERE user_id=\? AND source_kind='objective_proposal'/,'Persisted proposal lookup must remain owner scoped');
assert.match(proactive,/suggestion_hash LIKE \?/,'Persisted proposal tokens must resolve through the canonical proactive hash');
assert.match(proactive,/VP3_AGENT_PROACTIVE_OBJECTIVE_SHOWN_TTL_HOURS_V178/,'Automatic proactive nudges must be rate limited');

assert.match(proactive,/notification_unread_count|notifications_attention/,'Notification backlog must be available as aggregate objective evidence');
assert.match(proactive,/crm_tasks WHERE assigned_user_id=\?/,'CRM due-work evidence must be scoped to tasks assigned to the current user');
assert.doesNotMatch(proactive,/SELECT[^;]*(?:crm_contacts|email|phone|company)/is,'Proactive CRM evidence must not copy contact PII into objective proposals');
assert.match(proactive,/workflow_recovery/,'Multiple failed non-objective workflows may produce a coordinated recovery proposal');
assert.match(proactive,/knowledge_available/,'Available personal Knowledge should enrich planning without becoming a standalone execution path');
assert.match(proactive,/operations_homeserver/,'HomeServer operational evidence must be supported');
assert.match(proactive,/str_starts_with\(\$source,'calendar_'\)/,'Calendar evidence must be supported');
assert.match(proactive,/\[private path\]/,'Cloud proposal persistence must scrub native filesystem paths');
assert.doesNotMatch(proactive,/native_path|filesystem_path|folder_path/i,'Phase 17.8 must not request or persist HomeServer native paths');

assert.match(proactive,/agent_objective_memory_similar_v177/,'17.7 outcomes must inform proactive planning');
assert.match(proactive,/\['achieved','remediated'\]/,'Only successful learned outcomes may seed a proactive plan');
assert.match(proactive,/negative_memory_id/,'Failed learned outcomes must remain available as negative evidence');
assert.match(proactive,/agent_objective_memory_replay_stages_v177/,'Successful similar outcomes may seed the proposed plan');
assert.match(memory,/HomeServer private execution context is never copied into objective memory/,'17.7 privacy boundary must remain intact');

const proposalsStart=proactive.indexOf('function agent_proactive_objectives_v178');
const acceptStart=proactive.indexOf('function agent_proactive_objective_accept_v178');
assert.ok(proposalsStart>=0&&acceptStart>proposalsStart,'Proposal derivation and acceptance must be separate boundaries');
const proposalSection=proactive.slice(proposalsStart,acceptStart);
assert.doesNotMatch(proposalSection,/agent_objective_create_v175|agent_objective_memory_reuse_v177/,'Deriving/showing proposals must never create durable work');
const acceptSection=proactive.slice(acceptStart,proactive.indexOf('function agent_proactive_objective_dismiss_v178'));
assert.match(acceptSection,/agent_objective_create_v175\(/,'Explicit acceptance must create fresh canonical Phase 17.5 workflows');
assert.match(acceptSection,/agent_objective_memory_reuse_v177\(/,'Accepted learned plans must reuse the safe Phase 17.7 replay boundary');
assert.match(objective,/agent_action_v124_plan/,'Fresh objective creation must recalculate current risk/approval requirements');
assert.match(proactive,/proactive_objective_accepted/,'Accepted proposals must leave a canonical workflow audit event');
assert.match(proactive,/Nothing runs until you accept/,'Proposal UI/chat copy must make the execution boundary explicit');

assert.match(chat,/require_once __DIR__\.'\/agent-proactive-objectives-v178\.php'/,'Shared Agent Chat must load Phase 17.8');
assert.match(chat,/agent_proactive_objective_chat_v178\(\$query,\$user,\$conversationId\)/,'Explicit proposal commands must route through Phase 17.8');
assert.ok(chat.indexOf('agent_proactive_objective_chat_v178')<chat.indexOf('agent_objective_memory_chat_v177'),'Proactive proposal acceptance must resolve before generic memory/objective routing');
assert.match(chat,/agent_proactive_objectives_v178\(\$pdo,\$user,\[\],\$objectiveContext,false\)/,'Normal Agent Chat answers must be able to detect proactive objective opportunities without creating work');
assert.match(chat,/\(float\)\(\$proposal\['score'\].*>=0\.88/s,'Automatic Agent Chat nudges need a high-confidence threshold');
assert.match(chat,/shownRecently/,'Automatic Agent Chat nudges must respect the proactive shown cooldown');
assert.match(chat,/Nothing will run unless you accept it/,'Automatic nudges must state the explicit acceptance boundary');
assert.match(chat,/created_by_user_id=\? AND knowledge_scope='personal'/,'Knowledge enrichment must remain owner scoped and personal');

assert.match(engine,/agent_job_claim_run_v1900/,'Phase 19 remains the durable execution authority');
assert.doesNotMatch(proactive,/agent_job_claim|lease_expires|worker_id|setInterval\s*\(/,'Phase 17.8 must not add a claimant, lease path, worker or browser polling loop');
assert.equal(fs.existsSync('agent-proactive-objectives.php'),false,'Phase 17.8 must not add a competing proactive-objective dashboard');
assert.equal(fs.existsSync('upgrade-agent-proactive-objectives-v178.sql'),false,'Phase 17.8 must not require a standalone SQL migration');

console.log('Agent Proactive Objective Intelligence v17.8 contract passed.');
