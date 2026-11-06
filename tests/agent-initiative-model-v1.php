<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-initiative-model-v1.php';

function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface):array
{
    $GLOBALS['routes'][]=[(int)$user['id'],$agentId,$surface];
    return $GLOBALS['plan'];
}
function homeserver_execution_v220_registry(int $owner):array
{
    $GLOBALS['probes'][]=$owner;
    return ['available'=>!empty($GLOBALS['safeHome']),'capabilities'=>['unified_execution'=>[
        'profile_safe_local_inference'=>['operation'=>'agent.infer.local','stateless'=>true,
            'local_only'=>true,'caller_supplied_context_only'=>true,'tools_enabled'=>false],
    ]]];
}
function homeserver_execution_v230_execute(int $owner,string $operation,array $payload,?callable $fallback=null,bool $transientContext=false):array
{
    $GLOBALS['homeCalls'][]=[$owner,$operation,$payload,$transientContext];
    if(isset($GLOBALS['homeFailure'])){
        if(isset($GLOBALS['replacePlan']))$GLOBALS['plan']=$GLOBALS['replacePlan'];
        throw new RuntimeException('HomeServer interrupted.');
    }
    return ['result'=>['reply'=>'{"jobs":[]}'],'execution'=>['run_id'=>73,'status'=>'completed']];
}
function ai_generate_chat_response(string $prompt,array $history,array $context,array $user,string $module,array $options):array
{
    $GLOBALS['cloudCalls'][]=[$prompt,$history,$context,$user,$module,$options];
    return $GLOBALS['cloudResult']??['ok'=>true,'answer'=>'{"jobs":[]}'];
}
function check_model(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function rejects_model(callable $work,string $message):void
{
    try{$work();}catch(RuntimeException|InvalidArgumentException $e){check_model(true,$message);return;}
    throw new RuntimeException($message);
}
$pdo=new PDO('sqlite::memory:');$user=['id'=>9];
$cloud=['route'=>'vp3_cloud','effective_preference'=>'vp3_cloud','blocked'=>false,'cloud'=>['ready'=>true]];
$home=['route'=>'homeserver','effective_preference'=>'homeserver_only','blocked'=>false,
    'try_homeserver'=>true,'home'=>['supported'=>true],'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted']];
$GLOBALS['plan']=$cloud;$GLOBALS['safeHome']=true;
$GLOBALS['routes']=$GLOBALS['probes']=$GLOBALS['homeCalls']=$GLOBALS['cloudCalls']=[];
check_model(vp3_initiative_infer_v1($pdo,$user,17,'{"evidence":[]}','vp3.initiative-plan.v1')==='{"jobs":[]}',
    'configured Cloud inference uses the canonical tool-json provider');
check_model($GLOBALS['routes']===[[9,17,'chat']]&&$GLOBALS['probes']===[]&&$GLOBALS['homeCalls']===[],
    'exact named Agent routing never probes HomeServer for an explicit Cloud preference');
check_model($GLOBALS['cloudCalls'][0][5]===['surface'=>'tool_json','protocol'=>'vp3.initiative-plan.v1'],
    'Cloud inference retains canonical protocol and provider accounting boundary');
$GLOBALS['plan']=$home;
check_model(vp3_initiative_inference_health_v1($pdo,$user,17)['ready'],
    'safe HomeServer-only inference remains available with no Cloud token capacity');
vp3_initiative_infer_v1($pdo,$user,17,'{"evidence":[{"title":"Only supplied evidence"}]}','vp3.initiative-draft.v1');
[$owner,$operation,$payload]=$GLOBALS['homeCalls'][0];
check_model($owner===9&&$operation==='agent.infer.local'&&array_keys($payload)===['messages']
    &&count($payload['messages'])===2&&$payload['messages'][0]['role']==='system'
    &&str_contains($payload['messages'][0]['content'],'8000 characters'),
    'HomeServer uses stateless, tool-free inference with only the explicit protocol and evidence');
check_model(count($GLOBALS['cloudCalls'])===1&&$GLOBALS['vp3_initiative_inference_last_v1']['execution']['run_id']===73,
    'local execution preserves its canonical receipt and makes no Cloud call');
$GLOBALS['safeHome']=false;
check_model(!vp3_initiative_inference_health_v1($pdo,$user,17)['ready'],
    'a HomeServer that cannot enforce caller-context-only inference is unavailable');
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1'),
    'HomeServer-only never falls through to Cloud');
$GLOBALS['safeHome']=true;$GLOBALS['homeFailure']=true;
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1'),
    'failed HomeServer-only inference never bypasses the configured transport');
$auto=$home;$auto['effective_preference']='auto';$auto['allow_vp3_fallback']=true;
$auto['homeserver_cloud_allowed']=true;$auto['cloud']=['ready'=>true];$GLOBALS['plan']=$auto;
vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1');
check_model(count($GLOBALS['cloudCalls'])===2&&$GLOBALS['vp3_initiative_inference_last_v1']['route']==='vp3_cloud',
    'Automatic can fall back after stateless local failure only with explicit canonical permission');
$GLOBALS['replacePlan']=$home;$GLOBALS['plan']=$auto;
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1'),
    'route revocation during local inference prevents a later Cloud fallback');
check_model(count($GLOBALS['cloudCalls'])===2,'revoked fallback transmits no prompt to Cloud');
unset($GLOBALS['replacePlan'],$GLOBALS['homeFailure']);
$GLOBALS['plan']=$cloud;$GLOBALS['plan']['blocked']=true;
$GLOBALS['plan']['route']='blocked';$GLOBALS['plan']['cloud']=['ready'=>false,'reason'=>'cloud_balance_exhausted'];
check_model(vp3_initiative_inference_health_v1($pdo,$user,17)['reason']==='quota_exhausted',
    'exhausted selected Cloud capacity is an explicit blocker');
$GLOBALS['plan']=$cloud;$GLOBALS['cloudResult']=['ok'=>false];
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1'),
    'provider failure never becomes a completed preparation');
$before=count($GLOBALS['cloudCalls']);
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','unknown'),
    'unknown protocols cannot select an inference path');
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,str_repeat('x',200001),'vp3.initiative-plan.v1'),
    'oversized evidence cannot trigger unbounded model work');
check_model(count($GLOBALS['cloudCalls'])===$before,'rejected inputs perform no inference');
foreach([
    [['ok'=>false,'status'=>503],true,'503 response'],
    [['ok'=>false,'status'=>0],true,'network timeout'],
    [['ok'=>false,'error_code'=>'inference_budget_exhausted'],true,'exhausted inference deadline'],
    [['ok'=>false,'status'=>401],false,'authentication failure'],
    [['ok'=>false,'status'=>503,'quota_exhausted'=>true],false,'quota refusal'],
    [['ok'=>false],false,'unconfigured provider'],
] as [$response,$retryable,$label]){
    $GLOBALS['cloudResult']=$response;
    try{vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1');throw new LogicException('Provider failure accepted.');}
    catch(RuntimeException $e){check_model(($e instanceof Vp3InitiativeTransientInferenceV1)===$retryable,
        $label.' has the correct server-derived bounded retry classification');}
}
$GLOBALS['cloudResult']=['ok'=>true,'answer'=>'{"jobs":[]}'];
unset($GLOBALS['homeFailure'],$GLOBALS['replacePlan']);
$GLOBALS['homeCalls']=$GLOBALS['cloudCalls']=[];
$GLOBALS['plan']=$home;
vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1',true,static fn()=>null);
check_model(($GLOBALS['homeCalls'][0][3]??null)===true,'actual live inference opts into transient HomeServer transport');
$GLOBALS['homeCalls']=$GLOBALS['cloudCalls']=[];
$deny=static function():void{throw new RuntimeException('Current source changed.',409);};
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1',true,$deny),
    'a stopped live source cannot start HomeServer inference');
check_model($GLOBALS['homeCalls']===[]&&$GLOBALS['cloudCalls']===[],'revoked live evidence performs no model call');
$GLOBALS['plan']=$cloud;
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1',true,$deny),
    'a stopped live source cannot start Cloud inference');
check_model($GLOBALS['cloudCalls']===[],'Cloud source refusal occurs before provider invocation');
$GLOBALS['plan']=array_replace_recursive($home,['effective_preference'=>'auto','allow_vp3_fallback'=>true,
    'homeserver_cloud_allowed'=>true,'cloud'=>['ready'=>true]]);
$GLOBALS['homeFailure']=true;$guardCalls=0;
$guard=static function()use(&$guardCalls):void{if(++$guardCalls>1)throw new RuntimeException('Source stopped during local inference.',409);};
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1',true,$guard),
    'source revocation during local work blocks a permitted later Cloud fallback');
check_model($guardCalls===2&&count($GLOBALS['homeCalls'])===1&&$GLOBALS['cloudCalls']===[],
    'each inference attempt rechecks the actual live source without silently reusing old context');
unset($GLOBALS['homeFailure']);
$GLOBALS['homeCalls']=$GLOBALS['cloudCalls']=[];$GLOBALS['plan']=$cloud;
$changePolicy=static function()use($home):void{$GLOBALS['plan']=$home;};
rejects_model(fn()=>vp3_initiative_infer_v1($pdo,$user,17,'{}','vp3.initiative-plan.v1',true,$changePolicy),
    'a newly strict healthy HomeServer-only policy blocks an earlier Cloud selection');
check_model($GLOBALS['homeCalls']===[]&&$GLOBALS['cloudCalls']===[],
    'transport policy is fresh after source preflight and no unselected provider is substituted');
