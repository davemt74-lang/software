<?php
declare(strict_types=1);

const STONEFELLOW_AGENT_BRAIN_V122 = 'agent-brain-phase2-v122-20260826';

function agent_brain_v122_memory_hash(string $type,string $subject): string
{
    return sha1('v122|'.agent_brain_normalize($type).'|'.agent_brain_normalize($subject));
}

function agent_brain_v122_upsert_system_memory(array $user,string $type,string $subject,string $text,array $metadata=[],float $confidence=0.95): int
{
    if(!agent_brain_schema_ready())return 0;
    $pdo=db();$uid=(int)($user['id']??0);
    $type=mb_substr(agent_brain_normalize($type),0,40);
    $subject=trim(mb_strimwidth($subject,0,190,'…'));
    $text=trim(mb_strimwidth($text,0,6000,'…'));
    if(!$pdo||$uid<1||$type===''||$subject===''||$text==='')return 0;
    $hash=agent_brain_v122_memory_hash($type,$subject);
    $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $stmt=$pdo->prepare(
        'INSERT INTO agent_memory_items
         (user_id,memory_type,subject,memory_text,memory_hash,source_archive_id,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
         VALUES (?,?,?,?,?,NULL,?,1,NOW(),NOW(),1,?)
         ON DUPLICATE KEY UPDATE
           memory_text=VALUES(memory_text),confidence=VALUES(confidence),last_seen_at=NOW(),is_active=1,metadata_json=VALUES(metadata_json)'
    );
    $stmt->execute([$uid,$type,$subject,$text,$hash,max(0.0,min(1.0,$confidence)),is_string($json)?$json:'{}']);
    $id=(int)$pdo->lastInsertId();
    if($id<1){$q=$pdo->prepare('SELECT id FROM agent_memory_items WHERE user_id=? AND memory_hash=? LIMIT 1');$q->execute([$uid,$hash]);$id=(int)$q->fetchColumn();}
    return $id;
}

function agent_brain_v122_memory(array $user,string $type,string $subject): ?array
{
    if(!agent_brain_schema_ready())return null;
    $pdo=db();$uid=(int)($user['id']??0);if(!$pdo||$uid<1)return null;
    try{
        $stmt=$pdo->prepare('SELECT * FROM agent_memory_items WHERE user_id=? AND memory_hash=? AND is_active=1 LIMIT 1');
        $stmt->execute([$uid,agent_brain_v122_memory_hash($type,$subject)]);$row=$stmt->fetch()?:null;
        if(!$row)return null;$metadata=json_decode((string)($row['metadata_json']??''),true);$row['metadata']=is_array($metadata)?$metadata:[];return $row;
    }catch(Throwable $e){return null;}
}

function agent_brain_v122_activity_context(array $user): array
{
    $pdo=db();$uid=(int)($user['id']??0);$base=['surface'=>'chat','task_title'=>'Agent Chat','track_id'=>0,'project_id'=>0,'conversation_id'=>0];
    if(!$pdo||$uid<1||!table_exists('agent_activity_state'))return $base;
    try{
        $stmt=$pdo->prepare('SELECT surface,task_title,details_json FROM agent_activity_state WHERE user_id=? LIMIT 1');$stmt->execute([$uid]);$row=$stmt->fetch()?:[];
        $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
        return [
            'surface'=>(string)($row['surface']??'chat'),'task_title'=>(string)($row['task_title']??'Agent Chat'),
            'track_id'=>max(0,(int)($details['track_id']??0)),'project_id'=>max(0,(int)($details['project_id']??0)),
            'conversation_id'=>max(0,(int)($details['conversation_id']??0)),
        ];
    }catch(Throwable $e){return $base;}
}

function agent_brain_v122_project_label(array $user,array $activity): string
{
    $title=trim((string)($activity['task_title']??''));
    if($title!==''&&!in_array($title,['Agent Chat','Signed out'],true))return mb_strimwidth($title,0,190,'…');
    $pdo=db();if(!$pdo)return '';
    try{
        if(($id=(int)($activity['track_id']??0))>0){$s=$pdo->prepare('SELECT title FROM tracks WHERE id=? LIMIT 1');$s->execute([$id]);$v=trim((string)$s->fetchColumn());if($v!=='')return 'Track · '.$v;}
        if(($id=(int)($activity['project_id']??0))>0&&table_exists('video_editor_projects')){$s=$pdo->prepare('SELECT title FROM video_editor_projects WHERE id=? AND user_id=? LIMIT 1');$s->execute([$id,(int)$user['id']]);$v=trim((string)$s->fetchColumn());if($v!=='')return 'Video · '.$v;}
    }catch(Throwable $e){}
    return '';
}

function agent_brain_v122_goal_from_text(string $text): string
{
    foreach(agent_brain_sentences($text) as $sentence){
        if(preg_match('/\b(?:i\s+(?:want|need|plan|intend|am trying)|we\s+(?:need|want|should)|let(?:\x27|’)s|goal|working on|finish|complete|build|fix|release)\b/iu',$sentence))return mb_strimwidth(trim($sentence),0,500,'…');
    }
    return '';
}

function agent_brain_v122_state(array $user,int $conversationId): array
{
    $subject='conversation:'.max(0,$conversationId);$row=agent_brain_v122_memory($user,'conversation_state',$subject);
    $metadata=$row['metadata']??[];
    return is_array($metadata)&&isset($metadata['conversation_id'])?$metadata:[
        'conversation_id'=>max(0,$conversationId),'current_surface'=>'chat','current_project'=>'','current_goal'=>'','current_task'=>'',
        'pending_question'=>'','last_agent_action'=>'','next_expected_action'=>'','last_user_message'=>'','last_agent_message'=>'',
        'last_message_id'=>0,'last_role'=>'','updated_at'=>date(DATE_ATOM)
    ];
}

function agent_brain_v122_update_state(array $user,int $conversationId,array $message): array
{
    $conversationId=max(0,$conversationId);if($conversationId<1)return [];
    $state=agent_brain_v122_state($user,$conversationId);$activity=agent_brain_v122_activity_context($user);
    $role=(string)($message['role']??'');$text=trim((string)($message['message']??''));$messageId=max(0,(int)($message['id']??0));
    $state['conversation_id']=$conversationId;$state['current_surface']=(string)($activity['surface']??'chat');
    $project=agent_brain_v122_project_label($user,$activity);if($project!=='')$state['current_project']=$project;
    if($role==='user'){
        $state['last_user_message']=mb_strimwidth($text,0,700,'…');$state['current_task']=mb_strimwidth($text,0,500,'…');
        $goal=agent_brain_v122_goal_from_text($text);if($goal!=='')$state['current_goal']=$goal;
        $state['pending_question']=str_contains($text,'?')?mb_strimwidth($text,0,500,'…'):'';
        $state['next_expected_action']='Stonefellow response or action';
    }elseif($role==='assistant'){
        $state['last_agent_message']=mb_strimwidth($text,0,900,'…');$state['last_agent_action']=mb_strimwidth($text,0,500,'…');
        $sentences=agent_brain_sentences($text);$last=$sentences?trim((string)end($sentences)):'';
        if($last!==''&&str_contains($last,'?')){$state['pending_question']=mb_strimwidth($last,0,500,'…');$state['next_expected_action']='User response requested';}
        else{$state['pending_question']='';$state['next_expected_action']='Continue the highest-value active task';}
    }
    $state['last_message_id']=$messageId;$state['last_role']=$role;$state['updated_at']=date(DATE_ATOM);
    $summaryParts=[];
    foreach(['current_project'=>'Project','current_goal'=>'Goal','current_task'=>'Task','pending_question'=>'Open question','next_expected_action'=>'Next'] as $key=>$label){if(trim((string)($state[$key]??''))!=='')$summaryParts[]=$label.': '.(string)$state[$key];}
    agent_brain_v122_upsert_system_memory($user,'conversation_state','conversation:'.$conversationId,implode(' · ',$summaryParts)?:'Conversation state is active.',$state,0.99);
    return $state;
}

function agent_brain_v122_rollup(array $user,int $conversationId,array $state=[]): array
{
    $pdo=db();$uid=(int)($user['id']??0);$conversationId=max(0,$conversationId);if(!$pdo||$uid<1||$conversationId<1||!table_exists('chat_messages'))return [];
    try{
        $stmt=$pdo->prepare('SELECT id,role,message,created_at FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 28');$stmt->execute([$conversationId]);$messages=array_reverse($stmt->fetchAll()?:[]);
        if(!$messages)return [];$state=$state?:agent_brain_v122_state($user,$conversationId);
        $decisions=[];$commitments=[];$questions=[];
        foreach($messages as $row){
            $text=trim((string)$row['message']);if($text==='')continue;
            foreach(agent_brain_sentences($text) as $sentence){
                if(count($decisions)<5&&preg_match('/\b(?:decid(?:e|ed)|use|keep|change|set|choose|selected|locked|approved)\b/iu',$sentence))$decisions[]=mb_strimwidth(trim($sentence),0,320,'…');
                if(count($commitments)<5&&preg_match('/\b(?:need to|have to|will|todo|to-do|finish|complete|fix|build|follow up|next)\b/iu',$sentence))$commitments[]=mb_strimwidth(trim($sentence),0,320,'…');
                if(count($questions)<4&&str_contains($sentence,'?'))$questions[]=mb_strimwidth(trim($sentence),0,320,'…');
            }
        }
        $last=end($messages);$lastId=(int)($last['id']??0);
        $parts=[];
        if(($v=trim((string)($state['current_project']??'')))!=='')$parts[]='Current project: '.$v.'.';
        if(($v=trim((string)($state['current_goal']??'')))!=='')$parts[]='Current goal: '.$v.'.';
        if(($v=trim((string)($state['current_task']??'')))!=='')$parts[]='Current task: '.$v.'.';
        if(($v=trim((string)($state['last_user_message']??'')))!=='')$parts[]='Latest user request: '.$v;
        if(($v=trim((string)($state['last_agent_action']??'')))!=='')$parts[]='Latest Stonefellow action/response: '.$v;
        if($decisions)$parts[]='Recent decisions: '.implode(' | ',array_values(array_unique($decisions))).'.';
        if($commitments)$parts[]='Open/recurring commitments: '.implode(' | ',array_values(array_unique($commitments))).'.';
        if(($v=trim((string)($state['pending_question']??'')))!=='')$parts[]='Open question: '.$v;
        $summary=mb_strimwidth(implode("\n",$parts),0,5800,'…');
        $metadata=['conversation_id'=>$conversationId,'last_message_id'=>$lastId,'message_count'=>count($messages),'state'=>$state,'updated_at'=>date(DATE_ATOM),'questions'=>array_values(array_unique($questions))];
        agent_brain_v122_upsert_system_memory($user,'conversation_summary','conversation:'.$conversationId,$summary?:'Conversation summary is active.',$metadata,0.98);
        return ['text'=>$summary,'metadata'=>$metadata];
    }catch(Throwable $e){return [];}
}

function agent_brain_v122_refresh_latest(array $user): void
{
    if(!agent_brain_schema_ready()||!table_exists('chat_messages')||!table_exists('chat_conversations'))return;
    $pdo=db();$uid=(int)($user['id']??0);if(!$pdo||$uid<1)return;
    try{
        $stmt=$pdo->prepare('SELECT m.id,m.conversation_id,m.role,m.message FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id WHERE c.user_id=? ORDER BY m.id DESC LIMIT 1');
        $stmt->execute([$uid]);$latest=$stmt->fetch()?:null;if(!$latest)return;
        $cid=(int)$latest['conversation_id'];$state=agent_brain_v122_state($user,$cid);
        if((int)($state['last_message_id']??0)===(int)$latest['id'])return;
        $state=agent_brain_v122_update_state($user,$cid,$latest);agent_brain_v122_rollup($user,$cid,$state);
    }catch(Throwable $e){error_log('Stonefellow v122 state refresh failed: '.$e->getMessage());}
}

function agent_brain_v122_register_shutdown_refresh(): void
{
    static $registered=false;if($registered)return;$registered=true;
    register_shutdown_function(static function(): void {
        try{
            $user=current_user();if(!$user||!has_permission('chat.access',$user))return;
            if(function_exists('agent_background_v125_enqueue')){
                $activity=agent_brain_v122_activity_context($user);$cid=max(0,(int)($activity['conversation_id']??0));$uid=(int)($user['id']??0);
                if($cid>0)agent_background_v125_enqueue('conversation-summary',['user_id'=>$uid,'conversation_id'=>$cid],'conversation-'.$uid.'-'.$cid);
                else agent_background_v125_enqueue('memory-reconcile',['user_id'=>$uid],'memory-'.$uid,1);
                return;
            }
            agent_brain_v122_refresh_latest($user);
        }catch(Throwable $e){}
    });
}

agent_brain_v122_register_shutdown_refresh();