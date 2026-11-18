<?php
declare(strict_types=1);
require __DIR__.'/fixtures/commerce-recovery-pa2.php';
$file=sys_get_temp_dir().'/vp3-checkout-clock-'.bin2hex(random_bytes(8)).'.sqlite';
$config=commerce_fixture_config_pa2($file);$pdo=commerce_fixture_connect_pa2($config);$checks=0;
function coc_check_v1(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function coc_reject_v1(callable $work,string $label): Throwable
{
    try{$work();}catch(Throwable $error){coc_check_v1(true,$label);return $error;}
    throw new RuntimeException($label.' unexpectedly succeeded.');
}
function coc_order_v1(string $provider='stripe'): array
{
    $f=commerce_fixture_order_pa2(db(),['provider_snapshot'=>$provider]);
    db()->prepare('DELETE FROM agent_commerce_checkout_attempts_v800 WHERE id=?')->execute([$f['attempt_id']]);return $f;
}
function coc_call_v1(array $f,?PDO $connection=null): array
{
    $connection??=db();
    return agent_commerce_create_checkout_v800($connection,agent_commerce_order_v800($connection,$f['id']),$f['connection_id'],
        'https://fixture.example.test/return','https://fixture.example.test/cancel');
}
function coc_row_v1(array $f,?PDO $connection=null): ?array
{
    $q=($connection??db())->prepare('SELECT * FROM agent_commerce_checkout_attempts_v800 WHERE order_id=? ORDER BY id');$q->execute([$f['id']]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();if(count($rows)>1)throw new RuntimeException('Duplicate checkout attempts.');return $rows[0]??null;
}
function coc_response_v1(array $f,string $name='created'): array
{
    $provider=$f['provider'];$session='clock_'.$provider.'_'.$f['id'].'_'.$name;$url='https://provider.example.test/'.$session;
    return ['status'=>200,'json'=>match($provider){
        'stripe'=>['id'=>$session,'url'=>$url],
        'square'=>['payment_link'=>['id'=>$session,'order_id'=>'order_'.$session,'url'=>$url]],
        'paypal'=>['id'=>$session,'links'=>[['rel'=>'approve','href'=>$url]]],
    }];
}
function coc_transport_v1(array $f,?callable $before=null): void
{
    $GLOBALS['pa2_http']=static function(string $method,string $url,array $headers,mixed $body,bool $form)use($f,$before):array{
        if(str_ends_with($url,'/v1/oauth2/token'))return ['status'=>200,'json'=>['access_token'=>'fixture-clock-token']];
        if($method!=='POST')throw new RuntimeException('Unexpected checkout transport.');
        if($before)$before($headers,$body,$form);return coc_response_v1($f);
    };
}
try{
    commerce_fixture_schema_pa2($pdo);
    coc_check_v1(profile_promotion_capture_checkout_clock_v1($pdo),'canonical Commerce installation includes the nullable checkout-open clock');
    foreach(['stripe','square','paypal'] as $provider){
        $f=coc_order_v1($provider);$writes=[];$uncertain=true;$reserved=null;
        coc_transport_v1($f,static function(array $headers,mixed $body,bool $form)use($f,&$writes,&$uncertain,&$reserved):void{
            $reserved=coc_row_v1($f);$writes[]=[$headers,$body,$form];
            coc_check_v1($reserved['status']==='creating'&&$reserved['checkout_url']===null&&$reserved['opened_at_utc_ms']===null&&!db()->inTransaction(),
                $f['provider'].' reservations and in-flight provider requests have no checkout-open time');
            if($uncertain)throw new RuntimeException('Provider response was lost.');
        });
        coc_reject_v1(fn()=>coc_call_v1($f),$provider.' uncertain provider creation is not locally usable checkout evidence');
        $pending=coc_row_v1($f);
        coc_check_v1($pending['status']==='creating'&&$pending['opened_at_utc_ms']===null&&$pending['creation_lease_token']==='',
            $provider.' an unverified provider outcome remains retryable and unclocked');
        $uncertain=false;$low=(int)floor(microtime(true)*1000);$saved=coc_call_v1($f);$high=(int)floor(microtime(true)*1000);
        coc_check_v1($saved['status']==='open'&&(int)$saved['opened_at_utc_ms']>=$low&&(int)$saved['opened_at_utc_ms']<=$high&&$writes[0]===$writes[1],
            $provider.' the first committed usable link receives application UTC time on the original operation');
        $again=coc_call_v1($f);
        coc_check_v1($again['opened_at_utc_ms']===$saved['opened_at_utc_ms']&&count($writes)===2&&coc_row_v1($f)['id']===$saved['id'],
            $provider.' open checkout replay never rewrites the first time or calls the provider again');
    }

    // Fail before and after the real completion UPDATE, with the original SQL shape.
    foreach(['before_update','after_update','after_audit'] as $fault){
        $f=coc_order_v1();$writes=0;$fail=true;$updated=0;
        coc_transport_v1($f,static function()use(&$writes,&$fail,$fault,&$updated):void{
            $writes++;
            if(!$fail)return;
            if($fault==='before_update')db()->failSql='SET external_session_id=';
            else db()->afterSql=static function(string $sql,?array $params,PA2CommercePDO $connection)use($fault,&$updated):void{
                if(str_contains($sql,'SET external_session_id=')){
                    $updated++;if($fault==='after_update'){$connection->afterSql=null;throw new PDOException('Completion UPDATE acknowledgement lost.');}
                }
                if($fault==='after_audit'&&str_contains($sql,'INSERT INTO agent_commerce_audit_v800')&&in_array('checkout_created',$params??[],true)){
                    $connection->afterSql=null;throw new PDOException('Completion audit acknowledgement lost.');
                }
            };
        });
        try{coc_reject_v1(fn()=>coc_call_v1($f),$fault.' rejects an uncommitted checkout completion');}
        finally{$pdo->failSql='';$pdo->afterSql=null;}
        $attempt=coc_row_v1($f);
        coc_check_v1($attempt['status']==='creating'&&$attempt['opened_at_utc_ms']===null&&$attempt['checkout_url']===null&&$writes===1&&!$pdo->inTransaction(),
            $fault.' rolls back the clock and usable link without a fallback completion write');
        $fail=false;$low=(int)floor(microtime(true)*1000);$saved=coc_call_v1($f);
        coc_check_v1($writes===2&&$saved['id']===$attempt['id']&&(int)$saved['opened_at_utc_ms']>=$low,
            $fault.' later explicit retry records its actual first committed open time');
    }

    $f=coc_order_v1();$writes=0;$completed=0;
    coc_transport_v1($f,static function()use(&$writes,$f,&$completed):void{
        $writes++;
        db()->afterSql=static function(string $sql)use(&$completed):void{if(str_contains($sql,'SET external_session_id='))$completed++;};
        db()->afterCommit=static function(PA2CommercePDO $connection)use($f):void{
            $connection->afterCommit=null;
            if(coc_row_v1($f,$connection)['status']!=='open')throw new LogicException('Lost-ACK seam must follow the actual open commit.');
            throw new PDOException('Commit completed but its acknowledgement was lost.');
        };
    });
    try{coc_reject_v1(fn()=>coc_call_v1($f),'lost commit acknowledgement does not pretend the caller received a usable result');}
    finally{$pdo->afterSql=null;$pdo->afterCommit=null;}
    $committed=coc_row_v1($f);
    coc_check_v1($committed['status']==='open'&&(int)$committed['opened_at_utc_ms']>0&&$writes===1&&$completed===1,
        'a committed but unacknowledged open retains one durable clock and no retry write');
    $recovered=coc_call_v1($f);
    coc_check_v1($recovered['opened_at_utc_ms']===$committed['opened_at_utc_ms']&&$writes===1,
        'a retry after a lost commit acknowledgement recovers the same original usable link and clock');

    // A successor uses the real claim path after the original lease has expired.
    $other=commerce_fixture_connect_pa2($config);$f=coc_order_v1();$turn=0;$successor=null;
    $GLOBALS['pa2_http']=static function()use($f,$other,&$turn,&$successor):array{
        $turn++;
        if($turn===1){
            $other->prepare("UPDATE agent_commerce_checkout_attempts_v800 SET creation_lease_expires_at='2001-01-01 00:00:00' WHERE order_id=?")->execute([$f['id']]);
            $successor=coc_call_v1($f,$other);return coc_response_v1($f,'stale');
        }
        return coc_response_v1($f,'successor');
    };
    coc_reject_v1(fn()=>coc_call_v1($f),'an expired first worker cannot overwrite its successor after provider I/O');
    $kept=coc_row_v1($f);
    coc_check_v1($turn===2&&$kept['status']==='open'&&$kept['external_session_id']===$successor['external_session_id']
        &&$kept['opened_at_utc_ms']===$successor['opened_at_utc_ms']&&str_ends_with($kept['external_session_id'],'successor'),
        'independent-connection lease takeover preserves the successor’s exact open time and provider identity');

    foreach(['amount','currency','merchant','expired_hold','paid'] as $change){
        $f=coc_order_v1();
        coc_transport_v1($f,static function()use($f,$change):void{
            $sql=match($change){'amount'=>'amount_due_cents=6000','currency'=>"currency='eur'",'merchant'=>"external_account_snapshot='changed_merchant'",
                'expired_hold'=>"hold_expires_at='2001-01-01 00:00:00'",'paid'=>"payment_status='paid'"};
            db()->prepare('UPDATE agent_commerce_orders_v800 SET '.$sql.' WHERE id=?')->execute([$f['id']]);
        });
        coc_reject_v1(fn()=>coc_call_v1($f),$change.' during provider I/O cannot publish an active checkout');
        $a=coc_row_v1($f);
        coc_check_v1($a['status']!=='open'&&$a['opened_at_utc_ms']===null,
            $change.' never records a usable checkout-open time');
    }

    $f=coc_order_v1();$zone=date_default_timezone_get();date_default_timezone_set('Pacific/Honolulu');
    if($config['driver']==='mysql')$pdo->exec("SET time_zone='-07:00'");
    coc_transport_v1($f);$low=(int)floor(microtime(true)*1000);
    try{$shifted=coc_call_v1($f);}finally{date_default_timezone_set($zone);if($config['driver']==='mysql')$pdo->exec("SET time_zone='+00:00'");}
    coc_check_v1((int)$shifted['opened_at_utc_ms']>=$low&&(int)$shifted['opened_at_utc_ms']<=(int)floor(microtime(true)*1000),
        'checkout recorded UTC milliseconds are independent of PHP and database timezone');

    // The persisted timestamp survives canonical payment and receipt replay.
    $pay=coc_order_v1();coc_transport_v1($pay);$opened=coc_call_v1($pay);
    $evidence=['id'=>$pay['id'],'account'=>$pay['account'],'session'=>$opened['external_session_id'],'payment'=>'pi_clock_'.$pay['id']];
    $GLOBALS['pa2_http']=commerce_fixture_stripe_transport_pa2([$evidence]);$event=commerce_fixture_stripe_pa2($evidence);
    agent_commerce_process_webhook_v800($pdo,'stripe',$event['payload'],$event['headers']);
    agent_commerce_process_webhook_v800($pdo,'stripe',$event['payload'],$event['headers']);
    $paid=coc_row_v1($pay);
    coc_check_v1($paid['status']==='completed'&&$paid['opened_at_utc_ms']===$opened['opened_at_utc_ms'],
        'verified payment and its replay preserve the historical checkout-open clock');

    $legacy=commerce_fixture_order_pa2($pdo);$legacyBefore=agent_commerce_checkout_attempt_v800($pdo,$legacy['attempt_id']);$requests=count($GLOBALS['pa2_http_calls']);
    $legacyReplay=coc_call_v1($legacy);
    coc_check_v1($legacyBefore['opened_at_utc_ms']===null&&$legacyReplay['opened_at_utc_ms']===null&&count($GLOBALS['pa2_http_calls'])===$requests,
        'an already-open legacy attempt remains unclocked on replay rather than being dated today');

    // A missing optional column is different from an uncertain database failure.
    $f=coc_order_v1();$pdo->failSql='SELECT opened_at_utc_ms';$requests=count($GLOBALS['pa2_http_calls']);
    try{coc_reject_v1(fn()=>coc_call_v1($f),'an operational capture-schema failure does not silently discard clock evidence');}
    finally{$pdo->failSql='';}
    coc_check_v1(coc_row_v1($f)===null&&count($GLOBALS['pa2_http_calls'])===$requests,
        'unverified schema failure stops before reservation or provider I/O');
    $pdo->exec('ALTER TABLE agent_commerce_checkout_attempts_v800 DROP COLUMN opened_at_utc_ms');
    coc_check_v1(!profile_promotion_capture_checkout_clock_v1($pdo),'a definitely missing checkout measurement column is detected query-only');
    coc_transport_v1($f);$old=coc_call_v1($f);
    coc_check_v1($old['status']==='open'&&!array_key_exists('opened_at_utc_ms',$old)&&!column_exists('agent_commerce_checkout_attempts_v800','opened_at_utc_ms'),
        'an older schema still serves a usable checkout with its one legacy completion-write shape');
    profile_promotion_capture_install_v1($pdo,'checkout');$upgraded=coc_row_v1($f);
    coc_check_v1($upgraded['opened_at_utc_ms']===null&&coc_call_v1($f)['opened_at_utc_ms']===null,
        'explicit upgrade and later replay never backfill old usable checkout dates');
    $before=commerce_fixture_rows_pa2($pdo,'agent_commerce_checkout_attempts_v800');profile_promotion_capture_install_v1($pdo,'checkout');
    coc_check_v1($before===commerce_fixture_rows_pa2($pdo,'agent_commerce_checkout_attempts_v800'),'repeated checkout clock migration preserves every existing attempt');
    echo 'COMMERCE_CHECKOUT_OPEN_CLOCK_V1=PASS driver='.$config['driver'].' checks='.$checks.PHP_EOL;
}finally{
    $pdo->afterSql=null;$pdo->afterCommit=null;$pdo->failSql='';if($pdo->inTransaction())$pdo->rollBack();
    $other=null;$pdo=null;commerce_fixture_cleanup_pa2($config);@unlink($file);
}
