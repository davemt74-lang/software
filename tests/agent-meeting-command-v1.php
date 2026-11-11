<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-meeting-command-v1.php';

$count=0;
$check=static function(bool $ok,string $name)use(&$count):void {
    if (!$ok) throw new RuntimeException($name);
    echo 'PASS '.(++$count).' '.$name.PHP_EOL;
};
$now=new DateTimeImmutable('2030-11-06T20:00:00Z');
$source=['status'=>'selected','source'=>['meeting_id'=>7912],'meeting'=>[
    'id'=>7912,'title'=>'Delivery review','start_at_utc'=>'2030-11-07 00:15:00','end_at_utc'=>'2030-11-07 01:00:00','timezone'=>'America/Phoenix'],
    'findings'=>[
        ['ref'=>'meeting:7912','label'=>'Delivery review','kind'=>'meeting','revision'=>'a'],
        ['ref'=>'agenda:7942','label'=>'Assign an owner for the revised delivery plan','kind'=>'follow_up','revision'=>'b'],
        ['ref'=>'agenda:7943','label'=>'Review the revised dates','kind'=>'agenda','revision'=>'c'],
        ['ref'=>'transcript:7921:segment:7931','label'=>'The revised delivery plan needs an owner by Friday.','kind'=>'transcript','revision'=>'d'],
    ]];
$parse=static fn(string $q,array $s=[]):array=>vp3_meeting_command_parse_v1($q,$s?:$source,['id'=>1],$now);
foreach ([
    'Turn that follow-up into a task',
    'Please turn that meeting commitment into a task.',
    'Create a task from that meeting finding',
    'Remind me 15 minutes before the meeting',
    'Add updated dates to the meeting agenda',
    'Move the meeting to 2030-11-09 at 10:30',
    'Cancel the meeting',
    'Confirm meeting action 12',
] as $q) $check(vp3_meeting_command_intent_v1($q),'recognizes direct command: '.$q);
foreach ([
    'Search YouTube for a meeting song',
    'Create a task to wash the car',
    'Remind me tomorrow to attend a meeting',
    'How do I cancel a meeting?',
    'What is on my meeting agenda?',
    'I think we should move the meeting',
    'Add buy milk to my prep list',
    'Summarize the meeting transcript',
] as $q) $check(!vp3_meeting_command_intent_v1($q),'does not intercept unrelated turn: '.$q);
$task=$parse('Turn that follow-up into a task');
$check($task===['status'=>'ready','request'=>['adapter'=>'task.create','finding_ref'=>'agenda:7942','arguments'=>['title'=>'Assign an owner for the revised delivery plan']]],
    'single actionable follow-up resolves exactly among ordinary agenda and transcript evidence');
$canonical=$source;$canonical['findings'][1]['kind']='agenda';$canonical['findings'][1]['actionable']=true;
$check($parse('Turn that follow-up into a task',$canonical)['request']['finding_ref']==='agenda:7942',
    'canonical actionable agenda flag selects follow-up without treating transcript as a second candidate');
$check($parse('Turn meeting finding agenda:7943 into a task')['request']['finding_ref']==='agenda:7943','explicit exact finding overrides general follow-up default');
$check($parse('Turn meeting finding "The revised delivery plan needs an owner by Friday." into a task')['request']['finding_ref']==='transcript:7921:segment:7931',
    'quoted complete finding resolves exact authorized transcript reference');
$check($parse('Turn meeting finding agenda:9999 into a task')['status']==='clarify','unknown exact finding is not redirected to another item');
$check($parse('Turn meeting finding "revised delivery plan" into a task')['status']==='clarify',
    'partial quoted text cannot silently select a different complete finding');
$long=$canonical;$long['findings'][1]['label']=str_repeat('é',200);
$longTask=$parse('Turn that follow-up into a task',$long);
$check($longTask['status']==='ready'&&$longTask['request']['finding_ref']==='agenda:7942'
    &&$longTask['request']['arguments']['title']===str_repeat('é',179).'…'
    &&mb_strlen($longTask['request']['arguments']['title'])===180,
    'long verified finding proposes a visible canonical-length title while retaining the exact source reference');
$long['findings'][1]['label']=str_repeat('é',180);
$check($parse('Turn that follow-up into a task',$long)['request']['arguments']['title']===$long['findings'][1]['label'],
    'exactly 180 characters remain unchanged');
$ambiguous=$source;$ambiguous['findings'][]=['ref'=>'agenda:7944','label'=>'Verify the delivery address','kind'=>'follow_up','revision'=>'e'];
$r=$parse('Turn that follow-up into a task',$ambiguous);
$check($r['status']==='clarify'&&count($r['choices'])===2,'multiple actionable findings require exact selection');
$missing=$source;$missing['findings']=[];
$check($parse('Turn that follow-up into a task',$missing)['status']==='clarify','missing findings cannot generate a fabricated task');
$r=$parse('Remind me 15 minutes before the meeting');
$check($r['request']['adapter']==='reminder.create'&&$r['request']['finding_ref']==='meeting:7912'
    &&$r['request']['arguments']['schedule']['start_local']==='2030-11-06T17:00:00'
    &&$r['request']['arguments']['schedule']['timezone']==='America/Phoenix','reminder subtracts verified UTC start and retains source timezone');
$check($parse('Remind me before the meeting')['status']==='clarify','missing reminder interval is clarified');
$check($parse('Remind me 1 day before the meeting')['status']==='clarify','already passed reminder time is rejected');
$badZone=$source;$badZone['meeting']['timezone']='GMT-7';
$check($parse('Remind me 15 minutes before the meeting',$badZone)['status']==='clarify','invalid source timezone never falls back to a guessed zone');
$check($parse('Add updated dates to the meeting agenda')['request']===['action'=>'agenda_add','meeting_id'=>7912,'item_text'=>'updated dates','item_type'=>'discussion','priority'=>'normal'],
    'explicit agenda text is preserved as a canonical agenda request');
$check($parse('Add that to the agenda')['request']['item_text']==='Assign an owner for the revised delivery plan','contextual agenda item uses exact single follow-up');
$check($parse('Add that to the agenda',$ambiguous)['status']==='clarify','ambiguous contextual agenda item is not guessed');
$check(vp3_meeting_command_selector_v1('Move meeting #7912 to 2030-11-09 at 10:00')===['meeting_id'=>7912],'explicit meeting number produces exact selector');
$check(vp3_meeting_command_selector_v1('Add updated dates to meeting "Delivery review" agenda')===['title'=>'Delivery review'],'quoted meeting name produces exact selector');
$check(vp3_meeting_command_selector_v1('Move the meeting to 2030-11-09 at 10:00')===[],'ordinary command does not fabricate a meeting selector');
$r=$parse('Move the meeting to 2030-11-09 at 10:30');
$check($r['request']===['action'=>'reschedule','meeting_id'=>7912,'date'=>'2030-11-09','start_time'=>'10:30','end_date'=>'2030-11-09','end_time'=>'11:15','timezone'=>'America/Phoenix'],
    'reschedule preserves verified 45-minute duration and zone');
$check($parse('Reschedule the meeting to 2030-11-09 at 2:30 pm UTC')['request']['start_time']==='14:30','explicit twelve-hour time and UTC are honored');
$check($parse('Move the meeting to tomorrow at 10:30')['request']['date']==='2030-11-07','tomorrow resolves from controlled now in meeting zone');
$check($parse('Move the meeting to Friday at 10:30')['status']==='clarify','weekday without explicit date is clarified');
$check($parse('Move the meeting to 2030-02-30 at 10:30')['status']==='clarify','invalid calendar date is rejected rather than rolled forward');
$check($parse('Move the meeting to 2030-11-09 at 24:30')['status']==='clarify','invalid clock time is rejected');
$check($parse('Move the meeting to 2030-11-09 at 13:30 pm')['status']==='clarify','invalid twelve-hour clock is rejected');
$check($parse('Move the meeting to 2030-11-09 at 10:30 and notify attendees')['status']==='clarify','outbound notice request cannot silently become a local-only edit');
$check($parse('Cancel the meeting and email attendees')['status']==='clarify','cancellation with email is not silently stripped');
$check($parse('Cancel the meeting')['request']===['action'=>'cancel','meeting_id'=>7912],'plain cancellation yields exact meeting identity');
$check($parse('Confirm meeting action 12',['status'=>'unavailable'])===['status'=>'confirm','plan_id'=>12],'confirmation selects reviewed plan without a new meeting source');
$check($parse('Cancel meeting action 12',['status'=>'unavailable'])===['status'=>'cancel','plan_id'=>12],'review cancellation is distinct from canceling meeting');
$check($parse('hello')['status']==='unhandled','ordinary conversation remains unhandled');
$check($parse('Cancel the meeting',['status'=>'unavailable'])['status']==='clarify','unavailable meeting source cannot yield a mutation');
$dst=$source;$dst['meeting']['timezone']='America/New_York';
$check($parse('Move the meeting to 2031-03-09 at 02:30',$dst)['status']==='clarify','nonexistent spring-forward wall time is rejected');
$check($parse('Move the meeting to 2031-11-02 at 01:30',$dst)['status']==='clarify','ambiguous fall-back wall time requires unambiguous time');
$overnight=$parse('Move the meeting to 2030-11-09 at 23:45');
$check($overnight['request']['end_date']==='2030-11-10'&&$overnight['request']['end_time']==='00:30','reschedule preserves duration across midnight');
echo 'Meeting command parser: '.$count.' cases passed.'.PHP_EOL;
