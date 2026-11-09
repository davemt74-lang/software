<?php
declare(strict_types=1);

/** Cloud account BYOK v1. Secrets never cross the HomeServer relay. */
const VP3_USER_LLM_V1='vp3.cloud.user-llm.v1';

function vp3_user_llm_v1_providers(): array { return ['openai','anthropic']; }

function vp3_user_llm_v1_models(PDO $pdo): array
{
    require_once __DIR__.'/ai-service-policy-v1.php';
    $builtIn = ai_model_catalog();
    try {
        $registered = vp3_ai_service_models_v1($pdo, 'conversation');
    } catch (PDOException | UnexpectedValueException) {
        return $builtIn;
    }
    foreach (vp3_user_llm_v1_providers() as $provider) {
        $builtIn[$provider] = ($registered[$provider] ?? []) + $builtIn[$provider];
    }
    return $builtIn;
}

function vp3_user_llm_v1_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_llm_credentials (
      user_id INT UNSIGNED NOT NULL,
      provider VARCHAR(32) NOT NULL,
      encrypted_key TEXT NOT NULL,
      model VARCHAR(160) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,provider),
      CONSTRAINT fk_user_llm_credentials_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_llm_preferences (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      route VARCHAR(24) NOT NULL DEFAULT 'system',
      provider VARCHAR(32) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_user_llm_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_user_llm_v1_preference(PDO $pdo,int $userId): array
{
    $default=['route'=>'system','provider'=>''];
    if($userId<1)return $default;
    try {
        $stmt=$pdo->prepare('SELECT route,provider FROM user_llm_preferences WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    }catch(PDOException $error){
        // Installations before the preferences table used the VP3 subscription.
        if($error->getCode()==='42S02'||($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            &&str_contains($error->getMessage(),'no such table: user_llm_preferences')))return $default;
        throw $error;
    }
    if($row===false||($row['route']??'')==='system')return $default;
    if(($row['route']??'')!=='own_key')throw new RuntimeException('Cloud account funding is unavailable.');
    $provider=$row['provider']??'';
    return ['route'=>'own_key','provider'=>in_array($provider,vp3_user_llm_v1_providers(),true)?$provider:''];
}

function vp3_user_llm_v1_funding(PDO $pdo,?array $user): string
{
    return vp3_user_llm_v1_preference($pdo,(int)($user['id']??0))['route'];
}

function vp3_user_llm_v1_credential(PDO $pdo,int $userId,string $provider): array
{
    if($userId<1||!in_array($provider,vp3_user_llm_v1_providers(),true))return [];
    try{
        $stmt=$pdo->prepare('SELECT encrypted_key,model FROM user_llm_credentials WHERE user_id=? AND provider=? LIMIT 1');
        $stmt->execute([$userId,$provider]);
        return $stmt->fetch(PDO::FETCH_ASSOC)?:[];
    }catch(PDOException){
        return [];
    }
}

function vp3_user_llm_v1_saved_key(PDO $pdo,int $userId,string $provider): string
{
    $row=vp3_user_llm_v1_credential($pdo,$userId,$provider);
    return ai_decrypt_secret((string)($row['encrypted_key']??''));
}

function vp3_user_llm_v1_read(PDO $pdo,int $userId): array
{
    if($userId<1)throw new RuntimeException('A signed-in account is required.');
    $pref=vp3_user_llm_v1_preference($pdo,$userId);
    try {
        $stmt=$pdo->prepare('SELECT provider,model,encrypted_key FROM user_llm_credentials WHERE user_id=?');
        $stmt->execute([$userId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }catch(PDOException){
        return $pref+['providers'=>[],'schema_ready'=>false];
    }
    $providers=[];
    foreach($rows as $row){
        $name=(string)$row['provider'];
        if(!in_array($name,vp3_user_llm_v1_providers(),true))continue;
        $encrypted=(string)($row['encrypted_key']??'');
        $key=ai_decrypt_secret($encrypted);
        $providers[$name]=['configured'=>$key!=='','model'=>(string)($row['model']??''),'suffix'=>$key!==''?substr($key,-4):''];
    }
    return ['route'=>$pref['route'],'provider'=>$pref['provider'],'providers'=>$providers,'schema_ready'=>true,'contract'=>VP3_USER_LLM_V1];
}

function vp3_user_llm_v1_effective(PDO $pdo,array $user): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)return ['route'=>'system'];
    $pref=vp3_user_llm_v1_preference($pdo,$uid);
    if($pref['route']!=='own_key')return ['route'=>'system'];
    $name=$pref['provider'];
    $row=vp3_user_llm_v1_credential($pdo,$uid,$name);
    $key=ai_decrypt_secret((string)($row['encrypted_key']??''));
    $model=(string)($row['model']??'');
    if($key===''||(!ai_valid_model($name,$model)&&!isset(vp3_user_llm_v1_models($pdo)[$name][$model])))return ['route'=>'unavailable'];
    return ['route'=>'own_key','provider'=>$name,'model'=>$model,'api_key'=>$key];
}

function vp3_user_llm_v1_update(PDO $pdo,int $userId,callable $write): array
{
    require_once __DIR__.'/ai-service-usage-v1.php';
    $pdo->beginTransaction();
    try{
        vp3_ai_service_usage_lock_owner_v1($pdo,$userId);
        $write();
        $pdo->commit();
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
    return vp3_user_llm_v1_read($pdo,$userId);
}

function vp3_user_llm_v1_save(PDO $pdo,array $user,string $provider,string $apiKey,string $model): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in before saving provider keys.');
    if(!in_array($provider,vp3_user_llm_v1_providers(),true))throw new RuntimeException('Unsupported provider.');
    if(!ai_valid_model($provider,$model)&&!isset(vp3_user_llm_v1_models($pdo)[$provider][$model]))throw new RuntimeException('Choose a supported provider model.');
    $key=trim($apiKey);
    if($key===''||strlen($key)>4000||preg_match('/[\x00-\x20\x7f]/',$key))throw new RuntimeException('Enter a valid provider API key.');
    // Reuse Cloud's existing encrypted credential key. No plaintext database column.
    $encrypted=ai_encrypt_secret($key);
    return vp3_user_llm_v1_update($pdo,$uid,static function() use($pdo,$uid,$provider,$encrypted,$model): void{
        $stmt=$pdo->prepare('INSERT INTO user_llm_credentials(user_id,provider,encrypted_key,model) VALUES(?,?,?,?)
          ON DUPLICATE KEY UPDATE encrypted_key=VALUES(encrypted_key),model=VALUES(model),updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([$uid,$provider,$encrypted,$model]);
    });
}

function vp3_user_llm_v1_select(PDO $pdo,array $user,string $route,string $provider=''): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in before changing inference routing.');
    if($route!=='system'&&$route!=='own_key')throw new RuntimeException('Unknown routing mode.');
    return vp3_user_llm_v1_update($pdo,$uid,static function() use($pdo,$uid,$route,$provider): void{
        if($route==='own_key'&&vp3_user_llm_v1_saved_key($pdo,$uid,$provider)==='')
            throw new RuntimeException('Save your own provider key first.');
        $stmt=$pdo->prepare('INSERT INTO user_llm_preferences(user_id,route,provider) VALUES(?,?,?)
          ON DUPLICATE KEY UPDATE route=VALUES(route),provider=VALUES(provider),updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([$uid,$route,$route==='own_key'?$provider:'']);
    });
}

function vp3_user_llm_v1_remove(PDO $pdo,array $user,string $provider): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!in_array($provider,vp3_user_llm_v1_providers(),true))throw new RuntimeException('Invalid provider.');
    return vp3_user_llm_v1_update($pdo,$uid,static function() use($pdo,$uid,$provider): void{
        $pdo->prepare('DELETE FROM user_llm_credentials WHERE user_id=? AND provider=?')->execute([$uid,$provider]);
    });
}
