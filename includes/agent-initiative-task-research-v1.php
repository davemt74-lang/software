<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-office-records-v1.php';
require_once __DIR__.'/agent-public-research-plan-v1.php';

function vp3_initiative_task_research_id_v1(string $ref): ?int
{
    return preg_match('/\Atask-research:([1-9][0-9]{0,17})\z/D',$ref,$match)?(int)$match[1]:null;
}

/** A grant is independent of task lifecycle metadata, which Brain may rewrite. */
function vp3_initiative_task_research_evidence_v1(PDO $pdo,array $scope,?array $refs,array &$coverage): array
{
    if($refs!==null){
        $ids=[];
        foreach($refs as $ref){
            if(is_string($ref)&&($id=vp3_initiative_task_research_id_v1($ref))!==null)$ids[]=$id;
        }
        if(!$ids)return [];
    }
    try{
        vp3_initiative_office_load_v1($pdo,'task');
        $user=$scope['user'];$owner=(int)$user['id'];$agentId=$scope['agent_id'];
        vp3_task_list_authorize_v1($pdo,$user,$scope['principal'],$scope['conversation_id']);
        if($refs===null){
            [$where,$params]=vp3_agent_memory_scope_sql_v410($agentId);
            $json="CASE WHEN JSON_VALID(metadata_json) THEN metadata_json ELSE '{}' END";
            $hasTopic=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
                ?"JSON_TYPE($json,'$.topic')='text'":"JSON_TYPE(JSON_EXTRACT($json,'$.topic'))='STRING'";
            $query=$pdo->prepare("SELECT subject FROM agent_memory_items WHERE user_id=? AND $where AND is_active=1
                AND memory_type='initiative_policy' AND subject LIKE 'public-research-task:%'
                AND $hasTopic
                ORDER BY last_seen_at DESC,id DESC LIMIT 17");
            $query->execute([$owner,...$params]);$ids=[];
            foreach($query->fetchAll(PDO::FETCH_COLUMN) as $subject){
                if(preg_match('/\Apublic-research-task:([1-9][0-9]{0,17})\z/D',$subject,$match))$ids[]=(int)$match[1];
            }
        }
        $limited=$refs===null&&count($ids)>16;$out=[];
        foreach($ids as $id){
            $grant=vp3_initiative_in_scope_v1($user,$agentId,static fn()=>agent_brain_v122_memory($user,'initiative_policy','public-research-task:'.$id))['metadata']??[];
            $topic=$grant['topic']??null;$messageId=$grant['authorized_message_id']??null;
            if(!is_string($topic)||!vp3_topic_research_subject_valid_v1($topic)||!is_int($messageId)||$messageId<1)continue;
            $task=vp3_initiative_office_task_state_v1($pdo,$scope,$id);
            if(!$task||!in_array($task['status'],['open','in_progress'],true))continue;
            if($refs===null&&count($out)>=5){$limited=true;break;}
            $ref='task-research:'.$id;
            $out[$ref]=['ref'=>$ref,'kind'=>'task_research','title'=>$topic,
                'detail'=>'The owner approved this exact public search topic for open task #'.$id.'. Look for a useful next step. Keep the task itself private and unchanged.',
                'url'=>url('/chat.php?agent='.($agentId?:'system').'&conversation_id='.$scope['conversation_id']),
                'revision'=>hash('sha256',json_encode([$ref,$owner,$agentId,$task['source_hash'],$topic,$messageId],JSON_THROW_ON_ERROR))];
        }
        $coverage['task_research']=$limited?'limited':'ready';
        return $out;
    }catch(Throwable $error){
        $coverage['task_research']=in_array($error->getCode(),[403,404],true)?'forbidden':'unavailable';
        return [];
    }
}

/** Title selection stays within the active Agent and uses exact case-insensitive matches. */
function vp3_initiative_task_research_title_ids_v1(PDO $pdo,array $user,array $principal,string $title): array
{
    [$scope,$params]=vp3_agent_memory_scope_sql_v410(vp3_task_list_agent_v1($principal));
    $query=$pdo->prepare("SELECT id,subject FROM agent_memory_items WHERE user_id=? AND $scope AND is_active=1
        AND memory_type IN ('task','commitment') AND LOWER(subject)=LOWER(?) ORDER BY id LIMIT 101");
    $query->execute([(int)$user['id'],...$params,$title]);
    $ids=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $id=(int)$row['id'];
        if(mb_strtolower((string)$row['subject'])===mb_strtolower($title)&&vp3_task_list_task_allowed_v1($pdo,$user,$principal,$id))$ids[]=$id;
    }
    return $ids;
}

/** Only an exact, saved owner turn can set or revoke the topic a worker may search. */
function vp3_initiative_task_research_chat_v1(PDO $pdo,array $user,array $principal,array $plan,int $cid,int $messageId,callable $check): array
{
    require_once __DIR__.'/agent-initiative-v1.php';
    $id=$plan['task_id'];$topic=$plan['topic']!==''?$plan['topic']:null;
    $owner=(int)$user['id'];$agentId=(int)($principal['agent_id']??0);
    $result=static fn(string $answer,string $status,string $reason='',int $recordId=0):array=>[
        'handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('tasks.manage','set_public_research',$status,$reason,$recordId),
    ];
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$principal,$plan,$cid,$messageId,$check,$id,$topic,$owner,$agentId,$result):array{
        $pdo->beginTransaction();
        try{
            vp3_initiative_owner_lock_v1($pdo,['id'=>$owner]);
            $live=$check();
            vp3_initiative_office_load_v1($pdo,'task');
            vp3_task_list_authorize_v1($pdo,$live,$principal,$cid);
            $turn=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
            $turn->execute([$messageId,$cid,$owner]);
            if(vp3_public_research_plan_v1((string)$turn->fetchColumn())!==$plan)throw new RuntimeException('Owner research choice changed.',403);
            if($agentId>0&&!function_exists('user_data_policy_can_use_v236'))throw new RuntimeException('Task data policy is unavailable.',503);
            if($id===0){
                $ids=vp3_initiative_task_research_title_ids_v1($pdo,$live,$principal,$plan['task_title']);
                if(count($ids)!==1){
                    $pdo->rollBack();
                    if(!$ids)return $result('I couldn’t find a task with that exact title in this Agent.','no_matches');
                    return $result('More than one task has that title. Use a task number to choose: #'.implode(', #',array_slice($ids,0,10)).'.',
                        'clarification_required','invalid_plan');
                }
                $id=$ids[0];
            }
            $subject='public-research-task:'.$id;
            $current=agent_brain_v122_memory($live,'initiative_policy',$subject)['metadata']??[];
            // An owner can revoke a saved grant by ID even when task access was later lost.
            if($topic!==null||!is_int($current['message_id']??null)){
                vp3_task_list_existing_task_v1($pdo,$live,$principal,$id);
            }
            if($topic!==null){
                $scope=['user'=>$live,'principal'=>$principal,'agent_id'=>$agentId,'conversation_id'=>$cid];
                $task=vp3_initiative_office_task_state_v1($pdo,$scope,$id);
                if(!$task||!in_array($task['status'],['open','in_progress'],true)){
                    $pdo->rollBack();
                    return $result('Task #'.$id.' needs to be open before it can be used for public research.','blocked','scope_restricted');
                }
            }
            if(($current['message_id']??0)>=$messageId){
                $pdo->commit();
                return $result('A public research choice for task #'.$id.' was already saved.','completed','',$id);
            }
            $topicChanged=$topic!==($current['topic']??null);
            $grant=['topic'=>$topic,'message_id'=>$messageId,'authorized_message_id'=>$topicChanged?$messageId:($current['authorized_message_id']??$messageId)];
            // Keep the latest turn ID after revocation, so an older retry cannot restore a grant.
            if(agent_brain_v122_upsert_system_memory($live,'initiative_policy',$subject,'Owner public research choice for task #'.$id.'.',$grant)<1){
                throw new RuntimeException('Public research choice could not be saved.',503);
            }
            $saved=agent_brain_v122_memory($live,'initiative_policy',$subject)['metadata']??[];
            if(array_intersect_key($saved,$grant)!==$grant){
                throw new RuntimeException('Public research choice could not be verified.',503);
            }
            $check();
            $policy=vp3_initiative_policy_v1($live,$agentId);
            $pdo->commit();
            if($topicChanged&&$topic!==null&&!empty($policy['enabled'])){
                // Wake the canonical scheduler, never the search provider, for this new owner decision.
                try{vp3_initiative_schedule_scoped_v1($pdo,$live,['task:'.$id,'task-research:'.$id]);}
                catch(Throwable $error){
                    if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('initiative.schedule_failed',['user_id'=>$owner,'error_class'=>get_class($error)]);
                }
            }
            return $result($topic===null?'Public research is off for task #'.$id.'.'
                :'I saved “'.$topic.'” as the public search topic for task #'.$id.'. '.(!empty($policy['enabled'])
                    ?'Initiative can research it and bring findings to your linked Agent Chat.'
                    :'Enable Initiative in Agent Chat to let me research it in the background.'),'completed','',$id);
        }catch(Throwable $error){
            if($pdo->inTransaction())$pdo->rollBack();
            if(in_array($error->getCode(),[403,404],true))return $result('That task is unavailable in this Agent.','blocked','scope_restricted');
            return $result('I couldn’t verify the public research choice for that task.','failed','outcome_unverified');
        }
    });
}
