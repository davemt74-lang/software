<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';

$query=trim((string)($_SERVER['QUERY_STRING']??''));
$target=url('/team.php').($query!==''?'?'.$query:'');

// My Team is a member-facing VP3 workspace now. Preserve method/body for any
// stale POSTs from an older admin page while moving every request to one UI.
if(!headers_sent()){
    header('Location: '.$target,true,307);
    exit;
}
redirect($target);
