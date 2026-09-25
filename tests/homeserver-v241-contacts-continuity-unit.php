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
    if(method_exists($pdo,'sqliteCreateFunction'))$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>'2026-09-25 12:00:00');
    $pdo->exec("CREATE TABLE homeserver_federated_records (
      user_id INTEGER NOT NULL,authority_source TEXT NOT NULL,dataset TEXT NOT NULL,
      authority_key TEXT NOT NULL,canonical_id TEXT NOT NULL,observed_source TEXT NOT NULL,
      record_hash TEXT NOT NULL DEFAULT '',source_updated_at TEXT,tombstoned INTEGER NOT NULL DEFAULT 0,
      first_seen_at TEXT,last_seen_at TEXT
    )");
    $pdo->exec("CREATE TABLE homeserver_contact_mutations (
      user_id INTEGER NOT NULL,mutation_id TEXT NOT NULL,action_key TEXT NOT NULL,
      request_hash TEXT NOT NULL,canonical_id TEXT,result_json TEXT NOT NULL,created_at TEXT,
      PRIMARY KEY(user_id,mutation_id)
    )");
    $pdo->exec("CREATE TABLE crm_contacts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER NOT NULL,public_id TEXT,
      name TEXT,email TEXT,email_normalized TEXT,phone TEXT,company TEXT,source TEXT,
      status TEXT NOT NULL DEFAULT 'active',lifecycle_stage TEXT NOT NULL DEFAULT '',
      marketing_status TEXT NOT NULL DEFAULT 'unknown',created_at TEXT,updated_at TEXT
    )");
    return $pdo;
}


function table_exists(string $table): bool
{
    $s=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    foreach(db()->query("PRAGMA table_info(".$table.")")->fetchAll()?:[] as $row){
        if((string)$row['name']===$column)return true;
    }
    return false;
}
function crm_v180_upsert_contact(PDO $pdo,array $data): int
{
    $owner=(int)($data['owner_user_id']??0);
    $email=strtolower(trim((string)($data['email']??'')));
    $s=$pdo->prepare("SELECT id FROM crm_contacts WHERE owner_user_id=? AND email_normalized=? AND status<>'archived' LIMIT 1");
    $s->execute([$owner,$email]);$id=(int)$s->fetchColumn();
    if($id>0){
        $pdo->prepare("UPDATE crm_contacts SET name=?,email=?,email_normalized=?,phone=?,company=?,source=?,updated_at='2026-09-25 12:00:00' WHERE id=?")
          ->execute([(string)($data['name']??$email),$email,$email,(string)($data['phone']??''),(string)($data['company']??''),(string)($data['source']??''),$id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO crm_contacts(owner_user_id,public_id,name,email,email_normalized,phone,company,source,status,lifecycle_stage,marketing_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,'active','','unknown','2026-09-25 11:00:00','2026-09-25 11:00:00')")
      ->execute([$owner,'test-'.bin2hex(random_bytes(4)),(string)($data['name']??$email),$email,$email,(string)($data['phone']??''),(string)($data['company']??''),(string)($data['source']??'')]);
    return (int)$pdo->lastInsertId();
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
    $canonical=(string)($record['canonical_id']??'');
    $authority=(string)($record['authority_source']??'');
    $dataset=(string)($record['dataset']??'contacts');
    $key=(string)($record['authority_key']??'');
    $s=db()->prepare("SELECT COUNT(*) FROM homeserver_federated_records WHERE user_id=? AND canonical_id=? AND observed_source=?");
    $s->execute([$userId,$canonical,$observedSource]);
    if((int)$s->fetchColumn()===0){
        db()->prepare("INSERT INTO homeserver_federated_records
          (user_id,authority_source,dataset,authority_key,canonical_id,observed_source,record_hash,tombstoned,last_seen_at)
          VALUES (?,?,?,?,?,?,?,0,'2026-09-25 12:00:00')")
          ->execute([$userId,$authority,$dataset,$key,$canonical,$observedSource,(string)($record['record_revision']??'')]);
    }else{
        db()->prepare("UPDATE homeserver_federated_records SET record_hash=?,tombstoned=0,last_seen_at='2026-09-25 12:00:00'
          WHERE user_id=? AND canonical_id=? AND observed_source=?")
          ->execute([(string)($record['record_revision']??''),$userId,$canonical,$observedSource]);
    }
    return ['canonical_id'=>$canonical];
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
v241_assert($cloud['read_only']===false,'Core CRM is writable through its Cloud authority');

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
  'canonical_id'=>$canonical,'mutation_id'=>'local-update-001','expected_revision'=>str_repeat('a',64),'relationship'=>'colleague'
]);
v241_assert($update['status']==='pending_approval','update becomes governed approval');
v241_assert($GLOBALS['v241_governed'][0][1]==='contacts.update','update governed tool');

$create=homeserver_contacts_v241_request_homeserver(7,'create',[
  'mutation_id'=>'local-create-001','display_name'=>'New Local Contact'
]);
v241_assert($GLOBALS['v241_governed'][1][1]==='contacts.create','create governed tool');

$cloudCanonical=homeserver_federated_v240_canonical_id('vp3_cloud','contacts','core_crm:77');
db()->prepare("INSERT INTO homeserver_federated_records
  (user_id,authority_source,dataset,authority_key,canonical_id,observed_source,tombstoned)
  VALUES (?,?,?,?,?,'vp3_cloud',0)")->execute([7,'vp3_cloud','contacts','core_crm:77',$cloudCanonical]);
$blocked=false;
try{
    homeserver_contacts_v241_request_homeserver(7,'delete',[
      'canonical_id'=>$cloudCanonical,'mutation_id'=>'wrong-delete-001','expected_revision'=>str_repeat('b',64)
    ]);
}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'not a writable HomeServer');}
v241_assert($blocked,'Cloud-owned canonical ID blocked from HomeServer mutation');


$created=homeserver_contacts_v241_mutate(7,'create',[
  'authority_source'=>'vp3_cloud','mutation_id'=>'cloud-create-001',
  'display_name'=>'Katherine Johnson','email'=>'kj@example.test','organization'=>'NASA','phone'=>'555-0102'
]);
v241_assert(!empty($created['created']),'Cloud CRM create');
$crm=$created['contact'];
v241_assert(($crm['authority_source']??'')==='vp3_cloud','Cloud CRM authority');
v241_assert(($crm['contact_class']??'')==='core_crm','Cloud CRM class');
v241_assert(($crm['read_only']??true)===false,'Cloud CRM is writable');
$crmCanonical=(string)$crm['canonical_id'];
$crmRevision=(string)$crm['record_revision'];

$createdReplay=homeserver_contacts_v241_mutate(7,'create',[
  'authority_source'=>'vp3_cloud','mutation_id'=>'cloud-create-001',
  'display_name'=>'Katherine Johnson','email'=>'kj@example.test','organization'=>'NASA','phone'=>'555-0102'
]);
v241_assert(!empty($createdReplay['idempotent_replay']),'Cloud create replay is idempotent');

$changed=homeserver_contacts_v241_mutate(7,'update',[
  'canonical_id'=>$crmCanonical,'mutation_id'=>'cloud-update-001','expected_revision'=>$crmRevision,
  'organization'=>'NASA Langley'
]);
v241_assert(!empty($changed['updated']),'Cloud CRM update');
$newRevision=(string)$changed['contact']['record_revision'];
v241_assert($newRevision!==$crmRevision,'Cloud CRM revision changes');

$changedReplay=homeserver_contacts_v241_mutate(7,'update',[
  'canonical_id'=>$crmCanonical,'mutation_id'=>'cloud-update-001','expected_revision'=>$crmRevision,
  'organization'=>'NASA Langley'
]);
v241_assert(!empty($changedReplay['idempotent_replay']),'Cloud update replay survives changed current revision');

$stale=false;
try{
    homeserver_contacts_v241_mutate(7,'update',[
      'canonical_id'=>$crmCanonical,'mutation_id'=>'cloud-update-stale-001','expected_revision'=>$crmRevision,
      'organization'=>'Stale Org'
    ]);
}catch(RuntimeException $e){$stale=str_contains($e->getMessage(),'changed after this edit');}
v241_assert($stale,'Cloud stale revision is rejected');

$visitor=homeserver_contacts_v241_cloud_item(7,'profile_visitor','guest-1','Guest One','Visitor','2026-09-25 12:00:00');
$readOnlyBlocked=false;
try{
    homeserver_contacts_v241_mutate(7,'update',[
      'canonical_id'=>$visitor['canonical_id'],'mutation_id'=>'visitor-update-001',
      'expected_revision'=>$visitor['record_revision'],'display_name'=>'Should Fail'
    ]);
}catch(RuntimeException $e){$readOnlyBlocked=str_contains($e->getMessage(),'does not support generic edits');}
v241_assert($readOnlyBlocked,'Profile visitor generic edits are blocked');

$deleted=homeserver_contacts_v241_mutate(7,'delete',[
  'canonical_id'=>$crmCanonical,'mutation_id'=>'cloud-delete-001','expected_revision'=>$newRevision
]);
v241_assert(!empty($deleted['deleted']),'Cloud CRM delete archives native record');
$deletedReplay=homeserver_contacts_v241_mutate(7,'delete',[
  'canonical_id'=>$crmCanonical,'mutation_id'=>'cloud-delete-001','expected_revision'=>$newRevision
]);
v241_assert(!empty($deletedReplay['idempotent_replay']),'Cloud delete replay survives tombstone');

echo "HomeServer v2.4 Section 2 contacts continuity runtime: PASS\n";
