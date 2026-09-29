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
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id)||!is_array($row))continue;
        if((int)($row['expires_at']??0)<=$now)continue;
        $clean[$id]=$row;
    }
    if(count($clean)>VP3_PROFILE_WEBMCP_ACTION_CONTEXT_MAX_V195){
        uasort($clean,static fn(array $a,array $b):int=>(int)($b['updated_at']??$b['created_at']??0)<=>(int)($a['updated_at']??$a['created_at']??0));
        $clean=array_slice($clean,0,VP3_PROFILE_WEBMCP_ACTION_CONTEXT_MAX_V195,true);
    }
    return $clean;
}

function vp3_profile_webmcp_action_context_issue_v195(array $profile,array $user,string $goal,int $conversationId=0): array
{
    $userId=(int)($user['id']??0);
    if($userId<1||(int)($profile['user_id']??0)!==$userId)throw new RuntimeException('Profile action context owner mismatch.');
    $username=(string)($profile['username']??'');
    if($username==='')throw new RuntimeException('Profile action context is unavailable.');

    $now=time();
    $id=bin2hex(random_bytes(16));
    $returnToken=bin2hex(random_bytes(16));
    $rows=vp3_profile_webmcp_action_context_prune_v195(vp3_profile_webmcp_action_context_store_v195(),$now);
    $rows[$id]=[
        'version'=>VP3_PROFILE_WEBMCP_CONTINUITY_V195,
        'context_id'=>$id,
        'return_token_hash'=>hash('sha256',$returnToken),
        'profile_user_id'=>$userId,
        'profile_username'=>$username,
        'conversation_id'=>max(0,$conversationId),
        'goal'=>mb_strimwidth(trim($goal),0,1000,''),
        'phase'=>'viewed',
        'tool'=>'',
        'result_code'=>'',
        'idempotent_replay'=>false,
        'created_at'=>$now,
        'updated_at'=>$now,
        'expires_at'=>$now+VP3_PROFILE_WEBMCP_ACTION_CONTEXT_TTL_V195,
        'returned_at'=>0,
    ];
    $_SESSION['vp3_profile_webmcp_action_context_v195']=vp3_profile_webmcp_action_context_prune_v195($rows,$now);
    return [
        'context_id'=>$id,
        'return_token'=>$returnToken,
        'return_path'=>'/chat.php?profile_webmcp_return='.$returnToken,
        'expires_at'=>$now+VP3_PROFILE_WEBMCP_ACTION_CONTEXT_TTL_V195,
    ];
}

function vp3_profile_webmcp_action_context_validate_v195(array $profile,array $user,string $contextId,string $returnToken): ?array
{
    $contextId=strtolower(trim($contextId));
    $returnToken=strtolower(trim($returnToken));
    if(!preg_match('/^[a-f0-9]{32}$/',$contextId)||!preg_match('/^[a-f0-9]{32}$/',$returnToken))return null;
    $userId=(int)($user['id']??0);
    if($userId<1||(int)($profile['user_id']??0)!==$userId)return null;
    $now=time();
    $rows=vp3_profile_webmcp_action_context_prune_v195(vp3_profile_webmcp_action_context_store_v195(),$now);
    $_SESSION['vp3_profile_webmcp_action_context_v195']=$rows;
    $row=$rows[$contextId]??null;
    if(!is_array($row))return null;
    if((int)($row['profile_user_id']??0)!==$userId)return null;
    if(!hash_equals((string)($profile['username']??''),(string)($row['profile_username']??'')))return null;
    $hash=hash('sha256',$returnToken);
    if(!hash_equals((string)($row['return_token_hash']??''),$hash))return null;
    return $row;
}

function vp3_profile_webmcp_action_context_note_v195(
    array $profile,array $user,string $contextId,string $returnToken,string $tool,string $phase,string $resultCode='',bool $idempotentReplay=false
): ?array {
    $row=vp3_profile_webmcp_action_context_validate_v195($profile,$user,$contextId,$returnToken);
    if(!$row)return null;
    $allowed=['viewed','prepared','needs_input','confirming','completed','cancelled','expired','error','conflict'];
    $phase=strtolower(trim($phase));
    if(!in_array($phase,$allowed,true))$phase='error';
    $tool=mb_strimwidth(trim($tool),0,120,'');
    $resultCode=mb_strimwidth(preg_replace('/[^A-Z0-9_\-]/','',strtoupper(trim($resultCode)))??'',0,80,'');
    $now=time();
    $rows=vp3_profile_webmcp_action_context_prune_v195(vp3_profile_webmcp_action_context_store_v195(),$now);
    $contextId=(string)$row['context_id'];
    $rows[$contextId]['phase']=$phase;
    $rows[$contextId]['tool']=$tool;
    $rows[$contextId]['result_code']=$resultCode;
    $rows[$contextId]['idempotent_replay']=$idempotentReplay;
    $rows[$contextId]['updated_at']=$now;
    $_SESSION['vp3_profile_webmcp_action_context_v195']=$rows;
    return vp3_profile_webmcp_action_context_public_v195($rows[$contextId]);
}

function vp3_profile_webmcp_action_context_public_v195(array $row): array
{
    $phase=(string)($row['phase']??'viewed');
    $statusText=match($phase){
        'completed'=>'The Profile action completed.',
        'cancelled'=>'The Profile action was cancelled.',
        'expired'=>'The Profile action expired before completion.',
        'prepared'=>'The Profile action is prepared and awaiting confirmation.',
        'confirming'=>'The Profile action is being confirmed.',
        'needs_input'=>'The Profile action needs more information.',
        'conflict'=>'The Profile action needs to be prepared again.',
        'error'=>'The Profile action could not be completed.',
        default=>'The Profile action is ready to continue.',
    };
    return [
        'contract'=>'vp3.webmcp.return.v1',
        'continuity_version'=>VP3_PROFILE_WEBMCP_CONTINUITY_V195,
        'context_id'=>(string)($row['context_id']??''),
        'profile_username'=>(string)($row['profile_username']??''),
        'conversation_id'=>(int)($row['conversation_id']??0),
        'goal'=>(string)($row['goal']??''),
        'phase'=>$phase,
        'tool'=>(string)($row['tool']??''),
        'result_code'=>(string)($row['result_code']??''),
        'idempotent_replay'=>!empty($row['idempotent_replay']),
        'status_text'=>$statusText,
        'updated_at'=>(int)($row['updated_at']??0),
        'execution_allowed'=>false,
        'contains_sensitive_payload'=>false,
    ];
}

function vp3_profile_webmcp_return_consume_v195(array $profile,array $user,string $returnToken): ?array
{
    $returnToken=strtolower(trim($returnToken));
    if(!preg_match('/^[a-f0-9]{32}$/',$returnToken))return null;
    $userId=(int)($user['id']??0);
    if($userId<1||(int)($profile['user_id']??0)!==$userId)return null;
    $hash=hash('sha256',$returnToken);
    $now=time();
    $rows=vp3_profile_webmcp_action_context_prune_v195(vp3_profile_webmcp_action_context_store_v195(),$now);
    foreach($rows as $id=>$row){
        if((int)($row['profile_user_id']??0)!==$userId)continue;
        if(!hash_equals((string)($profile['username']??''),(string)($row['profile_username']??'')))continue;
        if(!hash_equals((string)($row['return_token_hash']??''),$hash))continue;
        $rows[$id]['returned_at']=$now;
        $rows[$id]['updated_at']=max((int)($row['updated_at']??0),$now);
        $_SESSION['vp3_profile_webmcp_action_context_v195']=$rows;
        $public=vp3_profile_webmcp_action_context_public_v195($rows[$id]);
        $_SESSION['vp3_profile_webmcp_last_return_v195']=[
            'user_id'=>$userId,
            'conversation_id'=>(int)($row['conversation_id']??0),
            'context'=>$public,
            'captured_at'=>$now,
        ];
        return $public;
    }
    return null;
}

function vp3_profile_webmcp_return_followthrough_v195(array $user,string $query,int $conversationId=0): ?array
{
    $last=$_SESSION['vp3_profile_webmcp_last_return_v195']??null;
    if(!is_array($last)||(int)($last['user_id']??0)!==(int)($user['id']??0))return null;
    if(time()-(int)($last['captured_at']??0)>VP3_PROFILE_WEBMCP_ACTION_CONTEXT_TTL_V195)return null;
    $storedConversation=(int)($last['conversation_id']??0);
    if($storedConversation>0&&$conversationId>0&&$storedConversation!==$conversationId)return null;
    if(!preg_match('/\b(?:continue|what happened|status|profile action|did it|finished|complete|completed|cancelled|canceled)\b/i',$query))return null;
    $context=is_array($last['context']??null)?$last['context']:[];
    if(($context['contract']??'')!=='vp3.webmcp.return.v1')return null;
    return [
        'handled'=>true,
        'answer'=>(string)($context['status_text']??'The Profile action status is available.'),
        'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[],
        'profile_webmcp_plan'=>null,
        'profile_webmcp_return'=>$context,
    ];
}
