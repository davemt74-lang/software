<?php
declare(strict_types=1);

require_once __DIR__.'/agent-inference-budget-v1.php';

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
    $result=vp3_ai_with_budget_v1(25,static function() use ($pdo,$user,$owner,$agent,$messages,$last,$name,$service,$recheck):array {
        $preference=vp3_agent_runtime_preference_readonly_v420($pdo,$owner,$agent);
        $plan=vp3_agent_runtime_plan_v420($pdo,$user,$agent,'chat',$service);
        if (!empty($plan['blocked']) || !in_array($plan['route']??null,['vp3_cloud','homeserver'],true)) {
            throw new RuntimeException('Connected agent has no available chat route.',503);
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
            $input=[['role'=>'system','content'=>ai_system_prompt([],$user,$control)]];
            foreach (ai_history_messages($messages) as $message) $input[]=$message;
            $input[]=['role'=>'user','content'=>$last['content']];
            $result=homeserver_execution_v230_execute($owner,'agent.infer.local',['messages'=>$input],null,true,$guard);
            return ['ok'=>!empty($result['ok']),'answer'=>$result['result']['reply']??null];
        }
        return ai_v100_with_service_context($service,
            static fn()=>ai_generate_chat_response($last['content'],$messages,[],$user,'chat',$control));
    });
    $answer=$result['answer']??null;
    if (empty($result['ok']) || !is_string($answer) || trim($answer)===''
        || !mb_check_encoding($answer,'UTF-8') || mb_strlen($answer)>12000) {
        throw new RuntimeException('The connected agent could not generate a reply.',503);
    }
    return trim($answer);
}
