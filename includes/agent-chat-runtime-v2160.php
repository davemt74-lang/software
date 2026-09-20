<?php
declare(strict_types=1);

const VP3_AGENT_CHAT_RUNTIME_V2160='agent-chat-runtime-v2160-20260920';

require_once __DIR__.'/chat-execution-v019.php';
require_once __DIR__.'/homeserver-agent-v025.php';
require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-tool-authorization-v400.php';
require_once __DIR__.'/agent-work-control-v173.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/user-calendar-agent-v1300.php';
require_once __DIR__.'/knowledge-retrieval-v162.php';
require_once __DIR__.'/browser-context-v2130.php';
require_once __DIR__.'/video-meetings-memory-v18120.php';
require_once __DIR__.'/video-meetings-commitment-command-v18230-chat.php';

function vp3_agent_chat_runtime_conversation_v2160(PDO $pdo,int $conversationId,int $userId,?array $agent): ?array
{
    return vp3_agent_chat_conversation_v380($pdo,$conversationId,$userId,$agent);
}

function vp3_agent_chat_runtime_authorized_media_v2160(array $media,array $context): array
{
    $titles=[];
    foreach($context as $item){
        $source=(string)($item['source']??'');
        if(str_starts_with($source,'database:track:')||str_starts_with($source,'artist:track:')){
            $titles[mb_strtolower(trim((string)($item['title']??'')))]=true;
        }
    }
    if(!$titles)return [];
    return array_values(array_filter($media,static function(array $item)use($titles): bool {
        $title=mb_strtolower(trim((string)($item['title']??'')));
        return $title!==''&&isset($titles[$title]);
    }));
}

function vp3_agent_chat_runtime_calendar_events_v2160(array $awareness): array
{
    $events=[];
    if(empty($awareness['available']))return $events;
    $current=is_array($awareness['current']??null)?$awareness['current']:null;
    if($current)$events[]=[
        'id'=>'calendar-current-'.(string)($current['ref']??''),'type'=>'event','event_kind'=>'calendar_current',
        'title'=>(string)($current['title']??'Current commitment'),
        'summary'=>'In progress until '.(string)($current['end_local']??'').'.','source'=>'calendar_awareness'
    ];
    $next=is_array($awareness['next']??null)?$awareness['next']:null;
    if($next)$events[]=[
        'id'=>'calendar-next-'.(string)($next['ref']??''),'type'=>'event','event_kind'=>'calendar_next',
        'title'=>(string)($next['title']??'Next commitment'),
        'summary'=>'Starts '.(string)($next['start_local']??'').' · '.max(0,(int)($next['starts_in_minutes']??0)).' minutes from now.',
        'source'=>'calendar_awareness'
    ];
    foreach(array_slice((array)($awareness['conflicts']??[]),0,2) as $index=>$conflict){
        if(!is_array($conflict))continue;
        $events[]=[
            'id'=>'calendar-conflict-'.$index.'-'.sha1((string)($conflict['left_ref']??'').'|'.(string)($conflict['right_ref']??'')),
            'type'=>'event','event_kind'=>'calendar_conflict','title'=>'Calendar overlap',
            'summary'=>(string)($conflict['left_title']??'Scheduled item').' overlaps '.(string)($conflict['right_title']??'Scheduled item').' by about '.max(1,(int)($conflict['overlap_minutes']??0)).' minutes.',
            'source'=>'calendar_awareness'
        ];
    }
    return array_slice($events,0,4);
}

/**
 * Canonical Agent Chat send pipeline shared by web Chat and Browser Companion.
 * Authentication/session concerns stay in the caller. This function assumes the
 * supplied user and active agent have already been authorized for Chat.
 */
function vp3_agent_chat_send_v2160(
    PDO $pdo,
    array $user,
    ?array $activeAgent,
    array $principal,
    array $input,
    bool $brainAllowed
): array {

$query=trim((string)($input['message']??''));$conversationId=(int)($input['conversation_id']??0);$inputMode=(string)($input['input_mode']??'text');$inputMode=$inputMode==='voice'?'voice':'text';if($query==='')throw new RuntimeException('Enter a message.');if(mb_strlen($query)>6000)throw new RuntimeException('That message is too long.');
$activeAgentId=vp3_agent_chat_agent_id_v380($activeAgent);
if($conversationId<1){$workspaceId=artist_workspace_v181_scope_id($user);$conversation=vp3_agent_chat_create_conversation_v380($pdo,$user,$activeAgent,$query,$workspaceId);$conversationId=(int)$conversation['id'];}elseif(!vp3_agent_chat_runtime_conversation_v2160($pdo,$conversationId,$userId,$activeAgent))throw new RuntimeException('Conversation not found for this agent.');
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
$rawAgentContext['conversation_id']=$conversationId;$rawAgentContext['user_agent_id']=$activeAgentId;$calendarAwareness=calendar_schedule_awareness_snapshot_v1330($pdo,$user);$calendarContextEvents=vp3_agent_chat_runtime_calendar_events_v2160($calendarAwareness);$clientEvents=is_array($rawAgentContext['events']??null)?$rawAgentContext['events']:[];$rawAgentContext['events']=array_merge($calendarContextEvents,$clientEvents);$meetingMemory=video_meeting_memory_agent_context_v18120($pdo,$userId,$query);$meetingMemory=video_meeting_commitment_command_enrich_v18230($pdo,$userId,$query,$meetingMemory);if(!empty($meetingMemory['relevant']))$rawAgentContext['meeting_memory']=$meetingMemory;$agentContext=agent_surface_v131_enrich($user,'chat',$rawAgentContext);if(!empty($meetingMemory['relevant']))$agentContext['meeting_memory']=$meetingMemory;$agentContext['calendar_awareness']=$calendarAwareness;
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
$mediaLimit=track_is_playlist_request($query)?7:(track_is_next_request($query)?1:4);$answerMedia=track_media_from_answer($answer,$user,$mediaLimit);$queryMedia=track_media_suggestions($query,$user,$mediaLimit);$media=vp3_agent_chat_runtime_authorized_media_v2160(array_slice(track_merge_media_suggestions($answerMedia,$queryMedia),0,$mediaLimit),$context);$playlistTitle=$media?track_playlist_title($query):'';$toolSources=!empty($toolResult['sources'])&&is_array($toolResult['sources'])?$toolResult['sources']:[];$meetingSources=array_merge(video_meeting_memory_chat_sources_v18120($meetingMemory),video_meeting_commitment_command_chat_sources_v18230($meetingMemory));$publicSources=array_merge($publicSources,$toolSources,$meetingSources);$publicSources[]=chat_execution_v019_source($execution);$cardNamespace=vp3_cognitive_agent_namespace_v500($pdo,$user,$activeAgentId);$cardRequests=function_exists('vp3_cognitive_cards_chat_requests_v520')?vp3_cognitive_cards_chat_requests_v520($pdo,$user,$cardNamespace,$query,8):[];$messageContext=['sources'=>$publicSources,'knowledge'=>$knowledgeContext,'media'=>$media,'stem_media'=>!empty($toolResult['stem_media'])&&is_array($toolResult['stem_media'])?$toolResult['stem_media']:[],'actions'=>!empty($toolResult['actions'])&&is_array($toolResult['actions'])?$toolResult['actions']:[],'cards'=>$cardRequests,'playlist_title'=>$playlistTitle,'agent_context'=>$persistedAgentContext,'agent'=>['id'=>(int)$principal['agent_id'],'name'=>(string)$principal['display_name'],'kind'=>(string)$principal['kind']],'execution'=>$execution];$contextJson=json_encode($messageContext,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$stmt=$pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,?,?,?)');$stmt->execute([$conversationId,'assistant',$answer,$contextJson]);$assistantMessageId=(int)$pdo->lastInsertId();if($brainAllowed)agent_brain_archive_and_parse($user,$conversationId,$assistantMessageId,'assistant',$answer,$inputMode);$pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=?')->execute([$conversationId]);return ['ok'=>true,'conversation_id'=>$conversationId,'user_message_id'=>$userMessageId,'assistant_message_id'=>$assistantMessageId,'answer'=>$answer,'sources'=>$publicSources,'knowledge'=>$knowledgeContext,'media'=>$media,'stem_media'=>$messageContext['stem_media'],'actions'=>$messageContext['actions'],'cards'=>$messageContext['cards'],'playlist_title'=>$playlistTitle,'input_mode'=>$inputMode,'agent_name'=>(string)$principal['display_name'],'agent_id'=>(int)$principal['agent_id'],'execution'=>$execution];
}
