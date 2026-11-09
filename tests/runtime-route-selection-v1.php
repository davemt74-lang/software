<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/ai-gateway-v031.php';
require dirname(__DIR__).'/includes/agent-runtime-routing-v420.php';
require dirname(__DIR__).'/includes/ai-settings.php';
$pdo=new PDO('sqlite::memory:');$pref='auto';$ready=false;$cloud=true;$paired=true;$supported=true;$homeCalls=0;$checks=0;$personalFunding=false;
function agent_compute_v020_preference($pdo,$uid){return $GLOBALS['pref'];}function agent_compute_v023_override(...$a){return 'inherit';}function agent_compute_v023_policy_from_values($a,$b){return ['effective_preference'=>$a,'source'=>'account'];}function agent_compute_v023_public_policy($p,$a){return $p;}
function subscription_has_entitlement(...$a){return true;}function subscription_ai_balance(...$a){return ['unlimited'=>$GLOBALS['cloud'],'remaining'=>0];}
function homeserver_scope_v026_fetch(...$a){$GLOBALS['homeCalls']++;return [];}function homeserver_scope_v026_apply_compute_policy($p,$s){return $p;}
function homeserver_agent_v018_credentials(...$a){return $GLOBALS['paired']?['paired'=>true]:null;}
function homeserver_capability_v024_registry(...$a){return [];}function homeserver_capability_v024_resolve(...$a){return ['ready'=>$GLOBALS['ready'],'supported'=>$GLOBALS['supported'],'reason'=>'fixture'];}
function vp3_user_llm_v1_effective(...$a){return ['route'=>$GLOBALS['personalFunding']?'own_key':'system'];}
function ck($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
$u=['id'=>1];$r=vp3_agent_runtime_plan_v420($pdo,$u,7);ck($r['route']==='vp3_cloud'&&!$r['try_homeserver'],'Automatic selected Cloud bypasses unready HomeServer instead of dispatching a Chat recovery probe');
$ready=true;$r=vp3_agent_runtime_plan_v420($pdo,$u,7);ck($r['route']==='homeserver'&&$r['try_homeserver']&&$r['homeserver_cloud_allowed'],'Automatic still uses a currently ready HomeServer and permits VP3-funded delegation');
$personalFunding=true;$r=vp3_agent_runtime_plan_v420($pdo,$u,7);ck($r['route']==='homeserver'&&$r['try_homeserver']&&!$r['homeserver_cloud_allowed']
    &&$r['allow_vp3_fallback']&&$r['cloud']['billing_source']==='user_provider','Personal Cloud funding keeps HomeServer local and allows direct VP3 fallback only');
$personalFunding=false;
$pref='homeserver_only';$ready=false;$r=vp3_agent_runtime_plan_v420($pdo,$u,7);ck($r['try_homeserver']&&!$r['allow_vp3_fallback'],'explicit HomeServer-only retains its single recovery attempt without Cloud fallback');
$pref='vp3_cloud';$homeCalls=0;$r=vp3_agent_runtime_plan_v420($pdo,$u,7);ck(!$r['try_homeserver']&&$homeCalls===0,'explicit Cloud performs no HomeServer scope/capability probe');
$pref='auto';$paired=false;$r=vp3_agent_runtime_plan_v420($pdo,$u,7);ck($r['route']==='vp3_cloud'&&!$r['try_homeserver'],'unpaired Automatic uses Cloud');
$paired=true;$supported=false;$r=vp3_agent_runtime_plan_v420($pdo,$u,7);ck($r['route']==='vp3_cloud'&&!$r['try_homeserver'],'unsupported HomeServer cannot receive Automatic Chat');
$pref='vp3_cloud';$cloud=false;$r=vp3_agent_runtime_plan_v420($pdo,$u,7);ck($r['blocked']&&!$r['try_homeserver'],'unavailable explicit Cloud is not silently redirected to HomeServer');
echo "RUNTIME_ROUTE_SELECTION=PASS ($checks cases; actual v4.20 planner and gateway)\n";
