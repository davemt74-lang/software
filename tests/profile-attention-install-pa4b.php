<?php
declare(strict_types=1);

/** Real legacy Profile rows, production PA4B DDL, and the worker's failure boundary. */
require_once __DIR__.'/fixtures/profile-handoff-pa3.php';
pa3_reset();
if(!column_exists('notifications','read_at'))$pdo->exec('ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL');
$checks=0;
function pai_check(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['checks']++;echo 'PASS '.$message.PHP_EOL;
}
$tablesBefore=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
    ?$pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
    :$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
require_once dirname(__DIR__).'/includes/profile-attention-pa4b.php';
require_once dirname(__DIR__).'/includes/profile-event-projection-pa4b.php';
$tablesAfter=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
    ?$pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
    :$pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
pai_check($tablesBefore===$tablesAfter&&!vp3_profile_attention_schema_ready_pa4b($pdo)&&!vp3_profile_projection_schema_ready_pa4b($pdo),'loading optional recovery modules does not install or mutate schema');

// One exact read notification, plus foreign-owner and unrelated-source decoys.
foreach([1=>'seen',2=>'handled',3=>'ignored',4=>'snoozed',5=>'pending'] as $id=>$state){
    $pdo->prepare('INSERT INTO profile_events(id,owner_user_id,event_type,priority,metadata_json) VALUES(?,1,\'profile_view\',10,\'{}\')')->execute([$id]);
    $pdo->prepare('INSERT INTO agent_attention_items(id,owner_user_id,source_event_id,attention_type,status,headline,summary,snoozed_until) VALUES(?,1,?,\'profile_view\',?,\'Saved headline\',\'Saved summary\',?)')->execute([$id,$id,$state,$state==='snoozed'?'2099-01-01 00:00:00':null]);
}
$pdo->exec("INSERT INTO notifications(user_id,type,title,body,source_type,source_id,is_read,read_at) VALUES(1,'profile_view','Exact read','Saved','profile_event',1,1,'2026-01-02 03:04:05'),(4,'profile_view','Foreign read','Saved','profile_event',2,1,NULL),(1,'other','Wrong source','Saved','unrelated',3,1,NULL),(1,'profile_view','Unread','Saved','profile_event',4,0,NULL)");
$before=$pdo->query('SELECT id,owner_user_id,source_event_id,status,headline,summary,snoozed_until FROM agent_attention_items ORDER BY id')->fetchAll();
$notesBefore=$pdo->query('SELECT * FROM notifications ORDER BY id')->fetchAll();
final class PAIInterruptedInstallPDO extends PDO
{
    public bool $interrupt=true;
    public function exec(string $sql): int|false
    {
        $result=parent::exec($sql);
        if($this->interrupt&&preg_match('/ALTER TABLE agent_attention_items ADD COLUMN read_generation\b/i',$sql)){
            $this->interrupt=false;
            throw new RuntimeException('Fixture interruption after committed read field DDL.');
        }
        return $result;
    }
}
$cfg=$GLOBALS['pa3Config'];
$resume=new PAIInterruptedInstallPDO($cfg['dsn'],$cfg['user']??null,$cfg['password']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    // Separate in-memory connection gets the real pre-upgrade fixture rows.
    foreach(['agent_attention_items','notifications'] as $table){
        $q=$pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);$resume->exec((string)$q->fetchColumn());
        foreach($pdo->query('SELECT * FROM '.$table)->fetchAll() as $record){$resume->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($record)).') VALUES('.implode(',',array_fill(0,count($record),'?')).')')->execute(array_values($record));}
    }
}
$interrupted=false;try{vp3_profile_attention_ensure_schema_pa4b($resume);}catch(RuntimeException $e){$interrupted=str_contains($e->getMessage(),'Fixture interruption');}
pai_check($interrupted&&vp3_profile_attention_has_column_pa4b($resume,'agent_attention_items','read_generation'),'interruption can leave the read field committed before legacy receipt backfill');
vp3_profile_attention_ensure_schema_pa4b($resume);
pai_check(array_map('intval',$resume->query('SELECT read_generation FROM agent_attention_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))===[1,0,0,0,0],'rerunning interrupted installation resumes exact legacy read backfill');
vp3_profile_attention_ensure_schema_pa4b($pdo);
vp3_profile_projection_ensure_schema_pa4b($pdo);
pai_check(vp3_profile_attention_schema_ready_pa4b($pdo)&&vp3_profile_projection_schema_ready_pa4b($pdo),'real installation creates complete attention and projection storage');
pai_check($pdo->query('SELECT id,owner_user_id,source_event_id,status,headline,summary,snoozed_until FROM agent_attention_items ORDER BY id')->fetchAll()===$before,'installation preserves terminal states, snooze deadline and saved attention content');
pai_check($pdo->query('SELECT * FROM notifications ORDER BY id')->fetchAll()===$notesBefore,'installation preserves existing notification receipts');
$rows=$pdo->query('SELECT id,state_revision,wake_generation,read_generation FROM agent_attention_items ORDER BY id')->fetchAll();
pai_check(array_map(static fn(array $r):int=>(int)$r['read_generation'],$rows)===[1,0,0,0,0],'legacy read migration requires the exact owner and Profile event source');
pai_check(array_unique(array_map(static fn(array $r):int=>(int)$r['state_revision'],$rows))===[1]&&array_unique(array_map(static fn(array $r):int=>(int)$r['wake_generation'],$rows))===[1],'legacy lifecycle starts with one explicit state and wake generation');

$pdo->exec('UPDATE agent_attention_items SET read_generation=0,state_revision=4,wake_generation=3 WHERE id=1');
$saved=$pdo->query('SELECT * FROM agent_attention_items ORDER BY id')->fetchAll();
vp3_profile_attention_ensure_schema_pa4b($pdo);vp3_profile_projection_ensure_schema_pa4b($pdo);
pai_check($pdo->query('SELECT * FROM agent_attention_items ORDER BY id')->fetchAll()===$saved,'repeated installation does not reapply legacy read migration or reset generations');
$pdo->beginTransaction();$refused=0;
foreach(['vp3_profile_attention_ensure_schema_pa4b','vp3_profile_projection_ensure_schema_pa4b'] as $install){try{$install($pdo);}catch(RuntimeException $e){$refused++;}}
pai_check($refused===2&&$pdo->inTransaction(),'installers refuse caller-owned transactions without committing them');$pdo->rollBack();

foreach([['profile_attention_deliveries_pa4b','source_hash','vp3_profile_attention_schema_ready_pa4b'],['profile_event_projections_pa4b','result_hash','vp3_profile_projection_schema_ready_pa4b']] as [$table,$column,$ready]){
    $pdo->exec('ALTER TABLE '.$table.' RENAME COLUMN '.$column.' TO incomplete_'.$column);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('PRAGMA query_only=ON');else $pdo->exec('START TRANSACTION READ ONLY');
    pai_check(!$ready($pdo),'partial '.$table.' is detected by a pure readiness read');
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('PRAGMA query_only=OFF');else $pdo->commit();
    $pdo->exec('ALTER TABLE '.$table.' RENAME COLUMN incomplete_'.$column.' TO '.$column);
}

// Execute the production worker helper, without starting its CLI loop or unrelated jobs.
$worker=(string)file_get_contents(dirname(__DIR__).'/agent-worker-v1910.php');
$start=strpos($worker,'function agent_worker_profile_attention_maintenance_v1910(');
$end=strpos($worker,'function agent_worker_runner_cycle_v1910(',$start);
if($start===false||$end===false)throw new RuntimeException('Production attention maintenance is missing.');
$helper=substr($worker,$start,$end-$start);
$helper=str_replace("__DIR__.'/includes/","dirname(__DIR__).'/includes/",$helper);eval($helper);
$pdo->exec('ALTER TABLE profile_attention_deliveries_pa4b RENAME COLUMN source_hash TO incomplete_source_hash');
$pdo->exec('ALTER TABLE profile_event_projections_pa4b RENAME COLUMN result_hash TO incomplete_result_hash');
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('PRAGMA query_only=ON');else $pdo->exec('START TRANSACTION READ ONLY');
$result=agent_worker_profile_attention_maintenance_v1910($pdo,1000);
pai_check(($result['attention']['reason']??null)==='attention_schema_unavailable'&&($result['projections']['reason']??null)==='projection_schema_unavailable','worker reports incomplete storage without executing DDL or pretending recovery completed');
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->exec('PRAGMA query_only=OFF');else $pdo->commit();
pai_check(pa3_count('agent_workflow_runs')===0&&$GLOBALS['pa3ModelCalls']===[],'installation and incomplete maintenance start no workflow or model action');
echo 'PROFILE_ATTENTION_INSTALL_PA4B=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).')'.PHP_EOL;
