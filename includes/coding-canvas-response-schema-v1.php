<?php
declare(strict_types=1);

function vp3_coding_canvas_response_ready_v1(PDO $pdo): bool
{
    return schema_metadata_column_exists($pdo,'agent_workflow_runs','coding_provider_result_json');
}

/** Explicit upgrade only; received output stays on the canonical private job. */
function vp3_coding_canvas_response_schema_v1(PDO $pdo): void
{
    if(!schema_metadata_table_exists($pdo,'agent_workflow_runs')||vp3_coding_canvas_response_ready_v1($pdo))return;
    try{$pdo->exec('ALTER TABLE agent_workflow_runs ADD COLUMN coding_provider_result_json MEDIUMTEXT NULL');}
    catch(PDOException $error){if(!vp3_coding_canvas_response_ready_v1($pdo))throw $error;}
}
