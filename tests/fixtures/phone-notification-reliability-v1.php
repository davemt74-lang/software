<?php
declare(strict_types=1);

// Runs inside the native source fixture: real private Gmail draft, grant,
// workspace API, ingestion, outbox and sender; only provider HTTP is substituted.
$pdo->exec('DROP TABLE vp3_phone_agent_alerts_v1');
foreach(['source_grant_id','source_wake_revision'] as $column)$pdo->exec('ALTER TABLE vp3_phone_push_outbox_v1 DROP COLUMN '.$column);
foreach(['wake_revision','wake_requested_epoch'] as $column)$pdo->exec('ALTER TABLE vp3_phone_agent_receipts_v1 DROP COLUMN '.$column);
// A deployed upgrade begins with a fresh metadata cache/connection.
$pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$name,$login,$password,$options);
pas_check(!vp3_phone_push_delivery_ready_v1($pdo)&&!vp3_phone_agent_workspace_ready_v1($pdo),'older optional notification schema reports upgrade without runtime DDL');
vp3_phone_schema_v1($pdo);vp3_phone_schema_v1($pdo);
pas_check(vp3_phone_push_delivery_ready_v1($pdo)&&vp3_phone_agent_workspace_ready_v1($pdo),'explicit notification migration replays and preserves paired workspace');
pas_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_agent_alerts_v1')->fetchColumn()===1,'upgrade backfills semantic marker for existing legacy notification');
$send=static fn(int $at):array=>vp3_phone_push_delivery_run_v1($pdo,3,'vp3_phone_push_source_current_v1',
    static fn():array=>['state'=>'accepted','code'=>'provider_accepted','http_status'=>201,'retry_after'=>0,'dispatched'=>true],$at,null,static fn():int=>$at);
pas_check($send($clock+22)['attempted']===1,'legacy queued alert still dispatches after migration');
$future=$clock+8*86400;
// Simulate normal authorized use retaining this same paired credential.
$pdo->prepare('UPDATE vp3_phone_devices_v1 SET expires_at=? WHERE owner_user_id=1 AND id=?')->execute([$future+86400,$deviceId]);
pas_check(vp3_phone_push_delivery_prune_v1($pdo,$future,100)===1,'provider outbox cleanup removes expired terminal metadata');
$after=vp3_phone_agent_workspace_sweep_v1($pdo,$future,1);
pas_check($after['unavailable']===0&&$after['eligible_events']===0&&(int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()===0,'cleanup never announces an unchanged unread native draft again');
$marker=$pdo->query('SELECT * FROM vp3_phone_agent_alerts_v1')->fetch();
pas_check($marker['event_ref']===$event['event_ref']&&!str_contains(json_encode($marker),'BODY_SENTINEL')&&!str_contains(json_encode($marker),'push.apple'),'durable identity survives cleanup without source body or subscription');
$mutate=static function(string $op,int $at,?int $until=null)use($pdo,$owner,$token,$deviceId,$session,$event):void{
    $_SESSION=$session;$scope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$token,$deviceId,false,$at);
    $item=vp3_phone_agent_workspace_item_v1($pdo,$scope,$event['source_id'])['item'];
    vp3_phone_agent_workspace_request_v1($pdo,$owner,$token,['action'=>'mutate','device_id'=>$deviceId,'csrf_token'=>'phone-source-csrf',
        'enrollment_revision'=>(int)$scope['enrollment']['revision'],'scope_revision'=>$scope['scope_revision'],
        'item_ref'=>$event['source_id'],'expected_revision'=>$item['revision'],'operation'=>$op]
        +($until!==null?['snooze_until'=>gmdate('Y-m-d\TH:i:s\Z',$until)]:[]),$at);
    unset($_SESSION);
};
$latest=static fn():array=>$pdo->query('SELECT * FROM vp3_phone_push_outbox_v1 ORDER BY created_epoch DESC,event_ref DESC LIMIT 1')->fetch();
$mutate('snooze',$future+1,$future+120);
$backlog=[];for($i=0;$i<45;$i++)$backlog[]=pas_draft()['id'];
$bounded=vp3_phone_agent_sources_discover_v1($pdo,$owner,7,$future+2,40);
pas_check(!in_array($new['id'],array_column($bounded['items'],'source_id'),true),'fixture puts the snoozed source beyond ordinary newest-item discovery');
pas_check(vp3_phone_agent_workspace_sweep_v1($pdo,$future+2,1)['eligible_events']===0,'snoozed delivered work is quiet before its requested deadline');
pas_check(vp3_phone_agent_workspace_sweep_v1($pdo,$future+121,1)['eligible_events']===1,'explicit snooze wakes a previously delivered unchanged item when due');
$wake1=$latest();
pas_check((int)$wake1['source_wake_revision']===1&&$wake1['source_grant_id']===$marker['grant_id'],'wake event binds exact receipt generation and current owner grant');
$wakeSource=vp3_phone_push_delivery_event_source_v1($wake1);
pas_check(!vp3_phone_push_source_current_v1($pdo,$device,array_replace($wakeSource,['grant_id'=>str_repeat('f',32)]),$future+121),'a wake captured under another approval cannot use the current grant');
pas_check(!vp3_phone_push_source_current_v1($pdo,$device,array_replace($wakeSource,['wake_revision'=>2]),$future+121),'a superseded wake generation cannot claim current source authority');
vp3_phone_agent_workspace_sweep_v1($pdo,$future+121,1);
pas_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()===1,'repeated due sweeps cannot duplicate a wake');
pas_check($send($future+122)['attempted']===1,'due snooze wake dispatches through current native source checks');
foreach($backlog as $id)$pdo->prepare('DELETE FROM agent_connection_email_drafts_v1 WHERE id=?')->execute([$id]);
$mutate('snooze',$future+123,$future+240);
vp3_phone_agent_workspace_sweep_v1($pdo,$future+241,1);$wake2=$latest();
$mutate('snooze',$future+242,$future+360);$sleep=$send($future+243);
pas_check($sleep['attempted']===0&&vp3_phone_push_delivery_event_v1($pdo,$wake2['event_ref'])['state']==='cancelled','resnoozing fences an already queued wake without provider dispatch');
pas_check(vp3_phone_agent_workspace_sweep_v1($pdo,$future+361,1)['eligible_events']===1,'a later snooze deadline gets a new wake rather than reviving cancelled work');
$wake3=$latest();
pas_check($wake3['event_ref']!==$wake2['event_ref']&&(int)$wake3['source_wake_revision']===3,'each explicit wake keeps its own durable identity');
$mutate('snooze',$future+362,$future+500);$send($future+363);
$mutate('unsnooze',$future+364);
pas_check(vp3_phone_agent_workspace_sweep_v1($pdo,$future+365,1)['eligible_events']===1,'explicit unsnooze wakes immediately under its new generation');
pas_check($send($future+366)['attempted']===1,'unsnoozed work sends once');
pas_check(vp3_phone_agent_workspace_sweep_v1($pdo,$future+501,1)['eligible_events']===0,'superseded snooze deadline cannot later repeat an unsnoozed alert');
$mutate('snooze',$future+502,$future+700);$mutate('read',$future+503);
pas_check(vp3_phone_agent_workspace_sweep_v1($pdo,$future+701,1)['eligible_events']===0,'reading before wake suppresses the deferred notification');
pas_check($pdo->query("SELECT status FROM agent_connection_email_drafts_v1 WHERE id='".$new['id']."'")->fetchColumn()==='prepared','notification receipt and wake controls never approve or send Gmail');
$changed=$newPayload;$changed['body']='A genuine later native update';$changed['composer']['saved_at']=$future+800;
$pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?')->execute([ai_encrypt_secret(json_encode($changed)),$new['id']]);
pas_check(vp3_phone_agent_workspace_sweep_v1($pdo,$future+801,1)['eligible_events']===1,'a genuine new source revision still notifies after durable deduplication');
$edited=$latest();
pas_check((int)$edited['source_revision']>(int)$event['source_revision']&&(int)$edited['source_wake_revision']===0,'new draft revision clears obsolete snooze receipt generation');
$_SESSION=$session;$scope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$token,$deviceId,false,$future+802);
vp3_phone_agent_workspace_request_v1($pdo,$owner,$token,['action'=>'revoke','device_id'=>$deviceId,'csrf_token'=>'phone-source-csrf',
    'enrollment_revision'=>(int)$scope['enrollment']['revision'],'scope_revision'=>$scope['scope_revision'],'expected_revision'=>(int)$scope['enrollment']['revision']],$future+802);
unset($_SESSION);
pas_check($send($future+803)['attempted']===0&&vp3_phone_push_delivery_event_v1($pdo,$edited['event_ref'])['state']==='cancelled','revocation never revives or dispatches a queued current-generation alert');
echo 'PASS native notification migration, durable semantic identity and explicit wake lifecycle.'.PHP_EOL;
