<?php
declare(strict_types=1);

function artist_posts_v183_schema_ready(): bool
{
    return table_exists('artist_posts_v181')
        && column_exists('artist_posts_v181','image_photo_id');
}

function artist_posts_v183_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo) throw new RuntimeException('Database connection is unavailable.');
    artist_workspace_v181_ensure_schema($pdo);
    artist_media_v182_ensure_schema($pdo);
    if(!column_exists('artist_posts_v181','image_photo_id')) {
        $pdo->exec("ALTER TABLE artist_posts_v181 ADD COLUMN image_photo_id BIGINT UNSIGNED NULL AFTER image_path, ADD INDEX idx_artist_post_photo (workspace_id,image_photo_id)");
    }
}

function artist_posts_v183_post(PDO $pdo,int $workspaceId,int $postId): ?array
{
    if($workspaceId<1 || $postId<1) return null;
    $stmt=$pdo->prepare('SELECT * FROM artist_posts_v181 WHERE id=? AND workspace_id=? LIMIT 1');
    $stmt->execute([$postId,$workspaceId]);
    return $stmt->fetch()?:null;
}

function artist_posts_v183_validate_photo(PDO $pdo,int $workspaceId,int $photoId): int
{
    if($photoId<1) return 0;
    if(!artist_media_v182_photo($pdo,$workspaceId,$photoId)) {
        throw new RuntimeException('Selected post image is not in your artist media library.');
    }
    return $photoId;
}

function artist_posts_v183_list(PDO $pdo,int $workspaceId,string $filter='all',int $limit=250): array
{
    $limit=max(1,min(500,$limit));
    $where='workspace_id=?';
    if($filter==='published') $where.=' AND is_published=1';
    elseif($filter==='draft') $where.=' AND is_published=0';
    $stmt=$pdo->prepare("SELECT * FROM artist_posts_v181 WHERE {$where} ORDER BY COALESCE(published_at,updated_at) DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$workspaceId]);
    return $stmt->fetchAll()?:[];
}

function artist_posts_v183_public_image(PDO $pdo,int $postId,?array $viewer=null): ?array
{
    if($postId<1) return null;
    $stmt=$pdo->prepare(
        'SELECT p.id AS post_id,p.workspace_id,p.visibility,p.is_published,p.image_photo_id,ph.image_path
         FROM artist_posts_v181 p
         INNER JOIN artist_catalog_photos_v181 ph ON ph.id=p.image_photo_id AND ph.workspace_id=p.workspace_id
         WHERE p.id=? LIMIT 1'
    );
    $stmt->execute([$postId]);
    $row=$stmt->fetch();
    if(!$row || (int)$row['is_published']!==1 || !can_view_visibility((string)$row['visibility'],$viewer)) return null;
    $path=artist_media_v182_resolve_stored_photo((int)$row['workspace_id'],(string)$row['image_path']);
    if(!$path) return null;
    return ['path'=>$path,'workspace_id'=>(int)$row['workspace_id']];
}
