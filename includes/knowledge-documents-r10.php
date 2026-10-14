<?php
declare(strict_types=1);

/** New documents use the canonical personal Knowledge writer and the existing action ledger. */
function knowledge_r10_key(string $project='',int $folder=0): string {
    return 'new-document:'.($project===''?'-':$project).':'.$folder;
}
function knowledge_r10_target(PDO $pdo,array $user,string $key): array {
    if(!preg_match('/^new-document:(-|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}):(0|[1-9][0-9]{0,9})$/D',$key,$m))throw new InvalidArgumentException('Choose an existing project and personal Knowledge folder.');
    $project=$m[1]==='-'?'':$m[1];$folder=(int)$m[2];$p=null;$f=null;
    if($pdo->inTransaction()){
        if($project!=='')$pdo->prepare('SELECT id FROM research_projects_v2060 WHERE public_id=? FOR UPDATE')->execute([$project]);
        if($folder>0)$pdo->prepare('SELECT id FROM artist_transcript_folders_v177 WHERE id=? FOR UPDATE')->execute([$folder]);
    }
    if($project!==''){try{$p=capture_r5_project($pdo,$user,$project,'researcher');}catch(RuntimeException $e){throw new RuntimeException('Destination project is unavailable or read-only.',403);}}
    if($folder>0){$f=personal_knowledge_folder($pdo,$user,$folder);if(!$f)throw new RuntimeException('Destination folder is unavailable.',403);}
    $destination=['project_id'=>$project,'project_title'=>$p['title']??'No project','folder_id'=>$folder,'folder_title'=>$f['folder_name']??'Unfiled personal Knowledge'];
    return ['key'=>$key,'dataset'=>'knowledge','revision'=>knowledge_r8_hash([$user['id'],$destination,$p,$f]),'before'=>[], 'destination'=>$destination];
}
function knowledge_r10_destinations(PDO $pdo,array $user): array {
    $projects=[];
    if(vp3_research_schema_ready_v2060($pdo))foreach(vp3_research_projects_for_user_v2060($pdo,(int)$user['id'],false) as $p){
        if(vp3_research_role_at_least_v2060($p['role'],'researcher'))$projects[]=['id'=>$p['id'],'title'=>$p['title']];
    }
    return ['projects'=>$projects,'folders'=>array_map(static fn($f)=>['id'=>(int)$f['id'],'title'=>$f['folder_name']],personal_knowledge_folders($pdo,$user))];
}
function knowledge_r10_cited_fields(array $fields,array $packet): array {
    preg_match_all('/\[P([0-9]+)\]/',$fields['content_text'],$matches);
    foreach($matches[1] as $label)if((int)$label<1||(int)$label>count($packet['sources']))throw new InvalidArgumentException('Document cites a source outside its reviewed evidence.');
    $lines=['','Sources — historical evidence; saved dates do not establish current facts.'];
    foreach($packet['sources'] as $s){
        $lines[]='['.$s['label'].'] '.$s['title'].' | '.$s['url'].' | Evidence date: '.($s['captured_at']?:'unknown');
        $lines[]='Source: '.$s['kind'].':'.$s['id'].' | Revision: '.$s['revision'];
    }
    $fields['content_text']=trim($fields['content_text'])."\n".implode("\n",$lines);
    if(mb_strlen($fields['content_text'])>50000)throw new InvalidArgumentException('Document and source citations exceed the text limit.');
    return $fields;
}
function knowledge_r10_write(PDO $pdo,array $user,array $row,array $preview): array {
    $d=$preview['target']['destination'];$fields=$preview['fields'];
    $id=personal_knowledge_store($user,'knowledge-document-r10:'.$row['id'],$fields['title'],$fields['content_text'],$fields['description'],(int)$d['folder_id']);
    $q=$pdo->prepare("SELECT * FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'");$q->execute([$id,$user['id']]);$record=$q->fetch(PDO::FETCH_ASSOC);
    if(!$record||$record['title']!==$fields['title']||$record['description']!==$fields['description']||$record['content_text']!==$fields['content_text'])throw new RuntimeException('Canonical document differs from its approved fields.',409);
    if($d['project_id']!==''){
        $project=capture_r5_project($pdo,$user,$d['project_id'],'researcher');
        vp3_research_event_v2060($pdo,(int)$project['id'],(int)$user['id'],'knowledge.created','knowledge',(string)$id,['action_id'=>$row['id'],'personal'=>true]);
    }
    return ['record_key'=>'knowledge_items:'.$id,'record_revision'=>workspace_native_revision_v1($record),'applied'=>true,'authority_source'=>'cloud','source_url'=>url('/knowledge.php?edit='.$id),'project_id'=>$d['project_id'],'folder_id'=>$d['folder_id'],'applied_at'=>gmdate(DATE_ATOM)];
}
/** Project association is a pointer in the immutable action receipt, never another Knowledge copy. */
function knowledge_r10_project_documents(PDO $pdo,array $user,string $project,?int $id=null): array {
    if(!table_exists('chat_knowledge_actions_r8')||!personal_capability_has_v242('personal_knowledge.access',$user))return [];
    capture_r5_project($pdo,$user,$project);$args=[$user['id'],$project];
    $sql="SELECT result_json FROM chat_knowledge_actions_r8 WHERE owner_user_id=? AND status='applied' AND JSON_UNQUOTE(JSON_EXTRACT(preview_json,'$.operation'))='knowledge.create' AND JSON_UNQUOTE(JSON_EXTRACT(preview_json,'$.target.destination.project_id'))=?";
    if($id!==null){$sql.=" AND JSON_UNQUOTE(JSON_EXTRACT(result_json,'$.record_key'))=?";$args[]='knowledge_items:'.$id;}
    $q=$pdo->prepare($sql.' ORDER BY created_at DESC LIMIT 100');$q->execute($args);$out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $json){
        $receipt=json_decode($json,true,32,JSON_THROW_ON_ERROR);$key=$receipt['record_key']??'';
        try{[$table,$record]=workspace_native_owned_row_v1($pdo,(int)$user['id'],'knowledge',$key);workspace_native_authorize_v1($pdo,(int)$user['id'],$table,$record);
            if((int)($record['folder_id']??0)>0&&!personal_knowledge_folder($pdo,$user,(int)$record['folder_id']))continue;
            $out[]=['id'=>(int)$record['id'],'title'=>$record['title'],'url'=>url('/knowledge.php?edit='.$record['id'])];
        }catch(RuntimeException $e){}
    }
    return $out;
}
