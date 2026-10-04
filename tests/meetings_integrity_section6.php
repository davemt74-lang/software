<?php
declare(strict_types=1);
// Run canonical presence SQL; SQLite adapts dialect, not InnoDB locking semantics.
final class MeetingSQLite extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','UTC_TIMESTAMP()'],['','CURRENT_TIMESTAMP'],$query),$options);
    }
}
$dsn=(string)(getenv('VP3_SECTION6_MYSQL_DSN')?:'');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$pdo=$dsn!==''?new PDO($dsn,'root',(string)getenv('VP3_SECTION6_MYSQL_PASSWORD'),$options):new MeetingSQLite('sqlite::memory:',null,null,$options);
$notifications=[];
function create_notification(...$args): void {global $notifications;$notifications[]=$args;}
function url(string $path): string {return $path;}
function table_exists(string $table): bool {return true;}
function column_exists(string $table,string $column): bool {return true;}
require __DIR__.'/../includes/video-meetings-v1800.php';
function check(bool $value,string $name): void {if(!$value)throw new LogicException($name);echo 'PASS '.$name."\n";}
function rejects(callable $run): void {try{$run();}catch(RuntimeException $error){return;}throw new LogicException('expected rejection');}
function access(int $id,bool $owner=false): array {return ['meeting'=>['id'=>1,'owner_user_id'=>1,'status'=>'ready'],'participant'=>['id'=>$id],'is_organizer'=>$owner];}
$config=['livekit'=>[]];
if(($argv[1]??'')==='race'){
    echo "READY\n";fflush(STDOUT);fgets(STDIN);
    try{video_meeting_mark_presence_v1800($pdo,access(($argv[2]??'')==='end'?1:2,($argv[2]??'')==='end'),(string)$argv[2]);echo "DONE\n";}
    catch(RuntimeException $error){if(!str_contains($error->getMessage(),'closed'))throw $error;echo "CLOSED\n";}
    exit;
}
if($dsn!==''){$pdo->exec('DROP TABLE IF EXISTS video_meeting_participants');$pdo->exec('DROP TABLE IF EXISTS video_meetings');}
$pdo->exec("CREATE TABLE video_meetings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,public_id TEXT,title TEXT,status TEXT,started_at TEXT,ended_at TEXT);
INSERT INTO video_meetings VALUES(1,1,'public','Meeting','ready',NULL,NULL);
CREATE TABLE video_meeting_participants(id INTEGER PRIMARY KEY,meeting_id INTEGER,role TEXT,display_name TEXT,invitation_status TEXT,attendance_status TEXT,accepted_at TEXT,joined_at TEXT,left_at TEXT);
INSERT INTO video_meeting_participants VALUES(1,1,'organizer','Owner','accepted','invited',NULL,NULL,NULL),(2,1,'attendee','Guest','invited','invited',NULL,NULL,NULL),(3,1,'attendee','Other','invited','invited',NULL,NULL,NULL);");
// LiveKit is unconfigured in this fixture, so the real delete function returns
// without network. Read the real SQL instead of substituting service behavior.
$first=video_meeting_mark_presence_v1800($pdo,access(2),'join');
check($first['status']==='live'&&$first['_presence_changed']&&count($notifications)===1,'join makes canonical meeting live and notifies once');
$again=video_meeting_mark_presence_v1800($pdo,access(2),'join');
check(!$again['_presence_changed']&&count($notifications)===1,'repeat join is idempotent');
$read=video_meeting_mark_presence_v1800($pdo,access(3),'status');
check(!$read['_presence_changed']&&$pdo->query('SELECT attendance_status FROM video_meeting_participants WHERE id=3')->fetchColumn()==='invited','status does not invent attendance');
$left=video_meeting_mark_presence_v1800($pdo,access(2),'leave');$leftAt=$pdo->query('SELECT left_at FROM video_meeting_participants WHERE id=2')->fetchColumn();
$repeat=video_meeting_mark_presence_v1800($pdo,access(2),'leave');
check($left['_presence_changed']&&!$repeat['_presence_changed']&&$pdo->query('SELECT left_at FROM video_meeting_participants WHERE id=2')->fetchColumn()===$leftAt,'repeat leave preserves departure');
video_meeting_mark_presence_v1800($pdo,access(2),'join');
check($pdo->query('SELECT left_at FROM video_meeting_participants WHERE id=2')->fetchColumn()===null,'re-entry clears departure');
rejects(fn()=>video_meeting_mark_presence_v1800($pdo,access(2,true),'end'));
check(!$pdo->inTransaction()&&$pdo->query('SELECT status FROM video_meetings')->fetchColumn()==='live','attendee cannot end by setting organizer flag');
$pdo->exec("UPDATE video_meeting_participants SET invitation_status='revoked' WHERE id=3");
rejects(fn()=>video_meeting_mark_presence_v1800($pdo,access(3),'join'));
check(!$pdo->inTransaction(),'current revocation rejects stale access and releases transaction');
video_meeting_mark_presence_v1800($pdo,access(1,true),'join');
$ended=video_meeting_mark_presence_v1800($pdo,access(1,true),'end');
check($ended['status']==='ended'&&(int)$pdo->query("SELECT COUNT(*) FROM video_meeting_participants WHERE attendance_status='joined'")->fetchColumn()===0,'end closes all joined attendance');
rejects(fn()=>video_meeting_mark_presence_v1800($pdo,access(2),'join'));
check(!$pdo->inTransaction()&&$pdo->query('SELECT status FROM video_meetings')->fetchColumn()==='ended','late join cannot reopen ended meeting');
$endedAt=$pdo->query('SELECT ended_at FROM video_meetings')->fetchColumn();
$repeat=video_meeting_mark_presence_v1800($pdo,access(1,true),'end');
check(!$repeat['_presence_changed']&&$pdo->query('SELECT ended_at FROM video_meetings')->fetchColumn()===$endedAt,'end is idempotent');
$pdo->exec("UPDATE video_meetings SET status='processed'");
video_meeting_mark_presence_v1800($pdo,access(1,true),'end');
check($pdo->query('SELECT status FROM video_meetings')->fetchColumn()==='processed','repeat end preserves processed state');
$pdo->exec("UPDATE video_meetings SET status='cancelled'");
rejects(fn()=>video_meeting_mark_presence_v1800($pdo,access(2),'join'));
video_meeting_mark_presence_v1800($pdo,access(1,true),'end');
check($pdo->query('SELECT status FROM video_meetings')->fetchColumn()==='cancelled','cancelled meeting cannot reopen or become ended');
echo "MEETINGS_INTEGRITY_SECTION6=PASS (12 canonical service cases)\n";
if($dsn!==''){
    // Independent PHP processes contend on the real InnoDB meeting row. The
    // final state must be closed regardless of which request acquires it first.
    for($round=0;$round<10;$round++){
        $pdo->exec("UPDATE video_meetings SET status='live',ended_at=NULL;UPDATE video_meeting_participants SET invitation_status='accepted',attendance_status='invited',left_at=NULL");
        $workers=[];
        foreach(['join','end','join'] as $action){
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'race',$action],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new LogicException('race worker did not start');
            stream_set_timeout($pipes[1],15);
            if(trim((string)fgets($pipes[1]))!=='READY')throw new LogicException('race worker not ready: '.stream_get_contents($pipes[2]));
            $workers[]=[$process,$pipes];
        }
        foreach($workers as [$process,$pipes]){fwrite($pipes[0],"START\n");fclose($pipes[0]);}
        foreach($workers as [$process,$pipes]){
            $answer=trim((string)fgets($pipes[1]));$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0||!in_array($answer,['DONE','CLOSED'],true))throw new LogicException('race failed: '.$errors);
        }
        if($pdo->query('SELECT status FROM video_meetings')->fetchColumn()!=='ended'||(int)$pdo->query("SELECT COUNT(*) FROM video_meeting_participants WHERE attendance_status='joined'")->fetchColumn()!==0)throw new LogicException('concurrent join reopened an ended meeting');
    }
    check(true,'10 real InnoDB parallel join/end races preserve closure and departed attendance');
}
