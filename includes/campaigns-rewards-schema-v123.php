<?php
declare(strict_types=1);

/**
 * Campaigns & Rewards V1.23 — canonical journey release schema.
 *
 * The release model is intentionally small:
 * - campaign_journeys identifies one logical journey inside a Campaign.
 * - campaign_journey_versions stores immutable published/scheduled snapshots and
 *   the one mutable draft snapshot.
 * - campaign_journey_publications is the append-only publication/rollback audit.
 *
 * Node definitions remain campaign_messages. Execution remains
 * campaign_deliveries. In-flight version pins are stored in delivery metadata.
 */
const VP3_CAMPAIGNS_REWARDS_SCHEMA_V123='vp3-campaigns-rewards-schema-v123-20260923';

function campaigns_rewards_journey_release_schema_ready_v123(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach(['campaign_journeys','campaign_journey_versions','campaign_journey_publications'] as $table)
        if(!table_exists($table))return false;
    return true;
}

function campaigns_rewards_journey_release_ensure_schema_v123(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_journeys (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      campaign_id BIGINT UNSIGNED NOT NULL,
      journey_key VARCHAR(80) NOT NULL,
      name VARCHAR(190) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'draft',
      enrollment_status VARCHAR(30) NOT NULL DEFAULT 'open',
      current_draft_version_id BIGINT UNSIGNED NULL,
      current_published_version_id BIGINT UNSIGNED NULL,
      created_by_user_id INT UNSIGNED NULL,
      archived_by_user_id INT UNSIGNED NULL,
      archived_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_journey_public (public_id),
      UNIQUE KEY uq_campaign_journey_key (campaign_id,journey_key),
      INDEX idx_campaign_journey (campaign_id,status,enrollment_status,id),
      INDEX idx_campaign_journey_publish (current_published_version_id),
      INDEX idx_campaign_journey_draft (current_draft_version_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_journey_versions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      journey_id BIGINT UNSIGNED NOT NULL,
      version_no INT UNSIGNED NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'draft',
      graph_json LONGTEXT NOT NULL,
      validation_json LONGTEXT NULL,
      release_notes TEXT NULL,
      based_on_version_id BIGINT UNSIGNED NULL,
      created_by_user_id INT UNSIGNED NULL,
      published_by_user_id INT UNSIGNED NULL,
      scheduled_publish_at DATETIME NULL,
      published_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_journey_version_public (public_id),
      UNIQUE KEY uq_campaign_journey_version (journey_id,version_no),
      INDEX idx_campaign_journey_version_status (journey_id,status,version_no),
      INDEX idx_campaign_journey_scheduled (status,scheduled_publish_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_journey_publications (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      journey_id BIGINT UNSIGNED NOT NULL,
      journey_version_id BIGINT UNSIGNED NOT NULL,
      previous_journey_version_id BIGINT UNSIGNED NULL,
      action VARCHAR(40) NOT NULL,
      inflight_policy VARCHAR(30) NOT NULL DEFAULT 'continue',
      release_notes TEXT NULL,
      metadata_json LONGTEXT NULL,
      actor_user_id INT UNSIGNED NULL,
      published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_campaign_journey_publication_public (public_id),
      INDEX idx_campaign_journey_publication (journey_id,published_at,id),
      INDEX idx_campaign_journey_publication_version (journey_version_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    campaigns_rewards_journey_release_backfill_v123($pdo);
}

function campaigns_rewards_journey_release_graph_v123(array $rows,string $journeyKey): array
{
    $nodes=[];
    foreach($rows as $row){
        $template=json_decode((string)($row['template_json']??''),true);if(!is_array($template))continue;
        if(($template['kind']??'')!=='journey_node'||(string)($template['journey_key']??'')!==$journeyKey)continue;
        $nodes[]=[
            'message_id'=>(int)$row['id'],
            'message_key'=>(string)$row['message_key'],
            'message_version_no'=>(int)$row['version_no'],
            'channel'=>(string)$row['channel'],
            'subject'=>(string)($row['subject']??''),
            'template'=>$template,
        ];
    }
    usort($nodes,static fn(array $a,array $b):int=>[
        (int)($a['template']['step_order']??999),(string)($a['template']['step_key']??''),(string)($a['template']['variant_key']??'default'),(int)$a['message_id']
    ]<=>[
        (int)($b['template']['step_order']??999),(string)($b['template']['step_key']??''),(string)($b['template']['variant_key']??'default'),(int)$b['message_id']
    ]);
    return ['schema'=>'campaign-journey-graph-v1','journey_key'=>$journeyKey,'nodes'=>$nodes];
}

function campaigns_rewards_journey_release_backfill_v123(PDO $pdo): void
{
    if(!table_exists('campaign_messages')||!table_exists('campaigns'))return;
    $q=$pdo->query("SELECT cm.* FROM campaign_messages cm
      INNER JOIN campaigns c ON c.id=cm.campaign_id
      WHERE cm.template_json IS NOT NULL
      ORDER BY cm.campaign_id,cm.message_key,cm.version_no,cm.id");
    $rows=$q?$q->fetchAll():[];if(!$rows)return;

    $groups=[];
    foreach($rows as $row){
        $t=json_decode((string)($row['template_json']??''),true);if(!is_array($t)||($t['kind']??'')!=='journey_node')continue;
        $journeyKey=campaigns_rewards_slug_v100((string)($t['journey_key']??'default'),80)?:'default';
        $groups[(int)$row['campaign_id']][$journeyKey][(string)$row['message_key']][]=$row;
    }

    foreach($groups as $campaignId=>$journeys){
        foreach($journeys as $journeyKey=>$messageGroups){
            $find=$pdo->prepare("SELECT id FROM campaign_journeys WHERE campaign_id=? AND journey_key=? LIMIT 1");
            $find->execute([$campaignId,$journeyKey]);
            if($find->fetchColumn())continue;

            $live=[];$draft=[];
            foreach($messageGroups as $messageRows){
                $publishedCandidate=null;$draftCandidate=null;$latest=null;
                foreach($messageRows as $row){
                    $latest=$row;$status=(string)$row['status'];
                    if(in_array($status,['active','superseded'],true))$publishedCandidate=$row;
                    if(in_array($status,['draft','paused'],true))$draftCandidate=$row;
                }
                if($publishedCandidate)$live[]=$publishedCandidate;
                if($draftCandidate&&(!$publishedCandidate||(int)$draftCandidate['id']!==(int)$publishedCandidate['id']))$draft[]=$draftCandidate;
            }

            $pdo->prepare("INSERT INTO campaign_journeys
              (public_id,campaign_id,journey_key,name,status,enrollment_status,created_at,updated_at)
              VALUES (?,?,?,?,?,'open',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
              ->execute([campaigns_rewards_uuid_v100(),$campaignId,$journeyKey,ucwords(str_replace('-',' ',$journeyKey)),$live?'active':'draft']);
            $journeyId=(int)$pdo->lastInsertId();$versionNo=0;$publishedId=null;

            if($live){
                $versionNo=1;$graph=campaigns_rewards_journey_release_graph_v123($live,$journeyKey);
                $pdo->prepare("INSERT INTO campaign_journey_versions
                  (public_id,journey_id,version_no,status,graph_json,validation_json,release_notes,published_at,created_at,updated_at)
                  VALUES (?,?,?,'published',?,'{}','V1.23 legacy journey adoption',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                  ->execute([campaigns_rewards_uuid_v100(),$journeyId,$versionNo,campaigns_rewards_json_v100($graph)]);
                $publishedId=(int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE campaign_journeys SET current_published_version_id=?,status='active' WHERE id=?")->execute([$publishedId,$journeyId]);
                $pdo->prepare("INSERT INTO campaign_journey_publications
                  (public_id,journey_id,journey_version_id,previous_journey_version_id,action,inflight_policy,release_notes,metadata_json,actor_user_id,published_at)
                  VALUES (?,?,?,NULL,'adopt','continue','V1.23 legacy journey adoption','{}',NULL,UTC_TIMESTAMP())")
                  ->execute([campaigns_rewards_uuid_v100(),$journeyId,$publishedId]);
            }

            if($draft){
                $baseByKey=[];
                foreach($live as $row)$baseByKey[(string)$row['message_key']]=$row;
                foreach($draft as $row)$baseByKey[(string)$row['message_key']]=$row;
                $versionNo=max(1,$versionNo+1);$graph=campaigns_rewards_journey_release_graph_v123(array_values($baseByKey),$journeyKey);
                $pdo->prepare("INSERT INTO campaign_journey_versions
                  (public_id,journey_id,version_no,status,graph_json,validation_json,release_notes,based_on_version_id,created_at,updated_at)
                  VALUES (?,?,?,'draft',?,'{}','Migrated V1.22 draft',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                  ->execute([campaigns_rewards_uuid_v100(),$journeyId,$versionNo,campaigns_rewards_json_v100($graph),$publishedId]);
                $draftId=(int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE campaign_journeys SET current_draft_version_id=? WHERE id=?")->execute([$draftId,$journeyId]);
            }
        }
    }
}
