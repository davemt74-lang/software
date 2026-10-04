<?php
declare(strict_types=1);
final class ImportSQLite extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','NOW()','GREATEST(','INSERT IGNORE'],['','CURRENT_TIMESTAMP','MAX(','INSERT OR IGNORE'],$query),$options);
    }
}
$dsn=getenv('VP3_SECTION7_MYSQL_DSN')?:'sqlite::memory:';
$mysql=str_starts_with($dsn,'mysql:');
$connection=$mysql?new PDO($dsn,'root',getenv('VP3_SECTION7_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]):new ImportSQLite($dsn,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $connection;return $connection;}
function table_exists(string $table): bool {return true;}
function column_exists(string $table,string $column): bool {return true;}
function user_has_role(string $role,array $user): bool {return $role==='artist';}
require __DIR__.'/../includes/artist-listening.php';
require __DIR__.'/../includes/homeserver-transcription-import-v1.php';
function document(string $id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',int $count=2): array {
    $segments=[];for($i=0;$i<$count;$i++)$segments[]=['client_key'=>str_pad(dechex($i+1),32,'0',STR_PAD_LEFT),'text'=>'words '.$i,'started_ms'=>$i*100];
    return ['contract'=>'vp3.homeserver.transcription-session.v1','raw_audio_included'=>false,'session'=>['id'=>$id,'title'=>'Shared text','status'=>'completed','cloud_shared'=>true,'segment_count'=>$count,'segments'=>$segments]];
}
function diarized_document(string $id): array {
    $doc=document($id,2);
    foreach([[0,900,'Speaker 1'],[600,1200,'Speaker 2']] as $i=>$spec){
        $doc['session']['segments'][$i]=[
            'client_key'=>str_repeat((string)($i+1),32),'text'=>'speaker words '.$i,
            'started_ms'=>$spec[0],'ended_ms'=>$spec[1],'speaker'=>$spec[2],
            'attribution'=>[
                'contract'=>'speaker-attribution-v1-20261004','source'=>'provider_diarization',
                'speaker_label'=>$spec[2],'confidence'=>0.0,'participant_id'=>0,
                'participant_identity'=>'','speaker_identity_verified'=>false,
                'authentication_authority'=>false,'overlap'=>true,'overlap_group'=>'overlap-1'
            ]
        ];
    }
    return $doc;
}
$user=['id'=>1];$source=document();
if(in_array('--race-import',$argv,true)||in_array('--race-start',$argv,true)){
    $barrier=getenv('VP3_SECTION7_BARRIER');file_put_contents($barrier.'.'.getmypid().'.ready','ready');
    $deadline=microtime(true)+15;while(!is_file($barrier)){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timed out.');usleep(1000);}
    if(in_array('--race-start',$argv,true))echo json_encode(['capture'=>artist_listening_v172_start($user,str_repeat('9',32),0,'en-US','1')['id']]);
    else{try{echo json_encode(homeserver_transcription_import_v1($user,$source['session']['id'],$source));}catch(RuntimeException $error){if($error->getMessage()!=='stop_current_cloud_transcription_before_import')throw $error;echo json_encode(['blocked'=>true]);}}exit;
}
foreach(['artist_transcript_segments_v172','artist_transcript_sessions_v172','artist_transcript_folders_v177','chat_conversations','knowledge_items','tracks','users'] as $table)$connection->exec('DROP TABLE IF EXISTS '.$table);
$connection->exec($mysql?'CREATE TABLE users(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB':'CREATE TABLE users(id INTEGER PRIMARY KEY)');$connection->exec('INSERT INTO users VALUES(1),(2)');
if($mysql){
    // Minimal canonical FK parents; use the production transcript schema itself.
    $connection->exec('CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $connection->exec('CREATE TABLE knowledge_items(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $connection->exec('CREATE TABLE tracks(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    artist_listening_v172_ensure_schema();
}
else{
    $connection->exec("CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,created_by_user_id INTEGER,conversation_id INTEGER,client_session_key TEXT,title TEXT,status TEXT,language TEXT,duration_ms INTEGER DEFAULT 0,metadata_json TEXT,stopped_at TEXT,discarded_at TEXT,last_activity_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(created_by_user_id,client_session_key))");
    $connection->exec("CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER,client_segment_key TEXT,segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,confidence REAL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(session_id,client_segment_key),UNIQUE(session_id,segment_index))");
}
$cases=0;
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn): void {try{$fn();}catch(RuntimeException $error){return;}throw new LogicException('Expected rejection');}
function passed(string $name): void {global $cases;$cases++;echo 'PASS '.$name."\n";}
function save(array $remote,array $user=['id'=>1]): array {return homeserver_transcription_import_v1($user,$remote['session']['id'],$remote);}
$first=save($source);$cloudId=$first['cloud_session_id'];
check($first['imported']&&artist_listening_v172_payload($connection,$user,$cloudId)['status']==='draft','atomic draft');passed('whole source imports into a closed canonical document');
$again=save($source);check($again['already_imported']&&$again['cloud_session_id']===$cloudId,'retry duplicated');passed('lost acknowledgement resolves the same independent copy');
$other=save($source,['id'=>2]);check($other['cloud_session_id']!==$cloudId,'owner collision');passed('same source keys remain isolated by Cloud owner');
$changed=$source;$changed['session']['segments'][0]['text']='changed';rejects(fn()=>save($changed));passed('changed source cannot overwrite an existing copy');
$connection->prepare("UPDATE artist_transcript_sessions_v172 SET status='discarded' WHERE id=?")->execute([$cloudId]);check(save($source)['cloud_status']==='discarded','discard resurrection');passed('retry preserves discarded copies');
foreach([
    'revoked share'=>fn($r)=>array_replace_recursive($r,['session'=>['cloud_shared'=>false]]),
    'audio transfer'=>fn($r)=>array_replace($r,['raw_audio_included'=>true]),
    'manifest count'=>fn($r)=>array_replace_recursive($r,['session'=>['segment_count'=>3]]),
    'duplicate key'=>fn($r)=>array_replace_recursive($r,['session'=>['segments'=>[1=>['client_key'=>$r['session']['segments'][0]['client_key']]]]]),
    'invalid text'=>fn($r)=>array_replace_recursive($r,['session'=>['segments'=>[0=>['text'=>"\xff"]]]]),
    'noninteger timing'=>fn($r)=>array_replace_recursive($r,['session'=>['segments'=>[0=>['started_ms'=>'0']]]]),
    'backward timing'=>fn($r)=>array_replace_recursive($r,['session'=>['segments'=>[0=>['started_ms'=>200]]]]),
] as $name=>$modify){$bad=$modify(document(str_repeat('b',32)));rejects(fn()=>save($bad));passed('reject '.$name.' without a partial document');}
$wide=document(str_repeat('c',32),1);$wide['session']['segments'][0]['text']=str_repeat('你',8000);
$wideId=save($wide)['cloud_session_id'];$stmt=$connection->prepare('SELECT transcript_text FROM artist_transcript_segments_v172 WHERE session_id=?');$stmt->execute([$wideId]);check($stmt->fetchColumn()===$wide['session']['segments'][0]['text'],'Unicode truncated');passed('valid Unicode transfers without width truncation');
$diarized=diarized_document(str_repeat('7',32));$diarizedId=save($diarized)['cloud_session_id'];
$stmt=$connection->prepare('SELECT speaker_label,started_ms,ended_ms FROM artist_transcript_segments_v172 WHERE session_id=? ORDER BY segment_index');
$stmt->execute([$diarizedId]);$speakerRows=$stmt->fetchAll(PDO::FETCH_ASSOC);
check(array_column($speakerRows,'speaker_label')===['Speaker 1','Speaker 2'],'speaker labels lost');
check((int)$speakerRows[1]['started_ms']<(int)$speakerRows[0]['ended_ms'],'overlap timing flattened');
$meta=artist_listening_v197_metadata(artist_listening_v172_payload($connection,$user,$diarizedId));
check(($meta['homeserver_import_v1']['speaker_attribution']??'')==='provider_diarization','session attribution missing');
check(empty($meta['homeserver_import_v1']['speaker_identity_verified'])&&empty($meta['homeserver_import_v1']['authentication_authority']),'identity authority imported');
check(count($meta['homeserver_import_v1']['segment_attribution']??[])===2,'per-segment attribution missing');
passed('diarized HomeServer turns preserve labels and overlap without identity authority');
$bad=diarized_document(str_repeat('8',32));$bad['session']['segments'][0]['attribution']['source']='verified_voice';
rejects(fn()=>save($bad));passed('reject imported verified voice identity');
$bad=diarized_document(str_repeat('9',32));$bad['session']['segments'][0]['attribution']['speaker_identity_verified']=true;
rejects(fn()=>save($bad));passed('reject imported identity verification flag');
$bad=diarized_document(str_repeat('a',32));$bad['session']['segments'][0]['attribution']['speaker_label']='Speaker 9';
rejects(fn()=>save($bad));passed('reject speaker label and attribution mismatch');
$bad=diarized_document(str_repeat('b',32));$bad['session']['segments'][0]['attribution']['provider_speaker_id']='raw-ref';
rejects(fn()=>save($bad));passed('reject raw provider speaker reference');

$active=artist_listening_v172_start($user,str_repeat('d',32),0,'en-US','1');
rejects(fn()=>save(document(str_repeat('e',32))));check(count(artist_listening_v172_payload($connection,$user,(int)$active['id'])['segments'])===0,'capture contaminated');passed('an active capture cannot receive imported words');
artist_listening_v172_stop($user,(int)$active['id'],0);
$connection->exec($mysql?"CREATE TRIGGER import_fail BEFORE INSERT ON artist_transcript_segments_v172 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'":"CREATE TRIGGER import_fail BEFORE INSERT ON artist_transcript_segments_v172 BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
$before=(int)$connection->query('SELECT COUNT(*) FROM artist_transcript_sessions_v172')->fetchColumn();rejects(fn()=>save(document(str_repeat('f',32))));check((int)$connection->query('SELECT COUNT(*) FROM artist_transcript_sessions_v172')->fetchColumn()===$before,'partial draft retained');$connection->exec('DROP TRIGGER import_fail');passed('database failure rolls back the whole import');
$legacy=document(str_repeat('1',32));$clientKey='hs'.substr(hash('sha256','1:'.$legacy['session']['id']),0,32);
$partial=artist_listening_v172_start($user,$clientKey,0,'en-US','1');$partialId=(int)$partial['id'];
$seg=$legacy['session']['segments'][0];artist_listening_v172_append($user,$partialId,[['text'=>$seg['text'],'key'=>substr(hash('sha256','hsseg:'.$legacy['session']['id'].':'.$seg['client_key']),0,32),'started_ms'=>0]]);
check(save($legacy)['cloud_session_id']===$partialId,'legacy retarget');check(count(artist_listening_v172_payload($connection,$user,$partialId)['segments'])===2,'legacy duplicate');passed('matching legacy partial import resumes atomically');
if($mysql){
    $connection->exec("DELETE FROM artist_transcript_sessions_v172 WHERE created_by_user_id=1 AND client_session_key='hs".substr(hash('sha256','1:'.$source['session']['id']),0,32)."'");
    function race(array $commands): array {
        $barrier=tempnam(sys_get_temp_dir(),'import-race-');unlink($barrier);putenv('VP3_SECTION7_BARRIER='.$barrier);
        $jobs=[];foreach($commands as $command){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$command],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$jobs[]=[$process,$pipes];}
        $deadline=microtime(true)+10;while(count(glob($barrier.'.*.ready'))<count($commands)){check(microtime(true)<$deadline,'Workers did not reach race barrier');usleep(1000);}
        touch($barrier);$results=[];foreach($jobs as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0,$error);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}unlink($barrier);foreach(glob($barrier.'.*.ready') as $ready)unlink($ready);
        return $results;
    }
    $results=race(array_fill(0,6,'--race-import'));$ids=array_column($results,'cloud_session_id');

    check(count(array_unique($ids))===1,'concurrent imports duplicated');$stmt=$connection->prepare('SELECT COUNT(*) FROM artist_transcript_segments_v172 WHERE session_id=?');$stmt->execute([$ids[0]]);check((int)$stmt->fetchColumn()===2,'concurrent duplicate text');passed('six parallel InnoDB imports converge on one complete copy');
    $connection->prepare('DELETE FROM artist_transcript_sessions_v172 WHERE id=?')->execute([$ids[0]]);
    $results=race(['--race-import','--race-start']);$captured=(int)$results[1]['capture'];
    $live=artist_listening_v172_payload($connection,$user,$captured);check($live['status']==='active'&&!$live['segments'],'import contaminated concurrent capture');
    if(isset($results[0]['cloud_session_id']))check((int)$results[0]['cloud_session_id']!==$captured,'import reused concurrent capture');
    else check(!empty($results[0]['blocked']),'import neither saved nor blocked');
    passed('parallel InnoDB import and capture Start keep distinct documents');

}
echo "TRANSFER_INTEGRITY_SECTION7=PASS ($cases canonical service cases)\n";
