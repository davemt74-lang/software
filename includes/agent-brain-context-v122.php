<?php
declare(strict_types=1);

/** Stonefellow v122 — hybrid lexical + local semantic-vector Agent Brain retrieval. */
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
    foreach(['agent brain','my brain','my history','full history','recent history','memory','memories','what have i been','what was i working','what am i working','what did i','what have i','recent pattern','patterns','recurring','unfinished','open task','open tasks','commitment','recent edits','edit history','ledger','studio history','tool history','activity history','lately','recently','last session','previous session','where did i leave off','resume my'] as $needle){if(str_contains($q,$needle))return true;}
    return false;
}
function agent_brain_v99_terms(string $query): array
{
    $stop=array_flip(['the','and','for','with','from','that','this','what','when','where','which','who','why','how','have','has','had','been','being','was','were','are','is','am','did','does','do','my','me','i','you','your','our','we','a','an','to','of','in','on','at','it','its','about','show','tell','give','agent','brain','history','recent','recently','last','latest','please','look','review']);
    $parts=preg_split('/[^\pL\pN._-]+/u',mb_strtolower($query))?:[];
    return array_slice(array_values(array_unique(array_filter($parts,static fn(string $x):bool=>mb_strlen($x)>=3&&!isset($stop[$x])))),0,14);
}
function agent_brain_v122_semantic_aliases(): array
{
    return [
        'song'=>['track','music','recording'],'track'=>['song','music','recording'],'album'=>['release','record'],'release'=>['album','launch','publish','distribution'],
        'vocal'=>['voice','singer','singing'],'voice'=>['vocal','singer','speech'],'drums'=>['rhythm','percussion'],'bass'=>['lowend','rhythm'],'guitar'=>['instrument','strings'],
        'mix'=>['mixer','levels','balance','audio'],'master'=>['mastering','final','release'],'credit'=>['credits','contributor','songwriter','producer','engineer'],
        'show'=>['concert','gig','venue','performance'],'concert'=>['show','gig','venue'],'merch'=>['merchandise','store','product'],'post'=>['content','social','update'],
        'photo'=>['image','picture','visual'],'video'=>['editor','timeline','media'],'message'=>['contact','reply','inbox','communication'],
        'task'=>['todo','commitment','work','action'],'todo'=>['task','commitment','work'],'deadline'=>['due','release','schedule'],'calendar'=>['schedule','release','date'],
        'listen'=>['listening','analytics','plays','audience'],'analytics'=>['performance','listening','metrics','plays'],'producer'=>['collaborator','production'],'studio'=>['stem','mix','production','recording'],
    ];
}
function agent_brain_v122_semantic_features(string $text): array
{
    $terms=agent_brain_v99_terms($text);$aliases=agent_brain_v122_semantic_aliases();$features=[];
    foreach($terms as $term){$features[$term]=($features[$term]??0)+1.0;foreach($aliases[$term]??[] as $alias)$features[$alias]=($features[$alias]??0)+0.55;}
    for($i=0;$i<count($terms)-1;$i++){$features[$terms[$i].'_'.$terms[$i+1]]=($features[$terms[$i].'_'.$terms[$i+1]]??0)+0.7;}
    return $features;
}
function agent_brain_v122_vector(string $text,int $dimensions=192): array
{
    $vector=[];foreach(agent_brain_v122_semantic_features($text) as $feature=>$weight){$index=(int)(sprintf('%u',crc32((string)$feature))%$dimensions);$vector[$index]=($vector[$index]??0.0)+(float)$weight;}return $vector;
}
function agent_brain_v122_cosine(array $a,array $b): float
{
    if(!$a||!$b)return 0.0;$dot=0.0;$na=0.0;$nb=0.0;foreach($a as $i=>$v){$na+=$v*$v;if(isset($b[$i]))$dot+=$v*$b[$i];}foreach($b as $v)$nb+=$v*$v;return $na>0&&$nb>0?$dot/(sqrt($na)*sqrt($nb)):0.0;
}
function agent_brain_v99_pick(array $rows,array $terms,int $limit,bool $deep,callable $textFn): array
{
    if(!$rows)return [];$queryVector=agent_brain_v122_vector(implode(' ',$terms));$ranked=[];
    foreach(array_values($rows) as $i=>$row){
        $text=mb_strtolower((string)$textFn($row));$hits=0;$boundaries=0;
        foreach($terms as $term){if(str_contains($text,$term)){$hits++;if(preg_match('/\b'.preg_quote($term,'/').'/u',$text))$boundaries++;}}
        $semantic=agent_brain_v122_cosine($queryVector,agent_brain_v122_vector($text));$score=($hits*105)+($boundaries*25)+($semantic*145)+max(0,42-$i);
        $ranked[]=['row'=>$row,'hits'=>$hits,'semantic'=>$semantic,'score'=>$score];
    }
    usort($ranked,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);$out=[];
    foreach($ranked as $item){if($terms&&$item['hits']===0&&$item['semantic']<0.12&&!$deep)continue;$out[]=$item['row'];if(count($out)>=$limit)break;}
    if(!$out&&$rows)$out=array_slice($rows,0,$limit);return $out;
}
function agent_brain_v99_json_value(mixed $value): string
{
    if(is_bool($value))return $value?'true':'false';if($value===null)return 'null';if(is_scalar($value))return agent_brain_v99_text((string)$value,100);$json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return agent_brain_v99_text(is_string($json)?$json:'',160);
}
function agent_brain_v99_edit_changes(string $json,int $limit=10): string
{
    $rows=json_decode($json,true);if(!is_array($rows))return '';$lines=[];foreach(array_slice($rows,0,$limit) as $change){if(!is_array($change))continue;$lines[]=agent_brain_v99_text((string)($change['path']??'change'),120).': '.agent_brain_v99_json_value($change['before']??null).' -> '.agent_brain_v99_json_value($change['after']??null);}return implode('; ',$lines);
}
function agent_brain_v122_memory_context(array $user,int $conversationId=0): array
{
    $out=[];if($conversationId>0){$state=agent_brain_v122_memory($user,'conversation_state','conversation:'.$conversationId);if($state)$out[]=['source'=>'agent-brain:conversation-state','title'=>'Current conversation state','text'=>(string)$state['memory_text']];$summary=agent_brain_v122_memory($user,'conversation_summary','conversation:'.$conversationId);if($summary)$out[]=['source'=>'agent-brain:rolling-summary','title'=>'Rolling conversation summary','text'=>(string)$summary['memory_text']];}return $out;
}
function agent_brain_v99_context(array $user,string $query,int $limit=12): array
{
    if(!agent_brain_schema_ready())return [];$uid=(int)($user['id']??0);if($uid<1)return [];$deep=agent_brain_v99_history_intent($query);$terms=agent_brain_v99_terms($query);$context=[];
    $activity=agent_brain_v122_activity_context($user);$conversationId=(int)($activity['conversation_id']??0);if($conversationId<1&&table_exists('chat_conversations'))$conversationId=agent_chat_v101_latest_conversation_id(db(),$uid);
    foreach(agent_brain_v122_memory_context($user,$conversationId) as $item)$context[]=$item;

    $counts=[];foreach(['archived messages'=>'agent_chat_archive','active memories'=>'agent_memory_items','edit events'=>'agent_edit_events','tool actions'=>'agent_tool_history','Studio sessions'=>'agent_studio_sessions','activity transitions'=>'agent_activity_events'] as $label=>$table){if(!table_exists($table))continue;$where=$table==='agent_memory_items'?' AND is_active=1':'';$counts[]=$label.': '.agent_brain_v99_scalar("SELECT COUNT(*) FROM {$table} WHERE user_id=?{$where}",[$uid]);}
    $overview=$counts?implode(' · ',$counts):'Agent Brain storage is available.';$overview.=' Hybrid retrieval: lexical + semantic vector.';if(trim((string)($activity['task_title']??''))!=='')$overview.=' Current activity: '.agent_brain_v99_text($activity['task_title'],180).' on '.agent_brain_v99_text($activity['surface']??'chat',40).'.';$context[]=['source'=>'agent-brain:overview','title'=>'Agent Brain live overview','text'=>$overview];

    $patterns=[];
    if(table_exists('agent_memory_items')){
        foreach(agent_brain_v99_rows("SELECT memory_type,COUNT(*) AS items,SUM(occurrence_count) AS occurrences,MAX(last_seen_at) AS last_seen FROM agent_memory_items WHERE user_id=? AND is_active=1 GROUP BY memory_type ORDER BY occurrences DESC",[$uid]) as $r)$patterns[]='Memory type '.$r['memory_type'].' · '.(int)$r['items'].' items · '.(int)$r['occurrences'].' occurrences · last '.$r['last_seen'];
        foreach(agent_brain_v99_rows("SELECT subject,occurrence_count,last_seen_at FROM agent_memory_items WHERE user_id=? AND is_active=1 AND memory_type='theme' ORDER BY occurrence_count DESC,last_seen_at DESC LIMIT 10",[$uid]) as $r)$patterns[]='Recurring theme: '.agent_brain_v99_text($r['subject'],100).' · '.(int)$r['occurrence_count'].' occurrences · last '.$r['last_seen_at'];
    }
    if(table_exists('agent_edit_events'))foreach(agent_brain_v99_rows("SELECT editor_kind,action_key,COUNT(*) AS c,MAX(created_at) AS last_seen FROM agent_edit_events WHERE user_id=? GROUP BY editor_kind,action_key ORDER BY c DESC,last_seen DESC LIMIT 12",[$uid]) as $r)$patterns[]='Edit pattern: '.$r['editor_kind'].' / '.$r['action_key'].' · '.(int)$r['c'].' events · last '.$r['last_seen'];
    if(table_exists('agent_tool_history'))foreach(agent_brain_v99_rows("SELECT tool_key,COUNT(*) AS c,MAX(created_at) AS last_seen FROM agent_tool_history WHERE user_id=? GROUP BY tool_key ORDER BY c DESC,last_seen DESC LIMIT 8",[$uid]) as $r)$patterns[]='Tool pattern: '.$r['tool_key'].' · '.(int)$r['c'].' uses · last '.$r['last_seen'];
    if($patterns)$context[]=['source'=>'agent-brain:patterns','title'=>'Longitudinal Agent Brain patterns','text'=>implode("\n",array_slice($patterns,0,30))];

    if(table_exists('agent_memory_items')){
        $rows=agent_brain_v99_rows("SELECT memory_type,subject,memory_text,occurrence_count,confidence,last_seen_at FROM agent_memory_items WHERE user_id=? AND is_active=1 ORDER BY last_seen_at DESC,id DESC LIMIT 140",[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?20:9,$deep,static fn(array $r):string=>implode(' ',[$r['memory_type']??'',$r['subject']??'',$r['memory_text']??'']));
        if($rows){$lines=[];foreach($rows as $r)$lines[]='['.$r['last_seen_at'].'] '.strtoupper((string)$r['memory_type']).' · '.agent_brain_v99_text($r['subject'],110).': '.agent_brain_v99_text($r['memory_text'],360).' (seen '.(int)$r['occurrence_count'].'x · confidence '.number_format((float)$r['confidence'],2).')';$context[]=['source'=>'agent-brain:memory','title'=>'Hybrid-retrieved Agent Brain memory','text'=>implode("\n",$lines)];}
    }
    if(table_exists('agent_chat_archive')){
        $rows=agent_brain_v99_rows("SELECT conversation_id,role,input_mode,message_text,created_at FROM agent_chat_archive WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 160",[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?24:8,$deep,static fn(array $r):string=>(string)($r['message_text']??''));
        if($rows){$lines=[];foreach(array_reverse($rows) as $r)$lines[]='['.$r['created_at'].' · conversation '.(int)$r['conversation_id'].'] '.strtoupper((string)$r['role']).': '.agent_brain_v99_text($r['message_text'],440);$context[]=['source'=>'agent-brain:conversation-history','title'=>'Hybrid-retrieved conversation history','text'=>implode("\n",$lines)];}
    }
    if(table_exists('agent_edit_events')){
        $rows=agent_brain_v99_rows("SELECT editor_kind,project_id,source_kind,action_key,request_text,playhead_seconds,changes_json,created_at FROM agent_edit_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 100",[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?16:6,$deep,static fn(array $r):string=>implode(' ',[$r['editor_kind']??'',$r['action_key']??'',$r['request_text']??'',$r['changes_json']??'']));
        if($rows){$lines=[];foreach($rows as $r){$line='['.$r['created_at'].'] '.strtoupper((string)$r['editor_kind']).' project '.(int)$r['project_id'].' · '.$r['action_key'];if(trim((string)$r['request_text'])!=='')$line.=' · request: '.agent_brain_v99_text($r['request_text'],230);$changes=agent_brain_v99_edit_changes((string)$r['changes_json']);if($changes!=='')$line.=' · changes: '.$changes;$lines[]=$line;}$context[]=['source'=>'agent-brain:edit-ledger','title'=>'Relevant Studio and Video edits','text'=>implode("\n",$lines)];}
    }
    if(table_exists('agent_tool_history')){
        $rows=agent_brain_v99_rows("SELECT conversation_id,tool_key,request_text,status,result_json,created_at FROM agent_tool_history WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 80",[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?14:5,$deep,static fn(array $r):string=>implode(' ',[$r['tool_key']??'',$r['request_text']??'',$r['status']??'',$r['result_json']??'']));
        if($rows){$lines=[];foreach($rows as $r)$lines[]='['.$r['created_at'].'] '.$r['tool_key'].' · '.$r['status'].' · '.agent_brain_v99_text($r['request_text'],260).' · '.agent_brain_v99_text($r['result_json'],220);$context[]=['source'=>'agent-brain:tool-history','title'=>'Relevant Agent tool actions','text'=>implode("\n",$lines)];}
    }
    if(table_exists('agent_studio_sessions')){
        $sessions=agent_brain_v99_rows("SELECT id,track_id,last_activity_at FROM agent_studio_sessions WHERE user_id=? ORDER BY last_activity_at DESC,id DESC LIMIT 20",[$uid]);
        if($sessions){$lines=[];foreach($sessions as $r)$lines[]='Studio session '.(int)$r['id'].' · track '.(int)$r['track_id'].' · last activity '.$r['last_activity_at'];if(table_exists('agent_studio_history')){$history=agent_brain_v99_rows("SELECT session_id,status,message_text,result_text,id FROM agent_studio_history WHERE user_id=? ORDER BY id DESC LIMIT 40",[$uid]);$history=agent_brain_v99_pick($history,$terms,$deep?14:6,$deep,static fn(array $r):string=>implode(' ',[$r['message_text']??'',$r['result_text']??'',$r['status']??'']));foreach($history as $r)$lines[]='Studio action · session '.(int)$r['session_id'].' · '.$r['status'].' · request: '.agent_brain_v99_text($r['message_text'],260).' · result: '.agent_brain_v99_text($r['result_text'],260);}$context[]=['source'=>'agent-brain:studio-history','title'=>'Stem Studio Agent sessions and actions','text'=>implode("\n",array_slice($lines,0,$deep?28:12))];}
    }
    if(table_exists('agent_activity_events')){
        $rows=agent_brain_v99_rows("SELECT surface,task_kind,task_title,previous_state,activity_state,reason,created_at FROM agent_activity_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 60",[$uid]);$rows=agent_brain_v99_pick($rows,$terms,$deep?12:5,$deep,static fn(array $r):string=>implode(' ',[$r['task_title']??'',$r['task_kind']??'',$r['surface']??'',$r['reason']??'']));
        if($rows){$lines=[];foreach($rows as $r)$lines[]='['.$r['created_at'].'] '.strtoupper((string)$r['previous_state']).' -> '.strtoupper((string)$r['activity_state']).' · '.agent_brain_v99_text($r['task_title'],160).' · '.$r['surface'];$context[]=['source'=>'agent-brain:activity-history','title'=>'Relevant work/activity history','text'=>implode("\n",$lines)];}
    }
    $assets=[];if(table_exists('video_editor_projects'))foreach(agent_brain_v99_rows("SELECT id,title,updated_at FROM video_editor_projects WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 8",[$uid]) as $r)$assets[]='Video project '.(int)$r['id'].' · '.agent_brain_v99_text($r['title'],160).' · updated '.$r['updated_at'];if(table_exists('user_media_assets'))foreach(agent_brain_v99_rows("SELECT id,media_type,title,created_at FROM user_media_assets WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 8",[$uid]) as $r)$assets[]='Media '.(int)$r['id'].' · '.$r['media_type'].' · '.agent_brain_v99_text($r['title'],160).' · '.$r['created_at'];if($assets)$context[]=['source'=>'agent-brain:production-assets','title'=>'Recent production projects and media','text'=>implode("\n",$assets)];
    return array_slice($context,0,max(1,min(16,$limit)));
}