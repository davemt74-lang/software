<?php
declare(strict_types=1);

final class Vp3InitiativeVoiceRefusalV1 extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, int $status = 409)
    {
        parent::__construct($message,$status);
    }
}

/** One optional spoken rendering of an exact delivered Chat message. */
function vp3_initiative_voice_time_v1(mixed $value): bool
{
    if(!is_string($value)||preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D',$value)!==1)return false;
    $date=DateTimeImmutable::createFromFormat(DATE_ATOM,$value,new DateTimeZone('UTC'));
    return $date!==false&&$date->format(DATE_ATOM)===$value;
}

/** Browser-reported codes are stored without browser error strings or Chat text. */
function vp3_initiative_voice_suppression_reason_v1(mixed $value): bool
{
    return in_array($value,[
        'agent_voice_off','auto_greet_off','attention_unavailable','tab_changed','chat_not_focused','chat_busy',
        'microphone_active','live_context_changed','post_changed','post_unverified','page_closed','voice_unavailable',
        'draft_in_composer','reply_selected','manual_listening','another_announcement','participant_chat','no_speech_output',
        'playback_cancelled','playback_failed','playback_autoplay','playback_device','playback_voice','playback_timeout',
    ],true);
}

function vp3_initiative_voice_metadata_v1(mixed $value): ?array
{
    if(!is_array($value))return null;
    $keys=['version','message_id','request_id','activity_tab','token_hash','status','claimed_at','finished_at','live_session_id'];
    if(array_key_exists('suppression_reason',$value)){
        if(($value['status']??null)!=='suppressed'||!vp3_initiative_voice_suppression_reason_v1($value['suppression_reason']))return null;
        $keys[]='suppression_reason';
    }
    if(count($value)!==count($keys)||array_diff(array_keys($value),$keys)
        ||($value['version']??null)!==1||!is_int($value['message_id']??null)||$value['message_id']<1
        ||!is_string($value['request_id']??null)||preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$value['request_id'])!==1
        ||!is_string($value['activity_tab']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$value['activity_tab'])!==1
        ||!is_string($value['token_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$value['token_hash'])!==1
        ||!in_array($value['status']??null,['claimed','spoken','suppressed'],true)
        ||!vp3_initiative_voice_time_v1($value['claimed_at']??null)
        ||!is_string($value['finished_at']??null)
        ||($value['status']==='claimed'?$value['finished_at']!=='':!vp3_initiative_voice_time_v1($value['finished_at']))
        ||($value['live_session_id']!==null&&(!is_string($value['live_session_id'])
            ||preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$value['live_session_id'])!==1)))return null;
    return array_intersect_key($value,array_flip($keys));
}

function vp3_initiative_voice_context_v1(PDO $pdo,array $user,int $aid,int $cid,array $input,bool $available=true,bool $allowDeferral=false): array
{
    require_once __DIR__.'/agent-initiative-runtime-v1.php';
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-initiative-tools-v1.php';
    $fresh=vp3_initiative_runtime_context_v1($pdo,$user,$aid,$cid);$policy=$fresh['policy'];
    $livePost=($input['live_post']??false)===true;
    if($livePost){
        $scope=['user'=>$fresh['user'],'agent_id'=>$aid,'conversation_id'=>$cid,
            'principal'=>vp3_initiative_principal_v1($pdo,$fresh['user'],$aid)];
    }else{
        if(($policy['conversation_id']??null)!==$cid||vp3_initiative_binding_agent_v1($policy)!==$aid)
            throw new RuntimeException('Voice policy belongs to a different Chat.',403);
        $scope=vp3_initiative_tools_scope_v1($pdo,$fresh['user'],$policy);
    }
    $binding=$input['working_attention_binding']??null;$tab=$input['activity_tab']??null;
    if(!is_string($binding)||$binding===''||!hash_equals(vp3_catch_up_page_binding_v1($scope['user']),$binding)
        ||!is_string($tab)||preg_match('/\A[a-f0-9]{32}\z/D',$tab)!==1)
        throw new Vp3InitiativeVoiceRefusalV1('live_context_changed','Voice context changed.',403);
    $result=['scope'=>$scope,'policy'=>$policy,'binding'=>$binding,'tab'=>$tab,'live_session_id'=>null,
        'live_post'=>$livePost,'live_notice'=>($input['live_notice']??false)===true];
    if(!$available)return $result;
    // Listing may defer during ordinary changes in attention. An actual claim still requires current availability.
    $defer=static function(array $context,string $reason,string $message)use($allowDeferral):array{
        if(!$allowDeferral)throw new Vp3InitiativeVoiceRefusalV1($reason,$message);
        return $context+['deferred'=>true];
    };
    require_once __DIR__.'/chat-settings-v237.php';
    if(!chat_settings_schema_ready_v237($pdo)||!chat_settings_agent_voice_schema_ready_v237($pdo))
        throw new Vp3InitiativeVoiceRefusalV1('voice_settings_unavailable','Agent Voice settings are unavailable.',503);
    if(!chat_settings_agent_voice_enabled_v237($pdo,$scope['user']))
        return $defer($result,'agent_voice_off','Agent Voice is off.');
    if($livePost&&!chat_settings_agent_auto_greet_enabled_v237($pdo,$scope['user']))
        return $defer($result,'auto_greet_off','Auto Greet is off.');
    $presence=vp3_initiative_presence_v1($pdo,$scope['user'],$cid);
    if($presence!=='quiet'){
        $reason=match($presence){
            'busy'=>'chat_busy',
            'not_focused','not_visible'=>'chat_not_focused',
            'other_chat'=>'live_context_changed',
            default=>'attention_unavailable',
        };
        return $defer($result,$reason,'Owner attention is unavailable.');
    }
    $q=$pdo->prepare('SELECT * FROM agent_activity_state WHERE user_id=? LIMIT 1');$q->execute([(int)$scope['user']['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $details=json_decode((string)($row['details_json']??''),true,32,JSON_THROW_ON_ERROR);
    $reason=match(true){
        !is_array($details)=>'attention_unavailable',
        ($details['activity_tab']??null)!==$tab=>'tab_changed',
        ($details['visible']??null)!==true||($details['focused']??null)!==true||($details['chat_focused']??null)!==true=>'chat_not_focused',
        ($details['chat_busy']??null)!==false=>'chat_busy',
        ($details['conversation_id']??null)!==$cid=>'live_context_changed',
        default=>null,
    };
    if($reason!==null)return $defer($result,$reason,'The requesting Chat is not available for voice.');
    if(is_array($details['live_context']??null)){
        require_once __DIR__.'/agent-live-context-v1.php';
        $packet=vp3_live_context_row_v1($row,(int)$scope['user']['id'],$aid,$cid,$tab,(int)floor(microtime(true)*1000));
        if($packet===null)return $defer($result,'live_context_changed','Live voice context changed.');
        $result['live_session_id']=$packet['session_id'];
        if(($packet['microphone']['activity']??null)==='energy')
            return $defer($result,'microphone_active','Microphone activity defers voice.');
    }
    return $result;
}

/** A fresh post can use the text already verified for this exact Chat. */
function vp3_initiative_voice_latest_v1(PDO $pdo,array $context): ?array
{
    $scope=$context['scope'];$owner=(int)$scope['user']['id'];$aid=$scope['agent_id'];$cid=$scope['conversation_id'];
    $value=static function(string $path,string $alias='a')use($pdo):string{
        $sql="JSON_EXTRACT(CASE WHEN JSON_VALID($alias.result_json) THEN $alias.result_json ELSE '{}' END,'$path')";
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?$sql:"JSON_UNQUOTE($sql)";
    };
    foreach(vp3_initiative_runtime_runs_v1($pdo,$owner,$aid,$context['policy']) as $run){
        $goal=json_decode((string)$run['goal'],true,32,JSON_THROW_ON_ERROR);
        if(($run['status']??null)!=='completed'||($goal['tool']??null)!=='chat.check_in')continue;
        $q=$pdo->prepare('SELECT a.id AS action_id,'.$value('$.initiative_message_id').' AS message_id,'
            .$value('$.initiative_delivery').' AS delivery,'.$value('$.initiative_tool_result.output.type').' AS output_type,'
            .$value('$.initiative_tool_result.output.delivery_preference').' AS preference,'
            .$value('$.initiative_voice.status').' AS voice_status FROM agent_workflow_actions a
            JOIN agent_workflow_receipts rc ON rc.run_id=a.run_id AND rc.action_id=a.id AND rc.owner_user_id=a.owner_user_id
                AND rc.status=\'completed\' AND rc.result_json=a.result_json
            WHERE a.run_id=? AND a.owner_user_id=? AND a.status=\'completed\' AND a.capability_key=\'initiative.prepare\'
            ORDER BY a.id LIMIT 1');
        $q->execute([(int)$run['id'],$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['delivery']!=='delivered'||$row['output_type']!=='initiative_chat_check_in')return null;
        $message=(int)$row['message_id'];
        $q=$pdo->prepare("SELECT id,created_at FROM chat_messages WHERE id=? AND conversation_id=? AND role='assistant' LIMIT 1");
        $q->execute([$message,$cid]);$post=$q->fetch(PDO::FETCH_ASSOC);if(!$post)return null;
        $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$post['created_at'],new DateTimeZone('UTC'));
        if(!$at||$at->getTimestamp()>time()+5||time()-$at->getTimestamp()>120)return null;
        return ['run_id'=>(int)$run['id'],'message_id'=>$message,'action_id'=>(int)$row['action_id'],
            'preference'=>$row['preference'],'voice_status'=>$row['voice_status']];
    }
    return null;
}

/** Load only an exact current canonical action/receipt/Chat association. */
function vp3_initiative_voice_record_v1(PDO $pdo,array $context,int $run,int $message): array
{
    $scope=$context['scope'];$owner=(int)$scope['user']['id'];
    $q=$pdo->prepare("SELECT r.goal,r.agent_id,r.status AS run_status,a.id AS action_id,a.result_json,a.status AS action_status,
        rc.result_json AS receipt_json,c.message,c.context_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        JOIN agent_workflow_receipts rc ON rc.run_id=r.id AND rc.action_id=a.id AND rc.owner_user_id=r.owner_user_id AND rc.status='completed'
        JOIN chat_messages c ON c.id=? AND c.conversation_id=? AND c.role='assistant'
        WHERE r.id=? AND r.owner_user_id=? AND r.source_kind='agent_initiative' AND r.capability_key='initiative.prepare'
            AND a.capability_key='initiative.prepare' ORDER BY a.id LIMIT 1");
    $q->execute([$message,$scope['conversation_id'],$run,$owner]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['run_status']!=='completed'||$row['action_status']!=='completed'||(int)$row['agent_id']!==$scope['agent_id']
        ||!hash_equals((string)$row['result_json'],(string)$row['receipt_json']))throw new RuntimeException('Voice result changed.',409);
    $goal=json_decode($row['goal'],true,32,JSON_THROW_ON_ERROR);$result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
    $post=json_decode($row['context_json'],true,32,JSON_THROW_ON_ERROR);$output=$result['initiative_tool_result']['output']??null;
    if(!is_array($goal)||($goal['binding']??null)!==$context['policy']||($goal['tool']??null)!=='chat.check_in'
        ||vp3_initiative_followthrough_id_v1($result['initiative_message_id']??null)!==$message||($result['initiative_delivery']??null)!=='delivered'
        ||!is_array($output)||($output['type']??null)!=='initiative_chat_check_in'||($output['delivery_preference']??null)!=='voice'
        ||($post['source']??null)!=='agent_initiative'||($post['initiative_run_id']??null)!==$run
        ||($post['initiative_output']??null)!==$output||($post['initiative_work']['owner_user_id']??null)!==$owner
        ||($post['initiative_work']['agent_id']??null)!==$scope['agent_id']||($post['initiative_work']['conversation_id']??null)!==$scope['conversation_id']
        ||!is_string($result['initiative_draft']??null)||!hash_equals($result['initiative_draft'],(string)$row['message'])
        ||trim($row['message'])===''||mb_strlen($row['message'])>320)
        throw new RuntimeException('Only the exact short voice-selected check-in may speak.',409);
    return ['run_id'=>$run,'action_id'=>(int)$row['action_id'],'goal'=>$goal,'result'=>$result,'text'=>$row['message']];
}

function vp3_initiative_voice_sources_v1(PDO $pdo,array $context,array $record): void
{
    $scope=$context['scope'];$goal=$record['goal'];
    vp3_initiative_current_sources_v1($pdo,$scope['user'],$goal['sources'],!empty($context['policy']['screen_enabled']),
        $goal['canonical_sources']??[],$scope['agent_id'],$goal['capability_sources']??[]);
    vp3_initiative_result_current_v1($pdo,$scope['user'],$context['policy'],$goal,$record['result']['initiative_tool_result']??[]);
}

function vp3_initiative_voice_fresh_post_v1(PDO $pdo,int $cid,int $message,array $row): void
{
    $posted=vp3_initiative_sql_timestamp_v1($row['created_at']);
    $checked=vp3_initiative_sql_timestamp_v1($row['checked_at']);
    if($posted<1||$checked<1||$posted>$checked+5||$posted<$checked-120)
        throw new Vp3InitiativeVoiceRefusalV1('post_expired','This Chat post is too old for a voice turn.');
    $newer=$pdo->prepare("SELECT 1 FROM chat_messages WHERE conversation_id=? AND id>? AND role='user' LIMIT 1");
    $newer->execute([$cid,$message]);
    if($newer->fetchColumn()!==false)throw new Vp3InitiativeVoiceRefusalV1('post_changed','Chat moved on before this voice turn.');
}

/** Briefs and reminders are written by the coordinator directly as scoped Chat messages. */
function vp3_initiative_voice_notice_record_v1(PDO $pdo,array $context,int $run,int $message,bool $claim): array
{
    $scope=$context['scope'];$cid=$scope['conversation_id'];$policy=$context['policy'];
    $q=$pdo->prepare("SELECT message,context_json,created_at,CURRENT_TIMESTAMP AS checked_at FROM chat_messages
        WHERE id=? AND conversation_id=? AND role='assistant' AND user_id IS NULL LIMIT 1");
    $q->execute([$message,$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $post=$row?json_decode((string)$row['context_json'],true,32,JSON_THROW_ON_ERROR):null;
    $brief=$post['morning_brief']??null;$reminder=$post['initiative_reminder']??null;
    $isBrief=($post['source']??null)==='agent_initiative_morning_brief'&&is_array($brief)
        &&is_int($brief['assessment_id']??null)&&$brief['assessment_id']>0
        &&is_string($brief['local_day']??null)&&preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$brief['local_day'])===1;
    $originalId=vp3_initiative_followthrough_id_v1($reminder['message_id']??null);
    $isReminder=($post['source']??null)==='agent_initiative_reminder'&&$originalId>0&&$originalId<$message;
    if($run!==$message||!$row||(!$isBrief&&!$isReminder)||!is_string($row['message'])||trim($row['message'])===''
        ||mb_strlen($row['message'])>vp3_initiative_limits_v1()['max_draft_chars'])
        throw new Vp3InitiativeVoiceRefusalV1('post_unverified','Only a verified proactive Chat post may start a voice turn.');
    if($claim){
        vp3_initiative_voice_fresh_post_v1($pdo,$cid,$message,$row);
        if(($policy['enabled']??false)!==true||($policy['conversation_id']??null)!==$cid
            ||vp3_initiative_binding_agent_v1($policy)!==$scope['agent_id'])
            throw new Vp3InitiativeVoiceRefusalV1('post_changed','The Chat post is no longer current.');
        if($isBrief){
            require_once __DIR__.'/agent-morning-brief-v1.php';
            $verified=vp3_morning_brief_history_v1($pdo,$scope['user'],$scope['agent_id'],$cid,$row,$post);
            if($verified!==$row)throw new Vp3InitiativeVoiceRefusalV1('post_changed','The morning brief is no longer current.');
        }
        if($isReminder){
            require_once __DIR__.'/agent-coordination-reminders-v1.php';
            try{
                $original=vp3_initiative_followthrough_load_v1($pdo,$scope['user'],$scope['principal'],$cid,$originalId);
            }catch(RuntimeException $error){
                if($error instanceof PDOException)throw $error;
                throw new Vp3InitiativeVoiceRefusalV1('post_changed','The original Chat question is unavailable.');
            }
            if(!vp3_coordination_reminder_open_v1($original['state'])||!empty($original['state']['coordination_source_changed']))
                throw new Vp3InitiativeVoiceRefusalV1('post_changed','The original Chat question is no longer open.');
        }
    }
    return ['run_id'=>$run,'notice_conversation_id'=>$cid,'result'=>$post,'text'=>$row['message'],'reply'=>null];
}

/** Only a fresh receipt-backed post from the current private Chat can start a live voice turn. */
function vp3_initiative_voice_live_record_v1(PDO $pdo,array $context,int $run,int $message,bool $claim): array
{
    if(!empty($context['live_notice']))return vp3_initiative_voice_notice_record_v1($pdo,$context,$run,$message,$claim);
    $scope=$context['scope'];$owner=(int)$scope['user']['id'];$cid=$scope['conversation_id'];$agent=$scope['agent_id'];
    $q=$pdo->prepare("SELECT m.message,m.context_json,m.created_at,CURRENT_TIMESTAMP AS checked_at,
        r.source_kind,r.capability_key,r.agent_id,a.id AS action_id,a.result_json,p.id AS receipt_id,p.result_json AS receipt_json
        FROM chat_messages m JOIN agent_workflow_runs r ON r.id=? AND r.owner_user_id=? AND r.status='completed'
            AND r.execution_target='cloud'
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id AND a.sequence_no=1
            AND a.capability_key=r.capability_key AND a.status='completed' AND a.execution_target='cloud'
        JOIN agent_workflow_receipts p ON p.action_id=a.id AND p.run_id=r.id AND p.owner_user_id=a.owner_user_id
            AND p.attempt_no=a.attempt_count AND p.executor='cloud' AND p.status='completed'
        WHERE m.id=? AND m.conversation_id=? AND m.role='assistant' AND m.user_id IS NULL
        ORDER BY p.id DESC LIMIT 1");
    $q->execute([$run,$owner,$message,$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||(int)$row['agent_id']!==$agent||!is_string($row['result_json'])||$row['receipt_json']!==$row['result_json'])
        throw new Vp3InitiativeVoiceRefusalV1('post_unverified','Voice result changed.');
    $result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
    $post=json_decode((string)$row['context_json'],true,32,JSON_THROW_ON_ERROR);
    $reply=null;
    if($claim)vp3_initiative_voice_fresh_post_v1($pdo,$cid,$message,$row);
    if($row['source_kind']==='agent_initiative'&&$row['capability_key']==='initiative.prepare'&&($post['source']??null)==='agent_initiative'){
        $researchTool=match($post['initiative_output']['type']??null){
            'initiative_artist_research'=>'artist.research',
            'initiative_topic_research'=>'topic.research',
            default=>null,
        };
        $readOnlyResearch=$researchTool!==null;
        if($claim&&$researchTool==='topic.research'
            &&str_starts_with((string)($post['initiative_output']['source_ref']??''),'transcript-research:')){
            require_once __DIR__.'/agent-meeting-research-v1.php';
            $readOnlyResearch=vp3_meeting_research_saved_link_v1($pdo,$owner,$run)===null;
        }
        try{
            $proof=$claim&&!$readOnlyResearch?vp3_initiative_followthrough_load_v1($pdo,$scope['user'],$scope['principal'],$cid,$message)
                :vp3_initiative_followthrough_origin_load_v1($pdo,$scope['user'],$scope['principal'],$cid,$message);
        }catch(Vp3InitiativeSourcesChangedV1 $error){
            throw new Vp3InitiativeVoiceRefusalV1('post_changed','The Chat post is no longer current.',$error->getCode());
        }catch(Vp3InitiativeSourceProofUnavailableV1 $error){
            throw new Vp3InitiativeVoiceRefusalV1('post_unverified','The Chat post could not be verified.',$error->getCode());
        }
        if((int)$proof['run']['id']!==$run||(int)$proof['action']['id']!==(int)$row['action_id']||$proof['result']!==$result)
            throw new Vp3InitiativeVoiceRefusalV1('post_unverified','The Chat post does not match its voice receipt.');
        if($claim&&((($context['policy']['enabled']??null)!==true)||$proof['goal']['binding']!==$context['policy']
            ||!empty($proof['state']['coordination_source_changed'])))
            throw new Vp3InitiativeVoiceRefusalV1('post_changed','The Chat post is no longer current.');
        if($claim&&$readOnlyResearch){
            if(($proof['goal']['tool']??null)!==$researchTool)
                throw new Vp3InitiativeVoiceRefusalV1('post_unverified','The Research post does not match its voice receipt.');
            // A verified Research report can speak without granting Reply or microphone access.
            try{
                vp3_initiative_work_sources_v1($pdo,$proof['user'],$context['policy'],$proof['goal']);
                vp3_initiative_result_current_v1($pdo,$proof['user'],$context['policy'],$proof['goal'],$result['initiative_tool_result']);
            }catch(RuntimeException $error){
                if($error instanceof PDOException||!in_array($error->getCode(),[403,404,409,503],true))throw $error;
                throw $error->getCode()===503
                    ?new Vp3InitiativeVoiceRefusalV1('post_unverified','The Research post could not be verified.',503)
                    :new Vp3InitiativeVoiceRefusalV1('post_changed','The Research post is no longer current.');
            }
        }
        if($claim&&($proof['goal']['tool']??null)==='chat.check_in'&&vp3_live_context_eyes_visit_ref_v1($proof['goal']['primary']??null)){
            try{vp3_initiative_work_sources_v1($pdo,$scope['user'],$context['policy'],$proof['goal']);}
            catch(RuntimeException $error){throw new Vp3InitiativeVoiceRefusalV1('post_changed','The Agent Eyes visit is no longer current.');}
        }
        $text=$row['message'];
        $state=$proof['state'];
        if($claim&&!$readOnlyResearch&&$state['state']==='open'){
            $reply=['message_id'=>$message,'reply_ref'=>$state['reply_ref'],'revision'=>$state['revision']];
        }
    }elseif($row['source_kind']==='coding_canvas'&&in_array($row['capability_key'],['coding.build','coding.repair'],true)
        &&($post['source']??null)==='agent_coding_canvas'&&($post['coding_canvas_run_id']??null)===$run
        &&vp3_initiative_followthrough_id_v1($result['canvas_message_id']??null)===$message){
        require_once __DIR__.'/coding-canvas-chat-v1.php';
        $card=vp3_coding_canvas_chat_card_v1($pdo,$scope['user'],$agent,$cid,$run,$message);
        if($card===null||($claim&&$card['status']!=='draft'))
            throw new Vp3InitiativeVoiceRefusalV1($card===null?'post_unverified':'post_changed','The Canvas draft is no longer current.');
        $text='Your app draft is ready to review. '.$card['title'].'.';
    }else{
        throw new Vp3InitiativeVoiceRefusalV1('post_unverified','Only a verified proactive Chat post may start a voice turn.');
    }
    if(!is_string($text)||trim($text)===''||mb_strlen($text)>vp3_initiative_limits_v1()['max_draft_chars'])
        throw new Vp3InitiativeVoiceRefusalV1('post_unverified','This Chat post is unavailable for voice.');
    return ['run_id'=>$run,'action_id'=>(int)$row['action_id'],'receipt_id'=>(int)$row['receipt_id'],
        'result'=>$result,'text'=>$text,'reply'=>$reply];
}

/** Legacy check-ins keep their existing storage path. Live turns update only the verified attempt. */
function vp3_initiative_voice_save_v1(PDO $pdo,int $owner,array $record,array $result): void
{
    if(isset($record['notice_conversation_id'])){
        $pdo->prepare("UPDATE chat_messages SET context_json=? WHERE id=? AND conversation_id=? AND role='assistant' AND user_id IS NULL")
            ->execute([agent_workflow_json_v1400($result),$record['run_id'],$record['notice_conversation_id']]);
        return;
    }
    if(!isset($record['receipt_id'])){
        vp3_initiative_delivery_result_v1($pdo,$owner,$record,$result);
        return;
    }
    $json=agent_workflow_json_v1400($result);
    $pdo->prepare("UPDATE agent_workflow_actions SET result_json=?,updated_at=UTC_TIMESTAMP()
        WHERE id=? AND run_id=? AND owner_user_id=? AND status='completed'")
        ->execute([$json,$record['action_id'],$record['run_id'],$owner]);
    $pdo->prepare("UPDATE agent_workflow_receipts SET result_json=?
        WHERE id=? AND action_id=? AND run_id=? AND owner_user_id=? AND status='completed'")
        ->execute([$json,$record['receipt_id'],$record['action_id'],$record['run_id'],$owner]);
}

function vp3_initiative_voice_token_v1(array $context,int $run,int $message,string $request): string
{
    $scope=$context['scope'];
    return hash_hmac('sha256',json_encode([(int)$scope['user']['id'],$scope['agent_id'],$scope['conversation_id'],$run,$message,
        $request,$context['tab'],$context['binding']],JSON_THROW_ON_ERROR),csrf_token()."\0".$context['binding']);
}

function vp3_initiative_voice_v1(PDO $pdo,array $user,int $agentId,int $conversationId,string $action,array $input): array
{
    if(!in_array($action,['voice_candidates','voice_claim','voice_finish'],true))throw new InvalidArgumentException('Unknown Initiative voice operation.');
    if(array_key_exists('live_post',$input)&&!is_bool($input['live_post']))throw new InvalidArgumentException('Invalid live voice association.');
    if(array_key_exists('live_notice',$input)&&(!is_bool($input['live_notice'])||($input['live_post']??null)!==true))
        throw new InvalidArgumentException('Invalid live voice notice association.');
    $context=vp3_initiative_voice_context_v1($pdo,$user,$agentId,$conversationId,$input,$action!=='voice_finish',$action==='voice_candidates');
    $scope=$context['scope'];$base=['owner_user_id'=>(int)$scope['user']['id'],'agent_id'=>$agentId,'conversation_id'=>$conversationId,
        'working_attention_binding'=>$context['binding'],'activity_tab'=>$context['tab'],'live_session_id'=>$context['live_session_id']];
    if($action==='voice_candidates'){
        $latest=empty($context['deferred'])&&!$context['live_post']?vp3_initiative_voice_latest_v1($pdo,$context):null;
        return ['ok'=>true]+$base+['candidates'=>$latest&&$latest['preference']==='voice'&&$latest['voice_status']===null
            ?[['run_id'=>$latest['run_id'],'message_id'=>$latest['message_id']]]:[]];
    }
    $run=$input['run_id']??null;$message=$input['message_id']??null;$request=$input['request_id']??null;
    if(!is_int($run)||$run<1||!is_int($message)||$message<1||!is_string($request)
        ||preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$request)!==1)
        throw new InvalidArgumentException('Invalid Initiative voice association.');
    if($action==='voice_claim'){
        if($context['live_post'])vp3_initiative_voice_live_record_v1($pdo,$context,$run,$message,true);
        else vp3_initiative_voice_sources_v1($pdo,$context,vp3_initiative_voice_record_v1($pdo,$context,$run,$message));
        return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$agentId,$conversationId,$input,$run,$message,$request):array{
            vp3_initiative_owner_lock_v1($pdo,$user);
            $current=vp3_initiative_voice_context_v1($pdo,$user,$agentId,$conversationId,$input);
            if($current['live_post']){
                $fresh=vp3_initiative_voice_live_record_v1($pdo,$current,$run,$message,true);
            }else{
                $latest=vp3_initiative_voice_latest_v1($pdo,$current);
                if(!$latest||$latest['run_id']!==$run||$latest['message_id']!==$message)throw new RuntimeException('A newer check-in replaced this voice candidate.',409);
                $fresh=vp3_initiative_voice_record_v1($pdo,$current,$run,$message);
                vp3_initiative_voice_sources_v1($pdo,$current,$fresh);
            }
            $token=vp3_initiative_voice_token_v1($current,$run,$message,$request);$result=$fresh['result'];
            if(isset($result['initiative_voice'])){
                $claim=vp3_initiative_voice_metadata_v1($result['initiative_voice']);
                if(!$claim||$claim['status']!=='claimed'||$claim['message_id']!==$message||$claim['request_id']!==$request||$claim['activity_tab']!==$current['tab']
                    ||$claim['live_session_id']!==$current['live_session_id']||!hash_equals($claim['token_hash'],hash('sha256',$token)))
                    throw new Vp3InitiativeVoiceRefusalV1('voice_already_claimed','This check-in already has a voice claim.');
            }else{
                $claim=['version'=>1,'message_id'=>$message,'request_id'=>$request,'activity_tab'=>$current['tab'],
                    'token_hash'=>hash('sha256',$token),'status'=>'claimed','claimed_at'=>gmdate(DATE_ATOM),'finished_at'=>'',
                    'live_session_id'=>$current['live_session_id']];
                $result['initiative_voice']=$claim;
                vp3_initiative_voice_save_v1($pdo,(int)$current['scope']['user']['id'],$fresh,$result);
            }
            return ['ok'=>true,'owner_user_id'=>(int)$current['scope']['user']['id'],'agent_id'=>$agentId,'conversation_id'=>$conversationId,
                'working_attention_binding'=>$current['binding'],'activity_tab'=>$current['tab'],'live_session_id'=>$current['live_session_id'],
                'claim'=>['run_id'=>$run,'message_id'=>$message,'request_id'=>$request,'token'=>$token,'text'=>$fresh['text']]
                    +($current['live_post']?['reply'=>$fresh['reply']]:[])];
        });
    }
    $token=$input['token']??null;$outcome=$input['outcome']??null;
    if(!is_string($token)||preg_match('/\A[a-f0-9]{64}\z/D',$token)!==1||!in_array($outcome,['spoken','suppressed'],true))
        throw new InvalidArgumentException('Invalid voice completion.');
    $suppressionReason=$input['suppression_reason']??null;
    if(array_key_exists('suppression_reason',$input)&&(!$context['live_post']||$outcome!=='suppressed'
        ||!vp3_initiative_voice_suppression_reason_v1($suppressionReason)))
        throw new InvalidArgumentException('Invalid voice suppression reason.');
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$agentId,$conversationId,$input,$run,$message,$request,$token,$outcome,$suppressionReason):array{
        vp3_initiative_owner_lock_v1($pdo,$user);$current=vp3_initiative_voice_context_v1($pdo,$user,$agentId,$conversationId,$input,false);
        $fresh=$current['live_post']?vp3_initiative_voice_live_record_v1($pdo,$current,$run,$message,false)
            :vp3_initiative_voice_record_v1($pdo,$current,$run,$message);
        $claim=vp3_initiative_voice_metadata_v1($fresh['result']['initiative_voice']??null);
        if(!$claim||$claim['request_id']!==$request||$claim['activity_tab']!==$current['tab']||$claim['message_id']!==$message
            ||!hash_equals($claim['token_hash'],hash('sha256',$token)))
            throw new Vp3InitiativeVoiceRefusalV1('voice_completion_unverified','Voice completion does not match its claim.');
        if($claim['status']==='claimed'){
            $claim['status']=$outcome;$claim['finished_at']=gmdate(DATE_ATOM);
            if($suppressionReason!==null)$claim['suppression_reason']=$suppressionReason;
            $fresh['result']['initiative_voice']=$claim;
            vp3_initiative_voice_save_v1($pdo,(int)$current['scope']['user']['id'],$fresh,$fresh['result']);
        }elseif($claim['status']!==$outcome){
            throw new Vp3InitiativeVoiceRefusalV1('voice_completion_unverified','Voice completion is already final.');
        }
        return ['ok'=>true,'status'=>$claim['status'],'run_id'=>$run,'message_id'=>$message];
    });
}
