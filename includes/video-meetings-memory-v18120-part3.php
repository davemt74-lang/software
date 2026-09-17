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
    $stmt=$pdo->prepare("SELECT id,public_id,title,start_at_utc,timezone,status FROM video_meetings WHERE owner_user_id=? AND status NOT IN ('cancelled','ended','processed') AND start_at_utc IS NOT NULL AND start_at_utc>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND start_at_utc<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY start_at_utc ASC,id ASC LIMIT 4");
    $stmt->execute([$ownerUserId]);$out=[];
    $itemsStmt=$pdo->prepare("SELECT id,item_text,item_type,status,priority,action_kind,approval_state,sort_order,source_kind,source_review_path FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? ORDER BY sort_order,id LIMIT 40");
    foreach($stmt->fetchAll()?:[] as $meeting){if(!is_array($meeting))continue;$itemsStmt->execute([(int)$meeting['id'],$ownerUserId]);$items=[];foreach($itemsStmt->fetchAll()?:[] as $item){if(!is_array($item))continue;$items[]=['id'=>(int)$item['id'],'text'=>video_meeting_memory_text_v18120($item['item_text']??'',1200),'type'=>(string)$item['item_type'],'status'=>(string)$item['status'],'priority'=>(string)$item['priority'],'action_kind'=>(string)$item['action_kind'],'approval_state'=>(string)$item['approval_state'],'source_kind'=>(string)$item['source_kind'],'source_review_path'=>(string)$item['source_review_path']];}
        if(!$items)continue;$out[]=['meeting_id'=>(int)$meeting['id'],'meeting_public_id'=>(string)$meeting['public_id'],'meeting_title'=>video_meeting_memory_text_v18120($meeting['title']??'Meeting',190),'start_at_utc'=>(string)$meeting['start_at_utc'],'timezone'=>(string)($meeting['timezone']??'UTC'),'status'=>(string)$meeting['status'],'agenda_items'=>$items,'review_path'=>'/meeting.php?meeting='.rawurlencode((string)$meeting['public_id'])];}
    return $out;
}

function video_meeting_memory_action_query_relevant_v18150(string $query): bool
{
    $q=video_meeting_memory_normalize_v18120($query);
    return $q!==''&&preg_match('/\b(meeting action|meeting actions|after (?:the|our) meeting|follow[ -]?through|follow[ -]?up|executed|execution|what did we do|what happened after)\b/u',$q)===1;
}

function video_meeting_memory_action_history_v18150(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1||!table_exists('video_meeting_action_executions')||!table_exists('video_meeting_agenda_items'))return [];
    $stmt=$pdo->prepare("SELECT e.id,e.meeting_id,e.action_kind,e.status,e.workflow_run_id,e.calendar_event_id,e.crm_lead_id,e.result_summary,e.executed_at,e.completed_at,m.public_id,m.title,m.start_at_utc,a.item_text
      FROM video_meeting_action_executions e
      JOIN video_meetings m ON m.id=e.meeting_id
      JOIN video_meeting_agenda_items a ON a.id=e.agenda_item_id
      WHERE e.owner_user_id=? AND e.status IN ('executed','failed','completed')
      ORDER BY COALESCE(e.completed_at,e.executed_at,e.updated_at) DESC,e.id DESC LIMIT 8");
    $stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){if(!is_array($row))continue;$out[]=[
        'execution_id'=>(int)$row['id'],'meeting_id'=>(int)$row['meeting_id'],'meeting_public_id'=>(string)$row['public_id'],'meeting_title'=>video_meeting_memory_text_v18120($row['title']??'Meeting',190),
        'meeting_start_at_utc'=>(string)$row['start_at_utc'],'action_kind'=>(string)$row['action_kind'],'status'=>(string)$row['status'],
        'agenda_text'=>video_meeting_memory_text_v18120($row['item_text']??'',600),'result_summary'=>video_meeting_memory_text_v18120($row['result_summary']??'',800),
        'workflow_run_id'=>(int)($row['workflow_run_id']??0),'calendar_event_id'=>(int)($row['calendar_event_id']??0),'crm_lead_id'=>(int)($row['crm_lead_id']??0),
        'review_path'=>'/meeting.php?meeting='.rawurlencode((string)$row['public_id']),
    ];}
    return $out;
}

function video_meeting_memory_continuity_query_relevant_v18210(string $query): bool
{
    $q=video_meeting_memory_normalize_v18120($query);
    return $q!==''&&preg_match('/\b(continuity|carry forward|carried forward|cross[ -]?meeting|same (?:commitment|thread)|prior meetings?|previous meetings?|across meetings?|what (?:did|have) we promise|promised|promise|unresolved commitments?)\b/u',$q)===1;
}

function video_meeting_memory_continuity_context_v18210(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1||!table_exists('video_meeting_continuity_links')||!table_exists('video_meeting_agenda_items'))return [];
    $stmt=$pdo->prepare("SELECT l.id,l.status,l.source_meeting_id,l.source_agenda_item_id,l.target_meeting_id,l.target_agenda_item_id,
      sm.public_id AS source_public_id,sm.title AS source_title,sm.start_at_utc AS source_start_at_utc,
      tm.public_id AS target_public_id,tm.title AS target_title,tm.start_at_utc AS target_start_at_utc,
      sa.item_text AS source_text,sa.item_type AS source_item_type,sa.status AS source_item_status,
      ta.item_text AS target_text,ta.item_type AS target_item_type,ta.status AS target_item_status,
      te.action_kind AS target_action_kind,te.status AS target_action_status
      FROM video_meeting_continuity_links l
      JOIN video_meetings sm ON sm.id=l.source_meeting_id AND sm.owner_user_id=l.owner_user_id
      JOIN video_meetings tm ON tm.id=l.target_meeting_id AND tm.owner_user_id=l.owner_user_id
      JOIN video_meeting_agenda_items sa ON sa.id=l.source_agenda_item_id AND sa.owner_user_id=l.owner_user_id
      LEFT JOIN video_meeting_agenda_items ta ON ta.id=l.target_agenda_item_id AND ta.owner_user_id=l.owner_user_id
      LEFT JOIN video_meeting_action_executions te ON te.agenda_item_id=l.target_agenda_item_id AND te.owner_user_id=l.owner_user_id
      WHERE l.owner_user_id=?
      ORDER BY l.updated_at DESC,l.id DESC LIMIT 10");
    $stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){if(!is_array($row))continue;$out[]=[
        'continuity_link_id'=>(int)$row['id'],'status'=>(string)$row['status'],
        'source_meeting_id'=>(int)$row['source_meeting_id'],'source_meeting_public_id'=>(string)$row['source_public_id'],'source_meeting_title'=>video_meeting_memory_text_v18120($row['source_title']??'Meeting',190),'source_start_at_utc'=>(string)$row['source_start_at_utc'],
        'source_text'=>video_meeting_memory_text_v18120($row['source_text']??'',1200),'source_item_type'=>(string)$row['source_item_type'],'source_item_status'=>(string)$row['source_item_status'],'source_review_path'=>'/meeting.php?meeting='.rawurlencode((string)$row['source_public_id']),
        'target_meeting_id'=>(int)$row['target_meeting_id'],'target_meeting_public_id'=>(string)$row['target_public_id'],'target_meeting_title'=>video_meeting_memory_text_v18120($row['target_title']??'Meeting',190),'target_start_at_utc'=>(string)$row['target_start_at_utc'],
        'target_text'=>video_meeting_memory_text_v18120($row['target_text']??'',1200),'target_item_type'=>(string)($row['target_item_type']??''),'target_item_status'=>(string)($row['target_item_status']??''),'target_review_path'=>'/meeting.php?meeting='.rawurlencode((string)$row['target_public_id']),
        'target_action_kind'=>(string)($row['target_action_kind']??''),'target_action_status'=>(string)($row['target_action_status']??''),
    ];}
    return $out;
}

function video_meeting_memory_closure_query_relevant_v18220(string $query): bool
{
    $q=video_meeting_memory_normalize_v18120($query);
    return $q!==''&&preg_match('/\b(close(?:d|ure)? meeting|meeting closure|next meeting|recurring meeting|carried to (?:the )?next|moved to (?:the )?next|closure snapshot|what moved forward|what carried forward)\b/u',$q)===1;
}

function video_meeting_memory_closure_context_v18220(PDO $pdo,int $ownerUserId): array
{
    if($ownerUserId<1||!table_exists('video_meeting_closure_snapshots')||!table_exists('video_meeting_closure_handoffs'))return [];
    $stmt=$pdo->prepare("SELECT s.id AS snapshot_id,s.revision,s.status AS closure_status,s.closed_at,s.meeting_id,
      sm.public_id AS source_public_id,sm.title AS source_title,sm.start_at_utc AS source_start_at_utc,
      h.id AS handoff_id,h.source_agenda_item_id,h.target_meeting_id,h.status AS handoff_status,
      a.item_text AS source_text,tm.public_id AS target_public_id,tm.title AS target_title,tm.start_at_utc AS target_start_at_utc
      FROM video_meeting_closure_snapshots s
      JOIN video_meetings sm ON sm.id=s.meeting_id AND sm.owner_user_id=s.owner_user_id
      LEFT JOIN video_meeting_closure_handoffs h ON h.closure_snapshot_id=s.id AND h.owner_user_id=s.owner_user_id
      LEFT JOIN video_meeting_agenda_items a ON a.id=h.source_agenda_item_id AND a.owner_user_id=s.owner_user_id
      LEFT JOIN video_meetings tm ON tm.id=h.target_meeting_id AND tm.owner_user_id=s.owner_user_id
      WHERE s.owner_user_id=? ORDER BY s.closed_at DESC,s.id DESC,h.id DESC LIMIT 12");
    $stmt->execute([$ownerUserId]);$out=[];
    foreach($stmt->fetchAll()?:[] as $row){if(!is_array($row))continue;$out[]=[
        'snapshot_id'=>(int)$row['snapshot_id'],'revision'=>(int)$row['revision'],'closure_status'=>(string)$row['closure_status'],'closed_at'=>(string)$row['closed_at'],
        'source_meeting_id'=>(int)$row['meeting_id'],'source_meeting_public_id'=>(string)$row['source_public_id'],'source_meeting_title'=>video_meeting_memory_text_v18120($row['source_title']??'Meeting',190),'source_start_at_utc'=>(string)$row['source_start_at_utc'],'source_review_path'=>'/meeting.php?meeting='.rawurlencode((string)$row['source_public_id']),
        'handoff_id'=>(int)($row['handoff_id']??0),'source_text'=>video_meeting_memory_text_v18120($row['source_text']??'',1200),'handoff_status'=>(string)($row['handoff_status']??''),
        'target_meeting_id'=>(int)($row['target_meeting_id']??0),'target_meeting_public_id'=>(string)($row['target_public_id']??''),'target_meeting_title'=>video_meeting_memory_text_v18120($row['target_title']??'',190),'target_start_at_utc'=>(string)($row['target_start_at_utc']??''),'target_review_path'=>!empty($row['target_public_id'])?'/meeting.php?meeting='.rawurlencode((string)$row['target_public_id']):'',
    ];}
    return $out;
}

function video_meeting_memory_agent_context_v18120(PDO $pdo,int $ownerUserId,string $query): array
{
    $memoryRelevant=video_meeting_memory_query_relevant_v18120($query);$agendaRelevant=video_meeting_memory_agenda_query_relevant_v18140($query);$actionRelevant=video_meeting_memory_action_query_relevant_v18150($query);$continuityRelevant=video_meeting_memory_continuity_query_relevant_v18210($query);$closureRelevant=video_meeting_memory_closure_query_relevant_v18220($query);
    if(!$memoryRelevant&&!$agendaRelevant&&!$actionRelevant&&!$continuityRelevant&&!$closureRelevant)return ['version'=>'v18.12','relevant'=>false,'results'=>[]];
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
    $agendas=$agendaRelevant?video_meeting_memory_upcoming_agenda_v18140($pdo,$ownerUserId):[];$actions=$actionRelevant?video_meeting_memory_action_history_v18150($pdo,$ownerUserId):[];$continuity=$continuityRelevant?video_meeting_memory_continuity_context_v18210($pdo,$ownerUserId):[];$closures=$closureRelevant?video_meeting_memory_closure_context_v18220($pdo,$ownerUserId):[];
    return [
        'version'=>'v18.12','agenda_version'=>'v18.14','action_version'=>'v18.15','continuity_version'=>'v18.21','closure_version'=>'v18.22','relevant'=>true,'source'=>'vp3_meeting_memory','results'=>$rows,'upcoming_agendas'=>$agendas,'meeting_actions'=>$actions,'meeting_continuity'=>$continuity,'meeting_closure'=>$closures,
        'error'=>(string)($search['error']??''),
        'instructions'=>'Finalized Meeting Memory results are source-backed. Upcoming agendas are organizer-owned Phase 18.14 state. Meeting Action history is organizer-owned Phase 18.15 execution state and intentionally omits email bodies and recipient addresses. Cross-meeting continuity is organizer-owned Phase 18.21 agenda lineage and is read-only in Agent Chat; carried_forward means the prior thread was linked into another meeting, not that it was completed. Meeting Closure is organizer-owned Phase 18.22 review state; a closed snapshot freezes what was reviewed, and a next-meeting handoff means the organizer explicitly carried context forward, not that any Task, Calendar event, CRM update or Email was executed. Distinguish pending, failed, executed, completed and verified follow-through exactly as labeled. Do not claim a pending item was completed. Do not claim an executed action is completed unless its status is completed. An agenda item marked approved_for_agent_review is approved for Agent review only; it does not mean a Task, Calendar event, CRM update, email, notification or tool action was executed. Continuity, closure and next-meeting mutations require explicit organizer action in the Meeting workspace. Cite the meeting title/date or meeting path when relying on meeting context.',
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
    foreach((array)($memory['meeting_actions']??[]) as $action){
        if(!is_array($action))continue;$meetingId=(int)($action['meeting_id']??0);$publicId=trim((string)($action['meeting_public_id']??''));if($meetingId<1||$publicId==='')continue;$key='action|'.$meetingId;if(isset($seen[$key]))continue;$seen[$key]=true;$title=video_meeting_memory_text_v18120($action['meeting_title']??'Meeting actions',190);$sources[]=['source'=>'video_meeting_action:'.$meetingId,'title'=>$title.' · action history','url'=>url('/meeting.php?meeting='.rawurlencode($publicId))];if(count($sources)>=10)break;
    }
    foreach((array)($memory['meeting_continuity']??[]) as $thread){
        if(!is_array($thread))continue;$linkId=(int)($thread['continuity_link_id']??0);if($linkId<1)continue;
        foreach([['source','source_meeting_id','source_meeting_public_id','source_meeting_title','source_start_at_utc'],['target','target_meeting_id','target_meeting_public_id','target_meeting_title','target_start_at_utc']] as [$side,$idKey,$publicKey,$titleKey,$whenKey]){
            $meetingId=(int)($thread[$idKey]??0);$publicId=trim((string)($thread[$publicKey]??''));if($meetingId<1||$publicId==='')continue;$key='continuity|'.$linkId.'|'.$side;if(isset($seen[$key]))continue;$seen[$key]=true;$title=video_meeting_memory_text_v18120($thread[$titleKey]??'Meeting continuity',190);$when=video_meeting_memory_text_v18120($thread[$whenKey]??'',40);$sources[]=['source'=>'video_meeting_continuity:'.$linkId.':'.$side,'title'=>$title.($when!==''?' · '.$when:'').' · continuity','url'=>url('/meeting.php?meeting='.rawurlencode($publicId))];if(count($sources)>=12)break 2;
        }
    }
    foreach((array)($memory['meeting_closure']??[]) as $closure){
        if(!is_array($closure))continue;$snapshotId=(int)($closure['snapshot_id']??0);$sourceId=(int)($closure['source_meeting_id']??0);$sourcePublic=trim((string)($closure['source_meeting_public_id']??''));if($snapshotId<1||$sourceId<1||$sourcePublic==='')continue;$key='closure|'.$snapshotId.'|source';if(!isset($seen[$key])){$seen[$key]=true;$title=video_meeting_memory_text_v18120($closure['source_meeting_title']??'Meeting closure',190);$sources[]=['source'=>'video_meeting_closure:'.$snapshotId.':source','title'=>$title.' · closure revision '.(int)($closure['revision']??0),'url'=>url('/meeting.php?meeting='.rawurlencode($sourcePublic))];}
        $handoffId=(int)($closure['handoff_id']??0);$targetId=(int)($closure['target_meeting_id']??0);$targetPublic=trim((string)($closure['target_meeting_public_id']??''));if($handoffId>0&&$targetId>0&&$targetPublic!==''&&count($sources)<14){$key='closure|'.$handoffId.'|target';if(!isset($seen[$key])){$seen[$key]=true;$title=video_meeting_memory_text_v18120($closure['target_meeting_title']??'Next meeting',190);$sources[]=['source'=>'video_meeting_closure_handoff:'.$handoffId,'title'=>$title.' · next-meeting handoff','url'=>url('/meeting.php?meeting='.rawurlencode($targetPublic))];}}
        if(count($sources)>=14)break;
    }
    return $sources;
}
