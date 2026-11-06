<?php
declare(strict_types=1);

/** One optional spoken rendering of a delivered, model-selected short check-in. */
function vp3_initiative_voice_time_v1(mixed $value): bool
{
    if(!is_string($value)||preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D',$value)!==1)return false;
    $date=DateTimeImmutable::createFromFormat(DATE_ATOM,$value,new DateTimeZone('UTC'));
    return $date!==false&&$date->format(DATE_ATOM)===$value;
}

function vp3_initiative_voice_metadata_v1(mixed $value): ?array
{
    $keys=['version','message_id','request_id','activity_tab','token_hash','status','claimed_at','finished_at','live_session_id'];
    if(!is_array($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys)
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

function vp3_initiative_voice_context_v1(PDO $pdo,array $user,int $aid,int $cid,array $input,bool $available=true): array
{
    require_once __DIR__.'/agent-initiative-runtime-v1.php';
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    require_once __DIR__.'/agent-initiative-tools-v1.php';
    $fresh=vp3_initiative_runtime_context_v1($pdo,$user,$aid,$cid);$policy=$fresh['policy'];
    if(($policy['conversation_id']??null)!==$cid||vp3_initiative_binding_agent_v1($policy)!==$aid)
        throw new RuntimeException('Voice policy belongs to a different Chat.',403);
    $scope=vp3_initiative_tools_scope_v1($pdo,$fresh['user'],$policy);
    $binding=$input['working_attention_binding']??null;$tab=$input['activity_tab']??null;
    if(!is_string($binding)||$binding===''||!hash_equals(vp3_catch_up_page_binding_v1($scope['user']),$binding)
        ||!is_string($tab)||preg_match('/\A[a-f0-9]{32}\z/D',$tab)!==1)
        throw new RuntimeException('Voice context changed.',403);
    $result=['scope'=>$scope,'policy'=>$policy,'binding'=>$binding,'tab'=>$tab,'live_session_id'=>null];
    if(!$available)return $result;
    require_once __DIR__.'/chat-settings-v237.php';
    if(!chat_settings_schema_ready_v237($pdo)||!chat_settings_agent_voice_schema_ready_v237($pdo))
        throw new RuntimeException('Agent Voice settings are unavailable.',503);
    if(!chat_settings_agent_voice_enabled_v237($pdo,$scope['user']))throw new RuntimeException('Agent Voice is off.',409);
    if(!vp3_initiative_owner_present_v1($pdo,$scope['user'],$cid))throw new RuntimeException('Owner attention is unavailable.',409);
    $q=$pdo->prepare('SELECT * FROM agent_activity_state WHERE user_id=? LIMIT 1');$q->execute([(int)$scope['user']['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $details=json_decode((string)($row['details_json']??''),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($details)||($details['activity_tab']??null)!==$tab||($details['visible']??null)!==true
        ||($details['focused']??null)!==true||($details['chat_focused']??null)!==true||($details['chat_busy']??null)!==false
        ||($details['conversation_id']??null)!==$cid)throw new RuntimeException('The requesting Chat is not available for voice.',409);
    if(is_array($details['live_context']??null)){
        require_once __DIR__.'/agent-live-context-v1.php';
        $packet=vp3_live_context_row_v1($row,(int)$scope['user']['id'],$aid,$cid,$tab,(int)floor(microtime(true)*1000));
        if($packet===null)throw new RuntimeException('Live voice context changed.',409);
        if(($packet['microphone']['activity']??null)==='energy')throw new RuntimeException('Microphone activity defers voice.',409);
        $result['live_session_id']=$packet['session_id'];
    }
    return $result;
}

/** Metadata projection only: no draft text, source inference or notification rows. */
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

function vp3_initiative_voice_token_v1(array $context,int $run,int $message,string $request): string
{
    $scope=$context['scope'];
    return hash_hmac('sha256',json_encode([(int)$scope['user']['id'],$scope['agent_id'],$scope['conversation_id'],$run,$message,
        $request,$context['tab'],$context['binding']],JSON_THROW_ON_ERROR),csrf_token()."\0".$context['binding']);
}

function vp3_initiative_voice_v1(PDO $pdo,array $user,int $agentId,int $conversationId,string $action,array $input): array
{
    if(!in_array($action,['voice_candidates','voice_claim','voice_finish'],true))throw new InvalidArgumentException('Unknown Initiative voice operation.');
    $context=vp3_initiative_voice_context_v1($pdo,$user,$agentId,$conversationId,$input,$action!=='voice_finish');
    $scope=$context['scope'];$base=['owner_user_id'=>(int)$scope['user']['id'],'agent_id'=>$agentId,'conversation_id'=>$conversationId,
        'working_attention_binding'=>$context['binding'],'activity_tab'=>$context['tab'],'live_session_id'=>$context['live_session_id']];
    if($action==='voice_candidates'){
        $latest=vp3_initiative_voice_latest_v1($pdo,$context);
        return ['ok'=>true]+$base+['candidates'=>$latest&&$latest['preference']==='voice'&&$latest['voice_status']===null
            ?[['run_id'=>$latest['run_id'],'message_id'=>$latest['message_id']]]:[]];
    }
    $run=$input['run_id']??null;$message=$input['message_id']??null;$request=$input['request_id']??null;
    if(!is_int($run)||$run<1||!is_int($message)||$message<1||!is_string($request)
        ||preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$request)!==1)
        throw new InvalidArgumentException('Invalid Initiative voice association.');
    $record=vp3_initiative_voice_record_v1($pdo,$context,$run,$message);
    if($action==='voice_claim'){
        vp3_initiative_voice_sources_v1($pdo,$context,$record);
        return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$agentId,$conversationId,$input,$run,$message,$request,$record):array{
            vp3_initiative_owner_lock_v1($pdo,$user);
            $current=vp3_initiative_voice_context_v1($pdo,$user,$agentId,$conversationId,$input);
            $latest=vp3_initiative_voice_latest_v1($pdo,$current);
            if(!$latest||$latest['run_id']!==$run||$latest['message_id']!==$message)throw new RuntimeException('A newer check-in replaced this voice candidate.',409);
            $fresh=vp3_initiative_voice_record_v1($pdo,$current,$run,$message);
            vp3_initiative_voice_sources_v1($pdo,$current,$fresh);
            $token=vp3_initiative_voice_token_v1($current,$run,$message,$request);$result=$fresh['result'];
            if(isset($result['initiative_voice'])){
                $claim=vp3_initiative_voice_metadata_v1($result['initiative_voice']);
                if(!$claim||$claim['status']!=='claimed'||$claim['message_id']!==$message||$claim['request_id']!==$request||$claim['activity_tab']!==$current['tab']
                    ||$claim['live_session_id']!==$current['live_session_id']||!hash_equals($claim['token_hash'],hash('sha256',$token)))
                    throw new RuntimeException('This check-in already has a voice claim.',409);
            }else{
                $claim=['version'=>1,'message_id'=>$message,'request_id'=>$request,'activity_tab'=>$current['tab'],
                    'token_hash'=>hash('sha256',$token),'status'=>'claimed','claimed_at'=>gmdate(DATE_ATOM),'finished_at'=>'',
                    'live_session_id'=>$current['live_session_id']];
                $result['initiative_voice']=$claim;
                vp3_initiative_delivery_result_v1($pdo,(int)$current['scope']['user']['id'],$fresh,$result);
            }
            return ['ok'=>true,'owner_user_id'=>(int)$current['scope']['user']['id'],'agent_id'=>$agentId,'conversation_id'=>$conversationId,
                'working_attention_binding'=>$current['binding'],'activity_tab'=>$current['tab'],'live_session_id'=>$current['live_session_id'],
                'claim'=>['run_id'=>$run,'message_id'=>$message,'request_id'=>$request,'token'=>$token,'text'=>$fresh['text']]];
        });
    }
    $token=$input['token']??null;$outcome=$input['outcome']??null;
    if(!is_string($token)||preg_match('/\A[a-f0-9]{64}\z/D',$token)!==1||!in_array($outcome,['spoken','suppressed'],true))
        throw new InvalidArgumentException('Invalid voice completion.');
    return vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$user,$agentId,$conversationId,$input,$run,$message,$request,$token,$outcome):array{
        vp3_initiative_owner_lock_v1($pdo,$user);$current=vp3_initiative_voice_context_v1($pdo,$user,$agentId,$conversationId,$input,false);
        $fresh=vp3_initiative_voice_record_v1($pdo,$current,$run,$message);$claim=vp3_initiative_voice_metadata_v1($fresh['result']['initiative_voice']??null);
        if(!$claim||$claim['request_id']!==$request||$claim['activity_tab']!==$current['tab']||$claim['message_id']!==$message
            ||!hash_equals($claim['token_hash'],hash('sha256',$token)))throw new RuntimeException('Voice completion does not match its claim.',409);
        if($claim['status']==='claimed'){
            $claim['status']=$outcome;$claim['finished_at']=gmdate(DATE_ATOM);$fresh['result']['initiative_voice']=$claim;
            vp3_initiative_delivery_result_v1($pdo,(int)$current['scope']['user']['id'],$fresh,$fresh['result']);
        }elseif($claim['status']!==$outcome)throw new RuntimeException('Voice completion is already final.',409);
        return ['ok'=>true,'status'=>$claim['status'],'run_id'=>$run,'message_id'=>$message];
    });
}
