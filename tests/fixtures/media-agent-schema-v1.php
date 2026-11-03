<?php
/** Fixture storage and relay only; canonical TV, authorization and app services stay real. */
function media_agent_fixture_v1(PDO $pdo): void
{
    tv_schema_v1($pdo);
    $q=$pdo->prepare('INSERT OR IGNORE INTO tv_watching_state_v1 VALUES(?,?)');$q->execute([1,json_encode(['enabled'=>false,'share_screen'=>false,'epoch'=>0,'timezone'=>'America/Phoenix'])]);
    $base=['title'=>'Fixture Cheers','candidate_title'=>'','episode'=>'Pilot','status'=>'confirmed','screen_id'=>'fixture-screen','run_id'=>'fixture-run','first_seen'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'last_seen'=>gmdate('Y-m-d\TH:i:s\Z'),'personal_viewing_confirmed'=>false,'deleted'=>false,'visible_text'=>'Untrusted screen clue','sources'=>[]];
    if(!tv_row_v1($pdo,1,'session:fixture'))tv_put_v1($pdo,1,'session:fixture','session',$base);
    if(!tv_row_v1($pdo,2,'session:foreign'))tv_put_v1($pdo,2,'session:foreign','session',[...$base,'title'=>'SECRET foreign program']);
    if(!tv_row_v1($pdo,1,'preference:fixture'))tv_put_v1($pdo,1,'preference:fixture','preference',['title'=>'Fixture sitcoms','preference'=>'I like sitcoms','source'=>'owner_explicit','deleted'=>false]);
}
function media_fixture_remote_v1(int $uid,string $op,array $payload=[]): array
{
    $GLOBALS['media_calls'][]=[$uid,$op,$payload];$mode=$GLOBALS['media_mode']??($_SERVER['HTTP_X_FIXTURE_MEDIA_MODE']??'success');
    if($mode==='offline')throw new RuntimeException('SECRET relay timeout');
    if($mode==='denied')throw new RuntimeException('SECRET scope denied',403);
    if($mode==='revoke')db()->exec('UPDATE users SET is_active=0 WHERE id=1');
    if($mode==='swap')db()->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=1');
    if($op==='apps.media.status')return $mode==='malformed'?['ok'=>true]:['ok'=>true,'app_key'=>'vp3.media-server','library_count'=>$mode==='negative'?-1:($mode==='zero'?0:3),'types'=>['video'=>1,'audio'=>1,'image'=>1]];
    if($op==='apps.media.search')return ['ok'=>true,'items'=>$mode==='malformed'?null:($mode==='empty'?[]:[['media_id'=>'opaque-1','title'=>'Fixture local movie','media_type'=>'video']])];
    if(($op==='apps.control.invoke'&&($payload['app_key']??'')!=='vp3.music-server')||str_starts_with($op,'apps.video.'))return photo_media_fixture_response_v1($op,$payload,$mode);
    if($op==='apps.control.invoke'){
        $action=$payload['action'];$result=$action==='music.status'?['tracks'=>4,'artists'=>2,'albums'=>1,'mapped_sources'=>1]:['tracks'=>$mode==='empty'?[]:[['media_id'=>'opaque-track','title'=>'Fixture local song','artist'=>'Fixture artist','album'=>'Fixture album']]];
        if($mode==='malformed')$result=[];
        if($action==='music.status'&&in_array($mode,['negative','zero'],true))$result['tracks']=$mode==='negative'?-1:0;
        return ['ok'=>true,'contract'=>'vp3.app.agent-action-result.v1','app_key'=>$mode==='identity'?'foreign-app':'vp3.music-server','action'=>$mode==='action'?'music.delete':$action,'result'=>$result];
    }
    throw new RuntimeException('Unexpected fixture operation: '.$op);
}

function photo_media_fixture_response_v1(string $op,array $input,string $mode): array
{
    $action=$input['action']??$op;$app=$input['app_key']??'vp3.video-editor';
    $status=match($action){
        'photos.status'=>['photos'=>4,'albums'=>2,'favorites'=>1,'tags'=>2,'people'=>1,'mapped_sources'=>1,'face_recognition_enabled'=>false],
        'downloads.status'=>['active'=>1,'queued'=>2,'paused'=>0,'failed'=>0,'completed'=>3,'bytes_downloaded'=>100,'destinations'=>1],
        'processor.status'=>['active'=>1,'queued'=>1,'failed'=>0,'completed'=>2,'derivatives'=>2,'ffmpeg_available'=>true,'ffmpeg_managed_by_homeserver'=>true,'ffmpeg_version'=>'7'],
        'apps.video.status'=>['projects'=>2,'clips'=>4,'renders_active'=>1,'media_library_count'=>3,'media_server_available'=>true],default=>[]};
    if($status){
        if($mode==='zero')foreach($status as &$value)if(is_int($value))$value=0;unset($value);
        if($mode==='negative')$status[array_key_first($status)]=-1;
        if($mode==='malformed')$status=[];
        $data=$status;
    }else{
        [$key,$row]=match($action){
            'photos.search'=>['photos',['media_id'=>'photo-1','title'=>'Fixture sunset','folder_album'=>'Arizona','favorite'=>true,'tags'=>['sunset']]],
            'downloads.list'=>['downloads',['download_id'=>'download-1','final_filename'=>'Fixture download','display_url'=>'SECRET url','status'=>'queued','progress'=>0,'eta_seconds'=>null,'error'=>'SECRET error']],
            'processor.jobs'=>['jobs',['job_id'=>'job-1','media_id'=>'photo-1','operation'=>'Fixture resize','preset'=>'web','status'=>'running','progress'=>25,'output_ready'=>false,'error'=>'SECRET error']],
            'apps.video.projects'=>['projects',['project_id'=>'project-1','name'=>'Fixture project','width'=>1920,'height'=>1080,'fps'=>30,'updated_at'=>'2026-11-03T00:00:00Z']],default=>throw new RuntimeException('Unknown photo fixture action')};
        if($mode==='malformed')unset($row[array_key_first($row)]);
        if($mode==='bad_progress')$row['progress']=-1;
        $data=[$key=>$mode==='empty'?[]:[$row]];
    }
    $data+=['file_path'=>'SECRET path','settings'=>['key'=>'SECRET key']];
    return $op==='apps.control.invoke'?['ok'=>true,'contract'=>'vp3.app.agent-action-result.v1','app_key'=>$mode==='identity'?'wrong':$app,'action'=>$mode==='action'?'delete':$action,'result'=>$data]:['ok'=>true,'app_key'=>$mode==='identity'?'wrong':$app]+$data;
}
