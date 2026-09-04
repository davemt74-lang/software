<?php
declare(strict_types=1);

/** Stonefellow v99 — unified Agent Brain retrieval for main Agent Chat. */
function agent_brain_v99_rows(string $sql,array $params=[]): array
{
    $pdo=db();
    if(!$pdo)return [];
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll()?:[];}catch(Throwable $e){return [];}
}

function agent_brain_v99_scalar(string $sql,array $params=[]): int
{
    $pdo=db();
    if(!$pdo)return 0;
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();}catch(Throwable $e){return 0;}
}

function agent_brain_v99_text(mixed $value,int $max=500): string
{
    $text=trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value);
    return mb_strimwidth($text,0,$max,'…');
}

function agent_brain_v99_history_intent(string $query): bool
{
    $q=mb_strtolower($query);
    foreach([
        'agent brain','my brain','my history','full history','recent history','memory','memories',
        'what have i been','what was i working','what am i working','what did i','what have i',
        'recent pattern','patterns','recurring','unfinished','open task','open tasks','commitment',
        'recent edits','edit history','ledger','studio history','tool history','activity history',
        'lately','recently','last session','previous session','where did i leave off','resume my'
    ] as $needle){if(str_contains($q,$needle))return true;}
    return false;
}

function agent_brain_v99_terms(string $query): array
{
    $stop=array_flip([
        'the','and','for','with','from','that','this','what','when','where','which','who','why','how',
        'have','has','had','been','being','was','were','are','is','am','did','does','do','my','me','i',
        'you','your','our','we','a','an','to','of','in','on','at','it','its','about','show','tell','give',
        'agent','brain','history','recent','recently','last','latest','please','look','review'
    ]);
    $parts=preg_split('/[^\pL\pN._-]+/u',mb_strtolower($query))?:[];
    return array_slice(array_values(array_unique(array_filter($parts,static fn(string $x):bool=>mb_strlen($x)>=3&&!isset($stop[$x])))),0,10);
}

function agent_brain_v99_pick(array $rows,array $terms,int $limit,bool $deep,callable $textFn): array
{
    if(!$rows)return [];
    $ranked=[];
    foreach(array_values($rows) as $i=>$row){
        $text=mb_strtolower((string)$textFn($row));$hits=0;
        foreach($terms as $term){if(str_contains($text,$term))$hits++;}
        $ranked[]=['row'=>$row,'hits'=>$hits,'score'=>($hits*100)+max(0,40-$i)];
    }
    usort($ranked,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);
    $out=[];
    foreach($ranked as $item){
        if($terms&&$item['hits']===0&&!$deep)continue;
        $out[]=$item['row'];
        if(count($out)>=$limit)break;
    }
    if(!$out&&$rows)$out=array_slice($rows,0,$limit);
    return $out;
}

function agent_brain_v99_json_value(mixed $value): string
{
    if(is_bool($value))return $value?'true':'false';
    if($value===null)return 'null';
    if(is_scalar($value))return agent_brain_v99_text((string)$value,100);
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return agent_brain_v99_text(is_string($json)?$json:'',160);
}

function agent_brain_v99_edit_changes(string $json,int $limit=10): string
{
    $rows=json_decode($json,true);
    if(!is_array($rows))return '';
    $lines=[];
    foreach(array_slice($rows,0,$limit) as $change){
        if(!is_array($change))continue;
        $path=agent_brain_v99_text((string)($change['path']??'change'),120);
        $before=agent_brain_v99_json_value($change['before']??null);
        $after=agent_brain_v99_json_value($change['after']??null);
        $lines[]=$path.': '.$before.' -> '.$after;
    }
    return implode('; ',$lines);
}

function agent_brain_v99_context(array $user,string $query,int $limit=12): array
{
    if(!agent_brain_schema_ready())return [];
    $uid=(int)($user['id']??0);if($uid<1)return [];
    $deep=agent_brain_v99_history_intent($query);$terms=agent_brain_v99_terms($query);$context=[];

    $counts=[];
    foreach([
        'archived messages'=>'agent_chat_archive',
        'active memories'=>'agent_memory_items',
        'edit events'=>'agent_edit_events',
        'tool actions'=>'agent_tool_history',
        'Studio sessions'=>'agent_studio_sessions',
        'activity transitions'=>'agent_activity_events'
    ] as $label=>$table){
        if(!table_exists($table))continue;
        $where=$table==='agent_memory_items'?' AND is_active=1':'';
        $counts[]=$label.': '.agent_brain_v99_scalar("SELECT COUNT(*) FROM {$table} WHERE user_id=?{$where}",[$uid]);
    }
    $overview=$counts?implode(' · ',$counts):'Agent Brain storage is available.';
    if(table_exists('agent_activity_state')){
        $state=agent_brain_v99_rows('SELECT surface,context_key,task_kind,task_title,activity_state,last_input_at,last_task_at,idle_since_at,updated_at FROM agent_activity_state WHERE user_id=? LIMIT 1',[$uid])[0]??null;
        if($state){
            $overview.=' Current activity: '.strtoupper((string)$state['activity_state']);
            if(trim((string)$state['task_title'])!=='')$overview.=' — '.agent_brain_v99_text($state['task_title'],180);
            $overview.=' (surface '.agent_brain_v99_text($state['surface'],40).', updated '.(string)$state['updated_at'].').';
        }
    }
    $context[]=['source'=>'agent-brain:overview','title'=>'Agent Brain live overview','text'=>$overview];

    $patternLines=[];
    if(table_exists('agent_memory_items')){
        foreach(agent_brain_v99_rows("SELECT memory_type,COUNT(*) AS items,SUM(occurrence_count) AS occurrences,MAX(last_seen_at) AS last_seen FROM agent_memory_items WHERE user_id=? AND is_active=1 GROUP BY memory_type ORDER BY occurrences DESC",[$uid]) as $r){
            $patternLines[]='Memory type '.(string)$r['memory_type'].' · '.(int)$r['items'].' items · '.(int)$r['occurrences'].' occurrences · last '.(string)$r['last_seen'];
        }
        foreach(agent_brain_v99_rows("SELECT subject,occurrence_count,last_seen_at FROM agent_memory_items WHERE user_id=? AND is_active=1 AND memory_type='theme' ORDER BY occurrence_count DESC,last_seen_at DESC LIMIT 10",[$uid]) as $r){
            $patternLines[]='Recurring theme: '.agent_brain_v99_text($r['subject'],100).' · '.(int)$r['occurrence_count'].' occurrences · last '.(string)$r['last_seen_at'];
        }
    }
    if(table_exists('agent_edit_events')){
        foreach(agent_brain_v99_rows("SELECT editor_kind,action_key,COUNT(*) AS c,MAX(created_at) AS last_seen FROM agent_edit_events WHERE user_id=? GROUP BY editor_kind,action_key ORDER BY c DESC,last_seen DESC LIMIT 12",[$uid]) as $r){
            $patternLines[]='Edit pattern: '.(string)$r['editor_kind'].' / '.(string)$r['action_key'].' · '.(int)$r['c'].' events · last '.(string)$r['last_seen'];
        }
    }
    if(table_exists('agent_tool_history')){
        foreach(agent_brain_v99_rows("SELECT tool_key,COUNT(*) AS c,MAX(created_at) AS last_seen FROM agent_tool_history WHERE user_id=? GROUP BY tool_key ORDER BY c DESC,last_seen DESC LIMIT 8",[$uid]) as $r){
            $patternLines[]='Tool pattern: '.(string)$r['tool_key'].' · '.(int)$r['c'].' uses · last '.(string)$r['last_seen'];
        }
    }
    if($patternLines)$context[]=['source'=>'agent-brain:patterns','title'=>'Longitudinal Agent Brain patterns','text'=>implode("\n",array_slice($patternLines,0,30))];

    $memoryRows=agent_brain_v99_rows("SELECT memory_type,subject,memory_text,occurrence_count,confidence,last_seen_at FROM agent_memory_items WHERE user_id=? AND is_active=1 ORDER BY last_seen_at DESC,id DESC LIMIT 80",[$uid]);
    $memoryRows=agent_brain_v99_pick($memoryRows,$terms,$deep?18:8,$deep,static fn(array $r):string=>implode(' ',[$r['memory_type']??'',$r['subject']??'',$r['memory_text']??'']));
    if($memoryRows){
        $lines=[];foreach($memoryRows as $r){$lines[]='['.(string)$r['last_seen_at'].'] '.strtoupper((string)$r['memory_type']).' · '.agent_brain_v99_text($r['subject'],110).': '.agent_brain_v99_text($r['memory_text'],320).' (seen '.(int)$r['occurrence_count'].'x)';}
        $context[]=['source'=>'agent-brain:memory','title'=>'Preferences, decisions, commitments and recurring memory','text'=>implode("\n",$lines)];
    }

    if(table_exists('agent_chat_archive')){
        $archive=agent_brain_v99_rows("SELECT conversation_id,role,input_mode,message_text,created_at FROM agent_chat_archive WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 100",[$uid]);
        $archive=agent_brain_v99_pick($archive,$terms,$deep?24:8,$deep,static fn(array $r):string=>(string)($r['message_text']??''));
        if($archive){
            $lines=[];foreach(array_reverse($archive) as $r){$lines[]='['.(string)$r['created_at'].' · conversation '.(int)$r['conversation_id'].'] '.strtoupper((string)$r['role']).': '.agent_brain_v99_text($r['message_text'],420);}
            $context[]=['source'=>'agent-brain:conversation-history','title'=>'Retrieved Agent Chat history','text'=>implode("\n",$lines)];
        }
    }

    if(table_exists('agent_edit_events')){
        $edits=agent_brain_v99_rows("SELECT editor_kind,project_id,session_key,source_kind,action_key,request_text,model_provider,model_name,playhead_seconds,changes_json,created_at FROM agent_edit_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 80",[$uid]);
        $edits=agent_brain_v99_pick($edits,$terms,$deep?18:7,$deep,static fn(array $r):string=>implode(' ',[$r['editor_kind']??'',$r['action_key']??'',$r['request_text']??'',$r['changes_json']??'']));
        if($edits){
            $lines=[];foreach($edits as $r){
                $line='['.(string)$r['created_at'].'] '.strtoupper((string)$r['editor_kind']).' project '.(int)$r['project_id'].' · '.(string)$r['source_kind'].' · '.(string)$r['action_key'];
                if($r['playhead_seconds']!==null)$line.=' @ '.rtrim(rtrim(number_format((float)$r['playhead_seconds'],3,'.',''),'0'),'.').'s';
                if(trim((string)$r['request_text'])!=='')$line.=' · request: '.agent_brain_v99_text($r['request_text'],220);
                $changes=agent_brain_v99_edit_changes((string)$r['changes_json']);if($changes!=='')$line.=' · exact changes: '.$changes;
                if(trim((string)$r['model_name'])!=='')$line.=' · model '.agent_brain_v99_text($r['model_name'],80);
                $lines[]=$line;
            }
            $context[]=['source'=>'agent-brain:edit-ledger','title'=>'Exact recent Studio and Video edit ledger','text'=>implode("\n",$lines)];
        }
    }

    if(table_exists('agent_tool_history')){
        $tools=agent_brain_v99_rows("SELECT conversation_id,tool_key,request_text,status,result_json,created_at FROM agent_tool_history WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 60",[$uid]);
        $tools=agent_brain_v99_pick($tools,$terms,$deep?14:6,$deep,static fn(array $r):string=>implode(' ',[$r['tool_key']??'',$r['request_text']??'',$r['status']??'',$r['result_json']??'']));
        if($tools){$lines=[];foreach($tools as $r){$lines[]='['.(string)$r['created_at'].'] '.(string)$r['tool_key'].' · '.(string)$r['status'].' · '.agent_brain_v99_text($r['request_text'],260).' · result '.agent_brain_v99_text($r['result_json'],220);}$context[]=['source'=>'agent-brain:tool-history','title'=>'Recent Agent tool actions','text'=>implode("\n",$lines)];}
    }

    if(table_exists('agent_studio_sessions')){
        $sessions=agent_brain_v99_rows("SELECT id,track_id,last_activity_at FROM agent_studio_sessions WHERE user_id=? ORDER BY last_activity_at DESC,id DESC LIMIT 20",[$uid]);
        if($sessions){
            $lines=[];foreach($sessions as $r){$lines[]='Studio session '.(int)$r['id'].' · track '.(int)$r['track_id'].' · last activity '.(string)$r['last_activity_at'];}
            if(table_exists('agent_studio_history')){
                $history=agent_brain_v99_rows("SELECT session_id,status,message_text,result_text,id FROM agent_studio_history WHERE user_id=? ORDER BY id DESC LIMIT 30",[$uid]);
                $history=agent_brain_v99_pick($history,$terms,$deep?14:6,$deep,static fn(array $r):string=>implode(' ',[$r['message_text']??'',$r['result_text']??'',$r['status']??'']));
                foreach($history as $r){$lines[]='Studio action · session '.(int)$r['session_id'].' · '.(string)$r['status'].' · request: '.agent_brain_v99_text($r['message_text'],260).' · result: '.agent_brain_v99_text($r['result_text'],260);}
            }
            $context[]=['source'=>'agent-brain:studio-history','title'=>'Stem Studio Agent sessions and actions','text'=>implode("\n",array_slice($lines,0,$deep?28:12))];
        }
    }

    if(table_exists('agent_activity_events')){
        $events=agent_brain_v99_rows("SELECT surface,context_key,task_kind,task_title,previous_state,activity_state,reason,created_at FROM agent_activity_events WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 40",[$uid]);
        $events=agent_brain_v99_pick($events,$terms,$deep?14:5,$deep,static fn(array $r):string=>implode(' ',[$r['task_title']??'',$r['task_kind']??'',$r['surface']??'',$r['reason']??'']));
        if($events){$lines=[];foreach($events as $r){$lines[]='['.(string)$r['created_at'].'] '.strtoupper((string)$r['previous_state']).' -> '.strtoupper((string)$r['activity_state']).' · '.agent_brain_v99_text($r['task_title'],160).' · '.agent_brain_v99_text($r['reason'],100).' · '.agent_brain_v99_text($r['surface'],50);}$context[]=['source'=>'agent-brain:activity-history','title'=>'Working, paused and idle transitions','text'=>implode("\n",$lines)];}
    }

    $projectLines=[];
    if(table_exists('video_editor_projects'))foreach(agent_brain_v99_rows("SELECT id,title,updated_at FROM video_editor_projects WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 8",[$uid]) as $r){$projectLines[]='Video project '.(int)$r['id'].' · '.agent_brain_v99_text($r['title'],160).' · updated '.(string)$r['updated_at'];}
    if(table_exists('user_media_assets'))foreach(agent_brain_v99_rows("SELECT id,media_type,title,created_at FROM user_media_assets WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 8",[$uid]) as $r){$projectLines[]='Media '.(int)$r['id'].' · '.(string)$r['media_type'].' · '.agent_brain_v99_text($r['title'],160).' · captured '.(string)$r['created_at'];}
    if($projectLines)$context[]=['source'=>'agent-brain:production-assets','title'=>'Recent production projects and captured media','text'=>implode("\n",$projectLines)];

    return array_slice($context,0,max(1,min(16,$limit)));
}
