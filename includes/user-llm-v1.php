<?php
declare(strict_types=1);

/** Cloud account BYOK v1. Secrets never cross the HomeServer relay. */
const VP3_USER_LLM_V1='vp3.cloud.user-llm.v1';

function vp3_user_llm_v1_providers(): array { return ['openai','anthropic']; }

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

function vp3_user_llm_v1_read(PDO $pdo,int $userId): array
{
    if($userId<1)throw new RuntimeException('A signed-in account is required.');
    $pref=['route'=>'system','provider'=>''];
    try {
        $stmt=$pdo->prepare('SELECT route,provider FROM user_llm_preferences WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(is_array($row)&&($row['route']??'')==='own_key'&&in_array($row['provider']??'',vp3_user_llm_v1_providers(),true))$pref=$row;
        $stmt=$pdo->prepare('SELECT provider,model,encrypted_key FROM user_llm_credentials WHERE user_id=?');
        $stmt->execute([$userId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }catch(PDOException $e){
        // Existing accounts continue using system-funded inference before migration.
        return ['route'=>'system','provider'=>'','providers'=>[],'schema_ready'=>false];
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
    $state=vp3_user_llm_v1_read($pdo,$uid);
    $name=(string)($state['provider']??'');
    if(($state['route']??'system')!=='own_key')return ['route'=>'system'];
    if(empty($state['providers'][$name]['configured']))return ['route'=>'unavailable'];
    $stmt=$pdo->prepare('SELECT encrypted_key,model FROM user_llm_credentials WHERE user_id=? AND provider=? LIMIT 1');
    $stmt->execute([$uid,$name]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))return ['route'=>'unavailable'];
    $key=ai_decrypt_secret((string)$row['encrypted_key']);
    if($key===''||!ai_valid_model($name,(string)$row['model']))return ['route'=>'unavailable'];
    return ['route'=>'own_key','provider'=>$name,'model'=>(string)$row['model'],'api_key'=>$key];
}

function vp3_user_llm_v1_save(PDO $pdo,array $user,string $provider,string $apiKey,string $model): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in before saving provider keys.');
    if(!in_array($provider,vp3_user_llm_v1_providers(),true))throw new RuntimeException('Unsupported provider.');
    if(!ai_valid_model($provider,$model))throw new RuntimeException('Choose a supported provider model.');
    $key=trim($apiKey);
    if($key===''||strlen($key)>4000||preg_match('/[\x00-\x20\x7f]/',$key))throw new RuntimeException('Enter a valid provider API key.');
    // Reuse Cloud's existing encrypted credential key. No plaintext database column.
    $encrypted=ai_encrypt_secret($key);
    $stmt=$pdo->prepare('INSERT INTO user_llm_credentials(user_id,provider,encrypted_key,model) VALUES(?,?,?,?)
      ON DUPLICATE KEY UPDATE encrypted_key=VALUES(encrypted_key),model=VALUES(model),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$uid,$provider,$encrypted,$model]);
    return vp3_user_llm_v1_read($pdo,$uid);
}

function vp3_user_llm_v1_select(PDO $pdo,array $user,string $route,string $provider=''): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in before changing inference routing.');
    if($route!=='system'&&$route!=='own_key')throw new RuntimeException('Unknown routing mode.');
    if($route==='own_key'){
        $state=vp3_user_llm_v1_read($pdo,$uid);
        if(!in_array($provider,vp3_user_llm_v1_providers(),true)||empty($state['providers'][$provider]['configured']))
            throw new RuntimeException('Save your own provider key first.');
    }else{$provider='';}
    $stmt=$pdo->prepare('INSERT INTO user_llm_preferences(user_id,route,provider) VALUES(?,?,?)
      ON DUPLICATE KEY UPDATE route=VALUES(route),provider=VALUES(provider),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$uid,$route,$provider]);
    return vp3_user_llm_v1_read($pdo,$uid);
}

function vp3_user_llm_v1_remove(PDO $pdo,array $user,string $provider): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!in_array($provider,vp3_user_llm_v1_providers(),true))throw new RuntimeException('Invalid provider.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE user_llm_preferences SET route='system',provider='' WHERE user_id=? AND provider=?")
            ->execute([$uid,$provider]);
        $pdo->prepare('DELETE FROM user_llm_credentials WHERE user_id=? AND provider=?')->execute([$uid,$provider]);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    return vp3_user_llm_v1_read($pdo,$uid);
}
