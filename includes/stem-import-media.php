<?php
declare(strict_types=1);

// Use the same file validation as playback before publishing imported rows.
if (!defined('STONEFELLOW_STEM_MEDIA_LIBRARY_ONLY')) define('STONEFELLOW_STEM_MEDIA_LIBRARY_ONLY', true);
require_once dirname(__DIR__).'/stem-media-v34.php';

function stem_import_validate_staged_media(PDO $pdo,int $trackId,int $projectId,array $ids,int $expected): void
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));
    if($expected<1||count($ids)!==$expected)throw new RuntimeException('Staged import rows are incomplete.');
    $q=$pdo->prepare('SELECT id,file_path FROM track_stems WHERE track_id=? AND project_id=? AND is_active=0 AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
    $q->execute([$trackId,$projectId,...$ids]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==$expected)throw new RuntimeException('Staged audio belongs to another import or is no longer available.');
    foreach($rows as $row){
        $resolved=stem_media_v229_resolve_path(STONEFELLOW_ROOT,(string)$row['file_path']);
        if(empty($resolved['ok']))throw new RuntimeException('An imported audio file is missing from final storage. The existing project has been kept.');
        clearstatcache(true,(string)$resolved['path']);
        $inspection=stem_media_v229_inspect_file((string)$resolved['path']);
        if(empty($inspection['ok']))throw new RuntimeException('An imported audio file is empty or cannot be played. The existing project has been kept.');
    }
}
