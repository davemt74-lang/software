<?php
declare(strict_types=1);

require_once __DIR__.'/analytics-agent-v1.php';

/** Data only; call after the shared owner/Profile fixture initializes its DB. */
function profile_specialist_analytics_fixture_v1(PDO $pdo): void
{
    foreach(['vp3_agent_registry','vp3_agent_policies','vp3_agent_access_requests'] as $table)
        $pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.'(id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_agent_contacts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name VARCHAR(190),operator_name VARCHAR(190),visitor_class VARCHAR(40))');
    $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
    $pdo->exec($insert." INTO vp3_agent_contacts(id,owner_user_id,display_name,operator_name,visitor_class) VALUES(31,1,'Fixture Crawler','Fixture','ai_crawler'),(32,1,'Fixture Agent','Fixture','ai_user_agent')");
    analytics_agent_fixture_v1($pdo);
}
