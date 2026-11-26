<?php
declare(strict_types=1);

// Operator v1: a private, read-only browser call projection. The Phone workspace
// remains the authority for call control. No carrier, model, SMS or job dispatch.
require_once __DIR__.'/phone-browser-v1.php';
require_once __DIR__.'/agent-operator-worker-status-8a4.php';

function vp3_operator_call_status_v1(PDO $pdo,array $user,int $agentId,int $cid,int $now): ?array
{
    if ($agentId<0 || $cid<0 || $now<1) return null;
    try {
        $scope=vp3_phone_workspace_scope_v1($pdo,$user,['agent_id'=>$agentId,'conversation_id'=>$cid]);
        if (!vp3_phone_package_v100($pdo,$scope['owner_user_id'])['voice']) return null;
        $numbers=vp3_phone_workspace_numbers_v1($pdo,$scope);
        if (!$numbers) return null;
        $ready=vp3_phone_browser_config_state_v1()['ready'] && (bool)array_filter($numbers,
            static fn(array $number):bool=>$number['voice_enabled']&&$number['browser_enabled']);
        $row=['id'=>'operator','conversation_id'=>$cid,'state'=>$ready?'idle':'blocked','progress'=>null,
            'detail'=>$ready?'Phone calling is available.':'Browser calling is not enabled and configured for this Chat.'];
        // This is an owner-level registration observation, independent of browser
        // call readiness. Preserve manual calling and signed call state.
        $worker=vp3_operator_worker_status_8a4($pdo,$scope['owner_user_id'],$now);
        $withWorker=static function(array $row)use($worker):array{
            // Preserve the prior exact contract on deployments where disabled.
            if($worker['state']!=='disabled'){
                $row['operator_worker']=$worker;
                $row['detail'].=' '.vp3_operator_worker_detail_8a4($worker);
            }
            return $row;
        };
        if (!$ready || $cid===0) return $withWorker($row);

        // Exact Chat binding, not just the assigned number or owner. The Main
        // Chat can use a named Agent's assigned line, as the Phone UI already does.
        $q=$pdo->prepare("SELECT c.* FROM phone_browser_calls_v1 c
            JOIN chat_conversations h ON h.id=c.conversation_id AND h.user_id=c.owner_user_id
            AND COALESCE(h.user_agent_id,0)=c.chat_agent_id
            WHERE c.owner_user_id=? AND c.chat_agent_id=? AND c.conversation_id=?
            AND c.state IN ('dialing','ringing','answered') AND c.ended_epoch IS NULL
            AND c.provider_call_sid IS NOT NULL
            ORDER BY c.updated_epoch DESC,c.id DESC LIMIT 20");
        $q->execute([$scope['owner_user_id'],$agentId,$cid]);
        $stale=false;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $call) {
            if (($agentId>0 && (int)$call['agent_id']!==$agentId) || !vp3_phone_browser_live_call_v1($pdo,$call)) continue;
            $updated=(int)$call['updated_epoch'];
            // There is no continuous carrier heartbeat in this release. Report
            // the last signed state, and stop implying current activity when old.
            if ($updated>$now || $updated<=$now-300) {$stale=true;continue;}
            $detail=match($call['state']){
                'dialing'=>'Carrier last reported a dialing call.',
                'ringing'=>'Carrier last reported a ringing call.',
                'answered'=>'Carrier last reported an answered call.',
            };
            return $withWorker(array_replace($row,['state'=>'working','detail'=>$detail.' Open Phone for call controls.',
                'status_fresh_until'=>gmdate(DATE_ATOM,$updated+300)]));
        }
        if ($stale) return $withWorker(array_replace($row,['state'=>'unavailable','detail'=>'Current call status could not be verified. Open Phone to check the call.']));
        return $withWorker($row);
    } catch (VP3PhoneWorkspaceException $error) {
        return null;
    } catch (Throwable $error) {
        // Do not expose SQL errors, carrier identifiers or other private data.
        // No misleading ready state on failure.
        return null;
    }
}
