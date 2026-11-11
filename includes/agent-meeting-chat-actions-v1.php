<?php
declare(strict_types=1);

require_once __DIR__.'/agent-meeting-command-v1.php';
require_once __DIR__.'/agent-meeting-action-sources-v1.php';
require_once __DIR__.'/agent-meeting-management-v1.php';

/** Foreground reviews share Workflow history and canonical domain services.
 * They require the owner's current authority, independently of Initiative. */
function vp3_meeting_chat_scope_v1(PDO $pdo,array $user,array $principal,int $cid,?callable $authority=null): array
{
    if($authority)$authority();
    if(vp3_participant_scope_v1()!==null||!knowledge_retrieval_v162_owner_session($user,$principal))
        throw new RuntimeException('Use your private Agent Chat for meeting actions.',403);
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],$authority);
    if(!has_permission('account.access',$live)||$cid<1)throw new RuntimeException('Meeting action access changed.',403);
    if(!agent_workflow_schema_ready_v1400($pdo)||!table_exists('agent_workflow_receipts'))
        throw new RuntimeException('Meeting action history is unavailable. Run Database Upgrade.',503);
    return $live;
}

function vp3_meeting_chat_latest_v1(PDO $pdo,int $cid,?int $before=null): ?array
{
    $q=$pdo->prepare('SELECT id,role,message,context_json FROM chat_messages WHERE conversation_id=?'.($before!==null?' AND id<?':'').' ORDER BY id DESC LIMIT 1');
    $q->execute($before!==null?[$cid,$before]:[$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

function vp3_meeting_chat_possible_v1(PDO $pdo,int $cid,string $query): bool
{
    if(vp3_meeting_command_intent_v1($query))return true;
    if($cid<1||!preg_match('/\A(?:yes|yes please|confirm|go ahead|do it|cancel|no|no thanks)\s*[.!]?\z/i',trim($query)))return false;
    $row=vp3_meeting_chat_latest_v1($pdo,$cid);$c=json_decode((string)($row['context_json']??''),true);
    return ($row['role']??null)==='assistant'&&is_array($c['meeting_action_review_v1']??null);
}

/** Lost plain confirmations remain associated with their original request even
 * after a newer, unrelated turn. No fresh action is inferred from that retry. */
function vp3_meeting_chat_request_v1(PDO $pdo,array $user,int $aid,int $cid,string $query,string $key=''): bool
{
    if(vp3_meeting_chat_possible_v1($pdo,$cid,$query))return true;
    if($cid<1||trim($key)==='')return false;
    $hash=hash('sha256',json_encode([(int)$user['id'],$aid,$cid,trim($key)],JSON_THROW_ON_ERROR));
    $q=$pdo->prepare("SELECT context_json FROM chat_messages WHERE conversation_id=? AND role='assistant' AND context_json LIKE ? ORDER BY id DESC");
    $q->execute([$cid,'%"request_key":"'.$hash.'"%']);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$c=json_decode((string)$row['context_json'],true);if(($c['meeting_action_request_v1']['request_key']??null)===$hash)return true;}
    return false;
}

function vp3_meeting_chat_load_v1(PDO $pdo,array $user,array $principal,int $cid,int $id): array
{
    $uid=(int)$user['id'];$aid=(int)($principal['agent_id']??0);
    $q=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE id=? AND owner_user_id=? AND source_kind='meeting_chat_action'".($pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
    $q->execute([$id,$uid]);$run=$q->fetch(PDO::FETCH_ASSOC);
    if(!$run||(int)($run['agent_id']??0)!==$aid||!in_array($run['status'],['approval_pending','completed','cancelled'],true))throw new RuntimeException('This meeting action is unavailable.',409);
    $goal=json_decode((string)$run['goal'],true,64,JSON_THROW_ON_ERROR);$binding=$goal['binding']??[];
    if(($goal['version']??null)!==1||($binding['owner_user_id']??null)!==$uid||($binding['agent_id']??null)!==$aid
        ||($binding['conversation_id']??null)!==$cid||!is_array($goal['plan']??null)
        ||!hash_equals(hash('sha256',json_encode($goal['plan'],JSON_THROW_ON_ERROR)),(string)($goal['plan_hash']??'')))
        throw new RuntimeException('The meeting action review changed.',409);
    $q=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $q->execute([$binding['owner_message_id'],$cid,$uid]);$request=$q->fetchColumn();
    if(!is_string($request)||!hash_equals(hash('sha256',$request),(string)$binding['owner_message_hash']))throw new RuntimeException('The meeting action request changed.',409);
    $q=$pdo->prepare('SELECT * FROM agent_workflow_actions WHERE run_id=? AND owner_user_id=? ORDER BY sequence_no,id');$q->execute([$id,$uid]);$steps=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($steps)!==1||$steps[0]['status']!==$run['status']||$steps[0]['capability_key']!=='meeting.chat_action')throw new RuntimeException('The meeting action step changed.',409);
    $receipt=null;
    if($run['status']==='completed'){
        $q=$pdo->prepare("SELECT * FROM agent_workflow_receipts WHERE run_id=? AND action_id=? AND owner_user_id=? AND status='completed'");
        $q->execute([$id,(int)$steps[0]['id'],$uid]);$receipts=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($receipts)!==1||$receipts[0]['result_json']!==$steps[0]['result_json'])throw new RuntimeException('The meeting action receipt changed.',409);
        $receipt=json_decode((string)$receipts[0]['result_json'],true,64,JSON_THROW_ON_ERROR);
        if(($receipt['plan_hash']??null)!==$goal['plan_hash']||($receipt['action_id']??null)!==(int)$steps[0]['id']||!is_array($receipt['domain_result']??null))throw new RuntimeException('The meeting action result changed.',409);
        if(!is_int($receipt['approval_message_id']??null)||$receipt['approval_message_id']<=$binding['owner_message_id']
            ||!is_string($receipt['approval_message_hash']??null))throw new RuntimeException('The meeting action approval changed.',409);
        $q=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
        $q->execute([$receipt['approval_message_id'],$cid,$uid]);$approved=$q->fetchColumn();
        if(!is_string($approved)||!hash_equals(hash('sha256',$approved),$receipt['approval_message_hash']))throw new RuntimeException('The saved meeting approval changed.',409);
        $identity='meeting-chat-action:'.(int)$steps[0]['id'];
        if(($goal['plan']['family']??null)==='followup'
            &&($receipt['domain_result']['meeting_source_v1']['action_id']??null)!==$identity)throw new RuntimeException('The meeting action identity changed.',409);
    }
    return ['run'=>$run,'action'=>$steps[0],'goal'=>$goal,'receipt'=>$receipt];
}

function vp3_meeting_chat_preview_v1(array $loaded): array
{
    $g=$loaded['goal'];$p=$g['plan'];$id=(int)$loaded['run']['id'];$b=$g['binding'];
    return ['version'=>1,'owner_user_id'=>$b['owner_user_id'],'agent_id'=>$b['agent_id'],'conversation_id'=>$b['conversation_id'],
        'plan_id'=>$id,'plan_hash'=>$g['plan_hash'],'status'=>$loaded['run']['status'],
        'label'=>$g['label'],'summary'=>$g['summary'],'details'=>$g['details'],
        'confirmation_text'=>'Confirm meeting action '.$id];
}

function vp3_meeting_chat_domain_v1(string $operation,PDO $pdo,array $user,array $principal,array $binding,array $plan,?array $result=null,int $actionId=0): array
{
    if(($plan['family']??null)==='meeting'){
        if($operation==='apply')return vp3_meeting_management_apply_v1($pdo,$user,$binding['agent_id'],$binding['conversation_id'],$plan['domain']);
        return vp3_meeting_management_inspect_v1($pdo,$user,$binding['agent_id'],$binding['conversation_id'],$plan['domain'],$result??[]);
    }
    if($operation==='apply')return vp3_meeting_action_apply_v1($pdo,$user,$principal,$binding,$plan['domain'],'meeting-chat-action:'.$actionId);
    return vp3_meeting_action_inspect_v1($pdo,$user,$principal,$binding,$plan['domain'],$result??[]);
}

/** Current private content is rendered from its owner-bound plan, never from a
 * browser-supplied meeting pointer or an unverified language-model result. */
function vp3_meeting_chat_projection_v1(PDO $pdo,array $user,array $principal,int $cid,array $marker): array
{
    try{
        vp3_meeting_chat_scope_v1($pdo,$user,$principal,$cid);
        if(($marker['conversation_id']??null)!==$cid||!is_int($marker['plan_id']??null))throw new RuntimeException('Review scope changed.',409);
        $a=vp3_meeting_chat_load_v1($pdo,$user,$principal,$cid,$marker['plan_id']);
        if(($marker['plan_hash']??null)!==$a['goal']['plan_hash'])throw new RuntimeException('Review changed.',409);
        if(in_array($a['run']['status'],['approval_pending','cancelled'],true))vp3_meeting_action_source_current_v1($pdo,$user,(int)$principal['agent_id'],$cid,$a['goal']['source']);
        elseif($a['run']['status']==='completed')vp3_meeting_chat_domain_v1('inspect',$pdo,$user,$principal,$a['goal']['binding'],$a['goal']['plan'],$a['receipt']['domain_result']);
        $preview=vp3_meeting_chat_preview_v1($a);
        if($a['run']['status']==='completed')$preview['summary']=(string)($a['receipt']['domain_result']['summary']??'The canonical record was saved and verified.');
        $answer=$a['run']['status']==='approval_pending'?'Review this meeting action, then confirm or cancel.':($a['run']['status']==='cancelled'?'Meeting action cancelled.':'Meeting action saved and verified.');
        return ['answer'=>$answer,'meeting_action'=>$preview];
    }catch(Throwable $error){
        $stale=null;
        if(($marker['conversation_id']??null)===$cid&&is_int($marker['plan_id']??null)&&$marker['plan_id']>0
            &&is_string($marker['plan_hash']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$marker['plan_hash']))
            $stale=['version'=>1,'owner_user_id'=>(int)$user['id'],'agent_id'=>(int)($principal['agent_id']??0),'conversation_id'=>$cid,
                'plan_id'=>$marker['plan_id'],'plan_hash'=>$marker['plan_hash'],'status'=>'stale','label'=>'Meeting action unavailable',
                'summary'=>'Ask for a fresh review.','details'=>[],'confirmation_text'=>'Confirm meeting action '.$marker['plan_id']];
        return ['answer'=>'This meeting action is no longer current. Ask for a fresh review.','meeting_action'=>$stale];
    }
}

function vp3_meeting_chat_replay_v1(PDO $pdo,array $user,array $principal,array $input,array $saved,?callable $authority=null): array
{
    $cid=(int)($saved['conversation_id']??0);
    vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input);
    vp3_meeting_chat_scope_v1($pdo,$user,$principal,$cid,$authority);
    if((int)($input['conversation_id']??0)>0&&(int)$input['conversation_id']!==$cid)throw new RuntimeException('The meeting action conversation changed.',409);
    $q=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $q->execute([(int)($saved['user_message_id']??0),$cid,(int)$user['id']]);$query=$q->fetchColumn();
    if(!is_string($query)||$query!==trim((string)($input['message']??'')))throw new RuntimeException('The meeting action request changed.',409);
    $q=$pdo->prepare("SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant'");
    $q->execute([(int)($saved['assistant_message_id']??0),$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('The meeting action response is unavailable.',409);
    $c=json_decode((string)$row['context_json'],true,64,JSON_THROW_ON_ERROR);
    $view=is_array($c['meeting_action_review_v1']??null)?vp3_meeting_chat_projection_v1($pdo,$user,$principal,$cid,$c['meeting_action_review_v1']):['answer'=>$row['message'],'meeting_action'=>null];
    return array_replace($saved,$view,['replayed'=>true]);
}

/** Conversation inference receives the same authorized result as display.
 * Storage keeps a content-free marker; an old marker alone proves no effect. */
function vp3_meeting_chat_model_history_v1(PDO $pdo,array $user,array $principal,int $cid,array $rows): array
{
    foreach($rows as &$row){
        if(($row['role']??null)!=='assistant')continue;
        $context=json_decode((string)($row['context_json']??''),true);
        if(!is_array($context['meeting_action_review_v1']??null))continue;
        $view=vp3_meeting_chat_projection_v1($pdo,$user,$principal,$cid,$context['meeting_action_review_v1']);
        $card=$view['meeting_action'];$text=$view['answer'];
        if(is_array($card)&&$card['status']!=='stale'){
            try{
                $loaded=vp3_meeting_chat_load_v1($pdo,$user,$principal,$cid,$card['plan_id']);
                if(($loaded['goal']['plan']['family']??null)==='followup'){
                    $sid=(int)($loaded['goal']['source']['access']['recording_id']??0);
                    if($sid>0){
                        $session=vp3_transcript_session_v1($pdo,$user,$principal,$sid);
                        video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$session,true);
                    }
                }
            }catch(Throwable $error){$card=null;$text='A private meeting action was reviewed. Its current details are unavailable to Cloud AI processing.';}
        }
        if(is_array($card)&&$card['status']!=='stale'){
            $text.="\n".$card['label'].' · '.$card['status'];
            foreach($card['details'] as $detail)$text.="\n".$detail['label'].': '.$detail['value'];
            if($card['status']==='completed')$text.="\n".$card['summary'];
        }
        $row['message']=mb_substr($text,0,6000);
    }
    unset($row);
    return array_map(static fn(array $row):array=>array_intersect_key($row,array_flip(['role','message'])),$rows);
}

function vp3_meeting_chat_send_v1(PDO $pdo,array $user,?array $activeAgent,array $principal,array $input,?callable $authority=null): array
{
    $cid=(int)($input['conversation_id']??0);$uid=(int)$user['id'];$aid=(int)($principal['agent_id']??0);$query=trim((string)$input['message']);
    if($query===''||mb_strlen($query)>6000)throw new RuntimeException('Enter a valid meeting request.',422);
    vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input);
    vp3_meeting_chat_scope_v1($pdo,$user,$principal,$cid,$authority);
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$principal,$input,$authority,$cid,$uid,$aid,$query):array{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$lock->execute([$uid]);
        if(!$lock->fetchColumn())throw new RuntimeException('Meeting action account is unavailable.',403);
        vp3_meeting_chat_scope_v1($pdo,$user,$principal,$cid,$authority);
        $requestKey=trim((string)($input['idempotency_key']??''));
        $requestKey=$requestKey!==''?hash('sha256',json_encode([$uid,$aid,$cid,$requestKey],JSON_THROW_ON_ERROR)):'';
        if($requestKey!==''){
            $q=$pdo->prepare("SELECT id,context_json FROM chat_messages WHERE conversation_id=? AND role='assistant' AND context_json LIKE ? ORDER BY id DESC");
            $q->execute([$cid,'%"request_key":"'.$requestKey.'"%']);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $saved){
                $sc=json_decode((string)$saved['context_json'],true);$sr=$sc['meeting_action_request_v1']??null;
                if(!is_array($sr)||($sr['request_key']??null)!==$requestKey)continue;
                if(($sr['query_hash']??null)!==hash('sha256',$query))throw new RuntimeException('The meeting action request changed.',409);
                return vp3_meeting_chat_replay_v1($pdo,$user,$principal,$input,['ok'=>true,'conversation_id'=>$cid,'agent_id'=>$aid,
                    'user_message_id'=>$sr['user_message_id'],'assistant_message_id'=>(int)$saved['id'],'input_mode'=>($input['input_mode']??'text')==='voice'?'voice':'text',
                    'sources'=>[],'actions'=>[],'media'=>[],'execution'=>['mode'=>'cloud','status'=>'completed']],$authority);
            }
        }
        $previous=vp3_meeting_chat_latest_v1($pdo,$cid);$previousContext=json_decode((string)($previous['context_json']??''),true);
        $control=null;$planId=0;
        if(preg_match('/\A(Confirm|Cancel) meeting action ([1-9][0-9]{0,15})\z/i',$query,$m)){$control=strtolower($m[1]);$planId=(int)$m[2];}
        elseif(preg_match('/\A(?:yes|yes please|confirm|go ahead|do it|cancel|no|no thanks)\s*[.!]?\z/i',$query)){
            $planId=(int)($previousContext['meeting_action_review_v1']['plan_id']??0);
            $control=preg_match('/\A(?:cancel|no)\b/i',$query)?'cancel':'confirm';
        }
        $context=['skip_brain_archive'=>true];$preview=null;$answer='';$binding=null;$loaded=null;
        if($control!==null){
            $loaded=vp3_meeting_chat_load_v1($pdo,$user,$principal,$cid,$planId);
            $marker=['plan_id'=>$planId,'plan_hash'=>$loaded['goal']['plan_hash'],'conversation_id'=>$cid];
            if($loaded['run']['status']==='approval_pending'&&(($previous['role']??null)!=='assistant'||($previousContext['meeting_action_review_v1']??null)!==$marker))
                throw new RuntimeException('Show the current meeting action review before confirming.',409);
        }else{
            $selection=vp3_meeting_action_source_v1($pdo,$user,$aid,$cid,vp3_meeting_command_selector_v1($query));
            if(($selection['status']??null)!=='selected'){
                $answer=($selection['status']??null)==='ambiguous'?'Which meeting should I use?':'I need an available meeting source. Ask for your next meeting prep, or name an owned meeting.';
                foreach($selection['choices']??[] as $choice)$answer.="\n".(string)($choice['title']??'Meeting').' (#'.(int)$choice['meeting_id'].')';
            }else{
                $parsed=vp3_meeting_command_parse_v1($query,$selection,$user);
                if(($parsed['status']??null)!=='ready'){
                    $answer=(string)($parsed['answer']??'Please specify the meeting action and its exact details.');
                    foreach(array_slice($parsed['choices']??[],0,20) as $choice)$answer.="\n".(string)($choice['label']??'Finding').' ('.(string)($choice['ref']??'').')';
                }
            }
        }
        $q=$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,?,'user',?,?)");$q->execute([$cid,$uid,$query,json_encode($context,JSON_THROW_ON_ERROR)]);$mid=(int)$pdo->lastInsertId();
        if($control!==null&&$loaded!==null){
            if($loaded['run']['status']==='approval_pending'){
                if($control==='cancel'){
                    $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled',approval_status='rejected',cancelled_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([$planId,$uid]);
                    $pdo->prepare("UPDATE agent_workflow_actions SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([(int)$loaded['action']['id'],$uid]);
                    agent_workflow_event_v1400($pdo,$uid,$planId,'meeting_action_cancelled','approval_pending','cancelled','owner','Owner cancelled the reviewed meeting action.');
                }else{
                    vp3_meeting_action_source_current_v1($pdo,$user,$aid,$cid,$loaded['goal']['source']);
                    $domain=vp3_meeting_chat_domain_v1('apply',$pdo,$user,$principal,$loaded['goal']['binding'],$loaded['goal']['plan'],null,(int)$loaded['action']['id']);
                    if(($domain['verified']??false)!==true)throw new RuntimeException('The meeting action could not be verified.',503);
                    vp3_meeting_chat_domain_v1('inspect',$pdo,$user,$principal,$loaded['goal']['binding'],$loaded['goal']['plan'],$domain);
                    vp3_meeting_chat_scope_v1($pdo,$user,$principal,$cid,$authority);
                    $proof=['version'=>1,'action_id'=>(int)$loaded['action']['id'],'plan_hash'=>$loaded['goal']['plan_hash'],'approval_message_id'=>$mid,'approval_message_hash'=>hash('sha256',$query),'domain_result'=>$domain];
                    $json=json_encode($proof,JSON_THROW_ON_ERROR);$stepId=(int)$loaded['action']['id'];
                    $pdo->prepare("UPDATE agent_workflow_actions SET status='completed',attempt_count=1,result_json=?,completed_at=UTC_TIMESTAMP(),started_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([$json,$stepId,$uid]);
                    $pdo->prepare("INSERT INTO agent_workflow_receipts(run_id,action_id,owner_user_id,receipt_key,executor,worker_id,attempt_no,status,summary,result_json,error_class) VALUES(?,?,?,?,'cloud','meeting-owner-action',1,'completed','Canonical meeting action verified.',?,'')")->execute([$planId,$stepId,$uid,hash('sha256','meeting-chat-action:'.$stepId),$json]);
                    $pdo->prepare("UPDATE agent_workflow_runs SET status='completed',approval_status='approved',approved_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),attempt_count=1 WHERE id=? AND owner_user_id=?")->execute([$planId,$uid]);
                    agent_workflow_event_v1400($pdo,$uid,$planId,'meeting_action_completed','approval_pending','completed','owner','Reviewed meeting action committed with canonical readback.',['action_id'=>$stepId,'approval_message_id'=>$mid]);
                }
                $loaded=vp3_meeting_chat_load_v1($pdo,$user,$principal,$cid,$planId);
            }
            if($loaded['run']['status']==='completed')vp3_meeting_chat_domain_v1('inspect',$pdo,$user,$principal,$loaded['goal']['binding'],$loaded['goal']['plan'],$loaded['receipt']['domain_result']);
            else vp3_meeting_action_source_current_v1($pdo,$user,$aid,$cid,$loaded['goal']['source']);
            $view=vp3_meeting_chat_projection_v1($pdo,$user,$principal,$cid,$marker);$answer=$view['answer'];$preview=$view['meeting_action'];
            if(($preview['status']??null)==='stale'||$preview===null)throw new RuntimeException('The meeting action response could not be verified.',409);
            $context['meeting_action_review_v1']=$marker;
            if($loaded['run']['status']==='completed'&&is_array($loaded['receipt']['domain_result']['tool_receipt']??null))
                $context['tool_receipt']=$loaded['receipt']['domain_result']['tool_receipt'];
        }elseif($answer===''&&isset($selection['source'],$parsed['request'])){
            $binding=['owner_user_id'=>$uid,'agent_id'=>$aid,'conversation_id'=>$cid,'owner_message_id'=>$mid,'owner_message_hash'=>hash('sha256',$query)];$raw=$parsed['request'];
            $management=isset($raw['action']);
            $domain=$management?vp3_meeting_management_prepare_v1($pdo,$user,$aid,$cid,$raw,$authority):vp3_meeting_action_normalize_v1($pdo,$user,$principal,$binding,$selection['source'],$raw);
            $plan=['family'=>$management?'meeting':'followup','domain'=>$domain];$hash=hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR));
            $label=(string)($domain['label']??($management?'Update meeting':(($raw['adapter']??'')==='task.create'?'Create meeting follow-up task':'Create meeting reminder')));
            $details=is_array($domain['details']??null)?$domain['details']:[];
            if(!$details){
                $details[]=['label'=>'Meeting','value'=>(string)($selection['meeting']['title']??$selection['source']['meeting_title']??('#'.$selection['source']['meeting_id']))];
                foreach(($raw['arguments']??$raw) as $k=>$value){if(in_array($k,['action','meeting_id'],true))continue;if(is_string($value))$details[]=['label'=>ucwords(str_replace('_',' ',$k)),'value'=>$value];elseif(is_array($value))foreach($value as $sk=>$sv)if(is_string($sv))$details[]=['label'=>ucwords(str_replace('_',' ',$sk)),'value'=>$sv];}
            }
            $goal=['version'=>1,'binding'=>$binding,'source'=>$selection['source'],'plan'=>$plan,'plan_hash'=>$hash,'label'=>$label,'summary'=>(string)($domain['summary']??'Review the exact details before saving.'),'details'=>$details];
            $key=hash('sha256',json_encode(['meeting.chat_action',$binding,$hash],JSON_THROW_ON_ERROR));
            // One active review in this Chat. Superseding a preview grants no authority.
            $q=$pdo->prepare("SELECT id,goal FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='meeting_chat_action' AND status='approval_pending'");$q->execute([$uid]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $old){$og=json_decode((string)$old['goal'],true);if(($og['binding']['conversation_id']??null)!==$cid||($og['binding']['agent_id']??null)!==$aid)continue;
                $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled',approval_status='rejected',cancelled_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$old['id']]);
                $pdo->prepare("UPDATE agent_workflow_actions SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE run_id=?")->execute([(int)$old['id']]);}
            $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,status,risk_level,requires_approval,approval_status,execution_target,capability_key) VALUES(?,?,'meeting_action','chat','meeting_chat_action',?,?,?, ?,?,'approval_pending','low',1,'pending','cloud','meeting.chat_action')")->execute([$uid,$aid?:null,$key,substr($hash,0,40),$key,$label,json_encode($goal,JSON_THROW_ON_ERROR)]);$id=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO agent_workflow_actions(run_id,owner_user_id,sequence_no,action_key,action_type,label,status,requires_approval,execution_target,capability_key) VALUES(?,?,1,?,'execute',?,'approval_pending',1,'cloud','meeting.chat_action')")->execute([$id,$uid,$key,$label]);
            agent_workflow_event_v1400($pdo,$uid,$id,'meeting_action_review','','approval_pending','owner','Exact meeting action awaits owner review.',['owner_message_id'=>$mid,'plan_hash'=>$hash]);
            $loaded=vp3_meeting_chat_load_v1($pdo,$user,$principal,$cid,$id);$preview=vp3_meeting_chat_preview_v1($loaded);
            $context['meeting_action_review_v1']=['plan_id'=>$id,'plan_hash'=>$hash,'conversation_id'=>$cid];$answer='Review this meeting action, then confirm or cancel.';
        }
        vp3_meeting_chat_scope_v1($pdo,$user,$principal,$cid,$authority);
        if($requestKey!=='')$context['meeting_action_request_v1']=['request_key'=>$requestKey,'query_hash'=>hash('sha256',$query),'user_message_id'=>$mid];
        $stored=isset($context['meeting_action_review_v1'])?'A private meeting action was reviewed in this turn.':$answer;
        $q=$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,NULL,'assistant',?,?)");$q->execute([$cid,$stored,json_encode($context,JSON_THROW_ON_ERROR)]);$assistant=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?')->execute([$cid,$uid]);
        return ['ok'=>true,'conversation_id'=>$cid,'agent_id'=>$aid,'user_message_id'=>$mid,'assistant_message_id'=>$assistant,'input_mode'=>($input['input_mode']??'text')==='voice'?'voice':'text',
            'answer'=>$answer,'meeting_action'=>$preview,'tool_receipt'=>$context['tool_receipt']??null,'sources'=>[],'actions'=>[],'media'=>[],'execution'=>['mode'=>'cloud','status'=>'completed']];
    });
}
