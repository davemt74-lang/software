<?php
declare(strict_types=1);
session_save_path(sys_get_temp_dir());session_start();
require dirname(__DIR__).'/includes/profile-webmcp-continuity-v195.php';
function t(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$user=['id'=>7];$profile=['user_id'=>7,'username'=>'demo','is_public'=>1,'is_active'=>1];
$binding=['contract'=>'vp3.webmcp.owner-context.v1','user_id'=>7,'agent_id'=>9,'conversation_id'=>42];
$issued=vp3_profile_webmcp_action_context_issue_v195($profile,$user,'SECRET private goal',42,$binding,['vp3.booking.prepare','vp3.booking.confirm']);
t((bool)preg_match('/^[a-f0-9]{32}$/',$issued['context_id']),'opaque context id');
t((bool)preg_match('/^[a-f0-9]{32}$/',$issued['return_token']),'opaque return token');
t(str_contains($issued['return_path'],'agent=9&conversation_id=42'),'return route retains source binding');
$row=$_SESSION['vp3_profile_webmcp_action_context_v195'][$issued['context_id']];
t($row['binding']===$binding,'exact source binding is retained');
t(!isset($row['server_action']),'handoff does not establish action evidence');
$row['phase']='completed';$row['tool']='SECRET';$row['idempotent_replay']=true;
$public=vp3_profile_webmcp_action_context_public_v195($row);
t($public['phase']==='unverified'&&$public['outcome']['verification']==='unverified','client-only completed phase stays unverified');
t($public['idempotent_replay']===false,'client-only replay flag is ignored');
t(!str_contains(json_encode($public),'SECRET'),'safe projection omits original goal and browser tool');
t($public['contains_sensitive_payload']===false&&$public['execution_allowed']===false,'return is a safe read-only projection');
t(vp3_profile_webmcp_action_context_note_v195($profile,$user,$issued['context_id'],$issued['return_token'],'vp3.booking.confirm','completed','',true)===null,'browser notes require live database authority');
t(vp3_profile_webmcp_return_consume_v195($profile,$user,$issued['return_token'])===null,'return cannot consume without live authority');
t(vp3_profile_webmcp_action_context_validate_v195($profile,['id'=>8],$issued['context_id'],$issued['return_token'])===null,'wrong owner denied');
$now=time();$expired=$row;$expired['expires_at']=$now-1;$future=$row;$future['created_at']=$now+10;
t(vp3_profile_webmcp_action_context_prune_v195([str_repeat('a',32)=>$expired,str_repeat('b',32)=>$future],$now)===[],'expired and future contexts are pruned');
$rejected=false;try{vp3_profile_webmcp_action_context_issue_v195($profile,$user,'Open profile',42);}catch(Throwable $e){$rejected=true;}
t($rejected,'legacy unbound context cannot be minted');
echo "PROFILE_WEBMCP_CONTINUITY_V195_PHP=PASS\n";
