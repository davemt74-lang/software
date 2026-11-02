<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-receipts-v1.php';

function vp3_participant_conversation_ready_v2(PDO $pdo): bool
{
    return function_exists('vp3_participant_updates_ready_v1')&&function_exists('vp3_task_lists_ready_v1')&&vp3_participant_updates_ready_v1($pdo)&&vp3_task_lists_ready_v1($pdo);
}
function vp3_participant_result_v2(string $text,string $cap,string $op,string $status='read_complete',int $count=0): array
{
    return ['handled'=>true,'answer'=>$text,'sources'=>[],'actions'=>[],'sticky_notes'=>[],'tool_receipt'=>vp3_tool_receipt_v1($cap,$op,$status,'',0,$count)];
}
/** Names are choices, never account or recognition authority. No self-profile creation on reads. */
function vp3_participant_catalog_v2(PDO $pdo,array $u,array $p): array
{
    vp3_task_list_authorize_v1($pdo,$u,$p,0);
    if(vp3_participant_scope_v1()!==null)throw new RuntimeException('The signed-in account owner manages participant setup.',403);
    $q=$pdo->prepare('SELECT id,display_name,recognition_consent FROM studio_participants WHERE owner_user_id=? AND is_active=1 ORDER BY display_name,id LIMIT 100');$q->execute([(int)$u['id']]);$profiles=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$profiles[]=['id'=>(int)$r['id'],'name'=>$r['display_name'],'recognition_consent'=>!empty($r['recognition_consent'])];
    $q=$pdo->prepare('SELECT id,name FROM agent_task_lists_v1 WHERE owner_user_id=? AND agent_scope_id=? ORDER BY name,id LIMIT 100');$q->execute([(int)$u['id'],vp3_task_list_agent_v1($p)]);$lists=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$lists[]=['id'=>(int)$r['id'],'name'=>$r['name']];
    return ['profiles'=>$profiles,'lists'=>$lists,'bounded'=>true];
}
/** This writes only a proposal in the existing list-action ledger, never a permission. */
function vp3_participant_prepare_v2(PDO $pdo,array $u,array $p,array $plan,array $ctx): array
{
    $cid=(int)($ctx['conversation_id']??0);vp3_task_list_authorize_v1($pdo,$u,$p,$cid);
    if(!vp3_participant_conversation_ready_v2($pdo))return vp3_participant_result_v2('Run upgrade.php to enable participant setup.','participants.setup','prepare','blocked');
    if($cid<1)throw new RuntimeException('Open a conversation before participant setup.',409);
    $kind=$plan['action']??'';$pid=$plan['participant_id']??0;$lid=$plan['list_id']??0;$mode=$plan['access_mode']??'choose';
    if(!in_array($kind,['link_self','share_list'],true)||!is_int($pid)||$pid<0||!is_int($lid)||$lid<0||!in_array($mode,['read','edit','choose'],true))throw new InvalidArgumentException('Invalid participant setup request.');
    $scope=vp3_participant_scope_v1();if($scope!==null&&($kind!=='link_self'||$pid!==(int)$scope['participant']))throw new RuntimeException('Only the account owner can prepare sharing.',403);
    $person=$pid?vp3_participant_owned_v1($pdo,$u,$pid):null;$list=null;
    if($kind==='share_list'&&$lid){$q=$pdo->prepare('SELECT id,name FROM agent_task_lists_v1 WHERE id=? AND owner_user_id=? AND agent_scope_id=?');$q->execute([$lid,(int)$u['id'],vp3_task_list_agent_v1($p)]);$list=$q->fetch(PDO::FETCH_ASSOC);if(!$list)throw new RuntimeException('List unavailable in this Agent.',403);}
    if($kind==='link_self'&&($lid||$mode!=='choose'))throw new InvalidArgumentException('Unexpected profile link fields.');
    $s=vp3_participant_settings_v1($pdo,$u);$token=bin2hex(random_bytes(16));$action=['version'=>2,'kind'=>$kind,'participant_id'=>$pid,'list_id'=>$lid,'access_mode'=>$mode,'settings_revision'=>$s['revision']];
    $pdo->prepare("INSERT INTO agent_task_list_actions_v1(token,owner_user_id,agent_scope_id,conversation_id,action_json,status,expires_at) VALUES(?,?,?,?,?,'prepared',?)")->execute([$token,(int)$u['id'],vp3_task_list_agent_v1($p),$cid,json_encode($action,JSON_THROW_ON_ERROR),time()+900]);
    $text=$kind==='link_self'?'Review which recognized profile is yours. The signed-in owner must confirm before it can use this account’s personal task tools.':'Review sharing '.($list?'“'.$list['name'].'”':'a list').' with '.($person?$person['display_name']:'a participant').'. Choose view or edit access, then confirm. No access has changed yet.';
    $r=vp3_participant_result_v2($text,'participants.setup','prepare','clarification_required');
    $r['actions']=[vp3_participant_card_action_v2($u,$token,$cid)];return $r;
}
function vp3_participant_card_action_v2(array $u,string $token,int $cid): array
{
    $GLOBALS['VP3_PARTICIPANT_CARDS_V2'][$token]=['owner'=>(int)$u['id'],'conversation_id'=>$cid];
    return ['type'=>'participant_setup','token'=>$token,'conversation_id'=>$cid,'label'=>'Review participant setup','auto'=>false];
}
function vp3_participant_proposal_row_v2(PDO $pdo,array $u,array $p,string $token,int $cid,bool $lock=false): array
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$token)||$cid<1)throw new InvalidArgumentException('Invalid participant proposal.');
    vp3_task_list_authorize_v1($pdo,$u,$p,$cid);
    $q=$pdo->prepare('SELECT * FROM agent_task_list_actions_v1 WHERE token=? AND owner_user_id=? AND agent_scope_id=? AND conversation_id=?'.($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$token,(int)$u['id'],vp3_task_list_agent_v1($p),$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $action=$row?json_decode($row['action_json'],true):null;
    if(!$row||!is_array($action)||($action['version']??0)!==2||!in_array($action['kind']??'', ['link_self','share_list'],true))throw new RuntimeException('Participant setup not found.',404);
    if($row['status']==='prepared'&&(int)$row['expires_at']<time())throw new RuntimeException('This setup expired. Ask again in Chat.',409);
    return [$row,$action];
}
/** Owner-only review, including a digest to fence edits made after the card was loaded. */
function vp3_participant_review_v2(PDO $pdo,array $u,array $p,string $token,int $cid): array
{
    if(vp3_participant_scope_v1()!==null)throw new RuntimeException('Owner confirmation is required.',403);
    [$row,$action]=vp3_participant_proposal_row_v2($pdo,$u,$p,$token,$cid);
    if($row['status']!=='prepared')return ['status'=>$row['status'],'result'=>json_decode((string)$row['result_json'],true)];
    $settings=vp3_participant_settings_v1($pdo,$u);if($settings['revision']!==$action['settings_revision'])throw new RuntimeException('Participant settings changed. Ask for a fresh review.',409);
    $choices=vp3_participant_catalog_v2($pdo,$u,$p);
    if($action['participant_id']){$person=vp3_participant_owned_v1($pdo,$u,$action['participant_id']);$choices['profiles']=[['id'=>(int)$person['id'],'name'=>$person['display_name'],'recognition_consent'=>!empty($person['recognition_consent'])]];}
    if($action['list_id']){$q=$pdo->prepare('SELECT id,name FROM agent_task_lists_v1 WHERE id=? AND owner_user_id=? AND agent_scope_id=?');$q->execute([$action['list_id'],(int)$u['id'],vp3_task_list_agent_v1($p)]);$list=$q->fetch(PDO::FETCH_ASSOC);if(!$list)throw new RuntimeException('List unavailable.',403);$choices['lists']=[['id'=>(int)$list['id'],'name'=>$list['name']]];}
    $digest=hash('sha256',json_encode([$action,$choices,$settings],JSON_THROW_ON_ERROR));
    return ['status'=>'prepared','kind'=>$action['kind'],'participant_id'=>$action['participant_id'],'list_id'=>$action['list_id'],'access_modes'=>$action['access_mode']==='choose'?['read','edit']:[$action['access_mode']],'profiles'=>$choices['profiles'],'lists'=>$action['kind']==='share_list'?$choices['lists']:[],'revision'=>$digest,'enabled'=>$settings['enabled'],'expires_at'=>(int)$row['expires_at']];
}
/** An explicit authenticated owner button calls this endpoint. No model or bare yes can call it. */
function vp3_participant_confirm_v2(PDO $pdo,array $u,array $p,array $input): array
{
    if(vp3_participant_scope_v1()!==null||($input['owner_confirmed']??false)!==true)throw new RuntimeException('Explicit confirmation from the signed-in owner is required.',403);
    $token=$input['token']??'';$cid=$input['conversation_id']??0;$cancel=($input['decision']??'')==='cancel';
    if(!is_string($token)||!is_int($cid)||!in_array($input['decision']??'', ['confirm','cancel'],true))throw new InvalidArgumentException('Invalid confirmation.');
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([(int)$u['id']]);
        $u=vp3_capability_live_user_v1($pdo,$u,$p,['conversation_id'=>$cid],null);
        [$row,$action]=vp3_participant_proposal_row_v2($pdo,$u,$p,$token,$cid,true);
        if($row['status']!=='prepared'){$result=json_decode((string)$row['result_json'],true);if(!$result)throw new RuntimeException('This setup is no longer pending.',409);$pdo->commit();return $result+['replayed'=>true];}
        $changed=false;$pid=0;$mode='';$lid=0;
        if($cancel)$text='Participant setup cancelled. No profile mapping or sharing was changed.';
        else{
            $review=vp3_participant_review_v2($pdo,$u,$p,$token,$cid);
            if(!is_string($input['revision']??null)||!hash_equals($review['revision'],$input['revision']))throw new RuntimeException('The choices changed. Reload this review before confirming.',409);
            $pid=$input['participant_id']??0;if(!is_int($pid)||$pid<1||($action['participant_id']&&$pid!==$action['participant_id'])||!in_array($pid,array_column($review['profiles'],'id'),true))throw new RuntimeException('Choose the reviewed participant.',403);
            $q=$pdo->prepare('SELECT * FROM studio_participants WHERE id=? AND owner_user_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$pid,(int)$u['id']]);$person=$q->fetch(PDO::FETCH_ASSOC);if(!$person||empty($person['is_active']))throw new RuntimeException('Participant changed. Ask again.',403);
            $reviewed=array_values(array_filter($review['profiles'],fn($r)=>$r['id']===$pid))[0];
            if($person['display_name']!==$reviewed['name']||!empty($person['recognition_consent'])!==$reviewed['recognition_consent'])throw new RuntimeException('The participant changed after review. Reload the choices.',409);
            $settings=vp3_participant_settings_v1($pdo,$u);
            if($action['kind']==='link_self'){
                $changed=!$settings['enabled']||$settings['self_participant_id']!==$pid;
                if(!$settings['revision'])$pdo->prepare('INSERT INTO agent_participant_settings_v1(owner_user_id) VALUES(?)')->execute([(int)$u['id']]);
                if($changed)$pdo->prepare('UPDATE agent_participant_settings_v1 SET self_participant_id=?,enabled=1,revision=revision+1 WHERE owner_user_id=?')->execute([$pid,(int)$u['id']]);
                $text='Linked “'.$person['display_name'].'” to your signed-in account for personal task conversations. Participant-aware voice tasks are enabled. Recognition remains provisional.';
                if(empty($person['recognition_consent']))$text.=' Set up recognition with “Learn my voice” before using voice identification.';
            }else{
                $lid=$input['list_id']??0;$mode=$input['access_mode']??'';
                if(!is_int($lid)||$lid<1||($action['list_id']&&$action['list_id']!==$lid)||!in_array($lid,array_column($review['lists'],'id'),true)||!in_array($mode,$review['access_modes'],true))throw new RuntimeException('Choose the reviewed list and access level.',403);
                $q=$pdo->prepare('SELECT name FROM agent_task_lists_v1 WHERE id=? AND owner_user_id=? AND agent_scope_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$lid,(int)$u['id'],vp3_task_list_agent_v1($p)]);$name=$q->fetchColumn();if($name===false)throw new RuntimeException('List unavailable.',403);
                $reviewedList=array_values(array_filter($review['lists'],fn($r)=>$r['id']===$lid))[0];if($name!==$reviewedList['name'])throw new RuntimeException('The list changed after review. Reload the choices.',409);
                $q=$pdo->prepare("SELECT access_mode FROM agent_participant_grants_v1 WHERE owner_user_id=? AND participant_id=? AND agent_scope_id=? AND resource_kind='task_list' AND resource_id=?");$key=[(int)$u['id'],$pid,vp3_task_list_agent_v1($p),$lid];$q->execute($key);$changed=$q->fetchColumn()!==$mode;
                if($changed){
                    if(!$settings['revision'])$pdo->prepare('INSERT INTO agent_participant_settings_v1(owner_user_id) VALUES(?)')->execute([(int)$u['id']]);
                    $pdo->prepare("DELETE FROM agent_participant_grants_v1 WHERE owner_user_id=? AND participant_id=? AND agent_scope_id=? AND resource_kind='task_list' AND resource_id=?")->execute($key);
                    $pdo->prepare("INSERT INTO agent_participant_grants_v1(owner_user_id,participant_id,agent_scope_id,resource_kind,resource_id,access_mode) VALUES(?,?,?,'task_list',?,?)")->execute([...$key,$mode]);
                    $pdo->prepare('UPDATE agent_participant_settings_v1 SET revision=revision+1 WHERE owner_user_id=?')->execute([(int)$u['id']]);
                }
                $text='Shared “'.$name.'” with '.$person['display_name'].': '.($mode==='edit'?'can view, add items and update its tasks.':'can view only.');
                if(!$settings['enabled'])$text.=' Participant-aware voice tasks are off. Link your own profile in Chat to enable them.';
            }
        }
        $receipt=vp3_tool_receipt_v1('participants.setup',$cancel?'cancel':'confirm',$changed?'completed':'read_complete');
        $result=['kind'=>$action['kind'],'decision'=>$cancel?'cancel':'confirm','participant_id'=>$pid,'participant_name'=>$cancel?'':$person['display_name'],'list_id'=>$lid,'access_mode'=>$mode,'answer'=>$text,'tool_receipt'=>$receipt,'settings'=>vp3_participant_settings_v1($pdo,$u),'confirmed_at'=>gmdate('c')];
        $q=$pdo->prepare('INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,?,?,?,?)');
        $q->execute([$cid,(int)$u['id'],'user',$cancel?'Cancel participant setup':'Confirm participant '.($action['kind']==='link_self'?'profile mapping':'list sharing'),json_encode(['participant_setup_confirmation'=>true,'token'=>$token],JSON_THROW_ON_ERROR)]);
        $q->execute([$cid,null,'assistant',$text,json_encode(['tool_receipt'=>$receipt,'participant_setup_receipt'=>$token],JSON_THROW_ON_ERROR)]);$result['assistant_message_id']=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,(int)$u['id']]);
        $pdo->prepare('UPDATE agent_task_list_actions_v1 SET status=?,result_json=? WHERE token=?')->execute([$cancel?'cancelled':'completed',json_encode($result,JSON_THROW_ON_ERROR),$token]);
        $pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function vp3_participant_presence_chat_v2(PDO $pdo,array $u,array $p,?int $now=null): array
{
    vp3_task_list_authorize_v1($pdo,$u,$p,0);$now??=time();$lines=[];
    $q=$pdo->prepare('SELECT s.display_name,r.last_seen,r.last_heard FROM agent_participant_presence_v1 r JOIN studio_participants s ON s.id=r.participant_id AND s.owner_user_id=r.owner_user_id WHERE r.owner_user_id=? AND s.is_active=1 AND s.recognition_consent=1 AND (r.last_seen>=? OR r.last_heard>=?) ORDER BY r.last_activity DESC LIMIT 4');$q->execute([(int)$u['id'],$now-45,$now-90]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$e=[];if((int)$r['last_seen']>=$now-45)$e[]='seen '.gmdate('H:i:s',(int)$r['last_seen']).' UTC';if((int)$r['last_heard']>=$now-90)$e[]='heard '.gmdate('H:i:s',(int)$r['last_heard']).' UTC';$lines[]=$r['display_name'].' — '.implode('; ',$e);}
    return vp3_participant_result_v2($lines?"Recent room observations:\n".implode("\n",$lines)."\nThese are provisional observations, not proof everyone is still present.":'No participants were seen in the last 45 seconds or heard in the last 90 seconds.','participants.read','presence',$lines?'read_complete':'no_matches',count($lines));
}
function vp3_participant_overview_v2(PDO $pdo,array $u,array $p,int $participant=0,?DateTimeImmutable $now=null): array
{
    $data=vp3_participant_resources_v1($pdo,$u,$p);$lines=[];$seen=[];$refs=[];$partial=false;$count=0;
    foreach($data['lists'] as $list){$items=[];foreach($list['items'] as $t)if(!in_array($t['status'],['completed','cancelled'],true)&&!isset($seen[$t['id']])){$seen[$t['id']]=true;$items[]='  #'.$t['id'].' · '.$t['title'].' · '.$t['status'];$count++;}
        if($items){$lines[]='“'.$list['title'].'”';$lines=[...$lines,...$items];$refs[]=['kind'=>'task_list','id'=>$list['id']]+($participant?['participant_id'=>$participant]:[]);}$partial=$partial||!empty($list['truncated']);}
    foreach($data['tasks'] as $t)if(!isset($seen[$t['id']])&&!in_array($t['status'],['completed','cancelled'],true)){$seen[$t['id']]=true;$lines[]='Task #'.$t['id'].' · '.$t['title'].' · '.$t['status'];$count++;}
    $now??=reminder_now_v1();$due=[];
    if(reminder_ready_v1($pdo))foreach(reminder_list_rows_v1($pdo,(int)$u['id'],'open','UTC',$now) as $r){if(!vp3_participant_resource_v1($pdo,$u,$p,'reminder',(int)$r['id']))continue;if(!empty($r['list_at'])&&$r['list_at']<=reminder_utc_v1($now)){$due[]=reminder_list_label_v1($r);$count++;}}
    if($due){$lines[]='Due reminders:';$lines=[...$lines,...$due];$refs[]=['kind'=>'reminders','view'=>'open','timezone'=>'UTC']+($participant?['participant_id'=>$participant]:[]);}
    $text=$lines?"Here is your current open work".($participant?' shared with this participant':'').":\n".implode("\n",$lines):'No open tasks or due reminders were found in the bounded records available here.';
    $text.="\nThis overview reads up to 100 lists, 100 recent tasks and 100 reminders. Nothing was marked done or delivered.";
    if($partial)$text.=" List previews are partial; open the notes for more items.";
    $r=vp3_participant_result_v2($text,'tasks.overview','read',$count?'read_complete':'no_matches',$count);$r['sticky_notes']=array_slice($refs,0,8);return $r;
}
function vp3_participant_pending_v2(PDO $pdo,array $u,array $p,array $ctx): ?array
{
    $cid=(int)($ctx['conversation_id']??0);if($cid<1||!vp3_participant_conversation_ready_v2($pdo))return null;
    vp3_task_list_authorize_v1($pdo,$u,$p,$cid);
    $q=$pdo->prepare("SELECT context_json FROM chat_messages WHERE conversation_id=? AND role='assistant' ORDER BY id DESC LIMIT 1");$q->execute([$cid]);$data=json_decode((string)$q->fetchColumn(),true);
    foreach($data['actions']??[] as $a)if(($a['type']??'')==='participant_setup'&&is_string($a['token']??null)){
        try{[$row,$action]=vp3_participant_proposal_row_v2($pdo,$u,$p,$a['token'],$cid);if($row['status']==='prepared')return ['token'=>$a['token'],'kind'=>$action['kind']];}catch(Throwable $e){}
    }
    return null;
}
function vp3_participant_owner_tool_v2(PDO $pdo,array $u,array $p,array $plan,array $ctx): array
{
    if(!vp3_participant_conversation_ready_v2($pdo))return vp3_participant_result_v2('Run upgrade.php to enable participant conversations.','participants.setup','prepare','blocked');
    if($plan['action']==='review'){
        $pending=vp3_participant_pending_v2($pdo,$u,$p,$ctx);$r=vp3_participant_result_v2($pending?'Use the review card below to choose the exact profile or sharing level and confirm. A spoken yes does not change access.':'There is no current participant setup awaiting review. Tell me which profile or list to set up.','participants.setup','prepare','clarification_required');
        if($pending)$r['actions']=[vp3_participant_card_action_v2($u,$pending['token'],(int)$ctx['conversation_id'])];return $r;
    }
    if($plan['action']==='presence')return vp3_participant_presence_chat_v2($pdo,$u,$p);
    if($plan['action']==='overview')return vp3_participant_overview_v2($pdo,$u,$p);
    return vp3_participant_prepare_v2($pdo,$u,$p,$plan,$ctx);
}
