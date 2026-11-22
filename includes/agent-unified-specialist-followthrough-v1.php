<?php
declare(strict_types=1);

/**
 * Phase 2C. Read-only lifecycle of the exact specialist Chat decision.
 *
 * A source report, a posted question, an owner's reply, a queued continuation,
 * a canonical private-work receipt and an approved domain action are different
 * facts. Never infer completion from model text, a UI status or a suggestion.
 */
function vp3_unified_specialist_followthrough_receipt_v1(
    array $brief, array $finding, array $origin, ?array $loaded,
    bool $replyVerified, int $owner, int $agent, int $conversation, string $chatUrl
): ?array {
    $ref=$brief['source_ref']??null;$revision=$brief['source_revision']??null;
    $mid=$finding['message_id']??0;$run=$finding['run_id']??0;
    $route=$finding['routing']??null;
    if($owner<1||$agent<0||$conversation<1
        ||!in_array($brief['specialist_id']??null,['profile','media','marketing'],true)
        ||!is_string($ref)||!preg_match('/\A(?:profile|media|marketing):[A-Za-z0-9:@_-]{1,180}\z/D',$ref)
        ||!is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision)
        ||!is_int($mid)||$mid<1||!is_int($run)||$run<1||!is_array($route)
        ||($route['specialist']??null)!==$brief['specialist_id']
        ||!in_array($route['action']??null,['ask','report'],true)
        ||($finding['delivery']??null)!=='delivered'
        ||($finding['source_ref']??null)!==$ref||($finding['source_revision']??null)!==$revision
        ||($finding['conversation_id']??null)!==$conversation
        ||($finding['url']??null)!==$chatUrl||!is_string($chatUrl)||$chatUrl==='' 
        ||($origin['user']['id']??null)!==$owner
        ||(int)($origin['run']['id']??0)!==$run
        ||(int)($origin['message']['id']??0)!==$mid
        ||($origin['agent_id']??null)!==$agent
        ||($origin['conversation_id']??null)!==$conversation
        ||($origin['goal']['tool']??null)!=='coordination.review'
        ||($origin['goal']['primary']??null)!==$ref
        ||($origin['goal']['sources'][$ref]??null)!==$revision
        ||($origin['goal']['binding']['conversation_id']??null)!==$conversation
        ||($origin['goal']['binding']['agent_id']??null)!==$agent
        ||isset($origin['goal']['binding']['owner_user_id'])&&$origin['goal']['binding']['owner_user_id']!==$owner
        ||($origin['goal']['coordination_route']['action']??null)!==$route['action']
        ||($origin['goal']['coordination_route']['specialist']??null)!==$route['specialist'])return null;
    $output=$origin['result']['initiative_tool_result']['output']??null;
    if(!is_array($output)||($output['type']??null)!=='initiative_coordination'
        ||($output['evidence_revisions'][$ref]??null)!==$revision
        ||($origin['context']['initiative_output']??null)!==$output
        ||($origin['context']['source']??null)!=='agent_initiative'
        ||($origin['context']['initiative_run_id']??null)!==$run)return null;
    $state=$origin['state']['state']??null;
    if(!in_array($state,['open','discussed','queued','deferred','declined','owner_resolved','completed'],true))return null;
    $reply=(int)($origin['state']['last_reply_message_id']??0);
    if($state!=='open'&&(!$replyVerified||$reply<=$mid))return null;
    if($state==='open'&&$reply!==0)return null;
    $status=$state==='open'?($route['action']==='ask'?'awaiting_owner':'posted') : match($state){
        'discussed'=>'owner_replied','queued'=>'preparation_queued',
        'deferred'=>'deferred','declined'=>'declined',
        'owner_resolved'=>'owner_marked_resolved',
        'completed'=>'preparation_verified',
    };
    $receiptRun=0;
    if($loaded!==null){
        if((int)($loaded['run']['id']??0)!==$run
            ||(int)($loaded['message']['id']??0)!==$mid
            ||($loaded['state']['reply_ref']??null)!==($origin['state']['reply_ref']??null)
            ||($loaded['state']['state']??null)!==$state)return null;
        $action=$loaded['domain_action']??null;
        if(is_array($action)&&($action['run']['status']??null)==='completed'
            &&is_array($action['result']??null)
            &&($action['result']['domain_result']['verified']??null)===true
            &&(int)($action['receipt_id']??0)>0){
            $status='approved_action_verified';
            $receiptRun=(int)($action['run']['id']??0);
        }elseif(in_array($state,['queued','completed'],true)){
            $child=$loaded['continuation']??null;
            if(!is_array($child)||(int)($child['id']??0)<1
                ||(int)$child['id']!==(int)($loaded['state']['continuation_run_id']??0))return null;
            if($child['status']==='failed')$status='preparation_failed';
            elseif($child['status']==='completed'){
                if(($loaded['continuation_result']['effect_state']??null)!=='private_draft_ready')return null;
                $status='preparation_verified';$receiptRun=(int)$child['id'];
            }elseif($state==='completed'||!in_array($child['status'],['approved','queued','executing'],true))return null;
            else $status='preparation_queued';
        }
    }elseif(in_array($state,['queued','completed'],true))return null;
    // A private draft is not a sent message, published campaign or played track.
    return ['state'=>$status,'conversation_id'=>$conversation,'message_id'=>$mid,
        'chat_url'=>$chatUrl,'run_id'=>$run,'receipt_run_id'=>$receiptRun]
        +($replyVerified&&$reply>$mid?['reply_message_id'=>$reply]:[]);
}

/**
 * Re-read canonical owner/Chat, live source, saved reply and executor receipts.
 * Any unavailable or revoked proof means NO lifecycle claim. Bounded to three
 * currently selected specialists and never mutates a job or sends a message.
 */
function vp3_unified_specialist_followthrough_current_v1(
    PDO $pdo,array $scope,array $briefings,array $findings
): array {
    $owner=(int)($scope['user']['id']??0);$agent=$scope['agent_id']??null;
    $cid=$scope['conversation_id']??null;
    if($owner<1||!is_int($agent)||$agent<0||!is_int($cid)||$cid<1||!is_array($findings)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))return $briefings;
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-coordination-chat-v1.php';
    require_once __DIR__.'/agent-initiative-engagement-feedback-v1.php';
    $principal=$scope['principal']??null;
    if(!is_array($principal))return $briefings;
    foreach(array_slice($briefings,0,3,true) as $index=>$report){
        foreach(array_slice($findings,0,8) as $finding){
            $mid=$finding['message_id']??0;
            if(!is_int($mid)||$mid<1
                ||($finding['source_ref']??null)!==($report['source_ref']??null)
                ||($finding['source_revision']??null)!==($report['source_revision']??null)
                ||($finding['delivery']??null)!=='delivered'
                ||($finding['routing']['specialist']??null)!==($report['specialist_id']??null)
                ||($finding['conversation_id']??null)!==$cid)continue;
            $chatUrl=vp3_agent_activity_chat_href_v1($agent,$cid).'&message_id='.$mid;
            if(($finding['url']??null)!==$chatUrl)continue;
            try{
                $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$scope['user'],$principal,$cid,$mid);
                vp3_coordination_chat_origin_current_v1($pdo,$origin);
                $state=$origin['state']['state']??null;
                $loaded=in_array($state,['discussed','queued','completed'],true)
                    ?vp3_initiative_followthrough_load_v1($pdo,$scope['user'],$principal,$cid,$mid):null;
                $verified=in_array($state,['open'],true)?false:vp3_initiative_engagement_reply_verified_v1(
                    $pdo,$scope['user'],$cid,$mid,$origin['state']);
                $view=vp3_unified_specialist_followthrough_receipt_v1(
                    $report,$finding,$origin,$loaded,$verified,$owner,$agent,$cid,$chatUrl);
                if($view!==null){$briefings[$index]['engagement']=$view;break;}
            }catch(PDOException|JsonException|RuntimeException $error){
                // Fail closed; surface no owner decision or work completion.
                continue;
            }
        }
    }
    return $briefings;
}
