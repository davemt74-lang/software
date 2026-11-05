<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-scope-v1.php';
$owner=['id'=>1,'is_active'=>1];$binding=vp3_initiative_policy_v1($owner,17);
$pdo->exec('ALTER TABLE agent_activity_state ADD COLUMN task_title TEXT');
$pdo->prepare("UPDATE agent_activity_state SET context_key='chat:170',activity_state='working',last_heartbeat_at=NOW(),task_title=? WHERE user_id=1")
    ->execute(['<b>'.str_repeat('Current Chat work ',30).'</b>']);
$memoryBefore=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->exec('PRAGMA query_only=ON');
$context=vp3_initiative_owner_context_v1($pdo,$owner,$binding);
scope_check($context['available']===true&&$context['state']==='working'&&$context['interruptible']===false
    &&$context['surface']==='chat'&&isset($context['observed_at'],$context['current_time'],$context['local_time'],$context['timezone']),
    'planner availability reflects the real saved canonical owner activity and its observation time');
scope_check(mb_strlen($context['task_title'])===190&&!str_contains($context['task_title'],'<')
    &&!array_intersect(array_keys($context),['context_key','details_json','conversation_id','path','url']),
    'the exact bound Chat title is bounded plain data without private activity details');
scope_check(($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null)===$memoryBefore
    &&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'availability projection changes no records or caller memory scope');
$pdo->exec('PRAGMA query_only=OFF');
$pdo->prepare('UPDATE agent_activity_state SET details_json=? WHERE user_id=1')->execute([
    json_encode(['visible'=>true,'conversation_id'=>170,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
$pdo->exec('PRAGMA query_only=ON');
$quietChat=vp3_initiative_owner_context_v1($pdo,$owner,$binding);
scope_check($quietChat['state']==='working'&&$quietChat['interruptible']===false&&$quietChat['text_delivery_available']===true,
    'planning distinguishes an available silent-text Chat from permission to interrupt the owner verbally');
$pdo->exec("PRAGMA query_only=OFF; UPDATE agent_activity_state SET context_key='chat:180',task_title='SECRET OTHER AGENT TASK' WHERE user_id=1; PRAGMA query_only=ON");
$other=vp3_initiative_owner_context_v1($pdo,$owner,$binding);
scope_check($other['available']===true&&$other['state']==='working'&&!isset($other['task_title'])
    &&!str_contains(json_encode($other,JSON_THROW_ON_ERROR),'SECRET'),'another Agent Chat shares only owner availability, never its private task title');
$spoofed=vp3_initiative_owner_context_v1($pdo,$owner,array_replace($binding,['conversation_id'=>180]));
scope_check(!isset($spoofed['task_title']),'a mismatched Agent/conversation binding cannot borrow another Chat activity title');
$pdo->exec("PRAGMA query_only=OFF; UPDATE agent_activity_state SET last_heartbeat_at='2000-01-01 00:00:00' WHERE user_id=1; PRAGMA query_only=ON");
$stale=vp3_initiative_owner_context_v1($pdo,$owner,$binding);
scope_check($stale['available']===false&&$stale['state']==='unknown'&&$stale['interruptible']===null
    &&!isset($stale['task_title'],$stale['observed_at']),'an expired observation is unknown availability rather than a new live observation');
$pdo->exec('PRAGMA query_only=OFF; DELETE FROM agent_activity_state; PRAGMA query_only=ON');
$missing=vp3_initiative_owner_context_v1($pdo,$owner,$binding);
scope_check($missing['available']===false&&$missing['state']==='unknown'&&isset($missing['current_time']),
    'a missing activity record preserves current server time without inventing owner availability');
echo 'AGENT_INITIATIVE_OWNER_CONTEXT_V1=PASS'.PHP_EOL;
