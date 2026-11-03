<?php
declare(strict_types=1);
$input=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
putenv('VP3_PA3_CONFIG='.$input['config']);require __DIR__.'/profile-attention-pa4b.php';
$GLOBALS['pa3Now']=(int)$input['now'];$which=(int)$argv[2];$hold=($argv[3]??'')==='hold';$connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();pa3_login(1);
$operation=$input['operations'][$which];
if(!$hold){echo json_encode(['phase'=>'ready','connection'=>$connection])."\n";flush();}
else $pdo->afterSql=static function(string $sql,PA3PDO $pdo)use($connection,$operation):void{
    $prefix=$operation['action']==='repair'?'SELECT * FROM profile_event_projections_pa4b WHERE id=':'SELECT * FROM agent_attention_items WHERE id=';
    if($pdo->inTransaction()&&str_starts_with($sql,$prefix)&&str_contains($sql,'FOR UPDATE')){
        $pdo->afterSql=null;echo json_encode(['phase'=>'locked','connection'=>$connection])."\n";flush();
        if(trim((string)fgets(STDIN))!=='continue')throw new RuntimeException('Missing race continuation signal.');
    }
};
try{
    $result=match($operation['action']){
        'present'=>vp3_profile_attention_present_pa4b($pdo,pa4b_owner(),$operation['input']),
        'update'=>vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),$operation['input']),
        'wake'=>vp3_profile_attention_maintenance_pa4b($pdo),
        'repair'=>vp3_profile_projection_repair_pa4b($pdo,(int)$operation['obligation_id']),
        default=>throw new RuntimeException('Unknown race operation.'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
}catch(VP3ProfileAttentionException $error){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'code'=>$error->publicCode,'status'=>$error->httpStatus],JSON_THROW_ON_ERROR)."\n";}
