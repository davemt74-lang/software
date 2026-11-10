<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/ai-stream-v121.php';
require_once dirname(__DIR__).'/includes/participant-chat-v1.php';
require_once dirname(__DIR__).'/includes/chat-execution-v019.php';
require_once dirname(__DIR__).'/includes/homeserver-agent-v025.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/agent-tool-authorization-v400.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/agent-capability-awareness-v1.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/connected-sites-chat-v1.php';
require_once dirname(__DIR__).'/includes/connected-gelato-v1.php';
require_once dirname(__DIR__).'/includes/transcript-agent-v1.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
require_once dirname(__DIR__).'/includes/user-calendar-agent-v1300.php';
require_once dirname(__DIR__).'/includes/agent-work-control-v173.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
header('Content-Type: application/x-ndjson; charset=UTF-8');header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('X-Accel-Buffering: no');@ini_set('zlib.output_compression','0');@ini_set('output_buffering','0');@set_time_limit(180);ignore_user_abort(true);
function chat_v121_emit(array $event): void{if(connection_aborted())return;if(($event['type']??'')==='delta'&&isset($GLOBALS['vp3_working_context_stream_buffer_v1'])){$GLOBALS['vp3_working_context_stream_buffer_v1'][]=$event;return;}if(function_exists('agent_runtime_v125_trace_id')&&!isset($event['trace_id']))$event['trace_id']=agent_runtime_v125_trace_id();echo json_encode($event,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";if(function_exists('ob_flush'))@ob_flush();flush();}
function chat_v121_scope_sql(?array $agent,string $alias='c'): array{return vp3_agent_chat_scope_sql_v380($agent,$alias);}
function chat_v121_owned(PDO $pdo,int $conversationId,int $userId,?array $agent): bool{return vp3_agent_chat_conversation_v380($pdo,$conversationId,$userId,$agent)!==null;}
function chat_v121_agent(PDO $pdo,array $user,array $input,int $conversationId): ?array{$input['conversation_id']=$conversationId;return vp3_agent_chat_stream_scope_v380($pdo,$user,$input)['agent'];}
function chat_v121_context(string $query,array $user,array $principal,array $agentContext=[],int $conversationId=0): array{
    $context=chat_policy_context_v236($query,$user,$principal,$conversationId);
    if($agentContext&&function_exists('agent_surface_v131_context_items'))array_unshift($context,...agent_surface_v131_context_items($agentContext));
    if(release_v105_schema_ready()&&permission_v105_has('release.manage',$user)){
        $releaseContext=release_v105_agent_context($user,release_v105_chat_intent($query)?10:4);
        if($releaseContext)$context=array_merge($releaseContext,$context);else{$resourceLines=[];foreach(array_slice(release_v105_resources($user,20),0,12) as $resource)$resourceLines[]='Resource #'.(int)$resource['id'].' · '.(string)$resource['resource_type'].' · '.(string)$resource['title'].((string)$resource['provider_key']!==''?' · provider '.(string)$resource['provider_key']:'');foreach(release_v105_integrations($user) as $integration)$resourceLines[]='Integration '.(string)$integration['provider_key'].' · '.(string)$integration['status'].' · '.((string)$integration['label']?: (string)$integration['connection_key']);if($resourceLines)$context[]=['source'=>'agent-brain:operations-resources','title'=>'Agent Operations resources and connected capabilities','text'=>implode("\n",$resourceLines)];}
        $context[]=['source'=>'agent:release-operations-tool','title'=>'Agent Operations tool contract','text'=>'Release Calendar is a first-class Agent tool even before the first release is created. It coordinates release plans, due work, tracks, shows, resources, contact lists, documents, websites and audited external actions. External actions may require approval before side effects. Never claim an external action was sent unless its action record reports completion.'];
    }
    if(release_v105_schema_ready()&&preg_match('/\b(credit|credits|credited|contributor|producer|songwriter|engineer)\b/i',$query)){$track=agent_tool_find_track($query,$user);if($track&&permission_v105_track_allowed($track,$user)){$rows=credits_v105_rows($user,(int)$track['id']);$lines=[];foreach($rows as $row)$lines[]=(string)$row['display_name'].' · '.(string)$row['contribution_role'].(trim((string)$row['contribution_detail'])!==''?' · '.(string)$row['contribution_detail']:'');if($lines)$context[]=['source'=>'agent-brain:credits:'.(int)$track['id'],'title'=>'Credits graph · '.(string)$track['title'],'text'=>implode("\n",$lines)];}}
    return array_slice($context,0,32);
}

$assistantMessageId=0;$conversationId=0;$pdo=null;$idempotencyScope='';$idempotencyKey='';$idempotencyClaimed=false;
try{
$user=current_user();if(!$user){http_response_code(401);throw new RuntimeException('Sign in again to continue.');}if(!has_permission('chat.access',$user)){http_response_code(403);throw new RuntimeException('Chat access is not available for this account.');}
if(isset($_SERVER['HTTP_X_VP3_WORKING_ATTENTION'])&&!hash_equals(vp3_catch_up_page_binding_v1($user),(string)$_SERVER['HTTP_X_VP3_WORKING_ATTENTION'])){
    http_response_code(403);chat_v121_emit(['type'=>'error','code'=>'account_changed','error'=>'Your Chat session changed. Refresh the page and try again.']);exit;
}
$pdo=db();if(!$pdo||!table_exists('chat_conversations')||!table_exists('chat_messages')||!user_agent_system_schema_ready_v236($pdo))throw new RuntimeException('Chat storage is not ready.');$brainAllowed=personal_capability_has_v242('agent_brain.access',$user);
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;$csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(401);throw new RuntimeException('Session expired. Refresh the page and try again.');}$query=trim((string)($input['message']??''));if($query==='')throw new RuntimeException('Enter a message.');if(mb_strlen($query)>6000)throw new RuntimeException('That message is too long.');$userId=(int)$user['id'];$conversationId=max(0,(int)($input['conversation_id']??0));$activeAgent=chat_v121_agent($pdo,$user,$input,$conversationId);$principal=vp3_agent_chat_principal_v380($user,$activeAgent);$agentScopeId=vp3_agent_chat_agent_id_v380($activeAgent);
$initiativeReply=vp3_agent_chat_initiative_reply_v2160($input);
if($initiativeReply!==null&&$conversationId<1)throw new RuntimeException('A saved owner Chat is required for this reply.',422);
$input['input_mode']='voice';$participantActor=vp3_participant_chat_actor_v1($pdo,$user,$input);if($initiativeReply!==null&&($participantActor!==null||vp3_participant_scope_v1()!==null))throw new RuntimeException('Use the private owner Chat for this reply.',403);
$initiativeRequest=null;
$connectedSitePlan=$initiativeReply===null&&$participantActor===null&&empty($input['ephemeral_protocol'])
    ?vp3_connected_sites_chat_request_v1($pdo,$user,$principal,$query):null;
$initiativeStatusRead=$initiativeReply===null&&empty($input['ephemeral_protocol'])
    &&in_array(vp3_initiative_command_v1($query),['status','findings'],true);
$emailPlan=$connectedSitePlan===null&&$initiativeReply===null&&$participantActor===null&&vp3_participant_scope_v1()===null
    &&empty($input['ephemeral_protocol'])&&knowledge_retrieval_v162_owner_session($user,$principal)
    ?agent_connection_email_chat_plan_v1($query,true):null;
$emailRequest=$emailPlan!==null;
$emailReviewRequest=($emailPlan['operation']??null)==='review_status'
    ||(($emailPlan['operation']??null)==='reply'&&($emailPlan['mode']??null)==='auto');
$youtubeHistoryRequest=$initiativeReply===null&&empty($input['ephemeral_protocol'])&&agent_connection_youtube_history_request_v1($query);
if($youtubeHistoryRequest&&$participantActor!==null)throw new RuntimeException('Use your private Agent Chat to review your YouTube playback in VP3.',403);
$youtubeFollowup=$initiativeReply===null&&empty($input['ephemeral_protocol'])?agent_connection_youtube_followup_plan_v1($query):null;
$youtubeRequest=$connectedSitePlan===null&&$initiativeReply===null&&!$emailRequest&&$participantActor===null
    &&empty($input['ephemeral_protocol'])&&($youtubeHistoryRequest||$youtubeFollowup!==null||agent_connection_youtube_chat_plan_v1($query)!==null);
$publicResearchRequest=$connectedSitePlan===null&&$initiativeReply===null&&!$emailRequest&&$participantActor===null
    &&empty($input['ephemeral_protocol'])&&vp3_public_research_plan_v1($query)!==null;
if($connectedSitePlan===null&&!$emailRequest&&!$youtubeRequest&&!$publicResearchRequest&&!$initiativeStatusRead&&$participantActor===null&&vp3_participant_scope_v1()===null
    &&empty($input['ephemeral_protocol'])&&$conversationId>0
    &&($initiativeReply!==null||vp3_initiative_followthrough_possible_v1($pdo,$userId,$agentScopeId,$conversationId,$query))){
    try{$initiativeRequest=vp3_initiative_followthrough_request_v1($pdo,$user,$principal,$conversationId,$query,
        static fn()=>vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input),$initiativeReply);}catch(Throwable $preflightError){throw vp3_agent_chat_followthrough_error_v2160($preflightError);}
}
$idempotencyKey=mb_substr(trim((string)($input['idempotency_key']??'')),0,160);
if($initiativeReply!==null&&$idempotencyKey==='')$idempotencyKey='initiative:'.hash('sha256',json_encode([$conversationId,$initiativeReply,$query],JSON_THROW_ON_ERROR));
$idempotencyScope='voice-chat:user-'.$userId.':agent-'.$agentScopeId;
if($participantActor!==null)$idempotencyScope.=':participant-'.(int)($participantActor['participant_id']??0).':revision-'.vp3_participant_settings_v1($pdo,$user)['revision'];
$voiceFollowthroughRequest=($initiativeReply!==null||$initiativeRequest!==null)?[
    'owner_user_id'=>$userId,'agent_id'=>$agentScopeId,'conversation_id'=>$conversationId,'initiative_reply'=>$initiativeReply,
    'selected_reply'=>$initiativeReply??$initiativeRequest['initiative_reply'],
    'explicit'=>$initiativeReply!==null,'query_hash'=>hash('sha256',$query)]:null;
if($idempotencyKey!==''){
    if(($initiativeRequest['replay_only']??false)===true){
        $outer=vp3_agent_chat_followthrough_peek_v2160($idempotencyScope,$idempotencyKey);
        if($outer!==null){
            if(($outer['status']??null)==='processing'){
                if(($outer['initiative_voice_request']??null)!==$voiceFollowthroughRequest)throw new RuntimeException('The voice reply request changed.',409);
            }elseif(($outer['status']??null)==='completed'&&is_array($outer['result']??null)&&!empty($outer['result']['ok'])){
                vp3_agent_chat_followthrough_cached_v2160($pdo,$user,$principal,$input,$outer['result'],
                    static fn()=>vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input));
            }else throw new RuntimeException('The historical voice reply cache is unavailable.',409);
        }
        // The private completed-cache reader below is authoritative. Neither
        // outer nor private cache is rewritten for a historical projection.
    }else{
    $outer=$voiceFollowthroughRequest!==null?vp3_agent_chat_followthrough_peek_v2160($idempotencyScope,$idempotencyKey):null;
    $claim=is_array($outer)&&($outer['status']??null)==='completed'&&is_array($outer['result']??null)&&!empty($outer['result']['ok'])
        ?['claimed'=>false,'state'=>'completed','result'=>$outer['result']]
        :agent_runtime_v125_idempotency_claim($idempotencyScope,$idempotencyKey,86400);
    if(empty($claim['claimed'])){
        if(($claim['state']??'')==='processing'&&$voiceFollowthroughRequest!==null){
            vp3_agent_chat_followthrough_record_v2160($idempotencyScope,$idempotencyKey,static function(array &$row)use($voiceFollowthroughRequest):void{
                if(($row['status']??null)!=='processing'||($row['initiative_voice_request']??null)!==$voiceFollowthroughRequest)throw new RuntimeException('The voice reply request changed.',409);
            });
            // The exact shared private request performs the bounded busy/stale
            // arbitration; this outer stream cache is not a second worker.
            $replay=null;
        }else{
        if(($claim['state']??'')!=='completed'||!is_array($claim['result']??null))throw new RuntimeException('That voice turn is already processing.',503);
        $replay=$claim['result'];$failedBinding=$replay['initiative_reply_failure']??null;
        if(is_array($failedBinding)){
            $followthroughReplay=true;
            if(($initiativeReply===null&&$initiativeRequest===null)||($failedBinding['initiative_reply']??null)!==$initiativeReply
                ||($failedBinding['explicit']??true)!==($initiativeReply!==null)
                ||($failedBinding['query_hash']??null)!==hash('sha256',$query)||($failedBinding['conversation_id']??null)!==$conversationId
                ||($failedBinding['agent_id']??null)!==$agentScopeId)throw new RuntimeException('The failed voice reply binding changed.',409);
            // The shared private owner-request store reclaims this failed reply
            // exclusively, preserving its saved owner turn and any accepted work.
        }else{
            if($initiativeReply!==null||array_key_exists('initiative_followthrough',$replay)){
                $followthroughReplay=true;
                $replay=vp3_agent_chat_followthrough_cached_v2160($pdo,$user,$principal,$input,$replay,
                    static fn()=>vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input));
            }
            if(isset($input['working_context_ref'])||isset($replay['working_context_ref'])){
                require_once dirname(__DIR__).'/includes/agent-working-context-v1.php';vp3_working_context_replay_v1($pdo,$user,$principal,$input,$replay);
            }
            if($emailReviewRequest||isset($replay['gmail_query_hash'])){
                $replayConversationId=(int)($replay['conversation_id']??0);
                if(!$emailReviewRequest||($conversationId>0&&$conversationId!==$replayConversationId)
                    ||!hash_equals((string)($replay['gmail_query_hash']??''),hash('sha256',$query))){
                    throw new RuntimeException('That Gmail request changed.',409);
                }
                agent_connection_email_chat_authority_v1($pdo,$user,$principal,$replayConversationId,
                    static fn()=>vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input));
                unset($replay['gmail_query_hash']);
            }
            if($connectedSitePlan!==null||isset($replay['connected_site_query_hash'])){
                $replayConversationId=(int)($replay['conversation_id']??0);
                if(($conversationId>0&&$conversationId!==$replayConversationId)
                    ||!hash_equals((string)($replay['connected_site_query_hash']??''),hash('sha256',$query))){
                    throw new RuntimeException('That connected-site request changed.',409);
                }
                if(!empty($input['ephemeral_protocol'])||!vp3_connected_sites_chat_allowed_v1($user,$principal))
                    throw new RuntimeException('Use your private Agent Chat to ask Gelato.',403);
                vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input);
                if(!empty($replay['ok'])&&!chat_v121_owned($pdo,$replayConversationId,$userId,$activeAgent))
                    throw new RuntimeException('Conversation access changed.',403);
                unset($replay['connected_site_query_hash']);
            }
            if(empty($replay['ok'])){chat_v121_emit(['type'=>'error','error'=>(string)($replay['error']??'The prior voice turn failed.'),'replayed'=>true]);exit;}
            $researchHash=$replay['public_research_query_hash']??$replay['artist_research_query_hash']??null;
            if($publicResearchRequest||$researchHash!==null){
                $replayConversationId=(int)($replay['conversation_id']??0);
                if(!$publicResearchRequest||($conversationId>0&&$conversationId!==$replayConversationId)
                    ||!hash_equals((string)$researchHash,hash('sha256',$query))){
                    throw new RuntimeException('That research request changed.',409);
                }
                vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input);
                $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$replayConversationId],null);
                if(!has_permission('account.access',$live))throw new RuntimeException('Research access changed.',403);
                unset($replay['public_research_query_hash'],$replay['artist_research_query_hash']);
            }
            if($youtubeRequest||isset($replay['youtube_query_hash'])){
                $replayConversationId=(int)($replay['conversation_id']??0);
                if(!$youtubeRequest||($conversationId>0&&$conversationId!==$replayConversationId)
                    ||!hash_equals((string)($replay['youtube_query_hash']??''),hash('sha256',$query))){
                    throw new RuntimeException('That YouTube request changed.',409);
                }
                $youtubeAuthority=static fn()=>vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input);
                if($youtubeHistoryRequest){
                    $history=agent_connection_youtube_history_chat_v1($pdo,$user,$principal,$replayConversationId,$youtubeAuthority);
                    foreach(['answer','actions','tool_receipt','youtube_results'] as $field)$replay[$field]=$history[$field];
                }elseif($youtubeFollowup!==null){
                    $sourceMessageId=(int)($replay['youtube_source_message_id']??0);
                    $followup=agent_connection_youtube_followup_chat_v1($pdo,$user,$principal,$youtubeFollowup,$replayConversationId,$youtubeAuthority,$sourceMessageId);
                    foreach(['answer','actions','tool_receipt','youtube_results'] as $field)$replay[$field]=$followup[$field];
                    if(empty($followup['youtube_source_message_id']))unset($replay['youtube_source_message_id']);
                }else{
                    agent_connection_youtube_chat_authority_v1($pdo,$user,$principal,$replayConversationId,$youtubeAuthority);
                    $replayMessageId=(int)($replay['assistant_message_id']??0);
                    $videoResults=agent_connection_youtube_results_read_v1($pdo,$userId,$agentScopeId,$replayConversationId,[$replayMessageId]);
                    if(isset($videoResults[$replayMessageId]))$replay['youtube_results']=$videoResults[$replayMessageId];
                    elseif(($replay['tool_receipt']['status']??null)==='read_complete')$replay['answer']='The saved YouTube results are unavailable. You can search again from Chat.';
                }
                unset($replay['youtube_query_hash']);
            }
            chat_v121_emit(['type'=>'start','conversation_id'=>(int)($replay['conversation_id']??0),'user_message_id'=>(int)($replay['user_message_id']??0),
                'assistant_message_id'=>(int)($replay['assistant_message_id']??0),'agent_id'=>$agentScopeId,'replayed'=>true]);
            if(trim((string)($replay['answer']??''))!=='')chat_v121_emit(['type'=>'delta','delta'=>(string)$replay['answer'],'replayed'=>true]);
            chat_v121_emit(['type'=>'done','data'=>$replay+['replayed'=>true]]);exit;
        }
        }
    }elseif($voiceFollowthroughRequest!==null){
        vp3_agent_chat_followthrough_record_v2160($idempotencyScope,$idempotencyKey,static function(array &$row)use($voiceFollowthroughRequest):void{
            if(($row['status']??null)!=='processing'||isset($row['initiative_voice_request']))throw new RuntimeException('The voice reply request changed.',409);
            $row['initiative_voice_request']=$voiceFollowthroughRequest;
        });
    }
    $idempotencyClaimed=true;
    }
}
agent_runtime_v125_trace('chat.turn.accepted',['user_id'=>$userId,'conversation_id'=>$conversationId,'agent_id'=>$agentScopeId,'idempotent'=>$idempotencyKey!=='']);
if($conversationId<1){$workspaceId=artist_workspace_v181_scope_id($user);$conversation=vp3_agent_chat_create_conversation_v380($pdo,$user,$activeAgent,vp3_agent_chat_public_prompt_v2160($query),$workspaceId);$conversationId=(int)$conversation['id'];}elseif(!chat_v121_owned($pdo,$conversationId,$userId,$activeAgent))throw new RuntimeException('Conversation not found for this agent.');
if($participantActor!==null&&array_key_exists('initiative_reply',$input))throw new RuntimeException('Use the private owner Chat for this reply.',403);
if($participantActor!==null){
    $done=vp3_participant_chat_send_v1($pdo,$user,$principal,$input,$participantActor,$conversationId);
    if($idempotencyClaimed&&$idempotencyKey!=='')agent_runtime_v125_idempotency_complete($idempotencyScope,$idempotencyKey,$done);
    chat_v121_emit(['type'=>'start','conversation_id'=>$conversationId,'user_message_id'=>$done['user_message_id'],'assistant_message_id'=>$done['assistant_message_id'],'agent_id'=>$agentScopeId]);
    chat_v121_emit(['type'=>'delta','delta'=>$done['answer']]);chat_v121_emit(['type'=>'done','data'=>$done]);exit;
}
if(array_key_exists('initiative_reply',$input)||$initiativeRequest!==null||$youtubeRequest||$publicResearchRequest||$initiativeStatusRead||$emailReviewRequest){
    // Use the same owner-scoped executor for these voice and typed turns.
    $input['conversation_id']=$conversationId;
    $done=vp3_agent_chat_send_v2160($pdo,$user,$activeAgent,$principal,$input,$brainAllowed,allowConnectedSites:true);
    $assistantMessageId=(int)$done['assistant_message_id'];$done['input_mode']='voice';
    if($idempotencyClaimed&&$idempotencyKey!==''){
        $replay=$done;
        if($publicResearchRequest)$replay['public_research_query_hash']=hash('sha256',$query);
        if($emailReviewRequest)$replay['gmail_query_hash']=hash('sha256',$query);
        if($youtubeRequest){
            unset($replay['youtube_results']);
            $replay['youtube_query_hash']=hash('sha256',$query);
        }
        if($initiativeStatusRead){
            $replay['answer']='I checked for saved background findings.';
            $replay['sources']=[];
        }
        if(!empty($done['private_email_read'])){
            [$replay['answer'],$privateContext]=agent_connection_email_chat_view_v1(['email_private_read_v1'=>true],$done['answer'],[]);
            $replay['sources']=$privateContext['sources'];
        }
        agent_runtime_v125_idempotency_complete($idempotencyScope,$idempotencyKey,$replay);
    }
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    chat_v121_emit(['type'=>'start','conversation_id'=>$conversationId,'user_message_id'=>$done['user_message_id'],
        'assistant_message_id'=>$assistantMessageId,'agent_id'=>$agentScopeId]);
    chat_v121_emit(['type'=>'delta','delta'=>$done['answer']]);chat_v121_emit(['type'=>'done','data'=>$done]);exit;
}
$rawAgentContext=is_array($input['agent_context']??null)?$input['agent_context']:[];$rawAgentContext['conversation_id']=$conversationId;$rawAgentContext['user_agent_id']=$agentScopeId;$historyStmt=$pdo->prepare('SELECT role,message FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12');$historyStmt->execute([$conversationId]);$history=array_reverse($historyStmt->fetchAll());
$stmt=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,?,?,?,?)');
$stmt->execute([$conversationId,$userId,'user',$query,$emailRequest?'{"skip_brain_archive":true}':null]);
$userMessageId=(int)$pdo->lastInsertId();
$reminderTool=null;$workingSelection=null;
unset($rawAgentContext['working_context_ref'],$rawAgentContext['initiative_reply'],$rawAgentContext['initiative_followthrough'],$rawAgentContext['followthrough'],$rawAgentContext['advisory_context'],$rawAgentContext['verified_followthrough'],$rawAgentContext['initiative_reply_anchor_v1'],$rawAgentContext['initiative_reply_recovery'],$rawAgentContext['initiative_request'],$rawAgentContext['initiative_claim'],$rawAgentContext['initiative_voice_request'],$rawAgentContext['initiative_reply_unassociated_v1']);
$followthroughAuthority=static fn()=>vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input);
$followthroughSelection=$emailRequest||$connectedSitePlan!==null?null:vp3_initiative_followthrough_reply_v1($pdo,$user,$principal,$conversationId,$userMessageId,$initiativeReply,$query,$followthroughAuthority);
if(!empty($followthroughSelection['handled'])){
    $ownerAuthority=static fn()=>vp3_agent_chat_followthrough_owner_v2160($pdo,$user,$input);
    $done=vp3_agent_chat_followthrough_finish_v2160($pdo,$user,$activeAgent,$principal,$conversationId,$userMessageId,$query,'voice',$followthroughSelection,$ownerAuthority);
    $assistantMessageId=(int)$done['assistant_message_id'];
    if($idempotencyClaimed&&$idempotencyKey!=='')agent_runtime_v125_idempotency_complete($idempotencyScope,$idempotencyKey,$done);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    chat_v121_emit(['type'=>'start','conversation_id'=>$conversationId,'user_message_id'=>$userMessageId,'assistant_message_id'=>$assistantMessageId,'agent_id'=>$agentScopeId]);
    chat_v121_emit(['type'=>'delta','delta'=>$done['answer']]);chat_v121_emit(['type'=>'done','data'=>$done]);exit;
}
if(array_key_exists('initiative_reply',$input))throw new RuntimeException('That follow-through reply is no longer current.',409);
if($connectedSitePlan===null&&array_key_exists('working_context_ref',$input)){
    require_once dirname(__DIR__).'/includes/agent-working-context-v1.php';
    $workingContextRef=vp3_working_context_request_v1($query,$input['working_context_ref']);
    if($workingContextRef!==null){
        $reminderTool=vp3_working_context_chat_v1($pdo,$user,$principal,$conversationId,$workingContextRef);
        if(is_array($reminderTool['working_context']??null)){
            $workingSelection=$reminderTool;$rawAgentContext['working_context_ref']=$workingContextRef;
            $reminderTool=null;$GLOBALS['vp3_working_context_stream_buffer_v1']=[];
        }
    }
}
if($reminderTool===null&&$emailRequest)$reminderTool=agent_connection_email_chat_v1($pdo,$user,$principal,$query,$conversationId,$userMessageId,$followthroughAuthority,true);
if($reminderTool===null&&empty($input['ephemeral_protocol'])){
    if($connectedSitePlan!==null&&session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $reminderTool=vp3_connected_sites_chat_v1($pdo,$user,$principal,$query,$conversationId,$userMessageId,$connectedSitePlan);
}
if($reminderTool===null&&vp3_catch_up_chat_scope_v1($query)!==null){
    $reminderTool=vp3_capability_chat_v1($pdo,$user,$principal,$query,
        ['conversation_id'=>$conversationId,'user_agent_id'=>$agentScopeId],
        $input['knowledge_scope']??null,$history);
}
if($reminderTool===null){
    $agentContext=agent_surface_v131_enrich($user,'chat',$rawAgentContext);
    $agentContext['chat_user_message_id']=$userMessageId;
    if($brainAllowed)agent_brain_archive_and_parse($user,$conversationId,$userMessageId,'user',$query,'voice');
    vp3_capability_begin_v1($pdo,$user,$principal,$agentContext,$input['knowledge_scope']??null);
    $reminderTool=vp3_capability_chat_v1($pdo,$user,$principal,$query,$agentContext,$input['knowledge_scope']??null,$history);
}else{
    $agentContext=['conversation_id'=>$conversationId,'user_agent_id'=>$agentScopeId,'chat_user_message_id'=>$userMessageId];
}
if(empty($reminderTool['handled']))$reminderTool=vp3_transcript_chat_v1($pdo,$user,$principal,$query,$agentContext,$history);
if(empty($reminderTool['handled'])&&knowledge_retrieval_v162_owner_session($user,$principal)){
    $reminderZone=(string)($rawAgentContext['timezone']??$input['reminder_timezone']??user_calendar_default_timezone_v1300($pdo,$user));
    try{reminder_zone_v1($reminderZone);}catch(Throwable $ignored){$reminderZone=user_calendar_default_timezone_v1300($pdo,$user);}
    $reminderTool=reminder_chat_v1($pdo,$user,$query,'cloud:'.$conversationId,$idempotencyKey!==''?'stream:'.hash('sha256',$idempotencyKey):'message:'.$userMessageId,$conversationId,$reminderZone);
}
$toolResult=!empty($reminderTool['handled'])?vp3_agent_tool_authorize_result_v400($reminderTool,$user,$query,$agentContext,$principal):agent_runtime_v125_span('chat.tools',static fn():array=>vp3_agent_chat_tool_query_v1($query,$user,$conversationId,$agentContext),['conversation_id'=>$conversationId,'user_id'=>$userId,'agent_id'=>$agentScopeId]);
$serviceContext=['service'=>'conversation','agent_id'=>$agentScopeId,'conversation_id'=>$conversationId,'job_key'=>'chat:'.$userMessageId];
$runtimePlan=!empty($toolResult['handled'])?vp3_agent_runtime_tool_plan_v420($userId,$agentScopeId,'chat'):vp3_agent_runtime_plan_v420($pdo,$user,$agentScopeId,'chat',$serviceContext);
$stmt=$pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,'assistant','','{}')");$stmt->execute([$conversationId]);$assistantMessageId=(int)$pdo->lastInsertId();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();while(ob_get_level()>0)@ob_end_flush();chat_v121_emit(['type'=>'start','conversation_id'=>$conversationId,'user_message_id'=>$userMessageId,'assistant_message_id'=>$assistantMessageId,'agent_id'=>$agentScopeId,'agent_name'=>(string)$principal['display_name'],'runtime'=>vp3_agent_runtime_public_plan_v420($runtimePlan)]);
$streamPartial=false;$homeAttempted=false;$homeResult=null;$context=[];$knowledgeContext=['scope'=>knowledge_retrieval_v162_normalize_scope($input['knowledge_scope']??null),'citations'=>[],'provenance'=>[],'homeserver_local_knowledge'=>'not_queried'];
if(!empty($toolResult['handled'])){
    $answer=(string)$toolResult['answer'];if($answer!=='')chat_v121_emit(['type'=>'delta','delta'=>$answer]);
    $homeReceipt=is_array($toolResult['execution']??null)?$toolResult['execution']:[];
    if($homeReceipt){
        $execution=vp3_agent_runtime_finalize_v420($runtimePlan,chat_execution_v019_homeserver_receipt($homeReceipt),'homeserver_local_read');
        $capabilityRoute=['version'=>'v4.20','capability'=>(string)($homeReceipt['domain']??'tools'),'planned_source'=>'homeserver','actual_source'=>'homeserver_local','supported'=>true,'ready'=>true,'fallback_used'=>!empty($homeReceipt['fallback_used']),'reason'=>'homeserver_v230_local_read'];
    }else{
        $execution=vp3_agent_runtime_finalize_v420($runtimePlan,chat_execution_v019_tool(),'vp3_tool');
        $capabilityRoute=['version'=>'v4.20','capability'=>'tools','planned_source'=>'vp3_tool','actual_source'=>'vp3_tool','supported'=>true,'ready'=>true,'fallback_used'=>false,'reason'=>'vp3_tool_handled'];
    }
}else{
    if(!empty($runtimePlan['try_homeserver'])&&!empty($runtimePlan['home']['supported'])){$homeAttempted=true;$homeResult=homeserver_agent_v025_chat($user,$query,$conversationId,$history,$principal,$activeAgent,$agentContext,!empty($runtimePlan['homeserver_cloud_allowed']),knowledge_retrieval_v162_normalize_scope($input['knowledge_scope']??null)['mode']==='all');}
    if($homeAttempted&&!$homeResult)homeserver_agent_v018_require_safe_fallback(homeserver_agent_v018_last_attempt());
    if($homeResult){
        $answer=(string)$homeResult['answer'];if($answer!=='')chat_v121_emit(['type'=>'delta','delta'=>$answer]);$execution=vp3_agent_runtime_homeserver_execution_v420($homeResult);$execution['brain_delegation']=homeserver_agent_v025_public_state($homeResult);$execution=vp3_agent_runtime_finalize_v420($runtimePlan,$execution,'homeserver');
    }elseif((string)$runtimePlan['effective_preference']==='homeserver_only'){
        if(function_exists('homeserver_compute_v232_failure'))homeserver_compute_v232_failure($userId);
        throw new RuntimeException(vp3_agent_runtime_block_message_v420($runtimePlan));
    }elseif(empty($runtimePlan['cloud']['ready'])||((string)$runtimePlan['route']!=='vp3_cloud'&&empty($runtimePlan['allow_vp3_fallback']))){
        if($homeAttempted&&function_exists('homeserver_compute_v232_failure'))homeserver_compute_v232_failure($userId);
        throw new RuntimeException(vp3_agent_runtime_block_message_v420($runtimePlan));
    }else{
        $baseContext=agent_runtime_v125_span('chat.context',static fn():array=>chat_v121_context($query,$user,$principal,$agentContext,$conversationId),['conversation_id'=>$conversationId,'user_id'=>$userId,'agent_id'=>$agentScopeId]);$knowledgeBundle=knowledge_retrieval_v162_for_chat($pdo,$user,$principal,$query,$input['knowledge_scope']??null,$baseContext,$conversationId);$context=$knowledgeBundle['context'];$knowledgeContext=['scope'=>$knowledgeBundle['scope'],'citations'=>$knowledgeBundle['citations'],'provenance'=>$knowledgeBundle['provenance'],'homeserver_local_knowledge'=>$knowledgeBundle['homeserver_local_knowledge']];
        $streamResult=agent_runtime_v125_span('chat.ai_stream',static function()use($query,$history,$context,$user,$serviceContext):array{
            return ai_v121_stream_chat_response($query,$history,$context,$user,static function(string $delta):void{
                if(!connection_aborted())chat_v121_emit(['type'=>'delta','delta'=>$delta]);
            },$serviceContext);
        },['conversation_id'=>$conversationId,'user_id'=>$userId,'agent_id'=>$agentScopeId]);
        $answer=trim((string)($streamResult['answer']??''));
        $streamPartial=!empty($streamResult['partial'])||connection_aborted();
        if($answer===''&&!connection_aborted()){
            $answer=chat_local_answer($query,$context,$streamResult);
            if($answer!=='')chat_v121_emit(['type'=>'delta','delta'=>$answer]);
        }
        if($answer===''&&connection_aborted())$answer='Interrupted.';
        $execution=chat_execution_v019_provider_outcome($user,$streamResult,$homeAttempted,!empty($runtimePlan['home']['paired']));
        $execution=vp3_agent_runtime_finalize_v420($runtimePlan,$execution,$homeAttempted?'homeserver->vp3_cloud':'vp3_cloud',$homeAttempted?'homeserver_unavailable':'none');
    }
    $capabilityRoute=vp3_agent_runtime_capability_route_v420($runtimePlan,$execution,$homeAttempted);
}
$execution['capability_route']=$capabilityRoute;
if($workingSelection!==null){
    $recheck=vp3_working_context_chat_v1($pdo,$user,$principal,$conversationId,
        (string)$workingSelection['working_context']['reference']);
    if(!isset($recheck['working_context']))throw new RuntimeException('That working context changed while the Agent was responding. Check the latest update and try again.',409);
}

if(function_exists('homeserver_compute_v232_attach'))$execution=homeserver_compute_v232_attach($userId,$execution,$homeResult,$homeAttempted);
$localRead=function_exists('homeserver_reads_v231_last')?homeserver_reads_v231_last():['attempted'=>false,'execution'=>null,'domain'=>''];
if(!empty($localRead['attempted']))$execution['homeserver_local_read']=[
    'domain'=>(string)($localRead['domain']??''),
    'execution'=>is_array($localRead['execution']??null)?$localRead['execution']:null,
];
ai_usage_accounting_v032_record($pdo,$user,$agentScopeId,$conversationId,$execution);
$publicSources=$workingSelection['sources']??[];foreach($context as $item){$source=(string)($item['source']??'');if(chat_context_is_internal_source($source))continue;$entry=['source'=>$source,'title'=>(string)($item['title']??'')];if(str_starts_with($source,'knowledge:')){$knowledgeId=(int)substr($source,strlen('knowledge:'));$knowledge=get_knowledge_item($knowledgeId);if($knowledge&&!empty($knowledge['file_path'])){$scope=(string)($knowledge['knowledge_scope']??'system');$owned=(int)($knowledge['created_by_user_id']??0)===$userId;if(($scope==='personal'&&$owned)||$scope==='system')$entry['url']=url('/knowledge-file.php?id='.$knowledgeId);}}$publicSources[]=$entry;}$toolSources=!empty($toolResult['sources'])&&is_array($toolResult['sources'])?$toolResult['sources']:[];$publicSources=array_merge($publicSources,$toolSources);$publicSources[]=chat_execution_v019_source($execution);$mediaLimit=track_is_playlist_request($query)?7:(track_is_next_request($query)?1:4);$connectedSiteRead=isset($toolResult['connected_site_context_v1']);$answerMedia=$connectedSiteRead?[]:track_media_from_answer($answer,$user,$mediaLimit);$queryMedia=$connectedSiteRead?[]:track_media_suggestions($query,$user,$mediaLimit);$media=array_slice(track_merge_media_suggestions($answerMedia,$queryMedia),0,$mediaLimit);$playlistTitle=$media?track_playlist_title($query):'';$publicSources=array_merge($publicSources,capture_r5_home_sources($pdo,$user,$homeResult??[]));$publicSources=array_merge($publicSources,capture_r5_sources($knowledgeContext['citations']??[]));$systemAppPlan=is_array($toolResult['system_app_plan']??null)?$toolResult['system_app_plan']:null;$systemAppCard=function_exists('vp3_system_apps_action_card_v190')?vp3_system_apps_action_card_v190($systemAppPlan):null;
$persistedAgentContext=$agentContext;unset($persistedAgentContext['screen_observations']);
$messageContext=['canvas_media'=>$toolResult['canvas_media']??null,'navigation_context'=>$toolResult['navigation_context']??null,'sticky_notes'=>$toolResult['sticky_notes']??[],'task_context'=>$toolResult['task_context']??null,'transcript_context'=>$toolResult['transcript_context']??null,'system_app_plan'=>$systemAppPlan,'system_app_action_card'=>$systemAppCard,'tool_receipt'=>vp3_tool_receipt_normalize_v1($toolResult['tool_receipt']??null),'sources'=>$publicSources,'knowledge'=>$knowledgeContext,'media'=>$media,'stem_media'=>!empty($toolResult['stem_media'])&&is_array($toolResult['stem_media'])?$toolResult['stem_media']:[],'actions'=>!empty($toolResult['actions'])&&is_array($toolResult['actions'])?$toolResult['actions']:[],'profile_webmcp_plan'=>is_array($toolResult['profile_webmcp_plan']??null)?$toolResult['profile_webmcp_plan']:null,'profile_webmcp_return'=>is_array($toolResult['profile_webmcp_return']??null)?$toolResult['profile_webmcp_return']:null,'playlist_title'=>$playlistTitle,'conversation_v131'=>true,'stream_partial'=>$streamPartial,'trace_id'=>agent_runtime_v125_trace_id(),'agent_context'=>$persistedAgentContext,'agent'=>['id'=>$agentScopeId,'name'=>(string)$principal['display_name'],'kind'=>(string)$principal['kind']],'execution'=>$execution];[$storedAnswer,$messageContext]=vp3_connected_sites_chat_storage_v1($toolResult,$answer,$messageContext);
$messageContext['chat_user_message_id']=$userMessageId;
$contextJson=json_encode($messageContext,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$stmt=$pdo->prepare('UPDATE chat_messages SET message=?,context_json=? WHERE id=? AND conversation_id=?');$stmt->execute([$storedAnswer,is_string($contextJson)?$contextJson:'{}',$assistantMessageId,$conversationId]);if($brainAllowed&&empty($toolResult['transcript_context'])&&!isset($toolResult['connected_site_context_v1']))agent_brain_archive_and_parse($user,$conversationId,$assistantMessageId,'assistant',$answer,'voice');$pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$conversationId,$userId]);
$done=['ok'=>true,'canvas_media'=>$messageContext['canvas_media'],'sticky_notes'=>$messageContext['sticky_notes'],'system_app_plan'=>$systemAppPlan,'system_app_action_card'=>$systemAppCard,'tool_receipt'=>$messageContext['tool_receipt'],'conversation_id'=>$conversationId,'user_message_id'=>$userMessageId,'assistant_message_id'=>$assistantMessageId,'answer'=>$answer,'sources'=>$publicSources,'knowledge'=>$knowledgeContext,'media'=>$media,'stem_media'=>$messageContext['stem_media'],'actions'=>$messageContext['actions'],'profile_webmcp_plan'=>$messageContext['profile_webmcp_plan'],'profile_webmcp_return'=>$messageContext['profile_webmcp_return'],'playlist_title'=>$playlistTitle,'input_mode'=>'voice','stream_partial'=>$streamPartial,'trace_id'=>agent_runtime_v125_trace_id(),'agent_name'=>(string)$principal['display_name'],'agent_id'=>$agentScopeId,'execution'=>$execution];if($workingSelection!==null)$done['working_context_ref']=$workingSelection['working_context']['reference'];
if($idempotencyClaimed&&$idempotencyKey!==''){
    $replay=$connectedSiteRead?array_replace($done,['answer'=>$storedAnswer,'sources'=>[]]):$done;
    if($connectedSitePlan!==null)$replay['connected_site_query_hash']=hash('sha256',$query);
    agent_runtime_v125_idempotency_complete($idempotencyScope,$idempotencyKey,$replay);
}
if($brainAllowed){agent_background_v125_enqueue('conversation-summary',['user_id'=>$userId,'conversation_id'=>$conversationId],'conversation-'.$userId.'-'.$conversationId);agent_background_v125_enqueue('memory-reconcile',['user_id'=>$userId],'memory-'.$userId,1);}agent_runtime_v125_trace('chat.turn.completed',['user_id'=>$userId,'conversation_id'=>$conversationId,'agent_id'=>$agentScopeId,'assistant_message_id'=>$assistantMessageId,'partial'=>$streamPartial,'actual_route'=>(string)($execution['actual_route']??'')]);if(isset($GLOBALS['vp3_working_context_stream_buffer_v1'])){
    $buffered=$GLOBALS['vp3_working_context_stream_buffer_v1'];unset($GLOBALS['vp3_working_context_stream_buffer_v1']);
    foreach($buffered as $event)chat_v121_emit($event);
}
chat_v121_emit(['type'=>'done','data'=>$done]);
}catch(Throwable $e){if($assistantMessageId>0&&$pdo instanceof PDO){try{$pdo->prepare("DELETE FROM chat_messages WHERE id=? AND conversation_id=? AND message='' ")->execute([$assistantMessageId,$conversationId]);}catch(Throwable $cleanup){}}$publicError=ai_v100_safe_exception($e,'The voice conversation request could not be completed.');if($idempotencyClaimed&&$idempotencyScope!==''&&$idempotencyKey!==''){
    $failedVoice=['ok'=>false,'error'=>$publicError,'conversation_id'=>$conversationId,'assistant_message_id'=>$assistantMessageId,'trace_id'=>agent_runtime_v125_trace_id()];
    if(!empty($emailReviewRequest))$failedVoice['gmail_query_hash']=hash('sha256',$query);
    if(!empty($connectedSitePlan))$failedVoice['connected_site_query_hash']=hash('sha256',$query);
    if(is_array($initiativeReply??null)||is_array($initiativeRequest??null)){
        $failedVoice['initiative_reply_failure']=['initiative_reply'=>$initiativeReply,'explicit'=>$initiativeReply!==null,
            'query_hash'=>hash('sha256',$query),'conversation_id'=>$conversationId,'agent_id'=>$agentScopeId];
        try{
            $anchor=vp3_initiative_followthrough_request_v1($pdo,$user,$principal,$conversationId,$query,null,$initiativeReply);
            if(is_array($anchor)&&($anchor['user_message_id']??0)>0)$failedVoice['initiative_reply_failure']['request_anchor']=$anchor;
        }catch(Throwable $anchorError){}
    }
    if(is_array($voiceFollowthroughRequest??null)){
        // A late expired generation cannot replace a successful newer stream
        // cache with its error. The shared private store retains recovery state.
        try{vp3_agent_chat_followthrough_record_v2160($idempotencyScope,$idempotencyKey,static function(array &$row)use($voiceFollowthroughRequest,$failedVoice):void{
            if(($row['status']??null)==='processing'&&($row['initiative_voice_request']??null)===$voiceFollowthroughRequest)$row['initiative_reply_failure']=$failedVoice['initiative_reply_failure']??null;
        });}catch(Throwable $voiceStorageError){}
    }else agent_runtime_v125_idempotency_complete($idempotencyScope,$idempotencyKey,$failedVoice);
}agent_runtime_v125_trace('chat.turn.error',['conversation_id'=>$conversationId,'assistant_message_id'=>$assistantMessageId,'error_class'=>get_class($e)]);$followthroughError=$e instanceof VP3InitiativeReplyExceptionV2160||array_key_exists('initiative_reply',$input??[])||is_array($initiativeRequest??null)||!empty($followthroughSelection['handled'])||!empty($followthroughReplay);$status=(int)$e->getCode();if($followthroughError){$status=in_array($status,[403,404,409,422,503],true)?$status:503;$publicError=$status===409?'initiative_reply_stale':($status===403?'initiative_reply_forbidden':'initiative_reply_unavailable');}chat_v121_emit(['type'=>'error','error'=>$publicError]+($followthroughError?['status'=>$status,'code'=>$publicError]:[]));}
