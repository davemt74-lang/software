<?php
declare(strict_types=1);

/** Real SQLite canonical Brain/list writes, scope and permission tables. Only
 * host settings and account permission lookups are projected by this fixture. */
class OfficeActionPDO extends PDO
{
    public bool $rejectMembership=false;
    public bool $rejectPermissions=false;
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        $sql=str_replace(' FOR UPDATE','',$sql);
        if($this->rejectMembership&&str_contains($sql,'INSERT INTO agent_task_list_items_v1'))throw new PDOException('Injected membership failure');
        if($this->rejectPermissions&&str_contains($sql,'role_permissions'))throw new PDOException('Injected permission read failure');
        if(str_contains($sql,'information_schema.statistics'))$sql="SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?";
        $sql=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$sql);
        $sql=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$sql);
        return parent::prepare($sql,$options);
    }
}
$pdo=new OfficeActionPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('LAST_INSERT_ID',static fn($n)=>$n);
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool{$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
function setting(string $key,mixed $fallback=null): mixed{return $key==='personal_capabilities_seed_v242'?'1':$fallback;}
function permissions_schema_ready(): bool{return table_exists('role_permissions');}
function user_roles(): array{return ['member'=>'Member','admin'=>'Admin'];}
function valid_role(string $role): bool{return isset(user_roles()[$role]);}
function user_roles_for_user(?array $user=null): array{$q=db()->prepare('SELECT role FROM users WHERE id=? AND is_active=1');$q->execute([(int)($user['id']??0)]);$role=$q->fetchColumn();return $role?[$role]:[];}
function user_has_role(string $role,?array $user=null): bool{return in_array($role,user_roles_for_user($user),true);}
function has_permission(string $permission,?array $user=null): bool{$q=db()->prepare('SELECT 1 FROM role_permissions WHERE role=? AND permission_key=?');foreach(user_roles_for_user($user) as $role){$q->execute([$role,$permission]);if($q->fetchColumn())return true;}return false;}
function agent_brain_schema_ready(): bool{return table_exists('agent_memory_items');}
function agent_brain_normalize(string $value): string{return mb_strtolower(trim($value));}
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-memory-lifecycle-v123.php';
require_once dirname(__DIR__).'/includes/agent-task-lifecycle-v123.php';
require_once dirname(__DIR__).'/includes/agent-initiative-office-actions-v1.php';
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,role TEXT);INSERT INTO users VALUES(1,1,'member'),(2,1,'member');
CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT DEFAULT 'Fixture Agent');INSERT INTO user_agents(id,owner_user_id,is_active) VALUES(7,1,1),(8,1,1),(9,2,1);
CREATE TABLE role_permissions(role TEXT,permission_key TEXT);INSERT INTO role_permissions VALUES('member','account.access'),('member','chat.access'),('member','agent_brain.access');
CREATE TABLE permissions(permission_key TEXT);INSERT INTO permissions VALUES('agent_brain.access');
CREATE TABLE settings(setting_key TEXT,setting_value TEXT);INSERT INTO settings VALUES('personal_capabilities_seed_v242','1');
CREATE TABLE user_account_types(user_id INTEGER,role TEXT,assigned_explicitly_at TEXT);
CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);INSERT INTO chat_conversations VALUES(10,1,7),(11,1,8),(12,2,9),(13,1,NULL);
CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,role TEXT,context_json TEXT);
CREATE TABLE agent_chat_archive(id INTEGER,user_agent_id INTEGER);CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id);
CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,memory_hash TEXT,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT,UNIQUE(user_id,memory_hash));
CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,occurrence_count)");
vp3_task_lists_schema_v1($pdo);
$u=['id'=>1,'is_active'=>1,'role'=>'member'];$p=['kind'=>'user_agent','owner_user_id'=>1,'viewer_user_id'=>1,'agent_id'=>7];$b=['owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>10];$checks=0;
function office_action_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function office_action_throws(callable $work,int $code,string $label): void{try{$work();}catch(Throwable $error){office_action_check($error->getCode()===$code,$label.' ('.get_class($error).': '.$error->getMessage().')');return;}throw new RuntimeException($label.' did not fail');}
function office_action_plan(string $adapter,array $args,array $allowed=[]): array{return vp3_initiative_office_action_normalize_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['b'],['adapter'=>$adapter,'arguments'=>$args],$allowed);}
function office_action_apply(array $plan,string $id): array{db()->beginTransaction();try{$r=vp3_initiative_office_action_apply_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['b'],$plan,$id);db()->commit();return $r;}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}}
function office_action_inspect(array $plan,array $result): array{return vp3_initiative_office_action_inspect_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['b'],$plan,$result);}
function office_action_count(string $table): int{return(int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}

office_action_check(count(vp3_initiative_office_action_catalog_v1($pdo,$u,$p,$b))===4,'four existing task/list actions are discoverable as typed private reviews');
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$create=office_action_plan('task.create',['title'=>'Prepare product launch']);
office_action_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before&&$create['review_required']===true,'normalization is a read-only review, never authorization or a write');
office_action_throws(fn()=>office_action_plan('task.create',['title'=>'X','owner_user_id'=>2]),0,'model owner fields are rejected');
office_action_throws(fn()=>office_action_plan('task.create',['title'=>"hidden\ncommand"]),0,'control characters are rejected');
office_action_throws(fn()=>office_action_plan('task.set_status',['task_id'=>'1','status'=>'completed']),0,'record IDs are strictly typed');
office_action_throws(fn()=>vp3_initiative_office_action_apply_v1($pdo,$u,$p,$b,$create,'action-create-1'),409,'apply requires the broker transaction');
$r=office_action_apply($create,'action-create-1');$tid=$r['office_action']['task_id'];
office_action_check($r['verified']&&$r['tool_receipt']['changes_applied']&&office_action_count('agent_memory_items')===1,'approved action saves and reads back one actual canonical Brain task');
office_action_check($pdo->query('SELECT user_agent_id FROM agent_memory_items WHERE id='.$tid)->fetchColumn()===7&&!isset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']),'task uses exact named Agent and restores previous global scope');
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();office_action_check(office_action_inspect($create,$r)['verified']&&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'receipt inspection is read-only');
office_action_throws(fn()=>office_action_apply($create,'action-create-1'),409,'same stable action is never blindly reapplied');
office_action_check(office_action_count('agent_memory_items')===1,'duplicate apply cannot create another task');
office_action_throws(fn()=>office_action_plan('task.set_status',['task_id'=>$tid,'status'=>'completed']),403,'an owned task outside proposal resource scope is denied');
office_action_throws(fn()=>office_action_plan('task.set_status',['task_id'=>$tid,'status'=>'completed'],['tasks'=>[['id'=>$tid,'title'=>'Different label']]]),409,'curated labels must still match the exact live record');
$allowed=['tasks'=>[['id'=>$tid,'title'=>'Prepare product launch']]];$complete=office_action_plan('task.set_status',['task_id'=>$tid,'status'=>'completed'],$allowed);
$done=office_action_apply($complete,'action-complete-1');office_action_check($done['office_action']['after']['task']['status']==='completed','task status action reuses canonical lifecycle and verifies saved state');
office_action_throws(fn()=>office_action_inspect($create,$r),409,'later task edits make the old result stale without resetting them');
office_action_throws(fn()=>office_action_apply($create,'action-create-1'),409,'task create replay cannot reopen a completed task');
office_action_throws(fn()=>office_action_apply($complete,'action-complete-stale'),409,'stale status review cannot overwrite a later task revision');
$same=office_action_plan('task.set_status',['task_id'=>$tid,'status'=>'completed'],$allowed);$noop=office_action_apply($same,'action-complete-noop');office_action_check(!$noop['tool_receipt']['changes_applied'],'already attained status is reported as verified read without metadata rewrite');
$listPlan=office_action_plan('task_list.create',['name'=>'Launch']);$listResult=office_action_apply($listPlan,'action-list-1');$lid=$listResult['office_action']['list_id'];
office_action_check(office_action_count('agent_task_lists_v1')===1&&office_action_count('agent_task_list_items_v1')===0,'reviewed list creation uses canonical empty list kernel');
$listAllowed=['lists'=>[['id'=>$lid,'name'=>'Launch']]];$add=office_action_plan('task_list.add',['list_id'=>$lid,'title'=>'Review marketing'],$listAllowed);$added=office_action_apply($add,'action-list-add-1');
office_action_check(office_action_count('agent_memory_items')===2&&office_action_count('agent_task_list_items_v1')===1&&$added['verified'],'new list item creates one Brain task and one canonical reference');
$duplicate=office_action_plan('task_list.add',['list_id'=>$lid,'title'=>'review marketing'],$listAllowed);$duplicateResult=office_action_apply($duplicate,'action-list-add-duplicate');office_action_check(!$duplicateResult['tool_receipt']['changes_applied']&&office_action_count('agent_memory_items')===2,'canonical case-insensitive list duplicate reuses task without extra writes');
$attach=office_action_plan('task_list.add',['list_id'=>$lid,'task_id'=>$tid],$listAllowed+$allowed);$attached=office_action_apply($attach,'action-list-attach-1');
office_action_check(office_action_count('agent_memory_items')===2&&office_action_count('agent_task_list_items_v1')===2,'existing task is attached by ID without copying');
office_action_throws(fn()=>office_action_apply($add,'action-list-add-stale'),409,'a changed list invalidates an earlier review');
$rollback=office_action_plan('task_list.add',['list_id'=>$lid,'title'=>'Rollback evidence'],$listAllowed);$pdo->rejectMembership=true;
office_action_throws(fn()=>office_action_apply($rollback,'action-list-add-rollback'),0,'canonical membership failure propagates to the broker');$pdo->rejectMembership=false;
office_action_check(office_action_count('agent_memory_items')===2&&office_action_count('agent_task_list_items_v1')===2,'broker rollback removes both unverified task and membership effect');
$foreignP=['kind'=>'user_agent','owner_user_id'=>1,'viewer_user_id'=>1,'agent_id'=>8];$foreignB=['owner_user_id'=>1,'agent_id'=>8,'conversation_id'=>11];
office_action_throws(fn()=>vp3_initiative_office_action_normalize_v1($pdo,$u,$foreignP,$foreignB,['adapter'=>'task.set_status','arguments'=>['task_id'=>$tid,'status'=>'open']],$allowed),403,'same-owner other Agent cannot use this task');
office_action_throws(fn()=>vp3_initiative_office_action_normalize_v1($pdo,$u,$foreignP,$foreignB,['adapter'=>'task_list.add','arguments'=>['list_id'=>$lid,'title'=>'Other']],$listAllowed),403,'same-owner other Agent cannot use this list');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','brain:{$tid}','deny')");
office_action_throws(fn()=>office_action_plan('task.set_status',['task_id'=>$tid,'status'=>'open'],$allowed),403,'named Agent exact-record data rule revocation prevents action');$pdo->exec('DELETE FROM user_agent_data_rules');
$pdo->exec("DELETE FROM role_permissions WHERE permission_key='agent_brain.access'");
office_action_throws(fn()=>office_action_apply($create,'action-denied-personal'),403,'explicit personal permission revoke cannot fall back to default roles');$pdo->exec("INSERT INTO role_permissions VALUES('member','agent_brain.access')");
$pdo->rejectPermissions=true;office_action_throws(fn()=>office_action_apply($create,'action-permission-read-error'),503,'permission store failure is an operational blocker, never a grant');$pdo->rejectPermissions=false;
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');office_action_throws(fn()=>office_action_apply($create,'action-retired-agent'),403,'retired Agent cannot fall back to System scope');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');office_action_throws(fn()=>office_action_apply($create,'action-inactive-owner'),403,'fresh inactive account blocks effect');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$wrong=$b;$wrong['conversation_id']=12;office_action_throws(fn()=>vp3_initiative_office_action_normalize_v1($pdo,$u,$p,$wrong,['adapter'=>'task.create','arguments'=>['title'=>'Wrong conversation']],[]),403,'account-switched conversation cannot be used');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'participant'=>9];office_action_throws(fn()=>office_action_apply($create,'action-participant'),403,'private proactive action cannot execute in participant scope');unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$systemP=['kind'=>'system','owner_user_id'=>0,'viewer_user_id'=>1,'agent_id'=>0];$systemB=['owner_user_id'=>1,'agent_id'=>0,'conversation_id'=>13];
$systemPlan=vp3_initiative_office_action_normalize_v1($pdo,$u,$systemP,$systemB,['adapter'=>'task.create','arguments'=>['title'=>'System task']],[]);$pdo->beginTransaction();$systemResult=vp3_initiative_office_action_apply_v1($pdo,$u,$systemP,$systemB,$systemPlan,'action-system-task');$pdo->commit();
office_action_check($pdo->query('SELECT user_agent_id FROM agent_memory_items WHERE id='.$systemResult['office_action']['task_id'])->fetchColumn()===null,'canonical System principal keeps null Agent namespace');
$forged=$systemResult;$forged['tool_receipt']['record_id']=$tid;office_action_throws(fn()=>vp3_initiative_office_action_inspect_v1($pdo,$u,$systemP,$systemB,$systemPlan,$forged),409,'receipt cannot substitute another record ID');
echo 'OFFICE_ACTIONS=PASS ('.$checks.' checks; real canonical persistence, strict authority, readback, replay and rollback)'.PHP_EOL;
