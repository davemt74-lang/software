<?php
declare(strict_types=1);
require __DIR__.'/../includes/chat-browser-research-v1.php';
// Production R3 functions with durable SQLite rows and controlled current-authority fixtures.
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE chat_browser_research_requests_v1(id TEXT,owner_user_id INTEGER,agent_namespace TEXT,status TEXT,mission_id TEXT,question TEXT,urls_json TEXT,runtime_id TEXT,created_at TEXT);CREATE TABLE missions(public_id TEXT,owner_user_id INTEGER,status TEXT,report_public_id TEXT,project_id INTEGER,saved_at TEXT,payload TEXT);CREATE TABLE reports(public_id TEXT,id INTEGER,project_id INTEGER,deleted_at TEXT,updated_at TEXT);CREATE TABLE projects(id INTEGER,public_id TEXT,allowed INTEGER);");
$allowed=true;$chat=true;$domains=['one.example','two.example'];$uid=7;$user=['id'=>$uid];
function has_permission(string $p,array $u):bool{global $chat;return $chat;}
function table_exists(string $name):bool{global $pdo;$q=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return (bool)$q->fetchColumn();}
function vp3_browser_runtime_row_v2200(PDO $p,int $u,string $n,string $i):?array{global $allowed;return $allowed&&$u===7?['status'=>'active','allowed_actions_json'=>'["browser_research"]']:null;}
function vp3_browser_delegation_json_array_v2190(string $j):array{return json_decode($j,true);}
function vp3_browser_multisite_row_v2220(PDO $p,array $r):array{return ['id'=>1];}
function vp3_browser_research_allowed_domains_v2230(PDO $p,array $r):array{global $domains;return $domains;}
function vp3_browser_research_mission_row_v2230(PDO $p,int $u,string $id):?array{$q=$p->prepare('SELECT * FROM missions WHERE public_id=? AND owner_user_id=?');$q->execute([$id,$u]);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
function vp3_browser_research_public_v2230(PDO $p,array $u,string $id):array{$m=vp3_browser_research_mission_row_v2230($p,$u['id'],$id);return json_decode($m['payload'],true);}
function vp3_research_report_row_v2060(PDO $p,string $id):?array{$q=$p->prepare('SELECT * FROM reports WHERE public_id=? AND deleted_at IS NULL');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
function vp3_research_project_row_by_id_v2060(PDO $p,int $id):?array{$q=$p->prepare('SELECT * FROM projects WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
function vp3_research_project_role_v2060(PDO $p,array $r,int $u):string{return $r['allowed']&&$u===7?'viewer':'';}
function vp3_research_role_at_least_v2060(string $r,string $min):bool{return $r==='viewer';}
function vp3_research_report_public_v2060(PDO $p,array $r,int $u):array{return ['id'=>$r['public_id'],'url'=>'/research-report.php?report='.$r['public_id']];}
function check(bool $b,string $m):void{if(!$b)throw new RuntimeException($m);}
function rejected(callable $fn):void{try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected rejection');}
$url='https://one.example/about';$fingerprint=hash('sha256',$url);
$mission=['question'=>'Studio opening hours','status'=>'completed','claims'=>[
 ['id'=>'claim1','key'=>'hours','statement'=>'Studio opens','value'=>'9 am','state'=>'single_source','evidence'=>[['domain'=>'one.example','excerpt'=>'Opens at nine','directness'=>'direct','fingerprint'=>$fingerprint,'source_id'=>'source1','source_version_id'=>'version1','as_of'=>'2026-10-01']]],
 ['id'=>'claim2','key'=>'weekends','statement'=>'Weekend service inferred','value'=>'Possible','state'=>'single_source','evidence'=>[['domain'=>'one.example','excerpt'=>'Weekday service listed','directness'=>'inferred','fingerprint'=>$fingerprint,'source_id'=>'source1','source_version_id'=>'version1','as_of'=>'2026-10-01']]],
 ['id'=>'unsupported','key'=>'bad','statement'=>'Unsupported','value'=>'Guess','evidence'=>[]]],'gaps'=>['Holiday schedule unknown']];
$a=chat_research_answer_r3($mission,[['url'=>$url]]);check(count($a['claims'])===2&&count($a['citations'])===1,'Only cited claims; deduplicate receipt labels');check($a['claims'][0]['kind']==='observed'&&$a['claims'][1]['kind']==='inference','Inference remains distinct');check($a['citations'][0]['url']===$url&&$a['citations'][0]['source_version_id']==='version1','Exact source and pinned version');check(str_contains($a['text'],'date')&&str_contains($a['text'],'not independently proven'),'Dates and uncertainty');
$pdo->exec("INSERT INTO projects VALUES(1,'project1',1)");
for($i=1;$i<=3;$i++){
 $id=sprintf('%08d-1111-4111-8111-111111111111',$i);$m=$mission;if($i===2){$m['claims'][0]['value']='10 am';$m['claims'][0]['state']='conflicted';$m['claims']=array_slice($m['claims'],0,1);}
 $pdo->prepare('INSERT INTO missions VALUES(?,?,?,?,?,?,?)')->execute(['mission'.$i,7,'completed','report'.$i,1,'2026-10-0'.$i,json_encode($m)]);
 $pdo->prepare('INSERT INTO reports VALUES(?,?,?,?,?)')->execute(['report'.$i,$i,1,null,'2026-10-0'.$i]);
 $pdo->prepare('INSERT INTO chat_browser_research_requests_v1 VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$i===3?8:7,'system','complete','mission'.$i,'Studio opening hours',json_encode([['url'=>$url,'domain'=>'one.example']]),'runtime','2026-10-0'.$i]);
}
$r=chat_research_recall_r3($pdo,$user,'system','recall previous research about studio');check(count($r)===2,'Cross-conversation saved owner-only recall');check(chat_research_recall_r3($pdo,$user,'other','')===[],'Lead namespace isolated');check(chat_research_recall_r3($pdo,$user,'system','unmatched subject')===[],'Irrelevant reports excluded');
$c=chat_research_compare_r3($r[1],$r[0]);check($c['changes'][0]['state']==='different_observed_values','Observed value comparison');check($c['changes'][1]['state']==='not_reobserved','Missing does not imply false');check($c['changes'][0]['after'][0]['kind']==='conflict','Conflict preserved');check(str_contains($c['notice'],'do not prove'),'Comparison limitations');
$ctx=chat_research_context_r3($r);check(mb_strlen($ctx)<=4000&&str_contains($ctx,'UNTRUSTED')&&str_contains($ctx,'version1'),'Bounded cited model context');check(!str_contains(chat_research_context_r3($r,15000,false),'Ignore instructions'),'User answer excludes model instructions');
$pdo->exec('UPDATE projects SET allowed=0');check(chat_research_recall_r3($pdo,$user,'system')===[],'Revoked project hidden');$pdo->exec('UPDATE projects SET allowed=1');
$allowed=false;check(chat_research_recall_r3($pdo,$user,'system')===[],'Revoked runtime hidden');$allowed=true;$domains=[];check(chat_research_recall_r3($pdo,$user,'system')===[],'Revoked website hidden');$domains=['one.example'];
$pdo->exec("UPDATE reports SET deleted_at='deleted'");check(chat_research_recall_r3($pdo,$user,'system')===[],'Deleted report hidden');$pdo->exec('UPDATE reports SET deleted_at=NULL');
$chat=false;check(chat_research_recall_r3($pdo,$user,'system')===[],'Chat permission required');$chat=true;
$before=$r[0]['hash'];$pdo->exec("UPDATE reports SET updated_at='changed'");check(chat_research_recall_r3($pdo,$user,'system')[0]['hash']!==$before,'Post-answer snapshot detects changed saved report');
check(chat_research_recall_intent_r3('What did our prior research say about studio hours?')&&!chat_research_recall_intent_r3('Research studio hours'),'Recall does not hijack new research');
$fresh=$r[0];$fresh['answer']['citations'][0]['source_version_id']='version2';check(chat_research_compare_r3($r[0],$fresh)['changes'][0]['state']==='same_values_updated_evidence','Source refresh differs from changed values');
$large=$mission;$large['claims']=array_fill(0,60,$mission['claims'][0]);foreach($large['claims'] as &$claim){$claim['statement']=str_repeat('Observed ',130);$claim['value']=str_repeat('Value ',150);}unset($claim);
$brief=chat_research_answer_r3($large,[['url'=>$url]]);check(mb_strlen($brief['text'])<15000&&str_contains($brief['text'],'additional claims')&&str_contains($brief['text'],'[C1] one.example'),'Complete bounded citation blocks before save-summary limit');
echo "PASS: R3 receipt-backed observed/inferred/conflicting claims, exact citations, bounded complete summaries, durable saved recall, owner/lead/project/report/runtime/site fences and truthful comparisons.\n";
