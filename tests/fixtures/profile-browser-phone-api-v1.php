<?php
declare(strict_types=1);
$config=$argv[2]??throw new RuntimeException('Fixture configuration is required.');
if(($argv[1]??'')==='cleanup'){require __DIR__.'/profile-handoff-api-pa3-db.php';exit;}
putenv('VP3_PA3_CONFIG='.$config);
require __DIR__.'/profile-browser-phone-v1.php';
profile_phone_fixture_settings_v1();
switch($argv[1]??''){
    case 'init': profile_phone_fixture_reset_v1();echo $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);break;
    case 'owner_online':
        $pdo->exec('DELETE FROM phone_browser_presence_v1');
        $pdo->prepare('INSERT INTO phone_browser_presence_v1(owner_user_id,last_epoch,conversation_id,chat_agent_id) VALUES(1,?,14,0)')->execute([time()]);break;
    case 'phone_agent':
        $pdo->exec("INSERT INTO phone_voice_modes_v140(phone_number_id,owner_user_id,agent_id,mode) VALUES(61,1,8,'conversation')");break;
    case 'choose_agent':
        $scope=vp3_phone_workspace_scope_v1($pdo,pa3_owner(),['conversation_id'=>14,'agent_id'=>0]);
        vp3_phone_browser_action_v1($pdo,$scope,['action'=>'route_incoming','call_id'=>$argv[3]??'','destination'=>'agent']);break;
    case 'state':
        $out=[];foreach(['profile_browser_phone_calls_v1','profile_browser_phone_cancellations_v1','phone_browser_calls_v1','phone_browser_presence_v1','phone_voice_calls_v130','phone_browser_deliveries_v1','profile_agent_conversations','profile_agent_messages'] as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();
        echo json_encode($out,JSON_THROW_ON_ERROR);break;
    default: throw new RuntimeException('Unsupported fixture command.');
}
