<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/profile-agent-boundary-v390.php';
require_once __DIR__.'/profile-promotion-click-v1.php';

const VP3_PROFILE_PROMOTION_NATIVE_CLICK_V1='vp3.profile-promotion-native-click.v1';

function vp3_profile_promotion_native_click_ready_v1(PDO $pdo): bool
{
    try{$pdo->query('SELECT native_context_json_v1 FROM agent_promotion_clicks_v1 WHERE 1=0');return true;}
    catch(PDOException $error){
        $state=(string)($error->errorInfo[0]??$error->getCode());$native=(int)($error->errorInfo[1]??0);
        if(in_array($state,['42S02','42S22'],true)
            ||($native===1&&preg_match('/no such (?:table|column):/i',$error->getMessage())))return false;
        throw $error;
    }
}

function vp3_profile_promotion_native_click_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new LogicException('Install native click proof outside a transaction.');
    if(vp3_profile_promotion_native_click_ready_v1($pdo))return;
    try{$pdo->exec('ALTER TABLE agent_promotion_clicks_v1 ADD COLUMN native_context_json_v1 TEXT NULL');}
    catch(PDOException $error){if(!vp3_profile_promotion_native_click_ready_v1($pdo))throw $error;}
}

/** Decode only the closed, bounded native writer contract. NULL never becomes proof. */
function vp3_profile_promotion_native_click_decode_v1(mixed $json,array $click,array $publication): ?array
{
    if(!is_string($json)||strlen($json)>4096)return null;
    try{$proof=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(JsonException){return null;}
    $keys=['contract','click_id','owner_user_id','product_id','publication_id','public_conversation_id',
        'profile_agent_id','profile_session_id','visitor_user_id','credential_hash','session_hash','visitor_auth_revision'];
    if(!is_array($proof)||count($proof)!==count($keys)||array_diff(array_keys($proof),$keys)
        ||($proof['contract']??null)!==VP3_PROFILE_PROMOTION_NATIVE_CLICK_V1
        ||($click['placement']??null)!=='profile_agent')return null;
    foreach(['owner_user_id','product_id','public_conversation_id','profile_agent_id','profile_session_id','visitor_user_id'] as $key)
        if(!is_int($proof[$key]??null)||$proof[$key]<($key==='visitor_user_id'?0:1))return null;
    foreach(['click_id','publication_id'] as $key)
        if(!is_string($proof[$key]??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$proof[$key]))return null;
    foreach(['credential_hash','session_hash'] as $key)
        if(!is_string($proof[$key]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$proof[$key]))return null;
    if(!is_string($proof['visitor_auth_revision']??null)
        ||($proof['visitor_user_id']===0?$proof['visitor_auth_revision']!==''
            :!preg_match('/\A[a-f0-9]{64}\z/D',$proof['visitor_auth_revision'])))return null;
    if($proof['click_id']!==($click['click_id']??null)||$proof['publication_id']!==($click['publication_id']??null)
        ||$proof['publication_id']!==($publication['publication_id']??null)
        ||$proof['owner_user_id']!==(int)($publication['owner_user_id']??0)
        ||$proof['product_id']!==(int)($publication['product_id']??0)
        ||$proof['visitor_user_id']===$proof['owner_user_id'])return null;
    return $proof;
}

function vp3_profile_promotion_native_click_rows_v1(PDO $pdo,string $sql,array $params,bool $current): array
{
    if($current&&!$pdo->inTransaction())throw new LogicException('Current click proof requires an active transaction.');
    if($current&&!agent_commerce_sqlite_pa2($pdo))$sql.=' LOCK IN SHARE MODE';
    $query=$pdo->prepare($sql);$query->execute($params);$rows=$query->fetchAll(PDO::FETCH_ASSOC);$query->closeCursor();
    return $rows;
}

/** Current public admission capabilities, independent of any private Chat id. */
function vp3_profile_promotion_owner_current_v1(PDO $pdo,int $owner): void
{
    if(!$pdo->inTransaction())throw new LogicException('Native click admission needs an active transaction.');
    if(!function_exists('valid_role')||!function_exists('personal_capability_catalog_v242')
        ||!function_exists('personal_capability_default_roles_v242')||!defined('STONEFELLOW_PERSONAL_CAPABILITIES_SEED'))
        throw new RuntimeException('Native click capability definitions are unavailable.',503);
    $rows=static fn(string $sql,array $params=[]):array=>vp3_profile_promotion_native_click_rows_v1($pdo,$sql,$params,true);
    if(!agent_commerce_sqlite_pa2($pdo)){
        $tables=['users','user_account_types','settings','permissions','role_permissions'];
        $q=$pdo->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?,?,?)');
        $q->execute($tables);$engines=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(count($engines)!==count($tables))throw new RuntimeException('Native click capability storage is incomplete.',503);
        foreach($engines as $engine)if(strtoupper((string)$engine['ENGINE'])!=='INNODB')
            throw new RuntimeException('Native click capabilities need transactional storage.',503);
    }
    $user=$rows('SELECT id,role,is_active FROM users WHERE id=?',[$owner])[0]??null;
    if(!$user||(int)$user['is_active']!==1)vp3_profile_agent_public_unavailable_v1();
    $primary=(string)$user['role'];$roles=valid_role($primary)?[$primary]:[];
    // Hold the positive role and grant records used for admission. A session
    // isolation default does not identify the caller's active transaction.
    foreach($rows('SELECT role,assigned_explicitly_at FROM user_account_types WHERE user_id=? ORDER BY role',[$owner]) as $row)
        if(valid_role((string)$row['role'])&&($row['role']===$primary||$row['assigned_explicitly_at']!==null))$roles[]=(string)$row['role'];
    $roles=array_values(array_unique($roles));sort($roles,SORT_STRING);
    if(!$roles)vp3_profile_agent_public_unavailable_v1();
    if(in_array('admin',$roles,true))return;
    $required=['profile_agent.access','profile_chat.access'];$catalog=personal_capability_catalog_v242();
    foreach($required as $key)if(!isset($catalog[$key]))throw new RuntimeException('Native click capability definitions are unavailable.',503);
    $seed=$rows('SELECT setting_value FROM settings WHERE setting_key=?',[STONEFELLOW_PERSONAL_CAPABILITIES_SEED])[0]??null;
    // An absent marker is a gap, not a locked default-capability proof.
    if(!$seed)throw new RuntimeException('Native click capability state is unavailable.',503);
    $value=(string)($seed['setting_value']??'');
    if(!in_array($value,['','0','1'],true))throw new RuntimeException('Native click capability state is unavailable.',503);
    $rows('SELECT permission_key FROM permissions WHERE permission_key IN (?,?) ORDER BY permission_key',$required);
    $marks=implode(',',array_fill(0,count($roles),'?'));
    $grants=$rows("SELECT role,permission_key FROM role_permissions WHERE role IN ($marks) AND permission_key IN (?,?) ORDER BY role,permission_key",[...$roles,...$required]);
    $granted=array_fill_keys(array_column($grants,'permission_key'),true);$defaults=personal_capability_default_roles_v242();
    foreach($required as $key)
        if($value==='1'?empty($granted[$key]):!array_intersect($roles,$defaults[$key]??[]))vp3_profile_agent_public_unavailable_v1();
}

/**
 * Query-only historical lineage. The caller supplies current private owner/Chat
 * authority. Public visibility and a paused promotion do not erase past facts.
 * No session, cookie, provider, schema or saved receipt is changed here.
 */
function vp3_profile_promotion_native_click_proof_v1(PDO $pdo,array $click,array $publication,bool $current=false): ?array
{
    $proof=vp3_profile_promotion_native_click_decode_v1($click['native_context_json_v1']??null,$click,$publication);
    if(!$proof)return null;
    $owner=$proof['owner_user_id'];$cid=$proof['public_conversation_id'];
    $rows=static fn(string $sql,array $params):array=>vp3_profile_promotion_native_click_rows_v1($pdo,$sql,$params,$current);
    $c=$rows('SELECT owner_user_id,profile_agent_id,profile_session_id,visitor_user_id,status FROM profile_agent_conversations WHERE id=?',[$cid])[0]??null;
    $session=$rows('SELECT owner_user_id,session_key FROM profile_visit_sessions WHERE id=?',[$proof['profile_session_id']])[0]??null;
    $agent=$rows('SELECT owner_user_id FROM user_agents WHERE id=?',[$proof['profile_agent_id']])[0]??null;
    $product=$rows('SELECT owner_user_id FROM agent_commerce_products_v800 WHERE id=?',[$proof['product_id']])[0]??null;
    if(!$c||!$session||!$agent||!$product||(int)$c['owner_user_id']!==$owner
        ||(int)$session['owner_user_id']!==$owner||(int)$agent['owner_user_id']!==$owner||(int)$product['owner_user_id']!==$owner
        ||(int)$c['profile_agent_id']!==$proof['profile_agent_id']||(int)$c['profile_session_id']!==$proof['profile_session_id']
        ||(int)($c['visitor_user_id']??0)!==$proof['visitor_user_id']
        ||!in_array($c['status'],['open','owner_joined','resolved'],true)
        ||!hash_equals($proof['session_hash'],hash('sha256',(string)$session['session_key'])))return null;
    $bindings=$rows("SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id LIMIT 2",[$cid]);
    if(count($bindings)!==1)return null;
    $binding=json_decode((string)$bindings[0]['context_json'],true);
    if(!vp3_profile_agent_conversation_authority_valid_v1(is_array($binding)?$binding:null,$owner)
        ||$binding['transport']!=='native'||$binding['visitor_user_id']!==$proof['visitor_user_id']
        ||(int)($binding['profile_agent_id']??0)!==$proof['profile_agent_id']
        ||(int)($binding['profile_session_id']??0)!==$proof['profile_session_id']
        ||!hash_equals($proof['credential_hash'],$binding['credential_hash']))return null;
    $visitor=$proof['visitor_user_id'];
    if($visitor>0){
        $user=$rows('SELECT is_active,password_hash FROM users WHERE id=?',[$visitor])[0]??null;
        $blocks=$rows('SELECT blocker_user_id,blocked_user_id FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?)',[$owner,$visitor,$visitor,$owner]);
        if(!$user||empty($user['is_active'])||$blocks
            ||!hash_equals($proof['visitor_auth_revision'],hash('sha256',$visitor.'|'.(string)$user['password_hash'])))return null;
    }
    return $proof;
}

/** Accept only a signed first-party click; browser credentials are checked now. */
function vp3_profile_promotion_accept_click_v1(PDO $pdo,array $record,string $key,array $query): ?array
{
    $claim=profile_product_promotion_click_claim_v1($key,$query);
    $owner=(int)($record['profile']['user_id']??0);$product=(int)($record['card']['product_id']??0);
    if(!$claim||$owner<1||$product<1||($record['card']['key']??null)!==$key)return null;
    if(function_exists('reset_current_user_cache'))reset_current_user_cache();
    $viewer=current_user();
    if((int)($viewer['id']??0)===$owner)return null;
    require_once __DIR__.'/profile-promotion-attribution-v1.php';
    if($claim['placement']==='profile_agent'&&!$pdo->inTransaction()&&!agent_commerce_sqlite_pa2($pdo))
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$record,$key,$claim,$owner,$product,$viewer):?array{
        $proof=null;
        if($claim['placement']==='profile_agent'){
            // A lost browser credential must not create a new identity at click time.
            if(!is_string($_COOKIE['sf_profile_conversation']??null)
                ||!preg_match('/\A[a-f0-9]{64}\z/D',$_COOKIE['sf_profile_conversation'])
                ||!is_string($_COOKIE['sf_profile_visitor']??null)
                ||!preg_match('/\A(?:[a-f0-9]{48}|[a-f0-9]{64})\z/D',$_COOKIE['sf_profile_visitor']))return null;
            require_once __DIR__.'/profile-agent-public-service-v110.php';
            vp3_profile_promotion_owner_current_v1($pdo,$owner);
            $ctx=vp3_profile_agent_public_context_v110($pdo,$record['profile'],$viewer,true);
            $ctx=vp3_profile_agent_public_live_v1($pdo,$ctx,true);
            if(($ctx['conversation_authority']['transport']??null)!=='native')return null;
            vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$claim['conversation_id'],true);
            $proof=['contract'=>VP3_PROFILE_PROMOTION_NATIVE_CLICK_V1,'click_id'=>$claim['click_id'],
                'owner_user_id'=>$owner,'product_id'=>$product,'publication_id'=>$key,
                'public_conversation_id'=>$claim['conversation_id'],'profile_agent_id'=>(int)$ctx['agent_id'],
                'profile_session_id'=>(int)$ctx['session_id'],'visitor_user_id'=>(int)$ctx['conversation_authority']['visitor_user_id'],
                'credential_hash'=>$ctx['conversation_authority']['credential_hash'],
                'session_hash'=>hash('sha256',(string)$ctx['session']['session_key']),
                'visitor_auth_revision'=>(string)($ctx['accepted_visitor_auth_revision']??'')];
        }
        $accepted=profile_promotion_attribution_record_click_v1($pdo,$owner,$key,$claim['placement'],$claim['click_id'],$proof);
        if($proof!==null){
            vp3_profile_promotion_owner_current_v1($pdo,$owner);
            vp3_profile_agent_public_live_v1($pdo,$ctx,true);
            vp3_profile_agent_public_bound_conversation_v1($pdo,$ctx,$claim['conversation_id'],true);
        }
        return $accepted?$claim:null;
    });
}
