<?php
declare(strict_types=1);

session_start();
define('STONEFELLOW_ROOT',(string)getenv('VP3_NATIVE_CLICK_ROOT'));
require __DIR__.'/profile-promotion-native-click-v1.php';
$action=$argv[1]??'';
if($action==='init'){
    echo json_encode(native_click_fixture_v1()+['driver'=>db()->getAttribute(PDO::ATTR_DRIVER_NAME)],JSON_THROW_ON_ERROR);
}elseif($action==='state'){
    echo json_encode(db()->query('SELECT * FROM agent_promotion_clicks_v1 ORDER BY click_id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
}elseif($action==='revoke'){
    db()->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'");
}elseif($action==='pause'){
    db()->exec("UPDATE agent_commerce_promotions_v1 SET status='paused'");
}else throw new RuntimeException('Unsupported native click fixture command.');
