<?php
declare(strict_types=1);

$includes=dirname(__DIR__).'/includes/';
foreach(['bootstrap','agent-job-worker-v1900','agent-worker-cloud-v1910','cloud-mission-schedules-v1','agent-initiative-v1'] as $module)require_once $includes.$module.'.php';
$checks=0;
function brainstorm_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function brainstorm_reject(callable $operation,string $label): void
{
    try{$operation();}catch(InvalidArgumentException|JsonException){brainstorm_check(true,$label);return;}
    throw new RuntimeException('Expected rejection: '.$label);
}
$evidence=[];$opportunities=[];
for($i=1;$i<=8;$i++){
    $ref='task:'.$i;
    $evidence[$ref]=['ref'=>$ref,'revision'=>hash('sha256','task '.$i),'title'=>'Private task '.$i];
    $opportunities[]=['tool'=>'work.prepare_private','primary'=>$ref,'refs'=>[$ref],
        'objective'=>'Prepare useful options for task '.$i,'rationale'=>'Its saved next step could benefit from a focused review.'];
}
$tools=['work.prepare_private'];
$encode=static fn(array $value):string=>json_encode($value,JSON_THROW_ON_ERROR);
$parse=static fn(array $value):array=>vp3_initiative_assessment_v1($encode($value),$evidence,$tools);
$job=['tool'=>'work.prepare_private','primary'=>'task:1','refs'=>['task:1'],
    'focus'=>'Prepare useful options for task 1','report'=>false];
brainstorm_check(vp3_initiative_plan_v1($encode(['jobs'=>[$job]]),$evidence)===[ $job ],'legacy planner returns its exact original jobs array');
brainstorm_check($parse(['jobs'=>[]])===['jobs'=>[],'opportunities'=>[]],'legacy idle assessment has no invented ideas');
$assessment=$parse(['jobs'=>[$job],'opportunities'=>$opportunities]);
foreach(['json',''] as $language){
    $fenced="```".$language."\n".$encode(['jobs'=>[$job],'opportunities'=>$opportunities])."\n```";
    brainstorm_check(vp3_initiative_assessment_v1($fenced,$evidence,$tools)===$assessment,
        'a whole-response JSON formatting fence preserves the exact verified assessment: '.$language);
}
foreach([
    ['jobs'=>[array_replace($job,['refs'=>['task:99']])]],
    ['jobs'=>[],'instructions'=>'send email'],
    ['jobs'=>[array_replace($job,['tool'=>'email.send'])]],
    ['jobs'=>[],'opportunities'=>[array_replace($opportunities[0],['permission'=>true])]],
] as $invalid){
    brainstorm_reject(static fn()=>vp3_initiative_assessment_v1("```json\n".$encode($invalid)."\n```",$evidence,$tools),
        'formatting normalization never bypasses source, tool, field or authority validation');
}
foreach(["Here is the plan: {\"jobs\":[]}","```json\n{\"jobs\":[]}\n```\nApproved.","```json\n{\"jobs\":[]}\n{\"jobs\":[]}\n```"] as $invalid){
    brainstorm_reject(static fn()=>vp3_initiative_assessment_v1($invalid,$evidence,$tools),
        'prose or multiple objects cannot be extracted as a verified assessment');
}
brainstorm_check(count($assessment['opportunities'])===8&&count($assessment['jobs'])===1,'eight useful ideas are independent of the smaller execution budget');
brainstorm_check(vp3_initiative_plan_v1($encode(['jobs'=>[$job],'opportunities'=>$opportunities]),$evidence)===[ $job ],'the jobs compatibility wrapper accepts the extended assessment without changing dispatched work');
foreach([null,'[]',(object)[],array_fill(0,9,$opportunities[0])] as $bad){
    brainstorm_reject(static fn()=>$parse(['jobs'=>[],'opportunities'=>$bad]),'opportunity list is typed and bounded: '.get_debug_type($bad));
}
foreach(['tool','primary','refs','objective','rationale'] as $field){
    $bad=$opportunities[0];unset($bad[$field]);
    brainstorm_reject(static fn()=>$parse(['jobs'=>[],'opportunities'=>[$bad]]),'every opportunity requires '.$field);
}
foreach([
    ['tool'=>'email.send'],['primary'=>'task:99'],['refs'=>['task:1','task:99']],['refs'=>['task:1','task:1']],
    ['refs'=>[]],['refs'=>(object)['task:1']],['refs'=>range(1,6)],['objective'=>null],['rationale'=>null],
    ['objective'=>str_repeat('x',301)],['rationale'=>str_repeat('x',501)],['objective'=>'   '],
    ['rationale'=>'Visit https://example.test'],['objective'=>'<b>Build now</b>'],['rationale'=>"bad\0text"],
    ['status'=>'queued'],['id'=>str_repeat('a',64)],['permission'=>true],
] as $patch){
    $bad=array_replace($opportunities[0],$patch);
    brainstorm_reject(static fn()=>$parse(['jobs'=>[],'opportunities'=>[$bad]]),'reject malformed or model-authorized opportunity '.implode(',',array_keys($patch)));
}
brainstorm_reject(static fn()=>$parse(['jobs'=>[],'opportunities'=>[$opportunities[0],$opportunities[0]]]),'duplicate tool and primary cannot inflate the opportunity list');
$catalog=['work.prepare_private'=>['ready'=>true,'source_refs'=>['task:2']]];
brainstorm_reject(static fn()=>vp3_initiative_assessment_v1($encode(['jobs'=>[],'opportunities'=>[$opportunities[0]]]),$evidence,$tools,$catalog),'an opportunity obeys the selected tool source allowlist');
brainstorm_reject(static fn()=>vp3_initiative_assessment_v1($encode(['jobs'=>[$job]]),$evidence,$tools,$catalog),'dispatch and opportunities share the same catalog source restriction');
$weak='youtube-interest-checkin:topic:'.str_repeat('b',64);
$weakEvidence=[$weak=>['ref'=>$weak,'kind'=>'youtube_interest_checkin','revision'=>hash('sha256','tentative')]];
$weakOpportunity=['tool'=>'chat.check_in','primary'=>$weak,'refs'=>[$weak],'objective'=>'Ask whether this topic is useful.','rationale'=>'A single viewing pattern leaves its purpose uncertain.'];
brainstorm_check(count(vp3_initiative_assessment_v1($encode(['jobs'=>[],'opportunities'=>[$weakOpportunity]]),$weakEvidence,['chat.check_in'])['opportunities'])===1,'tentative viewing can ground its own conversational idea');
brainstorm_reject(static fn()=>vp3_initiative_assessment_v1($encode(['jobs'=>[],'opportunities'=>[array_replace($weakOpportunity,['tool'=>'work.prepare_private'])]]),$weakEvidence),'tentative viewing cannot become research or build authority');
$revisions=['task:1'=>$evidence['task:1']['revision']];
$id=vp3_initiative_brainstorm_identity_v1('work.prepare_private','task:1',$revisions);
$result=vp3_initiative_brainstorm_result_v1($assessment['opportunities'],$evidence,[$id=>'queued']);
brainstorm_check($result['output']['opportunities'][0]['status']==='queued'&&$result['output']['opportunities'][1]['status']==='considered','only exact actually admitted jobs acquire queued status');
$existing=vp3_initiative_brainstorm_result_v1([$assessment['opportunities'][0]],$evidence,[$id=>'existing']);
brainstorm_check($existing['output']['opportunities'][0]['status']==='existing','a duplicate canonical assignment is recorded as existing');
brainstorm_check(vp3_initiative_brainstorm_identity_v1('work.prepare_private','task:1',['task:1'=>$evidence['task:1']['revision'],'task:2'=>$evidence['task:2']['revision']])
    ===vp3_initiative_brainstorm_identity_v1('work.prepare_private','task:1',['task:2'=>$evidence['task:2']['revision'],'task:1'=>$evidence['task:1']['revision']]),'reference order cannot manufacture novelty');
brainstorm_check(vp3_initiative_brainstorm_identity_v1('work.prepare_private','task:1',['task:1'=>hash('sha256','changed')])!==$id,'materially changed source revisions get a new opportunity identity');
brainstorm_check(vp3_initiative_brainstorm_tool_result_v1($result)===$result,'the typed private normalizer preserves the exact bounded assessment');
$bad=$result;$bad['output']['opportunities'][0]['source_revisions']['task:1']=hash('sha256','forged');
brainstorm_check(vp3_initiative_brainstorm_tool_result_v1($bad)===null,'a stored fingerprint cannot be reused with substituted evidence');
$bad=$result;$bad['output']['opportunities'][0]['status']='completed';
brainstorm_check(vp3_initiative_brainstorm_tool_result_v1($bad)===null,'a queued idea cannot claim completed execution');
$bad=$result;$bad['output']['opportunities'][0]['rationale']=null;
brainstorm_check(vp3_initiative_brainstorm_tool_result_v1($bad)===null,'private storage rejects a missing owner-facing explanation');
brainstorm_check(agent_workflow_public_json_v1400(json_encode(['initiative_tool_result'=>$result],JSON_THROW_ON_ERROR))===[], 'ordinary workflow projection excludes private opportunity explanations');
brainstorm_check(agent_workflow_public_json_v1400(json_encode(['initiative_tool_result'=>$result],JSON_THROW_ON_ERROR),true)['initiative_tool_result']===$result,'the canonical private result projection retains the typed assessment');
$before=new DateTimeImmutable('2026-11-10T12:04:59+00:00');$after=new DateTimeImmutable('2026-11-10T12:05:00+00:00');
brainstorm_check(vp3_initiative_brainstorm_slot_v1($after)===vp3_initiative_brainstorm_slot_v1($before)+1,'stable-data reassessment identity advances once per five-minute slot');
brainstorm_check(vp3_initiative_brainstorm_slot_v1($before)===vp3_initiative_brainstorm_slot_v1($before->setTimezone(new DateTimeZone('America/New_York'))),'reassessment slots use absolute time across browser timezones');
$semantic=['binding'=>['revision'=>'r','conversation_id'=>42],'sources'=>['task:2'=>'b','task:1'=>'a'],'reassessment_slot'=>123];
brainstorm_check(vp3_initiative_brainstorm_semantic_v1($semantic)===vp3_initiative_brainstorm_semantic_v1([
    'reassessment_slot'=>456,'sources'=>['task:1'=>'a','task:2'=>'b'],'binding'=>['conversation_id'=>42,'revision'=>'r']]),
    'an already pending assessment survives slot and object-order changes');
brainstorm_check(vp3_initiative_brainstorm_semantic_v1($semantic)!==vp3_initiative_brainstorm_semantic_v1($semantic+['engagement_revision'=>'new decision']),
    'a real new owner decision is not swallowed by pending-plan suppression');
brainstorm_check(str_contains(vp3_tool_json_contract_v1('vp3.initiative-plan.v1'),'owner-facing explanation')&&str_contains(vp3_tool_json_contract_v1('vp3.initiative-plan.v1'),'budget.max_opportunities'),'the actual governed protocol asks for useful evidence-grounded ideas separately from dispatch');
echo 'AGENT_INITIATIVE_BRAINSTORM_V1=PASS ('.$checks.' cases)'.PHP_EOL;
