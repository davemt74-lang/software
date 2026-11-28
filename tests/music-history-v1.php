<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/music-history-v1.php';

/** Actual SQL mutation during a read proves the final live check; authorization is never stubbed. */
class MusicHistoryTestPDO extends PDO
{
    public ?Closure $historyHook=null;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $statement=parent::prepare($query,$options);
        if($this->historyHook!==null)($this->historyHook)($this,$query);
        return $statement;
    }
}

function music_history_assert_v1(bool $condition,string $label): void
{
    if(!$condition)throw new RuntimeException('FAIL: '.$label);
    $GLOBALS['music_history_assertions_v1']=($GLOBALS['music_history_assertions_v1']??0)+1;
}
function music_history_error_v1(callable $call,int $status,string $label): void
{
    try{$call();}catch(RuntimeException $error){music_history_assert_v1((int)$error->getCode()===$status,$label.' ('.$error->getCode().')');return;}
    throw new RuntimeException('FAIL: '.$label.' did not reject');
}
function music_history_refs_v1(array $page): array{return array_column($page['items'],'entry_ref');}
function music_history_snapshot_v1(PDO $pdo): string
{
    $tables=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
        :$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);$data=[];
    foreach($tables as $table){$rows=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$encoded=array_map(static fn(array $r):string=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($encoded);$data[$table]=$encoded;}
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}

function music_history_suite_v1(PDO $pdo): int
{
    $GLOBALS['music_history_assertions_v1']=0;
    music_chat_fixture_bootstrap_v1($pdo);music_chat_fixture_schema_v1($pdo);music_chat_fixture_seed_v1($pdo);
    music_history_fixture_schema_v1($pdo);$fixture=music_history_fixture_seed_v1($pdo);$user=current_user();$refs=$fixture['refs'];
    $read=static fn(array $input=[]):array=>vp3_music_history_read_v1($pdo,$user,7,42,$input);
    $entry=static fn(string $ref):array=>vp3_music_history_entry_v1($pdo,$user,7,42,$ref);
    $item=static fn(string $ref):array=>vp3_music_history_item_v1($pdo,$user,7,42,$ref);
    $before=music_history_snapshot_v1($pdo);$page=$read();
    music_history_assert_v1($page['protocol']===VP3_MUSIC_HISTORY_PROTOCOL_V1&&count($page['items'])===6,'both sources, individual receipt count');
    music_history_assert_v1(music_history_refs_v1($page)===[$refs['native'],$refs['ordinary'],$refs['playlist'],$refs['repeat'],$refs['legacy'],$refs['unavailable']],'one stable cross-source newest-first order');
    music_history_assert_v1(!$page['has_more']&&$page['next_cursor']===null,'exact exhausted page');
    music_history_assert_v1($page['items'][0]['listened_seconds']==18&&$page['items'][0]['measurement']==='native_receipts','accepted native measurement');
    music_history_assert_v1($page['items'][3]['listened_seconds']===null&&$page['items'][3]['evidence']==='started_only','start is not invented zero listening');
    music_history_assert_v1($page['items'][4]['listened_seconds']===12.5&&$page['items'][4]['measurement']==='native_legacy_reported','legacy evidence remains distinct');
    music_history_assert_v1($page['items'][1]['measurement']==='youtube_watch_receipts'&&$page['items'][2]['listened_seconds']===10,'ordinary and MC1 child measurements included');
    music_history_assert_v1($page['items'][0]['resume_seconds']==22&&$page['items'][0]['resume_available'],'mapped unchanged asset has exact historical resume');
    music_history_assert_v1(!$page['items'][3]['resume_available']&&!$page['items'][4]['resume_available'],'start-only and legacy lack verified resume');
    music_history_assert_v1(!$page['items'][5]['play_available']&&$page['items'][5]['title']==='Unavailable VP3 song'&&$page['items'][5]['source_id']==='','retained unavailable native placeholder');
    $wire=json_encode($page,JSON_THROW_ON_ERROR);
    foreach(['PRIVATE FOREIGN TITLE','history.mp3','native_revision','youtube_video','session_token','capture_key','source_url','listener_hash'] as $secret){music_history_assert_v1(!str_contains($wire,$secret),'public history omits '.$secret);}
    music_history_assert_v1($page['coverage']['sources']['youtube']['capture_enabled']&&$page['coverage']['sources']['youtube']['measurement_enabled'],'explicit capture/measurement coverage');
    music_history_assert_v1(str_contains(implode(' ',$page['coverage']['sources']['youtube']['notes']),'seven days')&&str_contains($page['coverage']['summary'],'not a complete'),'retention and completeness honest');
    music_history_assert_v1($item($refs['native'])['item']['source_id']==='71'&&isset($item($refs['native'])['item']['native_revision']),'trusted exact native item');
    music_history_assert_v1($item($refs['playlist'])['item']['youtube_video']['video_id']==='vp300000006'&&$item($refs['playlist'])['resume_seconds']==15,'exact child receipt canonical item and position');
    music_history_assert_v1(music_history_snapshot_v1($pdo)===$before,'discovery, restore and selection are read-only in every table');
    music_history_assert_v1(count($read(['source'=>'vp3'])['items'])===4&&count($read(['source'=>'youtube'])['items'])===2,'source filters');
    music_history_assert_v1(music_history_refs_v1($read(['query'=>'MOONLIGHT']))===[$refs['native'],$refs['repeat']],'current title text filter retains repeated plays');
    music_history_assert_v1(count($read(['query'=>'Night Drives'])['items'])===2&&count($read(['query'=>'fixture channel'])['items'])===2,'album and artist text filters');
    music_history_assert_v1($read(['query'=>'PRIVATE FOREIGN'])['items']===[],'unavailable titles cannot be searched');
    music_history_assert_v1($read(['query'=>"%' OR 1=1 --"])['items']===[],'text wildcard and SQL syntax remain literal text');
    $draft=music_history_fixture_native_v1($pdo,44,$fixture['clock']-200);$draftRef=vp3_music_history_reference_v1(11,'n',(string)$draft);
    music_history_assert_v1($entry($draftRef)['title']==='Unpublished Draft'&&$entry($draftRef)['metadata_available']&&!$entry($draftRef)['play_available'],'owned unpublished history keeps authorized metadata without expanding MC1 Play');
    music_history_assert_v1(music_history_refs_v1($read(['query'=>'Unpublished Draft']))===[$draftRef],'authorized preview history remains searchable');
    music_history_error_v1(fn()=>$item($draftRef),409,'preview history does not bypass published-only MC1 selection');
    $pdo->exec('DELETE FROM track_play_sessions WHERE id='.$draft);
    foreach([
        ['source'=>'stoneward'],['limit'=>0],['limit'=>51],['limit'=>'2'],['query'=>str_repeat('q',201)],['query'=>"bad\nquery"],
        ['timezone'=>'Mars/Olympus'],['from'=>'2026-02-30'],['from'=>'2026-11-29','to'=>'2026-11-28'],
        ['from_at'=>'2026-02-30T12:00:00Z'],['from_at'=>'2026-01-01T12:00:00'],
        ['from_at'=>'2026-11-27T18:00:00Z','until_at'=>'2026-11-27T18:00:00Z'],
        ['from'=>'2026-11-27','until_at'=>'2026-11-28T06:00:00Z'],['owner_user_id'=>22],['cursor'=>[]],
    ] as $invalid)music_history_error_v1(fn()=>$read($invalid),422,'invalid filter '.json_encode($invalid));

    $first=$read(['limit'=>2]);$all=music_history_refs_v1($first);$next=$first;
    music_history_assert_v1($first['has_more']&&is_string($first['next_cursor']),'bounded first page has cursor');
    while($next['next_cursor']!==null){$next=$read($first['filters']+['limit'=>2,'cursor'=>$next['next_cursor']]);$all=[...$all,...music_history_refs_v1($next)];}
    music_history_assert_v1($all===music_history_refs_v1($page)&&count(array_unique($all))===6,'keyset pages neither skip nor repeat receipt entries');
    $cursor=$first['next_cursor'];
    music_history_error_v1(fn()=>$read($first['filters']+['limit'=>3,'cursor'=>$cursor]),409,'page size bound to cursor');
    music_history_error_v1(fn()=>$read(array_replace($first['filters'],['query'=>'Moonlight','limit'=>2,'cursor'=>$cursor])),409,'filter bound to cursor');
    music_history_error_v1(fn()=>vp3_music_history_read_v1($pdo,$user,7,43,$first['filters']+['limit'=>2,'cursor'=>$cursor]),409,'conversation bound to cursor');
    music_history_error_v1(fn()=>$read(['cursor'=>substr($cursor,0,-1).(str_ends_with($cursor,'0')?'1':'0')]),409,'cursor tamper denied');
    $secret=$_SESSION['vp3_media_playback_secret_v1'];$_SESSION['vp3_media_playback_secret_v1']=str_repeat('b',64);
    music_history_error_v1(fn()=>$read($first['filters']+['limit'=>2,'cursor'=>$cursor]),409,'session rotation expires paging');
    music_history_assert_v1($entry($refs['native'])['entry_ref']===$refs['native'],'durable receipt reference survives session rotation');$_SESSION['vp3_media_playback_secret_v1']=$secret;
    $parts=explode('.',$cursor);$payload=json_decode(vp3_music_history_unb64_v1($parts[1]),true,12,JSON_THROW_ON_ERROR);$payload['as_of']=time()-3601;
    music_history_error_v1(fn()=>$read($first['filters']+['limit'=>2,'cursor'=>vp3_music_history_cursor_v1($payload)]),409,'cursor time expiry');
    $late=music_history_fixture_native_v1($pdo,41,$fixture['clock']-1000);$new=music_history_fixture_native_v1($pdo,41,time());
    $next=$read($first['filters']+['limit'=>2,'cursor'=>$cursor]);$frozen=music_history_refs_v1($next);
    while($next['next_cursor']!==null){$next=$read($first['filters']+['limit'=>2,'cursor'=>$next['next_cursor']]);$frozen=[...$frozen,...music_history_refs_v1($next)];}
    music_history_assert_v1(!in_array(vp3_music_history_reference_v1(11,'n',(string)$late),$frozen,true)&&!in_array(vp3_music_history_reference_v1(11,'n',(string)$new),$frozen,true),'native high-water mark excludes later inserts even backdated ones');
    $pdo->exec('DELETE FROM track_play_sessions WHERE id IN ('.$late.','.$new.')');

    // Equal timestamps exercise every keyset tie field and bytewise cross-ledger order.
    $tie=music_history_fixture_native_v1($pdo,42,$fixture['clock']);$tieRef=vp3_music_history_reference_v1(11,'n',(string)$tie);
    $pdo->prepare('UPDATE vp3_youtube_playback_v1 SET played_at=? WHERE play_id=?')->execute([$fixture['clock'],$fixture['ordinary']]);
    $pdo->prepare('UPDATE vp3_youtube_playlist_plays_v1 SET played_at=? WHERE play_id=?')->execute([$fixture['clock'],$fixture['playlist']]);
    $ties=$read(['limit'=>2]);$tieRefs=music_history_refs_v1($ties);
    while($ties['has_more']){$ties=$read($ties['filters']+['limit'=>2,'cursor'=>$ties['next_cursor']]);$tieRefs=[...$tieRefs,...music_history_refs_v1($ties)];}
    music_history_assert_v1(array_slice($tieRefs,0,4)===[$refs['playlist'],$refs['ordinary'],$tieRef,$refs['native']]&&count(array_unique($tieRefs))===7,'equal-second tie paging orders provider kind and numeric native IDs without duplicates');
    $pdo->prepare('UPDATE vp3_youtube_playback_v1 SET played_at=? WHERE play_id=?')->execute([$fixture['clock']-20,$fixture['ordinary']]);
    $pdo->prepare('UPDATE vp3_youtube_playlist_plays_v1 SET played_at=? WHERE play_id=?')->execute([$fixture['clock']-40,$fixture['playlist']]);
    $pdo->exec('DELETE FROM track_play_sessions WHERE id='.$tie);

    $floor=(int)$pdo->query('SELECT MAX(id) FROM track_play_sessions')->fetchColumn();
    for($i=0;$i<211;++$i)music_history_fixture_native_v1($pdo,41,$fixture['clock']-300-$i);
    $needle=music_history_fixture_native_v1($pdo,42,$fixture['clock']-900);
    music_history_assert_v1(music_history_refs_v1($read(['query'=>'First Light','limit'=>1]))===[vp3_music_history_reference_v1(11,'n',(string)$needle)],'text query scans past multiple bounded SQL chunks without a hidden history cutoff');
    $paged=$read(['source'=>'vp3','limit'=>50]);$scan=music_history_refs_v1($paged);$pages=1;
    while($paged['has_more']){$paged=$read($paged['filters']+['limit'=>50,'cursor'=>$paged['next_cursor']]);$scan=[...$scan,...music_history_refs_v1($paged)];++$pages;}
    music_history_assert_v1(count($scan)===216&&count(array_unique($scan))===216&&$pages===5,'all retained native rows page through five pages');
    $pdo->exec('DELETE FROM track_play_sessions WHERE id>'.$floor);

    $floor=(int)$pdo->query('SELECT MAX(id) FROM track_play_sessions')->fetchColumn();
    $boundary=[];
    foreach(['2026-03-08T04:59:59Z','2026-03-08T05:00:00Z','2026-03-09T03:59:59Z','2026-03-09T04:00:00Z'] as $at){$boundary[]=music_history_fixture_native_v1($pdo,42,(new DateTimeImmutable($at))->getTimestamp());}
    $dst=$read(['source'=>'vp3','query'=>'First Light','from'=>'2026-03-08','to'=>'2026-03-08','timezone'=>'America/New_York']);
    music_history_assert_v1(music_history_refs_v1($dst)===[vp3_music_history_reference_v1(11,'n',(string)$boundary[2]),vp3_music_history_reference_v1(11,'n',(string)$boundary[1])],'DST spring day is23hours and includes only local calendar day');
    music_history_assert_v1(str_ends_with($dst['items'][0]['played_at_local'],'-04:00')&&str_ends_with($dst['items'][1]['played_at_local'],'-05:00'),'per-entry local timestamps retain actual DST offsets');
    $night=$read(['source'=>'vp3','query'=>'First Light','from_at'=>'2026-03-07T18:00:00-05:00','until_at'=>'2026-03-08T06:00:00-04:00','timezone'=>'America/New_York']);
    music_history_assert_v1(count($night['items'])===2&&$night['filters']['until_at']==='2026-03-08T10:00:00Z','exact night spans DST using explicit absolute bounds');
    $exclusive=$read(['source'=>'vp3','query'=>'First Light','from_at'=>'2026-03-08T05:00:00Z','until_at'=>'2026-03-09T03:59:59Z']);
    music_history_assert_v1(music_history_refs_v1($exclusive)===[vp3_music_history_reference_v1(11,'n',(string)$boundary[1])],'exact until bound is exclusive');
    $pdo->exec('DELETE FROM track_play_sessions WHERE id>'.$floor);

    foreach([['is_active',0],['account_allowed',0],['chat_allowed',0]] as [$column,$value]){
        $pdo->exec('UPDATE users SET '.$column.'='.$value.' WHERE id=11');music_history_error_v1(fn()=>$read(),403,'live account '.$column);$pdo->exec('UPDATE users SET '.$column.'=1 WHERE id=11');
    }
    $pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');music_history_error_v1(fn()=>$read(),403,'active reading Agent required');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
    foreach([[8,42],[0,42],[7,52],[7,0]] as [$agent,$cid])music_history_error_v1(fn()=>vp3_music_history_read_v1($pdo,$user,$agent,$cid,[]),403,'exact owner Agent conversation boundary');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>1];music_history_error_v1(fn()=>$read(),403,'participant scope excluded');unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    $GLOBALS['music_chat_fixture_user_id']=22;music_history_error_v1(fn()=>$read(),403,'live authenticated owner mismatch');$GLOBALS['music_chat_fixture_user_id']=11;
    $pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','playback-history','deny')");music_history_error_v1(fn()=>$read(),403,'named Agent explicit history optout');music_history_error_v1(fn()=>$item($refs['native']),403,'exact item cannot bypass history optout');$pdo->exec('DELETE FROM user_agent_data_rules WHERE agent_id=7');
    $pdo->exec("INSERT INTO user_data_policies(owner_user_id,resource_type,resource_id,audience_scope,owner_agents_allowed,profile_agent_allowed,stonefellow_shared) VALUES(11,'knowledge','playback-history','private',0,0,0)");
    music_history_error_v1(fn()=>$read(),403,'owner history sharing policy denied');
    music_history_assert_v1(count(vp3_music_history_read_v1($pdo,$user,0,45,['source'=>'vp3'])['items'])===4,'owner system Agent retains own native records under existing policy');
    $pdo->exec('DELETE FROM user_data_policies');
    music_history_error_v1(fn()=>$entry(vp3_music_history_reference_v1(22,'n',(string)$fixture['foreign'])),404,'foreign locator does not convey authority');
    foreach(['not-a-reference',$refs['native'].'=',vp3_music_history_reference_v1(11,'x','1')] as $bad)music_history_error_v1(fn()=>$entry($bad),422,'malformed locator');
    music_history_error_v1(fn()=>$item($refs['unavailable']),409,'redacted unavailable item cannot enter MC1');
    $pdo->exec("UPDATE tracks SET title='Current Moonlight' WHERE id=71");music_history_assert_v1($entry($refs['native'])['title']==='Current Moonlight'&&$read(['query'=>'History Moonlight'])['items']===[],'title changes use current authority, not cached labels');
    $pdo->exec("UPDATE tracks SET visibility='private',owner_user_id=22,title='REVOKED SECRET' WHERE id=71");
    music_history_assert_v1($entry($refs['native'])['title']==='Unavailable VP3 song'&&!str_contains(json_encode($read()),'REVOKED SECRET'),'revoked song hides both current and historical titles');
    music_history_error_v1(fn()=>$item($refs['native']),409,'revocation blocks exact replay');
    $pdo->exec("UPDATE tracks SET visibility='public',owner_user_id=11,title='History Moonlight' WHERE id=71");
    $pdo->exec("UPDATE music_recordings_v1 SET current_asset_id='00000000-0000-4000-8000-000000000999' WHERE source_track_id=71");
    music_history_assert_v1($entry($refs['native'])['play_available']&&!$entry($refs['native'])['resume_available'],'audio asset replacement preserves current Play but suppresses historical Resume');
    $pdo->exec("UPDATE music_recordings_v1 SET current_asset_id='00000000-0000-4000-8000-000000000271' WHERE source_track_id=71");
    $pdo->exec("UPDATE tracks SET audio_path='native.wav' WHERE id=71");music_history_assert_v1(!$entry($refs['native'])['resume_available'],'legacy media path replacement also blocks Resume');$pdo->exec("UPDATE tracks SET audio_path='history.mp3' WHERE id=71");
    $pdo->exec('UPDATE music_listening_sessions_v1 SET position_ms=duration_ms WHERE session_id='.$fixture['native']);music_history_assert_v1(!$entry($refs['native'])['resume_available'],'past-end native position cannot Resume');$pdo->exec('UPDATE music_listening_sessions_v1 SET position_ms=22000 WHERE session_id='.$fixture['native']);
    $pdo->exec('UPDATE music_listening_sessions_v1 SET natural_ended=1 WHERE session_id='.$fixture['native']);music_history_assert_v1(!$entry($refs['native'])['resume_available'],'completed native receipt cannot Resume');$pdo->exec('UPDATE music_listening_sessions_v1 SET natural_ended=0 WHERE session_id='.$fixture['native']);

    if($pdo instanceof MusicHistoryTestPDO){
        $pdo->historyHook=static function(MusicHistoryTestPDO $db,string $sql):void{
            if(str_starts_with($sql,'SELECT a.stored_path,c.audio_path')){$db->historyHook=null;$db->exec('UPDATE users SET chat_allowed=0 WHERE id=11');}
        };
        music_history_error_v1(fn()=>$read(),403,'mid-scan owner authority revocation cannot return early projected metadata');
        $pdo->historyHook=null;$pdo->exec('UPDATE users SET chat_allowed=1 WHERE id=11');
        $pdo->historyHook=static function(MusicHistoryTestPDO $db,string $sql):void{
            if(str_starts_with($sql,'SELECT p.*,r.payload_json')){$db->historyHook=null;$db->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='revoked' WHERE owner_user_id=11");}
        };
        music_history_error_v1(fn()=>$read(),409,'mid-scan YouTube optout cannot survive cached source eligibility');
        $pdo->historyHook=null;$pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET policy_version=? WHERE owner_user_id=11')->execute([VP3_YOUTUBE_CONSENT_POLICY_V1]);
        $pdo->historyHook=static function(MusicHistoryTestPDO $db,string $sql):void{
            if(str_starts_with($sql,'SELECT a.stored_path,c.audio_path')){$db->historyHook=null;$db->exec("UPDATE tracks SET visibility='private',owner_user_id=22,title='MIDSCAN SECRET' WHERE id=71");}
        };
        $revoked=$read();music_history_assert_v1($revoked['items'][0]['title']==='Unavailable VP3 song'&&!str_contains(json_encode($revoked),'MIDSCAN SECRET')&&!str_contains(json_encode($revoked),'History Moonlight'),'final page reprojects metadata after mid-scan song revocation');
        $pdo->historyHook=null;$pdo->exec("UPDATE tracks SET visibility='public',owner_user_id=11,title='History Moonlight' WHERE id=71");
    }

    $pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='youtube_watch_research_owner_11'");
    $yt=$read(['source'=>'youtube']);music_history_assert_v1(count($yt['items'])===2&&$yt['items'][0]['listened_seconds']===null&&$yt['items'][1]['listened_seconds']===null&&!$yt['coverage']['sources']['youtube']['measurement_enabled'],'measurement optout suppresses both ledgers, retained starts visible');
    $pdo->exec("UPDATE settings SET setting_value='1' WHERE setting_key='youtube_watch_research_owner_11'");
    vp3_youtube_history_change_v1($pdo,11,'pause_history');$yt=$read(['source'=>'youtube']);
    music_history_assert_v1(count($yt['items'])===2&&!$yt['coverage']['sources']['youtube']['capture_enabled']&&!$yt['items'][0]['resume_available']&&!$yt['items'][1]['resume_available'],'capture pause retains starts without Resume');
    vp3_youtube_history_change_v1($pdo,11,'enable_history');
    $pdo->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='expired' WHERE owner_user_id=11");
    music_history_assert_v1($read(['source'=>'youtube'])['items']===[]&&$read()['coverage']['sources']['youtube']['status']==='disabled','consent optout hides source');
    music_history_error_v1(fn()=>$entry($refs['ordinary']),404,'opted-out source cannot be restored by locator');
    $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET policy_version=? WHERE owner_user_id=11')->execute([VP3_YOUTUBE_CONSENT_POLICY_V1]);
    foreach([0,time()+60] as $invalidConsent){
        $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11')->execute([$invalidConsent]);
        music_history_assert_v1($read(['source'=>'youtube'])['items']===[],'invalid or future consent epoch cannot authorize history');
        music_history_error_v1(fn()=>$item($refs['playlist']),404,'exact child cannot bypass invalid consent epoch');
    }
    $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11')->execute([$fixture['clock']-20]);
    music_history_assert_v1(music_history_refs_v1($read(['source'=>'youtube']))===[$refs['ordinary']],'consent boundary includes exact-time ordinary start and excludes earlier child');
    music_history_error_v1(fn()=>$entry($refs['playlist']),404,'pre-consent exact child does not revive on policy reacceptance');
    $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11')->execute([$fixture['clock']-19]);
    music_history_assert_v1($read(['source'=>'youtube'])['items']===[],'later consent epoch excludes both retained ledgers');
    music_history_error_v1(fn()=>$entry($refs['ordinary']),404,'pre-consent ordinary locator cannot bypass current epoch');
    $pdo->prepare('UPDATE agent_connection_youtube_consent_v1 SET accepted_at=? WHERE owner_user_id=11')->execute([$fixture['clock']-8*86400]);
    $oldContext=(string)$pdo->query('SELECT context_json FROM chat_messages WHERE id='.$fixture['message'])->fetchColumn();
    foreach([['participant_context'=>['id'=>1]],['shared_chat'=>true],['source'=>'public_profile'],['agent_context'=>['participant_scope'=>1]]] as $context){
        $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode($context),$fixture['message']]);
        music_history_assert_v1($read(['source'=>'youtube'])['items']===[],'historical public/shared/participant source excluded');
    }
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([$oldContext,$fixture['message']]);
    music_history_assert_v1(count($read(['source'=>'youtube'])['items'])===2,'MC1 skip-brain marker does not hide separately recorded private child plays');
    $pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=43');$pdo->exec('UPDATE chat_messages SET conversation_id=43 WHERE id='.$fixture['message']);
    music_history_assert_v1(count($read(['source'=>'youtube'])['items'])===2,'authorized owner history crosses current source Agent scope');
    $pdo->exec("INSERT INTO user_agent_data_rules VALUES(8,'knowledge','playback-history','deny')");
    music_history_assert_v1($read(['source'=>'youtube'])['items']===[],'source Agent deny fences both ordinary and child receipts');
    $pdo->exec('DELETE FROM user_agent_data_rules WHERE agent_id=8');$pdo->exec('UPDATE chat_messages SET conversation_id=42 WHERE id='.$fixture['message']);$pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=43');
    $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=? WHERE playlist_id=?')->execute([time()-1,$fixture['child']]);
    music_history_assert_v1(count($read(['source'=>'youtube'])['items'])===1&&$entry($refs['playlist'])['title']==='Unavailable YouTube video','expired child omitted in discovery and redacted in exact restore');
    music_history_error_v1(fn()=>$item($refs['playlist']),409,'expired child cannot play');
    $pdo->prepare('UPDATE vp3_youtube_saved_playlists_v1 SET cached_until=? WHERE playlist_id=?')->execute([time()+600,$fixture['child']]);
    $pdo->prepare('UPDATE vp3_youtube_playlist_plays_v1 SET played_at=? WHERE play_id=?')->execute([time()-7*86400,$fixture['playlist']]);
    music_history_assert_v1(count($read(['source'=>'youtube'])['items'])===1&&!$entry($refs['playlist'])['play_available'],'seven-day saved-play history does not extend with refreshed metadata');
    $pdo->prepare('UPDATE vp3_youtube_playlist_plays_v1 SET played_at=? WHERE play_id=?')->execute([$fixture['clock']-40,$fixture['playlist']]);
    $pdo->prepare('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id=?')->execute([time()-1,$fixture['message']]);
    $yt=$read(['source'=>'youtube']);music_history_assert_v1(count($yt['items'])===1&&$yt['items'][0]['entry_ref']===$refs['playlist'],'ordinary expiry independent from current child cache');
    music_history_assert_v1($entry($refs['ordinary'])['title']==='Unavailable YouTube video'&&!$entry($refs['ordinary'])['play_available'],'expired provider title absent from restored exact record');
    $pdo->prepare('UPDATE agent_connection_youtube_results_v1 SET expires_at=? WHERE message_id=?')->execute([time()+600,$fixture['message']]);
    $pdo->exec('UPDATE chat_messages SET conversation_id=52 WHERE id='.$fixture['message']);music_history_assert_v1($read(['source'=>'youtube'])['items']===[],'lost source conversation ownership excluded');$pdo->exec('UPDATE chat_messages SET conversation_id=42 WHERE id='.$fixture['message']);
    $pdo->prepare('DELETE FROM vp3_youtube_playback_v1 WHERE owner_user_id=11 AND play_id=?')->execute([$fixture['ordinary']]);music_history_error_v1(fn()=>$entry($refs['ordinary']),404,'cleared history cannot be restored from context');
    $pdo->exec('DELETE FROM track_play_sessions WHERE id='.$fixture['native']);music_history_error_v1(fn()=>$entry($refs['native']),404,'removed native history cannot be restored');
    $before=music_history_snapshot_v1($pdo);$read();$entry($refs['playlist']);$item($refs['playlist']);music_history_assert_v1(music_history_snapshot_v1($pdo)===$before,'final authority/expiry-aware reader never cleans, refreshes or updates history');

    // Schema gaps remain an explicit coverage state; no read creates or repairs tables.
    $pdo->exec('DROP TABLE music_listening_receipts_v1');$pdo->exec('DROP TABLE music_listening_sessions_v1');
    music_history_assert_v1(!$read()['coverage']['sources']['vp3']['measurement_available'],'legacy-only schema is explicit coverage');
    $pdo->exec('DROP TABLE track_play_events');$pdo->exec('DROP TABLE track_play_sessions');
    $before=music_history_snapshot_v1($pdo);$missing=$read();
    music_history_assert_v1($missing['coverage']['sources']['vp3']['status']==='unavailable'&&$missing['items']!==[],'missing native source permits explicit partial YouTube coverage');
    music_history_assert_v1(music_history_snapshot_v1($pdo)===$before&&!table_exists('track_play_sessions'),'missing-schema read does not perform DDL');
    return (int)$GLOBALS['music_history_assertions_v1'];
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $pdo=new MusicHistoryTestPDO('sqlite::memory:');$count=music_history_suite_v1($pdo);
    echo "Music history SQLite (real SQL): {$count} checks passed.\n";
}
