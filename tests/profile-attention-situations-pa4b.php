<?php
declare(strict_types=1);

// Real Profile source authority, presentation receipts, lifecycle mutations and
// the shared situation reader; the inherited fixture replaces external I/O.
require_once __DIR__.'/fixtures/profile-attention-pa4b.php';
require_once dirname(__DIR__).'/includes/agent-activity-situations-v1.php';

$checks=0;
function psit_check(bool $ok,string $label): void
{
    global $checks;
    if(!$ok)throw new RuntimeException($label);
    ++$checks;
    echo 'PASS '.$label."\n";
}

function psit_note(string $type='profile_view'): array
{
    if($type==='profile_view')return pa4b_note();
    $message=pa4b_message('A question that needs the owner.');
    $context=$message['context'];
    $conversation=vp3_profile_handoff_conversation_pa3(db(),1,$message['public_conversation_id']);
    profile_agent_needs_owner(db(),$context['profile'],$context['agent'],
        vp3_profile_agent_public_event_session_v1($context),$conversation,'A question that needs the owner.');
    $q=db()->prepare("SELECT n.* FROM notifications n JOIN profile_events e ON e.id=n.source_id
        AND e.owner_user_id=n.user_id WHERE n.user_id=1 AND n.source_type='profile_event'
        AND e.event_type='needs_owner' ORDER BY e.id DESC LIMIT 1");
    $q->execute();$notification=$q->fetch(PDO::FETCH_ASSOC);
    if(!$notification)throw new RuntimeException('Owner attention fixture was not saved.');
    $source=vp3_profile_attention_source_pa4b(db(),pa4b_owner(),(int)$notification['id']);
    return ['notification'=>$notification,'event'=>$source['event'],
        'item'=>vp3_profile_attention_projection_pa4b(db(),$source,pa4b_scope())];
}

function psit_present(array $note): array
{
    $shown=vp3_profile_attention_present_pa4b(db(),pa4b_owner(),pa4b_input($note));
    return vp3_profile_attention_ack_pa4b(db(),pa4b_owner(),pa4b_input($shown,[
        'delivery_id'=>$shown['delivery_id'],'chat_message_id'=>$shown['chat_message_id'],
        'visible'=>true,'tab_id'=>str_repeat('a',32),'client_instance_id'=>str_repeat('c',32),
    ]));
}

function psit_update(array $note,string $operation): array
{
    return vp3_profile_attention_update_pa4b(db(),pa4b_owner(),pa4b_input(pa4b_fresh($note),['operation'=>$operation]));
}

function psit_read(array $note,int $agentId=0,?array $user=null): array
{
    $now=new DateTimeImmutable('@'.intdiv(vp3_profile_attention_now_pa4b(),1000));
    [$coverage,$signals]=pa4b_readonly(fn()=>vp3_activity_situation_notifications_v1(
        db(),$user??pa4b_owner(),['agent_id'=>$agentId],$now
    ));
    $id=(string)$note['event']['id'];
    $matching=array_values(array_filter($signals,static fn(array $signal):bool=>
        ($signal['anchor']['object_ref']['type']??null)==='profile_event'
        &&($signal['anchor']['object_ref']['id']??null)===$id));
    return ['coverage'=>$coverage,'signals'=>$matching];
}

function psit_projection(array $note): array
{
    // Deliberately pass the original notification row: disposition and current
    // read generation must come from source authority, not a stale caller row.
    return notification_profile_projection_pa4b(db(),pa4b_owner(),$note['notification']);
}

pa4b_reset();$note=psit_note();$shown=psit_present($note);$before=pa4b_snapshot();$effects=pp_no_external_snapshot();
$projection=psit_projection($note);$pending=psit_read($note);
psit_check($shown['item']['presented']&&!$shown['item']['read']
    &&$projection['attention_type']==='profile_view'&&$projection['attention_disposition']==='pending'
    &&$projection['attention_wake_due']===false,'canonical presentation leaves the pending visit and read receipt distinct');
psit_check($pending['coverage']==='ready'&&count($pending['signals'])===1
    &&$pending['signals'][0]['state']==='needs_attention','one unread current visit supplies one existing activity situation');
psit_check(pa4b_snapshot()===$before&&pp_no_external_snapshot()===$effects,
    'situation reads do not acknowledge, publish, run inference or start external work');
psit_update($note,'read');$read=psit_read($note);$projection=psit_projection($note);
psit_check($projection['attention_disposition']==='pending'&&!$projection['can_read']
    &&$read['signals'][0]['state']==='current','a routine read visit stays current without manufacturing unresolved owner work');
psit_check(psit_read($note)===$read,'repeated projection cannot turn a routine read visit back into needs-attention');

pa4b_reset();$question=psit_note('needs_owner');$shown=psit_present($question);
psit_check($shown['item']['presented']&&!$shown['item']['read']
    &&psit_read($question)['signals'][0]['state']==='needs_attention',
    'visible acknowledgement does not resolve a saved unanswered owner question');
psit_update($question,'read');$readQuestion=psit_read($question);$projection=psit_projection($question);
psit_check($projection['attention_type']==='needs_owner'&&$projection['attention_disposition']==='pending'
    &&!$projection['can_read']&&$readQuestion['signals'][0]['state']==='needs_attention',
    'reading an explicit needs-owner request does not claim it was answered');

foreach(['profile_view','needs_owner'] as $type){
    foreach(['handled','ignored'] as $operation){
        foreach([false,true] as $readFirst){
            pa4b_reset();$note=psit_note($type);psit_present($note);
            if($readFirst)psit_update($note,'read');
            $settled=psit_update($note,$operation);$before=pa4b_snapshot();
            $projection=psit_projection($note);$result=psit_read($note);
            psit_check($settled['item']['disposition']===$operation&&$settled['item']['read']===$readFirst
                &&$projection['attention_disposition']===$operation
                &&$result===['coverage'=>'ready','signals'=>[]]&&pa4b_snapshot()===$before,
                $type.' '.$operation.' leaves active situations while preserving '.($readFirst?'read':'unread').' history');
        }
    }
}

foreach(['profile_view','needs_owner'] as $type){
    pa4b_reset();$note=psit_note($type);psit_present($note);psit_update($note,'read');
    $snoozed=psit_update($note,'snooze');$generation=$snoozed['item']['wake_generation'];$before=pa4b_snapshot();
    $projection=psit_projection($note);
    psit_check($projection['attention_disposition']==='snoozed'&&!$projection['attention_wake_due']
        &&psit_read($note)===['coverage'=>'ready','signals'=>[]]&&pa4b_snapshot()===$before,
        $type.' remains suppressed during its canonical snooze without changing source state');
    $GLOBALS['pa3Now']+=86400001;$before=pa4b_snapshot();$due=psit_read($note);$projection=psit_projection($note);
    psit_check($projection['attention_wake_due']&&$projection['attention_disposition']==='snoozed'
        &&!$projection['can_read']&&count($due['signals'])===1&&$due['signals'][0]['state']==='needs_attention'
        &&pa4b_snapshot()===$before,
        $type.' verified due snooze becomes actionable before worker recovery without a read-side wake');
    vp3_profile_attention_maintenance_pa4b(db(),20);$awake=psit_read($note);$projection=psit_projection($note);
    psit_check($projection['attention_disposition']==='pending'&&!$projection['attention_wake_due']
        &&$projection['wake_generation']===$generation+1&&$projection['can_read']
        &&count($awake['signals'])===1&&$awake['signals'][0]['state']==='needs_attention'
        &&$awake['signals'][0]['revision']!==$due['signals'][0]['revision'],
        $type.' recovered generation retains one exact source with a current lifecycle revision');
}

pa4b_reset();$note=psit_note('needs_owner');$shown=psit_present($note);$projection=psit_projection($note);
psit_check($shown['item']['chat_agent_id']===7&&$shown['item']['chat_conversation_id']===11
    &&$projection['presentation_owner']===$shown['item']['presentation_owner']&&$projection['voice_allowed']===false,
    'existing named-Agent live delivery and presentation ownership remain intact');
psit_check(psit_read($note,7)['signals']===[]&&psit_read($note,8)['signals']===[],
    'generic Profile situations keep the existing named-Agent exclusion');
psit_check(psit_read($note,0,pa3_owner(4))['signals']===[],
    'another owner cannot receive this Profile situation');

pa4b_reset();$message=pa4b_message('PRIVATE_PROFILE_SITUATION_STATEMENT');psit_present($message);
db()->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');$before=pa4b_snapshot();
$projection=psit_projection($message);$result=psit_read($message);
psit_check($projection['source_status']==='unavailable'&&$result['signals']===[]
    &&$result['coverage']==='limited'&&!str_contains(json_encode([$projection,$result]),'PRIVATE_PROFILE_SITUATION_STATEMENT')
    &&pa4b_snapshot()===$before,'revoked source stays absent with incomplete coverage and no stale statement');

pa4b_reset();$note=psit_note();
db()->prepare('UPDATE profile_events SET metadata_json=? WHERE id=?')->execute(['{}',(int)$note['event']['id']]);
$projection=psit_projection($note);$result=psit_read($note);
psit_check($projection['source_status']==='historical_unverified'&&$result===['coverage'=>'limited','signals'=>[]],
    'unverified historical evidence cannot become an empty successful or actionable Profile review');

pa4b_reset();$note=psit_note();$before=pa4b_snapshot();
db()->failSql='SELECT * FROM profile_events';
try{$projection=psit_projection($note);$result=psit_read($note);}
finally{db()->failSql='';}
psit_check($projection['source_status']==='unavailable'&&!$projection['source_complete']
    &&$projection['attention_disposition']===null&&!$projection['attention_wake_due']
    &&$result===['coverage'=>'limited','signals'=>[]]&&pa4b_snapshot()===$before,
    'failed source reads fail closed without inventing quiet, disposed or awakened attention');

echo 'PROFILE_ATTENTION_SITUATIONS_PA4B=PASS ('.$checks.' checks; '.db()->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
