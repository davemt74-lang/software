<?php
declare(strict_types=1);
/** Dedicated Cloud worker: keep paid call processing off the shared Agent queue. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-workspace-v1.php';
require_once __DIR__.'/includes/phone-recording-pipeline-v1.php';
$pdo=db();
if(!$pdo instanceof PDO||!vp3_phone_recording_schema_ready_v1($pdo)){fwrite(STDERR,"Phone recording schema is unavailable.\n");exit(1);}
$directory=STONEFELLOW_ROOT.'/private/phone-recordings-v1';
if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){fwrite(STDERR,"Private worker storage is unavailable.\n");exit(1);}
$lock=fopen($directory.'/worker.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
@chmod($directory.'/worker.lock',0600);
try{
    vp3_phone_recording_worker_observe_v1('processing');
    $result=vp3_phone_recording_worker_tick_v1($pdo,1);
    vp3_phone_recording_worker_observe_v1($result['failed']?'failed':($result['completed']?'completed':'idle'));
    // Only operational counters leave the CLI; transcripts and contacts stay private.
    echo json_encode(['completed'=>$result['completed'],'failed'=>$result['failed']],JSON_THROW_ON_ERROR).PHP_EOL;
    exit($result['failed']?1:0);
}catch(Throwable){vp3_phone_recording_worker_observe_v1('failed');fwrite(STDERR,"Phone recording worker did not complete.\n");exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}
