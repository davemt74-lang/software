<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=db();if(!$pdo){fwrite(STDERR,"Database unavailable.\n");exit(1);}
if(!agent_paid_appointments_schema_ready_v800($pdo)){fwrite(STDERR,"Appointment Commerce is not installed. Run the VP3 database upgrade.\n");exit(2);}
try{$result=agent_paid_appointments_housekeeping_v800($pdo,500);echo json_encode(['ok'=>true,'phase'=>'appointment-commerce-v800']+$result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,'Appointment Commerce housekeeping failed: '.$e->getMessage().PHP_EOL);exit(3);}
