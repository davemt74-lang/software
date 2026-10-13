<?php
declare(strict_types=1);
require __DIR__.'/../includes/homeserver-agent-v025.php';
$features=['agent.delegation.v1'];$calls=[];$ack=true;$offAck=true;$attempt=[];
function homeserver_capability_v024_raw(int $uid):array{return ['features'=>$GLOBALS['features']];}
function homeserver_capability_v024_string_list($values):array{return array_values($values);}
function homeserver_agent_v018_set_last_attempt(array $state):void{$GLOBALS['attempt']=$state;}
function homeserver_agent_v018_credentials(int $uid):array{return ['transport'=>'fixture'];}
function homeserver_agent_v018_execute(int $uid,array $credentials,array $payload):array{
    $GLOBALS['calls'][]=$payload;$result=['reply'=>'Scoped source answer [P1]','provider'=>'fixture'];
    if($GLOBALS['ack']&&!empty($payload['project_knowledge_r6']))$result['project_knowledge_r6']=['contract'=>'vp3.project-knowledge.r6','fingerprint'=>$payload['project_knowledge_r6']['fingerprint']];
    $result['knowledge_scope_off_r6']=$GLOBALS['offAck']&&($payload['knowledge_scope_off_r6']??false);
    return ['result'=>$result];
}
function homeserver_agent_v018_failure_class(string $message):string{return 'fixture_failure';}
function check(bool $yes,string $message):void{if(!$yes)throw new RuntimeException($message);}
$pointer=['conversation_id'=>42,'agent_id'=>0,'query'=>'flour','state_revision'=>str_repeat('a',64),'fingerprint'=>str_repeat('b',64),'attachments'=>[]];
$send=static fn($refs,$allowed=true)=>homeserver_agent_v025_chat(['id'=>7],'flour',42,[],['display_name'=>'Owner Agent'],null,[],true,$allowed,$refs);
check($send($pointer)===null&&count($calls)===0,'Old native build received a scoped turn.');
check($send([],false)===null&&count($calls)===0,'Old native build received an unenforceable Knowledge opt-out.');
$features[]='knowledge.context.scoped.r6';$valid=$send($pointer);
check($valid['project_knowledge_r6']['fingerprint']===$pointer['fingerprint'],'Exact native source acknowledgement was discarded.');
check($calls[0]['include_knowledge']===false&&$calls[0]['project_knowledge_r6']===$pointer,'Native request broadened canonical context.');
$ack=false;check($send($pointer)===null&&$attempt['failure_class']==='project_knowledge_receipt_mismatch','An unacknowledged scoped answer was accepted.');$ack=true;
$off=$send([],false);check($off['knowledge_scope_off_r6']===true,'Explicit native opt-out acknowledgement was lost.');
$offAck=false;check($send([],false)===null&&$attempt['failure_class']==='knowledge_optout_receipt_missing','Native opt-out downgrade was accepted.');
echo "PASS: R6 capability gates before delegated inference, exact canonical packet transport, bounded Knowledge opt-out and source/opt-out acknowledgements.\n";
