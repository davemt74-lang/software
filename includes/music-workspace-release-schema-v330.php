<?php
declare(strict_types=1);

/** Install the existing v105 release graph through the canonical VP3 upgrade path. */
const VP3_MUSIC_WORKSPACE_RELEASE_SCHEMA_V330='music-workspace-release-schema-v330-20260909';

function music_workspace_release_schema_v330_ready(): bool
{
    return table_exists('release_plans')
        && table_exists('release_items')
        && table_exists('agent_resources')
        && table_exists('release_item_resources')
        && table_exists('agent_integrations')
        && table_exists('agent_work_actions')
        && table_exists('track_credits');
}

function music_workspace_release_schema_v330_ensure(PDO $pdo): void
{
    if(music_workspace_release_schema_v330_ready())return;
    $path=STONEFELLOW_ROOT.'/upgrade-stonefellow-v105.sql';
    $sql=@file_get_contents($path);
    if(!is_string($sql)||trim($sql)==='')throw new RuntimeException('Release Operations schema migration is unavailable.');
    $statements=preg_split('/;\s*(?:\R|$)/',$sql)?:[];
    foreach($statements as $statement){
        $statement=trim($statement);
        if($statement==='')continue;
        $pdo->exec($statement);
    }
    if(!music_workspace_release_schema_v330_ready())throw new RuntimeException('Release Operations schema could not be installed.');
}
