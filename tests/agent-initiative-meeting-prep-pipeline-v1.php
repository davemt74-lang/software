<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-scope-v1.php';
require __DIR__.'/fixtures/meeting-prep-calendar-fixture.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';

// The shared worker fixture already supplies its routing decision. Keep the
// separately protected transcript-processing route local-only in this case.
function vp3_agent_runtime_preference_readonly_v420(PDO $pdo,int $userId,int $agentId): array
{
    return ['effective_preference'=>'homeserver_only'];
}

function meeting_pipeline_evidence(array $user,int $agent,DateTimeImmutable $now): array
{
    $coverage=[];
    return vp3_initiative_evidence_v1(db(),$user,$now,false,[],$coverage,null,$agent);
}

function meeting_pipeline_visible(bool $visible): void
{
    db()->prepare("UPDATE agent_activity_state SET context_key='chat:170',last_heartbeat_at=NOW(),details_json=? WHERE user_id=1")
        ->execute([json_encode(['visible'=>$visible,'conversation_id'=>170],JSON_THROW_ON_ERROR)]);
    db()->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170");
}

function meeting_pipeline_result(int $id): array
{
    $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');
    $q->execute([$id]);$result=(string)$q->fetchColumn();
    $q=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([$id]);
    scope_check($result!==''&&$result===(string)$q->fetchColumn(),'meeting read has the same result on the action and canonical receipt');
    return json_decode($result,true,32,JSON_THROW_ON_ERROR);
}

function meeting_pipeline_prepare(array $user,string $ref,string $label): array
{
    meeting_pipeline_visible(false);
    $before=count(scope_runs(1,17,'initiative.prepare'));
    $GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($ref):string{
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        $records=array_column($input['evidence'],null,'ref');
        scope_check($protocol==='vp3.initiative-plan.v1'&&!db()->inTransaction()
            &&in_array($ref,$input['tools']['calendar.read']['source_refs']??[],true)
            &&($records[$ref]['meeting_prep']['open_count']??0)>0
            &&!str_contains($prompt,'TRANSCRIPT_PREP_PRIVATE_SENTINEL'),
            'normal Initiative planning offers one exact calendar.read meeting source without transcript passages');
        return json_encode(['jobs'=>[['tool'=>'calendar.read','primary'=>$ref,'refs'=>[$ref],
            'focus'=>'Prepare the verified open commitment before the meeting.','report'=>true]]],JSON_THROW_ON_ERROR);
    };
    $plan=scope_make_plan($user,17,$label);
    $planned=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$plan));
    $runs=scope_runs(1,17,'initiative.prepare');
    scope_check(!empty($planned['ok'])&&count($runs)===$before+1,'planner choice queues one canonical meeting preparation ('.($planned['reason']??'unknown').')');
    $id=(int)$runs[count($runs)-1]['id'];
    $messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);
    $GLOBALS['scopeModel']=static fn()=>throw new LogicException('Exact background prep does not send source content to inference.');
    $done=vp3_initiative_execute_v1(db(),$user,scope_claim($user,$id));
    $result=meeting_pipeline_result($id);
    scope_check(!empty($done['ok'])&&($result['initiative_delivery']??null)==='pending'
        &&($result['initiative_tool_result']['tool_receipt']['changes_applied']??null)===false
        &&count(scope_messages())===$messages&&count($GLOBALS['scopeInference'])===$models,
        'the leased worker prepares an exact read without a mutation, further inference, or interruption while the owner is away');
    return [$id,$result];
}

$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$user=['id'=>1,'is_active'=>1];
$fixture=vp3_test_meeting_prep_seed_v1(db(),$now,17);
db()->prepare("UPDATE artist_transcript_segments_v172 SET transcript_text='TRANSCRIPT_PREP_PRIVATE_SENTINEL. Assign an owner for the delivery plan.' WHERE id=?")
    ->execute([$fixture['segment_id']]);
$ref='calendar:event:'.$fixture['calendar_id'];
scope_command($user,17,170,'off');
scope_check(!empty(scope_command($user,17,170,'on')['ok']),'meeting prep uses the existing enabled private Chat binding');
$initial=meeting_pipeline_evidence($user,17,$now);
scope_check(isset($initial[$ref]['meeting_prep'])&&!array_filter($initial,static fn(array $row):bool=>
    $row['kind']==='meeting_prep'&&$row['url']===$initial[$ref]['url']),
    'one rich event source replaces the duplicate carried-forward preview in assessment');

[$stale]=meeting_pipeline_prepare($user,$ref,'Meeting prep first assessment');
db()->prepare("UPDATE video_meeting_continuity_links SET status='resolved_elsewhere' WHERE target_meeting_id=?")->execute([$fixture['meeting_id']]);
meeting_pipeline_visible(true);$messages=count(scope_messages());$models=count($GLOBALS['scopeInference']);
scope_check(!isset(meeting_pipeline_evidence($user,17,$now)[$ref])
    &&!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
    &&count($GLOBALS['scopeInference'])===$models&&meeting_pipeline_result($stale)['initiative_delivery']==='withheld',
    'closing the exact commitment before delivery keeps the background assessment quiet and withdraws the pending result');

db()->prepare("UPDATE video_meeting_continuity_links SET status='carried_forward' WHERE target_meeting_id=?")->execute([$fixture['meeting_id']]);
db()->prepare("UPDATE video_meeting_agenda_items SET item_text='Confirm the revised delivery plan' WHERE meeting_id=? AND source_kind='continuity'")->execute([$fixture['meeting_id']]);
[$revoked]=meeting_pipeline_prepare($user,$ref,'Meeting prep with revised source');
db()->prepare("INSERT INTO user_agent_data_rules VALUES(17,'recording',?,'deny')")->execute([(string)$fixture['transcript_id']]);
meeting_pipeline_visible(true);
scope_check(!vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages
    &&meeting_pipeline_result($revoked)['initiative_delivery']==='withheld',
    'revoking the linked recording stops an already prepared report from exposing its source link');

db()->exec("DELETE FROM user_agent_data_rules WHERE agent_id=17");
db()->prepare("UPDATE artist_transcript_segments_v172 SET transcript_text='TRANSCRIPT_PREP_PRIVATE_SENTINEL. The delivery plan was revised.' WHERE session_id=?")
    ->execute([$fixture['transcript_id']]);
[$valid,$pending]=meeting_pipeline_prepare($user,$ref,'Meeting prep with current authorized source');
$encoded=json_encode($pending,JSON_THROW_ON_ERROR);
scope_check(!str_contains($encoded,'Confirm the revised delivery plan')
    &&!str_contains($encoded,'/artist-listening.php?session='.$fixture['transcript_id'])
    &&!str_contains($encoded,'TRANSCRIPT_PREP_PRIVATE_SENTINEL'),
    'the saved pending result contains the exact source proof without storing commitment text, transcript passages, or links');
meeting_pipeline_visible(true);$models=count($GLOBALS['scopeInference']);
scope_check(vp3_initiative_deliver_pending_v1(db(),$user,17)&&count(scope_messages())===$messages+1
    &&count($GLOBALS['scopeInference'])===$models,
    'returning to the bound Chat delivers one current preparation without another model call');
$delivered=meeting_pipeline_result($valid);
$q=db()->prepare('SELECT conversation_id,message,context_json FROM chat_messages WHERE id=?');
$q->execute([$delivered['initiative_message_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
$context=json_decode($row['context_json'],true,32,JSON_THROW_ON_ERROR);
scope_check((int)$row['conversation_id']===170&&($context['initiative_meeting_prep']??null)===$initial[$ref]['meeting_prep']['key']
    &&$row['message']===VP3_INITIATIVE_MEETING_PREP_MARKER_V1&&$context['sources']===[]
    &&($context['tool_receipt']['changes_applied']??null)===false
    &&!vp3_initiative_deliver_pending_v1(db(),$user,17),
    'the exact Chat stores one content-free occurrence receipt and repeat delivery is idempotent');
$messageId=(int)$delivered['initiative_message_id'];
$projection=vp3_initiative_meeting_prep_projection_v1(db(),$user,17,170,$messageId);
scope_check(str_contains($projection['answer']??'','Confirm the revised delivery plan')
    &&in_array('/artist-listening.php?session='.$fixture['transcript_id'],array_column($projection['sources'],'url'),true)
    &&!str_contains(json_encode($projection,JSON_THROW_ON_ERROR),'TRANSCRIPT_PREP_PRIVATE_SENTINEL'),
    'authorized Chat reload projects the exact current commitment and transcript link from the source records');
require_once dirname(__DIR__).'/includes/agent-meeting-action-sources-v1.php';
$meetingSource=vp3_meeting_action_source_v1(db(),$user,17,170);
scope_check($meetingSource['status']==='selected'&&$meetingSource['source']['origin_kind']==='initiative_meeting_prep'
    &&$meetingSource['source']['origin_message_id']===$messageId&&$meetingSource['source']['meeting_id']===$fixture['meeting_id'],
    'meeting actions inherit the exact delivered proactive source through its completed canonical receipt');
$priorChatSource=vp3_meeting_action_source_v1(db(),$user,17,171);
scope_check($priorChatSource['status']==='selected'&&$priorChatSource['source']['origin_conversation_id']===170
    &&$priorChatSource['source']['conversation_id']===171&&$priorChatSource['source']['origin_message_id']===$messageId,
    'an exact proactive meeting source can follow the same owner and Agent into another private Chat');
scope_check(vp3_initiative_meeting_prep_projection_v1(db(),$user,17,171,$messageId)===null
    &&vp3_initiative_meeting_prep_projection_v1(db(),$user,18,180,$messageId)===null,
    'another Chat or Agent cannot hydrate a copied meeting report marker');
$feed=vp3_top_of_mind_v1(db(),$user,17,170);
scope_check(count(array_filter($feed['items'],static fn(array $item):bool=>
    $item['run_id']===$valid&&$item['state']==='completed'))===1,
    'the same completed preparation appears in the existing Brain projection');
scope_command($user,17,170,'off');
scope_check(vp3_meeting_action_source_current_v1(db(),$user,17,170,$meetingSource['source'])['source']===$meetingSource['source'],
    'turning Initiative off does not revoke the owner direct-action handoff from a completed report');
db()->prepare("INSERT INTO user_agent_data_rules VALUES(17,'recording',?,'deny')")->execute([(string)$fixture['transcript_id']]);
scope_check(vp3_initiative_meeting_prep_projection_v1(db(),$user,17,170,$messageId)===null,
    'revoking a linked transcript after delivery withdraws its content and links on Chat reload');
scope_check(vp3_meeting_action_source_v1(db(),$user,17,170)['status']==='unavailable',
    'a revoked proactive source cannot authorize a later meeting finding mutation');
db()->exec("DELETE FROM user_agent_data_rules WHERE agent_id=17");
db()->prepare("UPDATE video_meetings SET status='live' WHERE id=?")->execute([$fixture['meeting_id']]);
scope_check(vp3_initiative_meeting_prep_projection_v1(db(),$user,17,170,$messageId)===null,
    'a started meeting cannot rehydrate old prep as a current result');
db()->prepare("UPDATE video_meetings SET status='scheduled' WHERE id=?")->execute([$fixture['meeting_id']]);
db()->prepare("UPDATE video_meeting_agenda_items SET item_text='Confirm the final delivery plan' WHERE meeting_id=? AND source_kind='continuity'")->execute([$fixture['meeting_id']]);
scope_check(!isset(meeting_pipeline_evidence($user,17,$now)[$ref])
    &&vp3_initiative_meeting_prep_projection_v1(db(),$user,17,170,$messageId)===null,
    'editing a commitment after delivery cannot re-offer an already reported occurrence');
scope_command($user,17,171,'on');
scope_check(!isset(meeting_pipeline_evidence($user,17,$now)[$ref]),
    'moving the owner Initiative binding to another Chat cannot repost the same occurrence');

$future=$initial[$ref]['meeting_prep'];
$future['key']=str_repeat('9',64);
scope_check(!vp3_initiative_meeting_prep_eligible_v1(db(),1,17,$future,$now->modify('+21 minutes'))
    &&!vp3_initiative_meeting_prep_eligible_v1(db(),1,17,$future,$now->modify('-11 minutes'))
    &&vp3_initiative_meeting_prep_eligible_v1(db(),1,17,$future,$now),
    'the delivery gate admits only the thirty-minute preparation window before the exact occurrence');
echo "AGENT_INITIATIVE_MEETING_PREP_PIPELINE_V1=PASS\n";
