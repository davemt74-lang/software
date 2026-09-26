<?php
declare(strict_types=1);

define('VP3_TRACKY_PROTOCOL_V270','physical_context.v1');
require __DIR__.'/../includes/cognitive-runtime-v500.php';
require __DIR__.'/../includes/cognitive-attention-v2410.php';
require __DIR__.'/../includes/tracky-agent-v271.php';

function v272_fail(string $message): never { fwrite(STDERR,$message."\n"); exit(1); }
function v272_expect(bool $value,string $message): void { if(!$value)v272_fail($message); }
function v272_same(mixed $actual,mixed $expected,string $message): void {
    if($actual!==$expected)v272_fail($message.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
}

$defaults=tracky_v272_settings_defaults();
v272_expect(!empty($defaults['now_enabled']),'Now must default on');
v272_expect(!empty($defaults['chat_enabled']),'proactive Chat must default on');
v272_expect(empty($defaults['voice_enabled']),'Tracky voice must be opt-in');
v272_expect(empty($defaults['cross_plugin_access']),'cross-plugin access must fail closed');
v272_expect(!in_array('presence',$defaults['now_classes'],true),'presence must not spam Now by default');
v272_expect(in_array('safety',$defaults['voice_classes'],true),'safety class missing from voice allowlist');

$normalized=tracky_v272_settings_normalize([
    'now_enabled'=>false,'voice_enabled'=>true,
    'now_classes'=>['object','safety','unknown'],
    'voice_classes'=>['health','safety','raw_video'],
]);
v272_expect(empty($normalized['now_enabled']),'Now disable was not retained');
v272_expect(!empty($normalized['voice_enabled']),'voice enable was not retained');
v272_same($normalized['now_classes'],['object','safety'],'unknown Now class survived normalization');
v272_same($normalized['voice_classes'],['health','safety'],'unsafe voice class survived normalization');

v272_same(tracky_v272_event_class('person.detected'),'presence','person class');
v272_same(tracky_v272_event_class('object.moved'),'object','object class');
v272_same(tracky_v272_event_class('environment.changed'),'environment','environment class');
v272_same(tracky_v272_event_class('routine.deviation'),'routine','routine class');
v272_same(tracky_v272_event_class('camera.disconnected'),'health','camera health class');
v272_same(tracky_v272_event_class('safety.possible_hazard'),'safety','safety class');

v272_expect(tracky_v272_surface_allowed($defaults,'now','object'),'default object Now policy missing');
v272_expect(!tracky_v272_surface_allowed($defaults,'now','presence'),'default presence Now policy too noisy');
v272_expect(!tracky_v272_surface_allowed($defaults,'voice','safety'),'voice must remain disabled until user opts in');
$voice=$defaults;$voice['voice_enabled']=true;
v272_expect(tracky_v272_surface_allowed($voice,'voice','safety'),'enabled safety voice policy failed');

$event=[
    'id'=>44,'site_id'=>'home','event_id'=>'evt-44','event_type'=>'safety.possible_hazard',
    'severity'=>'urgent','confidence'=>0.91,'privacy_class'=>'cloud_derived','occurred_at'=>'2026-09-26 12:00:00',
    'event'=>['summary'=>'Possible smoke-like condition detected','room_id'=>'room:kitchen'],
];
$summary=tracky_v272_safe_summary($event);
v272_expect(str_contains(strtolower($summary),'possible safety condition'),'safety wording lost uncertainty');
v272_expect(!str_contains(strtolower($summary),'confirmed emergency'),'safety wording became overconfident');
$metric=tracky_v272_event_score($event);
v272_expect(!empty($metric['attention'])&&$metric['score']>=96,'safety event was not high attention');

$trigger=tracky_v272_trigger_payload($event);
v272_same($trigger['trigger'],'tracky.safety.possible_hazard','safety trigger mapping');
v272_same($trigger['event_class'],'safety','trigger class');
v272_expect(!array_key_exists('frame',$trigger)&&!array_key_exists('video',$trigger),'trigger leaked raw perception');

$objectEvent=$event;
$objectEvent['event_type']='object.moved';$objectEvent['severity']='notable';
$objectEvent['event']=['summary'=>'Keys moved','subject'=>['entity_id'=>'object:keys','type'=>'object'],'room_id'=>'room:office'];
$objectTrigger=tracky_v272_trigger_payload($objectEvent);
v272_same($objectTrigger['trigger'],'tracky.object.moved','object trigger mapping');
v272_same($objectTrigger['subject'],['entity_id'=>'object:keys','type'=>'object'],'object trigger subject');

tracky_agent_register_cognitive_v271();
$public=vp3_cognitive_registry_public_v500();
$module=null;foreach($public['modules'] as $candidate)if(($candidate['module']??'')==='physical_context'){$module=$candidate;break;}
v272_expect(is_array($module),'physical context module missing');
v272_expect(in_array('physical_event',$module['objects'],true),'physical event object not registered');
v272_expect(in_array('physical_event',$module['cards'],true),'physical event card not registered');

$input=tracky_v272_observation_input($voice,$event);
v272_same($input['category'],'risk','safety observation category');
v272_same($input['presentation_recommendation'],'voice_announce','opt-in safety event should request voice');
v272_same($input['proposed_action_ids'],[],'V2.72 must not propose physical actions');
v272_same($input['proposed_cards'][0]['card_type'],'physical_event','physical event card proposal missing');
v272_same($input['proposed_cards'][0]['object_ref']['id'],'44','physical event ref must use owner-scoped row identity');

$voiceOffInput=tracky_v272_observation_input($defaults,$event);
v272_same($voiceOffInput['presentation_recommendation'],'notification','voice-off safety event should request canonical notification');
v272_same($voiceOffInput['voice_safe_summary'],'','voice-off observation should not expose a voice summary');

$decision=vp3_cognitive_attention_decide_v2410($input,[
    'agent_voice_enabled'=>true,'voice_candidate_allowed'=>true,'interruptible'=>true,
    'attention_budget_remaining'=>true,'quiet_hours'=>false,'focus_mode'=>false,
]);
v272_expect(in_array($decision['surface'],['voice_announce','notification'],true),'safety event did not enter canonical attention surface');

$scenarios=tracky_v272_simulator_scenarios();
foreach(['arrival','departure','object_moved','camera_failure_and_recovery','possible_safety_condition','duplicate_event','outage_reconnect'] as $name){
    v272_expect(isset($scenarios[$name]),'missing simulator scenario '.$name);
}
v272_same(count($scenarios['duplicate_event']),2,'duplicate simulator scenario incomplete');
v272_same($scenarios['duplicate_event'][0]['event_id'],$scenarios['duplicate_event'][1]['event_id'],'duplicate scenario does not duplicate identity');
v272_expect(tracky_v272_event_is_resolution($scenarios['camera_failure_and_recovery'][1]),'camera recovery not recognized as resolution');

echo "TRACKY_V272_CROSS_SURFACE_UNIT=PASS\n";
