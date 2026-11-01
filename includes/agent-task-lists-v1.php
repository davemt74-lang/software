<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-receipts-v1.php';

/** Lists contain references to canonical Brain tasks, never a second task payload. DDL runs only in upgrade. */
function vp3_task_lists_schema_v1(PDO $pdo): void
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $id=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_task_lists_v1(id $id,owner_user_id INT UNSIGNED NOT NULL,agent_scope_id BIGINT NOT NULL DEFAULT 0,name VARCHAR(120) NOT NULL,name_key VARCHAR(64) NOT NULL,created_at VARCHAR(20) NOT NULL,UNIQUE(owner_user_id,agent_scope_id,name_key),FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_task_list_items_v1(list_id BIGINT UNSIGNED NOT NULL,task_id BIGINT UNSIGNED NOT NULL,item_key VARCHAR(64) NOT NULL,created_at VARCHAR(20) NOT NULL,PRIMARY KEY(list_id,task_id),UNIQUE(list_id,item_key),FOREIGN KEY(list_id) REFERENCES agent_task_lists_v1(id) ON DELETE CASCADE,FOREIGN KEY(task_id) REFERENCES agent_memory_items(id) ON DELETE CASCADE)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_task_list_actions_v1(token VARCHAR(32) PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,agent_scope_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,action_json TEXT NOT NULL,status VARCHAR(16) NOT NULL,expires_at BIGINT NOT NULL,result_json TEXT NULL,FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)$tail");
}
function vp3_task_lists_ready_v1(PDO $pdo): bool
{
    foreach(['agent_task_lists_v1','agent_task_list_items_v1','agent_task_list_actions_v1'] as $t)if(!table_exists($t))return false;
    return agent_brain_schema_ready()&&vp3_agent_memory_scope_schema_ready_v410($pdo);
}
function vp3_task_list_text_v1(mixed $v,int $max): string
{
    if(!is_string($v)||mb_strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/u',$v))throw new InvalidArgumentException('Invalid task/list text.');
    return trim(preg_replace('/\s+/u',' ',$v)??'');
}
function vp3_task_list_key_v1(string $v): string{return hash('sha256',mb_strtolower(vp3_task_list_text_v1($v,240)));}
function vp3_task_list_agent_v1(array $p): int{return ($p['kind']??'')==='user_agent'?(int)$p['agent_id']:0;}
function vp3_task_list_authorize_v1(PDO $pdo,array $u,array $p,int $cid): void
{
    $q=$pdo->prepare('SELECT is_active FROM users WHERE id=?');$q->execute([(int)$u['id']]);
    if(!$q->fetchColumn()||!knowledge_retrieval_v162_owner_session($u,$p)||!has_permission('account.access',$u)||!has_permission('chat.access',$u)||!personal_capability_has_v242('agent_brain.access',$u))throw new RuntimeException('Task and list access is unavailable.',403);
    if($cid>0){$aid=vp3_task_list_agent_v1($p);$q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND '.($aid?'user_agent_id=?':'user_agent_id IS NULL'));$q->execute($aid?[$cid,(int)$u['id'],$aid]:[$cid,(int)$u['id']]);if(!$q->fetchColumn())throw new RuntimeException('Conversation not found.',403);}
}
function vp3_task_list_pending_v1(PDO $pdo,array $u,array $p,array $ctx): ?array
{
    if(!vp3_task_lists_ready_v1($pdo))return null;
    $cid=(int)($ctx['conversation_id']??0);if($cid<1)return null;
    vp3_task_list_authorize_v1($pdo,$u,$p,$cid);
    $q=$pdo->prepare("SELECT id,context_json FROM chat_messages WHERE conversation_id=? AND role='assistant' ORDER BY id DESC LIMIT 1");$q->execute([$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
    $data=json_decode((string)$row['context_json'],true);$token=$data['task_context']['pending_token']??'';
    if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token))return null;
    $q=$pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=? AND role='user' AND id>?");$q->execute([$cid,(int)$row['id']]);if((int)$q->fetchColumn()>1)return null;
    $q=$pdo->prepare("SELECT * FROM agent_task_list_actions_v1 WHERE token=? AND owner_user_id=? AND agent_scope_id=? AND conversation_id=? AND status='prepared' AND expires_at>=?");$q->execute([$token,(int)$u['id'],vp3_task_list_agent_v1($p),$cid,time()]);$action=$q->fetch(PDO::FETCH_ASSOC);
    return $action?['token'=>$token,'action'=>json_decode($action['action_json'],true)]:null;
}
function vp3_task_list_find_v1(PDO $pdo,array $u,array $p,string $name): ?array
{
    $q=$pdo->prepare('SELECT * FROM agent_task_lists_v1 WHERE owner_user_id=? AND agent_scope_id=? AND name_key=?');$q->execute([(int)$u['id'],vp3_task_list_agent_v1($p),vp3_task_list_key_v1($name)]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function vp3_task_list_task_allowed_v1(PDO $pdo,array $u,array $p,int $id): bool
{
    return vp3_task_list_agent_v1($p)===0||!function_exists('user_data_policy_can_use_v236')||user_data_policy_can_use_v236($pdo,$p,(int)$u['id'],'knowledge','brain:'.$id,false);
}
function vp3_task_list_existing_task_v1(PDO $pdo,array $u,array $p,int $id): array
{
    $aid=vp3_task_list_agent_v1($p);$q=$pdo->prepare("SELECT id,subject FROM agent_memory_items WHERE id=? AND user_id=? AND ".($aid?'user_agent_id=?':'user_agent_id IS NULL')." AND is_active=1 AND memory_type IN ('task','commitment')");$q->execute($aid?[$id,(int)$u['id'],$aid]:[$id,(int)$u['id']]);$t=$q->fetch(PDO::FETCH_ASSOC);
    if(!$t||!vp3_task_list_task_allowed_v1($pdo,$u,$p,$id))throw new RuntimeException('Task unavailable in this Agent.',403);return $t;
}
function vp3_task_list_note_v1(PDO $pdo,array $u,array $p,int $id): array
{
    vp3_task_list_authorize_v1($pdo,$u,$p,0);
    $aid=vp3_task_list_agent_v1($p);$q=$pdo->prepare('SELECT * FROM agent_task_lists_v1 WHERE id=? AND owner_user_id=? AND agent_scope_id=?');$q->execute([$id,(int)$u['id'],$aid]);$list=$q->fetch(PDO::FETCH_ASSOC);if(!$list)throw new RuntimeException('List not found.',404);
    $q=$pdo->prepare("SELECT t.id,t.subject,t.metadata_json FROM agent_task_list_items_v1 i JOIN agent_memory_items t ON t.id=i.task_id WHERE i.list_id=? AND t.user_id=? AND ".($aid?'t.user_agent_id=?':'t.user_agent_id IS NULL')." AND t.is_active=1 AND t.memory_type IN ('task','commitment') ORDER BY i.created_at,t.id LIMIT 201");$q->execute($aid?[$id,(int)$u['id'],$aid]:[$id,(int)$u['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=[];
    foreach(array_slice($rows,0,200) as $t){if(!vp3_task_list_task_allowed_v1($pdo,$u,$p,(int)$t['id']))continue;$meta=json_decode((string)$t['metadata_json'],true);$status=$meta['task_status']??'open';$items[]=['id'=>(int)$t['id'],'title'=>$t['subject'],'status'=>$status,'done'=>in_array($status,['completed','cancelled'],true)];}
    return ['kind'=>'task_list','id'=>$id,'title'=>$list['name'],'items'=>$items,'truncated'=>count($rows)>200];
}
function vp3_task_list_result_v1(string $text,string $op,string $status='read_complete',int $id=0,int $count=0): array
{
    return ['handled'=>true,'answer'=>$text,'actions'=>[],'sources'=>[],'task_context'=>['pending_token'=>null],'sticky_notes'=>[],'tool_receipt'=>vp3_tool_receipt_v1('tasks.lists',$op,$status,'',$id,$count)];
}
/** Frozen missing-list proposal; a yes can confirm only the last authoritative assistant question. */
function vp3_task_list_prepare_v1(PDO $pdo,array $u,array $p,int $cid,array $action): array
{
    if($cid<1)throw new InvalidArgumentException('Open a conversation before creating a list.');
    $token=bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO agent_task_list_actions_v1(token,owner_user_id,agent_scope_id,conversation_id,action_json,status,expires_at) VALUES(?,?,?,?,?,'prepared',?)")->execute([$token,(int)$u['id'],vp3_task_list_agent_v1($p),$cid,json_encode($action,JSON_THROW_ON_ERROR),time()+900]);
    $text='You don’t have a “'.$action['list_name'].'” list yet. Create it'.($action['title']!==''?' and add “'.$action['title'].'”':'').'?';
    $r=vp3_task_list_result_v1($text,'prepare','clarification_required');$r['task_context']=['pending_token'=>$token];return $r;
}
function vp3_task_list_save_task_v1(PDO $pdo,array $u,array $p,string $title,?array $list,string $identity,int $cid): int
{
    if($list){$q=$pdo->prepare('SELECT task_id FROM agent_task_list_items_v1 WHERE list_id=? AND item_key=?');$q->execute([(int)$list['id'],vp3_task_list_key_v1($title)]);$existing=(int)$q->fetchColumn();if($existing){vp3_task_list_existing_task_v1($pdo,$u,$p,$existing);return $existing;}}
    $old=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;vp3_agent_memory_scope_set_current_v410((int)$u['id'],vp3_task_list_agent_v1($p));
    try{
        // The canonical Brain upsert key is an immutable action identity, independent of task title.
        $subject='task-list-action:'.$identity;
        $id=agent_brain_v122_upsert_system_memory($u,'task',$subject,$title,['task_status'=>'open','task_kind'=>'task','conversation_id'=>$cid,'source_kind'=>'task_list_v1','task_title'=>$title],.99);
        if($id<1)throw new RuntimeException('Task save was not verified.');
        $q=$pdo->prepare("UPDATE agent_memory_items SET subject=? WHERE id=? AND user_id=? AND memory_type='task'");$q->execute([$title,$id,(int)$u['id']]);
        $q=$pdo->prepare('SELECT subject FROM agent_memory_items WHERE id=? AND user_id=?');$q->execute([$id,(int)$u['id']]);if($q->fetchColumn()!==$title)throw new RuntimeException('Task title was not verified.');
        if($list)$pdo->prepare('INSERT INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(?,?,?,?)')->execute([(int)$list['id'],$id,vp3_task_list_key_v1($title),gmdate('Y-m-d\TH:i:s\Z')]);
        return $id;
    }finally{if($old===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$old;}
}
/** Validated, model-selected commands; owner row serialization protects confirm/add races. */
function vp3_task_list_execute_v1(PDO $pdo,array $u,array $p,array $plan,array $ctx): array
{
    $pending=null;$op=$plan['action'];$cid=(int)($ctx['conversation_id']??0);$uid=(int)$u['id'];$aid=vp3_task_list_agent_v1($p);
    try{
        vp3_task_list_authorize_v1($pdo,$u,$p,$cid);
        if(!vp3_task_lists_ready_v1($pdo))return [...vp3_task_list_result_v1('Run upgrade.php to enable task lists.','show','blocked'),'tool_receipt'=>vp3_tool_receipt_v1('tasks.lists','show','blocked','upgrade_required')];
        $name=vp3_task_list_text_v1($plan['list_name']??'',120);$title=vp3_task_list_text_v1($plan['title']??'',180);$existingId=(int)($plan['task_id']??0);
        if($existingId){$existingTask=vp3_task_list_existing_task_v1($pdo,$u,$p,$existingId);$title=$existingTask['subject'];}
        if($op==='show_lists'){
            $q=$pdo->prepare('SELECT id,name FROM agent_task_lists_v1 WHERE owner_user_id=? AND agent_scope_id=? ORDER BY name LIMIT 101');$q->execute([$uid,$aid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            return vp3_task_list_result_v1($rows?"Your lists:\n".implode("\n",array_map(static fn($r)=>$r['name'],array_slice($rows,0,100))).(count($rows)>100?"\nShowing the first 100 lists.":''):'You have no saved lists yet.','show_lists',$rows?'read_complete':'no_matches',0,min(count($rows),100));
        }
        if($op==='show'){
            if($name==='')return vp3_task_list_result_v1('Which list would you like to show?','show','clarification_required');
            $list=vp3_task_list_find_v1($pdo,$u,$p,$name);if(!$list)return vp3_task_list_result_v1('You don’t have a “'.$name.'” list yet. Ask me to create it or add an item.','show','no_matches');
            $note=vp3_task_list_note_v1($pdo,$u,$p,(int)$list['id']);$r=vp3_task_list_result_v1('Showing “'.$list['name'].'”.'.($note['items']?'':' It is empty.'),'show','read_complete',(int)$list['id'],count($note['items']));$r['sticky_notes']=[['kind'=>'task_list','id'=>(int)$list['id']]];return $r;
        }
        $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid]);
        vp3_task_list_authorize_v1($pdo,$u,$p,$cid);
        if(in_array($op,['confirm','cancel'],true)){
            // Reload the pending source under the lock; caller/model fields cannot supply a token.
            $pending=vp3_task_list_pending_v1($pdo,$u,$p,$ctx);
            if(!$pending){$pdo->commit();return vp3_task_list_result_v1('There is no current list creation awaiting confirmation. Tell me what you want to add.',$op,'clarification_required');}
            if($op==='cancel'){$pdo->prepare("UPDATE agent_task_list_actions_v1 SET status='cancelled' WHERE token=? AND status='prepared'")->execute([$pending['token']]);$pdo->commit();return vp3_task_list_result_v1('Cancelled. No list or task was created.','cancel');}
            $name=$pending['action']['list_name'];$title=$pending['action']['title'];$op=$pending['action']['action'];$existingId=(int)($pending['action']['task_id']??0);if($existingId){$t=vp3_task_list_existing_task_v1($pdo,$u,$p,$existingId);$title=$t['subject'];}
        }
        if($op==='create_task'){
            if($title==='')throw new InvalidArgumentException('What task should I save?');
            $id=vp3_task_list_save_task_v1($pdo,$u,$p,$title,null,bin2hex(random_bytes(16)),$cid);
            $r=vp3_task_list_result_v1('Saved “'.$title.'” as task #'.$id.'.','create_task','completed',$id,1);
        }else{
            if($name==='')throw new InvalidArgumentException('Which list should I use?');
            if($op==='add'&&$title==='')throw new InvalidArgumentException('What should I add to “'.$name.'”?');
            $list=vp3_task_list_find_v1($pdo,$u,$p,$name);
            if(!$list&&!$pending){$r=vp3_task_list_prepare_v1($pdo,$u,$p,$cid,['action'=>$op,'list_name'=>$name,'title'=>$title,'task_id'=>$existingId]);$pdo->commit();return $r;}
            $created=false;
            if(!$list){$pdo->prepare('INSERT INTO agent_task_lists_v1(owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(?,?,?,?,?)')->execute([$uid,$aid,$name,vp3_task_list_key_v1($name),gmdate('Y-m-d\TH:i:s\Z')]);$list=vp3_task_list_find_v1($pdo,$u,$p,$name);if(!$list)throw new RuntimeException('List save was not verified.');$created=true;}
            $id=0;$duplicate=false;
            if($title!==''){$q=$pdo->prepare('SELECT task_id FROM agent_task_list_items_v1 WHERE list_id=? AND item_key=?');$q->execute([(int)$list['id'],vp3_task_list_key_v1($title)]);$duplicate=(bool)$q->fetchColumn();if($existingId&&!$duplicate){vp3_task_list_existing_task_v1($pdo,$u,$p,$existingId);$pdo->prepare('INSERT INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(?,?,?,?)')->execute([(int)$list['id'],$existingId,vp3_task_list_key_v1($title),gmdate('Y-m-d\TH:i:s\Z')]);$id=$existingId;}else $id=vp3_task_list_save_task_v1($pdo,$u,$p,$title,$list,$pending['token']??bin2hex(random_bytes(16)),$cid);}
            $text=($created?'Created “'.$list['name'].'”. ':'').($title!==''?($duplicate?'“'.$title.'” is already in “'.$list['name'].'”.':'Added “'.$title.'” to “'.$list['name'].'”.'):'The list is ready.');
            $r=vp3_task_list_result_v1($text,($duplicate||(!$created&&$title===''))?'show':$op,($duplicate||(!$created&&$title===''))?'read_complete':'completed',(int)$list['id'],$id?1:0);$r['sticky_notes']=[['kind'=>'task_list','id'=>(int)$list['id']]];
        }
        if($pending)$pdo->prepare("UPDATE agent_task_list_actions_v1 SET status='consumed',result_json=? WHERE token=? AND status='prepared'")->execute([json_encode($r,JSON_THROW_ON_ERROR),$pending['token']]);
        $pdo->commit();return $r;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return [...vp3_task_list_result_v1($e instanceof InvalidArgumentException?$e->getMessage():'The task/list operation could not be verified. Please try again.','show','failed'),'tool_receipt'=>vp3_tool_receipt_v1('tasks.lists','show',$e->getCode()===403?'blocked':'failed',$e->getCode()===403?'permission_denied':'execution_failed')];}
}
