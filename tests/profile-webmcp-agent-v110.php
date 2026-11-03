<?php
declare(strict_types=1);

// Keep the original service contract assertions on real current authority and storage.
define('PA1_PUBLIC_BOOTSTRAP_ONLY',true);
require __DIR__.'/profile-public-authority-pa1.php';
resetPublic();
$pdo->exec("INSERT INTO users(id,display_name,email,role,is_active,avatar_path,password_hash) VALUES(123,'Owner','owner123@example.test','member',1,'','owner123-password'),(42,'Visitor','visitor42@example.test','member',1,'','visitor42-password')");
$pdo->exec("INSERT INTO user_profiles VALUES(123,'demo','',1,1,77,'Hello there','',1)");
$pdo->exec("INSERT INTO user_agents VALUES(77,123,'Profile Agent',1)");
loginVisitor(42);
$profile=profile_for_user($pdo,123,false);
function t(bool $value,string $message): void{check($value,$message);}

$ctx=vp3_profile_agent_public_context_v110($pdo,$profile,current_user());
t($ctx['owner_user_id']===123,'owner binding');
t($ctx['agent_id']===77,'agent binding');
t($ctx['session_id']>0&&(int)$ctx['session']['owner_user_id']===123,'session binding');

$state=vp3_profile_agent_public_state_service_v110($pdo,$ctx,0);
t($state['agent']['id']===77,'agent projection');
t($state['agent']['greeting']==='Hello there','greeting projection');
t($state['conversation']===null,'empty state has no conversation');

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO profile_agent_conversations(id,owner_user_id,profile_agent_id,profile_session_id,visitor_user_id) VALUES(7001,123,77,?,42)')->execute([$ctx['session_id']]);
vp3_profile_agent_conversation_bind_v1($pdo,profile_agent_conversation_get($pdo,7001,123,$ctx['session_id']),$ctx['conversation_authority']);
$pdo->exec("INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message) VALUES(7001,'visitor',42,'hello')");
$pdo->commit();
$thread=vp3_profile_agent_public_state_service_v110($pdo,$ctx,7001);
t($thread['conversation']['conversation_id']===7001,'conversation boundary');
t(count($thread['messages'])===1,'conversation messages');

try{vp3_profile_agent_public_context_v110($pdo,$profile,['id'=>123]);t(false,'owner must not become visitor');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='OWNER_VISITOR_PREVIEW_REQUIRED','owner visitor code');}

$caps['profile_chat.access']=false;
try{vp3_profile_agent_public_context_v110($pdo,$profile,current_user());t(false,'entitlement must gate agent');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='PROFILE_AGENT_UNAVAILABLE','entitlement code');}
$caps['profile_chat.access']=true;

$result=vp3_profile_agent_public_request_owner_v110($pdo,$ctx,7001,'Please ask the owner');
t($result['requested']===true,'owner request result');
$attention=$pdo->query("SELECT summary FROM agent_attention_items WHERE owner_user_id=123 AND attention_type='needs_owner' ORDER BY id DESC LIMIT 1")->fetchColumn();
t(is_string($attention)&&str_contains($attention,'Please ask the owner'),'owner request canonical attention');
try{vp3_profile_agent_public_request_owner_v110($pdo,$ctx,9999,'x');t(false,'cross-thread request must fail');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='CONVERSATION_NOT_FOUND','conversation isolation');}

echo "PROFILE_WEBMCP_AGENT_V110_PHP=PASS\n";
