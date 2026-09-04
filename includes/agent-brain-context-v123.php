<?php
declare(strict_types=1);

/** Stonefellow v123 — confidence-aware hybrid Agent Brain retrieval. */
function agent_brain_v99_rows(string $sql,array $params=[]): array
{
    $pdo=db();if(!$pdo)return [];
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll()?:[];}catch(Throwable $e){return [];}
}
function agent_brain_v99_scalar(string $sql,array $params=[]): int
{
    $pdo=db();if(!$pdo)return 0;
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();}catch(Throwable $e){return 0;}
}
function agent_brain_v99_text(mixed $value,int $max=500): string
{
    $text=trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value);return mb_strimwidth($text,0,$max,'…');
}
function agent_brain_v99_history_intent(string $query): bool
{
    $q=mb_strtolower($query);
    foreach(['agent brain','my brain','my history','full history','recent history','memory','memories','what have i been','what was i working','what am i working','what did i','what have i','recent pattern','patterns','recurring','unfinished','open task','open tasks','commitment','recent edits','edit history','ledger','studio history','tool history','activity history','lately','recently','last session','previous session','where did i leave off','resume my'] as $needle)if(str_contains($q,$needle))return true;
    return false;
}
function agent_brain_v99_terms(string $query): array
{
    $stop=array_flip(['the','and','for','with','from','that','this','what','when','where','which','who','why','how','have','has','had','been','being','was','were','are','is','am','did','does','do','my','me','i','you','your','our','we','a','an','to','of','in','on','at','it','its','about','show','tell','give','agent','brain','history','recent','recently','last','latest','please','look','review']);
    $parts=preg_split('/[^\pL\pN._-]+/u',mb_strtolower($query))?:[];
    return array_slice(array_values(array_unique(array_filter($parts,static fn(string $x):bool=>mb_strlen($x)>=3&&!isset($stop[$x])))),0,14);
}
function agent_brain_v123_aliases(): array
{
    return ['song'=>['track','music','recording'],'track'=>['song','music','recording'],'release'=>['album','launch','publish','distribution'],'album'=>['release','record'],'vocal'=>['voice','singer','singing'],'voice'=>['vocal','speech'],'drums'=>['rhythm','percussion'],'mix'=>['mixer','levels','balance','audio'],'master'=>['mastering','final','release'],'show'=>['concert','gig','venue','performance'],'concert'=>['show','gig','venue'],'video'=>['editor','timeline','media'],'message'=>['contact','reply','inbox'],'task'=>['todo','commitment','work','action'],'todo'=>['task','commitment'],'deadline'=>['due','schedule'],'listen'=>['listening','analytics','plays','audience'],'studio'=>['stem','mix','production','recording']];
}
function agent_brain_v123_vector(string $text,int $dimensions=192): array
{
    $features=[];$terms=agent_brain_v99_terms($text);$aliases=agent_brain_v123_aliases();
    foreach($terms as $term){$features[$term]=($features[$term]??0)+1.0;foreach($aliases[$term]??[] as $alias)$features[$alias]=($features[$alias]??0)+0.55;}
    for($i=0;$i<count($terms)-1;$i++)$features[$terms[$i].'_'.$terms[$i+1]]=($features[$terms[$i].'_'.$terms[$i+1]]??0)+0.7;
    $vector=[];foreach($features as $feature=>$weight){$idx=(int)(sprintf('%u',crc32((string)$feature))%$dimensions);$vector[$idx]=($vector[$idx]??0)+(float)$weight;}return $vector;
}
function agent_brain_v123_cosine(array $a,array $b): float
{
    if(!$a||!$b)return 0.0;$dot=$na=$nb=0.0;foreach($a as $i=>$v){$na+=$v*$v;if(isset($b[$i]))$dot+=$v*$b[$i];}foreach($b as $v)$nb+=$v*$v;return $na>0&&$nb>0?$dot/(sqrt($na)*sqrt($nb)):0.0;
}
function agent_brain_v99_pick(array $rows,array $terms,int $limit,bool $deep,callable $textFn,array $context=[]): array
{
    if(!$rows)return [];$query=implode(' ',$terms);$qv=agent_brain_v123_vector($query);$ranked=[];
    foreach(array_values($rows) as $i=>$row){
        $text=mb_strtolower((string)$textFn($row));$hits=0;foreach($terms as $term)if(str_contains($text,$term))$hits++;
        $semantic=agent_brain_v123_cosine($qv,agent_brain_v123_vector($text));$recency=max(0,42-$i)/42;
        $confidence=array_key_exists('confidence',$row)&&function_exists('agent_memory_v123_rank')?agent_memory_v123_rank($row,$query,$context):0.5;
        $score=($hits*1.05)+($semantic*1.45)+($recency*.28)+($confidence*.82);
        $ranked[]=['row'=>$row,'hits'=>$hits,'semantic'=>$semantic,'confidence'=>$confidence,'score'=>$score];
    }
    usort($ranked,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);$out=[];
    foreach($ranked as $item){if($terms&&$item['hits']===0&&$item['semantic']<0.12&&$item['confidence']<0.55&&!$deep)continue;$out[]=$item['row'];if(count($out)>=$limit)break;}
    return $out?:array_slice($rows,0,$limit);
}
function agent_brain_v99_json_value(mixed $value): string
{
    if(is_bool($value))return $value?'true':'false';if($value===null)return 'null';if(is_scalar($value))return agent_brain_v99_text((string)$value,100);$json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return agent_brain_v99_text(is_string($json)?$json:'',160);
}
function agent_brain_v99_edit_changes(string $json,int $limit=10): string
{
    $rows=json_decode($json,true);if(!is_array($rows))return '';$lines=[];foreach(array_slice($rows,0,$limit) as $c)if(is_array($c))$lines[]=agent_brain_v99_text((string)($c['path']??'change'),120).': '.agent_brain_v99_json_value($c['before']??null).' -> '.agent_brain_v99_json_value($c['after']??null);return implode('; ',$lines);
}
function agent_brain_v123_system_context(array $user,int $conversationId): array
{
    $out=[];if($conversationId>0&&function_exists('agent_brain_v122_memory')){
        $state=agent_brain_v122_memory($user,'conversation_state','conversation:'.$conversationId);if($state)$out[]=['source'=>'agent-brain:conversation-state','title'=>'Current conversation state','text'=>(string)$state['memory_text']];
        $summary=agent_brain_v122_memory($user,'conversation_summary','conversation:'.$conversationId);if($summary)$out[]=['source'=>'agent-brain:rolling-summary','title'=>'Rolling conversation summary','text'=>(string)$summary['memory_text']];
    }return $out;
}
function agent_brain_v99_context(array $user,string $query,int $limit=12): array
{
    if(!agent_brain_schema_ready())return [];$uid=(int)($user['id']??0);if($uid<1)return [];
    if(function_exists('agent_memory_v123_reconcile_user'))agent_memory_v123_reconcile_user($user);
    $deep=agent_brain_v99_history_intent($query);$terms=agent_brain_v99_terms($query);$context=[];
    $activity=function_exists('agent_activity_v94_snapshot')?agent_activity_v94_snapshot($user,'chat',[]):[];$conversationId=0;
    if(table_exists('agent_activity_state')){$r=agent_brain_v99_rows('SELECT details_json FROM agent_activity_state WHERE user_id=? LIMIT 1',[$uid])[0]??[];$d=json_decode((string)($r['details_json']??''),true);if(is_array($d))$conversationId=(int)($d['conversation_id']??0);}
    if($conversationId<1&&function_exists('agent_chat_v101_latest_conversation_id'))$conversationId=agent_chat_v101_latest_conversation_id(db(),$uid);
    foreach(agent_brain_v123_system_context($user,$conversationId) as $item)$context[]=$item;

    $counts=[];foreach(['archived messages'=>'agent_chat_archive','active memories'=>'agent_memory_items','edit events'=>'agent_edit_events','tool actions'=>'agent_tool_history','Studio sessions'=>'agent_studio_sessions','activity transitions'=>'agent_activity_events'] as $label=>$table){if(!table_exists($table))continue;$where=$table==='agent_memory_items'?' AND is_active=1':'';$counts[]=$label.': '.agent_brain_v99_scalar("SELECT COUNT(*) FROM {$table} WHERE user_id=?{$where}",[$uid]);}
    $context[]=['source'=>'agent-brain:overview','title'=>'Agent Brain live overview','text'=>($counts?implode(' · ',$counts):'Agent Brain storage is available.').' Retrieval: lexical + semantic + confidence + recency + reinforcement.'];

    if(table_exists('agent_memory_items')){
        $rows=agent_brain_v99_rows('SELECT id,memory_type,subject,memory_text,occurrence_count,confidence,last_seen_at,metadata_json FROM agent_memory_items WHERE user_id=? AND is_active=1 ORDER BY last_seen_at DESC,id DESC LIMIT 160',[$uid]);
        $rows=agent_brain_v99_pick($rows,$terms,$deep?22:10,$deep,static fn(array $r):string=>implode(' ',[$r['memory_type']??'',$r['subject']??'',$r['memory_text']??'']),$activity);
        if($rows){$lines=[];foreach($rows as $r){$effective=function_exists('agent_memory_v123_effective_confidence')?agent_memory_v123_effective_confidence($r):(float)$r['confidence'];$meta=function_exists('agent_memory_v123_metadata')?agent_memory_v123_metadata($r):[];$status=(string)($meta['task_status']??'');$lines[]='['.$r['last_seen_at'].'] '.strtoupper((string)$r['memory_type']).' · '.agent_brain_v99_text($r['subject'],110).': '.agent_brain_v99_text($r['memory_text'],360).' · confidence '.number_format($effective,2).($status!==''?' · status '.$status:'');}$context[]=['source'=>'agent-brain:memory','title'=>'Confidence-ranked Agent Brain memory','text'=>implode("\n",$lines)];}
    }
    if(function_exists('agent_memory_v123_tasks')){$tasks=agent_memory_v123_tasks($user,false);if($tasks){$lines=[];foreach(array_slice($tasks,0,12) as $t)$lines[]=strtoupper((string)$t['status']).' · '.agent_brain_v99_text($t['title'],120).': '.agent_brain_v99_text($t['text'],300).($t['due_at']!==''?' · due '.$t['due_at']:'').' · confidence '.number_format((float)$t['confidence'],2);$context[]=['source'=>'agent-brain:tasks','title'=>'Open task and commitment lifecycle','text'=>implode("\n",$lines)];}}
    if(table_exists('agent_chat_archive')){$rows=agent_brain_v99_rows('SELECT conversation_id,role,input_mode,message_text,created_at FROM agent_chat_archive WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 160',[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?24:8,$deep,static fn(array $r):string=>(string)($r['message_text']??''),$activity);if($rows){$lines=[];foreach(array_reverse($rows) as $r)$lines[]='['.$r['created_at'].' · conversation '.(int)$r['conversation_id'].'] '.strtoupper((string)$r['role']).': '.agent_brain_v99_text($r['message_text'],440);$context[]=['source'=>'agent-brain:conversation-history','title'=>'Retrieved conversation history','text'=>implode("\n",$lines)];}}
    if(table_exists('agent_edit_events')){$rows=agent_brain_v99_rows('SELECT editor_kind,project_id,source_kind,action_key,request_text,playhead_seconds,changes_json,created_at FROM agent_edit_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 100',[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?16:6,$deep,static fn(array $r):string=>implode(' ',[$r['editor_kind']??'',$r['action_key']??'',$r['request_text']??'',$r['changes_json']??'']),$activity);if($rows){$lines=[];foreach($rows as $r){$line='['.$r['created_at'].'] '.strtoupper((string)$r['editor_kind']).' project '.(int)$r['project_id'].' · '.$r['action_key'];if(trim((string)$r['request_text'])!=='')$line.=' · request: '.agent_brain_v99_text($r['request_text'],230);$changes=agent_brain_v99_edit_changes((string)$r['changes_json']);if($changes!=='')$line.=' · changes: '.$changes;$lines[]=$line;}$context[]=['source'=>'agent-brain:edit-ledger','title'=>'Relevant Studio and Video edits','text'=>implode("\n",$lines)];}}
    if(table_exists('agent_tool_history')){$rows=agent_brain_v99_rows('SELECT conversation_id,tool_key,request_text,status,result_json,created_at FROM agent_tool_history WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 80',[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?14:5,$deep,static fn(array $r):string=>implode(' ',[$r['tool_key']??'',$r['request_text']??'',$r['status']??'',$r['result_json']??'']),$activity);if($rows){$lines=[];foreach($rows as $r)$lines[]='['.$r['created_at'].'] '.$r['tool_key'].' · '.$r['status'].' · '.agent_brain_v99_text($r['request_text'],250);$context[]=['source'=>'agent-brain:tool-history','title'=>'Relevant Agent tool actions','text'=>implode("\n",$lines)];}}
    $patterns=[];if(table_exists('agent_memory_items'))foreach(agent_brain_v99_rows("SELECT subject,occurrence_count,last_seen_at FROM agent_memory_items WHERE user_id=? AND is_active=1 AND memory_type='theme' ORDER BY occurrence_count DESC,last_seen_at DESC LIMIT 10",[$uid]) as $r)$patterns[]='Recurring theme: '.agent_brain_v99_text($r['subject'],100).' · '.(int)$r['occurrence_count'].' occurrences · last '.$r['last_seen_at'];if($patterns)$context[]=['source'=>'agent-brain:patterns','title'=>'Longitudinal Agent Brain patterns','text'=>implode("\n",$patterns)];
    if(table_exists('agent_studio_sessions')){$sessions=agent_brain_v99_rows('SELECT id,track_id,last_activity_at FROM agent_studio_sessions WHERE user_id=? ORDER BY last_activity_at DESC,id DESC LIMIT 12',[$uid]);if($sessions){$lines=[];foreach($sessions as $r)$lines[]='Studio session '.(int)$r['id'].' · track '.(int)$r['track_id'].' · last '.$r['last_activity_at'];$context[]=['source'=>'agent-brain:studio-history','title'=>'Stem Studio sessions','text'=>implode("\n",$lines)];}}
    if(table_exists('agent_activity_events')){$rows=agent_brain_v99_rows('SELECT surface,task_kind,task_title,previous_state,activity_state,reason,created_at FROM agent_activity_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 60',[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?12:5,$deep,static fn(array $r):string=>implode(' ',[$r['task_title']??'',$r['task_kind']??'',$r['surface']??'',$r['reason']??'']),$activity);if($rows){$lines=[];foreach($rows as $r)$lines[]='['.$r['created_at'].'] '.strtoupper((string)$r['previous_state']).' -> '.strtoupper((string)$r['activity_state']).' · '.agent_brain_v99_text($r['task_title'],160).' · '.$r['surface'];$context[]=['source'=>'agent-brain:activity-history','title'=>'Relevant work/activity history','text'=>implode("\n",$lines)];}}
    $assets=[];if(table_exists('video_editor_projects'))foreach(agent_brain_v99_rows('SELECT id,title,updated_at FROM video_editor_projects WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 8',[$uid]) as $r)$assets[]='Video project '.(int)$r['id'].' · '.agent_brain_v99_text($r['title'],160).' · updated '.$r['updated_at'];if(table_exists('user_media_assets'))foreach(agent_brain_v99_rows('SELECT id,media_type,title,created_at FROM user_media_assets WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 8',[$uid]) as $r)$assets[]='Media '.(int)$r['id'].' · '.$r['media_type'].' · '.agent_brain_v99_text($r['title'],160).' · '.$r['created_at'];if($assets)$context[]=['source'=>'agent-brain:production-assets','title'=>'Recent production projects and media','text'=>implode("\n",$assets)];
    return array_slice($context,0,max(1,min(18,$limit)));
}