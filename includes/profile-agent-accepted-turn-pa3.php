<?php
declare(strict_types=1);

require_once __DIR__.'/profile-agent-boundary-v390.php';
require_once __DIR__.'/profile-agent-public-service-v110.php';

/** Server authority for finishing an admitted turn; never a browser credential. */
const VP3_PROFILE_AGENT_ACCEPTED_TURN_PA3='profile-agent-accepted-turn-pa3';

function vp3_profile_agent_accepted_fingerprint_pa3(array $row,array $keys): string
{
    $values=[];foreach($keys as $key)$values[$key]=(string)($row[$key]??'');
    return hash('sha256',json_encode($values,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function vp3_profile_agent_accepted_snapshot_pa3(array $ctx): array
{
    if(($ctx['conversation_authority']['transport']??null)!=='native')vp3_profile_agent_public_unavailable_v1();
    $revision=(string)($ctx['accepted_visitor_auth_revision']??'');
    $messageHash=(string)($ctx['accepted_message_sha256']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$messageHash)
       ||((int)($ctx['visitor']['id']??0)>0&&!preg_match('/^[a-f0-9]{64}$/D',$revision)))vp3_profile_agent_public_unavailable_v1();
    return [
        'contract'=>'vp3.profile-accepted-turn.pa3',
        'profile_fingerprint'=>vp3_profile_agent_accepted_fingerprint_pa3($ctx['profile'],['user_id','username','display_name','bio','profile_agent_instructions','profile_agent_greeting']),
        'agent_fingerprint'=>vp3_profile_agent_accepted_fingerprint_pa3($ctx['agent'],['id','owner_user_id','display_name','is_active']),
        'session_fingerprint'=>vp3_profile_agent_accepted_fingerprint_pa3($ctx['session'],['id','owner_user_id','session_key']),
        'visitor_auth_revision'=>$revision,
        'message_sha256'=>$messageHash,
    ];
}

/** Load the exact durable admission. current_user(), cookies and CRM identity are not authority here. */
function vp3_profile_agent_accepted_context_pa3(PDO $pdo,array $turn,bool $lock=false): array
{
    if(!function_exists('vp3_profile_handoff_row_pa3'))require_once __DIR__.'/profile-agent-handoff-pa3.php';
    $fresh=vp3_profile_handoff_row_pa3($pdo,(int)($turn['id']??0),$lock);
    if(!$fresh)vp3_profile_agent_public_unavailable_v1();
    foreach(['id','owner_user_id','profile_agent_id','profile_session_id','conversation_id','visitor_message_id','visitor_user_id','version'] as $key){
        if(!array_key_exists($key,$turn)||(int)$turn[$key]!== (int)($fresh[$key]??-1))vp3_profile_agent_public_unavailable_v1();
    }
    foreach(['transport','credential_hash','accepted_json'] as $key){
        if(!is_string($turn[$key]??null)||!is_string($fresh[$key]??null)||!hash_equals($fresh[$key],$turn[$key]))vp3_profile_agent_public_unavailable_v1();
    }
    if($fresh['transport']!=='native'||strlen($fresh['accepted_json'])>4096)vp3_profile_agent_public_unavailable_v1();
    $snapshot=json_decode($fresh['accepted_json'],true);
    if(!is_array($snapshot)||($snapshot['contract']??null)!=='vp3.profile-accepted-turn.pa3')vp3_profile_agent_public_unavailable_v1();
    foreach(['profile_fingerprint','agent_fingerprint','session_fingerprint','message_sha256'] as $field){
        if(!is_string($snapshot[$field]??null)||!preg_match('/^[a-f0-9]{64}$/D',$snapshot[$field]))vp3_profile_agent_public_unavailable_v1();
    }
    $owner=(int)$fresh['owner_user_id'];$agentId=(int)$fresh['profile_agent_id'];$visitorId=(int)$fresh['visitor_user_id'];
    if($owner<1||$agentId<1||$visitorId<0||$visitorId===$owner)vp3_profile_agent_public_unavailable_v1();
    $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT p.*,u.display_name,u.role,u.is_active,u.avatar_path FROM user_profiles p INNER JOIN users u ON u.id=p.user_id WHERE p.user_id=? LIMIT 1'.$suffix);
    $q->execute([$owner]);$profile=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=? LIMIT 1'.$suffix);$q->execute([$agentId,$owner]);$agent=$q->fetch(PDO::FETCH_ASSOC);
    $ownerUser=profile_user_row($pdo,$owner);
    if(!$profile||!$ownerUser||empty($ownerUser['is_active'])||empty($profile['is_active'])||empty($profile['is_public'])
       ||empty($profile['profile_agent_enabled'])||(int)($profile['profile_agent_id']??0)!==$agentId||!$agent||empty($agent['is_active']))vp3_profile_agent_public_unavailable_v1();
    if(!function_exists('vp3_observation_personal_permission_v1'))require_once __DIR__.'/agent-observation-authority-v1.php';
    if(!vp3_observation_personal_permission_v1($pdo,$ownerUser,'profile_agent.access')
       ||!vp3_observation_personal_permission_v1($pdo,$ownerUser,'profile_chat.access'))vp3_profile_agent_public_unavailable_v1();
    $visitor=null;$visitorRevision='';
    if($visitorId>0){
        $q=$pdo->prepare('SELECT id,display_name,role,is_active,avatar_path,password_hash FROM users WHERE id=? LIMIT 1'.$suffix);
        $q->execute([$visitorId]);$member=$q->fetch(PDO::FETCH_ASSOC);
        if(!$member||empty($member['is_active'])||!is_string($member['password_hash']??null))vp3_profile_agent_public_unavailable_v1();
        $visitorRevision=hash('sha256',(string)$visitorId.'|'.$member['password_hash']);
        unset($member['password_hash']);$visitor=$member;
    }
    if(!is_string($snapshot['visitor_auth_revision']??null)||!hash_equals($snapshot['visitor_auth_revision'],$visitorRevision))vp3_profile_agent_public_unavailable_v1();
    $q=$pdo->prepare('SELECT * FROM profile_visit_sessions WHERE id=? AND owner_user_id=? LIMIT 1'.$suffix);
    $q->execute([(int)$fresh['profile_session_id'],$owner]);$session=$q->fetch(PDO::FETCH_ASSOC);
    if(!$session)vp3_profile_agent_public_unavailable_v1();
    $authority=['contract'=>'vp3.profile-conversation-authority.v1','transport'=>'native','owner_user_id'=>$owner,'visitor_user_id'=>$visitorId,'credential_hash'=>$fresh['credential_hash']];
    $conversation=vp3_profile_agent_public_conversation_v390($pdo,(int)$fresh['conversation_id'],$owner,$agentId,(int)$fresh['profile_session_id'],$lock,$authority);
    if(!$conversation||!vp3_profile_agent_agent_may_reply_v390($conversation))vp3_profile_agent_public_unavailable_v1();
    $q=$pdo->prepare("SELECT id,conversation_id,sender_type,sender_user_id,message,context_json FROM profile_agent_messages WHERE id=? AND conversation_id=? LIMIT 1".$suffix);
    $q->execute([(int)$fresh['visitor_message_id'],(int)$fresh['conversation_id']]);$message=$q->fetch(PDO::FETCH_ASSOC);
    if(!$message||$message['sender_type']!=='visitor'||(int)($message['sender_user_id']??0)!==$visitorId
       ||!is_string($message['message'])||!hash_equals($snapshot['message_sha256'],hash('sha256',$message['message'])))vp3_profile_agent_public_unavailable_v1();
    if($message['context_json']===VP3_PROFILE_CHAT_ATTACHMENT_CONTEXT_V1)vp3_profile_agent_public_unavailable_v1();
    unset($message['context_json']);
    $ctx=['owner_user_id'=>$owner,'owner_user'=>$ownerUser,'profile'=>$profile,'agent'=>$agent,'agent_id'=>$agentId,
        'visitor'=>$visitor,'session'=>$session,'session_id'=>(int)$fresh['profile_session_id'],'conversation_authority'=>$authority,
        'accepted_visitor_auth_revision'=>$visitorRevision,'accepted_message_sha256'=>$snapshot['message_sha256'],
        'conversation'=>$conversation,'visitor_message'=>$message,'turn_id'=>(int)$fresh['id']];
    $current=vp3_profile_agent_accepted_snapshot_pa3($ctx);
    foreach(['profile_fingerprint','agent_fingerprint','session_fingerprint'] as $key){if(!hash_equals($snapshot[$key],$current[$key]))vp3_profile_agent_public_unavailable_v1();}
    return $ctx;
}

/** Inference only. The durable executor owns the publication transaction and receipt. */
function vp3_profile_agent_accepted_generate_pa3(PDO $pdo,array $turn,array $claim,?callable $infer=null): array
{
    $ctx=vp3_profile_agent_accepted_context_pa3($pdo,$turn);
    $owner=(int)$ctx['owner_user_id'];$profile=$ctx['profile'];$agent=$ctx['agent'];$visitor=$ctx['visitor'];$ownerUser=$ctx['owner_user'];
    $query=(string)$ctx['visitor_message']['message'];$cid=(int)$turn['conversation_id'];
    $historical=vp3_profile_agent_public_history_v1($pdo,$ctx,$cid,(int)$turn['visitor_message_id']);
    $history=$historical['messages'];$historySources=$historical['source_sets'];
    $context=profile_agent_context($pdo,$profile,$agent,$visitor,$query);
    if(function_exists('profile_agent_transcript_brain_context_v255'))foreach(profile_agent_transcript_brain_context_v255($pdo,$ownerUser,$agent,$visitor,$query,$cid) as $item)$context[]=$item;
    if(function_exists('vp3_profile_webmcp_agent_capability_context_v190')){
        $capability=vp3_profile_webmcp_agent_capability_context_v190($pdo,$profile,$visitor);
        if(!empty($capability['capabilities']))$context[]=$capability;
    }
    $context=array_slice($context,0,24);
    if(!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$context,$historySources))vp3_profile_agent_public_unavailable_v1();
    vp3_profile_agent_accepted_context_pa3($pdo,$turn);
    $sourceDependencies=vp3_profile_agent_public_source_dependencies_v1($context,$historySources);
    $profileHome=['attempted'=>false,'success'=>false,'execution'=>null,'failure_class'=>'none'];$mode='model';$needsOwner=false;
    $control=['surface'=>'profile_agent','profile_owner_user_id'=>$owner,'profile_agent_id'=>(int)$ctx['agent_id'],
        'profile_agent_name'=>(string)$agent['display_name'],'profile_owner_name'=>(string)($profile['display_name']??$ownerUser['display_name']??''),
        'profile_instructions'=>(string)($profile['profile_agent_instructions']??'')];
    $greeting=(bool)preg_match('/^(?:hi|hello|hey|good\s+(?:morning|afternoon|evening))[!.\s]*$/i',$query);
    if($greeting&&trim((string)($profile['profile_agent_greeting']??''))!==''){
        $answer=(string)$profile['profile_agent_greeting'];$mode='configured_greeting';
    }else{
        $home=$infer===null&&function_exists('homeserver_profile_v235_answer')?homeserver_profile_v235_answer($owner,$query,$history,$context):null;
        if($infer===null&&function_exists('homeserver_profile_v235_last'))$profileHome=homeserver_profile_v235_last();
        if(is_array($home)&&trim((string)($home['answer']??''))!==''){$answer=trim((string)$home['answer']);$mode='homeserver_model';}
        else{
            if(!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$context,$historySources))vp3_profile_agent_public_unavailable_v1();
            vp3_profile_agent_accepted_context_pa3($pdo,$turn);
            $generated=$infer?$infer($query,$history,$context,$ownerUser,'profile_agent',$control):ai_generate_chat_response($query,$history,$context,$ownerUser,'profile_agent',$control);
            $answer=is_array($generated)&&!empty($generated['ok'])&&is_string($generated['answer']??null)?trim($generated['answer']):'';
            if($answer===''){
                $needsOwner=true;$mode=!empty($generated['quota_exhausted'])?'quota_handoff':'owner_handoff';
                $answer=($mode==='quota_handoff'?'This Profile Agent has reached its AI usage allowance. ':'I could not complete that reply right now. ')
                    .'I’ve asked '.(string)($profile['display_name']??'the profile owner').' for input rather than guessing.';
            }
        }
    }
    if(mb_strlen($answer)>20000)vp3_profile_agent_public_unavailable_v1();
    $sources=[];foreach($context as $item){if(!in_array((string)$item['source'],['profile:identity','profile:rules'],true))$sources[]=['source'=>(string)$item['source'],'title'=>(string)($item['title']??'')];}
    $messageContext=['sources'=>$sources,'source_dependencies'=>$sourceDependencies,'answer_mode'=>$mode,'handoff_turn_id'=>(int)$turn['id'],'visitor_message_id'=>(int)$turn['visitor_message_id']];
    if(!$needsOwner&&$mode!=='configured_greeting'){
        require_once __DIR__.'/profile-product-promotion-v1.php';
        $promotionKey=profile_product_promotion_match_v1($pdo,$profile,$query,$context);
        if($promotionKey!==null)$messageContext['product_promotion_key']=$promotionKey;
    }
    if(!empty($profileHome['attempted']))$messageContext['homeserver_compute']=[
        'success'=>!empty($profileHome['success']),'failure_class'=>mb_strimwidth(trim((string)($profileHome['failure_class']??'none')),0,80,''),
        'provider'=>mb_strimwidth(trim((string)($profileHome['provider']??'')),0,80,''),'model'=>mb_strimwidth(trim((string)($profileHome['model']??'')),0,160,''),
        'execution'=>is_array($profileHome['execution']??null)?$profileHome['execution']:null];
    $result=['answer'=>$answer,'context'=>$context,'history_sources'=>$historySources,'message_context'=>$messageContext,'needs_owner'=>$needsOwner,'answer_mode'=>$mode];
    vp3_profile_agent_accepted_recheck_pa3($pdo,$turn,$result,false);
    return $result;
}

/** Re-read source policy, then exact authority again so policy reads cannot open a late race. */
function vp3_profile_agent_accepted_recheck_pa3(PDO $pdo,array $turn,array $generated,bool $lock=true): array
{
    $ctx=vp3_profile_agent_accepted_context_pa3($pdo,$turn,$lock);
    if(!is_array($generated['context']??null)||!is_array($generated['history_sources']??null)
       ||!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$generated['context'],$generated['history_sources']))vp3_profile_agent_public_unavailable_v1();
    return vp3_profile_agent_accepted_context_pa3($pdo,$turn,$lock);
}
