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
    if($op==='apps.control.invoke'){
        $action=$payload['action'];$result=$action==='music.status'?['tracks'=>4,'artists'=>2,'albums'=>1,'mapped_sources'=>1]:['tracks'=>$mode==='empty'?[]:[['media_id'=>'opaque-track','title'=>'Fixture local song','artist'=>'Fixture artist','album'=>'Fixture album']]];
        if($mode==='malformed')$result=[];
        if($action==='music.status'&&in_array($mode,['negative','zero'],true))$result['tracks']=$mode==='negative'?-1:0;
        return ['ok'=>true,'contract'=>'vp3.app.agent-action-result.v1','app_key'=>$mode==='identity'?'foreign-app':'vp3.music-server','action'=>$mode==='action'?'music.delete':$action,'result'=>$result];
    }
    throw new RuntimeException('Unexpected fixture operation: '.$op);
}
