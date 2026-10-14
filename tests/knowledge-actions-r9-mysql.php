<?php
declare(strict_types=1);
$knowledgeR9Acceptance=static function(PDO $pdo,array $user,string $key,string $ns,array $state,array $item):void{
    require_once __DIR__.'/../includes/knowledge-actions-r9.php';
    foreach(['Use these highlights to create a follow-up task.','Can you use this Knowledge to update Supplier contact?','Please draft a task from my research.'] as $q)check(knowledge_r9_intent($q),'Direct request was missed.');
    foreach(['How do Knowledge tasks work?','For example, create a task from Knowledge.','Use Knowledge for a hypothetical task.','Do not create a task from Knowledge.','Explain this research.','"Use Knowledge to create a task"','What if we update a contact from Knowledge?'] as $q)check(!knowledge_r9_intent($q),'Discussion/negation was interpreted as an action.');
    $input=['instruction'=>'Use these highlights to create a follow-up task and update Supplier contact.','references'=>[['kind'=>'annotation','id'=>$item['id'],'revision'=>$item['revision']]],'request_id'=>'r9-turn-1'];$noop=static function():void{};
    $model=static fn()=>json_encode(['answer'=>'Drafts ready','proposals'=>[
        ['operation'=>'task.create','source_indices'=>[0],'target_index'=>null,'fields'=>['title'=>'Verify reviewed quote','description'=>'Check the historical price with the supplier [P1].']],
        ['operation'=>'contacts.update','source_indices'=>[0],'target_index'=>0,'fields'=>['organization'=>'Reviewed supplier company']],
    ]]);
    $before=[(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM chat_knowledge_actions_r8')->fetchColumn(),$pdo->query('SELECT company FROM crm_contacts WHERE id=1')->fetchColumn()];
    $result=knowledge_r9_chat($pdo,$user,$key,$ns,$input,$noop,$model);check(count($result['drafts'])===2&&$result['contract']===VP3_KNOWLEDGE_ACTIONS_R9,'Multi-action draft missing.');
    check($before===[(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM chat_knowledge_actions_r8')->fetchColumn(),$pdo->query('SELECT company FROM crm_contacts WHERE id=1')->fetchColumn()],'Conversation draft executed a writer or prepared approval.');
    check(knowledge_r9_chat($pdo,$user,$key,$ns,$input,$noop,$model)['drafts'][0]['draft_id']===$result['drafts'][0]['draft_id'],'Same turn lost draft identity.');
    check(knowledge_r9_chat($pdo,$user,$key,$ns,array_replace($input,['request_id'=>'r9-turn-2']),$noop,$model)['drafts'][0]['draft_id']!==$result['drafts'][0]['draft_id'],'Different turns collapsed their identities.');
    $never=static function(){throw new RuntimeException('Model should not run for clarification.');};
    check(!knowledge_r9_chat($pdo,$user,$key,$ns,['instruction'=>'Use these highlights to update this contact.'],$noop,$never)['drafts'],'Unnamed destination was guessed.');
    check(!knowledge_r9_chat($pdo,$user,$key,$ns,['instruction'=>'Use highlights in this folder to create a task.'],$noop,$never)['drafts'],'Unselected folder was inferred.');
    check(!knowledge_r9_chat($pdo,$user,$key,$ns,['instruction'=>'How do tasks use Knowledge?'],$noop,$never)['drafts'],'Ordinary question called action model.');
    $pdo->exec("INSERT INTO crm_contacts SELECT 3,owner_user_id,name,company,email,email_normalized,phone,lifecycle_stage,status,updated_at FROM crm_contacts WHERE id=1");
    check(!knowledge_r9_chat($pdo,$user,$key,$ns,$input,$noop,$never)['drafts'],'Same-name contacts were guessed.');$pdo->exec('DELETE FROM crm_contacts WHERE id=3');
    foreach([
        ['operation'=>'contacts.create','source_indices'=>[0],'target_index'=>0,'fields'=>['title'=>'Injection']],
        ['operation'=>'task.create','source_indices'=>[999],'target_index'=>null,'fields'=>['title'=>'Invalid','description'=>'Invalid']],
        ['operation'=>'task.create','source_indices'=>[0,0],'target_index'=>null,'fields'=>['title'=>'Duplicate','description'=>'Duplicate']],
        ['operation'=>'task.create','source_indices'=>[0],'target_index'=>null,'fields'=>['tool'=>'execute']],
    ] as $bad){try{knowledge_r9_chat($pdo,$user,$key,$ns,$input,$noop,static fn()=>json_encode(['answer'=>'bad','proposals'=>[$bad]]));throw new RuntimeException('Unsupported model proposal accepted.');}catch(InvalidArgumentException $e){}catch(RuntimeException $e){check($e->getCode()===422,'Wrong model rejection.');}}
    $draft=$result['drafts'][0];knowledge_r9_validate($pdo,$user,$key,$ns,$draft);$restored=knowledge_r9_restore($pdo,$user,$key,$ns,$draft);check($restored['evidence']['fingerprint']===$draft['draft_fingerprint'],'Current historical draft revalidation failed.');
    $prepare=array_replace($draft,['request_id'=>'19191919-1919-4919-8919-191919191919','fields'=>['title'=>'Edited conversational task','description'=>'Owner-edited exact task body [P1].']]);
    $review=knowledge_r8_prepare($pdo,$user,$key,$ns,$prepare,$noop);check($review['status']==='pending'&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===$before[0],'Preparing exact review executed task.');
    $decision=['id'=>$review['id'],'expected_hash'=>$review['review_hash'],'confirmed'=>true,'decision'=>'apply'];
    $done=knowledge_r8_decide($pdo,$user,$key,$ns,$decision,$noop);knowledge_r8_decide($pdo,$user,$key,$ns,$decision,$noop);$retry=knowledge_r8_prepare($pdo,$user,$key,$ns,$prepare,$noop);
    check($retry['status']==='applied'&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===$before[0]+1,'Conversational exact retry duplicated task.');
    $contact=$result['drafts'][1];$old=$pdo->query('SELECT company FROM crm_contacts WHERE id=1')->fetchColumn();$pdo->exec("UPDATE crm_contacts SET company='Changed after draft' WHERE id=1");
    rejected_r7(fn()=>knowledge_r9_validate($pdo,$user,$key,$ns,$contact),409);
    rejected_r7(fn()=>knowledge_r8_prepare($pdo,$user,$key,$ns,array_replace($contact,['request_id'=>'29292929-2929-4929-8929-292929292929']),$noop),409);
    $pdo->prepare('UPDATE crm_contacts SET company=? WHERE id=1')->execute([$old]);
    $original=$pdo->prepare('SELECT snapshot_hash FROM browser_shares_v2010 WHERE public_id=?');$original->execute([$item['id']]);$hash=$original->fetchColumn();
    rejected_r7(fn()=>knowledge_r9_chat($pdo,$user,$key,$ns,$input,$noop,static function()use($pdo,$item,$model){$pdo->prepare('UPDATE browser_shares_v2010 SET snapshot_hash=? WHERE public_id=?')->execute([hash('sha256','R9 model in-flight mutation'),$item['id']]);return $model();}),409);
    $pdo->prepare('UPDATE browser_shares_v2010 SET snapshot_hash=? WHERE public_id=?')->execute([$hash,$item['id']]);
    $GLOBALS['grant']=false;rejected_r7(fn()=>knowledge_r9_restore($pdo,$user,$key,$ns,$draft),403);$GLOBALS['grant']=true;
    if(isset($GLOBALS['knowledgeR10Acceptance']))($GLOBALS['knowledgeR10Acceptance'])($pdo,$user,$key,$ns,$state,$item);
    echo "PASS: R9 direct intent vs discussion, two draft-only actions, exact source selection, named/duplicate destination clarification, strict untrusted-model validation, current historical draft restoration, source/target races, edited exact review, canonical write and duplicate-free retry.\n";
};
require __DIR__.'/knowledge-actions-r8-mysql.php';
