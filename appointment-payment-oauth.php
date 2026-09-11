<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$query=$_SERVER['QUERY_STRING']??'';
redirect(url('/commerce-provider-oauth.php').($query!==''?'?'.$query:''));
