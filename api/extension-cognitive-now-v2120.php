<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/browser-context-v2130.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

const VP3_EXTENSION_COGNITIVE_NOW_V2120='vp3-extension-cognitive-now-v2120-20260919';

function vp3_extension_cognitive_json_v2120(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_EXTENSION_COGNITIVE_NOW_V2120]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_cognitive_input_v2120(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>16384)vp3_extension_cognitive_json_v2120(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_cognitive_json_v2120(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

function vp3_extension_cognitive_internal_url_v2120(mixed $value): string
{
    $url=trim((string)$value);
    if($url===''||!str_starts_with($url,'/')||str_starts_with($url,'//'))return '';
    return mb_strimwidth($url,0,1500,'');
}

function vp3_extension_cognitive_card_v2120(array $card): array
{
    $out=[
        'contract'=>mb_strimwidth(trim((string)($card['contract']??'')),0,120,''),
        'card_type'=>mb_strimwidth(trim((string)($card['card_type']??'')),0,80,''),
        'display_mode'=>in_array((string)($card['display_mode']??''),['compact','standard','expanded'],true)?(string)$card['display_mode']:'standard',
        'title'=>mb_strimwidth(trim((string)($card['title']??'')),0,300,''),
        'subtitle'=>mb_strimwidth(trim((string)($card['subtitle']??'')),0,500,''),
        'status'=>mb_strimwidth(trim((string)($card['status']??'')),0,80,''),
        'summary'=>mb_strimwidth(trim((string)($card['summary']??'')),0,1800,''),
        'timestamp'=>mb_strimwidth(trim((string)($card['timestamp']??'')),0,80,''),
        'badges'=>[],
        'facts'=>[],
        'sections'=>[],
        'actions'=>[],
    ];
    foreach(array_slice((array)($card['badges']??[]),0,8) as $badge){
        $value=mb_strimwidth(trim((string)$badge),0,120,'');
        if($value!=='')$out['badges'][]=$value;
    }
    foreach(array_slice((array)($card['facts']??[]),0,10) as $fact){
        if(!is_array($fact))continue;
        $label=mb_strimwidth(trim((string)($fact['label']??'')),0,120,'');
        $value=mb_strimwidth(trim((string)($fact['value']??'')),0,500,'');
        if($label!==''||$value!=='')$out['facts'][]=['label'=>$label,'value'=>$value];
    }
    foreach(array_slice((array)($card['sections']??[]),0,8) as $section){
        if(!is_array($section))continue;
        $items=[];
        foreach(array_slice((array)($section['items']??[]),0,12) as $item){
            $value=mb_strimwidth(trim((string)$item),0,800,'');
            if($value!=='')$items[]=$value;
        }
        $out['sections'][]=[
            'label'=>mb_strimwidth(trim((string)($section['label']??'')),0,120,''),
            'text'=>mb_strimwidth(trim((string)($section['text']??'')),0,1800,''),
            'items'=>$items,
        ];
    }
    foreach(array_slice((array)($card['actions']??[]),0,6) as $action){
        if(!is_array($action))continue;
        $type=trim((string)($action['type']??''));
        $label=mb_strimwidth(trim((string)($action['label']??'Open')),0,120,'');
        if($type==='open_url'){
            $url=vp3_extension_cognitive_internal_url_v2120($action['url']??'');
            if($url!=='')$out['actions'][]=['type'=>'open_url','label'=>$label,'url'=>$url];
            continue;
        }
        if(in_array($type,['prompt','tool'],true)){
            $out['actions'][]=[
                'type'=>'agent_review',
                'label'=>$label,
                'requires_approval'=>$type==='tool'&&!empty($action['requires_approval']),
            ];
        }
    }
    return $out;
}

function vp3_extension_cognitive_candidate_v2120(PDO $pdo,array $user,string $namespace,array $item): array
{
    $request=is_array($item['card_request']??null)?$item['card_request']:[];
    $card=null;
    try{
        $card=vp3_cognitive_render_card_v500($pdo,$user,$namespace,$request);
    }catch(Throwable $e){
        error_log(
            'VP3 Browser Companion Cognitive card unavailable ['.
            mb_strimwidth((string)($item['key']??''),0,190,'').']: '.$e->getMessage()
        );
    }
    if(!is_array($card))return [];

    if(function_exists('vp3_cognitive_learning_schema_ready_v540')&&vp3_cognitive_learning_schema_ready_v540($pdo)){
        try{
            vp3_cognitive_learning_feedback_v540(
                $pdo,$user,$namespace,$item,'shown',
                ['section'=>$item['section']??'','surface'=>'browser_companion_now'],
                'browser_companion_surface:'.(string)floor(time()/VP3_COGNITIVE_LEARNING_SURFACE_COOLDOWN_V540)
            );
        }catch(Throwable $e){}
    }

    return [
        'key'=>mb_strimwidth(trim((string)($item['key']??'')),0,190,''),
        'fingerprint'=>strtolower(trim((string)($item['fingerprint']??''))),
        'reason'=>mb_strimwidth(trim((string)($item['reason']??'')),0,700,''),
        'attention'=>!empty($item['attention']),
        'source'=>mb_strimwidth(trim((string)($item['source']??'')),0,80,''),
        'updated_at'=>mb_strimwidth(trim((string)($item['updated_at']??'')),0,80,''),
        'signals'=>array_values(array_slice(array_filter(array_map(static fn($v)=>mb_strimwidth(trim((string)$v),0,80,''),(array)($item['signals']??[]))),0,8)),
        'plan_status'=>mb_strimwidth(trim((string)($item['plan_status']??'')),0,24,''),
        'card'=>vp3_extension_cognitive_card_v2120($card),
    ];
}

function vp3_extension_cognitive_feed_v2120(PDO $pdo,array $user,string $namespace): array
{
    $feed=vp3_cognitive_feed_compose_v530($pdo,$user,$namespace,false);
    $sections=[];
    foreach((array)($feed['sections']??[]) as $section){
        if(!is_array($section))continue;
        $items=[];
        foreach((array)($section['items']??[]) as $item){
            if(!is_array($item))continue;
            $resolved=vp3_extension_cognitive_candidate_v2120($pdo,$user,$namespace,$item);
            if($resolved)$items[]=$resolved;
        }
        if(!$items)continue;
        $sections[]=[
            'id'=>mb_strimwidth(trim((string)($section['id']??'')),0,32,''),
            'label'=>mb_strimwidth(trim((string)($section['label']??'')),0,120,''),
            'description'=>mb_strimwidth(trim((string)($section['description']??'')),0,500,''),
            'items'=>$items,
        ];
    }
    $count=0;foreach($sections as $section)$count+=count($section['items']);
    return [
        'agent_namespace'=>(string)($feed['agent_namespace']??$namespace),
        'generated_at'=>(string)($feed['generated_at']??gmdate(DATE_ATOM)),
        'operations'=>is_array($feed['operations']??null)?$feed['operations']:null,
        'priority_queue'=>is_array($feed['priority_queue']??null)?$feed['priority_queue']:null,
        'proactive_brief'=>is_array($feed['proactive_brief']??null)?$feed['proactive_brief']:null,
        'sections'=>$sections,
        'item_count'=>$count,
        'hidden_count'=>max(0,(int)($feed['hidden_count']??0)),
        'has_attention'=>(bool)array_filter($sections,static fn(array $s): bool=>(string)($s['id']??'')==='attention'),
        'refresh_seconds'=>max(60,(int)($feed['refresh_seconds']??60)),
        'agent_url'=>'/chat.php',
    ];
}

function vp3_extension_cognitive_current_candidate_v2120(PDO $pdo,array $user,string $namespace,array $input): array
{
    $key=mb_strimwidth(trim((string)($input['item_key']??'')),0,190,'');
    $fingerprint=strtolower(trim((string)($input['fingerprint']??'')));
    if($key===''||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))throw new InvalidArgumentException('Cognitive item identity is invalid.');
    $candidate=vp3_cognitive_feed_find_candidate_v530($pdo,$user,$namespace,$key);
    if(!is_array($candidate)||!hash_equals((string)($candidate['fingerprint']??''),$fingerprint))throw new RuntimeException('This Agent item changed. Refresh to review the current state.');
    return $candidate;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_extension_cognitive_json_v2120(204);
if(!in_array($method,['GET','POST'],true))vp3_extension_cognitive_json_v2120(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_cognitive_json_v2120(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

$pdo=db();
if(!$pdo
    ||!function_exists('vp3_cognitive_feed_schema_ready_v530')
    ||!vp3_cognitive_feed_schema_ready_v530($pdo)
    ||!function_exists('vp3_cognitive_render_card_v500')){
    vp3_extension_cognitive_json_v2120(503,['ok'=>false,'error'=>['code'=>'cognitive_runtime_unavailable','message'=>'Agent Now is unavailable. Run the VP3 database upgrade.']]);
}

try{
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_cognitive_json_v2120(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
        vp3_extension_cognitive_json_v2120(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection cannot access Agent Now.']]);
    }
    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user||!has_permission('chat.access',$user))vp3_extension_cognitive_json_v2120(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'Agent Chat access is unavailable for this VP3 account.']]);

    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,0);
    if($method==='GET'){
        vp3_extension_cognitive_json_v2120(200,['ok'=>true,'feed'=>vp3_extension_cognitive_feed_v2120($pdo,$user,$namespace)]);
    }

    $input=vp3_extension_cognitive_input_v2120();
    $action=trim((string)($input['action']??''));

    if($action==='context_feed'){
        $rawContext=is_array($input['context']??null)?$input['context']:[];
        $context=vp3_browser_context_validate_v2130($rawContext);
        $relations=vp3_browser_context_relationships_v2130($pdo,$user,$context,(array)($session['capabilities']??[]));
        $feed=vp3_extension_cognitive_feed_v2120($pdo,$user,$namespace);
        $feed=vp3_browser_contextualize_feed_v2130($feed,$context,$relations);
        $suggestions=vp3_browser_context_suggestions_v2130($session,$context,$relations);
        $prompt=trim((string)($input['prompt']??''));
        vp3_extension_cognitive_json_v2120(200,[
            'ok'=>true,
            'context_build'=>VP3_BROWSER_CONTEXT_V2130,
            'context'=>$context,
            'feed'=>$feed,
            'relationships'=>$feed['relationships']??[],
            'suggestions'=>$suggestions,
            'agent_payload'=>vp3_browser_context_agent_payload_v2130($context,$relations,$prompt),
            'persistence'=>'none_until_explicit_action',
        ]);
    }

    if($action==='hide'){
        $candidate=vp3_extension_cognitive_current_candidate_v2120($pdo,$user,$namespace,$input);
        vp3_cognitive_feed_hide_v530($pdo,$user,$namespace,(string)$candidate['key'],(string)$candidate['fingerprint']);
        if(function_exists('vp3_cognitive_learning_feedback_v540')){
            vp3_cognitive_learning_feedback_v540($pdo,$user,$namespace,$candidate,'hidden',['surface'=>'browser_companion_now'],'browser_companion_hide:'.(string)$candidate['fingerprint']);
        }
        vp3_extension_cognitive_json_v2120(200,['ok'=>true,'feed'=>vp3_extension_cognitive_feed_v2120($pdo,$user,$namespace)]);
    }

    if($action==='restore_all'){
        vp3_cognitive_feed_restore_v530($pdo,$user,$namespace,'');
        vp3_extension_cognitive_json_v2120(200,['ok'=>true,'feed'=>vp3_extension_cognitive_feed_v2120($pdo,$user,$namespace)]);
    }

    if($action==='feedback'){
        $candidate=vp3_extension_cognitive_current_candidate_v2120($pdo,$user,$namespace,$input);
        $event=trim((string)($input['event']??'engaged'));
        if(!in_array($event,['engaged','acted'],true))throw new InvalidArgumentException('Unsupported cognitive feedback event.');
        if(function_exists('vp3_cognitive_learning_feedback_v540')){
            vp3_cognitive_learning_feedback_v540($pdo,$user,$namespace,$candidate,$event,[
                'action_type'=>mb_strimwidth(trim((string)($input['action_type']??'')),0,120,''),
                'surface'=>'browser_companion_now',
            ],'browser_companion:'.$event.':'.bin2hex(random_bytes(8)));
        }
        vp3_extension_cognitive_json_v2120(200,['ok'=>true]);
    }

    if($action==='explain'){
        $candidate=vp3_extension_cognitive_current_candidate_v2120($pdo,$user,$namespace,$input);
        if(!function_exists('vp3_cognitive_learning_explain_v540'))throw new RuntimeException('Cognitive explanation is unavailable.');
        vp3_extension_cognitive_json_v2120(200,['ok'=>true,'explanation'=>vp3_cognitive_learning_explain_v540($pdo,$user,$namespace,$candidate)]);
    }

    if($action==='plan_decide'){
        $decision=trim((string)($input['decision']??''));
        if(!in_array($decision,['accept','dismiss'],true))throw new InvalidArgumentException('Unknown proactive plan decision.');
        $planId=mb_strimwidth(trim((string)($input['plan_id']??'')),0,190,'');
        if($planId==='')throw new InvalidArgumentException('Plan identity is required.');
        $plan=vp3_cognitive_planning_decide_v550($pdo,$user,$namespace,$planId,$decision);
        $source=vp3_cognitive_feed_find_candidate_v530($pdo,$user,$namespace,(string)($plan['source_item_key']??''));
        if(is_array($source)
            &&hash_equals((string)($source['fingerprint']??''),(string)($plan['source_fingerprint']??''))
            &&function_exists('vp3_cognitive_learning_feedback_v540')){
            vp3_cognitive_learning_feedback_v540(
                $pdo,$user,$namespace,$source,$decision==='accept'?'acted':'engaged',
                ['action_type'=>'plan_'.$decision,'surface'=>'browser_companion_now'],
                'browser_companion_plan:'.(string)$plan['public_id'].':'.$decision
            );
        }
        vp3_extension_cognitive_json_v2120(200,[
            'ok'=>true,
            'plan'=>['id'=>(string)$plan['public_id'],'status'=>(string)$plan['status'],'authority'=>'proposal_only'],
            'feed'=>vp3_extension_cognitive_feed_v2120($pdo,$user,$namespace),
        ]);
    }

    vp3_extension_cognitive_json_v2120(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Agent Now action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_cognitive_json_v2120($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_cognitive_json_v2120(422,['ok'=>false,'error'=>['code'=>'invalid_action','message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    vp3_extension_cognitive_json_v2120(409,['ok'=>false,'error'=>['code'=>'state_changed','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Companion Cognitive Now v21.20 failed: '.$e->getMessage());
    vp3_extension_cognitive_json_v2120(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Agent Now could not be loaded.']]);
}
