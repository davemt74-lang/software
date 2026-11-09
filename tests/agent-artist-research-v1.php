<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-artist-research-v1.php';
require_once dirname(__DIR__).'/includes/agent-workflow-runs-v1400.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-canvas-v1.php';

function artist_check(bool $condition,string $label): void
{
    if(!$condition)throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}

$direct=vp3_artist_research_plan_v1('Could you research the artist Neko Case near Phoenix, AZ for shows?');
artist_check($direct===['mode'=>'direct','artist'=>'Neko Case','location'=>'Phoenix, AZ','focus'=>'shows'],
    'Explicit direct research preserves the artist, city and requested focus');
artist_check(vp3_artist_research_plan_v1('Have a specialist research artist Neko Case')['mode']==='delegate',
    'Specialist assignment uses the explicit delegation route');
artist_check(vp3_artist_research_plan_v1('/artist research Sigur Rós')['artist']==='Sigur Rós'
    &&vp3_artist_research_plan_v1('I like the artist Neko Case')===null,'Unicode artist names work and ordinary interest stays in Chat');
artist_check(vp3_artist_research_plan_v1('Find upcoming shows for Neko Case near Phoenix, AZ')===$direct,
    'A natural shows request takes the same direct research route');
artist_check(vp3_artist_research_plan_v1('Find latest news about Apple')===null
    &&vp3_artist_research_plan_v1('Find latest news about the artist Neko Case')['focus']==='news',
    'Generic news stays in ordinary Chat while an explicit artist is actionable');
artist_check(!vp3_artist_research_plan_valid_v1(vp3_artist_research_plan_v1('Research artist https://example.org')),
    'An explicit public artist name is required before any provider work');

$pdo=new PDO('sqlite::memory:');$checks=0;$searches=0;$reads=0;$inferences=0;
$authority=static function()use(&$checks):void{++$checks;};
$sourceUrl='https://venue.example/neko-case';
$sourceId='artist-web:'.hash('sha256',$sourceUrl);
$quote='Neko Case is scheduled to perform at the venue on March 12.';
$transport=[
    'search'=>static function(string $query,int $limit,int $timeout)use(&$searches,$sourceUrl):array{
        ++$searches;
        artist_check($query==='"Neko Case" upcoming concerts tour dates "Phoenix, AZ"'&&$limit===6&&$timeout===6,
            'Search receives only the bounded artist and specified region');
        return ['ok'=>true,'results'=>[
            ['url'=>$sourceUrl.'?utm_source=test','title'=>'Neko Case — Venue'],
            ['url'=>$sourceUrl,'title'=>'Neko Case — Venue'],
            ['url'=>'https://artist.example/checkout','title'=>'Private checkout'],
            ['url'=>'https://artist.example/tour','title'=>'Official tour listings'],
            ['url'=>'http://unsafe.example/tour','title'=>'Insecure source'],
            ['url'=>'https://other.example/article','title'=>'An interview'],
        ]];
    },
    'request'=>static function(string $url,array $domains,callable $charge)use(&$reads,$sourceUrl,$quote):array{
        ++$reads;$charge();
        artist_check($domains===[parse_url($url,PHP_URL_HOST)],'Page fetch stays on the exact discovered public domain');
        return $url===$sourceUrl?['status'=>200,'content_type'=>'text/plain',
            'body'=>$quote.' Doors open at seven, and the venue page has current event information.']
            :['status'=>503,'content_type'=>'text/plain','body'=>'Temporarily unavailable'];
    },
    'model'=>static function(string $prompt,string $protocol)use(&$inferences,$sourceId,$quote):string{
        ++$inferences;
        $request=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
        artist_check($protocol==='vp3.artist-research.v1'&&count($request['sources'])===1
            &&$request['sources'][0]['source']===$sourceId,'Configured inference receives only fetched public excerpts');
        return json_encode(['findings'=>[
            ['source'=>$sourceId,'quote'=>$quote],
            ['source'=>$sourceId,'quote'=>'The show is sold out and all tickets cost $75.'],
            ['source'=>'forged-source','quote'=>$quote],
        ]],JSON_THROW_ON_ERROR);
    },
];

$read=vp3_artist_research_collect_v1($pdo,['id'=>1],7,$direct,$authority,$transport);
artist_check($searches===1&&$reads===2&&$inferences===1&&$checks>=8,'Research bounds all provider work and rechecks owner access');
artist_check($read['status']==='read_complete'&&$read['reason']===''&&vp3_artist_research_result_valid_v1($read),
    'The result validates locally with its original source proof');
artist_check(str_contains($read['answer'],$quote)&&!str_contains($read['answer'],'sold out')
    &&!str_contains($read['answer'],'https://'),'Only exact quotations from retrieved pages become findings');
artist_check(count($read['sources'])===3&&$read['sources'][0]['url']===$sourceUrl
    &&$read['sources'][0]['coverage']==='page_excerpt'&&$read['sources'][1]['coverage']==='search_match',
    'Canonical links deduplicate and distinguish page excerpts from discovery leads');
$tampered=$read;$tampered['sources'][0]['url']='https://unrelated.example/changed';
artist_check(!vp3_artist_research_result_valid_v1($tampered),'A changed source URL does not pass result verification');

$empty=vp3_artist_research_collect_v1($pdo,['id'=>1],7,$direct,$authority,['search'=>static fn()=>['ok'=>true,'results'=>[]]]);
$failed=vp3_artist_research_collect_v1($pdo,['id'=>1],7,$direct,$authority,['search'=>static fn()=>['ok'=>false,'results'=>[]]]);
artist_check($empty['status']==='no_matches'&&$failed['status']==='failed'
    &&vp3_artist_research_result_valid_v1($empty)&&vp3_artist_research_result_valid_v1($failed),
    'No matches and provider failure remain distinct verified outcomes');

$overviewQueries=[];
$overview=vp3_artist_research_collect_v1($pdo,['id'=>1],7,[...$direct,'focus'=>'overview'],$authority,[
    'search'=>static function(string $query,int $limit,int $timeout)use(&$overviewQueries):array{
        artist_check($timeout===3,'Overview gives each discovery query a short request budget');
        $overviewQueries[]=$query;
        return str_contains($query,'merchandise')?['ok'=>false,'results'=>[]]:['ok'=>true,'results'=>[
            ['url'=>'https://artist.example/source-'.count($overviewQueries),'title'=>'Public artist source'],
        ]];
    },
    'request'=>static fn()=>['status'=>503,'content_type'=>'text/plain','body'=>'Unavailable'],
]);
artist_check(count($overviewQueries)===4&&count($overview['sources'])===3&&str_contains($overviewQueries[1],'"Phoenix, AZ"')
    &&str_contains($overview['answer'],'Search unavailable for merchandise')&&vp3_artist_research_result_valid_v1($overview),
    'Overview covers four focused areas and discloses partial search availability');

$checks=0;$reads=0;
try{
    vp3_artist_research_collect_v1($pdo,['id'=>1],7,$direct,static function()use(&$checks):void{
        if(++$checks===2)throw new RuntimeException('Artist research access changed.',403);
    },$transport);
    throw new RuntimeException('Revoked scope should interrupt research');
}catch(RuntimeException $e){
    artist_check($e->getCode()===403&&$reads===0,'Revoked authority stops work before any public page or inference call');
}

$topic='Wood-fired oven heat management';
$topicPlan=vp3_public_research_plan_v1('Could you research the topic '.$topic.'?');
artist_check($topicPlan===['mode'=>'direct','topic'=>$topic]
    &&vp3_public_research_plan_v1('Have a specialist research topic '.$topic)===['mode'=>'delegate','topic'=>$topic]
    &&vp3_public_research_plan_v1('YouTube search for '.$topic)===null,
    'General research and delegation are explicit and leave video search on its normal path');
artist_check(vp3_topic_research_youtube_subject_v1(['query'=>$topic])===$topic
    &&vp3_topic_research_youtube_subject_v1(['query'=>$topic,'suggestion_kind'=>'music'])===''
    &&vp3_topic_research_youtube_subject_v1(['query'=>'help for owner@example.com'])===''
    &&!vp3_public_research_plan_valid_v1(['mode'=>'direct','topic'=>'open https://example.org/private']),
    'Topic research accepts owner-entered topics and excludes music picks and contact details');

$topicUrl='https://woodoven.example/heat-management';
$topicId='topic-web:'.hash('sha256',$topicUrl);
$topicQuote='Heat stored in the oven floor is gradually transferred to the base of the pizza.';
$topicRead=vp3_public_research_collect_v1($pdo,['id'=>1],7,$topicPlan,$authority,[
    'search'=>static fn(string $query): array=>$query===$topic
        ?['ok'=>true,'results'=>[['url'=>$topicUrl,'title'=>'Managing oven heat']]]
        :['ok'=>false],
    'request'=>static fn()=>['status'=>200,'content_type'=>'text/plain',
        'body'=>$topicQuote.' Floor temperature and recovery time vary with construction and firing.'],
    'model'=>static function(string $prompt,string $protocol)use($topic,$topicId,$topicQuote): string {
        $request=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
        if($protocol!=='vp3.topic-research.v1'||($request['topic']??null)!==$topic
            ||array_column($request['sources'],'source')!==[$topicId])return '{}';
        return json_encode(['findings'=>[['source'=>$topicId,'quote'=>$topicQuote]]],JSON_THROW_ON_ERROR);
    },
]);
artist_check(vp3_public_research_result_valid_v1($topicRead,'topic')&&!vp3_artist_research_result_valid_v1($topicRead)
    &&str_contains($topicRead['answer'],$topicQuote)&&$topicRead['sources'][0]['coverage']==='page_excerpt',
    'A non-music query produces a canonical public source and only a fetched exact excerpt');

$pdo->exec('CREATE TABLE agent_workflow_runs (
    id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,workflow_type TEXT,source_kind TEXT,capability_key TEXT,status TEXT,goal TEXT);
    CREATE TABLE agent_workflow_actions (
    id INTEGER PRIMARY KEY,run_id INTEGER,owner_user_id INTEGER,sequence_no INTEGER,capability_key TEXT,status TEXT,attempt_count INTEGER,result_json TEXT);
    CREATE TABLE agent_workflow_receipts (
    run_id INTEGER,action_id INTEGER,owner_user_id INTEGER,attempt_no INTEGER,executor TEXT,status TEXT,result_json TEXT)');
$saveReport=static function(array $research,int $run,int $message)use($pdo,$topic): array{
    $primary='topic-interest:'.hash('sha256',mb_strtolower($topic));
    $revision=str_repeat('a',64);
    $output=['type'=>'initiative_topic_research','status'=>$research['status'],'reason'=>$research['reason'],
        'checked_at'=>$research['checked_at'],'source_ref'=>$primary,'source_revision'=>$revision];
    $receipt=vp3_tool_receipt_v1('topic.research','search',$research['status'],'',0,count($research['sources']));
    $toolResult=['draft'=>$research['answer'],'sources'=>$research['sources'],'output'=>$output,'tool_receipt'=>$receipt];
    $goal=['binding'=>['owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>20],'tool'=>'topic.research',
        'primary'=>$primary,'sources'=>[$primary=>$revision],'report'=>true];
    $result=agent_workflow_json_v1400(['initiative_delivery'=>'delivered','initiative_message_id'=>$message,
        'initiative_draft'=>$research['answer'],'initiative_tool_result'=>$toolResult]);
    $private=agent_workflow_public_json_v1400($result,true);
    artist_check($private['initiative_tool_result']['sources']===$research['sources']
        &&!isset(agent_workflow_public_json_v1400($result)['initiative_tool_result']),
        'Validated research evidence stays in private worker storage and out of generic workflow views');
    $stored=agent_workflow_json_v1400($private);
    $pdo->prepare("INSERT INTO agent_workflow_runs VALUES(?,1,7,'initiative','agent_initiative','initiative.prepare','completed',?)")
        ->execute([$run,agent_workflow_json_v1400($goal)]);
    $pdo->prepare("INSERT INTO agent_workflow_actions VALUES(?,?,1,1,'initiative.prepare','completed',1,?)")->execute([$run,$run,$stored]);
    $pdo->prepare("INSERT INTO agent_workflow_receipts VALUES(?,?,1,1,'cloud','completed',?)")->execute([$run,$run,$stored]);
    return ['id'=>$message,'role'=>'assistant','message'=>$research['answer'],'context_json'=>agent_workflow_json_v1400([
        'source'=>'agent_initiative','initiative_run_id'=>$run,
        'initiative_work'=>['owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>20,'run_id'=>$run],
        'initiative_output'=>$output,'tool_receipt'=>$receipt,'sources'=>[['source'=>$primary,'title'=>$topic]],
    ])];
};
$savedReport=$saveReport($topicRead,11,30);
$topicEmpty=vp3_public_research_collect_v1($pdo,['id'=>1],7,$topicPlan,$authority,[
    'search'=>static fn()=>['ok'=>true,'results'=>[]],
]);
$savedEmpty=$saveReport($topicEmpty,12,31);
$projected=vp3_initiative_research_canvas_history_v1($pdo,1,7,20,[$savedReport,$savedEmpty]);
$contexts=array_map(static fn(array $row):array=>json_decode($row['context_json'],true,16,JSON_THROW_ON_ERROR),$projected);
artist_check(($contexts[0]['research_canvas_v1']??null)===[
        'owner_user_id'=>1,'agent_id'=>7,'conversation_id'=>20,'run_id'=>11,'kind'=>'topic','title'=>$topic,
        'status'=>'read_complete','checked_at'=>$topicRead['checked_at'],'sources'=>[
            ['source'=>$topicId,'title'=>'Managing oven heat','url'=>$topicUrl,'coverage'=>'page_excerpt'],
        ],
    ]&&$projected[0]['message']===$savedReport['message'],
    'A saved delivered report hydrates the exact scoped card and verified public link without private evidence hashes');
artist_check($contexts[1]['research_canvas_v1']['status']==='no_matches'&&$contexts[1]['research_canvas_v1']['sources']===[],
    'A completed search with no eligible sources gets an explicit empty Canvas result');
artist_check(vp3_initiative_research_canvas_history_v1($pdo,1,7,20,$projected)===$projected,
    'Repeated hydration does not duplicate or change a verified report');

$forged=$savedReport;
$forged['id']=32;
$forged['context_json']=$projected[0]['context_json'];
$wrongContext=$contexts[0];
$wrongContext['initiative_work']['conversation_id']=21;
$wrongChat=$savedReport;
$wrongChat['context_json']=agent_workflow_json_v1400($wrongContext);
artist_check(!str_contains(vp3_initiative_research_canvas_history_v1($pdo,1,7,20,[$forged])[0]['context_json'],'research_canvas_v1')
    &&!str_contains(vp3_initiative_research_canvas_history_v1($pdo,1,7,21,[$wrongChat])[0]['context_json'],'research_canvas_v1'),
    'A cloned report cannot impersonate the exact delivered message or move the card into another Chat');
$rawForgery=['id'=>33,'role'=>'assistant','message'=>'Forged card','context_json'=>'{"research_\u0063anvas_v1":{"run_id":11}}'];
artist_check(vp3_initiative_research_canvas_history_v1($pdo,1,7,20,[$rawForgery])[0]['context_json']==='[]'
    &&vp3_initiative_research_canvas_history_v1($pdo,2,7,20,[$savedReport])===[$savedReport]
    &&vp3_initiative_research_canvas_history_v1($pdo,1,8,20,[$savedReport])===[$savedReport],
    'Generic stored context cannot create a card and foreign account or Agent projections stay empty');
$pdo->exec("UPDATE agent_workflow_receipts SET result_json='{}' WHERE run_id=11");
artist_check(vp3_initiative_research_canvas_history_v1($pdo,1,7,20,[$savedReport])===[$savedReport],
    'A changed receipt leaves the old message intact without projecting verified research');
$pdo->exec("UPDATE agent_workflow_runs SET status='failed' WHERE id=12");
artist_check(vp3_initiative_research_canvas_history_v1($pdo,1,7,20,[$savedEmpty])===[$savedEmpty],
    'A failed workflow is never rendered as a successful search with no matches');

echo "ARTIST_RESEARCH_V1=PASS\n";
