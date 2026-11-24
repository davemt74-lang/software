<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/agent-operator-paired-worker-8a4.php';
require_once __DIR__.'/../includes/db.php';
try {
    $config=require __DIR__.'/../config.php';
    $db=db();if(!$db)throw new RuntimeException('Database unavailable.');
    $cmd=$argv[1]??'';
    if($cmd==='install' && $argc===2){
        VP3OperatorPairedWorker8A4::install($db);
        echo "Installed simulation-only Operator worker tables.\n";exit;
    }
    if(!in_array($cmd,['grant','revoke'],true) || !isset($argv[2]) || preg_match('/\A[1-9][0-9]{0,9}\z/D',$argv[2])!==1)
        throw new DomainException('Usage: install | grant OWNER_ID HS_DEVICE | revoke OWNER_ID');
    $settings=$config['operator_worker_registration']??[];
    if(!is_array($settings))throw new DomainException('Configuration required.');
    $service=new VP3OperatorPairedWorker8A4($db,VP3OperatorPairedWorker8A4::masterKey($settings));
    $owner=(int)$argv[2];
    if($cmd==='grant' && $argc===4 && ($settings['enabled']??false)===true){
        $r=$service->grant($owner,$argv[3],time());echo json_encode($r,JSON_THROW_ON_ERROR)."\n";
    }elseif($cmd==='revoke' && $argc===3){
        echo $service->revoke($owner,time())?"Worker credential revoked.\n":"No active worker credential.\n";
    }else throw new DomainException('Invalid command or registration disabled.');
}catch(Throwable $e){
    // Never print a config, token, worker secret, raw SQL or exception trace.
    fwrite(STDERR,"Operator worker admin command rejected. Check private configuration, schema, pairing and arguments.\n");exit(1);
}
