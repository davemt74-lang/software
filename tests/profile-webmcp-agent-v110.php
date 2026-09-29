<?php
declare(strict_types=1);

class VP3WebMCPAgentTestPDO extends PDO { public function __construct(){} }
$GLOBALS['vp3_test_chat']=true;
$GLOBALS['vp3_test_owner_requested']=false;

function profile_user_row(PDO $pdo,int $id): ?array { return ['id'=>$id,'display_name'=>'Owner']; }
function personal_capability_has_v242(string $cap,array $user): bool { return $GLOBALS['vp3_test_chat']; }
function profile_active_agent(PDO $pdo,array $profile): ?array { return ['id'=>77,'display_name'=>'Profile Agent']; }
function profile_runtime_session(PDO $pdo,int $owner,?array $visitor,bool $record): array { return ['id'=>901,'visitor_user_id'=>(int)($visitor['id']??0)?:null]; }
function system_agent_name(): string { return 'Stonefellow'; }
function vp3_profile_agent_public_conversation_v390(PDO $pdo,int $cid,int $owner,int $agent,int $session,bool $forUpdate=false): ?array {
    return $cid===7001?['id'=>7001,'owner_user_id'=>$owner,'profile_agent_id'=>$agent,'profile_session_id'=>$session,'status'=>'open']:null;
}
function vp3_profile_agent_public_state_v390(array $c): array { return ['conversation_id'=>(int)$c['id'],'status'=>(string)$c['status'],'agent_may_reply'=>true]; }
function profile_agent_messages(PDO $pdo,int $cid,int $limit=120): array { return [['id'=>1,'sender_type'=>'visitor','message'=>'hello','created_at'=>'now']]; }
function profile_agent_needs_owner(PDO $pdo,array $profile,array $agent,array $session,array $conversation,string $question): void { $GLOBALS['vp3_test_owner_requested']=$question; }

require dirname(__DIR__) . '/includes/profile-agent-public-service-v110.php';

function t(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }
$pdo=new VP3WebMCPAgentTestPDO();
$profile=['user_id'=>123,'display_name'=>'Owner','profile_agent_greeting'=>'Hello there'];

$ctx=vp3_profile_agent_public_context_v110($pdo,$profile,['id'=>42]);
t($ctx['owner_user_id']===123,'owner binding');
t($ctx['agent_id']===77,'agent binding');
t($ctx['session_id']===901,'session binding');

$state=vp3_profile_agent_public_state_service_v110($pdo,$ctx,0);
t($state['agent']['id']===77,'agent projection');
t($state['agent']['greeting']==='Hello there','greeting projection');
t($state['conversation']===null,'empty state has no conversation');

$thread=vp3_profile_agent_public_state_service_v110($pdo,$ctx,7001);
t($thread['conversation']['conversation_id']===7001,'conversation boundary');
t(count($thread['messages'])===1,'conversation messages');

try{vp3_profile_agent_public_context_v110($pdo,$profile,['id'=>123]);t(false,'owner must not become visitor');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='OWNER_VISITOR_PREVIEW_REQUIRED','owner visitor code');}

$GLOBALS['vp3_test_chat']=false;
try{vp3_profile_agent_public_context_v110($pdo,$profile,['id'=>42]);t(false,'entitlement must gate agent');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='PROFILE_AGENT_UNAVAILABLE','entitlement code');}
$GLOBALS['vp3_test_chat']=true;

$result=vp3_profile_agent_public_request_owner_v110($pdo,$ctx,7001,'Please ask the owner');
t($result['requested']===true,'owner request result');
t($GLOBALS['vp3_test_owner_requested']==='Please ask the owner','owner request canonical attention');
try{vp3_profile_agent_public_request_owner_v110($pdo,$ctx,9999,'x');t(false,'cross-thread request must fail');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='CONVERSATION_NOT_FOUND','conversation isolation');}

echo "PROFILE_WEBMCP_AGENT_V110_PHP=PASS\n";
