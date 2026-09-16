<?php
declare(strict_types=1);

function video_meeting_live_agent_context_v18110(PDO $pdo,array $meeting): array
{
    $public=video_meeting_intelligence_public_state_v1890($pdo,$meeting);$snapshot=is_array($public['snapshot']??null)?$public['snapshot']:[];
    $cleanRows=static function(mixed $rows,array $keys,int $limit=8): array {
        $out=[];foreach(array_slice(is_array($rows)?$rows:[],0,$limit) as $row){if(!is_array($row))continue;$text='';foreach($keys as $key){$text=video_meeting_live_agent_text_v18110($row[$key]??'',900);if($text!=='')break;}if($text!=='')$out[]=$text;}return $out;
    };
    return [
        'meeting'=>[
            'public_id'=>(string)$meeting['public_id'],'title'=>video_meeting_live_agent_text_v18110($meeting['title']??'',190),
            'status'=>(string)$meeting['status'],'agent_name'=>video_meeting_agent_name_v1800($pdo,$meeting),
        ],
        'meeting_intelligence'=>[
            'summary'=>video_meeting_live_agent_text_v18110($snapshot['summary']??'',2400),
            'decisions'=>$cleanRows($snapshot['decisions']??[],['decision','commitment','text']),
            'actions'=>$cleanRows($snapshot['actions']??[],['action','follow_up','next_step','text']),
            'questions'=>$cleanRows($snapshot['questions']??[],['question','text']),
            'objectives'=>array_values(array_filter(array_map(static fn($row)=>is_array($row)?video_meeting_live_agent_text_v18110($row['objective_text']??'',700):'',array_slice((array)($public['objectives']??[]),0,8)))),
            'source_hash'=>(string)($public['source_hash']??''),'word_count'=>(int)($public['word_count']??0),
        ],
        'guardrails'=>[
            'live_advisory_only'=>true,'no_direct_side_effects'=>true,
            'promote_actions_via_post_meeting_queue'=>true,'private_context_not_persisted'=>true,
        ],
    ];
}

function video_meeting_live_agent_public_turn_v18110(array $turn): array
{
    return [
        'id'=>(string)($turn['id']??''),'client_turn_id'=>(string)($turn['client_turn_id']??''),
        'question'=>video_meeting_live_agent_text_v18110($turn['question']??'',4000),
        'answer'=>video_meeting_live_agent_text_v18110($turn['answer']??'',12000),
        'created_at'=>(string)($turn['created_at']??''),'route'=>(string)($turn['route']??''),
        'sources'=>array_slice(is_array($turn['sources']??null)?$turn['sources']:[],0,12),
        'ephemeral'=>!empty($turn['ephemeral']),
    ];
}

function video_meeting_live_agent_public_state_v18110(PDO $pdo,array $meeting,array $user): array
{
    $cap=video_meeting_live_agent_capability_v18110($pdo,$meeting,$user);$stored=video_meeting_live_agent_artifact_v18110($pdo,$meeting)?:[];
    $turns=[];foreach(array_slice((array)($stored['turns']??[]),-30) as $turn)if(is_array($turn))$turns[]=video_meeting_live_agent_public_turn_v18110($turn);
    $active=!empty($stored['active'])&&!empty($cap['meeting_live']);
    return [
        'available'=>!empty($cap['available']),'active'=>$active,'session_id'=>(string)($stored['session_id']??''),
        'started_at'=>(string)($stored['started_at']??''),'stopped_at'=>(string)($stored['stopped_at']??''),
        'interaction_mode'=>'text','spoken_available'=>false,'media_worker_available'=>!empty($cap['media_worker_available']),
        'media_worker'=>is_array($stored['media_worker']??null)?$stored['media_worker']:null,
        'agent_id'=>(int)$cap['agent_id'],'agent_name'=>(string)$cap['agent_name'],'provider'=>(string)($cap['provider']??''),'runtime_plan'=>$cap['runtime_plan'],
        'turns'=>$turns,'turn_count'=>count($turns),
        'safety'=>[
            'organizer_controlled'=>true,'advisory_only'=>true,'direct_actions_disabled'=>true,
            'followthrough_requires_review'=>true,'spoken_output_available'=>false,
            'homeserver_live_generation_supported'=>true,'homeserver_turns_ephemeral'=>true,
        ],
    ];
}

function video_meeting_live_agent_history_v18110(array $state): array
{
    $history=[];foreach(array_slice((array)($state['turns']??[]),-8) as $turn){if(!is_array($turn))continue;$q=video_meeting_live_agent_text_v18110($turn['question']??'',4000);$a=video_meeting_live_agent_text_v18110($turn['answer']??'',8000);if($q!=='')$history[]=['role'=>'user','message'=>$q];if($a!=='')$history[]=['role'=>'assistant','message'=>$a];}return $history;
}
