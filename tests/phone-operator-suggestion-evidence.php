<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/phone-workspace-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-live-assist-v1.php';

$assist=['capture_mode'=>'both','last_suggestion_seq'=>0,'receipt_secret'=>bin2hex(random_bytes(32))];
$call=bin2hex(random_bytes(16));
$session=['next_seq'=>2];
$caller=vp3_operator_live_caption_receipt_v1($assist,$call,1,'remote_audio','Which day works?');
$owner=vp3_operator_live_caption_receipt_v1($assist,$call,1,'local_audio','Friday works.');
$turns=vp3_operator_live_suggestion_evidence_v1($assist,$session,$call,[$caller,$owner]);
if(array_column($turns['turns'],'audio_side')!==['remote_audio','local_audio']||$turns['last_seq']!==1)
    throw new RuntimeException('Speaker evidence lost its source or sequence.');

$cases=[
    'edited caption'=>[$assist,$session,$call,[array_replace($caller,['text'=>'Another day'])],409],
    'changed speaker'=>[$assist,$session,$call,[array_replace($caller,['speaker'=>'local_audio'])],409],
    'different call'=>[$assist,$session,bin2hex(random_bytes(16)),[$caller],409],
    'duplicate segment'=>[$assist,$session,$call,[$caller,$caller],422],
    'expired segment'=>[$assist,['next_seq'=>12],$call,[$caller],409],
    'caller-only grant'=>[array_replace($assist,['capture_mode'=>'remote']),$session,$call,[$caller,$owner],409],
    'owner alone'=>[$assist,$session,$call,[$owner],409],
    'already suggested'=>[array_replace($assist,['last_suggestion_seq'=>1]),$session,$call,[$caller],409],
];
foreach($cases as $name=>[$grant,$sequence,$id,$receipts,$status]){
    try{vp3_operator_live_suggestion_evidence_v1($grant,$sequence,$id,$receipts);}
    catch(VP3PhoneWorkspaceException $error){if($error->httpStatus===$status)continue;throw $error;}
    throw new RuntimeException('Accepted invalid evidence for '.$name);
}
echo "PASS 9 private suggestion evidence cases\n";
