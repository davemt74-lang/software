<?php
declare(strict_types=1);
$input=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);putenv('VP3_PA3_CONFIG='.$input['config']);
require __DIR__.'/profile-handoff-pa3.php';$GLOBALS['pa3Now']=(int)$input['now'];
$actor=$argv[2];$hold=($argv[3]??'')==='hold';$connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
if(!$hold){echo json_encode(['phase'=>'ready','connection'=>$connection])."\n";flush();}
try{
    if($hold)$pdo->beginTransaction();
    $result=$actor==='owner'?vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$input['owner_input']):vp3_profile_handoff_tick_pa3($pdo,1,(int)$input['turn']['conversation_id']);
    if($hold){echo json_encode(['phase'=>'locked','connection'=>$connection])."\n";flush();if(trim((string)fgets(STDIN))!=='commit')throw new RuntimeException('Missing race commit signal.');$pdo->commit();}
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
}catch(VP3ProfileHandoffException $error){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'code'=>$error->publicCode],JSON_THROW_ON_ERROR)."\n";}
