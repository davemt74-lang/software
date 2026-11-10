<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-artist-research-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-status-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-canvas-v1.php';

$user=['id'=>1,'is_active'=>1];
$principal=scope_principal($user,17);
scope_command($user,17,170,'off');
scope_command($user,17,170,'on');
$policy=vp3_initiative_policy_v1($user,17);
$pdo->exec("UPDATE agent_memory_items SET memory_text='Private supplier terms and next-month budget' WHERE id=1002");
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=170");
$scope=vp3_initiative_tools_scope_v1($pdo,$user,$policy);
$ref='task-research:1002';
$evidence=static function(?array $refs=null)use($pdo,$scope):array{
    $coverage=[];
    return vp3_initiative_task_research_evidence_v1($pdo,$scope,$refs,$coverage);
};
$saveTurn=static function(string $message,int $cid=170)use($pdo):int{
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES(?,1,'user',?,NOW())")
        ->execute([$cid,$message]);
    return (int)$pdo->lastInsertId();
};
$grant=static function(string $message,?int $existingTurn=null,int $cid=170)use($pdo,$user,$principal,$saveTurn):array{
    return vp3_public_research_chat_v1($pdo,$user,$principal,vp3_public_research_plan_v1($message),$cid,$existingTurn??$saveTurn($message,$cid));
};

vp3_initiative_schedule_v1($pdo,$user,17);
$planCount=count(scope_runs(1,17,'initiative.plan'));
scope_check($evidence()===[]&&$planCount>0,'an ordinary open task alone cannot become a public research source');
$email=$grant('/research task #1002: private@example.com');
$forged=$grant('/research task #1002: oven sizing',$saveTurn('A saved turn about something else'));
$otherAgent=$grant('/research task #1003: public oven sizing');
scope_check($email['tool_receipt']['status']==='clarification_required'&&$forged['tool_receipt']['status']==='blocked'
    &&$otherAgent['tool_receipt']['status']==='blocked'&&$evidence()===[],
    'contact data, an invented grant, and a sibling Agent task cannot authorize a public query');

$pdo->exec("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,is_active,metadata_json)
    VALUES(930005,1,17,'task','Only Agent A task','Duplicate title','task-research-duplicate',1,'{}')");
$topic='commercial pizza oven ventilation';
$request='/research task "Only Agent A task": '.$topic;
$duplicate=$grant($request);
scope_check($duplicate['tool_receipt']['status']==='clarification_required'&&str_contains($duplicate['answer'],'#1002, #930005')
    &&$evidence()===[],'duplicate exact task titles require a task number');
$pdo->exec('UPDATE agent_memory_items SET is_active=0 WHERE id=930005');
$requestId=$saveTurn($request,171);
$accepted=$grant($request,$requestId,171);
$sources=$evidence();
$plans=scope_runs(1,17,'initiative.plan');
scope_check($accepted['tool_receipt']['status']==='completed'&&$accepted['tool_receipt']['record_id']===1002
    &&array_keys($sources)===[$ref]&&$sources[$ref]['title']===$topic&&count($plans)===$planCount+1,
    'an exact topic approval in another same-Agent Chat wakes the bound scheduler after a fresh assessment');
$same=$grant('Research task 1002 on '.$topic);
scope_check($same['tool_receipt']['status']==='completed'&&$evidence()[$ref]['revision']===$sources[$ref]['revision']
    &&count(scope_runs(1,17,'initiative.plan'))===count($plans),'restating the same topic keeps its research identity and avoids extra assessment');

$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES(170,NULL,'assistant','Research choice saved',NOW())");
$activity=$pdo->prepare("UPDATE agent_activity_state SET context_key='chat:170',details_json=?,last_heartbeat_at=NOW() WHERE user_id=1");
$activity->execute(['{"visible":false,"conversation_id":170}']);
$beforeJobs=count(scope_runs(1,17,'initiative.prepare'));
$planned=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,(int)end($plans)['id']),
    static function(string $prompt,string $protocol)use($ref,$topic):string{
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        $rows=array_column($input['evidence'],null,'ref');
        scope_check($protocol==='vp3.initiative-plan.v1'&&($input['tools']['topic.research']['source_refs']??null)===[$ref]
            &&($rows[$ref]['title']??null)===$topic,'the planner sees the exact task topic and existing research tool with YouTube disconnected');
        return json_encode(['jobs'=>[['tool'=>'topic.research','primary'=>$ref,'refs'=>[$ref,'task:1002'],
            'focus'=>'Private supplier terms and next-month budget','report'=>false]]],JSON_THROW_ON_ERROR);
    });
$jobs=scope_runs(1,17,'initiative.prepare');$runId=(int)end($jobs)['id'];
$job=json_decode((string)end($jobs)['goal'],true,24,JSON_THROW_ON_ERROR);
scope_check($planned['ok']&&count($jobs)===$beforeJobs+1&&array_keys($job['sources'])===[$ref]&&$job['report']===true
    &&vp3_initiative_research_status_v1($pdo,$user,17,170)['specialist']===['run_id'=>$runId,'kind'=>'topic','state'=>'queued','subject'=>$topic],
    'one canonical task research job is reportable and queued in the linked Chat rail');

$url='https://example.org/commercial-ovens/ventilation';
$quote='Commercial ovens need ventilation designed for the equipment and building.';
$searches=0;
$completed=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$runId),
    static function(string $prompt,string $protocol)use($topic,$url,$quote):string{
        $input=json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
        $source='topic-web:'.hash('sha256',$url);
        scope_check($protocol==='vp3.topic-research.v1'&&($input['topic']??null)===$topic
            &&array_column($input['sources'],'source')===[$source]&&!str_contains($prompt,'Private supplier'),
            'the public synthesis receives the approved query and exact public excerpt without private task context');
        return json_encode(['findings'=>[['source'=>$source,'quote'=>$quote]]],JSON_THROW_ON_ERROR);
    },[
        'search'=>static function(string $query)use($pdo,$user,$runId,$topic,$url,&$searches):array{
            ++$searches;
            $status=vp3_initiative_research_status_v1($pdo,$user,17,170)['specialist'];
            scope_check($query===$topic&&$status['state']==='running'&&$status['run_id']===$runId,
                'the leased worker shows working status and sends only the exact approved public search terms');
            return ['ok'=>true,'results'=>[['url'=>$url,'title'=>'Commercial oven ventilation']]];
        },
        'request'=>static fn():array=>['status'=>200,'content_type'=>'text/plain',
            'body'=>$quote.' Operators should confirm installation details with the manufacturer and the relevant local authority.'],
    ]);
$action=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');
$receipt=$pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');
$action->execute([$runId]);$receipt->execute([$runId]);
$json=$action->fetchColumn();$result=json_decode((string)$json,true,24,JSON_THROW_ON_ERROR);
scope_check($completed['ok']&&$json===$receipt->fetchColumn()&&$result['initiative_delivery']==='pending'
    &&$result['initiative_tool_result']['output']['public_topic']===$topic&&$searches===1,
    'a completed task search saves identical verified worker evidence while the owner is away');
$findings=vp3_initiative_status_v1($pdo,$user,$policy,['handled'=>true],true);
scope_check(str_contains($findings['answer'],$quote)&&in_array($url,array_column($findings['sources'],'url'),true)&&$searches===1,
    'a direct background findings read returns the saved result and public citation without another search');

$activity->execute(['{"visible":true,"conversation_id":170}']);
scope_check(vp3_initiative_deliver_pending_v1($pdo,$user,17),'the normal delivery service posts task research when the owner is available');
$action->execute([$runId]);$delivered=json_decode((string)$action->fetchColumn(),true,24,JSON_THROW_ON_ERROR);
$message=$pdo->prepare('SELECT id,role,message,context_json FROM chat_messages WHERE id=? AND conversation_id=170');
$message->execute([$delivered['initiative_message_id']]);
$history=vp3_initiative_research_canvas_history_v1($pdo,1,17,170,[$message->fetch()]);
$canvas=json_decode($history[0]['context_json'],true,24,JSON_THROW_ON_ERROR)['research_canvas_v1'];
scope_check($canvas['title']===$topic&&$canvas['sources'][0]['url']===$url&&!vp3_initiative_deliver_pending_v1($pdo,$user,17),
    'ordinary Chat history renders the exact completed research card once');

$taskState=$pdo->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=1002');
$taskState->execute(['{"task_status":"completed"}']);
scope_check($evidence([$ref])===[],'a completed task stops being an eligible research source');
$taskState->execute(['{}']);
$next=$grant('/research task #1002: public oven installation requirements');
$plans=scope_runs(1,17,'initiative.plan');
$planned=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,(int)end($plans)['id']),
    static fn():string=>json_encode(['jobs'=>[['tool'=>'topic.research','primary'=>$ref,'refs'=>[$ref],
        'focus'=>'Find an official guide','report'=>true]]],JSON_THROW_ON_ERROR));
$jobs=scope_runs(1,17,'initiative.prepare');$revokedRun=(int)end($jobs)['id'];
$revoke=$grant('Stop public research for task 1002');
$retry=$grant($request,$requestId,171);
$blockedCalls=0;
$blocked=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$revokedRun),null,
    ['search'=>static function()use(&$blockedCalls):array{++$blockedCalls;return ['ok'=>true,'results'=>[]];}]);
scope_check($next['tool_receipt']['status']==='completed'&&$planned['ok']&&$revoke['tool_receipt']['status']==='completed'
    &&$retry['tool_receipt']['status']==='completed'&&$evidence()===[]&&$blockedCalls===0&&!$blocked['ok']
    &&vp3_initiative_research_status_v1($pdo,$user,17,170)['specialist']===null,
    'revoking a queued task stops every provider call and an older replay cannot re-enable it');
echo "AGENT_INITIATIVE_TASK_RESEARCH_PIPELINE_V1=PASS\n";
