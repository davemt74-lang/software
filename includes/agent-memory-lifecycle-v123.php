<?php
declare(strict_types=1);

const STONEFELLOW_AGENT_MEMORY_V123='agent-memory-phase3-v123-20260826';

function agent_memory_v123_metadata(array $row): array
{
    $meta=json_decode((string)($row['metadata_json']??''),true);
    return is_array($meta)?$meta:[];
}

function agent_memory_v123_type_half_life(string $type): int
{
    return match($type){
        'preference'=>365,
        'decision'=>180,
        'commitment','task'=>60,
        'conversation_state','conversation_summary'=>7,
        'theme'=>120,
        default=>90,
    };
}

function agent_memory_v123_effective_confidence(array $row,?int $now=null): float
{
    $now=$now??time();
    $base=max(0.0,min(1.0,(float)($row['confidence']??0.5)));
    $last=strtotime((string)($row['last_seen_at']??''))?:$now;
    $ageDays=max(0.0,($now-$last)/86400);
    $half=max(7,agent_memory_v123_type_half_life((string)($row['memory_type']??'')));
    $recency=pow(0.5,$ageDays/$half);
    $occ=max(1,(int)($row['occurrence_count']??1));
    $reinforcement=min(0.18,log(1+$occ,2)*0.035);
    $meta=agent_memory_v123_metadata($row);
    $status=(string)($meta['task_status']??'');
    $statusFactor=in_array($status,['completed','cancelled','superseded'],true)?0.18:($status==='waiting'?0.86:1.0);
    $sourceFactor=($meta['source_kind']??'')==='explicit_user'?1.08:1.0;
    return max(0.0,min(1.0,($base*0.72+$recency*0.28+$reinforcement)*$statusFactor*$sourceFactor));
}

function agent_memory_v123_term_set(string $text): array
{
    $text=agent_brain_normalize($text);
    $stop=array_flip(['the','and','for','that','this','with','from','have','has','had','was','were','are','will','would','should','could','into','about','your','you','our','their','then','than','just','need','want','make','work','task','finish','complete','done']);
    $parts=preg_split('/\s+/u',$text)?:[];
    return array_values(array_unique(array_filter($parts,static fn(string $x):bool=>mb_strlen($x)>=3&&!isset($stop[$x]))));
}

function agent_memory_v123_overlap(string $a,string $b): float
{
    $aa=agent_memory_v123_term_set($a);$bb=agent_memory_v123_term_set($b);
    if(!$aa||!$bb)return 0.0;$set=array_flip($aa);$hits=0;
    foreach($bb as $term)if(isset($set[$term]))$hits++;
    return $hits/max(1,min(count($aa),count($bb)));
}

function agent_memory_v123_task_status_from_text(string $text): string
{
    $q=mb_strtolower($text);
    if(preg_match('/\b(?:cancel(?:led)?|drop(?:ped)?|abandon(?:ed)?|not doing|skip this)\b/u',$q))return 'cancelled';
    if(preg_match('/\b(?:done|finished|complete(?:d)?|wrapped up|resolved|shipped|published|merged)\b/u',$q))return 'completed';
    if(preg_match('/\b(?:waiting|blocked|on hold|pending someone|pending approval)\b/u',$q))return 'waiting';
    if(preg_match('/\b(?:working on|continue|continuing|started|in progress|doing now)\b/u',$q))return 'in_progress';
    return '';
}

function agent_memory_v123_write_row(int $id,array $meta,?float $confidence=null,?int $active=null): void
{
    $pdo=db();if(!$pdo||$id<1)return;
    $sets=['metadata_json=?'];$params=[json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}'];
    if($confidence!==null){$sets[]='confidence=?';$params[]=max(0.0,min(1.0,$confidence));}
    if($active!==null){$sets[]='is_active=?';$params[]=$active?1:0;}
    $params[]=$id;
    try{$pdo->prepare('UPDATE agent_memory_items SET '.implode(',',$sets).' WHERE id=?')->execute($params);}catch(Throwable $e){}
}

function agent_memory_v123_recent_user_messages(int $uid,int $limit=80): array
{
    if(!table_exists('agent_chat_archive'))return [];$pdo=db();if(!$pdo)return [];
    try{$s=$pdo->prepare('SELECT message_text,created_at FROM agent_chat_archive WHERE user_id=? AND role=\'user\' ORDER BY id DESC LIMIT '.max(1,min(200,$limit)));$s->execute([$uid]);return $s->fetchAll()?:[];}catch(Throwable $e){return [];}
}

function agent_memory_v123_reconcile_user(array $user): array
{
    $uid=(int)($user['id']??0);$pdo=db();
    $result=['ready'=>false,'examined'=>0,'superseded'=>0,'decayed'=>0,'tasks_updated'=>0];
    if(!$pdo||$uid<1||!table_exists('agent_memory_items'))return $result;
    try{$s=$pdo->prepare('SELECT * FROM agent_memory_items WHERE user_id=? AND is_active=1 ORDER BY last_seen_at DESC,id DESC LIMIT 500');$s->execute([$uid]);$rows=$s->fetchAll()?:[];}catch(Throwable $e){return $result;}
    $result['ready']=true;$result['examined']=count($rows);$seen=[];$messages=agent_memory_v123_recent_user_messages($uid);
    $activity=function_exists('agent_activity_v94_snapshot')?agent_activity_v94_snapshot($user,'chat',[]):[];
    $activityTask=(string)($activity['task_title']??'');

    foreach($rows as $row){
        $id=(int)$row['id'];$type=(string)$row['memory_type'];$subject=(string)$row['subject'];$text=(string)$row['memory_text'];$meta=agent_memory_v123_metadata($row);
        $identity=$type.'|'.agent_brain_normalize($subject!==''?$subject:$text);
        if(isset($seen[$identity])){
            $meta['lifecycle']='superseded';$meta['superseded_by']=$seen[$identity];$meta['reconciled_at']=date('c');
            agent_memory_v123_write_row($id,$meta,(float)$row['confidence']*0.6,0);$result['superseded']++;continue;
        }
        $seen[$identity]=$id;

        $effective=agent_memory_v123_effective_confidence($row);
        $meta['effective_confidence']=round($effective,4);$meta['reconciled_at']=date('c');
        if(in_array($type,['commitment','task'],true)){
            $status=(string)($meta['task_status']??'open');
            if(!in_array($status,['open','in_progress','waiting','completed','cancelled'],true))$status='open';
            if($status==='open'&&$activityTask!==''&&agent_memory_v123_overlap($text.' '.$subject,$activityTask)>=0.35)$status='in_progress';
            foreach($messages as $message){
                $candidate=(string)$message['message_text'];$detected=agent_memory_v123_task_status_from_text($candidate);
                if($detected!==''&&agent_memory_v123_overlap($text.' '.$subject,$candidate)>=0.35){$status=$detected;$meta['status_evidence_at']=(string)$message['created_at'];break;}
            }
            if(($meta['task_status']??'')!==$status)$result['tasks_updated']++;
            $meta['task_status']=$status;$meta['task_kind']=$type==='commitment'?'commitment':'task';
            $meta['task_key']=$meta['task_key']??sha1(agent_brain_normalize($subject.'|'.$text));
            if(in_array($status,['completed','cancelled'],true))$meta['closed_at']=$meta['closed_at']??date('c');
        }
        if($effective<0.16&&!in_array($type,['preference','decision','commitment','task','conversation_state','conversation_summary'],true)){
            $meta['lifecycle']='stale';agent_memory_v123_write_row($id,$meta,max(0.05,(float)$row['confidence']*0.82),0);$result['decayed']++;continue;
        }
        // Effective confidence is a derived ranking signal. Keep the stored
        // base confidence stable unless real lifecycle evidence changes it.
        agent_memory_v123_write_row($id,$meta,null,null);
    }
    return $result;
}

function agent_memory_v123_tasks(array $user,bool $includeClosed=false): array
{
    $uid=(int)($user['id']??0);$pdo=db();if(!$pdo||$uid<1||!table_exists('agent_memory_items'))return [];
    agent_memory_v123_reconcile_user($user);
    try{$s=$pdo->prepare("SELECT * FROM agent_memory_items WHERE user_id=? AND is_active=1 AND memory_type IN ('commitment','task') ORDER BY last_seen_at DESC,id DESC LIMIT 100");$s->execute([$uid]);$rows=$s->fetchAll()?:[];}catch(Throwable $e){return [];}
    $out=[];
    foreach($rows as $row){
        $meta=agent_memory_v123_metadata($row);$status=(string)($meta['task_status']??'open');
        if(!$includeClosed&&in_array($status,['completed','cancelled'],true))continue;
        $due=(string)($meta['due_at']??'');
        if($due===''){foreach(agent_brain_extract_dates((string)$row['memory_text']) as $date){$due=$date;break;}}
        $out[]=[
            'memory_id'=>(int)$row['id'],'task_key'=>(string)($meta['task_key']??sha1((string)$row['memory_hash'])),'kind'=>(string)($meta['task_kind']??$row['memory_type']),
            'title'=>(string)$row['subject'],'text'=>(string)$row['memory_text'],'status'=>$status,'due_at'=>$due,
            'confidence'=>agent_memory_v123_effective_confidence($row),'occurrences'=>(int)$row['occurrence_count'],'last_seen_at'=>(string)$row['last_seen_at'],
        ];
    }
    return $out;
}

function agent_memory_v123_rank(array $row,string $query='',array $context=[]): float
{
    $confidence=agent_memory_v123_effective_confidence($row);
    $overlap=$query!==''?agent_memory_v123_overlap($query,(string)($row['subject']??'').' '.(string)($row['memory_text']??'')):0.0;
    $occ=min(1.0,log(1+max(1,(int)($row['occurrence_count']??1)),8));
    $last=strtotime((string)($row['last_seen_at']??''))?:time();$recency=max(0.0,1.0-min(1.0,(time()-$last)/(120*86400)));
    $contextText=implode(' ',array_filter([(string)($context['surface']??''),(string)($context['task_title']??''),(string)($context['project_title']??'')]));
    $contextFit=$contextText!==''?agent_memory_v123_overlap($contextText,(string)($row['subject']??'').' '.(string)($row['memory_text']??'')):0.0;
    return round(($confidence*0.42)+($overlap*0.28)+($recency*0.14)+($occ*0.08)+($contextFit*0.08),6);
}
