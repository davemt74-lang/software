<?php
declare(strict_types=1);
require_once __DIR__.'/project-knowledge-r6.php';
const VP3_PROJECT_KNOWLEDGE_R7='vp3.project-knowledge.r7';

/** Workflow pointers only. Captures and Research remain the evidence authorities. */
function knowledge_r7_ensure_schema(PDO $pdo): void {
    if($pdo->inTransaction())throw new RuntimeException('Install refresh storage before a transaction.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_knowledge_refresh_r7 (
      research_id CHAR(36) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
      context_key VARCHAR(200) NOT NULL, agent_namespace VARCHAR(190) NOT NULL,
      baseline_json TEXT NOT NULL, project_id CHAR(36) NOT NULL, folder_id INT UNSIGNED NOT NULL,
      knowledge_id INT UNSIGNED NULL, knowledge_revision CHAR(64) NULL,
      applied_hash CHAR(64) NULL, applied_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ix_refresh_context(owner_user_id,context_key,agent_namespace)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function knowledge_r7_age(string $date,int $days=30,?int $now=null): array {
    if($days<1||$days>365)throw new InvalidArgumentException('Choose a review interval from 1 to 365 days.');
    $now??=time();$stamp=false;
    if(preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:Z|\+00:00)?)?$/D',$date)){
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',substr($date,0,10),new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if($d&&(!$errors||(!$errors['warning_count']&&!$errors['error_count'])))$stamp=strtotime(preg_replace('/(?:Z|\+00:00)$/','',$date).' UTC');
    }
    if($stamp===false)return ['state'=>'date_unknown','age_days'=>null,'review_days'=>$days,'notice'=>'Source date unknown; a saved or edited date is not proof of current evidence.'];
    if($stamp>$now)return ['state'=>'future_date','age_days'=>null,'review_days'=>$days,'notice'=>'Source date is in the future; review the evidence.'];
    $age=(int)floor(($now-$stamp)/86400);
    return ['state'=>$age>=$days?'review_due':'within_interval','age_days'=>$age,'review_days'=>$days,'notice'=>'Capture age suggests a review interval; it does not establish correctness or live freshness.'];
}

function knowledge_r7_source(PDO $pdo,array $user,string $key,string $namespace,array $reference,?string $stateRevision=null): array {
    $ref=knowledge_r6_refs([$reference])[0];$state=knowledge_r6_state($pdo,$user,$key,$namespace);
    if($state['scope']['mode']==='off')throw new RuntimeException('Enable Knowledge before refreshing sources.',403);
    if($stateRevision!==null&&!hash_equals($state['revision'],$stateRevision))throw new RuntimeException('Conversation scope changed. Review a fresh plan.',409);
    $source=null;$saved=null;
    if($ref['kind']==='annotation'){
        $row=capture_r5_rows($pdo,$user,$ref['id'],$pdo->inTransaction())[0]??null;
        if($row){
            if($row['delivery']['project_public_id']!=='')capture_r5_project($pdo,$user,$row['delivery']['project_public_id']);
            $item=capture_r5_item($pdo,$user,$row);
            if(!$item['knowledge_conflict'])$source=knowledge_r6_annotation($item);
        }
    }else{
        $row=chat_research_row_v1($pdo,(int)$user['id'],$ref['id'],$pdo->inTransaction());
        if($row['agent_namespace']!==$namespace)throw new RuntimeException('Research belongs to another lead.',403);
        $saved=chat_research_saved_r3($pdo,$user,$row);
        $report=vp3_research_report_row_v2060($pdo,$saved['report']['id']);
        $project=vp3_research_project_row_by_id_v2060($pdo,(int)$report['project_id']);
        $source=['kind'=>'report','id'=>$saved['id'],'revision'=>$saved['hash'],'title'=>$saved['question'],'url'=>$saved['report']['url'],
            'captured_at'=>$row['created_at'],'project_id'=>$project['public_id'],'excerpt'=>$saved['answer']['text']];
    }
    if(!$source||!knowledge_r6_matches($source,$state['scope']))throw new RuntimeException('Source is unavailable in this conversation scope.',403);
    if(!hash_equals($source['revision'],$ref['revision']))throw new RuntimeException('Source changed. Review its current revision.',409);
    $urls=$ref['kind']==='annotation'?chat_research_urls_v1($source['url']):chat_research_urls_v1(implode("\n",array_column(json_decode($row['urls_json'],true,16,JSON_THROW_ON_ERROR),'url')));
    return ['state'=>$state,'source'=>$source,'saved'=>$saved,'urls'=>$urls];
}

function knowledge_r7_target(PDO $pdo,array $user,array $ref): ?array {
    $marker='personal-'.sha1('knowledge-refresh-r7:'.$ref['kind'].':'.$ref['id']).'.txt';
    $q=$pdo->prepare("SELECT * FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' AND file_type='personal_note' AND file_name=? LIMIT 1".($pdo->inTransaction()?' FOR UPDATE':''));$q->execute([$user['id'],$marker]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function knowledge_r7_source_hash(array $resolved): string {
    $source=$resolved['source'];unset($source['sync'],$source['freshness'],$source['refreshable']);
    return hash('sha256',json_encode([$source,$resolved['urls']],JSON_THROW_ON_ERROR));
}

function knowledge_r7_bound(PDO $pdo,array $user,array $row): array {
    $base=json_decode($row['baseline_json'],true,32,JSON_THROW_ON_ERROR);
    $resolved=knowledge_r7_source($pdo,$user,$row['context_key'],$row['agent_namespace'],$base['reference'],$base['state_revision']);
    if(!isset($base['source_hash'])||!hash_equals($base['source_hash'],knowledge_r7_source_hash($resolved)))throw new RuntimeException('Original source evidence changed. Prepare a fresh review.',409);
    capture_r5_project($pdo,$user,$row['project_id'],'researcher');
    if((int)$row['folder_id']>0&&!personal_knowledge_folder($pdo,$user,(int)$row['folder_id']))throw new RuntimeException('Destination folder access changed.',403);
    return $resolved;
}

function knowledge_r7_row(PDO $pdo,array $user,string $key,string $namespace,string $id,bool $lock=false): array {
    chat_research_uuid_v1($id);
    $q=$pdo->prepare('SELECT * FROM chat_knowledge_refresh_r7 WHERE research_id=? AND owner_user_id=? AND context_key=? AND agent_namespace=?'.($lock?' FOR UPDATE':''));
    $q->execute([$id,$user['id'],$key,$namespace]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new RuntimeException('Refresh unavailable for this conversation and lead.',403);
    return $r;
}

function knowledge_r7_list(PDO $pdo,array $user,string $key,string $namespace,array $input): array {
    $state=knowledge_r6_state($pdo,$user,$key,$namespace,false);$items=[];$days=$input['review_days']??30;
    if(!is_int($days)||$days<1||$days>365)throw new InvalidArgumentException('Invalid review interval.');
    if($state['available']&&$state['scope']['mode']!=='off'){
        $f=[];if($state['scope']['mode']==='project')$f['project_id']=$state['scope']['project_id'];
        if($state['scope']['mode']==='folder')$f['folder_id']=$state['scope']['folder_id'];
        foreach(capture_r5_list($pdo,$user,$f)['items'] as $i){
            if($i['knowledge_conflict'])continue;$s=knowledge_r6_annotation($i);$s['freshness']=knowledge_r7_age($s['captured_at'],$days);
            try{knowledge_r7_source($pdo,$user,$key,$namespace,$s,$state['revision']);$s['refreshable']=true;}catch(RuntimeException|InvalidArgumentException $e){$s['refreshable']=false;}
            $items[]=$s;
        }
        foreach(knowledge_r6_reports($pdo,$user,$namespace,$state['scope']) as $s){
            $saved=chat_research_saved_r3($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$s['id']));
            $dates=array_column($saved['answer']['citations'],'as_of');sort($dates);
            $s['freshness']=knowledge_r7_age($dates[0]??'',$days);
            try{knowledge_r7_source($pdo,$user,$key,$namespace,$s,$state['revision']);$s['refreshable']=true;}catch(RuntimeException|InvalidArgumentException $e){$s['refreshable']=false;}
            $items[]=$s;
        }
    }
    $runtimes=[];
    foreach(($state['available']&&$state['scope']['mode']!=='off'?vp3_browser_runtime_list_v2200($pdo,$user,$namespace,20):[]) as $r){try{
        $runtime=vp3_browser_research_runtime_v2230($pdo,$user,$namespace,$r['runtime_id']);
        $runtimes[]=['id'=>$r['runtime_id'],'title'=>$r['title']??'Chrome research','domains'=>vp3_browser_research_allowed_domains_v2230($pdo,$runtime['_multisite'])];
    }catch(RuntimeException $e){}}
    $runs=[];if(table_exists('chat_knowledge_refresh_r7')){
        $q=$pdo->prepare('SELECT research_id FROM chat_knowledge_refresh_r7 WHERE owner_user_id=? AND context_key=? AND agent_namespace=? ORDER BY created_at DESC,research_id DESC LIMIT 20');$q->execute([$user['id'],$key,$namespace]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){try{$runs[]=knowledge_r7_get($pdo,$user,$key,$namespace,$id);}catch(RuntimeException|InvalidArgumentException $e){}}
    }
    return ['state'=>$state,'items'=>array_slice($items,0,75),'runtimes'=>$runtimes,'runs'=>$runs,
        'folders'=>$state['available']&&$state['scope']['mode']!=='off'?personal_knowledge_folders($pdo,$user):[],'projects'=>$state['available']&&$state['scope']['mode']!=='off'?array_values(array_filter(vp3_research_projects_for_user_v2060($pdo,(int)$user['id'],false),static fn($p)=>vp3_research_role_at_least_v2060($p['role']??'','researcher'))):[]];
}

function knowledge_r7_prepare(PDO $pdo,array $user,string $key,string $namespace,?array $agent,array $input,callable $recheck): array {
    if(!table_exists('chat_knowledge_refresh_r7'))throw new RuntimeException('Run the Cloud database upgrade for reviewed refresh.',503);
    if(!is_array($input['reference']??null)||!is_string($input['state_revision']??null))throw new InvalidArgumentException('Select a current source.');
    if($pdo->inTransaction())throw new RuntimeException('Refresh preparation requires its own transaction.');
    $pdo->beginTransaction();
    try{
    $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user['id']]);
    $s=knowledge_r7_source($pdo,$user,$key,$namespace,$input['reference'],$input['state_revision']);
    $project=chat_research_uuid_v1($input['project_id']??'');capture_r5_project($pdo,$user,$project,'researcher');
    $folder=$input['folder_id']??0;if(!is_int($folder)||$folder<0||($folder>0&&!personal_knowledge_folder($pdo,$user,$folder)))throw new RuntimeException('Choose an available Knowledge folder.',403);
    if(($s['state']['scope']['mode']==='project'&&$project!==$s['state']['scope']['project_id'])||($s['state']['scope']['mode']==='folder'&&$folder!==$s['state']['scope']['folder_id']))throw new RuntimeException('Refresh destinations must match the selected context.',409);
    // Only exact source URLs leave this system; old notes, excerpts and draft text are not search queries.
    $question="Review current observations and evidence dates at these exact sources:\n".implode("\n",array_column($s['urls'],'url'));
    $research=chat_research_prepare_v1($pdo,$user,$namespace,$agent,['request_id'=>$input['request_id']??'', 'runtime_id'=>$input['runtime_id']??'',
        'context_key'=>$key,'conversation_id'=>(int)($input['conversation_id']??0),'question'=>$question],$s['urls']);
    $ref=knowledge_r6_refs([$input['reference']])[0];$target=knowledge_r7_target($pdo,$user,$ref);
    $base=['reference'=>$ref,'source_hash'=>knowledge_r7_source_hash($s),'state_revision'=>$s['state']['revision'],'target_revision'=>$target?capture_r5_record_hash($target):null];
    $prior=$pdo->prepare('SELECT * FROM chat_knowledge_refresh_r7 WHERE research_id=?');$prior->execute([$research['id']]);$old=$prior->fetch(PDO::FETCH_ASSOC);
    if($old){if($old['owner_user_id']!=$user['id']||$old['context_key']!==$key||$old['agent_namespace']!==$namespace||json_decode($old['baseline_json'],true)!==$base||$old['project_id']!==$project||(int)$old['folder_id']!==$folder)throw new RuntimeException('This refresh request belongs to another reviewed scope.',409);}
    else $pdo->prepare('INSERT INTO chat_knowledge_refresh_r7(research_id,owner_user_id,context_key,agent_namespace,baseline_json,project_id,folder_id) VALUES(?,?,?,?,?,?,?)')->execute([$research['id'],$user['id'],$key,$namespace,json_encode($base,JSON_THROW_ON_ERROR),$project,$folder]);
    $result=knowledge_r7_get($pdo,$user,$key,$namespace,$research['id']);
    $recheck();knowledge_r7_bound($pdo,$user,knowledge_r7_row($pdo,$user,$key,$namespace,$research['id']));chat_research_authority_v1($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$research['id']));$pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function knowledge_r7_receipt(PDO $pdo,array $user,int $id,string $revision): array {
    if(table_exists('homeserver_workspace_snapshots_v1')){
        $q=$pdo->prepare("SELECT body_json,synced_at FROM homeserver_workspace_snapshots_v1 WHERE user_id=? AND source='homeserver' AND dataset='chats'");$q->execute([$user['id']]);
        if($row=$q->fetch(PDO::FETCH_ASSOC)){foreach(json_decode($row['body_json'],true,512,JSON_THROW_ON_ERROR)['records']??[] as $r){
            if(($r['table']??'')==='knowledge_refresh_receipts_r7'&&(string)($r['data']['knowledge_id']??'')===(string)$id&&($r['data']['knowledge_revision']??'')===$revision)return ['state'=>'received','confirmed_at'=>$row['synced_at']];
        }}
    }
    return ['state'=>'pending','confirmed_at'=>null];
}

function knowledge_r7_get(PDO $pdo,array $user,string $key,string $namespace,string $id): array {
    $row=knowledge_r7_row($pdo,$user,$key,$namespace,$id);$bound=knowledge_r7_bound($pdo,$user,$row);
    $research=chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,(int)$user['id'],$id,$pdo->inTransaction()));$after=$research['mission']['answer']??null;
    $result=['id'=>$id,'source'=>$bound['source'],'research'=>$research,'project_id'=>$row['project_id'],'folder_id'=>(int)$row['folder_id'],
        'status'=>$row['knowledge_id']?'applied':$research['status'],'knowledge_id'=>(int)$row['knowledge_id'],'sync'=>null];
    if($after&&$research['status']==='complete'){
        $result['comparison']=$bound['saved']?chat_research_compare_r3($bound['saved'],['id'=>$id,'question'=>$research['question'],'saved_at'=>'','answer'=>$after]):
            ['before_excerpt'=>$bound['source']['excerpt'],'after'=>$after,'notice'=>'Compare the original captured quote with newly observed findings. These observations do not prove a real-world change.'];
        $result['review_hash']=hash('sha256',json_encode([$row['baseline_json'],$row['project_id'],(int)$row['folder_id'],$after],JSON_THROW_ON_ERROR));
        $target=knowledge_r7_target($pdo,$user,json_decode($row['baseline_json'],true,32,JSON_THROW_ON_ERROR)['reference']);
        $baseline=json_decode($row['baseline_json'],true,32,JSON_THROW_ON_ERROR);
        if(!$row['knowledge_id']&&($target?capture_r5_record_hash($target):null)!==$baseline['target_revision'])throw new RuntimeException('The existing refresh note changed. Prepare a new exact review.',409);
        $result['before_knowledge_content']=$target['content_text']??'';
        $result['knowledge_content']="Reviewed Knowledge refresh (untrusted source evidence)\nOriginal source: ".$bound['source']['url']."\nOriginal capture: ".$bound['source']['captured_at']."\nOriginal reference: ".$bound['source']['kind'].':'.$bound['source']['id']."\n\n".$after['text'];
    }
    if($row['knowledge_id']){
        $q=$pdo->prepare("SELECT * FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'");$q->execute([$row['knowledge_id'],$user['id']]);$k=$q->fetch(PDO::FETCH_ASSOC);
        if(!$k)throw new RuntimeException('The refreshed Knowledge note is unavailable.',403);
        if(capture_r5_record_hash($k)!==$row['knowledge_revision']){$result['status']='superseded';$result['sync']=['state'=>'superseded'];$result['review_hash']=$row['applied_hash'];return $result;}
        $result['review_hash']=$row['applied_hash'];$result['sync']=knowledge_r7_receipt($pdo,$user,(int)$row['knowledge_id'],$row['knowledge_revision']);
        $result['report']=$research['mission']['report']??null;$result['knowledge_url']=url('/personal-knowledge.php');
    }
    return $result;
}

function knowledge_r7_apply(PDO $pdo,array $user,string $key,string $namespace,array $input,callable $recheck): array {
    if(!function_exists('vp3_browser_research_save_v2230'))require_once __DIR__.'/browser-research-save-v2230.php';
    capture_r5_permission($user,true);
    if(($input['confirmed']??null)!==true||!is_string($input['expected_hash']??null))throw new InvalidArgumentException('Review the exact Knowledge update before applying.');
    $pdo->beginTransaction();
    try{
        $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user['id']]);
        $row=knowledge_r7_row($pdo,$user,$key,$namespace,(string)($input['id']??''),true);$preview=knowledge_r7_get($pdo,$user,$key,$namespace,$row['research_id']);
        if(!isset($preview['review_hash'])||!hash_equals($preview['review_hash'],$input['expected_hash']))throw new RuntimeException('Refresh findings changed. Review them again.',409);
        if($row['knowledge_id']){$recheck();$pdo->commit();return $preview;}
        if($preview['research']['status']!=='complete'||empty($preview['research']['mission']['answer']['claims']))throw new RuntimeException('Wait for completed, receipt-backed findings.',409);
        $saved=vp3_browser_research_save_v2230($pdo,$user,$preview['research']['mission']['mission_id'],$row['project_id']);
        if(($saved['project']['id']??'')!==$row['project_id'])throw new RuntimeException('The saved report belongs to another project.',409);
        $content=$preview['knowledge_content'];
        $base=json_decode($row['baseline_json'],true,32,JSON_THROW_ON_ERROR);$ref=$base['reference'];
        $target=knowledge_r7_target($pdo,$user,$ref);
        if(($target?capture_r5_record_hash($target):null)!==$base['target_revision'])throw new RuntimeException('The existing refresh note changed. Prepare a new exact review.',409);
        $id=personal_knowledge_store($user,'knowledge-refresh-r7:'.$ref['kind'].':'.$ref['id'],'Reviewed refresh — '.$preview['source']['title'],$content,'Reviewed source update; original evidence retained.',(int)$row['folder_id']);
        $q=$pdo->prepare('SELECT * FROM knowledge_items WHERE id=?');$q->execute([$id]);$k=$q->fetch(PDO::FETCH_ASSOC);
        $recheck();knowledge_r7_bound($pdo,$user,$row);capture_r5_permission($user,true);
        $pdo->prepare('UPDATE chat_knowledge_refresh_r7 SET knowledge_id=?,knowledge_revision=?,applied_hash=?,applied_at=UTC_TIMESTAMP() WHERE research_id=?')->execute([$id,capture_r5_record_hash($k),$input['expected_hash'],$row['research_id']]);
        $pdo->commit();return knowledge_r7_get($pdo,$user,$key,$namespace,$row['research_id']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
