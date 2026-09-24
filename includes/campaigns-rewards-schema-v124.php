<?php
declare(strict_types=1);

const VP3_CAMPAIGNS_REWARDS_SCHEMA_V124='vp3-campaigns-rewards-schema-v124-20260923';

function campaigns_rewards_journey_operations_schema_ready_v124(?PDO $pdo=null): bool
{
    $pdo??=db();return $pdo&&table_exists('campaign_journey_instances');
}

function campaigns_rewards_journey_operations_ensure_schema_v124(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_journey_instances (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      campaign_id BIGINT UNSIGNED NOT NULL,
      journey_id BIGINT UNSIGNED NOT NULL,
      journey_version_id BIGINT UNSIGNED NOT NULL,
      contact_id BIGINT UNSIGNED NOT NULL,
      instance_key VARCHAR(96) NOT NULL,
      trigger_event VARCHAR(80) NOT NULL,
      trigger_event_id VARCHAR(190) NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'active',
      current_step_key VARCHAR(80) NULL,
      metadata_json LONGTEXT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      paused_at DATETIME NULL,
      completed_at DATETIME NULL,
      cancelled_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_journey_instance_public (public_id),
      UNIQUE KEY uq_campaign_journey_instance_key (instance_key),
      INDEX idx_campaign_journey_instance_journey (journey_id,status,last_activity_at,id),
      INDEX idx_campaign_journey_instance_contact (contact_id,status,id),
      INDEX idx_campaign_journey_instance_version (journey_version_id,status,id),
      INDEX idx_campaign_journey_instance_campaign (campaign_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    campaigns_rewards_journey_operations_backfill_v124($pdo);
}

function campaigns_rewards_journey_operations_backfill_v124(PDO $pdo): void
{
    if(!table_exists('campaign_deliveries')||!table_exists('campaign_journeys'))return;
    $q=$pdo->query("SELECT d.campaign_id,d.contact_id,d.metadata_json,d.created_at
      FROM campaign_deliveries d
      WHERE d.metadata_json LIKE '%\"runtime\":\"v1.23\"%' ORDER BY d.id");
    foreach($q?$q->fetchAll():[] as $row){
        $m=json_decode((string)$row['metadata_json'],true);if(!is_array($m))continue;
        $key=(string)($m['journey_instance_key']??'');$journeyId=max(0,(int)($m['journey_id']??0));$versionId=max(0,(int)($m['journey_version_id']??0));
        if($key===''||$journeyId<1||$versionId<1)continue;
        $pdo->prepare("INSERT INTO campaign_journey_instances
          (public_id,campaign_id,journey_id,journey_version_id,contact_id,instance_key,trigger_event,trigger_event_id,status,current_step_key,metadata_json,started_at,last_activity_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?)
          ON DUPLICATE KEY UPDATE last_activity_at=GREATEST(last_activity_at,VALUES(last_activity_at)),current_step_key=COALESCE(VALUES(current_step_key),current_step_key)")
          ->execute([
            campaigns_rewards_uuid_v100(),(int)$row['campaign_id'],$journeyId,$versionId,(int)$row['contact_id'],$key,
            (string)($m['trigger_event']??'manual'),(string)($m['trigger_event_id']??''),'active',(string)($m['step_key']??''),
            campaigns_rewards_json_v100(['source'=>'v123_backfill']),(string)$row['created_at'],(string)$row['created_at']
          ]);
    }
}
