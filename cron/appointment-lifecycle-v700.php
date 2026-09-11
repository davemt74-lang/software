<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=db();
if(!$pdo||!agent_appointment_lifecycle_schema_ready_v700($pdo)){
    fwrite(STDERR,"Appointment Lifecycle v7.00 is not ready. Run the VP3 database upgrade first.\n");
    exit(1);
}

try{
    $result=agent_appointment_lifecycle_housekeeping_v700($pdo,500);
    fwrite(STDOUT,json_encode([
        'ok'=>true,
        'version'=>VP3_AGENT_APPOINTMENT_LIFECYCLE_V700,
        'queued'=>(int)($result['queued']??0),
        'processed'=>(int)($result['processed']??0),
        'reconciled'=>(int)($result['reconciled']??0),
        'ran_at_utc'=>gmdate(DATE_ATOM),
    ],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,'Appointment lifecycle automation failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
