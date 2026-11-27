<?php
declare(strict_types=1);

define('VP3_BRAINSTORM_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-initiative-brainstorm-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-creative-feedback-v1.php';
$start=$checks;
foreach(canvas_pipeline_runs('initiative.prepare') as $prior){
    if((json_decode($prior['goal'],true)['tool']??null)==='brainstorm.explore'){
        $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled' WHERE id=?")->execute([$prior['id']]);
    }
}
vp3_initiative_in_scope_v1($owner,7,static function()use($owner):void{
    $binding=vp3_initiative_policy_v1($owner,7);$lanes=[];
    foreach(VP3_INITIATIVE_OPPORTUNITY_LANES_V1 as $lane)$lanes[$lane]=['checked_at'=>time(),'state'=>'no_opportunity'];
    vp3_initiative_opportunity_save_v1($owner,$binding,$lanes);
});
$proposal=canvas_pipeline_prepare(24001);
$message=(int)canvas_pipeline_result($proposal)['initiative_message_id'];
canvas_pipeline_reply($message,'defer');
$noise=$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message,created_at) VALUES(11,'assistant','Unrelated later status.',CURRENT_TIMESTAMP)");
for($i=0;$i<70;$i++)$noise->execute();
$binding=vp3_initiative_policy_v1($owner,7);$evidence=brainstorm_pipeline_evidence();
$feedback=vp3_creative_feedback_v1($pdo,$owner,$binding,$evidence);
$decision=array_values(array_filter($feedback,static fn(array $row):bool=>$row['primary']==='task:24001'&&$row['tool']==='canvas.prepare'));
canvas_pipeline_check(count($decision)===1&&$decision[0]['owner_decision']==='defer',
    'A real source-bound owner deferral remains available after seventy unrelated newer messages');
canvas_pipeline_check(!str_contains(json_encode($feedback),'Put this aside')&&!str_contains(json_encode($feedback),'reply_message_id'),
    'Creative feedback carries typed decisions rather than copied owner conversation text');
$beforeRuns=count(canvas_pipeline_runs('initiative.prepare'));$beforeMessages=canvas_pipeline_count('chat_messages');
if(!$mysql)$pdo->exec('PRAGMA query_only=ON');
try{$readOnly=vp3_creative_feedback_v1($pdo,$owner,$binding,$evidence);}
finally{if(!$mysql)$pdo->exec('PRAGMA query_only=OFF');}
canvas_pipeline_check($readOnly===$feedback&&count(canvas_pipeline_runs('initiative.prepare'))===$beforeRuns
    &&canvas_pipeline_count('chat_messages')===$beforeMessages,'Reading feedback performs no inference, write, delivery or task admission');
$snapshot=vp3_brainstorm_snapshot_v1($pdo,$owner,$binding);
canvas_pipeline_check((bool)array_filter($snapshot['feedback'],static fn(array $row):bool=>$row['primary']==='task:24001'&&$row['owner_decision']==='defer')
    &&vp3_brainstorm_schedule_v1($pdo,$owner,$binding)==='queued',
    'An exact new owner decision participates in Brainstorming context identity');
$runs=canvas_pipeline_runs('initiative.prepare');$creative=(int)end($runs)['id'];
$creativeDone=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($creative),static function(string $prompt):string{
    $input=json_decode($prompt,true);
    canvas_pipeline_check(!empty($input['creative_feedback'])&&str_contains($input['quality_guidance'],'prepared continuation'),
        'The creative model sees verified feedback and the distinction between preparation and objective completion');
    return json_encode(['jobs'=>[],'opportunities'=>[
        ['tool'=>'canvas.prepare','primary'=>'task:24001','refs'=>['task:24001'],'objective'=>'Repeat the deferred Canvas','rationale'=>'This model deliberately ignores the saved deferral.'],
        ['tool'=>'work.prepare_private','primary'=>'task:24001','refs'=>['task:24001'],'objective'=>'Consider a different private approach','rationale'=>'A different preparation can honor the saved decision.']]]);
},null,false);
$ideas=canvas_pipeline_result($creative)['initiative_tool_result']['output']['ideas']??[];
canvas_pipeline_check($creativeDone['ok']&&array_column($ideas,'tool')===['work.prepare_private'],
    'Ignoring an exact deferral cannot return the same Canvas while a different supported idea remains usable');
$payload=['binding'=>$binding,'sources'=>array_column($snapshot['evidence'],'revision','ref'),
    'canonical_sources'=>array_column($snapshot['evidence'],'revision','ref')];
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.plan',$payload,hash('sha256','creative-feedback-supervisor'));
$plans=canvas_pipeline_runs('initiative.plan');$plan=(int)end($plans)['id'];
$beforeRuns=count(canvas_pipeline_runs('initiative.prepare'));
$reviewed=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($plan),static function(string $prompt):string{
    $input=json_decode($prompt,true);
    canvas_pipeline_check(!empty($input['creative_feedback']),'OB1 receives the same source-bound creative decision history');
    return json_encode(['jobs'=>[
        ['tool'=>'canvas.prepare','primary'=>'task:24001','refs'=>['task:24001'],'focus'=>'Try the deferred app anyway','report'=>true],
        ['tool'=>'work.prepare_private','primary'=>'task:24001','refs'=>['task:24001'],'focus'=>'Evaluate another private way to prepare','report'=>false]]]);
},null,false);
$new=array_slice(canvas_pipeline_runs('initiative.prepare'),$beforeRuns);
canvas_pipeline_check($reviewed['ok']&&count($new)===1&&(json_decode($new[0]['goal'],true)['tool']??null)==='work.prepare_private',
    'OB1 cannot assign the deferred creation merely because its model ignored the feedback');
canvas_pipeline_reply($message,'advance');
$feedback=vp3_creative_feedback_v1($pdo,$owner,$binding,$evidence);
$decision=array_values(array_filter($feedback,static fn(array $row):bool=>$row['primary']==='task:24001'&&$row['tool']==='canvas.prepare'));
canvas_pipeline_check(count($decision)===1&&$decision[0]['owner_decision']==='advance'
    &&vp3_creative_feedback_allows_v1(['tool'=>'canvas.prepare','primary'=>'task:24001'],$feedback,$evidence),
    'A verified later owner request supersedes the deferral without granting blanket creation permission');
$q=$pdo->prepare("SELECT message FROM chat_messages WHERE id=(SELECT MAX(id) FROM chat_messages WHERE role='user' AND conversation_id=11)");
$q->execute();$text=$q->fetchColumn();
$reply=(int)$pdo->query("SELECT MAX(id) FROM chat_messages WHERE role='user' AND conversation_id=11")->fetchColumn();
$pdo->prepare('UPDATE chat_messages SET message=? WHERE id=?')->execute(['A changed reply cannot verify the old decision.',$reply]);
canvas_pipeline_check(!array_filter(vp3_creative_feedback_v1($pdo,$owner,$binding,$evidence),
    static fn(array $row):bool=>$row['primary']==='task:24001'&&$row['tool']==='canvas.prepare'),
    'An edited owner message cannot authenticate the former creative decision');
$pdo->prepare('UPDATE chat_messages SET message=? WHERE id=?')->execute([$text,$reply]);
$changed=$evidence;$changed['task:24001']['revision']=str_repeat('b',64);
canvas_pipeline_check(vp3_creative_feedback_allows_v1(['tool'=>'canvas.prepare','primary'=>'task:24001'],
    [$readOnly[0]],$changed),'A historical source decision cannot suppress work on a different current source revision');
echo 'CREATIVE_FEEDBACK=PASS ('.($checks-$start).' cases, '.($mysql?'mysql':'sqlite').')'.PHP_EOL;
