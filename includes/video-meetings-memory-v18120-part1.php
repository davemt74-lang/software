<?php
declare(strict_types=1);

function video_meeting_memory_text_v18120(mixed $value,int $limit=1800): string
{
    $text=trim(preg_replace('/\s+/u',' ',(string)$value)??'');
    return mb_strimwidth($text,0,max(0,$limit),'…');
}

function video_meeting_memory_first_v18120(array $row,array $keys,int $limit=1800): string
{
    foreach($keys as $key){
        $text=video_meeting_memory_text_v18120($row[$key]??'',$limit);
        if($text!=='')return $text;
    }
    return '';
}

function video_meeting_memory_normalize_v18120(string $value): string
{
    return mb_strtolower(video_meeting_memory_text_v18120($value,6000));
}

function video_meeting_memory_tokens_v18120(string $query): array
{
    $query=video_meeting_memory_normalize_v18120($query);
    $parts=preg_split('/[^\p{L}\p{N}]+/u',$query,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $stop=array_fill_keys([
        'a','an','and','are','as','at','be','did','do','does','for','from','had','has','have','i','in','is','it','me','my','of','on','or','our','the','to','was','we','were','what','when','where','which','who','with','you',
        'find','show','tell','meeting','meetings','about','last','this','that','those','these','month','week','year','day','days','weeks','months','years'
    ],true);
    $tokens=[];
    foreach($parts as $part){
        $part=video_meeting_memory_text_v18120($part,80);
        if($part===''||mb_strlen($part)<2||isset($stop[$part]))continue;
        $tokens[$part]=true;
        if(count($tokens)>=12)break;
    }
    return array_keys($tokens);
}

function video_meeting_memory_query_relevant_v18120(string $query): bool
{
    $q=video_meeting_memory_normalize_v18120($query);
    if($q==='')return false;
    foreach(['meeting','agreed','agree to','decided','decision','commitment','committed','follow-up','follow up','followup','action item','discussed','discussion','said in','talked about','last month','last week','meeting history'] as $needle){
        if(mb_stripos($q,$needle)!==false)return true;
    }
    return false;
}

function video_meeting_memory_date_window_v18120(string $query): ?array
{
    $q=video_meeting_memory_normalize_v18120($query);
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $today=$now->setTime(0,0);
    $start=null;$end=null;$label='';
    if(str_contains($q,'yesterday')){$start=$today->modify('-1 day');$end=$today;$label='yesterday';}
    elseif(preg_match('/\btoday\b/u',$q)){$start=$today;$end=$today->modify('+1 day');$label='today';}
    elseif(str_contains($q,'last week')){$start=$today->modify('monday last week');$end=$start->modify('+7 days');$label='last week';}
    elseif(str_contains($q,'this week')){$start=$today->modify('monday this week');$end=$start->modify('+7 days');$label='this week';}
    elseif(str_contains($q,'last month')){$start=$today->modify('first day of last month');$end=$today->modify('first day of this month');$label='last month';}
    elseif(str_contains($q,'this month')){$start=$today->modify('first day of this month');$end=$start->modify('+1 month');$label='this month';}
    elseif(str_contains($q,'this year')){$start=$today->setDate((int)$today->format('Y'),1,1);$end=$start->modify('+1 year');$label='this year';}
    elseif(preg_match('/\blast\s+(\d{1,3})\s+(day|days|week|weeks|month|months)\b/u',$q,$m)){
        $n=max(1,min(365,(int)$m[1]));$unit=(string)$m[2];
        if(str_starts_with($unit,'week'))$start=$today->modify('-'.$n.' weeks');
        elseif(str_starts_with($unit,'month'))$start=$today->modify('-'.$n.' months');
        else $start=$today->modify('-'.$n.' days');
        $end=$now->modify('+1 second');$label='last '.$n.' '.$unit;
    }
    if(!$start||!$end)return null;
    return ['start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s'),'label'=>$label];
}

function video_meeting_memory_require_final_v18120(PDO $pdo,array $meeting): array
{
    if(!in_array(strtolower((string)($meeting['status']??'')),['ended','processed'],true))throw new RuntimeException('Meeting memory is available after the meeting ends.');
    $public=video_meeting_intelligence_public_state_v1890($pdo,$meeting);
    $sourceHash=(string)($public['source_hash']??'');
    $state=video_meeting_intelligence_state_row_v1820($pdo,$meeting);
    $finalHash=(string)($state['final_source_hash']??'');
    if($sourceHash===''||empty($public['final_analysis_at'])||!empty($public['final_analysis_due'])||$finalHash===''||!hash_equals($finalHash,$sourceHash)){
        throw new RuntimeException('Finalize and review the current meeting intelligence before it can become searchable memory.');
    }
    return ['public'=>$public,'source_hash'=>$sourceHash,'final_source_hash'=>$finalHash];
}

function video_meeting_memory_artifact_v18120(PDO $pdo,array $meeting,string $sourceHash): ?array
{
    if($sourceHash==='')return null;
    $stmt=$pdo->prepare('SELECT id,result_json,generated_at FROM video_meeting_artifacts WHERE meeting_id=? AND app_id=? AND artifact_type=? AND source_hash=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$meeting['id'],VP3_VIDEO_MEETINGS_MEMORY_APP_V18120,VP3_VIDEO_MEETINGS_MEMORY_ARTIFACT_V18120,$sourceHash]);
    $row=$stmt->fetch();if(!is_array($row))return null;
    $index=json_decode((string)($row['result_json']??''),true);if(!is_array($index))return null;
    if((string)($index['version']??'')!=='v18.12'||(int)($index['meeting']['id']??0)!==(int)$meeting['id']||!hash_equals((string)($index['source_hash']??''),$sourceHash))return null;
    $index['artifact_id']=(int)$row['id'];$index['artifact_generated_at']=(string)$row['generated_at'];
    return $index;
}

function video_meeting_memory_store_v18120(PDO $pdo,array $meeting,array $index): array
{
    $json=json_encode($index,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))throw new RuntimeException('Could not encode the meeting memory index.');
    $stmt=$pdo->prepare('INSERT INTO video_meeting_artifacts (meeting_id,app_id,artifact_type,result_json,source_hash,generated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE result_json=VALUES(result_json),artifact_type=VALUES(artifact_type),generated_at=NOW()');
    $stmt->execute([(int)$meeting['id'],VP3_VIDEO_MEETINGS_MEMORY_APP_V18120,VP3_VIDEO_MEETINGS_MEMORY_ARTIFACT_V18120,$json,(string)$index['source_hash']]);
    $stored=video_meeting_memory_artifact_v18120($pdo,$meeting,(string)$index['source_hash']);
    if(!$stored)throw new RuntimeException('Meeting memory index could not be verified after storage.');
    return $stored;
}
