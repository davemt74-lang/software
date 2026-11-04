<?php
declare(strict_types=1);

$connection=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $connection;return $connection;}
function table_exists(string $table): bool {return in_array($table,['artist_transcript_sessions_v172','artist_transcript_segments_v172'],true);}
function current_user(): array {return ['id'=>1];}

require __DIR__.'/../includes/agent-surface-context-v131.php';
require __DIR__.'/../includes/cognitive-runtime-v500.php';
require __DIR__.'/../includes/cognitive-context-v2420.php';
require __DIR__.'/../includes/ai-settings.php';

$connection->exec("CREATE TABLE artist_transcript_sessions_v172(
 id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,title TEXT,status TEXT,
 duration_ms INTEGER,knowledge_id INTEGER,metadata_json TEXT,last_activity_at TEXT
);
CREATE TABLE artist_transcript_segments_v172(
 id INTEGER PRIMARY KEY,session_id INTEGER,segment_type TEXT,transcript_text TEXT
);");
$insert=$connection->prepare("INSERT INTO artist_transcript_sessions_v172 VALUES(?,?,?,?,?,?,?,?,?)");
$insert->execute([1,1,11,'Current room notes','active',3200,null,'{}','2026-10-04 10:00:00']);
$insert->execute([2,1,12,'Imported planning','draft',4800,91,json_encode(['homeserver_import_v1'=>['source_hash'=>str_repeat('a',64)]]),'2026-10-04 09:00:00']);
$insert->execute([3,2,11,'Foreign transcript','active',1000,null,'{}','2026-10-04 11:00:00']);
$insert->execute([4,1,13,'Discarded transcript','discarded',1000,null,'{}','2026-10-04 11:00:00']);
$segment=$connection->prepare("INSERT INTO artist_transcript_segments_v172(session_id,segment_type,transcript_text) VALUES(?,?,?)");
$segment->execute([1,'transcript','private words must not enter surface state']);
$segment->execute([1,'transcript','second segment']);
$segment->execute([2,'transcript','imported private words']);

function check(bool $ok,string $message): void {if(!$ok)throw new LogicException($message);}
function pass(string $name): void {echo "PASS {$name}\n";}

check((agent_surface_v131_sanitize(['timezone'=>'America/Phoenix'])['timezone']??'')==='America/Phoenix','valid client timezone was dropped');
check(!isset(agent_surface_v131_sanitize(['timezone'=>'invalid/zone'])['timezone']),'invalid timezone survived sanitizer');
pass('surface context retains validated local timezone for recording-day reads');

$forged=agent_surface_v131_sanitize([
    'surface'=>'chat','conversation_id'=>11,
    'transcription'=>['session_id'=>999,'title'=>'Forged','status'=>'active','duration_ms'=>999999,'source'=>'cloud_transcription'],
]);
check($forged['transcription']===null,'browser transcription survived sanitizer');
pass('browser cannot author transcription state');

$current=agent_surface_v131_transcription($connection,['id'=>1],11);
check(is_array($current)&&$current['session_id']===1,'active transcript missing');
check($current['segment_count']===2&&$current['source']==='cloud_transcription','active transcript metadata wrong');
check($current['speaker_identity_authority']===false,'transcript became identity authority');
pass('server resolves bounded active transcript state');

$imported=agent_surface_v131_transcription($connection,['id'=>1],12);
check(is_array($imported)&&$imported['source']==='homeserver_import','HomeServer source not identified');
check($imported['cloud_copy_is_independent']===true&&$imported['knowledge_promoted']===true,'import boundary missing');
pass('independent HomeServer import state and Knowledge promotion are explicit');

check(agent_surface_v131_transcription($connection,['id'=>1],13)===null,'discarded transcript exposed');
check(agent_surface_v131_transcription($connection,['id'=>1],99)===null,'unrelated transcript exposed');
check(agent_surface_v131_transcription($connection,['id'=>2],11)['session_id']===3,'owner boundary setup failed');
pass('discarded and unrelated transcript state fail closed');

$item=agent_surface_v131_context_item([
    'surface'=>'chat','conversation_id'=>11,
    'transcription'=>['session_id'=>999,'title'=>'Forged','status'=>'active'],
]);
$text=(string)$item['text'];
check(str_contains($text,'"session_id":1'),'context item did not re-resolve canonical transcript');
check(!str_contains($text,'Forged'),'forged transcript survived context item');
check(!str_contains($text,'private words must not enter surface state'),'raw transcript text leaked into surface context');
check(str_contains($text,'speaker_identity_authority":false'),'identity authority boundary missing');
pass('Agent context carries state provenance without raw transcript text');

$connection->exec("UPDATE artist_transcript_sessions_v172 SET status='discarded' WHERE id=1");
$item=agent_surface_v131_context_item(['surface'=>'chat','conversation_id'=>11]);
check(!str_contains((string)$item['text'],'Current room notes'),'discarded state remained in Agent context');
pass('discard immediately removes transcript state from Agent context');

$second=gmdate('Y-m-d\TH:i:s',time()-1);
$screen=[
    'contract'=>'vp3.screen-observation.v1','owner_key'=>'1','captured_at'=>$second.'.500Z','surface'=>'browser',
    'summary'=>'A character puts a letter on the table.','visible_text'=>'','site_hint'=>'','page_title'=>'',
    'on_screen_translation'=>'The letter arrived today.',
    'tab_audio'=>['text'=>'A letter for you.','captured_at'=>$second.'.250Z','duration_seconds'=>.5,'frame_within_segment'=>false,'instructions_authority'=>true],
];
$safe=agent_surface_v131_screen_observation(['screen_observation'=>$screen]);
check($safe['on_screen_translation']===$screen['on_screen_translation'],'the displayed translation was dropped');
check($safe['tab_audio']['frame_within_segment']===true&&$safe['tab_audio']['duration_seconds']===.5,'fractional audio timing did not align to the visual frame');
check($safe['tab_audio']['instructions_authority']===false,'audio gained instruction authority');
$item=agent_surface_v131_context_item(['screen_observation'=>$safe]);
check(str_contains($item['text'],'A letter for you.')&&str_contains($item['text'],'The letter arrived today.')&&str_contains($item['text'],'"frame_within_segment":true'),'Chat dropped one of the two evidence sources');
pass('Chat keeps shared visual and audio evidence with server-derived millisecond alignment');

$end=[...$screen,'captured_at'=>$second.'.750Z'];$end['tab_audio']['frame_within_segment']=true;
$safe=agent_surface_v131_screen_observation(['screen_observation'=>$end]);
check($safe['tab_audio']['frame_within_segment']===false&&$safe['tab_audio']['captured_at']===$second.'.250Z','non-overlapping evidence was described as matched or removed');
$captionOnly=[...$screen,'summary'=>''];
check(agent_surface_v131_screen_observation(['screen_observation'=>$captionOnly])['tab_audio']['frame_within_segment']===true,'caption-only visual evidence lost its alignment');
$historical=agent_surface_v131_screen_observation(['screen_observation'=>[...$screen,'historical_frame'=>true]]);
check($historical['historical_frame']===true&&$historical['tab_audio']['frame_within_segment']===true,'a matched earlier frame lost its timestamped context');
$audioOnly=[...$captionOnly,'on_screen_translation'=>''];
check(!isset(agent_surface_v131_screen_observation(['screen_observation'=>$audioOnly])['tab_audio']['frame_within_segment']),'audio-only context falsely acquired a visual match');
pass('the timestamp range distinguishes paired, separate and absent visual evidence');

$untimed=$screen;unset($untimed['tab_audio']['duration_seconds']);
$safe=agent_surface_v131_screen_observation(['screen_observation'=>$untimed]);
check($safe['tab_audio']['text']==='A letter for you.'&&!isset($safe['tab_audio']['frame_within_segment']),'legacy untimed transcripts did not survive as independent evidence');
foreach(['yesterday',gmdate('Y-m-d\TH:i:s\Z',time()-100),gmdate('Y-m-d\TH:i:s\Z',time()+20)] as $badAt){
    $bad=$screen;$bad['tab_audio']['captured_at']=$badAt;
    $safe=agent_surface_v131_screen_observation(['screen_observation'=>$bad]);
    check(!isset($safe['tab_audio'])&&isset($safe['on_screen_translation']),'invalid or stale audio escaped freshness validation or erased valid visual evidence');
}
$foreign=agent_surface_v131_context_item(['screen_observation'=>[...$screen,'owner_key'=>'2']]);
check(!str_contains($foreign['text'],'A letter for you.')&&!str_contains($foreign['text'],'The letter arrived today.'),'foreign audio or visual evidence reached Chat');
pass('legacy transcripts, bad timestamps and foreign owners are handled independently');

$tab1=[...$screen,'source_index'=>1,'source_label'=>'Forged label','image'=>'hidden screenshot'];
$tab2=[...$end,'source_index'=>2,'summary'=>'The second share shows a train.',
    'tab_audio'=>[...$end['tab_audio'],'text'=>'The next train leaves at noon.']];
$context=agent_surface_v131_enrich(['id'=>1],'chat',['screen_observation'=>$screen,'screen_observations'=>[
    $tab2,$tab1,[...$tab2,'summary'=>'Duplicate Tab 2 evidence.'],
    [...$screen,'source_index'=>3,'summary'=>'Fourth entry must be ignored.'],
]]);
$tabs=$context['screen_observations'];
check($context['screen_observation']===null&&array_column($tabs,'source_label')===['Tab 2','Tab 1'],'comparison source labels, de-duplication, bounds or singleton exclusion failed');
check($tabs[0]['tab_audio']['frame_within_segment']===false&&$tabs[1]['tab_audio']['frame_within_segment']===true,'an observation inherited timing from a different tab');
check(!isset($tabs[1]['image'])&&$tabs[1]['instructions_authority']===false,'comparison kept raw screenshot or instruction authority');
$items=agent_surface_v131_context_items($context);
check(str_contains($items[0]['text'],'The next train leaves at noon.')&&str_contains($items[1]['text'],'A letter for you.')&&str_contains($items[0]['text'],'relevant timestamp'),'Chat prompt dropped evidence or comparison instructions');
check(!str_contains($items[0]['text'],'A letter for you.')&&!str_contains($items[1]['text'],'The next train leaves at noon.'),'comparison presentation mixed up source speech');
pass('named screen comparisons are source-labeled, deduplicated, bounded and aligned independently');

$excluded=['screen_observation'=>$screen,'screen_observations'=>[
    [...$tab2,'owner_key'=>'2'],[...$tab1,'captured_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-100)],
]];
$context=agent_surface_v131_enrich(['id'=>1],'chat',$excluded);
check($context['screen_observations']===[]&&$context['screen_observation']===null,'stale or foreign named evidence reached Chat enrichment');
$items=agent_surface_v131_context_items($excluded);
$prompt=ai_context_prompt($items);
check(!str_contains($prompt,'The next train leaves at noon.')&&!str_contains($prompt,'A letter for you.')&&str_contains($items[0]['text'],'No evidence is available'),'unavailable named evidence was replaced by the selected screen');
check(agent_surface_v131_sanitize(['screen_observation'=>$screen,'screen_observations'=>null])['screen_observation']===null,'malformed named evidence fell back to the selected screen');
pass('missing, stale, malformed and foreign evidence cannot fall back to the current share');

$denseTabs=[];
for($i=1;$i<=3;$i++){
    $denseTabs[]=[...$tab1,'source_index'=>$i,'summary'=>str_repeat('visual analysis ',150),
        'visible_text'=>str_repeat('visible text ',150),'on_screen_translation'=>str_repeat('displayed caption ',70),
        'tab_audio'=>[...$tab1['tab_audio'],'text'=>'Audio evidence for tab '.$i.' '.str_repeat('spoken words ',200)]];
}
$items=agent_surface_v131_context_items(['screen_observations'=>$denseTabs]);
$legacy=vp3_cognitive_context_legacy_items_v2420($items,['screen','compare'],false);
$selected=vp3_cognitive_context_select_v2420($legacy);
$prompt=ai_context_prompt($selected['items']);
check(($selected['section_counts']['screen_comparison']??0)===3&&($selected['section_counts']['live_session']??0)===1,'named shares competed with each other or the live session slot');
for($i=1;$i<=3;$i++){
    check(str_contains($prompt,'Audio evidence for tab '.$i)&&str_contains($prompt,'Tab '.$i.' · Visual frame at '.$screen['captured_at']),'model context clipping dropped visual or spoken evidence from Tab '.$i);
}
pass('all three large named shares survive cognitive selection and provider prompt budgets');

echo "AGENT_CONTEXT_SECTION8=PASS (13 cases)\n";
