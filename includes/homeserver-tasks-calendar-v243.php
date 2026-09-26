<?php
declare(strict_types=1);

/**
 * HomeServer v2.4 Section 4 — Tasks & Calendar continuity.
 *
 * Cloud Agent Brain tasks and VP3 User Calendar records remain Cloud-authoritative.
 * HomeServer Tasks and Calendar remain HomeServer-authoritative. Remote records are
 * projected with canonical IDs and are never copied into native Cloud tables.
 */
const VP3_HOMESERVER_TASK_CALENDAR_V243='vp3-homeserver-task-calendar-v243-20260925';

function homeserver_task_calendar_v243_text(mixed $value,int $max=2200): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value),0,$max,'…');
}

function homeserver_task_calendar_v243_cloud_tasks(int $userId,string $query='',int $limit=100): array
{
    $pdo=db();if(!$pdo||$userId<1||!table_exists('agent_memory_items'))return [];
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->prepare("SELECT id,memory_type,subject,memory_text,metadata_json,last_seen_at
      FROM agent_memory_items
      WHERE user_id=? AND is_active=1 AND memory_type IN ('task','commitment')
      ORDER BY last_seen_at DESC,id DESC LIMIT 240");
    $stmt->execute([$userId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];
        $title=homeserver_task_calendar_v243_text($row['subject']??'Agent task',240);
        $description=homeserver_task_calendar_v243_text($row['memory_text']??'',6000);
        if(function_exists('homeserver_shared_v210_matches')&&!homeserver_shared_v210_matches($query,$title.' '.$description))continue;
        $key='agent_memory_task:'.(int)$row['id'];
        $envelope=homeserver_federated_v240_envelope('vp3_cloud','tasks',$key,$title,$description,(string)($row['last_seen_at']??''));
        homeserver_federated_v240_observe($userId,$envelope,'vp3_cloud');
        $out[]=[
          'id'=>(int)$row['id'],'title'=>$title,'description'=>$description,
          'status'=>homeserver_task_calendar_v243_text($meta['task_status']??'open',40),
          'priority'=>homeserver_task_calendar_v243_text($meta['priority']??'',40),
          'due_at'=>homeserver_task_calendar_v243_text($meta['due_at']??'',80)?:null,
          'memory_type'=>(string)$row['memory_type'],'updated_at'=>$row['last_seen_at']??null,
          'authority_source'=>'vp3_cloud','authority_key'=>$key,
          'canonical_id'=>$envelope['canonical_id'],'record_revision'=>$envelope['record_revision'],
          'federation_version'=>'2.4','mirror_only'=>false,
          'mutation_route'=>'cloud_native_agent_brain','allowed_mutations'=>[],
          'source_label'=>'VP3 Cloud',
        ];
        if(count($out)>=$limit)break;
    }
    return $out;
}

function homeserver_task_calendar_v243_remote_tool(int $userId,string $toolKey,array $arguments): array
{
    if($userId<1||!function_exists('homeserver_execution_v230_execute'))return [];
    try{
        $run=homeserver_execution_v230_execute($userId,'tool.execute',[
          'tool_key'=>$toolKey,'arguments'=>$arguments,
        ]);
        $outer=is_array($run['result']??null)?$run['result']:[];
        $result=is_array($outer['result']??null)?$outer['result']:[];
        return is_array($result['items']??null)?array_values(array_filter($result['items'],'is_array')):[];
    }catch(Throwable $e){return [];}
}

function homeserver_task_calendar_v243_validate_remote(array $row,string $dataset,string $keyPrefix): ?array
{
    $canonical=strtolower(trim((string)($row['canonical_id']??'')));
    $key=homeserver_task_calendar_v243_text($row['authority_key']??'',180);
    $revision=strtolower(trim((string)($row['record_revision']??'')));
    if((string)($row['authority_source']??'')!=='homeserver')return null;
    if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonical)||!str_starts_with($key,$keyPrefix))return null;
    if(!preg_match('/^[0-9a-f]{64}$/',$revision))return null;
    $expected=homeserver_federated_v240_canonical_id('homeserver',$dataset,$key);
    if(!hash_equals($expected,$canonical))return null;
    $row['canonical_id']=$canonical;$row['authority_key']=$key;$row['record_revision']=$revision;
    $row['authority_source']='homeserver';$row['federation_version']='2.4';$row['mirror_only']=true;
    $row['source_label']='HomeServer';
    return $row;
}

function homeserver_task_calendar_v243_homeserver_tasks(int $userId,string $query='',int $limit=100): array
{
    $rows=homeserver_task_calendar_v243_remote_tool($userId,'tasks.list',[
      'query'=>homeserver_task_calendar_v243_text($query,240),'limit'=>max(1,min(50,$limit)),
    ]);
    $out=[];
    foreach($rows as $row){
        $item=homeserver_task_calendar_v243_validate_remote($row,'tasks','task:');if(!$item)continue;
        homeserver_federated_v240_observe($userId,[
          'authority_source'=>'homeserver','dataset'=>'tasks','authority_key'=>$item['authority_key'],
          'canonical_id'=>$item['canonical_id'],'record_revision'=>$item['record_revision'],
          'title'=>(string)($item['title']??'HomeServer task'),'content'=>(string)($item['description']??''),
          'updated_at'=>(string)($item['updated_at']??''),
        ],'vp3_cloud');
        $out[]=$item;
    }
    return $out;
}

function homeserver_task_calendar_v243_unified_tasks(int $userId,string $query='',int $limit=150): array
{
    $limit=max(1,min(250,$limit));$items=[];$seen=[];$sources=['vp3_cloud'=>0,'homeserver'=>0];
    foreach(homeserver_task_calendar_v243_cloud_tasks($userId,$query,$limit) as $item){
        $canonical=(string)$item['canonical_id'];if(isset($seen[$canonical]))continue;
        $seen[$canonical]=true;$items[]=$item;$sources['vp3_cloud']++;
        if(count($items)>=$limit)break;
    }
    if(count($items)<$limit){
        foreach(homeserver_task_calendar_v243_homeserver_tasks($userId,$query,$limit-count($items)) as $item){
            $canonical=(string)$item['canonical_id'];if(isset($seen[$canonical]))continue;
            $seen[$canonical]=true;$items[]=$item;$sources['homeserver']++;
            if(count($items)>=$limit)break;
        }
    }
    return ['version'=>'2.4','dataset'=>'tasks','items'=>$items,'count'=>count($items),'sources'=>$sources];
}

function homeserver_task_calendar_v243_iso(string $value): ?string
{
    $value=trim($value);if($value==='')return null;
    try{return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
    catch(Throwable $e){return null;}
}

function homeserver_task_calendar_v243_homeserver_calendar_items(int $userId,string $fromUtc,string $toUtc,int $limit=100): array
{
    if($userId<1)return [];
    try{
        $from=(new DateTimeImmutable($fromUtc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        $to=(new DateTimeImmutable($toUtc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }catch(Throwable $e){return [];}
    $rows=homeserver_task_calendar_v243_remote_tool($userId,'calendar.list',[
      'from_at'=>$from,'to_at'=>$to,'limit'=>max(1,min(100,$limit)),
    ]);
    $out=[];
    foreach($rows as $row){
        $item=homeserver_task_calendar_v243_validate_remote($row,'calendar','calendar_event:');if(!$item)continue;
        $start=homeserver_task_calendar_v243_iso((string)($item['start_at']??''));
        $end=homeserver_task_calendar_v243_iso((string)($item['end_at']??''));
        if(!$start||!$end)continue;
        homeserver_federated_v240_observe($userId,[
          'authority_source'=>'homeserver','dataset'=>'calendar','authority_key'=>$item['authority_key'],
          'canonical_id'=>$item['canonical_id'],'record_revision'=>$item['record_revision'],
          'title'=>(string)($item['title']??'HomeServer calendar event'),
          'content'=>(string)($item['description']??''),'updated_at'=>(string)($item['updated_at']??''),
        ],'vp3_cloud');
        $out[]=[
          'kind'=>'homeserver_event','id'=>(int)($item['id']??0),
          'title'=>(string)($item['title']??'HomeServer event'),
          'description'=>(string)($item['description']??''),'location'=>(string)($item['location']??''),
          'start_at_utc'=>$start,'end_at_utc'=>$end,'timezone'=>(string)($item['timezone']??'UTC'),
          'all_day'=>!empty($item['all_day']),'source'=>'homeserver','status'=>'active','editable'=>false,
          'homeserver_managed'=>true,'authority_source'=>'homeserver','authority_key'=>$item['authority_key'],
          'canonical_id'=>$item['canonical_id'],'record_revision'=>$item['record_revision'],
          'federation_version'=>'2.4','mirror_only'=>true,'mutation_route'=>'homeserver_governed',
        ];
    }
    return $out;
}

function homeserver_task_calendar_v243_cloud_calendar_records(int $userId,string $fromUtc,string $toUtc): array
{
    $pdo=db();if(!$pdo||$userId<1||!table_exists('user_calendar_events'))return [];
    $from=homeserver_task_calendar_v243_iso($fromUtc);$to=homeserver_task_calendar_v243_iso($toUtc);
    if(!$from||!$to)return [];
    $stmt=$pdo->prepare("SELECT id,title,description,location,start_at_utc,end_at_utc,timezone,all_day,source,status,updated_at
      FROM user_calendar_events WHERE owner_user_id=? AND status='active' AND start_at_utc<? AND end_at_utc>?
      ORDER BY start_at_utc,id LIMIT 300");
    $stmt->execute([$userId,$to,$from]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $key='user_calendar_event:'.(int)$row['id'];
        $content=trim((string)$row['description'].' · '.(string)$row['location'].' · '.(string)$row['start_at_utc'].' → '.(string)$row['end_at_utc']);
        $envelope=homeserver_federated_v240_envelope('vp3_cloud','calendar',$key,(string)$row['title'],$content,(string)($row['updated_at']??''));
        homeserver_federated_v240_observe($userId,$envelope,'vp3_cloud');
        $row['authority_source']='vp3_cloud';$row['authority_key']=$key;$row['canonical_id']=$envelope['canonical_id'];
        $row['record_revision']=$envelope['record_revision'];$row['federation_version']='2.4';$row['mirror_only']=false;
        $row['mutation_route']='cloud_native_calendar';$row['source_label']='VP3 Cloud';
        $out[]=$row;
    }
    return $out;
}

function homeserver_task_calendar_v243_unified_calendar(int $userId,string $fromUtc,string $toUtc): array
{
    $cloud=homeserver_task_calendar_v243_cloud_calendar_records($userId,$fromUtc,$toUtc);
    $home=homeserver_task_calendar_v243_homeserver_calendar_items($userId,$fromUtc,$toUtc,100);
    return ['version'=>'2.4','dataset'=>'calendar','items'=>array_merge($cloud,$home),'count'=>count($cloud)+count($home),
      'sources'=>['vp3_cloud'=>count($cloud),'homeserver'=>count($home)]];
}

function homeserver_task_calendar_v243_request_homeserver(int $userId,string $dataset,string $action,array $payload): array
{
    $dataset=strtolower(trim($dataset));$action=strtolower(trim($action));
    $tools=[
      'tasks'=>['create'=>'tasks.create','update'=>'tasks.update','delete'=>'tasks.delete'],
      'calendar'=>['create'=>'calendar.create','update'=>'calendar.update','delete'=>'calendar.delete'],
    ];
    $tool=$tools[$dataset][$action]??'';
    if($tool==='')throw new RuntimeException('Unsupported HomeServer task/calendar action.');
    if(in_array($action,['update','delete'],true)){
        $canonical=strtolower(trim((string)($payload['canonical_id']??'')));
        if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonical))throw new RuntimeException('A valid HomeServer canonical ID is required.');
        $records=$dataset==='tasks'
          ? homeserver_task_calendar_v243_homeserver_tasks($userId,'',50)
          : homeserver_task_calendar_v243_homeserver_calendar_items($userId,'2000-01-01 00:00:00','2100-01-01 00:00:00',100);
        $found=false;foreach($records as $record){if(hash_equals($canonical,(string)($record['canonical_id']??''))){$found=true;break;}}
        if(!$found)throw new RuntimeException('HomeServer record is unavailable or owned by another authority.');
    }
    return homeserver_governed_v233_request($userId,$tool,$payload);
}
