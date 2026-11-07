<?php
declare(strict_types=1);

/** Real Commerce schema and transactions; only provider transport is replaced. */
final class PA2CommerceStatement extends PDOStatement
{
    protected function __construct(private PA2CommercePDO $connection) {}
    public function execute(?array $params=null): bool
    {
        $ok=parent::execute($params);
        if ($this->connection->afterSql) ($this->connection->afterSql)($this->queryString,$params,$this->connection);
        return $ok;
    }
}

final class PA2CommercePDO extends PDO
{
    public mixed $afterSql=null;
    public string $failSql='';
    public function sql(string $sql): string
    {
        if ($this->failSql!=='' && str_contains($sql,$this->failSql)) throw new PDOException('SECRET injected Commerce database diagnostic');
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite') return $sql;
        // SQLite is only a fast behavioral fixture. CI runs unmodified SQL on MySQL 8.
        $sql=preg_replace('/\ASHOW INDEX FROM `?(\w+)`? WHERE Key_name=\?\z/i',
            "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='$1' AND name=?",$sql);
        $sql=preg_replace('/\s+FOR UPDATE\b/i','',$sql);
        $sql=preg_replace('/\bINSERT IGNORE\b/i','INSERT OR IGNORE',$sql);
        $sql=preg_replace("/DATE_SUB\((?:UTC_TIMESTAMP|NOW)\(\),\s*INTERVAL (\d+) (DAY|HOUR|MINUTE|SECOND)\)/i","datetime('now','-$1 $2')",$sql);
        $sql=preg_replace("/DATE_ADD\((?:UTC_TIMESTAMP|NOW)\(\),\s*INTERVAL (\d+) (DAY|HOUR|MINUTE|SECOND)\)/i","datetime('now','+$1 $2')",$sql);
        if (preg_match('/^\s*(CREATE|ALTER) TABLE/i',$sql)) {
            $sql=preg_replace('/\s+ENGINE=InnoDB[^;]*/i','',$sql);
            $sql=preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP/i','',$sql);
            $sql=preg_replace('/\b(?:BIGINT|INT|INTEGER)\s+(?:UNSIGNED\s+)?AUTO_INCREMENT PRIMARY KEY/i','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
            $sql=preg_replace('/\s+UNSIGNED\b/i','',$sql);
            $sql=preg_replace('/\bUNIQUE KEY\s+\w+\s*/i','UNIQUE ',$sql);
            $sql=preg_replace('/^\s*(?:INDEX|KEY)\s+\w+\s*\([^\n]+\),?\s*$/mi','',$sql);
            $sql=preg_replace('/,\s*(?:INDEX|KEY)\s+\w+\s*\([^)]*\)/i','',$sql);
            $sql=preg_replace('/,\s*\)/',')',$sql);
            $sql=preg_replace('/\s+AFTER\s+`?\w+`?\s*$/i','',$sql);
        }
        return $sql;
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        return parent::prepare($this->sql($query),$options);
    }
    public function exec(string $statement): int|false
    {
        return parent::exec($this->sql($statement));
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query=$this->sql($query);
        return $fetchMode===null ? parent::query($query) : parent::query($query,$fetchMode,...$fetchModeArgs);
    }
}

function commerce_fixture_config_pa2(?string $sqliteFile=null): array
{
    $mysql=getenv('VP3_TEST_MYSQL_DSN');
    if (!$mysql) return ['dsn'=>'sqlite:'.($sqliteFile??':memory:'),'user'=>null,'pass'=>null,'driver'=>'sqlite'];
    $user=getenv('VP3_TEST_MYSQL_USER')?:'root';$pass=getenv('VP3_TEST_MYSQL_PASS')?:'root';
    $admin=new PDO($mysql,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_commerce_pa2_'.bin2hex(random_bytes(5));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn=preg_match('/dbname=[^;]+/',$mysql)?preg_replace('/dbname=[^;]+/','dbname='.$database,$mysql):rtrim($mysql,';').';dbname='.$database;
    return compact('dsn','user','pass','database')+['driver'=>'mysql','admin_dsn'=>$mysql];
}

function commerce_fixture_cleanup_pa2(array $config): void
{
    if (!isset($config['database'])) return;
    if (!preg_match('/^vp3_commerce_pa2_[a-f0-9]{10}$/',$config['database'])) throw new RuntimeException('Unsafe fixture database name.');
    $admin=new PDO($config['admin_dsn'],$config['user'],$config['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admin->exec('DROP DATABASE IF EXISTS `'.$config['database'].'`');
}

function commerce_fixture_connect_pa2(array $config): PA2CommercePDO
{
    $pdo=new PA2CommercePDO($config['dsn'],$config['user'],$config['pass'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PA2CommerceStatement::class,[$pdo]]);
    if ($config['driver']==='mysql') {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET time_zone='+00:00'");
    } else {
        $pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));
        $pdo->sqliteCreateFunction('GREATEST',fn(...$v)=>max($v));
        $pdo->sqliteCreateFunction('LEAST',fn(...$v)=>min($v));
        $pdo->sqliteCreateFunction('IF',fn($condition,$yes,$no)=>$condition?$yes:$no);
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    return $pdo;
}

function db(): PDO {return $GLOBALS['pdo'];}
function table_exists(string $table): bool
{
    $pdo=db();$query=$pdo->prepare($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        :"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);return (bool)$query->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    if (!preg_match('/^[a-z0-9_]+$/',$table)) return false;
    $pdo=db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        $query=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $query->execute([$table,$column]);return (bool)$query->fetchColumn();
    }
    foreach ($pdo->query('PRAGMA table_info('.$table.')')->fetchAll() as $row) if ($row['name']===$column) return true;
    return false;
}
function setting(string $key,mixed $fallback=''): mixed {return $fallback;}
function url(string $path): string {return $path;}

// This is the sole provider substitution. Calling a provider without an explicit
// response fixture fails; tests cannot accidentally charge a real instrument.
function agent_commerce_provider_http_pa2(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false): array
{
    $GLOBALS['pa2_http_calls'][]=compact('method','url','headers','body','form');
    if (!is_callable($GLOBALS['pa2_http']??null)) throw new RuntimeException('Fixture provider transport is unavailable.');
    return ($GLOBALS['pa2_http'])($method,$url,$headers,$body,$form);
}

$GLOBALS['pa2_http_calls']=[];$GLOBALS['pa2_http']=null;
// The production configuration reader is used with known, non-secret fixtures.
foreach ([
    'ENCRYPTION_KEY'=>'fixture-encryption-key','PLATFORM_FEE_BPS'=>'0','STRIPE_SECRET_KEY'=>'fixture-stripe-key',
    'STRIPE_CONNECT_CLIENT_ID'=>'fixture-connect','STRIPE_WEBHOOK_SECRET'=>'fixture-stripe-webhook',
    'SQUARE_APPLICATION_ID'=>'fixture-square-app','SQUARE_CLIENT_SECRET'=>'fixture-square-secret',
    'SQUARE_WEBHOOK_SIGNATURE_KEY'=>'fixture-square-webhook','SQUARE_ENVIRONMENT'=>'sandbox',
    'PAYPAL_CLIENT_ID'=>'fixture-paypal-client','PAYPAL_CLIENT_SECRET'=>'fixture-paypal-secret',
    'PAYPAL_PARTNER_ID'=>'fixture-paypal-partner','PAYPAL_WEBHOOK_ID'=>'fixture-paypal-webhook','PAYPAL_ENVIRONMENT'=>'sandbox',
] as $name=>$value) putenv('VP3_COMMERCE_'.$name.'='.$value);
$config=['site'=>['base_url'=>'https://fixture.example.test']];
require_once dirname(__DIR__,2).'/includes/agent-commerce-v800.php';
require_once dirname(__DIR__,2).'/includes/agent-commerce-recovery-pa2.php';

function commerce_fixture_schema_pa2(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED PRIMARY KEY)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_agents (id BIGINT UNSIGNED PRIMARY KEY)');
    $pdo->exec('INSERT IGNORE INTO users(id) VALUES(1),(2)');
    agent_commerce_ensure_schema_v800($pdo);
    agent_commerce_recovery_ensure_schema_pa2($pdo);
}

function commerce_fixture_insert_pa2(PDO $pdo,string $table,array $values): int
{
    if (!preg_match('/^[a-z0-9_]+$/',$table)) throw new RuntimeException('Invalid fixture table.');
    $columns=array_keys($values);
    foreach ($columns as $column) if (!preg_match('/^[a-z0-9_]+$/',$column)) throw new RuntimeException('Invalid fixture column.');
    $pdo->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($values));
    return (int)$pdo->lastInsertId();
}

/** Each scenario has an independent immutable checkout and provider account. */
function commerce_fixture_order_pa2(PDO $pdo,array $changes=[],array $attemptChanges=[],array $itemChanges=[]): array
{
    $suffix=bin2hex(random_bytes(7));$provider=(string)($changes['provider_snapshot']??'stripe');
    $account='acct_'.$suffix;$session='session_'.$suffix;$payment='payment_'.$suffix;
    $connection=commerce_fixture_insert_pa2($pdo,'agent_commerce_provider_connections_v800',[
        'owner_user_id'=>1,'provider'=>$provider,'external_account_id'=>$account,'status'=>'connected',
        'access_token_ciphertext'=>agent_commerce_encrypt_v800('fixture-access-token'),
        'capabilities_json'=>json_encode(['location_id'=>'location_'.$suffix]),
    ]);
    $id=commerce_fixture_insert_pa2($pdo,'agent_commerce_orders_v800',array_replace([
        'order_number'=>'PA2-'.$suffix,'owner_user_id'=>1,'connection_id'=>$connection,
        'provider_snapshot'=>$provider,'external_account_snapshot'=>$account,
        'order_status'=>'pending_payment','payment_status'=>'awaiting_payment','payment_mode'=>'full',
        'total_cents'=>5000,'subtotal_cents'=>5000,'amount_due_cents'=>5000,'currency'=>'usd',
        'hold_expires_at'=>gmdate('Y-m-d H:i:s',time()+1800),'metadata_json'=>'{}',
        'terms_snapshot_json'=>json_encode(['payment_mode'=>'full','price_cents'=>5000,'currency'=>'usd']),
    ],$changes));
    $itemId=commerce_fixture_insert_pa2($pdo,'agent_commerce_order_items_v800',array_replace([
        'order_id'=>$id,'title_snapshot'=>'Fixture item','product_type_snapshot'=>'service',
        'fulfillment_type'=>'manual','total_cents'=>5000,'unit_price_cents'=>5000,'metadata_json'=>'{}',
    ],$itemChanges));
    $attemptId=commerce_fixture_insert_pa2($pdo,'agent_commerce_checkout_attempts_v800',array_replace([
        'order_id'=>$id,'connection_id'=>$connection,'provider'=>$provider,'external_session_id'=>$session,
        'provider_order_id'=>$session,'provider_account_id'=>$account,
        'provider_location_id'=>$provider==='square'?'location_'.$suffix:'',
        'amount_cents'=>(int)($changes['amount_due_cents']??5000),'currency'=>(string)($changes['currency']??'usd'),
        'idempotency_key'=>hash('sha256',$suffix),'expires_at'=>gmdate('Y-m-d H:i:s',time()+1800),
    ],$attemptChanges));
    return ['id'=>$id,'item_id'=>$itemId,'attempt_id'=>$attemptId,'connection_id'=>$connection,
        'account'=>$account,'session'=>$session,'payment'=>$payment,'provider'=>$provider];
}

function commerce_fixture_stripe_pa2(array $f,array $objectChanges=[],string $eventId='',string $eventType='checkout.session.completed'): array
{
    $event=['id'=>$eventId?:'evt_'.bin2hex(random_bytes(10)),'type'=>$eventType,'account'=>$f['account'],'data'=>['object'=>array_replace([
        'object'=>'checkout.session','id'=>$f['session'],'payment_status'=>'paid','status'=>'complete',
        'payment_intent'=>$f['payment'],'amount_total'=>5000,'currency'=>'usd',
        'metadata'=>['vp3_commerce_order_id'=>(string)$f['id']],
    ],$objectChanges)]];
    $payload=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$timestamp=time();
    return ['event'=>$event,'payload'=>$payload,'headers'=>['stripe-signature'=>'t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$payload,'fixture-stripe-webhook')]];
}

function commerce_fixture_rows_pa2(PDO $pdo,string $table,string $where='1=1'): array
{
    return $pdo->query('SELECT * FROM '.$table.' WHERE '.$where.' ORDER BY id')->fetchAll();
}

function commerce_fixture_stripe_transport_pa2(array $fixtures): callable
{
    return static function(string $method,string $url,array $headers=[],mixed $body=null,bool $form=false) use ($fixtures): array {
        if ($method!=='GET' || !str_starts_with($url,'https://api.stripe.com/')) throw new RuntimeException('Unexpected fixture provider request.');
        foreach ($fixtures as $f) {
            $amount=(int)($f['amount']??5000);$currency=(string)($f['currency']??'usd');
            $metadata=['vp3_commerce_order_id'=>(string)$f['id']];
            $json=null;
            if ($url==='https://api.stripe.com/v1/checkout/sessions/'.$f['session']) $json=[
                'object'=>'checkout.session','id'=>$f['session'],'mode'=>'payment','payment_status'=>'paid',
                'status'=>'complete','amount_total'=>$amount,'currency'=>$currency,'payment_intent'=>$f['payment'],'metadata'=>$metadata,
            ];
            if ($url==='https://api.stripe.com/v1/payment_intents/'.$f['payment']) $json=[
                'object'=>'payment_intent','id'=>$f['payment'],'status'=>'succeeded','amount'=>$amount,
                'amount_received'=>$amount,'currency'=>$currency,'metadata'=>$metadata,
            ];
            if ($json!==null) {
                if (!in_array('Stripe-Account: '.$f['account'],$headers,true)) throw new RuntimeException('Fixture request used the wrong provider account.');
                return ['status'=>200,'json'=>$json,'raw'=>json_encode($json),'headers'=>[]];
            }
        }
        throw new RuntimeException('Fixture provider object not found.');
    };
}
