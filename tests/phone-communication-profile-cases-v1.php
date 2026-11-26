<?php
declare(strict_types=1);

/** Shared domain assertions: the same contract runs against SQLite and MySQL. */
function vp3_test_phone_communication_v1(PDO $db,int $owner,int $other,string $id,string $token,callable $assert):void
{
    $status=fn()=>vp3_phone_device_v1($db,$token,'status',[])['device'];
    $current=$status();
    $generation=$current['generation'];
    $base=['device_id'=>$id,'generation'=>$generation];
    $holderFields=['phone_e164'=>'+1 (602) 555-0129','holder_calls'=>false,'holder_proactive'=>false,
        'holder_requests'=>false,'holder_memory'=>false,'timezone'=>'America/Phoenix','window_start'=>'09:00',
        'window_end'=>'17:00','max_calls_per_day'=>2,'min_gap_minutes'=>180];
    $save=fn(int $rev,array $fields=[])=>vp3_phone_device_v1($db,$token,'save_communication_profile',
        array_replace($base,$holderFields,['communication_revision'=>$rev],$fields))['device']['communication'];
    $grant=fn(int $rev,bool $calls,bool $requests)=>vp3_phone_communication_owner_v1($db,$owner,
        $base+['communication_revision'=>$rev,'owner_calls'=>$calls,'owner_requests'=>$requests])['device']['communication'];
    $reject=function(int $code,callable $f,string $label)use($assert):void{
        try{$f();}catch(RuntimeException $e){$assert($e->getCode()===$code,$label);return;}
        $assert(false,$label);
    };
    $initial=$current['communication'];
    $assert($initial['revision']===0&&!$initial['phone_saved']&&!$initial['owner_calls']
        &&!$initial['holder_calls']&&!$initial['execution_enabled'],'communication defaults grant nothing');
    $saved=$save(0,['holder_calls'=>true,'holder_proactive'=>true]);
    $assert($saved['phone_e164']==='+16025550129'&&$saved['phone_revision']===1&&$saved['revision']===1
        &&!$saved['holder_calls']&&!$saved['holder_proactive']&&!$saved['identity_verified'],'number normalization never implies consent or verification');
    $profile=$saved['profile_id'];
    $map=vp3_phone_communication_map_v1($db,$owner,vp3_phone_owner_v1($db,$owner,'state',[])['devices'],time());
    $matches=array_values(array_filter($map,fn($d)=>$d['id']===$id));
    $masked=$matches[0]['communication'];
    $assert($masked['phone_masked']==='Ending in 0129'&&!array_key_exists('phone_e164',$masked)
        &&$masked['profile_id']===$profile,'owner projection masks number and identifies same profile');
    $reject(422,fn()=>$save(1,['holder_calls'=>true]),'holder cannot grant calls without owner permission');
    $reject(422,fn()=>$save(1,['window_end'=>'08:00']),'invalid calling window is rejected');
    $reject(422,fn()=>$save(1,['phone_e164'=>'not a phone']),'invalid phone number is rejected');
    $reject(403,fn()=>$save(1,['device_id'=>str_repeat('0',32)]),'holder cannot edit another pairing');
    $reject(404,fn()=>vp3_phone_communication_owner_v1($db,$other,$base+['communication_revision'=>1,'owner_calls'=>true,'owner_requests'=>true]),'another owner cannot grant communication');
    $permitted=$grant(1,true,true);
    $assert(!$permitted['holder_scheduled_agent']&&!$permitted['holder_spontaneous_agent'],'owner authorization cannot grant recorded agent calls');
    $assert($permitted['revision']===2&&!$permitted['call_permission_saved']&&!$permitted['request_permission_saved'],'owner grants wait for holder consent');
    $reject(409,fn()=>$save(1),'stale communication save is rejected');
    $consented=$save(2,['holder_scheduled_agent'=>true,'holder_spontaneous_agent'=>true,'holder_calls'=>true,'holder_proactive'=>true,'holder_requests'=>true,'holder_memory'=>true]);
    $assert($consented['revision']===3&&$consented['call_permission_saved']&&$consented['proactive_permission_saved']
        &&$consented['request_permission_saved']&&$consented['holder_memory']&&!$consented['execution_enabled'],'independent consents are saved without execution');
    $same=$save(3,['holder_calls'=>true,'holder_proactive'=>true,'holder_requests'=>true,'holder_memory'=>true]);
    $assert($same['holder_scheduled_agent']&&$same['holder_spontaneous_agent'],'a legacy save preserves explicit holder mode choices');
    $assert($same['revision']===3,'unchanged preferences do not advance revision');
    $changed=$save(3,['phone_e164'=>'+16025550130','holder_calls'=>true,'holder_proactive'=>true,'holder_requests'=>true]);
    $assert(!$changed['holder_scheduled_agent']&&!$changed['holder_spontaneous_agent'],'changing destination clears both recorded AI call grants');
    $assert($changed['phone_revision']===2&&$changed['profile_id']===$profile&&!$changed['holder_calls']
        &&!$changed['holder_proactive']&&!$changed['call_permission_saved'],'changing number clears call consent and preserves profile identity');
    $savedAgain=$save(4,['holder_scheduled_agent'=>true,'holder_spontaneous_agent'=>false,'phone_e164'=>'+16025550130','holder_calls'=>true,'holder_proactive'=>true,'holder_requests'=>true]);
    $assert($savedAgain['holder_scheduled_agent']&&!$savedAgain['holder_spontaneous_agent'],'the two AI call modes have independent grants');
    $assert($savedAgain['call_permission_saved'],'holder can explicitly consent for the new number');
    $off=$grant(5,false,false);
    $assert(!$off['holder_scheduled_agent']&&!$off['holder_spontaneous_agent'],'revoking owner call permission clears recorded AI call grants');
    $assert(!$off['holder_calls']&&!$off['holder_proactive']&&!$off['holder_requests'],'owner revocation clears dependent holder consent');
    $on=$grant(6,true,true);
    $assert(!$on['call_permission_saved']&&!$on['request_permission_saved'],'re-enabling owner grant does not restore holder consent');
    $last=$status();
    $assert($last['generation']===$generation&&$last['location_state']===$current['location_state']
        &&$last['communication']['profile_id']===$profile,'communication edits preserve location state and pairing generation');
}
