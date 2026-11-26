<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/phone-caller-contacts-schema-v1.php';
require_once __DIR__.'/../includes/workspace-native-actions-v1.php';

$checks=0;
function phone_contact_schema_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function phone_contact_schema_reject(callable $fn,string $label): void{try{$fn();}catch(Throwable){phone_contact_schema_check(true,$label);return;}phone_contact_schema_check(false,$label);}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES(1),(2)');
$pdo->exec(<<<'SQL'
CREATE TABLE crm_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NOT NULL REFERENCES users(id),
    public_id VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL CHECK(name<>''),
    email VARCHAR(190) NOT NULL DEFAULT '',
    "email_normalized" VARCHAR(190) CONSTRAINT "required email" NOT NULL ON CONFLICT ABORT DEFAULT '' CHECK(email_normalized IS NULL OR email_normalized<>'literal,NOT NULL'),
    phone VARCHAR(80) NOT NULL DEFAULT '',
    company VARCHAR(190) NOT NULL DEFAULT '',
    source VARCHAR(80) NOT NULL DEFAULT 'before,(NOT NULL)',
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    lifecycle_stage VARCHAR(80) NOT NULL DEFAULT '',
    marketing_status VARCHAR(40) NOT NULL DEFAULT 'unknown',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    display_text TEXT GENERATED ALWAYS AS (name || ', NOT NULL') VIRTUAL,
    UNIQUE(owner_user_id,email_normalized)
)
SQL);
$pdo->exec('CREATE INDEX crm_contacts_phone_lookup ON crm_contacts(owner_user_id,phone)');
$pdo->exec('CREATE TABLE contact_notes(id INTEGER PRIMARY KEY, contact_id INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE, note TEXT NOT NULL)');
$pdo->exec('CREATE TABLE contact_audit(contact_id INTEGER NOT NULL)');
$pdo->exec('CREATE TRIGGER crm_contacts_changed AFTER UPDATE ON crm_contacts BEGIN INSERT INTO contact_audit VALUES(NEW.id); END');
$pdo->exec('CREATE VIEW visible_crm_contacts AS SELECT id,name FROM crm_contacts');
$pdo->exec("INSERT INTO crm_contacts(id,owner_user_id,public_id,name,email,email_normalized,phone) VALUES(1,1,'existing-email','Existing email','person@example.invalid','person@example.invalid','+16025550111'),(2,1,'existing-phone','Existing phone','','','+16025550112'),(99,1,'deleted','Deleted','deleted@example.invalid','deleted@example.invalid','')");
$pdo->exec('DELETE FROM crm_contacts WHERE id=99');
$pdo->exec("INSERT INTO contact_notes VALUES(1,1,'Keep source note'),(2,2,'Keep phone note')");

phone_contact_schema_check(!vp3_phone_caller_contacts_schema_ready_v1($pdo),'old NOT NULL CRM explicitly requires upgrade');
$definitions=$pdo->query("SELECT type,name,sql FROM sqlite_master WHERE tbl_name='crm_contacts' AND type IN ('index','trigger') AND sql IS NOT NULL ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
vp3_phone_caller_contacts_ensure_schema_v1($pdo);
phone_contact_schema_check(vp3_phone_caller_contacts_schema_ready_v1($pdo),'explicit upgrade makes email identity optional');
phone_contact_schema_check(vp3_phone_contact_details_schema_ready_v1($pdo),'explicit upgrade adds relationship and owner-private notes');
phone_contact_schema_check($pdo->query('SELECT relationship FROM crm_contacts WHERE id=1')->fetchColumn()===''&&$pdo->query('SELECT notes FROM crm_contacts WHERE id=1')->fetchColumn()===null,'new personal fields leave existing CRM records unchanged');
phone_contact_schema_check((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()===1,'foreign-key enforcement remains enabled');
phone_contact_schema_check((int)$pdo->query('PRAGMA legacy_alter_table')->fetchColumn()===0,'temporary rename compatibility flag is restored');
phone_contact_schema_check($pdo->query('PRAGMA foreign_key_check')->fetchAll()===[],'foreign-key references survive the table replacement');
phone_contact_schema_check((int)$pdo->query('SELECT count(*) FROM visible_crm_contacts')->fetchColumn()===2,'existing CRM views keep the canonical table reference');
phone_contact_schema_check((int)$pdo->query('SELECT count(*) FROM contact_notes')->fetchColumn()===2,'dependent rows survive ON DELETE CASCADE migration');
phone_contact_schema_check($pdo->query("SELECT type,name,sql FROM sqlite_master WHERE tbl_name='crm_contacts' AND type IN ('index','trigger') AND sql IS NOT NULL ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC)===$definitions,'explicit indexes and triggers retain their definitions');
phone_contact_schema_check($pdo->query('SELECT email_normalized FROM crm_contacts WHERE id=2')->fetchColumn()===null,'legacy empty email identity becomes NULL');
phone_contact_schema_check($pdo->query('SELECT email FROM crm_contacts WHERE id=2')->fetchColumn()==='','email text remains blank without a fabricated identity');
phone_contact_schema_check($pdo->query('SELECT email_normalized FROM crm_contacts WHERE id=1')->fetchColumn()==='person@example.invalid','existing valid email identity remains unchanged');
phone_contact_schema_check($pdo->query('SELECT display_text FROM crm_contacts WHERE id=2')->fetchColumn()==='Existing phone, NOT NULL','generated columns and quoted SQL literals remain intact');
$pdo->exec("INSERT INTO crm_contacts(owner_user_id,public_id,name,email_normalized,phone) VALUES(1,'phone-three','Phone three',NULL,'+16025550113'),(1,'phone-four','Phone four',NULL,'+16025550114')");
phone_contact_schema_check((int)$pdo->query("SELECT id FROM crm_contacts WHERE public_id='phone-three'")->fetchColumn()===100,'deleted AUTOINCREMENT high-water mark survives migration');
phone_contact_schema_check((int)$pdo->query('SELECT count(*) FROM crm_contacts WHERE owner_user_id=1 AND email_normalized IS NULL')->fetchColumn()===3,'one owner may keep distinct contacts without email');
phone_contact_schema_reject(fn()=>$pdo->exec("INSERT INTO crm_contacts(owner_user_id,public_id,name,email_normalized) VALUES(1,'duplicate-email','Duplicate','person@example.invalid')"),'owner email uniqueness survives migration');
$pdo->exec("INSERT INTO crm_contacts(owner_user_id,public_id,name,email,email_normalized) VALUES(2,'other-owner','Other owner','person@example.invalid','person@example.invalid')");
phone_contact_schema_check((int)$pdo->query("SELECT count(*) FROM crm_contacts WHERE email_normalized='person@example.invalid'")->fetchColumn()===2,'valid email uniqueness remains scoped to owner');
phone_contact_schema_reject(fn()=>$pdo->exec("INSERT INTO crm_contacts(owner_user_id,public_id,name,email_normalized) VALUES(1,'bad-name','',NULL)"),'other CHECK constraints remain enforced');
phone_contact_schema_reject(fn()=>$pdo->exec("INSERT INTO crm_contacts(owner_user_id,public_id,name,email,email_normalized) VALUES(1,'null-email','Null email',NULL,NULL)"),'email display column stays NOT NULL');
phone_contact_schema_reject(fn()=>$pdo->exec("INSERT INTO crm_contacts(owner_user_id,public_id,name,email_normalized) VALUES(999,'bad-owner','Unknown owner',NULL)"),'owner foreign-key constraint remains enforced');
phone_contact_schema_check($pdo->query("SELECT source FROM crm_contacts WHERE public_id='phone-three'")->fetchColumn()==='before,(NOT NULL)','other defaults retain punctuation and constraint words');
$pdo->exec('DELETE FROM contact_audit');
workspace_native_contact_update_v1($pdo,1,2,['display_name'=>'Edited phone contact']);
workspace_native_contact_update_v1($pdo,1,100,['display_name'=>'Another phone contact']);
phone_contact_schema_check((int)$pdo->query('SELECT count(*) FROM contact_audit')->fetchColumn()===2,'native edits retain working update triggers');
phone_contact_schema_check((int)$pdo->query('SELECT count(*) FROM crm_contacts WHERE id IN(2,100) AND email_normalized IS NULL')->fetchColumn()===2,'native edits preserve absent email for multiple phone contacts');
workspace_native_contact_update_v1($pdo,1,2,['email'=>' ADDED@Example.Invalid ']);
phone_contact_schema_check($pdo->query('SELECT email_normalized FROM crm_contacts WHERE id=2')->fetchColumn()==='added@example.invalid','phone contact may later gain a normalized real email');
workspace_native_contact_update_v1($pdo,1,2,['email'=>'']);
phone_contact_schema_check($pdo->query('SELECT email_normalized FROM crm_contacts WHERE id=2')->fetchColumn()===null,'removing email clears its canonical identity');
$before=$pdo->query('SELECT * FROM crm_contacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
vp3_phone_caller_contacts_ensure_schema_v1($pdo);
phone_contact_schema_check($pdo->query('SELECT * FROM crm_contacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'upgrade is idempotent');
$pdo->beginTransaction();
phone_contact_schema_reject(fn()=>vp3_phone_caller_contacts_ensure_schema_v1($pdo),'upgrade refuses to replace tables inside a caller transaction');
phone_contact_schema_check($pdo->inTransaction(),'transaction refusal leaves caller transaction intact');
$pdo->rollBack();
$missing=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
phone_contact_schema_check(!vp3_phone_caller_contacts_schema_ready_v1($missing),'readiness does not create missing CRM tables');
phone_contact_schema_reject(fn()=>vp3_phone_caller_contacts_ensure_schema_v1($missing),'phone migration requires full canonical CRM first');
echo 'PASS '.$checks." phone contact schema preservation and native edit checks\n";
