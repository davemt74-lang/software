<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-v100.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-analytics-v130.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-external-v120.php';
require_once dirname(__DIR__).'/includes/profile-agent-public-service-v110.php';
require_once dirname(__DIR__).'/includes/profile-agent-transcription-context.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-chat-v140.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-scheduling-v150.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-commerce-v160.php';
require_once dirname(__DIR__).'/includes/profile-webmcp-campaigns-v170.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function vp3_profile_webmcp_external_json_v120(bool $ok,array $payload=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok'=>$ok],$payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_profile_webmcp_external_tool_json_v130(PDO $pdo,?array $telemetryContext,string $tool,float $startedAt,bool $ok,array $payload=[],int $status=200,string $resultCode=''): never
{
    $duration=(int)max(0,round((microtime(true)-$startedAt)*1000));
    vp3_profile_webmcp_record_v130(
        $pdo,$telemetryContext,$ok?'webmcp_tool_completed':'webmcp_tool_failed',
        $tool,$ok?'completed':'failed',$duration,['result_code'=>$resultCode]
    );
    vp3_profile_webmcp_external_json_v120($ok,$payload,$status);
}

$pdo=db();
if(!$pdo||!vp3_radar_schema_ready($pdo)){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'WEBMCP_UNAVAILABLE','message'=>'Connected-site WebMCP is unavailable.']],503);
}

$key=strtolower(trim((string)($_GET['key']??'')));
$property=vp3_radar_external_property_by_key($pdo,$key);
if(!$property){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROPERTY_NOT_FOUND','message'=>'Connected site is unavailable.']],404);
}

try{
    $origin=vp3_profile_webmcp_external_origin_v120($property,(string)($_SERVER['HTTP_ORIGIN']??''));
}catch(Throwable $e){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'ORIGIN_DENIED','message'=>'Connected-site Origin is not authorized.']],403);
}

header('Access-Control-Allow-Origin: '.$origin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 600');

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$manifestSession=vp3_profile_webmcp_transport_id_v130((string)($_GET['session']??''));
if($method==='OPTIONS'){
    http_response_code(204);
    exit;
}
if(!in_array($method,['GET','POST'],true)){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'GET or POST is required.']],405);
}

try{
    $profile=vp3_profile_webmcp_external_profile_v120($pdo,$property);
    $chatAvailable=$manifestSession!==''&&vp3_profile_webmcp_external_agent_v120($pdo,$profile)!==null;
    $manifest=vp3_profile_webmcp_external_manifest_v120($pdo,$property,$profile,$chatAvailable,true,true,true);
}catch(Throwable $e){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROFILE_UNAVAILABLE','message'=>'The connected VP3 profile is unavailable.']],404);
}

if($method==='GET'){
    $chatGrantData=null;
    if($chatAvailable){
        try{$chatGrantData=vp3_profile_webmcp_chat_grant_create_v140($property,$profile,$origin,$manifestSession);}
        catch(Throwable $e){$chatGrantData=null;}
    }
    $manifestTelemetryContext=vp3_profile_webmcp_context_v130(
        $pdo,$profile,'external_site',['webmcp_session_id'=>$manifestSession,'interaction_id'=>'','agent_referral'=>''],$property,null
    );
    vp3_profile_webmcp_record_v130($pdo,$manifestTelemetryContext,'webmcp_manifest_loaded','','loaded');
    vp3_profile_webmcp_external_json_v120(true,[
        'manifest'=>$manifest,
        'chat_grant'=>(string)($chatGrantData['grant']??''),
        'chat_grant_expires_at'=>(string)($chatGrantData['expires_at_utc']??''),
        'runtime'=>[
            'build'=>VP3_PROFILE_WEBMCP_EXTERNAL_V120,
            'read_only'=>!empty($manifest['external']['read_only']),
            'chat_enabled'=>!empty($manifest['external']['stateful_profile_agent']),
            'scheduling_enabled'=>!empty($manifest['external']['scheduling_enabled']),
            'commerce_enabled'=>!empty($manifest['external']['commerce_enabled']),
            'campaigns_enabled'=>!empty($manifest['external']['campaigns_enabled']),
        ],
    ]);
}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>VP3_PROFILE_WEBMCP_MAX_BODY_V100){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PAYLOAD_TOO_LARGE','message'=>'Request is too large.']],413);
}
$input=json_decode($raw,true);
if(!is_array($input)){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'INVALID_JSON','message'=>'Invalid JSON request.']],400);
}
$telemetry=vp3_profile_webmcp_telemetry_v130($input);
if((string)($input['manifest_version']??'')!==VP3_PROFILE_WEBMCP_MANIFEST_V100){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'MANIFEST_VERSION_UNSUPPORTED','message'=>'Unsupported profile WebMCP manifest.']],400);
}
if((string)($input['surface']??'')!=='external_site'){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'SURFACE_MISMATCH','message'=>'This endpoint accepts only connected-site WebMCP requests.']],403);
}
if((int)($input['property_id']??0)!==(int)$property['id']){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROPERTY_MISMATCH','message'=>'Connected-site property does not match.']],403);
}
if(!hash_equals((string)$profile['username'],(string)($input['profile_username']??''))){
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'PROFILE_MISMATCH','message'=>'Connected profile does not match.']],403);
}

$tool=trim((string)($input['tool']??''));
$chatTools=['vp3.agent.chat.start','vp3.agent.conversation.get','vp3.agent.message.send','vp3.agent.owner_handoff.request'];
$telemetryContext=vp3_profile_webmcp_context_v130($pdo,$profile,'external_site',$telemetry,$property,null);
if(!in_array($tool,$manifest['allowed_tools'],true)){
    vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_denied',$tool,'denied',0,['result_code'=>'CAPABILITY_UNAVAILABLE']);
    vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That connected-site capability is unavailable.']],404);
}
$args=is_array($input['input']??null)?$input['input']:[];
$agentCtx=null;
if(in_array($tool,$chatTools,true)){
    $grant=trim((string)($input['chat_grant']??''));
    try{
        vp3_profile_webmcp_chat_grant_verify_v140(
            $property,$profile,$origin,(string)($telemetry['webmcp_session_id']??''),$grant
        );
        $agentCtx=vp3_profile_webmcp_external_chat_context_v140(
            $pdo,$property,$profile,(string)($telemetry['webmcp_session_id']??'')
        );
    }catch(VP3ProfileAgentPublicException $e){
        vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_denied',$tool,'denied',0,['result_code'=>$e->publicCode]);
        vp3_profile_webmcp_external_json_v120(false,['error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->httpStatus);
    }
}
$startedAt=microtime(true);
vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_tool_called',$tool,'called');

try{
    if($tool==='vp3.profile.capabilities.get'){
        vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,[
            'manifest_version'=>VP3_PROFILE_WEBMCP_MANIFEST_V100,
            'capabilities'=>$manifest['capabilities'],
            'allowed_tools'=>$manifest['allowed_tools'],
        ]);
    }
    if($tool==='vp3.profile.get'){
        vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['profile'=>vp3_profile_webmcp_public_profile_v100($profile)]);
    }
    if($tool==='vp3.intent.resolve'){
        $goal=trim((string)($args['goal']??''));
        vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['resolution'=>vp3_profile_webmcp_resolve_intent_v100($goal,$manifest)]);
    }
    if($tool==='vp3.agent.get'){
        $agent=vp3_profile_webmcp_external_agent_v120($pdo,$profile);
        if(!$agent)vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'PROFILE_AGENT_UNAVAILABLE','message'=>'This Profile Agent is unavailable.']],404,'PROFILE_AGENT_UNAVAILABLE');
        vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['agent'=>$agent]);
    }
    if($tool==='vp3.agent.chat.start'){
        $result=vp3_profile_webmcp_chat_start_v140($pdo,$agentCtx);
        vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
    }
    if($tool==='vp3.agent.conversation.get'){
        $cid=max(0,(int)($args['conversation_id']??0));
        vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_agent_public_state_service_v110($pdo,$agentCtx,$cid));
    }
    if($tool==='vp3.agent.message.send'){
        $cid=max(0,(int)($args['conversation_id']??0));
        $message=trim((string)($args['message']??''));
        $result=vp3_profile_agent_public_message_service_v110($pdo,$agentCtx,$message,$cid);
        vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_message_sent',$tool,'completed',(int)max(0,round((microtime(true)-$startedAt)*1000)),['conversation_id'=>(int)($result['conversation_id']??0)]);
        vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
    }
    if($tool==='vp3.agent.owner_handoff.request'){
        $cid=max(0,(int)($args['conversation_id']??0));
        $reason=trim((string)($args['reason']??''));
        $result=vp3_profile_agent_public_request_owner_v110($pdo,$agentCtx,$cid,$reason);
        vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_handoff_requested',$tool,'completed',(int)max(0,round((microtime(true)-$startedAt)*1000)),['conversation_id'=>$cid]);
        vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
    }
    if(str_starts_with($tool,'vp3.booking.')){
        $schedulingContext=vp3_profile_webmcp_scheduling_context_v150($profile,'external_site',$telemetry,'',$property,$origin);
        if($tool==='vp3.booking.options.list'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['appointment_types'=>vp3_profile_webmcp_scheduling_options_v150($pdo,$profile)]);
        }
        if($tool==='vp3.booking.availability.list'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_scheduling_availability_v150($pdo,$profile,$args));
        }
        if($tool==='vp3.booking.get'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_scheduling_get_v150($pdo,$profile,$args));
        }
        if(in_array($tool,['vp3.booking.prepare','vp3.booking.reschedule.prepare','vp3.booking.cancel.prepare'],true)){
            if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo)){
                vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'SCHEDULING_UNAVAILABLE','message'=>'Booking confirmation ledger is unavailable.']],503,'SCHEDULING_UNAVAILABLE');
            }
            $operation=match($tool){
                'vp3.booking.prepare'=>'booking.create',
                'vp3.booking.reschedule.prepare'=>'booking.reschedule',
                default=>'booking.cancel',
            };
            $result=vp3_profile_webmcp_scheduling_prepare_v150($pdo,$profile,$schedulingContext,$operation,$args);
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_booking_prepared',$tool,'prepared',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
        if(in_array($tool,['vp3.booking.confirm','vp3.booking.reschedule.confirm','vp3.booking.cancel.confirm'],true)){
            if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo)){
                vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'SCHEDULING_UNAVAILABLE','message'=>'Booking confirmation ledger is unavailable.']],503,'SCHEDULING_UNAVAILABLE');
            }
            $operation=match($tool){
                'vp3.booking.confirm'=>'booking.create',
                'vp3.booking.reschedule.confirm'=>'booking.reschedule',
                default=>'booking.cancel',
            };
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_scheduling_confirm_v150(
                $pdo,$profile,$schedulingContext,$operation,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??''))
            );
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_booking_completed',$tool,'completed',(int)max(0,round((microtime(true)-$startedAt)*1000)),['booking_id'=>(int)($result['booking']['booking_id']??0)]);
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
    }
    if(str_starts_with($tool,'vp3.campaign')){
        if($tool==='vp3.campaigns.list'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['campaigns'=>vp3_profile_webmcp_campaigns_list_v170($pdo,$profile)]);
        }
        if($tool==='vp3.campaign.get'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_campaign_get_v170($pdo,$profile,(string)($args['campaign_slug']??'')));
        }
        if($tool==='vp3.campaign.eligibility.get'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_campaign_eligibility_v170($pdo,$profile,(string)($args['campaign_slug']??'')));
        }
        if($tool==='vp3.campaign.participation.prepare'){
            $campaignContext=vp3_profile_webmcp_campaign_context_v170($profile,'external_site',$telemetry,'',$property,$origin);
            $result=vp3_profile_webmcp_campaign_prepare_v170($pdo,$profile,$campaignContext,$args);
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_campaign_prepared',$tool,'prepared',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
        if($tool==='vp3.campaign.participation.confirm'){
            $campaignContext=vp3_profile_webmcp_campaign_context_v170($profile,'external_site',$telemetry,'',$property,$origin);
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_campaign_confirm_v170($pdo,$profile,$campaignContext,$intent,trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??'')));
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_campaign_participation_completed',$tool,'completed',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
    }
    if(str_starts_with($tool,'vp3.commerce.')){
        $commerceContext=vp3_profile_webmcp_commerce_context_v160($profile,'external_site',$telemetry,'',$property,$origin);
        if($tool==='vp3.commerce.products.list'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,['products'=>vp3_profile_webmcp_commerce_products_v160($pdo,$profile)]);
        }
        if($tool==='vp3.commerce.product.get'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_commerce_product_detail_v160($pdo,$profile,(string)($args['product_slug']??'')));
        }
        if($tool==='vp3.commerce.checkout.prepare'){
            $result=vp3_profile_webmcp_commerce_checkout_prepare_v160($pdo,$profile,$commerceContext,$args);
            if(!empty($result['confirmation_required'])){
                vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_checkout_prepared',$tool,'prepared',(int)max(0,round((microtime(true)-$startedAt)*1000)));
                vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            }
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
        if($tool==='vp3.commerce.checkout.confirm'){
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_commerce_checkout_confirm_v160(
                $pdo,$profile,$commerceContext,$telemetryContext,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??'')),!empty($args['terms_accepted'])
            );
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_checkout_started',$tool,'checkout_started',(int)max(0,round((microtime(true)-$startedAt)*1000)),['order_id'=>(int)($result['order']['order_id']??0)]);
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
        if($tool==='vp3.commerce.order.get'||$tool==='vp3.commerce.receipt.get'){
            $result=vp3_profile_webmcp_commerce_order_get_v160($pdo,$profile,$args);
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$tool==='vp3.commerce.receipt.get'?['receipt'=>$result]:$result);
        }
        if($tool==='vp3.commerce.delivery.get'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_commerce_delivery_get_v160($pdo,$profile,$args));
        }
        if($tool==='vp3.commerce.refund.status'){
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,vp3_profile_webmcp_commerce_refund_status_v160($pdo,$profile,$args));
        }
        if($tool==='vp3.commerce.refund.prepare'){
            $result=vp3_profile_webmcp_commerce_refund_prepare_v160($pdo,$profile,$commerceContext,$args);
            vp3_profile_webmcp_record_v130($pdo,$telemetryContext,'webmcp_confirmation_required',$tool,'confirmation_required',(int)max(0,round((microtime(true)-$startedAt)*1000)));
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
        if($tool==='vp3.commerce.refund.confirm'){
            $intent=is_array($args['intent']??null)?$args['intent']:[];
            $result=vp3_profile_webmcp_commerce_refund_confirm_v160(
                $pdo,$profile,$commerceContext,$intent,
                trim((string)($args['confirmation_token']??'')),trim((string)($args['idempotency_key']??''))
            );
            vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,true,$result);
        }
    }
    vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'CAPABILITY_UNAVAILABLE','message'=>'That connected-site capability is unavailable.']],404,'CAPABILITY_UNAVAILABLE');
}catch(Throwable $e){
    $message=$e instanceof RuntimeException?$e->getMessage():'The connected-site capability could not be completed.';
    vp3_profile_webmcp_external_tool_json_v130($pdo,$telemetryContext,$tool,$startedAt,false,['error'=>['code'=>'VALIDATION_FAILED','message'=>$message]],422,'VALIDATION_FAILED');
}
