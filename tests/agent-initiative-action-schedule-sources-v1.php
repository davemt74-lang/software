<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-schedule-actions-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-action-sources-v1.php';
$scheduleBinding=schedule_action_seed_policy(7,10);$p=vp3_initiative_principal_v1($pdo,$u,7);$sourceChecks=0;
function schedule_source_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['sourceChecks']++;echo 'PASS '.$label.PHP_EOL;}
function schedule_source_loaded(array $refs,string $tool):array
{
    $scope=['user'=>$GLOBALS['u'],'principal'=>$GLOBALS['p'],'agent_id'=>7,'conversation_id'=>10,'verification_only'=>true];$coverage=[];
    $binding=vp3_initiative_policy_v1($GLOBALS['u'],7);
    $evidence=vp3_initiative_capabilities_evidence_v1(db(),$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),$refs,$coverage);
    $sources=[];foreach($refs as $ref)$sources[$ref]=$evidence[$ref]['revision'];
    $goal=['binding'=>$binding,'sources'=>$sources,'capability_sources'=>vp3_initiative_capability_source_map_v1($evidence,$refs),
        'tool'=>$tool,'primary'=>$refs[0],'focus'=>'Advance the exact schedule.','report'=>true];
    $read=vp3_initiative_capability_snapshot_v1(db(),$scope,$binding,$tool,['primary'=>$refs[0],'refs'=>$refs],$evidence);
    $revisions=$sources;ksort($revisions);
    $out=['type'=>'initiative_capability_read','version'=>1,'tool'=>$tool,'status'=>'read_complete','owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>10,
        'primary'=>$refs[0],'source_revisions'=>$revisions,'read_fingerprint'=>vp3_initiative_capability_fingerprint_v1($read),
        'record_count'=>count($read['records']),'coverage_fingerprint'=>vp3_initiative_capability_fingerprint_v1($read['coverage']),
        'coverage_state'=>in_array($read['coverage']['state']??'', ['ready','limited'],true)?$read['coverage']['state']:'bounded'];
    $json=json_encode($goal,JSON_THROW_ON_ERROR);
    return ['user'=>$GLOBALS['u'],'principal'=>$GLOBALS['p'],'agent_id'=>7,'conversation_id'=>10,'message'=>['id'=>510],
        'run'=>['id'=>610,'goal'=>$json],'goal'=>$goal,'state'=>['goal_hash'=>hash('sha256',$json)],
        'result'=>['initiative_tool_result'=>['output'=>$out]],'evidence'=>$evidence];
}
function schedule_source_apply(array $loaded,array $plan,string $key):array
{
    db()->beginTransaction();try{
        section12_lock_owner(db(),1);$before=vp3_initiative_action_sources_prepare_v1(db(),$loaded,$plan);
        $result=vp3_initiative_schedule_action_apply_v1(db(),$GLOBALS['u'],$GLOBALS['p'],$GLOBALS['scheduleBinding'],$plan,$key);
        $proof=vp3_initiative_action_sources_capture_v1(db(),$loaded,$plan,$result,$before);db()->commit();
        return [$result,$proof];
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
$r=schedule_action_apply(schedule_action_plan('reminder.create',['schedule'=>$schedule]),'transition-reminder-new');$ref='reminder:'.$r['record_id'];
$loaded=schedule_source_loaded([$ref],'reminders.read');$plan=schedule_action_plan('reminder.cancel',['target_ref'=>$ref],schedule_action_resources([$ref]));
[$result,$proof]=schedule_source_apply($loaded,$plan,'transition-reminder-cancel');
schedule_source_check(vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof)[$ref]['kind']==='action_tombstone','cancelled reminder remains a native-inspected tombstone instead of stale original active evidence');
$r=schedule_action_apply(schedule_action_plan('reminder.create',['schedule'=>$schedule]),'transition-reminder-delete-new');$ref='reminder:'.$r['record_id'];
$loaded=schedule_source_loaded([$ref],'reminders.read');$plan=schedule_action_plan('reminder.delete',['target_ref'=>$ref],schedule_action_resources([$ref]));
[$result,$proof]=schedule_source_apply($loaded,$plan,'transition-reminder-delete');
schedule_source_check(vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof)[$ref]['kind']==='action_tombstone','deleted reminder replay verifies exact canonical absence and ongoing authority');
$r=schedule_action_apply(schedule_action_plan('calendar.create',['event'=>$event]),'transition-calendar-new');$ref='calendar:event:'.$r['record_id'];
$loaded=schedule_source_loaded([$ref],'calendar.read');$plan=schedule_action_plan('calendar.update',['target_ref'=>$ref,'event'=>array_merge($event,['title'=>'Verified new title'])],schedule_action_resources([$ref]));
[$result,$proof]=schedule_source_apply($loaded,$plan,'transition-calendar-update');
schedule_source_check(vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof)[$ref]['title']==='Verified new title','calendar update replaces only exact source revision and verifies current original read snapshot');
$loaded=schedule_source_loaded([$ref],'calendar.read');$plan=schedule_action_plan('calendar.cancel',['target_ref'=>$ref],schedule_action_resources([$ref]));
[$result,$proof]=schedule_source_apply($loaded,$plan,'transition-calendar-cancel');
schedule_source_check(vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof)[$ref]['kind']==='action_tombstone','calendar cancellation does not invalidate its own completed action report');
$r=schedule_action_apply(schedule_action_plan('calendar.create',['event'=>array_merge($event,['recurrence'=>'weekly'])]),'transition-weekly-new');
$ref=$r['output_refs'][0]['ref'];$second='calendar:event:'.$r['record_id'].'@'.(new DateTimeImmutable($r['effect']['record']['start_at_utc'],new DateTimeZone('UTC')))->modify('+7 days')->format('Ymd\THis\Z');
$loaded=schedule_source_loaded([$ref,$second],'calendar.read');
$plan=schedule_action_plan('calendar.update',['target_ref'=>$ref,'event'=>array_merge($event,['recurrence'=>'weekly','start_time'=>'11:00','end_time'=>'12:00'])],schedule_action_resources([$ref]));
[$result,$proof]=schedule_source_apply($loaded,$plan,'transition-weekly-move');$verified=vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof);
schedule_source_check($verified[$ref]['kind']==='action_tombstone'&&$verified[$second]['kind']==='action_tombstone'&&count($proof['affected_refs'])===2,'whole-series move binds both original selected occurrence removals to the exact native record');
$writes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();vp3_initiative_action_sources_current_v1($pdo,$loaded,$plan,$result,$proof);
schedule_source_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$writes,'schedule transition replay performs no delivery, scheduler tick or mutation');
echo 'ACTION_SCHEDULE_SOURCES=PASS ('.$sourceChecks.' focused checks)'.PHP_EOL;
