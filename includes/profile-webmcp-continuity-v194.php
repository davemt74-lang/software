<?php
declare(strict_types=1);

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
        if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/',$token)||!is_array($row))continue;
        if((int)($row['expires_at']??0)<=$now)continue;
        $clean[$token]=$row;
    }
    if(count($clean)>VP3_PROFILE_WEBMCP_RESUME_MAX_V194){
        uasort($clean,static fn(array $a,array $b):int=>(int)($b['created_at']??0)<=>(int)($a['created_at']??0));
        $clean=array_slice($clean,0,VP3_PROFILE_WEBMCP_RESUME_MAX_V194,true);
    }
    return $clean;
}

function vp3_profile_webmcp_resume_issue_v194(PDO $pdo,array $profile,array $user,string $goal,array $plan): array
{
    $userId=(int)($user['id']??0);
    if($userId<1||(int)($profile['user_id']??0)!==$userId)throw new RuntimeException('Profile resume owner mismatch.');
    if(empty($profile['username'])||empty($profile['is_public'])||empty($profile['is_active']))throw new RuntimeException('Profile resume is unavailable.');

    $now=time();
    $rows=vp3_profile_webmcp_resume_prune_v194(vp3_profile_webmcp_resume_store_v194(),$now);
    $token=bin2hex(random_bytes(16));
    $safeGoal=mb_strimwidth(trim($goal),0,1000,'');
    $rows[$token]=[
        'version'=>VP3_PROFILE_WEBMCP_CONTINUITY_V194,
        'profile_user_id'=>$userId,
        'profile_username'=>(string)$profile['username'],
        'goal'=>$safeGoal,
        'recommended_capabilities'=>array_values(array_filter(array_map('strval',(array)($plan['recommended_capabilities']??[])))),
        'recommended_tools'=>array_values(array_filter(array_map(
            static fn($row):string=>is_array($row)?(string)($row['name']??''):'',
            (array)($plan['recommended_tools']??[])
        ))),
        'created_at'=>$now,
        'expires_at'=>$now+VP3_PROFILE_WEBMCP_RESUME_TTL_V194,
    ];
    $_SESSION['vp3_profile_webmcp_resume_v194']=vp3_profile_webmcp_resume_prune_v194($rows,$now);

    return [
        'token'=>$token,
        'path'=>'/'.rawurlencode((string)$profile['username']).'?webmcp_resume='.$token,
        'expires_at'=>$now+VP3_PROFILE_WEBMCP_RESUME_TTL_V194,
    ];
}

function vp3_profile_webmcp_resume_consume_v194(PDO $pdo,array $profile,?array $viewer,string $token): ?array
{
    $token=strtolower(trim($token));
    if(!preg_match('/^[a-f0-9]{32}$/',$token))return null;
    $viewerId=(int)($viewer['id']??0);
    $profileUserId=(int)($profile['user_id']??0);
    if($viewerId<1||$viewerId!==$profileUserId)return null;

    $now=time();
    $rows=vp3_profile_webmcp_resume_prune_v194(vp3_profile_webmcp_resume_store_v194(),$now);
    $row=$rows[$token]??null;
    unset($rows[$token]);
    $_SESSION['vp3_profile_webmcp_resume_v194']=$rows;
    if(!is_array($row))return null;
    if((int)($row['profile_user_id']??0)!==$viewerId)return null;
    if(!hash_equals((string)($profile['username']??''),(string)($row['profile_username']??'')))return null;

    $resolution=vp3_profile_webmcp_resolve_capabilities_v190($pdo,$profile,$viewer,['surface'=>'native_profile']);
    $allowed=array_fill_keys($resolution['allowed_tools'],true);
    $catalog=vp3_profile_webmcp_tool_catalog_v100();

    $caps=[];
    foreach((array)($row['recommended_capabilities']??[]) as $capability){
        $capability=(string)$capability;
        if($capability!==''&&!empty($resolution['capabilities'][$capability]))$caps[$capability]=true;
    }

    $tools=[];
    foreach((array)($row['recommended_tools']??[]) as $name){
        $name=(string)$name;
        if($name===''||!isset($allowed[$name])||!isset($catalog[$name]))continue;
        $tool=$catalog[$name];
        $tools[]=[
            'name'=>$name,
            'title'=>(string)($tool['title']??$name),
            'capability'=>(string)($tool['capability']??''),
            'read_only'=>!empty($tool['annotations']['readOnlyHint']),
            'consequential'=>!empty($tool['annotations']['consequentialHint']),
        ];
    }

    $goal=mb_strimwidth(trim((string)($row['goal']??'')),0,1000,'');
    $intent=$goal!==''?vp3_profile_webmcp_resolve_intent_v100($goal,[
        'capabilities'=>$resolution['capabilities'],
        'allowed_tools'=>$resolution['allowed_tools'],
    ]):['recommended_capabilities'=>[]];

    return [
        'contract'=>'vp3.webmcp.resume.v1',
        'continuity_version'=>VP3_PROFILE_WEBMCP_CONTINUITY_V194,
        'profile_username'=>(string)$profile['username'],
        'goal'=>$goal,
        'recommended_capabilities'=>array_values(array_keys($caps)),
        'recommended_tools'=>$tools,
        'resolved_capabilities'=>array_values($intent['recommended_capabilities']??[]),
        'execution_allowed'=>true,
        'auto_execute_consequential'=>false,
        'consumed'=>true,
    ];
}
