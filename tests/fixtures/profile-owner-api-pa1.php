<?php
declare(strict_types=1);

// Controlled identity and infrastructure only. The HTTP runner copies the
// production API and conversation boundary unchanged into an isolated server.
final class ProfileOwnerApiFixturePDO extends PDO
{
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        $fault=(string)($_SERVER['HTTP_X_FIXTURE_FAULT']??'');
        if(($fault==='insert'&&str_starts_with($sql,'INSERT INTO profile_agent_messages'))
           ||($fault==='after_insert'&&str_starts_with($sql,'UPDATE profile_agent_conversations'))){
            throw new PDOException('SECRET SQLSTATE fixture provider credential /private/config.php');
        }
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=str_replace(' FOR UPDATE','',$sql);
        return parent::prepare($sql,$options);
    }
}
function fixture_pa1_pdo(): PDO
{
    static $pdo=null;if($pdo)return $pdo;
    $cfg=json_decode((string)file_get_contents((string)getenv('VP3_PA1_API_CONFIG')),true,512,JSON_THROW_ON_ERROR);
    $pdo=new ProfileOwnerApiFixturePDO($cfg['dsn'],$cfg['user']??null,$cfg['password']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
    else $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    return $pdo;
}
function db(): ?PDO
{
    if(($_SERVER['HTTP_X_FIXTURE_FAULT']??'')==='connect')throw new PDOException('SECRET SQLSTATE connection credential');
    if(($_SERVER['HTTP_X_FIXTURE_FAULT']??'')==='offline')return null;
    return fixture_pa1_pdo();
}
function current_user(): ?array{$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??1);return $id>0?['id'=>$id,'role'=>'member']:null;}
function has_permission(string $key,?array $user=null): bool{return ($_SERVER['HTTP_X_FIXTURE_ACCOUNT_DENIED']??'')!=='1';}
function personal_capability_has_v242(string $key,?array $user=null): bool{return ($_SERVER['HTTP_X_FIXTURE_PROFILE_DENIED']??'')!=='1';}
function profile_agent_schema_ready(PDO $pdo): bool{return true;}
function personal_capability_schema_ready_v242(PDO $pdo): bool{return true;}
function csrf_token(): string{return 'fixture-owner-csrf';}
function personal_profile_migrate_legacy_artist_v242(PDO $pdo,array $user): void{}
function profile_runtime_owner_state(PDO $pdo,array $user): array
{
    $fault=(string)($_SERVER['HTTP_X_FIXTURE_FAULT']??'');
    if($fault==='runtime')throw new RuntimeException('SECRET unreviewed runtime /private/credentials.php');
    if($fault==='sql_validation_text')throw new PDOException('That username is already in use.');
    return ['owner_user_id'=>(int)$user['id']];
}
function profile_runtime_conversation_owner(PDO $pdo,int $cid,int $uid): ?array{return vp3_profile_agent_owner_conversation_v390($pdo,$cid,$uid);}
function profile_agent_messages(PDO $pdo,int $cid,int $limit=120): array
{
    $q=$pdo->prepare("SELECT id,conversation_id,sender_type,sender_user_id,message,created_at FROM profile_agent_messages WHERE conversation_id=? AND sender_type IN ('visitor','agent','owner') ORDER BY id");$q->execute([$cid]);return $q->fetchAll();
}
function fixture_pa1_mutation(PDO $pdo,string $action,int $uid): void{$pdo->prepare('INSERT INTO fixture_owner_mutations (action,owner_user_id) VALUES (?,?)')->execute([$action,$uid]);}
function personal_profile_save_v242(PDO $pdo,array $user,array $input): array
{
    if(($input['username']??'')==='invalid')throw new RuntimeException('Choose a username using 3–60 letters, numbers, dots, dashes or underscores.');
    fixture_pa1_mutation($pdo,'save_profile',(int)$user['id']);return [];
}
function profile_configure_agent(PDO $pdo,array $user,array $input): array{fixture_pa1_mutation($pdo,'save_profile_agent',(int)$user['id']);return [];}
function user_agent_resource_catalog_v236(): array{return ['knowledge'=>[]];}
function user_data_policy_get_v236(PDO $pdo,int $uid,string $type): array{return ['audience_scope'=>'private'];}
function user_data_policy_save_v236(PDO $pdo,array $user,array $input): array{fixture_pa1_mutation($pdo,'save_profile_access',(int)$user['id']);return [];}
function profile_attention_update(PDO $pdo,int $uid,int $id,string $action): void
{
    if(!in_array($action,['seen','handled','ignored','snooze'],true))throw new RuntimeException('Unknown attention action.');
    $pdo->prepare('UPDATE agent_attention_items SET status=? WHERE id=? AND owner_user_id=?')->execute([$action,$id,$uid]);
}
function upload_file(...$args): ?string{return null;}
function profile_username_normalize(string $username): string{return strtolower($username);}
function profile_by_username(PDO $pdo,string $username): ?array
{
    if(($_SERVER['HTTP_X_FIXTURE_FAULT']??'')==='public_sql')$pdo->query('SELECT SECRET_MISSING_COLUMN FROM profile_agent_conversations');
    return null;
}
require_once __DIR__.'/profile-agent-boundary-v390.php';
