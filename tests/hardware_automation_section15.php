<?php
declare(strict_types=1);
$GLOBALS['reply']=[];
function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[]): array {
    return $GLOBALS['reply'];
}
require dirname(__DIR__).'/includes/homeserver-governed-actions-v233.php';
function rejected(array $reply): void {
    $GLOBALS['reply']=$reply;
    try { homeserver_governed_v233_request(7,'devices.command',['device_key'=>'lamp','command'=>'on']); }
    catch(RuntimeException $e) { return; }
    throw new RuntimeException('Invalid device acknowledgement was accepted.');
}
$pending=['request_id'=>'request-12345678','status'=>'pending','action'=>'devices.command','owner_approval_required'=>true];
$GLOBALS['reply']=['ok'=>true,'result'=>['approval_required'=>true,'result'=>$pending]];
$result=homeserver_governed_v233_request(7,'devices.command',['device_key'=>'lamp','command'=>'on']);
assert($result['status']==='pending_approval' && $result['local_owner_required']===true);
assert($result['request_id']==='request-12345678');
rejected(['ok'=>true,'result'=>[]]);
rejected(['ok'=>true,'result'=>['result'=>['executed'=>true]]]);
rejected(['ok'=>false,'result'=>['approval_required'=>true,'result'=>$pending]]);
rejected(['ok'=>true,'result'=>['ok'=>false,'approval_required'=>true,'result'=>$pending]]);
foreach(['request_id'=>'short','status'=>'failed','action'=>'memory.write'] as $key=>$value) {
    rejected(['ok'=>true,'result'=>['approval_required'=>true,'result'=>array_replace($pending,[$key=>$value])]]);
}
// Normal automatic nonphysical writes keep their existing behavior.
$GLOBALS['reply']=['ok'=>true,'result'=>['status'=>'completed','result'=>['created'=>true]]];
assert(homeserver_governed_v233_request(7,'memory.write',['content'=>'Test'])['status']==='executed');
echo "SECTION15_CLOUD_DEVICE_APPROVAL_ACKNOWLEDGEMENT=PASS\n";
