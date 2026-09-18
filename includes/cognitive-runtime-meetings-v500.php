<?php
declare(strict_types=1);

/**
 * Phase 11B.1 Meeting Intelligence adapter for Cognitive Runtime v5.00.
 * Existing Video Meeting/Transcription Intelligence remains canonical.
 */

function vp3_cognitive_meeting_load_v500(): void
{
    require_once __DIR__.'/video-meetings-v1800.php';
    require_once __DIR__.'/video-meetings-transcription-v1800.php';
    require_once __DIR__.'/video-meetings-intelligence-v1820.php';
}

function vp3_cognitive_meeting_row_v500(PDO $pdo,array $ref): ?array
{
    vp3_cognitive_meeting_load_v500();
    $id=(string)($ref['id']??'');
    if($id==='')return null;
    if(ctype_digit($id)&&function_exists('video_meeting_row_v1800'))return video_meeting_row_v1800($pdo,(int)$id);
    return function_exists('video_meeting_by_public_id_v1800')?video_meeting_by_public_id_v1800($pdo,$id):null;
}

function vp3_cognitive_meeting_permission_v500(PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'): bool
{
    $meeting=vp3_cognitive_meeting_row_v500($pdo,$ref);
    if(!$meeting)return false;
    $uid=(int)($user['id']??0);
    if($uid<1)return false;
    if($uid===(int)($meeting['owner_user_id']??0))return true;
    if($operation!=='read')return false;
    if(!function_exists('video_meeting_access_v1800'))return false;
    try{
        $access=video_meeting_access_v1800($pdo,$user,(string)($meeting['public_id']??''),'');
        return is_array($access)&&!empty($access['meeting']);
    }catch(Throwable $e){return false;}
}

function vp3_cognitive_meeting_context_v500(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    $meeting=vp3_cognitive_meeting_row_v500($pdo,$ref);
    if(!$meeting)throw new RuntimeException('Meeting not found.');
    $uid=(int)($user['id']??0);
    $owner=$uid===(int)($meeting['owner_user_id']??0);
    $participants=[];
    if(function_exists('video_meeting_participants_v1800')){
        foreach(array_slice(video_meeting_participants_v1800($pdo,(int)$meeting['id']),0,30) as $row){
            if(!is_array($row))continue;
            $participants[]=[
                'display_name'=>vp3_cognitive_text_v500($row['display_name']??'',120),
                'role'=>vp3_cognitive_text_v500($row['role']??'',40),
                'status'=>vp3_cognitive_text_v500($row['status']??'',40),
            ];
        }
    }
    $context=[
        'meeting'=>[
            'id'=>(string)$meeting['public_id'],
            'title'=>vp3_cognitive_text_v500($meeting['title']??'Meeting',190),
            'description'=>vp3_cognitive_text_v500($meeting['description']??'',1200),
            'status'=>(string)($meeting['status']??'scheduled'),
            'start_at_utc'=>(string)($meeting['start_at_utc']??''),
            'end_at_utc'=>(string)($meeting['end_at_utc']??''),
            'timezone'=>(string)($meeting['timezone']??'UTC'),
            'agent_mode'=>(string)($meeting['agent_mode']??''),
            'transcription_enabled'=>!empty($meeting['transcription_enabled']),
            'recording_enabled'=>!empty($meeting['recording_enabled']),
            'participants'=>$participants,
        ],
        'role'=>$owner?'organizer':'participant',
    ];
    if($owner&&function_exists('video_meeting_intelligence_public_state_v1820')){
        try{
            $state=video_meeting_intelligence_public_state_v1820($pdo,$meeting);
            $snapshot=is_array($state['snapshot']??null)?$state['snapshot']:[];
            $context['intelligence']=[
                'summary'=>vp3_cognitive_text_v500($snapshot['summary']??'',3000),
                'key_points'=>array_slice((array)($snapshot['key_points']??[]),0,16),
                'decisions_and_commitments'=>array_slice((array)($snapshot['decisions']??[]),0,24),
                'actions'=>array_slice((array)($snapshot['actions']??[]),0,20),
                'questions'=>array_slice((array)($snapshot['questions']??[]),0,16),
                'answered_questions'=>array_slice((array)($snapshot['answered_questions']??[]),0,12),
                'risks'=>array_slice((array)($snapshot['risks']??[]),0,16),
                'crm'=>is_array($snapshot['crm']??null)?$snapshot['crm']:[],
                'prep'=>is_array($state['prep']??null)?$state['prep']:null,
                'notes'=>is_array($state['notes']??null)?$state['notes']:[],
                'objectives'=>array_slice((array)($state['objectives']??[]),0,20),
                'transcript_session_id'=>(int)($state['session_id']??0),
                'full_transcription_url'=>(string)($state['full_transcription_url']??''),
            ];
        }catch(Throwable $e){
            $context['intelligence']=['available'=>false];
        }
    }
    return $context;
}

function vp3_cognitive_meeting_relationships_v500(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    $meeting=vp3_cognitive_meeting_row_v500($pdo,$ref);
    if(!$meeting)return [];
    $owner=(int)($user['id']??0)===(int)($meeting['owner_user_id']??0);
    $edges=[];
    if((int)($meeting['calendar_event_id']??0)>0){
        // Calendar module will own this object type when registered in 11B.3+.
        // Keep the relationship as a provider hint until that module is present.
    }
    if($owner&&function_exists('video_meeting_transcription_session_v1800')){
        try{
            $session=video_meeting_transcription_session_v1800($pdo,$meeting);
            if(is_array($session)){
                $registry=vp3_cognitive_registry_storage_v500();
                if(isset($registry['objects']['transcription'])){
                    $edges[]=[
                        'relation'=>'transcript_of',
                        'object_ref'=>vp3_cognitive_object_ref_v500('transcription',(string)$session['id'],'personal',['provenance'=>'video_meeting']),
                        'provenance'=>'video_meeting_transcription_v1800',
                        'confidence'=>1,
                        'confirmation_state'=>'deterministic',
                    ];
                }
            }
        }catch(Throwable $e){}
    }
    return $edges;
}

function vp3_cognitive_meeting_card_v500(PDO $pdo,array $user,string $agentNamespace,array $ref,string $mode): array
{
    $meeting=vp3_cognitive_meeting_row_v500($pdo,$ref);
    if(!$meeting)throw new RuntimeException('Meeting not found.');
    $context=vp3_cognitive_meeting_context_v500($pdo,$user,$agentNamespace,$ref,['card'=>true]);
    $intel=is_array($context['intelligence']??null)?$context['intelligence']:[];
    $summary='';
    if($ref['type']==='meeting_brief'){
        $prep=is_array($intel['prep']??null)?$intel['prep']:[];
        $summary=vp3_cognitive_text_v500($prep['summary']??$prep['brief']??$intel['summary']??'',900);
    }elseif(in_array($ref['type'],['meeting_summary','meeting_followup'],true)){
        $summary=vp3_cognitive_text_v500($intel['summary']??'',900);
    }
    if($summary==='')$summary=vp3_cognitive_text_v500($meeting['description']??'',900);

    $actions=[
        ['type'=>'open_url','label'=>'Open meeting','url'=>'/meeting.php?meeting='.rawurlencode((string)$meeting['public_id'])],
    ];
    if((int)($intel['transcript_session_id']??0)>0){
        $actions[]=['type'=>'open_url','label'=>'Open transcription','url'=>'/artist-listening.php?session='.(int)$intel['transcript_session_id']];
    }
    if($ref['type']==='meeting_brief')$actions[]=['type'=>'tool','label'=>'Prepare brief','tool_id'=>'meeting.prepare_brief'];
    if(in_array($ref['type'],['meeting_summary','meeting_followup'],true))$actions[]=['type'=>'tool','label'=>'Draft follow-up','tool_id'=>'meeting.draft_followup'];

    return [
        'title'=>(string)($meeting['title']??'Meeting'),
        'subtitle'=>trim((string)($meeting['start_at_utc']??'').' · '.(string)($meeting['timezone']??'UTC')),
        'status'=>(string)($meeting['status']??'scheduled'),
        'summary'=>$summary,
        'timestamp'=>(string)($meeting['start_at_utc']??''),
        'metadata'=>[
            'participants'=>count((array)($context['meeting']['participants']??[])),
            'role'=>(string)($context['role']??'participant'),
            'recording_enabled'=>!empty($meeting['recording_enabled']),
            'transcription_enabled'=>!empty($meeting['transcription_enabled']),
            'decision_commitment_count'=>count((array)($intel['decisions_and_commitments']??[])),
            'action_count'=>count((array)($intel['actions']??[])),
            'question_count'=>count((array)($intel['questions']??[])),
        ],
        'actions'=>$actions,
    ];
}

function vp3_cognitive_register_meetings_v500(): void
{
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['meetings']))return;
    vp3_cognitive_register_module_v500([
        'module'=>'meetings',
        'version'=>'meeting-intelligence-v1',
        'objects'=>['meeting','meeting_brief','meeting_summary','meeting_followup'],
        'events'=>[
            'meeting.created','meeting.updated','meeting.reminder_due','meeting.started','meeting.ended',
            'meeting.transcription_ready','meeting.summary_ready','meeting.followup_due'
        ],
        'permission_resolver'=>'vp3_cognitive_meeting_permission_v500',
        'context_provider'=>'vp3_cognitive_meeting_context_v500',
        'relationship_provider'=>'vp3_cognitive_meeting_relationships_v500',
        'cards'=>[
            'meeting'=>'vp3_cognitive_meeting_card_v500',
            'meeting_brief'=>'vp3_cognitive_meeting_card_v500',
            'meeting_summary'=>'vp3_cognitive_meeting_card_v500',
            'meeting_followup'=>'vp3_cognitive_meeting_card_v500',
        ],
        'tools'=>[
            'meeting.context'=>['label'=>'Read meeting context','kind'=>'read','risk'=>'low','requires_approval'=>false],
            'meeting.prepare_brief'=>['label'=>'Prepare pre-meeting brief','kind'=>'prepare','risk'=>'low','requires_approval'=>false],
            'meeting.draft_followup'=>['label'=>'Draft meeting follow-up','kind'=>'prepare','risk'=>'low','requires_approval'=>false],
        ],
        'freshness_policy'=>['meeting_seconds'=>60,'brief_seconds'=>300,'summary_seconds'=>300],
        'sensitivity_policy'=>['transcript'=>'private','notes'=>'owner_private'],
        'surfaces'=>['memory','brief','away_digest','notification','voice_announce','ask_user','chat_response'],
        'voice_safe'=>true,
    ]);
}

vp3_cognitive_register_meetings_v500();
