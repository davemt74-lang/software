<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/phone-managed-media-worker-v1.php';
$pdo=db();
if(!$pdo instanceof PDO||!vp3_managed_recording_ready_v1($pdo)||!vp3_managed_transport_ready_v1($pdo)){
    fwrite(STDERR,"Managed media upgrade required.\n");exit(1);
}
$dir=STONEFELLOW_ROOT.'/private/phone-recordings-v1';
if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))exit(1);
$lock=fopen($dir.'/managed-media.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
@chmod($dir.'/managed-media.lock',0600);
$totals=['started'=>0,'stopped'=>0,'unconfirmed'=>0,'attempted'=>0];
$until=microtime(true)+(in_array('--watch',$argv,true)?50:0);
try{
    do{
        $r=vp3_managed_media_tick_v1($pdo);
        foreach($totals as $key=>$value)$totals[$key]+=(int)$r[$key];
        $json=json_encode(['source'=>'managed_media_cli','observed_epoch'=>time(),'driver_enabled'=>vp3_managed_media_driver_enabled_v1(),'outcome'=>$r['unconfirmed']?'unconfirmed':'observed'],JSON_THROW_ON_ERROR);
        $temp=$dir.'/managed-media-health.'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($temp,$json,LOCK_EX)===strlen($json)){@chmod($temp,0600);if(!rename($temp,$dir.'/managed-media-health.json'))@unlink($temp);}else @unlink($temp);
        if(microtime(true)>=$until)break;
        sleep(2);
    }while(microtime(true)<$until);
    echo json_encode($totals,JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable){fwrite(STDERR,"Managed media worker failed.\n");exit(1);}
finally{flock($lock,LOCK_UN);fclose($lock);}
