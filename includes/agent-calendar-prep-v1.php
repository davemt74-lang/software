<?php
declare(strict_types=1);

require_once __DIR__.'/agent-tool-receipts-v1.php';

function vp3_calendar_prep_text_v1(mixed $value, int $limit): string
{
    $text = is_string($value) ? trim(preg_replace('/[\s\x00-\x1f\x7f]+/u', ' ', $value) ?? '') : '';
    return mb_strimwidth($text, 0, $limit, '…');
}

/** The Calendar only supplies evidence for an exact owner-linked meeting. */
function vp3_calendar_prep_details_v1(PDO $pdo, array $user, array $principal, array $event, ?array $verifiedLineage = null): array
{
    $owner = (int)$user['id'];
    $id = (int)$event['id'];
    $booking = ($event['kind'] ?? 'event') === 'booking';
    $reviewUrl = $booking ? url('/scheduling.php?schedule='.(int)$event['schedule_id'].'&tab=bookings') : url('/calendar-event.php?id='.$id);
    $location = trim((string)($event['location'] ?? ''));
    $locationUrl = filter_var($location, FILTER_VALIDATE_URL) && str_starts_with($location, 'https://')
        && parse_url($location, PHP_URL_USER) === null && parse_url($location, PHP_URL_PASS) === null
        ? $location : '';
    $result = ['review_url'=>$reviewUrl, 'meeting_url'=>$locationUrl, 'agenda_status'=>'unknown',
        'open_items'=>[], 'completed_items'=>[], 'unknown_items'=>[], 'items_truncated'=>false];
    if ($verifiedLineage !== null) $result['actionable_items'] = [];

    if (!table_exists('video_meetings') || ($event['recurrence'] ?? 'none') === 'weekly') return $result;
    require_once __DIR__.'/video-meetings-v1800.php';
    $meeting = $booking ? video_meeting_for_booking_v1800($pdo, $id) : video_meeting_for_calendar_event_v1800($pdo, $id);
    if (!$meeting || (int)$meeting['owner_user_id'] !== $owner || !in_array($meeting['status'], ['scheduled','ready','live'], true)
        || (string)$meeting['start_at_utc'] !== (string)$event['start_at_utc']
        || !preg_match('/\A[a-f0-9]{32}\z/', (string)$meeting['public_id'])) return $result;

    $result['meeting_url'] = $result['review_url'] = url('/meeting.php?meeting='.$meeting['public_id']);
    require_once __DIR__.'/video-meetings-agenda-v18140.php';
    if (video_meeting_agenda_schema_ready_v18140($pdo)) {
        $agenda = video_meeting_agenda_items_v18140($pdo, $meeting, $owner);
        $result['agenda_status'] = $agenda ? 'available' : 'empty';
        $actions = [];
        $actionsReady = false;
        if ($agenda && table_exists('video_meeting_action_executions')) {
            require_once __DIR__.'/video-meetings-actions-v18150-part1.php';
            $actionsReady = video_meeting_action_schema_ready_v18150($pdo);
            if ($actionsReady) {
                $deliveryFields = column_exists('video_meeting_action_executions','result_json') && column_exists('video_meeting_action_executions','executed_at')
                    ? ',result_json,executed_at' : '';
                $stmt = $pdo->prepare('SELECT agenda_item_id,action_kind,status,workflow_run_id'.$deliveryFields.' FROM video_meeting_action_executions
                    WHERE meeting_id=? AND owner_user_id=? ORDER BY id LIMIT '.VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140);
                $stmt->execute([(int)$meeting['id'],$owner]);
                $actions = array_column($stmt->fetchAll(PDO::FETCH_ASSOC),null,'agenda_item_id');
            }
        }
        foreach ($agenda as $item) {
            $text = vp3_calendar_prep_text_v1($item['item_text'] ?? '', 200);
            if ($text === '' || ($item['status'] ?? '') === 'skipped') continue;
            $continuity = ($item['source_kind'] ?? '') === 'continuity';
            $lineage = $verifiedLineage[(int)$item['id']] ?? null;
            if ($verifiedLineage !== null && $continuity && (!$lineage || ($lineage['thread_kind'] ?? '') === 'verified')) continue;
            $status = (string)($item['status'] ?? '');
            $kind = (string)($item['action_kind'] ?? '');
            if ($kind === '') {
                if ($continuity && $lineage && empty($lineage['thread_kind'])) {
                    $result['open_items'][] = $text;
                    $result['actionable_items'][] = $text;
                } elseif ($status === 'open') {
                    $result['open_items'][] = 'Agenda · '.$text;
                }
                continue;
            }
            $execution = $actions[(int)$item['id']] ?? null;
            if (!$actionsReady || ($execution && $execution['action_kind'] !== $kind)) {
                $result['unknown_items'][] = $text;
                continue;
            }
            $executionStatus = $execution['status'] ?? '';
            if ($executionStatus === 'executed' && $kind === 'task' && function_exists('agent_workflow_row_v1400')) {
                $run = agent_workflow_row_v1400($pdo, $owner, (int)($execution['workflow_run_id'] ?? 0));
                if (($run['status'] ?? '') === 'completed') $executionStatus = 'completed';
            }
            if ($executionStatus === 'executed' && $kind === 'email' && !empty($execution['executed_at'])) {
                $delivery = json_decode((string)($execution['result_json'] ?? ''),true);
                if (($delivery['delivery'] ?? '') === 'accepted_by_mail_transport') {
                    $result['completed_items'][] = $text.' (mail transport accepted)';
                    continue;
                }
            }
            $bucket = match ($executionStatus) {
                'completed' => 'completed_items',
                'needs_review', 'approved', 'executing' => 'open_items',
                '' => in_array($status, ['open','follow_up'], true) ? 'open_items' : 'unknown_items',
                default => 'unknown_items',
            };
            $result[$bucket][] = $text;
            if ($verifiedLineage !== null && $bucket === 'open_items'
                && ($executionStatus === 'needs_review'
                    || ($lineage && empty($lineage['thread_kind']) && in_array($status,['open','follow_up'],true)))) {
                $result['actionable_items'][] = $text;
            }
        }
        $result['items_truncated'] = count($agenda) >= VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140;
    }

    try {
        vp3_calendar_prep_linked_tasks_v1($pdo, $user, $principal, (int)$meeting['id'], $result);
    } catch (PDOException $error) {
        $result['items_truncated'] = true;
    }
    foreach (array_keys(array_intersect_key($result,array_flip(['open_items','completed_items','unknown_items','actionable_items']))) as $key) {
        $items = array_values(array_unique($result[$key]));
        $result['items_truncated'] = $result['items_truncated'] || count($items) > 4;
        $result[$key] = array_slice($items, 0, 4);
    }
    return $result;
}

/** Read only explicit Meeting Intelligence task references and their saved status. */
function vp3_calendar_prep_linked_tasks_v1(PDO $pdo, array $user, array $principal, int $meetingId, array &$result): void
{
    if (!function_exists('personal_capability_has_v242') || !personal_capability_has_v242('agent_brain.access',$user)
        || !table_exists('agent_memory_items') || !column_exists('agent_memory_items','metadata_json')
        || !column_exists('agent_memory_items','user_agent_id')) return;
    require_once __DIR__.'/agent-task-lists-v1.php';
    $agentId = vp3_task_list_agent_v1($principal);
    if ($agentId > 0 && !function_exists('user_data_policy_can_use_v236')) return;
    $scope = $agentId > 0 ? 'user_agent_id=?' : 'user_agent_id IS NULL';
    $params = [(int)$user['id'], (string)$meetingId];
    if ($agentId > 0) $params[] = $agentId;
    $stmt = $pdo->prepare("SELECT id,subject,metadata_json FROM agent_memory_items WHERE user_id=? AND is_active=1
        AND memory_type IN ('task','commitment') AND CAST(CASE WHEN JSON_VALID(metadata_json)
        THEN JSON_EXTRACT(metadata_json,'$.video_meeting_id') END AS CHAR)=? AND {$scope} ORDER BY id LIMIT 21");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result['items_truncated'] = $result['items_truncated'] || count($rows) > 20;
    foreach (array_slice($rows, 0, 20) as $task) {
        $metadata = json_decode((string)$task['metadata_json'], true);
        if (($metadata['source_kind'] ?? '') !== 'video_meeting_intelligence' || ($metadata['review_state'] ?? '') !== 'approved'
            || (int)($metadata['video_meeting_id'] ?? 0) !== $meetingId
            || !in_array((int)($metadata['assigned_user_id'] ?? 0), [0,(int)$user['id']], true)
            || !vp3_task_list_task_allowed_v1($pdo,$user,$principal,(int)$task['id'])) continue;
        $title = vp3_calendar_prep_text_v1($task['subject'], 200);
        if ($title === '') continue;
        $bucket = match ($metadata['task_status'] ?? '') {
            'completed' => 'completed_items',
            'open', 'in_progress', 'waiting' => 'open_items',
            'cancelled' => null,
            default => 'unknown_items',
        };
        if ($bucket !== null) $result[$bucket][] = $title;
        if ($bucket === 'open_items' && isset($result['actionable_items'])) $result['actionable_items'][] = $title;
    }
}

/** Quote the reviewed recording linked to the exact previous meeting. */
function vp3_calendar_prep_prior_transcript_v1(PDO $pdo,array $scope,array $lineage): array
{
    if (!$lineage) return ['status'=>'not_linked'];
    foreach (['video_meeting_transcription_links','artist_transcript_sessions_v172','artist_transcript_segments_v172'] as $table) {
        if (!table_exists($table)) return ['status'=>'unavailable'];
    }
    require_once __DIR__.'/knowledge-retrieval-v162.php';
    require_once __DIR__.'/transcript-agent-v1.php';
    require_once __DIR__.'/video-meetings-homeserver-v1801.php';
    $sourceId=(int)$lineage['source_meeting_id'];
    $q=$pdo->prepare('SELECT transcript_session_id FROM video_meeting_transcription_links WHERE meeting_id=?');
    $q->execute([$sourceId]);$id=(int)$q->fetchColumn();
    if (!$id) return ['status'=>'not_recorded'];
    $user=$scope['user'];$principal=$scope['principal'];
    if ($scope['agent_id']>0 && !function_exists('user_data_policy_can_use_v236')) return ['status'=>'unavailable'];
    try {
        $session=vp3_transcript_session_v1($pdo,$user,$principal,$id);
        $binding=video_meeting_transcription_readonly_binding_v1801($pdo,$user,$session);
        if ((int)($binding['id']??0)!==$sourceId || ($binding['public_id']??'')!==$lineage['source_public_id']
            || (int)($binding['organizer_agent_id']??0)!==$scope['agent_id']) return ['status'=>'unavailable'];
    } catch (RuntimeException $error) {
        return ['status'=>in_array($error->getCode(),[403,404],true)?'restricted':'unavailable'];
    }

    // Scan a bounded prefix of the canonical reviewed text. A deleted segment
    // or a user note never substitutes for recorded speech.
    $q=$pdo->prepare("SELECT id,started_ms,speaker_label,transcript_text FROM artist_transcript_segments_v172
        WHERE session_id=? AND segment_type='transcript' AND TRIM(transcript_text)<>''
        ORDER BY segment_index,id LIMIT 121");
    $q->execute([$id]);$segments=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$segments) return ['status'=>'empty'];
    $prefixLimited=count($segments)>120;
    $segments=array_slice($segments,0,120);
    $terms=knowledge_retrieval_v162_terms((string)$lineage['source_text'].' '.(string)$lineage['item_text']);
    foreach ($segments as $position=>&$segment) {
        $segment['score']=0;$segment['position']=$position;
        foreach ($terms as $term) if (mb_stripos((string)$segment['transcript_text'],$term)!==false) $segment['score']++;
    }
    unset($segment);
    usort($segments,static fn(array $a,array $b): int=>($b['score']<=>$a['score'])?:($a['position']<=>$b['position']));
    $passages=[];$excerpted=false;
    foreach (array_slice($segments,0,3) as $segment) {
        $raw=trim((string)$segment['transcript_text']);
        $text=mb_strcut($raw,0,360,'UTF-8');
        $excerpted=$excerpted || $text!==$raw;
        $passages[]=['segment_id'=>(int)$segment['id'],'started_ms'=>max(0,(int)$segment['started_ms']),
            'at'=>artist_listening_v172_time(max(0,(int)$segment['started_ms'])),
            'speaker'=>vp3_calendar_prep_text_v1($segment['speaker_label']??'',70),'text'=>$text,'excerpted'=>$text!==$raw];
    }
    usort($passages,static fn(array $a,array $b): int=>($a['started_ms']<=>$b['started_ms'])?:($a['segment_id']<=>$b['segment_id']));
    $partial=$prefixLimited||count($segments)>count($passages)||$excerpted;
    $revision=hash('sha256',json_encode([$sourceId,$id,$lineage['lineage_hash'],$partial,$segments],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    return ['status'=>'available','session_id'=>$id,'meeting_id'=>$sourceId,
        'title'=>vp3_calendar_prep_text_v1($lineage['source_title'],190),
        'url'=>url('/artist-listening.php?session='.$id),
        'meeting_url'=>url('/meeting.php?meeting='.$lineage['source_public_id']),
        'passages'=>$passages,'coverage'=>$partial?'partial':'complete','segments_checked'=>count($segments),'revision'=>$revision];
}

/** Exact native Calendar-to-meeting read. Transcript passages are private display evidence. */
function vp3_calendar_prep_meeting_evidence_v1(PDO $pdo,array $user,array $principal,array $event,int $conversationId,
    bool $includeTranscript=true,?DateTimeImmutable $now=null): array
{
    $empty=['meeting_id'=>0,'actionable_items'=>[],'prior_transcript'=>['status'=>'not_linked']];
    if (!table_exists('video_meetings')||($event['kind']??'event')!=='event'
        || ($event['recurrence']??'none')==='weekly'||!empty($event['all_day'])) return $empty;
    require_once __DIR__.'/video-meetings-v1800.php';
    require_once __DIR__.'/video-meetings-homeserver-v1801.php';
    require_once __DIR__.'/agent-catch-up-meeting-prep-v1.php';
    $aid=(int)($principal['agent_id']??0);$owner=(int)$user['id'];
    $scope=video_meeting_processing_scope_readonly_v1801($pdo,$user,[
        'user'=>$user,'principal'=>$principal,'agent_id'=>$aid,'conversation_id'=>$conversationId,
    ]);
    $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $calendar=user_calendar_event_v1300($pdo,$owner,(int)$event['id']);
    if (!$calendar||($calendar['status']??'')!=='active'||!empty($calendar['all_day'])
        || ($calendar['recurrence']??'none')==='weekly'
        || (string)$calendar['start_at_utc']!==(string)$event['start_at_utc']
        || (string)$calendar['end_at_utc']!==(string)$event['end_at_utc']) return $empty;
    $meeting=video_meeting_for_calendar_event_v1800($pdo,(int)$calendar['id']);
    if (!$meeting||(int)$meeting['owner_user_id']!==$owner||(int)($meeting['organizer_agent_id']??0)!==$aid
        || !in_array($meeting['status'],['scheduled','ready'],true)
        || $meeting['start_at_utc']!==$calendar['start_at_utc']||$meeting['end_at_utc']!==$calendar['end_at_utc']
        || $meeting['start_at_utc']<=$now->format('Y-m-d H:i:s')
        || !preg_match('/\A[a-f0-9]{32}\z/D',(string)$meeting['public_id'])) return $empty;
    $meetingId=(int)$meeting['id'];
    $q=$pdo->prepare("SELECT 1 FROM video_meeting_participants WHERE meeting_id=? AND user_id=?
        AND `role`='organizer' AND invitation_status NOT IN ('cancelled','revoked','declined') LIMIT 1");
    $q->execute([$meetingId,$owner]);
    if (!$q->fetchColumn()) return $empty;

    $rows=vp3_meeting_prep_lineage_rows_v1($pdo,$owner,$aid,$now,$meetingId);
    $lineage=[];$prior=[];
    foreach (array_slice($rows??[],0,8) as $row) {
        if (!vp3_meeting_prep_lineage_valid_v1($owner,$row)) continue;
        $signal=video_meeting_continuity_thread_signal_v18210($pdo,$owner,(int)$row['source_agenda_id'],$meetingId);
        $row['thread_kind']=$signal['kind']??'';
        $lineage[(int)$row['target_agenda_id']]=$row;
        if ($row['thread_kind']!=='verified'
            && (!$prior||[$row['source_end_at_utc'],(int)$row['source_meeting_id']]>[$prior['source_end_at_utc'],(int)$prior['source_meeting_id']])) $prior=$row;
    }
    $details=vp3_calendar_prep_details_v1($pdo,$scope['user'],$scope['principal'],$calendar,$lineage);
    if ($details['review_url']!==url('/meeting.php?meeting='.$meeting['public_id'])) return $empty;
    $details['items_truncated']=$details['items_truncated']||$rows===null||count($rows)>8;
    $transcript=match(true){
        !$includeTranscript=>['status'=>'not_read'],
        $rows===null=>['status'=>'unavailable'],
        default=>vp3_calendar_prep_prior_transcript_v1($pdo,$scope,$prior),
    };
    return ['meeting_id'=>$meetingId,'prior_transcript'=>$transcript]+$details;
}

/** The earliest native meeting linked to an exact active calendar event, across days. */
function vp3_calendar_prep_next_v1(PDO $pdo,array $user,int $agentId,int $conversationId,?DateTimeImmutable $now=null,?callable $authority=null): ?array
{
    [$user,$principal]=vp3_calendar_prep_scope_v1($pdo,$user,$agentId,$conversationId,$authority);
    require_once __DIR__.'/video-meetings-v1800.php';
    if (!video_meeting_schema_ready_v1800($pdo)) throw new RuntimeException('Meeting storage is unavailable.',503);
    $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $agent=$agentId>0?'m.organizer_agent_id=?':'m.organizer_agent_id IS NULL';
    $oneTime=user_calendar_recurrence_schema_ready_v1340($pdo)?"AND c.recurrence<>'weekly'":'';
    $q=$pdo->prepare("SELECT c.id FROM video_meetings m
        JOIN user_calendar_events c ON c.id=m.calendar_event_id AND c.owner_user_id=m.owner_user_id
        WHERE m.owner_user_id=? AND m.status IN ('scheduled','ready') AND m.start_at_utc>? AND m.start_at_utc<?
        AND c.status='active' AND c.all_day=0 AND c.start_at_utc=m.start_at_utc AND c.end_at_utc=m.end_at_utc
        AND {$agent} {$oneTime}
        AND EXISTS (SELECT 1 FROM video_meeting_participants p WHERE p.meeting_id=m.id AND p.user_id=m.owner_user_id
            AND p.`role`='organizer' AND p.invitation_status NOT IN ('cancelled','revoked','declined'))
        ORDER BY m.start_at_utc,m.id LIMIT 1");
    $args=[(int)$user['id'],$now->format('Y-m-d H:i:s'),$now->modify('+30 days')->format('Y-m-d H:i:s')];
    if ($agentId>0) $args[]=$agentId;
    $q->execute($args);$id=(int)$q->fetchColumn();
    [$user,$principal]=vp3_calendar_prep_scope_v1($pdo,$user,$agentId,$conversationId,$authority);
    $event=$id>0?user_calendar_event_v1300($pdo,(int)$user['id'],$id):null;
    return $event?vp3_calendar_prep_next_event_v1($pdo,$user,$principal,$event,$conversationId,$now):null;
}

function vp3_calendar_prep_next_event_v1(PDO $pdo,array $user,array $principal,array $event,int $conversationId,DateTimeImmutable $now): ?array
{
    $details=vp3_calendar_prep_meeting_evidence_v1($pdo,$user,$principal,$event,$conversationId,now:$now);
    if (!$details['meeting_id']) return null;
    $start=user_calendar_validate_utc_v1300($event['start_at_utc']);
    $zone=new DateTimeZone(user_calendar_default_timezone_v1300($pdo,$user));
    $event=['id'=>(int)$event['id'],'kind'=>'event','occurrence_id'=>'event:'.(int)$event['id'],
        'title'=>vp3_calendar_prep_text_v1($event['title'],190),'starts_at'=>$start->format(DATE_ATOM),
        'starts_label'=>$start->setTimezone($zone)->format('D, M j · g:i A T'),'all_day'=>false]+$details;
    $event['revision']=hash('sha256',json_encode($event,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    return $event;
}

function vp3_calendar_prep_scope_v1(PDO $pdo,array $user,int $agentId,int $conversationId,?callable $authority): array
{
    if ($agentId<0||$conversationId<1||(int)($user['id']??0)<1
        || (function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)) {
        throw new RuntimeException('Calendar prep is available in private Agent Chat.',403);
    }
    require_once __DIR__.'/agent-chat-boundary-v380.php';
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $user=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],$authority);
    if (!has_permission('account.access',$user)) throw new RuntimeException('Calendar access changed.',403);
    if (!function_exists('user_calendar_events_v1300')||!user_calendar_schema_ready_v1300($pdo)) {
        throw new RuntimeException('Calendar storage is unavailable.',503);
    }
    return [$user,$principal];
}

/** Upcoming Cloud Calendar occurrences in the owner's current calendar day. */
function vp3_calendar_prep_today_v1(PDO $pdo, array $user, int $agentId, int $conversationId, ?DateTimeImmutable $now = null, ?callable $authority = null): array
{
    [$user,$principal]=vp3_calendar_prep_scope_v1($pdo,$user,$agentId,$conversationId,$authority);

    $zone = new DateTimeZone(user_calendar_default_timezone_v1300($pdo,$user));
    $utc = new DateTimeZone('UTC');
    $now = ($now ?? new DateTimeImmutable('now',$utc))->setTimezone($utc);
    $end = $now->setTimezone($zone)->setTime(0,0)->modify('+1 day')->setTimezone($utc);
    $events = user_calendar_events_v1300($pdo,$user,$now->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),false);
    usort($events,static fn(array $a,array $b): int => (int)!empty($a['all_day']) <=> (int)!empty($b['all_day']));
    $rows = [];
    foreach ($events as $event) {
        $start = user_calendar_validate_utc_v1300($event['start_at_utc']);
        if ($start < $now && empty($event['all_day'])) continue;
        $details = vp3_calendar_prep_details_v1($pdo,$user,$principal,$event);
        $rows[] = ['id'=>$event['id'], 'kind'=>$event['kind'],
            'occurrence_id'=>$event['occurrence_id'] ?? 'booking:'.$event['id'],
            'title'=>vp3_calendar_prep_text_v1($event['title'],190), 'starts_at'=>$start->format(DATE_ATOM),
            'all_day'=>!empty($event['all_day']),
            'starts_label'=>!empty($event['all_day']) ? 'All day' : $start->setTimezone($zone)->format('g:i A T'),
        ] + $details;
        if (count($rows) === 3) break;
    }
    $live = vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],$authority);
    if (!has_permission('account.access',$live)) throw new RuntimeException('Calendar access changed.',403);
    return $rows;
}

function vp3_calendar_prep_next_intent_v1(string $query): bool
{
    $text=mb_strtolower(trim(preg_replace('/\s+/u',' ',str_replace('’',"'",$query))??''));
    $text=preg_replace('/\A(?:(?:please|(?:can|could|would) you) )+/u','',$text)??'';
    $meeting='(?:(?:my|the) )?next (?:video )?meeting';
    $topic='(?:prep(?:aration)?|agenda|checklist|(?:open )?commitments?|(?:open )?follow[- ]ups?)';
    $action="(?:(?:help me )?(?:prep(?:are)?|get ready)|prepare me|brief me)";
    $question="(?:what (?:should i|do i need to) (?:do(?: to prep(?:are)?)?|prep(?:are)?)|how (?:should|do|can) i (?:prep(?:are)?|get ready)|what(?:'s| is) (?:on (?:the|my) agenda|left to do)|what (?:agenda items|commitments?|follow[- ]ups?) are (?:open|left|outstanding))";
    $read="(?:show(?: me)?|review|check|pull up) (?:(?:the|my) )?";
    return (bool)preg_match("/\A(?:(?:{$action}|{$question}|{$read}{$topic}|{$topic}) (?:for|before) {$meeting}|(?:{$read})?{$meeting} {$topic})(?: please)?[?.!]*\z/u",$text);
}

function vp3_calendar_prep_chat_intent_v1(string $query): bool
{
    if (vp3_calendar_prep_next_intent_v1($query)) return true;
    $text = mb_strtolower(trim(str_replace('’', "'", $query)));
    if (preg_match('/\b(?:tomorrow|yesterday|next week|next month)\b/u',$text)) return false;
    return preg_match('/^(?:please\s+|(?:can|could|would) you\s+)?(?:what|which|how|have i|am i|do i|show(?: me)?|check|review|help me|calendar prep)\b/u',$text)
        && preg_match('/\b(?:today|today\'s|calendar prep)\b/u',$text)
        && preg_match('/\b(?:calendar|event|meeting|call|appointment)\b/u',$text)
        && preg_match('/\b(?:prep(?:are|aration)?|checklist|agenda|ready|readiness|left|done|completed|remaining|need to do)\b/u',$text);
}

function vp3_calendar_prep_next_result_v1(?array $event): array
{
    $result=['handled'=>true,'answer'=>'I found no upcoming VP3 video meeting linked to your saved Calendar in the next 30 days.',
        'actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('calendar.read','list','no_matches')];
    if (!$event) return $result;
    $lines=['Meeting prep · '.$event['title'],$event['starts_label']];
    if ($event['agenda_status']==='unknown') $lines[]='I couldn’t verify the linked agenda.';
    if ($event['agenda_status']==='empty') $lines[]='The linked meeting agenda has no saved items.';
    foreach (['open_items'=>'Open agenda and follow-ups','completed_items'=>'Recorded complete','unknown_items'=>'Completion unverified'] as $key=>$label) {
        if ($event[$key]) $lines[]=$label."\n- ".implode("\n- ",$event[$key]);
    }
    if ($event['items_truncated']) $lines[]='Open the meeting to review more agenda or follow-up detail.';
    $transcript=$event['prior_transcript'];
    if ($transcript['status']==='available') {
        $lines[]='Prior transcript · '.$transcript['title'].' ('.($transcript['coverage']==='partial'?'selected recorded excerpts':'recorded speech').')';
        foreach ($transcript['passages'] as $passage) {
            $speaker=$passage['speaker']!==''?' · '.$passage['speaker']:'';
            $lines[]=$passage['at'].$speaker.' · segment #'.$passage['segment_id'].' — “'.$passage['text'].($passage['excerpted']?'…':'').'”';
        }
        $result['sources'][]=['source'=>'transcript:'.$transcript['session_id'],'title'=>'Prior transcript · '.$transcript['title'],'url'=>$transcript['url']];
        $result['actions'][]=['type'=>'open_url','label'=>'Open prior transcript','url'=>$transcript['url']];
        $result['transcript_context']=vp3_transcript_attachment_v1((int)$transcript['session_id']);
    } else {
        $lines[]=match($transcript['status']){
            'not_linked'=>'I found no verified prior meeting linked by a saved follow-up.',
            'not_recorded'=>'The linked prior meeting has no saved transcript.',
            'empty'=>'The linked prior transcript has no saved speech.',
            default=>'I couldn’t verify access to the linked prior transcript.',
        };
    }
    $result['answer']=implode("\n",$lines);
    array_unshift($result['sources'],['source'=>'calendar:'.$event['occurrence_id'],'title'=>$event['title'],'url'=>$event['review_url']]);
    array_unshift($result['actions'],['type'=>'open_url','label'=>'Review meeting','url'=>$event['review_url']]);
    $result['tool_receipt']=vp3_tool_receipt_v1('calendar.read','list','read_complete','',0,1);
    $result['meeting_prep']=array_intersect_key($event,array_flip(['kind','id','occurrence_id','revision']));
    return $result;
}

/** A stored marker is a pointer. Replays cannot switch meetings or return revoked text. */
function vp3_calendar_prep_replay_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $marker,?callable $authority=null,
    ?DateTimeImmutable $now=null): array
{
    $unavailable=['handled'=>true,'answer'=>'That saved meeting prep is no longer current. Ask for meeting prep again.',
        'actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('calendar.read','list','failed','check_failed')];
    $id=$marker['id']??null;$revision=$marker['revision']??null;
    if (!is_int($id)||$id<1||($marker['kind']??null)!=='event'||($marker['occurrence_id']??null)!=='event:'.$id
        || !is_string($revision)||!preg_match('/\A[a-f0-9]{64}\z/D',$revision)) return $unavailable;
    try {
        [$user,$principal]=vp3_calendar_prep_scope_v1($pdo,$user,$agentId,$conversationId,$authority);
        $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        [$user,$principal]=vp3_calendar_prep_scope_v1($pdo,$user,$agentId,$conversationId,$authority);
        $event=user_calendar_event_v1300($pdo,(int)$user['id'],$id);
        if (!$event) return $unavailable;
        $current=vp3_calendar_prep_next_event_v1($pdo,$user,$principal,$event,$conversationId,$now);
        if (!$current||!hash_equals($revision,$current['revision'])) return $unavailable;
        return vp3_calendar_prep_next_result_v1($current);
    } catch (Throwable $error) {
        return $unavailable;
    }
}

function vp3_calendar_prep_chat_v1(PDO $pdo, array $user, int $agentId, int $conversationId, ?callable $authority = null,
    string $query=''): array
{
    $result = ['handled'=>true, 'answer'=>'', 'actions'=>[], 'sources'=>[]];
    try {
        if (vp3_calendar_prep_next_intent_v1($query)) {
            return vp3_calendar_prep_next_result_v1(vp3_calendar_prep_next_v1($pdo,$user,$agentId,$conversationId,null,$authority));
        }
        $events = vp3_calendar_prep_today_v1($pdo,$user,$agentId,$conversationId,null,$authority);
    } catch (Throwable $error) {
        $blocked = $error->getCode() === 403;
        $result['answer'] = $blocked ? 'Open your private Agent Chat to review calendar prep.' : 'I couldn’t verify calendar prep right now.';
        $result['tool_receipt'] = vp3_tool_receipt_v1('calendar.read','list',$blocked?'blocked':'failed',$blocked?'permission_denied':'check_failed');
        return $result;
    }
    $lines = [];
    foreach ($events as $event) {
        $lines[] = $event['starts_label'].' — '.$event['title'];
        if ($event['meeting_url'] !== '') $lines[] = 'A meeting link is saved.';
        if ($event['agenda_status'] === 'unknown') $lines[] = 'I couldn’t verify a linked agenda.';
        if ($event['agenda_status'] === 'empty') $lines[] = 'The linked meeting agenda has no saved items.';
        foreach (['open_items'=>'Open', 'completed_items'=>'Completed', 'unknown_items'=>'Completion unverified'] as $key=>$label) {
            if ($event[$key]) $lines[] = $label."\n- ".implode("\n- ",$event[$key]);
        }
        if (!$event['completed_items'] && !$event['unknown_items']) $lines[] = 'I couldn’t verify any completed prep from the linked records.';
        if ($event['items_truncated']) $lines[] = 'Open the source to review more items.';
        $result['sources'][] = ['source'=>'calendar:'.$event['occurrence_id'],'title'=>$event['title'],'url'=>$event['review_url']];
        $result['actions'][] = ['type'=>'open_url','label'=>'Review '.$event['title'],'url'=>$event['review_url']];
    }
    $result['answer'] = $lines ? implode("\n",$lines) : 'I found no upcoming events in your saved VP3 calendar for today.';
    $result['tool_receipt'] = vp3_tool_receipt_v1('calendar.read','list',$events?'read_complete':'no_matches','',0,count($events));
    return $result;
}
