<?php
declare(strict_types=1);
// Real R4/R5/R6/R7 source authority and InnoDB writes; only identity/model outputs are controlled.
function vp3_browser_research_mission_row_v2230(PDO $pdo,int $uid,string $id,bool $lock=false):?array {
    $q=$pdo->prepare('SELECT payload FROM fixture_refresh_missions WHERE id=? AND owner_user_id=?');$q->execute([$id,$uid]);$raw=$q->fetchColumn();if(!$raw)return null;$m=json_decode($raw,true);
    $report=vp3_research_report_row_v2060($pdo,(string)($m['report']['id']??''));
    return $report?['status'=>'completed','report_public_id'=>$m['report']['id'],'project_id'=>$report['project_id'],'saved_at'=>'2026-10-13 00:00:00']:null;
}
$knowledgeR8Acceptance=static function(PDO $pdo,array $user,string $key,string $ns,array $state,array $item,array $refresh):void{
    require_once __DIR__.'/../includes/knowledge-actions-r8.php';
    require_once __DIR__.'/../includes/agent-brain-v82.php';
    require_once __DIR__.'/../includes/agent-memory-scope-v410.php';
    knowledge_r8_ensure_schema($pdo);knowledge_r8_ensure_schema($pdo);workspace_native_schema_v1($pdo);
    $pdo->exec("CREATE TABLE agent_chat_archive(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_agent_id INT NULL,created_at DATETIME,KEY idx_agent_archive_agent_created_v410(user_agent_id,created_at));
      CREATE TABLE agent_memory_items(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT,user_agent_id INT NULL,memory_type VARCHAR(40),subject VARCHAR(190),memory_text TEXT,memory_hash CHAR(40),memory_scope_version INT DEFAULT 410,source_archive_id BIGINT NULL,confidence DOUBLE,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,is_active INT,metadata_json TEXT,UNIQUE KEY uniq_memory(user_id,memory_hash),KEY idx_agent_memory_agent_type_v410(user_id,user_agent_id,memory_type),KEY idx_agent_memory_agent_occurrence_v410(user_id,user_agent_id,occurrence_count));
      CREATE TABLE crm_contacts(id BIGINT PRIMARY KEY,owner_user_id INT,name VARCHAR(120),company VARCHAR(190),email VARCHAR(190),email_normalized VARCHAR(190),phone VARCHAR(80),lifecycle_stage VARCHAR(80),status VARCHAR(20),updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
      INSERT INTO crm_contacts VALUES(1,7,'Supplier','Old company','old@example.com','old@example.com','555-0000','active','active',CURRENT_TIMESTAMP),(2,8,'Other','Other','other@example.com','other@example.com','','active','active',CURRENT_TIMESTAMP);");
    vp3_agent_memory_scope_set_current_v410(7,0);
    $refs=[['kind'=>'annotation','id'=>$item['id'],'revision'=>$item['revision']]];$noop=static function():void{};
    $base=['request_id'=>'10101010-1010-4010-8010-101010101010','references'=>$refs,'state_revision'=>$state['revision'],'operation'=>'task.create','target_key'=>'','fields'=>['title'=>'Verify supplier flour price','description'=>'Check the captured supplier quote before ordering. [P1]']];
    $options=knowledge_r8_options($pdo,$user,$key,$ns);check(count($options['sources'])>0&&count($options['targets']['contacts.update'])===1,'R8 source/target ownership projection failed.');
    $p=knowledge_r8_prepare($pdo,$user,$key,$ns,$base,$noop);check($p['status']==='pending'&&$p['preview']['fields']['title']===$base['fields']['title'],'Exact task preview missing.');
    check((int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===0,'Preparation executed a task.');
    check(knowledge_r8_prepare($pdo,$user,$key,$ns,$base,$noop)['id']===$p['id'],'Prepare retry duplicated proposal.');
    rejected_r7(fn()=>knowledge_r8_prepare($pdo,$user,$key,$ns,array_replace($base,['fields'=>['title'=>'Changed','description'=>'Different']]),$noop),409);
    rejected_r7(fn()=>knowledge_r8_prepare($pdo,$user,$key,$ns,array_replace($base,['request_id'=>'20202020-2020-4020-8020-202020202020']),static function(){throw new RuntimeException('Revoked',403);}),403);
    $decision=['id'=>$p['id'],'expected_hash'=>$p['review_hash'],'confirmed'=>true,'decision'=>'apply'];
    rejected_r7(fn()=>knowledge_r8_decide($pdo,$user,$key,$ns,array_replace($decision,['expected_hash'=>str_repeat('0',64)]),$noop),409);
    $calls=0;rejected_r7(fn()=>knowledge_r8_decide($pdo,$user,$key,$ns,$decision,static function()use(&$calls){if(++$calls===2)throw new RuntimeException('Final authority revoked',403);}),403);
    check((int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===0&&!$pdo->inTransaction(),'Final-authority failure left a partial task.');
    $done=knowledge_r8_decide($pdo,$user,$key,$ns,$decision,$noop);$again=knowledge_r8_decide($pdo,$user,$key,$ns,$decision,$noop);
    check($done['status']==='applied'&&$done['result']===$again['result']&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1,'Apply retry duplicated task.');
    $task=$pdo->query('SELECT * FROM agent_memory_items')->fetch(PDO::FETCH_ASSOC);check($task['subject']===$base['fields']['title']&&$task['memory_text']===$base['fields']['description']&&json_decode($task['metadata_json'],true)['evidence'][0]['revision']===$item['revision'],'Canonical task differs from preview/evidence.');
    // Same title is a separate explicit proposal and never overwrites unrelated work.
    $p2=knowledge_r8_prepare($pdo,$user,$key,$ns,array_replace($base,['request_id'=>'30303030-3030-4030-8030-303030303030','fields'=>['title'=>$base['fields']['title'],'description'=>'Second independent task.']]),$noop);
    $d2=['id'=>$p2['id'],'expected_hash'=>$p2['review_hash'],'confirmed'=>true,'decision'=>'apply'];knowledge_r8_decide($pdo,$user,$key,$ns,$d2,$noop);check((int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===2,'Same-title proposal overwrote prior task.');
    $contact=array_replace($base,['request_id'=>'40404040-4040-4040-8040-404040404040','operation'=>'contacts.update','target_key'=>'crm_contacts:1','fields'=>['email'=>' NEW@EXAMPLE.COM ','organization'=>'Supplier verified']]);
    $p=knowledge_r8_prepare($pdo,$user,$key,$ns,$contact,$noop);check($p['preview']['fields']['email']==='new@example.com'&&$p['preview']['before']['email']==='old@example.com','Normalized exact contact review mismatch.');
    $d=['id'=>$p['id'],'expected_hash'=>$p['review_hash'],'confirmed'=>true,'decision'=>'apply'];
    $pdo->exec("UPDATE crm_contacts SET company='Independent edit' WHERE id=1");rejected_r7(fn()=>knowledge_r8_decide($pdo,$user,$key,$ns,$d,$noop),409);
    check(knowledge_r8_view($pdo,$user,$key,$ns,knowledge_r8_row($pdo,$user,$key,$ns,$p['id']))['status']==='stale','Changed destination not marked stale.');
    knowledge_r8_decide($pdo,$user,$key,$ns,array_replace($d,['decision'=>'dismiss']),$noop);
    $contact['request_id']='50505050-5050-4050-8050-505050505050';$p=knowledge_r8_prepare($pdo,$user,$key,$ns,$contact,$noop);$d=['id'=>$p['id'],'expected_hash'=>$p['review_hash'],'confirmed'=>true,'decision'=>'apply'];$contactDone=knowledge_r8_decide($pdo,$user,$key,$ns,$d,$noop);check($pdo->query('SELECT email FROM crm_contacts WHERE id=1')->fetchColumn()==='new@example.com','Existing CRM writer did not apply exact email.');
    rejected_r7(fn()=>knowledge_r8_prepare($pdo,$user,$key,$ns,array_replace($contact,['request_id'=>'60606060-6060-4060-8060-606060606060','target_key'=>'crm_contacts:2']),$noop),409);
    $q=$pdo->prepare("INSERT INTO knowledge_items(folder_id,title,description,file_name,file_path,file_type,mime_type,file_size,content_text,visibility,is_published,created_by_user_id,knowledge_scope) VALUES(1,'Separate working document','','working.txt','','personal_note','text/plain',0,'Earlier working text','private',1,7,'personal')");$q->execute();$document=(int)$pdo->lastInsertId();
    $doc=array_replace($base,['request_id'=>'70707070-7070-4070-8070-707070707070','operation'=>'knowledge.update','target_key'=>'knowledge_items:'.$document,'fields'=>['content_text'=>'Reviewed working text with pinned source [P1].']]);$p=knowledge_r8_prepare($pdo,$user,$key,$ns,$doc,$noop);$d=['id'=>$p['id'],'expected_hash'=>$p['review_hash'],'confirmed'=>true,'decision'=>'apply'];$docDone=knowledge_r8_decide($pdo,$user,$key,$ns,$d,$noop);check($pdo->query('SELECT content_text FROM knowledge_items WHERE id='.$document)->fetchColumn()===$doc['fields']['content_text'],'Existing document writer diverged from preview.');
    rejected_r7(fn()=>knowledge_r8_prepare($pdo,$user,$key,$ns,array_replace($doc,['request_id'=>'80808080-8080-4080-8080-808080808080','target_key'=>'knowledge_items:'.$refresh['knowledge_id']]),$noop),422);
    $suggest=array_replace($base,['instruction'=>'Create a verification follow-up task']);$draft=knowledge_r8_suggest($pdo,$user,$key,$ns,$suggest,$noop,static fn()=>'{"title":"Model draft","description":"Verify the original source [P1]."}');check($draft['fields']['title']==='Model draft'&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===2,'Suggestion mutated records.');
    try{knowledge_r8_suggest($pdo,$user,$key,$ns,$suggest,$noop,static fn()=>'{"tool":"execute"}');throw new RuntimeException('Model tool injection accepted.');}catch(InvalidArgumentException $e){}
    // Changes outside a truncated displayed excerpt still invalidate a frozen proposal.
    $q=$pdo->prepare('SELECT snapshot_hash FROM browser_shares_v2010 WHERE public_id=?');$q->execute([$item['id']]);$originalHash=$q->fetchColumn();
    $pending=knowledge_r8_prepare($pdo,$user,$key,$ns,array_replace($base,['request_id'=>'90909090-9090-4090-8090-909090909090']),$noop);
    $pdo->prepare('UPDATE browser_shares_v2010 SET snapshot_hash=? WHERE public_id=?')->execute([hash('sha256','changed source receipt'),$item['id']]);
    rejected_r7(fn()=>knowledge_r8_decide($pdo,$user,$key,$ns,['id'=>$pending['id'],'expected_hash'=>$pending['review_hash'],'confirmed'=>true,'decision'=>'apply'],$noop),409);
    $pdo->prepare('UPDATE browser_shares_v2010 SET snapshot_hash=? WHERE public_id=?')->execute([$originalHash,$item['id']]);
    rejected_r7(fn()=>knowledge_r8_suggest($pdo,$user,$key,$ns,$suggest,$noop,static function()use($pdo,$item){$pdo->prepare('UPDATE browser_shares_v2010 SET snapshot_hash=? WHERE public_id=?')->execute([hash('sha256','changed during model'),$item['id']]);return '{"title":"Stale draft","description":"Do not return this changed-source draft."}';}),409);
    $pdo->prepare('UPDATE browser_shares_v2010 SET snapshot_hash=? WHERE public_id=?')->execute([$originalHash,$item['id']]);
    $changed=$refs;$changed[0]['revision']=str_repeat('f',64);rejected_r7(fn()=>knowledge_r8_packet($pdo,$user,$key,$ns,$changed,$state['revision']),409);
    $record=$done['result'];$body=json_encode(['records'=>[['table'=>'knowledge_action_receipts_r8','data'=>['record_key'=>$record['record_key'],'record_revision'=>$record['record_revision']]]]]);workspace_sync_store_v1($pdo,7,'homeserver','chats',['body'=>$body,'revision'=>hash('sha256',$body),'byte_count'=>strlen($body),'record_count'=>1]);check(knowledge_r8_view($pdo,$user,$key,$ns,knowledge_r8_row($pdo,$user,$key,$ns,$base['request_id']))['sync']['state']==='received','Exact action receipt not projected.');
    if($path=getenv('VP3_KNOWLEDGE_R8_EXPORT'))file_put_contents($path,json_encode(['task'=>$record,'contact'=>$contactDone['result'],'document'=>$docDone['result'],'datasets'=>array_map(static fn($dataset)=>json_decode(workspace_sync_export_v1($pdo,7,$dataset)['body'],true),['agents','contacts','knowledge'])],JSON_THROW_ON_ERROR));
    $pdo->exec("UPDATE agent_memory_items SET memory_text='Independently changed' WHERE id=".(int)$task['id']);check(knowledge_r8_view($pdo,$user,$key,$ns,knowledge_r8_row($pdo,$user,$key,$ns,$base['request_id']))['status']==='superseded','Old task receipt falsely reported current result.');
    $GLOBALS['grant']=false;rejected_r7(fn()=>knowledge_r8_view($pdo,$user,$key,$ns,knowledge_r8_row($pdo,$user,$key,$ns,$base['request_id'])),403);$GLOBALS['grant']=true;
    echo "PASS: R8 actual source authority, exact immutable task/CRM/document preview, model draft only, canonical existing writers, same-title isolation, stable retries, stale/foreign destinations, evidence hashes, final-authority rollback, protected originals and received/superseded receipts.\n";
};
require __DIR__.'/project-knowledge-r7-mysql.php';
