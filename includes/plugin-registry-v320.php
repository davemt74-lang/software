<?php
declare(strict_types=1);

/** Generic opt-in VP3 plugin installation state. Commercial eligibility remains in package entitlements. */
const VP3_PLUGIN_REGISTRY_V320 = 'vp3-plugin-registry-v320-20260909';

function vp3_plugin_catalog_v320(): array
{
    return [
        'music_workspace'=>[
            'label'=>'Music Workspace',
            'description'=>'Tracks, albums, releases, team collaboration, production and music-supervisor tools.',
            'entitlement'=>'music_workspace.access',
        ],
    ];
}

function vp3_plugin_schema_ready_v320(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&table_exists('user_plugin_installations');
}

function vp3_plugin_ensure_schema_v320(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(vp3_plugin_schema_ready_v320($pdo))return;
    // MySQL DDL implicitly commits. Plugin lifecycle mutations may be wrapped in
    // an application transaction, so missing schema must be installed beforehand.
    if($pdo->inTransaction())throw new RuntimeException('Plugin registry schema must be installed before starting a plugin lifecycle transaction.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_plugin_installations (
      user_id INT UNSIGNED NOT NULL,
      plugin_key VARCHAR(80) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'enabled',
      settings_json LONGTEXT NULL,
      enabled_at DATETIME NULL,
      disabled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,plugin_key),
      INDEX idx_plugin_installations_status (plugin_key,status,user_id),
      CONSTRAINT fk_plugin_installation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_plugin_valid_v320(string $key): bool{return isset(vp3_plugin_catalog_v320()[$key]);}

function vp3_plugin_installation_v320(PDO $pdo,int $userId,string $key): ?array
{
    if($userId<1||!vp3_plugin_valid_v320($key)||!vp3_plugin_schema_ready_v320($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM user_plugin_installations WHERE user_id=? AND plugin_key=? LIMIT 1');$stmt->execute([$userId,$key]);$row=$stmt->fetch();return $row?:null;
}

function vp3_plugin_enabled_v320(PDO $pdo,int $userId,string $key): bool
{
    $row=vp3_plugin_installation_v320($pdo,$userId,$key);return $row&&$row['status']==='enabled';
}

function vp3_plugin_set_enabled_v320(PDO $pdo,int $userId,string $key,bool $enabled): array
{
    if($userId<1)throw new RuntimeException('Sign in to manage plugins.');if(!vp3_plugin_valid_v320($key))throw new RuntimeException('Unknown VP3 plugin.');
    // DDL causes implicit commits in MySQL. Only create the schema when missing so
    // callers can safely wrap installation-state mutations in their own transaction.
    if(!vp3_plugin_schema_ready_v320($pdo))vp3_plugin_ensure_schema_v320($pdo);
    $status=$enabled?'enabled':'disabled';
    $stmt=$pdo->prepare("INSERT INTO user_plugin_installations (user_id,plugin_key,status,enabled_at,disabled_at) VALUES (?,?,?,IF(?='enabled',NOW(),NULL),IF(?='disabled',NOW(),NULL)) ON DUPLICATE KEY UPDATE status=VALUES(status),enabled_at=IF(VALUES(status)='enabled',COALESCE(enabled_at,NOW()),enabled_at),disabled_at=IF(VALUES(status)='disabled',NOW(),NULL),updated_at=NOW()");
    $stmt->execute([$userId,$key,$status,$status,$status]);
    return vp3_plugin_installation_v320($pdo,$userId,$key)?:throw new RuntimeException('Plugin state could not be saved.');
}
