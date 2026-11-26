<?php
declare(strict_types=1);

/** Native queries are unchanged; fixture-only pipe barriers expose their order. */
class ProviderConcurrencyPDO extends PDO
{
    public bool $announceWorkspace=false;
    public bool $pauseBeforeInsert=false;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if($this->announceWorkspace&&str_starts_with($query,'SELECT id FROM artist_workspaces_v181 WHERE id=?')&&str_contains($query,'FOR UPDATE')){
            $this->announceWorkspace=false;fwrite(STDOUT,"WORKSPACE\n");fflush(STDOUT);
        }
        if($this->pauseBeforeInsert&&str_starts_with($query,'INSERT INTO music_provider_sessions_v1 ')){
            $this->pauseBeforeInsert=false;fwrite(STDOUT,"BEFORE_INSERT\n");fflush(STDOUT);
            if(trim((string)fgets(STDIN,20))!=='CONTINUE')throw new RuntimeException('The insert barrier was not released.');
        }
        return parent::prepare($query,$options);
    }
}

/** Separate PHP processes and PDO connections, not serial replay or mocked locks. */
function provider_concurrency_mysql(array $barriers=[]): ?PDO
{
    $dsn=getenv('MUSIC_TEST_MYSQL_DSN')?:'';if($dsn==='')return null;
    if(!str_starts_with($dsn,'mysql:')||!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('Native MySQL concurrency requires pdo_mysql and a mysql: DSN.');
    $pdo=new ProviderConcurrencyPDO($dsn,getenv('MUSIC_TEST_MYSQL_USER')?:'',getenv('MUSIC_TEST_MYSQL_PASSWORD')?:'',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $pdo->announceWorkspace=($barriers['workspace']??false)===true;$pdo->pauseBeforeInsert=($barriers['insert']??false)===true;
    if(!preg_match('/\Avp3_music_test[a-zA-Z0-9_]*\z/',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Refusing concurrency fixture: database name must start with vp3_music_test.');
    return $pdo;
}

function provider_concurrency_worker(): never
{
    try{
        $line=fgets(STDIN,65537);$job=json_decode($line===false?'':$line,true,24,JSON_THROW_ON_ERROR);
        $root=is_array($job)&&is_string($job['root']??null)?realpath($job['root']):false;
        if(!$root||!str_starts_with($root,rtrim(sys_get_temp_dir(),'/').'/vp3-provider-concurrency-'))throw new RuntimeException('Invalid isolated concurrency fixture.');
        define('STONEFELLOW_ROOT',$root);define('MUSIC_PROVIDER_PLAYBACK_TEST_LIBRARY_ONLY',true);
        if($pdo=provider_concurrency_mysql($job['barriers']??[]))$GLOBALS['musicSongTestConnection']=$pdo;
        else{
            if(!is_file($root.'/data.sqlite'))throw new RuntimeException('Concurrency SQLite fixture is missing.');
            putenv('MUSIC_LISTENING_TEST_DB='.$root.'/data.sqlite');
        }
        require __DIR__.'/music-provider-playback-v1.php';
        $GLOBALS['config']=['site'=>['base_url'=>'https://vp3.example.com','base_path'=>'']];
        if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')db()->exec('PRAGMA busy_timeout=10000');
        fwrite(STDOUT,"READY\n");fflush(STDOUT);
        if(trim((string)fgets(STDIN,16))!=='GO')throw new RuntimeException('Concurrency barrier was not released.');
        $result=($job['operation']??'playback')==='catalog'
            ?provider_concurrency_catalog(db(),($job['published']??false)===true)
            :music_provider_v1_playback_action(db(),$job['input'],$job['context']);
        fwrite(STDOUT,json_encode(['ok'=>true,'receipt'=>$result],JSON_THROW_ON_ERROR)."\n");exit(0);
    }catch(Throwable $error){
        // Fixture diagnostics contain no credentials, media tickets or DB DSN.
        fwrite(STDOUT,json_encode(['ok'=>false,'class'=>get_class($error),'status'=>$error instanceof MusicProviderV1Exception?$error->httpStatus:500,
            'code'=>$error instanceof MusicProviderV1Exception?$error->publicCode:'fixture_failure'],JSON_THROW_ON_ERROR)."\n");exit(1);
    }
}

function provider_concurrency_catalog(PDO $pdo,bool $published): array
{
    require_once dirname(__DIR__).'/includes/music-catalog.php';
    $catalog=artist_music_v185_track($pdo,1,10);$metadata=music_song_metadata_v1_row($pdo,1,10);
    $id=music_catalog_write($pdo,1,current_user(),'track','save',[
        'id'=>10,'title'=>$catalog['title'],'description'=>$catalog['description']??'','genre'=>$catalog['genre']??'',
        'visibility'=>'public','is_published'=>$published?1:0,'album_id'=>(int)($catalog['album_id']??0),
        'duration_seconds'=>(int)($catalog['duration_seconds']??0),'track_number'=>(int)($catalog['track_number']??0),
        'cover_photo_id'=>(int)($catalog['cover_photo_id']??0),'revision'=>music_catalog_revision($catalog),'metadata_revision'=>(int)$metadata['revision'],
    ]);
    return ['catalog_track_id'=>$id,'published'=>$published];
}

if(($argv[1]??'')==='--worker')provider_concurrency_worker();

/** Release all ready workers together, then collect every actual process result. */
function provider_concurrency_race(array $inputs,array $context): array
{
    if(!function_exists('proc_open'))throw new RuntimeException('Concurrency acceptance requires proc_open; no race behavior was verified.');
    $workers=[];
    try{
        foreach($inputs as $input){
            $runtime=getenv('PHP_BINARY')?:PHP_BINARY;
            $process=proc_open([$runtime,'-d','display_errors=stderr','-d','log_errors=0',__FILE__,'--worker'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
            if(!is_resource($process))throw new RuntimeException('Could not create the concurrent PHP worker.');
            stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
            fwrite($pipes[0],json_encode(['root'=>STONEFELLOW_ROOT,'input'=>$input,'context'=>$context],JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
            $workers[]=['process'=>$process,'pipes'=>$pipes,'stdout'=>'','stderr'=>'','ready'=>false,'exit'=>null];
        }
        $deadline=microtime(true)+15;
        while(true){
            $allReady=true;
            foreach($workers as &$worker){
                $worker['stdout'].=stream_get_contents($worker['pipes'][1]);$worker['stderr'].=stream_get_contents($worker['pipes'][2]);
                if(!$worker['ready']&&str_starts_with($worker['stdout'],"READY\n")){$worker['ready']=true;$worker['stdout']=substr($worker['stdout'],6);}
                if(!$worker['ready']){
                    $allReady=false;$status=proc_get_status($worker['process']);
                    if(!$status['running'])throw new RuntimeException('A concurrency worker failed before the barrier: '.substr($worker['stdout'].$worker['stderr'],0,1500));
                }
            }
            unset($worker);
            if($allReady)break;
            if(microtime(true)>$deadline)throw new RuntimeException('Concurrent worker startup exceeded its 15-second deadline.');
            usleep(10000);
        }
        foreach($workers as &$worker){fwrite($worker['pipes'][0],"GO\n");fclose($worker['pipes'][0]);$worker['pipes'][0]=null;}unset($worker);
        $deadline=microtime(true)+25;
        while(true){
            $complete=true;
            foreach($workers as &$worker){
                $worker['stdout'].=stream_get_contents($worker['pipes'][1]);$worker['stderr'].=stream_get_contents($worker['pipes'][2]);
                if($worker['exit']!==null)continue;
                $status=proc_get_status($worker['process']);
                if($status['running'])$complete=false;else $worker['exit']=$status['exitcode'];
            }
            unset($worker);
            if($complete)break;
            if(microtime(true)>$deadline)throw new RuntimeException('Concurrent playback exceeded its 25-second deadline.');
            usleep(10000);
        }
        $results=[];
        foreach($workers as &$worker){
            $worker['stdout'].=stream_get_contents($worker['pipes'][1]);$worker['stderr'].=stream_get_contents($worker['pipes'][2]);
            $result=json_decode(trim($worker['stdout']),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($result)||!array_key_exists('ok',$result)||$worker['stderr']!=='')throw new RuntimeException('Concurrency worker produced unexpected output.');
            if($worker['exit']!==($result['ok']?0:1))throw new RuntimeException('Concurrency worker exit/result disagreed.');
            $results[]=$result;
        }
        unset($worker);return $results;
    }finally{
        foreach($workers as $worker){
            $status=proc_get_status($worker['process']);if($status['running'])proc_terminate($worker['process']);
            foreach($worker['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);
            proc_close($worker['process']);
        }
    }
}

function provider_concurrency_same(array $input,array $context): array
{
    $results=provider_concurrency_race([$input,$input,$input],$context);
    foreach($results as $result){
        if(($result['ok']??false)!==true)throw new RuntimeException('Concurrent identical request failed: '.json_encode($result));
        provider_playback_test_equal($result['receipt'],$results[0]['receipt'],'Concurrent requests did not return the same frozen receipt');
    }
    return $results[0]['receipt'];
}

function provider_publication_worker(array $job): array
{
    $process=proc_open([getenv('PHP_BINARY')?:PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',__FILE__,'--worker'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if(!is_resource($process))throw new RuntimeException('Publication race worker could not start.');
    stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    fwrite($pipes[0],json_encode(['root'=>STONEFELLOW_ROOT]+$job,JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
    return ['process'=>$process,'pipes'=>$pipes,'stdout'=>'','stderr'=>''];
}

function provider_publication_line(array &$worker,string $expected): void
{
    $deadline=microtime(true)+20;
    while(true){
        $worker['stdout'].=stream_get_contents($worker['pipes'][1]);$worker['stderr'].=stream_get_contents($worker['pipes'][2]);
        $newline=strpos($worker['stdout'],"\n");
        if($newline!==false){
            $line=substr($worker['stdout'],0,$newline);$worker['stdout']=substr($worker['stdout'],$newline+1);
            if($line!==$expected)throw new RuntimeException('Publication barrier returned unexpected fixture output: '.substr($line,0,300));
            return;
        }
        if(!proc_get_status($worker['process'])['running'])throw new RuntimeException('Publication worker ended before its barrier.');
        if(microtime(true)>$deadline)throw new RuntimeException('Publication barrier exceeded its 20-second deadline.');
        usleep(10000);
    }
}

function provider_publication_finish(array &$worker): array
{
    $deadline=microtime(true)+20;
    while(true){
        $worker['stdout'].=stream_get_contents($worker['pipes'][1]);$worker['stderr'].=stream_get_contents($worker['pipes'][2]);
        $status=proc_get_status($worker['process']);
        if(!$status['running'])break;
        if(microtime(true)>$deadline)throw new RuntimeException('Publication worker exceeded its 20-second completion deadline.');
        usleep(10000);
    }
    $worker['stdout'].=stream_get_contents($worker['pipes'][1]);$worker['stderr'].=stream_get_contents($worker['pipes'][2]);
    if($worker['stderr']!=='')throw new RuntimeException('Publication worker emitted a runtime diagnostic.');
    $result=json_decode(trim($worker['stdout']),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($result)||!isset($result['ok'])||$status['exitcode']!==($result['ok']?0:1))throw new RuntimeException('Publication worker result disagreed with its exit code.');
    return $result;
}

/** Deterministic InnoDB publication race, including the real canonical writer. */
function provider_concurrency_publication(PDO $pdo,array $site,string $recording,int $clock): void
{
    $workers=[];
    try{
        $begin=provider_playback_test_begin($site,$recording);
        $context=['now_ms'=>$clock,'origin'=>$site['origin'],'site_id'=>$site['site_id'],'playback_key'=>$begin['playback_key']];
        $workers['begin']=provider_publication_worker(['input'=>$begin,'context'=>$context,'barriers'=>['insert'=>true]]);
        provider_publication_line($workers['begin'],'READY');fwrite($workers['begin']['pipes'][0],"GO\n");fflush($workers['begin']['pipes'][0]);
        provider_publication_line($workers['begin'],'BEFORE_INSERT');
        // The actual grant transaction must already hold the same mutex as the
        // canonical writer. NOWAIT proves this with a second native connection.
        $pdo->beginTransaction();$blocked=false;
        try{$q=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE id=1 FOR UPDATE NOWAIT');$q->execute();$q->closeCursor();}
        catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==3572)throw $error;$blocked=true;}
        finally{if($pdo->inTransaction())$pdo->rollBack();}
        provider_playback_test_equal($blocked,true,'Grant insertion did not hold the canonical publication mutex');
        $workers['withdraw']=provider_publication_worker(['operation'=>'catalog','published'=>false,'barriers'=>['workspace'=>true]]);
        provider_publication_line($workers['withdraw'],'READY');fwrite($workers['withdraw']['pipes'][0],"GO\n");fflush($workers['withdraw']['pipes'][0]);
        provider_publication_line($workers['withdraw'],'WORKSPACE');
        fwrite($workers['begin']['pipes'][0],"CONTINUE\n");fflush($workers['begin']['pipes'][0]);
        $allocated=provider_publication_finish($workers['begin']);$withdrawn=provider_publication_finish($workers['withdraw']);
        provider_playback_test_equal($allocated['ok'],true,'Begin-first transaction failed');provider_playback_test_equal($withdrawn['ok'],true,'Concurrent canonical withdrawal failed');
        $receipt=$allocated['receipt'];
        provider_playback_test_equal(provider_playback_test_session($receipt['session_id'])['state'],'revoked','Canonical withdrawal missed the concurrent insertion');
        provider_concurrency_catalog($pdo,true);
        provider_playback_test_reject(static fn()=>music_provider_v1_ticket_context($pdo,provider_playback_test_ticket($receipt),['now_ms'=>$clock+1]),404,'Republish revived the concurrently withdrawn grant');

        // Reverse order: withdrawal owns the mutex while a new begin arrives.
        // The later begin must see committed publication, not an earlier RR view.
        $pdo->beginTransaction();provider_concurrency_catalog($pdo,false);
        $lateBegin=provider_playback_test_begin($site,$recording);$context['playback_key']=$lateBegin['playback_key'];
        $workers['late']=provider_publication_worker(['input'=>$lateBegin,'context'=>$context,'barriers'=>['workspace'=>true]]);
        provider_publication_line($workers['late'],'READY');fwrite($workers['late']['pipes'][0],"GO\n");fflush($workers['late']['pipes'][0]);
        provider_publication_line($workers['late'],'WORKSPACE');$pdo->commit();
        $late=provider_publication_finish($workers['late']);
        provider_playback_test_equal($late['ok'],false,'Withdrawal-first transaction allowed a new grant');provider_playback_test_equal($late['status'],404,'Withdrawal-first unavailable status');
        $q=$pdo->prepare('SELECT COUNT(*) FROM music_provider_sessions_v1 WHERE site_id=? AND play_id=?');$q->execute([$site['site_id'],$lateBegin['play_id']]);
        provider_playback_test_equal((int)$q->fetchColumn(),0,'Withdrawal-first denial inserted a latent grant');
        provider_concurrency_catalog($pdo,true);
        provider_playback_test_reject(static fn()=>music_provider_v1_ticket_context($pdo,provider_playback_test_ticket($receipt),['now_ms'=>$clock+2]),404,'Repeated publication revived an old grant');
        echo "MUSIC_PROVIDER_PUBLICATION_RACE_MYSQL_V1=PASS real native connections and canonical catalog writes: workspace mutex held before INSERT, begin-first withdrawal revokes new grant, withdrawal-first begin denies after waiting, republish never revives\n";
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        foreach($workers as $worker){$status=proc_get_status($worker['process']);if($status['running'])proc_terminate($worker['process']);foreach($worker['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($worker['process']);}
    }
}

$pdo=provider_concurrency_mysql();
if($pdo&&(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Refusing concurrency fixture: use an empty disposable test database.');
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-provider-concurrency-'.bin2hex(random_bytes(6)));
if(!mkdir(STONEFELLOW_ROOT,0700,true))throw new RuntimeException('Cannot create isolated concurrency files.');
define('MUSIC_PROVIDER_PLAYBACK_TEST_LIBRARY_ONLY',true);
if($pdo)$GLOBALS['musicSongTestConnection']=$pdo;else putenv('MUSIC_LISTENING_TEST_DB='.STONEFELLOW_ROOT.'/data.sqlite');
require __DIR__.'/music-provider-playback-v1.php';
$pdo=db();$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
try{
    if($driver==='sqlite')$pdo->exec('PRAGMA busy_timeout=10000');
    music_listening_test_seed();music_song_metadata_v1_install($pdo);music_provider_v1_install($pdo);
    $clock=1800000000000;$recording=$pdo->query('SELECT recording_id FROM music_recordings_v1 WHERE catalog_track_id=10')->fetchColumn();
    $site=provider_playback_test_site($clock,[$recording]);$begin=provider_playback_test_begin($site,$recording);
    $context=['now_ms'=>$clock,'site_id'=>$site['site_id'],'origin'=>$site['origin'],'playback_key'=>$begin['playback_key']];
    $started=provider_concurrency_same($begin,$context);
    provider_playback_test_equal((int)$pdo->query('SELECT COUNT(*) FROM music_provider_sessions_v1')->fetchColumn(),1,'Concurrent begins duplicated the session');
    $resume=provider_playback_test_event($begin,$started,1);$context['now_ms']=$clock+1000;
    provider_concurrency_same($resume,$context);
    $heartbeat=provider_playback_test_event($begin,$started,2,['event_type'=>'heartbeat','elapsed_ms'=>11000,'cumulative_played_ms'=>10000,'position_ms'=>10000]);$context['now_ms']=$clock+11000;
    $heard=provider_concurrency_same($heartbeat,$context);
    provider_playback_test_equal($heard['accepted_played_ms'],10000,'Concurrent heartbeat multiplied accepted time');
    provider_playback_test_equal((int)$pdo->query("SELECT COUNT(*) FROM music_provider_receipts_v1 WHERE kind='event'")->fetchColumn(),2,'Concurrent events duplicated receipts');
    $renew=['protocol'=>'vp3_music_provider_v1','action'=>'playback.renew','site_id'=>$site['site_id'],'session_id'=>$started['session_id'],'request_id'=>music_song_metadata_v1_uuid()];$context['now_ms']=$clock+300001;
    $renewed=provider_concurrency_same($renew,$context);
    provider_playback_test_equal($renewed['accepted_played_ms'],10000,'Concurrent renewal changed listening');
    provider_playback_test_equal($renewed['accepted_seq'],2,'Concurrent renewal reset sequence');
    provider_playback_test_equal((int)$pdo->query("SELECT COUNT(*) FROM music_provider_receipts_v1 WHERE kind='renew'")->fetchColumn(),1,'Concurrent renewal duplicated receipt');
    // Two valid but conflicting requests race for one new logical play key.
    $conflictBegin=provider_playback_test_begin($site,$recording);$context['playback_key']=$conflictBegin['playback_key'];
    $conflict=provider_concurrency_race([$conflictBegin,array_replace($conflictBegin,['position_ms'=>1000])],$context);
    $accepted=array_values(array_filter($conflict,static fn(array $result):bool=>$result['ok']===true));
    $denied=array_values(array_filter($conflict,static fn(array $result):bool=>$result['ok']===false));
    provider_playback_test_equal(count($accepted),1,'Conflicting concurrent requests both won');provider_playback_test_equal(count($denied),1,'Conflicting request was not rejected');
    provider_playback_test_equal($denied[0]['status'],409,'Conflicting concurrent request status');
    provider_playback_test_equal($denied[0]['code'],'play_conflict','Conflicting concurrent request code');
    provider_playback_test_equal((int)$pdo->query('SELECT COUNT(*) FROM music_provider_sessions_v1')->fetchColumn(),2,'Conflicting concurrent begin created extra sessions');
    provider_playback_test_equal((int)$pdo->query('SELECT COUNT(*) FROM track_play_sessions')->fetchColumn(),1,'Concurrent provider use wrote native personal history');
    if($driver==='mysql')provider_concurrency_publication($pdo,$site,$recording,$clock+300010);
    echo 'MUSIC_PROVIDER_CONCURRENCY_V1=PASS '.$driver." actual separate-process PDO races: three identical begin/resume/heartbeat/renew requests, one frozen receipt/interval, conflicting begin has one winner, native history unchanged\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    if($driver==='mysql'){
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try{
            $tables=$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
            foreach(array_reverse($tables) as $table){if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$table))throw new RuntimeException('Unexpected concurrency table.');$pdo->exec('DROP TABLE `'.$table.'`');}
        }finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
    }
    music_listening_test_clean_files(STONEFELLOW_ROOT);
}
