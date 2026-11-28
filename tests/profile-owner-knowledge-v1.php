<?php
declare(strict_types=1);

define('PA3_HANDOFF_HELPERS_ONLY',true);
require __DIR__.'/profile-handoff-pa3.php';
require_once dirname(__DIR__).'/includes/profile-agent-knowledge-v1.php';

/** SQL dialect adaptation only; all Knowledge, policy, source and owner code is real. */
final class ProfileKnowledgeTestPDO extends PDO
{
    public array $statements=[];
    public mixed $beforeSql=null;
    public mixed $afterSql=null;
    public string $failSql='';
    private function observed(string $sql): string
    {
        $this->statements[]=$sql;if($hook=$this->beforeSql)$hook($sql,$this);
        if($this->failSql!==''&&str_contains($sql,$this->failSql))throw new PDOException('PRIVATE SQL DIAGNOSTIC');
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')return $sql;
        $sql=str_replace([' FOR UPDATE','INSERT IGNORE'],['','INSERT OR IGNORE'],$sql);
        $sql=preg_replace('/SHOW INDEX FROM `?([a-z0-9_]+)`? WHERE Key_name=\?/i',"SELECT name FROM sqlite_master WHERE tbl_name='$1' AND type='index' AND name=?",$sql);
        $sql=preg_replace('/DATE_SUB\((?:UTC_TIMESTAMP|NOW)\(\),INTERVAL (\d+) (DAY|HOUR|MINUTE|SECOND)\)/i',"datetime('now','-$1 $2')",$sql);
        if(str_starts_with($sql,'INSERT INTO profile_visit_sessions ')){
            $sql=str_contains($sql,'VALUES (?,NULL,?,0,0)')
                ?"INSERT INTO profile_visit_sessions(owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,NULL,?,0,0) ON CONFLICT(owner_user_id,session_key) DO UPDATE SET last_seen_at=NOW()"
                :"INSERT INTO profile_visit_sessions(owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,?,?,?,?) ON CONFLICT(owner_user_id,session_key) DO UPDATE SET visitor_user_id=COALESCE(excluded.visitor_user_id,profile_visit_sessions.visitor_user_id),identity_disclosed=CASE WHEN excluded.visitor_user_id IS NULL THEN profile_visit_sessions.identity_disclosed ELSE excluded.identity_disclosed END,view_count=profile_visit_sessions.view_count+excluded.view_count,last_seen_at=NOW()";
        }
        if(str_starts_with($sql,'INSERT INTO user_data_policies ')&&str_contains($sql,' ON DUPLICATE KEY UPDATE ')){
            [$insert,$update]=explode(' ON DUPLICATE KEY UPDATE ',$sql,2);
            $sql=$insert.' ON CONFLICT(owner_user_id,resource_type,resource_id) DO UPDATE SET '.preg_replace('/VALUES\(([a-z_]+)\)/','excluded.$1',$update);
        }
        $sql=str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
        $sql=preg_replace('/\s+UNSIGNED\b|\s+ON UPDATE CURRENT_TIMESTAMP|\s+AFTER [a-z_]+/i','',$sql);
        $sql=preg_replace('/UNIQUE KEY [a-z0-9_]+\s*\(/i','UNIQUE (',$sql);
        $sql=preg_replace('/,\s*INDEX [a-z0-9_]+\s*\([^)]*\)/i','',$sql);
        $sql=preg_replace('/,\s*\)/',')',$sql);
        return preg_replace('/\s+ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/i','',$sql);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false{return parent::prepare($this->observed($query),$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {$query=$this->observed($query);return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
    public function exec(string $statement): int|false{return parent::exec($this->observed($statement));}
}

$cfg=$GLOBALS['pa3Config'];
$pdo=new ProfileKnowledgeTestPDO($cfg['dsn'],$cfg['user']??null,$cfg['password']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['pdo']=$pdo;
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    $pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('REPEAT',static fn($text,$count)=>str_repeat((string)$text,(int)$count));
    $pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
}else{$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET time_zone='+00:00'");}
pa3_reset();
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
foreach(['track_id'=>'INTEGER NULL','folder_id'=>'INTEGER NULL','knowledge_scope'=>"VARCHAR(20) NOT NULL DEFAULT 'personal'",'file_name'=>"VARCHAR(255) NOT NULL DEFAULT ''",'file_path'=>"VARCHAR(500) NOT NULL DEFAULT ''",'file_type'=>"VARCHAR(40) NOT NULL DEFAULT 'personal_note'",'mime_type'=>"VARCHAR(100) NOT NULL DEFAULT 'text/plain'",'file_size'=>'INTEGER NOT NULL DEFAULT 0'] as $column=>$definition)if(!column_exists('knowledge_items',$column))$pdo->exec('ALTER TABLE knowledge_items ADD COLUMN '.$column.' '.$definition);
if($mysql){$pdo->exec('ALTER TABLE knowledge_items MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');$pdo->exec('ALTER TABLE knowledge_chunks MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');}
$auto=$mysql?'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
$pdo->exec("CREATE TABLE artist_transcript_folders_v177(id $auto,created_by_user_id INTEGER NOT NULL,folder_name VARCHAR(80),sort_order INTEGER NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec('CREATE UNIQUE INDEX profile_knowledge_policy_test_unique ON user_data_policies(owner_user_id,resource_type,resource_id)');
shared_knowledge_index_ensure_schema_v236($pdo);
vp3_profile_knowledge_install_v1($pdo);

function pk_reset_v1(): void
{
    $p=db();$p->failSql='';$p->beforeSql=null;if($p->inTransaction())$p->rollBack();
    $p->exec('DELETE FROM profile_agent_knowledge_saves_v1');$p->exec('DELETE FROM shared_knowledge_index');$p->exec('DELETE FROM artist_transcript_folders_v177');
    pa3_reset();
    $p->exec("INSERT INTO role_permissions VALUES('member','personal_knowledge.access'),('member','personal_knowledge.manage')");
    $p->exec("INSERT INTO artist_transcript_folders_v177(id,created_by_user_id,folder_name) VALUES(10,1,'Owner answers'),(20,4,'Foreign private folder')");
    // Match the existing Knowledge Access controls: Allow is unchecked and
    // audience is Existing visibility. Saving must leave these choices alone.
    $p->exec("UPDATE user_data_policies SET audience_scope='inherit',profile_agent_allowed=0,stonefellow_shared=0 WHERE owner_user_id=1 AND resource_type='knowledge'");
    $p->beginTransaction();vp3_profile_response_settings_save_v1($p,1,'user_responses',0);$p->commit();
}
function pk_answer_v1(string $question='What are the approved Aurora hours?',string $answer='Aurora opens at nine on weekdays.'): array
{
    $receipt=pa3_admit($question);$turn=pa3_turn($receipt);
    vp3_profile_handoff_owner_action_pa3(db(),pa3_owner(),pa3_action_input($turn,'reply',['message'=>$answer]),true);
    $request=vp3_profile_response_projection_v1(db(),1,(int)$receipt['conversation_id'])['request'];
    pa3_login(1);
    return vp3_profile_knowledge_preview_v1(db(),pa3_owner(),(int)$receipt['conversation_id'],(int)$request['id']);
}
function pk_input_v1(array $preview,array $overrides=[]): array
{
    return array_replace(['conversation_id'=>$preview['conversation_id'],'owner_request_id'=>$preview['owner_request_id'],'source_revision'=>$preview['source_revision'],
        'title'=>$preview['title'],'content'=>$preview['content'],'folder_id'=>10,'approve_content'=>true],$overrides);
}
function pk_denied_v1(callable $work,string $code,string $label): void
{
    try{$work();}catch(VP3ProfileKnowledgeException $e){pa3_check($e->errorCode===$code,$label.' ('.$e->errorCode.')');return;}
    throw new RuntimeException($label.' unexpectedly succeeded');
}
function pk_item_v1(int $id): array{$q=db()->prepare('SELECT * FROM knowledge_items WHERE id=?');$q->execute([$id]);return $q->fetch()?:throw new RuntimeException('Missing saved Knowledge item.');}
function pk_access_snapshot_v1(): array
{
    return ['policies'=>db()->query('SELECT * FROM user_data_policies ORDER BY owner_user_id,resource_type,resource_id')->fetchAll(),
        'rules'=>db()->query('SELECT * FROM user_agent_data_rules ORDER BY agent_id,resource_type,resource_id')->fetchAll()];
}
function pk_access_v1(bool $allow,string $audience,string $resourceId='*'): void
{
    $prior=user_data_policy_get_v236(db(),1,'knowledge',$resourceId);
    user_data_policy_save_v236(db(),pa3_owner(),['resource_type'=>'knowledge','resource_id'=>$resourceId,'profile_agent_allowed'=>$allow,'audience_scope'=>$audience,
        'owner_agents_allowed'=>$prior['owner_agents_allowed'],'stonefellow_shared'=>$prior['stonefellow_shared']]);
}
function pk_context_contains_v1(int $id,?int $visitor=null): bool
{
    $context=profile_agent_context(db(),profile_by_username(db(),'fixture-owner'),user_agent_get_v236(db(),1,7),$visitor===null?null:pa3_owner($visitor),'Aurora hours');
    return in_array('knowledge:'.$id,array_column($context,'source'),true);
}

pk_reset_v1();$preview=pk_answer_v1();
pa3_check($preview['contract']==='vp3.profile-knowledge.v1'&&preg_match('/^[a-f0-9]{64}$/D',$preview['source_revision'])===1,'preview binds the exact saved question and owner answer revision');
pa3_check($preview['question']==='What are the approved Aurora hours?'&&$preview['reply']==='Aurora opens at nine on weekdays.'&&str_contains($preview['content'],$preview['question'])&&str_contains($preview['content'],$preview['reply']),'review starts from actual visitor and owner text');
pa3_check($preview['folders']===[['id'=>10,'name'=>'Owner answers']]&&$preview['saved']===null,'preview exposes only existing owner folders and does not invent a saved item');
pa3_check(pa3_count('profile_agent_knowledge_saves_v1')===0&&pa3_count('knowledge_items')===3,'owner reply and preview alone do not retain or publish a new Knowledge note');
$wildcard=user_data_policy_get_v236($pdo,1,'knowledge');$beforeAccess=pk_access_snapshot_v1();$pdo->statements=[];
$private=vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['content'=>"Question: Aurora hours?\n\nAnswer: Nine on weekdays."]));$id=$private['saved']['knowledge_id'];$item=pk_item_v1($id);
pa3_check($private['created']&&$private['saved']['folder_id']===10&&$private['saved']['folder_name']==='Owner answers','Save to Knowledge uses the selected existing owner folder');
pa3_check($item['knowledge_scope']==='personal'&&(int)$item['created_by_user_id']===1&&$item['file_type']==='personal_note'&&$item['visibility']==='private'&&(int)$item['is_published']===0,'canonical store retains an ordinary private personal note');
pa3_check($item['content_text']==="Question: Aurora hours?\n\nAnswer: Nine on weekdays."&&pa3_count('knowledge_chunks','knowledge_id='.$id)>0,'the reviewed edited content is saved and indexed into canonical Knowledge chunks');
$policy=user_data_policy_get_v236($pdo,1,'knowledge',(string)$id);
pa3_check($policy['resource_id']==='*'&&!$policy['profile_agent_allowed']&&$policy['audience_scope']==='inherit','saved note uses the existing unchecked Allow and Existing visibility policy without an item override');
pa3_check(pk_access_snapshot_v1()===$beforeAccess&&user_data_policy_get_v236($pdo,1,'knowledge')===$wildcard,'saving preserves every existing data policy, Agent rule, audience and global Allow choice');
pa3_check(!array_filter($pdo->statements,static fn(string $sql): bool=>(bool)preg_match('/^\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+(?:user_data_policies|user_agent_data_rules)\b/i',$sql)),'saving issues no policy or Agent-rule mutation');
$agent=user_agent_get_v236($pdo,1,7);$public=user_agent_principal_v236(null,$agent,true);
pa3_check(!user_data_policy_can_use_v236($pdo,$public,1,'knowledge',(string)$id,false)&&!pk_context_contains_v1($id),'existing disabled Knowledge Access excludes the saved note from anonymous Profile context');
pa3_check(!array_filter($pdo->statements,static fn(string $sql): bool=>(bool)preg_match('/^\s*(CREATE|ALTER|DROP)\b/i',$sql)),'runtime preview/save performs no schema installation');
$link=$pdo->query('SELECT * FROM profile_agent_knowledge_saves_v1')->fetch();
pa3_check((int)$link['visitor_message_id']===$preview['visitor_message_id']&&(int)$link['reply_message_id']===$preview['reply_message_id']&&$link['source_revision']===$preview['source_revision']&&hash_equals($link['content_hash'],hash('sha256',$item['content_text'])),'filing receipt preserves exact source IDs, revision and reviewed content hash');
pa3_check(!column_exists('profile_agent_knowledge_saves_v1','profile_use_approved')&&!column_exists('profile_agent_knowledge_saves_v1','audience_scope'),'filing receipt stores provenance without parallel sharing approval or audience state');
$retry=vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['content'=>$item['content_text']]));
pa3_check(!$retry['created']&&$retry['saved']['knowledge_id']===$id&&pa3_count('profile_agent_knowledge_saves_v1')===1&&pa3_count('knowledge_items')===4,'identical retry returns one saved note and one provenance receipt');
$pdo->prepare('UPDATE knowledge_items SET title=?,content_text=? WHERE id=?')->execute(['Later owner edit','Later private content',$id]);
$retry=vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['content'=>$item['content_text']]));
pa3_check(pk_item_v1($id)['content_text']==='Later private content'&&$retry['saved']['title']==='Later owner edit','a lost-response retry never overwrites subsequent edits in Knowledge');
pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['content'=>'Different replacement'])),'PROFILE_KNOWLEDGE_ALREADY_SAVED','changed retry cannot repurpose a saved source receipt');

pk_reset_v1();$preview=pk_answer_v1();pk_access_v1(true,'public');$beforeAccess=pk_access_snapshot_v1();
$publicSave=vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['folder_id'=>0]));$publicId=$publicSave['saved']['knowledge_id'];
pa3_check($publicSave['saved']['folder_id']===0&&$publicSave['saved']['folder_name']==='Unfiled'&&pk_access_snapshot_v1()===$beforeAccess,'saving to Unfiled preserves an existing enabled public Knowledge Access policy');
pa3_check(pk_item_v1($publicId)['visibility']==='private'&&(int)pk_item_v1($publicId)['is_published']===0,'ordinary Knowledge save retains canonical private, unpublished note visibility');
pa3_check(pk_context_contains_v1($publicId),'existing public Knowledge Access permits the note in real anonymous Profile retrieval');
$foreign=user_agent_principal_v236(null,user_agent_get_v236($pdo,4,9),true);
pa3_check(!user_data_policy_can_use_v236($pdo,$foreign,1,'knowledge',(string)$publicId,false),'saving never grants another owner Profile Agent access');
foreach([[false,'public'],[true,'inherit'],[true,'private']] as [$allow,$audience]){
    pk_access_v1($allow,$audience);$beforeAccess=pk_access_snapshot_v1();
    vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['folder_id'=>0]));
    pa3_check(!pk_context_contains_v1($publicId)&&pk_access_snapshot_v1()===$beforeAccess,'current existing Knowledge Access revokes retrieval with Allow '.($allow?'on':'off').' and audience '.$audience.' without a save retry restoring it');
}
pk_access_v1(true,'connections');
$pdo->exec("INSERT INTO user_relationships VALUES(1,2,'connection','accepted'),(1,3,'collaborator','accepted')");
pa3_check(!pk_context_contains_v1($publicId)&&!pk_context_contains_v1($publicId,4)&&pk_context_contains_v1($publicId,2)&&pk_context_contains_v1($publicId,3),'Connections audience uses existing accepted relationship bounds for the retained note');
pk_access_v1(true,'collaborators');
pa3_check(!pk_context_contains_v1($publicId)&&!pk_context_contains_v1($publicId,2)&&pk_context_contains_v1($publicId,3),'Collaborators audience excludes anonymous visitors and ordinary connections');
$pdo->exec('DELETE FROM user_relationships WHERE owner_user_id=1 AND related_user_id=3');
pa3_check(!pk_context_contains_v1($publicId,3),'relationship revocation immediately removes retained Knowledge from that visitor context');
pk_access_v1(true,'public');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','*','deny')");
$beforeAccess=pk_access_snapshot_v1();vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['folder_id'=>0]));
pa3_check(!pk_context_contains_v1($publicId)&&pk_access_snapshot_v1()===$beforeAccess,'existing Agent deny remains unchanged and authoritative after a save retry');
$pdo->exec('DELETE FROM user_agent_data_rules WHERE agent_id=7');pk_access_v1(false,'private',(string)$publicId);
$beforeAccess=pk_access_snapshot_v1();vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['folder_id'=>0]));
pa3_check(!pk_context_contains_v1($publicId)&&pk_access_snapshot_v1()===$beforeAccess,'existing exact-item restrictions retain canonical precedence and are never replaced by saving');

foreach([
    ['approve_content'=>false],['approve_profile_use'=>null],['approve_profile_use'=>true,'audience_scope'=>'private'],['audience_scope'=>'public'],
    ['folder_id'=>-1],['owner_user_id'=>4],['title'=>''],['content'=>''],['title'=>str_repeat('x',191)],['content'=>str_repeat('x',32769)],
] as $bad){
    pk_reset_v1();$preview=pk_answer_v1();$failed=false;
    try{vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,$bad));}catch(VP3ProfileKnowledgeException $e){$failed=in_array($e->status,[403,409,422],true);}
    pa3_check($failed&&pa3_count('profile_agent_knowledge_saves_v1')===0&&pa3_count('knowledge_items')===3,'invalid or unapproved filing makes no Knowledge write: '.implode(',',array_keys($bad)));
}
pk_reset_v1();$preview=pk_answer_v1();
pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview,['folder_id'=>20])),'PROFILE_KNOWLEDGE_FOLDER_UNAVAILABLE','a foreign folder cannot receive this owner answer');
$pdo->prepare('UPDATE profile_agent_messages SET message=? WHERE id=?')->execute(['Changed owner answer',$preview['reply_message_id']]);
pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview)),'PROFILE_KNOWLEDGE_SOURCE_CHANGED','an answer changed after preview requires a new review');
pa3_check(pa3_count('profile_agent_knowledge_saves_v1')===0&&pa3_count('knowledge_items')===3,'stale source review cannot create a note');

foreach(['personal_knowledge.manage','personal_knowledge.access','profile_chat.access','profile_agent.access','account.access'] as $permission){
    pk_reset_v1();$preview=pk_answer_v1();$pdo->prepare('DELETE FROM role_permissions WHERE permission_key=?')->execute([$permission]);
    pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview)),'PROFILE_KNOWLEDGE_FORBIDDEN','current permission is required: '.$permission);
}
pk_reset_v1();$preview=pk_answer_v1();$owner=pa3_owner();pa3_login(4);
pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,$owner,pk_input_v1($preview)),'PROFILE_KNOWLEDGE_FORBIDDEN','an account switch cannot reuse a cached owner review');
pk_denied_v1(fn()=>vp3_profile_knowledge_preview_v1($pdo,pa3_owner(4),$preview['conversation_id'],$preview['owner_request_id']),'PROFILE_KNOWLEDGE_SOURCE_UNAVAILABLE','another active owner cannot preview foreign question or reply text');
pa3_login(1);$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,$owner,pk_input_v1($preview)),'PROFILE_KNOWLEDGE_FORBIDDEN','deactivated owner cannot use a still-open review');

foreach(['visitor','agent'] as $role){
    pk_reset_v1();$preview=pk_answer_v1();$pdo->prepare('UPDATE profile_agent_messages SET sender_type=? WHERE id=?')->execute([$role,$preview['reply_message_id']]);
    pk_denied_v1(fn()=>vp3_profile_knowledge_preview_v1($pdo,pa3_owner(),$preview['conversation_id'],$preview['owner_request_id']),'PROFILE_KNOWLEDGE_SOURCE_UNAVAILABLE','a '.$role.' message cannot masquerade as the owner answer');
}
pk_reset_v1();$preview=pk_answer_v1();$pdo->prepare('UPDATE profile_agent_owner_requests_v1 SET status=? WHERE id=?')->execute(['waiting',$preview['owner_request_id']]);
pk_denied_v1(fn()=>vp3_profile_knowledge_preview_v1($pdo,pa3_owner(),$preview['conversation_id'],$preview['owner_request_id']),'PROFILE_KNOWLEDGE_SOURCE_UNAVAILABLE','an unanswered request cannot be retained as an owner answer');

foreach(['INSERT INTO knowledge_chunks','INSERT INTO profile_agent_knowledge_saves_v1'] as $fault){
    pk_reset_v1();$preview=pk_answer_v1();$before=$pdo->query('SELECT * FROM user_data_policies ORDER BY owner_user_id,resource_type,resource_id')->fetchAll();$pdo->failSql=$fault;$failed=false;
    try{vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview));}catch(PDOException){$failed=true;}finally{$pdo->failSql='';}
    pa3_check($failed&&pa3_count('profile_agent_knowledge_saves_v1')===0&&pa3_count('knowledge_items')===3&&pa3_count('knowledge_chunks')===3&&$pdo->query('SELECT * FROM user_data_policies ORDER BY owner_user_id,resource_type,resource_id')->fetchAll()===$before,'failure rolls back note, chunks and provenance without touching existing policies at '.$fault);
}
pk_reset_v1();$preview=pk_answer_v1();$pdo->beforeSql=static function(string $sql,ProfileKnowledgeTestPDO $p): void{
    if(str_starts_with($sql,'INSERT INTO knowledge_items')){$p->beforeSql=null;$p->exec("DELETE FROM role_permissions WHERE permission_key='personal_knowledge.manage'");}
};
pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview)),'PROFILE_KNOWLEDGE_FORBIDDEN','revoked management permission before commit rolls back the entire save');
pa3_check(pa3_count('knowledge_items')===3&&pa3_count('profile_agent_knowledge_saves_v1')===0,'in-flight revocation leaves no retained or published answer');

pk_reset_v1();$preview=pk_answer_v1();$owner=pa3_owner();current_user();
$pdo->beforeSql=static function(string $sql,ProfileKnowledgeTestPDO $p): void{
    if(str_starts_with($sql,'INSERT INTO knowledge_items')){$p->beforeSql=null;$p->exec("UPDATE users SET password_hash='revoked-owner-credential' WHERE id=1");}
};
pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,$owner,pk_input_v1($preview)),'PROFILE_KNOWLEDGE_FORBIDDEN','final authority guard rechecks a cached login after credential revocation');
pa3_check(pa3_count('knowledge_items')===3&&pa3_count('profile_agent_knowledge_saves_v1')===0,'credential revocation before commit rolls back Knowledge, policy and provenance');

pk_reset_v1();$preview=pk_answer_v1();$pdo->exec('ALTER TABLE shared_knowledge_index RENAME TO profile_knowledge_hidden_index');$pdo->statements=[];
pk_denied_v1(fn()=>vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($preview)),'PROFILE_KNOWLEDGE_UPGRADE_REQUIRED','missing canonical index fails closed with an upgrade requirement');
pa3_check(!array_filter($pdo->statements,static fn(string $sql): bool=>(bool)preg_match('/^\s*(CREATE|ALTER|DROP)\b/i',$sql))&&pa3_count('knowledge_items')===3,'missing index does not trigger the legacy store installer or partial writes');
$pdo->exec('ALTER TABLE profile_knowledge_hidden_index RENAME TO shared_knowledge_index');
$pdo->beginTransaction();$blocked=false;try{vp3_profile_knowledge_install_v1($pdo);}catch(RuntimeException){$blocked=true;}$pdo->rollBack();
pa3_check($blocked,'explicit provenance installer refuses active transactions');
vp3_profile_knowledge_install_v1($pdo);pa3_check(vp3_profile_knowledge_ready_v1($pdo),'explicit installation is idempotent with canonical dependencies ready');

// Per-message history keeps older answered requests available even while the
// conversation-level response projection points at a later waiting question.
pk_reset_v1();$older=pk_answer_v1('An earlier answered question','The owner answered the earlier question.');
vp3_profile_knowledge_save_v1($pdo,pa3_owner(),pk_input_v1($older,['content'=>'PRIVATE_RETAINED_KNOWLEDGE_CONTENT']));
pa3_age();$newer=pa3_admit('A later question still awaiting the owner',$older['conversation_id']);pa3_login(1);
$plain=profile_agent_messages($pdo,$older['conversation_id']);$pdo->statements=[];
$decorated=vp3_profile_knowledge_messages_v1($pdo,1,$older['conversation_id'],$plain);
$ownerMessages=array_values(array_filter($decorated,static fn(array $message): bool=>$message['sender_type']==='owner'));
$requests=$ownerMessages[0]['knowledge_requests']??[];
pa3_check(count($requests)===1&&$requests[0]['id']===$older['owner_request_id']&&$requests[0]['reply_message_id']===$older['reply_message_id']
    &&vp3_profile_response_projection_v1($pdo,1,$older['conversation_id'])['request']['status']==='waiting','older answered request remains attached to its owner reply while latest request waits');
pa3_check($requests[0]['question_excerpt']===$older['question']&&array_keys($requests[0])===['id','conversation_id','visitor_message_id','reply_message_id','revision','question_excerpt']
    &&!str_contains(json_encode($decorated,JSON_THROW_ON_ERROR),'PRIVATE_RETAINED_KNOWLEDGE_CONTENT'),'history exposes only exact request identity and question excerpt, never saved Knowledge content or ID');
pa3_check(!array_filter($decorated,static fn(array $message): bool=>$message['sender_type']!=='owner'&&array_key_exists('knowledge_requests',$message)),'visitor, Agent and receipt messages receive no owner Knowledge metadata');
$stripped=array_map(static function(array $message): array{unset($message['knowledge_requests']);return $message;},$decorated);
pa3_check($stripped===$plain&&!array_filter($pdo->statements,static fn(string $sql): bool=>(bool)preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i',$sql)),'history decoration preserves all original messages and performs only reads');
$subset=array_values(array_filter($plain,static fn(array $message): bool=>$message['sender_type']!=='owner'));
pa3_check(vp3_profile_knowledge_messages_v1($pdo,1,$older['conversation_id'],$subset)===$subset,'requests whose owner reply is outside rendered history are not leaked into another message');
foreach([[4,$older['conversation_id']],[1,$older['conversation_id']+999]] as [$owner,$cid]){
    $unavailable=vp3_profile_knowledge_messages_v1($pdo,$owner,$cid,$plain);
    pa3_check(!array_filter($unavailable,static fn(array $message): bool=>!empty($message['knowledge_requests'])),'history metadata is bound to owner and conversation '.$owner.':'.$cid);
}
$pdo->exec('ALTER TABLE profile_agent_knowledge_saves_v1 RENAME TO profile_knowledge_hidden_saves');
$unavailable=vp3_profile_knowledge_messages_v1($pdo,1,$older['conversation_id'],$plain);
pa3_check(count($unavailable)===count($plain)&&!array_filter($unavailable,static fn(array $message): bool=>!empty($message['knowledge_requests'])),'missing optional Knowledge schema returns empty request lists without hiding chat');
$pdo->exec('ALTER TABLE profile_knowledge_hidden_saves RENAME TO profile_agent_knowledge_saves_v1');
$pdo->exec("DELETE FROM role_permissions WHERE permission_key='personal_knowledge.manage'");
$unavailable=vp3_profile_knowledge_messages_v1($pdo,1,$older['conversation_id'],$plain);
pa3_check(count($unavailable)===count($plain)&&!array_filter($unavailable,static fn(array $message): bool=>!empty($message['knowledge_requests'])),'revoked Knowledge management keeps chat visible without owner filing controls');

pk_reset_v1();$first=pa3_admit('First question covered by a shared owner reply');$cid=(int)$first['conversation_id'];
$firstRequest=vp3_profile_response_projection_v1($pdo,1,$cid)['request'];pa3_age();
$second=pa3_admit('Second question covered by the same owner reply',$cid);$secondRequest=vp3_profile_response_projection_v1($pdo,1,$cid)['request'];
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($second),'reply',['message'=>'One owner reply covers these two questions.']),true);pa3_login(1);
$decorated=vp3_profile_knowledge_messages_v1($pdo,1,$cid,profile_agent_messages($pdo,$cid));
$ownerMessages=array_values(array_filter($decorated,static fn(array $message): bool=>$message['sender_type']==='owner'));
$requests=$ownerMessages[0]['knowledge_requests']??[];
pa3_check(count($ownerMessages)===1&&count($requests)===2&&array_column($requests,'id')===[$firstRequest['id'],$secondRequest['id']]
    &&count(array_unique(array_column($requests,'reply_message_id')))===1,'one owner reply exposes every exact answered question in visitor-message order');
foreach($requests as $request){
    $selected=vp3_profile_knowledge_preview_v1($pdo,pa3_owner(),$cid,$request['id']);
    pa3_check($selected['visitor_message_id']===$request['visitor_message_id']&&$selected['reply_message_id']===$request['reply_message_id']
        &&$selected['question']===$request['question_excerpt'],'selecting question '.$request['id'].' opens the corresponding exact Knowledge review');
}

echo 'PROFILE_OWNER_KNOWLEDGE_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)."; real request, Knowledge, folders, policy and SQL)\n";
