<?php
declare(strict_types=1);

$GLOBALS['runs']=[];
$GLOBALS['mode']='pending-file';

function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[]): array {
    $GLOBALS['runs'][]=['user_id'=>$userId,'operation'=>$operation,'payload'=>$payload];
    if($operation==='tool.execute'){
        $tool=(string)($payload['tool_key']??'');
        if($tool==='files.update'||$tool==='files.delete'||$tool==='devices.command'){
            return ['ok'=>true,'result'=>[
                'approval_required'=>true,
                'result'=>['request_id'=>'req-12345678','status'=>'pending','action'=>$tool,'owner_approval_required'=>true],
            ],'execution'=>['route'=>'homeserver','status'=>'completed','fallback_used'=>false]];
        }
        return ['ok'=>true,'result'=>[
            'tool'=>$tool,'status'=>'completed','result'=>['created'=>true,'id'=>9],
        ],'execution'=>['route'=>'homeserver','status'=>'completed','fallback_used'=>false]];
    }
    if($operation==='action.list'){
        return ['ok'=>true,'result'=>['items'=>[['id'=>'req-12345678','action_key'=>'files.update','status'=>'pending']]],'execution'=>['route'=>'homeserver']];
    }
    if($operation==='action.status'){
        $action=($GLOBALS['status_action']??'files.update');
        return ['ok'=>true,'result'=>['request'=>['id'=>'req-12345678','action_key'=>$action,'status'=>'pending']],'execution'=>['route'=>'homeserver']];
    }
    if($operation==='action.approve'||$operation==='action.deny'){
        return ['ok'=>true,'result'=>['request'=>['id'=>'req-12345678','action_key'=>'memory.write','status'=>$operation==='action.approve'?'executed':'denied']],'execution'=>['route'=>'homeserver']];
    }
    throw new RuntimeException('Unexpected operation '.$operation);
}

require dirname(__DIR__).'/includes/homeserver-governed-actions-v233.php';

$file=homeserver_governed_v233_request(7,'files.update',['ref'=>'hsf-1-0123456789abcdef','content'=>'new body']);
assert($file['ok']===true);
assert($file['status']==='pending_approval');
assert($file['local_owner_required']===true);
assert($file['request_id']==='req-12345678');
assert($GLOBALS['runs'][0]['operation']==='tool.execute');

$memory=homeserver_governed_v233_request(7,'memory.write',['content'=>'remember this']);
assert($memory['status']==='executed');
assert($memory['local_owner_required']===false);
assert($memory['approval_mode']==='federated');

$list=homeserver_governed_v233_list(7,'pending',20);
assert(count($list['items'])===1);
assert($GLOBALS['runs'][2]['operation']==='action.list');

$GLOBALS['status_action']='files.update';
$before=count($GLOBALS['runs']);
$blocked=homeserver_governed_v233_review(7,'req-12345678','approve');
assert($blocked['ok']===false);
assert($blocked['local_owner_required']===true);
assert(count($GLOBALS['runs'])===$before+1);
assert($GLOBALS['runs'][$before]['operation']==='action.status');

$GLOBALS['status_action']='memory.write';
$approved=homeserver_governed_v233_review(7,'req-12345678','approve');
assert($approved['ok']===true);
assert(end($GLOBALS['runs'])['operation']==='action.approve');

$GLOBALS['status_action']='devices.command';
$denied=homeserver_governed_v233_review(7,'req-12345678','deny');
assert($denied['ok']===true);
assert(end($GLOBALS['runs'])['operation']==='action.deny');

$threw=false;
try{homeserver_governed_v233_request(7,'shell.execute',['cmd'=>'rm -rf /']);}
catch(RuntimeException $e){$threw=str_contains($e->getMessage(),'not allowlisted');}
assert($threw===true);

echo "HomeServer v2.3 Section 4 governed actions runtime: PASS\n";
