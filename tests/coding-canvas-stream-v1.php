<?php
declare(strict_types=1);

ob_start();require __DIR__.'/agent-initiative-canvas-transport-v1.php';ob_end_clean();
require_once dirname(__DIR__).'/includes/coding-canvas-stream-v1.php';
if(!function_exists('ai_haiku55_request')){
    $source=file_get_contents(dirname(__DIR__).'/includes/ai-settings.php');
    $start=strpos($source,'function ai_haiku55_request(');$end=strpos($source,'function ai_provider_api_key(',$start);
    eval(substr($source,$start,$end-$start));
}
if(!function_exists('ai_anthropic_credit_rejection_v1')){
    $source=file_get_contents(dirname(__DIR__).'/includes/ai-settings.php');
    $start=strpos($source,'function ai_anthropic_credit_rejection_v1(');$end=strpos($source,'function ai_openai_response(',$start);
    eval(substr($source,$start,$end-$start));
}
$assertions=0;
function stream_check(bool $ok,string $label):void {++$GLOBALS['assertions'];if(!$ok)throw new RuntimeException($label);}
function sse(array $event):string {return 'data: '.json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\r\n\r\n";}
function message_events(string $text,bool $complete=true):array {
    $events=[['type'=>'message_start','message'=>['id'=>'msg_fixture','usage'=>['input_tokens'=>120,'output_tokens'=>1]]],
        ['type'=>'content_block_start','index'=>0,'content_block'=>['type'=>'thinking']],
        ['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'thinking_delta','thinking'=>'PRIVATE_THINKING']],
        ['type'=>'content_block_stop','index'=>0],
        ['type'=>'content_block_start','index'=>1,'content_block'=>['type'=>'text','text'=>'']],
        ['type'=>'content_block_delta','index'=>1,'delta'=>['type'=>'text_delta','text'=>$text]],
        ['type'=>'content_block_stop','index'=>1]];
    if($complete)$events=[...$events,['type'=>'message_delta','delta'=>['stop_reason'=>'end_turn'],'usage'=>['output_tokens'=>90]],['type'=>'message_stop']];
    return $events;
}
$text=json_encode(['title'=>'Rebuilt app','html'=>'<h1>New code 🎨</h1>','css'=>'h1{color:green}','js'=>'CanvasUI.text("h1","Saved");'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$wire=implode('',array_map('sse',message_events($text)));
foreach([1,2,7,53,strlen($wire)] as $size){
    $stream=new Vp3CodingCanvasStreamV1();foreach(str_split($wire,$size) as $part)stream_check($stream->feed($part),'SSE accepts arbitrary HTTP chunk boundaries');
    stream_check($stream->terminal&&$stream->text===$text&&$stream->usage===['input_tokens'=>120,'output_tokens'=>90],'Cumulative usage and UTF-8 code survive chunking');
    stream_check(!str_contains(json_encode($stream->snapshot()),'PRIVATE_THINKING'),'Thinking is never retained in the checkpoint');
}
$stream=new Vp3CodingCanvasStreamV1();$stream->feed(implode('',array_map('sse',message_events($text,false))));
stream_check(!$stream->terminal&&$stream->snapshot()['usage']===[]&&$stream->text===$text,'Code remains inspectable without inventing terminal usage');
foreach([
    [['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'text_delta','text'=>'wrong']]],
    [['type'=>'message_stop']],
    [['type'=>'message_start','message'=>['id'=>str_repeat('x',161)]]],
    [...message_events($text),['type'=>'content_block_delta','index'=>1,'delta'=>['type'=>'text_delta','text'=>'after terminal']]],
] as $events){$stream=new Vp3CodingCanvasStreamV1();stream_check(!$stream->feed(implode('',array_map('sse',$events))),'Out-of-order and post-terminal events fail closed');}
$stream=new Vp3CodingCanvasStreamV1();stream_check(!$stream->feed("data: invalid\n\n"),'Malformed event JSON is rejected');
$stream=new Vp3CodingCanvasStreamV1();stream_check(!$stream->feed('data: '.str_repeat('x',262145)),'An unterminated SSE line is bounded');
$stream=new Vp3CodingCanvasStreamV1();stream_check(!$stream->feed(implode('',array_map('sse',message_events(str_repeat('x',200001))))),'Generated source is bounded');
$stream=new Vp3CodingCanvasStreamV1();stream_check(!$stream->feed(sse(['type'=>'error','error'=>['type'=>'overloaded_error']]))&&$stream->failure==='provider_overloaded','In-stream errors are typed without provider text');
$openaiEvents=[['type'=>'response.created','response'=>['id'=>'resp_fixture']],
    ['type'=>'response.reasoning_summary_text.delta','delta'=>'PRIVATE_REASONING'],
    ['type'=>'response.output_text.delta','delta'=>$text],
    ['type'=>'response.completed','response'=>['id'=>'resp_fixture','status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>$text]]]],'usage'=>['input_tokens'=>30,'output_tokens'=>40,'total_tokens'=>70]]]];
$stream=new Vp3CodingCanvasStreamV1('openai');stream_check($stream->feed(implode('',array_map('sse',$openaiEvents)))&&$stream->terminal&&$stream->text===$text,'OpenAI answer deltas match the final response and omit reasoning');
$openaiEvents[3]['response']['output'][0]['content'][0]['text']='mismatch';$stream=new Vp3CodingCanvasStreamV1('openai');
stream_check(!$stream->feed(implode('',array_map('sse',$openaiEvents))),'A mismatched OpenAI final response cannot confirm received source');

// Real native meter and ledger, with an injected outbound connection only.
foreach(['openai','anthropic'] as $provider)vp3_ai_service_save_model_v1($pdo,$provider,'coding-stream-fixture','Coding stream fixture',['code_build','code_repair']);
foreach(['code_build','code_repair'] as $service)vp3_ai_service_save_route_v1($pdo,$service,['provider'=>'anthropic','model'=>'coding-stream-fixture'],null);
$run=8000;
$attempt=static function(array $events,array $wireResult,?callable $before=null,bool $cancel=false,string $provider='anthropic')use($pdo,&$run):array{
    $id=++$run;$pin=['provider'=>$provider,'model'=>'coding-stream-fixture','funding'=>'system'];
    $meter=new AiV100ServiceMeter($pdo,['id'=>1,'is_active'=>1],['service'=>'code_repair','stage'=>'code_repair','job_key'=>'coding-canvas:'.$id,'attempt_key'=>'stream-'.$id,'run_id'=>$id,'agent_id'=>7,'conversation_id'=>11],['configured'=>true],'code_repair');
    $payload=['model'=>$pin['model']]+($provider==='openai'?['input'=>[['role'=>'user','content'=>'Rebuild']],'max_output_tokens'=>12000,'store'=>false]:['messages'=>[['role'=>'user','content'=>'Rebuild']],'max_tokens'=>12000]);
    $calls=0;$saved=[];
    $transport=static function($body,$consume,$header,$timeout)use(&$calls,$events,$wireResult):array{
        $calls++;$data=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        stream_check($data['stream']===true&&$timeout<=300,'The pinned request streams within a bounded deadline');
        $header('request-id: req_fixture');
        foreach($events as $event){if(!$consume(sse($event)))break;}
        return $wireResult;
    };
    $checkpoint=static function(array $snapshot)use($pdo,&$saved,$cancel,$id):bool{
        $row=$pdo->query('SELECT service_status FROM ai_execution_ledger WHERE run_id='.$id)->fetchColumn();
        stream_check($row==='pending','Received code is checkpointed before native usage settlement');
        $saved[]=$snapshot;return !$cancel;
    };
    $result=vp3_coding_canvas_provider_stream_v1($payload,'fixture-key',$meter,$pin,null,$before,$checkpoint,$transport);
    $row=$pdo->query('SELECT * FROM ai_execution_ledger WHERE run_id='.$id)->fetch(PDO::FETCH_ASSOC);
    return [$result,$row,$calls,$saved];
};
[$result,$row,$calls,$saved]=$attempt(message_events($text),['ok'=>true,'status'=>200,'errno'=>0]);
stream_check($result['ok']&&$calls===1&&(int)$row['total_tokens']===210&&(int)$row['usage_known']===1&&$row['service_status']==='completed','Exactly one complete Claude attempt settles cumulative native usage');
stream_check(end($saved)['text']===$text&&end($saved)['terminal']===true,'The final source is retained before validation');
$openaiEvents[3]['response']['output'][0]['content'][0]['text']=$text;
[$result,$row,$calls]=$attempt($openaiEvents,['ok'=>true,'status'=>200,'errno'=>0],null,false,'openai');
stream_check($result['ok']&&$result['data']['output_text']===$text&&$calls===1&&(int)$row['total_tokens']===70,'Exactly one complete OpenAI stream settles native usage and returns the same validated answer');
[$result,$row,$calls,$saved]=$attempt(message_events($text,false),['ok'=>false,'status'=>200,'errno'=>28]);
stream_check(!$result['ok']&&$result['provider_failure_class']==='provider_timeout'&&$calls===1&&$row['service_status']==='unknown'&&(int)$row['usage_known']===0,'An interrupted paid attempt remains unknown without a hidden retry');
stream_check(end($saved)['text']===$text&&end($saved)['failure']==='provider_timeout','Complete source bytes survive a missing provider completion receipt');
[$result,$row,$calls]=$attempt([],['ok'=>true,'status'=>401,'errno'=>0]);
stream_check($result['failure_code']==='provider_rejected'&&$calls===1&&$row['service_status']==='rejected','Confirmed provider rejection is distinct from an uncertain paid attempt');
[$result,$row,$calls]=$attempt([],['ok'=>false,'status'=>529,'errno'=>0]);
stream_check($result['provider_failure_class']==='provider_server'&&$calls===1&&$row['service_status']==='unknown','A provider service failure has no retry or invented free usage');
[$result,$row,$calls]=$attempt(message_events($text),['ok'=>true,'status'=>200,'errno'=>0],static fn()=>false);
stream_check($calls===0&&$row===false,'A revoked source before dispatch releases the native reservation without a provider call');
[$result,$row,$calls]=$attempt(message_events($text),['ok'=>false,'status'=>200,'errno'=>23],null,true);
stream_check(!$result['ok']&&$calls===1&&$row['service_status']==='unknown','Revoked execution stops streaming without a second provider attempt');
echo 'CODING_CANVAS_STREAM=PASS ('.$assertions." assertions; offline transport, real meter)\n";
