<?php
declare(strict_types=1);

const STONEFELLOW_USER_AGENT_SYSTEM_V236='user-agent-system-v236-20260903';

function system_agent_name(): string
{
    $name=trim((string)setting('system_agent_name','STONEFELLOW'));
    $name=preg_replace('/\s+/u',' ',$name) ?? '';
    return $name!==''?mb_strimwidth($name,0,80,''):'STONEFELLOW';
}

function user_agent_resource_catalog_v236(): array
{
    return [
        'profile'=>['label'=>'Profile','description'=>'Bio, profile images, links and public identity.'],
        'knowledge'=>['label'=>'Knowledge Base','description'=>'Notes, documents and retained user knowledge.'],
        'track'=>['label'=>'Songs & Tracks','description'=>'Published music, lyrics, descriptions and song metadata.'],
        'album'=>['label'=>'Albums','description'=>'Album releases, artwork and release information.'],
        'show'=>['label'=>'Shows','description'=>'Show dates, venues, tickets and live-event information.'],
        'photo'=>['label'=>'Photos','description'=>'Profile and artist media library photos.'],
        'post'=>['label'=>'Posts','description'=>'Artist updates, social posts and attached media.'],
        'merch'=>['label'=>'Merch','description'=>'Published merchandise and purchase information.'],
        'recording'=>['label'=>'Recordings','description'=>'Private Artist Listening recordings and retained audio references.'],
        'voice'=>['label'=>'Voice Profile','description'=>'Voice clone availability and approved voice identity settings.'],
        'project'=>['label'=>'Projects','description'=>'Private studio projects, stems and collaboration context.'],
    ];
}

function user_agent_roles_v236(): array
{
    return ['personal'=>'Personal Agent','artist'=>'Artist Agent','studio'=>'Studio Agent','booking'=>'Booking Agent','profile'=>'Profile Agent','custom'=>'Custom Agent'];
}

function user_agent_audiences_v236(): array
{
    return ['inherit'=>'Use existing content visibility','private'=>'Private to me','connections'=>'My connections','collaborators'=>'My collaborators','public'=>'Public'];
}

function user_agent_system_schema_ready_v236(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo
        && table_exists('user_agents')
        && table_exists('user_agent_preferences')
        && table_exists('user_data_policies')
        && table_exists('user_agent_data_rules')
        && table_exists('user_relationships')
        && table_exists('user_data_policy_audit')
        && column_exists('chat_conversations','user_agent_id');
}

function user_agent_system_ensure_schema_v236(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo) throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_agents (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_key VARCHAR(80) NOT NULL,
      display_name VARCHAR(190) NOT NULL,
      agent_role VARCHAR(40) NOT NULL DEFAULT 'personal',
      engine_key VARCHAR(40) NOT NULL DEFAULT 'stonefellow',
      instructions TEXT NULL,
      is_default TINYINT(1) NOT NULL DEFAULT 0,
      is_profile_agent TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      voice_enabled TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_user_agent_key (owner_user_id,agent_key),
      INDEX idx_user_agents_owner_active (owner_user_id,is_active,is_default,id),
      CONSTRAINT fk_user_agents_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_agent_preferences (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      onboarding_dismissed TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_user_agent_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_data_policies (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      resource_type VARCHAR(50) NOT NULL,
      resource_id VARCHAR(100) NOT NULL DEFAULT '*',
      audience_scope VARCHAR(30) NOT NULL DEFAULT 'inherit',
      owner_agents_allowed TINYINT(1) NOT NULL DEFAULT 1,
      profile_agent_allowed TINYINT(1) NOT NULL DEFAULT 0,
      stonefellow_shared TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_user_data_policy (owner_user_id,resource_type,resource_id),
      INDEX idx_user_data_stonefellow (stonefellow_shared,resource_type,audience_scope,owner_user_id),
      CONSTRAINT fk_user_data_policy_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_agent_data_rules (
      agent_id BIGINT UNSIGNED NOT NULL,
      resource_type VARCHAR(50) NOT NULL,
      resource_id VARCHAR(100) NOT NULL DEFAULT '*',
      access_mode VARCHAR(20) NOT NULL DEFAULT 'inherit',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (agent_id,resource_type,resource_id),
      CONSTRAINT fk_user_agent_rule_agent FOREIGN KEY (agent_id) REFERENCES user_agents(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_relationships (
      owner_user_id INT UNSIGNED NOT NULL,
      related_user_id INT UNSIGNED NOT NULL,
      relationship_scope VARCHAR(30) NOT NULL DEFAULT 'connection',
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (owner_user_id,related_user_id),
      INDEX idx_user_relationship_related (related_user_id,status,relationship_scope),
      CONSTRAINT fk_user_relationship_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_relationship_related FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_data_policy_audit (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      actor_user_id INT UNSIGNED NULL,
      action_key VARCHAR(60) NOT NULL,
      resource_type VARCHAR(50) NOT NULL DEFAULT '',
      resource_id VARCHAR(100) NOT NULL DEFAULT '',
      before_json LONGTEXT NULL,
      after_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user_data_audit_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_user_data_audit_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_data_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if(table_exists('chat_conversations')&&!column_exists('chat_conversations','user_agent_id')){
      $pdo->exec('ALTER TABLE chat_conversations ADD COLUMN user_agent_id BIGINT UNSIGNED NULL AFTER user_id, ADD INDEX idx_chat_conversations_agent (user_id,user_agent_id,updated_at)');
      try{$pdo->exec('ALTER TABLE chat_conversations ADD CONSTRAINT fk_chat_conversations_user_agent FOREIGN KEY (user_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL');}catch(Throwable $e){}
    }
}

function user_agent_slug_v236(string $value): string
{
    $value=mb_strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/u','-',$value) ?? '';
    return substr(trim($value,'-'),0,60);
}

function user_agents_list_v236(PDO $pdo,int $userId,bool $activeOnly=false): array
{
    $sql='SELECT id,owner_user_id,agent_key,display_name,agent_role,engine_key,instructions,is_default,is_profile_agent,is_active,voice_enabled,created_at,updated_at FROM user_agents WHERE owner_user_id=?';
    if($activeOnly)$sql.=' AND is_active=1';
    $sql.=' ORDER BY is_default DESC,is_profile_agent DESC,display_name ASC,id ASC';
    $stmt=$pdo->prepare($sql);$stmt->execute([$userId]);return $stmt->fetchAll()?:[];
}

function user_agent_get_v236(PDO $pdo,int $userId,int $agentId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$agentId,$userId]);return $stmt->fetch()?:null;
}

function user_agent_create_v236(PDO $pdo,array $user,array $input): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in account is required.');
    $name=trim(preg_replace('/\s+/u',' ',(string)($input['display_name']??''))??'');
    if($name==='')throw new RuntimeException('Enter a name for your agent.');
    $name=mb_strimwidth($name,0,190,'');
    $role=(string)($input['agent_role']??'personal');if(!isset(user_agent_roles_v236()[$role]))$role='custom';
    $key=user_agent_slug_v236($name);if($key==='')$key='agent-'.substr(sha1($name.microtime(true)),0,10);
    $candidate=$key;$n=1;
    while(true){$s=$pdo->prepare('SELECT 1 FROM user_agents WHERE owner_user_id=? AND agent_key=? LIMIT 1');$s->execute([$uid,$candidate]);if(!$s->fetchColumn())break;$n++;$candidate=substr($key,0,52).'-'.$n;}
    $key=$candidate;
    $hasAny=(int)$pdo->prepare('SELECT COUNT(*) FROM user_agents WHERE owner_user_id=?')->execute([$uid]);
    $countStmt=$pdo->prepare('SELECT COUNT(*) FROM user_agents WHERE owner_user_id=?');$countStmt->execute([$uid]);$isFirst=(int)$countStmt->fetchColumn()===0;
    $isDefault=$isFirst||!empty($input['is_default']);
    $isProfile=!empty($input['is_profile_agent']);
    if($isDefault)$pdo->prepare('UPDATE user_agents SET is_default=0 WHERE owner_user_id=?')->execute([$uid]);
    if($isProfile)$pdo->prepare('UPDATE user_agents SET is_profile_agent=0 WHERE owner_user_id=?')->execute([$uid]);
    $instructions=mb_strimwidth(trim((string)($input['instructions']??'')),0,4000,'…');
    $stmt=$pdo->prepare("INSERT INTO user_agents (owner_user_id,agent_key,display_name,agent_role,engine_key,instructions,is_default,is_profile_agent,is_active,voice_enabled) VALUES (?,?,?,?, 'stonefellow',?,?,?,?,?)");
    $stmt->execute([$uid,$key,$name,$role,$instructions,$isDefault?1:0,$isProfile?1:0,1,!empty($input['voice_enabled'])?1:0]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_agent_preferences (user_id,onboarding_dismissed) VALUES (?,1) ON DUPLICATE KEY UPDATE onboarding_dismissed=1')->execute([$uid]);
    return user_agent_get_v236($pdo,$uid,$id)?:throw new RuntimeException('Agent could not be created.');
}

function user_agent_update_v236(PDO $pdo,array $user,array $input): array
{
    $uid=(int)($user['id']??0);$id=(int)($input['id']??0);$before=user_agent_get_v236($pdo,$uid,$id);if(!$before)throw new RuntimeException('Agent not found.');
    $name=trim(preg_replace('/\s+/u',' ',(string)($input['display_name']??$before['display_name']))??'');if($name==='')throw new RuntimeException('Enter a name for your agent.');
    $role=(string)($input['agent_role']??$before['agent_role']);if(!isset(user_agent_roles_v236()[$role]))$role='custom';
    $isDefault=!empty($input['is_default']);$isProfile=array_key_exists('is_profile_agent',$input)?!empty($input['is_profile_agent']):!empty($before['is_profile_agent']);
    if($isDefault)$pdo->prepare('UPDATE user_agents SET is_default=0 WHERE owner_user_id=?')->execute([$uid]);
    if($isProfile)$pdo->prepare('UPDATE user_agents SET is_profile_agent=0 WHERE owner_user_id=?')->execute([$uid]);
    $stmt=$pdo->prepare('UPDATE user_agents SET display_name=?,agent_role=?,instructions=?,is_default=?,is_profile_agent=?,is_active=?,voice_enabled=? WHERE id=? AND owner_user_id=?');
    $stmt->execute([mb_strimwidth($name,0,190,''),$role,mb_strimwidth(trim((string)($input['instructions']??'')),0,4000,'…'),$isDefault?1:0,$isProfile?1:0,array_key_exists('is_active',$input)?(!empty($input['is_active'])?1:0):(int)$before['is_active'],!empty($input['voice_enabled'])?1:0,$id,$uid]);
    return user_agent_get_v236($pdo,$uid,$id)?:throw new RuntimeException('Agent could not be updated.');
}

function user_agent_delete_v236(PDO $pdo,array $user,int $id): void
{
    $uid=(int)($user['id']??0);$agent=user_agent_get_v236($pdo,$uid,$id);if(!$agent)throw new RuntimeException('Agent not found.');
    $pdo->prepare('DELETE FROM user_agents WHERE id=? AND owner_user_id=?')->execute([$id,$uid]);
    if(!empty($agent['is_default'])){$s=$pdo->prepare('SELECT id FROM user_agents WHERE owner_user_id=? AND is_active=1 ORDER BY id ASC LIMIT 1');$s->execute([$uid]);$next=(int)$s->fetchColumn();if($next>0)$pdo->prepare('UPDATE user_agents SET is_default=1 WHERE id=? AND owner_user_id=?')->execute([$next,$uid]);}
}

function user_agent_onboarding_dismissed_v236(PDO $pdo,int $uid): bool
{
    $s=$pdo->prepare('SELECT onboarding_dismissed FROM user_agent_preferences WHERE user_id=? LIMIT 1');$s->execute([$uid]);return (bool)$s->fetchColumn();
}

function user_agent_dismiss_onboarding_v236(PDO $pdo,int $uid): void
{
    $pdo->prepare('INSERT INTO user_agent_preferences (user_id,onboarding_dismissed) VALUES (?,1) ON DUPLICATE KEY UPDATE onboarding_dismissed=1')->execute([$uid]);
}

function user_data_policy_default_v236(string $type): array
{
    return ['resource_type'=>$type,'resource_id'=>'*','audience_scope'=>'inherit','owner_agents_allowed'=>true,'profile_agent_allowed'=>false,'stonefellow_shared'=>false,'explicit'=>false];
}

function user_data_policy_get_v236(PDO $pdo,int $owner,string $type,string $resourceId='*'): array
{
    if(!isset(user_agent_resource_catalog_v236()[$type]))return user_data_policy_default_v236($type);
    $rid=trim($resourceId)!==''?mb_strimwidth(trim($resourceId),0,100,''):'*';
    $s=$pdo->prepare("SELECT * FROM user_data_policies WHERE owner_user_id=? AND resource_type=? AND resource_id IN (?, '*') ORDER BY (resource_id=?) DESC LIMIT 1");$s->execute([$owner,$type,$rid,$rid]);$r=$s->fetch();if(!$r)return user_data_policy_default_v236($type);
    return ['resource_type'=>$type,'resource_id'=>(string)$r['resource_id'],'audience_scope'=>(string)$r['audience_scope'],'owner_agents_allowed'=>(bool)$r['owner_agents_allowed'],'profile_agent_allowed'=>(bool)$r['profile_agent_allowed'],'stonefellow_shared'=>(bool)$r['stonefellow_shared'],'explicit'=>true];
}

function user_data_policy_save_v236(PDO $pdo,array $user,array $input): array
{
    $uid=(int)($user['id']??0);$type=(string)($input['resource_type']??'');if(!isset(user_agent_resource_catalog_v236()[$type]))throw new RuntimeException('Unknown data type.');
    $audience=(string)($input['audience_scope']??'inherit');if(!isset(user_agent_audiences_v236()[$audience]))throw new RuntimeException('Unknown audience.');
    $rid=trim((string)($input['resource_id']??'*'))?:'*';$rid=mb_strimwidth($rid,0,100,'');
    $s=$pdo->prepare('INSERT INTO user_data_policies (owner_user_id,resource_type,resource_id,audience_scope,owner_agents_allowed,profile_agent_allowed,stonefellow_shared) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE audience_scope=VALUES(audience_scope),owner_agents_allowed=VALUES(owner_agents_allowed),profile_agent_allowed=VALUES(profile_agent_allowed),stonefellow_shared=VALUES(stonefellow_shared)');
    $s->execute([$uid,$type,$rid,$audience,!empty($input['owner_agents_allowed'])?1:0,!empty($input['profile_agent_allowed'])?1:0,!empty($input['stonefellow_shared'])?1:0]);
    return user_data_policy_get_v236($pdo,$uid,$type,$rid);
}

function user_agent_rule_get_v236(PDO $pdo,int $agentId,string $type,string $rid='*'): string
{
    $s=$pdo->prepare("SELECT access_mode FROM user_agent_data_rules WHERE agent_id=? AND resource_type=? AND resource_id IN (?, '*') ORDER BY (resource_id=?) DESC LIMIT 1");$s->execute([$agentId,$type,$rid,$rid]);$mode=(string)($s->fetchColumn()?:'inherit');return in_array($mode,['inherit','allow','deny'],true)?$mode:'inherit';
}

function user_agent_rule_save_v236(PDO $pdo,array $user,int $agentId,string $type,string $mode): void
{
    $uid=(int)($user['id']??0);if(!user_agent_get_v236($pdo,$uid,$agentId))throw new RuntimeException('Agent not found.');if(!isset(user_agent_resource_catalog_v236()[$type]))throw new RuntimeException('Unknown data type.');if(!in_array($mode,['inherit','allow','deny'],true))throw new RuntimeException('Unknown access mode.');
    if($mode==='inherit'){$pdo->prepare("DELETE FROM user_agent_data_rules WHERE agent_id=? AND resource_type=? AND resource_id='*'")->execute([$agentId,$type]);return;}
    $pdo->prepare("INSERT INTO user_agent_data_rules (agent_id,resource_type,resource_id,access_mode) VALUES (?,?,'*',?) ON DUPLICATE KEY UPDATE access_mode=VALUES(access_mode)")->execute([$agentId,$type,$mode]);
}

function user_relationship_scope_v236(PDO $pdo,int $owner,int $viewer): string
{
    if($owner>0&&$owner===$viewer)return 'self';if($owner<1||$viewer<1)return 'none';$s=$pdo->prepare('SELECT relationship_scope,status FROM user_relationships WHERE owner_user_id=? AND related_user_id=? LIMIT 1');$s->execute([$owner,$viewer]);$r=$s->fetch();if(!$r||$r['status']!=='accepted')return 'none';return $r['relationship_scope']==='collaborator'?'collaborator':'connection';
}

function user_policy_audience_allows_v236(PDO $pdo,string $scope,int $owner,int $viewer,bool $legacy): bool
{
    if($viewer>0&&$viewer===$owner)return true;if($scope==='inherit')return $legacy;if($scope==='private')return false;if($scope==='public')return true;$rel=user_relationship_scope_v236($pdo,$owner,$viewer);if($scope==='connections')return in_array($rel,['connection','collaborator'],true);if($scope==='collaborators')return $rel==='collaborator';return false;
}

function user_agent_principal_v236(?array $viewer,?array $agent=null,bool $profile=false): array
{
    if($agent){return ['kind'=>$profile?'profile_agent':'user_agent','agent_id'=>(int)$agent['id'],'owner_user_id'=>(int)$agent['owner_user_id'],'viewer_user_id'=>(int)($viewer['id']??0),'display_name'=>(string)$agent['display_name'],'engine_key'=>'stonefellow'];}
    return ['kind'=>'system','agent_id'=>0,'owner_user_id'=>0,'viewer_user_id'=>(int)($viewer['id']??0),'display_name'=>system_agent_name(),'engine_key'=>'stonefellow'];
}

function user_data_policy_can_use_v236(PDO $pdo,array $principal,int $owner,string $type,string $rid='*',bool $legacy=false): bool
{
    if($owner<1)return true;if(!isset(user_agent_resource_catalog_v236()[$type]))return false;$p=user_data_policy_get_v236($pdo,$owner,$type,$rid);$kind=(string)($principal['kind']??'system');$viewer=(int)($principal['viewer_user_id']??0);
    if($kind==='user_agent'){
      if((int)$principal['owner_user_id']===$owner){$rule=user_agent_rule_get_v236($pdo,(int)$principal['agent_id'],$type,$rid);if($rule==='deny')return false;if($rule==='allow')return true;return !empty($p['owner_agents_allowed']);}
      if(empty($p['stonefellow_shared']))return false;return user_policy_audience_allows_v236($pdo,(string)$p['audience_scope'],$owner,$viewer,$legacy);
    }
    if($kind==='profile_agent'){
      if((int)$principal['owner_user_id']!==$owner||empty($p['profile_agent_allowed']))return false;$rule=user_agent_rule_get_v236($pdo,(int)$principal['agent_id'],$type,$rid);if($rule==='deny')return false;return user_policy_audience_allows_v236($pdo,(string)$p['audience_scope'],$owner,$viewer,$legacy);
    }
    if(empty($p['stonefellow_shared']))return false;return user_policy_audience_allows_v236($pdo,(string)$p['audience_scope'],$owner,$viewer,$legacy);
}

function user_data_owner_workspace_v236(PDO $pdo,int $workspaceId): int
{
    static $cache=[];if($workspaceId<1)return 0;if(isset($cache[$workspaceId]))return $cache[$workspaceId];$s=$pdo->prepare('SELECT artist_user_id FROM artist_workspaces_v181 WHERE id=? LIMIT 1');$s->execute([$workspaceId]);return $cache[$workspaceId]=(int)$s->fetchColumn();
}

function user_agent_state_v236(PDO $pdo,array $user): array
{
    $uid=(int)$user['id'];$agents=user_agents_list_v236($pdo,$uid);foreach($agents as &$a){$a['data_rules']=[];foreach(array_keys(user_agent_resource_catalog_v236()) as $type)$a['data_rules'][$type]=user_agent_rule_get_v236($pdo,(int)$a['id'],$type);}unset($a);
    $policies=[];foreach(user_agent_resource_catalog_v236() as $type=>$meta){$p=user_data_policy_get_v236($pdo,$uid,$type);$p['label']=$meta['label'];$p['description']=$meta['description'];$policies[]=$p;}
    return ['build'=>STONEFELLOW_USER_AGENT_SYSTEM_V236,'system_agent'=>['display_name'=>system_agent_name(),'kind'=>'system','powers_user_agents'=>true],'agents'=>$agents,'onboarding'=>['show'=>!$agents&&!user_agent_onboarding_dismissed_v236($pdo,$uid)],'policies'=>$policies,'resources'=>user_agent_resource_catalog_v236(),'audiences'=>user_agent_audiences_v236(),'roles'=>user_agent_roles_v236()];
}
