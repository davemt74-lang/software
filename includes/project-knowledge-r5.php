<?php
declare(strict_types=1);
require_once __DIR__.'/browser-capture-routing-r4.php';
require_once __DIR__.'/homeserver-workspace-sync-v1.php';

const VP3_PROJECT_KNOWLEDGE_R5='vp3.project-knowledge.r5';

function capture_r5_permission(array $user,bool $write=false): void
{
    if((int)($user['id']??0)<1||!has_permission('chat.access',$user)||!personal_knowledge_available($user)
       ||($write&&!personal_capability_has_v242('personal_knowledge.manage',$user)))throw new RuntimeException('Chat Knowledge access is unavailable.',403);
}

function capture_r5_revision(array $item): string
{
    return hash('sha256',json_encode([$item['id'],$item['note'],array_values($item['tags']),$item['project_id'],(int)$item['folder_id'],(int)$item['knowledge_id'],(string)($item['knowledge_revision']??'')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function capture_r5_record_hash(array $row): string
{
    $row=workspace_sync_safe_v1($row);ksort($row);
    return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function capture_r5_receipts(PDO $pdo,int $owner): array
{
    if(!table_exists('homeserver_workspace_snapshots_v1'))return [];
    $q=$pdo->prepare("SELECT body_json,synced_at FROM homeserver_workspace_snapshots_v1 WHERE user_id=? AND source='homeserver' AND dataset='chats'");$q->execute([$owner]);$snapshot=$q->fetch(PDO::FETCH_ASSOC);
    if(!$snapshot)return [];
    $out=[];
    try{foreach(json_decode($snapshot['body_json'],true,512,JSON_THROW_ON_ERROR)['records']??[] as $r){
        if(($r['table']??'')==='browser_capture_receipts_r5'&&is_array($r['data']??null))$out[(string)$r['source_id']]=['data'=>$r['data'],'synced_at'=>$snapshot['synced_at']];
    }}catch(JsonException $e){}
    return $out;
}

function capture_r5_project(PDO $pdo,array $user,string $id,string $role='viewer'): ?array
{
    if($id==='')return null;
    return vp3_research_project_require_v2060($pdo,$id,(int)$user['id'],$role);
}

function capture_r5_rows(PDO $pdo,array $user,string $id='',bool $lock=false): array
{
    if(!capture_r4_schema_ready($pdo))return [];
    $sql="SELECT d.id FROM browser_capture_deliveries_r4 d JOIN browser_shares_v2010 s ON s.id=d.browser_share_id
      JOIN chat_conversations c ON c.id=d.conversation_id JOIN chat_messages m ON m.id=d.chat_message_id AND m.conversation_id=c.id
      WHERE d.owner_user_id=? AND s.sender_user_id=? AND c.user_id=? AND s.deleted_at IS NULL";
    $args=array_fill(0,3,(int)$user['id']);
    if($id!==''){$sql.=' AND s.public_id=?';$args[]=$id;}
    $sql.=' ORDER BY d.id DESC'.($id!==''?' LIMIT 1':'').($lock?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute($args);$rows=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $pk){
        $q=$pdo->prepare('SELECT * FROM browser_capture_deliveries_r4 WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$pk]);$d=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM browser_shares_v2010 WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$d['browser_share_id']]);$s=$q->fetch(PDO::FETCH_ASSOC);
        $k=null;if((int)$d['knowledge_id']>0){$q=$pdo->prepare("SELECT * FROM knowledge_items WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'".($lock?' FOR UPDATE':''));$q->execute([$d['knowledge_id'],$user['id']]);$k=$q->fetch(PDO::FETCH_ASSOC)?:null;if(!$k)continue;}
        $rows[]=['delivery'=>$d,'share'=>$s,'knowledge'=>$k];
    }
    return $rows;
}

function capture_r5_item(PDO $pdo,array $user,array $row,array $receipts=[]): array
{
    $d=$row['delivery'];$s=$row['share'];$k=$row['knowledge'];
    $project=null;$projectAvailable=true;
    if($d['project_public_id']!==''){try{$project=capture_r5_project($pdo,$user,$d['project_public_id']);}catch(RuntimeException $e){$projectAvailable=false;}}
    $folder=(int)$d['folder_id']>0?personal_knowledge_folder($pdo,$user,(int)$d['folder_id']):null;
    $item=['id'=>$s['public_id'],'title'=>$s['source_title']?:$s['source_domain'],'source_url'=>$s['source_url'],
        'selection'=>$s['selected_text'],'note'=>$s['user_note'],'captured_at'=>$s['captured_at'],
        'tags'=>json_decode($d['tags_json'],true,32,JSON_THROW_ON_ERROR),'project_id'=>$projectAvailable?$d['project_public_id']:'',
        'project_title'=>$project['title']??($projectAvailable?'':'Project access unavailable'),
        'folder_id'=>(int)$d['folder_id'],'folder_name'=>$folder['folder_name']??'',
        'knowledge_id'=>(int)$d['knowledge_id'],'cloud_conversation_id'=>(int)$d['conversation_id'],
        'source_id'=>(int)($s['source_id']??0),'source_version_id'=>(int)($s['source_version_id']??0),'can_edit'=>$projectAvailable&&personal_capability_has_v242('personal_knowledge.manage',$user),
        'authority_source'=>'vp3_cloud','knowledge_revision'=>$k?capture_r5_record_hash($k):''];
    // The revision includes the canonical assignment even when its project title is no longer readable.
    $item['knowledge_conflict']=$k&&capture_r5_knowledge_changed(array_replace($item,['project_id'=>$d['project_public_id']]),$k);
    if($item['knowledge_conflict'])$item['can_edit']=false;
    $item['revision']=capture_r5_revision(array_replace($item,['project_id'=>$d['project_public_id']]));
    $receipt=$receipts[(string)$s['id']]??null;$received=false;
    if($receipt){$r=$receipt['data'];$received=($r['capture_id']??'')===$s['public_id']&&($r['delivery_revision']??'')===capture_r5_record_hash($d)
        &&($r['share_revision']??'')===capture_r5_record_hash($s)&&($r['knowledge_revision']??'')===($k?capture_r5_record_hash($k):'');}
    $item['sync']=['state'=>$received?'received':'pending','confirmed_at'=>$received?$receipt['synced_at']:null,'last_confirmed_at'=>$receipt['synced_at']??null];
    return $item;
}

function capture_r5_filters(array $input): array
{
    $out=[];
    foreach(['q'=>240,'tag'=>40,'project_id'=>36,'after'=>10,'before'=>10] as $key=>$max){$v=$input[$key]??'';if(!is_string($v)||mb_strlen($v)>$max)throw new RuntimeException('Invalid annotation filter.',422);$out[$key]=trim($v);}
    foreach(['after','before'] as $key)if($out[$key]!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$out[$key])||date('Y-m-d',strtotime($out[$key]))!==$out[$key]))throw new RuntimeException('Choose a valid capture date.',422);
    if($out['after']&&$out['before']&&$out['after']>$out['before'])throw new RuntimeException('The capture date range is reversed.',422);
    $folder=$input['folder_id']??null;if($folder!==null&&(!is_int($folder)||$folder<0))throw new RuntimeException('Choose an existing Knowledge folder.',422);$out['folder_id']=$folder;
    $offset=$input['offset']??0;if(!is_int($offset)||$offset<0||$offset>100000)throw new RuntimeException('Invalid result page.',422);$out['offset']=$offset;
    return $out;
}

function capture_r5_match(array $item,array $f): bool
{
    if($f['project_id']!==''&&$item['project_id']!==$f['project_id'])return false;
    if($f['folder_id']!==null&&$item['folder_id']!==$f['folder_id'])return false;
    if($f['tag']!==''&&!in_array(mb_strtolower($f['tag']),array_map('mb_strtolower',$item['tags']),true))return false;
    $day=substr((string)$item['captured_at'],0,10);
    if(($f['after']!==''&&$day<$f['after'])||($f['before']!==''&&$day>$f['before']))return false;
    $hay=mb_strtolower(implode(' ',[$item['title'],$item['selection'],$item['note'],implode(' ',$item['tags'])]));
    foreach(preg_split('/\s+/u',mb_strtolower($f['q']),-1,PREG_SPLIT_NO_EMPTY)?:[] as $term)if(mb_strpos($hay,$term)===false)return false;
    return true;
}

function capture_r5_list(PDO $pdo,array $user,array $input=[]): array
{
    capture_r5_permission($user);$f=capture_r5_filters($input);
    if($f['project_id']!=='')capture_r5_project($pdo,$user,$f['project_id']);
    if(($f['folder_id']??0)>0&&!personal_knowledge_folder($pdo,$user,$f['folder_id']))throw new RuntimeException('The Knowledge folder is unavailable.',403);
    $receipts=capture_r5_receipts($pdo,(int)$user['id']);$items=[];
    if(isset($input['id'])&&(!is_string($input['id'])||!vp3_extension_valid_uuid_v2000($input['id'])))throw new RuntimeException('Invalid annotation identity.',422);
    foreach(capture_r5_rows($pdo,$user,(string)($input['id']??'')) as $row){$item=capture_r5_item($pdo,$user,$row,$receipts);if(capture_r5_match($item,$f))$items[]=$item;}
    $projects=[];if(vp3_research_schema_ready_v2060($pdo))foreach(vp3_research_projects_for_user_v2060($pdo,(int)$user['id'],false) as $p)$projects[]=['id'=>$p['id'],'title'=>$p['title'],'can_edit'=>vp3_research_role_at_least_v2060($p['role'],'researcher')];
    return ['contract'=>VP3_PROJECT_KNOWLEDGE_R5,'items'=>array_slice($items,$f['offset'],25),'count'=>count($items),'offset'=>$f['offset'],
        'folders'=>personal_knowledge_folders($pdo,$user),'projects'=>$projects,'can_edit'=>personal_capability_has_v242('personal_knowledge.manage',$user)];
}

function capture_r5_body(array $item): string
{
    return "Captured browser annotation (untrusted source data)\n\n".$item['selection']."\n\nYour annotation: ".$item['note']."\nSource: ".$item['source_url']."\nCaptured: ".$item['captured_at']." UTC\nCapture: ".$item['id']."\nProject: ".$item['project_id']."\nTags: ".implode(', ',$item['tags']);
}

function capture_r5_knowledge_changed(array $item,array $knowledge): bool
{
    // Organization edits may only regenerate an untouched canonical annotation note.
    $title=mb_strimwidth(trim(preg_replace('/\s+/u',' ',$item['title'])??$item['title']),0,190,'…');
    $expected=['title'=>$title,'description'=>trim('Browser annotation · '.implode(', ',$item['tags'])),
        'content_text'=>trim(capture_r5_body($item)),'file_name'=>'personal-'.sha1('browser-share:'.$item['id']).'.txt',
        'file_path'=>'','file_type'=>'personal_note','mime_type'=>'text/plain','knowledge_scope'=>'personal','visibility'=>'private'];
    foreach($expected as $key=>$value)if((string)($knowledge[$key]??'')!==$value)return true;
    return (int)($knowledge['folder_id']??0)!==(int)$item['folder_id']||(int)($knowledge['file_size']??0)!==0
        ||(int)($knowledge['track_id']??0)!==0||(int)($knowledge['is_published']??0)!==0;
}

function capture_r5_edit(PDO $pdo,array $user,array $input,?callable $recheck=null): array
{
    capture_r5_permission($user,true);
    if(($input['confirmed']??false)!==true||!is_string($input['id']??null)||!vp3_extension_valid_uuid_v2000($input['id'])
       ||!is_string($input['expected_revision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['expected_revision']))throw new RuntimeException('Review the exact annotation changes before saving.',422);
    $note=$input['note']??null;$tags=$input['tags']??null;$project=$input['project_id']??null;$folder=$input['folder_id']??null;
    if(!is_string($note)||strlen($note)>4096||!is_array($tags)||count($tags)>20||!is_string($project)||($project!==''&&!vp3_extension_valid_uuid_v2000($project))||!is_int($folder)||$folder<0)throw new RuntimeException('Invalid annotation changes.',422);
    foreach($tags as $tag)if(!is_string($tag)||mb_strlen(trim($tag))>40)throw new RuntimeException('Use up to 20 tags of 40 characters.',422);
    $tags=vp3_research_tags_v2060($tags);$note=trim($note);
    if($pdo->inTransaction())throw new RuntimeException('Annotation editing requires its own transaction.');
    $pdo->beginTransaction();
    try{
        $row=capture_r5_rows($pdo,$user,$input['id'],true)[0]??null;if(!$row)throw new RuntimeException('The annotation is unavailable.',410);
        $d=$row['delivery'];$s=$row['share'];$old=capture_r5_item($pdo,$user,$row);$oldProject=(string)$d['project_public_id'];
        if($old['knowledge_conflict'])throw new RuntimeException('The Knowledge item was edited separately. Review it in Knowledge before changing this annotation.',409);
        if($oldProject!=='')capture_r5_project($pdo,$user,$oldProject,'researcher');
        if($project!=='')capture_r5_project($pdo,$user,$project,'researcher');
        if($folder>0&&!personal_knowledge_folder($pdo,$user,$folder))throw new RuntimeException('The destination folder is unavailable.',403);
        if($folder>0&&!$row['knowledge'])throw new RuntimeException('This annotation was not saved to Knowledge.',422);
        $desired=array_replace($old,['note'=>$note,'tags'=>$tags,'project_id'=>$project,'folder_id'=>$folder]);
        if(hash_equals(capture_r5_revision($desired),$old['revision'])){if($recheck)$recheck();$pdo->commit();return ['contract'=>VP3_PROJECT_KNOWLEDGE_R5,'item'=>$old,'idempotent_replay'=>true];}
        if(!hash_equals($old['revision'],$input['expected_revision'])){
            if(!hash_equals(capture_r5_revision($desired),$old['revision']))throw new RuntimeException('This annotation changed. Refresh and review the current version.',409);
            if($recheck)$recheck();$pdo->commit();return ['contract'=>VP3_PROJECT_KNOWLEDGE_R5,'item'=>$old,'idempotent_replay'=>true];
        }
        if($oldProject!==''&&$oldProject!==$project){$p=capture_r5_project($pdo,$user,$oldProject,'researcher');$pdo->prepare("UPDATE research_project_items_v2060 SET item_status='removed',updated_at=UTC_TIMESTAMP() WHERE project_id=? AND browser_share_id=? AND item_type='annotation'")->execute([$p['id'],$s['id']]);}
        if($project!==''){
            vp3_research_assign_share_v2060($pdo,(int)$user['id'],$project,$input['id'],$note,$tags);$p=capture_r5_project($pdo,$user,$project,'researcher');
            $pdo->prepare("UPDATE research_project_items_v2060 SET note=?,tags_json=?,updated_at=UTC_TIMESTAMP() WHERE project_id=? AND browser_share_id=? AND item_type='annotation'")->execute([$note,json_encode($tags,JSON_THROW_ON_ERROR),$p['id'],$s['id']]);
        }
        $pdo->prepare('UPDATE browser_shares_v2010 SET user_note=? WHERE id=?')->execute([$note,$s['id']]);
        $pdo->prepare('UPDATE browser_capture_deliveries_r4 SET tags_json=?,project_public_id=?,folder_id=? WHERE id=?')->execute([json_encode($tags,JSON_THROW_ON_ERROR),$project,$folder,$d['id']]);
        if($row['knowledge']){
            $kid=personal_knowledge_store($user,'browser-share:'.$input['id'],$old['title'],capture_r5_body($desired),'Browser annotation · '.implode(', ',$tags),$folder);
            if($kid!==(int)$d['knowledge_id'])throw new RuntimeException('The canonical Knowledge identity changed.',409);
        }
        if($recheck)$recheck();
        // Revalidate destinations within the transaction immediately before commit.
        capture_r5_permission($user,true);if($project!=='')capture_r5_project($pdo,$user,$project,'researcher');
        if($oldProject!=='')capture_r5_project($pdo,$user,$oldProject,'researcher');
        if($folder>0&&!personal_knowledge_folder($pdo,$user,$folder))throw new RuntimeException('Folder access changed.',403);
        $item=capture_r5_item($pdo,$user,capture_r5_rows($pdo,$user,$input['id'],true)[0]);$pdo->commit();
        return ['contract'=>VP3_PROJECT_KNOWLEDGE_R5,'item'=>$item,'idempotent_replay'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function capture_r5_retrieve(PDO $pdo,array $user,string $query,array $scope): array
{
    if(!function_exists('vp3_research_schema_ready_v2060')||!capture_r4_schema_ready($pdo)||($scope['mode']??'off')==='off'||!personal_knowledge_available($user)||!has_permission('chat.access',$user))return [];
    $terms=knowledge_retrieval_v162_terms($query);if(!$terms)return [];
    $f=['q'=>'','folder_id'=>($scope['mode']??'')==='folder'?(int)$scope['folder_id']:null,'tag'=>'','project_id'=>'','after'=>'','before'=>''];$ranked=[];
    foreach(capture_r5_rows($pdo,$user) as $row){$i=capture_r5_item($pdo,$user,$row);if(!capture_r5_match($i,$f))continue;
        $score=knowledge_retrieval_v162_score(['title'=>$i['title'],'chunk_text'=>$i['selection'].' '.$i['note'].' '.implode(' ',$i['tags'])],$terms,$query);if($score<=0)continue;
        $ranked[]=['source'=>'cloud','item_id'=>$i['knowledge_id'],'chunk_id'=>0,'chunk_index'=>0,'title'=>$i['title'],'folder_id'=>$i['folder_id'],'folder_name'=>$i['folder_name'],
            'excerpt'=>knowledge_retrieval_v162_excerpt($i['selection'],650)."\nYour note: ".knowledge_retrieval_v162_excerpt($i['note'],250)."\nSource: ".$i['source_url']."\nCaptured: ".$i['captured_at'].' UTC',
            'score'=>$score,'capture_id'=>$i['id'],'source_url'=>$i['source_url'],'captured_at'=>$i['captured_at'],'source_version_id'=>$i['source_version_id'],'project_id'=>$i['project_id'],
            'canonical_id'=>'browser-share:'.$i['id'],'authority_source'=>'vp3_cloud','record_revision'=>$i['revision']];
    }
    usort($ranked,static fn($a,$b)=>[$b['score'],$b['captured_at'],$b['capture_id']]<=>[$a['score'],$a['captured_at'],$a['capture_id']]);return array_slice($ranked,0,4);
}

function capture_r5_sources(array $citations): array
{
    $out=[];
    foreach($citations as $c)if(!empty($c['capture_id'])&&!empty($c['source_url']))$out[]=['source'=>'browser-annotation:'.$c['capture_id'],'title'=>'['.$c['label'].'] '.$c['title'].' · captured '.$c['captured_at'].' UTC','url'=>$c['source_url'],'excerpt'=>$c['excerpt']];
    return $out;
}

function capture_r5_home_sources(PDO $pdo,array $user,array $result): array
{
    $out=[];
    if(!personal_knowledge_available($user)||!has_permission('chat.access',$user))return [];
    foreach(array_slice((array)($result['annotation_citations']??[]),0,4) as $c){
        if(!is_array($c)||!is_string($c['id']??null)||!vp3_extension_valid_uuid_v2000($c['id'])||!preg_match('/^A[1-4]$/D',(string)($c['label']??'')))continue;
        $row=capture_r5_rows($pdo,$user,$c['id'])[0]??null;if(!$row)continue;
        $i=capture_r5_item($pdo,$user,$row);if(($c['record_revision']??'')!==$i['revision']||($c['source_url']??'')!==$i['source_url'])continue;
        $out[]=['source'=>'browser-annotation:'.$i['id'],'title'=>'['.$c['label'].'] '.$i['title'].' · captured '.$i['captured_at'].' UTC','url'=>$i['source_url'],'excerpt'=>knowledge_retrieval_v162_excerpt($i['selection'])];
    }
    return $out;
}
