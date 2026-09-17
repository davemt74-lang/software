<?php
declare(strict_types=1);

const VP3_VIDEO_MEETINGS_COMMITMENT_COMMAND_V18230='video-meetings-commitment-command-v18230-20260917';

require_once __DIR__.'/video-meetings-plan-action-guard-v18190.php';

function video_meeting_commitment_command_query_relevant_v18230(string $query): bool
{
    $q=video_meeting_memory_normalize_v18120($query);
    if($q==='')return false;
    if(preg_match('/\b(meeting commitment command|commitment command|meeting commitments?|meeting promises?|meeting obligations?|unresolved commitments?|outstanding commitments?|commitments? from meetings?|what (?:did|have) we commit|what (?:did|have) we promise|what do i owe from meetings?|what needs attention from meetings?|what is overdue from meetings?)\b/u',$q)===1)return true;
    $meeting=preg_match('/\b(meeting|meetings|agenda|follow[ -]?through|follow[ -]?up|continuity|carried forward)\b/u',$q)===1;
    $commitment=preg_match('/\b(commit|committed|commitment|commitments|promise|promised|outstanding|overdue|blocked|stale|drift|verification|verify)\b/u',$q)===1;
    return $meeting&&$commitment;
}

function video_meeting_commitment_command_ready_v18230(): bool
{
    foreach([
        'video_meetings','video_meeting_agenda_items','video_meeting_followthrough_plans','video_meeting_plan_action_handoffs',
        'video_meeting_action_executions','video_meeting_followthrough_monitors','video_meeting_followthrough_closures','video_meeting_continuity_links',
    ] as $table){if(!table_exists($table))return false;}
    return true;
}

function video_meeting_commitment_command_handoff_v18230(array $row): array
{
    $handoffId=(int)($row['handoff_id']??0);
    if($handoffId<1)return ['status'=>'none','drift_source'=>''];
    if((int)($row['plan_id']??0)<1||(string)($row['plan_readiness']??'')!=='ready')return ['status'=>'stale','drift_source'=>'plan'];
    $plan=[
        'id'=>(int)$row['plan_id'],'agenda_item_id'=>(int)$row['agenda_item_id'],'owner_label'=>(string)($row['plan_owner_label']??''),
        'target_at'=>(string)($row['plan_target_at']??''),'target_timezone'=>(string)($row['plan_target_timezone']??'UTC'),
        'verification_criteria'=>(string)($row['plan_verification_criteria']??''),'readiness'=>(string)$row['plan_readiness'],
    ];
    $planHash=video_meeting_plan_action_snapshot_hash_v18190(video_meeting_plan_action_snapshot_from_row_v18190($plan));
    $storedPlan=(string)($row['handoff_plan_snapshot_hash']??'');
    if($storedPlan===''||!hash_equals($storedPlan,$planHash))return ['status'=>'stale','drift_source'=>'plan'];
    if((int)($row['execution_id']??0)<1)return ['status'=>'stale','drift_source'=>'action_draft'];
    $execution=['action_kind'=>(string)($row['action_kind']??''),'draft_json'=>(string)($row['execution_draft_json']??'')];
    $draftHash=video_meeting_plan_action_bound_draft_hash_v18190($execution);
    $storedDraft=(string)($row['handoff_action_draft_hash']??'');
    if($storedDraft===''||!hash_equals($storedDraft,$draftHash))return ['status'=>'stale','drift_source'=>'action_draft'];
    return ['status'=>'current','drift_source'=>''];
}

function video_meeting_commitment_command_monitor_status_v18230(array $row,int $now): string
{
    $status=(string)($row['monitor_status']??'');
    if($status===''||in_array($status,['verified','blocked','dismissed'],true))return $status;
    $expected=strtotime((string)($row['expected_by_at']??''))?:0;
    if($expected>0&&$now>=$expected)return 'overdue';
    if($expected>0&&$expected-$now<=86400)return 'due_soon';
    return $status;
}

function video_meeting_commitment_command_classify_v18230(array $row): array
{
    $verification=(string)($row['verification_status']??'');$execution=(string)($row['execution_status']??'');
    $monitor=(string)($row['derived_monitor_status']??'');$handoff=(string)($row['derived_handoff_status']??'');$plan=(string)($row['plan_readiness']??'');
    if($verification==='verified')return ['bucket'=>'verified','attention_required'=>false,'stage'=>'verified'];
    if($execution==='failed')return ['bucket'=>'action_failed','attention_required'=>true,'stage'=>'action'];
    if($monitor==='blocked')return ['bucket'=>'followthrough_blocked','attention_required'=>true,'stage'=>'followthrough'];
    if($monitor==='overdue')return ['bucket'=>'overdue','attention_required'=>true,'stage'=>'followthrough'];
    if($handoff==='stale')return ['bucket'=>'handoff_drift','attention_required'=>true,'stage'=>'handoff'];
    if((int)($row['plan_id']??0)<1||$plan==='needs_definition')return ['bucket'=>'plan_needs_definition','attention_required'=>true,'stage'=>'plan'];
    if($verification==='needs_attention')return ['bucket'=>'verification_needs_attention','attention_required'=>true,'stage'=>'verification'];
    if($verification==='evidence_available')return ['bucket'=>'verification_review','attention_required'=>true,'stage'=>'verification'];
    if($verification==='waiting_for_verification')return ['bucket'=>'waiting_for_verification','attention_required'=>false,'stage'=>'verification'];
    if((int)($row['execution_id']??0)>0&&$execution==='needs_review')return ['bucket'=>'action_needs_review','attention_required'=>true,'stage'=>'action'];
    if($handoff==='none'&&$plan==='ready')return ['bucket'=>'ready_for_handoff','attention_required'=>true,'stage'=>'plan'];
    if((int)($row['continuity_link_id']??0)>0)return ['bucket'=>'carried_forward','attention_required'=>false,'stage'=>'continuity'];
    if((int)($row['monitor_id']??0)>0)return ['bucket'=>'active_followthrough','attention_required'=>false,'stage'=>'followthrough'];
    if((int)($row['execution_id']??0)>0)return ['bucket'=>'action_in_progress','attention_required'=>false,'stage'=>'action'];
    return ['bucket'=>'active','attention_required'=>false,'stage'=>'agenda'];
}

function video_meeting_commitment_command_state_v18230(PDO $pdo,int $ownerUserId,int $limit=60): array
{
    $limit=max(1,min(100,$limit));
    $empty=['version'=>'v18.23','schema'=>'vp3.meeting.commitment_command','available'=>false,'commitments'=>[],'summary'=>['total'=>0,'attention_required'=>0,'verified'=>0,'carried_forward'=>0,'by_bucket'=>[]],'generated_at'=>gmdate('c')];
    if($ownerUserId<1||!video_meeting_commitment_command_ready_v18230())return $empty;
    $sql="SELECT a.id AS agenda_item_id,a.item_text,a.item_type,a.status AS agenda_status,a.priority,a.action_kind,a.approval_state,a.sort_order,
      m.id AS meeting_id,m.public_id AS meeting_public_id,m.title AS meeting_title,m.start_at_utc AS meeting_start_at_utc,m.timezone AS meeting_timezone,m.status AS meeting_status,
      p.id AS plan_id,p.owner_label AS plan_owner_label,p.target_at AS plan_target_at,p.target_timezone AS plan_target_timezone,p.verification_criteria AS plan_verification_criteria,p.readiness AS plan_readiness,
      h.id AS handoff_id,h.plan_snapshot_hash AS handoff_plan_snapshot_hash,h.action_draft_hash AS handoff_action_draft_hash,h.status AS stored_handoff_status,
      e.id AS execution_id,e.status AS execution_status,e.draft_json AS execution_draft_json,e.error_class AS execution_error_class,e.last_error AS execution_last_error,e.result_summary AS execution_result_summary,
      fm.id AS monitor_id,fm.status AS monitor_status,fm.expected_by_at,fm.canonical_status AS monitor_canonical_status,fm.evidence_summary AS monitor_evidence_summary,fm.verification_source AS monitor_verification_source,fm.last_checked_at AS monitor_last_checked_at,
      vc.id AS verification_id,vc.status AS verification_status,vc.criteria_snapshot AS verification_criteria_snapshot,vc.evidence_summary AS verification_evidence_summary,vc.evidence_source AS verification_evidence_source,vc.evidence_status AS verification_evidence_status,vc.agent_suggestion AS verification_agent_suggestion,vc.organizer_note AS verification_organizer_note,vc.verified_at,
      cl.id AS continuity_link_id,cl.status AS continuity_status,cl.target_meeting_id AS continuity_target_meeting_id,cl.target_agenda_item_id AS continuity_target_agenda_item_id,
      cm.public_id AS continuity_target_public_id,cm.title AS continuity_target_title,cm.start_at_utc AS continuity_target_start_at_utc
      FROM video_meeting_agenda_items a
      JOIN video_meetings m ON m.id=a.meeting_id AND m.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_followthrough_plans p ON p.agenda_item_id=a.id AND p.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_plan_action_handoffs h ON h.agenda_item_id=a.id AND h.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_action_executions e ON e.agenda_item_id=a.id AND e.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_followthrough_monitors fm ON fm.execution_id=e.id AND fm.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_followthrough_closures vc ON vc.agenda_item_id=a.id AND vc.owner_user_id=a.owner_user_id
      LEFT JOIN video_meeting_continuity_links cl ON cl.id=(SELECT MAX(cl2.id) FROM video_meeting_continuity_links cl2 WHERE cl2.source_agenda_item_id=a.id AND cl2.owner_user_id=a.owner_user_id)
      LEFT JOIN video_meetings cm ON cm.id=cl.target_meeting_id AND cm.owner_user_id=a.owner_user_id
      WHERE a.owner_user_id=? AND m.owner_user_id=? AND a.status<>'skipped' AND (a.item_type='follow_up' OR a.status='follow_up')
      ORDER BY COALESCE(p.target_at,m.start_at_utc) DESC,a.id DESC LIMIT ".$limit;
    $stmt=$pdo->prepare($sql);$stmt->execute([$ownerUserId,$ownerUserId]);
    $now=time();$items=[];$buckets=[];$attention=0;$verified=0;$carried=0;
    foreach($stmt->fetchAll()?:[] as $row){
        if(!is_array($row))continue;$handoff=video_meeting_commitment_command_handoff_v18230($row);$row['derived_handoff_status']=$handoff['status'];$row['derived_monitor_status']=video_meeting_commitment_command_monitor_status_v18230($row,$now);$class=video_meeting_commitment_command_classify_v18230($row);$bucket=(string)$class['bucket'];$buckets[$bucket]=($buckets[$bucket]??0)+1;if($class['attention_required'])$attention++;if($bucket==='verified')$verified++;if((int)($row['continuity_link_id']??0)>0)$carried++;
        $targetPublic=trim((string)($row['continuity_target_public_id']??''));$meetingPublic=(string)$row['meeting_public_id'];
        $items[]=[
            'agenda_item_id'=>(int)$row['agenda_item_id'],'commitment'=>video_meeting_memory_text_v18120($row['item_text']??'',1200),'priority'=>(string)($row['priority']??''),'agenda_status'=>(string)($row['agenda_status']??''),'action_kind'=>(string)($row['action_kind']??''),'approval_state'=>(string)($row['approval_state']??''),
            'meeting'=>['id'=>(int)$row['meeting_id'],'public_id'=>$meetingPublic,'title'=>video_meeting_memory_text_v18120($row['meeting_title']??'Meeting',190),'start_at_utc'=>(string)($row['meeting_start_at_utc']??''),'timezone'=>(string)($row['meeting_timezone']??'UTC'),'status'=>(string)($row['meeting_status']??''),'review_path'=>'/meeting.php?meeting='.rawurlencode($meetingPublic)],
            'plan'=>['id'=>(int)($row['plan_id']??0),'owner_label'=>video_meeting_memory_text_v18120($row['plan_owner_label']??'',190),'target_at'=>(string)($row['plan_target_at']??''),'target_timezone'=>(string)($row['plan_target_timezone']??'UTC'),'verification_criteria'=>video_meeting_memory_text_v18120($row['plan_verification_criteria']??'',1000),'readiness'=>(string)($row['plan_readiness']??'')],
            'handoff'=>['id'=>(int)($row['handoff_id']??0),'stored_status'=>(string)($row['stored_handoff_status']??''),'status'=>(string)$handoff['status'],'drift_source'=>(string)$handoff['drift_source']],
            'action'=>['execution_id'=>(int)($row['execution_id']??0),'status'=>(string)($row['execution_status']??''),'error_class'=>(string)($row['execution_error_class']??''),'last_error'=>video_meeting_memory_text_v18120($row['execution_last_error']??'',700),'result_summary'=>video_meeting_memory_text_v18120($row['execution_result_summary']??'',700)],
            'followthrough'=>['monitor_id'=>(int)($row['monitor_id']??0),'stored_status'=>(string)($row['monitor_status']??''),'status'=>(string)$row['derived_monitor_status'],'expected_by_at'=>(string)($row['expected_by_at']??''),'canonical_status'=>(string)($row['monitor_canonical_status']??''),'evidence_summary'=>video_meeting_memory_text_v18120($row['monitor_evidence_summary']??'',700),'verification_source'=>(string)($row['monitor_verification_source']??''),'last_checked_at'=>(string)($row['monitor_last_checked_at']??'')],
            'verification'=>['id'=>(int)($row['verification_id']??0),'status'=>(string)($row['verification_status']??''),'criteria'=>video_meeting_memory_text_v18120($row['verification_criteria_snapshot']??'',1000),'evidence_summary'=>video_meeting_memory_text_v18120($row['verification_evidence_summary']??'',700),'evidence_source'=>(string)($row['verification_evidence_source']??''),'evidence_status'=>(string)($row['verification_evidence_status']??''),'agent_suggestion'=>(string)($row['verification_agent_suggestion']??''),'organizer_note'=>video_meeting_memory_text_v18120($row['verification_organizer_note']??'',700),'verified_at'=>(string)($row['verified_at']??'')],
            'continuity'=>['link_id'=>(int)($row['continuity_link_id']??0),'status'=>(string)($row['continuity_status']??''),'target_meeting_id'=>(int)($row['continuity_target_meeting_id']??0),'target_agenda_item_id'=>(int)($row['continuity_target_agenda_item_id']??0),'target_meeting_public_id'=>$targetPublic,'target_meeting_title'=>video_meeting_memory_text_v18120($row['continuity_target_title']??'',190),'target_start_at_utc'=>(string)($row['continuity_target_start_at_utc']??''),'target_review_path'=>$targetPublic!==''?'/meeting.php?meeting='.rawurlencode($targetPublic):''],
            'command'=>['bucket'=>$bucket,'stage'=>(string)$class['stage'],'attention_required'=>(bool)$class['attention_required']],
        ];
    }
    ksort($buckets);
    return ['version'=>'v18.23','schema'=>'vp3.meeting.commitment_command','available'=>true,'commitments'=>$items,'summary'=>['total'=>count($items),'attention_required'=>$attention,'verified'=>$verified,'carried_forward'=>$carried,'by_bucket'=>$buckets],'generated_at'=>gmdate('c')];
}
