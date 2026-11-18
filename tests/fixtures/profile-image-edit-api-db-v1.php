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
if($action==='rename_credit_sources'){
    $pdo->exec("UPDATE users SET display_name='Renamed owner' WHERE id=1");
    $pdo->exec("UPDATE vp3_media_collections_v1 SET name='Renamed collection'");
    $pdo->exec("UPDATE user_media_assets SET title='Renamed image'");exit;
}
if($action==='gallery_change'){
    switch($argv[3]??''){
        case 'priced_default':$pdo->exec('UPDATE profile_image_catalogs_v1 SET default_price_cents=100,revision=revision+1');break;
        case 'priced_image':$pdo->exec('UPDATE profile_image_catalog_refs_v1 SET price_override_cents=100 WHERE asset_id=11');break;
        case 'unapproved':$pdo->exec('UPDATE profile_image_catalogs_v1 SET approved=0');break;
        case 'disabled':$pdo->exec('UPDATE profile_image_settings_v1 SET enabled=0');break;
        case 'member_removed':$pdo->exec('DELETE FROM vp3_media_collection_assets_v1 WHERE asset_id=11');break;
        case 'reference_removed':$pdo->exec('DELETE FROM profile_image_catalog_refs_v1 WHERE asset_id=11');break;
        case 'revision':$pdo->exec('UPDATE profile_image_catalogs_v1 SET revision=revision+1');break;
        case 'metadata':$pdo->exec("UPDATE user_media_assets SET updated_at='2026-11-19 00:00:00' WHERE id=11");break;
        case 'bytes':file_put_contents(media_studio_user_root(1).'/style-11.png',edit_png(2,97));break;
        case 'private':$pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');break;
        case 'inactive_owner':$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');break;
        case 'agent_disabled':$pdo->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1');break;
        case 'inactive_agent':$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');break;
        case 'foreign_agent':$pdo->exec('UPDATE user_profiles SET profile_agent_id=9 WHERE user_id=1');break;
        case 'profile_permission':$pdo->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='profile_agent.access'");break;
        case 'chat_permission':$pdo->exec("DELETE FROM role_permissions WHERE role='member' AND permission_key='profile_chat.access'");break;
        case 'private_path':$pdo->exec("UPDATE user_media_assets SET file_path='private/user-media/4/foreign.png' WHERE id=11");break;
        case 'unsupported':$pdo->exec("UPDATE user_media_assets SET media_type='video',mime_type='video/mp4' WHERE id=11");break;
        case 'schema_missing':$pdo->exec('DROP TABLE profile_image_edits_v1');break;
        default:throw new RuntimeException('Unknown gallery mutation.');
    }
    exit;
}
if($action==='gallery_extra'){
    foreach([[13,1],[21,4]] as [$id,$owner]){
        $root=media_studio_ensure_user_root($owner);$bytes=edit_png(2,$id);file_put_contents($root.'/style-'.$id.'.png',$bytes);
        $pdo->prepare('INSERT INTO user_media_assets VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$owner,'photo','Private '.$id,'image/png','private/user-media/'.$owner.'/style-'.$id.'.png',strlen($bytes),'upload','2026-11-18 00:00:00']);
    }
    $collection=(int)$pdo->query('SELECT collection_id FROM profile_image_catalogs_v1 WHERE owner_user_id=1')->fetchColumn();
    vp3_media_collection_set_asset_v1($pdo,1,$collection,13,true);
    $foreign=vp3_media_collection_create_v1($pdo,4,'Foreign style')['id'];vp3_media_collection_set_asset_v1($pdo,4,$foreign,21,true);
    vp3_profile_image_catalog_save_v1($pdo,pa3_owner(4),['action'=>'save_catalog','collection_id'=>$foreign,'expected_revision'=>0,'approved'=>true,'style_instructions'=>'','default_price_cents'=>0,'references'=>[['asset_id'=>21,'price_override_cents'=>0]]]);
    echo json_encode(['foreign_collection_id'=>$foreign],JSON_THROW_ON_ERROR);exit;
}
if($action==='gallery_state'){
    $out=[];
    foreach(['profile_visit_sessions','profile_agent_conversations','profile_agent_messages','profile_image_edits_v1','profile_chat_attachments_v1','profile_agent_handoff_turns_pa3','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts','agent_workflow_events','ai_execution_ledger','ai_token_reservations','profile_image_settings_v1','profile_image_catalogs_v1','profile_image_catalog_refs_v1','user_media_assets'] as $table){
        $rows=table_exists($table)?$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC):null;
        if(is_array($rows))usort($rows,static fn(array $a,array $b):int=>strcmp(json_encode($a),json_encode($b)));
        $out[$table]=$rows;
    }
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
if($action==='state'){
    echo json_encode(['images'=>$pdo->query('SELECT request_id,visitor_message_id,result_message_id,state FROM profile_image_edits_v1 ORDER BY visitor_message_id')->fetchAll(),'messages'=>(int)$pdo->query('SELECT COUNT(*) FROM profile_agent_messages')->fetchColumn(),'files'=>table_exists('profile_chat_attachments_v1')?(int)$pdo->query('SELECT COUNT(*) FROM profile_chat_attachments_v1')->fetchColumn():0],JSON_THROW_ON_ERROR);exit;
}
throw new RuntimeException('Unsupported fixture action.');
