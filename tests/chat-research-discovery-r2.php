<?php
declare(strict_types=1);
if(($argv[1]??'')==='--transport-fixture'){
 if(function_exists('curl_init'))throw new RuntimeException('Transport fixture requires the explicit no-INI child.');
 $names=['CURLOPT_RETURNTRANSFER','CURLOPT_WRITEFUNCTION','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_USERAGENT','CURLOPT_HTTPHEADER','CURLINFO_HTTP_CODE'];foreach($names as $i=>$n)define($n,$i+1);
 $options=[];$status=200;$body='<html><body>Controlled search result</body></html>';$written=null;$target='';
 function curl_init(string $url):object{global $target;$target=$url;return new stdClass();}
 function curl_setopt_array(object $ch,array $opts):bool{global $options;$options=$opts;return true;}
 function curl_exec(object $ch):bool{global $options,$body,$written;$written=$options[CURLOPT_WRITEFUNCTION]($ch,$body);return $written===strlen($body);}
 function curl_getinfo(object $ch,int $key):int{global $status;return $status;}
 function curl_close(object $ch):void{}
 require __DIR__.'/../includes/agent-tools-v84.php';
 booking_agent_web_search('scoped question',2);
 if($target!=='https://html.duckduckgo.com/html/?q=scoped%20question'||$options[CURLOPT_FOLLOWLOCATION]!==false||$options[CURLOPT_TIMEOUT]!==9||$options[CURLOPT_CONNECTTIMEOUT]!==4||$written!==strlen($body))throw new RuntimeException('Transport query, redirect or timeout boundary failed.');
 $status=302;if(booking_agent_web_search('query',2)!==[])throw new RuntimeException('Redirect accepted.');
 $status=200;$body=str_repeat('x',2000001);if(booking_agent_web_search('query',2)!==[]||$written!==0)throw new RuntimeException('Oversized response accepted.');
 echo 'PASS transport';exit;
}
require __DIR__.'/../includes/chat-browser-research-v1.php';
class ResearchTestPDO extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
$pdo=new ResearchTestPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE chat_browser_research_requests_v1(id TEXT PRIMARY KEY,owner_user_id INTEGER,request_id TEXT,context_key TEXT,agent_namespace TEXT,agent_id INTEGER,runtime_id TEXT,conversation_id INTEGER,question TEXT,urls_json TEXT,review_hash TEXT,status TEXT DEFAULT 'prepared',mission_id TEXT,device_key TEXT,lease_token TEXT,lease_until TEXT,next_source INTEGER DEFAULT 0,last_error TEXT DEFAULT '',expires_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(owner_user_id,request_id));CREATE TABLE chat_conversations(id INTEGER,user_id INTEGER);");
$uid=7;$user=['id'=>$uid];$runtimeId='11111111-1111-4111-8111-111111111111';$rid='22222222-2222-4222-8222-222222222222';
$active=true;$domains=['one.example','two.example'];$starts=0;$ledger=[];$missionId='33333333-3333-4333-8333-333333333333';
function vp3_extension_uuid_v2000():string{$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20);}
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
require __DIR__.'/../includes/chat-research-discovery-r2.php';
$chatAllowed=true;$leadAllowed=true;$calls=0;
function vp3_extension_user_for_permission_v2001(PDO $pdo,int $id):?array{return ['id'=>$id];}
function user_account_types_for_user_id(int $id,string $role=''):array{return [];}
function vp3_agent_chat_resolve_agent_v380(PDO $pdo,array $u,int $id):?array{global $leadAllowed;if(!$leadAllowed)throw new RuntimeException('Revoked lead',403);return ['id'=>$id];}
function has_permission(string $permission,?array $user=null):bool{global $chatAllowed;return $chatAllowed;}
function vp3_agent_chat_conversation_v380(PDO $pdo,int $id,int $uid,?array $agent):?array{return null;}
$question='Compare service coverage and explain evidence gaps';
$base=['question'=>$question,'request_id'=>$rid,'runtime_id'=>$runtimeId,'context_key'=>'cloud:0','conversation_id'=>0,'discovery_domains'=>$domains];
$results=[['url'=>'https://unapproved.example/about','title'=>'No grant'],['url'=>'http://one.example/about','title'=>'Unsafe'],['url'=>'https://one.example/admin','title'=>'Action'],['url'=>'https://one.example/?secret=1','title'=>'Query'],['url'=>'https://one.example/about','title'=>'<b>First</b>'],['url'=>'https://www.one.example/duplicate','title'=>'Duplicate'],['url'=>'https://two.example/services','title'=>'Second']];
$search=function(string $q,int $limit)use(&$calls,$question,$results):array{$calls++;check($q===$question.' (site:one.example OR site:two.example)','exact reviewed query');check($limit===12,'bounded search result budget');return $results;};
rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,$base,$search),'no outbound query without search approval');check($calls===0,'no unapproved external call');
$input=$base+['discovery_confirmed'=>true];
rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,array_replace($input,['question'=>['not text']]),$search),'malformed question cannot export');check($calls===0,'typed query validation precedes transport');
rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,array_replace($input,['discovery_domains'=>['two.example']]),$search),'stale query scope');check($calls===0,'scope mismatch cannot search');
rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,array_replace($input,['conversation_id'=>71]),$search),'wrong conversation cannot export');check($calls===0,'conversation checked before transport');
$chatAllowed=false;rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,$input,$search),'Chat revoked before export');$chatAllowed=true;check($calls===0,'Chat checked before external call');
$leadAllowed=false;rejected(fn()=>chat_research_plan_r2($pdo,$user,'agent:9',['id'=>9],$input,$search),'lead revoked before export');$leadAllowed=true;check($calls===0,'lead checked before external call');
$r=chat_research_plan_r2($pdo,$user,'system',null,$input,$search);check($starts===0&&$r['status']==='prepared','discovery cannot execute Chrome');check(count($r['sources'])===2,'deduplicated eligible websites');check(array_column($r['sources'],'url')===['https://one.example/about','https://two.example/services'],'only safe approved exact URLs');check($r['sources'][0]['title']==='First'&&$r['sources'][0]['origin']==='web_search','safe candidate metadata');check(str_contains($r['sources'][0]['reason'],'not yet read or verified'),'candidate not evidence');
check(chat_research_plan_r2($pdo,$user,'system',null,$input,$search)['review_hash']===$r['review_hash']&&$calls===1,'lost acknowledgement reuses frozen plan without search');
rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,array_replace($input,['question'=>'Changed research question']),$search),'request identity scope cannot change');
check($calls===1,'wrong identity cannot search');
$domains=['one.example','two.example','z.example'];rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,array_replace($input,['discovery_domains'=>$domains]),$search),'frozen query scope cannot change');$domains=['one.example','two.example'];check($calls===1,'changed frozen scope cannot search');
rejected(fn()=>chat_research_start_v1($pdo,$user,['id'=>$r['id'],'expected_hash'=>$r['review_hash']]),'search consent never substitutes for source-plan approval');
check(chat_research_start_v1($pdo,$user,['id'=>$r['id'],'expected_hash'=>$r['review_hash'],'confirmed'=>true])['status']==='waiting_chrome'&&$starts===1,'approved discovered plan enters canonical R1 queue');
$reader=chat_research_claim_v1($pdo,$user,'chrome-r2',['id'=>$r['id']]);$target=chat_research_step_v1($pdo,$user,'chrome-r2',['id'=>$r['id'],'lease_token'=>$reader['lease_token']]);check($target['next']['url']==='https://one.example/about','canonical reader receives exact discovered target');
$pdo->exec('DELETE FROM chat_browser_research_requests_v1');
$race=function(string $q,int $limit)use($pdo,$user,$input,$results):array{
 $winner=chat_research_discovery_candidates_r2([['url'=>'https://one.example/frozen','title'=>'Winner']],['one.example','two.example']);
 chat_research_prepare_v1($pdo,$user,'system',null,$input,$winner);return $results;
};
$r=chat_research_plan_r2($pdo,$user,'system',null,$input,$race);check($r['sources'][0]['url']==='https://one.example/frozen','concurrent rankings reuse first frozen plan');check((int)$pdo->query('SELECT COUNT(*) FROM chat_browser_research_requests_v1')->fetchColumn()===1,'one durable plan');
$pdo->exec('DELETE FROM chat_browser_research_requests_v1');
rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,$input,fn()=>[]),'empty provider output actionable fallback');
$revokes=function()use(&$active,$results):array{$active=false;return $results;};rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,$input,$revokes),'runtime revoked during search');$active=true;
$revokeChat=function()use(&$chatAllowed,$results):array{$chatAllowed=false;return $results;};rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,$input,$revokeChat),'Chat revoked during search');$chatAllowed=true;
$revokeLead=function()use(&$leadAllowed,$results):array{$leadAllowed=false;return $results;};rejected(fn()=>chat_research_plan_r2($pdo,$user,'agent:9',['id'=>9],$input,$revokeLead),'lead revoked during search');$leadAllowed=true;
$changeScope=function()use(&$domains,$results):array{$domains=['one.example'];return $results;};rejected(fn()=>chat_research_plan_r2($pdo,$user,'system',null,$input,$changeScope),'website scope changed during search');$domains=['one.example','two.example'];
check((int)$pdo->query('SELECT COUNT(*) FROM chat_browser_research_requests_v1')->fetchColumn()===0,'failed or revoked discovery saves no plan');
$html='<html><body><a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fone.example%2Fabout">Primary page</a><a class="result__a" href="https://two.example/services">Second page</a><a href="https://ad.example/">Ad</a></body></html>';
$parsed=booking_agent_parse_web_search($html,1);check(count($parsed)===1&&$parsed[0]['url']==='https://one.example/about','production provider HTML parser decodes result links and honors budget');
$pipes=[];$proc=proc_open([PHP_BINARY,'-n',__FILE__,'--transport-fixture'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($proc),'transport fixture launches');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0&&$out==='PASS transport'&&$err==='','production fixed HTTPS query, no redirects, bounded response and timeout fixture');
echo "PASS: R2 exact search consent/scope, pre-export conversation, safe ranked candidates, unverified metadata, frozen retry/concurrent plans, revoked Chat/runtime/domains, empty-result fallback and production search parser.\n";
