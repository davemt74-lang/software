<?php
declare(strict_types=1);

/** Actual paired phone attachment ledger on SQLite and MySQL. All data is synthetic. */
function vp3_test_phone_files_v1(PDO $db,int $owner,int $other,string $id,string $token,callable $assert):void
{
    $status=fn()=>vp3_phone_device_v1($db,$token,'status',[])['device'];
    $reject=function(int $code,callable $f,string $label)use($assert):void{
        try{$f();}catch(RuntimeException $e){$assert($e->getCode()===$code,$label);return;}
        $assert(false,$label);
    };
    // Advance only the previous synthetic requests beyond the rolling rate window.
    $db->prepare('UPDATE vp3_phone_information_v1 SET created_epoch=? WHERE owner_user_id=? AND device_id=?')->execute([time()-86401,$owner,$id]);
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
        throw new RuntimeException('Fixture attachment missing.');
    };
    $png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jN5sAAAAASUVORK5CYII=';
    $txt=base64_encode("Synthetic private file.\n");
    $ids=array_map(static fn(int $n)=>md5('ps5b-fixture-'.$n),range(1,8));
    $req=fn(int $n,string $kind)=>$ownerAct('information_create',['exchange_id'=>$ids[$n-1],'kind'=>$kind,'message'=>'Private fixture request '.$n]);
    $upload=fn(int $n,int $version,string $data,array $more=[])=>$holderAct('information_attach_file',array_replace([
        'exchange_id'=>$ids[$n-1],'version'=>$version,'file_base64'=>$data,'share_confirmed'=>true],$more));
    $share=fn(int $n,string $data,array $more=[])=>$ownerAct('information_create_file',array_replace([
        'exchange_id'=>$ids[$n-1],'kind'=>'file_share','message'=>'Private fixture file '.$n,'file_base64'=>$data,'share_confirmed'=>true],$more));
    $assert(vp3_phone_file_available_v1($db)&&$ownerAct('information_list')['files_ready'],'file exchange migration and content detection ready');
    $reject(422,fn()=>vp3_phone_file_parse_v1('invalid!',false),'malformed attachment encoding denied');
    $reject(422,fn()=>vp3_phone_file_parse_v1(base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'),false),'active SVG not allowed');
    $reject(413,fn()=>vp3_phone_file_parse_v1(str_repeat('A',4*(int)ceil(VP3_PHONE_FILE_MAX_V1/3)+4),false),'encoded size is bounded before decode');
    $reject(422,fn()=>vp3_phone_file_parse_v1($txt,true),'photo request cannot take plain text');
    $r=$find($req(1,'photo_request'),$ids[0]);
    $assert($r['state']==='pending'&&$r['attachment']===null,'photo request does not access camera or files');
    $reject(409,fn()=>$upload(1,1,$png),'photo upload requires accepted request');
    $a=$holderAct('information_accept',['exchange_id'=>$ids[0],'version'=>1]);
    $assert($find($a,$ids[0])['state']==='accepted','holder accepts photo request');
    $reject(403,fn()=>$upload(1,2,$png,['share_confirmed'=>false]),'attachment sharing requires explicit confirmation');
    $reject(403,fn()=>$holderAct('information_reply',['exchange_id'=>$ids[0],'version'=>2,'response'=>'No file']),'photo request cannot be completed by text');
    $reject(422,fn()=>$upload(1,2,$txt),'wrong attachment content type cannot complete photo request');
    $r=$find($upload(1,2,$png),$ids[0]);
    $assert($r['state']==='completed'&&$r['version']===3&&$r['attachment']['mime']==='image/png'&&$r['attachment']['bytes']===strlen(base64_decode($png,true)),'accepted photo attaches exact bytes and completes once');
    $assert(!isset($r['attachment']['base64'])&&!str_contains(json_encode($ownerAct('information_list'),JSON_THROW_ON_ERROR),$png),'list exposes attachment metadata, never bytes');
    $assert($find($upload(1,2,$png),$ids[0])===$r,'identical upload acknowledgement retry is idempotent');
    $reject(409,fn()=>$upload(1,2,base64_encode(base64_decode($png,true).''),['version'=>3]),'completed attachment cannot be replaced');
    $download=$ownerAct('information_download',['exchange_id'=>$ids[0],'version'=>3])['download'];
    $assert($download['base64']===$png&&$download['sha256']===hash('sha256',base64_decode($png,true)),'authorized owner receives exact photo and digest');
    $reject(409,fn()=>$ownerAct('information_download',['exchange_id'=>$ids[0],'version'=>2]),'stale download version denied');
    $reject(404,fn()=>vp3_phone_information_owner_v1($db,$other,'information_download',$bound()+['exchange_id'=>$ids[0],'version'=>3]),'foreign account cannot retrieve bytes');
    $reject(403,fn()=>$holderAct('information_download',['exchange_id'=>$ids[0],'version'=>3,'device_id'=>str_repeat('0',32)]),'foreign phone scope denied');
    $reject(422,fn()=>$share(2,$txt,['share_confirmed'=>false]),'owner must confirm file share');
    $shared=$find($share(2,$txt),$ids[1]);
    $assert($shared['state']==='pending'&&$shared['attachment']['mime']==='text/plain','owner creates file share atomically');
    $assert($find($share(2,$txt),$ids[1])===$shared,'exact owner share retry has one effect');
    $reject(409,fn()=>$share(2,base64_encode('Different private fixture text.')),'same share ID cannot replace bytes');
    $assert($holderAct('information_download',['exchange_id'=>$ids[1],'version'=>1])['download']['base64']===$txt,'paired holder downloads owner share');
    $ack=$find($holderAct('information_ack',['exchange_id'=>$ids[1],'version'=>1]),$ids[1]);
    $assert($ack['state']==='acknowledged'&&$ack['attachment']!==null,'explicit acknowledgement preserves current file access');
    $share(3,$txt);$cancel=$find($ownerAct('information_cancel',['exchange_id'=>$ids[2],'version'=>1]),$ids[2]);
    $assert($cancel['state']==='canceled'&&$cancel['attachment']===null&&vp3_phone_file_metadata_v1($db,[$owner,$id,$ids[2]])===null,'cancel deletes attachment bytes');
    $share(4,$txt);$holderAct('information_decline',['exchange_id'=>$ids[3],'version'=>1]);
    $assert(vp3_phone_file_metadata_v1($db,[$owner,$id,$ids[3]])===null,'decline deletes attachment bytes');
    $share(5,$txt);
    $db->prepare('UPDATE vp3_phone_information_files_v1 SET expires_epoch=? WHERE owner_user_id=? AND device_id=? AND exchange_id=?')->execute([time()-1,$owner,$id,$ids[4]]);
    $reject(404,fn()=>$holderAct('information_download',['exchange_id'=>$ids[4],'version'=>1]),'expired attachment cannot download');
    $share(6,$txt);
    $ownerAct('information_delete',['exchange_id'=>$ids[5],'version'=>1,'confirmed'=>true]);
    $assert(vp3_phone_file_metadata_v1($db,[$owner,$id,$ids[5]])===null,'permanent deletion also erases shared attachment bytes');
    $reject(404,fn()=>$holderAct('information_download',['exchange_id'=>$ids[5],'version'=>1]),'previous file references cannot download after deletion');
    $assert(vp3_phone_file_metadata_v1($db,[$owner,$id,$ids[4]])===null,'later upload prunes expired attachment bytes');
    $grant(false);
    $reject(403,fn()=>$ownerAct('information_download',['exchange_id'=>$ids[0],'version'=>3]),'consent withdrawal blocks previous downloads');
    $assert((int)vp3_phone_sql_v1($db,'SELECT COUNT(*) FROM vp3_phone_information_files_v1 WHERE owner_user_id=? AND device_id=?',[$owner,$id])->fetchColumn()===0,'revocation purges associated attachments');
    $grant(true);
    $assert($find($ownerAct('information_list'),$ids[0])['attachment']===null,'restoring permission never restores deleted bytes');
    $grant(false);
}
