<?php
declare(strict_types=1);

const STONEFELLOW_USER_DATA_USAGE_V236='user-data-usage-v236-20260903';

function user_data_usage_schema_ready_v236(?PDO $pdo=null): bool
{
    $pdo ??= db();
    return (bool)$pdo && table_exists('user_data_retrieval_log');
}

function user_data_usage_ensure_schema_v236(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_data_retrieval_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      requester_user_id INT UNSIGNED NULL,
      agent_id BIGINT UNSIGNED NULL,
      agent_kind VARCHAR(30) NOT NULL DEFAULT 'system',
      agent_name_snapshot VARCHAR(190) NOT NULL DEFAULT '',
      resource_type VARCHAR(50) NOT NULL,
      resource_id VARCHAR(100) NOT NULL DEFAULT '',
      resource_title_snapshot VARCHAR(255) NOT NULL DEFAULT '',
      source_key VARCHAR(190) NOT NULL DEFAULT '',
      access_class VARCHAR(40) NOT NULL DEFAULT 'shared_network',
      conversation_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user_data_usage_owner_time (owner_user_id,created_at,id),
      INDEX idx_user_data_usage_requester_time (requester_user_id,created_at,id),
      INDEX idx_user_data_usage_resource (resource_type,resource_id,created_at,id),
      INDEX idx_user_data_usage_class_time (access_class,created_at,id),
      INDEX idx_user_data_usage_agent_time (agent_id,created_at,id),
      CONSTRAINT fk_user_data_usage_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_user_data_usage_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_user_data_usage_agent FOREIGN KEY (agent_id) REFERENCES user_agents(id) ON DELETE SET NULL,
      CONSTRAINT fk_user_data_usage_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function user_data_usage_access_class_v236(array $principal,int $ownerUserId): string
{
    $kind=(string)($principal['kind']??'system');
    $viewer=(int)($principal['viewer_user_id']??0);
    $principalOwner=(int)($principal['owner_user_id']??0);
    if($kind==='profile_agent') return $principalOwner===$ownerUserId?'profile_agent':'shared_network';
    if($kind==='user_agent') return $principalOwner===$ownerUserId?'owner_agent':'shared_network';
    if($kind==='system' && $viewer>0 && $viewer===$ownerUserId) return 'owner_system';
    return 'shared_network';
}

function user_data_usage_log_v236(PDO $pdo,array $principal,int $ownerUserId,string $resourceType,string $resourceId,string $resourceTitle,string $sourceKey,int $conversationId=0): void
{
    if($ownerUserId<1 || !user_data_usage_schema_ready_v236($pdo)) return;
    static $seen=[];
    $agentId=max(0,(int)($principal['agent_id']??0));
    $requesterId=max(0,(int)($principal['viewer_user_id']??0));
    $key=$ownerUserId.'|'.$requesterId.'|'.$agentId.'|'.$resourceType.'|'.$resourceId.'|'.$conversationId;
    if(isset($seen[$key])) return;
    $seen[$key]=true;
    $accessClass=user_data_usage_access_class_v236($principal,$ownerUserId);
    try{
        $stmt=$pdo->prepare('INSERT INTO user_data_retrieval_log (owner_user_id,requester_user_id,agent_id,agent_kind,agent_name_snapshot,resource_type,resource_id,resource_title_snapshot,source_key,access_class,conversation_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
          $ownerUserId,
          $requesterId>0?$requesterId:null,
          $agentId>0?$agentId:null,
          mb_strimwidth((string)($principal['kind']??'system'),0,30,''),
          mb_strimwidth((string)($principal['display_name']??system_agent_name()),0,190,''),
          mb_strimwidth($resourceType,0,50,''),
          mb_strimwidth($resourceId,0,100,''),
          mb_strimwidth(trim($resourceTitle),0,255,''),
          mb_strimwidth($sourceKey,0,190,''),
          $accessClass,
          $conversationId>0?$conversationId:null,
        ]);
    }catch(Throwable $e){
        // Retrieval itself should remain available if telemetry storage has a transient failure.
    }
}

function user_data_usage_resource_label_v236(string $type): string
{
    $catalog=function_exists('user_agent_resource_catalog_v236')?user_agent_resource_catalog_v236():[];
    return (string)($catalog[$type]['label']??ucfirst(str_replace('_',' ',$type)));
}

function user_data_usage_owner_state_v236(PDO $pdo,int $ownerUserId,int $limit=30): array
{
    if($ownerUserId<1 || !user_data_usage_schema_ready_v236($pdo)) return ['total'=>0,'shared_total'=>0,'last_30_days'=>0,'by_resource'=>[],'recent'=>[]];
    $summary=$pdo->prepare("SELECT COUNT(*) total,SUM(access_class='shared_network') shared_total,SUM(created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) last_30_days FROM user_data_retrieval_log WHERE owner_user_id=?");
    $summary->execute([$ownerUserId]);$s=$summary->fetch()?:[];
    $by=$pdo->prepare("SELECT resource_type,COUNT(*) uses,SUM(access_class='shared_network') shared_uses,MAX(created_at) last_used_at FROM user_data_retrieval_log WHERE owner_user_id=? GROUP BY resource_type ORDER BY uses DESC,resource_type ASC");
    $by->execute([$ownerUserId]);$byRows=[];
    foreach($by->fetchAll()?:[] as $row){$row['label']=user_data_usage_resource_label_v236((string)$row['resource_type']);$byRows[]=$row;}
    $recent=$pdo->prepare("SELECT id,agent_kind,agent_name_snapshot,resource_type,resource_id,resource_title_snapshot,access_class,created_at FROM user_data_retrieval_log WHERE owner_user_id=? ORDER BY id DESC LIMIT ".max(1,min(100,$limit)));
    $recent->execute([$ownerUserId]);$recentRows=[];
    foreach($recent->fetchAll()?:[] as $row){
        $row['resource_label']=user_data_usage_resource_label_v236((string)$row['resource_type']);
        $row['consumer_label']=match((string)$row['access_class']){
          'shared_network'=>'System/network AI response',
          'owner_agent'=>(string)$row['agent_name_snapshot'],
          'profile_agent'=>(string)$row['agent_name_snapshot'].' profile interaction',
          default=>system_agent_name().' private session',
        };
        // Owner-facing telemetry intentionally omits requester identity and conversation identifiers.
        $recentRows[]=$row;
    }
    return ['total'=>(int)($s['total']??0),'shared_total'=>(int)($s['shared_total']??0),'last_30_days'=>(int)($s['last_30_days']??0),'by_resource'=>$byRows,'recent'=>$recentRows];
}

function user_data_usage_admin_state_v236(PDO $pdo,int $limit=100): array
{
    if(!user_data_usage_schema_ready_v236($pdo)) return ['total'=>0,'shared_total'=>0,'last_30_days'=>0,'owners'=>0,'recent'=>[]];
    $s=$pdo->query("SELECT COUNT(*) total,SUM(access_class='shared_network') shared_total,SUM(created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) last_30_days,COUNT(DISTINCT owner_user_id) owners FROM user_data_retrieval_log")->fetch()?:[];
    $sql="SELECT l.id,l.owner_user_id,l.requester_user_id,l.agent_id,l.agent_kind,l.agent_name_snapshot,l.resource_type,l.resource_id,l.resource_title_snapshot,l.source_key,l.access_class,l.conversation_id,l.created_at,ou.display_name owner_name,ou.email owner_email,ru.display_name requester_name,ru.email requester_email FROM user_data_retrieval_log l INNER JOIN users ou ON ou.id=l.owner_user_id LEFT JOIN users ru ON ru.id=l.requester_user_id ORDER BY l.id DESC LIMIT ".max(1,min(500,$limit));
    $rows=$pdo->query($sql)->fetchAll()?:[];
    foreach($rows as &$row)$row['resource_label']=user_data_usage_resource_label_v236((string)$row['resource_type']);
    unset($row);
    return ['total'=>(int)($s['total']??0),'shared_total'=>(int)($s['shared_total']??0),'last_30_days'=>(int)($s['last_30_days']??0),'owners'=>(int)($s['owners']??0),'recent'=>$rows];
}
