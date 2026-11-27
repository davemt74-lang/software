<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';

/** Project confirmed Curiosity answers as private, revision-bound reasoning evidence. */
function vp3_initiative_curiosity_evidence_v1(PDO $pdo,array $scope,?array $selectedRefs,array &$coverage): array
{
    $ids=null;
    if($selectedRefs!==null){
        $ids=[];
        foreach($selectedRefs as $ref){
            if(is_string($ref)&&preg_match('/\Acuriosity-finding:([a-f0-9]{32})\z/D',$ref,$match))$ids[$match[1]]=$match[1];
        }
        if(!$ids)return [];
        if(count($ids)>32)throw new InvalidArgumentException('Too many Curiosity finding references.');
        $ids=array_values($ids);
    }
    if(vp3_participant_scope_v1()!==null){
        $coverage['curiosity']='forbidden';
        return [];
    }
    $adapter=__DIR__.'/agent-curiosity-v1.php';
    if(!is_file($adapter)){
        $coverage['curiosity']='unavailable';
        return [];
    }
    require_once $adapter;
    try{
        $agent=$scope['agent_id'];
        $confirmed=vp3_curiosity_confirmed_v1($pdo,$scope['user'],$agent,$scope['conversation_id'],$ids);
    }catch(RuntimeException $error){
        $coverage['curiosity']=in_array((int)$error->getCode(),[403,404],true)?'forbidden':'unavailable';
        return [];
    }
    $coverage['curiosity']='ready';
    if($ids===null)$confirmed=array_slice($confirmed,0,6,true);
    else $confirmed=array_intersect_key($confirmed,array_fill_keys($ids,true));
    $evidence=[];
    foreach($confirmed as $id=>$row){
        if(!is_string($id)||preg_match('/\A[a-f0-9]{32}\z/D',$id)!==1||($row['id']??null)!==$id
            ||!is_string($row['revision']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$row['revision'])!==1
            ||!is_int($row['conversation_id']??null)||$row['conversation_id']<1
            ||!is_int($row['message_id']??null)||$row['message_id']<1)continue;
        $title=vp3_agent_activity_text_v1($row['title']??null,160);
        $summary=vp3_agent_activity_text_v1($row['summary']??null,800);
        if($title===''||$summary==='')continue;
        $ref='curiosity-finding:'.$id;
        $evidence[$ref]=['ref'=>$ref,'kind'=>'curiosity_finding','title'=>$title,
            'detail'=>'The owner confirmed this in a Curiosity reply. '.$summary,
            'url'=>vp3_agent_activity_chat_href_v1($agent,$row['conversation_id']).'&message_id='.$row['message_id'],
            'revision'=>$row['revision']];
        require_once __DIR__.'/agent-curiosity-gmail-draft-v1.php';
        try{$request=vp3_curiosity_gmail_request_v1($pdo,$scope['user'],$agent,$scope['conversation_id'],$id);}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException|DomainException|JsonException $error){$request=null;}
        if($request!==null){
            $evidence[$ref]['curiosity_gmail_reply']=$request['request_key'];
            $evidence[$ref]['revision']=hash('sha256',$row['revision'].'|gmail-reply:'.$request['request_key']);
            $evidence[$ref]['detail'].=' This verified answer requests private Gmail reply preparation; use gmail.prepare_reply when ready.';
            require_once __DIR__.'/agent-curiosity-gmail-outcomes-v1.php';
            try{$response=vp3_curiosity_gmail_response_v1($pdo,$scope['user'],vp3_initiative_policy_v1($scope['user'],$agent),$ref);}
            catch(PDOException $error){throw $error;}
            catch(RuntimeException|DomainException|JsonException $error){$response=null;}
            if($response!==null){
                $evidence[$ref]['gmail_response']=$response['response'];
                $evidence[$ref]['revision']=hash('sha256',$evidence[$ref]['revision'].'|response:'.$response['response']['revision']);
                $evidence[$ref]['gmail_response_native_revision']=$evidence[$ref]['revision'];
                $outcome=vp3_curiosity_gmail_outcome_v1($pdo,$scope['user'],vp3_initiative_policy_v1($scope['user'],$agent),$response['response']);
                if($outcome!==null){
                    $evidence[$ref]['curiosity_gmail_outcome']=$outcome;
                    $evidence[$ref]['revision']=hash('sha256',$evidence[$ref]['revision'].'|assessment:'.$outcome['receipt_key']);
                }
            }
        }
    }
    return $evidence;
}
