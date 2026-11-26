<?php
declare(strict_types=1);
require __DIR__.'/operator-scheduled-assignment-v1.php';

$history=static fn(array $scope)=>detail($id,1,$scope)['schedules']['history'];
$original=['conversation_id'=>11];
c(array_column($history($original),'state')===['missed','prepared','blocked'],'recurring outcomes have a stable newest-first sequence');
c($history(['conversation_id'=>12,'agent_id'=>7])===[],'another private Chat cannot read the original schedule receipts');
$pdo->prepare('UPDATE phone_operator_scheduled_assignments_v1 SET conversation_id=12,chat_agent_id=7,revision=revision+1 WHERE event_id=?')->execute([$eventId]);
c($history(['conversation_id'=>12,'agent_id'=>7])===[],'reassigning a series cannot move historical receipts');
$pdo->prepare("UPDATE user_calendar_events SET status='cancelled' WHERE id=?")->execute([$eventId]);
c(count($history($original))===3,'history survives cancellation');
echo "PASS private recurring schedule history\n";
