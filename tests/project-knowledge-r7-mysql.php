<?php
declare(strict_types=1);
// Actual InnoDB captures, contexts, requests, refresh ledger, Knowledge and indexes.
// Browser/model outputs are deterministic; existing R1/R3 suites cover their reader/save engines.
function vp3_browser_research_runtime_v2230(PDO $pdo,array $user,string $namespace,string $id):array{
    if(!$GLOBALS['readerAllowed'])throw new RuntimeException('Chrome reader revoked.',403);
    return ['runtime_id'=>$id,'_multisite'=>['id'=>1]];
}
function vp3_browser_research_allowed_domains_v2230(PDO $pdo,array $multi):array{return $GLOBALS['readerAllowed']?['supplier.example']:[];}
function vp3_browser_runtime_row_v2200(PDO $pdo,int $uid,string $namespace,string $id,bool $lock=false):?array{return $GLOBALS['readerAllowed']?['status'=>'active','allowed_actions_json'=>'["browser_research"]']:null;}
function vp3_browser_delegation_json_array_v2190(string $s):array{return json_decode($s,true);}
function vp3_browser_multisite_row_v2220(PDO $pdo,array $row):array{return ['id'=>1];}
function vp3_browser_research_public_v2230(PDO $pdo,array $user,string $id):array{
    $q=$pdo->prepare('SELECT payload FROM fixture_refresh_missions WHERE id=? AND owner_user_id=?');$q->execute([$id,$user['id']]);$raw=$q->fetchColumn();if(!$raw)throw new RuntimeException('Mission unavailable.',403);return json_decode($raw,true);
}
function vp3_browser_research_save_v2230(PDO $pdo,array $user,string $id,string $project):array{
    $p=capture_r5_project($pdo,$user,$project,'researcher');$m=vp3_browser_research_public_v2230($pdo,$user,$id);
    if(empty($m['report'])){
        $report=vp3_research_create_report_v2060($pdo,(int)$user['id'],$project,'Reviewed source draft',chat_research_answer_r3($m)['text']);
        $m['report']=$report;$m['project']=['id'=>$project];
        $pdo->prepare('UPDATE fixture_refresh_missions SET payload=? WHERE id=?')->execute([json_encode($m),$id]);
    }
    return $m;
}
function rejected_r7(callable $fn,int $code):void{try{$fn();}catch(RuntimeException $e){if($e->getCode()===$code)return;throw $e;}throw new RuntimeException('Expected R7 rejection '.$code);}
$readerAllowed=true;
$captureR5Acceptance=static function(PDO $pdo,array $user,array $session,array $first):void{
    require_once __DIR__.'/../includes/project-knowledge-r7.php';
    knowledge_r6_ensure_schema($pdo);knowledge_r7_ensure_schema($pdo);knowledge_r7_ensure_schema($pdo);chat_research_schema_v1($pdo);
    $pdo->exec('CREATE TABLE fixture_refresh_missions(id CHAR(36) PRIMARY KEY,owner_user_id INT,payload JSON)');
    $key='cloud:'.$first['conversation_id'];$ns='system';$state=knowledge_r6_state($pdo,$user,$key,$ns);
    $item=capture_r5_list($pdo,$user,['id'=>$first['browser_share_id']])['items'][0];
    $state=knowledge_r6_set($pdo,$user,$key,$ns,['scope'=>['mode'=>'project','project_id'=>$item['project_id']],'expected_revision'=>$state['revision']]);
    $input=['reference'=>['kind'=>'annotation','id'=>$item['id'],'revision'=>$item['revision']],'state_revision'=>$state['revision'],'runtime_id'=>'77777777-7777-4777-8777-777777777777','request_id'=>'88888888-8888-4888-8888-888888888888','project_id'=>$item['project_id'],'folder_id'=>1,'conversation_id'=>(int)$first['conversation_id']];
    $noop=static function():void{};
    $plan=knowledge_r7_prepare($pdo,$user,$key,$ns,null,$input,$noop);
    check($plan['status']==='prepared'&&count($plan['research']['sources'])===1,'R7 did not prepare the exact source.');
    check(!str_contains($plan['research']['question'],$item['note'])&&!str_contains($plan['research']['question'],$item['selection']),'Private notes or excerpts became external queries.');
    check(knowledge_r7_prepare($pdo,$user,$key,$ns,null,$input,$noop)['id']===$plan['id'],'Exact preparation retry duplicated the request.');
    rejected_r7(fn()=>knowledge_r7_prepare($pdo,$user,$key,$ns,null,array_replace($input,['folder_id'=>2]),$noop),403);
    rejected_r7(fn()=>knowledge_r7_prepare($pdo,$user,$key,$ns,null,array_replace($input,['request_id'=>'99999999-9999-4999-8999-999999999999']),static function(){throw new RuntimeException('Revoked before commit.',403);}),403);
    check((int)$pdo->query('SELECT COUNT(*) FROM chat_knowledge_refresh_r7')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM chat_browser_research_requests_v1')->fetchColumn()===1&&!$pdo->inTransaction(),'Failed preparation left an orphan request/pointer.');
    $row=chat_research_row_v1($pdo,7,$plan['id']);$GLOBALS['readerAllowed']=false;rejected_r7(fn()=>chat_research_authority_v1($pdo,$user,$row),403);$GLOBALS['readerAllowed']=true;
    $mission='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$source=$plan['research']['sources'][0]['url'];
    $public=['mission_id'=>$mission,'status'=>'completed','question'=>'Reviewed exact source','claims'=>[['id'=>'price','key'=>'flour','value'=>'25.50','statement'=>'Supplier lists flour price','state'=>'single_source','evidence'=>[['domain'=>'supplier.example','excerpt'=>'Flour: 25.50','directness'=>'direct','fingerprint'=>hash('sha256',$source),'source_id'=>'receipt-source','source_version_id'=>'receipt-version','as_of'=>'2026-10-13']]]],'gaps'=>['Availability not independently verified']];
    $pdo->prepare('INSERT INTO fixture_refresh_missions VALUES(?,?,?)')->execute([$mission,7,json_encode($public)]);
    $pdo->prepare("UPDATE chat_browser_research_requests_v1 SET status='complete',mission_id=? WHERE id=?")->execute([$mission,$plan['id']]);
    $preview=knowledge_r7_get($pdo,$user,$key,$ns,$plan['id']);check($preview['status']==='complete'&&str_contains($preview['knowledge_content'],'[C1]')&&str_contains($preview['knowledge_content'],'receipt-version'),'Preview omitted pinned citations.');
    $apply=['id'=>$plan['id'],'expected_hash'=>$preview['review_hash'],'confirmed'=>true];
    rejected_r7(fn()=>knowledge_r7_apply($pdo,$user,$key,$ns,array_replace($apply,['expected_hash'=>str_repeat('0',64)]),$noop),409);
    $before=(int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn();$reports=(int)$pdo->query('SELECT COUNT(*) FROM research_reports_v2060')->fetchColumn();
    rejected_r7(fn()=>knowledge_r7_apply($pdo,$user,$key,$ns,$apply,static function(){throw new RuntimeException('Revoked before commit.',403);}),403);
    check((int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn()===$before&&(int)$pdo->query('SELECT COUNT(*) FROM research_reports_v2060')->fetchColumn()===$reports&&!$pdo->inTransaction(),'Failed apply saved partial Knowledge/Research.');
    $applied=knowledge_r7_apply($pdo,$user,$key,$ns,$apply,$noop);$again=knowledge_r7_apply($pdo,$user,$key,$ns,$apply,$noop);
    check($applied['status']==='applied'&&$again['knowledge_id']===$applied['knowledge_id']&&(int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn()===$before+1,'Apply retry duplicated the note.');
    $q=$pdo->prepare('SELECT * FROM knowledge_items WHERE id=?');$q->execute([$applied['knowledge_id']]);$knowledge=$q->fetch(PDO::FETCH_ASSOC);
    check($knowledge['content_text']===$preview['knowledge_content']&&(int)$knowledge['folder_id']===1,'Applied body differs from the exact preview.');
    check(capture_r5_list($pdo,$user,['id'=>$item['id']])['items'][0]['revision']===$item['revision'],'Refresh mutated the original capture/history.');
    workspace_sync_schema_v1($pdo);$revision=capture_r5_record_hash($knowledge);
    $body=json_encode(['records'=>[['table'=>'knowledge_refresh_receipts_r7','data'=>['knowledge_id'=>(string)$applied['knowledge_id'],'knowledge_revision'=>$revision]]]]);
    workspace_sync_store_v1($pdo,7,'homeserver','chats',['body'=>$body,'revision'=>hash('sha256',$body),'byte_count'=>strlen($body),'record_count'=>1]);
    check(knowledge_r7_get($pdo,$user,$key,$ns,$plan['id'])['sync']['state']==='received','Exact native revision receipt not projected.');
    if($path=getenv('VP3_KNOWLEDGE_R7_EXPORT'))file_put_contents($path,json_encode(['knowledge'=>json_decode(workspace_sync_export_v1($pdo,7,'knowledge')['body'],true),'id'=>$applied['knowledge_id'],'revision'=>$revision,'content'=>$knowledge['content_text']],JSON_THROW_ON_ERROR));
    $pdo->prepare("UPDATE knowledge_items SET content_text=CONCAT(content_text,' Independent edit') WHERE id=?")->execute([$applied['knowledge_id']]);check(knowledge_r7_get($pdo,$user,$key,$ns,$plan['id'])['status']==='superseded','Independent edit falsely confirmed old receipt.');
    $off=knowledge_r6_set($pdo,$user,$key,$ns,['scope'=>['mode'=>'off'],'expected_revision'=>$state['revision']]);
    rejected_r7(fn()=>knowledge_r7_get($pdo,$user,$key,$ns,$plan['id']),403);
    // Date thresholds are deterministic and evidence dates stay distinct from edits.
    $now=strtotime('2026-10-13 UTC');check(knowledge_r7_age('2026-09-13',30,$now)['state']==='review_due','Review threshold boundary wrong.');check(knowledge_r7_age('2026-10-12T00:00:00Z',30,$now)['age_days']===1,'UTC ISO evidence date not parsed.');check(knowledge_r7_age('2026-02-30',30,$now)['state']==='date_unknown','Invalid evidence date normalized.');check(knowledge_r7_age('2026-10-14',30,$now)['state']==='future_date','Future date appeared fresh.');
    echo "PASS: R7 exact scope/source plan, no private query export, atomic prepare/apply rollback, stable retry identity, pinned citation preview, exact canonical Knowledge body, preserved original evidence, received/superseded revision receipts and date boundaries.\n";
};
require __DIR__.'/browser-capture-routing-r4-mysql.php';
