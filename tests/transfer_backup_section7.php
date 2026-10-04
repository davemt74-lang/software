<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/backup-section7-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
function url(string $path): string {return $path;}
require __DIR__.'/transfer_integrity_section7.php';
require __DIR__.'/../includes/homeserver-knowledge-backup-v029.php';
function homeserver_vp3_status(int $id,bool $refresh=false): array {return ['paired'=>true,'connected'=>true,'capabilities'=>['knowledge.external_backup.v1']];}
function homeserver_approvals_v028_credentials(int $id): array {return ['relay'=>'fixture-relay','home'=>'fixture-home'];}
$mode='valid';$calls=[];$key=str_repeat('7',32);$bytes=str_repeat('a',128);$digest=hash('sha256',$bytes);
function homeserver_vp3_remote_operation(string $relay,string $operation,array $body,string $home): array {
    global $mode,$calls,$bytes,$digest;
    $calls[]=$operation;
    if($operation==='knowledge.upsert')return ['id'=>$mode==='bad-text'?0:1,'content_hash'=>$digest];
    if($operation==='knowledge.asset.begin'){
        if($mode==='bad-present')return ['already_present'=>true,'asset'=>['sha256'=>str_repeat('0',64),'size_bytes'=>strlen($bytes)]];
        return ['upload_id'=>str_repeat('u',32),'received_bytes'=>$mode==='bad-begin'?999:0];
    }
    if($operation==='knowledge.asset.chunk')return ['received_bytes'=>$mode==='bad-chunk'?-1:strlen($bytes)];
    return ['committed'=>$mode!=='bad-commit','asset'=>['sha256'=>$digest,'size_bytes'=>strlen($bytes)]];
}
$dir=artist_listening_v197_private_dir($user,$wideId);mkdir($dir,0700,true);$path=$dir.'/'.$key.'.wav';file_put_contents($path,$bytes);
function reset_backup(bool $text=true): void {
    global $connection,$wideId,$key,$bytes,$calls;
    $calls=[];$metadata=['recordings_v197'=>[['key'=>$key,'file_name'=>$key.'.wav','mime_type'=>'audio/wav','bytes'=>strlen($bytes)]],
        'homeserver_knowledge_backup_v029'=>['mode'=>'direct','text_synced'=>$text,'source_key'=>homeserver_knowledge_v029_source_key($wideId),'state'=>'pending']];
    $connection->prepare("UPDATE artist_transcript_sessions_v172 SET status='draft',metadata_json=? WHERE id=?")->execute([json_encode($metadata),$wideId]);
}
$backupCases=0;
try{
    foreach(['bad-begin','bad-chunk','bad-commit','bad-present'] as $mode){
        reset_backup();$result=homeserver_knowledge_v029_sync_step($user,$wideId);
        check($result['backup']['state']!=='synced'&&$result['backup']['recording_synced']===0,'invalid acknowledgement marked complete');
        $backupCases++;echo 'PASS reject '.$mode." recording acknowledgement\n";
    }
    $mode='valid';reset_backup();$result=homeserver_knowledge_v029_sync_step($user,$wideId);
    check($result['backup']['state']==='synced'&&$result['backup']['recording_synced']===1,'valid packet not committed');
    check(!str_contains(json_encode($result),$root)&&!str_contains(json_encode($result),'fixture-home'),'private transport leaked');
    $backupCases++;echo "PASS verified recording commit and redacted status\n";
    $mode='bad-text';reset_backup(false);$result=homeserver_knowledge_v029_prepare($user,$wideId);
    check(!$result['backup']['text_synced'],'unacknowledged text marked complete');$backupCases++;echo "PASS missing text acknowledgement stays pending\n";
    reset_backup();unlink($path);rejects(fn()=>homeserver_knowledge_v029_status($user,$wideId));$backupCases++;echo "PASS missing retained audio cannot silently disappear from totals\n";
    file_put_contents($path,$bytes);reset_backup();$connection->prepare("UPDATE artist_transcript_sessions_v172 SET status='discarded' WHERE id=?")->execute([$wideId]);
    rejects(fn()=>homeserver_knowledge_v029_sync_step($user,$wideId));check(!$calls,'discarded transcript sent');$backupCases++;echo "PASS discarded source cannot restart transfer\n";
    reset_backup();$deleted=artist_listening_v197_delete_recording($connection,$user,$wideId,$key);
    check($deleted['deleted']&&!is_file($path)&&!$deleted['session']['recordings'],'raw clip not removed');check(count($deleted['session']['segments'])===1,'transcript removed');$backupCases++;echo "PASS owner deletion removes Cloud audio and preserves transcript\n";
    check(artist_listening_v197_delete_recording($connection,$user,$wideId,$key)['deleted'],'delete replay');$backupCases++;echo "PASS lost deletion acknowledgement can be retried\n";
    $incoming=$root.'/incoming.wav';file_put_contents($incoming,$bytes);
    rejects(fn()=>artist_listening_v197_store_recording($connection,$user,$wideId,$key,['error'=>UPLOAD_ERR_OK,'tmp_name'=>$incoming,'size'=>strlen($bytes),'type'=>'audio/wav'],0,0,0));
    check(!is_file($path),'deleted recording resurrected');unlink($incoming);$backupCases++;echo "PASS delayed upload cannot recreate deleted audio\n";
    reset_backup();file_put_contents($path,$bytes);rejects(fn()=>artist_listening_v197_delete_recording($connection,['id'=>2],$wideId,$key));check(is_file($path),'foreign owner deleted audio');$backupCases++;echo "PASS recording deletion remains owner bound\n";
}finally{if(is_file($path))unlink($path);rmdir($dir);rmdir(dirname($dir));rmdir(dirname(dirname($dir)));rmdir($root.'/private');rmdir($root);}
echo "TRANSFER_BACKUP_SECTION7=PASS ($backupCases canonical backup cases)\n";
