<?php
declare(strict_types=1);

/** Location Specialist: explicit visits, bounded history and opt-in pattern evidence. */
const VP3_PHONE_CHECKIN_RADIUS_V1=150;
function vp3_phone_checkin_install_v1(PDO $pdo,string $options):void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_checkins_v1(
        owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,visit_id CHAR(32) NOT NULL,
        generation BIGINT NOT NULL,version BIGINT NOT NULL,latitude DOUBLE NOT NULL,longitude DOUBLE NOT NULL,
        accuracy DOUBLE NOT NULL,started_epoch BIGINT NOT NULL,ended_epoch BIGINT NULL,
        last_fix_epoch BIGINT NOT NULL,outside_epoch BIGINT NULL,reason VARCHAR(24) NOT NULL,
        learn SMALLINT NOT NULL DEFAULT 0,label VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY(owner_user_id,device_id,visit_id),
        FOREIGN KEY(owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
    )".$options);
}
function vp3_phone_checkin_ready_v1(PDO $pdo):bool
{
    try{$pdo->query('SELECT owner_user_id,device_id,visit_id,generation,version,latitude,longitude,accuracy,started_epoch,ended_epoch,last_fix_epoch,outside_epoch,reason,learn,label FROM vp3_phone_checkins_v1 WHERE 1=0');return true;}
    catch(PDOException){return false;}
}
function vp3_phone_checkin_distance_v1(float $a,float $b,float $x,float $y):float
{
    $p=deg2rad($x-$a);$q=deg2rad($y-$b);
    $h=sin($p/2)**2+cos(deg2rad($a))*cos(deg2rad($x))*sin($q/2)**2;
    return 6371000*2*asin(sqrt(min(1,max(0,$h))));
}
function vp3_phone_checkin_rows_v1(PDO $pdo,array $d,int $now):array
{
    return vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_checkins_v1 WHERE owner_user_id=? AND device_id=? AND (ended_epoch IS NULL OR started_epoch>?) ORDER BY started_epoch DESC,visit_id DESC LIMIT 96',[(int)$d['owner_user_id'],$d['id'],$now-30*86400])->fetchAll(PDO::FETCH_ASSOC);
}
function vp3_phone_checkin_brief_v1(array $r,int $now):array
{
    return ['visit_id'=>$r['visit_id'],'version'=>(int)$r['version'],'label'=>$r['label'],
        'started_epoch'=>(int)$r['started_epoch'],'ended_epoch'=>$r['ended_epoch']===null?null:(int)$r['ended_epoch'],
        'last_fix_epoch'=>(int)$r['last_fix_epoch'],'duration_seconds'=>max(0,($r['ended_epoch']===null?$now:(int)$r['ended_epoch'])-(int)$r['started_epoch']),
        'reason'=>$r['reason'],'learning'=>$r['learn']==1];
}
function vp3_phone_checkin_patterns_v1(array $rows):array
{
    $groups=[];
    foreach($rows as $r){
        if($r['learn']!=1)continue;
        $index=null;
        foreach($groups as $k=>$g)if(vp3_phone_checkin_distance_v1((float)$g['latitude'],(float)$g['longitude'],(float)$r['latitude'],(float)$r['longitude'])<=VP3_PHONE_CHECKIN_RADIUS_V1){$index=$k;break;}
        if($index===null){$index=count($groups);$groups[]=['latitude'=>$r['latitude'],'longitude'=>$r['longitude'],'visit_id'=>$r['visit_id'],'version'=>(int)$r['version'],'label'=>$r['label'],'visits'=>0,'days'=>[],'seconds'=>0];}
        $groups[$index]['visits']++;
        $groups[$index]['days'][gmdate('Y-m-d',(int)$r['started_epoch'])]=true;
        if($r['ended_epoch']!==null)$groups[$index]['seconds']+=max(0,(int)$r['ended_epoch']-(int)$r['started_epoch']);
    }
    $out=[];
    foreach($groups as $g){
        if($g['visits']<3||count($g['days'])<3)continue;
        $out[]=['visit_id'=>$g['visit_id'],'version'=>$g['version'],'label'=>$g['label'],'visits'=>$g['visits'],
            'distinct_days'=>count($g['days']),'completed_duration_seconds'=>$g['seconds'],
            'question'=>$g['label']===''?'You have checked in here on several days. Is this work, school, or another regular place?':''];
        if(count($out)===6)break;
    }
    return $out;
}
function vp3_phone_checkin_view_v1(PDO $pdo,array $d,int $now):array
{
    if(!vp3_phone_checkin_ready_v1($pdo))return ['ready'=>false];
    if($d['device_status']!=='approved'||(int)$d['expires_at']<=$now)return ['ready'=>true,'active'=>null,'recent'=>[],'patterns'=>[]];
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_checkins_v1 WHERE owner_user_id=? AND device_id=? AND ended_epoch IS NOT NULL AND started_epoch<=?',[(int)$d['owner_user_id'],$d['id'],$now-30*86400]);
    $rows=vp3_phone_checkin_rows_v1($pdo,$d,$now);$active=null;$recent=[];
    foreach($rows as $r){if($r['ended_epoch']===null)$active=vp3_phone_checkin_brief_v1($r,$now);elseif(count($recent)<5)$recent[]=vp3_phone_checkin_brief_v1($r,$now);}
    return ['ready'=>true,'server_epoch'=>$now,'radius_metres'=>VP3_PHONE_CHECKIN_RADIUS_V1,'active'=>$active,'recent'=>$recent,
        'patterns'=>(bool)$d['allow_location']?vp3_phone_checkin_patterns_v1($rows):[]];
}
function vp3_phone_checkin_close_v1(PDO $pdo,array $d,array $r,int $at,string $reason,int $now):void
{
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_checkins_v1 SET ended_epoch=?,reason=?,version=version+1,outside_epoch=NULL WHERE owner_user_id=? AND device_id=? AND visit_id=? AND ended_epoch IS NULL',
        [max((int)$r['started_epoch'],$at),$reason,(int)$d['owner_user_id'],$d['id'],$r['visit_id']]);
    if($reason==='left_area')vp3_phone_push_event_enqueue_v1($pdo,$d,['type'=>'location_visit','id'=>$r['visit_id'],'revision'=>(int)$r['version']+1,'expires_epoch'=>$now+86400],$now);
}
/** Called only for accepted fresh GPS fixes, never polling or elapsed-time inference. */
function vp3_phone_checkin_fix_v1(PDO $pdo,array $d,int $now):void
{
    if(!vp3_phone_checkin_ready_v1($pdo))return;
    foreach(vp3_phone_checkin_rows_v1($pdo,$d,$now) as $r){
        if($r['ended_epoch']!==null)continue;
        if((int)$r['generation']!==(int)$d['generation']){vp3_phone_checkin_close_v1($pdo,$d,$r,$now,'sharing_stopped',$now);continue;}
        if((int)$d['fix_at']<=(int)$r['last_fix_epoch'])continue;
        $outside=null;
        if((float)$d['accuracy']<=50){
            $distance=vp3_phone_checkin_distance_v1((float)$r['latitude'],(float)$r['longitude'],(float)$d['latitude'],(float)$d['longitude']);
            if($distance>VP3_PHONE_CHECKIN_RADIUS_V1+(float)$r['accuracy']+(float)$d['accuracy']){
                $outside=$r['outside_epoch']===null||(int)$d['fix_at']-(int)$r['last_fix_epoch']>120?(int)$d['fix_at']:(int)$r['outside_epoch'];
                if((int)$d['fix_at']-$outside>=30){vp3_phone_checkin_close_v1($pdo,$d,$r,$outside,'left_area',$now);continue;}
            }
        }
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_checkins_v1 SET last_fix_epoch=?,outside_epoch=? WHERE owner_user_id=? AND device_id=? AND visit_id=?',[(int)$d['fix_at'],$outside,(int)$d['owner_user_id'],$d['id'],$r['visit_id']]);
    }
}
/** Sharing withdrawal ends observation; device revocation also removes visit history. */
function vp3_phone_checkin_fence_v1(PDO $pdo,array $d,int $now,bool $erase=false):void
{
    if(!vp3_phone_checkin_ready_v1($pdo))return;
    if($erase){vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_checkins_v1 WHERE owner_user_id=? AND device_id=?',[(int)$d['owner_user_id'],$d['id']]);return;}
    foreach(vp3_phone_checkin_rows_v1($pdo,$d,$now) as $r)if($r['ended_epoch']===null)vp3_phone_checkin_close_v1($pdo,$d,$r,$now,'sharing_stopped',$now);
}
function vp3_phone_checkin_action_v1(PDO $pdo,array $d,string $action,array $input,int $now):void
{
    if(!vp3_phone_checkin_ready_v1($pdo))throw new RuntimeException('Run Database Upgrade to enable location check-ins.',503);
    if(($input['device_id']??null)!==$d['id']||$d['device_status']!=='approved')throw new RuntimeException('Phone unavailable.',403);
    vp3_phone_generation_v1($d,$input);
    if(($input['confirmed']??null)!==true)throw new RuntimeException('Confirm this location action.',422);
    // Prune only closed visits: an offline interval never pretends the phone departed.
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_checkins_v1 WHERE owner_user_id=? AND device_id=? AND ended_epoch IS NOT NULL AND started_epoch<=?',[(int)$d['owner_user_id'],$d['id'],$now-30*86400]);
    $rows=vp3_phone_checkin_rows_v1($pdo,$d,$now);
    if($action==='checkin_forget'){
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_checkins_v1 SET learn=0,label='',version=version+1 WHERE owner_user_id=? AND device_id=?",[(int)$d['owner_user_id'],$d['id']]);return;
    }
    $id=vp3_phone_information_id_v1($input['visit_id']??null);
    if($action==='location_checkin'){
        if(!$d['allow_location']||$d['location_state']!=='live'||$d['fix_at']===null||(int)$d['fix_at']<=$now-60||(float)$d['accuracy']>50)throw new RuntimeException('Share a fresh, accurate location before checking in.',409);
        if(!is_bool($input['learn']??null))throw new RuntimeException('Choose whether to learn from this visit.',422);
        foreach($rows as $r){
            if($r['visit_id']===$id){if($r['ended_epoch']!==null||($r['learn']==1)!==$input['learn'])throw new RuntimeException('Check-in changed.',409);return;}
            if($r['ended_epoch']===null)throw new RuntimeException('Check out of your current location first.',409);
        }
        $label='';
        // A confirmed check-in name can be reused; no guessed purpose is persisted.
        foreach($rows as $old)if($old['label']!==''&&vp3_phone_checkin_distance_v1((float)$old['latitude'],(float)$old['longitude'],(float)$d['latitude'],(float)$d['longitude'])<=VP3_PHONE_CHECKIN_RADIUS_V1){$label=$old['label'];break;}
        if($label===''&&$d['device_role']==='owner'&&$d['holder_can_save_places']&&vp3_phone_public_v1($d,$now)['allow_saved_places']){
            $places=vp3_phone_sql_v1($pdo,'SELECT name,latitude,longitude FROM vp3_phone_places_v1 WHERE owner_user_id=? ORDER BY created_at DESC,id LIMIT 128',[(int)$d['owner_user_id']])->fetchAll(PDO::FETCH_ASSOC);
            foreach($places as $place)if(vp3_phone_checkin_distance_v1((float)$place['latitude'],(float)$place['longitude'],(float)$d['latitude'],(float)$d['longitude'])<=VP3_PHONE_CHECKIN_RADIUS_V1){$label=mb_substr($place['name'],0,64);break;}
        }
        $count=(int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_checkins_v1 WHERE owner_user_id=? AND device_id=? AND started_epoch>?',[(int)$d['owner_user_id'],$d['id'],$now-86400])->fetchColumn();
        if($count>=24)throw new RuntimeException('Daily check-in limit reached.',429);
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_checkins_v1(owner_user_id,device_id,visit_id,generation,version,latitude,longitude,accuracy,started_epoch,ended_epoch,last_fix_epoch,outside_epoch,reason,learn,label) VALUES(?,?,?,?,1,?,?,?,?,NULL,?,NULL,'',?,?)",[(int)$d['owner_user_id'],$d['id'],$id,(int)$d['generation'],(float)$d['latitude'],(float)$d['longitude'],(float)$d['accuracy'],$now,(int)$d['fix_at'],$input['learn']?1:0,$label]);return;
    }
    $r=null;foreach($rows as $row)if($row['visit_id']===$id)$r=$row;
    if(!$r)throw new RuntimeException('Check-in unavailable.',404);
    if(($input['version']??null)!==(int)$r['version'])throw new RuntimeException('Check-in changed. Refresh first.',409);
    if($action==='location_checkout'){
        if($r['ended_epoch']!==null)throw new RuntimeException('Already checked out.',409);
        vp3_phone_checkin_close_v1($pdo,$d,$r,$now,'manual',$now);return;
    }
    if($action!=='checkin_label'||$r['learn']!=1)throw new RuntimeException('This visit is not available for learning.',403);
    $label=$input['label']??null;
    if(!is_string($label)||trim($label)===''||mb_strlen($label)>64||preg_match('/[\x00-\x1F\x7F]/',$label))throw new RuntimeException('Enter a place name up to 64 characters.',422);
    foreach($rows as $row)if($row['learn']==1&&vp3_phone_checkin_distance_v1((float)$r['latitude'],(float)$r['longitude'],(float)$row['latitude'],(float)$row['longitude'])<=VP3_PHONE_CHECKIN_RADIUS_V1)
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_checkins_v1 SET label=?,version=version+1 WHERE owner_user_id=? AND device_id=? AND visit_id=?',[trim($label),(int)$d['owner_user_id'],$d['id'],$row['visit_id']]);
}
/** Curiosity receives counts and confirmed names, never precise coordinates or guessed identity. */
function vp3_location_specialist_checkin_context_v1(PDO $pdo,array $user):array
{
    if((function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)||!vp3_phone_checkin_ready_v1($pdo))return [];
    $now=time();$out=[];
    $devices=vp3_phone_sql_v1($pdo,"SELECT * FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND device_role='owner' AND device_status='approved' AND allow_location=1 AND expires_at>? ORDER BY id LIMIT 32",[(int)$user['id'],$now])->fetchAll(PDO::FETCH_ASSOC);
    foreach($devices as $d)foreach(vp3_phone_checkin_patterns_v1(vp3_phone_checkin_rows_v1($pdo,$d,$now)) as $p){
        unset($p['visit_id'],$p['version']);$out[]=$p;
        if(count($out)===6)return $out;
    }
    return $out;
}
