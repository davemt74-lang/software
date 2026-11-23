<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-local-research-v1.php';

function local_research_check(bool $test,string $why): void
{
    if (!$test) throw new RuntimeException($why);
    echo "PASS ".$why."\n";
}

$public=['area'=>'Phoenix, Arizona','item'=>'XLR cables','category'=>'music stores','open_today'=>true,'date'=>'2026-11-23'];
$expected='music stores in Phoenix, Arizona selling XLR cables open on 2026-11-23';
local_research_check(vp3_phone_local_public_query_v1($public)===$expected,'preview equals the literal public search request');
foreach ([$public+['task'=>'My private rehearsal'],array_replace($public,['area'=>'33.4500,-112.072']),
    array_replace($public,['area'=>'100 Main Street']),array_replace($public,['date'=>'2026-02-30'])] as $invalid) {
    try { vp3_phone_local_public_query_v1($invalid); throw new LogicException('Unexpectedly accepted an invalid public field'); }
    catch (RuntimeException $e) { local_research_check($e->getCode()===422,'reject extended or precise public payload'); }
}
$html=static fn($script):array=>[
    'status'=>200,'content_type'=>'text/html; charset=utf-8','location'=>'','body'=>'<html><body><script type="application/ld+json">'
        .json_encode(['@context'=>'https://schema.org','@graph'=>$script],JSON_HEX_TAG|JSON_THROW_ON_ERROR).'</script></body></html>'];
$address=['@type'=>'PostalAddress','@id'=>'#address','addressLocality'=>'Phoenix','addressRegion'=>'AZ','addressCountry'=>'US'];
$store=['@type'=>'MusicStore','@id'=>'#store','name'=>'Desert Audio','address'=>['@id'=>'#address'],
    'openingHoursSpecification'=>['@id'=>'#hours'],'makesOffer'=>['@id'=>'#offer']];
$hours=['@id'=>'#hours','@type'=>'OpeningHoursSpecification','dayOfWeek'=>['https://schema.org/Monday'],'opens'=>'10:00:00','closes'=>'18:00:00'];
$product=['@id'=>'#product','@type'=>'Product','name'=>'StudioPro XLR cable 10 ft'];
$offer=['@id'=>'#offer','@type'=>'Offer','itemOffered'=>['@id'=>'#product'],'availableAtOrFrom'=>['@id'=>'#store'],'availability'=>'https://schema.org/InStock'];
$read=static fn(array $merchant,array $where,array $price,array $goods,array $time) =>
    vp3_phone_local_page_v1($html([$merchant,$where,$price,$goods,$time]),$public);
$yes=$read($store,$address,$offer,$product,$hours)['desert audio'];
local_research_check(str_contains($yes['hours'],'10:00–18:00 local time') && str_contains($yes['stock'],'in stock at this branch'),
    'reference-linked store, dated weekday hours and branch-scoped offer with a branded sized item');
local_research_check(!$read($store,array_replace($address,['addressLocality'=>'West Phoenix']),$offer,$product,$hours)
    && !$read($store,array_replace($address,['addressRegion'=>'CA']),$offer,$product,$hours)
    && !$read($store,array_replace($address,['addressCountry'=>'CA']),$offer,$product,$hours),
    'city boundaries, region and country evidence prevent false local matches');
foreach (['XLR cable tester','Travel case for XLR cables','XLR cable organizer','XLR cable 10 ft tester','Protective case with XLR cables'] as $wrong) {
    $other=$read($store,$address,$offer,array_replace($product,['name'=>$wrong]),$hours);
    local_research_check(str_contains($other['desert audio']['stock'],'unverified'),'accessory does not establish stock of the requested item: '.$wrong);
}
$online=$offer; unset($online['availableAtOrFrom']);
$online['availableDeliveryMethod']='https://schema.org/ParcelService';
$otherBranch=array_replace($offer,['availableAtOrFrom'=>['@id'=>'#tucson'],'availableDeliveryMethod'=>'https://schema.org/OnSitePickup']);
$expired=array_replace($offer,['availabilityEnds'=>'2026-11-22']);
foreach ([$online,$otherBranch,$expired] as $unproven) {
    $r=$read($store,$address,$unproven,$product,$hours);
    local_research_check(str_contains($r['desert audio']['stock'],'unverified'),'shipping-only, another branch or expired stock cannot establish local availability');
}
$closed=$store+['specialOpeningHoursSpecification'=>['validFrom'=>'2026-11-23','validThrough'=>'2026-11-23','opens'=>'00:00','closes'=>'00:00']];
local_research_check(!$read($closed,$address,$offer,$product,$hours),'a dated closure overrides the normal posted weekday schedule');
$compact=$store; unset($compact['openingHoursSpecification']); $compact['openingHours']='Mo-Fr 09:00-17:00';
local_research_check(str_contains($read($compact,$address,$offer,$product,$hours)['desert audio']['hours'],'09:00–17:00'),'compact schema.org weekday ranges use the requested local calendar date');

$compact['openingHours']='Mo 00:00-00:00';
local_research_check(!$read($compact,$address,$offer,$product,$hours),'explicit closed day in a compact schedule excludes an open-today candidate');
$holidayHours=$store+['specialOpeningHoursSpecification'=>['validFrom'=>'2026-11-23','validThrough'=>'2026-11-23','opens'=>'12:00','closes'=>'14:00']];
local_research_check(str_contains($read($holidayHours,$address,$offer,$product,$hours)['desert audio']['hours'],'12:00–14:00'),'a dated special schedule overrides ordinary business hours');
$urls=['https://desert.example.com/store','https://other.example.com/retail','https://third.example.com/phoenix','https://fourth.example.com/phoenix'];
$log=[];
$net=[
    'search'=>static function($query,$limit,$seconds) use (&$log,$urls) {
        $log[]=['search',$query];
        return ['ok'=>true,'results'=>array_map(static fn($url)=>['title'=>'Indexed hours and stock are never proof','url'=>$url],$urls)];
    },
    'request'=>static function($url,$domains,$guard,$resolver,$timeout) use (&$log,$urls,$html,$store,$address,$offer,$product,$hours) {
        $guard(); $log[]=['page',$url];
        if ($url===$urls[0]) return $html([$store,$address,$offer,$product,$hours]);
        return $html([['@type'=>'MusicStore','name'=>['','Second Sound','Third Sound','Fourth Sound'][array_search($url,$urls,true)],
            'address'=>$address]]);
    }
];
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
    CREATE TABLE vp3_phone_chat_turns_v1(assistant_message_id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,conversation_id INTEGER);
    CREATE TABLE vp3_phone_local_offers_v1(assistant_message_id INTEGER PRIMARY KEY REFERENCES vp3_phone_chat_turns_v1(assistant_message_id) ON DELETE CASCADE);
    INSERT INTO vp3_phone_chat_turns_v1 VALUES(40,1,2,3),(41,1,2,3),(42,1,2,3),(43,1,2,3),(44,1,2,3);
    INSERT INTO vp3_phone_local_offers_v1 VALUES(40),(41),(42),(43),(44)");
vp3_phone_local_research_schema_v1($db);
vp3_phone_local_research_schema_v1($db);
local_research_check(vp3_phone_local_research_ready_v1($db),'repeat install and readiness');
$user=['id'=>1]; $principal=['agent_id'=>2]; $cid=3;
$valid=static fn(int $id):bool=>true;
$enqueue=static function(int $id) use ($db,$user,$principal,$cid,$public) {
    $db->beginTransaction();
    try { vp3_phone_local_launch_v1($db,$user,$principal,$cid,$id,$public); $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); throw $e; }
};
$work=static fn($verify,$transport)=>vp3_phone_local_research_work_v1($db,$user,$principal,$cid,$verify,$transport);
$results=static fn($verify)=>vp3_phone_local_research_results_v1($db,$user,$principal,$cid,$verify);
$enqueue(40); $enqueue(40);
vp3_phone_local_research_work_v1($db,$user,['agent_id'=>4],3,$valid,$net);
local_research_check(!$log && $db->query('SELECT COUNT(*) FROM vp3_phone_local_research_v1')->fetchColumn()==1,
    'approval and its replay enqueue one scoped intent; another agent cannot dispatch it');
$work($valid,$net);
$work($valid,$net);
local_research_check($log[0]===['search',$expected] && count($log)===4,'one exact search and three bounded page reads despite replay');
$db->beginTransaction();
$sent=$results($valid);
$db->rollBack();
$db->beginTransaction();
$retried=$results($valid);
$db->commit();
local_research_check($sent===$retried && count($sent)===1 && $sent[0]['offer_id']===40 && count($sent[0]['sources'])===3
    && !str_contains($sent[0]['answer'],'Fourth') && str_contains($sent[0]['answer'],'unverified')
    && $sent[0]['sources'][0]['url']===$urls[0] && $sent[0]['sources'][0]['coverage']==='page_excerpt',
    'rollback-safe result with three real linked stores and separate evidence for hours and branch stock');
$db->beginTransaction(); local_research_check($results($valid)===[],'a committed result cannot be announced twice'); $db->commit();

$enqueue(41); $count=count($log);
$work(static fn($id)=>false,$net);
local_research_check(count($log)===$count && $db->query('SELECT state FROM vp3_phone_local_research_v1 WHERE offer_id=41')->fetchColumn()==='withdrawn',
    'fresh authorization precedes every external dispatch');
$enqueue(42);
$db->exec("UPDATE vp3_phone_local_research_v1 SET state='running',claimed_at=1 WHERE offer_id=42");
$work($valid,$net);
$db->beginTransaction(); $interrupted=$results($valid); $db->commit();
local_research_check(count($log)===$count && str_contains($interrupted[0]['answer'],'interrupted'),'a lost provider acknowledgement produces an honest result and no redispatch');
$enqueue(43); $work($valid,['search'=>static fn()=>['ok'=>false]]);
$db->beginTransaction(); $unavailable=$results($valid); $db->commit();
local_research_check(str_contains($unavailable[0]['answer'],'unavailable') && !$unavailable[0]['sources'],'provider outage yields no fabricated hours, stock or links');
$db->beginTransaction();
vp3_phone_local_launch_v1($db,$user,$principal,$cid,44,$public,time()-1);
$db->commit();
$count=count($log);
$work($valid,$net);
$db->beginTransaction(); $expired=$results($valid); $db->commit();
local_research_check(count($log)===$count && str_contains($expired[0]['answer'],'expired'),'original preview deadline limits dispatch across local midnight');
$db->exec('DELETE FROM vp3_phone_local_offers_v1 WHERE assistant_message_id=40');
local_research_check(!$db->query('SELECT 1 FROM vp3_phone_local_research_v1 WHERE offer_id=40')->fetchColumn(),'revoking the source offer removes its approved payload and cached report');
