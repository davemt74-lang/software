<?php
declare(strict_types=1);
// Extend the existing durable SQLite Research fixture and its current-authority gates.
require __DIR__.'/chat-research-continuity-r3.php';
require_once __DIR__.'/../includes/project-knowledge-r6.php';
$all=['mode'=>'all','project_id'=>'','folder_id'=>0];$project=['mode'=>'project','project_id'=>'project1','folder_id'=>0];
$reports=knowledge_r6_reports($pdo,$user,'system',$project,'studio');
check(count($reports)===2&&$reports[0]['kind']==='report','Project context lost the canonical saved reports.');
check(str_contains($reports[0]['excerpt'],'UNTRUSTED')&&str_contains($reports[0]['excerpt'],'[C1]')&&str_contains($reports[0]['excerpt'],'version1'),'Saved report context lost trust boundary or pinned evidence.');
check(knowledge_r6_reports($pdo,$user,'other',$all)===[],'Report attachments crossed the lead namespace.');
check(knowledge_r6_reports($pdo,$user,'system',['mode'=>'project','project_id'=>'other','folder_id'=>0])===[],'Report context crossed the project.');
check(knowledge_r6_reports($pdo,$user,'system',['mode'=>'folder','project_id'=>'','folder_id'=>1])===[],'Reports without a canonical folder were inferred into a folder.');
check(knowledge_r6_reports($pdo,$user,'system',['mode'=>'off','project_id'=>'','folder_id'=>0])===[],'Knowledge off retrieved research.');
$old=$reports[0]['revision'];$pdo->exec("UPDATE reports SET updated_at='newer'");check(knowledge_r6_reports($pdo,$user,'system',$project)[0]['revision']!==$old,'Report revisions were not canonical.');
$pdo->exec('UPDATE projects SET allowed=0');check(knowledge_r6_reports($pdo,$user,'system',$project)===[],'Revoked project remained available for attachment.');$pdo->exec('UPDATE projects SET allowed=1');
$allowed=false;check(knowledge_r6_reports($pdo,$user,'system',$project)===[],'Revoked browser grant remained available for attachment.');
echo "PASS: R6 saved-report attachment projection, exact lead/project scope, original receipt/version citations, canonical revision updates and current project/browser revocation.\n";
