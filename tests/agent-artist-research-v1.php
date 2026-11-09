<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-artist-research-v1.php';

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

echo "ARTIST_RESEARCH_V1=PASS\n";
