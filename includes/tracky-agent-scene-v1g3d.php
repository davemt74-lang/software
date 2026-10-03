<?php
declare(strict_types=1);

/** Ordered mirror of optional scene meaning. Never camera or identity authority. */
const TRACKY_SCENE_V1G3D='tracky.agent-eyes.shared-scene.v1g3d';
const TRACKY_SCENE_OBJECTS_V1G3D=['chair','table','sofa','bed','cup','bottle','book','screen','keyboard','phone','lamp','door','window','plant','bag','box'];

function tracky_scene_canonical_v1g3d(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(!array_is_list($value))ksort($value,SORT_STRING);
    foreach($value as &$item)$item=tracky_scene_canonical_v1g3d($item);
    unset($item);return $value;
}
function tracky_scene_digest_v1g3d(array $summary): string {
    return hash('sha256',json_encode(tracky_scene_canonical_v1g3d($summary),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function tracky_scene_shape_v1g3d(array $value,array $keys): void {
    $actual=array_keys($value);sort($actual);sort($keys);
    if($actual!==$keys)throw new RuntimeException('Scene meaning has an unsupported shape.');
}
function tracky_scene_normalize_v1g3d(mixed $value): array {
    if(!is_array($value))throw new RuntimeException('Scene meaning must be an object.');
    tracky_scene_shape_v1g3d($value,['revision','summary','fingerprint']);
    if(!is_int($value['revision'])||$value['revision']<1||$value['revision']>2147483647)
        throw new RuntimeException('Scene revision must be a bounded positive integer.');
    $s=$value['summary'];
    if(!is_array($s)||($s['protocol']??null)!==TRACKY_SCENE_V1G3D||!in_array($s['state']??null,['available','unavailable','revoked'],true))
        throw new RuntimeException('Scene meaning protocol or state is unsupported.');
    if($s['state']==='available'){
        tracky_scene_shape_v1g3d($s,['protocol','state','reason','observed_at','room_id','objects','setting','lighting','owner_corrections']);
        if($s['reason']!=='recent_observation'||!is_string($s['room_id'])||!preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$s['room_id'])
          ||!in_array($s['setting'],['indoor','outdoor','unclear'],true)||!in_array($s['lighting'],['bright','dim','unclear'],true))
            throw new RuntimeException('Scene meaning contains unsupported values.');
        if(!is_string($s['observed_at'])||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D',$s['observed_at']))
            throw new RuntimeException('Scene observation requires a UTC timestamp.');
        $stamp=new DateTimeImmutable($s['observed_at']);$errors=DateTimeImmutable::getLastErrors();
        if($errors!==false&&($errors['warning_count']||$errors['error_count']))throw new RuntimeException('Scene observation timestamp is invalid.');
        if(!is_array($s['objects'])||!array_is_list($s['objects'])||count($s['objects'])>8)throw new RuntimeException('Scene object limit exceeded.');
        foreach($s['objects'] as $label)if(!is_string($label)||!in_array($label,TRACKY_SCENE_OBJECTS_V1G3D,true))throw new RuntimeException('Unsupported scene object.');
        if(count(array_unique($s['objects']))!==count($s['objects']))throw new RuntimeException('Duplicate scene object.');
        sort($s['objects'],SORT_STRING);
        if(!is_array($s['owner_corrections'])||!array_is_list($s['owner_corrections'])||count($s['owner_corrections'])>8)throw new RuntimeException('Scene owner report limit exceeded.');
        $seen=[];
        foreach($s['owner_corrections'] as $report){
            if(!is_array($report))throw new RuntimeException('Invalid owner report.');
            tracky_scene_shape_v1g3d($report,['object','present']);
            if(!is_string($report['object'])||!in_array($report['object'],TRACKY_SCENE_OBJECTS_V1G3D,true)||!is_bool($report['present'])||isset($seen[$report['object']]))throw new RuntimeException('Unsupported owner report.');
            $seen[$report['object']]=true;
        }
        usort($s['owner_corrections'],static fn($a,$b)=>strcmp($a['object'],$b['object']));
    }else{
        tracky_scene_shape_v1g3d($s,['protocol','state','reason']);
        $reasons=['owner_revoked','scene_unavailable','privacy_enabled','no_session','session_stopped','owner_presence_expired','owner_approval_required','model_review_required','session_evidence_mismatch','observation_missing','observation_expired','timestamp_invalid','evidence_missing','evidence_invalid','detector_uninterpretable','session_changed','status_unavailable'];
        if(!is_string($s['reason'])||!in_array($s['reason'],$reasons,true)||($s['state']==='revoked'&&$s['reason']!=='owner_revoked'))throw new RuntimeException('Unsupported scene availability reason.');
    }
    $digest=tracky_scene_digest_v1g3d($s);
    if(!is_string($value['fingerprint'])||!hash_equals($digest,$value['fingerprint']))throw new RuntimeException('Scene meaning fingerprint mismatch.');
    return ['revision'=>$value['revision'],'summary'=>$s,'fingerprint'=>$digest];
}
function tracky_scene_ensure_schema_v1g3d(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_scene_share_order (
        user_id INT UNSIGNED NOT NULL, site_id VARCHAR(100) NOT NULL,
        accepted_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        fingerprint CHAR(64) NOT NULL DEFAULT '', summary_json TEXT NULL,
        PRIMARY KEY(user_id,site_id),
        CONSTRAINT fk_tracky_scene_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function tracky_scene_decide_v1g3d(array $incoming,int $revision,string $fingerprint): array {
    if($incoming['revision']<$revision)return ['accepted'=>false,'reason'=>'stale_revision'];
    if($incoming['revision']===$revision)return ['accepted'=>hash_equals($fingerprint,$incoming['fingerprint']),'reason'=>hash_equals($fingerprint,$incoming['fingerprint'])?'idempotent':'revision_conflict'];
    return ['accepted'=>true,'reason'=>'newer_revision'];
}
function tracky_scene_apply_v1g3d(PDO $pdo,int $userId,string $siteId,?array $incoming): array {
    if(!$pdo->inTransaction()||$userId<1||$siteId==='')throw new RuntimeException('Scene ordering requires an authenticated site sync transaction.');
    $q=$pdo->prepare('INSERT IGNORE INTO tracky_cloud_scene_share_order(user_id,site_id) VALUES(?,?)');$q->execute([$userId,$siteId]);
    $q=$pdo->prepare('SELECT accepted_revision,fingerprint,summary_json FROM tracky_cloud_scene_share_order WHERE user_id=? AND site_id=? FOR UPDATE');$q->execute([$userId,$siteId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('Scene order ledger unavailable.');
    $decision=$incoming===null?['accepted'=>false,'reason'=>'not_reported']:tracky_scene_decide_v1g3d($incoming,(int)$row['accepted_revision'],(string)$row['fingerprint']);
    if($incoming!==null&&$decision['accepted']){
        $q=$pdo->prepare('UPDATE tracky_cloud_scene_share_order SET accepted_revision=?,fingerprint=?,summary_json=? WHERE user_id=? AND site_id=?');
        $q->execute([$incoming['revision'],$incoming['fingerprint'],json_encode($incoming['summary'],JSON_THROW_ON_ERROR),$userId,$siteId]);
        $row=['accepted_revision'=>$incoming['revision'],'fingerprint'=>$incoming['fingerprint'],'summary_json'=>json_encode($incoming['summary'],JSON_THROW_ON_ERROR)];
    }
    $s=json_decode((string)($row['summary_json']??''),true);
    return $decision+['site_id'=>$siteId,'revision'=>(int)$row['accepted_revision'],'fingerprint'=>(string)$row['fingerprint'],'state'=>$s['state']??'never_shared'];
}
function tracky_scene_project_v1g3d(array $summary,?float $now=null): array {
    $out=['protocol'=>TRACKY_SCENE_V1G3D,'state'=>$summary['state']??'unavailable','confidence'=>'uncalibrated','capture_authority'=>false,'identity_recognition'=>false,'source'=>'owner_supervised_local_vision','sources_separate'=>true];
    if($out['state']!=='available')return $out+['reason'=>$summary['reason']??'scene_unavailable'];
    try{$age=($now??microtime(true))-(float)(new DateTimeImmutable($summary['observed_at']))->format('U.u');}catch(Throwable){return array_replace($out,['state'=>'unavailable','reason'=>'timestamp_invalid']);}
    if(!is_finite($age)||$age<0||$age>60)return array_replace($out,['state'=>'unavailable','reason'=>$age<0?'timestamp_invalid':'observation_expired']+(is_finite($age)&&$age>60?['last_observed_at'=>$summary['observed_at']]:[]));
    $reports=[];foreach($summary['owner_corrections'] as $report)$reports[]=$report+['source'=>'owner_report','conflicts_with_camera'=>$report['present']!==in_array($report['object'],$summary['objects'],true)];
    return $out+['observed_at'=>$summary['observed_at'],'age_seconds'=>round($age,1),'freshness_limit_seconds'=>60,'room_id'=>$summary['room_id'],'objects'=>$summary['objects'],'setting'=>$summary['setting'],'lighting'=>$summary['lighting'],'owner_corrections'=>$reports];
}
function tracky_scene_read_v1g3d(PDO $pdo,int $userId,string $siteId): array {
    if($userId<1||$siteId==='')return [];
    $q=$pdo->prepare('SELECT accepted_revision,fingerprint,summary_json FROM tracky_cloud_scene_share_order WHERE user_id=? AND site_id=?');$q->execute([$userId,$siteId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||!(int)$row['accepted_revision'])return [];
    try{
        $v=tracky_scene_normalize_v1g3d(['revision'=>(int)$row['accepted_revision'],'fingerprint'=>$row['fingerprint'],'summary'=>json_decode((string)$row['summary_json'],true,32,JSON_THROW_ON_ERROR)]);
        return tracky_scene_project_v1g3d($v['summary'])+['site_id'=>$siteId];
    }catch(Throwable){return ['state'=>'unavailable','reason'=>'evidence_invalid'];}
}
function tracky_scene_reserved_v1g3d(array $row): bool {
    return str_starts_with((string)($row['source_event_id']??''),'agent-eyes:')||str_starts_with((string)($row['subject_id']??''),'agent-eyes-object:')||str_starts_with((string)($row['subject_id']??''),'agent-eyes-owner:');
}
function tracky_scene_rows_v1g3d(array $s): array {
    if(($s['state']??'')!=='available')return [];
    $base=['site_id'=>$s['site_id'],'object_id'=>'room:'.$s['room_id'],'confidence'=>0.0,'temporal_state'=>'inferred','source_event_id'=>'agent-eyes:'.hash('sha256',$s['observed_at']),'sequence_no'=>0,'as_of'=>$s['observed_at']];$rows=[];
    foreach($s['objects'] as $label)$rows[]=$base+['subject_id'=>'agent-eyes-object:'.$label,'predicate'=>'possibly_visible_in','value'=>['label'=>$label,'state'=>'possible']];
    foreach($s['owner_corrections'] as $r)$rows[]=$base+['subject_id'=>'agent-eyes-owner:'.$r['object'],'predicate'=>$r['present']?'owner_reports_present':'owner_reports_absent','value'=>['label'=>$r['object'],'state'=>'owner_report']];
    return $rows;
}
function tracky_scene_text_v1g3d(array $scene): string {
    if(($scene['state']??'')!=='available')return 'No current permitted Agent Eyes scene is available ('.str_replace('_',' ',(string)($scene['reason']??'not shared')).').';
    $text='Owner-supervised local vision suggested possible objects: '.(implode(', ',$scene['objects'])?:'none reported').' in room '.$scene['room_id'].' · '.$scene['setting'].' / '.$scene['lighting'].' · observed '.ceil($scene['age_seconds']).'s ago · confidence uncalibrated. These are object classes, not verified inventory or identities.';
    foreach($scene['owner_corrections'] as $r)$text.=' Owner reports '.$r['object'].' '.($r['present']?'present':'absent').($r['conflicts_with_camera']?' (differs from camera)':'').'.';
    return $text;
}
