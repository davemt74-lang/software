import assert from 'node:assert/strict';
import fs from 'node:fs';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const chat=read('includes/profile-webmcp-chat-v140.php');
const catalog=read('includes/profile-webmcp-v100.php');
const externalLayer=read('includes/profile-webmcp-external-v120.php');
const nativeApi=read('api/profile-webmcp-v100.php');
const externalApi=read('api/profile-webmcp-external-v120.php');
const nativeRuntime=read('profile-webmcp-v100.js');
const externalRuntime=read('profile-webmcp-external-v120.js');
const sitesUi=read('profile-agent-radar-sites.js');
const workflow=read('.github/workflows/public-funnel-onboarding-continuity.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(chat,/hash_hmac\('sha256'/,'chat grant must be cryptographically signed');
assert.match(chat,/verification_token/,'existing private site verification token must sign grants');
assert.match(chat,/origin_hash/);
assert.match(chat,/session_hash/);
assert.match(chat,/profile_username/);
assert.match(chat,/property_id/);
assert.match(chat,/owner_user_id/);
assert.match(chat,/CHAT_GRANT_EXPIRED/);
assert.match(chat,/CHAT_GRANT_MISMATCH/);
assert.match(chat,/VP3_PROFILE_WEBMCP_CHAT_GRANT_SECONDS_V140=1200/,'grant must be short lived');
assert.doesNotMatch(chat,/CREATE TABLE|ALTER TABLE/,'Section 5 must not add a parallel chat/session schema');
assert.match(chat,/profile_visit_sessions/,'external chat must reuse canonical Profile Agent visit sessions');
assert.match(chat,/profile_agent_conversations/,'chat start must reuse canonical conversations');
assert.match(chat,/profile_agent_conversation_create/,'chat start must use canonical conversation creator');
assert.match(chat,/status IN \('open','owner_joined','resolved'\)/,'chat start must resume the latest exact-session conversation, including resolved threads that reopen on the next visitor message');

assert.match(catalog,/vp3\.agent\.chat\.start/);
assert.match(nativeRuntime,/vp3\.agent\.chat\.start/);
assert.match(nativeApi,/vp3_profile_webmcp_chat_start_v140/,'native and external surfaces must share chat start semantics');

assert.match(externalLayer,/stateful_profile_agent'\s*=>\s*\$chatEnabled/);
assert.match(externalLayer,/transactional_actions'\s*=>\s*\$schedulingEnabled/,'only the scheduling adapter may enable connected-site transactional actions in Section 6');
for(const name of ['vp3.agent.chat.start','vp3.agent.conversation.get','vp3.agent.message.send','vp3.agent.owner_handoff.request']){
  assert.match(externalLayer,new RegExp(name.replaceAll('.','\\.')),'external manifest tool '+name);
  assert.match(externalRuntime,new RegExp(name.replaceAll('.','\\.')),'external trusted runtime '+name);
}
assert.match(externalLayer,/vp3\.booking\.prepare/,'Section 6 may expose public scheduling');
assert.doesNotMatch(externalLayer,/vp3\.commerce\.checkout|vp3\.rewards\.claim/,'commerce and rewards remain outside the chat/scheduling surface');

assert.match(externalApi,/vp3_profile_webmcp_chat_grant_create_v140/,'GET manifest must mint a scoped chat grant');
assert.match(externalApi,/vp3_profile_webmcp_chat_grant_verify_v140/,'stateful calls must verify the grant');
assert.match(externalApi,/vp3_profile_webmcp_external_chat_context_v140/,'external chat must create canonical profile-session context');
assert.match(externalApi,/vp3_profile_agent_public_message_service_v110/,'external chat must reuse canonical message service');
assert.match(externalApi,/vp3_profile_agent_public_state_service_v110/,'external conversation reads must reuse canonical state service');
assert.match(externalApi,/vp3_profile_agent_public_request_owner_v110/,'external owner handoff must reuse canonical escalation');
assert.match(externalApi,/webmcp_message_sent/,'external chat must keep WebMCP telemetry lineage');
assert.match(externalApi,/webmcp_handoff_requested/,'external handoff must keep WebMCP telemetry lineage');
assert.doesNotMatch(externalApi,/Access-Control-Allow-Credentials/i,'external chat must remain credential-free CORS');
assert.doesNotMatch(externalApi,/HTTP_AUTHORIZATION|Bearer\s|connected_site_auth/i,'external chat must not use private Connected Sites OAuth credentials');

assert.match(externalRuntime,/credentials:'omit'/);
assert.match(externalRuntime,/this\.chatGrant/);
assert.match(externalRuntime,/this\.chatGrantExpiresAt/);
assert.match(externalRuntime,/ensureChatGrantV140/);
assert.match(externalRuntime,/payload\.chat_grant=this\.chatGrant/,'grant must be transport metadata outside tool input');
assert.match(externalRuntime,/stateful_profile_agent===true&&Boolean\(this\.chatGrant\)/,'runtime registration must require manifest chat authority plus grant');
assert.doesNotMatch(externalRuntime,/localStorage|sessionStorage|document\.cookie/,'external chat must remain in-memory only');
assert.doesNotMatch(externalRuntime,/Authorization|X-VP3-WebMCP-Session/,'external chat must not inherit native/browser credentials');

assert.match(sitesUi,/Agent Chat <b>/,'owner diagnostics must show external chat state');
assert.match(sitesUi,/Public scheduling is available through explicit prepare\/confirm tools/);
assert.match(sitesUi,/Commerce, rewards, and other transactional domains remain unavailable/);

assert.match(workflow,/profile-webmcp-chat-v140\.php/);
assert.match(workflow,/profile-webmcp-chat-v140-contract\.mjs/);
assert.match(workflow,/profile-webmcp-chat-v140-runtime\.mjs/);
assert.match(recovery,/profile-webmcp-chat-v140\.php/);
assert.match(recovery,/profile-webmcp-chat-v140-contract\.mjs/);
assert.match(recovery,/profile-webmcp-chat-v140-runtime\.mjs/);

console.log('PROFILE_WEBMCP_CHAT_V140_CONTRACT=PASS');
