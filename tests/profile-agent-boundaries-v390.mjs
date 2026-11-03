import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=path=>fs.readFileSync(new URL(`../${path}`,import.meta.url),'utf8');
const boundary=read('includes/profile-agent-boundary-v390.php');
const lifecycle=read('includes/user-agent-lifecycle-v390.php');
const profileApi=read('api/profile-agent.php');
const publicService=read('includes/profile-agent-public-service-v110.php');
const agentApi=read('api/user-agent-system-v236.php');
const publicUi=read('profile-agent.js');
const accountUi=read('account-agent-settings-v236.js');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const architecture=read('docs/VP3_PLATFORM_ARCHITECTURE_V320.md');

// Public Profile Agent conversation authority is the exact tuple of profile
// owner + Profile Agent + visitor session. Conversation id alone is not authority.
assert.match(boundary,/VP3_PROFILE_AGENT_BOUNDARY_V390/);
assert.match(boundary,/function vp3_profile_agent_public_conversation_v390/);
assert.match(boundary,/c\.id=\? AND c\.owner_user_id=\? AND c\.profile_agent_id=\? AND c\.profile_session_id=\?/);
assert.match(boundary,/FOR UPDATE/);
assert.match(profileApi,/require_once[^;]*profile-agent-public-service-v110\.php/);
for(const operation of ['state','message','poll'])assert.match(profileApi,new RegExp(`vp3_profile_agent_public_${operation}_service_v110\\(\\$pdo,\\$publicCtx`));
assert.match(publicService,/vp3_profile_agent_public_bound_conversation_v1/);
assert.match(publicService,/Conversation not found for this Profile Agent/);
assert.match(boundary,/vp3_profile_agent_conversation_authority_valid_v1/);
assert.match(boundary,/visitor_user_id/);
assert.match(boundary,/hash_equals\(\(string\)\$stored\['credential_hash'\]/);
assert.doesNotMatch(profileApi,/\$cid\?profile_agent_conversation_get\(/,'public API no longer authorizes by owner + session while ignoring Agent identity');

// Owner history is deliberately broader than public authority: the owner can
// still inspect/reply to historical visitor threads after changing Agents.
assert.match(boundary,/function vp3_profile_agent_owner_conversation_v390/);
const ownerBoundary=boundary.match(/function vp3_profile_agent_owner_conversation_v390[\s\S]*?\n}/)?.[0]||'';
assert.match(ownerBoundary,/c\.id=\? AND c\.owner_user_id=\?/);
assert.doesNotMatch(ownerBoundary,/profile_agent_id=\?|profile_session_id=\?/);
assert.match(profileApi,/conversation_messages[\s\S]*vp3_profile_agent_owner_conversation_v390/);

// open/owner_joined/resolved is an enforced runtime lifecycle. A resolved
// visitor thread reopens on a new visitor turn; owner takeover blocks Agent auto-replies.
assert.match(boundary,/\['open','owner_joined','resolved'\]/);
assert.match(boundary,/function vp3_profile_agent_prepare_visitor_turn_v390/);
assert.match(boundary,/status==='resolved'/);
assert.match(boundary,/SET status='open'/);
assert.match(boundary,/function vp3_profile_agent_agent_may_reply_v390/);
assert.match(boundary,/==='open'/);
assert.match(publicService,/if\(!vp3_profile_agent_agent_may_reply_v390\(\$conversation\)\)/);
assert.match(publicService,/'awaiting_owner'=>true/);
assert.match(profileApi,/owner_reply[\s\S]*vp3_profile_agent_owner_conversation_v390\(\$pdo,\$cid,\$uid,true\)/);
assert.match(profileApi,/SET status='owner_joined'/);
assert.match(publicService,/\$current=vp3_profile_agent_public_bound_conversation_v1\(\$pdo,\$ctx,\$cid,true\)/);
assert.match(publicService,/if\(!vp3_profile_agent_agent_may_reply_v390\(\$current\)/);
assert.match(publicService,/hash_equals\(\$responseToken,vp3_profile_agent_response_token_v1\(\$pdo,\$cid\)\)/,'returning to open cannot revive superseded inference');

// Visitor and owner mutations serialize on the conversation row. Agent model
// generation happens outside the lock, then status is rechecked before persistence.
const messageService=publicService.split('function vp3_profile_agent_public_message_service_v110')[1].split('function vp3_profile_agent_public_poll_service_v110')[0];
assert.match(messageService,/\$pdo->beginTransaction\(\);[\s\S]*vp3_profile_agent_public_bound_conversation_v1\(\$pdo,\$ctx,\$cid,true\)[\s\S]*INSERT INTO profile_agent_messages/);
assert.match(messageService,/\$pdo->commit\(\);[\s\S]*\$history=/);
assert.match(messageService,/ai_generate_chat_response[\s\S]*\$pdo->beginTransaction\(\);[\s\S]*\$current=vp3_profile_agent_public_bound_conversation_v1[\s\S]*INSERT INTO profile_agent_messages \(conversation_id,sender_type,sender_user_id,message,context_json\)/);
assert.match(messageService,/vp3_profile_agent_public_admit_v1[\s\S]*vp3_profile_agent_public_create_bound_v1/,'rate admission precedes new conversation allocation');

// Public browser continuity recovers safely when the owner changes Profile Agent.
assert.match(publicUi,/error\.status===404/);
assert.match(publicUi,/resetConversation\(\)/);
assert.match(publicUi,/The Profile Agent changed\. Start a new conversation/);
assert.match(profileApi,/'async'=>true,'client_request_id'/,'native HTTP selects queued delivery on the server');
assert.match(publicUi,/data\.client_request_id!==saved\.client_request_id/,'the native widget verifies the accepted retry receipt');
assert.match(publicUi,/request\('poll',\{after_id:lastMessageId\},'GET'\)/,'native results are read through the authenticated poll boundary');
assert.match(publicUi,/data\.messages\|\|\[\]\)\.forEach\(m=>appendMessage/,'canonical saved visitor, owner and Agent messages are rendered by ID');
assert.doesNotMatch(publicUi,/if\(data\.answer\)appendMessage/,'an admission/dispatch response cannot directly publish a competing answer');
assert.match(publicUi,/owner_joined/);
assert.match(publicUi,/The profile owner has joined this conversation/);

// Retiring a user Agent preserves its durable identity row and all Agent Chat / Profile
// Agent history instead of deleting history or converting it into the system namespace.
assert.match(lifecycle,/VP3_USER_AGENT_LIFECYCLE_V390/);
assert.match(lifecycle,/ADD COLUMN retired_at DATETIME NULL/);
assert.match(lifecycle,/function vp3_user_agent_retire_v390/);
assert.match(lifecycle,/UPDATE user_agents SET is_active=0,is_default=0,is_profile_agent=0,voice_enabled=0,retired_at=NOW\(\)/);
assert.match(lifecycle,/UPDATE user_profiles SET profile_agent_id=NULL,profile_agent_enabled=0/);
assert.doesNotMatch(lifecycle,/DELETE FROM user_agents/);
assert.doesNotMatch(lifecycle,/DELETE FROM chat_conversations/);
assert.doesNotMatch(lifecycle,/DELETE FROM profile_agent_conversations/);
assert.match(agentApi,/vp3_user_agent_retire_v390/);
assert.doesNotMatch(agentApi,/DELETE FROM chat_conversations/);
assert.doesNotMatch(agentApi,/user_agent_delete_v236/);
assert.match(agentApi,/vp3_user_agent_filter_visible_v390/);
assert.match(agentApi,/vp3_user_agent_require_current_v390/);
assert.match(accountUi,/>Retire<\/button>/,'Agent settings labels the lifecycle action as Retire');
assert.match(accountUi,/Existing Agent Chat and Profile Agent history will be preserved/,'retirement confirmation explains history preservation');
assert.match(accountUi,/Agent retired\. Conversation history preserved\./,'retirement completion copy matches durable lifecycle behavior');
assert.doesNotMatch(accountUi,/and its agent chat history\? This cannot be undone/,'destructive history-deletion copy is retired');

// Lifecycle schema DDL is prepared before mutation and forbidden from running
// inside an active lifecycle transaction.
assert.match(lifecycle,/\$pdo->inTransaction\(\)&&!vp3_user_agent_lifecycle_schema_ready_v390\(\$pdo\)/);
assert.match(lifecycle,/Agent lifecycle schema must be installed before starting a lifecycle transaction/);
assert.match(agentApi,/vp3_user_agent_lifecycle_ensure_schema_v390\(\$pdo\);/);
const retirementBlock=agentApi.match(/if \(\$action === 'delete_agent'\)[\s\S]*?user_agent_api_v236\(true,[\s\S]*?\n    }/)?.[0]||'';
assert.ok(retirementBlock,'Agent retirement API block is inspectable');
assert.match(retirementBlock,/beginTransaction/);
assert.match(retirementBlock,/vp3_user_agent_retire_v390/);
assert.doesNotMatch(retirementBlock,/ensure_schema/,'retirement transaction does not run schema DDL');

// Normal setup/upgrade own the retirement schema; bootstrap owns both v3.90 boundaries.
assert.match(bootstrap,/user-agent-lifecycle-v390\.php/);
assert.match(bootstrap,/profile-agent-boundary-v390\.php/);
assert.match(setup,/vp3_user_agent_lifecycle_ensure_schema_v390/);
assert.match(upgrade,/vp3_user_agent_lifecycle_schema_ready_v390/);
assert.match(upgrade,/vp3_user_agent_lifecycle_ensure_schema_v390/);

// Architecture explicitly preserves the three conversation domains and durable Agent identity.
assert.match(architecture,/Profile Agent visitor conversation principal/);
assert.match(architecture,/owner \+ Profile Agent \+ visitor session/);
assert.match(architecture,/owner takeover/);
assert.match(architecture,/retired Agent/);
assert.match(architecture,/must not delete or reclassify Agent Chat or Profile Agent history/);

console.log('PROFILE_AGENT_BOUNDARIES_V390=PASS');
