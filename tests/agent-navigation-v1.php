<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-navigation-v1.php';
require dirname(__DIR__).'/includes/agent-tool-authorization-v400.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db(){return $GLOBALS['pdo'];}function table_exists($t){$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$t]);return(bool)$q->fetchColumn();}function has_permission($p,$u){return !isset($GLOBALS['denied'][$p]);}function user_has_role(...$a){return false;}function url($p){return $p;}
function knowledge_retrieval_v162_owner_session($u,$p){return ($p['kind']??'')==='system'||(($p['kind']??'')==='user_agent'&&($p['owner_user_id']??0)===$u['id']);}
function member_navigation_menu_links($u){return [['key'=>'contacts','label'=>'Contacts','url'=>'/contacts.php']];}
$pdo->exec("CREATE TABLE tracks(id INTEGER PRIMARY KEY,title TEXT,owner_user_id INTEGER,producer_user_id INTEGER);INSERT INTO tracks VALUES(9,'From the asteroid you saved me',1,NULL),(10,'SECRET foreign production',2,NULL);CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);INSERT INTO chat_conversations VALUES(1,1,7),(2,1,8);CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,role TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$u=['id'=>1];$p=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];$ctx=['conversation_id'=>1];$checks=0;
function ck($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
function runNav($a,$extra=[],$c=null){return vp3_navigation_tool_v1($GLOBALS['pdo'],$GLOBALS['u'],$GLOBALS['p'],['action'=>$a]+$extra,$c??$GLOBALS['ctx']);}
function saveNav($data,$cid=1,$at=null){$q=db()->prepare("INSERT INTO chat_messages(conversation_id,role,context_json,created_at) VALUES(?,'assistant',?,?)");$q->execute([$cid,json_encode($data),$at??gmdate('Y-m-d H:i:s')]);}
$r=runNav('open',['destination'=>'stem_studio','query'=>'From the asteroid you saved me']);$a=vp3_agent_tool_internal_action_v400($r['actions'][0],$u,'Load from the asteroid you saved me');ck($a['auto']&&str_contains($a['url'],'track=9'),'model-selected load produces an authorized automatic Studio navigation');
$r=runNav('prepare',['destination'=>'stem_studio','track_id'=>9]);ck(!$r['actions']&&$r['tool_receipt']['status']==='clarification_required','navigation offer does not open the editor');saveNav($r);
$r=runNav('confirm');$a=vp3_agent_tool_internal_action_v400($r['actions'][0],$u,'Yes');ck($a['auto']&&str_contains($a['url'],'track=9'),'yes resolves the server pending target and permits navigation without an open keyword');saveNav($r);
$r=runNav('confirm');ck(!$r['actions'],'a completed offer cannot revive the older pending target');
$r=runNav('open',['destination'=>'stem_studio','track_id'=>10]);ck(!$r['actions']&&!str_contains($r['answer'],'SECRET'),'foreign Studio tracks cannot leak through a navigation command');
$r=runNav('prepare',['destination'=>'stem_studio','track_id'=>9]);saveNav($r,2);ck(vp3_navigation_last_v1($pdo,$u,$p,['conversation_id'=>2])===null,'another Agent conversation cannot supply the pending offer');
$r['navigation_context']['expires_at']=1;saveNav($r);ck(!runNav('confirm')['actions'],'expired Studio offer cannot execute');
saveNav(['media'=>[['id'=>9,'title'=>'From the asteroid you saved me']]]);$r=runNav('confirm');ck(str_contains($r['actions'][0]['url'],'track=9'),'a previous single canonical media offer supports the reported yes-to-open sequence');
saveNav(['media'=>[['id'=>9],['id'=>10]]]);ck(!runNav('confirm')['actions'],'multiple media suggestions require a choice rather than guessing');
saveNav(['media'=>[['id'=>9]]],1,gmdate('Y-m-d H:i:s',time()-1800));ck(!runNav('confirm')['actions'],'stale track suggestions cannot be revived by yes');
$r=runNav('open',['destination'=>'contacts']);$a=vp3_agent_tool_internal_action_v400($r['actions'][0],$u,'Go to contacts');ck($a['url']==='/contacts.php'&&$a['auto'],'generic navigation uses the authorized canonical destination');
unset($GLOBALS['vp3_navigation_approved_v1']);$a=vp3_agent_tool_internal_action_v400(['type'=>'open_url','url'=>'/contacts.php','navigation_target'=>'contacts'],$u,'Hello');ck($a===null,'a model/browser tag cannot authorize automatic generic navigation');
$a=vp3_agent_tool_internal_action_v400(['type'=>'open_url','url'=>'/admin/stems.php?track=9','navigation_target'=>'stem_studio'],$u,'Hello');ck(!$a['auto'],'a forged Studio tag cannot trigger automatic navigation');
$r=runNav('open',['destination'=>'music_workspace']);ck(!$r['actions']&&$r['tool_receipt']['status']==='blocked','navigation visibility does not grant an unavailable music workspace');
$GLOBALS['denied']['chat.access']=true;$r=runNav('open',['destination'=>'contacts']);ck(!$r['actions'],'Chat permission revocation prevents navigation');
echo "AGENT_NAVIGATION=PASS ($checks cases; current catalog, conversation scope and actual final authorization)\n";
