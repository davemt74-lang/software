<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-calls-pa3c.php';
$checks=0;
function pa3c_race_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label."\n";
}
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'){
    echo "PROFILE_CALLS_CONCURRENCY_PA3C=SKIP (independent row-lock proof requires MySQL)\n";
    exit;
}
$temp=sys_get_temp_dir().'/vp3-pa3c-race-'.bin2hex(random_bytes(8));mkdir($temp,0700);
$config=$temp.'/database.json';file_put_contents($config,json_encode($GLOBALS['pa3Config'],JSON_THROW_ON_ERROR));chmod($config,0600);
$workers=[];
try{
    foreach(['first_visitor','second_visitor','same_request'] as $scenario){
        pa3c_reset();
        $one=pa3c_offer('visitor',pa3c_context(0,'b'));
        $two=$scenario==='same_request'?$one:pa3c_offer('visitor',pa3c_context(0,'c'));
        $inputOne=pa3c_owner_input($one['call'],'accept');
        $inputTwo=$scenario==='same_request'?$inputOne:pa3c_owner_input($two['call'],'accept');
        $operations=$scenario==='second_visitor'?[$inputTwo,$inputOne]:[$inputOne,$inputTwo];
        $winner=$scenario==='second_visitor'?$two:$one;
        $inputPath=$temp.'/input.json';
        file_put_contents($inputPath,json_encode(['config'=>$config,'now'=>$GLOBALS['pa3Now'],'operations'=>$operations],JSON_THROW_ON_ERROR));chmod($inputPath,0600);
        $start=static function(int $index,bool $hold)use($inputPath,&$workers):array{
            $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/profile-calls-race-pa3c.php',$inputPath,(string)$index,$hold?'hold':'run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Call race process did not start.');
            $workers[]=[$process,$pipes];stream_set_timeout($pipes[1],10);
            $line=(string)fgets($pipes[1]);$ready=json_decode($line,true);
            if(!is_array($ready)){
                stream_set_blocking($pipes[2],false);$error=(string)stream_get_contents($pipes[2],4096);
                throw new RuntimeException('Call race process did not report readiness: '.substr($line.$error,0,1500));
            }
            return [$process,$pipes,$ready];
        };
        [$firstProcess,$firstPipes,$firstReady]=$start(0,true);
        pa3c_race_check(($firstReady['phase']??'')==='locked',$scenario.': first accepted operation holds its real transaction '.(($firstReady['phase']??'')==='locked'?'':json_encode($firstReady,JSON_INVALID_UTF8_SUBSTITUTE)));
        [$secondProcess,$secondPipes,$secondReady]=$start(1,false);
        $waiting=false;
        $q=$pdo->prepare('SELECT 1 FROM performance_schema.data_lock_waits w INNER JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=? LIMIT 1');
        for($i=0;$i<500;$i++){$q->execute([(int)($secondReady['connection']??0)]);if($q->fetchColumn()){$waiting=true;break;}usleep(10000);}
        if(!$waiting){
            $diagnostic=['scenario'=>$scenario,'first'=>$firstReady,'second'=>$secondReady];
            foreach([['first',$firstProcess,$firstPipes],['second',$secondProcess,$secondPipes]] as [$name,$process,$pipes]){
                $diagnostic[$name.'_process']=proc_get_status($process);
                foreach([1=>'stdout',2=>'stderr'] as $index=>$label){stream_set_blocking($pipes[$index],false);$diagnostic[$name.'_'.$label]=(string)stream_get_contents($pipes[$index],8192);}
            }
            fwrite(STDERR,'PA3C_MYSQL_RACE_DIAGNOSTIC='.json_encode($diagnostic,JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }
        pa3c_race_check($waiting,$scenario.': competing acceptance waits on an actual MySQL row lock');
        fwrite($firstPipes[0],"commit\n");fclose($firstPipes[0]);fclose($secondPipes[0]);
        $outOne=stream_get_contents($firstPipes[1]);$errOne=stream_get_contents($firstPipes[2]);
        $outTwo=stream_get_contents($secondPipes[1]);$errTwo=stream_get_contents($secondPipes[2]);
        foreach([$firstPipes,$secondPipes] as $pipes){fclose($pipes[1]);fclose($pipes[2]);}
        $exitOne=proc_close($firstProcess);$exitTwo=proc_close($secondProcess);
        pa3c_race_check($exitOne===0&&$exitTwo===0,$scenario.': independent accepts finish without deadlock '.substr($errOne.$errTwo,0,1000));
        $resultOne=json_decode(trim($outOne),true,512,JSON_THROW_ON_ERROR);
        $resultTwo=json_decode(trim($outTwo),true,512,JSON_THROW_ON_ERROR);
        pa3c_race_check(($resultOne['ok']??false)===true,$scenario.': committed first accept succeeds');
        $q=$pdo->prepare('SELECT * FROM profile_calls_pa3c WHERE conversation_id=?');$q->execute([(int)$winner['conversation_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $slot=$pdo->query('SELECT active_call_id FROM profile_call_slots_pa3c WHERE owner_user_id=1')->fetchColumn();
        pa3c_race_check($row&&$row['state']==='preparing'&&(int)$slot===(int)$row['id']&&pa3_count('profile_calls_pa3c',"state IN ('preparing','ready','active','ending')")===1,$scenario.': exactly one owner slot and accepted call survive');
        if($scenario==='same_request'){
            pa3c_race_check(($resultTwo['ok']??false)===true&&pa3_count('profile_call_operations_pa3c',"operation='accept'")===1,'same request: concurrent retry returns its original acceptance once');
        }else{
            pa3c_race_check(($resultTwo['ok']??true)===false&&is_string($resultTwo['code']??null),$scenario.': competing visitor receives a conflict');
        }
        pa3c_race_check(pa3_count('profile_agent_messages',"sender_type IN ('visitor','agent','owner')")===0&&pa3_count('agent_workflow_runs')===0,$scenario.': calling creates no synthetic text, model task or cleanup before provider work');
    }
}finally{
    foreach($workers as [$process,$pipes])if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
    foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);
}
echo 'PROFILE_CALLS_CONCURRENCY_PA3C=PASS ('.$checks." checks; MySQL)\n";
