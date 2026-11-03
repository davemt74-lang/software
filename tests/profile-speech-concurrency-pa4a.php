<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-visitor-speech-pa4a.php';$checks=0;
function ps_race_check(bool $ok,string $name): void{if(!$ok)throw new RuntimeException($name);$GLOBALS['checks']++;echo 'PASS '.$name."\n";}
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'){echo "PROFILE_SPEECH_CONCURRENCY_PA4A=SKIP (independent row-lock proof requires MySQL)\n";exit;}
$temp=sys_get_temp_dir().'/vp3-speech-race-'.bin2hex(random_bytes(8));mkdir($temp,0700);$config=$temp.'/database.json';file_put_contents($config,json_encode($GLOBALS['pa3Config'],JSON_THROW_ON_ERROR));chmod($config,0600);$workers=[];
try{
    foreach(['competing_claims','same_claim','play_then_call','call_then_play'] as $scenario){
        ps_reset();$item=ps_page();$delivery=ps_delivery($item);$claimInput=ps_input($delivery);
        if(in_array($scenario,['competing_claims','same_claim'],true)){
            if($scenario==='same_claim')$second=$claimInput;
            else{$another=ps_page(ps_context(0,'c'));$second=ps_input(ps_delivery($another));}
            $operations=[['action'=>'claim','input'=>$claimInput],['action'=>'claim','input'=>$second]];
        }else{
            $claim=vp3_profile_speech_claim_pa4a($pdo,ps_owner(),$claimInput);$claimInput['revision']=$claim['item']['revision'];$claim['input']=$claimInput+['attempt_id'=>$claim['attempt_id'],'ticket'=>$claim['ticket']];ps_ready($claim);
            $offer=pa3c_offer('visitor',ps_context(2));$accept=pa3c_owner_input($offer,'accept');$play=array_replace($claim['input'],['request_id'=>bin2hex(random_bytes(16))]);
            $operations=[['action'=>'play','input'=>$play],['action'=>'call','input'=>$accept]];if($scenario==='call_then_play')$operations=array_reverse($operations);
        }
        $inputPath=$temp.'/input.json';file_put_contents($inputPath,json_encode(['config'=>$config,'now'=>$GLOBALS['pa3Now'],'operations'=>$operations],JSON_THROW_ON_ERROR));chmod($inputPath,0600);
        $start=static function(int $index,bool $hold)use($inputPath,&$workers):array{
            $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/profile-speech-race-pa4a.php',$inputPath,(string)$index,$hold?'hold':'run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Speech race process did not start.');$workers[]=[$process,$pipes];stream_set_timeout($pipes[1],10);$line=(string)fgets($pipes[1]);$ready=json_decode($line,true);
            if(!is_array($ready)){stream_set_blocking($pipes[2],false);throw new RuntimeException('Speech race process did not report readiness: '.substr($line.stream_get_contents($pipes[2],4096),0,1500));}return [$process,$pipes,$ready];
        };
        [$firstProcess,$firstPipes,$firstReady]=$start(0,true);ps_race_check(($firstReady['phase']??'')==='locked',$scenario.': first operation holds canonical owner call slot');
        [$secondProcess,$secondPipes,$secondReady]=$start(1,false);$waiting=false;$q=$pdo->prepare('SELECT 1 FROM performance_schema.data_lock_waits w INNER JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=? LIMIT 1');
        for($i=0;$i<500;$i++){$q->execute([(int)($secondReady['connection']??0)]);if($q->fetchColumn()){$waiting=true;break;}usleep(10000);}
        if(!$waiting){$diagnostic=['scenario'=>$scenario,'first'=>$firstReady,'second'=>$secondReady];foreach([['first',$firstProcess,$firstPipes],['second',$secondProcess,$secondPipes]] as [$name,$process,$pipes]){$diagnostic[$name.'_process']=proc_get_status($process);foreach([1=>'stdout',2=>'stderr'] as $index=>$label){stream_set_blocking($pipes[$index],false);$diagnostic[$name.'_'.$label]=(string)stream_get_contents($pipes[$index],8192);}}fwrite(STDERR,'SPEECH_MYSQL_RACE_DIAGNOSTIC='.json_encode($diagnostic,JSON_INVALID_UTF8_SUBSTITUTE)."\n");}
        ps_race_check($waiting,$scenario.': competing operation waits on actual MySQL row lock');fwrite($firstPipes[0],"continue\n");fclose($firstPipes[0]);fclose($secondPipes[0]);
        $outOne=stream_get_contents($firstPipes[1]);$errOne=stream_get_contents($firstPipes[2]);$outTwo=stream_get_contents($secondPipes[1]);$errTwo=stream_get_contents($secondPipes[2]);foreach([$firstPipes,$secondPipes] as $pipes){fclose($pipes[1]);fclose($pipes[2]);}$exitOne=proc_close($firstProcess);$exitTwo=proc_close($secondProcess);
        ps_race_check($exitOne===0&&$exitTwo===0,$scenario.': independent operations finish without deadlock '.substr($errOne.$errTwo,0,1000));$one=json_decode(trim($outOne),true,512,JSON_THROW_ON_ERROR);$two=json_decode(trim($outTwo),true,512,JSON_THROW_ON_ERROR);ps_race_check(($one['ok']??false)===true,$scenario.': first operation commits');
        if($scenario==='same_claim'){ps_race_check(($two['ok']??false)===true&&($two['result']['duplicate']??false)===true,'same claim: concurrent replay returns single durable attempt');ps_race_check(pa3_count('profile_speech_attempts_pa4a')===1,'same claim: no duplicate synthesis reservation');}
        elseif($scenario==='competing_claims'){ps_race_check(($two['ok']??true)===false&&($two['code']??'')==='PROFILE_SPEECH_BUSY','competing claims: owner-global speech slot serialized');ps_race_check(pa3_count('profile_speech_attempts_pa4a')===1,'competing claims: only one reservation');}
        else{
            ps_race_check($scenario==='play_then_call'?($two['ok']??false)===true:($two['ok']??true)===false,$scenario.': call preempts or prevents playback');$attempt=vp3_profile_speech_attempt_row_pa4a($pdo,$claim['attempt_id']);ps_race_check($attempt['delivery_state']==='blocked'&&!in_array($attempt['playback_state'],['granted','playing'],true)&&(int)$attempt['play_lease_until_ms']===0,$scenario.': no usable playback grant survives accepted call');
            ps_race_check((int)$pdo->query('SELECT active_call_id FROM profile_call_slots_pa3c WHERE owner_user_id=1')->fetchColumn()===(int)$offer['call_id'],$scenario.': exact human call owns slot');
        }
        ps_race_check(pa3_count('profile_agent_messages',"sender_type IN ('visitor','agent','owner')")===0,$scenario.': narration and calling fabricate no visible public message');
        if(in_array($scenario,['play_then_call','call_then_play'],true)){
            // A call-only native conversation has one hidden authority binding,
            // not a visitor/Agent/owner message or an inference task.
            $messages=$pdo->query('SELECT conversation_id,sender_type,sender_user_id,message,context_json FROM profile_agent_messages')->fetchAll(PDO::FETCH_ASSOC);
            $binding=count($messages)===1?json_decode((string)$messages[0]['context_json'],true):null;
            $expected=$offer['context']['conversation_authority']+['profile_agent_id'=>(int)$offer['context']['agent_id'],'profile_session_id'=>(int)$offer['context']['session_id']];
            $exact=is_array($binding)&&count($binding)===count($expected)+1&&(bool)preg_match('/^[a-f0-9]{32}$/D',(string)($binding['response_token']??''));
            foreach($expected as $key=>$value)$exact=$exact&&($binding[$key]??null)===$value;
            $call=vp3_profile_call_row_pa3c($pdo,(int)$offer['call_id']);
            ps_race_check($exact&&(int)$messages[0]['conversation_id']===(int)$offer['conversation_id']&&$messages[0]['sender_type']==='system'&&$messages[0]['sender_user_id']===null&&$messages[0]['message']===''
                &&$call!==null&&(int)$call['conversation_id']===(int)$offer['conversation_id']&&(int)$call['owner_user_id']===$expected['owner_user_id']&&(int)$call['profile_agent_id']===$expected['profile_agent_id']&&(int)$call['profile_session_id']===$expected['profile_session_id']&&(int)$call['visitor_user_id']===$expected['visitor_user_id']&&hash_equals((string)$call['credential_hash'],$expected['credential_hash']),$scenario.': only hidden authority record exactly binds the native call conversation');
        }else ps_race_check(pa3_count('profile_agent_messages')===0,$scenario.': narration alone creates no public conversation authority record');
        ps_race_check(pa3_count('agent_workflow_runs')===0&&pa3_count('agent_workflow_actions')===0,$scenario.': no model or other workflow is created before provider work');
    }
}finally{foreach($workers as [$process,$pipes])if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
echo 'PROFILE_SPEECH_CONCURRENCY_PA4A=PASS ('.$checks." checks; MySQL)\n";
