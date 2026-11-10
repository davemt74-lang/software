<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-status-v1.php';

function get_track_by_id(int $id): ?array
{
    $q=db()->prepare('SELECT * FROM tracks WHERE id=?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function can_view_track(array $track,?array $user=null): bool
{
    return $track['visibility']==='public'||$track['listener_id']===($user['id']??0);
}

$pdo->exec("CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
    ALTER TABLE users ADD COLUMN display_name TEXT;
    INSERT INTO users (id,is_active,display_name) VALUES (3,1,'Dawn Ensemble'),(4,1,'Hidden Artist'),(5,1,'Echo Group');
    CREATE TABLE user_profiles(user_id INTEGER PRIMARY KEY,username TEXT,is_public INTEGER);
    INSERT INTO user_profiles VALUES (3,'dawn-ensemble',1),(4,'hidden',0),(5,'echo-group',1);
    CREATE TABLE artist_workspaces_v181(id INTEGER PRIMARY KEY,artist_user_id INTEGER);
    INSERT INTO artist_workspaces_v181 VALUES(30,3),(40,4),(50,5);
    CREATE TABLE tracks(id INTEGER PRIMARY KEY,workspace_id INTEGER,owner_user_id INTEGER,title TEXT,visibility TEXT,listener_id INTEGER);
    INSERT INTO tracks VALUES (31,30,3,'Private demo metadata','restricted',1),(32,30,3,'Public recording','public',NULL),
       (41,40,4,'Private artist identity','public',NULL),(51,50,5,'Self-fed artist','public',NULL),
       (61,30,3,'No verified catalog link','public',NULL);
    CREATE TABLE artist_catalog_tracks_v181(id INTEGER PRIMARY KEY,workspace_id INTEGER,source_track_id INTEGER);
    INSERT INTO artist_catalog_tracks_v181 VALUES (31,30,31),(32,30,32),(41,40,41),(51,50,51);
    CREATE TABLE track_play_sessions(id INTEGER PRIMARY KEY,track_id INTEGER,user_id INTEGER,
       started_at TEXT,last_event_at TEXT,listened_seconds REAL,source_context TEXT,qualified_play INTEGER);");
$now=time();
$play=$pdo->prepare('INSERT INTO track_play_sessions(track_id,user_id,started_at,last_event_at,listened_seconds,source_context,qualified_play)
    VALUES(?,?,?,?,?,?,?)');
$record=static function(int $track,int $listener,int $age,int $seconds,string $source='player',int $qualified=1)use($play,$now):void{
    $play->execute([$track,$listener,gmdate('Y-m-d H:i:s',$now-$age),gmdate('Y-m-d H:i:s',$now-$age+$seconds),$seconds,$source,$qualified]);
};
$user=['id'=>1,'is_active'=>1];
scope_command($user,17,170,'off');scope_command($user,17,170,'on');
$pdo->exec("DELETE FROM user_agent_data_rules WHERE agent_id=17;
    UPDATE agent_activity_state SET context_key='chat:170',details_json='{\"visible\":true,\"conversation_id\":170}',last_heartbeat_at=NOW() WHERE user_id=1;
    UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170");
$native='native-artist-interest:'.hash('sha256','dawn ensemble');
$instant=new DateTimeImmutable('@'.$now);
$read=static function(?array $selected=null,?array $owner=null,?int $agent=null)use($pdo,$user,$instant):array{
    $owner??=$user;$agent??=17;$coverage=[];
    $scope=['user'=>$owner,'agent_id'=>$agent,'principal'=>scope_principal($owner,$agent)];
    return vp3_initiative_research_evidence_v1($pdo,$scope,$instant,$selected,$coverage);
};

$record(31,1,3200,65);$record(31,1,3150,60);
$record(32,2,1600,65);$record(32,1,2300,0);
$record(41,1,3100,65);$record(41,1,1300,65);
$record(51,1,3000,65,'agent_chat');$record(51,1,1400,65,'agent_chat');
$record(61,1,2900,65);$record(61,1,1200,65);
scope_check($read()===[],'native music research defaults off');
vp3_native_music_research_save_v1($pdo,1,true);
$grant=vp3_native_music_research_grant_v1($pdo,1);
vp3_native_music_research_save_v1($pdo,1,true);
scope_check(vp3_native_music_research_grant_v1($pdo,1)===$grant&&$read()===[],
    'save is idempotent and overlapping plays, foreign activity, private artist names, unmapped tracks and Chat feedback do not qualify');
foreach([3000,2700,2400,2100] as $age)$record(51,1,$age,300);
scope_check($read()===[],'a continuous album is one listening sitting even after 15 minutes of playback');
$record(32,1,1500,67,'agent_player');
$evidence=$read();
scope_check(array_keys($evidence)===[$native]&&$evidence[$native]['title']==='Dawn Ensemble'
    &&$evidence[$native]['interest']['basis']==='native_music_listening'
    &&str_contains($evidence[$native]['detail'],'2 separate listening sessions')&&!str_contains(json_encode($evidence),'Private demo metadata'),
    'two separate authorized listening sittings expose the exact public artist with private metadata excluded');
$revision=$evidence[$native]['revision'];
$record(32,1,120,70);
scope_check($read([$native])[$native]['revision']===$revision,'subsequent plays do not replace the qualifying source revision');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(17,'knowledge','playback-history','deny')");
scope_check($read([$native])===[],'the current named Agent playback-history rule can revoke the source');
$pdo->exec("DELETE FROM user_agent_data_rules WHERE agent_id=17");
scope_check($read([$native],['id'=>2,'is_active'=>1],19)===[],'the listener owner is isolated');

$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use($native):string{
    $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
    scope_check($protocol==='vp3.initiative-plan.v1'&&in_array($native,$input['tools']['artist.research']['source_refs']??[],true)
        &&array_column($input['evidence'],'title','ref')[$native]==='Dawn Ensemble','native listening appears in the existing artist specialist catalog');
    return json_encode(['jobs'=>[['tool'=>'artist.research','primary'=>$native,'refs'=>[$native],'focus'=>'overview','report'=>true]]],JSON_THROW_ON_ERROR);
};
$planId=scope_make_plan($user,17,'New native artist research');
scope_check(vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$planId))['ok'],'the existing governed planner queues one artist research job');
$run=(int)array_slice(scope_runs(1,17,'initiative.prepare'),-1)[0]['id'];
$pdo->prepare('UPDATE agent_workflow_runs SET created_at=NOW() WHERE id=?')->execute([$run]);
$progress=vp3_initiative_research_status_v1($pdo,$user,17,170)['specialist'];
scope_check($progress['run_id']===$run&&$progress['subject']==='Dawn Ensemble'&&$progress['state']==='queued',
    'the native source appears in the shared specialist progress projection');
scope_check($read()===[]&&isset($read([$native])[$native]),'queue history suppresses duplicate discovery while the exact leased source remains verifiable');
$rivalRef='artist-interest:'.hash('sha256','dawn ensemble');
agent_connection_youtube_consent_schema_v1($pdo);
agent_connection_youtube_results_schema_v1($pdo);
agent_connection_youtube_set_consent_v1($pdo,1,true);
vp3_youtube_research_save_v1($pdo,1,'artist',true);
$turn=$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message,created_at) VALUES(171,'assistant','YouTube results',?)");
foreach([3600,2400,1200] as $age){
    $turn->execute([gmdate('Y-m-d H:i:s',$now-$age)]);
    agent_connection_youtube_results_save_v1($pdo,1,171,(int)$pdo->lastInsertId(),[
        'query'=>'Dawn Ensemble live','expires_at'=>$now+3600,
        'videos'=>[['video_id'=>'abc_def-123','title'=>'Dawn Ensemble – Live','channel_title'=>'Dawn Ensemble - Topic',
            'music_category'=>true,'made_for_kids'=>false]],
    ]);
}
$rivalSource=$read([$rivalRef])[$rivalRef];
$rival=['binding'=>vp3_initiative_policy_v1($user,17),'tool'=>'artist.research','primary'=>$rivalRef,
    'sources'=>[$rivalRef=>$rivalSource['revision']],'focus'=>'news','report'=>true];
$before=count(scope_runs(1,17,'initiative.prepare'));
$admitRival=static function()use($pdo,$user,$rival):bool{
    return vp3_initiative_in_scope_v1($user,17,static function()use($pdo,$user,$rival):bool{
        return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$user,$rival):bool{
            vp3_initiative_owner_lock_v1($pdo,$user);
            return vp3_initiative_enqueue_v1($pdo,$user,'initiative.prepare',$rival,hash('sha256','competing stale plan'));
        });
    });
};
scope_check(!isset($read()[$rivalRef])&&!$admitRival()&&count(scope_runs(1,17,'initiative.prepare'))===$before,
    'a competing YouTube artist plan is deduplicated inside the locked admission transaction');
$q=$pdo->prepare('SELECT goal FROM agent_workflow_runs WHERE id=?');$q->execute([$run]);$savedGoal=$q->fetchColumn();
$stale=json_decode($savedGoal,true,24,JSON_THROW_ON_ERROR);
$stale['canonical_sources'][$native]=str_repeat('a',64);
$updateGoal=$pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?');
$updateGoal->execute([json_encode($stale,JSON_THROW_ON_ERROR),$run]);
scope_check(isset($read()[$rivalRef]),'unfinished native work with a changed exact source cannot mute YouTube discovery');
$updateGoal->execute([$savedGoal,$run]);
vp3_native_music_research_save_v1($pdo,1,false);
scope_check(isset($read()[$rivalRef])&&$admitRival()&&count(scope_runs(1,17,'initiative.prepare'))===$before+1,
    'revoking native listening lets the independently authorized YouTube plan proceed while the old native row is still queued');
// Restore the unchanged native snapshot only for the isolated report-delivery test below.
$pdo->prepare("INSERT INTO settings VALUES('native_music_research_owner_1',?)")->execute([$grant]);
agent_connection_youtube_set_consent_v1($pdo,1,false);
$searches=[];
$work=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$run),
    static function(string $prompt,string $protocol):string{
        if($protocol!=='vp3.artist-research.v1')throw new LogicException('Unexpected specialist protocol.');
        return '{"findings":[]}';
    },[
        'search'=>static function(string $query)use(&$searches):array{
            $searches[]=$query;
            $slug=str_replace(' ','-',substr($query,strlen('"Dawn Ensemble" ')));
            return ['ok'=>true,'results'=>[['url'=>'https://research.example/'.$slug,'title'=>'Dawn Ensemble public listing']]];
        },
        'request'=>static fn():array=>['status'=>503,'content_type'=>'text/plain','body'=>'Temporarily unavailable'],
    ]);
$q=$pdo->prepare('SELECT message,context_json FROM chat_messages WHERE conversation_id=170 AND role=\'assistant\' AND context_json LIKE ?');
$q->execute(['%'.$native.'%']);
$result=$q->fetchAll();
scope_check($work['ok']&&count($result)===1&&$searches===['"Dawn Ensemble" latest music release news interview',
    '"Dawn Ensemble" upcoming concerts tour dates','"Dawn Ensemble" official merchandise vinyl store',
    '"Dawn Ensemble" official live performances music videos'],
    'the specialist checks news, shows, merch and public videos using only the exact public name and saves one report without YouTube consent');
scope_check(!vp3_initiative_deliver_pending_v1($pdo,$user,17)&&$read()===[],'the completed research does not repeat or feed itself');
vp3_native_music_research_save_v1($pdo,1,false);
scope_check($read([$native])===[]&&vp3_initiative_research_status_v1($pdo,$user,17,170)['specialist']===null,
    'revocation removes current evidence and visible work');
vp3_native_music_research_save_v1($pdo,1,true);
scope_check(vp3_native_music_research_grant_v1($pdo,1)!==$grant&&$read([$native])[$native]['revision']!==$revision,
    'a renewed opt-in cannot revive work bound to the revoked grant');
$pdo->exec("UPDATE user_profiles SET is_public=0 WHERE user_id=3");
scope_check($read([$native])===[],'unpublishing the artist profile invalidates the external research source');
echo "AGENT_INITIATIVE_NATIVE_MUSIC_V1=PASS\n";
