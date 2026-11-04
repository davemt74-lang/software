<?php
declare(strict_types=1);

$action=$argv[1]??'';
$fixturePath=$argv[2]??throw new RuntimeException('Fixture configuration is required.');
if($action==='cleanup'){
    if(is_file($fixturePath)){
        $cfg=json_decode((string)file_get_contents($fixturePath),true,512,JSON_THROW_ON_ERROR);
        if(isset($cfg['database'])){
            if(!preg_match('/^vp3_profile_handoff_pa3_[a-f0-9]{12}$/D',$cfg['database']))throw new RuntimeException('Invalid isolated database.');
            (new PDO($cfg['admin_dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]))->exec('DROP DATABASE IF EXISTS `'.$cfg['database'].'`');
        }
    }
    exit;
}
putenv('VP3_PA3_CONFIG='.$fixturePath);
require __DIR__.'/profile-conversation-checkout-pa5.php';

if($action==='init'){
    echo json_encode(pa5_fixture_init()+['driver'=>db()->getAttribute(PDO::ATTR_DRIVER_NAME)],JSON_THROW_ON_ERROR);
    exit;
}
if($action==='state'){
    $counts=[];
    foreach(['profile_agent_conversations','profile_agent_messages','profile_conversation_checkouts_pa5','agent_commerce_orders_v800','agent_commerce_checkout_attempts_v800','agent_commerce_payments_v800'] as $table){
        $counts[$table]=pa3_count($table);
    }
    echo json_encode($counts,JSON_THROW_ON_ERROR);
    exit;
}
if($action==='age_messages'){
    pa3_age();
    exit;
}
throw new RuntimeException('Unsupported fixture command.');
