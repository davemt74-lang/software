<?php
declare(strict_types=1);

function video_meeting_memory_intent_v18120(string $query): array
{
    $q=video_meeting_memory_normalize_v18120($query);
    return [
        'decision'=>preg_match('/\b(agree|agreed|agreement|decide|decided|decision|commit|committed|commitment)\b/u',$q)===1,
        'followthrough'=>preg_match('/\b(follow[ -]?up|followup|action item|pending|outstanding|unfinished|still need)\b/u',$q)===1,
        'discussion'=>preg_match('/\b(discuss|discussed|discussion|talked|said|mention|mentioned|find)\b/u',$q)===1,
        'participant'=>preg_match('/\b(who|participant|attendee|with whom|met with)\b/u',$q)===1,
    ];
}

function video_meeting_memory_content_tokens_v18120(string $query): array
{
    $intentWords=array_fill_keys(['agree','agreed','agreement','decide','decided','decision','commit','committed','commitment','follow','followup','action','item','pending','outstanding','unfinished','discuss','discussed','discussion','talked','said','mention','mentioned'],true);
    return array_values(array_filter(video_meeting_memory_tokens_v18120($query),static fn(string $token): bool=>!isset($intentWords[$token])));
}

function video_meeting_memory_category_weight_v18120(string $category,array $intent): int
{
    $weights=['meeting_title'=>11,'decision'=>10,'followthrough_verified'=>10,'action'=>9,'followthrough_pending'=>8,'objective'=>7,'summary'=>6,'key_point'=>6,'topic'=>6,'question'=>5,'risk'=>5,'participant'=>4,'followthrough_failed'=>3];
    $score=$weights[$category]??3;
    if(!empty($intent['decision'])&&in_array($category,['decision','followthrough_verified'],true))$score+=14;
    if(!empty($intent['followthrough'])&&in_array($category,['action','followthrough_pending','followthrough_verified','followthrough_failed'],true))$score+=14;
    if(!empty($intent['discussion'])&&in_array($category,['meeting_title','summary','key_point','topic','decision'],true))$score+=8;
    if(!empty($intent['participant'])&&$category==='participant')$score+=12;
    return $score;
}

function video_meeting_memory_search_v18120(PDO $pdo,int $ownerUserId,string $query,int $limit=12,int $cursor=0): array
{
    $query=video_meeting_memory_text_v18120($query,300);if($query==='')throw new RuntimeException('Enter a meeting memory search.');
    $limit=max(1,min(VP3_VIDEO_MEETINGS_MEMORY_RESULT_LIMIT_V18120,$limit));
    $cursor=max(0,min(VP3_VIDEO_MEETINGS_MEMORY_CURSOR_LIMIT_V18120,$cursor));
    $tokens=video_meeting_memory_content_tokens_v18120($query);$intent=video_meeting_memory_intent_v18120($query);$window=video_meeting_memory_date_window_v18120($query);
    $phrase=video_meeting_memory_normalize_v18120($query);$matches=[];$scanned=0;$indexed=0;
    $stmt=$pdo->prepare("SELECT * FROM video_meetings WHERE owner_user_id=? AND status IN ('ended','processed') ORDER BY COALESCE(processed_at,ended_at,end_at_utc,start_at_utc) DESC,id DESC LIMIT ".VP3_VIDEO_MEETINGS_MEMORY_SCAN_LIMIT_V18120);
    $stmt->execute([$ownerUserId]);
    foreach($stmt->fetchAll()?:[] as $meeting){
        if(!is_array($meeting))continue;$scanned++;
        $when=(string)($meeting['processed_at']??$meeting['ended_at']??$meeting['end_at_utc']??$meeting['start_at_utc']??'');$whenTs=strtotime($when)?:0;
        if($window){$start=strtotime((string)$window['start'])?:0;$end=strtotime((string)$window['end'])?:PHP_INT_MAX;if($whenTs<$start||$whenTs>=$end)continue;}
        try{$index=video_meeting_memory_ensure_index_v18120($pdo,$meeting,false);}catch(Throwable $ignored){continue;}$indexed++;
        $meetingMatches=[];$title=video_meeting_memory_normalize_v18120((string)($index['meeting']['title']??''));
        foreach((array)($index['entries']??[]) as $entry){
            if(!is_array($entry))continue;$category=(string)($entry['category']??'');$text=(string)($entry['text']??'');if($category===''||$text==='')continue;
            $haystack=video_meeting_memory_normalize_v18120($text.' '.$title);$tokenHits=0;$score=video_meeting_memory_category_weight_v18120($category,$intent);
            foreach($tokens as $token){if(mb_stripos($haystack,$token)!==false){$tokenHits++;$score+=9;}}
            if($tokens&&$tokenHits===0)continue;
            if(!$tokens&&!$window&&!array_filter($intent))continue;
            if($phrase!==''&&mb_strlen($phrase)>=4&&mb_stripos($haystack,$phrase)!==false)$score+=18;
            $status=video_meeting_memory_text_v18120($entry['status']??'',40);
            $meetingMatches[]=[
                'meeting_id'=>(int)$index['meeting']['id'],'meeting_public_id'=>(string)$index['meeting']['public_id'],'meeting_title'=>(string)$index['meeting']['title'],
                'meeting_when_utc'=>(string)$index['meeting']['when_utc'],'meeting_timezone'=>(string)$index['meeting']['timezone'],'review_path'=>(string)$index['meeting']['review_path'],
                'category'=>$category,'text'=>video_meeting_memory_text_v18120($text,1800),'owner'=>video_meeting_memory_text_v18120($entry['owner']??'',190),
                'due_date'=>video_meeting_memory_text_v18120($entry['due_date']??'',80),'status'=>$status,'score'=>$score,
                'provenance'=>[
                    'artifact_id'=>(int)($index['artifact_id']??0),'index_hash'=>(string)($index['index_hash']??''),'source_hash'=>(string)($index['source_hash']??''),
                    'final_source_hash'=>(string)($index['final_source_hash']??''),'version'=>'v18.12','category'=>$category,
                ],
            ];
        }
        usort($meetingMatches,static function(array $a,array $b): int{return ($b['score']<=>$a['score'])?:strcmp((string)$a['category'],(string)$b['category'])?:strcmp((string)$a['text'],(string)$b['text']);});
        foreach(array_slice($meetingMatches,0,12) as $row)$matches[]=$row;
    }
    usort($matches,static function(array $a,array $b): int {
        return ($b['score']<=>$a['score']) ?: (strcmp((string)$b['meeting_when_utc'],(string)$a['meeting_when_utc'])) ?: ($b['meeting_id']<=>$a['meeting_id']) ?: strcmp((string)$a['category'],(string)$b['category']) ?: strcmp((string)$a['text'],(string)$b['text']);
    });
    $total=count($matches);$results=array_slice($matches,$cursor,$limit);$next=$cursor+count($results);$nextCursor=($next<$total&&$next<=VP3_VIDEO_MEETINGS_MEMORY_CURSOR_LIMIT_V18120)?$next:null;
    return [
        'version'=>'v18.12','query'=>$query,'results'=>$results,'result_count'=>count($results),'matched_count'=>$total,
        'scanned_meetings'=>$scanned,'indexed_meetings'=>$indexed,'scan_limit'=>VP3_VIDEO_MEETINGS_MEMORY_SCAN_LIMIT_V18120,
        'cursor'=>$cursor,'next_cursor'=>$nextCursor,'date_window'=>$window,
        'privacy'=>['raw_transcript_indexed'=>false,'private_notes_indexed'=>false,'participant_email_indexed'=>false,'homeserver_private_context_indexed'=>false],
    ];
}

function video_meeting_memory_agent_context_v18120(PDO $pdo,int $ownerUserId,string $query): array
{
    if(!video_meeting_memory_query_relevant_v18120($query))return ['version'=>'v18.12','relevant'=>false,'results'=>[]];
    try{$search=video_meeting_memory_search_v18120($pdo,$ownerUserId,$query,6,0);}catch(Throwable $e){return ['version'=>'v18.12','relevant'=>true,'results'=>[],'error'=>'Meeting memory search is unavailable.'];}
    $rows=[];
    foreach((array)($search['results']??[]) as $row){
        if(!is_array($row))continue;$rows[]=[
            'meeting_title'=>(string)$row['meeting_title'],'meeting_when_utc'=>(string)$row['meeting_when_utc'],'category'=>(string)$row['category'],
            'text'=>(string)$row['text'],'owner'=>(string)$row['owner'],'due_date'=>(string)$row['due_date'],'status'=>(string)$row['status'],
            'review_path'=>(string)$row['review_path'],'provenance'=>$row['provenance'],
        ];
    }
    return [
        'version'=>'v18.12','relevant'=>true,'source'=>'vp3_meeting_memory','results'=>$rows,
        'instructions'=>'These are source-backed finalized meeting-memory excerpts. Distinguish pending, failed and verified follow-through exactly as labeled. Do not claim a pending item was completed. Cite the meeting title/date or review path when relying on a result.',
    ];
}
