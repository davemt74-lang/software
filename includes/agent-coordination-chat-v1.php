<?php
declare(strict_types=1);

/** Verify the exact delivered situation against the current specialist sources. */
function vp3_coordination_chat_origin_v1(PDO $pdo,array $user,array $principal,int $conversation,int $message): array
{
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-coordination-work-v1.php';
    $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$conversation,$message);
    vp3_coordination_chat_origin_current_v1($pdo,$origin);
    return $origin;
}

function vp3_coordination_chat_origin_current_v1(PDO $pdo,array $origin,?array $items=null): void
{
    $goal=$origin['goal'];
    if(($goal['tool']??null)!=='coordination.review'||($origin['state']['coordination_source_changed']??null)===true){
        throw new RuntimeException('Top of Mind finding changed.',409);
    }
    $work=$origin['result']['initiative_tool_result'];
    if($items!==null){
        $revisions=vp3_coordination_saved_revisions_v1($goal,$work);
        $selected=array_intersect_key($items,$revisions);
        if(!array_diff_assoc($revisions,array_column($selected,'revision','ref'))){
            vp3_coordination_task_context_current_v1($pdo,$origin['user'],$goal,$selected);
            return;
        }
    }
    vp3_coordination_result_evidence_v1($pdo,$origin['user'],$goal['binding'],$goal,$work);
}

/** Apply the same live access check to saved Chat display and model history. */
function vp3_coordination_chat_history_v1(PDO $pdo,array $user,int $agent,int $conversation,array $rows): array
{
    $principal=null;
    $origins=[];
    $reminders=[];
    foreach($rows as &$row){
        if(($row['role']??null)!=='assistant')continue;
        $context=json_decode((string)($row['context_json']??''),true,32);
        if(($context['source']??null)==='agent_initiative_morning_brief'){
            require_once __DIR__.'/agent-morning-brief-v1.php';
            $row=vp3_morning_brief_history_v1($pdo,$user,$agent,$conversation,$row,$context);
            continue;
        }
        if(($context['source']??null)==='agent_initiative_reminder'){
            // The linked exact question owns the source permission, including on model-history reads.
            $id=$context['initiative_reminder']['message_id']??0;
            if(!is_int($id)||$id<1)$id=0;
            if(!array_key_exists($id,$reminders)){
                try{
                    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
                    $principal??=vp3_initiative_principal_v1($pdo,$user,$agent);
                    vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$conversation,$id);
                    $reminders[$id]=true;
                }catch(Throwable $error){$reminders[$id]=false;}
            }
            if(!$reminders[$id]){
                $row['message']='This reminder’s original question is no longer available.';
                $row['context_json']='{"source":"agent_initiative_reminder","skip_brain_archive":true}';
            }
            continue;
        }
        $output=$context['initiative_output']??null;
        if(($context['source']??null)==='agent_initiative'&&($output['type']??null)==='initiative_canvas'
            &&($output['status']??null)==='proposal_ready'&&is_string($output['source_ref']??null)
            &&str_starts_with($output['source_ref'],'canvas-feedback:')){
            try{
                require_once __DIR__.'/agent-initiative-followthrough-v1.php';
                $principal??=vp3_initiative_principal_v1($pdo,$user,$agent);
                $saved=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$conversation,(int)($row['id']??0));
                if($saved['message']['message']===$row['message']&&($saved['context']['initiative_output']??null)===$output){
                    $row['context_json']=$saved['message']['context_json'];
                    continue;
                }
            }catch(Throwable $error){/* Private feedback requires a live source check. */}
            $row['message']='This Canvas improvement proposal is no longer current.';
            $row['context_json']='{"source":"agent_initiative","skip_brain_archive":true}';
            continue;
        }
        $report=($output['type']??null)==='initiative_coordination';
        $reply=($context['initiative_coordination_reply_v1']??null)===true
            &&($context['initiative_coordination_source_changed_v1']??null)!==true;
        if(!$report&&!$reply)continue;

        $outcome=$reply?($context['initiative_followthrough']??[]):[];
        $id=(int)($report?($row['id']??0):($outcome['message_id']??0));
        if(!array_key_exists($id,$origins)){
            try{
                $principal??=vp3_initiative_principal_v1($pdo,$user,$agent);
                $origins[$id]=vp3_coordination_chat_origin_v1($pdo,$user,$principal,$conversation,$id);
            }catch(Throwable $error){$origins[$id]=null;}
        }
        $origin=$origins[$id];
        if($origin===null&&$report&&preg_match('/\Amedia:stoneward:[a-f0-9]{32}:resume-/D',
            (string)array_key_first($output['evidence_revisions']??[]))){
            try{
                require_once __DIR__.'/agent-media-stoneward-playback-v1.php';
                $historic=vp3_initiative_followthrough_origin_load_v1($pdo,$user,$principal,$conversation,$id);
                if(vp3_stoneward_playback_resumed_offer_played_v1($pdo,$historic))$origins[$id]=$origin=$historic;
            }catch(Throwable $error){/* Keep the ordinary unavailable-source display. */}
        }
        if($origin!==null&&($report
            ? ($origin['message']['message']===$row['message']&&($origin['context']['initiative_output']??null)===$output)
            : (($outcome['owner_user_id']??null)===(int)$origin['user']['id']&&($outcome['agent_id']??null)===$agent
                &&($outcome['conversation_id']??null)===$conversation&&($outcome['run_id']??null)===(int)$origin['run']['id']
                &&($outcome['reply_ref']??null)===$origin['state']['reply_ref']))){
            if($report){
                $saved=$origin['context'];
                $draftId=$origin['result']['initiative_tool_result']['output']['gmail_reply_draft_id']??null;
                if(is_string($draftId)){
                    require_once __DIR__.'/agent-coordination-gmail-draft-v1.php';
                    try{$saved['actions']=[vp3_coordination_gmail_draft_action_v1($pdo,$origin['user'],$agent,$conversation,$draftId)];}
                    catch(RuntimeException $error){unset($saved['actions']);}
                    $row['context_json']=json_encode($saved,JSON_THROW_ON_ERROR);
                }elseif(str_starts_with((string)($origin['goal']['primary']??''),'media:stoneward')){
                    require_once __DIR__.'/agent-media-stoneward-playback-v1.php';
                    $saved['stoneward_played']=vp3_stoneward_playback_offer_played_v1($pdo,$origin);
                    $row['context_json']=json_encode($saved,JSON_THROW_ON_ERROR);
                }else $row['context_json']=$origin['message']['context_json'];
            }
            continue;
        }

        $text='The information behind this Top of Mind finding is no longer available.';
        if($report&&vp3_initiative_followthrough_casual_marker_v1($context)){
            require_once __DIR__.'/agent-casual-checkin-v1.php';
            $kind=substr((string)array_key_first($output['evidence_revisions']),strlen('casual:'));
            $question=VP3_CASUAL_CHECKIN_QUESTIONS_V1[$kind]??null;
            if(is_string($question)&&$question===$row['message']&&$question===($output['question']??null))$text=$question;
        }
        $row['message']=$text;
        $row['context_json']='{"source":"agent_initiative","skip_brain_archive":true}';
    }
    unset($row);
    return $rows;
}

/** Verify a delivered Top of Mind report that used current Gmail evidence. */
function vp3_coordination_gmail_delivered_v1(PDO $pdo,array $user,int $agent,int $conversation): bool
{
    if(!function_exists('vp3_initiative_policy_v1')||!table_exists('agent_workflow_runs'))return false;
    require_once __DIR__.'/agent-coordination-work-v1.php';
    $binding=['agent_id'=>$agent,'conversation_id'=>$conversation];
    $principal=vp3_initiative_principal_v1($pdo,$user,$agent);
    foreach(vp3_coordination_runs_v1($pdo,$user,$binding) as $run){
        $result=vp3_coordination_verified_run_v1($pdo,$user,$run);
        if(($result['initiative_delivery']??null)!=='delivered')continue;
        $sources=$result['initiative_tool_result']['output']['evidence_revisions']??[];
        if(!array_filter(array_keys($sources),static fn(string $ref):bool=>str_starts_with($ref,'gmail:')))continue;
        try{
            $origin=vp3_coordination_chat_origin_v1($pdo,$user,$principal,$conversation,(int)($result['initiative_message_id']??0));
            if((int)$origin['run']['id']===(int)$run['id'])return true;
        }catch(RuntimeException $error){
            if(!in_array($error->getCode(),[403,409],true))throw $error;
        }
    }
    return false;
}
