<?php
declare(strict_types=1);
require_once __DIR__.'/profile-webmcp-continuity-v195.php';

const VP3_PROFILE_WEBMCP_CONTINUITY_V194='profile-webmcp-continuity-v194-20260929';
const VP3_PROFILE_WEBMCP_RESUME_TTL_V194=600;
const VP3_PROFILE_WEBMCP_RESUME_MAX_V194=12;

function vp3_profile_webmcp_resume_store_v194(): array
{
    $rows=$_SESSION['vp3_profile_webmcp_resume_v194']??[];
    return is_array($rows)?$rows:[];
}

function vp3_profile_webmcp_resume_prune_v194(array $rows,int $now): array
{
    $clean=[];
    foreach($rows as $token=>$row){
        if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token)||!is_array($row))continue;
        if(!is_int($row['created_at']??null)||!is_int($row['expires_at']??null)||$row['created_at']<1||$row['created_at']>$now+5||$row['expires_at']<=$now||$row['expires_at']>$row['created_at']+VP3_PROFILE_WEBMCP_RESUME_TTL_V194)continue;
        $clean[$token]=$row;
    }
    if(count($clean)>VP3_PROFILE_WEBMCP_RESUME_MAX_V194){
        uasort($clean,static fn(array $a,array $b):int=>$b['created_at']<=>$a['created_at']);
        $clean=array_slice($clean,0,VP3_PROFILE_WEBMCP_RESUME_MAX_V194,true);
    }
    return $clean;
}

function vp3_profile_webmcp_resume_issue_v194(PDO $pdo,array $profile,array $user,string $goal,array $plan,int $conversationId=0,array $binding=[]): array
{
    require_once __DIR__.'/agent-profile-webmcp-read-v1.php';
    $binding=vp3_profile_webmcp_binding_v1($binding);
    if($binding['conversation_id']!==$conversationId||$binding['user_id']!==(int)($user['id']??0))throw new RuntimeException('Profile handoff source changed.',403);
    $principal=['kind'=>$binding['agent_id']>0?'user_agent':'system','owner_user_id'=>$binding['user_id'],'agent_id'=>$binding['agent_id']];
    $live=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],true);
    if(vp3_profile_webmcp_profile_identity_v1($profile)!==vp3_profile_webmcp_profile_identity_v1($live['profile']))throw new RuntimeException('Profile handoff owner changed.',403);
    if(!mb_check_encoding($goal,'UTF-8')||mb_strlen($goal)>1000)throw new RuntimeException('Profile handoff goal is invalid.');
    $resolution=vp3_profile_webmcp_resolution_v1($pdo,$profile,$live['user']);$allowed=array_fill_keys($resolution['allowed_tools'],true);
    $tools=$plan['recommended_tools']??null;$caps=$plan['recommended_capabilities']??null;
    if(!is_array($tools)||!array_is_list($tools)||count($tools)>128||!is_array($caps)||!array_is_list($caps)||count($caps)>64)throw new RuntimeException('Profile handoff tools are invalid.');
    $names=[];$trustedCaps=[];
    foreach($tools as $tool){
        if(!is_array($tool)||!is_string($tool['name']??null)||!isset($allowed[$tool['name']])||isset($names[$tool['name']]))throw new RuntimeException('Profile handoff capability changed.',403);
        $trusted=vp3_profile_webmcp_tool_catalog_v100()[$tool['name']];$names[$tool['name']]=true;$trustedCaps[$trusted['capability']]=true;
    }
    foreach($caps as $cap)if(!is_string($cap)||!isset($trustedCaps[$cap]))throw new RuntimeException('Profile handoff capability is invalid.');
    $fresh=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],true);
    if(vp3_profile_webmcp_profile_identity_v1($fresh['profile'])!==vp3_profile_webmcp_profile_identity_v1($profile))throw new RuntimeException('Profile handoff availability changed.',403);
    $now=time();$token=bin2hex(random_bytes(16));$safeGoal=trim($goal);
    $actionContext=vp3_profile_webmcp_action_context_issue_v195($profile,$fresh['user'],$safeGoal,$conversationId,$binding,array_keys($names));
    $rows=vp3_profile_webmcp_resume_prune_v194(vp3_profile_webmcp_resume_store_v194(),$now);
    $rows[$token]=[
        'version'=>VP3_PROFILE_WEBMCP_CONTINUITY_V194,'profile_user_id'=>$binding['user_id'],'profile_username'=>$profile['username'],'binding'=>$binding,'goal'=>$safeGoal,
        'action_context_id'=>$actionContext['context_id'],'correlation_id'=>$actionContext['context_id'],'return_token'=>$actionContext['return_token'],'return_path'=>$actionContext['return_path'],
        'conversation_id'=>$conversationId,'recommended_capabilities'=>array_keys($trustedCaps),'recommended_tools'=>array_keys($names),'created_at'=>$now,'expires_at'=>$now+VP3_PROFILE_WEBMCP_RESUME_TTL_V194,
    ];
    $_SESSION['vp3_profile_webmcp_resume_v194']=vp3_profile_webmcp_resume_prune_v194($rows,$now);
    return ['token'=>$token,'path'=>'/'.rawurlencode((string)$profile['username']).'?webmcp_resume='.$token,'expires_at'=>$now+VP3_PROFILE_WEBMCP_RESUME_TTL_V194,
        'action_context_id'=>$actionContext['context_id'],'return_token'=>$actionContext['return_token'],'return_path'=>$actionContext['return_path']];
}

function vp3_profile_webmcp_resume_validate_v194(array $profile,array $user,string $token,?PDO $pdo=null): ?array
{
    if(!$pdo||!preg_match('/^[a-f0-9]{32}$/D',$token))return null;
    require_once __DIR__.'/agent-profile-webmcp-read-v1.php';
    $rows=vp3_profile_webmcp_resume_prune_v194(vp3_profile_webmcp_resume_store_v194(),time());$row=$rows[$token]??null;
    if(!is_array($row)||($row['profile_user_id']??null)!==(int)($user['id']??0)||($row['profile_username']??null)!==($profile['username']??null))return null;
    try{
        $binding=vp3_profile_webmcp_binding_v1($row['binding']??null);
        $context=vp3_profile_webmcp_context_for_native_v1($pdo,$profile,$user,['context_id'=>$row['action_context_id']??null,'return_token'=>$row['return_token']??null]);
        if(!$context||$context['binding']!==$binding||($row['conversation_id']??null)!==$binding['conversation_id']||($row['recommended_tools']??null)!==$context['allowed_tools'])return null;
        $returnPath='/chat.php?agent='.($binding['agent_id']>0?(string)$binding['agent_id']:'system').'&conversation_id='.$binding['conversation_id'].'&profile_webmcp_return='.$row['return_token'];
        return ['token'=>$token,'path'=>'/'.rawurlencode((string)$profile['username']).'?webmcp_resume='.$token,'expires_at'=>$row['expires_at'],
            'action_context_id'=>$context['context_id'],'correlation_id'=>$context['context_id'],'return_token'=>$row['return_token'],'return_path'=>$returnPath];
    }catch(Throwable $e){return null;}
}

function vp3_profile_webmcp_resume_consume_v194(PDO $pdo,array $profile,?array $viewer,string $token): ?array
{
    if(!is_array($viewer))return null;
    $resume=vp3_profile_webmcp_resume_validate_v194($profile,$viewer,$token,$pdo);if(!$resume)return null;
    $row=$_SESSION['vp3_profile_webmcp_resume_v194'][$token];
    try{
        $resolution=vp3_profile_webmcp_resolve_capabilities_v190($pdo,$profile,$viewer,['surface'=>'native_profile','strict_observation'=>true]);
        if(($resolution['execution_allowed']??null)!==true||!is_array($resolution['allowed_tools']??null))throw new RuntimeException('Profile handoff surface is unavailable.');
        $allowed=array_fill_keys($resolution['allowed_tools'],true);$catalog=vp3_profile_webmcp_tool_catalog_v100();$tools=[];$caps=[];
        foreach($row['recommended_tools'] as $name){
            if(!isset($allowed[$name],$catalog[$name])||($resolution['capabilities'][$catalog[$name]['capability']]??null)!==true)throw new RuntimeException('Profile handoff tools changed.',403);
            $tool=$catalog[$name];$caps[$tool['capability']]=true;$tools[]=['name'=>$name,'title'=>(string)($tool['title']??$name),'capability'=>$tool['capability'],'read_only'=>!empty($tool['annotations']['readOnlyHint']),'consequential'=>!empty($tool['annotations']['consequentialHint'])];
        }
        $goal=$row['goal']??null;if(!is_string($goal)||!mb_check_encoding($goal,'UTF-8')||mb_strlen($goal)>1000)throw new RuntimeException('Profile handoff goal is invalid.');
        $intent=vp3_profile_webmcp_resolve_intent_v100($goal,['capabilities'=>$resolution['capabilities'],'allowed_tools'=>$resolution['allowed_tools']]);
        if(!vp3_profile_webmcp_resume_validate_v194($profile,$viewer,$token,$pdo))throw new RuntimeException('Profile handoff authority changed.',403);
        // Consume only after the current source and surface have both been verified.
        unset($_SESSION['vp3_profile_webmcp_resume_v194'][$token]);
        return ['contract'=>'vp3.webmcp.resume.v1','continuity_version'=>VP3_PROFILE_WEBMCP_CONTINUITY_V194,'profile_username'=>$profile['username'],'goal'=>$goal,
            'recommended_capabilities'=>array_keys($caps),'recommended_tools'=>$tools,'resolved_capabilities'=>array_values($intent['recommended_capabilities']??[]),
            'action_context_id'=>$resume['action_context_id'],'correlation_id'=>$resume['action_context_id'],'return_token'=>$resume['return_token'],'return_path'=>$resume['return_path'],
            'execution_allowed'=>true,'auto_execute_consequential'=>false,'consumed'=>true];
    }catch(Throwable $e){return null;}
}
