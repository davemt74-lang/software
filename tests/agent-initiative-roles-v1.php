<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-specialist-registry-v1.php';

$checks=0;
function role_check(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
    ++$GLOBALS['checks'];
    echo 'PASS '.$message.PHP_EOL;
}
$catalog=vp3_initiative_specialist_catalog_v1([]);
role_check($catalog['ob1']['label']==='OB1'&&$catalog['ob1']['state']==='coordinator'
    &&$catalog['brainstorm']['label']==='Brainstorming'&&$catalog['brainstorm']['state']!=='coordinator',
    'OB1 supervision and Brainstorming have distinct identities and responsibilities');
foreach(['initiative.plan','coordination.review','work.prepare_private','chat.check_in'] as $tool){
    role_check(vp3_initiative_tool_specialist_v1($tool)==='ob1',
        'Existing saved '.$tool.' work belongs to OB1 without rewriting canonical jobs');
}
role_check(vp3_initiative_runtime_job_specialist_v1(['capability_key'=>'initiative.plan','goal'=>'{}'])==='ob1',
    'An existing assessment is attributed to OB1 without a schema migration');
foreach(['app_suggestions'=>'app-suggestions','research'=>'research','missions'=>'mission'] as $lane=>$specialist){
    role_check(vp3_initiative_runtime_job_specialist_v1(['capability_key'=>'initiative.plan',
        'goal'=>json_encode(['opportunity_lane'=>$lane],JSON_THROW_ON_ERROR)])===$specialist,
        'The '.$lane.' assessment retains its specialist rather than appearing as OB1 work');
}
role_check(vp3_initiative_runtime_job_specialist_v1(['capability_key'=>'initiative.plan','goal'=>'invalid'])===null,
    'Malformed saved work cannot manufacture a supervisory activity indicator');
role_check(vp3_initiative_goal_specialist_v1(['tool'=>'coordination.review','primary'=>'casual:starter-brainstorm',
    'coordination_route'=>['specialist'=>'casual']])==='brainstorm',
    'A real Brainstorming starter retains its specialist identity');
role_check(vp3_initiative_runtime_job_phase_v1(['progress_message'=>'Assessing priorities across specialists.'])==='coordinating',
    'A model priority assessment reports coordination rather than a creative specialist claim');
echo 'INITIATIVE_ROLES_V1=PASS ('.$checks.' cases)'.PHP_EOL;
