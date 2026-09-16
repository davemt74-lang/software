<?php
declare(strict_types=1);

function video_meeting_live_agent_ask_v18110(PDO $pdo,array $meeting,array $user,string $question,string $clientTurnId): array
{
    if(!video_meeting_live_agent_owner_allowed_v18110($user,$meeting))throw new RuntimeException('Only the meeting organizer can direct the live Agent.');
    if((string)($meeting['status']??'')!=='live')throw new RuntimeException('The live Agent is only available while the meeting is live.');
    $question=video_meeting_live_agent_text_v18110($question,4000);if($question==='')throw new RuntimeException('Ask the meeting Agent a question.');
    $clientTurnId=trim($clientTurnId);
    if(!preg_match('/^[A-Za-z0-9._:-]{8,100}$/',$clientTurnId))throw new RuntimeException('The live Agent turn identifier is invalid.');

    $state=video_meeting_live_agent_artifact_v18110($pdo,$meeting)?:[];
    if(empty($state['active'])||trim((string)($state['session_id']??''))==='')throw new RuntimeException('Start the live Agent before asking it to participate.');
    foreach((array)($state['turns']??[]) as $existing){
        if(is_array($existing)&&hash_equals((string)($existing['client_turn_id']??''),$clientTurnId)){
            return ['state'=>video_meeting_live_agent_public_state_v18110($pdo,$meeting,$user),'turn'=>video_meeting_live_agent_public_turn_v18110($existing),'idempotent'=>true];
        }
    }

    $plan=video_meeting_live_agent_runtime_plan_v18110($pdo,$meeting,$user);
    if(!empty($plan['blocked']))throw new RuntimeException(function_exists('vp3_agent_runtime_block_message_v420')?vp3_agent_runtime_block_message_v420($plan):'No authorized Agent compute route is currently available.');

    $meetingContext=video_meeting_live_agent_context_v18110($pdo,$meeting);
    $agentContext=[
        'meeting_live_agent'=>true,'surface'=>'video_meeting','meeting_context'=>$meetingContext,
        'meeting_public_id'=>(string)$meeting['public_id'],'meeting_title'=>(string)$meeting['title'],
        'organizer_controlled'=>true,'direct_actions_disabled'=>true,
        'action_policy'=>'Advisory only. Do not claim an external action was executed. Proposed actions must be reviewed through the Post-Meeting Action Queue.',
    ];
    if(function_exists('agent_surface_v131_enrich'))$agentContext=agent_surface_v131_enrich($user,'chat',$agentContext);
    $history=video_meeting_live_agent_history_v18110($state);

    if(function_exists('chat_generate_answer_v105'))$result=chat_generate_answer_v105($question,$history,$user,$agentContext);
    elseif(function_exists('chat_generate_answer'))$result=chat_generate_answer($question,$history,$user);
    else throw new RuntimeException('The canonical VP3 Agent inference runtime is unavailable.');
    if(!is_array($result))throw new RuntimeException('The live Agent did not return a valid response.');
    $answer=video_meeting_live_agent_text_v18110($result['answer']??'',12000);if($answer==='')throw new RuntimeException('The live Agent returned an empty response.');

    $sessionId=(string)$state['session_id'];
    $turn=[
        'id'=>'mlat-'.substr(hash('sha256',$sessionId.'|'.$clientTurnId),0,28),'client_turn_id'=>$clientTurnId,
        'question'=>$question,'answer'=>$answer,'created_at'=>gmdate('c'),
        'route'=>(string)($plan['route']??'unknown'),'sources'=>video_meeting_live_agent_sources_v18110($result['context']??[]),
    ];
    $turns=is_array($state['turns']??null)?$state['turns']:[];$turns[]=$turn;$state['turns']=array_slice($turns,-40);
    video_meeting_live_agent_store_v18110($pdo,$meeting,$state);
    return ['state'=>video_meeting_live_agent_public_state_v18110($pdo,$meeting,$user),'turn'=>video_meeting_live_agent_public_turn_v18110($turn),'idempotent'=>false];
}
