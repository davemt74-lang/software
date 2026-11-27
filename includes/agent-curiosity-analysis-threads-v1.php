<?php
declare(strict_types=1);

require_once __DIR__.'/agent-curiosity-documents-v1.php';

/** A model can select a supplied gap, never invent a source, question, action or permission. */
function vp3_curiosity_analysis_questions_v1(mixed $value,?array $documents=null): ?array
{
    if(!is_array($value)||!array_is_list($value)||count($value)>2)return null;
    $seen=[];$out=[];
    foreach($value as $question){
        if(!is_array($question)||count($question)!==2||array_diff(array_keys($question),['source_ref','question_index'])
            ||!is_string($question['source_ref'])||!preg_match('/\Aattachment-review:[a-f0-9]{32}\z/D',$question['source_ref'])
            ||!is_int($question['question_index'])||$question['question_index']<0||$question['question_index']>7)return null;
        $key=$question['source_ref'].':'.$question['question_index'];
        if(isset($seen[$key]))return null;
        $seen[$key]=true;
        if($documents!==null&&empty($documents[$question['source_ref']]['questions'][$question['question_index']]))return null;
        $out[]=['source_ref'=>$question['source_ref'],'question_index'=>$question['question_index']];
    }
    return $out;
}

/** Verify the canonical completed worker attempt, without recursively asking for a new analysis. */
function vp3_curiosity_analysis_thread_current_v1(PDO $pdo,array $scope,array $thread,?array $documents=null): bool
{
    $origin=$thread['analysis_origin']??null;
    if(!is_array($origin)||($origin['conversation_id']??null)!==$scope['conversation_id']
        ||!is_int($origin['run_id']??null)||$origin['run_id']<1)return false;
    $documents??=vp3_curiosity_document_reports_v1($pdo,$scope['user'],vp3_initiative_policy_v1($scope['user'],$scope['agent_id']));
    $doc=$documents[$origin['source_ref']??'']??null;
    // A later review cannot erase an authenticated owner answer or reopen a skipped gap.
    // Keep the original historical citation while still requiring today's exact mailbox grant.
    if($doc===null&&in_array($thread['state'],['answered','skipped','deferred'],true)&&is_string($origin['source_ref']??null)){
        $doc=vp3_curiosity_document_reports_v1($pdo,$scope['user'],vp3_initiative_policy_v1($scope['user'],$scope['agent_id']),$origin['source_ref'])[$origin['source_ref']]??null;
    }
    if($doc===null||($origin['revision']??null)!==$doc['revision']||($origin['document_key']??null)!==$doc['document_key']
        ||($doc['questions'][$origin['question_index']??-1]??null)!==($origin['question']??null))return false;
    $q=$pdo->prepare("SELECT r.goal,a.result_json,p.result_json AS receipt_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1
            AND a.status='completed' AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'
        JOIN agent_workflow_receipts p ON p.id=(SELECT MAX(x.id) FROM agent_workflow_receipts x WHERE x.run_id=r.id
            AND x.action_id=a.id AND x.owner_user_id=r.owner_user_id AND x.attempt_no=a.attempt_count AND x.executor='cloud')
        WHERE r.id=? AND r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=? AND r.status='completed'
            AND r.workflow_type='initiative' AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
            AND r.execution_target='cloud' AND p.status='completed'");
    $q->execute([$origin['run_id'],(int)$scope['user']['id'],(string)$scope['agent_id']]);$proof=$q->fetch(PDO::FETCH_ASSOC);
    if(!$proof||$proof['result_json']!==$proof['receipt_json'])return false;
    $goal=json_decode($proof['goal'],true);$saved=json_decode($proof['result_json'],true);
    $result=$saved['initiative_tool_result']??null;
    if(($goal['tool']??null)!=='curiosity.analyze'||($goal['report']??null)!==false
        ||($goal['binding']['conversation_id']??null)!==$scope['conversation_id']||($saved['effect_state']??null)!=='private_draft_ready'
        ||!is_array($result)||vp3_curiosity_analysis_tool_result_v1($result)===null
        ||($saved['initiative_draft']??null)!==$result['draft']
        ||($goal['sources']??null)!==[$result['output']['context_ref']=>$result['output']['context_revision']])return false;
    return in_array(['source_ref'=>$origin['source_ref'],'question_index'=>$origin['question_index']],$result['output']['questions']??[],true);
}

/** Persist selections in the existing thread store, atomically with the worker's receipt. */
function vp3_curiosity_analysis_threads_commit_v1(PDO $pdo,array $user,array $binding,array $result,int $run): void
{
    if(!$pdo->inTransaction())throw new LogicException('Save Curiosity questions with the worker receipt.');
    $choices=$result['output']['questions']??[];
    if(!$choices)return;
    vp3_initiative_owner_lock_v1($pdo,$user);
    $scope=vp3_curiosity_private_scope_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding),(int)$binding['conversation_id']);
    $documents=vp3_curiosity_document_reports_v1($pdo,$user,$binding);
    if(vp3_curiosity_analysis_questions_v1($choices,$documents)===null)throw new Vp3InitiativeSourcesChangedV1();
    $threads=vp3_curiosity_read_threads_v1($pdo,$scope);
    foreach($choices as $choice){
        $doc=$documents[$choice['source_ref']];$prompt=$doc['questions'][$choice['question_index']];
        $id=vp3_curiosity_thread_id_v1($scope,'attachment:'.$scope['conversation_id'].':'.$doc['document_key'].':'.mb_strtolower(trim($prompt),'UTF-8'));
        $thread=$threads[$id]??null;
        // Reanalysis, a changed request ID and another worker cannot reopen an owner-closed gap.
        if($thread!==null&&$thread['state']!=='open')continue;
        if($thread!==null&&($thread['analysis_origin']['revision']??null)===$doc['revision'])continue;
        if($thread===null){
            $thread=vp3_curiosity_new_thread_v1($scope,['id'=>'attachment-review','title'=>$doc['title']],time());
            $thread['id']=$id;$thread['focused']=true;$thread['conversation_id']=$scope['conversation_id'];$thread['questions']=[];
        }elseif($thread['questions']){
            $last=count($thread['questions'])-1;
            $thread['questions'][$last]['state']='deferred';$thread['questions'][$last]['reason']='source_changed';
        }
        $thread['questions'][]=vp3_curiosity_question_shape_v1($id,count($thread['questions'])+1,$prompt);
        $thread['updated_at']=time();
        $thread['sources']=[['label'=>'Saved attachment review: '.$doc['title'],'href'=>$doc['href'],
            'attachment_review_id'=>substr($doc['ref'],strlen('attachment-review:')),
            'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id']]];
        $thread['analysis_origin']=['run_id'=>$run,'conversation_id'=>$scope['conversation_id'],'source_ref'=>$doc['ref'],
            'revision'=>$doc['revision'],'document_key'=>$doc['document_key'],'question_index'=>$choice['question_index'],'question'=>$prompt];
        vp3_curiosity_write_thread_v1($scope,$thread);
        $threads[$id]=$thread;
    }
}

/** Owner responses are input; writing/reading a proposed question never manufactures new work. */
function vp3_curiosity_analysis_answers_v1(PDO $pdo,array $user,array $binding,array $documents): array
{
    $scope=vp3_curiosity_private_scope_v1($pdo,$user,vp3_initiative_binding_agent_v1($binding),(int)$binding['conversation_id']);
    if(!agent_brain_schema_ready())return [];
    $out=[];
    foreach(vp3_curiosity_read_threads_v1($pdo,$scope) as $thread){
        if(!isset($thread['analysis_origin'])||!vp3_curiosity_analysis_thread_current_v1($pdo,$scope,$thread,$documents))continue;
        foreach($thread['questions'] as $question){
            if(!in_array($question['state'],['answered','skipped','deferred'],true)||!isset($question['reply_hash'])
                ||!vp3_curiosity_live_question_post_v1($pdo,$scope,$question))continue;
            $reply=vp3_curiosity_reply_source_v1($pdo,$scope,$question['conversation_id'],$question['reply_message_id'],$question['reply_hash'],$question['message_id']);
            if($reply===null)continue;
            $out[]=['source_ref'=>$thread['analysis_origin']['source_ref'],'question'=>$question['question'],'state'=>$question['state'],
                'answer'=>vp3_curiosity_safe_text_v1($reply['message'],400),
                'revision'=>hash('sha256',$thread['id'].'|'.$question['id'].'|'.$question['state'].'|'.$question['reply_hash'])];
            if(count($out)>=8)return $out;
        }
    }
    return $out;
}
