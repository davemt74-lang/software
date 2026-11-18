<?php
declare(strict_types=1);

// Loading capture definitions performs no migration and creates no visitor state.
require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/profile-agent-boundary-v390.php';

const VP3_PROFILE_PROMOTION_CAPTURE_V1='vp3.profile-promotion-capture.v1';

function profile_promotion_capture_integer_v1(mixed $value,bool $zero=false): ?int
{
    if(is_int($value))return $value>=($zero?0:1)?$value:null;
    if(!is_string($value)||!preg_match($zero?'/\A(?:0|[1-9][0-9]{0,18})\z/D':'/\A[1-9][0-9]{0,18}\z/D',$value))return null;
    $number=(int)$value;
    return (string)$number===$value&&$number>=($zero?0:1)?$number:null;
}

function profile_promotion_capture_now_v1(): int
{
    return profile_promotion_capture_integer_v1((int)floor(microtime(true)*1000))
        ??throw new RuntimeException('Commerce recorded time is unavailable.');
}

function profile_promotion_capture_missing_v1(PDOException $error): bool
{
    $state=(string)($error->errorInfo[0]??$error->getCode());
    $native=(int)($error->errorInfo[1]??0);
    return in_array($state,['42S02','42S22'],true)
        ||($native===1&&(bool)preg_match('/no such (?:table|column):/i',$error->getMessage()));
}

function profile_promotion_capture_retryable_v1(Throwable $error): bool
{
    return $error instanceof PDOException&&((string)($error->errorInfo[0]??$error->getCode())==='40001'
        ||in_array((int)($error->errorInfo[1]??0),[1205,1213],true));
}

/** A definite missing column selects the legacy shape before any write. */
function profile_promotion_capture_columns_v1(PDO $pdo,string $store): bool
{
    $sql=match($store){
        'clicks'=>'SELECT public_conversation_id,public_conversation_binding_sha256_v1 FROM agent_promotion_clicks_v1 WHERE 1=0',
        'checkout'=>'SELECT opened_recorded_at_utc_ms FROM agent_commerce_checkout_attempts_v800 WHERE 1=0',
        default=>throw new InvalidArgumentException('Unknown Commerce capture store.'),
    };
    try{$q=$pdo->query($sql);$q->closeCursor();return true;}
    catch(PDOException $error){if(profile_promotion_capture_missing_v1($error))return false;throw $error;}
}

function profile_promotion_capture_click_columns_v1(PDO $pdo): bool
{
    return profile_promotion_capture_columns_v1($pdo,'clicks');
}

function profile_promotion_capture_checkout_clock_v1(PDO $pdo): bool
{
    return profile_promotion_capture_columns_v1($pdo,'checkout');
}

function profile_promotion_capture_ready_v1(PDO $pdo): bool
{
    try{return profile_promotion_capture_click_columns_v1($pdo)&&profile_promotion_capture_checkout_clock_v1($pdo);}
    catch(PDOException $error){if(profile_promotion_capture_retryable_v1($error))throw $error;return false;}
}

/** Explicit Setup/Upgrade only. Existing NULL evidence is never backfilled. */
function profile_promotion_capture_install_v1(PDO $pdo,string $store='all'): void
{
    if($pdo->inTransaction())throw new RuntimeException('Commerce capture upgrade requires a separate migration transaction.');
    if(!in_array($store,['all','clicks','checkout'],true))throw new InvalidArgumentException('Unknown Commerce capture store.');
    if(!in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME),['sqlite','mysql'],true))throw new RuntimeException('Commerce capture storage is unsupported.');
    $columns=[
        'clicks'=>['agent_promotion_clicks_v1'=>[
            'public_conversation_id'=>'BIGINT NULL','public_conversation_binding_sha256_v1'=>'CHAR(64) NULL',
        ]],
        'checkout'=>['agent_commerce_checkout_attempts_v800'=>['opened_recorded_at_utc_ms'=>'BIGINT NULL']],
    ];
    foreach($columns as $kind=>$tables){
        if($store!=='all'&&$store!==$kind)continue;
        foreach($tables as $table=>$definitions)foreach($definitions as $column=>$definition){
            try{$q=$pdo->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0');$q->closeCursor();continue;}
            catch(PDOException $error){if(!profile_promotion_capture_missing_v1($error))throw $error;}
            try{$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);}
            catch(PDOException $error){
                $native=(int)($error->errorInfo[1]??0);
                if($native!==1060&&!($native===1&&str_contains(strtolower($error->getMessage()),'duplicate column name:')))throw $error;
                $q=$pdo->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0');$q->closeCursor();
            }
        }
    }
}

function profile_promotion_capture_rows_v1(PDO $pdo,string $sql,array $parameters=[],bool $current=false): array
{
    if($current&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        if(!$pdo->inTransaction())throw new RuntimeException('Current Commerce evidence needs an active transaction.',503);
        $sql.=' LOCK IN SHARE MODE';
    }
    $q=$pdo->prepare($sql);
    if(!$q instanceof PDOStatement||!$q->execute($parameters))throw new RuntimeException('Commerce capture storage is unavailable.',503);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();return $rows;
}

/** Pure immutable binder. Mutable responder tokens and CRM descriptions are excluded. */
function profile_promotion_public_binding_digest_v1(array $click,array $conversation,array $binding,array $session): ?string
{
    $owner=profile_promotion_capture_integer_v1($click['owner_user_id']??null);
    $cid=profile_promotion_capture_integer_v1($click['public_conversation_id']??null);
    $aid=profile_promotion_capture_integer_v1($conversation['profile_agent_id']??null);
    $sid=profile_promotion_capture_integer_v1($conversation['profile_session_id']??null);
    $visitor=profile_promotion_capture_integer_v1($conversation['visitor_user_id']??0,true);
    foreach(['publication_id','click_id'] as $key)
        if(!is_string($click[$key]??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$click[$key]))return null;
    if(!$owner||!$cid||!$aid||!$sid||$visitor===null||$visitor===$owner||($click['placement']??null)!=='profile_agent'
        ||profile_promotion_capture_integer_v1($conversation['id']??null)!==$cid
        ||profile_promotion_capture_integer_v1($conversation['owner_user_id']??null)!==$owner
        ||!vp3_profile_agent_conversation_authority_valid_v1($binding,$owner)||$binding['transport']!=='native'
        ||profile_promotion_capture_integer_v1($binding['profile_agent_id']??null)!==$aid
        ||profile_promotion_capture_integer_v1($binding['profile_session_id']??null)!==$sid
        ||$binding['visitor_user_id']!==$visitor
        ||profile_promotion_capture_integer_v1($session['id']??null)!==$sid
        ||profile_promotion_capture_integer_v1($session['owner_user_id']??null)!==$owner
        ||!is_string($session['session_key']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$session['session_key']))return null;
    $proof=[
        'contract'=>'vp3.profile-promotion-public-binding.v1','publication_id'=>$click['publication_id'],
        'click_id'=>$click['click_id'],'placement'=>'profile_agent','owner_user_id'=>$owner,'public_conversation_id'=>$cid,
        'authority_contract'=>$binding['contract'],'transport'=>'native','profile_agent_id'=>$aid,'profile_session_id'=>$sid,
        'visitor_user_id'=>$visitor,'credential_hash'=>$binding['credential_hash'],'session_sha256'=>hash('sha256',$session['session_key']),
    ];
    return hash('sha256',json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

/** Internal exact native rows; does not consult current visitor cookies or public availability. */
function profile_promotion_capture_native_rows_v1(PDO $pdo,int $owner,int $cid,bool $current=false): ?array
{
    $conversation=profile_promotion_capture_rows_v1($pdo,
        'SELECT id,owner_user_id,profile_agent_id,profile_session_id,visitor_user_id FROM profile_agent_conversations WHERE id=? AND owner_user_id=?',[$cid,$owner],$current)[0]??null;
    if(!$conversation)return null;
    $bindings=profile_promotion_capture_rows_v1($pdo,
        "SELECT SUBSTR(context_json,1,65537) context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id LIMIT 2",[$cid],$current);
    if(count($bindings)!==1||!is_string($bindings[0]['context_json'])||strlen($bindings[0]['context_json'])>65536)return null;
    try{$binding=json_decode($bindings[0]['context_json'],true,16,JSON_THROW_ON_ERROR);}catch(JsonException){return null;}
    if(!is_array($binding))return null;
    $agent=profile_promotion_capture_rows_v1($pdo,'SELECT id,owner_user_id FROM user_agents WHERE id=?',[(int)$conversation['profile_agent_id']],$current)[0]??null;
    $session=profile_promotion_capture_rows_v1($pdo,'SELECT id,owner_user_id,session_key FROM profile_visit_sessions WHERE id=?',[(int)$conversation['profile_session_id']],$current)[0]??null;
    if(!$agent||!$session||profile_promotion_capture_integer_v1($agent['owner_user_id']??null)!==$owner)return null;
    return compact('conversation','binding','session');
}

/** Existing cookies are a prerequisite; even the canonical read-only context may otherwise mint them. */
function profile_promotion_capture_native_context_v1(PDO $pdo,array $profile,?array $visitor,int $cid): ?array
{
    if($cid<1)return null;
    $visitorCookie=$_COOKIE['sf_profile_visitor']??null;$conversationCookie=$_COOKIE['sf_profile_conversation']??null;
    if(!is_string($visitorCookie)||!preg_match('/\A(?:[a-f0-9]{48}|[a-f0-9]{64})\z/D',$visitorCookie)
        ||!is_string($conversationCookie)||!preg_match('/\A[a-f0-9]{64}\z/D',$conversationCookie))return null;
    require_once __DIR__.'/profile-agent-public-service-v110.php';
    $ctx=vp3_profile_agent_public_context_v110($pdo,$profile,$visitor,true);
    vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$cid);
    return $ctx;
}

/**
 * Query-only evidence for an already-authorized private owner scope. The caller
 * owns private Agent/Chat permission checks. Current mode holds the exact rows
 * in that caller's transaction; no visitor session or conversation is created.
 */
function profile_promotion_public_click_evidence_v1(PDO $pdo,int $owner,string $publicationId,string $clickId,bool $current=false): array
{
    $out=['availability'=>'unavailable','source_revision'=>null,'public_conversation_id'=>null,'recorded_at_utc_ms'=>null];
    if($owner<1||!preg_match('/\A[a-f0-9]{32}\z/D',$publicationId)||!preg_match('/\A[a-f0-9]{32}\z/D',$clickId))return $out;
    try{
        if(!profile_promotion_capture_click_columns_v1($pdo))return $out;
        $user=profile_promotion_capture_rows_v1($pdo,'SELECT id,is_active FROM users WHERE id=?',[$owner],$current)[0]??null;
        if(!$user||(int)$user['is_active']!==1)return $out;
        $publication=profile_promotion_capture_rows_v1($pdo,'SELECT * FROM agent_promotion_publications_v1 WHERE publication_id=? AND owner_user_id=?',[$publicationId,$owner],$current)[0]??null;
        $click=profile_promotion_capture_rows_v1($pdo,'SELECT * FROM agent_promotion_clicks_v1 WHERE click_id=? AND publication_id=?',[$clickId,$publicationId],$current)[0]??null;
        if(!$publication||!$click||($publication['publication_id']??null)!==$publicationId
            ||($click['click_id']??null)!==$clickId||($click['publication_id']??null)!==$publicationId
            ||profile_promotion_capture_integer_v1($publication['owner_user_id']??null)!==$owner
            ||!in_array($click['placement']??null,['profile','profile_agent'],true))return $out;
        foreach(['product_id','conversation_id','source_asset_id'] as $field)
            if(profile_promotion_capture_integer_v1($publication[$field]??null)===null)return $out;
        if(profile_promotion_capture_integer_v1($publication['agent_id']??null,true)===null)return $out;
        foreach(['promotion_id'=>32,'draft_key'=>64] as $field=>$length)
            if(!is_string($publication[$field]??null)||!preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$publication[$field]))return $out;
        if(!array_key_exists('campaign_id',$publication)||!array_key_exists('review_request',$publication)
            ||($publication['campaign_id']!==null&&profile_promotion_capture_integer_v1($publication['campaign_id'])===null)
            ||($publication['review_request']!==null&&(!is_string($publication['review_request'])||!preg_match('/\A[a-f0-9]{64}\z/D',$publication['review_request']))))return $out;
        $recorded=agent_commerce_date_pa2($click['created_at']??null);
        if($recorded<1)return $out;
        $scope=[];
        foreach(['publication_id','promotion_id','owner_user_id','product_id','agent_id','conversation_id','source_asset_id','draft_key','campaign_id','review_request'] as $key)
            $scope[$key]=$publication[$key]===null?null:(string)$publication[$key];
        $native=null;$cid=$click['public_conversation_id'];$digest=$click['public_conversation_binding_sha256_v1'];
        $availability='ready';
        if($cid===null&&$digest===null){
            if($click['placement']==='profile_agent')$availability='unattributed';
        }else{
            $cid=profile_promotion_capture_integer_v1($cid);
            if($click['placement']!=='profile_agent'||!$cid||!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))return $out;
            $native=profile_promotion_capture_native_rows_v1($pdo,$owner,$cid,$current);
            if(!$native)return $out;
            $expected=profile_promotion_public_binding_digest_v1($click+['owner_user_id'=>$owner],$native['conversation'],$native['binding'],$native['session']);
            if($expected===null||!hash_equals($digest,$expected))return $out;
        }
        $proof=['publication'=>$scope,'click'=>[
            'click_id'=>$clickId,'publication_id'=>$publicationId,'placement'=>$click['placement'],'created_at'=>$click['created_at'],
            'public_conversation_id'=>$cid,'public_conversation_binding_sha256_v1'=>$digest,
        ]];
        return ['availability'=>$availability,'source_revision'=>hash('sha256',json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'public_conversation_id'=>$cid,'recorded_at_utc_ms'=>$recorded*1000];
    }catch(Throwable $error){
        if(profile_promotion_capture_retryable_v1($error))throw $error;
        return $out;
    }
}
