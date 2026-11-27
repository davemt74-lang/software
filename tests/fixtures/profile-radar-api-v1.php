<?php
declare(strict_types=1);

// Real file-backed PHP sessions. Only the Radar/database domain is replaced;
// the runner executes the production API unchanged in concurrent PHP workers.
session_save_path((string)getenv('VP3_RADAR_API_FIXTURE'));
session_name('vp3radarfixture');
ini_set('session.use_strict_mode','1');
session_start();
function db(): PDO
{
    static $pdo=null;return $pdo??=new PDO('sqlite::memory:');
}
function current_user(): ?array
{
    $id=(int)($_SESSION['user_id']??0);
    return $id>0?['id'=>$id,'role'=>'member']:null;
}
function reset_current_user_cache(): void{}
function has_permission(string $key,?array $user=null): bool{return ($_SESSION['account_allowed']??true)===true;}
function personal_capability_has_v242(string $key,?array $user=null): bool{return ($_SESSION['profile_allowed']??true)===true;}
function vp3_agent_relationship_refresh_owner(PDO $pdo,array $user,int $limit,bool $notify): array
{
    if(($_SERVER['HTTP_X_FIXTURE_PAUSE']??'')==='1'){
        $dir=(string)getenv('VP3_RADAR_API_FIXTURE');
        file_put_contents($dir.'/entered',json_encode(['session_status'=>session_status(),'owner'=>(int)$user['id']]));
        $until=microtime(true)+4;
        while(!is_file($dir.'/release')&&microtime(true)<$until){usleep(10000);clearstatcache(true,$dir.'/release');}
    }
    return [];
}
function vp3_radar_portal_state(PDO $pdo,int $owner): array
{
    if(($_SERVER['HTTP_X_FIXTURE_FAULT']??'')==='storage')throw new RuntimeException('SECRET database credentials');
    return ['ready'=>true,'owner_user_id'=>$owner,'contacts'=>[['display_name'=>'PRIVATE_OWNER_'.$owner]]];
}
function vp3_agent_relationship_enrich_portal(array $radar,array $metrics): array{return $radar;}
