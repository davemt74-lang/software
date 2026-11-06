<?php
declare(strict_types=1);

// Reuse the actual permission/source/worker/broker fixture. The model decision
// below depends on the received request, including its final consent boundary.
require __DIR__.'/agent-initiative-actions-v1.php';

$actionFixtureAgent=7;$actionFixtureCid=10;$principal=vp3_initiative_principal_v1($pdo,$user,7);$longChecks=0;
function initiative_action_long_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);$GLOBALS['longChecks']++;echo 'PASS '.$label.PHP_EOL;
}
$source=initiative_action_task('Long approval source');$root=initiative_action_seed('task:'.$source,'Review the complete saved owner request');
$review=initiative_action_reply($root,['action'=>['adapter'=>'task.create','arguments'=>['title'=>'Must not be saved from a truncated approval']]],'Prepare this exact next task');
initiative_action_ack($review);$pending=initiative_action_load($root);
$tail='DO_NOT_APPROVE_TAIL: Do not approve or save this task yet. This remains a conditional discussion.';
$query='Yes, those are the details. '.str_repeat('Additional context for review. ',150).$tail;
initiative_action_long_check(mb_strlen($query)>4000&&mb_strlen($query)<=6000&&mb_strpos($query,$tail)>4000,'the saved request carries a real consent negation beyond the former 4000-character cut');
$ownerMessage=initiative_action_owner($query);$seen=null;$calls=0;
$tasks=(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn();
$receipts=(int)$pdo->query('SELECT COUNT(*) FROM agent_workflow_receipts')->fetchColumn();
$selection=vp3_initiative_followthrough_reply_v1($pdo,$user,$principal,$actionFixtureCid,$ownerMessage,initiative_action_ref($pending),$query,null,
    static function(string $prompt,string $protocol)use(&$seen,&$calls,$tail):string{
        $calls++;if(db()->inTransaction()||$protocol!=='vp3.initiative-reply.v1')throw new LogicException('Long request decision must use normal outside-transaction inference.');
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);$seen=$input['owner_message']['text']??null;
        $conditional=is_string($seen)&&str_contains($seen,$tail);
        return json_encode(['intent'=>$conditional?'discuss':'advance','focus'=>'Read the full current owner consent before taking action.',
            'action_decision'=>$conditional?'none':'approve'],JSON_THROW_ON_ERROR);
    });
initiative_action_long_check($calls===1&&$seen===$query,'the complete bounded saved owner request reaches the action classifier unchanged');
$current=initiative_action_load($root);
initiative_action_long_check($selection['advisory_context']['verified_action']['status']==='approval_pending'
    &&$current['state']['last_intent']==='discuss'&&$current['domain_action']['result']===null
    &&(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn()===$tasks
    &&(int)$pdo->query('SELECT COUNT(*) FROM agent_workflow_receipts')->fetchColumn()===$receipts,
    'a consent condition after character 4000 stays a discussion and cannot create a native task or approval receipt');

$oversized=str_repeat('x',6001);$ownerMessage=initiative_action_owner($oversized);$beforeRevision=$current['state']['revision'];$calls=0;$rejected=false;
try{
    vp3_initiative_followthrough_reply_v1($pdo,$user,$principal,$actionFixtureCid,$ownerMessage,initiative_action_ref($current),$oversized,null,
        static function()use(&$calls):string{$calls++;return '{"intent":"advance","focus":"Do not reach this provider.","action_decision":"approve"}';});
}catch(RuntimeException $error){$rejected=$error->getCode()===422;}
initiative_action_long_check($rejected&&$calls===0,'an oversized direct follow-through request is rejected before model work');
$unchanged=initiative_action_load($root);
initiative_action_long_check($unchanged['state']['revision']===$beforeRevision&&$unchanged['domain_action']['run']['status']==='approval_pending'
    &&(int)$pdo->query('SELECT COUNT(*) FROM agent_workflow_receipts')->fetchColumn()===$receipts&&!$pdo->inTransaction(),
    'oversized input cannot consume the pending review, write a receipt or retain a transaction');

echo 'INITIATIVE_ACTIONS_LONG_MESSAGE=PASS ('.$longChecks.' cases)'.PHP_EOL;
