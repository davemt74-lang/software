<?php
declare(strict_types=1);

require_once __DIR__.'/agent-meeting-research-v1.php';
require_once __DIR__.'/agent-initiative-research-status-v1.php';

/** Read the latest exact research relationship on each existing agenda item. */
function vp3_meeting_research_agenda_view_v1(PDO $pdo,array $user,array $meeting,array $items): array
{
    $owner=(int)($user['id']??0);
    $agent=(int)($meeting['organizer_agent_id']??0);
    $meetingId=(int)($meeting['id']??0);
    if ($owner<1 || empty($user['is_active']) || (int)($meeting['owner_user_id']??0)!==$owner || $meetingId<1
        || !has_permission('account.access',$user) || !has_permission('chat.access',$user)
        || vp3_participant_scope_v1()!==null) return [];
    $open=[];
    foreach ($items as $item) {
        if (in_array($item['status']??null,['open','follow_up'],true)) $open[(int)$item['id']]=true;
    }
    if (!$open) return [];

    $now=time();
    $utc=gmdate('Y-m-d H:i:s',$now);
    $metadata="CASE WHEN JSON_VALID(e.metadata_json) THEN e.metadata_json ELSE '{}' END";
    $target="JSON_EXTRACT($metadata,'$.target.meeting_id')";
    $agenda="JSON_EXTRACT($metadata,'$.target.agenda_item_id')";
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    if (!$sqlite) {
        $target="JSON_UNQUOTE($target)";
        $agenda="JSON_UNQUOTE($agenda)";
    }
    try {
        if (!vp3_meeting_research_owned_v1($pdo,['user'=>$user,'agent_id'=>$agent],$meeting)) return [];
        $query=$pdo->prepare("SELECT r.id AS run_id,r.goal,r.status AS run_status,e.metadata_json,
                a.id AS action_id,a.attempt_count,a.result_json,a.completed_at AS action_completed_at
            FROM agent_workflow_events e
            JOIN agent_workflow_runs r ON r.id=e.run_id AND r.owner_user_id=e.owner_user_id
            JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1
                AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'
            WHERE e.owner_user_id=? AND e.event_type='meeting_research_linked'
                AND CAST($target AS CHAR)=? AND CAST($agenda AS CHAR) IN (".implode(',',array_fill(0,count($open),'?')).")
                AND CAST(COALESCE(r.agent_id,0) AS CHAR)=? AND r.workflow_type='initiative' AND r.source_kind='agent_initiative'
                AND r.capability_key='initiative.prepare' AND r.execution_target='cloud'
                AND ((r.status IN ('queued','approved') AND a.status='queued')
                    OR (r.status='executing' AND a.status='executing' AND r.current_action_id=a.id AND r.lease_expires_at>?)
                    OR (r.status='completed' AND a.status='completed'))
                AND NOT EXISTS (SELECT 1 FROM agent_workflow_events newer WHERE newer.run_id=e.run_id
                    AND newer.owner_user_id=e.owner_user_id AND newer.event_type=e.event_type AND newer.id>e.id)
            ORDER BY e.id DESC LIMIT 32");
        $query->execute([$owner,(string)$meetingId,...array_map('strval',array_keys($open)),(string)$agent,$utc]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        $views=[];
        foreach ($rows as $row) {
            $link=json_decode((string)$row['metadata_json'],true);
            $target=$link['target']??null;
            $id=$target['agenda_item_id']??null;
            if (!is_int($id) || !isset($open[$id]) || isset($views[$id]) || ($target['meeting_id']??null)!==$meetingId) continue;
            $goal=json_decode((string)$row['goal'],true);
            $conversation=$goal['binding']['conversation_id']??null;
            if (!is_array($goal) || !is_int($conversation) || $conversation<1
                || vp3_meeting_research_source_v1($goal)===null) continue;
            $view=match ($row['run_status']) {
                'executing'=>['state'=>'running'],
                'completed'=>vp3_meeting_research_agenda_completion_v1($pdo,$owner,$agent,$conversation,$row,$goal,$now),
                default=>['state'=>'queued'],
            };
            if ($view===null || vp3_meeting_research_projection_v1($pdo,$owner,$agent,$conversation,(int)$row['run_id'],$goal)===null) continue;
            $views[$id]=$view;
        }
        return $views;
    } catch (RuntimeException $error) {
        return [];
    }
}

/** The reverse link is durable, including after the transient Chat rail expires. */
function vp3_meeting_research_agenda_completion_v1(PDO $pdo,int $owner,int $agent,int $conversation,array $row,array $goal,int $now): ?array
{
    $completion=vp3_initiative_research_status_completion_v1($pdo,$owner,$agent,$conversation,$row,$goal,$now,true);
    if ($completion===null) return null;
    $view=['state'=>'ready'];
    if ($completion['state']==='completed') {
        $view['chat_url']=vp3_top_of_mind_media_href_v1($agent,$conversation,(int)$completion['message_id']);
    }
    return $view;
}
