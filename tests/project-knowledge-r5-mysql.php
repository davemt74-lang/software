<?php
declare(strict_types=1);
/** Extends the real R4 InnoDB fixture with canonical R5 edits and source retrieval. */
$captureR5Acceptance=static function(PDO $pdo,array $user,array $session,array $first):void{
    require_once __DIR__.'/../includes/knowledge-retrieval-v162.php';
    require_once __DIR__.'/../includes/project-knowledge-r5.php';
    workspace_sync_schema_v1($pdo);
    $pdo->exec("INSERT INTO artist_transcript_folders_v177(id,created_by_user_id,folder_name) VALUES(3,7,'Purchasing');
      INSERT INTO research_projects_v2060(public_id,owner_user_id,title,description,project_status) VALUES('77777777-7777-4777-8777-777777777777',7,'Purchasing','','active')");
    $all=capture_r5_list($pdo,$user);check($all['count']===2,'R5 lost canonical captures.');
    check(capture_r5_list($pdo,['id'=>8])['count']===0,'Cross-owner captures leaked.');
    check(capture_r5_list($pdo,$user,['q'=>'flour','tag'=>'pricing','folder_id'=>1])['count']===2,'Combined search lost matches.');
    check(capture_r5_list($pdo,$user,['tag'=>'price'])['count']===0,'Tag filter is not exact.');
    check(capture_r5_list($pdo,$user,['before'=>'2000-01-01'])['count']===0,'Date range ignored.');
    denied(fn()=>capture_r5_list($pdo,$user,['folder_id'=>2]));
    denied(fn()=>capture_r5_list($pdo,$user,['after'=>'2026-02-31']));
    $old=capture_r5_list($pdo,$user,['id'=>$first['browser_share_id']])['items'][0];
    $snapshot=function()use($pdo,$user):array{$out=[];foreach(['knowledge','chats'] as $d)$out[$d]=json_decode(workspace_sync_export_v1($pdo,(int)$user['id'],$d)['body'],true,512,JSON_THROW_ON_ERROR);return $out;};
    $before=$snapshot();$history=$pdo->query('SELECT message FROM chat_messages ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $edit=['confirmed'=>true,'id'=>$old['id'],'expected_revision'=>$old['revision'],'note'=>'Compare wholesale saffron costs.','tags'=>['wholesale','costs'],'folder_id'=>3,'project_id'=>'77777777-7777-4777-8777-777777777777'];
    $changed=capture_r5_edit($pdo,$user,$edit)['item'];
    check($changed['revision']!==$old['revision']&&$changed['selection']===$old['selection']&&$changed['source_version_id']===$old['source_version_id'],'Edit rewrote captured evidence or did not revise metadata.');
    check($changed['knowledge_id']===$old['knowledge_id']&&$changed['folder_id']===3&&$changed['project_title']==='Purchasing','Canonical destinations lost.');
    check(capture_r5_edit($pdo,$user,$edit)['idempotent_replay'],'Exact edit retry was not replay safe.');
    denied(fn()=>capture_r5_edit($pdo,$user,array_replace($edit,['note'=>'Different stale edit'])));
    denied(fn()=>capture_r5_edit($pdo,$user,array_replace($edit,['expected_revision'=>$changed['revision'],'folder_id'=>2])));
    check($pdo->query('SELECT message FROM chat_messages ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)===$history,'Organization edit rewrote Chat history.');
    check((int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn()===2,'Edit duplicated Knowledge.');
    check((int)$pdo->query('SELECT COUNT(*) FROM browser_shares_v2010')->fetchColumn()===2,'Edit duplicated source captures.');
    $q=$pdo->prepare("SELECT item_status FROM research_project_items_v2060 WHERE browser_share_id=? AND project_id=(SELECT id FROM research_projects_v2060 WHERE public_id=?)");$q->execute([$pdo->query('SELECT browser_share_id FROM browser_capture_deliveries_r4 ORDER BY id LIMIT 1')->fetchColumn(),$old['project_id']]);check($q->fetchColumn()==='removed','Old project membership remained active.');
    $originalContent=$pdo->query('SELECT content_text FROM knowledge_items WHERE id='.(int)$changed['knowledge_id'])->fetchColumn();
    $pdo->prepare('UPDATE knowledge_items SET content_text=? WHERE id=?')->execute(['Separate Knowledge edit',$changed['knowledge_id']]);
    denied(fn()=>capture_r5_edit($pdo,$user,array_replace($edit,['expected_revision'=>$changed['revision'],'note'=>'Clobber separate content'])));
    $pdo->prepare('UPDATE knowledge_items SET content_text=? WHERE id=?')->execute([$originalContent,$changed['knowledge_id']]);
    $retrieved=capture_r5_retrieve($pdo,$user,'wholesale saffron',['mode'=>'folder','folder_id'=>3]);
    check(count($retrieved)===1&&$retrieved[0]['capture_id']===$old['id']&&str_contains($retrieved[0]['excerpt'],'Flour costs'),'Retrieved annotation lost quote, current note or destination.');
    $context=knowledge_retrieval_v162_context_item($retrieved);$citations=knowledge_retrieval_v162_citations($retrieved);
    check(str_contains($context['text'],'UNTRUSTED EVIDENCE')&&str_contains($context['text'],$changed['source_url'])&&$citations[0]['source_url']===$changed['source_url']&&$citations[0]['captured_at']===$changed['captured_at'],'Original source citation or trust boundary missing.');
    check(capture_r5_home_sources($pdo,$user,['annotation_citations'=>[['id'=>$changed['id'],'label'=>'A1','record_revision'=>$changed['revision'],'source_url'=>$changed['source_url']]]])[0]['url']===$changed['source_url'],'Delegated source citation failed revalidation.');
    check(capture_r5_home_sources($pdo,$user,['annotation_citations'=>[['id'=>$changed['id'],'label'=>'A1','record_revision'=>str_repeat('0',64),'source_url'=>$changed['source_url']]]])===[],'Stale delegated source was trusted.');
    check(capture_r5_sources($citations)[0]['url']===$changed['source_url'],'Chat response did not expose original citation link.');
    check(capture_r5_retrieve($pdo,$user,'saffron',['mode'=>'off','folder_id'=>0])===[],'Knowledge opt-out ignored.');
    check(capture_r5_list($pdo,$user,['tag'=>'wholesale','project_id'=>$changed['project_id'],'folder_id'=>3])['count']===1,'Edited filters are stale.');
    $attempt=array_replace($edit,['expected_revision'=>$changed['revision'],'note'=>'Rollback sentinel']);
    $pdo->exec("CREATE TRIGGER fail_r5_knowledge BEFORE UPDATE ON knowledge_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled Knowledge failure'");
    denied(fn()=>capture_r5_edit($pdo,$user,$attempt));$pdo->exec('DROP TRIGGER fail_r5_knowledge');
    check(capture_r5_list($pdo,$user,['id'=>$old['id']])['items'][0]['revision']===$changed['revision'],'Failed destination partially saved metadata.');
    denied(fn()=>capture_r5_edit($pdo,$user,$attempt,static function():void{throw new RuntimeException('Grant revoked before commit.',403);}));
    check(capture_r5_list($pdo,$user,['id'=>$old['id']])['items'][0]['revision']===$changed['revision']&&!$pdo->inTransaction(),'Final authority failure partially saved metadata.');
    $GLOBALS['grant']=false;denied(fn()=>capture_r5_list($pdo,$user));$GLOBALS['grant']=true;
    if($export=(string)getenv('VP3_CAPTURE_R5_EXPORT'))file_put_contents($export,json_encode(['before'=>$before,'after'=>$snapshot(),'item'=>$changed],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if($receiptFile=(string)getenv('VP3_CAPTURE_R5_RECEIPTS')){
        // The optional paired test produces an actual native outbound snapshot from this Cloud export.
        $command=(string)getenv('VP3_CAPTURE_R5_NATIVE_COMMAND');if($command==='')throw new RuntimeException('Paired receipt command required.');
        passthru($command,$code);check($code===0,'Native paired acceptance failed.');
        $raw=(string)file_get_contents($receiptFile);$ack=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        workspace_sync_store_v1($pdo,7,'homeserver','chats',['body'=>$raw,'revision'=>hash('sha256',$raw),'byte_count'=>strlen($raw),'record_count'=>count($ack['records'])]);
        $confirmed=capture_r5_list($pdo,$user,['id'=>$old['id']])['items'][0];check($confirmed['sync']['state']==='received'&&!empty($confirmed['sync']['confirmed_at']),'Cloud did not confirm exact native receipt.');
        capture_r5_edit($pdo,$user,array_replace($attempt,['note'=>'Newer revision after receipt']));
        check(capture_r5_list($pdo,$user,['id'=>$old['id']])['items'][0]['sync']['state']==='pending','Stale receipt confirmed newer metadata.');
    }
    echo "PASS: R5 real database filters, owner isolation, canonical revision edits, exact retries/conflicts, unchanged captured source/history, current Knowledge indexing, source citations, atomic rollback and receipt validation.\n";
};
require __DIR__.'/browser-capture-routing-r4-mysql.php';
