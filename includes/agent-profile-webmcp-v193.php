<?php
declare(strict_types=1);
require_once __DIR__.'/agent-profile-webmcp-read-v1.php';

const VP3_AGENT_PROFILE_WEBMCP_V193='agent-profile-webmcp-v193-20260929';

function vp3_agent_profile_webmcp_intent_v193(string $query): bool
{
    return mb_check_encoding($query,'UTF-8')&&(bool)preg_match('/\b(?:my\s+(?:public\s+)?profile|public\s+profile|profile\s+(?:agent|webmcp)|webmcp|on\s+my\s+profile|through\s+my\s+profile|via\s+my\s+profile)\b/i',$query);
}

function vp3_agent_profile_webmcp_plan_v193(PDO $pdo,string $query,array $user,int $conversationId=0): ?array
{
    if((int)($user['id']??0)<1||!vp3_agent_profile_webmcp_intent_v193($query))return null;
    // Guard the original user text before any private lookup or session mutation.
    if(vp3_profile_webmcp_non_action_query_v1($query))return null;
    if(vp3_profile_webmcp_ability_query_v1($query))return [
        'handled'=>true,'answer'=>'I can inspect your current public Profile capabilities, prepare a handoff when you explicitly ask to open your Profile, and read a returned action’s server-verified status. Consequential actions keep their existing Profile confirmation. No Profile records were read or handoff created.','actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('capabilities.inspect','inspect','read_complete'),
    ];
    try{
        if(!function_exists('vp3_capability_control_principal_v1'))require_once __DIR__.'/agent-capability-chat-v1.php';
        $principal=vp3_capability_control_principal_v1($pdo,$user,$conversationId);$context=['conversation_id'=>$conversationId];
        if(vp3_profile_webmcp_explicit_handoff_v1($query))return vp3_profile_webmcp_handoff_v1($pdo,$user,$principal,['operation'=>'profile_webmcp_handoff','ids'=>['profile.webmcp.handoff'],'query'=>$query],$context,$query);
        return vp3_profile_webmcp_read_v1($pdo,$user,$principal,['operation'=>'profile_webmcp_read','ids'=>['profile.webmcp.read']],$context);
    }catch(Throwable $e){return vp3_profile_webmcp_failure_v1($e);}
}

/** Compatibility entry point; absent trusted invocation context cannot authorize a saved plan. */
function vp3_agent_profile_webmcp_authorize_plan_v193(PDO $pdo,array $plan,array $user,array $context=[],?array $principal=null): ?array
{
    if(!$principal||!array_key_exists('conversation_id',$context))return null;
    $result=vp3_profile_webmcp_authorize_result_v1($pdo,$user,$principal,$context,['profile_webmcp_plan'=>$plan]);
    return is_array($result['profile_webmcp_plan']??null)?$result['profile_webmcp_plan']:null;
}
