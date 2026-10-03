<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/tracky-cloud-v270.php';
function sceneExpect(bool $yes,string $message): void {if(!$yes)throw new RuntimeException($message);}
function sceneReject(callable $fn): void {try{$fn();}catch(Throwable){return;}throw new RuntimeException('Invalid scene meaning accepted');}
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/tracky-shared-scene-v1g3d.json'),true,32,JSON_THROW_ON_ERROR);
foreach($fixtures as $v){sceneExpect(tracky_scene_normalize_v1g3d($v)===$v,'Cross-language golden fixture mismatch');}
$first=$fixtures[0];$now=(float)(new DateTimeImmutable($first['summary']['observed_at']))->format('U.u');
$recent=tracky_scene_project_v1g3d($first['summary'],$now+59.99);
sceneExpect($recent['state']==='available'&&$recent['owner_corrections'][0]['conflicts_with_camera'],'Fresh evidence/report conflict missing');
sceneExpect($recent['owner_corrections'][1]['conflicts_with_camera'],'Positive owner report not separate from detector');
sceneExpect(!tracky_scene_project_v1g3d($first['summary'],$now-0.001)['capture_authority'],'Camera authority leaked');
foreach([$now-0.001,$now+60.001] as $clock){$v=tracky_scene_project_v1g3d($first['summary'],$clock);sceneExpect($v['state']==='unavailable'&&!isset($v['objects'])&&!isset($v['owner_corrections']),'Stale/future evidence exposed labels');}
sceneExpect(tracky_scene_decide_v1g3d($first,2,$fixtures[1]['fingerprint'])['accepted']===false,'Late scene overrode revocation');
sceneExpect(tracky_scene_decide_v1g3d($first,1,$first['fingerprint'])['accepted']===true,'Idempotent retry denied');
sceneExpect(tracky_scene_decide_v1g3d($first,1,$fixtures[1]['fingerprint'])['accepted']===false,'Equal revision conflict accepted');
sceneExpect(tracky_scene_decide_v1g3d($fixtures[1],1,$first['fingerprint'])['accepted']===true,'New revocation denied');
$mutations=[fn($v)=>array_replace($v,['revision'=>true]),fn($v)=>array_replace($v,['extra'=>'secret']),fn($v)=>array_replace($v,['fingerprint'=>str_repeat('0',64)])];
foreach($mutations as $mutate)sceneReject(fn()=>tracky_scene_normalize_v1g3d($mutate($first)));
foreach(['caption'=>'SECRET','images'=>['media'],'model'=>'secret-provider','session_id'=>'secret-session'] as $key=>$value){$bad=$first;$bad['summary'][$key]=$value;$bad['fingerprint']=tracky_scene_digest_v1g3d($bad['summary']);sceneReject(fn()=>tracky_scene_normalize_v1g3d($bad));}
foreach([['objects'=>['person']],['objects'=>['chair','chair']],['objects'=>array_slice(TRACKY_SCENE_OBJECTS_V1G3D,0,9)],['setting'=>'secret'],['lighting'=>[]],['observed_at'=>'2026-02-30T12:00:00Z'],['room_id'=>'../../private'],['owner_corrections'=>[['object'=>'cup','present'=>'false']]]] as $change){$bad=$first;$bad['summary']=array_replace($bad['summary'],$change);$bad['fingerprint']=tracky_scene_digest_v1g3d($bad['summary']);sceneReject(fn()=>tracky_scene_normalize_v1g3d($bad));}
$revoked=$fixtures[1];$revoked['summary']['objects']=['chair'];$revoked['fingerprint']=tracky_scene_digest_v1g3d($revoked['summary']);sceneReject(fn()=>tracky_scene_normalize_v1g3d($revoked));
sceneExpect(tracky_agent_intent_v271('Show me what Tracky sees right now')==='current','Scene query not connected to Agent Chat');
sceneExpect(tracky_agent_intent_v271('What can Agent Eyes see?')==='current','Agent Eyes query ignored');
$answer=tracky_agent_answer_v271(['agent_scene'=>$recent],'current');sceneExpect(str_contains($answer,'possible objects')&&str_contains($answer,'uncalibrated')&&str_contains($answer,'Owner reports'),'Scene explanation lost source or uncertainty');
$rows=tracky_scene_rows_v1g3d($recent+['site_id'=>'home']);sceneExpect(count($rows)===5,'Typed graph not complete');foreach($rows as $r)sceneExpect($r['confidence']===0.0&&$r['temporal_state']==='inferred'&&tracky_scene_reserved_v1g3d($r),'Scene graph claimed calibrated inventory');
sceneExpect(tracky_scene_rows_v1g3d(['state'=>'revoked'])===[],'Revoked graph retained labels');
echo "TRACKY_SHARED_SCENE_V1G3D: Python/PHP digest parity, strict schemas, uncertainty, provenance, age/future expiry, ordering, conflict separation PASS\n";
