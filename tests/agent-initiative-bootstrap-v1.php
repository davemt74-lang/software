<?php
declare(strict_types=1);

// Load the actual application bootstrap in a fresh PHP process. No fixture
// defines the participant boundary or supplies an alternate identity accessor.
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-lifecycle-v1.php';

$checks=0;
function initiative_bootstrap_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;
}

initiative_bootstrap_check(function_exists('vp3_participant_scope_v1'),
    'the actual bootstrap and Initiative entrypoint load the canonical participant boundary');
$boundary=new ReflectionFunction('vp3_participant_scope_v1');
initiative_bootstrap_check(realpath((string)$boundary->getFileName())===realpath(dirname(__DIR__).'/includes/participant-updates-v1.php'),
    'the boundary comes from production participant permissions rather than a fixture substitute');

class InitiativeBootstrapProbePDO extends PDO
{
    public int $queries=0;
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        ++$this->queries;throw new LogicException('owner_context_database_boundary');
    }
}
$pdo=new InitiativeBootstrapProbePDO();
$owner=['id'=>1,'is_active'=>1];
$original=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
$participant=['owner'=>1,'agent'=>0,'participant'=>2,'revision'=>1];
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=$participant;
try{
    $denied=false;
    try{vp3_initiative_runtime_context_v1($pdo,$owner,0,42);}
    catch(RuntimeException $error){$denied=$error->getCode()===403;}
    initiative_bootstrap_check($denied&&$pdo->queries===0,
        'a real participant scope cannot reach private owner runtime, policy or database reads');
    $health=vp3_initiative_worker_health_v1($pdo,$owner,0,42);
    initiative_bootstrap_check($health['enabled']===false&&$health['pump_available']===false&&$pdo->queries===0,
        'real participant health cannot obtain execution authority');
    $control=vp3_initiative_chat_v1($pdo,$owner,['kind'=>'system','agent_id'=>0],['conversation_id'=>42],'on',null);
    initiative_bootstrap_check(($control['ok']??null)===false&&$pdo->queries===0,
        'a real participant cannot use Initiative controls to enable owner private work');
    $adoption=vp3_initiative_adopt_activity_v1($pdo,$owner,['surface'=>'chat','conversation_id'=>42,
        'visible'=>true,'focused'=>true,'chat_focused'=>true,'chat_busy'=>false,'activity_tab'=>str_repeat('a',32),
        'activity_observed_at'=>(int)floor(microtime(true)*1000),'activity_sequence'=>1]);
    initiative_bootstrap_check(!$adoption['accepted']&&!$adoption['changed']&&$pdo->queries===0,
        'a real participant heartbeat cannot adopt an owner Agent or create a policy');
    initiative_bootstrap_check(($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null)===$original,
        'blocked real-bootstrap entrypoints restore the incoming memory namespace');
}finally{unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);}

initiative_bootstrap_check(vp3_participant_scope_v1()===null,
    'leaving the participant scope restores the canonical owner context');
$reachedOwnerBoundary=false;
try{vp3_initiative_runtime_context_v1($pdo,$owner,0,42);}
catch(LogicException $error){$reachedOwnerBoundary=$error->getMessage()==='owner_context_database_boundary';}
initiative_bootstrap_check($reachedOwnerBoundary&&$pdo->queries===1,
    'an ordinary owner reaches the actual live account read instead of an undefined dependency');

echo 'AGENT_INITIATIVE_BOOTSTRAP_V1=PASS ('.$checks.' cases)'.PHP_EOL;
