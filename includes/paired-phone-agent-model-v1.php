<?php
declare(strict_types=1);

require_once __DIR__.'/agent-inference-budget-v1.php';
require_once __DIR__.'/paired-phone-agent-v1.php';

function vp3_paired_phone_provider_failure_v1(array $result): PairedPhoneReplyFailure
{
    $value=$result['failure_code']??$result['error_code']??'';
    $code=is_string($value)?$value:'';
    $status=(int)($result['status']??0);
    $reason=match (true) {
        $code==='request_rate_limit' => 'busy',
        $code==='inference_budget_exhausted' => 'timeout',
        $code==='gmail_summary_access_changed' => 'access', // The shared transport's dispatch guard.
        str_starts_with($code,'credential_') || in_array($code,[
            'service_quota_exhausted','service_disabled','provider_disabled','service_usage_unavailable',
            'service_route_unavailable','personal_route_unavailable','anthropic_credit_balance_low','transport_unavailable',
        ],true) => 'setup',
        $status===429 => 'busy',
        in_array($status,[408,504],true) => 'timeout',
        in_array($status,[401,402,403],true) => 'setup',
        default => 'failed',
    };
    return new PairedPhoneReplyFailure($reason);
}

function vp3_paired_phone_cloud_fallback_v1(array $plan): bool
{
    $scope=$plan['compute_policy']['homeserver_scope']??[];
    return ($plan['blocked']??true)===false && ($plan['effective_preference']??null)==='auto'
        && in_array($plan['route']??null,['homeserver','vp3_cloud'],true)
        && ($plan['allow_vp3_fallback']??null)===true && ($plan['home']['cloud_allowed']??null)===true
        && ($plan['cloud']['ready']??null)===true
        && (empty($scope['supported']) || (!empty($scope['available']) && ($scope['cloud_allowed']??null)===true));
}

/** The caller supplies only messages attributed to this device. Recheck its live grant at provider dispatch. */
function vp3_paired_phone_model_reply_v1(PDO $pdo,int $owner,int $agent,array $history,Closure $recheck): string
{
    $user=vp3_phone_sql_v1($pdo,'SELECT * FROM users WHERE id=? AND is_active=1',[$owner])->fetch(PDO::FETCH_ASSOC);
    if (!$user || !has_permission('chat.access',$user) || !has_permission('account.access',$user)) {
        throw new RuntimeException('Phone Chat is unavailable.',403);
    }

    $name=system_agent_name();
    if ($agent>0) {
        $name=vp3_phone_sql_v1($pdo,'SELECT display_name FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1',
            [$agent,$owner])->fetchColumn();
        if ($name===false) throw new RuntimeException('Connected agent is unavailable.',403);
    }

    $last=array_pop($history);
    if (($last['role']??null)!=='user' || !is_string($last['content']??null) || trim($last['content'])==='') {
        throw new RuntimeException('Phone Chat needs a message.',422);
    }
    $messages=[];
    foreach (array_slice($history,-12) as $row) {
        if (in_array($row['role']??null,['user','assistant'],true) && is_string($row['content']??null)) {
            $messages[]=['role'=>$row['role'],'message'=>$row['content']];
        }
    }

    $service=['service'=>'conversation','stage'=>'paired_phone_chat','agent_id'=>$agent];
    // Match the regular provider's 55-second response window, inside the 90-second phone turn lease.
    $result=vp3_ai_with_budget_v1(55,static function() use ($pdo,$user,$owner,$agent,$messages,$last,$name,$service,$recheck):array {
        $preference=vp3_agent_runtime_preference_readonly_v420($pdo,$owner,$agent);
        $plan=vp3_agent_runtime_plan_v420($pdo,$user,$agent,'chat',$service);
        if (!empty($plan['blocked']) || !in_array($plan['route']??null,['vp3_cloud','homeserver'],true)) {
            throw new PairedPhoneReplyFailure(($plan['effective_preference']??null)==='homeserver_only'?'offline':'setup');
        }
        if (($plan['requested_preference']??$preference['effective_preference'])!==$preference['effective_preference']) {
            throw new PairedPhoneReplyFailure('access');
        }
        $guard=static function() use ($pdo,$user,$owner,$agent,$preference,$service,$recheck):bool {
            if (vp3_agent_runtime_preference_readonly_v420($pdo,$owner,$agent)!==$preference) return false;
            $policy=ai_v100_service_policy($pdo,$user,$service);
            return $policy!==null && !empty($policy['enabled']) && $recheck()===true;
        };
        $control=['surface'=>'paired_phone_chat','phone_agent_name'=>$name,'before_phone_dispatch'=>$guard];
        if ($plan['route']==='homeserver') {
            // This local-inference operation sees only caller-supplied text and cannot run account tools.
            require_once __DIR__.'/homeserver-local-execution-v230.php';
            require_once __DIR__.'/homeserver-profile-agent-v235.php';
            $input=[['role'=>'system','content'=>ai_system_prompt([],$user,$control)]];
            foreach (ai_history_messages($messages) as $message) $input[]=$message;
            $input[]=['role'=>'user','content'=>$last['content']];
            // The relay invokes this guard immediately before queueing. After that point
            // a missing reply can mean an accepted request; never send a second one to Cloud.
            $atDispatch=false;
            $localGuard=static function() use (&$atDispatch,$guard):bool {
                $atDispatch=true;
                return $guard();
            };
            try {
                if (!homeserver_profile_v235_supported($owner)) {
                    throw new RuntimeException('Stateless HomeServer inference is unavailable.');
                }
                $result=homeserver_execution_v230_execute($owner,'agent.infer.local',['messages'=>$input],null,true,$localGuard);
                return ['ok'=>!empty($result['ok']),'answer'=>$result['result']['reply']??null];
            } catch (RuntimeException $error) {
                $kind=homeserver_execution_v230_failure_class($error);
                if ($kind==='authorization' || $error->getCode()===409) {
                    throw new PairedPhoneReplyFailure('access');
                }
                if (in_array($error->getCode(),[401,402,403],true)) throw new PairedPhoneReplyFailure('setup');
                if ($atDispatch || $error instanceof PDOException || !vp3_paired_phone_cloud_fallback_v1($plan)) {
                    $reason=match(true) {
                        $error->getCode()===429 => 'busy',
                        $kind==='timeout' || in_array($error->getCode(),[408,504],true) => 'timeout',
                        default => 'offline',
                    };
                    throw new PairedPhoneReplyFailure($reason);
                }
                $current=vp3_agent_runtime_plan_v420($pdo,$user,$agent,'chat',$service);
                if (!vp3_paired_phone_cloud_fallback_v1($current)) {
                    throw new PairedPhoneReplyFailure('offline');
                }
            }
        }
        return ai_v100_with_service_context($service,
            static fn()=>ai_generate_chat_response($last['content'],$messages,[],$user,'chat',$control));
    });
    $answer=$result['answer']??null;
    if (empty($result['ok']) || !is_string($answer) || trim($answer)===''
        || !mb_check_encoding($answer,'UTF-8') || mb_strlen($answer)>12000) {
        throw vp3_paired_phone_provider_failure_v1($result);
    }
    return trim($answer);
}
