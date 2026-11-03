<?php
declare(strict_types=1);

// Independent process against the parent's disposable MySQL database.
$input=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
putenv('VP3_PA3_CONFIG='.$input['config']);
require __DIR__.'/profile-calls-pa3c.php';
$GLOBALS['pa3Now']=(int)$input['now'];
$which=(int)$argv[2];$hold=($argv[3]??'')==='hold';
$connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
pa3_login(1);
if(!$hold){echo json_encode(['phase'=>'ready','connection'=>$connection])."\n";flush();}
try{
    if($hold)$pdo->beginTransaction();
    $result=vp3_profile_call_owner_action_pa3c($pdo,pa3c_owner(),$input['operations'][$which]);
    if($hold){
        echo json_encode(['phase'=>'locked','connection'=>$connection])."\n";flush();
        if(trim((string)fgets(STDIN))!=='commit')throw new RuntimeException('Missing race commit signal.');
        $pdo->commit();
    }
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
}catch(VP3ProfileCallException $error){
    if($pdo->inTransaction())$pdo->rollBack();
    echo json_encode(['ok'=>false,'code'=>$error->publicCode],JSON_THROW_ON_ERROR)."\n";
}
