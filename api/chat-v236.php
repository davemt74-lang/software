<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/chat-execution-v019.php';
require_once dirname(__DIR__) . '/includes/homeserver-agent-v025.php';
require_once dirname(__DIR__) . '/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__) . '/includes/agent-tool-authorization-v400.php';
require_once dirname(__DIR__) . '/includes/agent-work-control-v173.php';
require_once dirname(__DIR__) . '/includes/user-calendar-v1300.php';
require_once dirname(__DIR__) . '/includes/user-calendar-agent-v1300.php';
require_once dirname(__DIR__) . '/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__) . '/includes/browser-context-v2130.php';
require_once dirname(__DIR__) . '/includes/video-meetings-memory-v18120.php';
require_once dirname(__DIR__) . '/includes/video-meetings-commitment-command-v18230-chat.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
$user=current_user();if(!$user||!has_permission('chat.access',$user)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Chat access is not available for this account.']);exit;}
$pdo=db();if(!$pdo||!table_exists('chat_conversations')||!user_agent_system_schema_ready_v236($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Agent Chat storage is not ready. An administrator needs to run the database upgrade.']);exit;}
$userId=(int)$user['id'];$brainAllowed=personal_capability_has_v242('agent_brain.access',$user);
$requestedAgentId=max(0,(int)($_GET['agent']??0));
try{$activeAgent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId);}catch(RuntimeException $e){http_response_code(404);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);exit;}
$principal=vp3_agent_chat_principal_v380($user,$activeAgent);
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;$csrf=(string)($input['csrf_token']??'');if($csrf===''||!hash_equals(csrf_token(),$csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh the page and try again.']);exit;}$action=(string)($input['action']??'send');

function chat_v236_scope_sql(?array $agent,string $alias='c'): array{return vp3_agent_chat_scope_sql_v380($agent,$alias);}
function chat_v236_conversation(PDO $pdo,int $conversationId,int $userId,?array $agent): ?array{return vp3_agent_chat_conversation_v380($pdo,$conversationId,$userId,$agent);}
function chat_v236_authorized_media(array $media,array $context): array{$titles=[];foreach($context as $item){$source=(string)($item['source']??'');if(str_starts_with($source,'database:track:')||str_starts_with($source,'artist:track:'))$titles[mb_strtolower(trim((string)($item['title']??'')))]=true;}if(!$titles)return [];return array_values(array_filter($media,static function(array $item)use($titles):bool{$title=mb_strtolower(trim((string)($item['title']??'')));return $title!==''&&isset($titles[$title]);}));}
function chat_v236_calendar_context_events(array $awareness): array{$events=[];if(empty($awareness['available']))return $events;$current=is_array($awareness['current']??null)?$awareness['current']:null;if($current){$events[]=['id'=>'calendar-current-'.(string)($current['ref']??''),'type'=>'event','event_kind'=>'calendar_current','title'=>(string)($current['title']??'Current commitment'),'summary'=>'In progress until '.(string)($current['end_local']??'').'.','source'=>'calendar_awareness'];}$next=is_array($awareness['next']??null)?$awareness['next']:null;if($next){$events[]=['id'=>'calendar-next-'.(string)($next['ref']??''),'type'=>'event','event_kind'=>'calendar_next','title'=>(string)($next['title']??'Next commitment'),'summary'=>'Starts '.(string)($next['start_local']??'').' · '.max(0,(int)($next['starts_in_minutes']??0)).' minutes from now.','source'=>'calendar_awareness'];}foreach(array_slice((array)($awareness['conflicts']??[]),0,2) as $index=>$conflict){if(!is_array($conflict))continue;$events[]=['id'=>'calendar-conflict-'.$index.'-'.sha1((string)($conflict['left_ref']??'').'|'.(string)($conflict['right_ref']??'')),'type'=>'event','event_kind'=>'calendar_conflict','title'=>'Calendar overlap','summary'=>(string)($conflict['left_title']??'Scheduled item').' overlaps '.(string)($conflict['right_title']??'Scheduled item').' by about '.max(1,(int)($conflict['overlap_minutes']??0)).' minutes.','source'=>'calendar_awareness'];}return array_slice($events,0,4);}

try{
if($action==='list'){[$scope,$params]=chat_v236_scope_sql($activeAgent,'c');$stmt=$pdo->prepare("SELECT c.id,c.title,c.created_at,c.updated_at,COALESCE(MAX(m.id),0) latest_message_id FROM chat_conversations c LEFT JOIN chat_messages m ON m.conversation_id=c.id WHERE c.user_id=? AND {$scope} GROUP BY c.id ORDER BY latest_message_id DESC,c.updated_at DESC,c.id DESC LIMIT 50");$stmt->execute(array_merge([$userId],$params));echo json_encode(['ok'=>true,'conversations'=>$stmt->fetchAll(),'agent_name'=>(string)$principal['display_name'],'agent_id'=>(int)$principal['agent_id']]);exit;}
if($action==='activity'){player_process_show_reminders($user);[$scope,$params]=chat_v236_scope_sql($activeAgent,'c');$stmt=$pdo->prepare("SELECT c.id FROM chat_conversations c WHERE c.user_id=? AND {$scope} ORDER BY c.updated_at DESC,c.id DESC LIMIT 1");$stmt->execute(array_merge([$userId],$params));$activeConversationId=(int)$stmt->fetchColumn();$activeMessageId=0;if($activeConversationId>0){$m=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE conversation_id=?');$m->execute([$activeConversationId]);$activeMessageId=(int)$m->fetchColumn();}if(!table_exists('notifications')){echo json_encode(['ok'=>true,'updates'=>[],'latest_id'=>0,'unread_count'=>0,'conversation_id'=>$activeConversationId,'latest_message_id'=>$activeMessageId]);exit;}$afterId=max(0,(int)($input['after_id']??0));if($afterId>0){$n=$pdo->prepare("SELECT id,type,title,body,target_url,created_at FROM notifications WHERE user_id=? AND id>? AND type IN ('agent_track_share','producer_track_share','agent_supervisor_listen','stem_region_note','production_note','new_track_release','new_album_release','show_reminder','artist_post','release_deadline','release_action') ORDER BY id ASC LIMIT 30");$n->execute([$userId,$afterId]);$updates=$n->fetchAll();}else{$n=$pdo->prepare("SELECT id,type,title,body,target_url,created_at FROM notifications WHERE user_id=? AND type IN ('agent_track_share','producer_track_share','agent_supervisor_listen','stem_region_note','production_note','new_track_release','new_album_release','show_reminder','artist_post','release_deadline','release_action') AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY id DESC LIMIT 10");$n->execute([$userId]);$updates=array_reverse($n->fetchAll());}$latest=$pdo->prepare("SELECT COALESCE(MAX(id),0) FROM notifications WHERE user_id=? AND type IN ('agent_track_share','producer_track_share','agent_supervisor_listen','stem_region_note','production_note','new_track_release','new_album_release','show_reminder','artist_post','release_deadline','release_action')");$latest->execute([$userId]);echo json_encode(['ok'=>true,'updates'=>$updates,'latest_id'=>(int)$latest->fetchColumn(),'unread_count'=>notification_unread_count($user),'conversation_id'=>$activeConversationId,'latest_message_id'=>$activeMessageId],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
if($action==='new'){$workspaceId=artist_workspace_v181_scope_id($user);$conversation=vp3_agent_chat_create_conversation_v380($pdo,$user,$activeAgent,'New chat',$workspaceId);echo json_encode(['ok'=>true,'conversation_id'=>(int)$conversation['id']]);exit;}
if($action==='load'){$conversationId=(int)($input['conversation_id']??0);$conversation=chat_v236_conversation($pdo,$conversationId,$userId,$activeAgent);if(!$conversation)throw new RuntimeException('Conversation not found for this agent.');$stmt=$pdo->prepare('SELECT id,role,message,context_json,created_at FROM chat_messages WHERE conversation_id=? ORDER BY created_at DESC,id DESC LIMIT 300');$stmt->execute([$conversationId]);echo json_encode(['ok'=>true,'conversation'=>$conversation,'messages'=>array_reverse($stmt->fetchAll()),'agent_name'=>(string)$principal['display_name']]);exit;}
if($action==='messages_after'){$conversationId=(int)($input['conversation_id']??0);if(!chat_v236_conversation($pdo,$conversationId,$userId,$activeAgent))throw new RuntimeException('Conversation not found for this agent.');$afterId=max(0,(int)($input['after_id']??0));$stmt=$pdo->prepare('SELECT id,role,message,context_json,created_at FROM chat_messages WHERE conversation_id=? AND id>? ORDER BY id ASC LIMIT 80');$stmt->execute([$conversationId,$afterId]);echo json_encode(['ok'=>true,'conversation_id'=>$conversationId,'messages'=>$stmt->fetchAll()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
if($action==='delete'){$conversationId=(int)($input['conversation_id']??0);if(!chat_v236_conversation($pdo,$conversationId,$userId,$activeAgent))throw new RuntimeException('Conversation not found for this agent.');homeserver_agent_v018_forget($userId,$conversationId);$pdo->prepare('DELETE FROM chat_conversations WHERE id=? AND user_id=?')->execute([$conversationId,$userId]);echo json_encode(['ok'=>true]);exit;}
if($action==='send'){
$query=trim((string)($input['message']??''));$conversationId=(int)($input['conversation_id']??0);$inputMode=(string)($input['input_mode']??'text');$inputMode=$inputMode==='voice'?'voice':'text';if($query==='')throw new RuntimeException('Enter a message.');if(mb_strlen($query)>6000)throw new RuntimeException('That message is too long.');
$activeAgentId=vp3_agent_chat_agent_id_v380($activeAgent);
if($conversationId<1){$workspaceId=artist_workspace_v181_scope_id($user);$conversation=vp3_agent_chat_create_conversation_v380($pdo,$user,$activeAgent,$query,$workspaceId);$conversationId=(int)$conversation['id'];}elseif(!chat_v236_conversation($pdo,$conversationId,$userId,$activeAgent))throw new RuntimeException('Conversation not found for this agent.');
$rawAgentContext=is_array($input['agent_context']??null)?$input['agent_context']:[];
$browserContext=null;$browserContextRelations=[];
$rawBrowserContext=is_array($rawAgentContext['browser_context']??null)?$rawAgentContext['browser_context']:[];
unset($rawAgentContext['browser_context']);
if($rawBrowserContext){
    $page=is_array($rawBrowserContext['page']??null)?$rawBrowserContext['page']:[];
    try{
        $browserContext=vp3_browser_context_validate_v2130([
            'source_url'=>$page['url']??'',
            'canonical_url'=>$page['canonical_url']??'',
            'title'=>$page['title']??'',
            'selected_text'=>$page['selected_text']??'',
            'metadata'=>$page['metadata']??[],
            'media'=>$page['media']??null,
        ]);
        $browserContextRelations=vp3_browser_context_relationships_v2130($pdo,$user,$browserContext);
        $rawAgentContext['browser_context']=vp3_browser_context_agent_payload_v2130(
            $browserContext,
            $browserContextRelations,
            (string)($rawBrowserContext['prompt']??'')
        );
    }catch(Throwable $e){
        error_log('VP3 Agent Chat browser context rejected: '.$e->getMessage());
        $browserContext=null;$browserContextRelations=[];
    }
}
$rawAgentContext['conversation_id']=$conversationId;$rawAgentContext['user_agent_id']=$activeAgentId;$calendarAwareness=calendar_schedule_awareness_snapshot_v1330($pdo,$user);$calendarContextEvents=chat_v236_calendar_context_events($calendarAwareness);$clientEvents=is_array($rawAgentContext['events']??null)?$rawAgentContext['events']:[];$rawAgentContext['events']=array_merge($calendarContextEvents,$clientEvents);$meetingMemory=video_meeting_memory_agent_context_v18120($pdo,$userId,$query);$meetingMemory=video_meeting_commitment_command_enrich_v18230($pdo,$userId,$query,$meetingMemory);if(!empty($meetingMemory['relevant']))$rawAgentContext['meeting_memory']=$meetingMemory;$agentContext=agent_surface_v131_enrich($user,'chat',$rawAgentContext);if(!empty($meetingMemory['relevant']))$agentContext['meeting_memory']=$meetingMemory;$agentContext['calendar_awareness']=$calendarAwareness;
$persistedAgentContext=$agentContext;
unset($persistedAgentContext['browser_context']);$historyStmt=$pdo->prepare('SELECT role,message FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12');$historyStmt->execute([$conversationId]);$history=array_reverse($historyStmt->fetchAll());$stmt=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (?,?,?,?)');$stmt->execute([$conversationId,$userId,'user',$query]);$userMessageId=(int)$pdo->lastInsertId();if($brainAllowed)agent_brain_archive_and_parse($user,$conversationId,$userMessageId,'user',$query,$inputMode);
$toolResult=agent_work_control_chat_v173($query,$user,$conversationId);if(!empty($toolResult['handled'])){$toolResult=vp3_agent_tool_authorize_result_v400($toolResult,$user,$query);}else{$toolResult=user_calendar_agent_query_v1300($query,$user,$conversationId);if(!empty($toolResult['handled'])){$toolResult=vp3_agent_tool_authorize_result_v400($toolResult,$user,$query);}else{$toolResult=function_exists('release_v105_chat_tool')?release_v105_chat_tool($query,$user,$conversationId):vp3_agent_tool_empty_v400();if(empty($toolResult['handled']))$toolResult=vp3_agent_tool_execute_query_v400($query,$user,$conversationId);else $toolResult=vp3_agent_tool_authorize_result_v400($toolResult,$user,$query);}}
$homeAttempted=false;$knowledgeContext=['scope'=>knowledge_retrieval_v162_normalize_scope($input['knowledge_scope']??null),'citations'=>[],'provenance'=>[],'homeserver_local_knowledge'=>'not_queried'];
if(!empty($toolResult['handled'])){
    $runtimePlan=vp3_agent_runtime_tool_plan_v420($userId,$activeAgentId,'chat');$answer=(string)$toolResult['answer'];$context=[];$execution=vp3_agent_runtime_finalize_v420($runtimePlan,chat_execution_v019_tool(),'vp3_tool');$capabilityRoute=['version'=>'v4.20','capability'=>'tools','planned_source'=>'vp3_tool','actual_source'=>'vp3_tool','supported'=>true,'ready'=>true,'fallback_used'=>false,'reason'=>'vp3_tool_handled'];
}else{
    $runtimePlan=vp3_agent_runtime_plan_v420($pdo,$user,$activeAgentId,'chat');
    $homeResult=null;
    if(!empty($runtimePlan['try_homeserver'])&&!empty($runtimePlan['home']['supported'])){
        $homeAttempted=true;
        $homeResult=homeserver_agent_v025_chat($user,$query,$conversationId,$history,$principal,$activeAgent,$agentContext,!empty($runtimePlan['homeserver_cloud_allowed']));
    }
    if($homeResult){
        $answer=(string)$homeResult['answer'];$context=[];$execution=vp3_agent_runtime_homeserver_execution_v420($homeResult);$execution['brain_delegation']=homeserver_agent_v025_public_state($homeResult);$execution=vp3_agent_runtime_finalize_v420($runtimePlan,$execution,'homeserver');
    }elseif((string)$runtimePlan['effective_preference']==='homeserver_only'){
        throw new RuntimeException(vp3_agent_runtime_block_message_v420($runtimePlan));
    }elseif(empty($runtimePlan['cloud']['ready'])||((string)$runtimePlan['route']!=='vp3_cloud'&&empty($runtimePlan['allow_vp3_fallback']))){
        throw new RuntimeException(vp3_agent_runtime_block_message_v420($runtimePlan));
    }else{
        $result=knowledge_retrieval_v162_generate_answer($query,$history,$user,$principal,$agentContext,$input['knowledge_scope']??null,$conversationId);
        if(!is_array($result)||!array_key_exists('answer',$result)||!isset($result['context'])||!is_array($result['context'])){
            $result=chat_generate_answer_policy_v236($query,$history,$user,$principal,$agentContext,$conversationId);
            $result['knowledge']=$knowledgeContext;
        }
        $answer=(string)$result['answer'];$context=$result['context'];$knowledgeContext=is_array($result['knowledge']??null)?$result['knowledge']:$knowledgeContext;$execution=$homeAttempted?chat_execution_v019_fallback($user,!empty($runtimePlan['home']['paired']),true):chat_execution_v019_vp3_direct($user);$execution=vp3_agent_runtime_finalize_v420($runtimePlan,$execution,$homeAttempted?'homeserver->vp3_cloud':'vp3_cloud',$homeAttempted?'homeserver_unavailable':'none');
    }
    $capabilityRoute=vp3_agent_runtime_capability_route_v420($runtimePlan,$execution,$homeAttempted);
}
$execution['capability_route']=$capabilityRoute;
ai_usage_accounting_v032_record($pdo,$user,$activeAgentId,$conversationId,$execution);
$publicSources=[];
if(is_array($browserContext)){
    $publicSources[]=[
        'source'=>'browser-context:ephemeral',
        'title'=>(string)($browserContext['title']??$browserContext['domain']??'Current page'),
        'url'=>(string)($browserContext['source_url']??''),
    ];
}
foreach($context as $item){$source=(string)($item['source']??'');if($source==='agent-context:v131'||$source==='agent:identity')continue;$entry=['source'=>$source,'title'=>(string)($item['title']??'')];if(str_starts_with($source,'knowledge:')){$kid=(int)substr($source,10);$knowledge=get_knowledge_item($kid);if($knowledge&&!empty($knowledge['file_path'])){$scope=(string)($knowledge['knowledge_scope']??'system');$owned=(int)($knowledge['created_by_user_id']??0)===$userId;if(($scope==='personal'&&$owned)||$scope==='system')$entry['url']=url('/knowledge-file.php?id='.$kid);}}$publicSources[]=$entry;}
$mediaLimit=track_is_playlist_request($query)?7:(track_is_next_request($query)?1:4);$answerMedia=track_media_from_answer($answer,$user,$mediaLimit);$queryMedia=track_media_suggestions($query,$user,$mediaLimit);$media=chat_v236_authorized_media(array_slice(track_merge_media_suggestions($answerMedia,$queryMedia),0,$mediaLimit),$context);$playlistTitle=$media?track_playlist_title($query):'';$toolSources=!empty($toolResult['sources'])&&is_array($toolResult['sources'])?$toolResult['sources']:[];$meetingSources=array_merge(video_meeting_memory_chat_sources_v18120($meetingMemory),video_meeting_commitment_command_chat_sources_v18230($meetingMemory));$publicSources=array_merge($publicSources,$toolSources,$meetingSources);$publicSources[]=chat_execution_v019_source($execution);$cardNamespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$activeAgentId);$cardRequests=function_exists('vp3_cognitive_cards_chat_requests_v520')?vp3_cognitive_cards_chat_requests_v520($pdo,$user,$cardNamespace,$query,8):[];$messageContext=['sources'=>$publicSources,'knowledge'=>$knowledgeContext,'media'=>$media,'stem_media'=>!empty($toolResult['stem_media'])&&is_array($toolResult['stem_media'])?$toolResult['stem_media']:[],'actions'=>!empty($toolResult['actions'])&&is_array($toolResult['actions'])?$toolResult['actions']:[],'cards'=>$cardRequests,'playlist_title'=>$playlistTitle,'agent_context'=>$persistedAgentContext,'agent'=>['id'=>(int)$principal['agent_id'],'name'=>(string)$principal['display_name'],'kind'=>(string)$principal['kind']],'execution'=>$execution];$contextJson=json_encode($messageContext,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$stmt=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,?,?,?)');$stmt->execute([$conversationId,'assistant',$answer,$contextJson]);$assistantMessageId=(int)$pdo->lastInsertId();if($brainAllowed)agent_brain_archive_and_parse($user,$conversationId,$assistantMessageId,'assistant',$answer,$inputMode);$pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=?')->execute([$conversationId]);echo json_encode(['ok'=>true,'conversation_id'=>$conversationId,'user_message_id'=>$userMessageId,'assistant_message_id'=>$assistantMessageId,'answer'=>$answer,'sources'=>$publicSources,'knowledge'=>$knowledgeContext,'media'=>$media,'stem_media'=>$messageContext['stem_media'],'actions'=>$messageContext['actions'],'cards'=>$messageContext['cards'],'playlist_title'=>$playlistTitle,'input_mode'=>$inputMode,'agent_name'=>(string)$principal['display_name'],'agent_id'=>(int)$principal['agent_id'],'execution'=>$execution],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
throw new RuntimeException('Unknown chat action.');
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}