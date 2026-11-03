<?php
declare(strict_types=1);
$input=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
putenv('VP3_PA3_CONFIG='.$input['config']);require __DIR__.'/profile-visitor-speech-pa4a.php';
$GLOBALS['pa3Now']=(int)$input['now'];$which=(int)$argv[2];$hold=($argv[3]??'')==='hold';$connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();pa3_login(1);
if(!$hold){echo json_encode(['phase'=>'ready','connection'=>$connection])."\n";flush();}
else $pdo->afterSql=static function(string $sql,PA3PDO $pdo)use($connection):void{
    if($pdo->inTransaction()&&str_starts_with($sql,'SELECT * FROM profile_call_slots_pa3c WHERE owner_user_id=')&&str_contains($sql,'FOR UPDATE')){
        $pdo->afterSql=null;echo json_encode(['phase'=>'locked','connection'=>$connection])."\n";flush();
        if(trim((string)fgets(STDIN))!=='continue')throw new RuntimeException('Missing race continuation signal.');
    }
};
try{
    $operation=$input['operations'][$which];$wire=$operation['input'];
    $result=match($operation['action']){
        'claim'=>vp3_profile_speech_claim_pa4a($pdo,ps_owner(),$wire),
        'play'=>vp3_profile_speech_play_pa4a($pdo,ps_owner(),$wire),
        'call'=>vp3_profile_call_owner_action_pa3c($pdo,ps_owner(),$wire),
        default=>throw new RuntimeException('Unknown race operation.'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
}catch(VP3ProfileSpeechException|VP3ProfileCallException $error){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'code'=>$error->publicCode],JSON_THROW_ON_ERROR)."\n";}
