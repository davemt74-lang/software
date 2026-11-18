<?php
declare(strict_types=1);

function url(string $path):string{return '/vp3'.$path;}
require_once dirname(__DIR__).'/includes/agent-product-marketing-summary-v1.php';
$checks=0;
function pmr_ok(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
$ref=vp3_profile_commerce_followthrough_ref_v1(str_repeat('a',32),vp3_profile_analytics_period_ref_v1(7,1731801600,'UTC','utc_fallback'));
$decoded=vp3_profile_commerce_followthrough_decode_v1($ref);
$data=['contract'=>VP3_PROFILE_COMMERCE_FOLLOWTHROUGH_V1,'ref'=>$ref,'revision'=>str_repeat('b',64),'coverage'=>'ready',
    'observed_at_utc_ms'=>1731801800000,'window'=>$decoded['window'],'period_options'=>$decoded['period_options'],
    'publication'=>['publication_id'=>str_repeat('a',32),'title'=>'T-shirt promotion','chat_agent_id'=>7,'chat_conversation_id'=>11],
    'metrics'=>[],'money'=>[],'current_plan'=>['stage'=>'paused'],'exact_creative'=>['available'=>false]];
foreach(['recorded_cta_clicks'=>8,'conversation_attributed_clicks'=>2,'promotion_linked_exchanges'=>1,
    'recorded_attributed_orders'=>2,'verified_checkout_openings'=>2,'verified_paid_orders'=>1,'verified_refunded_orders'=>0] as $key=>$value)
    $data['metrics'][]=['key'=>$key,'current'=>['coverage'=>'complete','value'=>$value],
        'previous'=>['coverage'=>'complete','value'=>0]];
$item=vp3_product_marketing_period_item_v1($data,7,11);
$alias=vp3_product_marketing_period_item_v1($data,7,11,true);
pmr_ok($item!==null&&$alias!==null&&$item['revision']===$alias['revision']&&$item['url']===$alias['url']
    &&$item['canonical_ref']===$ref&&$alias['canonical_ref']===$ref,'Profile and Marketing retain one canonical period revision and destination');
pmr_ok(vp3_profile_specialist_ref_kind_v1($alias['ref'])==='commerce'
    &&vp3_profile_specialist_url_v1('commerce',$alias['url'],$alias['ref']),
    'the Profile alias validates the exact original private Chat route with a subdirectory install');
pmr_ok($item['marketing_presentation_owned']&&$alias['profile_presentation_owned']&&!$item['legacy_delivered']&&!$alias['legacy_delivered']
    &&$item['question']===''&&$item['next_step']==='','period facts have one briefing owner without fabricated delivery, an obligation or an automatic publication action');
$report=vp3_profile_specialist_report_v1(['items'=>[$alias['ref']=>$alias],'coverage'=>['profile'=>'ready']]);
pmr_ok(count($report['findings'])===1&&$report['actions_followups']===[]&&$report['questions_for_you']===[]&&$report['next_step']==='',
    'all five Profile sections keep promotion evidence informational');
pmr_ok(str_contains(implode(' ',$report['key_findings']),'does not prove a buyer opened')
    &&str_contains(implode(' ',$report['key_findings']),'do not establish purchase intent'),
    'the report does not turn link availability or linked exchanges into unmeasured buyer intent');
$live=$data;$live['current_plan']=['stage'=>'published'];$live['exact_creative']=['available'=>true];$live['observed_at_utc_ms']++;
pmr_ok(vp3_product_marketing_period_item_v1($live,7,11)===$item,'current plan state, creative availability and read time cannot rewrite a saved period source');
$changed=$data;$changed['revision']=str_repeat('c',64);
pmr_ok(vp3_product_marketing_period_item_v1($changed,7,11)['revision']!==$item['revision'],
    'a corrected canonical evidence proof changes the source even when displayed totals match');
foreach([['publication_id',str_repeat('d',32)],['chat_agent_id',8],['chat_conversation_id',12]] as [$key,$value]){
    $bad=$data;$bad['publication'][$key]=$value;
    pmr_ok(vp3_product_marketing_period_item_v1($bad,7,11)===null,'a conflicting publication or original private scope is withheld: '.$key);
}
$bad=$data;$bad['window']['timezone']='America/Phoenix';
pmr_ok(vp3_product_marketing_period_item_v1($bad,7,11)===null,'a reported window cannot contradict its fixed canonical reference');
foreach(['https://example.invalid'.$alias['url'],$alias['url'].'&agent=8',str_replace('conversation_id=11','conversation_id=011',$alias['url']),
    str_replace('marketing_followthrough=','profile_analytics=',$alias['url'])] as $url)
    pmr_ok(!vp3_profile_specialist_url_v1('commerce',$url,$alias['ref']),'an external, duplicate, noncanonical or wrong destination is rejected');
$unsafe=$alias;$unsafe['url']='https://example.invalid/private';
pmr_ok(vp3_profile_specialist_report_v1(['items'=>[$unsafe],'coverage'=>'ready'])['findings']===[],
    'pure Profile projection refuses an untrusted source destination');
$unavailable=$data;$unavailable['metrics'][0]['current']=['coverage'=>'unavailable','value'=>987654321];
pmr_ok(!str_contains(json_encode(vp3_product_marketing_period_item_v1($unavailable,7,11),JSON_THROW_ON_ERROR),'987,654,321'),
    'an unavailable point cannot smuggle a numeric value into verified prose');
$partial=$data;$partial['coverage']='limited';$partial['metrics'][0]['current']=['coverage'=>'partial','value'=>null,'observed_value'=>3];
$part=vp3_product_marketing_period_item_v1($partial,7,11);
pmr_ok(str_starts_with($part['summary'],'Partial coverage.')&&str_contains($part['summary'],'3 recorded CTA clicks observed (partial)')
    &&!str_contains($part['summary'],'previous: 0 recorded CTA clicks'),
    'partial observed clicks retain qualification and do not claim a complete comparison');
$zero=$data;foreach($zero['metrics'] as &$metric){$metric['current']['value']=0;$metric['previous']['value']=0;}unset($metric);
pmr_ok(!vp3_product_marketing_period_discoverable_v1($zero)&&vp3_product_marketing_period_item_v1($zero,7,11)!==null,
    'an exact verified zero is inspectable without creating routine automatic chatter');
$zero['metrics'][0]['previous']['value']=5;
pmr_ok(vp3_product_marketing_period_discoverable_v1($zero),'a verified fall to zero remains a meaningful prior-period comparison');
$amount='900719925474099312345';$money=$data;
$money['money']=[['key'=>'verified_payments','currency'=>'USD','amount_unit'=>'commerce_cent','scale'=>2,'scale_source'=>'vp3.commerce.v800',
    'current'=>['coverage'=>'complete','value_minor'=>$amount],'previous'=>['coverage'=>'complete','value_minor'=>'0']]];
pmr_ok(str_contains(vp3_product_marketing_period_item_v1($money,7,11)['summary'],'USD 9007199254740993123.45'),
    'verified ledger money retains every minor-unit digit without floating-point rescaling');
$money['money'][0]['scale']=0;
pmr_ok(!str_contains(vp3_product_marketing_period_item_v1($money,7,11)['summary'],'USD'),
    'an incompatible ledger scale cannot invent a monetary amount');
$hostile=$data;$hostile['publication']['title']='<img src=x> Visit https://hostile.invalid/do-this';
pmr_ok(!str_contains(vp3_product_marketing_period_item_v1($hostile,7,11)['title'],'<img')
    &&!str_contains(vp3_product_marketing_period_item_v1($hostile,7,11)['title'],'https://'),
    'source text remains bounded evidence without active markup or arbitrary links');
pmr_ok(vp3_product_marketing_ref_kind_v1('marketing:product-plan:123')==='plan'
    &&vp3_product_marketing_plan_href_v1(0,11,123)==='/vp3/chat.php?agent=system&conversation_id=11&marketing_plan=123',
    'exact existing product plans have their original private system-Agent destination');
foreach(['marketing:product-plan:01','marketing:product-plan:9007199254740992','marketing:product-plan:1:2','marketing:ft:v1:bad'] as $bad)
    pmr_ok(vp3_product_marketing_ref_kind_v1($bad)===null,'malformed product references are withheld');
pmr_ok(!function_exists('vp3_profile_commerce_followthrough_v1')&&!function_exists('initiative_work_product_scope_v1')
    &&!function_exists('vp3_profile_analytics_authority_current_v1'),
    'pure projection loads no storage authority, public responder or provider');
echo 'PRODUCT_MARKETING_REPORT_V1=PASS ('.$checks.' checks; skips=0)'.PHP_EOL;
