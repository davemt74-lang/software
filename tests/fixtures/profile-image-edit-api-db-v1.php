<?php
declare(strict_types=1);
$config=$argv[2]??throw new RuntimeException('Fixture configuration is required.');
putenv('VP3_PA3_CONFIG='.$config);
define('STONEFELLOW_ROOT',dirname($config).'/private-site');
define('VP3_PROFILE_IMAGE_TEST_HELPERS_ONLY',true);
require dirname(__DIR__).'/profile-image-edit-v1.php';
$action=$argv[1]??'';
if($action==='init'){edit_setup();echo json_encode(['catalog_id'=>$GLOBALS['editCollection'],'revision'=>vp3_profile_image_catalog_row_v1($pdo,1,$GLOBALS['editCollection'])['revision']],JSON_THROW_ON_ERROR);exit;}
if($action==='dispatch'){
    $GLOBALS['imageReservations']=[];$GLOBALS['imageSettlements']=[];$GLOBALS['imageReleases']=[];$GLOBALS['editProviderCalls']=[];$GLOBALS['editHook']=null;
    $result=vp3_profile_handoff_dispatch_pa3($pdo,(int)($argv[3]??0),'edit_transport');echo json_encode(['result'=>$result,'calls'=>count($GLOBALS['editProviderCalls'])],JSON_THROW_ON_ERROR);exit;
}
if($action==='age'){pa3_age();exit;}
if($action==='png'){echo base64_encode(edit_png(2,(int)($argv[3]??64)));exit;}
if($action==='limit_one'){edit_settings(['daily_limit'=>1]);exit;}
if($action==='parallel'){
    $visitor=(int)($argv[3]??0);$browser=(string)($argv[4]??'b');$barrier=(string)($argv[5]??'');
    if(!in_array($visitor,[2,3],true)||!preg_match('/^[bc]$/D',$browser)||dirname($barrier)!==dirname($config))throw new RuntimeException('Invalid parallel fixture.');
    pa3_login($visitor);$_COOKIE=['sf_profile_visitor'=>str_repeat($browser,64),'sf_profile_conversation'=>str_repeat($browser,64)];
    $ctx=vp3_profile_agent_public_context_v110($pdo,profile_by_username($pdo,'fixture-owner'),current_user());$GLOBALS['editCollection']=(int)$pdo->query('SELECT collection_id FROM profile_image_catalogs_v1 WHERE owner_user_id=1')->fetchColumn();
    $intent=edit_intent();file_put_contents($barrier.'.'.$visitor.'.ready','ready');$end=microtime(true)+8;
    while(!is_file($barrier.'.go')){if(microtime(true)>$end)throw new RuntimeException('Parallel fixture timed out.');usleep(10000);clearstatcache();}
    try{$result=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Match this free style.',0,['async'=>true,'chat_mode'=>'agent','client_request_id'=>bin2hex(random_bytes(16)),'image_edit'=>$intent]);echo json_encode(['accepted'=>true,'turn'=>$result['handoff']['turn_id']],JSON_THROW_ON_ERROR);}
    catch(VP3ProfileAgentPublicException $e){echo json_encode(['accepted'=>false,'code'=>$e->publicCode],JSON_THROW_ON_ERROR);}
    exit;
}
if($action==='paid'){$pdo->exec('UPDATE profile_image_catalogs_v1 SET default_price_cents=100,revision=revision+1');$pdo->exec('UPDATE profile_image_catalog_refs_v1 SET price_override_cents=NULL');exit;}
if($action==='state'){
    echo json_encode(['images'=>$pdo->query('SELECT request_id,visitor_message_id,result_message_id,state FROM profile_image_edits_v1 ORDER BY visitor_message_id')->fetchAll(),'messages'=>(int)$pdo->query('SELECT COUNT(*) FROM profile_agent_messages')->fetchColumn(),'files'=>table_exists('profile_chat_attachments_v1')?(int)$pdo->query('SELECT COUNT(*) FROM profile_chat_attachments_v1')->fetchColumn():0],JSON_THROW_ON_ERROR);exit;
}
throw new RuntimeException('Unsupported fixture action.');
