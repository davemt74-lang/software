<?php
declare(strict_types=1);

require __DIR__.'/fixtures/cloud-mission-isolated-mysql-v1.php';
if(getenv('VP3_CLOUD_SCHEDULE_DSN')){
    putenv('VP3_IMAGE_TEST_DSN='.getenv('VP3_CLOUD_SCHEDULE_DSN'));
    putenv('VP3_IMAGE_TEST_USER='.getenv('VP3_CLOUD_SCHEDULE_USER'));
    putenv('VP3_IMAGE_TEST_PASSWORD='.getenv('VP3_CLOUD_SCHEDULE_PASSWORD'));
}
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-image-studio-test-'.bin2hex(random_bytes(5)));
require __DIR__.'/fixtures/image-generation-v1.php';
require dirname(__DIR__).'/includes/agent-brain-v82.php';
require dirname(__DIR__).'/includes/chat-image-studio-v1.php';

$checks=0;$inferences=0;$owner=['id'=>1,'is_active'=>1];
function studio_check(bool $value,string $label): void
{
    if(!$value)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo "PASS $label\n";
}
function studio_reject(callable $operation,string $label,?int $status=null): void
{
    try{$operation();}catch(Throwable $error){
        if($status!==null&&(int)$error->getCode()!==$status)throw new RuntimeException($label.' returned '.$error->getCode().': '.$error->getMessage(),0,$error);
        studio_check(true,$label);return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
function studio_queue(int $owner=1,int $agent=7,int $chat=11,string $title='Desert cover',string $prompt='A vintage desert album cover in warm amber tones'): array
{
    $pdo=db();$user=['id'=>$owner,'is_active'=>1];
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,?,'user',?)")
        ->execute([$chat,$owner,'Create '.$title]);
    $turn=(int)$pdo->lastInsertId();
    $generation=vp3_image_queue_v1($pdo,$user,['kind'=>'user_agent','owner_user_id'=>$owner,'agent_id'=>$agent],
        array_replace(image_fixture_plan_v1(),['title'=>$title,'prompt'=>$prompt]),['conversation_id'=>$chat,'chat_user_message_id'=>$turn]);
    agent_job_cancel_v1900($pdo,$user,$generation['job_id']);
    return $generation;
}
function studio_answer(array $sources): string
{
    $refs=array_column($sources,'ref');
    return json_encode(['ideas'=>[
        ['title'=>'Amber coast','prompt'=>'Create a vintage album cover with an amber sun over a quiet coastline and subtle paper grain.',
            'rationale'=>'The warm album-cover palette connects with the specialist report about coastal music.','source_refs'=>array_slice($refs,0,5)],
        ['title'=>'Moonlit paper','prompt'=>'Create a layered paper collage of a moonlit desert stage, muted violet shadows and ivory lettering.',
            'rationale'=>'Develop the saved desert-cover interest as a distinct nighttime visual.','source_refs'=>[$refs[0]]],
    ]],JSON_THROW_ON_ERROR);
}
$report=['ref'=>'report:media:track:41','revision'=>hash('sha256','Coastal music report v1'),
    'kind'=>'specialist_report','specialist'=>'media','title'=>'Coastal music direction',
    'text'=>'The current music report highlights warm surf textures and a reflective coastal mood.'];
$reports=static function()use(&$report):array{return ['sources'=>$report===null?[]:[$report],'coverage'=>['media'=>'ready']];};
$infer=static function(string $prompt,string $protocol,callable $before):string{
    $GLOBALS['inferences']++;$before();$input=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
    studio_check($protocol===VP3_IMAGE_STUDIO_PROTOCOL_V1,'explicit brainstorming uses the text-only structured inference protocol');
    studio_check(str_contains($prompt,'warm surf textures')&&str_contains($prompt,'vintage desert album cover'),
        'the model receives actual saved prompt text and permitted specialist findings');
    return studio_answer($input['sources']);
};

$scope=vp3_agent_activity_scope_v1($pdo,$owner,7,11);
$empty=vp3_image_studio_state_v1($pdo,$scope,null,$reports);
studio_check($empty['generations']===[]&&$empty['brainstorm']===null&&!table_exists('agent_image_brainstorms_v1')&&$inferences===0,
    'opening empty image history is read-only and never starts inference or creates brainstorm storage');
$first=studio_queue();
$pdo->exec('INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(14,1,7)');
$second=studio_queue(1,7,14,'Coastal cover','A vintage desert album cover with a warm coastal color palette');
$otherAgent=studio_queue(1,8,12,'OTHER AGENT SECRET');
$foreign=studio_queue(2,9,13,'FOREIGN OWNER SECRET');
$history=vp3_image_studio_history_v1($pdo,$scope);
studio_check(array_column($history['generations'],'id')===[$second['id'],$first['id']]
    &&array_column($history['generations'],'conversation_id')===[14,11],
    'history spans accessible owned Chats for this Agent and retains each original Chat');
studio_check(!str_contains(json_encode($history),'SECRET'),'other Agent and other owner prompts are excluded');
$pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=14');
studio_check(array_column(vp3_image_studio_history_v1($pdo,$scope)['generations'],'id')===[$first['id']],
    'a historical Chat reassignment immediately removes that Chat from history');
$pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=14');
$pdo->prepare('UPDATE agent_workflow_runs SET agent_id=8 WHERE id=?')->execute([$second['job_id']]);
studio_check(array_column(vp3_image_studio_history_v1($pdo,$scope)['generations'],'id')===[$first['id']],
    'history reauthorizes each canonical job and omits a changed workflow binding');
$pdo->prepare('UPDATE agent_workflow_runs SET agent_id=7 WHERE id=?')->execute([$second['job_id']]);
studio_reject(fn()=>vp3_agent_activity_scope_v1($pdo,$owner,7,12),'foreign Agent Chat cannot become the studio scope',403);
studio_reject(fn()=>vp3_agent_activity_scope_v1($pdo,$owner,9,13),'foreign owned Agent cannot become the studio scope',403);
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>8];
studio_reject(fn()=>vp3_agent_activity_scope_v1($pdo,$owner,7,11),'participant context cannot read private image history',403);
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);

$context=vp3_image_studio_context_v1($pdo,$scope,$reports);
studio_check($context['source_revision']===vp3_image_studio_context_v1($pdo,$scope,$reports)['source_revision'],
    'inspiration revision is stable across repeated reads');
$request=str_repeat('a',32);
$result=vp3_image_studio_brainstorm_v1($pdo,$scope,$request,$context['source_revision'],"Explore\nalbum art",$infer,$reports);
studio_check($result['state']==='ready'&&count($result['ideas'])===2&&count($result['ideas'][0]['sources'])===3,
    'explicit brainstorming persists verified original ideas and exact source provenance');
studio_check(vp3_image_studio_brainstorm_v1($pdo,$scope,$request,$context['source_revision'],'Explore album art',$infer,$reports)===$result
    &&$inferences===1,'replaying the same request returns the saved result without another inference');
studio_reject(fn()=>vp3_image_studio_brainstorm_v1($pdo,$scope,$request,$context['source_revision'],'Different brief',$infer,$reports),
    'a consumed request cannot be replayed with changed instructions',409);
$sameAgentOtherChat=vp3_agent_activity_scope_v1($pdo,$owner,7,14);
$otherContext=vp3_image_studio_context_v1($pdo,$sameAgentOtherChat,$reports);
studio_reject(fn()=>vp3_image_studio_brainstorm_v1($pdo,$sameAgentOtherChat,$request,$otherContext['source_revision'],'Explore album art',$infer,$reports),
    'a saved request cannot be read or replayed through another Chat',403);
$exposed=vp3_image_studio_state_v1($pdo,$scope,null,$reports);
studio_check($exposed['brainstorm']['ideas']===$result['ideas']&&!isset($exposed['sources'][0]['text']),
    'history restores the exact saved ideas and exposes bounded provenance instead of report bodies');
$report['revision']=hash('sha256','Coastal music report v2');$report['text']='The music report now calls for a cool geometric city aesthetic.';
$stale=vp3_image_studio_state_v1($pdo,$scope,null,$reports);
studio_check($stale['source_revision']!==$context['source_revision']&&$stale['brainstorm']['state']==='stale'&&$stale['brainstorm']['ideas']===[],
    'changed report content invalidates previously saved ideas before they are returned');
studio_reject(fn()=>vp3_image_studio_brainstorm_v1($pdo,$scope,str_repeat('b',32),$context['source_revision'],'',$infer,$reports),
    'an outdated inspiration revision is rejected before text inference',409);
studio_check($inferences===1,'stale and mismatched requests did not call the model');

$live=vp3_image_studio_context_v1($pdo,$scope,$reports);
$late=static function(string $prompt,string $protocol,callable $before)use(&$report):string{
    $before();$GLOBALS['inferences']++;$input=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);$report=null;
    return studio_answer($input['sources']);
};
$lateRequest=str_repeat('c',32);
studio_reject(fn()=>vp3_image_studio_brainstorm_v1($pdo,$scope,$lateRequest,$live['source_revision'],'',$late,$reports),
    'source withdrawal while inference is running prevents publication',409);
$lateRow=vp3_image_studio_brainstorm_row_v1($pdo,$scope,$lateRequest);
studio_check($lateRow['state']==='failed'&&$lateRow['result_json']===null,
    'a late revoked report leaves no saved generated ideas');
$noReports=vp3_image_studio_context_v1($pdo,$scope,$reports);
$revoke=static function(string $prompt,string $protocol,callable $before):string{
    $before();$GLOBALS['imageDenied']=true;return studio_answer(json_decode($prompt,true,16,JSON_THROW_ON_ERROR)['sources']);
};
studio_reject(fn()=>vp3_image_studio_brainstorm_v1($pdo,$scope,str_repeat('d',32),$noReports['source_revision'],'',$revoke,$reports),
    'permission loss after inference prevents publication',403);
$GLOBALS['imageDenied']=false;
$sources=$noReports['sources'];$answer=json_decode(studio_answer($sources),true,16,JSON_THROW_ON_ERROR);
foreach(['unknown','duplicate','markup','extra','empty'] as $mode){
    $invalid=$answer;
    if($mode==='unknown')$invalid['ideas'][0]['source_refs']=['report:media:track:999'];
    if($mode==='duplicate')$invalid['ideas'][0]['source_refs']=[$sources[0]['ref'],$sources[0]['ref']];
    if($mode==='markup')$invalid['ideas'][0]['prompt']='<script>draw</script>';
    if($mode==='extra')$invalid['ideas'][0]['operation']='image_generate';
    if($mode==='empty')$invalid['ideas']=[];
    studio_reject(fn()=>vp3_image_studio_result_v1(json_encode($invalid,JSON_THROW_ON_ERROR),$sources),
        'malformed brainstorm '.$mode.' fails closed');
}
$bad=static fn()=>['sources'=>[['ref'=>'report:unknown:1','revision'=>str_repeat('a',64),'kind'=>'specialist_report',
    'specialist'=>'unknown','title'=>'Unverified','text'=>'Unverified']], 'coverage'=>[]];
studio_reject(fn()=>vp3_image_studio_context_v1($pdo,$scope,$bad),'unrecognized specialist source references fail closed',503);

$pendingRequest=str_repeat('e',32);$pendingHash=hash('sha256',json_encode([7,11,$noReports['source_revision'],''],JSON_THROW_ON_ERROR));
$pdo->prepare("INSERT INTO agent_image_brainstorms_v1(request_id,owner_user_id,agent_id,conversation_id,source_revision,request_hash,state,brief,created_at)
    VALUES(?,1,7,11,?,?,'pending','',?)")->execute([$pendingRequest,$noReports['source_revision'],$pendingHash,gmdate('Y-m-d H:i:s')]);
$never=static function():string{throw new RuntimeException('Unexpected inference');};
studio_check(vp3_image_studio_brainstorm_v1($pdo,$scope,$pendingRequest,$noReports['source_revision'],'',$never,$reports)['state']==='pending',
    'an in-flight duplicate request returns pending without another inference');
studio_reject(fn()=>vp3_image_studio_brainstorm_v1($pdo,$scope,str_repeat('f',32),$noReports['source_revision'],'',$never,$reports),
    'a second simultaneous brainstorm in the same Chat is rejected',409);
$pdo->prepare('UPDATE agent_image_brainstorms_v1 SET created_at=? WHERE request_id=?')->execute(['2001-01-01 00:00:00',$pendingRequest]);
studio_check(vp3_image_studio_brainstorm_v1($pdo,$scope,$pendingRequest,$noReports['source_revision'],'',$never,$reports)['state']==='interrupted',
    'an abandoned request is displayed as interrupted and never replayed');
$fresh=static fn(string $prompt):string=>studio_answer(json_decode($prompt,true,16,JSON_THROW_ON_ERROR)['sources']);
$newResult=vp3_image_studio_brainstorm_v1($pdo,$scope,str_repeat('f',32),$noReports['source_revision'],'',$fresh,$reports);
studio_check($newResult['state']==='ready'&&vp3_image_studio_brainstorm_row_v1($pdo,$scope,$pendingRequest)['state']==='interrupted',
    'an explicit new request retires an expired pending request before inference');
studio_check((int)$pdo->query('SELECT COUNT(*) FROM user_media_assets')->fetchColumn()===0
    &&(int)$pdo->query("SELECT COUNT(*) FROM agent_image_generations_v1 WHERE provider_started_at<>''")->fetchColumn()===0
    &&empty($GLOBALS['imageUsage']),'history and brainstorming never start image generation or record fabricated image usage');

for($i=0;$i<21;$i++)studio_queue(1,7,11,'Archive '.$i);
$page1=vp3_image_studio_history_v1($pdo,$scope);$page2=vp3_image_studio_history_v1($pdo,$scope,$page1['next_before']);
studio_check(count($page1['generations'])===20&&count($page2['generations'])===3&&$page2['next_before']===null
    &&!array_intersect(array_column($page1['generations'],'id'),array_column($page2['generations'],'id')),
    'history pagination is bounded and does not duplicate or skip accessible requests');
$realReports=vp3_image_studio_reports_v1($pdo,$scope);
studio_check(is_array($realReports['sources'])&&is_array($realReports['coverage'])&&$inferences===2,
    'canonical specialist report discovery is a read-only path with explicit coverage');
echo 'IMAGE_STUDIO=PASS ('.$checks." assertions)\n";
