<?php
declare(strict_types=1);
// Exercise the production parser with a host timezone different from the owner.
function agent_scheduling_timezone_v430(string $zone):string{return $zone;}
require dirname(__DIR__).'/includes/agent-scheduling-tools-v460.php';
$checks=0;$original=date_default_timezone_get();
function check(bool $ok,string $label):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label);}
try{
 foreach(['UTC','Asia/Tokyo','America/Los_Angeles'] as $host){
  date_default_timezone_set($host);
  foreach(['America/Phoenix','America/New_York','Pacific/Honolulu','Europe/London','Pacific/Kiritimati'] as $owner){
   $now=new DateTimeImmutable('now',new DateTimeZone($owner));
   foreach(['next Monday','this Wednesday','Friday'] as $request){
    $phrase=$request==='Friday'?'next Friday':$request;
    check(agent_scheduling_tools_date_v460($request,$owner)===$now->modify($phrase)->format('Y-m-d'),'Weekday is interpreted in owner timezone: '.$host.' / '.$owner.' / '.$request);
   }
   check(agent_scheduling_tools_date_v460('January 5, 2030',$owner)==='2030-01-05','Named date does not shift to previous day');
   check(agent_scheduling_tools_date_v460('January 5',$owner)===($now->format('m-d')>'01-05'?(string)((int)$now->format('Y')+1):$now->format('Y')).'-01-05','Yearless month uses the owner year and rolls forward');
   check(agent_scheduling_tools_date_v460('February 30, 2030',$owner)===null,'Invalid named date is not normalized into a different month');
   check(agent_scheduling_tools_date_v460('2026-11-02',$owner)==='2026-11-02','Explicit ISO date remains unchanged');
   check(agent_scheduling_tools_date_v460('tomorrow',$owner)===$now->modify('+1 day')->format('Y-m-d'),'Tomorrow remains relative to the owner date');
   check(agent_scheduling_tools_date_v460('no date',$owner,'2030-04-15')==='2030-04-15','Caller fallback remains supported');
  }
 }
 echo 'AGENT_SCHEDULING_TIMEZONE_V1340=PASS ('.$checks.' assertions; production parser)'.PHP_EOL;
}finally{date_default_timezone_set($original);}
