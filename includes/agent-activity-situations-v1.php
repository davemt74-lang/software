<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/cognitive-runtime-v500.php';
require_once __DIR__.'/cognitive-feed-v530.php';

const VP3_ACTIVITY_ADVISORY_DAYS_V1=7;

function vp3_activity_situation_recent_v1(string $at,DateTimeImmutable $now): bool
{
    if($at==='')return false;
    try{$time=(new DateTimeImmutable($at,new DateTimeZone('UTC')))->getTimestamp();}
    catch(Throwable $e){return false;}
    return $time<=$now->getTimestamp()&&$time>=$now->getTimestamp()-VP3_ACTIVITY_ADVISORY_DAYS_V1*86400;
}

/** Reference-only projection over the Activity Center's existing authorities. */
function vp3_activity_situation_conversation_v1(PDO $pdo,array $user,int $agentId,int $conversationId): ?array
{
    if($conversationId<1||!vp3_agent_chat_conversation_v380($pdo,$conversationId,(int)$user['id'],$agentId>0?['id'=>$agentId]:null))return null;
    $q=$pdo->prepare('SELECT id,title FROM chat_conversations WHERE id=? AND user_id=?');
    $q->execute([$conversationId,(int)$user['id']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    return ['key'=>'conversation:'.$conversationId,'title'=>vp3_cognitive_text_v500($row['title']??'Conversation',170),
        'url'=>url('/chat.php?agent='.($agentId>0?$agentId:'system').'&conversation_id='.$conversationId),
        'object_ref'=>['type'=>'conversation','id'=>(string)$conversationId,'scope'=>'personal']];
}

function vp3_activity_situation_memory_v1(PDO $pdo,array $user,array $principal,int $id): ?array
{
    if($id<1||!table_exists('agent_memory_items'))return null;
    [$scope,$args]=vp3_agent_memory_scope_sql_v410((int)$principal['agent_id'],'m');
    $q=$pdo->prepare('SELECT m.id,m.memory_type,m.subject,m.metadata_json,m.source_archive_id,m.last_seen_at FROM agent_memory_items m
        WHERE m.id=? AND m.user_id=? AND '.$scope.' AND m.is_active=1');
    $q->execute([$id,(int)$user['id'],...$args]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    if(in_array($row['memory_type'],['task','commitment'],true)
        &&!vp3_task_list_task_allowed_v1($pdo,$user,$principal,$id))return null;
    return $row;
}

function vp3_activity_situation_memory_anchor_v1(PDO $pdo,array $user,array $principal,array $row,array $metadata): ?array
{
    $conversation=(int)($metadata['conversation_id']??0);
    $archive=(int)($row['source_archive_id']??0);
    if($conversation<1&&$archive>0&&table_exists('agent_chat_archive')){
        $q=$pdo->prepare('SELECT conversation_id FROM agent_chat_archive WHERE id=? AND user_id=?');
        $q->execute([$archive,(int)$user['id']]);
        $conversation=(int)$q->fetchColumn();
    }
    $context=$conversation>0?vp3_activity_situation_conversation_v1($pdo,$user,(int)$principal['agent_id'],$conversation):null;
    if($conversation>0&&!$context)return null;
    if($row['memory_type']==='agent_eyes'&&($metadata['source']??'')==='agent_eyes'
        &&preg_match('/^[a-f0-9-]{36}$/Di',(string)($metadata['session_id']??''))){
        $session=(string)$metadata['session_id'];
        return ['key'=>'eyes-session:'.$session,'title'=>'Agent Eyes session','url'=>$context['url']??'',
            'object_ref'=>['type'=>'agent_eyes_session','id'=>$session,'scope'=>'personal']];
    }
    if(in_array($row['memory_type'],['task','commitment'],true)){
        return ['key'=>'brain-memory:'.(int)$row['id'],'title'=>vp3_cognitive_text_v500($row['subject']??'Saved task',170),
            'url'=>$context['url']??url('/account.php#agent-brain'),
            'object_ref'=>['type'=>'brain_task','id'=>(string)$row['id'],'scope'=>'personal']];
    }
    if($context)return $context;
    return ['key'=>'brain-memory:'.(int)$row['id'],'title'=>vp3_cognitive_text_v500($row['subject']??'Saved context',170),
        'url'=>url('/account.php#agent-brain'),
        'object_ref'=>['type'=>'brain_memory','id'=>(string)$row['id'],'scope'=>'personal']];
}

function vp3_activity_situation_signal_v1(string $kind,string $sourceId,array $anchor,string $title,string $summary,string $state,string $at,array $revision,bool $canPrepare=false,string $evidenceRef=''): array
{
    return ['kind'=>$kind,'source_id'=>$sourceId,'anchor'=>$anchor,
        'title'=>vp3_cognitive_text_v500($title,170),'summary'=>vp3_cognitive_text_v500($summary,320),
        'state'=>$state,'occurred_at'=>$at,'revision'=>vp3_cognitive_feed_fingerprint_v530($revision),
        'can_prepare'=>$canPrepare,'evidence_ref'=>$evidenceRef];
}

function vp3_activity_situation_notifications_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,array $taskIds=[]): array
{
    if(!table_exists('notifications'))return ['unavailable',[]];
    $where=function_exists('notification_system_sql_predicate')?notification_system_sql_predicate('n'):'1=1';
    $where="({$where} OR n.source_type IN ('brain_memory','agent_memory_item','agent_memory','agent_task'))";
    if($taskIds){
        $q=$pdo->prepare("SELECT n.* FROM notifications n WHERE n.user_id=? AND {$where}
            AND n.source_type IN ('brain_memory','agent_memory_item','agent_memory','agent_task') AND n.source_id=?
            ORDER BY n.created_at DESC,n.id DESC LIMIT 4");
        $rows=[];
        foreach($taskIds as $taskId){$q->execute([(int)$user['id'],$taskId]);$rows=[...$rows,...$q->fetchAll(PDO::FETCH_ASSOC)];}
    }else{
        $q=$pdo->prepare('SELECT n.* FROM notifications n WHERE n.user_id=? AND '.$where.' ORDER BY n.created_at DESC,n.id DESC LIMIT 32');
        $q->execute([(int)$user['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $signals=[];
    foreach($rows as $row){
        $id=(int)$row['id'];$source=(string)($row['source_type']??'');$sourceId=(int)($row['source_id']??0);
        $memorySource=in_array($source,['brain_memory','agent_memory_item','agent_memory','agent_task'],true);
        if(!$memorySource&&function_exists('notification_is_agent_brain_activity')&&notification_is_agent_brain_activity($row))continue;
        $anchor=null;
        $sourceRevision=[];
        if(function_exists('notification_is_profile_source_pa4b')&&notification_is_profile_source_pa4b($row)){
            if((int)$principal['agent_id']>0||!function_exists('notification_profile_projection_pa4b'))continue;
            $row=notification_profile_projection_pa4b($pdo,$user,$row);
            if(($row['source_status']??'')!=='verified'||(int)($row['attention_id']??0)<1)continue;
            $anchor=['key'=>'profile-attention:'.(int)$row['attention_id'],'title'=>(string)$row['title'],
                'url'=>(string)$row['target_url'],'object_ref'=>['type'=>'profile_event','id'=>(string)$sourceId,'scope'=>'profile_agent']];
            $sourceRevision=[(int)$row['attention_id'],(int)$row['state_revision'],(int)$row['wake_generation']];
        }elseif(in_array($source,['conversation','chat_conversation','agent_chat'],true)){
            $anchor=vp3_activity_situation_conversation_v1($pdo,$user,(int)$principal['agent_id'],$sourceId);
            if(!$anchor)continue;
        }elseif($memorySource){
            if(!personal_capability_has_v242('agent_brain.access',$user))continue;
            $memory=vp3_activity_situation_memory_v1($pdo,$user,$principal,$sourceId);
            if(!$memory)continue;
            $metadata=json_decode((string)$memory['metadata_json'],true);
            $anchor=vp3_activity_situation_memory_anchor_v1($pdo,$user,$principal,$memory,is_array($metadata)?$metadata:[]);
            if(!$anchor)continue;
        }elseif((int)$principal['agent_id']>0){
            // Unscoped account notifications cannot become named-Agent evidence.
            continue;
        }
        $anchor??=['key'=>'notification:'.$id,'title'=>vp3_cognitive_text_v500($row['title']??'Account update',170),
            'url'=>'','object_ref'=>['type'=>'feed_activity','id'=>(string)$id,'scope'=>'personal']];
        $attention=empty($row['is_read'])&&function_exists('notification_requires_attention')&&notification_requires_attention($row);
        if(!$attention&&!vp3_activity_situation_recent_v1((string)$row['created_at'],$now))continue;
        $signals[]=vp3_activity_situation_signal_v1('notifications',(string)$id,$anchor,(string)($row['title']??'Account update'),
            $attention?'An update needs your review.':'An account update is available.',$attention?'needs_attention':'current',
            (string)$row['created_at'],[$id,$row['type']??'',$source,$sourceId,(bool)($row['is_read']??false),$row['title']??'',$row['created_at'],$sourceRevision]);
    }
    return [!$taskIds&&count($rows)>=32?'limited':'ready',$signals];
}

function vp3_activity_situation_brain_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,array $taskIds=[]): array
{
    if(!table_exists('agent_memory_items'))return ['unavailable',[]];
    $ids=$taskIds;
    if(!$taskIds){
        [$scope,$args]=vp3_agent_memory_scope_sql_v410((int)$principal['agent_id'],'m');
        $q=$pdo->prepare("SELECT m.id FROM agent_memory_items m WHERE m.user_id=? AND {$scope} AND m.is_active=1
            AND m.memory_type IN ('task','commitment','conversation_summary','agent_eyes','screen_observation','preference','goal')
            ORDER BY m.last_seen_at DESC,m.id DESC LIMIT 32");
        $q->execute([(int)$user['id'],...$args]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    }
    $signals=[];
    foreach($ids as $id){
        $row=vp3_activity_situation_memory_v1($pdo,$user,$principal,(int)$id);if(!$row)continue;
        $metadata=json_decode((string)$row['metadata_json'],true);if(!is_array($metadata))$metadata=[];
        $anchor=vp3_activity_situation_memory_anchor_v1($pdo,$user,$principal,$row,$metadata);if(!$anchor)continue;
        $task=in_array($row['memory_type'],['task','commitment'],true);
        $status=(string)($metadata['task_status']??'open');
        $open=$task&&in_array($status,['open','in_progress'],true);
        if(!$open&&!vp3_activity_situation_recent_v1((string)$row['last_seen_at'],$now))continue;
        $state=$task&&in_array($status,['completed','done'],true)?'resolved':($open?($status==='in_progress'?'working':'needs_attention'):'current');
        $signals[]=vp3_activity_situation_signal_v1('brain',(string)$id,$anchor,(string)$row['subject'],
            $task?($open?'An existing task remains open.':'The saved task has a recorded outcome.'):'Saved context is available.',
            $state,(string)$row['last_seen_at'],[$row['id'],$row['memory_type'],$row['subject'],$metadata,$row['source_archive_id']],$open,$task?'task:'.$id:'');
    }
    return [!$taskIds&&count($ids)>=32?'limited':'ready',$signals];
}

function vp3_activity_situation_history_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,array $taskIds=[]): array
{
    if(!table_exists('agent_chat_archive')||!table_exists('chat_conversations')||!table_exists('chat_messages'))return ['unavailable',[]];
    $agent=(int)$principal['agent_id'];$scope=$agent>0?'c.user_agent_id=?':'c.user_agent_id IS NULL';
    $archives=[];
    if($taskIds){
        foreach($taskIds as $taskId){
            $memory=vp3_activity_situation_memory_v1($pdo,$user,$principal,$taskId);
            if($memory&&(int)$memory['source_archive_id']>0)$archives[]=(int)$memory['source_archive_id'];
        }
        if(!$archives)return ['ready',[]];
    }
    $archiveScope=$archives?' AND a.id IN ('.implode(',',array_fill(0,count($archives),'?')).')':'';
    $q=$pdo->prepare("SELECT a.id,a.conversation_id,a.source_message_id,a.role,a.created_at,m.context_json
        FROM agent_chat_archive a JOIN chat_conversations c ON c.id=a.conversation_id AND c.user_id=a.user_id
        JOIN chat_messages m ON m.id=a.source_message_id AND m.conversation_id=a.conversation_id
        WHERE a.user_id=? AND {$scope}{$archiveScope} ORDER BY a.id DESC LIMIT 32");
    $q->execute([...($agent>0?[(int)$user['id'],$agent]:[(int)$user['id']]),...$archives]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$signals=[];
    foreach($rows as $row){
        if(!vp3_activity_situation_recent_v1((string)$row['created_at'],$now))continue;
        $context=json_decode((string)($row['context_json']??''),true);
        $source=is_array($context)?(string)($context['source']??''):'';
        if(is_array($context)&&(!empty($context['skip_brain_archive'])||in_array($source,['agent_initiative','agent_proactive','agent_brain_cognitive'],true)
            ||str_starts_with($source,'cognitive_')))continue;
        $context=vp3_activity_situation_conversation_v1($pdo,$user,$agent,(int)$row['conversation_id']);if(!$context)continue;
        $anchors=[];
        if(table_exists('agent_memory_items')){
            [$memoryScope,$memoryArgs]=vp3_agent_memory_scope_sql_v410($agent,'m');
            $taskScope=$taskIds?' AND m.id IN ('.implode(',',array_fill(0,count($taskIds),'?')).')':'';
            $memoryQuery=$pdo->prepare("SELECT m.id FROM agent_memory_items m WHERE m.user_id=? AND {$memoryScope}
                AND m.source_archive_id=? AND m.is_active=1 AND m.memory_type IN ('task','commitment'){$taskScope} ORDER BY m.id LIMIT 16");
            $memoryQuery->execute([(int)$user['id'],...$memoryArgs,(int)$row['id'],...$taskIds]);
            foreach($memoryQuery->fetchAll(PDO::FETCH_COLUMN) as $id){
                $memory=vp3_activity_situation_memory_v1($pdo,$user,$principal,(int)$id);if(!$memory)continue;
                $metadata=json_decode((string)$memory['metadata_json'],true);
                $anchor=vp3_activity_situation_memory_anchor_v1($pdo,$user,$principal,$memory,is_array($metadata)?$metadata:[]);
                if($anchor)$anchors[]=$anchor;
            }
        }
        if(!$anchors)$anchors[]=$context;
        foreach($anchors as $anchor)$signals[]=vp3_activity_situation_signal_v1('history',(string)$row['id'],$anchor,$anchor['title'],
            'The exact source conversation turn is available.','current',(string)$row['created_at'],
            [$row['id'],$row['conversation_id'],$row['source_message_id'],$row['role'],$row['created_at'],$row['context_json']]);
    }
    return [!$taskIds&&count($rows)>=32?'limited':'ready',$signals];
}

function vp3_activity_situation_eyes_v1(PDO $pdo,array $user,array $principal,string $namespace,DateTimeImmutable $now): array
{
    if(!table_exists('agent_event_inbox'))return ['unavailable',[]];
    $q=$pdo->prepare("SELECT id,event_type,payload_json,occurred_at,received_at FROM agent_event_inbox
        WHERE owner_user_id=? AND source='agent_eyes' AND verification_status IN ('trusted','verified') ORDER BY id DESC LIMIT 32");
    $q->execute([(int)$user['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$signals=[];
    foreach($rows as $row){
        $occurred=(string)($row['occurred_at']?:$row['received_at']);
        if(!vp3_activity_situation_recent_v1($occurred,$now))continue;
        $payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))continue;
        $reportedNamespace=(string)($payload['agent_namespace']??'');
        if($reportedNamespace!==''&&!hash_equals($namespace,$reportedNamespace))continue;
        if($reportedNamespace===''&&(int)$principal['agent_id']>0)continue;
        $context=null;
        if($reportedNamespace!==''&&(int)($payload['conversation_id']??0)>0){
            $context=vp3_activity_situation_conversation_v1($pdo,$user,(int)$principal['agent_id'],(int)$payload['conversation_id']);
            if(!$context)continue;
        }
        $session=(string)($payload['session_id']??'');
        if(!preg_match('/^[a-f0-9-]{36}$/Di',$session))$session='event:'.(int)$row['id'];
        $anchor=['key'=>'eyes-session:'.$session,'title'=>'Agent Eyes session','url'=>$context['url']??'',
            'object_ref'=>['type'=>'agent_eyes_session','id'=>$session,'scope'=>'personal']];
        $signals[]=vp3_activity_situation_signal_v1('eyes',(string)$row['id'],$anchor,'Agent Eyes context',
            'Browser-reported context is available. Participant attribution is provisional.','current',
            $occurred,[$row['id'],$row['event_type'],$session,$reportedNamespace,$payload['conversation_id']??null]);
    }
    return [count($rows)>=32?'limited':'ready',$signals];
}

function vp3_activity_situations_v1(PDO $pdo,array $user,string $namespace,?DateTimeImmutable $now=null,array $taskIds=[]): array
{
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $agent=vp3_cognitive_agent_id_v500($namespace);
    $principal=vp3_agent_chat_principal_v380($user,$agent>0?['id'=>$agent]:null);
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
    if(!has_permission('chat.access',$live)||!has_permission('account.access',$live)
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))throw new RuntimeException('Activity context is unavailable.',403);
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $taskIds=array_slice(array_values(array_unique(array_map('intval',$taskIds))),0,16);
    $brain=personal_capability_has_v242('agent_brain.access',$live);
    $readers=[
        'notifications'=>fn()=>vp3_activity_situation_notifications_v1($pdo,$live,$principal,$now,$taskIds),
        'brain'=>fn()=>vp3_activity_situation_brain_v1($pdo,$live,$principal,$now,$taskIds),
        'history'=>fn()=>vp3_activity_situation_history_v1($pdo,$live,$principal,$now,$taskIds),
        'eyes'=>fn()=>$taskIds?['ready',[]]:vp3_activity_situation_eyes_v1($pdo,$live,$principal,$namespace,$now),
    ];
    $coverage=[];$groups=[];
    foreach($readers as $kind=>$read){
        if($kind!=='notifications'&&!$brain){$coverage[$kind]='forbidden';continue;}
        try{[$coverage[$kind],$signals]=$read();}catch(Throwable $e){$coverage[$kind]=$e->getCode()===403?'forbidden':'unavailable';continue;}
        foreach($signals as $signal)$groups[$signal['anchor']['key']][]=$signal;
    }
    $items=[];
    $labels=['notifications'=>'Notifications','brain'=>'Agent Brain','history'=>'History','eyes'=>'Agent Eyes'];
    foreach($groups as $key=>$signals){
        usort($signals,static fn(array $a,array $b):int=>strcmp($b['occurred_at'],$a['occurred_at'])?:strcmp($a['kind'].':'.$a['source_id'],$b['kind'].':'.$b['source_id']));
        $latest=$signals[0];$counts=array_fill_keys(array_keys($readers),0);$sources=[];$versions=[];$state='current';$prepare=false;$evidenceRefs=[];$taskState=null;
        foreach($signals as $signal){
            $counts[$signal['kind']]++;
            $versions[$signal['kind'].':'.$signal['source_id']]=$signal['revision'];
            $prepare=$prepare||$signal['can_prepare'];
            if($signal['evidence_ref']!=='')$evidenceRefs[$signal['evidence_ref']]=true;
            if($signal['kind']==='brain'&&$signal['evidence_ref']!=='')$taskState=$signal['state'];
            if($signal['state']==='needs_attention'||($state!=='needs_attention'&&$signal['state']==='working'))$state=$signal['state'];
            elseif($state==='current'&&$signal['state']==='resolved')$state='resolved';
            if(count($sources)<8)$sources[]=['kind'=>$signal['kind'],'label'=>$labels[$signal['kind']],
                'occurred_at'=>$signal['occurred_at'],'url'=>$signal['anchor']['url'],
                'confirmation'=>$signal['kind']==='eyes'?'browser_reported':'saved_record'];
        }
        if($taskState!==null)$state=$taskState;
        ksort($versions,SORT_STRING);
        $id=hash('sha256',json_encode(['activity-situation-v1',(int)$live['id'],$namespace,$key],JSON_THROW_ON_ERROR));
        $anchor=$latest['anchor'];
        $items[]=['id'=>$id,'kind'=>'situation','state'=>$state,'title'=>$anchor['title']?:$latest['title'],
            'summary'=>$prepare?'An existing task remains open.':$latest['summary'],
            'updated_at'=>$latest['occurred_at'],'revision'=>vp3_cognitive_feed_fingerprint_v530([$id,$anchor,$versions]),
            'source_label'=>implode(' · ',array_keys(array_filter(array_combine(array_values($labels),array_values($counts))))),
            'source_url'=>$anchor['url'],'action_label'=>$anchor['url']!==''?'Open source':'',
            'sources'=>$sources,'source_counts'=>$counts,'object_ref'=>$anchor['object_ref'],
            'private_preparation_allowed'=>$prepare,'evidence_refs'=>array_keys($evidenceRefs)];
    }
    $stateOrder=['needs_attention'=>0,'working'=>1,'current'=>2,'resolved'=>3];
    usort($items,static fn(array $a,array $b):int=>($stateOrder[$a['state']]<=>$stateOrder[$b['state']])?:strcmp($b['updated_at'],$a['updated_at'])?:strcmp($a['id'],$b['id']));
    return ['build'=>'vp3-activity-situations-v1','complete'=>!array_intersect($coverage,['unavailable','limited']),
        'agent_namespace'=>$namespace,'as_of'=>$now->format(DATE_ATOM),'coverage'=>$coverage,'items'=>array_slice($items,0,$taskIds?16:12)];
}

/** Add supporting context only to work already admitted by canonical source readers. */
function vp3_activity_situations_support_v1(PDO $pdo,array $user,array $evidence,DateTimeImmutable $now,?array &$coverage=null): array
{
    $taskIds=[];
    foreach(array_keys($evidence) as $ref)if(preg_match('/^task:([1-9][0-9]*)$/D',$ref,$match))$taskIds[]=(int)$match[1];
    if(!$taskIds){$coverage['activity_context']='ready';return $evidence;}
    $stream=vp3_activity_situations_v1($pdo,$user,'system',$now,$taskIds);
    $coverage['activity_context']=$stream['complete']?'ready':'unavailable';
    foreach($stream['items'] as $item){
        if(!$item['private_preparation_allowed'])continue;
        $context=['id'=>$item['id'],'revision'=>$item['revision'],'state'=>$item['state'],'title'=>$item['title'],
            'summary'=>$item['summary'],'sources'=>$item['source_label'],'advisory_only'=>true];
        foreach($item['evidence_refs'] as $ref){
            if(!isset($evidence[$ref]))continue;
            $revision=$evidence[$ref]['revision'];
            $evidence[$ref]['canonical_source_revision']=$revision;
            $evidence[$ref]['activity_context']=$context;
            $evidence[$ref]['revision']=vp3_cognitive_feed_fingerprint_v530([$revision,$context]);
        }
    }
    return $evidence;
}

function vp3_activity_situations_context_v1(PDO $pdo,array $user,string $namespace): ?array
{
    $stream=vp3_activity_situations_v1($pdo,$user,$namespace);
    $items=array_slice($stream['items'],0,4);
    if(!$items)return null;
    $lines=[];
    foreach($items as $item)$lines[]=$item['title'].' — '.$item['summary'].' Sources: '.$item['source_label'].'.';
    return vp3_cognitive_context_item_v2420('current_priorities','activity-situations:v1','Current activity situations',
        implode("\n",$lines),93.0,['complete'=>$stream['complete'],'source_coverage'=>$stream['coverage']]);
}
