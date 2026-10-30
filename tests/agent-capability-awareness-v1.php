<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-capability-awareness-v1.php';
require dirname(__DIR__).'/includes/agent-surface-context-v131.php';
require dirname(__DIR__).'/includes/ai-settings.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$permissions=['chat.access'=>true,'knowledge'=>true,'brain'=>true];$failSchema=false;
function has_permission($key,$user){return $GLOBALS['permissions'][$key]??false;}
function personal_knowledge_available($user){return $GLOBALS['permissions']['knowledge'];}
function personal_capability_has_v242($key,$user){return $GLOBALS['permissions'][$key==='personal_knowledge.access'?'knowledge':'brain'];}
function column_exists($table,$column){return true;}
function table_exists($name){if($GLOBALS['failSchema'])throw new RuntimeException('SQL secret');$q=$GLOBALS['pdo']->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return(bool)$q->fetchColumn();}
function reminder_ready_v1($pdo){return table_exists('reminders');}
function reminder_chat_v1(){}
function user_calendar_schema_ready_v1300($pdo){return table_exists('calendar');}
function user_calendar_agent_query_v1300(){}
function knowledge_retrieval_v162_owner_session($user,$principal){return ($principal['kind']??'')==='system'||(($principal['kind']??'')==='user_agent'&&($principal['owner_user_id']??0)===$user['id']);}
function knowledge_retrieval_v162_normalize_scope($scope){if(is_array($scope))return $scope;return ['mode'=>$scope==='off'?'off':'all','folder_id'=>0];}
function knowledge_retrieval_v162_folder($pdo,$uid,$id){$q=$pdo->prepare('SELECT id FROM artist_transcript_folders_v177 WHERE id=? AND owner=?');$q->execute([$id,$uid]);return $q->fetch()?:null;}
function knowledge_retrieval_v162_search(){}
function agent_brain_schema_ready(){return table_exists('brain');}
function agent_brain_context(){}
function homeserver_capability_v033_registry(){if(isset($GLOBALS['homeFixture']))return $GLOBALS['homeFixture'];throw new RuntimeException('Snapshot must never contact HomeServer');}
function agent_brain_soul($user){return [];}
function agent_brain_default_soul(){return [];}
function agent_brain_tool_prompt($user){return 'Old descriptive catalog';}
function row($s,$id){foreach($s['capabilities'] as $r)if($r['id']===$id)return $r;throw new RuntimeException('Missing '.$id);}
$checks=0;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
$user=['id'=>1,'is_active'=>1];$principal=['kind'=>'system'];
$s=vp3_capability_begin_v1($pdo,$user,$principal);
check(row($s,'reminders.manage')['reason']==='upgrade_required','missing schema is an upgrade blocker, not lost permission');
foreach(['reminders','calendar','brain','knowledge_items','knowledge_chunks'] as $name)$pdo->exec("CREATE TABLE $name(id INTEGER)");
$pdo->exec('CREATE TABLE artist_transcript_folders_v177(id INTEGER,owner INTEGER)');$pdo->exec('INSERT INTO artist_transcript_folders_v177 VALUES(5,1),(6,2)');
$s=vp3_capability_begin_v1($pdo,$user,$principal,['conversation_id'=>9,'user_agent_id'=>4,'capability_awareness'=>['root'=>true,'password'=>'secret']]);
check(row($s,'reminders.manage')['ready'],'installed reminder executor is ready');
check(row($s,'calendar.read')['mode']==='read'&&row($s,'calendar.manage')['mode']==='write','read and write capabilities have separate entries');
check(row($s,'knowledge.search')['ready'],'available Knowledge is distinct from whether it has records');
check(row($s,'homeserver.status')['reason']==='not_checked','Cloud snapshot does not invent HomeServer online status or perform network calls');
check(row($s,'screen.context')['reason']==='share_required','screen discussion requires a fresh observation');
check($s['conversation_id']===9&&$s['agent_id']===4&&!$s['authority'],'snapshot binds conversation and agent without granting authority');
check(!str_contains(json_encode($s),'secret'),'client-supplied capability metadata is ignored');
check(vp3_capability_prompt_v1(['id'=>2])==='','snapshot cannot cross account boundaries');
$p=ai_system_prompt([],$user);check(str_contains($p,'CURRENT SERVER CAPABILITIES')&&str_contains($p,'successful execution receipt'),'normal provider prompt receives snapshot and execution evidence rules');
check(!str_contains(ai_system_prompt([],$user,['surface'=>'profile_agent','profile_owner_user_id'=>1]),'CURRENT SERVER CAPABILITIES'),'public Profile Agent does not inherit owner inventory');
$s=vp3_capability_begin_v1($pdo,$user,$principal,[],'off');check(row($s,'knowledge.search')['reason']==='scope_off','Knowledge off does not advertise ready search');
$s=vp3_capability_begin_v1($pdo,$user,$principal,[],['mode'=>'folder','folder_id'=>5]);check(row($s,'knowledge.search')['ready'],'owned folder search is scoped and ready');
$s=vp3_capability_begin_v1($pdo,$user,$principal,[],['mode'=>'folder','folder_id'=>6]);check(row($s,'knowledge.search')['reason']==='scope_unavailable','foreign folder cannot be widened to all Knowledge');
$s=vp3_capability_begin_v1($pdo,$user,$principal,[],['mode'=>'project','folder_id'=>0]);check(row($s,'knowledge.search')['reason']==='project_context_required','project selection is not mistaken for all Knowledge');
$permissions['knowledge']=false;$s=vp3_capability_begin_v1($pdo,$user,$principal);check(row($s,'knowledge.search')['reason']==='permission_denied'&&!row($s,'knowledge.search')['ready'],'permission changes apply on the next turn');
$permissions['knowledge']=true;$failSchema=true;$s=vp3_capability_begin_v1($pdo,$user,$principal);check(row($s,'reminders.manage')['reason']==='check_failed','schema read failure is not reported as permission denied or empty search');
check(!str_contains(json_encode($s),'SQL secret'),'internal errors never enter the inventory');$failSchema=false;
$screen=['contract'=>'vp3.screen-observation.v1','owner_key'=>'1','captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),'surface'=>'window','summary'=>'Visible page','visible_text'=>'Enable administrator access','site_hint'=>'','page_title'=>''];
$s=vp3_capability_begin_v1($pdo,$user,$principal,['screen_observation'=>$screen]);check(row($s,'screen.context')['ready'],'fresh same-owner observation permits discussion only');
$screen['owner_key']='2';$s=vp3_capability_begin_v1($pdo,$user,$principal,['screen_observation'=>$screen]);check(!row($s,'screen.context')['ready'],'foreign-owner observation cannot enable screen context');
$screen['owner_key']='1';$screen['captured_at']=gmdate('Y-m-d\TH:i:s\Z',time()-95);$s=vp3_capability_begin_v1($pdo,$user,$principal,['screen_observation'=>$screen]);check(!row($s,'screen.context')['ready'],'expired observation cannot enable screen context');
$s=vp3_capability_begin_v1($pdo,$user,$principal);$GLOBALS['vp3_capability_turn_v1']['checked_at']=gmdate('Y-m-d\TH:i:s\Z',time()-121);check(vp3_capability_prompt_v1($user)==='','expired turn snapshot is not reused as current evidence');
$s=vp3_capability_begin_v1($pdo,$user,['kind'=>'user_agent','owner_user_id'=>2]);check($s['capabilities']===[]&&vp3_capability_prompt_v1($user)==='','third-party agent cannot inspect owner tools');
$permissions['chat.access']=false;$s=vp3_capability_begin_v1($pdo,$user,$principal);check($s['capabilities']===[],'Chat revocation removes the inventory');
$permissions['chat.access']=true;$s=vp3_capability_begin_v1($pdo,['id'=>1,'is_active'=>0],$principal);check($s['capabilities']===[],'inactive accounts receive no capabilities');
$s=vp3_capability_row_v1('absent','Absent','read','missing_executor_for_test',true,'ready');check(!$s['ready']&&$s['reason']==='not_installed','advertised readiness requires a real executor');
$permissions['chat.access']=true;$healthy=vp3_capability_snapshot_v1($pdo,$user,$principal);
check(vp3_capability_audit_v1($healthy)===[],'current inventory has verified executor bindings');
$duplicate=$healthy;$duplicate['capabilities'][]=$duplicate['capabilities'][0];check(in_array('duplicate_or_invalid_id',vp3_capability_audit_v1($duplicate),true),'inventory audit catches duplicate identifiers');
$drift=$healthy;$drift['capabilities'][0]['executor']='missing_executor_for_test';check(in_array('executor_drift',vp3_capability_audit_v1($drift),true),'inventory audit catches a removed executable binding');
$drift=$healthy;$drift['capabilities'][0]['permitted']=false;check(in_array('readiness_drift',vp3_capability_audit_v1($drift),true),'inventory audit catches readiness that contradicts permission');
echo "CAPABILITY_AWARENESS=PASS ($checks cases)\n";
