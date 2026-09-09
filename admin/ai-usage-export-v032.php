<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_permission('ai.manage');
$pdo=db();if(!$pdo||!ai_usage_accounting_v032_schema_ready($pdo)){http_response_code(503);exit('AI usage accounting is unavailable.');}
$rows=ai_usage_accounting_v032_export($pdo,null);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="vp3-admin-ai-usage-'.date('Y-m-d').'.csv"');
header('Cache-Control: no-store');
$out=fopen('php://output','wb');
fputcsv($out,['when','user_id','agent_id','conversation_id','execution_source','provider','model','input_tokens','output_tokens','total_tokens','vp3_tokens_charged','estimated_cost_usd','cost_rate_source','homeserver_state','fallback_used','failure_class','latency_ms','run_id','trace_id','cloud_ledger_id']);
foreach($rows as $row){$cost=$row['estimated_cost_micros']===null?'':number_format(((int)$row['estimated_cost_micros'])/1000000,6,'.','');fputcsv($out,[(string)$row['created_at'],(int)$row['user_id'],(int)($row['agent_id']??0),(int)($row['conversation_id']??0),(string)$row['source'],(string)$row['provider'],(string)$row['model'],(int)$row['input_tokens'],(int)$row['output_tokens'],(int)$row['total_tokens'],(int)$row['cloud_tokens_charged'],$cost,(string)$row['cost_rate_source'],(string)$row['homeserver_state'],(int)$row['fallback_used'],(string)$row['failure_class'],(int)$row['latency_ms'],(int)($row['run_id']??0),(string)$row['trace_id'],(int)($row['cloud_ledger_id']??0)]);}
fclose($out);