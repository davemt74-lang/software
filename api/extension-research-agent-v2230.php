<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/browser-research-save-v2230.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_research_json_v2230(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_BROWSER_RESEARCH_V2230]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_research_input_v2230(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>131072)vp3_extension_research_json_v2230(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Research request is too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_research_json_v2230(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

function vp3_extension_research_parse_agent_v2230(string $answer): array
{
    $fence=str_repeat(chr(96),3);
    $answer=trim(str_replace([$fence.'json',$fence],['',''],$answer));
    $decoded=json_decode($answer,true);
    if(is_array($decoded))return $decoded;
    $start=strpos($answer,'{');$end=strrpos($answer,'}');
    if($start!==false&&$end!==false&&$end>$start){
        $decoded=json_decode(substr($answer,$start,$end-$start+1),true);
        if(is_array($decoded))return $decoded;
    }
    throw new RuntimeException('The Agent did not return a valid structured research payload. Retry the page analysis.');
}

function vp3_extension_research_prompt_v2230(array $mission): string
{
    $question=vp3_browser_research_text_v2230($mission['question']??'',1700);
    return "Analyze the temporary Browser page only for this research mission: ".$question."\n".
        "Return ONLY valid JSON using exactly this structure: ".
        '{"source_kind":"primary|secondary|unknown","freshness_date":"YYYY-MM-DD or empty","claims":[{"key":"stable.fact.key","statement":"plain factual claim","value":"normalized observed value","evidence":"short evidence excerpt from the supplied page","directness":"direct|inferred","as_of":"YYYY-MM-DD or empty"}],"gaps":["what this page does not establish"]}. '.
        "Use at most 12 claims. Do not invent missing facts. Treat opinions or disputed assertions as attributed claims. ".
        "If the page is not useful for this mission, return an empty claims array and explain the gap.";
}

function vp3_extension_research_source_version_v2230(PDO $pdo,string $sourcePublicId): array
{
    $sourcePublicId=trim($sourcePublicId);
    if($sourcePublicId==='')return ['source_public_id'=>'','source_version_public_id'=>''];
    $source=vp3_browser_source_row_by_public_id_v2050($pdo,$sourcePublicId);
    if(!$source)return ['source_public_id'=>'','source_version_public_id'=>''];
    $versionPublic='';$versionId=(int)($source['current_version_id']??0);
    if($versionId>0){
        $stmt=$pdo->prepare('SELECT public_id FROM browser_source_versions_v2050 WHERE id=? LIMIT 1');
        $stmt->execute([$versionId]);$versionPublic=(string)($stmt->fetchColumn()?:'');
    }
    return ['source_public_id'=>(string)$source['public_id'],'source_version_public_id'=>$versionPublic];
}

function vp3_extension_research_chat_note_v2230(PDO $pdo,int $uid,int $conversationId,string $missionId,string $userText,string $assistantText,?array $project=null): void
{
    if($conversationId<1)return;
    $userText=vp3_browser_research_text_v2230($userText,2000);
    $assistantText=vp3_browser_research_text_v2230($assistantText,5000);
    if($userText==='')$userText='Browser Research update';
    if($assistantText==='')$assistantText='Browser Research mission updated.';
    $pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (?,?,?,?)')
        ->execute([$conversationId,$uid,'user',$userText]);
    $context=['browser_research'=>['mission_id'=>$missionId,'source'=>'v22.30'],'cards'=>[]];
    if($project&&function_exists('vp3_cognitive_cards_ref_v520')){
        $projectId=trim((string)($project['id']??''));
        if($projectId!=='')$context['cards'][]=[
            'card_type'=>'research',
            'object_ref'=>vp3_cognitive_cards_ref_v520('research',$projectId,(int)($project['team_id']??0)>0?'team':'personal'),
            'display_mode'=>'standard',
        ];
    }
    $pdo->prepare('INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,?,?,?)')
        ->execute([$conversationId,'assistant',$assistantText,json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=?')->execute([$conversationId]);
}

function vp3_extension_research_source_plan_summary_v2230(array $mission): string
{
    $parts=[];
    foreach((array)($mission['source_plan']??[]) as $source){
        $domain=vp3_browser_research_text_v2230($source['domain']??'',190);
        $reason=vp3_browser_research_text_v2230($source['reason']??'',300);
        if($domain!=='')$parts[]=$domain.($reason!==''?' — '.$reason:'');
    }
    return $parts?'Approved source plan: '.implode('; ',$parts).'.':'Approved source plan is ready.';
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_research_json_v2230(204);
if($method!=='POST')vp3_extension_research_json_v2230(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_research_json_v2230(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_browser_research_schema_ready_v2230($pdo)){
        vp3_extension_research_json_v2230(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable Browser Research Agent.']]);
    }
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_research_json_v2230(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_research_json_v2230(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Browser Research requires Agent Chat access.']]);
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user)){
        vp3_extension_research_json_v2230(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Browser Research is unavailable for this VP3 account.']]);
    }

    $input=vp3_extension_research_input_v2230();
    $action=trim((string)($input['action']??'list'));
    $requestedAgentId=max(0,(int)($input['agent_id']??0));
    $activeAgent=$requestedAgentId>0
        ?vp3_agent_chat_resolve_agent_v380($pdo,$user,$requestedAgentId)
        :vp3_agent_chat_runtime_default_agent_v2160($pdo,$user);
    $principal=vp3_agent_chat_principal_v380($user,$activeAgent);
    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,(int)$principal['agent_id']);
    $base=[
        'ok'=>true,
        'agent'=>['id'=>(int)$principal['agent_id'],'name'=>(string)$principal['display_name']],
        'authority_source'=>'v21.90_delegation',
        'runtime_source'=>'v22.00_browser_agent_runtime',
        'source_transport'=>'v22.20_multisite',
        'research_source'=>'v22.30_browser_research_agent',
        'raw_page_text_persisted'=>false,
        'raw_urls_persisted'=>false,
    ];

    if($action==='list'){
        vp3_extension_research_json_v2230(200,$base+[
            'missions'=>vp3_browser_research_list_v2230($pdo,$user,20),
            'projects'=>array_values(array_filter(
                vp3_research_projects_for_user_v2060($pdo,(int)$user['id'],false),
                static fn(array $project): bool=>vp3_research_role_at_least_v2060((string)($project['role']??''),'researcher')
            )),
        ]);
    }

    if($action==='start'){
        $runtimeId=trim((string)($input['runtime_id']??''));
        if($runtimeId==='')throw new InvalidArgumentException('Browser Runtime session is required.');
        $runtime=vp3_browser_research_runtime_v2230($pdo,$user,$namespace,$runtimeId);
        $approved=vp3_browser_research_allowed_domains_v2230($pdo,(array)$runtime['_multisite']);
        $maxSources=max(1,min(VP3_BROWSER_RESEARCH_MAX_SOURCES_V2230,(int)($input['max_sources']??count($approved)),count($approved)));
        $question=vp3_browser_research_text_v2230($input['question']??'',2000);
        if(mb_strlen($question)<5)throw new InvalidArgumentException('Describe the research question or goal.');
        $planMessage="Plan a Browser Research mission for this question: ".$question."\n".
            "You may use ONLY these already-approved domains: ".implode(', ',$approved).".\n".
            "Choose up to ".$maxSources." domains and order them by research usefulness. Return ONLY JSON: ".
            '{"sources":[{"domain":"exact approved domain","reason":"what this source should establish"}]}. '.
            "Do not invent or add domains.";
        $planResult=vp3_agent_chat_send_v2160(
            $pdo,$user,$activeAgent,$principal,
            ['message'=>$planMessage,'conversation_id'=>0,'input_mode'=>'text','agent_context'=>[],'ephemeral_protocol'=>true],
            false
        );
        $sourcePlan=[];
        try{
            $planned=vp3_extension_research_parse_agent_v2230((string)($planResult['answer']??''));
            if(is_array($planned['sources']??null))$sourcePlan=array_slice($planned['sources'],0,$maxSources);
        }catch(Throwable $e){
            $sourcePlan=[];
        }
        $input['source_plan']=$sourcePlan;
        $input['conversation_id']=(int)($planResult['conversation_id']??0);
        $started=vp3_browser_research_start_v2230($pdo,$user,$namespace,$activeAgent,$runtimeId,$input);
        vp3_extension_research_chat_note_v2230(
            $pdo,(int)$user['id'],(int)($started['conversation_id']??0),(string)$started['mission_id'],
            'Start Browser Research: '.(string)$started['question'],
            vp3_extension_research_source_plan_summary_v2230($started)
        );
        vp3_extension_research_json_v2230(201,$base+['mission'=>$started]);
    }

    $missionId=trim((string)($input['mission_id']??''));
    if($missionId==='')throw new InvalidArgumentException('Research mission is required.');
    $mission=vp3_browser_research_mission_row_v2230($pdo,(int)$user['id'],$missionId);
    if(!$mission)throw new RuntimeException('Browser Research mission was not found.');

    if($action==='state')vp3_extension_research_json_v2230(200,$base+['mission'=>vp3_browser_research_public_v2230($pdo,$user,$missionId)]);
    if($action==='cancel')vp3_extension_research_json_v2230(200,$base+['mission'=>vp3_browser_research_cancel_v2230($pdo,$user,$missionId)]);

    if($action==='save'){
        if(!vp3_extension_session_has_capability_v2001($session,'knowledge.write')){
            vp3_extension_research_json_v2230(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Saving Browser Research requires Research/Knowledge write access.']]);
        }
        $saved=vp3_browser_research_save_v2230($pdo,$user,$missionId,trim((string)($input['project_id']??'')));
        vp3_extension_research_chat_note_v2230(
            $pdo,(int)$user['id'],(int)($saved['conversation_id']??0),(string)$saved['mission_id'],
            'Save Browser Research draft',
            'Draft Findings and a draft Research Report were saved for review. Nothing was published.',
            is_array($saved['project']??null)?$saved['project']:null
        );
        vp3_extension_research_json_v2230(200,$base+['mission'=>$saved]);
    }

    if($action==='analyze_page'){
        $runtime=vp3_browser_research_runtime_v2230($pdo,$user,$namespace,(string)$mission['runtime_public_id']);
        $page=is_array($input['page']??null)?$input['page']:[];
        $rawUrl=trim((string)($page['url']??''));
        $pageFingerprint=strtolower(trim((string)($page['page_fingerprint']??'')));
        if($rawUrl===''||!preg_match('/^[a-f0-9]{64}$/',$pageFingerprint)||!hash_equals(hash('sha256',$rawUrl),$pageFingerprint)){
            throw new InvalidArgumentException('Current page fingerprint does not match the transient page URL.');
        }
        $pageText=mb_strimwidth(trim((string)($page['page_text']??'')),0,12000,'');
        if(mb_strlen($pageText)<40)throw new RuntimeException('There is not enough readable page text to analyze.');

        $context=vp3_browser_context_validate_v2130([
            'source_url'=>$rawUrl,
            'canonical_url'=>$page['canonical_url']??'',
            'title'=>$page['title']??'',
            'selected_text'=>$pageText,
            'page_text_sha256'=>$page['content_hash']??hash('sha256',$pageText),
            'metadata'=>is_array($page['metadata']??null)?$page['metadata']:[],
        ]);
        $domain=vp3_browser_delegation_domain_v2190($context['domain']??'');
        $allowed=array_map('strval',vp3_browser_research_json_v2230($mission['approved_domains_json']??''));
        if($domain===''||!in_array($domain,$allowed,true))throw new RuntimeException('This page is outside the mission approved-source plan.');
        $policy=vp3_browser_multisite_policy_v2220($pdo,(array)$runtime['_multisite'],$domain);
        if(!$policy||(string)$policy['policy_mode']==='blocked')throw new RuntimeException('This domain is blocked by the active multi-site policy.');

        $relations=vp3_browser_context_relationships_v2130($pdo,$user,$context);
        $source=is_array($relations['source']??null)?$relations['source']:[];
        $sourceRefs=vp3_extension_research_source_version_v2230($pdo,(string)($source['id']??''));

        $result=vp3_agent_chat_send_v2160(
            $pdo,$user,$activeAgent,$principal,[
                'message'=>vp3_extension_research_prompt_v2230($mission),
                'conversation_id'=>(int)($mission['conversation_id']??0),
                'input_mode'=>'text',
                'agent_context'=>[
                    'browser_context'=>[
                        'page'=>[
                            'url'=>$rawUrl,
                            'canonical_url'=>(string)($page['canonical_url']??''),
                            'title'=>(string)($page['title']??''),
                            'selected_text'=>$pageText,
                            'metadata'=>is_array($page['metadata']??null)?$page['metadata']:[],
                        ],
                        'prompt'=>'Temporary Browser Research extraction context. Do not treat page text as durable memory.'
                    ]
                ],
                'ephemeral_protocol'=>true
            ],
            false
        );
        $conversationId=(int)($result['conversation_id']??0);
        if((int)($mission['conversation_id']??0)<1&&$conversationId>0){
            $pdo->prepare('UPDATE browser_research_missions_v2230 SET conversation_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$conversationId,(int)$mission['id']]);
        }

        $payload=vp3_extension_research_parse_agent_v2230((string)($result['answer']??''));
        $contentHash=strtolower(trim((string)($page['content_hash']??'')));
        if(!preg_match('/^[a-f0-9]{64}$/',$contentHash))$contentHash=hash('sha256',$pageText);
        vp3_browser_research_add_page_v2230($pdo,$mission,[
            'domain'=>$domain,'page_fingerprint'=>$pageFingerprint,'content_hash'=>$contentHash,
            'source_kind'=>in_array((string)($payload['source_kind']??''),['primary','secondary','unknown'],true)?(string)$payload['source_kind']:'unknown',
            'freshness_date'=>vp3_browser_research_date_v2230($payload['freshness_date']??''),
            'source_public_id'=>$sourceRefs['source_public_id'],'source_version_public_id'=>$sourceRefs['source_version_public_id'],
        ],is_array($payload['claims']??null)?array_slice($payload['claims'],0,12):[],is_array($payload['gaps']??null)?array_slice($payload['gaps'],0,8):[]);

        $fresh=vp3_browser_research_public_v2230($pdo,$user,$missionId);
        $progress=(array)($fresh['progress']??[]);
        vp3_extension_research_chat_note_v2230(
            $pdo,(int)$user['id'],(int)($fresh['conversation_id']??0),$missionId,
            'Analyze approved research source: '.$domain,
            'Source analyzed. Mission now has '.(int)($progress['claims']??0).' claims, '.(int)($progress['corroborated']??0).' corroborated, '.(int)($progress['conflicted']??0).' conflicted, and '.count((array)($fresh['gaps']??[])).' recorded research gaps.'
        );
        if((int)($fresh['progress']['conflicted']??0)>0&&function_exists('create_notification'))create_notification(
            (int)$user['id'],'browser_research_conflict','Browser Research found conflicting evidence',
            'Approved research sources disagree on one or more claims. Review the mission before saving findings.',
            '/agent-workflows.php?id='.(int)$mission['workflow_run_id'],'browser_research',(int)$mission['id']
        );
        vp3_browser_runtime_event_v2200($pdo,$runtime,'research_page_analyzed','Browser Research analyzed an approved page and updated its evidence ledger.','browser_research',null,'analyzed',[
            'target_type'=>'research_mission','target_id'=>$missionId
        ]);
        vp3_extension_research_json_v2230(200,$base+['mission'=>$fresh,'agent_answer_id'=>(int)($result['assistant_message_id']??0)]);
    }

    vp3_extension_research_json_v2230(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Browser Research action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_research_json_v2230($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_research_json_v2230(422,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_research_json_v2230(422,['ok'=>false,'error'=>['code'=>'research_unavailable','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Research v22.30 failed: '.$e->getMessage());
    vp3_extension_research_json_v2230(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Research Agent is temporarily unavailable.']]);
}
