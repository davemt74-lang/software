<?php
declare(strict_types=1);
require_once __DIR__.'/agent-observation-authority-v1.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

const VP3_PROFILE_WEBMCP_OWNER_CONTEXT_V1='vp3.webmcp.owner-context.v1';
const VP3_PROFILE_WEBMCP_AGENT_PLAN_V1='vp3.profile.webmcp.agent-plan.v1';

function vp3_profile_webmcp_plan_v1(array $plan): array
{
    $caps=['profile_webmcp_read'=>'profile.webmcp.read','profile_webmcp_handoff'=>'profile.webmcp.handoff','profile_webmcp_return_read'=>'profile.webmcp.return.read'];
    $op=$plan['operation']??null;
    if(!is_string($op)||!isset($caps[$op])||($plan['ids']??null)!==[$caps[$op]])throw new InvalidArgumentException('Invalid Profile tool plan.');
    $allowed=['operation','ids'];if($op==='profile_webmcp_handoff')$allowed[]='query';
    if(array_diff(array_keys($plan),$allowed))throw new InvalidArgumentException('Unsupported Profile selector.');
    $out=['operation'=>$op,'ids'=>[$caps[$op]]];
    if($op==='profile_webmcp_handoff'){
        $q=$plan['query']??null;
        if(!is_string($q)||!mb_check_encoding($q,'UTF-8')||mb_strlen(trim($q))<3||mb_strlen($q)>1000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$q))throw new InvalidArgumentException('Invalid Profile goal.');
        $out['query']=trim($q);
    }
    return $out;
}

function vp3_profile_webmcp_schema_ready_v1(PDO $pdo): bool
{
    try{$pdo->query('SELECT user_id,username,is_public FROM user_profiles WHERE 1=0');return true;}catch(Throwable $e){return false;}
}

function vp3_profile_webmcp_agent_runtime_v1(bool $continuity=false,bool $outcomes=false): void
{
    if(!function_exists('vp3_profile_webmcp_tool_catalog_v100'))require_once __DIR__.'/profile-webmcp-v100.php';
    if($continuity&&!function_exists('vp3_profile_webmcp_resume_issue_v194'))require_once __DIR__.'/profile-webmcp-continuity-v194.php';
    if($outcomes&&!function_exists('vp3_profile_webmcp_outcome_read_v1'))require_once __DIR__.'/agent-profile-webmcp-outcomes-v1.php';
}

function vp3_profile_webmcp_binding_v1(mixed $binding): array
{
    if(!is_array($binding)||array_diff(array_keys($binding),['contract','user_id','agent_id','conversation_id'])||
        ($binding['contract']??null)!==VP3_PROFILE_WEBMCP_OWNER_CONTEXT_V1)throw new RuntimeException('Profile context binding is unavailable.',403);
    foreach(['user_id','agent_id','conversation_id'] as $key)if(!is_int($binding[$key]??null)||$binding[$key]<($key==='user_id'?1:0))throw new RuntimeException('Profile context binding is invalid.',403);
    return $binding;
}

function vp3_profile_webmcp_make_binding_v1(array $user,array $principal,array $context): array
{
    $cid=$context['conversation_id']??0;
    if(!is_int($cid)||$cid<0)throw new RuntimeException('Current conversation is unavailable.',403);
    $kind=$principal['kind']??'system';if(!in_array($kind,['system','user_agent'],true))throw new RuntimeException('Owner Chat is required.',403);
    $aid=$kind==='user_agent'?(int)($principal['agent_id']??0):0;
    if($kind==='user_agent'&&$aid<1)throw new RuntimeException('Current Agent is unavailable.',403);
    return vp3_profile_webmcp_binding_v1(['contract'=>VP3_PROFILE_WEBMCP_OWNER_CONTEXT_V1,'user_id'=>(int)($user['id']??0),'agent_id'=>$aid,'conversation_id'=>$cid]);
}

/** Profile lookup is explicitly read-only; absent profiles are not created as a side effect. */
function vp3_profile_webmcp_owner_profile_v1(PDO $pdo,int $uid): ?array
{
    if(!function_exists('profile_for_user'))require_once __DIR__.'/profile-agent.php';
    $profile=profile_for_user($pdo,$uid,false);if(!$profile)return null;
    if((int)($profile['user_id']??0)!==$uid)throw new RuntimeException('Profile ownership changed.',403);
    $name=$profile['username']??'';if($name===null)$name='';
    if(!is_string($name)||!mb_check_encoding($name,'UTF-8')||strlen($name)>80)throw new RuntimeException('Profile identity is invalid.');
    foreach(['is_public','is_active'] as $field)if(!in_array($profile[$field]??null,[0,1,'0','1'],true))throw new RuntimeException('Profile availability is invalid.');
    $profile['username']=$name;return $profile;
}

function vp3_profile_webmcp_authority_v1(PDO $pdo,array $user,array $principal,array $context,bool $public=false): array
{
    $binding=vp3_profile_webmcp_make_binding_v1($user,$principal,$context);
    $live=vp3_observation_authority_v1($pdo,$user,$principal,$context,static fn(array $fresh):bool=>vp3_observation_personal_permission_v1($pdo,$fresh,'profile_agent.access'));
    if(!vp3_profile_webmcp_schema_ready_v1($pdo))throw new RuntimeException('Profile storage is unavailable.',409);
    $profile=vp3_profile_webmcp_owner_profile_v1($pdo,$binding['user_id']);
    if($public&&(!$profile||$profile['username']===''||empty($profile['is_public'])||empty($profile['is_active'])))throw new RuntimeException('An active public owned Profile is required.',403);
    // Finish scope lookup with live account/Agent/permission checks: a Profile query may race a revocation.
    $live=vp3_observation_authority_v1($pdo,$user,$principal,$context,static fn(array $fresh):bool=>vp3_observation_personal_permission_v1($pdo,$fresh,'profile_agent.access'));
    if($binding['conversation_id']>0){
        $q=$pdo->prepare('SELECT user_id,user_agent_id FROM chat_conversations WHERE id=?');$q->execute([$binding['conversation_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['user_id']!==$binding['user_id']||(int)($row['user_agent_id']??0)!==$binding['agent_id'])throw new RuntimeException('Profile conversation authority changed.',403);
    }
    return ['user'=>$live,'profile'=>$profile,'binding'=>$binding];
}

function vp3_profile_webmcp_profile_identity_v1(?array $profile): array
{
    return $profile?[(int)$profile['user_id'],(string)$profile['username'],(int)$profile['is_public'],(int)$profile['is_active']]:[];
}

/** Recheck an internal session row; no browser-supplied identity is an authority. */
function vp3_profile_webmcp_context_live_v1(PDO $pdo,array $profile,array $user,array $row,bool $allowReturned=false): array
{
    $id=$row['context_id']??null;$now=time();
    if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Profile context is invalid.',403);
    $current=$_SESSION['vp3_profile_webmcp_action_context_v195'][$id]??null;
    if(!is_array($current))throw new RuntimeException('Profile context is unavailable.',403);
    foreach(['context_id','profile_user_id','profile_username','binding','allowed_tools','created_at','expires_at','return_token_hash','returned_at'] as $key)if(!array_key_exists($key,$row)||($current[$key]??null)!==$row[$key])throw new RuntimeException('Profile context changed.',403);
    if(($current['server_action']??null)!==($row['server_action']??null))throw new RuntimeException('Profile server evidence changed.',403);
    $binding=vp3_profile_webmcp_binding_v1($current['binding']??null);
    foreach(['created_at','expires_at','returned_at'] as $key)if(!is_int($current[$key]??null))throw new RuntimeException('Profile context time is invalid.',403);
    if($current['created_at']<1||$current['created_at']>$now+5||$current['expires_at']<=$now||$current['expires_at']>$current['created_at']+3600||
        $current['returned_at']<0||$current['returned_at']>$now+5||($current['returned_at']>0&&$current['returned_at']<$current['created_at'])||(!$allowReturned&&$current['returned_at']!==0))throw new RuntimeException('Profile context expired or was consumed.',403);
    if(!is_int($current['profile_user_id']??null)||$current['profile_user_id']!==$binding['user_id']||$binding['user_id']!==(int)($user['id']??0)||
        (int)($profile['user_id']??0)!==$binding['user_id']||!is_string($current['profile_username']??null)||!hash_equals($current['profile_username'],(string)($profile['username']??'')))throw new RuntimeException('Profile context belongs to another owner.',403);
    $tools=$current['allowed_tools']??null;
    if(!is_array($tools)||!array_is_list($tools)||count($tools)>128)throw new RuntimeException('Profile context tools are invalid.',403);
    foreach($tools as $tool)if(!is_string($tool)||!preg_match('/^vp3\.[a-z0-9_.]{1,110}$/D',$tool))throw new RuntimeException('Profile context tool is invalid.',403);
    if(count(array_unique($tools))!==count($tools))throw new RuntimeException('Profile context tools are duplicated.',403);
    $principal=['kind'=>$binding['agent_id']>0?'user_agent':'system','owner_user_id'=>$binding['user_id'],'agent_id'=>$binding['agent_id']];
    $live=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,['conversation_id'=>$binding['conversation_id']],true);
    if(vp3_profile_webmcp_profile_identity_v1($live['profile'])!==vp3_profile_webmcp_profile_identity_v1($profile))throw new RuntimeException('Profile availability changed.',403);
    return $current;
}

/** Empty wire preserves ordinary public requests; supplied invalid wire must never downgrade. */
function vp3_profile_webmcp_context_for_native_v1(PDO $pdo,array $profile,array $user,array $wire): ?array
{
    if($wire===[])return null;
    if(array_diff(array_keys($wire),['context_id','return_token'])||count($wire)!==2)throw new RuntimeException('Profile continuity proof is invalid.',403);
    foreach(['context_id','return_token'] as $key)if(!is_string($wire[$key]??null)||!preg_match('/^[a-f0-9]{32}$/D',$wire[$key]))throw new RuntimeException('Profile continuity proof is invalid.',403);
    $row=$_SESSION['vp3_profile_webmcp_action_context_v195'][$wire['context_id']]??null;
    if(!is_array($row)||!is_string($row['return_token_hash']??null)||!hash_equals($row['return_token_hash'],hash('sha256',$wire['return_token'])))throw new RuntimeException('Profile continuity proof is unavailable.',403);
    return vp3_profile_webmcp_context_live_v1($pdo,$profile,$user,$row);
}

function vp3_profile_webmcp_context_store_server_v1(PDO $pdo,array $profile,array $user,array $expectedRow,array $patch): array
{
    $row=vp3_profile_webmcp_context_live_v1($pdo,$profile,$user,$expectedRow);
    if(($row['server_action']??null)!==($expectedRow['server_action']??null)||array_diff(array_keys($patch),['server_action','server_outcome'])||count($patch)!==2)throw new RuntimeException('Profile server evidence changed.');
    vp3_profile_webmcp_agent_runtime_v1(false,true);
    if(!is_array($patch['server_action']??null)||!is_array($patch['server_outcome']??null))throw new RuntimeException('Profile server evidence is invalid.');
    $action=vp3_profile_webmcp_action_binding_v1($patch['server_action']);$outcome=vp3_profile_webmcp_outcome_projection_v1($patch['server_outcome']);
    if(($action['owner_user_id']??null)!==$row['profile_user_id']||($action['profile_username']??null)!==$row['profile_username']||($action['surface']??null)!=='native_profile'||($action['property_id']??null)!==0||
        ($outcome['operation']??null)!==($action['operation']??null)||!in_array($action['prepare_tool']??null,$row['allowed_tools'],true)||!in_array($action['confirm_tool']??null,$row['allowed_tools'],true))throw new RuntimeException('Profile evidence does not match its context.');
    $row['server_action']=$action;$row['server_outcome']=$outcome;$row['updated_at']=time();
    $_SESSION['vp3_profile_webmcp_action_context_v195'][$row['context_id']]=$row;
    return $row;
}

function vp3_profile_webmcp_failure_v1(Throwable $e,string $cap='profile.webmcp.read'): array
{
    $op=match($cap){'profile.webmcp.handoff'=>'prepare','profile.webmcp.return.read'=>'read',default=>'inspect'};
    $reason=$e->getCode()===403?'permission_denied':($e->getCode()===409?'upgrade_required':($e instanceof InvalidArgumentException?'invalid_plan':'execution_failed'));
    return ['handled'=>true,'answer'=>$reason==='permission_denied'?'Current owner, Agent, conversation or Profile permissions do not permit this Profile operation.':($reason==='upgrade_required'?'The existing Profile storage is unavailable. No Profile capability or action outcome was established.':'The Profile request could not be verified. No action outcome or new handoff was established.'),'actions'=>[],'sources'=>[],'profile_webmcp_plan'=>null,'profile_webmcp_return'=>null,
        'tool_receipt'=>vp3_tool_receipt_v1($cap,$op,$reason==='permission_denied'?'blocked':'failed',$reason)];
}

function vp3_profile_webmcp_resolution_v1(PDO $pdo,array $profile,array $user): array
{
    vp3_profile_webmcp_agent_runtime_v1();
    $resolution=vp3_profile_webmcp_resolve_capabilities_v190($pdo,$profile,$user,['surface'=>'agent_brain','strict_observation'=>true]);
    if(($resolution['surface']??null)!=='agent_brain'||($resolution['execution_allowed']??null)!==false||!is_array($resolution['capabilities']??null)||!is_array($resolution['allowed_tools']??null)||!array_is_list($resolution['allowed_tools'])||count($resolution['allowed_tools'])>128)throw new RuntimeException('Profile capability evidence is invalid.');
    $catalog=vp3_profile_webmcp_tool_catalog_v100();$registry=vp3_profile_webmcp_capability_registry_v190();$tools=[];
    foreach($resolution['capabilities'] as $cap=>$enabled)if(!isset($registry[$cap])||!is_bool($enabled))throw new RuntimeException('Profile capability evidence is malformed.');
    foreach($resolution['allowed_tools'] as $name){
        if(!is_string($name)||!isset($catalog[$name])||isset($tools[$name]))throw new RuntimeException('Profile tool evidence is malformed.');
        $tool=$catalog[$name];$cap=$tool['capability']??'';
        if(($resolution['capabilities'][$cap]??false)!==true)throw new RuntimeException('Profile tool capability is unavailable.');
        $tools[$name]=['name'=>$name,'capability'=>$cap,'read_only'=>!empty($tool['annotations']['readOnlyHint']),'consequential'=>!empty($tool['annotations']['consequentialHint'])];
    }
    return $resolution+['tools'=>array_values($tools)];
}

function vp3_profile_webmcp_read_v1(PDO $pdo,array $user,array $principal,array $plan,array $context=[]): array
{
    try{
        $plan=vp3_profile_webmcp_plan_v1($plan);if($plan['operation']!=='profile_webmcp_read')throw new InvalidArgumentException('Invalid Profile inspection.');
        $live=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,$context);$profile=$live['profile'];$available=$profile&&$profile['username']!==''&&!empty($profile['is_public'])&&!empty($profile['is_active']);
        $resolution=$available?vp3_profile_webmcp_resolution_v1($pdo,$profile,$live['user']):null;
        $fresh=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,$context);
        if(vp3_profile_webmcp_profile_identity_v1($fresh['profile'])!==vp3_profile_webmcp_profile_identity_v1($profile))throw new RuntimeException('Profile availability changed.',403);
        $tools=$resolution['tools']??[];$domains=array_values(array_unique(array_column($tools,'capability')));
        $answer=$available?'For your signed-in owner session, your public Profile currently exposes these tool domains: '.($domains?implode(', ',$domains):'none').'. These are verified capability registrations. Transactions still require their existing signed Profile surface and confirmation.':(!$profile||$profile['username']===''?'Your Profile is not configured with a public username.':'Your Profile is not currently public and active.');
        return ['handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>$available?[['source'=>'profile:webmcp-capabilities','title'=>'Current owned Profile capabilities','url'=>profile_public_url($profile['username'])]]:[],
            'profile_webmcp_plan'=>['contract'=>VP3_PROFILE_WEBMCP_AGENT_PLAN_V1,'mode'=>'inspect','profile_user_id'=>(int)$live['user']['id'],'profile_username'=>$profile['username']??'','execution_allowed'=>false,'status'=>$available?'ready':'profile_unavailable','recommended_capabilities'=>$domains,'recommended_tools'=>$tools],
            'tool_receipt'=>vp3_tool_receipt_v1('profile.webmcp.read','inspect',$available?'read_complete':'no_matches','',0,count($tools))];
    }catch(Throwable $e){return vp3_profile_webmcp_failure_v1($e);}
}

function vp3_profile_webmcp_explicit_handoff_v1(string $query): bool
{
    if(vp3_profile_webmcp_non_action_query_v1($query))return false;
    return(bool)preg_match('/^\s*(?:please\s+)?(?:open|take me to|continue|start|prepare|plan|use|book|schedule|buy|purchase|checkout|claim|transfer|join|request)\b/i',$query)
        &&(bool)preg_match('/\b(?:my\s+(?:public\s+)?profile|public\s+profile|profile\s+(?:webmcp|agent)|webmcp)\b/i',$query);
}

function vp3_profile_webmcp_non_action_query_v1(string $query): bool
{
    return !mb_check_encoding($query,'UTF-8')||(bool)preg_match('/\b(?:if|unless|suppose|imagine|hypothetical|tutorial|example|explain)\b|\bhow\s+(?:to|do|does|can|should)\b|\bwhat\s+(?:is|are)\s+(?:(?:a|an|the)\s+)?(?:webmcp|profile\s+webmcp|public\s+profiles?)\b|\b(?:when|after|once)\s+I\s+(?:ask|say|request|decide|am ready)\b|\b(?:later|next time|not now)\b|\b(?:build|implement|develop|code|design)(?:ing)?\b.*\b(?:profile|tool|feature|integration|support|skill|capabilit\w*)\b/i',$query);
}

function vp3_profile_webmcp_ability_query_v1(string $query): bool
{
    return(bool)preg_match('/^\s*(?:(?:can|could|would|will)\s+you|(?:do|does)\s+(?:you|this\s+agent|the\s+agent|webmcp|profile\s+webmcp)\s+(?:have|support|allow|handle)|(?:are|is)\s+(?:you|this\s+agent|the\s+agent)\s+(?:able|allowed)|what\s+(?:can|could)\s+you)\b/i',$query);
}

function vp3_profile_webmcp_handoff_result_v1(array $profile,array $resume,array $resolution,array $recommendation): array
{
    $url=profile_public_url((string)$profile['username']);
    return ['handled'=>true,'answer'=>'The Profile handoff is prepared. Open your public Profile to review the available tools and explicitly confirm any requested action there. No booking, checkout, transfer or other Profile action has been executed by this Chat handoff.',
        'actions'=>[['type'=>'open_url','label'=>'Open my public Profile','url'=>$resume['path'],'auto'=>false]],'sources'=>[['source'=>'profile:webmcp-capabilities','title'=>'Profile WebMCP handoff','url'=>$url]],
        'profile_webmcp_plan'=>['contract'=>VP3_PROFILE_WEBMCP_AGENT_PLAN_V1,'mode'=>'handoff','profile_user_id'=>(int)$profile['user_id'],'profile_username'=>(string)$profile['username'],'public_url'=>$url,'surface'=>'agent_brain','status'=>'ready','execution_allowed'=>false,
            'recommended_capabilities'=>$recommendation['recommended_capabilities'],'recommended_tools'=>$recommendation['recommended_tools'],
            'handoff'=>['surface'=>'native_profile','url'=>$url,'resume_token'=>$resume['token'],'resume_path'=>$resume['path'],'resume_expires_at'=>$resume['expires_at'],'action_context_id'=>$resume['action_context_id'],'return_path'=>$resume['return_path'],'requires_signed_profile_surface'=>true]],
        'tool_receipt'=>vp3_tool_receipt_v1('profile.webmcp.handoff','prepare','clarification_required','confirmation_required',0,1)];
}

function vp3_profile_webmcp_handoff_v1(PDO $pdo,array $user,array $principal,array $plan,array $context=[],?string $requestQuery=null): array
{
    try{
        $plan=vp3_profile_webmcp_plan_v1($plan);if($plan['operation']!=='profile_webmcp_handoff')throw new InvalidArgumentException('Invalid Profile handoff.');
        if($requestQuery===null||!vp3_profile_webmcp_explicit_handoff_v1($requestQuery))return ['handled'=>true,'answer'=>'A Profile handoff needs a clear request to open or continue on your public Profile. No handoff or Profile action was created.','actions'=>[],'sources'=>[],'tool_receipt'=>vp3_tool_receipt_v1('profile.webmcp.handoff','prepare','clarification_required')];
        $live=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,$context,true);$profile=$live['profile'];$resolution=vp3_profile_webmcp_resolution_v1($pdo,$profile,$live['user']);
        $intent=vp3_profile_webmcp_resolve_intent_v100($plan['query'],['capabilities'=>$resolution['capabilities'],'allowed_tools'=>$resolution['allowed_tools']]);$capSet=array_fill_keys($intent['recommended_capabilities']??[],true);
        $tools=array_values(array_filter($resolution['tools'],static fn(array $tool):bool=>isset($capSet[$tool['capability']])));$recommendation=['recommended_capabilities'=>array_values(array_unique(array_column($tools,'capability'))),'recommended_tools'=>$tools];
        $fresh=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,$context,true);if(vp3_profile_webmcp_profile_identity_v1($fresh['profile'])!==vp3_profile_webmcp_profile_identity_v1($profile))throw new RuntimeException('Profile changed before handoff.',403);
        vp3_profile_webmcp_agent_runtime_v1(true);$resume=vp3_profile_webmcp_resume_issue_v194($pdo,$profile,$fresh['user'],$plan['query'],$recommendation,$live['binding']['conversation_id'],$live['binding']);
        try{vp3_profile_webmcp_context_for_native_v1($pdo,$profile,$user,['context_id'=>$resume['action_context_id'],'return_token'=>$resume['return_token']]);}
        catch(Throwable $e){unset($_SESSION['vp3_profile_webmcp_resume_v194'][$resume['token']],$_SESSION['vp3_profile_webmcp_action_context_v195'][$resume['action_context_id']]);throw $e;}
        return vp3_profile_webmcp_handoff_result_v1($profile,$resume,$resolution,$recommendation);
    }catch(Throwable $e){return vp3_profile_webmcp_failure_v1($e,'profile.webmcp.handoff');}
}

function vp3_profile_webmcp_return_read_v1(PDO $pdo,array $user,array $principal,array $plan,array $context=[]): array
{
    try{
        $plan=vp3_profile_webmcp_plan_v1($plan);if($plan['operation']!=='profile_webmcp_return_read')throw new InvalidArgumentException('Invalid Profile return read.');
        $live=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,$context,true);$last=$_SESSION['vp3_profile_webmcp_last_return_v195']??null;
        if(!is_array($last)||($last['user_id']??null)!==$live['binding']['user_id']||!is_int($last['captured_at']??null)||$last['captured_at']>time()+5||$last['captured_at']<time()-3600)throw new RuntimeException('A current Profile return is unavailable.');
        $id=$last['context_id']??null;$row=is_string($id)?($_SESSION['vp3_profile_webmcp_action_context_v195'][$id]??null):null;
        if(!is_array($row)||($row['binding']??null)!==$live['binding']||($row['returned_at']??0)<1)throw new RuntimeException('Profile return belongs to another Agent or conversation.',403);
        $row=vp3_profile_webmcp_context_live_v1($pdo,$live['profile'],$live['user'],$row,true);vp3_profile_webmcp_agent_runtime_v1(true,true);
        $outcome=vp3_profile_webmcp_outcome_read_v1($pdo,$row);$outcome=vp3_profile_webmcp_outcome_projection_v1($outcome);
        vp3_profile_webmcp_context_live_v1($pdo,$live['profile'],$live['user'],$row,true);
        $public=vp3_profile_webmcp_action_context_public_v195($row,$outcome);$verified=$outcome['verification']==='verified';
        $receipt=vp3_tool_receipt_v1('profile.webmcp.return.read','read',$verified?'read_complete':'failed',$verified?'':'outcome_unverified',0,$verified?1:0);$receipt['profile_webmcp_outcome']=$outcome;
        return ['handled'=>true,'answer'=>vp3_profile_webmcp_outcome_text_v1($outcome),'actions'=>[],'sources'=>[],'profile_webmcp_plan'=>null,'profile_webmcp_return'=>$public,'tool_receipt'=>$receipt];
    }catch(Throwable $e){return vp3_profile_webmcp_failure_v1($e,'profile.webmcp.return.read');}
}

/** Rebuild the complete response; a stale plan must not leave its old prose or URL behind. */
function vp3_profile_webmcp_authorize_result_v1(PDO $pdo,array $user,array $principal,array $context,array $result): array
{
    $isReturn=is_array($result['profile_webmcp_return']??null)||($result['tool_receipt']['capability']??'')==='profile.webmcp.return.read';
    if($isReturn)return vp3_profile_webmcp_return_read_v1($pdo,$user,$principal,['operation'=>'profile_webmcp_return_read','ids'=>['profile.webmcp.return.read']],$context);
    $plan=$result['profile_webmcp_plan']??null;if(!is_array($plan))return $result;
    $cap=($plan['mode']??null)==='handoff'?'profile.webmcp.handoff':'profile.webmcp.read';
    try{
        if(($plan['contract']??null)!==VP3_PROFILE_WEBMCP_AGENT_PLAN_V1||($plan['profile_user_id']??null)!==(int)($user['id']??0)||!in_array($plan['mode']??null,['inspect','handoff'],true))throw new RuntimeException('Profile plan identity is invalid.',403);
        $live=vp3_profile_webmcp_authority_v1($pdo,$user,$principal,$context,$plan['mode']==='handoff');
        if(($plan['profile_username']??null)!==($live['profile']['username']??''))throw new RuntimeException('Profile plan changed.',403);
        if($plan['mode']==='inspect')return vp3_profile_webmcp_read_v1($pdo,$user,$principal,['operation'=>'profile_webmcp_read','ids'=>['profile.webmcp.read']],$context);
        vp3_profile_webmcp_agent_runtime_v1(true);$token=$plan['handoff']['resume_token']??null;
        if(!is_string($token))throw new RuntimeException('Profile handoff token is unavailable.');
        $resume=vp3_profile_webmcp_resume_validate_v194($live['profile'],$live['user'],$token,$pdo);
        if(!$resume)throw new RuntimeException('Profile handoff expired or was consumed.');
        $row=$_SESSION['vp3_profile_webmcp_resume_v194'][$token]??null;
        if(!is_array($row)||($row['binding']??null)!==$live['binding'])throw new RuntimeException('Profile handoff belongs to another Agent or conversation.',403);
        $resolution=vp3_profile_webmcp_resolution_v1($pdo,$live['profile'],$live['user']);$names=$row['recommended_tools']??[];
        $tools=array_values(array_filter($resolution['tools'],static fn(array $tool):bool=>in_array($tool['name'],$names,true)));
        if(count($tools)!==count($names))throw new RuntimeException('Profile handoff capabilities changed.',403);
        vp3_profile_webmcp_context_for_native_v1($pdo,$live['profile'],$user,['context_id'=>$resume['action_context_id'],'return_token'=>$resume['return_token']]);
        return vp3_profile_webmcp_handoff_result_v1($live['profile'],$resume,$resolution,['recommended_capabilities'=>array_values(array_unique(array_column($tools,'capability'))),'recommended_tools'=>$tools]);
    }catch(Throwable $e){return vp3_profile_webmcp_failure_v1($e,$cap);}
}
