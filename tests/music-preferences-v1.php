<?php
declare(strict_types=1);

if(($argv[1]??'')==='--worker'){
    $musicPreferenceWorker=json_decode((string)file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    define('STONEFELLOW_ROOT',$musicPreferenceWorker['fixture_root']);
}
require_once __DIR__.'/fixtures/music-preferences-v1.php';

class MusicPreferencesTestPDO extends PDO
{
    public ?Closure $preferenceHook=null;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $statement=parent::prepare($query,$options);
        if($this->preferenceHook!==null)($this->preferenceHook)($this,$query);
        return $statement;
    }
}

function music_preferences_assert_v1(bool $condition,string $label): void
{
    if(!$condition)throw new RuntimeException('FAIL: '.$label);
    $GLOBALS['music_preferences_assertions_v1']=($GLOBALS['music_preferences_assertions_v1']??0)+1;
}
function music_preferences_error_v1(callable $call,int|array $status,string $label): void
{
    try{$call();}catch(RuntimeException $error){
        music_preferences_assert_v1(in_array((int)$error->getCode(),(array)$status,true),$label.' ('.$error->getCode().': '.$error->getMessage().')');return;
    }
    throw new RuntimeException('FAIL: '.$label.' did not reject');
}
function music_preferences_count_v1(PDO $pdo,string $table): int{return (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}

/** Independent processes share only a start barrier and a real SQL database. */
function music_preferences_parallel_v1(array $connection,array $operation,int $workers=3): array
{
    $file=tempnam(sys_get_temp_dir(),'music-preferences-race-');$gate=$file.'.go';$children=[];
    file_put_contents($file,json_encode($connection+$operation+['gate'=>$gate,'fixture_root'=>STONEFELLOW_ROOT],JSON_THROW_ON_ERROR));chmod($file,0600);
    try{
        for($i=0;$i<$workers;++$i){
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--worker',$file,(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start preference race fixture.');
            fclose($pipes[0]);$children[]=[$process,$pipes];
        }
        $until=microtime(true)+10;
        do{
            $ready=0;for($i=0;$i<$workers;++$i)if(is_file($gate.'.ready.'.$i))++$ready;
            if($ready===$workers)break;
            usleep(10000);
        }while(microtime(true)<$until);
        if($ready!==$workers)throw new RuntimeException('Preference race workers did not reach the barrier.');
        touch($gate);$results=[];
        foreach($children as [$process,$pipes]){
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0||$err!=='')throw new RuntimeException('Preference race failed: '.$err.' '.$out);
            $results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);
        }
        return $results;
    }finally{
        @unlink($file);@unlink($gate);for($i=0;$i<$workers;++$i)@unlink($gate.'.ready.'.$i);
    }
}

function music_preferences_suite_v1(PDO $pdo,array $connection): int
{
    $GLOBALS['music_preferences_assertions_v1']=0;
    music_chat_fixture_bootstrap_v1($pdo);music_chat_fixture_schema_v1($pdo);music_chat_fixture_seed_v1($pdo);
    music_history_fixture_schema_v1($pdo);$history=music_history_fixture_seed_v1($pdo,time()-60);
    $bound=music_preferences_fixture_bindings_v1($pdo,$history);$selection=$bound['selections'];$user=current_user();
    $read=static fn(array $item):array=>vp3_music_preferences_read_v1($pdo,$user,7,42,$item);
    $set=static fn(array $item,string $choice,int $revision,?string $request=null):array=>vp3_music_preferences_set_v1($pdo,$user,7,42,$item,$choice,$revision,$request??music_preferences_fixture_uuid_v1());
    $list=static fn(array $input=[]):array=>vp3_music_preferences_list_v1($pdo,$user,7,42,$input);
    $snapshot=static fn(array $candidates=[]):array=>vp3_music_preferences_snapshot_v1($pdo,$user,7,42,$candidates);

    music_preferences_assert_v1(!vp3_music_preferences_ready_v1($pdo),'uninstalled preferences are not ready');
    $before=music_preferences_fixture_digest_v1($pdo);
    music_preferences_error_v1(fn()=>$read($selection['native']),503,'missing schema requires explicit upgrade');
    music_preferences_assert_v1(music_preferences_fixture_digest_v1($pdo)===$before,'missing-schema read does not install tables or write source data');
    vp3_music_preferences_schema_v1($pdo);$installed=music_preferences_fixture_digest_v1($pdo);vp3_music_preferences_schema_v1($pdo);
    music_preferences_assert_v1(vp3_music_preferences_ready_v1($pdo)&&music_preferences_fixture_digest_v1($pdo)===$installed,'explicit additive installation is repeatable');
    $none=$read($selection['native']);
    music_preferences_assert_v1($none['preference']==='neutral'&&$none['revision']===0&&$none['source']==='vp3'&&$none['source_id']==='71','new item has no invented preference');
    $before=music_preferences_fixture_digest_v1($pdo);$read($selection['ordinary']);$list();$snapshot();
    music_preferences_assert_v1(music_preferences_fixture_digest_v1($pdo)===$before,'preference reads and Agent snapshot never mutate any table');
    music_preferences_assert_v1($list()['items']===[],'listening alone creates no explicit choices');
    $facts=$snapshot([['source'=>'vp3','source_id'=>'71'],['source'=>'vp3','source_id'=>'41'],['source'=>'vp3','source_id'=>'42'],['source'=>'youtube','source_id'=>'vp300000006']]);
    $nativeFacts=$facts['items']['vp3:71'];$youtubeFacts=$facts['items']['youtube:vp300000006'];
    music_preferences_assert_v1($nativeFacts['play_count']===2&&$nativeFacts['measured_plays']===1&&$nativeFacts['measured_seconds']===18.0,'measured native receipt and start-only repeat remain distinct facts');
    music_preferences_assert_v1($youtubeFacts['play_count']===2&&$youtubeFacts['measured_plays']===1&&$youtubeFacts['measured_seconds']===18.0,'ordinary and playlist YouTube plays contribute exact optional measured evidence');
    music_preferences_assert_v1($facts['items']['vp3:41']['play_count']===1&&$facts['items']['vp3:41']['measured_plays']===0&&$facts['items']['vp3:41']['measured_seconds']===0.0,'legacy reported seconds do not become verified measurement');
    music_preferences_assert_v1($facts['items']['vp3:42']['play_count']===0&&$facts['items']['vp3:42']['preference']==='neutral','unplayed candidate has no invented taste or failure');
    music_preferences_assert_v1(!str_contains(json_encode($facts),'PRIVATE FOREIGN TITLE'),'factual learning omits inaccessible history');

    $nonPreference=static fn():string=>music_preferences_fixture_digest_v1($pdo,['music_preferences_v1','music_preference_requests_v1','music_deliberate_skips_v1']);
    $originalSources=$nonPreference();$likeKey=music_preferences_fixture_uuid_v1();
    $liked=$set($selection['native'],'like',0,$likeKey);
    music_preferences_assert_v1($liked['state']['preference']==='like'&&$liked['state']['revision']===1&&!$liked['replayed'],'first explicit Like saved once');
    music_preferences_assert_v1($read($selection['repeat'])['preference']==='like','repeated native receipt maps to the same source preference');
    music_preferences_assert_v1($read($selection['result_native'])['preference']==='like'&&$read($selection['saved_native'])['preference']==='like','history, result and saved-list selections share the exact preference');
    $requests=music_preferences_count_v1($pdo,'music_preference_requests_v1');$again=$set($selection['native'],'like',0,$likeKey);
    music_preferences_assert_v1($again['replayed']&&$again['state']['revision']===1&&music_preferences_count_v1($pdo,'music_preference_requests_v1')===$requests,'lost response retry neither duplicates mutation nor request receipt');
    music_preferences_error_v1(fn()=>$set($selection['native'],'dislike',0,$likeKey),409,'request identity cannot be rebound to another decision');
    music_preferences_error_v1(fn()=>$set($selection['result_other'],'like',0,$likeKey),409,'request identity cannot be rebound to another source item');
    music_preferences_error_v1(fn()=>$set($selection['native'],'dislike',0),409,'stale revision rejects a new overwrite');
    $disliked=$set($selection['native'],'dislike',1);
    music_preferences_assert_v1($disliked['state']['preference']==='dislike'&&$disliked['state']['revision']===2,'explicit Dislike replaces prior Like');
    $oldRetry=$set($selection['native'],'like',0,$likeKey);
    music_preferences_assert_v1($oldRetry['replayed']&&$oldRetry['state']['preference']==='dislike'&&$oldRetry['state']['revision']===2,'old Like retry reports the current Dislike and cannot resurrect it');
    $cleared=$set($selection['native'],'clear',2);
    music_preferences_assert_v1($cleared['state']['preference']==='neutral'&&$cleared['state']['revision']===3,'Clear leaves a neutral revision tombstone');
    $oldRetry=$set($selection['native'],'like',0,$likeKey);
    music_preferences_assert_v1($oldRetry['replayed']&&$oldRetry['state']['preference']==='neutral'&&$oldRetry['state']['revision']===3,'old successful mutation remains quiet after Clear');
    music_preferences_error_v1(fn()=>$set($selection['native'],'like',0),409,'deletion cannot reset concurrency version to zero');
    music_preferences_assert_v1($list()['items']===[],'cleared choices do not appear as current likes or dislikes');
    music_preferences_assert_v1($nonPreference()===$originalSources,'explicit choices never start audio, write listening, alter saved playlists or spend provider quota');

    foreach(['neutral','skip','failed','declined','love','LIKE',''] as $invalid)music_preferences_error_v1(fn()=>$set($selection['native'],$invalid,3),422,'unrecognized explicit preference '.$invalid);
    foreach(['',str_repeat('x',200),'invalid request key'] as $invalid)music_preferences_error_v1(fn()=>$set($selection['native'],'like',3,$invalid),422,'strict bounded stable request identity');
    foreach([
        [],['kind'=>'raw','source'=>'vp3','source_id'=>'71'],['kind'=>'result','result_id'=>$bound['result']['result_id'],'revision'=>$bound['result']['revision'],'index'=>-1],
        ['kind'=>'result','result_id'=>$bound['result']['result_id'],'revision'=>$bound['result']['revision'],'index'=>99],
        $selection['native']+['title'=>'Untrusted title'],
        array_replace($selection['native'],['revision'=>str_repeat('0',64)]),
        array_replace($selection['native'],['entry_ref'=>vp3_music_history_reference_v1(22,'n',(string)$history['foreign'])]),
    ] as $invalid)music_preferences_error_v1(fn()=>$read($invalid),[404,409,422],'invalid, stale or foreign exact selection is rejected');
    foreach([['limit'=>0],['limit'=>51],['limit'=>'2'],['source'=>'stoneward'],['owner_user_id'=>22],['cursor'=>[]]] as $invalid){
        music_preferences_error_v1(fn()=>$list($invalid),422,'invalid list input '.json_encode($invalid));
    }

    $native=$set($selection['native'],'like',3)['state'];
    $youtube=$set($selection['ordinary'],'dislike',0)['state'];
    music_preferences_assert_v1($youtube['source']==='youtube'&&$youtube['source_id']==='vp300000006'&&$youtube['preference']==='dislike','YouTube identity and explicit opinion remain source tagged');
    music_preferences_assert_v1($read($selection['playlist'])['preference']==='dislike'&&$read($selection['result_youtube'])['preference']==='dislike','ordinary, saved-play receipt and current result share one exact YouTube preference');
    music_preferences_assert_v1(count($list()['items'])===2&&count($list(['source'=>'vp3'])['items'])===1&&count($list(['source'=>'youtube'])['items'])===1,'list has exact current source-filtered choices');
    $page=$list(['limit'=>1]);music_preferences_assert_v1(count($page['items'])===1&&$page['has_more']&&is_string($page['next_cursor']),'current choices page with an opaque cursor');
    $next=$list(['limit'=>1,'cursor'=>$page['next_cursor']]);
    music_preferences_assert_v1(count($next['items'])===1&&!$next['has_more']&&$page['items'][0]['reference']!==$next['items'][0]['reference'],'continuation neither repeats nor skips choices');
    music_preferences_error_v1(fn()=>$list(['limit'=>1,'source'=>'youtube','cursor'=>$page['next_cursor']]),409,'cursor cannot move between source filters');
    $wire=json_encode($pdo->query('SELECT * FROM music_preferences_v1')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    foreach(['History Moonlight','YouTube Song 6','Fixture Channel','vp300000006','thumbnail','audio_path','autoplay'] as $secret){
        music_preferences_assert_v1(!str_contains($wire,$secret),'durable explicit choices do not copy provider/private metadata '.$secret);
    }
    $before=music_preferences_fixture_digest_v1($pdo);$snapshot([['source'=>'vp3','source_id'=>'71'],['source'=>'youtube','source_id'=>'vp300000006']]);
    music_preferences_assert_v1(music_preferences_fixture_digest_v1($pdo)===$before,'source-aware preference snapshot remains read-only');

    if($pdo instanceof MusicPreferencesTestPDO){
        $ordered=$list()['items'];$first=$ordered[0];$revoked=false;
        $pdo->preferenceHook=static function(MusicPreferencesTestPDO $db,string $query)use($first,&$revoked):void{
            $later=$first['source']==='vp3'?str_contains($query,'FROM agent_connection_youtube_results_v1'):str_contains($query,'FROM tracks');
            if(!$later)return;$db->preferenceHook=null;$revoked=true;
            if($first['source']==='vp3')$db->exec("UPDATE tracks SET visibility='private',owner_user_id=22,title='MIDREAD PRIVATE PREFERENCE' WHERE id=71");
            else $db->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='revoked' WHERE owner_user_id=11");
        };
        $freshList=null;
        try{$freshList=$list();}
        catch(RuntimeException $error){music_preferences_assert_v1((int)$error->getCode()===409,'mid-list source change rejects the stale projection');}
        finally{
            $pdo->preferenceHook=null;
            $pdo->exec("UPDATE tracks SET visibility='public',owner_user_id=11,title='History Moonlight' WHERE id=71");
            $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET policy_version=? WHERE owner_user_id=11')->execute([VP3_YOUTUBE_CONSENT_POLICY_V1]);
        }
        if($freshList!==null)music_preferences_assert_v1(!str_contains(json_encode($freshList),$first['title']),'earlier preference metadata is rechecked after later source lookup revocation');
        music_preferences_assert_v1($revoked,'mid-list source revocation fixture actually ran');
        $lookups=0;
        $pdo->preferenceHook=static function(MusicPreferencesTestPDO $db,string $query)use(&$lookups):void{
            if(!str_contains($query,'FROM agent_connection_youtube_results_v1 r')||++$lookups!==2)return;
            $db->preferenceHook=null;
            $db->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='revoked' WHERE owner_user_id=11");
        };
        try{
            music_preferences_error_v1(fn()=>$read(['kind'=>'preference','reference'=>$youtube['reference']]),409,'single preference read rejects consent revoked during its final metadata lookup');
            music_preferences_assert_v1($lookups===2,'single-read consent fixture revokes after both source snapshots were captured');
        }finally{
            $pdo->preferenceHook=null;
            $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET policy_version=? WHERE owner_user_id=11')->execute([VP3_YOUTUBE_CONSENT_POLICY_V1]);
        }
        $pdo->preferenceHook=static function(MusicPreferencesTestPDO $db,string $query):void{
            if(!str_contains($query,"preference IN ('like','dislike')"))return;
            $db->preferenceHook=null;$db->exec('UPDATE users SET chat_allowed=0 WHERE id=11');
        };
        music_preferences_error_v1(fn()=>$list(),403,'mid-list account revocation cannot return private choices');
        $pdo->preferenceHook=null;$pdo->exec('UPDATE users SET chat_allowed=1 WHERE id=11');
    }

    foreach([['is_active',0],['account_allowed',0],['chat_allowed',0]] as [$column,$value]){
        $pdo->exec('UPDATE users SET '.$column.'='.$value.' WHERE id=11');
        music_preferences_error_v1(fn()=>$read($selection['native']),403,'live account '.$column);
        music_preferences_error_v1(fn()=>$set($selection['native'],'clear',$native['revision']),403,'write rechecks live account '.$column);
        $pdo->exec('UPDATE users SET '.$column.'=1 WHERE id=11');
    }
    $pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');music_preferences_error_v1(fn()=>$list(),403,'inactive Agent cannot read private preferences');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
    foreach([[8,42],[7,52],[7,0]] as [$agent,$cid])music_preferences_error_v1(fn()=>vp3_music_preferences_read_v1($pdo,$user,$agent,$cid,$selection['native']),403,'exact owner/Agent/private Chat boundary');
    $GLOBALS['music_chat_fixture_user_id']=22;music_preferences_error_v1(fn()=>$list(),403,'session owner mismatch');$GLOBALS['music_chat_fixture_user_id']=11;
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>1];music_preferences_error_v1(fn()=>$list(),403,'participant scopes cannot read private taste');unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    $pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','playback-history','deny')");
    music_preferences_error_v1(fn()=>$read($selection['native']),403,'current history policy also fences private preferences');
    music_preferences_error_v1(fn()=>$set($selection['native'],'clear',$native['revision']),403,'opaque preference reference grants no policy bypass');
    $pdo->exec('DELETE FROM user_agent_data_rules WHERE agent_id=7');

    // Revocation must hide current display data while still allowing the owner to forget a choice.
    $savedNative=$list(['source'=>'vp3'])['items'][0];
    $pdo->exec("UPDATE tracks SET visibility='private',owner_user_id=22,title='REVOKED PREFERENCE SECRET' WHERE id=71");
    music_preferences_error_v1(fn()=>$set($selection['native'],'like',$native['revision']),[404,409],'revoked source cannot be newly liked');
    $redacted=$list(['source'=>'vp3']);
    music_preferences_assert_v1(!str_contains(json_encode($redacted),'REVOKED PREFERENCE SECRET')&&!str_contains(json_encode($redacted),'History Moonlight'),'revoked choices never expose old or current private titles');
    $forgot=$set(['kind'=>'preference','reference'=>$savedNative['reference']],'clear',$savedNative['revision']);
    music_preferences_assert_v1($forgot['state']['preference']==='neutral','owner can clear a saved choice after source revocation');
    $pdo->exec("UPDATE tracks SET visibility='public',owner_user_id=11,title='History Moonlight' WHERE id=71");
    $savedYoutube=$list(['source'=>'youtube'])['items'][0];
    $accepted=(int)$pdo->query('SELECT accepted_at FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=11')->fetchColumn();
    $pdo->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='revoked' WHERE owner_user_id=11");
    $ytHidden=$list(['source'=>'youtube']);
    music_preferences_assert_v1(!str_contains(json_encode($ytHidden),'YouTube Song 6')&&!str_contains(json_encode($snapshot()),'vp300000006'),'source optout hides metadata and Agent identity evidence');
    music_preferences_error_v1(fn()=>$set($selection['ordinary'],'like',$youtube['revision']),[403,404,409],'source optout prevents new positive preference');
    $forgot=$set(['kind'=>'preference','reference'=>$savedYoutube['reference']],'clear',$savedYoutube['revision']);
    music_preferences_assert_v1($forgot['state']['preference']==='neutral','owner can clear the opted-out provider choice');
    $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET policy_version=?,accepted_at=? WHERE owner_user_id=11')->execute([VP3_YOUTUBE_CONSENT_POLICY_V1,$accepted]);

    $currentYoutube=$read($selection['ordinary']);$set($selection['ordinary'],'like',$currentYoutube['revision']);
    $currentYoutube=$list(['source'=>'youtube'])['items'][0];
    $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11')->execute([time()-1]);
    $renewed=$snapshot([['source'=>'youtube','source_id'=>'vp300000006']]);
    music_preferences_assert_v1(!isset($renewed['items']['youtube:vp300000006'])||$renewed['items']['youtube:vp300000006']['preference']==='neutral','a later consent period never revives the older explicit provider choice');
    $set(['kind'=>'preference','reference'=>$currentYoutube['reference']],'clear',$currentYoutube['revision']);
    $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11')->execute([$accepted]);

    // Bounded learning advertises truncation and never silently implies complete lifetime coverage.
    $floor=(int)$pdo->query('SELECT MAX(id) FROM track_play_sessions')->fetchColumn();
    $older=music_history_fixture_native_v1($pdo,42,time()-31*86400,11,20,['accepted_played_ms'=>20000,'position_ms'=>20000]);
    music_preferences_assert_v1($snapshot([['source'=>'vp3','source_id'=>'42']])['items']['vp3:42']['play_count']===0,'the factual window excludes older retained listening without deleting it');
    for($i=0;$i<205;++$i)music_history_fixture_native_v1($pdo,41,time()-200-$i,11,0);
    $beforeBounded=music_preferences_fixture_digest_v1($pdo);$bounded=$snapshot();
    music_preferences_assert_v1($bounded['coverage']['history_truncated']&&$bounded['coverage']['history_candidates_scanned']===200,'overflow is explicitly limited to 200 recent receipts');
    music_preferences_assert_v1(array_sum(array_column($bounded['items'],'play_count'))<=200,'bounded context does not claim evidence beyond the examined receipts');
    music_preferences_assert_v1(music_preferences_fixture_digest_v1($pdo)===$beforeBounded,'windowing and truncation never purge retained source rows');
    $pdo->exec('DELETE FROM music_listening_sessions_v1 WHERE session_id>'.$floor);$pdo->exec('DELETE FROM track_play_sessions WHERE id>'.$floor);

    // Mutations and their request receipts must commit or roll back together.
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $before=music_preferences_fixture_digest_v1($pdo);
    $pdo->exec($mysql?"CREATE TRIGGER preference_fail_request BEFORE INSERT ON music_preference_requests_v1 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'"
        :"CREATE TRIGGER preference_fail_request BEFORE INSERT ON music_preference_requests_v1 BEGIN SELECT RAISE(ABORT,'fixture rollback'); END");
    try{$set($selection['result_other'],'like',0);throw new LogicException('Preference transaction unexpectedly succeeded.');}
    catch(PDOException){music_preferences_assert_v1(music_preferences_fixture_digest_v1($pdo)===$before,'request receipt failure rolls back the corresponding choice');}
    finally{$pdo->exec('DROP TRIGGER preference_fail_request');}

    // Actual per-owner races: exact retry succeeds once; competing decisions cannot both win CAS.
    $request=music_preferences_fixture_uuid_v1();$raced=music_preferences_parallel_v1($connection,[
        'selection'=>$selection['result_other'],'preference'=>'like','expected_revision'=>0,'request_id'=>$request,
    ]);
    music_preferences_assert_v1(count(array_filter($raced,static fn(array $r):bool=>!empty($r['ok'])))===3,'simultaneous exact requests all receive one current result');
    music_preferences_assert_v1($read($selection['result_other'])['revision']===1,'simultaneous exact requests increment once');
    $competing=music_preferences_parallel_v1($connection,[
        'selection'=>$selection['result_other'],'preference'=>'dislike','expected_revision'=>1,'request_id'=>music_preferences_fixture_uuid_v1(),'different_requests'=>true,
    ],2);
    music_preferences_assert_v1(count(array_filter($competing,static fn(array $r):bool=>!empty($r['ok'])))===1
        &&count(array_filter($competing,static fn(array $r):bool=>($r['status']??0)===409))===1,'only one stale-version competitor wins');
    music_preferences_assert_v1($read($selection['result_other'])['revision']===2,'losing CAS does not add another revision');

    // A deliberate skip is a separate weak observation, never a negative preference.
    $skip=static fn(array $item,array $receipt,?string $key=null):array=>vp3_music_preferences_skip_v1($pdo,$user,7,42,$item,$receipt,$key??music_preferences_fixture_uuid_v1());
    $nativePlay=(string)$history['native'];
    $skipKey=music_preferences_fixture_uuid_v1();$beforeChoice=$read($selection['native']);
    $skipped=$skip($selection['native'],['kind'=>'n','id'=>$nativePlay],$skipKey);
    music_preferences_assert_v1($skipped['recorded']&&!$skipped['replayed'],'fresh accepted native play can record a deliberate skip');
    $duplicate=$skip($selection['native'],['kind'=>'n','id'=>$nativePlay],$skipKey);
    music_preferences_assert_v1($duplicate['replayed']&&music_preferences_count_v1($pdo,'music_deliberate_skips_v1')===1,'skip retry is idempotent');
    $skip($selection['native'],['kind'=>'n','id'=>$nativePlay]);
    music_preferences_assert_v1(music_preferences_count_v1($pdo,'music_deliberate_skips_v1')===1,'a new request identity cannot count the same play twice');
    music_preferences_assert_v1($read($selection['native'])===$beforeChoice,'deliberate skip never creates or changes explicit dislike');
    $captureKey=vp3_youtube_capture_key_v1($pdo,11);
    $pdo->exec("UPDATE vp3_youtube_history_settings_v1 SET capture_key='' WHERE owner_user_id=11");
    $paused=music_preferences_fixture_digest_v1($pdo);
    music_preferences_error_v1(fn()=>$skip($selection['ordinary'],['kind'=>'y','id'=>$history['ordinary']]),403,'paused YouTube capture rejects a new ordinary Next observation');
    music_preferences_error_v1(fn()=>$skip($selection['playlist'],['kind'=>'p','id'=>$history['playlist']]),403,'paused YouTube capture rejects a new saved-list Next observation');
    music_preferences_assert_v1(music_preferences_fixture_digest_v1($pdo)===$paused,'paused capture does not persist skipped observations or idempotency receipts');
    $pdo->prepare('UPDATE vp3_youtube_history_settings_v1 SET capture_key=? WHERE owner_user_id=11')->execute([$captureKey]);
    $ordinarySkip=$skip($selection['ordinary'],['kind'=>'y','id'=>$history['ordinary']]);
    $playlistSkip=$skip($selection['playlist'],['kind'=>'p','id'=>$history['playlist']]);
    music_preferences_assert_v1($ordinarySkip['recorded']&&$playlistSkip['recorded']&&music_preferences_count_v1($pdo,'music_deliberate_skips_v1')===3,'ordinary and child YouTube play receipts are separate deliberate skips');
    music_preferences_assert_v1($read($selection['ordinary'])['preference']==='neutral','YouTube Next does not manufacture an explicit dislike');
    $skipFacts=$snapshot();
    music_preferences_assert_v1($skipFacts['items']['vp3:71']['deliberate_skips']===1&&$skipFacts['items']['youtube:vp300000006']['deliberate_skips']===2,'distinct source receipts yield exact separate Next counts');
    music_preferences_assert_v1(str_contains($skipFacts['items']['youtube:vp300000006']['reason'],'not a dislike'),'Agent reason distinguishes weak skip context from explicit taste');
    $nativeReceipt=$pdo->query('SELECT last_received_ms,natural_ended FROM music_listening_sessions_v1 WHERE session_id='.$history['native'])->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET last_received_ms=? WHERE session_id=?')->execute([1000*(time()-91),$history['native']]);
    music_preferences_error_v1(fn()=>$skip($selection['native'],['kind'=>'n','id'=>$nativePlay]),409,'old native receipt cannot prove a current deliberate Next');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET last_received_ms=?,natural_ended=1 WHERE session_id=?')->execute([$nativeReceipt['last_received_ms'],$history['native']]);
    music_preferences_error_v1(fn()=>$skip($selection['native'],['kind'=>'n','id'=>$nativePlay]),409,'natural completion cannot be relabeled as a deliberate skip');
    $pdo->prepare('UPDATE music_listening_sessions_v1 SET natural_ended=? WHERE session_id=?')->execute([$nativeReceipt['natural_ended'],$history['native']]);
    $pdo->prepare('UPDATE vp3_youtube_playback_v1 SET played_at=? WHERE play_id=?')->execute([time()-21601,$history['ordinary']]);
    music_preferences_error_v1(fn()=>$skip($selection['ordinary'],['kind'=>'y','id'=>$history['ordinary']]),409,'old YouTube receipt cannot prove a current deliberate Next');
    $pdo->prepare('UPDATE vp3_youtube_playback_v1 SET played_at=? WHERE play_id=?')->execute([$history['clock']-20,$history['ordinary']]);
    $pdo->prepare('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id=?')->execute([time()-1,$history['message']]);
    music_preferences_error_v1(fn()=>$skip($selection['ordinary'],['kind'=>'y','id'=>$history['ordinary']]),409,'expired exact source cannot add new skip evidence through another cached copy');
    $pdo->prepare('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id=?')->execute([time()+600,$history['message']]);
    music_preferences_assert_v1(music_preferences_count_v1($pdo,'music_deliberate_skips_v1')===3,'failed current-receipt checks leave all prior skips untouched');
    foreach([
        ['kind'=>'n','id'=>'999999'],['kind'=>'failed','id'=>$nativePlay],['kind'=>'n','id'=>''],
    ] as $invalid)music_preferences_error_v1(fn()=>$skip($selection['native'],$invalid),[404,409,422],'unplayed, failed and malformed skip receipt rejected');
    music_preferences_error_v1(fn()=>$skip($selection['result_other'],['kind'=>'n','id'=>$nativePlay]),409,'accepted play receipt cannot be reassigned to another song');
    $foreignPlay=(string)$history['foreign'];
    music_preferences_error_v1(fn()=>$skip($selection['native'],['kind'=>'n','id'=>$foreignPlay]),[404,409],'another owner play cannot become this owner evidence');
    $before=music_preferences_fixture_digest_v1($pdo);$snap=$snapshot();
    music_preferences_assert_v1(($snap['coverage']['window_days']??null)===30&&($snap['coverage']['history_limit']??null)===200,'bounded learning coverage is explicit');
    music_preferences_assert_v1(music_preferences_fixture_digest_v1($pdo)===$before,'post-feedback Agent context does not invent retained evidence');

    return (int)$GLOBALS['music_preferences_assertions_v1'];
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    if(isset($musicPreferenceWorker)){
        $config=$musicPreferenceWorker;$pdo=new PDO($config['dsn'],$config['user']??null,$config['password']??null);music_chat_fixture_bootstrap_v1($pdo);
        touch($config['gate'].'.ready.'.$argv[3]);$until=microtime(true)+10;
        while(!is_file($config['gate'])&&microtime(true)<$until)usleep(10000);
        try{
            $key=!empty($config['different_requests'])?music_preferences_fixture_uuid_v1():$config['request_id'];
            $out=['ok'=>true]+vp3_music_preferences_set_v1($pdo,current_user(),7,42,$config['selection'],$config['preference'],$config['expected_revision'],$key);
        }catch(RuntimeException $error){$out=['ok'=>false,'status'=>(int)$error->getCode(),'error'=>$error->getMessage()];}
        echo json_encode($out,JSON_THROW_ON_ERROR);exit;
    }
    $file=tempnam(sys_get_temp_dir(),'music-preferences-sqlite-');$connection=['dsn'=>'sqlite:'.$file];
    try{$count=music_preferences_suite_v1(new MusicPreferencesTestPDO($connection['dsn']),$connection);echo "Music preferences SQLite (real SQL and independent request races): {$count} checks passed.\n";}
    finally{@unlink($file);}
}
