<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
require_once dirname(__DIR__).'/includes/permissions.php';
function db():PDO{return $GLOBALS['db'];}
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);echo "ok - $why\n";}
function reject(int $code,callable $f,string $why):void{try{$f();}catch(RuntimeException $e){check($e->getCode()===$code,$why.' ('.$e->getCode().')');return;}throw new RuntimeException('Expected rejection: '.$why);}
$dsn=getenv('VP3_TEST_MYSQL_DSN');$root=null;$database='vp3_checkins_'.bin2hex(random_bytes(5));
try{
    if($dsn){$dsn=preg_replace('/;dbname=[^;]*/','',$dsn);$root=new PDO($dsn,getenv('VP3_TEST_MYSQL_USER')?:'root',(string)getenv('VP3_TEST_MYSQL_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$root->exec("CREATE DATABASE $database");$db=new PDO($dsn.';dbname='.$database,getenv('VP3_TEST_MYSQL_USER')?:'root',(string)getenv('VP3_TEST_MYSQL_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    else{$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');}
    $db->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,display_name VARCHAR(64),is_active INT,role VARCHAR(32))");
    $db->exec("INSERT INTO users VALUES(1,'Owner',1,'artist'),(2,'Other',1,'artist')");
    $db->exec('CREATE TABLE permissions(permission_key VARCHAR(64) PRIMARY KEY)');$db->exec('CREATE TABLE role_permissions(role VARCHAR(32),permission_key VARCHAR(64))');
    $db->exec("INSERT INTO role_permissions VALUES('artist','account.access'),('artist','chat.access')");
    vp3_phone_schema_v1($db);vp3_phone_schema_v1($db);check(vp3_phone_checkin_ready_v1($db),'check-in upgrade is repeatable');
    $invite=vp3_phone_owner_v1($db,1,'invite',['request_id'=>bin2hex(random_bytes(16))])['invite'];
    $claim=vp3_phone_device_v1($db,'','claim',['invite'=>$invite,'claim_key'=>bin2hex(random_bytes(32)),'name'=>'Location phone']);$id=$claim['device']['id'];$token=$claim['token'];
    $op=fn(string $action,array $fields=[])=>vp3_phone_device_v1($db,$token,$action,['device_id'=>$id,'generation'=>vp3_phone_row_v1($db,1,$id)['generation']]+$fields)['device'];
    $visit=bin2hex(random_bytes(16));
    reject(403,fn()=>$op('location_checkin',['visit_id'=>$visit,'confirmed'=>true,'learn'=>true]),'pending phones cannot check in');
    vp3_phone_owner_v1($db,1,'approve',['request_id'=>bin2hex(random_bytes(16)),'device_id'=>$id,'generation'=>1,'role'=>'owner']);
    reject(409,fn()=>$op('location_checkin',['visit_id'=>$visit,'confirmed'=>true,'learn'=>true]),'check-in cannot start GPS or invent a location');
    $op('start');$now=time();$fix=['latitude'=>33.45,'longitude'=>-112.07,'accuracy'=>10,'fix_at'=>$now];$d=$op('fix',$fix);
    reject(422,fn()=>$op('location_checkin',['visit_id'=>$visit,'learn'=>true]),'check-in needs explicit confirmation');
    reject(403,fn()=>vp3_phone_device_v1($db,$token,'location_checkin',['device_id'=>str_repeat('a',32),'generation'=>$d['generation'],'confirmed'=>true,'visit_id'=>$visit,'learn'=>true]),'another device identity is rejected');
    $body=['visit_id'=>$visit,'confirmed'=>true,'learn'=>false];$d=$op('location_checkin',$body);check($d['checkins']['active']['visit_id']===$visit,'explicit holder check-in starts one durable visit');
    check($op('location_checkin',$body)['checkins']['active']===$d['checkins']['active'],'lost check-in acknowledgement does not duplicate the visit');
    reject(409,fn()=>$op('location_checkin',['visit_id'=>bin2hex(random_bytes(16)),'confirmed'=>true,'learn'=>true]),'one active location per phone');
    check(vp3_location_specialist_checkin_context_v1($db,['id'=>1])===[],'unselected learning never becomes Curiosity evidence');
    $row=vp3_phone_row_v1($db,1,$id);$row['fix_at']=$now+10;$row['longitude']=-112.06;$row['accuracy']=400;
    vp3_phone_checkin_fix_v1($db,$row,$now+10);check(vp3_phone_checkin_view_v1($db,$row,$now+10)['active']!==null,'inaccurate GPS jumps do not check out');
    $row['fix_at']=$now+20;$row['accuracy']=10;vp3_phone_checkin_fix_v1($db,$row,$now+20);check(vp3_phone_checkin_view_v1($db,$row,$now+20)['active']!==null,'one accurate outside fix does not check out');
    $row['fix_at']=$now+30;$row['longitude']=-112.07;vp3_phone_checkin_fix_v1($db,$row,$now+30);
    $row['fix_at']=$now+55;$row['longitude']=-112.06;vp3_phone_checkin_fix_v1($db,$row,$now+55);check(vp3_phone_checkin_view_v1($db,$row,$now+55)['active']!==null,'returning inside clears the departure candidate');
    $row['fix_at']=$now+85;vp3_phone_checkin_fix_v1($db,$row,$now+85);$view=vp3_phone_checkin_view_v1($db,$row,$now+85);
    check($view['active']===null&&$view['recent'][0]['reason']==='left_area'&&$view['recent'][0]['ended_epoch']===$now+55,'two accurate outside readings confirm checkout at first departure observation');
    $source=['type'=>'location_visit','id'=>$visit,'revision'=>2,'expires_epoch'=>$now+3600];
    check(vp3_phone_push_source_target_v1($db,$row,$source,$now+85)===['tab'=>'phone'],'checkout alerts resolve only their current private Phone destination');
    check(vp3_phone_push_source_target_v1($db,array_replace($row,['owner_user_id'=>2]),$source,$now+85)===null,'another owner cannot resolve a checkout alert');
    check(vp3_phone_push_source_target_v1($db,array_replace($row,['generation'=>$row['generation']+1]),$source,$now+85)===null,'stale sharing generation cannot deliver an old checkout alert');
    check(vp3_phone_push_source_target_v1($db,array_replace($row,['allow_location'=>0]),$source,$now+85)===null,'withdrawing location blocks queued checkout alerts');
    reject(409,fn()=>$op('location_checkin',$body),'replaying a completed check-in cannot reopen it');
    $visit=bin2hex(random_bytes(16));$d=$op('location_checkin',['visit_id'=>$visit,'confirmed'=>true,'learn'=>true]);
    $r=vp3_phone_sql_v1($db,'SELECT * FROM vp3_phone_checkins_v1 WHERE visit_id=?',[$visit])->fetch(PDO::FETCH_ASSOC);
    foreach([1,2] as $day){$copy=$r;$copy['visit_id']=bin2hex(random_bytes(16));$copy['started_epoch']=$now-$day*86400;$copy['ended_epoch']=$copy['started_epoch']+3600;$copy['reason']='manual';$columns=implode(',',array_keys($copy));vp3_phone_sql_v1($db,'INSERT INTO vp3_phone_checkins_v1('.$columns.') VALUES('.implode(',',array_fill(0,count($copy),'?')).')',array_values($copy));}
    $d=$op('status');check(count($d['checkins']['patterns'])===1&&$d['checkins']['patterns'][0]['label']==='','three explicit learned visits on separate days suggest a question, not a place identity');
    $context=vp3_location_specialist_checkin_context_v1($db,['id'=>1]);check(count($context)===1&&!str_contains(json_encode($context),'latitude')&&!str_contains(json_encode($context),$id),'Location Specialist shares bounded patterns without coordinates or device identity');
    check(vp3_location_specialist_checkin_context_v1($db,['id'=>2])===[],'another account cannot read location patterns');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>1];
    // Scope checks also work when the full owner runtime is loaded.
    if(function_exists('vp3_participant_scope_v1'))check(vp3_location_specialist_checkin_context_v1($db,['id'=>1])===[],'participants do not receive private patterns');
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    reject(409,fn()=>$op('checkin_label',['visit_id'=>$visit,'version'=>99,'confirmed'=>true,'label'=>'Work']),'stale place labels cannot overwrite reviewed learning');
    $d=$op('checkin_label',['visit_id'=>$visit,'version'=>1,'confirmed'=>true,'label'=>'Work']);check($d['checkins']['active']['label']==='Work'&&$d['checkins']['patterns'][0]['label']==='Work','explicit label applies to matching learned visits');
    reject(409,fn()=>$op('location_checkout',['visit_id'=>$visit,'version'=>1,'confirmed'=>true]),'stale checkout is rejected');
    $d=$op('location_checkout',['visit_id'=>$visit,'version'=>2,'confirmed'=>true]);check($d['checkins']['active']===null&&count(array_filter($d['checkins']['recent'],fn($x)=>$x['visit_id']===$visit&&$x['reason']==='manual'))===1,'manual checkout closes the timer');
    $op('checkin_forget',['confirmed'=>true]);check(vp3_location_specialist_checkin_context_v1($db,['id'=>1])===[],'forget removes all check-in learning and confirmed labels');
    vp3_phone_owner_v1($db,1,'set_holder_place_access',['request_id'=>bin2hex(random_bytes(16)),'device_id'=>$id,'generation'=>$d['generation'],'holder_can_save_places'=>true,'holder_place_version'=>$d['holder_place_version']]);
    $d=$op('set_place_permission',['allow_saved_places'=>true,'place_permission_version'=>$d['place_permission_version']]);
    $saved=$op('holder_save_place',['request_id'=>bin2hex(random_bytes(16)),'holder_place_version'=>$d['holder_place_version'],'name'=>'Studio','latitude'=>33.45,'longitude'=>-112.07,'accuracy'=>10,'fix_at'=>time()]);
    $last=bin2hex(random_bytes(16));$d=$op('location_checkin',['visit_id'=>$last,'confirmed'=>true,'learn'=>true]);check($d['checkins']['active']['label']==='Studio','an explicitly enabled saved place names a matching check-in');$d=$op('stop');check($d['checkins']['active']===null&&count(array_filter($d['checkins']['recent'],fn($x)=>$x['visit_id']===$last&&$x['reason']==='sharing_stopped'))===1,'stopping sharing ends the active timer without inventing departure');
    vp3_phone_owner_v1($db,1,'revoke',['request_id'=>bin2hex(random_bytes(16)),'device_id'=>$id,'generation'=>$d['generation']]);
    check((int)vp3_phone_sql_v1($db,'SELECT COUNT(*) FROM vp3_phone_checkins_v1',[])->fetchColumn()===0,'revocation erases visit locations and learning');
    echo 'PHONE_LOCATION_CHECKINS_V1=PASS ('.($dsn?'MySQL':'SQLite').")\n";
}finally{if($root)$root->exec("DROP DATABASE IF EXISTS $database");}
