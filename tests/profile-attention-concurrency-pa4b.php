<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-attention-pa4b.php';$checks=0;
function pa4b_race_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label."\n";}
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'){echo "PROFILE_ATTENTION_CONCURRENCY_PA4B=SKIP (independent row-lock proof requires MySQL)\n";exit;}
$temp=sys_get_temp_dir().'/vp3-attention-race-'.bin2hex(random_bytes(8));mkdir($temp,0700);$config=$temp.'/database.json';file_put_contents($config,json_encode($GLOBALS['pa3Config'],JSON_THROW_ON_ERROR));chmod($config,0600);$workers=[];
try{
    foreach(['same_present','present_then_handle','handle_then_present','double_wake','two_projection_repairs'] as $scenario){
        pa4b_reset();$note=null;$obligation=null;$delivery=null;
        if($scenario==='two_projection_repairs'){
            $message=pp_message();$delivery=pp_delivery($message['turn']);$obligation=pp_obligation($message['event_id'],'brain_archive',(int)$delivery['chat_message_id']);
            $operations=array_fill(0,2,['action'=>'repair','obligation_id'=>(int)$obligation['id']]);
        }else{
            $note=pa4b_note();$present=pa4b_input($note);$handle=pa4b_input($note,['operation'=>'handled']);
            if($scenario==='double_wake'){
                vp3_profile_attention_update_pa4b($pdo,pa4b_owner(),pa4b_input($note,['operation'=>'snooze']));$GLOBALS['pa3Now']+=86400001;$operations=[['action'=>'wake'],['action'=>'wake']];
            }elseif($scenario==='same_present')$operations=[['action'=>'present','input'=>$present],['action'=>'present','input'=>$present]];
            else{$operations=[['action'=>'present','input'=>$present],['action'=>'update','input'=>$handle]];if($scenario==='handle_then_present')$operations=array_reverse($operations);}
        }
        $publicBefore=$pdo->query('SELECT * FROM profile_agent_messages ORDER BY id')->fetchAll();$jobsBefore=pa3_count('agent_workflow_runs');$actionsBefore=pa3_count('agent_workflow_actions');
        $inputPath=$temp.'/input.json';file_put_contents($inputPath,json_encode(['config'=>$config,'now'=>$GLOBALS['pa3Now'],'operations'=>$operations],JSON_THROW_ON_ERROR));chmod($inputPath,0600);
        $start=static function(int $index,bool $hold)use($inputPath,&$workers):array{
            $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/profile-attention-race-pa4b.php',$inputPath,(string)$index,$hold?'hold':'run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Attention race process did not start.');$workers[]=[$process,$pipes];stream_set_timeout($pipes[1],10);$line=(string)fgets($pipes[1]);$ready=json_decode($line,true);
            if(!is_array($ready)){stream_set_blocking($pipes[2],false);throw new RuntimeException('Attention race readiness failed: '.substr($line.stream_get_contents($pipes[2],4096),0,1500));}return [$process,$pipes,$ready];
        };
        [$firstProcess,$firstPipes,$firstReady]=$start(0,true);pa4b_race_check(($firstReady['phase']??'')==='locked',$scenario.': first operation holds its canonical row');
        [$secondProcess,$secondPipes,$secondReady]=$start(1,false);$waiting=false;$q=$pdo->prepare('SELECT 1 FROM performance_schema.data_lock_waits w INNER JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=? LIMIT 1');
        for($i=0;$i<500;$i++){$q->execute([(int)($secondReady['connection']??0)]);if($q->fetchColumn()){$waiting=true;break;}usleep(10000);}
        if(!$waiting){$diag=['scenario'=>$scenario,'first'=>$firstReady,'second'=>$secondReady,'waits'=>$pdo->query('SELECT * FROM performance_schema.data_lock_waits')->fetchAll()];foreach([['first',$firstProcess,$firstPipes],['second',$secondProcess,$secondPipes]] as [$name,$proc,$pipes]){$diag[$name.'_process']=proc_get_status($proc);foreach([1=>'stdout',2=>'stderr'] as $index=>$label){stream_set_blocking($pipes[$index],false);$diag[$name.'_'.$label]=(string)stream_get_contents($pipes[$index],8192);}}fwrite(STDERR,'ATTENTION_MYSQL_RACE_DIAGNOSTIC='.json_encode($diag,JSON_INVALID_UTF8_SUBSTITUTE)."\n");}
        pa4b_race_check($waiting,$scenario.': second independent process waits on actual MySQL lock');fwrite($firstPipes[0],"continue\n");fclose($firstPipes[0]);fclose($secondPipes[0]);
        $outOne=stream_get_contents($firstPipes[1]);$errOne=stream_get_contents($firstPipes[2]);$outTwo=stream_get_contents($secondPipes[1]);$errTwo=stream_get_contents($secondPipes[2]);foreach([$firstPipes,$secondPipes] as $pipes){fclose($pipes[1]);fclose($pipes[2]);}$exitOne=proc_close($firstProcess);$exitTwo=proc_close($secondProcess);
        pa4b_race_check($exitOne===0&&$exitTwo===0,$scenario.': both processes finish without deadlock '.substr($errOne.$errTwo,0,1000));$one=json_decode(trim($outOne),true,512,JSON_THROW_ON_ERROR);$two=json_decode(trim($outTwo),true,512,JSON_THROW_ON_ERROR);
        pa4b_race_check(($one['ok']??false)===true,$scenario.': first operation commits');
        if($scenario==='same_present'){
            pa4b_race_check(($two['ok']??false)&&($two['result']['duplicate']??false)&&$one['result']['chat_message_id']===$two['result']['chat_message_id'],'same present: one idempotent marker result');
            pa4b_race_check(pa3_count('profile_attention_deliveries_pa4b')===1&&pa3_count('profile_attention_operations_pa4b')===1,'same present: exactly one delivery and request receipt');
        }elseif($scenario==='double_wake'){
            pa4b_race_check(($two['ok']??false)&&$one['result']['woken']===1&&$two['result']['woken']===0,'double wake: second process observes already advanced generation');
            $row=pa4b_fresh($note);pa4b_race_check($row['wake_generation']===2&&$row['disposition']==='pending'&&pa3_count('profile_events')===1,'double wake: one new generation without new source event');
        }elseif($scenario==='two_projection_repairs'){
            pa4b_race_check(($two['ok']??false)&&$one['result']['state']==='completed'&&$two['result']['state']==='completed'&&!$one['result']['duplicate']&&$two['result']['duplicate'],'two repairs: one completion and one exact receipt replay');
            $row=vp3_profile_projection_row_pa4b($pdo,(int)$obligation['id']);$q=$pdo->prepare('SELECT id,user_id,user_agent_id,conversation_id,source_message_id,message_text FROM agent_chat_archive WHERE user_id=1 AND source_message_id=?');$q->execute([(int)$delivery['chat_message_id']]);$archive=$q->fetchAll();
            pa4b_race_check(count($archive)===1&&(int)$archive[0]['id']===(int)$row['result_id']&&$row['state']==='completed'&&(int)$row['attempt_count']===1&&(int)$archive[0]['conversation_id']===11&&(int)$archive[0]['user_agent_id']===7,'two repairs: one archive at exact private message and one completed obligation');
        }else{
            pa4b_race_check(($two['ok']??true)===false&&($two['status']??0)===409,$scenario.': stale competing action conflicts');$item=pa4b_fresh($note);
            pa4b_race_check($scenario==='handle_then_present'?($item['disposition']==='handled'&&pa3_count('profile_attention_deliveries_pa4b')===0):($item['disposition']==='pending'&&pa3_count('profile_attention_deliveries_pa4b')===1),$scenario.': terminal winner cannot be revived or silently overwritten');
        }
        pa4b_race_check($pdo->query('SELECT * FROM profile_agent_messages ORDER BY id')->fetchAll()===$publicBefore&&pa3_count('agent_workflow_runs')===$jobsBefore&&pa3_count('agent_workflow_actions')===$actionsBefore,$scenario.': projection never repeats public messages or workflows');
        pa4b_race_check($GLOBALS['pa3ModelCalls']===[]&&$GLOBALS['psProviderCalls']===[]&&$GLOBALS['pa3cProviderCalls']===[],$scenario.': no provider action');
    }
}finally{foreach($workers as [$process,$pipes])if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
echo 'PROFILE_ATTENTION_CONCURRENCY_PA4B=PASS ('.$checks." checks; MySQL)\n";
