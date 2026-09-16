<?php
declare(strict_types=1);

function video_meeting_memory_push_v18120(array &$entries,string $category,string $text,array $meta=[]): void
{
    $text=video_meeting_memory_text_v18120($text,1800);if($text==='')return;
    $row=['category'=>$category,'text'=>$text];
    foreach(['owner','due_date','status'] as $key){
        $value=video_meeting_memory_text_v18120($meta[$key]??'',190);
        if($value!=='')$row[$key]=$value;
    }
    $fingerprint=hash('sha256',$category.'|'.video_meeting_memory_normalize_v18120($text).'|'.(string)($row['owner']??'').'|'.(string)($row['due_date']??'').'|'.(string)($row['status']??''));
    foreach($entries as $existing)if(($existing['fingerprint']??'')===$fingerprint)return;
    $row['fingerprint']=$fingerprint;$entries[]=$row;
}

function video_meeting_memory_index_build_v18120(PDO $pdo,array $meeting): array
{
    $final=video_meeting_memory_require_final_v18120($pdo,$meeting);$public=$final['public'];$snapshot=is_array($public['snapshot']??null)?$public['snapshot']:[];
    $entries=[];
    video_meeting_memory_push_v18120($entries,'meeting_title',(string)($meeting['title']??''));
    video_meeting_memory_push_v18120($entries,'summary',(string)($snapshot['summary']??''));

    foreach(array_slice((array)($snapshot['key_points']??[]),0,18) as $row){
        $text=is_array($row)?video_meeting_memory_first_v18120($row,['point','text','finding','summary']):video_meeting_memory_text_v18120($row);
        video_meeting_memory_push_v18120($entries,'key_point',$text);
    }
    foreach(array_slice((array)($snapshot['decisions']??[]),0,24) as $row){
        if(!is_array($row))continue;$text=video_meeting_memory_first_v18120($row,['decision','commitment','text']);
        video_meeting_memory_push_v18120($entries,'decision',$text,['owner'=>$row['owner']??'','due_date'=>$row['due_date']??$row['timing']??'','status'=>$row['status']??'']);
    }
    foreach(array_slice((array)($snapshot['actions']??[]),0,24) as $row){
        if(!is_array($row))continue;$text=video_meeting_memory_first_v18120($row,['action','follow_up','next_step','text']);
        video_meeting_memory_push_v18120($entries,'action',$text,['owner'=>$row['owner']??'','due_date'=>$row['due_date']??$row['timing']??'','status'=>$row['status']??'']);
    }
    foreach(array_slice((array)($snapshot['questions']??[]),0,16) as $row){
        if(!is_array($row))continue;video_meeting_memory_push_v18120($entries,'question',video_meeting_memory_first_v18120($row,['question','text']));
    }
    foreach(array_slice((array)($snapshot['risks']??[]),0,12) as $row){
        if(!is_array($row))continue;video_meeting_memory_push_v18120($entries,'risk',video_meeting_memory_first_v18120($row,['risk','blocker','text']));
    }
    foreach(array_slice((array)($snapshot['topics']??[]),0,18) as $row){
        $text=is_array($row)?video_meeting_memory_first_v18120($row,['topic','text','name','title']):video_meeting_memory_text_v18120($row);
        video_meeting_memory_push_v18120($entries,'topic',$text);
    }
    foreach(array_slice((array)($public['objectives']??[]),0,20) as $row){
        if(!is_array($row))continue;video_meeting_memory_push_v18120($entries,'objective',(string)($row['objective_text']??''),['status'=>$row['status']??'']);
    }

    $participants=[];
    $stmt=$pdo->prepare("SELECT display_name FROM video_meeting_participants WHERE meeting_id=? AND TRIM(display_name)<>'' ORDER BY role='organizer' DESC,id ASC LIMIT 32");
    $stmt->execute([(int)$meeting['id']]);
    foreach($stmt->fetchAll()?:[] as $row){
        $name=video_meeting_memory_text_v18120($row['display_name']??'',190);if($name==='')continue;
        $key=video_meeting_memory_normalize_v18120($name);if(isset($participants[$key]))continue;$participants[$key]=$name;
        video_meeting_memory_push_v18120($entries,'participant',$name);
    }

    $queue=video_meeting_followthrough_artifact_v18100($pdo,$meeting,(string)$final['source_hash']);
    foreach(array_slice((array)($queue['items']??[]),0,50) as $item){
        if(!is_array($item))continue;$status=strtolower((string)($item['status']??'suggested'));
        $category=$status==='verified'?'followthrough_verified':($status==='failed'?'followthrough_failed':'followthrough_pending');
        video_meeting_memory_push_v18120($entries,$category,(string)($item['title']??''),[
            'owner'=>$item['owner']??'','due_date'=>$item['due_date']??'','status'=>$status,
        ]);
    }

    $when=(string)($meeting['processed_at']??$meeting['ended_at']??$meeting['end_at_utc']??$meeting['start_at_utc']??'');
    $semantic=[
        'version'=>'v18.12','schema'=>'vp3.meeting.memory.index','meeting'=>[
            'id'=>(int)$meeting['id'],'public_id'=>(string)$meeting['public_id'],'title'=>video_meeting_memory_text_v18120($meeting['title']??'',190),
            'when_utc'=>$when,'start_at_utc'=>(string)($meeting['start_at_utc']??''),'timezone'=>(string)($meeting['timezone']??'UTC'),
            'review_path'=>url('/meeting.php?meeting='.rawurlencode((string)$meeting['public_id']).'&review=1'),
            'participants'=>array_values($participants),
        ],
        'source_hash'=>(string)$final['source_hash'],'final_source_hash'=>(string)$final['final_source_hash'],'entries'=>array_slice($entries,0,180),
    ];
    $canonical=json_encode($semantic,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($canonical))throw new RuntimeException('Could not fingerprint the meeting memory index.');
    $semantic['index_hash']=hash('sha256',$canonical);$semantic['generated_at']=gmdate('c');
    return $semantic;
}

function video_meeting_memory_ensure_index_v18120(PDO $pdo,array $meeting,bool $force=false): array
{
    $final=video_meeting_memory_require_final_v18120($pdo,$meeting);$sourceHash=(string)$final['source_hash'];
    if(!$force){$existing=video_meeting_memory_artifact_v18120($pdo,$meeting,$sourceHash);if($existing)return $existing;}
    return video_meeting_memory_store_v18120($pdo,$meeting,video_meeting_memory_index_build_v18120($pdo,$meeting));
}
