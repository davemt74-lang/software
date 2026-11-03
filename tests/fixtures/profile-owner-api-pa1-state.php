<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$pdo=fixture_pa1_pdo();
header('Content-Type: application/json');
$state=[];foreach(['profile_agent_conversations','profile_agent_messages','profile_visit_sessions','profile_events','agent_attention_items','fixture_owner_mutations'] as $table)$state[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
echo json_encode($state,JSON_THROW_ON_ERROR);
