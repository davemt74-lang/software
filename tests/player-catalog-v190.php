<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

function player_catalog_v190_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$platform = [
    ['id'=>1, 'title'=>'Platform One', 'visibility'=>'public'],
    ['id'=>2, 'title'=>'Platform Two', 'visibility'=>'members'],
];
$artist = [
    ['id'=>101, 'source_track_id'=>1, 'title'=>'Stale migration shadow'],
    ['id'=>102, 'source_track_id'=>null, 'title'=>'Native artist release'],
];

foreach (['fan', 'admin'] as $accountType) {
    $catalog = merge_player_track_catalogs($platform, $artist);
    player_catalog_v190_assert(count($catalog) === 3, "{$accountType}: shared player catalog is incomplete");
    player_catalog_v190_assert(array_column($catalog, 'id') === [1, 2, 1000000102], "{$accountType}: player ids are unstable");
    player_catalog_v190_assert($catalog[0]['title'] === 'Platform One', "{$accountType}: migration shadow replaced platform source");
}

$workspaceOnly = merge_player_track_catalogs([], $artist);
player_catalog_v190_assert(array_column($workspaceOnly, 'id') === [1, 1000000102], 'workspace-only catalog lost valid releases');

echo "PLAYER_CATALOG_V190_PHP=PASS\n";
