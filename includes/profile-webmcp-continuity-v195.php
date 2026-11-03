<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_CONTINUITY_V195='profile-webmcp-continuity-v195-20260929';
const VP3_PROFILE_WEBMCP_ACTION_CONTEXT_TTL_V195=3600;
const VP3_PROFILE_WEBMCP_ACTION_CONTEXT_MAX_V195=16;

function vp3_profile_webmcp_action_context_store_v195(): array
{
    $rows=$_SESSION['vp3_profile_webmcp_action_context_v195']??[];
    return is_array($rows)?$rows:[];
}

function vp3_profile_webmcp_action_context_prune_v195(array $rows,int $now): array
{
    $clean=[];
    foreach($rows as $id=>$row){
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_array($row))continue;
        if(!is_int($row['created_at']??null)||!is_int($row['expires_at']??null)||$row['created_at']<1||$row['created_at']>$now+5||$row['expires_at']<=$now||$row['expires_at']>$row['created_at']+VP3_PROFILE_WEBMCP_ACTION_CONTEXT_TTL_V195)continue;
        $clean[$id]=$row;
    }
    if(count($clean)>VP3_PROFILE_WEBMCP_ACTION_CONTEXT_MAX_V195){
        uasort($clean,static fn(array $a,array $b):int=>(int)($b['updated_at']??$b['created_at'])<=>(int)($a['updated_at']??$a['created_at']));
        $clean=array_slice($clean,0,VP3_PROFILE_WEBMCP_ACTION_CONTEXT_MAX_V195,true);
    }
    return $clean;
}

/** Called only after the authenticated handoff has checked its current source binding. */
function vp3_profile_webmcp_action_context_issue_v195(array $profile,array $user,string $goal,int $conversationId=0,array $binding=[],array $allowedTools=[]): array
{
    require_once __DIR__.'/agent-profile-webmcp-read-v1.php';
    $binding=vp3_profile_webmcp_binding_v1($binding);$userId=(int)($user['id']??0);
    if($binding['user_id']!==$userId||$binding['conversation_id']!==$conversationId||(int)($profile['user_id']??0)!==$userId)throw new RuntimeException('Profile action context owner mismatch.',403);
    $username=$profile['username']??null;
    if(!is_string($username)||$username===''||!mb_check_encoding($username,'UTF-8')||strlen($username)>80||empty($profile['is_public'])||empty($profile['is_active']))throw new RuntimeException('Profile action context is unavailable.',403);
    if(!array_is_list($allowedTools)||count($allowedTools)>128)throw new RuntimeException('Profile context tools are invalid.');
    foreach($allowedTools as $tool)if(!is_string($tool)||!preg_match('/^vp3\.[a-z0-9_.]{1,110}$/D',$tool))throw new RuntimeException('Profile context tool is invalid.');
    if(count(array_unique($allowedTools))!==count($allowedTools))throw new RuntimeException('Profile context tools are duplicated.');
    $now=time();$id=bin2hex(random_bytes(16));$returnToken=bin2hex(random_bytes(16));
    $rows=vp3_profile_webmcp_action_context_prune_v195(vp3_profile_webmcp_action_context_store_v195(),$now);
    $rows[$id]=[
        'version'=>VP3_PROFILE_WEBMCP_CONTINUITY_V195,'context_id'=>$id,'return_token_hash'=>hash('sha256',$returnToken),
        'profile_user_id'=>$userId,'profile_username'=>$username,'conversation_id'=>$conversationId,'binding'=>$binding,
        'allowed_tools'=>$allowedTools,'goal'=>mb_strimwidth(trim($goal),0,1000,''),'ui_phase'=>'viewed',
        'created_at'=>$now,'updated_at'=>$now,'expires_at'=>$now+VP3_PROFILE_WEBMCP_ACTION_CONTEXT_TTL_V195,'returned_at'=>0,
    ];
    $_SESSION['vp3_profile_webmcp_action_context_v195']=vp3_profile_webmcp_action_context_prune_v195($rows,$now);
    $path='/chat.php?agent='.($binding['agent_id']>0?(string)$binding['agent_id']:'system').'&conversation_id='.$conversationId.'&profile_webmcp_return='.$returnToken;
    return ['context_id'=>$id,'return_token'=>$returnToken,'return_path'=>$path,'expires_at'=>$now+VP3_PROFILE_WEBMCP_ACTION_CONTEXT_TTL_V195];
}

function vp3_profile_webmcp_action_context_validate_v195(array $profile,array $user,string $contextId,string $returnToken,?PDO $pdo=null): ?array
{
    if(!$pdo)return null;
    require_once __DIR__.'/agent-profile-webmcp-read-v1.php';
    try{return vp3_profile_webmcp_context_for_native_v1($pdo,$profile,$user,['context_id'=>$contextId,'return_token'=>$returnToken]);}catch(Throwable $e){return null;}
}

/** Browser reports are UI observations only; all outcome text comes from server evidence. */
function vp3_profile_webmcp_action_context_note_v195(
    array $profile,array $user,string $contextId,string $returnToken,string $tool,string $phase,string $resultCode='',bool $idempotentReplay=false,?PDO $pdo=null
): ?array {
    $row=vp3_profile_webmcp_action_context_validate_v195($profile,$user,$contextId,$returnToken,$pdo);if(!$row||!$pdo)return null;
    try{
        vp3_profile_webmcp_agent_runtime_v1(false,true);
        $outcome=vp3_profile_webmcp_outcome_read_v1($pdo,$row);
        $row=vp3_profile_webmcp_context_live_v1($pdo,$profile,$user,$row);
        $allowed=['viewed','prepared','needs_input','confirming','completed','cancelled','expired','error','conflict'];
        $row['ui_phase']=in_array($phase,$allowed,true)?$phase:'error';$row['updated_at']=time();
        // Never store tool/resultCode/idempotentReplay or copy browser status into server_action/server_outcome.
        $_SESSION['vp3_profile_webmcp_action_context_v195'][$row['context_id']]=$row;
        return vp3_profile_webmcp_action_context_public_v195($row,$outcome);
    }catch(Throwable $e){return null;}
}

function vp3_profile_webmcp_action_context_public_v195(array $row,?array $outcome=null): array
{
    require_once __DIR__.'/agent-profile-webmcp-outcomes-v1.php';
    $unknown=['contract'=>'vp3.webmcp.outcome.v1','verification'=>'unverified','operation'=>'','state'=>'unverified','result'=>'unverified','committed_at'=>null,'replayed'=>false];
    try{$outcome=vp3_profile_webmcp_outcome_projection_v1($outcome??$unknown);}catch(Throwable $e){$outcome=$unknown;}
    $binding=is_array($row['binding']??null)?$row['binding']:[];
    return [
        'contract'=>'vp3.webmcp.return.v1','continuity_version'=>VP3_PROFILE_WEBMCP_CONTINUITY_V195,
        'context_id'=>(string)($row['context_id']??''),'correlation_id'=>(string)($row['context_id']??''),'profile_username'=>(string)($row['profile_username']??''),
        'conversation_id'=>(int)($binding['conversation_id']??0),'agent_id'=>(int)($binding['agent_id']??0),
        'phase'=>$outcome['state'],'idempotent_replay'=>$outcome['replayed'],'status_text'=>vp3_profile_webmcp_outcome_text_v1($outcome),'outcome'=>$outcome,
        'updated_at'=>(int)($row['updated_at']??0),'execution_allowed'=>false,'contains_sensitive_payload'=>false,
    ];
}

function vp3_profile_webmcp_return_consume_v195(array $profile,array $user,string $returnToken,?PDO $pdo=null): ?array
{
    if(!$pdo||!preg_match('/^[a-f0-9]{32}$/D',$returnToken))return null;
    require_once __DIR__.'/agent-profile-webmcp-read-v1.php';
    $hash=hash('sha256',$returnToken);$now=time();
    $rows=vp3_profile_webmcp_action_context_prune_v195(vp3_profile_webmcp_action_context_store_v195(),$now);
    foreach($rows as $id=>$row){
        if(($row['profile_user_id']??null)!==(int)($user['id']??0)||($row['profile_username']??null)!==($profile['username']??null)||($row['returned_at']??null)!==0||!is_string($row['return_token_hash']??null)||!hash_equals($row['return_token_hash'],$hash))continue;
        try{
            $row=vp3_profile_webmcp_context_live_v1($pdo,$profile,$user,$row);vp3_profile_webmcp_agent_runtime_v1(false,true);
            $outcome=vp3_profile_webmcp_outcome_read_v1($pdo,$row);$row=vp3_profile_webmcp_context_live_v1($pdo,$profile,$user,$row);
            $row['returned_at']=$now;$row['return_token_hash']='';$row['updated_at']=$now;
            $_SESSION['vp3_profile_webmcp_action_context_v195'][$id]=$row;
            $_SESSION['vp3_profile_webmcp_last_return_v195']=['user_id'=>$row['binding']['user_id'],'agent_id'=>$row['binding']['agent_id'],'conversation_id'=>$row['binding']['conversation_id'],'context_id'=>$id,'captured_at'=>$now];
            return vp3_profile_webmcp_action_context_public_v195($row,$outcome);
        }catch(Throwable $e){return null;}
    }
    return null;
}

function vp3_profile_webmcp_return_followthrough_v195(array $user,string $query,int $conversationId=0,?PDO $pdo=null): ?array
{
    $last=$_SESSION['vp3_profile_webmcp_last_return_v195']??null;
    if(!is_array($last)||($last['user_id']??null)!==(int)($user['id']??0)||($last['conversation_id']??null)!==$conversationId)return null;
    require_once __DIR__.'/agent-profile-webmcp-read-v1.php';
    if(vp3_profile_webmcp_non_action_query_v1($query)||vp3_profile_webmcp_ability_query_v1($query))return null;
    if(!preg_match('/\b(?:profile|webmcp)\b.*\b(?:status|happened|finished|complete|completed|cancelled|canceled|continue|return|outcome)\b|\b(?:status|happened|finished|complete|completed|cancelled|canceled|continue|return|outcome)\b.*\b(?:profile|webmcp)\b/i',$query))return null;
    try{
        $pdo=$pdo??(function_exists('db')?db():null);if(!$pdo)return vp3_profile_webmcp_failure_v1(new RuntimeException('Current Profile authority is unavailable.'),'profile.webmcp.return.read');
        if(!function_exists('vp3_capability_control_principal_v1'))require_once __DIR__.'/agent-capability-chat-v1.php';
        $principal=vp3_capability_control_principal_v1($pdo,$user,$conversationId);
        return vp3_profile_webmcp_return_read_v1($pdo,$user,$principal,['operation'=>'profile_webmcp_return_read','ids'=>['profile.webmcp.return.read']],['conversation_id'=>$conversationId]);
    }catch(Throwable $e){return vp3_profile_webmcp_failure_v1($e,'profile.webmcp.return.read');}
}
