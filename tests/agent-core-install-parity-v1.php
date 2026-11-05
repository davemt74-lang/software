<?php
declare(strict_types=1);

/** Execute the canonical core installers in the real setup/upgrade call order. */
$root=dirname(__DIR__);
$installers=[
    'agent_work_control_ensure_schema_v173'=>'agent_work_control_schema_ready_v173',
    'vp3_cognitive_ensure_schema_v500'=>'vp3_cognitive_schema_ready_v500',
    'vp3_cognitive_presentation_ensure_schema_v510'=>'vp3_cognitive_presentation_schema_ready_v510',
    'vp3_cognitive_feed_ensure_schema_v530'=>'vp3_cognitive_feed_schema_ready_v530',
    'vp3_cognitive_learning_ensure_schema_v540'=>'vp3_cognitive_learning_schema_ready_v540',
    'vp3_cognitive_planning_ensure_schema_v550'=>'vp3_cognitive_planning_schema_ready_v550',
    'vp3_cognitive_orchestration_ensure_schema_v560'=>'vp3_cognitive_orchestration_schema_ready_v560',
    'vp3_cognitive_memory_ensure_schema_v570'=>'vp3_cognitive_memory_schema_ready_v570',
];
$sequence=static function(string $file)use($installers):array{
    preg_match_all('/\b('.implode('|',array_map('preg_quote',array_keys($installers))).')\s*\(\s*\$pdo\s*\)/',
        (string)file_get_contents($file),$matches);
    return $matches[1];
};
$setup=$sequence($root.'/setup.php');$upgrade=$sequence($root.'/upgrade.php');
$checks=0;
function install_check(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
    $GLOBALS['checks']++;echo 'PASS '.$message.PHP_EOL;
}
install_check($upgrade===array_keys($installers),'upgrade defines one ordered canonical core foundation');
install_check($setup===$upgrade,'fresh setup installs the complete core foundation in canonical upgrade order');
$setupSource=(string)file_get_contents($root.'/setup.php');
install_check(strpos($setupSource,'agent_job_engine_ensure_schema_v1900($pdo)')<strpos($setupSource,'agent_work_control_ensure_schema_v173($pdo)'),
    'durable jobs are installed before work controls');

require_once $root.'/includes/agent-work-control-v173.php';
foreach(['cognitive-runtime-v500','cognitive-presentation-v510','cognitive-feed-v530','cognitive-learning-v540',
    'cognitive-planning-v550','cognitive-orchestration-v560','cognitive-memory-v570'] as $file){require_once $root.'/includes/'.$file.'.php';}

class CoreInstallPDO extends PDO
{
    public function exec(string $sql):int|false
    {
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            // Dialect adaptation only. The production installers supply all
            // columns, indexes, foreign keys and their dependency order.
            $sql=preg_replace('/\b(?:BIGINT|INT)\s+UNSIGNED\s+(?:NOT NULL\s+)?AUTO_INCREMENT\b/i','INTEGER',$sql);
            $sql=preg_replace('/\s+UNSIGNED\b|\s+ON UPDATE CURRENT_TIMESTAMP|\s+AFTER\s+[a-z_0-9]+/i','',$sql);
            $sql=preg_replace('/UNIQUE KEY\s+[a-z_0-9]+\s*\(/i','UNIQUE (',$sql);
            $sql=preg_replace('/,\s*(?:INDEX|KEY)\s+[a-z_0-9]+\s*\([^)]*\)/i','',$sql);
            $sql=preg_replace('/\s+ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\s*$/i','',$sql);
        }
        return parent::exec($sql);
    }
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $sql=preg_replace('/SHOW INDEX FROM `?([a-z_0-9]+)`? WHERE Key_name=\?/i',
                "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='$1' AND name=?",$sql);
        }
        return parent::prepare($sql,$options);
    }
}
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool
{
    $query=db()->prepare(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ?"SELECT name FROM sqlite_master WHERE type='table' AND name=?"
        :'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $query->execute([$table]);return(bool)$query->fetchColumn();
}
function column_exists(string $table,string $column):bool
{
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);
    $query=db()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $query->execute([$table,$column]);return(bool)$query->fetchColumn();
}
function inventory(PDO $pdo):array
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        return $pdo->query("SELECT name,sql FROM sqlite_master WHERE type IN ('table','index') AND name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll();
    }
    $result=[];
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $result[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    }
    ksort($result);return $result;
}

$serverDsn=(string)getenv('VP3_SECTION7_MYSQL_DSN');$server=null;$databases=[];$inventories=[];
try{
    if($serverDsn!==''){
        if(!str_starts_with($serverDsn,'mysql:'))throw new RuntimeException('The MySQL acceptance DSN must use mysql.');
        $serverDsn=preg_replace('/;dbname=[^;]*/','',$serverDsn);
        $server=new PDO($serverDsn,'root',(string)getenv('VP3_SECTION7_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
    foreach(['fresh'=>$setup,'upgraded'=>$upgrade] as $kind=>$calls){
        if($server){
            $name='vp3_core_install_'.bin2hex(random_bytes(6));$databases[]=$name;
            $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo=new CoreInstallPDO($serverDsn.';dbname='.$name,'root',(string)getenv('VP3_SECTION7_MYSQL_PASSWORD'));
        }else{$pdo=new CoreInstallPDO('sqlite::memory:');$pdo->exec('PRAGMA foreign_keys=ON');}
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE users(id INT UNSIGNED NOT NULL PRIMARY KEY)');
        $pdo->exec('INSERT INTO users(id) VALUES(1),(2)');
        agent_job_engine_ensure_schema_v1900($pdo);
        install_check(!vp3_cognitive_presentation_schema_ready_v510($pdo),$kind.' historical baseline has no working presentation storage');
        $rejected=false;try{vp3_cognitive_orchestration_ensure_schema_v560($pdo);}catch(RuntimeException $e){$rejected=true;}
        install_check($rejected&&!table_exists('cognitive_plan_runs_v560'),$kind.' orchestration refuses to install before its planning dependency');
        foreach($calls as $install)$install($pdo);
        foreach($installers as $install=>$ready){install_check($ready($pdo),$kind.' is immediately ready: '.$ready);}
        $inventories[$kind]=inventory($pdo);
        $pdo->exec("INSERT INTO cognitive_presentation_state_v510(owner_user_id,agent_namespace,last_meaningful_at,baseline_notification_id,last_voice_notification_id,last_seen_at)
            VALUES(1,'system','2026-11-01 10:00:00',11,12,'2026-11-01 10:00:00'),(1,'agent:7','2026-11-02 10:00:00',21,22,'2026-11-02 10:00:00'),(2,'system','2026-11-03 10:00:00',31,32,'2026-11-03 10:00:00')");
        $saved=$pdo->query('SELECT * FROM cognitive_presentation_state_v510 ORDER BY owner_user_id,agent_namespace')->fetchAll();
        foreach($calls as $install)$install($pdo);
        install_check(inventory($pdo)===$inventories[$kind]&&$saved===$pdo->query('SELECT * FROM cognitive_presentation_state_v510 ORDER BY owner_user_id,agent_namespace')->fetchAll(),
            $kind.' rerun preserves schema and exact owner/Agent presentation cursors');
        $invalidOwner=false;
        try{$pdo->exec("INSERT INTO cognitive_feed_item_state_v530(owner_user_id,agent_namespace,item_key) VALUES(999,'system','invalid-owner')");}
        catch(PDOException $e){$invalidOwner=true;}
        install_check($invalidOwner,$kind.' production foreign keys reject an unknown owner');
        $pdo->exec('DELETE FROM users WHERE id=2');
        install_check((int)$pdo->query('SELECT COUNT(*) FROM cognitive_presentation_state_v510 WHERE owner_user_id=2')->fetchColumn()===0
            &&(int)$pdo->query('SELECT COUNT(*) FROM cognitive_presentation_state_v510 WHERE owner_user_id=1')->fetchColumn()===2,
            $kind.' owner deletion cascades only that owner’s rows');
    }
    install_check($inventories['fresh']===$inventories['upgraded'],'fresh setup and canonical upgrade produce identical core schema inventories');
    echo 'AGENT_CORE_INSTALL_PARITY_V1=PASS ('.$checks.' cases; '.($server?'native MySQL':'SQLite DDL adapter with real foreign keys').')'.PHP_EOL;
}finally{
    if($server)foreach($databases as $name)$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');
}
