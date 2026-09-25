<?php
declare(strict_types=1);

$GLOBALS['v241_runs']=[];
$GLOBALS['v241_governed']=[];

function db(): PDO
{
    static $pdo=null;
    if($pdo)return $pdo;
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE homeserver_federated_records (
      user_id INTEGER NOT NULL,authority_source TEXT NOT NULL,dataset TEXT NOT NULL,
      authority_key TEXT NOT NULL,canonical_id TEXT NOT NULL,observed_source TEXT NOT NULL,
      record_hash TEXT NOT NULL DEFAULT '',source_updated_at TEXT,tombstoned INTEGER NOT NULL DEFAULT 0,
      first_seen_at TEXT,last_seen_at TEXT
    )");
    return $pdo;
}

function homeserver_federated_v240_text(mixed $value,int $max): string
{
    return mb_strimwidth(trim((string)$value),0,$max,'');
}
function homeserver_federated_v240_canonical_id(string $source,string $dataset,mixed $key): string
{
    return 'fd24_'.substr(hash('sha256',strtolower(trim($source)).'|'.strtolower(trim($dataset)).'|'.trim((string)$key)),0,40);
}
function homeserver_federated_v240_revision(string $title,string $content,?string $updatedAt=null): string
{
    return hash('sha256',$title.'|'.$content.'|'.($updatedAt??''));
}
function homeserver_federated_v240_envelope(string $source,string $dataset,mixed $key,string $title='',string $content='',?string $updatedAt=null): array
{
    return [
      'federation_version'=>'2.4','canonical_id'=>homeserver_federated_v240_canonical_id($source,$dataset,$key),
      'authority_source'=>$source,'authority_key'=>(string)$key,'dataset'=>$dataset,'title'=>$title,'content'=>$content,
      'updated_at'=>$updatedAt,'record_revision'=>homeserver_federated_v240_revision($title,$content,$updatedAt),
      'mirror_only'=>$source!=='vp3_cloud',
    ];
}
function homeserver_federated_v240_observe(int $userId,array $record,string $observedSource='vp3_cloud'): array
{
    $GLOBALS['v241_observed'][]=[$userId,$record,$observedSource];
    return ['canonical_id'=>$record['canonical_id']??''];
}
function homeserver_execution_v230_execute(int $userId,string $operation,array $payload=[]): array
{
    $GLOBALS['v241_runs'][]=[$userId,$operation,$payload];
    if($operation!=='contacts.list')throw new RuntimeException('unexpected operation');
    $key='address_book:9';
    $canonical=homeserver_federated_v240_canonical_id('homeserver','contacts',$key);
    return ['ok'=>true,'result'=>[
      'items'=>[[
        'canonical_id'=>$canonical,'authority_source'=>'homeserver','authority_key'=>$key,
        'contact_class'=>'address_book','display_name'=>'Local Person','organization'=>'Local Org',
        'email'=>'local@example.test','phone'=>'555-0100','relationship'=>'friend','notes'=>'Local notes',
        'updated_at'=>'2026-09-25 12:00:00','record_revision'=>str_repeat('a',64),
      ]],
      'count'=>1,
    ],'execution'=>['operation'=>'contacts.list','route'=>'homeserver']];
}
function homeserver_governed_v233_request(int $userId,string $tool,array $payload): array
{
    $GLOBALS['v241_governed'][]=[$userId,$tool,$payload];
    return ['ok'=>true,'tool'=>$tool,'status'=>'pending_approval','approval_required'=>true,'request_id'=>'req-contact-123'];
}

require dirname(__DIR__).'/includes/homeserver-contacts-v241.php';

function v241_assert(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$cloud=homeserver_contacts_v241_cloud_item(
  7,'core_crm','77','Cloud Person','Cloud CRM record','2026-09-25 11:00:00',
  ['organization'=>'Cloud Org','email'=>'cloud@example.test','relationship'=>'lead']
);
v241_assert($cloud['contact_class']==='core_crm','Cloud class');
v241_assert($cloud['authority_source']==='vp3_cloud','Cloud authority');
v241_assert($cloud['authority_key']==='core_crm:77','Cloud authority key');
v241_assert($cloud['canonical_id']===homeserver_federated_v240_canonical_id('vp3_cloud','contacts','core_crm:77'),'Cloud canonical ID');
v241_assert($cloud['read_only']===true,'Cloud generic projection is read-only');

$local=homeserver_contacts_v241_homeserver_contacts(7,'Local',20);
v241_assert(count($local)===1,'HomeServer contact count');
v241_assert($local[0]['contact_class']==='address_book','HomeServer contact class');
v241_assert($local[0]['authority_source']==='homeserver','HomeServer authority');
v241_assert($local[0]['authority_key']==='address_book:9','HomeServer authority key');
v241_assert($local[0]['mutation_route']==='homeserver_governed','HomeServer mutation route');
v241_assert($GLOBALS['v241_runs'][0][1]==='contacts.list','read uses contacts.list');

$canonical=$local[0]['canonical_id'];
db()->prepare("INSERT INTO homeserver_federated_records
  (user_id,authority_source,dataset,authority_key,canonical_id,observed_source,tombstoned)
  VALUES (?,?,?,?,?,'vp3_cloud',0)")->execute([7,'homeserver','contacts','address_book:9',$canonical]);

$update=homeserver_contacts_v241_request_homeserver(7,'update',[
  'canonical_id'=>$canonical,'relationship'=>'colleague'
]);
v241_assert($update['status']==='pending_approval','update becomes governed approval');
v241_assert($GLOBALS['v241_governed'][0][1]==='contacts.update','update governed tool');

$create=homeserver_contacts_v241_request_homeserver(7,'create',[
  'display_name'=>'New Local Contact'
]);
v241_assert($GLOBALS['v241_governed'][1][1]==='contacts.create','create governed tool');

$cloudCanonical=homeserver_federated_v240_canonical_id('vp3_cloud','contacts','core_crm:77');
db()->prepare("INSERT INTO homeserver_federated_records
  (user_id,authority_source,dataset,authority_key,canonical_id,observed_source,tombstoned)
  VALUES (?,?,?,?,?,'vp3_cloud',0)")->execute([7,'vp3_cloud','contacts','core_crm:77',$cloudCanonical]);
$blocked=false;
try{
    homeserver_contacts_v241_request_homeserver(7,'delete',['canonical_id'=>$cloudCanonical]);
}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'not a writable HomeServer');}
v241_assert($blocked,'Cloud-owned canonical ID blocked from HomeServer mutation');

echo "HomeServer v2.4 Section 2 contacts continuity runtime: PASS\n";
