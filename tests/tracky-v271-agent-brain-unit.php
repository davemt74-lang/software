<?php
declare(strict_types=1);

define('VP3_TRACKY_PROTOCOL_V270','physical_context.v1');
require __DIR__.'/../includes/cognitive-runtime-v500.php';
require __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require __DIR__.'/../includes/tracky-agent-v271.php';

function v271_fail(string $message): never { fwrite(STDERR,$message."\n"); exit(1); }
function v271_expect(bool $value,string $message): void { if(!$value)v271_fail($message); }
function v271_same(mixed $actual,mixed $expected,string $message): void {
    if($actual!==$expected)v271_fail($message.' actual='.var_export($actual,true).' expected='.var_export($expected,true));
}

v271_same(tracky_agent_intent_v271('Where am I?'),'current','where am I intent');
v271_same(tracky_agent_intent_v271('Where are my keys?'),'where','where entity intent');
v271_same(tracky_agent_intent_v271('Where is my invoice?'),'','nonphysical where query was hijacked');
v271_same(tracky_agent_intent_v271('Who is in the office?'),'present','presence intent');
v271_same(tracky_agent_intent_v271('When did you last see my keys?'),'last_seen','last seen intent');
v271_same(tracky_agent_intent_v271('When did Tracky last spot my wallet?'),'last_seen','Tracky last-spot intent');
v271_same(tracky_agent_intent_v271('When was my wallet seen?'),'last_seen','passive last-seen intent');
v271_same(tracky_agent_intent_v271('What changed in the room?'),'changes','changes intent');
v271_same(tracky_agent_intent_v271('How confident are you about the keys?'),'confidence','confidence intent');
v271_same(tracky_agent_intent_v271('Why do you think the keys are in the office?'),'why','explain intent');
v271_same(tracky_agent_intent_v271('Tracky status'),'health','health intent');
v271_same(tracky_agent_intent_v271('Show my calendar'),'','calendar query was hijacked');
v271_same(tracky_agent_entity_label_v271('room:home_office'),'Home Office','entity label normalization');

v271_same(tracky_agent_cognitive_event_type_v271(['severity'=>'urgent','event_type'=>'object.state_changed']),'physical_context.alert','urgent event mapping');
v271_same(tracky_agent_cognitive_event_type_v271(['severity'=>'system-health','event_type'=>'camera.health_changed']),'physical_context.health_changed','health event mapping');
v271_same(tracky_agent_cognitive_event_type_v271(['severity'=>'notable','event_type'=>'room.entered']),'physical_context.changed','normal event mapping');

$domain=tracky_agent_domain_contract_v271();
v271_same($domain['id'],'physical_context','domain id');
v271_same($domain['plugin_key'],'tracky','plugin binding');
v271_expect(in_array('physical_site',$domain['objects'],true),'physical site missing');
v271_expect(in_array('physical_context.alert',$domain['events'],true),'physical alert missing');

require __DIR__.'/../includes/cognitive-domain-registry-v2600.php';
$public=vp3_cognitive_registry_public_v500();
$modules=array_column($public['modules'],'module');
v271_expect(in_array('physical_context',$modules,true),'physical context module missing from canonical registry');
$tools=[];
foreach((array)$public['tools'] as $tool)$tools[(string)$tool['id']]=$tool;
foreach(['tracky.current_context','tracky.where_is','tracky.who_is_present','tracky.last_seen','tracky.what_changed','tracky.confidence','tracky.why','tracky.health'] as $id){
    v271_expect(isset($tools[$id]),'missing normalized Tracky tool '.$id);
    v271_same($tools[$id]['kind'],'read','non-read Tracky cognitive tool: '.$id);
    v271_same($tools[$id]['risk'],'low','unexpected Tracky tool risk: '.$id);
    v271_expect(empty($tools[$id]['requires_approval']),'read tool should not require approval: '.$id);
    v271_expect(!preg_match('/(?:control|write|move|unlock|open_door|switch|execute)/i',$id),'physical action authority leaked into Tracky tool id: '.$id);
}

$registry=vp3_cognitive_domain_registry_v2600();
v271_expect(isset($registry['domains']['physical_context']),'physical context missing from canonical domain registry');
v271_same(vp3_cognitive_domain_for_event_v2600('tracky','physical_context.alert'),'physical_context','Tracky source alias did not resolve');
v271_same(vp3_cognitive_domain_event_class_v2600('physical_context','physical_context.health_changed'),'failure_recovery','health event class mismatch');

$prepared=vp3_cognitive_domain_prepare_event_v2600('physical_context','physical_context.alert',[
    ['type'=>'physical_site','id'=>'home-1','scope'=>'personal'],
],[
    'tracky_event_id'=>'event-1',
    'severity'=>'urgent',
    'confidence'=>0.97,
]);
v271_expect(!empty($prepared['accepted']),'governed physical event rejected by canonical ingress preparation');
v271_same($prepared['payload']['domain_source'],'physical_context','physical ingress domain mismatch');
v271_expect(!empty($prepared['payload']['brain_promotion_deferred']),'physical event must respect canonical deferred Brain promotion');

$why=tracky_agent_answer_v271([
    'fact'=>['entity'=>'Keys','predicate'=>'located_in','location'=>'Office','confidence'=>0.94],
    'evidence'=>['event_type'=>'object.moved','occurred_at'=>'2026-09-26 15:00:00'],
],'why');
v271_expect(str_contains($why,'Raw perception evidence is not copied'),'explanation omitted privacy boundary');

echo "TRACKY_V271_AGENT_BRAIN_UNIT=PASS\n";
