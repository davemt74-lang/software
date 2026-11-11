<?php
declare(strict_types=1);

// This fixture executes the production named planner, Brain reader, leases and receipts.
require __DIR__.'/agent-initiative-tools-pipeline-v1.php';

function reassessment_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}
function reassessment_schedule(array $user,int $ageSeconds=310): int
{
    db()->prepare("UPDATE agent_memory_items SET last_seen_at=?
        WHERE user_id=1 AND user_agent_id=17 AND memory_type='initiative_sweep'")
        ->execute([gmdate('Y-m-d H:i:s',time()-$ageSeconds)]);
    $before=count(scope_runs(1,17,'initiative.plan'));
    vp3_initiative_schedule_v1(db(),$user,17);
    $rows=scope_runs(1,17,'initiative.plan');
    return count($rows)>$before?(int)$rows[array_key_last($rows)]['id']:0;
}
function reassessment_activity(bool $busy,string $state='working',bool $focused=true): void
{
    db()->prepare("INSERT INTO agent_activity_state(user_id,surface,context_key,activity_state,last_heartbeat_at,idle_since_at,details_json)
        VALUES(1,'chat','chat:170',?,NOW(),NULL,?) ON CONFLICT(user_id) DO UPDATE SET
        activity_state=excluded.activity_state,last_heartbeat_at=excluded.last_heartbeat_at,details_json=excluded.details_json")
        ->execute([$state,json_encode(['visible'=>true,'conversation_id'=>170,'chat_focused'=>$focused,'chat_busy'=>$busy],JSON_THROW_ON_ERROR)]);
}

pipeline_reset($user);
db()->exec('DELETE FROM agent_activity_state WHERE user_id=1');
$plans=0;$reads=0;
$GLOBALS['scopeModel']=static function(string $prompt,string $protocol)use(&$plans,&$reads):string{
    $packet=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-plan.v1'){
        ++$plans;
        if(empty($packet['owner_context']['available']))return '{"jobs":[]}';
        return json_encode(['jobs'=>[['tool'=>'brain.read','primary'=>'task:1002','refs'=>['task:1002'],
            'focus'=>'Only Agent A task','report'=>true]]],JSON_THROW_ON_ERROR);
    }
    ++$reads;
    reassessment_check($packet['tool_result']['tool']==='brain.read'
        &&array_column($packet['tool_result']['records'],'source')===['brain:1002'],
        'reassessment advances the actual scoped Brain tool rather than merely a summary template');
    return json_encode(['draft'=>'I checked the current saved task and prepared its next step from that verified record.'],JSON_THROW_ON_ERROR);
};

$unknown=reassessment_schedule($user);
reassessment_check($unknown>0&&vp3_initiative_execute_v1(db(),$user,scope_claim($user,$unknown))['ok']&&$plans===1,
    'an unknown owner availability can record a real quiet model assessment');
$unknownGoal=json_decode(agent_workflow_row_v1400(db(),1,$unknown)['goal'],true,24,JSON_THROW_ON_ERROR);
reassessment_check($unknownGoal['owner_availability']==='unknown'&&preg_match('/\A[a-f0-9]{32}\z/D',$unknownGoal['attention_revision'])===1,
    'the plan identity stores only a bounded semantic availability and its stable attention revision');
reassessment_check(reassessment_schedule($user)===0&&$plans===1,
    'a due sweep with identical unknown semantic context creates no additional model plan');

reassessment_activity(false);
reassessment_check(reassessment_schedule($user,35)===0&&$plans===1,
    'unknown-to-quiet availability keeps the ordinary assessment cadence');
$quiet=reassessment_schedule($user);
reassessment_check($quiet>0&&vp3_initiative_execute_v1(db(),$user,scope_claim($user,$quiet))['ok']&&$plans===2,
    'the owner becoming available after a quiet assessment admits a new real model review of unchanged sources');
$quietGoal=json_decode(agent_workflow_row_v1400(db(),1,$quiet)['goal'],true,24,JSON_THROW_ON_ERROR);
reassessment_check($quietGoal['sources']===$unknownGoal['sources']&&$quietGoal['owner_availability']==='quiet_chat'
    &&$quietGoal['attention_revision']!==$unknownGoal['attention_revision'],
    'availability changes assessment identity while preserving the exact authorized source snapshot');
$preparations=scope_runs(1,17,'initiative.prepare');$prepare=(int)$preparations[array_key_last($preparations)]['id'];
reassessment_check(vp3_initiative_execute_v1(db(),$user,scope_claim($user,$prepare))['ok']&&$reads===1,
    'the newly useful assessment completes one actual Brain-read preparation through canonical execution');
$prepareCount=count(scope_runs(1,17,'initiative.prepare'));

db()->prepare('UPDATE agent_activity_state SET last_heartbeat_at=? WHERE user_id=1')->execute([gmdate('Y-m-d H:i:s',time()-2)]);
reassessment_check(reassessment_schedule($user)===0&&$plans===2&&$reads===1,
    'heartbeat timestamps alone neither reassess the model nor repeat actual tool work');
reassessment_activity(false,'paused');
reassessment_check(reassessment_schedule($user)===0&&$plans===2,
    'a working-to-paused label change with identical focused quiet availability does not create another assessment');

reassessment_activity(true);
reassessment_check(reassessment_schedule($user,35)===0&&$plans===2,
    'entering busy Chat does not trigger an early assessment');
$busy=reassessment_schedule($user);
reassessment_check($busy>0&&vp3_initiative_execute_v1(db(),$user,scope_claim($user,$busy))['ok']&&$plans===3,
    'a real busy availability transition can reassess the unchanged working situation');
reassessment_check(count(scope_runs(1,17,'initiative.prepare'))===$prepareCount&&$reads===1,
    'the unchanged preparation dedupe prevents a second Brain tool execution after reassessment');

reassessment_activity(false);
reassessment_check(reassessment_schedule($user,20)===0&&$plans===3,
    'returning to quiet Chat respects the 30-second assessment throttle');
$returned=reassessment_schedule($user,35);
reassessment_check($returned>0&&$returned!==$quiet&&vp3_initiative_execute_v1(db(),$user,scope_claim($user,$returned))['ok']&&$plans===4,
    'a busy-to-quiet owner return wakes a new assessment before five minutes with unchanged sources');
$returnedGoal=json_decode(agent_workflow_row_v1400(db(),1,$returned)['goal'],true,24,JSON_THROW_ON_ERROR);
reassessment_check($returnedGoal['sources']===$quietGoal['sources']&&$returnedGoal['owner_availability']===$quietGoal['owner_availability']
    &&$returnedGoal['attention_revision']!==$quietGoal['attention_revision']&&count(scope_runs(1,17,'initiative.prepare'))===$prepareCount&&$reads===1,
    'semantic attention revisions permit recurring returns while the same completed real tool work remains deduplicated');
reassessment_check(reassessment_schedule($user,35)===0&&$plans===4&&$reads===1,
    'another unchanged quiet heartbeat creates neither additional inference nor another preparation');

reassessment_activity(false,'paused',false);
$away=reassessment_schedule($user);
$awayGoal=json_decode(agent_workflow_row_v1400(db(),1,$away)['goal'],true,24,JSON_THROW_ON_ERROR);
reassessment_check($away>0&&$awayGoal['owner_availability']==='away'
    &&vp3_initiative_execute_v1(db(),$user,scope_claim($user,$away))['ok']&&$plans===5,
    'a scheduled assessment can record that the owner was away from this Chat');
reassessment_activity(false);
$refocused=reassessment_schedule($user,35);
reassessment_check($refocused>0&&vp3_initiative_execute_v1(db(),$user,scope_claim($user,$refocused))['ok']&&$plans===6
    &&count(scope_runs(1,17,'initiative.prepare'))===$prepareCount&&$reads===1,
    'returning to the exact focused Chat from away wakes a review without repeating completed work');
echo 'AGENT_INITIATIVE_REASSESSMENT_V1=PASS'.PHP_EOL;
