<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$connector=(string)file_get_contents($root.'/includes/homeserver-scheduling-connector-v620.php');
$endpoint=(string)file_get_contents($root.'/api/homeserver-scheduling-v620.php');
$status=(string)file_get_contents($root.'/api/homeserver-status.php');
foreach(['overview','availability','booking.create','booking.reschedule','booking.cancel','team.availability','team.booking.create','team.booking.cancel'] as $op){
    if(!str_contains($connector,"'{$op}'")){fwrite(STDERR,"Missing bounded operation {$op}\n");exit(1);}
}
foreach(['access_token','refresh_token','client_secret','provider_token'] as $secret){
    if(stripos($endpoint,$secret)!==false){fwrite(STDERR,"Provider secret marker exposed by machine endpoint\n");exit(1);}
}
if(!str_contains($connector,"status ENUM('active','revoked')")){fwrite(STDERR,"Scheduling credential is not revocable\n");exit(1);}
if(!str_contains($connector,'GET_LOCK(?,5)')){fwrite(STDERR,"Scheduling mutation replay lock missing\n");exit(1);}
if(!str_contains($status,'homeserver_scheduling_v620_revoke($userId);')){fwrite(STDERR,"Disconnect revocation missing\n");exit(1);}
echo "HomeServer scheduling v6.20 PHP static safety passed\n";
