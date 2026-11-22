<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-unified-specialist-followthrough-v1.php';
function check2c(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
$hash=str_repeat('a',64);$owner=41;$agent=7;$cid=60;$run=120;$mid=330;
$href='/chat.php?agent=7&conversation_id=60&message_id=330';
$fixture=static function(string $specialist,string $source,string $action='ask')
    use($hash,$owner,$agent,$cid,$run,$mid,$href):array {
    $route=['specialist'=>$specialist,'trigger'=>'needs_attention','action'=>$action];
    $brief=['specialist_id'=>$specialist,'source_ref'=>$source,'source_revision'=>$hash,'title'=>'Verified item'];
    $finding=['source_ref'=>$source,'source_revision'=>$hash,'message_id'=>$mid,
        'run_id'=>$run,'conversation_id'=>$cid,'delivery'=>'delivered','url'=>$href,'routing'=>$route];
    $output=['type'=>'initiative_coordination','evidence_revisions'=>[$source=>$hash]];
    $origin=['user'=>['id'=>$owner],'agent_id'=>$agent,'conversation_id'=>$cid,
        'run'=>['id'=>$run],'message'=>['id'=>$mid],
        'goal'=>['tool'=>'coordination.review','primary'=>$source,'sources'=>[$source=>$hash],
            'binding'=>['owner_user_id'=>$owner,'agent_id'=>$agent,'conversation_id'=>$cid],
            'coordination_route'=>$route],
        'result'=>['initiative_tool_result'=>['output'=>$output]],
        'context'=>['source'=>'agent_initiative','initiative_run_id'=>$run,'initiative_output'=>$output],
        'state'=>['state'=>'open','last_reply_message_id'=>0,'reply_ref'=>'canonical-owner-reference']];
    return [$brief,$finding,$origin];
};
$project=static function(array $b,array $f,array $o,?array $loaded=null,bool $verified=false)
    use($owner,$agent,$cid,$href):?array {
    return vp3_unified_specialist_followthrough_receipt_v1($b,$f,$o,$loaded,$verified,$owner,$agent,$cid,$href);
};
foreach([['profile','profile:visitor:15','ask'],['media','media:track:8','ask'],
    ['marketing','marketing:campaign-readiness:9','report']] as [$name,$ref,$action]) {
    [$b,$f,$o]=$fixture($name,$ref,$action);
    $posted=$project($b,$f,$o);
    check2c($posted!==null&&$posted['state']===($action==='ask'?'awaiting_owner':'posted'),
        "$name must show verified Chat post without claiming owner work");
    check2c($posted['chat_url']===$href&&$posted['message_id']===$mid&&$posted['receipt_run_id']===0,
        "$name must preserve the original exact Chat message");
    $declined=$o;$declined['state']['state']='declined';$declined['state']['last_reply_message_id']=401;
    check2c($project($b,$f,$declined)===null,"$name cannot infer an unverified owner decline");
    check2c($project($b,$f,$declined,null,true)['state']==='declined',"$name owner decline proof missing");
    $deferred=$declined;$deferred['state']['state']='deferred';
    check2c($project($b,$f,$deferred,null,true)['state']==='deferred',"$name owner deferral proof missing");
    $reported=$declined;$reported['state']['state']='owner_resolved';
    $ownerMark=$project($b,$f,$reported,null,true);
    check2c($ownerMark['state']==='owner_marked_resolved'&&$ownerMark['receipt_run_id']===0,
        "$name owner-reported resolution cannot be labeled executor completion");
    $discuss=$declined;$discuss['state']['state']='discussed';
    check2c($project($b,$f,$discuss,null,true)['state']==='owner_replied',
        "$name reply must be a separate lifecycle stage");
    $queued=$declined;$queued['state']['state']='queued';$queued['state']['continuation_run_id']=512;
    check2c($project($b,$f,$queued,null,true)===null,"$name queue without canonical child is not verified");
    $loaded=['run'=>['id'=>$run],'message'=>['id'=>$mid],
        'state'=>$queued['state'],'continuation'=>['id'=>512,'status'=>'queued'],
        'continuation_result'=>null,'domain_action'=>null];
    $queueStatus=$project($b,$f,$queued,$loaded,true);
    check2c($queueStatus['state']==='preparation_queued'&&$queueStatus['receipt_run_id']===0,
        "$name queued is not a completed draft");
    $broken=$loaded;$broken['continuation']['id']=513;
    check2c($project($b,$f,$queued,$broken,true)===null,"$name wrong child run accepted");
    $failed=$loaded;$failed['continuation']['status']='failed';
    check2c($project($b,$f,$queued,$failed,true)['state']==='preparation_failed',
        "$name failed private preparation must not appear running");
    $completed=$queued;$completed['state']['state']='completed';
    $finished=$loaded;$finished['state']=$completed['state'];
    $finished['continuation']['status']='completed';
    $finished['continuation_result']=['effect_state'=>'private_draft_ready'];
    $proof=$project($b,$f,$completed,$finished,true);
    check2c($proof['state']==='preparation_verified'&&$proof['receipt_run_id']===512,
        "$name completed private draft receipt missing");
    $invented=$finished;$invented['continuation_result']=['effect_state'=>'publicly_published'];
    check2c($project($b,$f,$completed,$invented,true)===null,
        "$name cannot claim a public effect from a private draft");
    $actioned=$discuss;$actioned['state']['action_run_id']=727;
    $actionLoaded=['run'=>['id'=>$run],'message'=>['id'=>$mid],
        'state'=>$actioned['state'],'continuation'=>null,'continuation_result'=>null,
        'domain_action'=>['run'=>['id'=>727,'status'=>'completed'],
            'receipt_id'=>818,'result'=>['domain_result'=>['verified'=>true]]]];
    $approved=$project($b,$f,$actioned,$actionLoaded,true);
    check2c($approved['state']==='approved_action_verified'&&$approved['receipt_run_id']===727,
        "$name approved action must require its own canonical receipt");
    $fake=$actionLoaded;$fake['domain_action']['receipt_id']=0;
    check2c($project($b,$f,$actioned,$fake,true)['state']==='owner_replied',
        "$name missing domain receipt cannot be treated as approved-action completion");
    $tampered=$f;$tampered['source_revision']=str_repeat('b',64);
    check2c($project($b,$tampered,$o)===null,"$name source changed");
    $tampered=$f;$tampered['url']='https://outside.example/message';
    check2c($project($b,$tampered,$o)===null,"$name forged Chat destination accepted");
    $tampered=$f;$tampered['delivery']='private';
    check2c($project($b,$tampered,$o)===null,"$name undelivered report claimed posted");
    $tampered=$f;$tampered['run_id']++;
    check2c($project($b,$tampered,$o)===null,"$name wrong root run accepted");
    $tampered=$o;$tampered['user']['id']=999;
    check2c($project($b,$f,$tampered)===null,"$name cross-owner decision leaked");
    $tampered=$o;$tampered['goal']['binding']['agent_id']=9;
    check2c($project($b,$f,$tampered)===null,"$name wrong Agent bound");
    $tampered=$o;$tampered['goal']['binding']['conversation_id']=61;
    check2c($project($b,$f,$tampered)===null,"$name wrong Chat bound");
    $tampered=$o;$tampered['context']['initiative_output']['evidence_revisions'][$ref]=str_repeat('f',64);
    check2c($project($b,$f,$tampered)===null,"$name tampered saved output accepted");
    $tampered=$o;$tampered['state']['state']='completed';
    check2c($project($b,$f,$tampered)===null,"$name fake completion without owner reply accepted");
}
echo "PASS Phase 2C Profile/Media/Marketing source-bound Chat, owner decision and executor receipts\n";
