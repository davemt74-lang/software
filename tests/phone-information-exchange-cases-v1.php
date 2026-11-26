<?php
declare(strict_types=1);

/** Run the same information lifecycle against the actual SQLite/MySQL paired-phone domain. */
function vp3_test_phone_information_v1(PDO $db,int $owner,int $other,string $id,string $token,callable $assert):void
{
    $status=fn()=>vp3_phone_device_v1($db,$token,'status',[])['device'];
    $reject=function(int $code,callable $f,string $label)use($assert):void{
        try{$f();}catch(RuntimeException $e){$assert($e->getCode()===$code,$label);return;}
        $assert(false,$label);
    };
    $initial=$status();$p=$initial['communication'];
    $assert($p['information_pending']===0&&!$p['request_permission_saved'],'existing profiles do not acquire information consent automatically');
    $reject(403,fn()=>vp3_phone_information_owner_v1($db,$owner,'information_list',['device_id'=>$id]),'owner cannot read without holder permission');
    $reject(403,fn()=>vp3_phone_device_v1($db,$token,'information_list',['device_id'=>$id]),'holder cannot read without both permissions');
    $grant=function(bool $allow)use($db,$token,$status,$id){
        $d=$status();$p=$d['communication'];
        return vp3_phone_device_v1($db,$token,'save_communication_profile',[
            'device_id'=>$id,'generation'=>$d['generation'],'communication_revision'=>$p['revision'],
            'phone_e164'=>$p['phone_e164'],'holder_calls'=>$p['holder_calls'],'holder_proactive'=>$p['holder_proactive'],
            'holder_requests'=>$allow,'holder_memory'=>$p['holder_memory'],'timezone'=>$p['timezone'],
            'window_start'=>$p['window_start'],'window_end'=>$p['window_end'],'max_calls_per_day'=>$p['max_calls_per_day'],
            'min_gap_minutes'=>$p['min_gap_minutes']])['device']['communication'];
    };
    $p=$grant(true);$profile=$p['profile_id'];
    $bound=fn()=>['device_id'=>$id,'generation'=>$status()['generation'],'profile_id'=>$profile,'profile_revision'=>$status()['communication']['revision']];
    $ownerAct=fn(string $action,array $input=[])=>vp3_phone_information_owner_v1($db,$owner,$action,array_replace($bound(),$input))['information'];
    $holderAct=fn(string $action,array $input=[])=>vp3_phone_device_v1($db,$token,$action,array_replace($bound(),$input))['information'];
    $find=static function(array $result,string $key):array{
        foreach($result['items'] as $r)if($r['exchange_id']===$key)return $r;
        throw new RuntimeException('Fixture exchange missing.');
    };
    $ids=array_map(static fn(int $n)=>md5('ps5-fixture-'.$n),range(1,9));
    $create=fn(int $n,string $kind,string $message)=>$ownerAct('information_create',['exchange_id'=>$ids[$n-1],'kind'=>$kind,'message'=>$message]);
    $reject(404,fn()=>vp3_phone_information_owner_v1($db,$other,'information_list',['device_id'=>$id]),'other owner cannot view this inbox');
    $reject(422,fn()=>$create(1,'photo','Not supported yet'),'file or sensor request not executed as text type');
    $reject(422,fn()=>$create(1,'question',str_repeat('x',1001)),'oversized information text rejected');
    $reject(422,fn()=>$holderAct('information_create',['exchange_id'=>$ids[0],'kind'=>'update','message'=>'spoofed owner']),'holder cannot impersonate owner creation');
    $row=$find($create(1,'question','What is your preferred meeting time?'),$ids[0]);
    $assert($row['state']==='pending'&&$row['version']===1,'question is durably pending');
    $assert($find($create(1,'question','What is your preferred meeting time?'),$ids[0])===$row,'lost creation reply exact retry is idempotent');
    $reject(409,fn()=>$create(1,'question','Changed prompt'),'same identifier cannot send different text');
    $assert($find($holderAct('information_list'),$ids[0])===$row,'only the paired holder receives the request');
    $reject(403,fn()=>$holderAct('information_list',['device_id'=>str_repeat('0',32)]),'wrong phone scope cannot read information');
    $reject(409,fn()=>$ownerAct('information_create',['exchange_id'=>$ids[1],'kind'=>'update','message'=>'stale','profile_revision'=>$p['revision']-1]),'stale consent revision cannot send');
    $create(2,'update','The meeting moved.');$create(3,'availability','Can you meet?');$create(4,'task','Please review the plan.');
    $assert($status()['communication']['information_pending']===4,'status contains only current open count');
    $assert(!str_contains(json_encode($status(),JSON_THROW_ON_ERROR),'preferred meeting time'),'status polling never returns private request text');
    $reject(429,fn()=>$create(5,'question','One too many'),'maximum four unfinished exchanges');
    $reject(422,fn()=>$ownerAct('information_accept',['exchange_id'=>$ids[0],'version'=>1]),'owner cannot fabricate acceptance');
    $reject(409,fn()=>$holderAct('information_reply',['exchange_id'=>$ids[0],'version'=>1,'response'=>'Morning']),'reply requires explicit acceptance');
    $a=$find($holderAct('information_accept',['exchange_id'=>$ids[0],'version'=>1]),$ids[0]);
    $assert($a['state']==='accepted'&&$a['version']===2,'holder can explicitly accept');
    $assert($find($holderAct('information_accept',['exchange_id'=>$ids[0],'version'=>1]),$ids[0])===$a,'lost accept reply is idempotent');
    $done=$find($holderAct('information_reply',['exchange_id'=>$ids[0],'version'=>2,'response'=>'Morning <private fixture>']),$ids[0]);
    $assert($done['state']==='completed'&&$done['response']==='Morning <private fixture>'&&$done['version']===3,'accepted request completes with exact text response');
    $assert($find($ownerAct('information_list'),$ids[0])===$done,'owner sees exact response in scoped history');
    $assert($find($holderAct('information_reply',['exchange_id'=>$ids[0],'version'=>2,'response'=>'Morning <private fixture>']),$ids[0])===$done,'lost response reply exact retry is idempotent');
    $reject(409,fn()=>$holderAct('information_reply',['exchange_id'=>$ids[0],'version'=>2,'response'=>'Changed response']),'completed response cannot be overwritten');
    $ack=$find($holderAct('information_ack',['exchange_id'=>$ids[1],'version'=>1]),$ids[1]);
    $assert($ack['state']==='acknowledged','explicit acknowledgement of update');
    $declined=$find($holderAct('information_decline',['exchange_id'=>$ids[2],'version'=>1]),$ids[2]);
    $assert($declined['state']==='declined','recipient may decline without supplying a reason');
    $cancel=$find($ownerAct('information_cancel',['exchange_id'=>$ids[3],'version'=>1]),$ids[3]);
    $assert($cancel['state']==='canceled'&&$cancel['message']===''&&$cancel['response']==='','owner cancellation erases pending content');
    $reject(409,fn()=>$holderAct('information_accept',['exchange_id'=>$ids[3],'version'=>1]),'canceled request cannot be revived');
    $create(5,'task','An expiring request');
    $db->prepare('UPDATE vp3_phone_information_v1 SET expires_epoch=? WHERE owner_user_id=? AND device_id=? AND exchange_id=?')->execute([time()-1,$owner,$id,$ids[4]]);
    $expired=$find($holderAct('information_list'),$ids[4]);
    $assert($expired['state']==='expired'&&$expired['message']==='','expired request body is no longer available');
    $reject(409,fn()=>$holderAct('information_accept',['exchange_id'=>$ids[4],'version'=>1]),'expired request cannot accept');
    $create(6,'question','Should be withdrawn');$holderAct('information_accept',['exchange_id'=>$ids[5],'version'=>1]);
    $beforeRev=$status()['communication']['revision'];
    $off=$grant(false);
    $assert(!$off['request_permission_saved']&&$off['revision']>$beforeRev,'holder revokes information consent');
    $reject(403,fn()=>$ownerAct('information_list'),'withdrawal immediately blocks private history');
    $grant(true);
    $history=$ownerAct('information_list');
    $assert($find($history,$ids[5])['state']==='canceled'&&$find($history,$ids[0])['response']==='','restored consent neither revives old requests nor old private content');
    $reject(409,fn()=>$holderAct('information_reply',['exchange_id'=>$ids[5],'version'=>2,'response'=>'Late reply']),'withdrawn acceptance cannot complete');
    $create(7,'update','New update');$create(8,'update','Another update');
    $reject(429,fn()=>$create(9,'update','Daily cap'),'daily budget persists across consent changes');
    $assert($status()['generation']===$initial['generation']&&$status()['location_state']===$initial['location_state'],'information exchange never activates GPS');
    // Leave the fixture with consent off and no actionable requests for the existing tracking tests.
    $grant(false);
}
