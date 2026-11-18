<?php
declare(strict_types=1);

/** Native SQLite / MySQL fixture. Production SQL is never rewritten. */
final class ProfileEventClockStatementV1 extends PDOStatement
{
    protected function __construct(private ProfileEventClockPDOV1 $connection) {}
    public function execute(?array $params=null): bool
    {
        $this->connection->trace[] = ['execute',$this->queryString];
        if ($this->connection->beforeExecute) ($this->connection->beforeExecute)($this->queryString,$params,$this->connection);
        $ok = parent::execute($params);
        if ($this->connection->afterSql) ($this->connection->afterSql)($this->queryString,$params,$this->connection);
        return $ok;
    }
}

final class ProfileEventClockPDOV1 extends PDO
{
    public mixed $beforeSql = null;
    public mixed $beforeExecute = null;
    public mixed $afterSql = null;
    public array $trace = [];
    private function before(string $sql): void
    {
        $this->trace[] = ['read_or_prepare',$sql];
        if ($this->beforeSql) ($this->beforeSql)($sql,null,$this);
    }
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        $this->before($sql);
        return parent::prepare($sql,$options);
    }
    public function query(string $sql,?int $fetchMode=null,mixed ...$args): PDOStatement|false
    {
        $this->before($sql);
        $result = $fetchMode === null ? parent::query($sql) : parent::query($sql,$fetchMode,...$args);
        if ($this->afterSql) ($this->afterSql)($sql,null,$this);
        return $result;
    }
    public function exec(string $sql): int|false
    {
        $this->before($sql);
        $result = parent::exec($sql);
        if ($this->afterSql) ($this->afterSql)($sql,null,$this);
        return $result;
    }
}

function pclock_connect_v1(array $config): ProfileEventClockPDOV1
{
    $pdo = new ProfileEventClockPDOV1($config['dsn'],$config['user'],$config['pass'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ProfileEventClockStatementV1::class,[$pdo]]);
    if ($config['driver'] === 'mysql') {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
        $pdo->exec("SET time_zone='+00:00'");
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
    } else {
        $pdo->exec('PRAGMA busy_timeout=15000');
    }
    $pdo->trace = [];
    return $pdo;
}

function pclock_config_v1(): array
{
    $directory = sys_get_temp_dir().'/vp3_profile_clock_'.bin2hex(random_bytes(8));
    if (!mkdir($directory,0700)) throw new RuntimeException('Clock fixture directory is unavailable.');
    $mysql = getenv('VP3_TEST_MYSQL_DSN');
    $config = ['driver'=>$mysql?'mysql':'sqlite','dsn'=>'sqlite:'.$directory.'/clock.sqlite',
        'user'=>null,'pass'=>null,'directory'=>$directory,'database'=>null,'admin_dsn'=>null];
    if ($mysql) {
        $config['admin_dsn'] = $mysql;
        $config['user'] = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
        $config['pass'] = getenv('VP3_TEST_MYSQL_PASS') ?: 'root';
        $admin = new PDO($mysql,$config['user'],$config['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $database = 'vp3_profile_clock_'.bin2hex(random_bytes(6));
        $admin->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config['database'] = $database;
        $config['dsn'] = preg_match('/dbname=[^;]+/',$mysql)
            ? preg_replace('/dbname=[^;]+/','dbname='.$database,$mysql) : rtrim($mysql,';').';dbname='.$database;
    }
    return $config;
}

function pclock_cleanup_v1(array $config): void
{
    foreach ($GLOBALS['pclock_children_v1'] ?? [] as $child) {
        if (is_resource($child['process'])) {
            $state = proc_get_status($child['process']);
            if ($state['running']) proc_terminate($child['process']);
            proc_close($child['process']);
        }
    }
    if ($config['driver'] === 'mysql') {
        $admin = new PDO($config['admin_dsn'],$config['user'],$config['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        foreach ($GLOBALS['pclock_users_v1'] ?? [] as $user) $admin->exec('DROP USER IF EXISTS '.$admin->quote($user)."@'%'");
        $admin->exec('DROP DATABASE IF EXISTS '.$config['database']);
    }
    foreach (glob($config['directory'].'/*') ?: [] as $path) {
        if (is_dir($path)) {
            foreach (glob($path.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
            rmdir($path);
        } elseif (is_file($path)) unlink($path);
    }
    rmdir($config['directory']);
}

function pclock_schema_v1(ProfileEventClockPDOV1 $pdo,bool $columns=false,bool $pending=false): void
{
    $pdo->beforeSql = $pdo->beforeExecute = $pdo->afterSql = null;
    foreach (['profile_event_clock_activation_v1','profile_events','profile_visit_sessions','user_profiles','users'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS '.$table);
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $auto = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,display_name VARCHAR(100) NOT NULL,role VARCHAR(30) NOT NULL,
        is_active INTEGER NOT NULL,avatar_path VARCHAR(100) NOT NULL)".$tail);
    $pdo->exec("CREATE TABLE user_profiles (user_id INTEGER PRIMARY KEY,profile_agent_enabled INTEGER NOT NULL DEFAULT 0,
        profile_agent_id INTEGER NULL,is_public INTEGER NOT NULL DEFAULT 0)".$tail);
    $pdo->exec('CREATE TABLE profile_visit_sessions (id INTEGER PRIMARY KEY,owner_user_id INTEGER NOT NULL,visitor_user_id INTEGER NULL)'.$tail);
    $pdo->exec("CREATE TABLE profile_events (id $auto,owner_user_id INTEGER NOT NULL,profile_session_id INTEGER NULL,
        visitor_user_id INTEGER NULL,profile_agent_id INTEGER NULL,event_type VARCHAR(50) NOT NULL,priority INTEGER NOT NULL DEFAULT 10,
        dedupe_key CHAR(64) NULL UNIQUE,metadata_json TEXT NULL,"
        .($columns?'recorded_at_utc_ms BIGINT NULL,clock_legacy_token_v1 CHAR(32) NULL,':'')
        .'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)'.$tail);
    $pdo->exec("INSERT INTO users (id,display_name,role,is_active,avatar_path) VALUES (1,'Owner','member',1,''),(2,'Visitor','member',1,'')");
    $pdo->exec('INSERT INTO user_profiles (user_id) VALUES (1),(2)');
    $pdo->exec('INSERT INTO profile_visit_sessions (id,owner_user_id,visitor_user_id) VALUES (11,1,2),(12,1,1)');
    if ($pending) {
        $pdo->exec('CREATE TABLE profile_event_clock_activation_v1 (id SMALLINT NOT NULL PRIMARY KEY,contract VARCHAR(64) NOT NULL,
            token CHAR(32) NULL,activated_at_utc_ms BIGINT NULL)'.$tail);
        $pdo->prepare('INSERT INTO profile_event_clock_activation_v1 (id,contract,token,activated_at_utc_ms) VALUES (1,?,NULL,NULL)')
            ->execute(['vp3.profile-event-clock.v1']);
    }
    $pdo->trace = [];
}

function pclock_event_v1(PDO $pdo,int $id,array $extra=[]): void
{
    $row = array_replace(['id'=>$id,'owner_user_id'=>1,'profile_session_id'=>11,'visitor_user_id'=>2,
        'profile_agent_id'=>null,'event_type'=>'profile_view','priority'=>10,
        'dedupe_key'=>hash('sha256','clock-fixture-'.$id),'metadata_json'=>'[]'],$extra);
    $pdo->prepare('INSERT INTO profile_events ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')
        ->execute(array_values($row));
}

function pclock_row_v1(PDO $pdo,int $id): ?array
{
    $query = $pdo->prepare('SELECT * FROM profile_events WHERE id=?');
    $query->execute([$id]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pclock_wait_file_v1(string $path,float $seconds=15): void
{
    $until = microtime(true) + $seconds;
    do {
        clearstatcache(true,$path);
        if (is_file($path)) return;
        usleep(10000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Timed out waiting for the independent clock fixture.');
}

function pclock_start_v1(array $config,array $operation): array
{
    if (!function_exists('proc_open')) throw new RuntimeException('Independent-connection clock tests require proc_open.');
    $base = $config['directory'].'/worker_'.bin2hex(random_bytes(5));
    $payload = $base.'.json';
    $operation += ['gate'=>$base,'config'=>$config];
    file_put_contents($payload,json_encode($operation,JSON_THROW_ON_ERROR));
    chmod($payload,0600);
    $process = proc_open([PHP_BINARY,__DIR__.'/profile-event-clock-worker-v1.php',$payload],[
        0=>['file','/dev/null','r'],1=>['file',$base.'.out','w'],2=>['file',$base.'.err','w'],
    ],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Independent clock process could not start.');
    $child = ['process'=>$process,'gate'=>$operation['gate'],'base'=>$base];
    $GLOBALS['pclock_children_v1'][] = $child;
    return $child;
}

function pclock_finish_v1(array $child): array
{
    $until = microtime(true) + 20;
    do {
        $state = proc_get_status($child['process']);
        if (!$state['running']) break;
        usleep(10000);
    } while (microtime(true) < $until);
    if ($state['running']) {
        proc_terminate($child['process']);
        throw new RuntimeException('Independent clock process did not finish.');
    }
    $exit = $state['exitcode'];
    $closed = proc_close($child['process']);
    if ($exit < 0) $exit = $closed;
    $output = file_get_contents($child['base'].'.out');
    $errors = file_get_contents($child['base'].'.err');
    if ($exit !== 0 || trim($errors) !== '') throw new RuntimeException('Independent clock fixture failed: '.trim($errors).' '.trim($output));
    $result = json_decode($output,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($result)) throw new RuntimeException('Independent clock fixture returned invalid data.');
    return $result;
}
