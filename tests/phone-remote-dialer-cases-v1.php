<?php
declare(strict_types=1);

function vp3_test_phone_dialer_v1(PDO $db,int $owner,int $other,string $id,string $token,callable $assert):void
{
    $status=fn()=>vp3_phone_device_v1($db,$token,'status',[])['device'];
    $bound=fn()=>['device_id'=>$id,'generation'=>$status()['generation'],'dialer_revision'=>$status()['dialer']['revision']];
    $holder=fn(string $action,array $input=[])=>vp3_phone_device_v1($db,$token,$action,array_replace($bound(),$input));
    $send=fn(string $action,array $input=[],?int $uid=null)=>vp3_phone_dialer_owner_v1($db,$uid??$owner,$action,array_replace($bound(),$input))['dialer'];
    $reject=function(int $code,callable $f,string $label)use($assert):void{
        try{$f();}catch(RuntimeException $e){$assert($e->getCode()===$code,$label);return;}
        $assert(false,$label);
    };
    $id1=md5('dialer-test-1');$id2=md5('dialer-test-2');$id3=md5('dialer-test-3');
    $a='+16025550100';$b='+16025550101';
    $payload=fn(string $request,string $n)=>['dialer_request_id'=>$request,'destination'=>$n,'confirmed'=>true];
    $assert($status()['dialer']['enabled']===false,'remote dialer defaults off');
    $reject(403,fn()=>$send('dialer_send',$payload($id1,$a)),'owner cannot send without holder opt in');
    $reject(422,fn()=>$holder('dialer_preferences',['enabled'=>true,'auto_number'=>'tel:+16025550100']),'only E.164 approved destinations');
    $holder('dialer_preferences',['enabled'=>true,'auto_number'=>$a]);
    $p=$status()['dialer'];$assert($p['enabled']&&$p['auto_number']===$a&&$p['revision']===1,'holder explicitly approves exact auto destination');
    $reject(422,fn()=>$send('dialer_preferences',['enabled'=>false,'auto_number'=>'']),'owner cannot change holder dialer permission');
    $reject(404,fn()=>$send('dialer_send',$payload($id1,$a),$other),'cross-account send rejected');
    $reject(403,fn()=>$send('dialer_send',array_replace($payload($id1,$a),['confirmed'=>false])),'explicit owner confirmation required');
    $reject(422,fn()=>$send('dialer_send',$payload($id1,'+16025550100;123')),'extension and control suffix rejected');
    $request=$send('dialer_send',$payload($id1,$a));
    $assert($request['request']['state']==='pending'&&!isset($request['request']['destination'],$request['auto_number']),'owner projection excludes private destination and auto number');
    $assert($send('dialer_send',$payload($id1,$a))['request']===$request['request'],'exact retry does not create another request');
    $reject(409,fn()=>$send('dialer_send',$payload($id1,$b)),'same request cannot change destination');
    $reject(429,fn()=>$send('dialer_send',$payload($id2,$a)),'per-phone burst limit enforced');
    $r=$status()['dialer']['request'];
    $assert($r['destination']===$a&&$r['auto_allowed']&&!$r['auto_attempted'],'approved number is eligible for one auto attempt');
    $claim=$holder('dialer_claim',['dialer_request_id'=>$id1]);
    $assert($claim['dialer_claimed']===$id1&&$claim['device']['dialer']['request']['auto_attempted'],'auto attempt is atomically claimed');
    $reject(409,fn()=>$holder('dialer_claim',['dialer_request_id'=>$id1]),'auto claim cannot replay from another tab');
    $assert($status()['dialer']['request']['state']==='pending','auto claim is not evidence of a completed handoff');
    $holder('dialer_handled',['dialer_request_id'=>$id1]);
    $assert($status()['dialer']['request']['state']==='handoff_requested'&&!isset($status()['dialer']['request']['destination']),'explicit tap records only handoff request and redacts number');
    $age=fn(string $request)=>vp3_phone_sql_v1($db,'UPDATE vp3_phone_dialer_requests_v1 SET created_epoch=? WHERE owner_user_id=? AND device_id=? AND request_id=?',[time()-61,$owner,$id,$request]);
    $age($id1);
    $send('dialer_send',$payload($id2,$b));
    $assert($status()['dialer']['request']['auto_allowed']===false,'other numbers stay manual even with auto enabled');
    $reject(409,fn()=>$holder('dialer_claim',['dialer_request_id'=>$id2]),'unapproved number cannot claim automatic opening');
    $holder('dialer_decline',['dialer_request_id'=>$id2]);
    $assert($status()['dialer']['request']['state']==='declined','holder can decline request');
    $age($id2);
    $send('dialer_send',$payload($id3,$a));
    vp3_phone_sql_v1($db,'UPDATE vp3_phone_dialer_requests_v1 SET expires_epoch=? WHERE owner_user_id=? AND device_id=? AND request_id=?',[time()-1,$owner,$id,$id3]);
    $expired=$status()['dialer']['request'];
    $assert($expired['state']==='expired'&&!isset($expired['destination']),'expired destination no longer delivered');
    $reject(409,fn()=>$holder('dialer_claim',['dialer_request_id'=>$id3]),'expired request cannot launch');
    $age($id3);
    $id4=md5('dialer-test-4');
    $send('dialer_send',$payload($id4,$a));
    $old=$bound();
    $holder('dialer_preferences',['enabled'=>false,'auto_number'=>'']);
    $assert(!$status()['dialer']['enabled']&&$status()['dialer']['request']===null,'withdrawal clears visible pending request');
    $reject(409,fn()=>vp3_phone_device_v1($db,$token,'dialer_claim',$old+['dialer_request_id'=>$id4]),'old permission revision cannot claim after withdrawal');
    $assert(vp3_phone_sql_v1($db,'SELECT destination FROM vp3_phone_dialer_requests_v1 WHERE owner_user_id=? AND device_id=? AND request_id=?',[$owner,$id,$id4])->fetchColumn()==='','withdrawal erases pending destination');
    $holder('dialer_preferences',['enabled'=>true,'auto_number'=>$a]);
    $assert($status()['dialer']['request']===null,'reenabling cannot revive previous request');
    $holder('dialer_preferences',['enabled'=>false,'auto_number'=>'']);
    $assert($status()['generation']===$old['generation'],'dialer does not alter GPS generation');
}
