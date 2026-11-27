<?php
declare(strict_types=1);

ob_start();require __DIR__.'/coding-canvas-store-v1.php';ob_end_clean();
require_once __DIR__.'/../includes/coding-canvas-source-v1.php';
$sourceAssertions=0;
function source_check(bool $ok,string $message):void {global $sourceAssertions;$sourceAssertions++;if(!$ok)throw new RuntimeException($message);}
function source_reject(int $code,callable $work,string $message):void {
    try{$work();}catch(Throwable $error){source_check($error->getCode()===$code,$message.': '.$error->getMessage());return;}
    throw new RuntimeException($message.' was accepted');
}
$editorUser=['id'=>1,'is_active'=>1];$editorOther=['id'=>2,'is_active'=>1];
$pdo->exec('INSERT INTO chat_conversations VALUES (14,1,11),(15,1,11)');
$editorId='6e000000-0000-4000-8000-000000000001';
$code=['html'=>'<h1>Workshop 🎨</h1><p id="count"></p>','css'=>'h1{color:green}','js'=>'const label="🎨"; CanvasUI.text("#count",String(CanvasData.get().count));'];
$base=vp3_coding_canvas_save_draft_v1($pdo,$editorUser,11,14,$editorId,'Source editor',$code,['count'=>4],commands:['music']);
source_check(preg_match('/^[a-f0-9]{64}$/',$base['revision']['source_hash'])===1,'Saved versions expose a source proof');

// Reproduce a pre-editor installation without mutating code or app records.
$pdo->exec($dsn?'DROP INDEX vp3_canvas_edit_request_v1 ON vp3_coding_canvas_versions_v1':'DROP INDEX vp3_canvas_edit_request_v1');
foreach(['edit_base_revision','edit_origin_revision','edit_request_id','edit_request_hash'] as $column)$pdo->exec('ALTER TABLE vp3_coding_canvas_versions_v1 DROP COLUMN '.$column);
schema_metadata_state($pdo)->columns['vp3_coding_canvas_versions_v1']=[];
source_check(!vp3_coding_canvas_source_ready_v1($pdo),'Older installs can report the editor upgrade is required');
source_check(vp3_coding_canvas_read_v1($pdo,$editorUser,11,$editorId)['revision']['artifact']===$code,'Older installs can still open existing apps');
$changed=$code;$changed['html'].='<button id="next">Next</button>';
$request='6e000001-0000-4000-8000-000000000002';
source_reject(503,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],$request,$changed,1,null),'Live save never performs a migration');
vp3_coding_canvas_schema_v1($pdo);vp3_coding_canvas_schema_v1($pdo);
source_check(vp3_coding_canvas_source_ready_v1($pdo),'Explicit upgrade adds the source metadata and unique request index idempotently');
source_check(vp3_coding_canvas_read_v1($pdo,$editorUser,11,$editorId)['state']==$base['state'],'Upgrade preserves saved app records');
vp3_coding_canvas_mark_tested_v1($pdo,$editorUser,11,$editorId,1,$base['state']['revision']);
$working=vp3_coding_canvas_activate_v1($pdo,$editorUser,11,$editorId,1,null);
$data=vp3_coding_canvas_save_state_v1($pdo,$editorUser,11,$editorId,1,$working['state']['revision'],['count'=>7]);

$manual=vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],$request,$changed,null,1);
source_check($manual['source_saved']&&$manual['revision']['id']===2&&$manual['revision']['status']==='draft','Manual code becomes a separate immutable draft');
source_check(!$manual['revision']['test_passed']&&!$manual['revision']['can_rollback'],'Manual source never inherits a tested or previously activated marker');
source_check($manual['canvas']['active_revision']===1&&$manual['state']==$data['state'],'Manual save preserves active code and current records');
source_check($manual['revision']['commands']===['music'],'Manual source preserves the exact original data commands');
source_check(vp3_coding_canvas_read_v1($pdo,$editorUser,11,$editorId,1)['revision']['artifact']===$code,'Saving source never edits the original code item');
$meta=$pdo->query("SELECT * FROM vp3_coding_canvas_versions_v1 WHERE canvas_id='$editorId' AND revision=2")->fetch(PDO::FETCH_ASSOC);
source_check($meta['run_id']===null&&(int)$meta['edit_base_revision']===1&&(int)$meta['edit_origin_revision']===1,'Manual provenance is native version metadata rather than a model execution');
source_check($meta['edit_request_id']===$request&&strlen($meta['edit_request_hash'])===64,'Native manual requests retain exact replay identity');
source_check(vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],$request,$changed,null,1)['revision']['id']===2,'A lost save response recovers the exact saved draft');
source_reject(409,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],$request,$code,null,1),'One save id cannot accept a different artifact');
source_reject(409,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],'6e000002-0000-4000-8000-000000000003',$changed,null,1),'A stale editor cannot overwrite a newer draft');
source_reject(409,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,str_repeat('b',64),'6e000003-0000-4000-8000-000000000004',$changed,2,1),'An incorrect immutable source proof is rejected');
source_reject(422,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],'bad',$changed,2,1),'Invalid request ids are rejected');
source_reject(422,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],'6e000003-0000-4000-8000-000000000004',['html'=>'<script>bad()</script>','css'=>'','js'=>''],2,1),'Manual HTML retains the native preview contract');
source_reject(422,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],'6e000003-0000-4000-8000-000000000004',$changed+['commands'=>['gmail']],2,1),'Artifacts cannot inject data commands');
source_reject(403,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser+['manage'=>false],11,14,$editorId,1,$base['revision']['source_hash'],$request,$changed,2,1),'Read-only accounts cannot edit source');
source_reject(404,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorOther,21,21,$editorId,1,$base['revision']['source_hash'],$request,$changed,2,1),'Another owner cannot write the Canvas');
source_reject(404,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,12,12,$editorId,1,$base['revision']['source_hash'],$request,$changed,2,1),'Another Agent cannot write the Canvas');
source_reject(404,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,21,$editorId,1,$base['revision']['source_hash'],$request,$changed,2,1),'A foreign Chat cannot submit source edits');
$GLOBALS['participant']=['kind'=>'visitor'];
source_reject(403,fn()=>vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],$request,$changed,2,1),'Participant context cannot submit source edits');unset($GLOBALS['participant']);
source_reject(409,fn()=>vp3_coding_canvas_activate_v1($pdo,$editorUser,11,$editorId,2,1),'A manual draft still needs an explicit current-state test');

$next=$changed;$next['css']='h1{color:purple}';
$third=vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,15,$editorId,2,$manual['revision']['source_hash'],'6e000004-0000-4000-8000-000000000005',$next,2,1);
source_check($third['revision']['id']===3&&$third['canvas']['conversation_id']===14,'Another owned Chat keeps the original Canvas origin');
$meta=$pdo->query("SELECT edit_base_revision,edit_origin_revision FROM vp3_coding_canvas_versions_v1 WHERE canvas_id='$editorId' AND revision=3")->fetch(PDO::FETCH_ASSOC);
source_check((int)$meta['edit_base_revision']===2&&(int)$meta['edit_origin_revision']===1,'Manual source ancestry is flattened while the exact base remains recorded');
$replay=vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,1,$base['revision']['source_hash'],$request,$changed,null,1);
source_check($replay['revision']['id']===2&&$replay['canvas']['draft_revision']===3&&count($replay['revisions'])===3,'Exact replay after another save returns its original draft and cannot replace newer work');
$noop=vp3_coding_canvas_source_save_v1($pdo,$editorUser,11,14,$editorId,3,$third['revision']['source_hash'],'6e000005-0000-4000-8000-000000000006',$next,3,1);
source_check(!$noop['source_saved']&&count($noop['revisions'])===3,'Unchanged source does not create empty revisions');
vp3_coding_canvas_mark_tested_v1($pdo,$editorUser,11,$editorId,3,$third['state']['revision']);
$active=vp3_coding_canvas_activate_v1($pdo,$editorUser,11,$editorId,3,1);
$restored=vp3_coding_canvas_activate_v1($pdo,$editorUser,11,$editorId,1,3);
source_check($restored['canvas']['active_revision']===1&&$restored['state']['value']->count===7,'Use and Restore preserve current records after manual edits');

$start=strpos($code['js'],'🎨');$context=['language'=>'js','start_byte'=>$start,'end_byte'=>$start+strlen('🎨'),'source_hash'=>$base['revision']['source_hash']];
source_check(vp3_coding_canvas_edit_context_v1($context,$base['revision'])===$context,'Exact UTF-8 selections bind to the saved source');
source_reject(409,fn()=>vp3_coding_canvas_edit_context_v1(array_replace($context,['start_byte'=>$start+1]),$base['revision']),'A selection cannot start inside a Unicode character');
source_reject(409,fn()=>vp3_coding_canvas_edit_context_v1(array_replace($context,['end_byte'=>$start+3]),$base['revision']),'A selection cannot end inside a Unicode character');
source_reject(409,fn()=>vp3_coding_canvas_edit_context_v1(array_replace($context,['source_hash'=>str_repeat('b',64)]),$base['revision']),'Selections cannot claim a different source proof');
source_reject(422,fn()=>vp3_coding_canvas_edit_context_v1($context+['selected_source'=>'untrusted']), 'Selection text is obtained from native source, never from a supplied excerpt');
source_reject(422,fn()=>vp3_coding_canvas_edit_context_v1(array_replace($context,['start_byte'=>0,'end_byte'=>8001])),'Selection sizes are bounded');
source_reject(422,fn()=>vp3_coding_canvas_edit_context_v1(array_replace($context,['language'=>'php'])),'Selections stay within the Canvas source languages');
source_check(vp3_coding_canvas_edit_context_v1(null)===null,'Existing requests can omit selected code');
echo 'CODING_CANVAS_SOURCE=PASS ('.$sourceAssertions.' assertions, '.($dsn?'mysql':'sqlite').")\n";
