<?php
declare(strict_types=1);

session_start();
$root=sys_get_temp_dir().'/vp3-native-click-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
require __DIR__.'/fixtures/profile-promotion-native-click-v1.php';
$checks=0;
function native_check_v1(bool $ok,string $label):void
{if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
function native_rows_v1():array{return db()->query('SELECT * FROM agent_promotion_clicks_v1 ORDER BY click_id')->fetchAll(PDO::FETCH_ASSOC);}
function native_reject_v1(callable $call,string $label):void
{
    $before=native_rows_v1();$rejected=false;
    try{$rejected=$call()===null;}catch(VP3ProfileAgentPublicException){$rejected=true;}
    native_check_v1($rejected&&native_rows_v1()===$before,$label);
}
function native_mutate_v1(callable $work):void
{
    $session=$_SESSION;$cookies=$_COOKIE;
    db()->beginTransaction();
    try{$work();}finally{
        if(db()->inTransaction())db()->rollBack();
        // Each revoked-login scenario uses the real authentication boundary;
        // its cleared browser login must not leak into the next fixture case.
        $_SESSION=$session;$_COOKIE=$cookies;reset_current_user_cache();
    }
}

try{
    $f=native_click_fixture_v1();$pdo=db();$key=$f['key'];
    $record=profile_product_promotion_key_record_v1($pdo,$key);
    $publication=profile_promotion_attribution_publication_v1($pdo,1,$key);
    native_check_v1($f['cid']===11&&(int)$publication['conversation_id']===11,'public conversation and original private Chat may have the same number without sharing a namespace');
    native_check_v1(vp3_profile_promotion_native_click_ready_v1($pdo),'explicit Commerce install provides nullable native click storage');
    $query=native_click_fixture_query_v1($key,$f['cid']);
    $accepted=vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$query);
    $click=native_rows_v1()[0];$proof=vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication);
    native_check_v1($accepted!==null&&count(native_rows_v1())===1&&$proof!==null&&$proof['public_conversation_id']===11
        &&$proof['visitor_user_id']===2&&$proof['click_id']===$accepted['click_id'],'signed native click records exact public conversation, visitor credential and publication proof atomically');
    $snapshot=serialize(native_rows_v1());
    native_check_v1(vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$query)===$accepted&&serialize(native_rows_v1())===$snapshot,'accepted click replay preserves the original row, UTC timestamp and native proof');
    native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,array_replace($query,['conversation'=>'12'])),'tampering with the public conversation invalidates the signed link');
    native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,12)),'a signed private-only Chat number cannot establish a public conversation association');
    $cookie=$_COOKIE['sf_profile_conversation'];$_COOKIE['sf_profile_conversation']=str_repeat('c',64);
    native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$query),'another native browser credential cannot replay an accepted link');
    $_COOKIE['sf_profile_conversation']=$cookie;
    unset($_COOKIE['sf_profile_visitor']);$cookies=$_COOKIE;
    native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$query),'missing visitor browser cookie cannot recover native authority from CRM alone');
    native_check_v1($_COOKIE===$cookies,'click rejection does not manufacture a new visitor cookie');
    $_COOKIE['sf_profile_visitor']=str_repeat('a',64);
    $session=$_SESSION;pa3_login(3);$_SESSION['vp3_promotion_click_secret_v1']=$session['vp3_promotion_click_secret_v1'];
    native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$query),'a different signed-in member cannot reuse the original native conversation');
    $_SESSION=$session;reset_current_user_cache();
    $expired=array_replace($query,['expires'=>(string)(time()-1)]);
    $expired['proof']=hash_hmac('sha256',profile_product_promotion_click_payload_v1($key,'profile_agent',11,(int)$expired['expires']),$_SESSION['vp3_promotion_click_secret_v1']);
    native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$expired),'an expired signed issuance cannot record a click');

    foreach(['click_id'=>str_repeat('f',32),'publication_id'=>str_repeat('e',32),'product_id'=>$f['product']+1,'credential_hash'=>str_repeat('0',64),'public_conversation_id'=>12] as $field=>$value){
        $bad=$click;$bad['native_context_json_v1']=json_encode(array_replace($proof,[$field=>$value]),JSON_THROW_ON_ERROR);
        native_check_v1(vp3_profile_promotion_native_click_proof_v1($pdo,$bad,$publication)===null,'saved proof cannot transplant or change '.$field);
    }
    native_mutate_v1(function()use($pdo,$click,$publication,$record,$key,$query){
        $pdo->exec("UPDATE agent_commerce_promotions_v1 SET status='paused'");
        native_check_v1(vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication,true)!==null,'a paused publication retains historical native lineage for an authorized private reader');
        native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$query),'a pause blocks new accepted click attribution');
    });
    native_mutate_v1(function()use($pdo,$click,$publication,$record,$key,$query){
        $pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
        native_check_v1(vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication,true)!==null,'public Profile visibility does not rewrite historical native proof');
        native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$query),'current public visibility is still required to accept a new native click');
    });
    foreach([
        ['UPDATE user_agents SET owner_user_id=4 WHERE id=7','transferred public Agent'],
        ['UPDATE agent_commerce_products_v800 SET owner_user_id=4 WHERE id='.$f['product'],'transferred product'],
        ["UPDATE profile_visit_sessions SET session_key='changed'",'changed session binding'],
    ] as [$sql,$label])native_mutate_v1(function()use($pdo,$sql,$label,$click,$publication){
        $pdo->exec($sql);native_check_v1(vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication,true)===null,$label.' withholds current public association proof');
    });
    foreach([
        ["UPDATE users SET password_hash='changed' WHERE id=2",'changed visitor authentication'],
        ['UPDATE users SET is_active=0 WHERE id=2','inactive visitor'],
        ['INSERT INTO user_blocks (blocker_user_id,blocked_user_id) VALUES (1,2)','current owner block'],
    ] as [$sql,$label])native_mutate_v1(function()use($pdo,$sql,$label,$click,$publication,$proof,$record,$key){
        $pdo->exec($sql);
        native_check_v1(vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication,true)===$proof,
            $label.' preserves immutable historical association for the authorized private owner');
        native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,4)),
            $label.' still rejects newly accepted native clicks');
    });
    $before=serialize(native_rows_v1());$sessions=serialize($_SESSION);$cookies=serialize($_COOKIE);
    $pdo->beginTransaction();
    $pdo->beforeSql=static function(string $sql):void{if(!preg_match('/\ASELECT\b/i',$sql))throw new RuntimeException('Proof reader attempted a write.');};
    try{$r=vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication,true);}finally{$pdo->beforeSql=null;$pdo->rollBack();}
    native_check_v1($r===$proof&&serialize(native_rows_v1())===$before&&serialize($_SESSION)===$sessions&&serialize($_COOKIE)===$cookies,'current proof reread performs only SELECTs and leaves session, cookies and stored clicks unchanged');
    $pdo->beforeSql=static function(string $sql):void{if(str_contains($sql,'FROM profile_agent_conversations'))throw new PDOException('fixture unavailable');};
    $unavailable=false;try{vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication);}catch(PDOException){$unavailable=true;}finally{$pdo->beforeSql=null;}
    native_check_v1($unavailable,'storage failure propagates as unavailable rather than an unproved or zero count');
    $late=false;
    $pdo->beforeSql=static function(string $sql,$connection)use(&$late):void{
        if(str_starts_with($sql,'SELECT id,owner_user_id,profile_agent_id,profile_session_id,visitor_user_id,status FROM profile_agent_conversations')){
            $connection->beforeSql=null;$connection->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'");$late=true;
        }
    };
    try{native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,4)),'late owner capability revocation rolls back the native click and its proof');}
    finally{$pdo->beforeSql=null;}
    native_check_v1($late&&(int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'")->fetchColumn()===1,'late authority fault occurred after initial admission and its transaction rolled back fully');
    native_mutate_v1(function()use($pdo){
        $pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='personal_capabilities_seed_v242'");
        $pdo->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key IN ('profile_agent.access','profile_chat.access')");
        vp3_profile_promotion_owner_current_v1($pdo,1);
        native_check_v1(true,'current owner fence preserves canonical personal defaults before capability seeding');
    });
    native_mutate_v1(function()use($pdo,$record,$key){
        $pdo->exec("DELETE FROM settings WHERE setting_key='personal_capabilities_seed_v242'");
        $before=native_rows_v1();$unavailable=false;
        try{vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,4));}
        catch(RuntimeException $error){$unavailable=$error->getCode()===503;}
        native_check_v1($unavailable&&$pdo->inTransaction()&&native_rows_v1()===$before,
            'a missing capability marker cannot admit a click or end the caller transaction');
    });
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $cfg=$GLOBALS['pa3Config'];$other=new PDO($cfg['dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        try{
            native_check_v1(vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication)!==null,'ordinary historical proof establishes the caller repeatable-read snapshot');
            $other->exec("UPDATE users SET password_hash='revoked-after-snapshot' WHERE id=2");
            native_check_v1(vp3_profile_promotion_native_click_proof_v1($pdo,$click,$publication,true)===$proof&&$pdo->inTransaction(),
                'current historical proof survives a later visitor password change without ending the caller transaction');
            native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,4)),
                'new admission rejects visitor authentication changed beyond the caller repeatable-read snapshot');
        }finally{
            if($pdo->inTransaction())$pdo->rollBack();
            $other->exec("UPDATE users SET password_hash='visitor-a-password' WHERE id=2");
        }
        $pdo->beginTransaction();
        try{
            $pdo->query("SELECT * FROM role_permissions WHERE role='member'")->fetchAll();
            $other->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'");
            native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,4)),'current owner grant fence rejects revocation hidden by an older caller repeatable-read snapshot');
            native_check_v1($pdo->inTransaction(),'rejected native acceptance preserves the caller transaction');
        }finally{
            if($pdo->inTransaction())$pdo->rollBack();
            $other->exec("INSERT INTO role_permissions (role,permission_key) VALUES ('member','profile_chat.access')");
        }
        $other->exec('SET innodb_lock_wait_timeout=1');
        $scalar=static function(PDO $connection,string $sql):mixed{
            $q=$connection->query($sql);$value=$q->fetchColumn();$q->closeCursor();return $value;
        };
        $display=$scalar($other,'SELECT display_name FROM users WHERE id=4');
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$pdo->beginTransaction();
        try{
            $prior=$scalar($pdo,'SELECT display_name FROM users WHERE id=4');
            $other->exec("UPDATE users SET display_name='Concurrent isolation sentinel' WHERE id=4");
            native_check_v1($scalar($pdo,'SELECT @@session.transaction_isolation')==='REPEATABLE-READ'
                &&$prior!=='Concurrent isolation sentinel'&&$scalar($pdo,'SELECT display_name FROM users WHERE id=4')==='Concurrent isolation sentinel',
                'ordinary reads prove an active RC transaction despite its RR session default');
            $before=count(native_rows_v1());
            $claimed=vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,4));
            native_check_v1($claimed!==null&&$pdo->inTransaction()&&count(native_rows_v1())===$before+1,
                'current positive authority permits exact native acceptance inside the caller RC transaction');
            $blocked=false;
            try{$other->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'");}
            catch(PDOException $error){$blocked=(int)($error->errorInfo[1]??0)===1205;}
            native_check_v1($blocked&&$pdo->inTransaction(),
                'accepted native click holds its exact grant against revocation until the caller releases the RC transaction');
        }finally{
            if($pdo->inTransaction())$pdo->rollBack();
            $other->prepare('UPDATE users SET display_name=? WHERE id=4')->execute([$display]);
        }
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
        try{
            $sql="SELECT COUNT(*) FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'";
            $prior=$scalar($pdo,$sql);$other->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'");
            native_check_v1($scalar($pdo,'SELECT @@session.transaction_isolation')==='READ-COMMITTED'
                &&(int)$prior===1&&$scalar($pdo,$sql)===$prior,
                'ordinary reads prove an active stale RR snapshot despite its RC session default');
            native_reject_v1(fn()=>vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,4)),
                'current native authority rejects a revoked grant independently of the misleading session isolation label');
            native_check_v1($pdo->inTransaction(),'isolation mismatch denial preserves the caller transaction');
        }finally{
            if($pdo->inTransaction())$pdo->rollBack();
            $other->exec("INSERT INTO role_permissions (role,permission_key) VALUES ('member','profile_chat.access')");
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
    }

    $profileQuery=native_click_fixture_query_v1($key,0,0,'profile');
    $profileAccepted=vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$profileQuery);
    $profileClick=array_values(array_filter(native_rows_v1(),static fn(array $row):bool=>$row['placement']==='profile'))[0];
    native_check_v1($profileAccepted!==null&&$profileClick['native_context_json_v1']===null,'a Profile-page click has no inferred public conversation');
    $before=native_rows_v1();$pdo->afterSql=static function(string $sql):void{if(str_starts_with($sql,'INSERT INTO agent_promotion_clicks_v1'))throw new PDOException('fixture failure after insert');};
    $failed=false;try{vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,1));}catch(PDOException){$failed=true;}finally{$pdo->afterSql=null;}
    native_check_v1($failed&&native_rows_v1()===$before,'uncertain click storage rolls back the whole click and proof without retrying another insert shape');

    $pdo->exec('ALTER TABLE agent_promotion_clicks_v1 RENAME COLUMN native_context_json_v1 TO prior_native_context_json_v1');
    $legacyQuery=native_click_fixture_query_v1($key,11,2);
    $legacy=vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$legacyQuery);
    native_check_v1($legacy!==null&&!vp3_profile_promotion_native_click_ready_v1($pdo),'pre-upgrade native click remains canonical without implicit schema installation');
    vp3_profile_promotion_native_click_install_v1($pdo);vp3_profile_promotion_native_click_install_v1($pdo);
    $legacyRow=array_values(array_filter(native_rows_v1(),static fn(array $row):bool=>$row['click_id']===$legacy['click_id']))[0];
    native_check_v1($legacyRow['native_context_json_v1']===null,'idempotent explicit migration leaves old native associations unknown');
    vp3_profile_promotion_accept_click_v1($pdo,$record,$key,$legacyQuery);
    $legacyRow=array_values(array_filter(native_rows_v1(),static fn(array $row):bool=>$row['click_id']===$legacy['click_id']))[0];
    native_check_v1($legacyRow['native_context_json_v1']===null,'replay never backfills a legacy click with new association proof');
    $fresh=vp3_profile_promotion_accept_click_v1($pdo,$record,$key,native_click_fixture_query_v1($key,11,3));
    $freshRow=array_values(array_filter(native_rows_v1(),static fn(array $row):bool=>$row['click_id']===$fresh['click_id']))[0];
    native_check_v1(vp3_profile_promotion_native_click_proof_v1($pdo,$freshRow,$publication)!==null,'a new accepted click after upgrade carries its own complete native proof');
    echo 'PROFILE_PROMOTION_NATIVE_CLICK_V1=PASS driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).' checks='.$checks.PHP_EOL;
}finally{
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);}
}
