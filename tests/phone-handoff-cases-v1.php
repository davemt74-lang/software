<?php
declare(strict_types=1);

/** Shared SQLite/MySQL production-domain transfer assertions. No sensors/carrier. */
function vp3_test_phone_handoff_v1(PDO $pdo,int $owner,int $other,callable $assert):void
{
    $reject=static function(int $code,callable $fn,string $label)use($assert){
        try{$fn();}catch(RuntimeException $e){$assert($e->getCode()===$code,$label.' ('.$e->getCode().')');return;}
        $assert(false,$label);
    };
    $command=static fn()=>['request_id'=>bin2hex(random_bytes(16))];
    $make=static function(string $name)use($pdo,$owner,$command){
        $invite=vp3_phone_owner_v1($pdo,$owner,'invite',$command())['invite'];
        $claim=vp3_phone_device_v1($pdo,'','claim',['invite'=>$invite,'name'=>$name,'claim_key'=>bin2hex(random_bytes(32))]);
        $d=$claim['device'];
        $approved=vp3_phone_owner_v1($pdo,$owner,'approve',$command()+['device_id'=>$d['id'],'generation'=>$d['generation'],'role'=>'guest','allow_location'=>true])['device'];
        return ['token'=>$claim['token'],'device'=>$approved];
    };
    $source=$make('Handoff source');$token=$source['token'];$id=$source['device']['id'];$g=$source['device']['generation'];
    $scope=['device_id'=>$id,'generation'=>$g];$status=static fn()=>vp3_phone_device_v1($pdo,$token,'status',[])['device'];
    $fields=$scope+['phone_e164'=>'+16025550189','holder_calls'=>false,'holder_proactive'=>false,'holder_requests'=>false,'holder_memory'=>true,
        'timezone'=>'UTC','window_start'=>'09:00','window_end'=>'17:00','max_calls_per_day'=>1,'min_gap_minutes'=>240,'communication_revision'=>0];
    $saved=vp3_phone_device_v1($pdo,$token,'save_communication_profile',$fields)['device']['communication'];
    $offer=static fn()=>vp3_phone_device_v1($pdo,$token,'handoff_offer',$scope+['confirmed'=>true]);
    $reject(422,fn()=>vp3_phone_device_v1($pdo,$token,'handoff_offer',$scope+['confirmed'=>false]),'holder must explicitly authorize a move');
    $active=vp3_phone_device_v1($pdo,$token,'start',$scope)['device'];
    $g=$active['generation'];$scope=['device_id'=>$id,'generation'=>$g];
    $reject(409,fn()=>vp3_phone_device_v1($pdo,$token,'handoff_offer',$scope+['confirmed'=>true]),'live location blocks move offer');
    $off=vp3_phone_device_v1($pdo,$token,'stop',$scope)['device'];
    $g=$off['generation'];$scope=['device_id'=>$id,'generation'=>$g];
    $o=vp3_phone_device_v1($pdo,$token,'handoff_offer',$scope+['confirmed'=>true]);$ticket=$o['handoff_ticket'];
    $assert(vp3_phone_secret_valid_v1($ticket['code'])&&$o['device']['handoff']['state']==='offered','authenticated holder receives one high-entropy code');
    $assert(!str_contains(json_encode($status(),JSON_THROW_ON_ERROR),$ticket['code']),'normal status never repeats the code');
    $stored=vp3_phone_handoff_row_v1($pdo,vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC));
    $assert($stored['code_hash']!==$ticket['code']&&$stored['target_hash']===null,'only digest persisted before claim');
    $target=['code'=>$ticket['code'],'claim_key'=>bin2hex(random_bytes(32)),'confirmed'=>true];
    $reject(409,fn()=>vp3_phone_handoff_target_v1($pdo,$token,'handoff_claim',$target,time()),'live source cookie cannot overwrite itself');
    $claimed=vp3_phone_handoff_target_v1($pdo,'','handoff_claim',$target,time())['handoff'];
    $assert($claimed['state']==='claimed'&&preg_match('/^[0-9A-F]{8}$/D',$claimed['comparison_code'])===1,'new installation gets an exact comparison code');
    $reject(403,fn()=>vp3_phone_handoff_target_v1($pdo,'','handoff_claim',array_replace($target,['claim_key'=>bin2hex(random_bytes(32))]),time()),'second claimant cannot take over first claim');
    $reject(409,fn()=>vp3_phone_handoff_target_v1($pdo,'','handoff_finish',$target,time()),'claim alone cannot rotate credential');
    $ownerArgs=$scope+['transfer_id'=>$ticket['transfer_id'],'comparison_code'=>$claimed['comparison_code'],'confirmed'=>true];
    $reject(404,fn()=>vp3_phone_handoff_owner_v1($pdo,['id'=>$other],'handoff_approve',$ownerArgs,time()),'different owner cannot approve move');
    $reject(422,fn()=>vp3_phone_handoff_owner_v1($pdo,['id'=>$owner],'handoff_approve',array_replace($ownerArgs,['comparison_code'=>'INVALID!']),time()),'owner must match exact target comparison code');
    vp3_phone_handoff_owner_v1($pdo,['id'=>$owner],'handoff_approve',$ownerArgs,time());
    $assert($status()['id']===$id&&$status()['handoff']['state']==='approved','owner approval does not disconnect old app');
    $result=vp3_phone_handoff_target_v1($pdo,'','handoff_finish',$target,time());$new=$result['token'];
    $assert($result['handoff']['state']==='finished'&&$new!==$token&&$result['device']['id']===$id&&$result['device']['generation']>$g,'finish rotates credential and preserves immutable device identity');
    $reject(403,fn()=>$status(),'old holder token cannot read after completed move');
    $fresh=vp3_phone_device_v1($pdo,$new,'status',[])['device'];
    $assert($fresh['location_state']==='off'&&$fresh['communication']['profile_id']===$saved['profile_id']&&!$fresh['communication']['phone_saved']
        &&!$fresh['communication']['holder_memory']&&!$fresh['communication']['holder_calls']&&!$fresh['communication']['holder_requests']
        &&$fresh['communication']['revision']>$saved['revision'],'holder grants and number reset while profile identity remains');
    $retry=vp3_phone_handoff_target_v1($pdo,'','handoff_finish',$target,time());
    $assert($retry['token']===$new&&$retry['device']['generation']===$fresh['generation'],'lost response recovery returns same credential, no second move');
    $reject(403,fn()=>vp3_phone_handoff_target_v1($pdo,'','handoff_finish',array_replace($target,['claim_key'=>bin2hex(random_bytes(32))]),time()),'wrong proof cannot recover the new token');
    vp3_phone_owner_v1($pdo,$owner,'revoke',$command()+['device_id'=>$id,'generation'=>$fresh['generation']]);
    $reject(403,fn()=>vp3_phone_handoff_target_v1($pdo,'','handoff_finish',$target,time()),'revocation deletes transfer recovery authority');

    $cancel=$make('Canceled transfer');$d=$cancel['device'];$s=['device_id'=>$d['id'],'generation'=>$d['generation']];
    $t=vp3_phone_device_v1($pdo,$cancel['token'],'handoff_offer',$s+['confirmed'=>true])['handoff_ticket'];
    $c=['code'=>$t['code'],'claim_key'=>bin2hex(random_bytes(32)),'confirmed'=>true];
    $claim=vp3_phone_handoff_target_v1($pdo,'','handoff_claim',$c,time())['handoff'];
    vp3_phone_handoff_owner_v1($pdo,['id'=>$owner],'handoff_owner_cancel',$s+['transfer_id'=>$t['transfer_id'],'confirmed'=>true],time());
    $reject(403,fn()=>vp3_phone_handoff_target_v1($pdo,'','handoff_finish',$c,time()),'canceled move cannot finish');
    $assert(vp3_phone_device_v1($pdo,$cancel['token'],'status',[])['device']['id']===$d['id'],'cancel leaves original connection working');
    vp3_phone_owner_v1($pdo,$owner,'revoke',$command()+$s);
    $exp=$make('Expired transfer');$d=$exp['device'];$s=['device_id'=>$d['id'],'generation'=>$d['generation']];
    $t=vp3_phone_device_v1($pdo,$exp['token'],'handoff_offer',$s+['confirmed'=>true])['handoff_ticket'];
    $reject(403,fn()=>vp3_phone_handoff_target_v1($pdo,'','handoff_claim',['code'=>$t['code'],'claim_key'=>bin2hex(random_bytes(32)),'confirmed'=>true],$t['expires_epoch']),'expired move code rejected');
    vp3_phone_owner_v1($pdo,$owner,'revoke',$command()+$s);
}
