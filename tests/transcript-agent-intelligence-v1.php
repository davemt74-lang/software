<?php
declare(strict_types=1);
class TranscriptEnginePDO extends PDO {public function prepare(string $sql,array $options=[]):PDOStatement|false{$sql=str_replace('NOW()','CURRENT_TIMESTAMP',$sql);$sql=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$sql);$sql=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$sql);return parent::prepare($sql,$options);}}
$GLOBALS['transcript_test_pdo']=TranscriptEnginePDO::class;
require __DIR__.'/transcript-agent-v1.php';$pdo->exec('UPDATE users SET is_active=1');
function agent_brain_schema_ready(){return false;}
$pdo->exec('CREATE TABLE artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash TEXT,source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider TEXT,model TEXT,generated_at TEXT,PRIMARY KEY(session_id,page_number));CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,source_word_count INTEGER,page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider TEXT,model TEXT,generated_at TEXT)');
$map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,1));$hash=$map['source_hash'];$date=gmdate('c');
$analysis=['modules'=>['basic'=>['source_hash'=>$hash,'generated_at'=>$date,'result'=>['summary'=>'AI inferred summary','key_findings'=>[['item_id'=>'f1','text'=>'Unverified interpretation','review_state'=>'unreviewed','evidence_refs'=>[['page'=>1,'label'=>'Page 1']]],['item_id'=>'f2','text'=>'Accepted interpretation','review_state'=>'accepted']]]],'research_brief'=>['source_hash'=>$hash,'generated_at'=>$date,'result'=>['overview'=>[['summary'=>'Public research available']],'claims'=>[['item_id'=>'c1','claim'=>'A public claim','verification'=>'unresolved','review_state'=>'unreviewed']]]]]];
$research=['text'=>'Public research, not private user facts','sources'=>[['title'=>'Primary source','url'=>'https://example.org/source'],['title'=>'Unsafe','url'=>'javascript:alert(1)']]];
$q=$pdo->prepare("INSERT INTO artist_transcript_master_analysis_v237 VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)");$q->execute([1,$hash,$map['total_words'],$map['page_count'],0,json_encode($analysis),json_encode($research),$date]);
$before=$checks;$r=vp3_transcript_intelligence_v1($pdo,$u,$p,1);ck($r['fresh']&&str_contains($r['text'],'AI inferred summary'),'saved analysis is fetched from canonical master and module data');
ck($r['review_counts']['accepted']===1&&$r['review_counts']['unreviewed']===2&&str_contains($r['text'],'unreviewed'),'review state remains distinct from generated findings');
$r=vp3_transcript_intelligence_v1($pdo,$u,$p,1,'research');ck($r['fresh']&&count($r['sources'])===1&&str_contains($r['text'],'Public research'),'research reads stored results with a validated source URL list');
$reply=vp3_transcript_intelligence_chat_v1($pdo,$u,$p,['operation'=>'read_research'],1);ck($reply['tool_receipt']['status']==='read_complete'&&!$reply['tool_receipt']['changes_applied']&&count($reply['sources'])===2,'research lookup has read evidence and does not launch a web search');
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='New edited transcript' WHERE id=1");$r=vp3_transcript_intelligence_v1($pdo,$u,$p,1);ck(!$r['fresh']&&$r['text']==='','edited transcript cannot be answered from stale AI analysis');
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='Changed price' WHERE id=1");
$analysis['modules']['research_brief']['generated_at']='2020-01-01';$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=1')->execute([json_encode($analysis)]);$r=vp3_transcript_intelligence_v1($pdo,$u,$p,1,'research');ck(!$r['fresh']&&$r['text']===''&&$r['fresh_reason']==='research_age','expired public research is marked stale rather than presented as current');
$analysis['modules']['research_brief']['generated_at']=$date;$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=1')->execute([json_encode($analysis)]);
$calls=0;$r=vp3_transcript_analyze_v1($pdo,$u,$p,1,['basic'],'Check pricing',true,function($pdo,$u,$id,$mode,$apps,$workflow)use(&$calls){$calls++;ck($mode==='manual'&&$workflow['web_research']&&$workflow['context_mode']==='transcript'&&in_array('research_brief',$apps,true),'explicit research uses the existing engine contract and separates private context');return ['executed_apps'=>$apps,'plugin_errors'=>[]];});ck($calls===1&&$r['executed_apps']===['basic','research_brief'],'one requested run invokes its execution path once');
try{vp3_transcript_analyze_v1($pdo,$u,$p,1,['shell'],'',false);throw new LogicException('bad apps accepted');}catch(RuntimeException $e){ck(true,'unsupported analysis modules cannot select arbitrary executors');}
try{vp3_transcript_analyze_v1($pdo,$u,$p,3,['basic'],'',false,function(){throw new LogicException('foreign executed');});throw new LogicException('foreign accepted');}catch(RuntimeException $e){ck($e->getCode()===404,'analysis execution enforces the same source ownership boundary');}
// Run the actual existing deterministic analysis engine and persistence layer.
$r=vp3_transcript_analyze_v1($pdo,$u,$p,1,['stats'],'',false);ck(in_array('stats',$r['executed_apps'],true)&&!$r['errors'],'actual canonical analysis engine runs and persists its stats module');
$r=vp3_transcript_intelligence_v1($pdo,$u,$p,1,'analysis','stats');ck(str_contains($r['text'],'total words')&&$r['fresh'],'Chat immediately reads the result persisted by the existing engine');

$applied = array_fill_keys(array_keys(transcription_app_registry_v307()), [
    'source_hash' => $hash, 'source_word_count' => $map['total_words'], 'generated_at' => $date, 'result' => [],
]);
unset($applied['entities']);
$applied['stats']['result'] = ['total_words' => $map['total_words']];
$applied['actions']['result']['items'] = [
    ['item_id' => 'a-open', 'action' => 'Ask about the revised quote', 'review_state' => 'accepted', 'evidence' => 'Page 1'],
    ['item_id' => 'a-rejected', 'action' => 'Reject this suggestion', 'review_state' => 'rejected'],
    ['item_id' => 'a-tracked', 'action' => 'Already assigned as a task', 'review_state' => 'accepted', 'actions' => [
        'agent_task' => ['record_id' => 42, 'performed_at' => $date, 'label' => 'INTERNAL_RECEIPT_TEXT'],
    ]],
    ['item_id' => 'a-copied', 'action' => 'Still open after saving a note', 'review_state' => 'accepted', 'actions' => [
        'agent_brain' => ['record_id' => 43, 'performed_at' => $date],
    ]],
    ['item_id' => 'a-invalid', 'action' => 'Still open despite invalid receipt', 'actions' => [
        'agent_task' => ['record_id' => '44', 'performed_at' => $date],
    ]],
];
for ($n = 1; $n <= 25; $n++) $applied['actions']['result']['items'][] = ['item_id' => 'a-extra-'.$n, 'action' => 'Review quote line '.$n];
$applied['followup']['result']['items'] = [
    ['item_id' => 'f-done', 'follow_up' => 'Closed follow-up', 'status' => 'completed'],
    ['item_id' => 'f-open', 'follow_up' => 'Check the supplier response', 'status' => 'waiting'],
];
$applied['qa']['result']['unanswered'] = [['item_id' => 'q-open', 'question' => 'Is tax included?', 'follow_up' => 'Ask whether tax is included']];
$applied['opportunities']['result']['items'] = [['item_id' => 'o-research', 'opportunity' => 'Compare supplier pricing', 'type' => 'research', 'validation_step' => 'Compare public supplier prices']];
$applied['research_brief']['result'] = [
    'unresolved' => [['item_id' => 'r-open', 'item' => 'Unconfirmed price', 'next_check' => 'Check the public price sheet', 'evidence_refs' => [['page' => 1], ['page' => 999]]]],
    'claims' => [['item_id' => 'r-done', 'claim' => 'Verified public claim', 'verification' => 'verified']],
];
$applied['responses']['source_hash'] = str_repeat('a', 64);
$applied['responses']['result']['items'] = [['item_id' => 's-stale', 'response' => 'Stale response suggestion']];
$applied['action_plan']['result']['actions'] = [['item_id' => 'p-open', 'action' => 'Confirm the action plan', 'status' => 'planned']];
$fixture = ['modules' => $applied];
$modules = transcription_app_modules_v306($fixture);
$fixture['modules']['action_plan']['context_hash'] = transcription_output_input_hash_v306(transcription_output_reviewed_input_v306($modules, $hash), $hash);
$stored = json_encode($fixture, JSON_THROW_ON_ERROR);
$update = $pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=1');
$update->execute([$stored]);

$read = vp3_transcript_intelligence_v1($pdo, $u, $p, 1);
$attention = $read['plugin_attention'];
$inventory = array_column($attention['modules'], null, 'plugin');
$suggestions = array_column($attention['suggestions'], null, 'item_id');
ck(array_keys($inventory) === array_keys($applied) && $inventory['stats']['suggestion_count'] === 0,
    'plugin inventory includes every applied report, including Stats and empty reports, without unapplied entries');
ck($inventory['actions']['coverage']['source_words'] === $map['total_words'] && $inventory['actions']['coverage']['transcript_pages'] === $map['page_count'],
    'inventory preserves the saved module word coverage and current transcript page count');
ck($inventory['responses']['state'] === 'stale' && $inventory['responses']['fresh_reason'] === 'transcript_changed' && !isset($suggestions['s-stale']),
    'stale applied plugin stays in the inventory without offering stale suggestions');
ck($inventory['actions']['review_counts']['rejected'] === 1 && $inventory['actions']['completion_counts']['tracked'] === 1
    && $inventory['followup']['completion_counts']['completed'] === 1
    && !isset($suggestions['a-rejected']) && !isset($suggestions['a-tracked']) && !isset($suggestions['f-done']),
    'rejected, already tracked and completed findings remain distinct and do not become new suggestions');
ck(isset($suggestions['a-copied'], $suggestions['a-invalid']) && $suggestions['a-copied']['completion_state'] === 'open',
    'copying a finding to Brain and malformed action receipts do not close an open follow-up');
ck($suggestions['a-open']['review_state'] === 'accepted' && $suggestions['a-open']['source_hash'] === $hash
    && str_contains($suggestions['a-open']['source_url'], 'session=1&plugin=actions&section=items&item=a-open&page=1'),
    'suggestion links carry the exact saved finding and its supported transcript evidence page');
ck($suggestions['q-open']['text'] === 'Ask whether tax is included' && $suggestions['o-research']['kind'] === 'research'
    && $suggestions['r-open']['evidence_refs'] === [['page' => 1, 'label' => 'Page 1']] && !isset($suggestions['r-done']),
    'explicit follow-up and research fields are used and evidence links stay inside the transcript');
ck($attention['truncated'] && count($attention['suggestions']) === 24 && array_sum($attention['suggestion_counts']) > 24
    && $attention['suggestion_counts']['research'] === 2 && isset($suggestions['p-open']),
    'bounded suggestions preserve full counts and give each relevant plugin a chance to contribute');
ck(str_contains($read['text'], 'Ask about the revised quote') && str_contains($read['text'], 'Confirm the action plan')
    && !str_contains($read['text'], 'INTERNAL_RECEIPT_TEXT'), 'saved report text retains Actions and Action Plan sections without exposing action receipts');
$discussion = vp3_transcript_intelligence_v1($pdo, $u, $p, 1, 'discussion');
$reports = array_column($discussion['plugin_attention']['modules'], 'report_excerpt', 'plugin');
ck($discussion['text'] === '' && str_contains($reports['stats'], 'Total words') && str_contains($reports['action_plan'], 'Confirm the action plan')
    && str_contains($reports['followup'], 'Check the supplier response') && $reports['responses'] === ''
    && !str_contains(implode(' ', $reports), 'Reject this suggestion') && !str_contains(implode(' ', $reports), 'Closed follow-up'),
    'discussion has bounded current report excerpts for each applied plugin without stale, rejected or completed follow-up prose');
ck($pdo->query('SELECT analysis_json FROM artist_transcript_master_analysis_v237 WHERE session_id=1')->fetchColumn() === $stored,
    'reading applied plugins and suggestions never mutates saved analysis or reviews');

$fixture['modules']['research_brief']['generated_at'] = '2020-01-01';
$update->execute([json_encode($fixture, JSON_THROW_ON_ERROR)]);
$attention = vp3_transcript_intelligence_v1($pdo, $u, $p, 1)['plugin_attention'];
$inventory = array_column($attention['modules'], null, 'plugin');
ck($inventory['research_brief']['fresh_reason'] === 'research_age' && $attention['suggestion_counts']['research'] === 1,
    'expired research brief suggestions are withheld while another current plugin can suggest research');
$pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='Edited after plugin analysis' WHERE id=1");
$attention = vp3_transcript_intelligence_v1($pdo, $u, $p, 1)['plugin_attention'];
ck(count($attention['modules']) === count($applied) && !array_filter($attention['modules'], static fn($row) => $row['state'] !== 'stale')
    && $attention['suggestions'] === [], 'a changed transcript keeps the applied plugin inventory and withholds all outdated suggestions');
echo 'TRANSCRIPT_AGENT_S2=PASS ('.($checks-$before)." cases; actual saved intelligence/status and deterministic engine, fixture research invocation)\n";
