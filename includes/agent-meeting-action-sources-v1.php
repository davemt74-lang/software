<?php
declare(strict_types=1);

require_once __DIR__.'/agent-calendar-prep-v1.php';
require_once __DIR__.'/agent-initiative-office-actions-v1.php';
require_once __DIR__.'/agent-reminders-v1.php';

/** A direct owner request shares the meeting reader's authority, not Initiative's enable switch. */
function vp3_meeting_action_scope_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    if(!function_exists('db')||db()!==$pdo)throw new RuntimeException('Meeting connection changed.',503);
    [$live,$principal]=vp3_calendar_prep_scope_v1($pdo,$user,$agentId,$cid,null);
    return ['user'=>$live,'principal'=>$principal,'agent_id'=>$agentId,'conversation_id'=>$cid];
}

function vp3_meeting_action_hash_v1(array $value): string
{
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
}

/** Record-policy revisions are checked separately from the source text revision. */
function vp3_meeting_action_policy_revision_v1(PDO $pdo,array $scope): string
{
    $q=$pdo->prepare("SELECT resource_type,resource_id,audience_scope,owner_agents_allowed,profile_agent_allowed,stonefellow_shared
        FROM user_data_policies WHERE owner_user_id=? AND resource_type IN ('knowledge','recording') ORDER BY resource_type,resource_id");
    $q->execute([(int)$scope['user']['id']]);$policy=$q->fetchAll(PDO::FETCH_ASSOC);$rules=[];
    if($scope['agent_id']>0){
        $q=$pdo->prepare("SELECT resource_type,resource_id,access_mode FROM user_agent_data_rules WHERE agent_id=?
            AND resource_type IN ('knowledge','recording') ORDER BY resource_type,resource_id");
        $q->execute([$scope['agent_id']]);$rules=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return vp3_meeting_action_hash_v1([$scope['user']['id'],$scope['agent_id'],$policy,$rules]);
}

/** Only independently verified prep items become source choices. No label search confers authority. */
function vp3_meeting_action_findings_v1(PDO $pdo,array $scope,array $event): array
{
    $mid=$event['meeting_id'];$owner=(int)$scope['user']['id'];$aid=$scope['agent_id'];
    $findings=[['ref'=>'meeting:'.$mid,'kind'=>'meeting','actionable'=>false,'label'=>$event['title'],
        'revision'=>vp3_meeting_action_hash_v1([$mid,$event['id'],$event['title'],$event['starts_at']])]];
    $labels=$event['actionable_items']??[];
    if($labels){
        $lineage=[];
        foreach(vp3_meeting_prep_lineage_rows_v1($pdo,$owner,$aid,new DateTimeImmutable('now',new DateTimeZone('UTC')),$mid)??[] as $link){
            if(!vp3_meeting_prep_lineage_valid_v1($owner,$link))continue;
            $signal=video_meeting_continuity_thread_signal_v18210($pdo,$owner,(int)$link['source_agenda_id'],$mid);
            if(empty($signal['kind']))$lineage[(int)$link['target_agenda_id']]=true;
        }
        $executions=[];
        if(table_exists('video_meeting_action_executions')){
            $q=$pdo->prepare("SELECT agenda_item_id,action_kind FROM video_meeting_action_executions
                WHERE meeting_id=? AND owner_user_id=? AND status='needs_review' ORDER BY id LIMIT 121");
            $q->execute([$mid,$owner]);$executions=array_column($q->fetchAll(PDO::FETCH_ASSOC),'action_kind','agenda_item_id');
        }
        $q=$pdo->prepare('SELECT id,item_text,source_kind,source_meeting_id,source_hash,status,action_kind,approval_state
            FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? ORDER BY sort_order,id LIMIT 121');
        $q->execute([$mid,$owner]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $label=vp3_calendar_prep_text_v1($row['item_text'],200);
            if(!in_array($label,$labels,true)||!in_array($row['status'],['open','follow_up'],true))continue;
            if(!isset($lineage[(int)$row['id']])&&(($row['action_kind']??'')===''||($executions[(int)$row['id']]??null)!==$row['action_kind']))continue;
            $findings[]=['ref'=>'agenda:'.(int)$row['id'],'kind'=>'agenda','actionable'=>true,'label'=>$label,'source_meeting_id'=>(int)($row['source_meeting_id']??0),
                'revision'=>vp3_meeting_action_hash_v1($row)];
        }
        if(table_exists('agent_memory_items')&&column_exists('agent_memory_items','metadata_json')){
            $q=$pdo->prepare("SELECT id,subject,metadata_json FROM agent_memory_items WHERE user_id=? AND ".($aid>0?'user_agent_id=?':'user_agent_id IS NULL')." AND is_active=1 AND memory_type IN ('task','commitment')
                AND CAST(CASE WHEN JSON_VALID(metadata_json) THEN JSON_EXTRACT(metadata_json,'$.video_meeting_id') END AS CHAR)=? ORDER BY id LIMIT 21");
            $q->execute($aid>0?[$owner,$aid,(string)$mid]:[$owner,(string)$mid]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                $meta=json_decode((string)$row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
                if(!is_array($meta)||($meta['source_kind']??'')!=='video_meeting_intelligence'||($meta['review_state']??'')!=='approved'
                    ||(int)($meta['video_meeting_id']??0)!==$mid||!in_array((int)($meta['assigned_user_id']??0),[0,$owner],true)
                    ||!in_array($meta['task_status']??'',['open','in_progress','waiting'],true)
                    ||!vp3_task_list_task_allowed_v1($pdo,$scope['user'],$scope['principal'],(int)$row['id']))continue;
                $label=vp3_calendar_prep_text_v1($row['subject'],200);if(!in_array($label,$labels,true))continue;
                $findings[]=['ref'=>'task:'.(int)$row['id'],'kind'=>'task','actionable'=>true,'label'=>$label,'revision'=>vp3_meeting_action_hash_v1($row)];
            }
        }
    }
    $prior=$event['prior_transcript']??[];
    if(($prior['status']??'')==='available')foreach($prior['passages'] as $passage){
        $findings[]=['ref'=>'transcript:'.$prior['session_id'].':segment:'.$passage['segment_id'],
            'kind'=>'transcript','actionable'=>false,'label'=>$passage['text'],'revision'=>vp3_meeting_action_hash_v1([$prior['meeting_id'],$prior['session_id'],$prior['revision'],$passage])];
    }
    return $findings;
}

/** Server-selected exact occurrence; the durable snapshot contains no private passages or labels. */
function vp3_meeting_action_source_build_v1(PDO $pdo,array $scope,array $calendar,?array $origin,DateTimeImmutable $now): ?array
{
    $event=vp3_calendar_prep_next_event_v1($pdo,$scope['user'],$scope['principal'],$calendar,$scope['conversation_id'],$now);
    if(!$event)return null;
    $findings=vp3_meeting_action_findings_v1($pdo,$scope,$event);
    $sourceMeetings=[];
    foreach($findings as $finding)if(($finding['source_meeting_id']??0)>0)$sourceMeetings[]=$finding['source_meeting_id'];
    if(($event['prior_transcript']['meeting_id']??0)>0)$sourceMeetings[]=$event['prior_transcript']['meeting_id'];
    $sourceMeetings=array_values(array_unique($sourceMeetings));sort($sourceMeetings);
    $source=['version'=>1,'owner_user_id'=>(int)$scope['user']['id'],'agent_id'=>$scope['agent_id'],
        'conversation_id'=>$scope['conversation_id'],'meeting_id'=>$event['meeting_id'],'calendar_event_id'=>$event['id'],
        'origin_message_id'=>(int)($origin['id']??0),'origin_conversation_id'=>(int)($origin['conversation_id']??0),
        'origin_kind'=>$origin['kind']??'explicit','origin_hash'=>$origin['hash']??'',
        'access'=>['source_meeting_ids'=>$sourceMeetings,'recording_id'=>(int)($event['prior_transcript']['session_id']??0)],
        'marker'=>array_intersect_key($event,array_flip(['id','kind','occurrence_id','revision'])),
        'revision'=>vp3_meeting_action_hash_v1([$event,array_map(static fn(array $f):array=>array_diff_key($f,['label'=>true]),$findings)]),
        'policy_revision'=>vp3_meeting_action_policy_revision_v1($pdo,$scope)];
    $source['proof']=vp3_meeting_action_hash_v1($source);
    return ['status'=>'selected','source'=>$source,'findings'=>$findings,
        'meeting'=>['id'=>$event['meeting_id'],'title'=>$event['title'],'calendar_event_id'=>$event['id'],
            'start_at_utc'=>$calendar['start_at_utc'],'end_at_utc'=>$calendar['end_at_utc'],'timezone'=>$calendar['timezone']],
        'review_url'=>$event['review_url']];
}

function vp3_meeting_action_origin_v1(PDO $pdo,array $scope,array $row,DateTimeImmutable $now): ?array
{
    $context=json_decode((string)$row['context_json'],true,32,JSON_THROW_ON_ERROR);if(!is_array($context))return null;
    $originCid=(int)$row['conversation_id'];
    $originScope=vp3_meeting_action_scope_v1($pdo,$scope['user'],$scope['agent_id'],$originCid);
    if(is_array($context['meeting_prep_v1']??null)){
        $saved=$context['meeting_prep_v1'];
        if(($saved['conversation_id']??null)!==$originCid||!is_array($saved['evidence']??null)
            ||$row['message']!=='Private meeting prep was displayed for this turn.')return null;
        $replay=vp3_calendar_prep_replay_v1($pdo,$scope['user'],$scope['agent_id'],$originCid,$saved['evidence'],null,$now);
        if(($replay['meeting_prep']??null)!==$saved['evidence'])return null;
        $calendar=user_calendar_event_v1300($pdo,(int)$scope['user']['id'],$saved['evidence']['id']);
        $kind='meeting_prep_v1';$binding=$saved;
    }elseif(is_string($context['initiative_meeting_prep']??null)){
        require_once __DIR__.'/agent-initiative-followthrough-v1.php';
        require_once __DIR__.'/agent-initiative-office-records-v1.php';
        if(vp3_initiative_meeting_prep_projection_v1($pdo,$scope['user'],$scope['agent_id'],$originCid,(int)$row['id'])===null)return null;
        $loaded=vp3_initiative_followthrough_origin_load_v1($pdo,$scope['user'],$originScope['principal'],$originCid,(int)$row['id']);
        $refs=array_keys($loaded['goal']['sources']);
        if(count($refs)!==1||!preg_match('/\Acalendar:event:([1-9][0-9]*)\z/D',$refs[0],$m))return null;
        $calendar=user_calendar_event_v1300($pdo,(int)$scope['user']['id'],(int)$m[1]);
        $kind='initiative_meeting_prep';$binding=[$context['initiative_meeting_prep'],$loaded['goal']['sources'],$loaded['state']['goal_hash']];
    }else return null;
    if(!$calendar)return null;
    return vp3_meeting_action_source_build_v1($pdo,$scope,$calendar,
        ['id'=>(int)$row['id'],'conversation_id'=>$originCid,'kind'=>$kind,'hash'=>vp3_meeting_action_hash_v1([$row['message'],$binding])],$now);
}

/** Explicit IDs/names or the most recent verified server prep, including this Agent's earlier Chats. */
function vp3_meeting_action_source_v1(PDO $pdo,array $user,int $agentId,int $cid,array $selector=[],?DateTimeImmutable $now=null): array
{
    $scope=vp3_meeting_action_scope_v1($pdo,$user,$agentId,$cid);
    $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $keys=array_keys($selector);sort($keys);
    if(!in_array($keys,[[],['meeting_id'],['title'],['origin_conversation_id','origin_message_id']],true))throw new InvalidArgumentException('Choose one exact meeting source.');
    if(isset($selector['meeting_id'])||isset($selector['title'])){
        if(isset($selector['meeting_id'])&&(!is_int($selector['meeting_id'])||$selector['meeting_id']<1))throw new InvalidArgumentException('Choose an exact meeting ID.');
        if(isset($selector['title'])&&(!is_string($selector['title'])||trim($selector['title'])===''||mb_strlen($selector['title'])>190))throw new InvalidArgumentException('Choose an exact meeting title.');
        $q=$pdo->prepare('SELECT id,calendar_event_id FROM video_meetings WHERE owner_user_id=? AND '.($agentId>0?'organizer_agent_id=?':'organizer_agent_id IS NULL').' AND '.(isset($selector['meeting_id'])?'id=?':'LOWER(title)=LOWER(?)').' ORDER BY start_at_utc DESC,id DESC LIMIT 21');
        $q->execute(array_merge([(int)$user['id']],$agentId>0?[$agentId]:[],[isset($selector['meeting_id'])?$selector['meeting_id']:trim($selector['title'])]));
        $selected=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $calendar=user_calendar_event_v1300($pdo,(int)$user['id'],(int)($row['calendar_event_id']??0));
            $current=$calendar?vp3_meeting_action_source_build_v1($pdo,$scope,$calendar,null,$now):null;
            if($current&&$current['source']['meeting_id']===(int)$row['id'])$selected[]=$current;
        }
        if(count($selected)===1)return $selected[0];
        if(count($selected)>1)return ['status'=>'ambiguous','choices'=>array_map(static fn(array $r):array=>[
            'meeting_id'=>$r['meeting']['id'],'title'=>$r['meeting']['title'],'starts_at'=>$r['meeting']['start_at_utc']],$selected)];
        return ['status'=>'unavailable','choices'=>[]];
    }
    $sql="SELECT m.id,m.conversation_id,m.message,m.context_json FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
        WHERE c.user_id=? AND ".($agentId>0?'c.user_agent_id=?':'c.user_agent_id IS NULL')." AND m.role='assistant'";
    $args=[(int)$user['id']];if($agentId>0)$args[]=$agentId;
    if($keys){
        foreach($selector as $id)if(!is_int($id)||$id<1)throw new InvalidArgumentException('Invalid meeting source origin.');
        $sql.=' AND m.id=? AND m.conversation_id=?';$args[]=$selector['origin_message_id'];$args[]=$selector['origin_conversation_id'];
    }else{$sql.=" AND m.context_json LIKE '%meeting_prep%' ORDER BY CASE WHEN c.id=? THEN 0 ELSE 1 END,m.id DESC LIMIT 120";$args[]=$cid;}
    $q=$pdo->prepare($sql);$q->execute($args);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        try{$selected=vp3_meeting_action_origin_v1($pdo,$scope,$row,$now);if($selected)return $selected;}
        catch(RuntimeException|JsonException $error){if($keys)throw $error;}
    }
    return ['status'=>'unavailable','choices'=>[]];
}

/** A frozen pointer cannot be redirected by a newer meeting, Chat turn or similarly named record. */
function vp3_meeting_action_source_current_v1(PDO $pdo,array $user,int $agentId,int $cid,array $source,?DateTimeImmutable $now=null): array
{
    if(($source['version']??null)!==1||($source['owner_user_id']??null)!==(int)$user['id']||($source['agent_id']??null)!==$agentId
        ||($source['conversation_id']??null)!==$cid||!is_int($source['meeting_id']??null)||$source['meeting_id']<1
        ||!is_int($source['origin_message_id']??null)||!is_int($source['origin_conversation_id']??null))throw new RuntimeException('Meeting source scope changed.',403);
    $selector=$source['origin_message_id']>0?array_intersect_key($source,array_flip(['origin_message_id','origin_conversation_id'])):['meeting_id'=>$source['meeting_id']];
    $current=vp3_meeting_action_source_v1($pdo,$user,$agentId,$cid,$selector,$now);
    if(($current['status']??'')!=='selected'||$current['source']!==$source)throw new RuntimeException('Meeting source changed. Review it again before saving.',409);
    return $current;
}

function vp3_meeting_action_binding_scope_v1(PDO $pdo,array $user,array $principal,array $binding): array
{
    $aid=$binding['agent_id']??null;$cid=$binding['conversation_id']??null;
    if(!is_int($aid)||!is_int($cid)||($binding['owner_user_id']??null)!==(int)$user['id'])throw new RuntimeException('Meeting action binding changed.',403);
    $scope=vp3_meeting_action_scope_v1($pdo,$user,$aid,$cid);
    foreach(['kind','owner_user_id','viewer_user_id','agent_id'] as $key)if(($principal[$key]??null)!==($scope['principal'][$key]??null))throw new RuntimeException('Meeting action principal changed.',403);
    return $scope;
}

/** A saved effect is historical after a source edit or meeting start. Current authority still applies. */
function vp3_meeting_action_source_access_v1(PDO $pdo,array $scope,array $source,string $findingRef): void
{
    if(($source['owner_user_id']??null)!==(int)$scope['user']['id']||($source['agent_id']??null)!==$scope['agent_id']
        ||($source['conversation_id']??null)!==$scope['conversation_id']||!is_string($source['proof']??null)
        ||!hash_equals($source['proof'],vp3_meeting_action_hash_v1(array_diff_key($source,['proof'=>true]))))throw new RuntimeException('Meeting source receipt scope changed.',403);
    $access=$source['access']??null;
    if(!is_array($access)||!is_array($access['source_meeting_ids']??null)||!is_int($access['recording_id']??null))throw new RuntimeException('Meeting source authority proof is unavailable.',409);
    foreach(array_merge([$source['meeting_id']],$access['source_meeting_ids']) as $mid){
        if(!is_int($mid)||$mid<1)throw new RuntimeException('Meeting source identity changed.',409);
        $q=$pdo->prepare('SELECT id,calendar_event_id FROM video_meetings WHERE id=? AND owner_user_id=? AND '.($scope['agent_id']>0?'organizer_agent_id=?':'organizer_agent_id IS NULL'));
        $q->execute(array_merge([$mid,(int)$scope['user']['id']],$scope['agent_id']>0?[$scope['agent_id']]:[]));$meeting=$q->fetch(PDO::FETCH_ASSOC);
        if(!$meeting||($mid===$source['meeting_id']&&(int)$meeting['calendar_event_id']!==$source['calendar_event_id']))throw new RuntimeException('Meeting source access changed.',403);
        $q=$pdo->prepare("SELECT 1 FROM video_meeting_participants WHERE meeting_id=? AND user_id=? AND `role`='organizer' AND invitation_status NOT IN ('cancelled','revoked','declined') LIMIT 1");
        $q->execute([$mid,(int)$scope['user']['id']]);if(!$q->fetchColumn())throw new RuntimeException('Meeting source participation changed.',403);
    }
    if($source['origin_message_id']>0){
        $originCid=$source['origin_conversation_id'];vp3_meeting_action_scope_v1($pdo,$scope['user'],$scope['agent_id'],$originCid);
        $q=$pdo->prepare("SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant'");
        $q->execute([$source['origin_message_id'],$originCid]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $context=$row?json_decode((string)$row['context_json'],true,32,JSON_THROW_ON_ERROR):[];
        if($source['origin_kind']==='meeting_prep_v1')$binding=$context['meeting_prep_v1']??null;
        elseif($source['origin_kind']==='initiative_meeting_prep'){
            require_once __DIR__.'/agent-initiative-followthrough-v1.php';
            $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$scope['user'],$scope['principal'],$originCid,$source['origin_message_id']);
            $binding=[$context['initiative_meeting_prep']??null,$origin['goal']['sources'],$origin['state']['goal_hash']];
        }else throw new RuntimeException('Meeting source origin changed.',409);
        if(!$row||!hash_equals($source['origin_hash'],vp3_meeting_action_hash_v1([$row['message'],$binding])))throw new RuntimeException('Meeting source origin changed.',409);
    }
    if($access['recording_id']>0){
        require_once __DIR__.'/transcript-agent-v1.php';
        $session=vp3_transcript_session_v1($pdo,$scope['user'],$scope['principal'],$access['recording_id']);
        $meeting=video_meeting_transcription_readonly_binding_v1801($pdo,$scope['user'],$session);
        if(!in_array((int)$meeting['id'],$access['source_meeting_ids'],true)||(int)($meeting['organizer_agent_id']??0)!==$scope['agent_id'])throw new RuntimeException('Meeting source recording binding changed.',403);
    }
    if(preg_match('/\Aagenda:([1-9][0-9]*)\z/D',$findingRef,$m)){
        $q=$pdo->prepare('SELECT id FROM video_meeting_agenda_items WHERE id=? AND meeting_id=? AND owner_user_id=?');$q->execute([(int)$m[1],$source['meeting_id'],(int)$scope['user']['id']]);
        if(!$q->fetchColumn())throw new RuntimeException('Meeting finding identity is unavailable.',409);
    }elseif(preg_match('/\Atask:([1-9][0-9]*)\z/D',$findingRef,$m))vp3_initiative_office_action_task_v1($pdo,$scope['user'],$scope['principal'],(int)$m[1]);
    elseif(preg_match('/\Atranscript:([1-9][0-9]*):segment:([1-9][0-9]*)\z/D',$findingRef,$m)){
        if((int)$m[1]!==$access['recording_id'])throw new RuntimeException('Meeting finding recording changed.',409);
        $q=$pdo->prepare('SELECT id FROM artist_transcript_segments_v172 WHERE id=? AND session_id=?');$q->execute([(int)$m[2],(int)$m[1]]);
        if(!$q->fetchColumn())throw new RuntimeException('Meeting finding recording is unavailable.',409);
    }elseif($findingRef!=='meeting:'.$source['meeting_id'])throw new RuntimeException('Meeting finding identity changed.',409);
}

function vp3_meeting_action_normalize_v1(PDO $pdo,array $user,array $principal,array $binding,array $source,array $raw): array
{
    $scope=vp3_meeting_action_binding_scope_v1($pdo,$user,$principal,$binding);
    $current=vp3_meeting_action_source_current_v1($pdo,$scope['user'],$scope['agent_id'],$scope['conversation_id'],$source);
    vp3_initiative_office_action_keys_v1($raw,['adapter','arguments','finding_ref']);
    if(!is_string($raw['finding_ref'])||!is_array($raw['arguments']))throw new InvalidArgumentException('Choose an exact meeting finding.');
    $finding=null;foreach($current['findings'] as $row)if($row['ref']===$raw['finding_ref'])$finding=$row;
    if(!$finding)throw new RuntimeException('That finding was not supplied by this meeting prep.',403);
    if($raw['adapter']==='task.create'){
        $domain=vp3_initiative_office_action_normalize_v1($pdo,$scope['user'],$principal,$binding,['adapter'=>'task.create','arguments'=>$raw['arguments']],[]);
    }elseif($raw['adapter']==='reminder.create'){
        vp3_initiative_office_action_keys_v1($raw['arguments'],['schedule']);
        if(!is_array($raw['arguments']['schedule'])||!reminder_ready_v1($pdo))throw new RuntimeException('Reminder storage is unavailable.',503);
        $schedule=reminder_schedule_v1($raw['arguments']['schedule'],reminder_now_v1());unset($schedule['next_at']);
        $domain=['adapter'=>'reminder.create','arguments'=>['schedule'=>$schedule],'capability'=>'reminders.manage','operation'=>'create','effect_class'=>'private_record','review_required'=>true,'target_refs'=>[],'expected'=>[]];
    }else throw new InvalidArgumentException('Unsupported meeting finding action.');
    return $domain+['source'=>$source,'finding_ref'=>$finding['ref'],'finding_revision'=>$finding['revision']];
}

/** Content-free origin retained beside the new record, not an invented meeting note or transcript copy. */
function vp3_meeting_action_provenance_v1(array $plan,string $actionId): array
{
    return ['source_kind'=>'meeting_chat_action_v1','action_id'=>$actionId,'source'=>$plan['source'],
        'finding_ref'=>$plan['finding_ref'],'finding_revision'=>$plan['finding_revision']];
}

function vp3_meeting_action_apply_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan,string $actionId): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Meeting action requires the owner review transaction.',409);
    if(!preg_match('/\A[A-Za-z0-9:_-]{8,100}\z/D',$actionId))throw new InvalidArgumentException('Invalid meeting action identity.');
    $scope=vp3_meeting_action_binding_scope_v1($pdo,$user,$principal,$binding);
    $checked=vp3_meeting_action_normalize_v1($pdo,$scope['user'],$principal,$binding,$plan['source']??[],array_intersect_key($plan,array_flip(['adapter','arguments','finding_ref'])));
    if($checked!==$plan)throw new RuntimeException('Reviewed meeting action changed.',409);
    $provenance=vp3_meeting_action_provenance_v1($plan,$actionId);
    if($plan['adapter']==='task.create'){
        $domain=array_diff_key($plan,array_flip(['source','finding_ref','finding_revision']));
        $result=vp3_initiative_office_action_apply_v1($pdo,$scope['user'],$principal,$binding,$domain,$actionId);
        $id=$result['office_action']['task_id'];
        $q=$pdo->prepare('SELECT metadata_json FROM agent_memory_items WHERE id=? AND user_id=?');$q->execute([$id,(int)$user['id']]);
        $meta=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);if(!is_array($meta))throw new RuntimeException('Task provenance storage changed.',503);
        $meta['meeting_source_v1']=$provenance;
        $pdo->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=? AND user_id=?')->execute([json_encode($meta,JSON_THROW_ON_ERROR),$id,(int)$user['id']]);
        $result['office_action']['after']['task']=vp3_initiative_office_action_task_v1($pdo,$scope['user'],$principal,$id);
    }else{
        $thread='meeting:'.$scope['agent_id'].':'.$scope['conversation_id'];$key='meeting_'.hash('sha256',$actionId);
        $payload=json_encode(['adapter'=>$plan['adapter'],'arguments'=>$plan['arguments'],'provenance'=>$provenance],JSON_THROW_ON_ERROR);
        $result=reminder_execute_locked_v1($pdo,(int)$user['id'],$thread,$key,$payload,
            ['operation'=>'create','schedule'=>$plan['arguments']['schedule']],[],$scope['conversation_id']);
        $result['meeting_source_v1']=$provenance;
        $pdo->prepare('UPDATE agent_reminder_turns_v1 SET result_json=? WHERE owner_user_id=? AND thread_key=? AND request_key=? AND payload_hash=?')
            ->execute([json_encode($result,JSON_THROW_ON_ERROR),(int)$user['id'],$thread,$key,hash('sha256',$payload)]);
    }
    $result['meeting_source_v1']=$provenance;
    return vp3_meeting_action_inspect_v1($pdo,$scope['user'],$principal,$binding,$plan,$result);
}

/** Receipt replays inspect canonical saved data; they never reapply the action. */
function vp3_meeting_action_inspect_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan,array $result): array
{
    $scope=vp3_meeting_action_binding_scope_v1($pdo,$user,$principal,$binding);$provenance=$result['meeting_source_v1']??null;
    if(!is_array($provenance)||!is_string($provenance['action_id']??null)
        ||$provenance!==vp3_meeting_action_provenance_v1($plan,$provenance['action_id']))throw new RuntimeException('Meeting action provenance changed.',409);
    vp3_meeting_action_source_access_v1($pdo,$scope,$plan['source'],$plan['finding_ref']);
    if($plan['adapter']==='task.create'){
        $domain=array_diff_key($plan,array_flip(['source','finding_ref','finding_revision']));
        vp3_initiative_office_action_inspect_v1($pdo,$scope['user'],$principal,$binding,$domain,$result);
        $q=$pdo->prepare('SELECT metadata_json FROM agent_memory_items WHERE id=? AND user_id=?');$q->execute([$result['office_action']['task_id'],(int)$user['id']]);
        $meta=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
        if(($meta['meeting_source_v1']??null)!==$provenance)throw new RuntimeException('Saved task meeting origin changed.',409);
    }elseif($plan['adapter']==='reminder.create'){
        $receipt=vp3_tool_receipt_normalize_v1($result['tool_receipt']??null);$id=(int)($receipt['record_id']??0);
        if(!$receipt||$receipt['capability']!=='reminders.manage'||$receipt['operation']!=='create'||$receipt['status']!=='completed'||$id<1)throw new RuntimeException('Reminder receipt changed.',409);
        $q=$pdo->prepare('SELECT * FROM agent_reminders_v1 WHERE id=? AND owner_user_id=?');$q->execute([$id,(int)$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']!=='active'||(int)$row['conversation_id']!==$scope['conversation_id']||(int)$row['revision']!==1)throw new RuntimeException('Saved meeting reminder changed.',409);
        foreach($plan['arguments']['schedule'] as $key=>$value)if(($row[$key]??null)!==$value)throw new RuntimeException('Saved reminder differs from its reviewed schedule.',409);
        $expected=reminder_schedule_v1($plan['arguments']['schedule'],new DateTimeImmutable($row['created_at']));
        if($row['next_at']!==$expected['next_at'])throw new RuntimeException('Saved reminder schedule changed.',409);
        $q=$pdo->prepare('SELECT result_json FROM agent_reminder_turns_v1 WHERE owner_user_id=? AND thread_key=? AND request_key=?');
        $q->execute([(int)$user['id'],'meeting:'.$scope['agent_id'].':'.$scope['conversation_id'],'meeting_'.hash('sha256',$provenance['action_id'])]);
        $saved=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
        if(($saved['meeting_source_v1']??null)!==$provenance||($saved['tool_receipt']??null)!==$result['tool_receipt'])throw new RuntimeException('Saved reminder origin changed.',409);
        $result['output_refs']=['reminder:'.$id];
    }else throw new RuntimeException('Meeting action receipt adapter changed.',409);
    $result['verified']=true;return $result;
}
