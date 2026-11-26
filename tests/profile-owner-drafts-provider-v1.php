<?php
declare(strict_types=1);

// Retain the shared broker fixture and every existing Gmail transport assertion.
// That fixture evaluates shipped AI prompt/broker/transport functions and uses a
// real usage ledger; only physical cURL is simulated.
require __DIR__.'/agent-connection-gmail-inbox-initial-summary-v1.php';

$profileChecks=0;
function pod_wire_check(bool $ok,string $message): void
{global $profileChecks;if(!$ok)throw new RuntimeException($message);$profileChecks++;echo 'PASS Profile draft transport: '.$message.PHP_EOL;}
$protocol='vp3.profile-owner-reply-draft.v1';
$send=static function(?Closure $guard,string $service='conversation',bool $background=false)use($owner,$protocol):array{
    $control=['surface'=>'tool_json','protocol'=>$protocol];if($guard)$control['before_profile_owner_reply_dispatch']=$guard;
    $job='profile-owner-draft:'.bin2hex(random_bytes(16));$GLOBALS['profileWireJob']=$job;
    return vp3_ai_with_budget_v1(55,static fn()=>ai_v100_with_service_context([
        'service'=>$service,'background'=>$background,'stage'=>$protocol,'agent_id'=>7,'conversation_id'=>11,
        'job_key'=>$job],
        static fn()=>ai_generate_chat_response('{"owner_instruction":"Ask a size question","visitor_conversation":{"messages":[{"speaker":"visitor","text":"EXACT_PUBLIC_VISITOR_TEXT"}]},"source_is_untrusted":true}',
            [['role'=>'assistant','message'=>'UNRELATED_PRIVATE_HISTORY']], [['title'=>'UNRELATED_PRIVATE_CONTEXT']],$owner,'main_ai',$control)));
};
$count=count($GLOBALS['gmailSummaryDispatch']);
pod_wire_check(empty($send(null)['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$count,'missing physical-dispatch authority blocks inference');
pod_wire_check(empty($send(static fn()=>true,'initiative')['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$count,'a private reply cannot use the background Initiative service');
pod_wire_check(empty($send(static fn()=>true,'conversation',true)['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$count,'background execution cannot masquerade as reviewed owner preparation');
$reserved=false;$ledgerBefore=(int)$pdo->query('SELECT COUNT(*) FROM ai_execution_ledger')->fetchColumn();
$blocked=$send(static function()use($pdo,&$reserved):bool{$reserved=(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===1;return false;});
pod_wire_check($reserved&&empty($blocked['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$count
    &&(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0
    &&(int)$pdo->query('SELECT COUNT(*) FROM ai_execution_ledger')->fetchColumn()===$ledgerBefore,'post-reservation source rejection aborts before physical dispatch and releases usage');
$thrown=$send(static function():bool{throw new RuntimeException('SECRET current authority changed');});
pod_wire_check(empty($thrown['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$count,'throwing source fence cannot send or expose a diagnostic');
$GLOBALS['gmailSummaryScenario']='reply';$guardCount=0;
$draft=$send(static function()use(&$guardCount):bool{return ++$guardCount===1;});$call=end($GLOBALS['gmailSummaryDispatch']);$wire=json_encode($call['payload'],JSON_THROW_ON_ERROR);
$ledger=$pdo->query("SELECT * FROM ai_execution_ledger WHERE service_stage='vp3.profile-owner-reply-draft.v1' ORDER BY id DESC LIMIT 1")->fetch();
pod_wire_check(!empty($draft['ok'])&&$draft['answer']==='{"body":"Thanks for the update."}'&&$guardCount===1&&count($GLOBALS['gmailSummaryDispatch'])===$count+1,'one authorized request reaches one physical provider attempt');
pod_wire_check($ledger['service_key']==='conversation'&&(int)$ledger['is_background']===0&&(int)$ledger['agent_id']===7&&(int)$ledger['conversation_id']===11
    &&(int)$ledger['cloud_tokens_charged']===140&&hash_equals($ledger['service_job_key'],hash('sha256','turn:'.$GLOBALS['profileWireJob'])),'foreground usage is attributed to exact private Agent/Chat and prepare operation');
pod_wire_check(str_contains($wire,'EXACT_PUBLIC_VISITOR_TEXT')&&!str_contains($wire,'UNRELATED_PRIVATE_HISTORY')&&!str_contains($wire,'UNRELATED_PRIVATE_CONTEXT')&&!str_contains($wire,'private-chat-persona')
    &&$call['payload']['max_output_tokens']===1600,'isolated protocol excludes implicit private history and bounds output');
pod_wire_check(str_contains(vp3_tool_json_contract_v1($protocol),'untrusted')&&str_contains(vp3_tool_json_contract_v1($protocol),'no tools')&&str_contains(vp3_tool_json_contract_v1($protocol),'never send'),'protocol treats visitor content as evidence and grants no sending authority');
$GLOBALS['gmailSummaryScenario']='rejected';$before=count($GLOBALS['gmailSummaryDispatch']);$result=$send(static fn()=>true);
pod_wire_check(empty($result['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$before+1,'a rejected response triggers neither transport retry nor configured provider fallback');
$GLOBALS['gmailSummaryScenario']='unknown';$before=count($GLOBALS['gmailSummaryDispatch']);$result=$send(static fn()=>true);
pod_wire_check(empty($result['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$before+1,'uncertain provider outcome is terminal for the invocation');
$GLOBALS['gmailSummaryExpireReservation']=true;$before=count($GLOBALS['gmailSummaryDispatch']);$result=$send(static fn()=>true);unset($GLOBALS['gmailSummaryExpireReservation']);
pod_wire_check(empty($result['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$before&&(int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()===0,'inference deadline exhausted while reserving usage prevents dispatch');
$pdo->exec("UPDATE user_llm_preferences SET route='own_key' WHERE user_id=1");$GLOBALS['gmailSummaryScenario']='reply';$before=count($GLOBALS['gmailSummaryDispatch']);$result=$send(static fn()=>true);
$call=end($GLOBALS['gmailSummaryDispatch']);$ledger=$pdo->query("SELECT * FROM ai_execution_ledger WHERE service_stage='vp3.profile-owner-reply-draft.v1' ORDER BY id DESC LIMIT 1")->fetch();
pod_wire_check(!empty($result['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$before+1&&$call['funding']==='own_key'&&(int)$ledger['cloud_tokens_charged']===0,'personal provider route retains exact guard and records no VP3 token debit');

// Execute the shipped orchestration function as well. Its two physical route
// boundaries use the broker fixture above and a deterministic HomeServer remote
// result; real queue/transaction semantics are covered by the retained relay
// suites. Only duplicate requires and the route/transport dependencies are mocked.
define('VP3_PROFILE_OWNER_DRAFT_V1',$protocol);
$modelSource=file_get_contents(__DIR__.'/../includes/profile-owner-drafts-v1.php');
$modelSource=substr($modelSource,strpos($modelSource,'    function vp3_profile_owner_draft_model_v1('));
$modelSource=substr(rtrim($modelSource),0,-1);
$modelSource=preg_replace("/require_once __DIR__\.'\/[^']+';/",'',$modelSource);
eval('namespace ProfileDraftRoute; use \\PDO; use \\Closure;'.$modelSource);
eval(<<<'PHP'
namespace ProfileDraftRoute;
function vp3_agent_runtime_plan_v420(\PDO $pdo,array $user,int $agent,string $surface,array $service=[]):array
{
    $GLOBALS['profileBudgetAtRoute']=\vp3_ai_budget_remaining_v1();
    return ['blocked'=>false,'route'=>$GLOBALS['profileChosenRoute']??'vp3_cloud','cloud'=>['ready'=>true]];
}
function vp3_agent_runtime_preference_readonly_v420(\PDO $pdo,int $owner,int $agent):array
{
    $GLOBALS['profilePreferenceReads']=($GLOBALS['profilePreferenceReads']??0)+1;
    return ['effective_preference'=>!empty($GLOBALS['profileChangePreference'])&&$GLOBALS['profilePreferenceReads']>1?'changed':'current'];
}
function homeserver_execution_v230_execute(int $owner,string $operation,array $payload=[],?callable $fallback=null,bool $transient=false,?callable $before=null):array
{
    if($owner!==1||$operation!=='agent.infer.local'||$fallback!==null||!$transient||$before===null)throw new \LogicException('Invalid private HomeServer route.');
    if(!$before())throw new \RuntimeException('Current route unavailable.',403);
    $GLOBALS['profileHomeAttempts']=($GLOBALS['profileHomeAttempts']??0)+1;
    if(!empty($GLOBALS['profileHomeUncertain']))throw new \RuntimeException('Uncertain HomeServer result.');
    return ['ok'=>true,'result'=>['reply'=>'{"body":"A prepared HomeServer draft."}']];
}
PHP);
$scope=['user'=>$owner,'agent_id'=>7,'conversation_id'=>11];$request=['prepare_request_id'=>str_repeat('b',32)];
$GLOBALS['profileChosenRoute']='vp3_cloud';$GLOBALS['profilePreferenceReads']=0;$before=count($GLOBALS['gmailSummaryDispatch']);
$result=ProfileDraftRoute\vp3_profile_owner_draft_model_v1($pdo,$scope,$request,'{"owner_instruction":"A bounded reply"}',static fn()=>true);
pod_wire_check(!empty($result['ok'])&&count($GLOBALS['gmailSummaryDispatch'])===$before+1&&$GLOBALS['profileBudgetAtRoute']>50&&$GLOBALS['profileBudgetAtRoute']<=55,'production orchestration starts its inference budget before route discovery');
$GLOBALS['profileChosenRoute']='homeserver';$GLOBALS['profilePreferenceReads']=0;$before=count($GLOBALS['gmailSummaryDispatch']);
$result=ProfileDraftRoute\vp3_profile_owner_draft_model_v1($pdo,$scope,$request,'{"owner_instruction":"A bounded reply"}',static fn()=>true);
pod_wire_check($result['answer']==='{"body":"A prepared HomeServer draft."}'&&$GLOBALS['profileHomeAttempts']===1&&count($GLOBALS['gmailSummaryDispatch'])===$before,'HomeServer orchestration uses only caller-context inference and forwards durable attempt fence');
$GLOBALS['profileHomeUncertain']=true;$GLOBALS['profilePreferenceReads']=0;$caught=false;
try{ProfileDraftRoute\vp3_profile_owner_draft_model_v1($pdo,$scope,$request,'{}',static fn()=>true);}catch(RuntimeException){$caught=true;}
pod_wire_check($caught&&$GLOBALS['profileHomeAttempts']===2&&count($GLOBALS['gmailSummaryDispatch'])===$before,'uncertain HomeServer execution cannot fall through to Cloud');
unset($GLOBALS['profileHomeUncertain']);$GLOBALS['profileChangePreference']=true;$GLOBALS['profilePreferenceReads']=0;$caught=false;
try{ProfileDraftRoute\vp3_profile_owner_draft_model_v1($pdo,$scope,$request,'{}',static fn()=>true);}catch(RuntimeException){$caught=true;}
pod_wire_check($caught&&$GLOBALS['profileHomeAttempts']===2&&count($GLOBALS['gmailSummaryDispatch'])===$before,'changed current compute preference blocks the queued physical attempt');
echo "PASS Profile owner draft provider: $profileChecks checks plus retained broker assertions\n";
