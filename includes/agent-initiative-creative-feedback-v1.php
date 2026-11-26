<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-engagement-memory-v1.php';

/** Source-bound facts from existing decisions and receipts; no copied reply text or new preference store. */
function vp3_creative_feedback_v1(PDO $pdo,array $user,array $binding,array $evidence): array
{
    if(!$evidence)return [];
    $principal=vp3_initiative_principal_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding));
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $extract=static function(string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $refs=array_keys($evidence);
    $q=$pdo->prepare("SELECT e.id,e.run_id,e.metadata_json FROM agent_workflow_events e
        JOIN agent_workflow_runs r ON r.id=e.run_id AND r.owner_user_id=e.owner_user_id
        WHERE e.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.source_kind='agent_initiative' AND r.workflow_type='initiative' AND r.execution_target='cloud'
            AND r.capability_key='initiative.prepare' AND r.status='completed'
            AND CAST(".$extract('$.binding.conversation_id')." AS CHAR)=?
            AND ".$extract('$.primary')." IN (".implode(',',array_fill(0,count($refs),'?')).")
            AND e.event_type='initiative_owner_decision' AND e.actor_kind='owner' ORDER BY e.id DESC LIMIT 32");
    $q->execute([(int)$user['id'],(string)vp3_initiative_binding_agent_v1($binding),(string)$binding['conversation_id'],...$refs]);
    $items=[];$seen=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $eventRow){
        $metadata=json_decode((string)$eventRow['metadata_json'],true);
        if(!is_int($metadata['message_id']??null)||!is_int($metadata['reply_message_id']??null)
            ||!in_array($metadata['intent']??null,['discuss','advance','defer','decline','resolve'],true))continue;
        $item=['message_id'=>$metadata['message_id'],'owner_decision'=>$metadata['intent']];
        try{
            $post=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,(int)$binding['conversation_id'],$item['message_id']);
            $goal=$post['goal'];$decision=vp3_initiative_decision_reply_v1($post['state']);
            if((int)$post['run']['id']!==(int)$eventRow['run_id']||($decision['message_id']??null)!==$metadata['reply_message_id']
                ||($post['state']['last_intent']??null)!==$item['owner_decision'])continue;
            $tool=$goal['tool']??null;$primary=$goal['primary']??null;
            if(!in_array($tool,['work.prepare_private','canvas.prepare','image.prepare','playlist.prepare','chat.check_in'],true)
                ||!is_string($primary)||!isset($evidence[$primary])||empty($goal['sources'])
                ||!vp3_initiative_engagement_reply_verified_v1($pdo,$user,(int)$binding['conversation_id'],$item['message_id'],$post['state']))continue;
            foreach($goal['sources'] as $ref=>$revision)if(($evidence[$ref]['revision']??null)!==$revision)continue 2;
            $event=(int)$eventRow['id'];
            $key=$tool.'|'.$primary;if(isset($seen[$key]))continue;
            $seen[$key]=true;
            $outcome='not_requested';$child=$post['continuation'];
            if($child!==null){
                $outcome=match($child['status']){'completed'=>'prepared','failed'=>'failed','cancelled'=>'cancelled',default=>'pending'};
                if(in_array($outcome,['prepared','failed'],true)){
                    $q=$pdo->prepare("SELECT a.result_json,p.result_json AS receipt_json FROM agent_workflow_actions a
                        JOIN agent_workflow_receipts p ON p.id=(SELECT MAX(x.id) FROM agent_workflow_receipts x
                            WHERE x.run_id=a.run_id AND x.action_id=a.id AND x.owner_user_id=a.owner_user_id
                            AND x.attempt_no=a.attempt_count AND x.executor='cloud')
                        WHERE a.run_id=? AND a.owner_user_id=? AND a.sequence_no=1");
                    $q->execute([(int)$child['id'],(int)$user['id']]);$proof=$q->fetch(PDO::FETCH_ASSOC);
                    if(!$proof||$proof['result_json']!==$proof['receipt_json'])$outcome='unverified';
                }
            }
            $sources=$goal['sources'];ksort($sources);
            $items[]=['tool'=>$tool,'primary'=>$primary,'source_revisions'=>$sources,'owner_decision'=>$item['owner_decision'],
                'preparation_outcome'=>$outcome,'owner_objective'=>$item['owner_decision']==='resolve'?'owner_reported_closed':'unverified',
                'decision_revision'=>hash('sha256',json_encode([$event,$decision],JSON_THROW_ON_ERROR))];
            if(count($items)>=6)break;
        }catch(PDOException $error){throw $error;}
        catch(RuntimeException|JsonException $error){continue;}
    }
    return $items;
}

/** A decision suppresses only the same tool and unchanged source situation. Direct owner continuations remain separate. */
function vp3_creative_feedback_allows_v1(array $idea,array $feedback,array $evidence): bool
{
    foreach($feedback as $decision){
        if(($idea['tool']??null)!==$decision['tool']||($idea['primary']??null)!==$decision['primary']
            ||!in_array($decision['owner_decision'],['defer','decline','resolve'],true))continue;
        foreach($decision['source_revisions'] as $ref=>$revision)if(($evidence[$ref]['revision']??null)!==$revision)continue 2;
        return false;
    }
    return true;
}

/** Drop exact declined suggestions before the normal parser rejects a tool already removed by its adapter. */
function vp3_creative_feedback_response_v1(string $answer,array $feedback,array $evidence,bool $ideasOnly=false): string
{
    if(!$feedback)return $answer;
    try{$plan=json_decode($answer,false,32,JSON_THROW_ON_ERROR);}
    catch(JsonException){return $answer;}
    if(!$plan instanceof stdClass)return $answer;
    foreach(['jobs'=>['tool','primary','refs','focus','report'],'opportunities'=>['tool','primary','refs','objective','rationale']] as $field=>$keys){
        if($ideasOnly&&$field==='jobs')continue;
        $max=$field==='jobs'?vp3_initiative_limits_v1()['max_jobs']:($ideasOnly?3:8);
        if(!is_array($plan->$field??null)||!array_is_list($plan->$field))continue;
        if(count($plan->$field)>$max)return $answer;
        $plan->$field=array_values(array_filter($plan->$field,static function(mixed $row)use($keys,$feedback,$evidence):bool{
            if(!$row instanceof stdClass||count((array)$row)!==count($keys)||array_diff(array_keys((array)$row),$keys))return true;
            return vp3_creative_feedback_allows_v1((array)$row,$feedback,$evidence);
        }));
    }
    return json_encode($plan,JSON_THROW_ON_ERROR);
}
