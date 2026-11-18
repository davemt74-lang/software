<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/vp3-promotion-capture-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
require __DIR__.'/fixtures/profile-promotion-capture-v1.php';
set_error_handler(static function(int $level,string $message,string $file,int $line):never{throw new ErrorException($message,0,$level,$file,$line);});
$checks=0;
function pcap_check_v1(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function pcap_reject_v1(callable $work,string $label): void
{
    try{$result=$work();}catch(Throwable){pcap_check_v1(true,$label);return;}
    pcap_check_v1($result===false||$result===null,$label);
}
function pcap_evidence_v1(array $f,string $id,int $owner=1,bool $current=false): array
{
    return profile_promotion_public_click_evidence_v1(db(),$owner,$f['publication_id'],$id,$current);
}
function pcap_record_v1(array $f,array $native,string $id): bool
{
    return profile_promotion_attribution_record_click_v1(db(),1,$f['publication_id'],'profile_agent',$id,$native['context'],$native['public_conversation_id']);
}
try{
    $f=pcap_fixture_init_v1();$p=db();
    pcap_check_v1(profile_promotion_capture_ready_v1($p),'canonical installation makes both forward capture fields available');
    $native=pcap_fixture_native_v1();$cid=$native['public_conversation_id'];$click=str_repeat('1',32);
    $before=pcap_fixture_observed_v1();$cookies=$_COOKIE;
    $ctx=profile_promotion_capture_native_context_v1($p,profile_for_user($p,1,false),current_user(),$cid);
    pcap_check_v1(is_array($ctx)&&(int)$ctx['session_id']===(int)$native['context']['session_id']&&$_COOKIE===$cookies&&pcap_fixture_observed_v1()===$before,
        'capturing existing native authority is query-only and retains the exact browser and public conversation');
    $low=(int)floor(microtime(true)*1000);
    pcap_check_v1(pcap_record_v1($f,$native,$click),'the production accepted-click writer stores a verified native binding');
    $row=pcap_fixture_click_v1($click);$evidence=pcap_evidence_v1($f,$click);
    pcap_check_v1(($stored=json_decode($row['native_context_json_v1'],true,8,JSON_THROW_ON_ERROR))['public_conversation_id']===$cid
        &&$stored['product_id']===$f['product']&&preg_match('/\A[a-f0-9]{64}\z/D',$stored['session_hash'])===1,
        'the exact public conversation and immutable native proof are saved together in one closed record');
    pcap_check_v1($evidence['availability']==='ready'&&$evidence['public_conversation_id']===$cid&&$evidence['recorded_at_utc_ms']>=$low-1000&&$evidence['recorded_at_utc_ms']<=(int)floor(microtime(true)*1000),
        'exact evidence returns the recorded UTC click time and no guessed payment or page-view time');
    pcap_check_v1((int)$native['context']['agent_id']===7&&(int)profile_promotion_attribution_publication_v1($p,1,$f['publication_id'])['agent_id']===8,
        'public responder identity is independent of the original private planning Agent');
    pcap_check_v1(pcap_record_v1($f,$native,$click)&&pcap_fixture_click_v1($click)===$row&&pcap_evidence_v1($f,$click)===$evidence,
        'an accepted replay keeps the first immutable click row and source revision');
    pcap_check_v1(pcap_evidence_v1($f,$click,4)['availability']==='unavailable','another owner cannot read the captured public binding');
    pcap_check_v1(profile_promotion_public_click_evidence_v1($p,1,str_repeat('d',32),$click)['availability']==='unavailable',
        'a different publication cannot claim the same captured click');

    foreach([[],['sf_profile_visitor'=>'invalid','sf_profile_conversation'=>str_repeat('b',64)],['sf_profile_visitor'=>str_repeat('a',64)],['sf_profile_visitor'=>str_repeat('a',64),'sf_profile_conversation'=>[]]] as $badCookies){
        $_COOKIE=$badCookies;$unchanged=pcap_fixture_observed_v1();
        pcap_check_v1(profile_promotion_capture_native_context_v1($p,profile_for_user($p,1,false),current_user(),$cid)===null&&$_COOKIE===$badCookies&&pcap_fixture_observed_v1()===$unchanged,
            'missing or malformed existing native cookies cannot create visitor state or manufacture click authority');
    }
    pcap_fixture_restore_browser_v1($native);
    $other=pcap_fixture_native_v1(2,'c');$otherId=$other['public_conversation_id'];
    pcap_check_v1($otherId!==$cid,'the second browser credential has a different canonical public conversation');
    pcap_reject_v1(fn()=>pcap_record_v1($f,$other,$click),'a click replay cannot move its saved public conversation to another browser');
    pcap_check_v1(pcap_fixture_click_v1($click)===$row,'a rejected relocation leaves the original captured evidence intact');
    pcap_fixture_restore_browser_v1($native);
    pcap_reject_v1(fn()=>profile_promotion_capture_native_context_v1($p,profile_for_user($p,1,false),current_user(),$otherId),
        'an existing but differently bound public conversation cannot be selected by its numeric ID');
    pcap_reject_v1(fn()=>profile_promotion_capture_native_context_v1($p,profile_for_user($p,1,false),current_user(),12),
        'the private planning Chat ID does not grant a public conversation binding');
    pcap_reject_v1(fn()=>profile_promotion_attribution_record_click_v1($p,4,$f['publication_id'],'profile_agent',str_repeat('2',32),$native['context'],$cid),
        'a native context cannot be assigned to another owner');
    pcap_reject_v1(fn()=>profile_promotion_attribution_record_click_v1($p,1,$f['publication_id'],'profile',str_repeat('2',32),$native['context'],$cid),
        'an unthreaded Profile placement cannot carry a native conversation');
    pcap_reject_v1(fn()=>profile_promotion_attribution_record_click_v1($p,1,$f['publication_id'],'profile_agent',str_repeat('2',32),null,$cid),
        'a signed conversation ID alone is insufficient without current native credentials');

    $legacy=str_repeat('3',32);
    pcap_check_v1(profile_promotion_attribution_record_click_v1($p,1,$f['publication_id'],'profile_agent',$legacy),
        'the retained placement-only writer remains usable without inventing native identity');
    $legacyRow=pcap_fixture_click_v1($legacy);
    pcap_check_v1(pcap_evidence_v1($f,$legacy)['availability']==='unattributed'&&pcap_evidence_v1($f,$legacy)['public_conversation_id']===null,
        'a legacy Profile Agent click remains valid placement evidence with explicitly unattributed conversation coverage');
    pcap_check_v1(pcap_record_v1($f,$native,$legacy)&&pcap_fixture_click_v1($legacy)===$legacyRow,
        'replaying an older NULL-bound click never backfills it from the current browser');
    $plain=str_repeat('4',32);
    pcap_check_v1(profile_promotion_attribution_record_click_v1($p,1,$f['publication_id'],'profile',$plain)
        &&pcap_evidence_v1($f,$plain)['availability']==='ready'&&pcap_evidence_v1($f,$plain)['public_conversation_id']===null,
        'a public Profile placement truthfully has no public conversation');

    $snapshot=pcap_fixture_observed_v1();$reads=[];
    $p->beforeSql=static function(string $sql)use(&$reads):void{
        $reads[]=$sql;if(preg_match('/\A\s*(?:CREATE|ALTER|INSERT|UPDATE|DELETE|REPLACE|DROP)\b/i',$sql))throw new LogicException('Evidence read attempted a mutation.');
    };
    try{$p->beginTransaction();$current=pcap_evidence_v1($f,$click,1,true);$kept=$p->inTransaction();$p->rollBack();}
    finally{$p->beforeSql=null;if($p->inTransaction())$p->rollBack();}
    pcap_check_v1($current===$evidence&&$kept&&pcap_fixture_observed_v1()===$snapshot&&count($reads)>3,
        'current evidence holds exact source reads inside the caller transaction without DDL, delivery, or other writes');
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $cfg=$GLOBALS['pa3Config'];$writer=new PDO($cfg['dsn'],$cfg['user'],$cfg['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $writer->exec('SET SESSION innodb_lock_wait_timeout=1');
        $p->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();
        try{
            $snapshotProof=pcap_evidence_v1($f,$click);
            $writer->exec('UPDATE users SET is_active=0 WHERE id=1');
            pcap_check_v1(pcap_evidence_v1($f,$click)===$snapshotProof,'native repeatable-read fixture actually retains an older owner snapshot');
            pcap_check_v1(pcap_evidence_v1($f,$click,1,true)['availability']==='unavailable'&&$p->inTransaction(),
                'current native evidence sees a concurrently revoked owner without ending the caller transaction');
        }finally{if($p->inTransaction())$p->rollBack();$writer->exec('UPDATE users SET is_active=1 WHERE id=1');}
        pcap_check_v1(pcap_evidence_v1($f,$click,1,true)['availability']==='unavailable',
            'native current proof cannot be certified without a caller transaction');
        $p->beginTransaction();
        try{
            pcap_check_v1(pcap_evidence_v1($f,$click,1,true)===$evidence,'native current proof locks and returns the exact committed source');
            foreach([
                ['UPDATE profile_visit_sessions SET session_key=? WHERE id=?',[str_repeat('e',64),(int)$native['context']['session_id']],'session'],
                ['UPDATE user_agents SET owner_user_id=4 WHERE id=?',[(int)$native['context']['agent_id']],'Agent owner'],
                ["UPDATE profile_agent_messages SET context_json='{}' WHERE conversation_id=? AND sender_type='system'",[$cid],'hidden binding'],
            ] as [$sql,$parameters,$label]){
                try{$writer->prepare($sql)->execute($parameters);throw new LogicException('Exact source row was not held.');}
                catch(PDOException $error){pcap_check_v1((int)($error->errorInfo[1]??0)===1205&&$p->inTransaction(),
                    'native current proof holds the exact '.$label.' row against a concurrent mutation');}
            }
        }finally{if($p->inTransaction())$p->rollBack();$writer=null;}
    }

    // Historical owner evidence must survive ordinary visitor/session lifecycle changes.
    $system=$p->prepare("SELECT id,context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system'");$system->execute([$cid]);$bindingRow=$system->fetch();
    $originalBinding=json_decode($bindingRow['context_json'],true,16,JSON_THROW_ON_ERROR);
    $p->beginTransaction();vp3_profile_agent_response_token_v1($p,$cid,true);$p->commit();
    pcap_check_v1(pcap_evidence_v1($f,$click)===$evidence,'rotating a mutable response-fence token cannot invalidate historical click attribution');
    $p->prepare("UPDATE profile_agent_conversations SET status='resolved',last_summary='Updated summary' WHERE id=?")->execute([$cid]);
    $p->prepare('UPDATE profile_visit_sessions SET visitor_user_id=3,identity_disclosed=0,view_count=view_count+1,last_seen_at=NOW() WHERE id=?')->execute([$native['context']['session_id']]);
    $p->exec("UPDATE users SET password_hash='changed-password',is_active=0 WHERE id=2");
    $p->exec("UPDATE user_agents SET is_active=0,display_name='Retired guide' WHERE id=7");
    $p->exec("UPDATE user_profiles SET profile_agent_enabled=0,profile_agent_id=8,username='renamed-owner',is_public=0 WHERE user_id=1");
    $_COOKIE=[];pa3_login(0);
    pcap_check_v1(pcap_evidence_v1($f,$click)===$evidence,
        'resolved threads, retired public Agents, renamed private Profiles, changed CRM contact and expired visitor access preserve immutable historical proof');
    $p->exec('UPDATE users SET is_active=0 WHERE id=1');
    pcap_check_v1(pcap_evidence_v1($f,$click)['availability']==='unavailable','historical evidence still requires the current owner account');
    $p->exec('UPDATE users SET is_active=1 WHERE id=1');
    $p->exec('UPDATE user_agents SET owner_user_id=4 WHERE id=7');
    pcap_check_v1(pcap_evidence_v1($f,$click)['availability']==='unavailable','a reassigned public Agent cannot retain another owner’s captured binding');
    $p->exec('UPDATE user_agents SET owner_user_id=1 WHERE id=7');
    $sid=(int)$native['context']['session_id'];$oldKey=$native['context']['session']['session_key'];
    $p->prepare('UPDATE profile_visit_sessions SET session_key=? WHERE id=?')->execute([str_repeat('f',64),$sid]);
    pcap_check_v1(pcap_evidence_v1($f,$click)['availability']==='unavailable','changing the immutable native session key invalidates association proof');
    $p->prepare('UPDATE profile_visit_sessions SET session_key=? WHERE id=?')->execute([$oldKey,$sid]);
    foreach(['credential_hash'=>str_repeat('e',64),'transport'=>'external_site','profile_session_id'=>$sid+100,'visitor_user_id'=>3] as $field=>$value){
        $bad=$originalBinding;$bad[$field]=$value;
        $p->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=?')->execute([json_encode($bad,JSON_THROW_ON_ERROR),$bindingRow['id']]);
        pcap_check_v1(pcap_evidence_v1($f,$click)['availability']==='unavailable','a changed '.$field.' cannot pass the saved immutable native proof');
    }
    foreach(['{bad',str_repeat(' ',65537)] as $bad){
        $p->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=?')->execute([$bad,$bindingRow['id']]);
        pcap_check_v1(pcap_evidence_v1($f,$click)['availability']==='unavailable','malformed or oversized hidden native proof is unavailable, never a zero count');
    }
    $p->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=?')->execute([$bindingRow['context_json'],$bindingRow['id']]);
    $duplicate=pa5_fixture_insert('profile_agent_messages',['conversation_id'=>$cid,'sender_type'=>'system','message'=>'','context_json'=>$bindingRow['context_json']]);
    pcap_check_v1(pcap_evidence_v1($f,$click)['availability']==='unavailable','multiple hidden binding rows cannot be treated as one verified conversation');
    $p->prepare('DELETE FROM profile_agent_messages WHERE id=?')->execute([$duplicate]);
    pcap_check_v1(pcap_evidence_v1($f,$click)===$evidence,'restoring the exact canonical proof restores the same semantic revision');
    $p->prepare('UPDATE agent_promotion_publications_v1 SET draft_key=? WHERE publication_id=?')->execute([str_repeat('z',64),$f['publication_id']]);
    pcap_check_v1(pcap_evidence_v1($f,$click)['availability']==='unavailable','malformed immutable publication scope cannot become verified click evidence');
    $p->prepare('UPDATE agent_promotion_publications_v1 SET draft_key=? WHERE publication_id=?')->execute([$f['draft_key'],$f['publication_id']]);
    $p->exec("UPDATE users SET password_hash='visitor-a-password',is_active=1 WHERE id=2");
    $p->exec("UPDATE user_agents SET is_active=1,display_name='Fixture Public Guide' WHERE id=7");
    $p->exec("UPDATE user_profiles SET profile_agent_enabled=1,profile_agent_id=7,username='fixture-owner',is_public=1 WHERE user_id=1");
    pcap_fixture_restore_browser_v1($native);

    // The UTC evidence is explicit even if PHP and the database use other zones.
    $zone=date_default_timezone_get();date_default_timezone_set('Pacific/Honolulu');
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$p->exec("SET time_zone='-07:00'");
    try{$timeClick=str_repeat('5',32);$timeLow=(int)floor(microtime(true)*1000);$timed=pcap_record_v1($f,$native,$timeClick);$timeProof=pcap_evidence_v1($f,$timeClick);}
    finally{date_default_timezone_set($zone);if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$p->exec("SET time_zone='+00:00'");}
    pcap_check_v1($timed&&$timeProof['availability']==='ready'&&$timeProof['recorded_at_utc_ms']>=$timeLow-1000&&$timeProof['recorded_at_utc_ms']<=(int)floor(microtime(true)*1000),
        'application-recorded click time cannot be shifted by PHP or database session timezone');
    $newKey=str_repeat('d',32);$p->prepare('UPDATE agent_commerce_promotions_v1 SET public_key=? WHERE id=?')->execute([$newKey,$f['promotion_id']]);
    pcap_check_v1(!pcap_record_v1($f,$native,str_repeat('6',32))&&pcap_evidence_v1($f,$click)===$evidence,
        'publication rotation rejects old live links while preserving the exact historical publication proof');
    $p->prepare('UPDATE agent_commerce_promotions_v1 SET public_key=? WHERE id=?')->execute([$f['publication_id'],$f['promotion_id']]);

    $faultClick=str_repeat('7',32);$inserts=0;
    $p->afterSql=static function(string $sql,PA3PDO $connection)use(&$inserts):void{
        if(str_starts_with($sql,'INSERT INTO agent_promotion_clicks_v1')){$inserts++;$connection->afterSql=null;throw new PDOException('Uncertain insert acknowledgement.');}
    };
    try{pcap_reject_v1(fn()=>pcap_record_v1($f,$native,$faultClick),'an uncertain click INSERT is not retried with an older SQL shape');}finally{$p->afterSql=null;}
    pcap_check_v1($inserts===1&&pcap_fixture_click_v1($faultClick)===null&&!$p->inTransaction(),
        'failure after a click INSERT rolls back the complete transaction without a fallback duplicate');
    pcap_check_v1(pcap_record_v1($f,$native,$faultClick)&&pcap_fixture_click_v1($faultClick)['native_context_json_v1']!==null,
        'a later explicit retry records one verified click after the failed transaction');
    $lateClick=str_repeat('b',32);$revoked=false;$rejected=false;
    $p->afterSql=static function(string $sql,PA3PDO $connection)use(&$revoked):void{
        if(str_starts_with($sql,'INSERT INTO agent_promotion_clicks_v1')){
            $connection->afterSql=null;
            $connection->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'");
            $revoked=true;
        }
    };
    try{pcap_record_v1($f,$native,$lateClick);}catch(VP3ProfileAgentPublicException){$rejected=true;}finally{$p->afterSql=null;}
    pcap_check_v1($revoked&&$rejected&&pcap_fixture_click_v1($lateClick)===null&&!$p->inTransaction()
        &&(int)$p->query("SELECT COUNT(*) FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'")->fetchColumn()===1,
        'the canonical context writer rechecks authority after insertion and rolls back both click and late revocation');
    $p->beforeSql=static function(string $sql):void{if(str_contains($sql,'SELECT native_context_json_v1'))throw new PDOException('Operational schema read failure.');};
    try{
        pcap_check_v1(pcap_evidence_v1($f,$click)['availability']==='unavailable','a failed schema read is unavailable evidence');
        pcap_reject_v1(fn()=>pcap_record_v1($f,$native,str_repeat('8',32)),'an operational schema error cannot silently choose the legacy INSERT');
    }finally{$p->beforeSql=null;}
    pcap_check_v1(pcap_fixture_click_v1(str_repeat('8',32))===null,'failed schema verification writes no click');
    $retry=new PDOException('Deadlock');$retry->errorInfo=['40001',1213,'Deadlock'];
    $p->beforeSql=static function(string $sql)use($retry):void{if(str_contains($sql,'SELECT native_context_json_v1'))throw $retry;};
    try{try{pcap_evidence_v1($f,$click);throw new LogicException('Retry was swallowed.');}catch(PDOException $error){pcap_check_v1($error===$retry,'retryable native transaction failures propagate to the caller recovery boundary');}}
    finally{$p->beforeSql=null;}

    // Definite missing columns preserve the old writer, without a read migration.
    $p->exec('ALTER TABLE agent_promotion_clicks_v1 DROP COLUMN native_context_json_v1');
    pcap_check_v1(!profile_promotion_capture_ready_v1($p)&&pcap_evidence_v1($f,$click)['availability']==='unavailable',
        'a partially deployed capture schema is explicitly unavailable and is not installed by a read');
    $oldShape=str_repeat('9',32);
    pcap_check_v1(pcap_record_v1($f,$native,$oldShape)&&!column_exists('agent_promotion_clicks_v1','native_context_json_v1'),
        'the canonical click writer remains available on the definitely older schema without read-time DDL');
    profile_promotion_capture_install_v1($p,'clicks');$oldRow=pcap_fixture_click_v1($oldShape);
    pcap_check_v1($oldRow['native_context_json_v1']===null,
        'explicit upgrade leaves old click association proof NULL');
    pcap_check_v1(pcap_record_v1($f,$native,$oldShape)&&pcap_fixture_click_v1($oldShape)===$oldRow,
        'an upgraded legacy replay never guesses its historical public conversation');
    $p->beginTransaction();
    try{pcap_reject_v1(fn()=>profile_promotion_capture_install_v1($p),'capture installation refuses caller-owned transactions');pcap_check_v1($p->inTransaction(),'rejected migration preserves the caller transaction');}
    finally{$p->rollBack();}
    $unchanged=pcap_fixture_observed_v1();profile_promotion_capture_install_v1($p);profile_promotion_capture_install_v1($p);
    pcap_check_v1(profile_promotion_capture_ready_v1($p)&&pcap_fixture_observed_v1()===$unchanged,'repeated explicit installation is idempotent and performs no historical backfill');
    pcap_check_v1($GLOBALS['pa3ModelCalls']===[]&&$GLOBALS['pa5ProviderRequests']===[],'capture and evidence never invoke inference or a payment provider');
    echo 'PROFILE_PROMOTION_CAPTURE_V1=PASS driver='.$p->getAttribute(PDO::ATTR_DRIVER_NAME).' checks='.$checks.PHP_EOL;
}finally{
    if(isset($p)){$p->beforeSql=null;$p->afterSql=null;$p->failSql='';if($p->inTransaction())$p->rollBack();}
    restore_error_handler();
    foreach(['original','promotion'] as $name)@unlink($root.'/private/user-media/1/'.$name.'.png');
    foreach(['/private/user-media/1','/private/user-media','/private',''] as $path)@rmdir($root.$path);
}
