<?php
declare(strict_types=1);
require __DIR__.'/../includes/chat-browser-research-v1.php';
class ResearchTestPDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
$pdo=new ResearchTestPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE chat_browser_research_requests_v1(id TEXT PRIMARY KEY,owner_user_id INTEGER,request_id TEXT,context_key TEXT,agent_namespace TEXT,agent_id INTEGER,runtime_id TEXT,conversation_id INTEGER,question TEXT,urls_json TEXT,review_hash TEXT,status TEXT DEFAULT 'prepared',mission_id TEXT,device_key TEXT,lease_token TEXT,lease_until TEXT,next_source INTEGER DEFAULT 0,last_error TEXT DEFAULT '',expires_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(owner_user_id,request_id));CREATE TABLE chat_conversations(id INTEGER,user_id INTEGER);");
$uid=7;$user=['id'=>$uid];$runtimeId='11111111-1111-4111-8111-111111111111';$rid='22222222-2222-4222-8222-222222222222';
$active=true;$domains=['one.example','two.example'];$starts=0;$ledger=[];$missionId='33333333-3333-4333-8333-333333333333';
function vp3_extension_uuid_v2000():string{return '44444444-4444-4444-8444-444444444444';}
function vp3_browser_delegation_domain_v2190(mixed $host):string{return preg_replace('/^www\./','',strtolower((string)$host));}
function vp3_browser_research_runtime_v2230(PDO $pdo,array $u,string $namespace,string $id):array{global $active,$runtimeId;if(!$active||$id!==$runtimeId||$u['id']!==7)throw new RuntimeException('Revoked runtime',403);return ['_multisite'=>[]];}
function vp3_browser_runtime_row_v2200(PDO $pdo,int $uid,string $namespace,string $id):?array{global $active;return $active&&$uid===7?['status'=>'active','allowed_actions_json'=>'["browser_research"]']:null;}
function vp3_browser_delegation_json_array_v2190(mixed $json):array{return json_decode((string)$json,true)?:[];}
function vp3_browser_multisite_row_v2220(PDO $pdo,array $r):?array{return ['id'=>1];}
function vp3_browser_research_allowed_domains_v2230(PDO $pdo,array $m):array{global $domains;return $domains;}
function vp3_browser_research_start_v2230(PDO $pdo,array $u,string $ns,?array $agent,string $r,array $input):array{global $starts,$missionId;$starts++;check($input['max_pages']===2,'source budget');check(array_column($input['source_plan'],'domain')===['one.example','two.example'],'scope unchanged');return ['mission_id'=>$missionId];}
function vp3_browser_research_public_v2230(PDO $pdo,array $u,string $id):array{global $ledger;return ['mission_id'=>$id,'status'=>'active','pages'=>$ledger,'claims'=>[]];}
function check(bool $b,string $message):void{if(!$b)throw new RuntimeException('FAIL: '.$message);}
function rejected(callable $fn,string $label):void{try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('FAIL: not rejected '.$label);}
foreach(['Research https://127.0.0.1/','Research https://localhost/','Research https://one.example/admin/','Research https://one.example/?secret=1','Research https://user:pass@one.example/','Research https://one.example:8443/','Research https://one.example/%2fadmin','Research https://one.example/ and https://one.example/other','Research without sources'] as $bad)rejected(fn()=>chat_research_urls_v1($bad),'unsafe source');
$input=['question'=>'Compare https://one.example/about and https://two.example/services','request_id'=>$rid,'runtime_id'=>$runtimeId,'context_key'=>'cloud:0','conversation_id'=>0];
$r=chat_research_prepare_v1($pdo,$user,'system',null,$input);check($r['status']==='prepared'&&$starts===0,'planning cannot execute');
check(chat_research_prepare_v1($pdo,$user,'system',null,$input)['id']===$r['id'],'stable prepare retries');
rejected(fn()=>chat_research_prepare_v1($pdo,$user,'system',null,array_replace($input,['question'=>'Changed https://one.example/about and https://two.example/services'])),'changed request');
rejected(fn()=>chat_research_row_v1($pdo,8,$r['id']),'other owner');
rejected(fn()=>chat_research_start_v1($pdo,$user,['id'=>$r['id'],'expected_hash'=>$r['review_hash']]),'missing approval');
rejected(fn()=>chat_research_start_v1($pdo,$user,['id'=>$r['id'],'expected_hash'=>str_repeat('0',64),'confirmed'=>true]),'wrong exact hash');
$start=['id'=>$r['id'],'expected_hash'=>$r['review_hash'],'confirmed'=>true];
check(chat_research_start_v1($pdo,$user,$start)['status']==='waiting_chrome','approved starts');chat_research_start_v1($pdo,$user,$start);check($starts===1,'save once under retry');
$claim=chat_research_claim_v1($pdo,$user,'chrome1',['id'=>$r['id']]);$step=['id'=>$r['id'],'lease_token'=>$claim['lease_token']];
rejected(fn()=>chat_research_claim_v1($pdo,$user,'chrome2',['id'=>$r['id']]),'concurrent reader');
rejected(fn()=>chat_research_step_v1($pdo,$user,'chrome2',$step),'other browser cannot use lease');
check(chat_research_step_v1($pdo,$user,'chrome1',$step)['next']['url']==='https://one.example/about','first reviewed target');
check(chat_research_analysis_guard_v1($pdo,$user,$missionId,'chrome1',$claim['lease_token'],hash('sha256','https://one.example/about')),'valid source receipt');
rejected(fn()=>chat_research_analysis_guard_v1($pdo,$user,$missionId,'chrome2',$claim['lease_token'],hash('sha256','https://one.example/about')),'stale analysis ownership');
rejected(fn()=>chat_research_analysis_guard_v1($pdo,$user,$missionId,'chrome1',$claim['lease_token'],hash('sha256','https://one.example/other')),'unreviewed same-domain page');
$ledger[]=['fingerprint'=>hash('sha256','https://one.example/about')];
check(chat_research_step_v1($pdo,$user,'chrome1',$step)['next']['url']==='https://two.example/services','durable receipt advances');
chat_research_step_v1($pdo,$user,'chrome1',$step+['failed'=>true]);
rejected(fn()=>chat_research_claim_v1($pdo,$user,'chrome1',['id'=>$r['id']]),'interruption requires review');
$claim=chat_research_claim_v1($pdo,$user,'chrome2',['id'=>$r['id'],'resume_confirmed'=>true]);
rejected(fn()=>chat_research_step_v1($pdo,$user,'chrome1',$step),'stale reader rejected');
$step=['id'=>$r['id'],'lease_token'=>$claim['lease_token']];
check(chat_research_step_v1($pdo,$user,'chrome2',$step)['next']['url']==='https://two.example/services','resume reuses first receipt');
$ledger[]=['fingerprint'=>hash('sha256','https://two.example/services')];check(chat_research_step_v1($pdo,$user,'chrome2',$step)['request']['status']==='complete','verified completion');
$active=false;rejected(fn()=>chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,7,$r['id'])),'revoked results hidden');
$active=true;$domains=['one.example'];rejected(fn()=>chat_research_view_v1($pdo,$user,chat_research_row_v1($pdo,7,$r['id'])),'changed website grant');
echo "PASS: reviewed source validation, owner isolation, stable preparation, exact approval, start-once, exclusive Chrome lease, receipt-based resume, stale-worker fencing, source-grant revocation and truthful completion.\n";
