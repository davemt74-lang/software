<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-work-control-v173.php';
function db(){throw new RuntimeException('A release identifier must not reach workflow storage.');}
$checks=0;
function releaseRoutingCheck(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
foreach(['Show release #101','Cancel release #101','Reschedule release #101 for 2027-02-01','Pause my release #101','Retry release #101','Show my releases #101'] as $query){
    $plan=agent_work_control_command_v173($query);
    releaseRoutingCheck($plan['run_id']===0&&$plan['action']==='','explicit release record cannot select workflow: '.$query);
    releaseRoutingCheck(empty(agent_work_control_chat_v173($query,['id'=>1],42)['handled']),'release command leaves workflow routing before storage: '.$query);
}
foreach(['Show workflow #101'=>['inspect',101],'Cancel workflow #101'=>['cancel',101],'Reschedule workflow #101 for 2027-02-01'=>['reschedule',101],'Show #101'=>['inspect',101],'Cancel workflow #12 for release #101'=>['cancel',12]] as $query=>[$action,$id]){
    $plan=agent_work_control_command_v173($query);releaseRoutingCheck($plan['action']===$action&&$plan['run_id']===$id,'existing explicit workflow selection is retained: '.$query);
}
echo "RELEASE_WORKFLOW_ROUTING=PASS ($checks cases)\n";
