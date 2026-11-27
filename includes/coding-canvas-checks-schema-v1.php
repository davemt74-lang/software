<?php
declare(strict_types=1);

/** Explicit upgrade only. Reports remain attached to canonical immutable versions. */
function vp3_coding_canvas_checks_schema_v1(PDO $pdo): void
{
    if(vp3_coding_canvas_checks_ready_v1($pdo))return;
    try{$pdo->exec('ALTER TABLE vp3_coding_canvas_versions_v1 ADD COLUMN workflow_result_json LONGTEXT NULL');}
    catch(PDOException $error){if(!vp3_coding_canvas_checks_ready_v1($pdo))throw $error;}
}

function vp3_coding_canvas_checks_ready_v1(PDO $pdo): bool
{
    return schema_metadata_column_exists($pdo,'vp3_coding_canvas_versions_v1','workflow_result_json');
}
