<?php
declare(strict_types=1);

function artist_shows_v184_schema_ready(): bool
{
    return table_exists('artist_catalog_shows_v181')
        && column_exists('artist_catalog_shows_v181','event_name')
        && column_exists('artist_catalog_shows_v181','show_status');
}

function artist_shows_v184_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo) throw new RuntimeException('Database connection is unavailable.');
    artist_workspace_v181_ensure_schema($pdo);
    if(!column_exists('artist_catalog_shows_v181','event_name')) $pdo->exec("ALTER TABLE artist_catalog_shows_v181 ADD COLUMN event_name VARCHAR(190) NOT NULL DEFAULT '' AFTER source_show_id");
    if(!column_exists('artist_catalog_shows_v181','show_status')) $pdo->exec("ALTER TABLE artist_catalog_shows_v181 ADD COLUMN show_status VARCHAR(30) NOT NULL DEFAULT 'scheduled' AFTER ticket_url");
}

function artist_shows_v184_statuses(): array
{
    return ['scheduled'=>'Scheduled','postponed'=>'Postponed','cancelled'=>'Cancelled'];
}

function artist_shows_v184_show(PDO $pdo,int $workspaceId,int $showId): ?array
{
    if($workspaceId<1 || $showId<1) return null;
    $stmt=$pdo->prepare('SELECT * FROM artist_catalog_shows_v181 WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$showId,$workspaceId]);
    return $stmt->fetch()?:null;
}

function artist_shows_v184_list(PDO $pdo,int $workspaceId,string $filter='upcoming',int $limit=250): array
{
    $limit=max(1,min(500,$limit));
    $where='workspace_id=?';$params=[$workspaceId];
    if($filter==='upcoming') $where.=' AND show_date>=NOW() AND is_published=1';
    elseif($filter==='past') $where.=' AND show_date<NOW() AND is_published=1';
    elseif($filter==='draft') $where.=' AND is_published=0';
    $order=$filter==='past'?'show_date DESC':'show_date ASC';
    $stmt=$pdo->prepare("SELECT * FROM artist_catalog_shows_v181 WHERE {$where} ORDER BY {$order},id DESC LIMIT {$limit}");
    $stmt->execute($params);
    return $stmt->fetchAll()?:[];
}

function artist_shows_v184_counts(PDO $pdo,int $workspaceId): array
{
    $stmt=$pdo->prepare('SELECT SUM(is_published=1 AND show_date>=NOW()) AS upcoming_count,SUM(is_published=1 AND show_date<NOW()) AS past_count,SUM(is_published=0) AS draft_count FROM artist_catalog_shows_v181 WHERE workspace_id=?');
    $stmt->execute([$workspaceId]);$row=$stmt->fetch()?:[];
    return ['upcoming'=>(int)($row['upcoming_count']??0),'past'=>(int)($row['past_count']??0),'draft'=>(int)($row['draft_count']??0)];
}
