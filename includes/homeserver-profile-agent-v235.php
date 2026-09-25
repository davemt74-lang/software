<?php
declare(strict_types=1);

/**
 * HomeServer v2.3 Section 5 — Profile Agent local compute parity.
 *
 * This path never calls normal HomeServer agent.chat. Cloud first applies the
 * Profile Agent's existing public data policies; only that already-approved
 * context is sent to a stateless, local-only HomeServer inference operation.
 */
const VP3_HOMESERVER_PROFILE_AGENT_V235='vp3-homeserver-profile-agent-v235-20260925';

function homeserver_profile_v235_set_last(array $state): array
{
    $GLOBALS['vp3_homeserver_profile_v235_last']=$state;
    return $state;
}

function homeserver_profile_v235_last(): array
{
    $state=$GLOBALS['vp3_homeserver_profile_v235_last']??null;
    return is_array($state)?$state:['attempted'=>false,'success'=>false,'execution'=>null,'failure_class'=>'none'];
}

function homeserver_profile_v235_supported(int $userId): bool
{
    if($userId<1||!function_exists('homeserver_execution_v220_registry'))return false;
    $registry=homeserver_execution_v220_registry($userId);
    $caps=is_array($registry['capabilities']??null)?$registry['capabilities']:[];
    $unified=is_array($caps['unified_execution']??null)?$caps['unified_execution']:[];
    $safe=is_array($unified['profile_safe_local_inference']??null)?$unified['profile_safe_local_inference']:[];
    return !empty($registry['available'])
      && (string)($safe['operation']??'')==='agent.infer.local'
      && !empty($safe['stateless'])
      && !empty($safe['local_only'])
      && !empty($safe['caller_supplied_context_only'])
      && empty($safe['tools_enabled']);
}

function homeserver_profile_v235_text(mixed $value,int $max): string
{
    $text=trim((string)$value);
    return mb_strimwidth($text,0,$max,'…');
}

function homeserver_profile_v235_messages(string $query,array $history,array $context): array
{
    $system="You are a public Profile Agent. Use only the approved context in this request. "
      ."Do not use or infer any HomeServer memory, awareness, contacts, files, tasks, notifications, tools, or other private data that is not explicitly included below. "
      ."Never invent private facts, commitments, pricing, availability, or contact details. If approved context is insufficient, say so.";

    $parts=[];
    $budget=0;
    foreach(array_slice($context,0,24) as $item){
        if(!is_array($item))continue;
        $source=homeserver_profile_v235_text($item['source']??'',180);
        $title=homeserver_profile_v235_text($item['title']??'',240);
        $text=homeserver_profile_v235_text($item['text']??'',5000);
        if($text==='')continue;
        $chunk="[Approved source: ".($source!==''?$source:'profile')."]".($title!==''?"\n".$title:'')."\n".$text;
        if($budget+mb_strlen($chunk)>20000)break;
        $parts[]=$chunk;$budget+=mb_strlen($chunk);
    }
    if($parts)$system.="\n\nApproved context:\n".implode("\n\n",$parts);

    $messages=[['role'=>'system','content'=>homeserver_profile_v235_text($system,24000)]];
    $trimmed=array_slice($history,-10);
    if($trimmed){
        $last=end($trimmed);
        if(is_array($last)&&(string)($last['role']??'')==='user'&&trim((string)($last['message']??''))===trim($query))array_pop($trimmed);
    }
    foreach($trimmed as $row){
        if(!is_array($row))continue;
        $role=(string)($row['role']??'');
        if(!in_array($role,['user','assistant'],true))continue;
        $message=homeserver_profile_v235_text($row['message']??'',1800);
        if($message!=='')$messages[]=['role'=>$role,'content'=>$message];
    }
    $messages[]=['role'=>'user','content'=>homeserver_profile_v235_text($query,4000)];
    return $messages;
}

function homeserver_profile_v235_answer(int $ownerUserId,string $query,array $history,array $approvedContext): ?array
{
    homeserver_profile_v235_set_last(['attempted'=>false,'success'=>false,'execution'=>null,'failure_class'=>'none']);
    if($ownerUserId<1||trim($query)===''||!function_exists('homeserver_execution_v230_execute'))return null;
    if(!homeserver_profile_v235_supported($ownerUserId))return null;

    homeserver_profile_v235_set_last(['attempted'=>true,'success'=>false,'execution'=>null,'failure_class'=>'none']);
    try{
        $run=homeserver_execution_v230_execute($ownerUserId,'agent.infer.local',[
          'messages'=>homeserver_profile_v235_messages($query,$history,$approvedContext),
        ]);
        $result=is_array($run['result']??null)?$run['result']:[];
        $reply=trim((string)($result['reply']??''));
        if($reply==='')throw new RuntimeException('HomeServer local inference returned no reply.');
        $state=[
          'attempted'=>true,'success'=>true,
          'execution'=>is_array($run['execution']??null)?$run['execution']:null,
          'failure_class'=>'none',
          'provider'=>mb_strimwidth(trim((string)($result['provider']??'ollama')),0,80,''),
          'model'=>mb_strimwidth(trim((string)($result['model']??'')),0,160,''),
        ];
        homeserver_profile_v235_set_last($state);
        return ['answer'=>$reply]+$state;
    }catch(Throwable $e){
        homeserver_profile_v235_set_last([
          'attempted'=>true,'success'=>false,'execution'=>null,
          'failure_class'=>function_exists('homeserver_execution_v230_failure_class')
            ? homeserver_execution_v230_failure_class($e)
            : 'homeserver_unavailable',
        ]);
        return null;
    }
}
