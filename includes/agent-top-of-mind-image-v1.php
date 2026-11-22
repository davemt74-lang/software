<?php
declare(strict_types=1);

/**
 * Project a confirmed image workflow into the existing specialist feed.
 * No prompts, image bytes, provider credentials, or new jobs enter the feed.
 */
function vp3_top_of_mind_image_projection_v1(array $view,array $row,int $owner,int $agentId,int $cid,int $now,int $messageId=0): ?array
{
    if($owner<1||$agentId<0||$cid<1||(int)($row['owner_user_id']??0)!==$owner
        ||(int)($row['agent_id']??-1)!==$agentId||(int)($row['conversation_id']??0)!==$cid
        ||!is_string($view['id']??null)
        ||preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$view['id'])!==1
        ||($view['id']!==($row['generation_id']??null))
        ||(int)($view['job_id']??0)!==(int)($row['run_id']??0)
        ||(int)$view['job_id']<1)return null;

    $status=$view['status']??null;
    if(!is_string($status))return null;
    $terminal=in_array($status,['completed','file_unavailable','failed','cancelled','superseded'],true);
    if(!$terminal&&!in_array($status,['queued','generating','paused'],true))return null;
    $changed=strtotime((string)($row['workflow_updated_at']??$row['created_at']??'').' UTC');
    if($terminal&&($changed===false||$changed>$now+300||$changed<$now-3*86400))return null;
    $count=(int)($view['count']??0);
    $completed=(int)($view['completed_count']??0);
    if($count<1||$count>4||$completed<0||$completed>$count)return null;
    if($status==='completed'&&$completed!==$count)$status='file_unavailable';
    $lease=$view['active_lease_expires_at']??null;
    $expired=($view['lease_expired']??false)===true
        ||($status==='generating'&&(!is_int($lease)||$lease<=$now*1000));
    $state=match($status){
        'queued'=>'queued','generating'=>$expired?'interrupted':'working',
        'completed'=>'completed','file_unavailable'=>'blocked','failed'=>'failed',
        'paused'=>'paused','cancelled','superseded'=>'interrupted',
    };
    $label=$count===1?'image':'images';
    $detail=match($status){
        'queued'=>'An image request is queued for the Cloud worker.',
        'generating'=>$expired?'The image worker lease expired. Completion is not verified.':'Generating '.$count.' '.$label.' in this Chat.',
        'completed'=>$count.' '.$label.' generated and saved. Review '.($count===1?'it':'them').' in Chat or Image Studio.',
        'file_unavailable'=>'The image job finished, but not all saved images can be verified.',
        'failed'=>'The image request failed. Check its job receipt before trying again.',
        'paused'=>'Image generation is paused.',
        'cancelled'=>'The image request was cancelled.',
        'superseded'=>'The image request was superseded.',
    };
    $url=url('/chat.php?agent='.($agentId>0?$agentId:'system').'&conversation_id='.$cid);
    if($messageId>0&&$messageId===(int)($row['user_message_id']??0))$url.='&message_id='.$messageId;
    $result=['id'=>'image-generator','state'=>$state,'detail'=>$detail,'progress'=>null,
        'run_id'=>(int)$view['job_id'],'conversation_id'=>$cid,'result_url'=>$url];
    if($messageId>0&&$messageId===(int)($row['user_message_id']??0))$result['message_id']=$messageId;
    if($state==='working'&&is_int($lease)&&$lease>$now*1000)
        $result['lease_expires_at']=gmdate(DATE_ATOM,intdiv($lease,1000));
    return $result;
}

/** Read only canonical owner/Agent/Chat-bound image job and asset receipts. */
function vp3_top_of_mind_image_status_v1(PDO $pdo,array $user,int $agentId,int $cid,int $now): ?array
{
    if($cid<1||$agentId<0||(int)($user['id']??0)<1||!table_exists('agent_image_generations_v1')
        ||!table_exists('agent_workflow_runs'))return null;
    require_once __DIR__.'/agent-image-generation-v1.php';
    $owner=(int)$user['id'];
    $query=$pdo->prepare("SELECT g.*,r.updated_at AS workflow_updated_at FROM agent_image_generations_v1 g
        JOIN chat_conversations c ON c.id=g.conversation_id AND c.user_id=g.owner_user_id
            AND COALESCE(c.user_agent_id,0)=g.agent_id
        JOIN agent_workflow_runs r ON r.id=g.run_id AND r.owner_user_id=g.owner_user_id
            AND COALESCE(r.agent_id,0)=g.agent_id AND r.source_kind='image_generation'
            AND r.source_key=g.generation_id AND r.capability_key='images.generate'
            AND r.execution_target='cloud' AND r.origin='chat'
        WHERE g.owner_user_id=? AND g.agent_id=? AND g.conversation_id=?
        ORDER BY g.run_id DESC LIMIT 10");
    $query->execute([$owner,$agentId,$cid]);
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        try{
            $view=vp3_image_view_v1($pdo,$user,$row);
            $messageId=0;
            if((int)($row['user_message_id']??0)>0){
                $origin=$pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND conversation_id=?
                    AND user_id=? AND role='user' LIMIT 1");
                $origin->execute([(int)$row['user_message_id'],$cid,$owner]);
                $messageId=(int)$origin->fetchColumn();
                if($messageId<1)continue; // The original owner request is no longer present.
            }
            $status=vp3_top_of_mind_image_projection_v1($view,$row,$owner,$agentId,$cid,$now,$messageId);
            if($status!==null)return $status; // Latest authorized job; do not repeatedly hydrate older image assets.
        }catch(PDOException $error){throw $error;}
        catch(RuntimeException|JsonException $error){continue;} // Revoked or changed source stays private.
    }
    return null;
}
