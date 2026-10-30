<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only.\n");}
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=db();
if(!$pdo||!reminder_ready_v1($pdo)){fwrite(STDERR,"Run the VP3 database upgrade to enable reminders.\n");exit(1);}
try{echo json_encode(['ok'=>true,'delivered'=>reminder_tick_v1($pdo,null,null,200),'at'=>gmdate(DATE_ATOM)],JSON_THROW_ON_ERROR).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
