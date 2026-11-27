<?php
declare(strict_types=1);

// Real canonical Canvas, source readers, device grants and workspace transactions.
$canvasRef=$delivery['phone_delivery']['item_ref'];
$inbox=static function(array $fields,?string $phoneId=null,?string $credential=null)use($pdo,$token,$deviceId,$clock):array{
    $phoneId??=$deviceId;$credential??=$token;
    $scope=vp3_phone_agent_workspace_scope_v1($pdo,null,$credential,$phoneId,false,$clock+32);
    return vp3_phone_agent_workspace_request_v1($pdo,null,$credential,['device_id'=>$phoneId,'csrf_token'=>'paired-source-csrf',
        'enrollment_revision'=>(int)$scope['enrollment']['revision'],'scope_revision'=>$scope['scope_revision']]+$fields,$clock+32);
};
$preview=$inbox(['action'=>'feed','limit'=>1]);
pas_check($preview['counts']['new_canvases']===1&&$preview['counts']['unread']>1,'Inbox badge counts one new Canvas independently of unread email and results');
$before=$inbox(['action'=>'detail','item_ref'=>$canvasRef])['item'];
pas_check($before['receipt']['seen_at']===null,'background badge reads do not mark a Canvas as seen');
pas_reject(422,fn()=>$inbox(['action'=>'feed','visit'=>'true']),'Inbox visit requires an actual boolean');
$visit=$inbox(['action'=>'feed','visit'=>true,'limit'=>20]);
$after=$inbox(['action'=>'detail','item_ref'=>$canvasRef])['item'];
pas_check($visit['counts']['new_canvases']===0&&$after['receipt']['seen_at']!==null
    &&$after['receipt']['read_at']===null&&$after['receipt']['acknowledged_at']===null,'opening Inbox clears the new Canvas badge without reading or approving its contents');
pas_check($visit['counts']['unread']===$preview['counts']['unread']&&$visit['counts']['needs_action']===$preview['counts']['needs_action'],
    'Inbox visits preserve unread email and pending native approvals');
$listed=array_values(array_filter($visit['items'],static fn(array $item):bool=>$item['item_ref']===$canvasRef));
pas_check(count($listed)===1&&$listed[0]['revision']===$after['revision']&&$listed[0]['receipt']===$after['receipt'],
    'visited feed returns the current receipt revision for subsequent exact-item actions');
$secondPhone=bin2hex(random_bytes(16));$secondToken=vp3_phone_token_v1(random_bytes(32));
vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_devices_v1(id,owner_user_id,token_hash,device_name,device_role,device_status,generation,location_state,created_at,expires_at,last_seen_at)
    VALUES(?,1,?,'Second fixture phone','guest','approved',1,'off',?,?,?)",[$secondPhone,vp3_extension_hash_v2000($secondToken),$clock,$clock+86400,$clock]);
$deliver=static function(string $phoneId)use($pdo,$owner,$session,$canvasId):array{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();$_SESSION=$session;
    $enrollment=vp3_phone_agent_workspace_enrollment_v1($pdo,1,$phoneId);
    return vp3_coding_canvas_phone_v1($pdo,$owner,7,11,$canvasId,2,'phone_send',[
        'device_id'=>$phoneId,'generation'=>1,'expected_revision'=>(int)($enrollment['revision']??0),
        'request_id'=>sprintf('40000000-0000-4000-8000-%012d',random_int(1,999999999999)),'confirmed'=>true]);
};
$deliver($secondPhone);$_SESSION=$anonymous;
pas_check($inbox(['action'=>'feed','limit'=>1],$secondPhone,$secondToken)['counts']['new_canvases']===1
    &&$inbox(['action'=>'feed','limit'=>1])['counts']['new_canvases']===0,'each approved paired phone keeps its own Canvas arrival badge');
$deliver($deviceId);$_SESSION=$anonymous;
pas_check($inbox(['action'=>'feed','limit'=>1])['counts']['new_canvases']===1,'explicitly resending the same saved Canvas makes it new again on its selected phone');
$inbox(['action'=>'feed','visit'=>true,'limit'=>1]);
pas_check($inbox(['action'=>'feed','limit'=>1],$secondPhone,$secondToken)['counts']['new_canvases']===1,
    'one phone visiting Inbox never clears another phone’s new Canvas badge');
pas_check(!isset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']),'Canvas Inbox visits preserve no-login paired access');
