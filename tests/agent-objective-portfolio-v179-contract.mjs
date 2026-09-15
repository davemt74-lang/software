import fs from 'node:fs';
import assert from 'node:assert/strict';

const portfolio=fs.readFileSync('includes/agent-objective-portfolio-v179.php','utf8');
const chat=fs.readFileSync('includes/release-chat-v105.php','utf8');
const briefJs=fs.readFileSync('chat-agent-intelligence-v171.js','utf8');
const briefCss=fs.readFileSync('chat-agent-intelligence-v171.css','utf8');

assert.match(portfolio,/agent-objective-portfolio-v179-20260915/,'stable Phase 17.9 marker is required');
assert.match(portfolio,/source_kind='objective_plan'/,'portfolio must derive from canonical objective parents');
assert.match(portfolio,/source_kind='objective_step'/,'portfolio must inspect canonical objective children');
assert.match(portfolio,/work_priority/,'portfolio must reuse durable work priority');
assert.match(portfolio,/objective_verification_status/,'verification state must influence arbitration');
assert.match(portfolio,/agent_objective_outcome_memory/,'17.7 outcomes must inform ranking');
assert.match(portfolio,/agent_proactive_objectives_v178/,'17.8 proposals must be portfolio inputs');
assert.match(portfolio,/user_priority.*state.*verification.*attention.*schedule.*historical_outcome.*recency/s,'ranking must combine explicit priority, state, verification, attention, schedule, learned outcomes, and recency');
assert.match(portfolio,/VP3_AGENT_OBJECTIVE_PORTFOLIO_DUPLICATE_THRESHOLD_V179/,'portfolio must detect overlapping objectives');
assert.match(portfolio,/schedule_conflict_with/,'portfolio must detect competing scheduled objective work');
assert.match(portfolio,/merge_review/,'overlap must be advisory rather than destructive');
assert.match(portfolio,/sequence_review/,'schedule conflicts must recommend sequencing');
assert.match(portfolio,/repair_now/,'failed/remediating objectives must be surfaced for repair');
assert.match(portfolio,/agent_work_control_priority_v173/,'explicit reprioritization must reuse Phase 17.3 controls');
assert.match(portfolio,/agent_work_dependency_add_v174/,'cross-objective ordering must reuse Phase 17.4 dependencies');
assert.match(portfolio,/status NOT IN \('completed','cancelled'\)/,'cross-objective sequencing must preserve completed work and gate only remaining work');
assert.match(portfolio,/Pause the executing objective work before changing cross-objective order/,'sequencing must fail before mutating an executing objective');
assert.match(portfolio,/cancelled objective cannot be used as a prerequisite/,'cancelled objectives must not become permanent prerequisite blockers');
assert.ok(portfolio.indexOf("==='executing')throw") < portfolio.indexOf("agent_work_dependency_add_v174($pdo,$user,(int)$row['id']"),'all remaining objective work must be prevalidated before dependency mutation starts');
assert.match(portfolio,/objective_portfolio_priority/,'priority changes must be audited');
assert.match(portfolio,/objective_portfolio_dependency/,'cross-objective ordering must be audited');
assert.match(portfolio,/Ranking is advisory/,'portfolio output must state the advisory boundary');
assert.match(portfolio,/No new scheduler, worker, queue, dashboard, or persistence table/,'architecture boundary must be documented');
assert.doesNotMatch(portfolio,/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE/i,'Phase 17.9 must not create a parallel persistence system');
assert.doesNotMatch(portfolio,/setInterval\s*\(|fetch\s*\(/,'Phase 17.9 must not add polling');

assert.match(chat,/require_once __DIR__\.\'\/agent-objective-portfolio-v179\.php\'/,'shared Chat boundary must load Phase 17.9');
assert.match(chat,/agent_objective_portfolio_chat_v179\(\$query,\$user,\$conversationId\)/,'Agent Chat must route portfolio commands through Phase 17.9');
assert.ok(chat.indexOf('agent_objective_portfolio_chat_v179') < chat.indexOf('agent_objective_memory_chat_v177'),'portfolio arbitration must run before single-objective memory routing');

assert.match(briefJs,/data-agent-objective-portfolio-control/,'Agent Brief must expose the portfolio without a second dashboard');
assert.match(briefJs,/Show my objective portfolio and explain what I should do first\./,'Agent Brief must route portfolio inspection through canonical Chat');
assert.match(briefJs,/form\.requestSubmit\(\)/,'portfolio control must reuse canonical Chat submission');
assert.doesNotMatch(briefJs,/fetch\s*\(|setInterval\s*\(/,'Agent Brief portfolio control must not add polling');
assert.match(briefCss,/chat-agent-intelligence-portfolio-control/,'Agent Brief portfolio control must use the existing brief presentation layer');

console.log('Agent Objective Portfolio v17.9 contract passed.');
