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

function video_meeting_memory_agenda_query_relevant_v18140(string $query): bool
{
    $q=video_meeting_memory_normalize_v18120($query);
    return $q!==''&&preg_match('/\b(agenda|meeting|meetings|prepare|prep|cover|talk about|talking points|what should we discuss|what should we cover)\b/u',$q)===1;
}

function video_meeting_memory_upcoming_agenda_v18140(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1||!table_exists('video_meeting_agenda_items'))return [];
    $stmt=$pdo->prepare("SELECT id,public_id,title,start_at_utc,timezone,status FROM video_meetings WHERE owner_user_id=? AND status<>'cancelled' AND start_at_utc IS NOT NULL AND start_at_utc>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND start_at_utc<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY start_at_utc ASC,id ASC LIMIT 4");
    $stmt->execute([$ownerUserId]);$out=[];
    $itemsStmt=$pdo->prepare("SELECT id,item_text,item_type,status,priority,action_kind,approval_state,sort_order,source_kind,source_review_path FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? ORDER BY sort_order,id LIMIT 40");
    foreach($stmt->fetchAll()?:[] as $meeting){if(!is_array($meeting))continue;$itemsStmt->execute([(int)$meeting['id'],$ownerUserId]);$items=[];foreach($itemsStmt->fetchAll()?:[] as $item){if(!is_array($item))continue;$items[]=['id'=>(int)$item['id'],'text'=>video_meeting_memory_text_v18120($item['item_text']??'',1200),'type'=>(string)$item['item_type'],'status'=>(string)$item['status'],'priority'=>(string)$item['priority'],'action_kind'=>(string)$item['action_kind'],'approval_state'=>(string)$item['approval_state'],'source_kind'=>(string)$item['source_kind'],'source_review_path'=>(string)$item['source_review_path']];}
        if(!$items)continue;$out[]=['meeting_id'=>(int)$meeting['id'],'meeting_public_id'=>(string)$meeting['public_id'],'meeting_title'=>video_meeting_memory_text_v18120($meeting['title']??'Meeting',190),'start_at_utc'=>(string)$meeting['start_at_utc'],'timezone'=>(string)($meeting['timezone']??'UTC'),'status'=>(string)$meeting['status'],'agenda_items'=>$items,'review_path'=>'/meeting.php?meeting='.rawurlencode((string)$meeting['public_id'])];}
    return $out;
}

function video_meeting_memory_agent_context_v18120(PDO $pdo,int $ownerUserId,string $query): array
{
    $memoryRelevant=video_meeting_memory_query_relevant_v18120($query);$agendaRelevant=video_meeting_memory_agenda_query_relevant_v18140($query);
    if(!$memoryRelevant&&!$agendaRelevant)return ['version'=>'v18.12','relevant'=>false,'results'=>[]];
    $search=['results'=>[]];
    if($memoryRelevant){try{$search=video_meeting_memory_search_v18120($pdo,$ownerUserId,$query,6,0);}catch(Throwable $e){$search=['results'=>[],'error'=>'Meeting memory search is unavailable.'];}}
    $rows=[];
    foreach((array)($search['results']??[]) as $row){
        if(!is_array($row))continue;$rows[]=[
            'meeting_id'=>(int)$row['meeting_id'],'meeting_public_id'=>(string)$row['meeting_public_id'],
            'meeting_title'=>(string)$row['meeting_title'],'meeting_when_utc'=>(string)$row['meeting_when_utc'],'category'=>(string)$row['category'],
            'text'=>(string)$row['text'],'owner'=>(string)$row['owner'],'due_date'=>(string)$row['due_date'],'status'=>(string)$row['status'],
            'review_path'=>(string)$row['review_path'],'provenance'=>$row['provenance'],
        ];
    }
    $agendas=$agendaRelevant?video_meeting_memory_upcoming_agenda_v18140($pdo,$ownerUserId):[];
    return [
        'version'=>'v18.12','agenda_version'=>'v18.14','relevant'=>true,'source'=>'vp3_meeting_memory','results'=>$rows,'upcoming_agendas'=>$agendas,
        'error'=>(string)($search['error']??''),
        'instructions'=>'Finalized Meeting Memory results are source-backed. Upcoming agendas are organizer-owned Phase 18.14 state. Distinguish pending, failed and verified follow-through exactly as labeled. An agenda item marked approved_for_agent_review is approved for Agent review only; it does not mean a Task, Calendar event, CRM update, email, notification or tool action was executed. Cite the meeting title/date or meeting path when relying on meeting context.',
    ];
}

function video_meeting_memory_chat_sources_v18120(array $memory): array
{
    if(empty($memory['relevant']))return [];$sources=[];$seen=[];
    foreach((array)($memory['results']??[]) as $row){
        if(!is_array($row))continue;
        $meetingId=max(0,(int)($row['meeting_id']??0));$publicId=trim((string)($row['meeting_public_id']??''));
        $sourceHash=trim((string)($row['provenance']['source_hash']??''));
        if($meetingId<1||$publicId===''||$sourceHash==='')continue;
        $key='memory|'.$meetingId.'|'.$sourceHash;if(isset($seen[$key]))continue;$seen[$key]=true;
        $path=trim((string)($row['review_path']??''));
        if($path===''||!str_starts_with($path,'/meeting.php?'))$path='/meeting.php?meeting='.rawurlencode($publicId).'&review=1';
        $when=video_meeting_memory_text_v18120($row['meeting_when_utc']??'',40);
        $title=video_meeting_memory_text_v18120($row['meeting_title']??'Meeting',190);
        $sources[]=['source'=>'video_meeting_memory:'.$meetingId.':'.substr($sourceHash,0,16),'title'=>$title.($when!==''?' · '.$when:''),'url'=>url($path)];
        if(count($sources)>=6)break;
    }
    foreach((array)($memory['upcoming_agendas']??[]) as $agenda){
        if(!is_array($agenda))continue;$meetingId=(int)($agenda['meeting_id']??0);$publicId=trim((string)($agenda['meeting_public_id']??''));if($meetingId<1||$publicId==='')continue;$key='agenda|'.$meetingId;if(isset($seen[$key]))continue;$seen[$key]=true;$title=video_meeting_memory_text_v18120($agenda['meeting_title']??'Meeting agenda',190);$when=video_meeting_memory_text_v18120($agenda['start_at_utc']??'',40);$sources[]=['source'=>'video_meeting_agenda:'.$meetingId,'title'=>$title.($when!==''?' · '.$when:''),'url'=>url('/meeting.php?meeting='.rawurlencode($publicId))];if(count($sources)>=8)break;
    }
    return $sources;
}
