<?php
declare(strict_types=1);
// Actual canonical public-claim/evidence and save-memo functions over an isolated SQLite ledger.
require __DIR__.'/../includes/cognitive-domain-manifest-v2370.php';
require __DIR__.'/../includes/cognitive-runtime-v500.php';
require __DIR__.'/../includes/browser-research-save-v2230.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE browser_agent_runtime_sessions_v2200(id INTEGER,public_id TEXT,agent_namespace TEXT,status TEXT,expires_at TEXT);
CREATE TABLE browser_research_missions_v2230(id INTEGER,public_id TEXT,owner_user_id INTEGER,runtime_session_id INTEGER,status TEXT,question TEXT,approved_domains_json TEXT,source_plan_json TEXT,project_id INTEGER,report_public_id TEXT,conversation_id INTEGER,expires_at TEXT,max_sources INTEGER,max_pages INTEGER,max_claims INTEGER,duration_minutes INTEGER,gaps_json TEXT,memo_text TEXT);
CREATE TABLE browser_research_pages_v2230(id INTEGER,public_id TEXT,mission_id INTEGER,domain TEXT,page_fingerprint TEXT,duplicate_group_hash TEXT,source_public_id TEXT,source_version_public_id TEXT,source_kind TEXT,freshness_date TEXT,extraction_status TEXT,claim_count INTEGER,collected_at TEXT);
CREATE TABLE browser_research_claims_v2230(id INTEGER,public_id TEXT,mission_id INTEGER,claim_key TEXT,statement_text TEXT,value_text TEXT,evidence_state TEXT,support_sources INTEGER,primary_sources INTEGER,direct_sources INTEGER,freshest_at TEXT,research_finding_public_id TEXT);
CREATE TABLE browser_research_evidence_v2230(id INTEGER,claim_id INTEGER,page_id INTEGER,evidence_excerpt TEXT,directness TEXT,as_of_date TEXT);");
$id='11111111-1111-4111-8111-111111111111';$version='22222222-2222-4222-8222-222222222222';$fp=hash('sha256','https://one.example/about');
$pdo->exec("INSERT INTO browser_agent_runtime_sessions_v2200 VALUES(1,'runtime','system','completed','2099-01-01')");
$pdo->prepare('INSERT INTO browser_research_missions_v2230 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([1,$id,7,1,'ready','Studio hours','["one.example"]','[]',null,'',0,'2099-01-01',1,1,60,60,'["Holiday hours unknown"]','']);
$pdo->prepare('INSERT INTO browser_research_pages_v2230 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([1,'page',1,'one.example',$fp,'group','source',$version,'primary','2026-10-01','complete',2,'2026-10-01']);
$pdo->prepare('INSERT INTO browser_research_claims_v2230 VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([1,'observed',1,'hours','Opens at nine','9 am','single_source',1,1,1,'2026-10-01','']);
$pdo->prepare('INSERT INTO browser_research_claims_v2230 VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([2,'inferred',1,'weekends','Possible weekend service','Possible','single_source',1,1,0,null,'']);
$pdo->prepare('INSERT INTO browser_research_claims_v2230 VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([3,'unsupported',1,'unsupported','No supporting excerpt','Guess','single_source',0,0,0,null,'']);
$pdo->exec("INSERT INTO browser_research_evidence_v2230 VALUES(1,1,1,'Opens nine','direct','2026-10-01');INSERT INTO browser_research_evidence_v2230 VALUES(2,2,1,'Weekday service listed','inferred',NULL)");
$mission=vp3_browser_research_mission_row_v2230($pdo,7,$id);$public=vp3_browser_research_public_v2230($pdo,['id'=>7],$id);$memo=vp3_browser_research_memo_v2230($pdo,$mission);
if(count($public['claims'])!==3||!str_contains($memo,'Observed:')||!str_contains($memo,'Inference:')||!str_contains($memo,$version)||!str_contains($memo,'date unknown')||str_contains($memo,'No supporting excerpt')||str_contains($memo,'https://'))throw new RuntimeException('Canonical R3 saved memo lost receipt provenance, uncertainty or privacy.');
if(vp3_browser_research_mission_row_v2230($pdo,8,$id)!==null)throw new RuntimeException('Canonical owner fence failed');
$pdo->exec("UPDATE browser_research_claims_v2230 SET evidence_state='conflicted' WHERE id=1");
if(!str_contains(vp3_browser_research_memo_v2230($pdo,$mission),'Conflict:'))throw new RuntimeException('Canonical conflicts were resolved during save');
if((int)$pdo->query('SELECT count(*) FROM browser_research_evidence_v2230')->fetchColumn()!==2)throw new RuntimeException('Memo projection changed evidence');
echo "PASS: actual canonical ledger/public-claim/save-memo integration, direct/inferred/conflicted and uncited exclusion, pinned versions, unknown dates, no URL/history or evidence duplication and owner fencing.\n";
