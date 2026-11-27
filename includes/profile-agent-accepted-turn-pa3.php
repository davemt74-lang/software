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
function vp3_profile_agent_accepted_context_pa3(PDO $pdo,array $turn,bool $lock=false,bool $ownerDraft=false): array
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
    if(!$conversation)vp3_profile_agent_public_unavailable_v1();
    if($ownerDraft){
        // Private preparation uses the same exact admission, without obtaining
        // or changing the public Agent responder lease. The owner must already
        // hold this current turn and the normal public authority still applies.
        $thread=vp3_profile_handoff_thread_pa3($pdo,$owner,(int)$fresh['conversation_id'],$lock);
        $latest=$pdo->prepare('SELECT id FROM profile_agent_handoff_turns_pa3 WHERE owner_user_id=? AND conversation_id=? ORDER BY visitor_message_id DESC,id DESC LIMIT 1'.$suffix);
        $latest->execute([$owner,(int)$fresh['conversation_id']]);
        if(!$thread||$thread['mode']!=='owner'||$conversation['status']!=='owner_joined'
            ||(int)$latest->fetchColumn()!==(int)$fresh['id']||(int)$thread['epoch']!==(int)$fresh['conversation_epoch']
            ||!in_array($fresh['state'],['owner_claimed','replied'],true))vp3_profile_agent_public_unavailable_v1();
        require_once __DIR__.'/profile-calls-pa3c.php';
        vp3_profile_call_block_check_pa3c($pdo,$owner,$visitorId,$lock);
    }elseif(!vp3_profile_agent_agent_may_reply_v390($conversation))vp3_profile_agent_public_unavailable_v1();
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
    require_once __DIR__.'/profile-image-edit-v1.php';
    if(vp3_profile_image_edit_ready_v1($pdo)&&vp3_profile_image_edit_row_v1($pdo,(int)$turn['visitor_message_id']))return vp3_profile_image_edit_generate_v1($pdo,$turn,$claim,$infer);
    require_once __DIR__.'/profile-agent-answer-v1.php';
    require_once __DIR__.'/profile-agent-responses-v1.php';
    $owner=(int)$ctx['owner_user_id'];$profile=$ctx['profile'];$agent=$ctx['agent'];$visitor=$ctx['visitor'];$ownerUser=$ctx['owner_user'];
    $query=(string)$ctx['visitor_message']['message'];$cid=(int)$turn['conversation_id'];
    $policy=vp3_profile_response_settings_v1($pdo,$owner);
    $context=[];$historySources=[];
    if($policy['mode']==='user_responses'){
        $generated=vp3_profile_answer_owner_v1('user_responses',(int)$policy['revision']);
    }else{
        $historical=vp3_profile_agent_public_history_v1($pdo,$ctx,$cid,(int)$turn['visitor_message_id']);
        $history=$historical['messages'];$historySources=$historical['source_sets'];
        $context=profile_agent_context($pdo,$profile,$agent,$visitor,$query);
        require_once __DIR__.'/profile-agent-transcription-context.php';
        foreach(profile_agent_transcript_brain_context_v255($pdo,$ownerUser,$agent,$visitor,$query,$cid) as $item)$context[]=$item;
        if(function_exists('vp3_profile_webmcp_agent_capability_context_v190')){
            $capability=vp3_profile_webmcp_agent_capability_context_v190($pdo,$profile,$visitor);
            if(!empty($capability['capabilities']))$context[]=$capability;
        }
        $context=array_slice($context,0,24);
        $guard=static function()use($pdo,$ctx,$context,$historySources,$turn):void{
            if(!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$context,$historySources))vp3_profile_agent_public_unavailable_v1();
            vp3_profile_agent_accepted_context_pa3($pdo,$turn);
        };
        $generated=vp3_profile_answer_generate_v1($pdo,$ctx,$query,$history,$context,$guard,$infer);
        // A submission receipt discloses no model answer or retrieved material.
        if($generated['needs_owner']){$context=[];$historySources=[];}
    }
    $sources=[];foreach($context as $item){if(!in_array((string)$item['source'],['profile:identity','profile:rules'],true))$sources[]=['source'=>(string)$item['source'],'title'=>(string)($item['title']??'')];}
    $messageContext=['sources'=>$sources,'source_dependencies'=>vp3_profile_agent_public_source_dependencies_v1($context,$historySources),
        'answer_mode'=>$generated['answer_mode'],'handoff_turn_id'=>(int)$turn['id'],'visitor_message_id'=>(int)$turn['visitor_message_id']];
    if(!$generated['needs_owner']&&$generated['answer_mode']!=='configured_greeting'){
        require_once __DIR__.'/profile-product-promotion-v1.php';
        $promotionKey=profile_product_promotion_match_v1($pdo,$profile,$query,$context);
        if($promotionKey!==null)$messageContext['product_promotion_key']=$promotionKey;
    }
    $profileHome=$generated['homeserver_compute'];
    if(!empty($profileHome['attempted']))$messageContext['homeserver_compute']=[
        'success'=>!empty($profileHome['success']),'failure_class'=>mb_strimwidth(trim((string)($profileHome['failure_class']??'none')),0,80,''),
        'provider'=>mb_strimwidth(trim((string)($profileHome['provider']??'')),0,80,''),'model'=>mb_strimwidth(trim((string)($profileHome['model']??'')),0,160,''),
        'execution'=>is_array($profileHome['execution']??null)?$profileHome['execution']:null];
    $result=$generated+['context'=>$context,'history_sources'=>$historySources,'message_context'=>$messageContext];
    vp3_profile_agent_accepted_recheck_pa3($pdo,$turn,$result,false);
    return $result;
}

/** Re-read source policy, then exact authority again so policy reads cannot open a late race. */
function vp3_profile_agent_accepted_recheck_pa3(PDO $pdo,array $turn,array $generated,bool $lock=true): array
{
    $ctx=vp3_profile_agent_accepted_context_pa3($pdo,$turn,$lock);
    if(isset($generated['message_context']['profile_image_request']))vp3_profile_image_edit_recheck_v1($pdo,$turn,$generated,$lock);
    if(!is_array($generated['context']??null)||!is_array($generated['history_sources']??null)
       ||!vp3_profile_agent_public_sources_current_v1($pdo,$ctx,$generated['context'],$generated['history_sources']))vp3_profile_agent_public_unavailable_v1();
    return vp3_profile_agent_accepted_context_pa3($pdo,$turn,$lock);
}
