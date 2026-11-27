<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/music-chat-library-v1.php';

function music_chat_test_assert_v1(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    $GLOBALS['music_chat_test_assertions_v1']=($GLOBALS['music_chat_test_assertions_v1']??0)+1;
}
function music_chat_test_error_v1(callable $call,int $status,string $label): void
{
    try{$call();}catch(RuntimeException $error){music_chat_test_assert_v1((int)$error->getCode()===$status,$label.' (status '.$error->getCode().')');return;}
    throw new RuntimeException('FAIL: '.$label.' did not reject');
}
function music_chat_test_saved_v1(PDO $pdo,array $items,array $meta=[],?string $key=null): array
{
    $key??=bin2hex(random_bytes(16));$user=current_user();
    $meta+=['intent'=>'playlist','source'=>'both','requested_count'=>max(1,count($items)),'create_playlist'=>true,'title'=>'Fixture list '.$key];
    $result=vp3_music_chat_result_store_v1($pdo,$user,7,42,$key,hash('sha256',$key),$items,$meta);
    $mid=music_chat_fixture_message_v1($pdo);
    $bound=vp3_music_chat_result_bind_v1($pdo,$user,7,42,$result['result_id'],$mid);
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($bound)],JSON_THROW_ON_ERROR),$mid]);
    return $bound;
}
function music_chat_test_count_v1(PDO $pdo,string $table): int{return (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}

/** Real independent connections/processes, no SQL rewriting or simulated lock result. */
function music_chat_test_parallel_v1(array $connection,array $operation,int $workers=3): array
{
    $file=tempnam(sys_get_temp_dir(),'music-chat-race-');$gate=$file.'.go';
    file_put_contents($file,json_encode($connection+$operation+['gate'=>$gate],JSON_THROW_ON_ERROR));chmod($file,0600);
    $children=[];
    try{
        for($i=0;$i<$workers;++$i){
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--worker',$file,(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start concurrency fixture.');
            fclose($pipes[0]);$children[]=[$process,$pipes];
        }
        $until=microtime(true)+10;
        do{
            $ready=0;for($i=0;$i<$workers;++$i)if(is_file($gate.'.ready.'.$i))++$ready;
            if($ready===$workers)break;
            usleep(10000);
        }while(microtime(true)<$until);
        if($ready!==$workers)throw new RuntimeException('Concurrent fixture workers did not all reach the ready barrier.');
        touch($gate);$results=[];
        foreach($children as [$process,$pipes]){
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            $code=proc_close($process);
            if($code!==0||$err!=='')throw new RuntimeException('Concurrent fixture failed: '.$err.' '.$out);
            $results[]=json_decode($out,true,12,JSON_THROW_ON_ERROR);
        }
        return $results;
    }finally{@unlink($file);@unlink($gate);for($i=0;$i<$workers;++$i)@unlink($gate.'.ready.'.$i);}
}

function music_chat_library_suite_v1(PDO $pdo,array $connection): int
{
    $GLOBALS['music_chat_test_assertions_v1']=0;
    music_chat_fixture_bootstrap_v1($pdo);music_chat_fixture_schema_v1($pdo,false);music_chat_fixture_seed_v1($pdo);
    $user=current_user();$native=['source'=>'vp3','source_id'=>'41'];$second=['source'=>'vp3','source_id'=>'42'];
    music_chat_test_assert_v1(!vp3_music_chat_ready_v1($pdo),'missing schema reported');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'missing',hash('sha256','missing'),[$native]),503,'reads cannot migrate');
    music_chat_test_assert_v1(!table_exists('music_chat_results_v1'),'failed read leaves schema absent');
    $pdo->exec('CREATE TABLE legacy_music_playlist_fixture(id INTEGER PRIMARY KEY,visibility VARCHAR(20))');
    $pdo->exec("INSERT INTO legacy_music_playlist_fixture VALUES(1,'public'),(2,'members')");
    vp3_music_chat_schema_v1($pdo);vp3_music_chat_schema_v1($pdo);
    music_chat_test_assert_v1(vp3_music_chat_ready_v1($pdo),'rerunnable additive upgrade');
    music_chat_test_assert_v1($pdo->query('SELECT visibility FROM legacy_music_playlist_fixture ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)===['public','members'],'legacy visibility unchanged');

    $one=music_chat_test_saved_v1($pdo,[$native],['intent'=>'song','source'=>'vp3','create_playlist'=>false,'autoplay'=>true],'one-song');
    music_chat_test_assert_v1(count($one['items'])===1&&$one['items'][0]['source']==='vp3','one global result');
    music_chat_test_assert_v1($one['items'][0]['artist']==='Fixture Artist'&&$one['items'][0]['title']==='Native Midnight','canonical native metadata');
    music_chat_test_assert_v1(!isset($one['playlist'])&&isset($one['autoplay']),'song does not create playlist');
    $wire=json_encode($one,JSON_THROW_ON_ERROR);
    music_chat_test_assert_v1(!str_contains($wire,'native.wav')&&!str_contains($wire,'native_revision')&&!str_contains($wire,'api-key')&&!str_contains($wire,'youtube_video'),'public metadata has no delivery secrets or raw internals');
    $before=music_chat_test_count_v1($pdo,'music_chat_results_v1');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'two',hash('sha256','two'),[$native,$second],['requested_count'=>1]),422,'global count enforced');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'wrong-source',hash('sha256','wrong'),[$native],['source'=>'youtube']),422,'explicit source enforced');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'third',hash('sha256','third'),[['source'=>'stoneward','source_id'=>'41']]),422,'only two sources');
    foreach([43,44] as $id)music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'hidden'.$id,hash('sha256',(string)$id),[['source'=>'vp3','source_id'=>(string)$id]]),409,'private foreign or unpublished song unavailable');
    music_chat_test_assert_v1(music_chat_test_count_v1($pdo,'music_chat_results_v1')===$before,'failed selections atomic');
    $replay=vp3_music_chat_result_request_v1($pdo,$user,7,42,'one-song',hash('sha256','one-song'));
    music_chat_test_assert_v1($replay['result_id']===$one['result_id']&&$replay['replayed']&&!isset($replay['autoplay']),'request receipt replay has no autoplay');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_request_v1($pdo,$user,7,42,'one-song',hash('sha256','changed')),409,'request conflict');
    $repeat=vp3_music_chat_result_store_v1($pdo,$user,7,42,'one-song',hash('sha256','one-song'),[$second],['source'=>'vp3']);
    music_chat_test_assert_v1($repeat['result_id']===$one['result_id']&&$repeat['items'][0]['source_id']==='41','retry never reranks selection');
    $repeatBind=vp3_music_chat_result_bind_v1($pdo,$user,7,42,$one['result_id'],music_chat_fixture_message_v1($pdo));
    music_chat_test_assert_v1($repeatBind['source_message_id']===$one['source_message_id']&&!isset($repeatBind['autoplay']),'bind preserves original receipt');

    $play=['result_id'=>$one['result_id'],'revision'=>$one['revision'],'index'=>0,'auto_action_id'=>$one['autoplay']['action_id']];
    $first=vp3_music_chat_resolve_v1($pdo,$user,7,42,$play);
    music_chat_test_assert_v1($first['media_playback']['track_id']===41&&isset($first['media_playback']['proof']),'real signed native action');
    music_chat_test_assert_v1(vp3_music_chat_resolve_v1($pdo,$user,7,42,$play)['already_claimed']===true,'automatic request at most once');
    unset($play['auto_action_id']);
    music_chat_test_assert_v1(isset(vp3_music_chat_resolve_v1($pdo,$user,7,42,$play)['media_playback']),'manual play remains retryable');
    music_chat_test_error_v1(fn()=>vp3_music_chat_resolve_v1($pdo,$user,7,42,array_replace($play,['revision'=>str_repeat('0',64)])),409,'result revision bound');
    $pdo->exec("UPDATE tracks SET visibility='private',owner_user_id=22,title='SECRET REVOKED TITLE' WHERE id=41");
    $hidden=vp3_music_chat_result_read_v1($pdo,$user,7,42,$one['result_id']);
    music_chat_test_assert_v1(!$hidden['items'][0]['available']&&!str_contains(json_encode($hidden),'Native Midnight')&&!str_contains(json_encode($hidden),'SECRET REVOKED'),'fresh visibility suppresses old and new metadata');
    music_chat_test_error_v1(fn()=>vp3_music_chat_resolve_v1($pdo,$user,7,42,$play),409,'revoked song cannot play');
    $pdo->exec("UPDATE tracks SET visibility='public',owner_user_id=11,title='Native Midnight' WHERE id=41");

    foreach([['is_active',0],['chat_allowed',0],['account_allowed',0]] as [$column,$value]){
        $pdo->exec('UPDATE users SET '.$column.'='.$value.' WHERE id=11');
        music_chat_test_error_v1(fn()=>vp3_music_chat_result_read_v1($pdo,$user,7,42,$one['result_id']),403,'fresh account '.$column);
        $pdo->exec('UPDATE users SET '.$column.'=1 WHERE id=11');
    }
    $pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_read_v1($pdo,$user,7,42,$one['result_id']),403,'inactive agent denied');
    $pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_read_v1($pdo,$user,7,43,$one['result_id']),404,'sibling chat result denied');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_read_v1($pdo,$user,8,42,$one['result_id']),403,'wrong agent scope denied');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_read_v1($pdo,$user,0,42,$one['result_id']),403,'system namespace cannot steal named conversation');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>1];
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_read_v1($pdo,$user,7,42,$one['result_id']),403,'participant cannot read owner library');unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    $GLOBALS['music_chat_fixture_user_id']=22;
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_read_v1($pdo,$user,7,42,$one['result_id']),403,'changed authenticated session denied');
    $GLOBALS['music_chat_fixture_user_id']=11;

    $youtube=music_chat_fixture_video_v1();
    $mixed=music_chat_test_saved_v1($pdo,[$native,$youtube,$second],['title'=>'Mixed private','autoplay'=>true],'mixed');$playlist=$mixed['playlist'];
    music_chat_test_assert_v1($playlist['visibility']==='private'&&$playlist['item_count']===3,'mixed durable private playlist created');
    music_chat_test_assert_v1(music_chat_test_count_v1($pdo,'music_chat_playlists_v1')===1,'one playlist per request');
    $child=$pdo->query('SELECT playlist_id FROM vp3_youtube_saved_playlists_v1')->fetchColumn();
    music_chat_test_assert_v1(is_string($child)&&strlen($child)===32,'existing YouTube private receipt adapter created');
    $savedRefs=$pdo->query('SELECT items_json FROM music_chat_playlists_v1')->fetchColumn();
    music_chat_test_assert_v1(!str_contains($savedRefs,'YouTube Song')&&!str_contains($savedRefs,$youtube['source_id'])&&!str_contains($savedRefs,'Native Midnight'),'durable list stores references without provider metadata');
    $yt=vp3_music_chat_resolve_v1($pdo,$user,7,42,['playlist_id'=>$playlist['id'],'revision'=>1,'index'=>1]);
    music_chat_test_assert_v1(count($yt['youtube_results']['videos'])===1&&$yt['youtube_playlist_id']===$child&&$yt['source_message_id']===$mixed['source_message_id'],'YouTube resolve retains real capture provenance');
    music_chat_test_assert_v1(music_chat_test_count_v1($pdo,'vp3_youtube_playlist_plays_v1')===0,'resolving does not count as listening');
    music_chat_test_assert_v1(vp3_youtube_playlist_play_v1($pdo,11,$child,$youtube['source_id'],'11111111-1111-4111-8111-111111111111',str_repeat('c',32),'record'),'existing YouTube receipt captures playback');
    music_chat_test_assert_v1(music_chat_test_count_v1($pdo,'vp3_youtube_playlist_plays_v1')===1,'no competing music listening ledger');
    $rebound=vp3_music_chat_result_bind_v1($pdo,$user,7,42,$mixed['result_id'],music_chat_fixture_message_v1($pdo));
    music_chat_test_assert_v1($rebound['playlist']['id']===$playlist['id']&&music_chat_test_count_v1($pdo,'music_chat_playlists_v1')===1,'playlist bind retry exact once');
    music_chat_test_assert_v1(vp3_music_chat_playlist_find_v1($pdo,$user,7,42,'Mixed private')['playlist']['id']===$playlist['id'],'named list exact durable identity');

    $trusted=vp3_music_chat_playlist_items_v1($pdo,$user,7,42,$playlist['id']);
    $named=music_chat_test_saved_v1($pdo,$trusted,['intent'=>'play_playlist','create_playlist'=>false,'autoplay'=>true,'existing_playlist_id'=>$playlist['id'],'expected_playlist_revision'=>1],'play-named');
    music_chat_test_assert_v1($named['playlist']['id']===$playlist['id']&&array_column($named['items'],'item_id')===array_column($mixed['items'],'item_id'),'named playlist retains identity and item order');
    music_chat_test_assert_v1(music_chat_test_count_v1($pdo,'music_chat_playlists_v1')===1,'playing named list does not duplicate');
    music_chat_test_assert_v1(vp3_music_chat_result_latest_v1($pdo,$user,7,42)['result_id']===$named['result_id'],'latest exact context reference');
    music_chat_fixture_message_v1($pdo);
    music_chat_test_assert_v1(vp3_music_chat_result_latest_v1($pdo,$user,7,42)===null,'unrelated assistant reply breaks deictic selection');
    $mid=music_chat_fixture_message_v1($pdo);$pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode(['music_chat_v1'=>vp3_music_chat_result_reference_v1($named)]),$mid]);
    music_chat_test_assert_v1(vp3_music_chat_result_latest_v1($pdo,$user,7,42)['result_id']===$named['result_id'],'later replay context can refer to original bound result');
    $clarification=music_chat_fixture_message_v1($pdo);$pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode(['music_chat_followup_v1'=>vp3_music_chat_result_reference_v1($named)]),$clarification]);
    $afterClarification=vp3_music_chat_result_latest_v1($pdo,$user,7,42);
    music_chat_test_assert_v1($afterClarification['result_id']===$named['result_id']&&!isset($afterClarification['autoplay']),'explicit clarification reference preserves selection without replaying audio');
    music_chat_fixture_message_v1($pdo);
    music_chat_test_assert_v1(vp3_music_chat_result_latest_v1($pdo,$user,7,42)===null,'unrelated reply still ends clarified selection continuity');
    $order=array_reverse(array_column($mixed['items'],'item_id'));
    $changed=vp3_music_chat_playlist_update_v1($pdo,$user,7,42,$playlist['id'],1,'Renamed private',$order);
    music_chat_test_assert_v1($changed['revision']===2&&$changed['items'][0]['source_id']==='42','reorder and rename revision');
    $restored=vp3_music_chat_result_read_v1($pdo,$user,7,42,$mixed['result_id']);
    music_chat_test_assert_v1($restored['playlist']['revision']===2&&array_column($restored['items'],'item_id')===array_column($changed['items'],'item_id'),'restored result pairs current saved order and revision');
    $restoredPlay=vp3_music_chat_resolve_v1($pdo,$user,7,42,['playlist_id'=>$restored['playlist']['id'],'revision'=>2,'index'=>0]);
    music_chat_test_assert_v1($restoredPlay['media_playback']['track_id']===42,'restored first visible song plays that exact song');
    music_chat_test_assert_v1(vp3_music_chat_result_item_reference_v1($pdo,$user,7,42,$mixed['result_id'],0)['source_id']==='42','conversational ordinal follows visible saved list order');
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_update_v1($pdo,$user,7,42,$playlist['id'],1,'Stale'),409,'optimistic stale edit rejected');
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_update_v1($pdo,$user,7,42,$playlist['id'],2,null,[$order[0],$order[0]]),422,'duplicate reorder rejected');
    music_chat_test_error_v1(fn()=>vp3_music_chat_resolve_v1($pdo,$user,7,42,['playlist_id'=>$playlist['id'],'revision'=>2,'index'=>2,'auto_action_id'=>$named['autoplay']['action_id']]),409,'edited playlist invalidates old automatic request');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'stale-named',hash('sha256','stale'),$trusted,['requested_count'=>3,'existing_playlist_id'=>$playlist['id'],'expected_playlist_revision'=>1]),409,'existing playlist store pins revision');
    $duplicate=vp3_music_chat_playlist_add_v1($pdo,$user,7,42,$playlist['id'],2,$one['result_id'],0);
    music_chat_test_assert_v1($duplicate['revision']===2&&count($duplicate['items'])===3,'adding same song idempotent');
    $more=music_chat_test_saved_v1($pdo,[['source'=>'vp3','source_id'=>'45']],['intent'=>'song','create_playlist'=>false]);
    $added=vp3_music_chat_playlist_add_v1($pdo,$user,7,42,$playlist['id'],2,$more['result_id'],0);
    music_chat_test_assert_v1($added['revision']===3&&count($added['items'])===4,'add exact shown result');
    $trimmed=vp3_music_chat_playlist_update_v1($pdo,$user,7,42,$playlist['id'],3,null,[$added['items'][1]['item_id']]);
    music_chat_test_assert_v1(count($trimmed['items'])===1&&$trimmed['items'][0]['source']==='youtube','full ordered subset removes unselected songs');
    $list=vp3_music_chat_playlist_list_v1($pdo,$user,7,42,1,0);
    music_chat_test_assert_v1($list['total']===1&&count($list['playlists'])===1&&!isset($list['playlists'][0]['items']),'bounded summaries');
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_list_v1($pdo,$user,7,42,101),422,'bounded page enforced');
    $GLOBALS['music_chat_fixture_user_id']=22;$foreign=current_user();
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_get_v1($pdo,$foreign,9,52,$playlist['id']),404,'foreign playlist private');
    $GLOBALS['music_chat_fixture_user_id']=11;

    $pending=vp3_music_chat_result_store_v1($pdo,$user,7,42,'pending',hash('sha256','pending'),[$youtube],['requested_count'=>1,'create_playlist'=>true,'title'=>'Must bind']);
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_bind_v1($pdo,$user,7,42,$pending['result_id'],102),409,'cannot bind user message');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_bind_v1($pdo,$user,7,42,$pending['result_id'],103),409,'cannot bind sibling reply');
    $collision=music_chat_fixture_message_v1($pdo);agent_connection_youtube_results_save_v1($pdo,11,42,$collision,['expires_at'=>time()+600,'videos'=>[]]);
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_bind_v1($pdo,$user,7,42,$pending['result_id'],$collision),409,'receipt collision rolls binding back');
    music_chat_test_assert_v1(!vp3_music_chat_result_read_v1($pdo,$user,7,42,$pending['result_id'])['bound']&&music_chat_test_count_v1($pdo,'music_chat_playlists_v1')===1,'failed bind never announces saved list');
    $failureMessage=music_chat_fixture_message_v1($pdo);
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $pdo->exec($mysql?"CREATE TRIGGER music_chat_fail_playlist BEFORE INSERT ON music_chat_playlists_v1 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'"
        :"CREATE TRIGGER music_chat_fail_playlist BEFORE INSERT ON music_chat_playlists_v1 BEGIN SELECT RAISE(ABORT,'fixture rollback'); END");
    $failed=false;
    try{vp3_music_chat_result_bind_v1($pdo,$user,7,42,$pending['result_id'],$failureMessage);}catch(PDOException){$failed=true;}
    $pdo->exec('DROP TRIGGER music_chat_fail_playlist');
    music_chat_test_assert_v1($failed,'database failure injected after binding and source receipt writes');
    $q=$pdo->prepare('SELECT COUNT(*) FROM agent_connection_youtube_results_v1 WHERE message_id=?');$q->execute([$failureMessage]);$rolled=(int)$q->fetchColumn();$q->closeCursor();
    music_chat_test_assert_v1($rolled===0&&!vp3_music_chat_result_read_v1($pdo,$user,7,42,$pending['result_id'])['bound'],'source receipt and assistant binding roll back together');
    music_chat_test_assert_v1(music_chat_test_count_v1($pdo,'music_chat_playlists_v1')===1&&music_chat_test_count_v1($pdo,'vp3_youtube_saved_playlists_v1')===1,'both durable list layers roll back');
    $recovered=vp3_music_chat_result_bind_v1($pdo,$user,7,42,$pending['result_id'],$failureMessage);
    music_chat_test_assert_v1(isset($recovered['playlist'])&&$recovered['source_message_id']===$failureMessage,'same request recovers cleanly after transactional failure');
    $empty=music_chat_test_saved_v1($pdo,[],['requested_count'=>1,'create_playlist'=>false,'status'=>'no_matches','coverage'=>[['source'=>'vp3','status'=>'no_matches','secret'=>'DO NOT EXPOSE']]],'empty');
    music_chat_test_assert_v1(!$empty['items']&&!isset($empty['playlist'])&&$empty['status']==='no_matches','empty no-match card stable');
    music_chat_test_assert_v1(!str_contains(json_encode($empty),'DO NOT EXPOSE')&&$empty['coverage']['shortfall']===1,'coverage allowlist and computed shortfall');
    $short=music_chat_test_saved_v1($pdo,[$native,$second],['source'=>'vp3','requested_count'=>8,'title'=>'Partial catalog']);
    music_chat_test_assert_v1($short['requested_count']===8&&$short['coverage']['shortfall']===6&&count($short['items'])===2,'saved playlist preserves original requested count and honest shortfall');

    $many=[];for($i=1;$i<=25;++$i)$many[]=music_chat_fixture_video_v1($i+10);
    $full=music_chat_test_saved_v1($pdo,$many,['source'=>'youtube','requested_count'=>25,'title'=>'All 25'],'full25');
    music_chat_test_assert_v1(count($full['items'])===25&&$full['playlist']['item_count']===25,'25 selected songs saved without hidden search cap');
    $children=$pdo->query('SELECT playlist_id,source_message_id FROM vp3_youtube_saved_playlists_v1')->fetchAll(PDO::FETCH_KEY_PAIR);$fullChild=array_search($full['source_message_id'],$children);
    $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=?,videos_json=?,retry_after=0 WHERE playlist_id=?')->execute([time()-1,'[]',$fullChild]);
    $refreshed=vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$full['playlist']['id'],music_chat_fixture_provider_v1(array_column($many,'source_id')));
    music_chat_test_assert_v1(count($refreshed['items'])===25&&$refreshed['coverage']['unavailable_count']===0,'all 25 refresh through existing provider adapter');
    music_chat_test_assert_v1(str_starts_with($refreshed['items'][24]['title'],'Refreshed '),'last provider result refreshed');
    $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=?,videos_json=?,retry_after=0 WHERE playlist_id=?')->execute([time()-1,'[]',$fullChild]);
    $revoker=music_chat_fixture_provider_v1(array_column($many,'source_id'),static function()use($pdo):void{$pdo->exec('UPDATE users SET is_active=0 WHERE id=11');});
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$full['playlist']['id'],$revoker),403,'post-provider fresh owner recheck');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=11');
    $pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='connections_youtube_enabled'");
    $pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('connections_youtube_enabled','0')");
    $disabled=vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$full['playlist']['id']);
    music_chat_test_assert_v1($disabled['coverage']['unavailable_count']===25&&!str_contains(json_encode($disabled),'Refreshed '),'disabled source hides all cached provider metadata');
    $pdo->exec("DELETE FROM settings WHERE setting_key='connections_youtube_enabled'");

    $expired=music_chat_test_saved_v1($pdo,[$native,$youtube],['title'=>'Survives search expiry'],'expires');
    $pdo->prepare('UPDATE music_chat_results_v1 SET expires_at=? WHERE result_id=?')->execute([time()-1,$expired['result_id']]);
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_read_v1($pdo,$user,7,42,$expired['result_id']),410,'expired result no data');
    music_chat_test_assert_v1(vp3_music_chat_cleanup_v1($pdo,100)>=1,'worker scrubs expired metadata');
    $q=$pdo->prepare('SELECT items_json,meta_json FROM music_chat_results_v1 WHERE result_id=?');$q->execute([$expired['result_id']]);$tombstone=$q->fetch();$q->closeCursor();
    music_chat_test_assert_v1($tombstone['items_json']==='[]'&&$tombstone['meta_json']==='{}','expiry keeps opaque request tombstone only');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'expires',hash('sha256','expires'),[$native]),410,'expired idempotency identity cannot recreate playlist');
    music_chat_test_assert_v1(count(vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$expired['playlist']['id'])['items'])===2,'saved list survives search cache expiry');
    $reference=vp3_music_chat_result_reference_v1($expired);
    music_chat_test_assert_v1(array_keys($reference['playlist'])===['id','revision','visibility']&&!isset($reference['items'],$reference['autoplay']),'durable chat reference keeps only private playlist identity');
    music_chat_test_assert_v1(vp3_music_chat_result_latest_v1($pdo,$user,7,42)['playlist']['id']===$expired['playlist']['id'],'that playlist remains addressable after source cache expiry');
    music_chat_test_assert_v1(vp3_music_chat_result_item_reference_v1($pdo,$user,7,42,$expired['result_id'],0)['source_id']==='41','durable playlist ordinal survives expired search receipt');
    $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET references_until=?,cached_until=?,videos_json=?,ordered_ids_json=? WHERE source_message_id=?')->execute([time()-1,time()-1,'[]','[]',$expired['source_message_id']]);
    $pastProvider=vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$expired['playlist']['id']);
    music_chat_test_assert_v1($pastProvider['items'][0]['available']&&!$pastProvider['items'][1]['available'],'native saved reference survives provider reference expiration');

    $saveSource=music_chat_test_saved_v1($pdo,[$native],['create_playlist'=>false],'manual-save-source');
    $manual=vp3_music_chat_playlist_create_v1($pdo,$user,7,42,$saveSource['result_id'],'Original manual name','manual-create-key');
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_create_v1($pdo,$user,7,42,$saveSource['result_id'],'Conflicting name','manual-create-key'),409,'same playlist creation key rejects a different payload');
    $manualRenamed=vp3_music_chat_playlist_update_v1($pdo,$user,7,42,$manual['playlist']['id'],1,'Changed after creation');
    $manualReplay=vp3_music_chat_playlist_create_v1($pdo,$user,7,42,$saveSource['result_id'],'Original manual name','manual-create-key');
    music_chat_test_assert_v1($manualReplay['playlist']['id']===$manual['playlist']['id']&&$manualReplay['playlist']['title']==='Changed after creation'&&$manualReplay['revision']===2,'exact create replay retains current renamed list');
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_create_v1($pdo,$user,7,42,$saveSource['result_id'],'Original manual name','changed-create-key'),409,'a saved selection cannot silently claim a new creation request');

    $addMeta=['intent'=>'add_to_playlist','requested_count'=>1,'create_playlist'=>false,'target_playlist_id'=>$manual['playlist']['id'],'expected_target_revision'=>2];
    $addedFromChat=music_chat_test_saved_v1($pdo,[$second],$addMeta,'add-from-chat');
    music_chat_test_assert_v1($addedFromChat['playlist']['id']===$manual['playlist']['id']&&$addedFromChat['playlist']['revision']===3
        &&array_column($addedFromChat['items'],'source_id')===['41','42'],'binding an add request edits the same existing list');
    music_chat_test_assert_v1(!isset($addedFromChat['autoplay']),'adding to a playlist never starts playback');
    music_chat_test_assert_v1($addedFromChat['playlist_changed']===true,'successful add has persisted change evidence');
    $addReplay=vp3_music_chat_result_request_v1($pdo,$user,7,42,'add-from-chat',hash('sha256','add-from-chat'));
    $addRebound=vp3_music_chat_result_bind_v1($pdo,$user,7,42,$addedFromChat['result_id'],music_chat_fixture_message_v1($pdo));
    music_chat_test_assert_v1($addReplay['playlist']['revision']===3&&$addRebound['playlist']['revision']===3&&count($addRebound['items'])===2&&$addReplay['playlist_changed']===true&&$addRebound['playlist_changed']===true,'add request replay and rebind cannot add twice');
    $alreadyPresent=music_chat_test_saved_v1($pdo,[$second],array_replace($addMeta,['expected_target_revision'=>3]),'add-already-present');
    music_chat_test_assert_v1($alreadyPresent['playlist']['revision']===3&&count($alreadyPresent['items'])===2&&$alreadyPresent['playlist_changed']===false,'explicitly adding an existing song preserves the same list');
    $youtubeAdded=music_chat_test_saved_v1($pdo,[$youtube],array_replace($addMeta,['expected_target_revision'=>3]),'add-youtube-from-chat');
    music_chat_test_assert_v1($youtubeAdded['playlist']['revision']===4&&$youtubeAdded['items'][2]['source']==='youtube','add request persists exact YouTube selection with source provenance');
    $youtubeAddedPlayback=vp3_music_chat_resolve_v1($pdo,$user,7,42,['playlist_id'=>$manual['playlist']['id'],'revision'=>4,'index'=>2]);
    music_chat_test_assert_v1($youtubeAddedPlayback['source_message_id']===$youtubeAdded['source_message_id']&&isset($youtubeAddedPlayback['youtube_playlist_id']),'added YouTube source resolves through its saved native receipt');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'stale-add-at-store',hash('sha256','stale-add-at-store'),[$second],$addMeta),409,'add store refuses stale target revision');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,$user,7,42,'invalid-add-auto',hash('sha256','invalid-add-auto'),[$second],array_replace($addMeta,['autoplay'=>true])),422,'add cannot smuggle automatic playback');
    $pendingAdd=vp3_music_chat_result_store_v1($pdo,$user,7,42,'pending-add',hash('sha256','pending-add'),[['source'=>'vp3','source_id'=>'45']],array_replace($addMeta,['expected_target_revision'=>4]));
    vp3_music_chat_playlist_update_v1($pdo,$user,7,42,$manual['playlist']['id'],4,'Target changed before bind');
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_bind_v1($pdo,$user,7,42,$pendingAdd['result_id'],music_chat_fixture_message_v1($pdo)),409,'target edit between add admission and saved assistant binding rejects');
    music_chat_test_assert_v1(!vp3_music_chat_result_read_v1($pdo,$user,7,42,$pendingAdd['result_id'])['bound']&&count(vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$manual['playlist']['id'])['items'])===3,'failed add binding leaves source unbound and target unchanged');
    $GLOBALS['music_chat_fixture_user_id']=22;
    music_chat_test_error_v1(fn()=>vp3_music_chat_result_store_v1($pdo,current_user(),9,52,'foreign-add',hash('sha256','foreign-add'),[$second],array_replace($addMeta,['expected_target_revision'=>5])),404,'add cannot target another account private playlist');
    $GLOBALS['music_chat_fixture_user_id']=11;

    $refreshRace=music_chat_test_saved_v1($pdo,[$youtube,$native],['title'=>'Refresh revision guard'],'refresh-revision-guard');
    $q=$pdo->prepare('SELECT playlist_id FROM vp3_youtube_saved_playlists_v1 WHERE source_message_id=?');$q->execute([$refreshRace['source_message_id']]);$refreshChild=$q->fetchColumn();$q->closeCursor();
    $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=?,videos_json=?,retry_after=0 WHERE playlist_id=?')->execute([time()-1,'[]',$refreshChild]);
    $changeDuringRefresh=music_chat_fixture_provider_v1([$youtube['source_id']],static function()use($pdo,$refreshRace):void{
        $pdo->prepare('UPDATE music_chat_playlists_v1 SET revision=revision+1 WHERE playlist_id=?')->execute([$refreshRace['playlist']['id']]);
    });
    music_chat_test_error_v1(fn()=>vp3_music_chat_resolve_v1($pdo,$user,7,42,['playlist_id'=>$refreshRace['playlist']['id'],'revision'=>1,'index'=>0],$changeDuringRefresh),409,'manual playback refuses a playlist changed during provider refresh');

    $sourceRace=music_chat_test_saved_v1($pdo,[$native,$youtube],['title'=>'Refresh visibility guard'],'refresh-visibility-guard');
    $q=$pdo->prepare('SELECT playlist_id FROM vp3_youtube_saved_playlists_v1 WHERE source_message_id=?');$q->execute([$sourceRace['source_message_id']]);$sourceChild=$q->fetchColumn();$q->closeCursor();
    $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=?,videos_json=?,retry_after=0 WHERE playlist_id=?')->execute([time()-1,'[]',$sourceChild]);
    $revokeNativeDuringRefresh=music_chat_fixture_provider_v1([$youtube['source_id']],static function()use($pdo):void{
        $pdo->exec("UPDATE tracks SET visibility='private',owner_user_id=22 WHERE id=41");
    });
    $afterRevocation=vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$sourceRace['playlist']['id'],$revokeNativeDuringRefresh);
    music_chat_test_assert_v1(!$afterRevocation['items'][0]['available']&&!str_contains(json_encode($afterRevocation),'Native Midnight')&&$afterRevocation['items'][1]['available'],'provider refresh cannot return another source metadata after its access changes');
    $pdo->exec("UPDATE tracks SET visibility='public',owner_user_id=11 WHERE id=41");
    $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=?,videos_json=?,retry_after=0 WHERE playlist_id=?')->execute([time()-1,'[]',$sourceChild]);
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_items_v1($pdo,$user,7,42,$sourceRace['playlist']['id'],$revokeNativeDuringRefresh),409,'trusted playlist references recheck local access after provider refresh');
    $pdo->exec("UPDATE tracks SET visibility='public',owner_user_id=11 WHERE id=41");

    $driving=music_chat_test_saved_v1($pdo,[$native],['title'=>'Driving playlist'],'named-driving');
    music_chat_test_assert_v1(vp3_music_chat_playlist_find_v1($pdo,$user,7,42,'driving')['playlist']['id']===$driving['playlist']['id'],'spoken playlist suffix may be omitted for a unique saved name');
    music_chat_test_assert_v1(vp3_music_chat_playlist_find_v1($pdo,$user,7,42,'DRIVING PLAYLIST')['playlist']['id']===$driving['playlist']['id'],'saved name matching is consistently case normalized');
    music_chat_test_saved_v1($pdo,[$second],['title'=>'Driving'],'named-driving-ambiguous');
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_find_v1($pdo,$user,7,42,'driving'),409,'two normalized spoken name matches require clarification');
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_find_v1($pdo,$user,7,42,'Driving playlist'),409,'a suffix does not silently select one of an ambiguous pair');

    $raceAdd=music_chat_test_parallel_v1($connection,['mode'=>'add','playlist_id'=>$manual['playlist']['id'],'revision'=>5]);
    music_chat_test_assert_v1(count(array_unique(array_column($raceAdd,'result_id')))===1&&count(array_unique(array_column($raceAdd,'playlist_id')))===1,'simultaneous add retries share one bound result');
    $raceAdded=vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$manual['playlist']['id']);
    music_chat_test_assert_v1($raceAdded['playlist']['revision']===6&&array_column($raceAdded['items'],'source_id')===['41','42',$youtube['source_id'],'45'],'simultaneous add retries append once to the exact target');

    $raceBefore=music_chat_test_count_v1($pdo,'music_chat_playlists_v1');
    $raced=music_chat_test_parallel_v1($connection,['mode'=>'create']);
    music_chat_test_assert_v1(count(array_unique(array_column($raced,'result_id')))===1&&count(array_unique(array_column($raced,'playlist_id')))===1,'real concurrent retries return one result and one playlist');
    music_chat_test_assert_v1(music_chat_test_count_v1($pdo,'music_chat_playlists_v1')===$raceBefore+1,'real SQL exactly-once playlist creation');
    $raceId=$raced[0]['playlist_id'];
    $edits=music_chat_test_parallel_v1($connection,['mode'=>'edit','playlist_id'=>$raceId,'revision'=>1],2);
    music_chat_test_assert_v1(count(array_filter($edits,static fn($r)=>($r['ok']??false)===true))===1&&count(array_filter($edits,static fn($r)=>($r['status']??0)===409))===1,'competing revisions admit one edit');
    $auto=music_chat_test_saved_v1($pdo,[$native],['create_playlist'=>false,'autoplay'=>true],'concurrent-auto');
    $claims=music_chat_test_parallel_v1($connection,['mode'=>'claim','result_id'=>$auto['result_id'],'revision'=>$auto['revision'],'auto_action_id'=>$auto['autoplay']['action_id']]);
    music_chat_test_assert_v1(count(array_filter($claims,static fn($r)=>($r['play']??false)===true))===1&&count(array_filter($claims,static fn($r)=>($r['already_claimed']??false)===true))===2,'real concurrent automatic claims issue one playback');
    vp3_music_chat_playlist_remove_v1($pdo,$user,7,42,$raceId,2);
    music_chat_test_error_v1(fn()=>vp3_music_chat_playlist_get_v1($pdo,$user,7,42,$raceId),404,'private removal owner-scoped');
    return $GLOBALS['music_chat_test_assertions_v1'];
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if(($argv[1]??'')==='--worker'){
        $config=json_decode(file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR);
        $pdo=new PDO($config['dsn'],$config['user']??null,$config['password']??null);music_chat_fixture_bootstrap_v1($pdo);
        touch($config['gate'].'.ready.'.$argv[3]);
        $until=microtime(true)+10;while(!is_file($config['gate'])&&microtime(true)<$until)usleep(10000);
        try{
            if($config['mode']==='create'){
                $value=music_chat_test_saved_v1($pdo,[['source'=>'vp3','source_id'=>'41']],['title'=>'Concurrent list'],'concurrent-create');
                $out=['result_id'=>$value['result_id'],'playlist_id'=>$value['playlist']['id']];
            }elseif($config['mode']==='add'){
                $value=music_chat_test_saved_v1($pdo,[['source'=>'vp3','source_id'=>'45']],['intent'=>'add_to_playlist','requested_count'=>1,'create_playlist'=>false,
                    'target_playlist_id'=>$config['playlist_id'],'expected_target_revision'=>$config['revision']],'concurrent-add');
                $out=['result_id'=>$value['result_id'],'playlist_id'=>$value['playlist']['id']];
            }elseif($config['mode']==='edit'){
                vp3_music_chat_playlist_update_v1($pdo,current_user(),7,42,$config['playlist_id'],$config['revision'],'Concurrent edit '.$argv[3]);$out=['ok'=>true];
            }else{
                $value=vp3_music_chat_resolve_v1($pdo,current_user(),7,42,['result_id'=>$config['result_id'],'revision'=>$config['revision'],'auto_action_id'=>$config['auto_action_id'],'index'=>0]);
                $out=['play'=>isset($value['media_playback']),'already_claimed'=>$value['already_claimed']??false];
            }
        }catch(RuntimeException $error){$out=['ok'=>false,'status'=>(int)$error->getCode(),'error'=>$error->getMessage()];}
        echo json_encode($out,JSON_THROW_ON_ERROR);exit;
    }
    $file=tempnam(sys_get_temp_dir(),'music-chat-sqlite-');$connection=['dsn'=>'sqlite:'.$file];
    try{$count=music_chat_library_suite_v1(new PDO($connection['dsn']),$connection);echo "Music chat library SQLite: {$count} checks passed.\n";}
    finally{@unlink($file);}
}
