<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/agent-marketing-native-ab-v1.php';
function test_ab(bool $pass,string $reason):void {if(!$pass)throw new RuntimeException($reason);echo "PASS $reason\n";}
$a=['journey_key'=>'welcome','step_key'=>'entry','variants'=>[
 ['journey_key'=>'welcome','step_key'=>'entry','variant_key'=>'b','sent'=>40,'viewed'=>20,'converted'=>3,'private_contact'=>'SECRET'],
 ['journey_key'=>'welcome','step_key'=>'entry','variant_key'=>'a','sent'=>50,'viewed'=>30,'converted'=>2]
]];
$p=vp3_marketing_native_ab_project_v1([$a]);
test_ab($p['contract']===VP3_MARKETING_NATIVE_AB_V1,'native contract');
test_ab($p['state']==='observed'&&$p['groups'][0]['state']==='observed','two valid observed variants');
test_ab($p['groups'][0]['variants'][0]['variant_key']==='a','source variants are stable sorted');
test_ab($p['groups'][0]['variants'][1]['converted']===3,'canonical counts preserved');
test_ab($p['scope']==='lifetime_message_level_descriptive_not_causal','not a causal experiment');
test_ab(!str_contains(json_encode($p,JSON_THROW_ON_ERROR),'SECRET'),'contact details removed');
$bad=$a;$bad['variants'][0]['sent']=5;$bad['variants'][0]['viewed']=2;$bad['variants'][0]['converted']=1;
test_ab(vp3_marketing_native_ab_project_v1([$bad])['groups'][0]['state']==='insufficient_sample','low sample labeled not compared');
$bad=$a;$bad['variants'][0]['sent']=null;
test_ab(vp3_marketing_native_ab_project_v1([$bad])['state']==='no_comparable_variants','unknown count not a zero');
$bad=$a;$bad['variants'][0]['converted']=41;
test_ab(vp3_marketing_native_ab_project_v1([$bad])['groups']===[],'invalid conversion count withheld');
$bad=$a;$bad['variants'][1]['variant_key']='b';
test_ab(vp3_marketing_native_ab_project_v1([$bad])['groups']===[],'duplicate variant withheld');
$bad=$a;$bad['variants'][0]['step_key']='other';
test_ab(vp3_marketing_native_ab_project_v1([$bad])['groups']===[],'cross-step source rejected');
$bad=$a;$bad['journey_key']='../../secret';
test_ab(vp3_marketing_native_ab_project_v1([$bad])['groups']===[],'unsafe journey key rejected');
test_ab(vp3_marketing_native_ab_project_v1([])['state']==='no_comparable_variants','empty source is not measured zero');
$bad=$a;$bad['variants'][0]['viewed']=41;
test_ab(vp3_marketing_native_ab_project_v1([$bad])['groups']===[],'invalid view counts withheld');
$bad=$a;$bad['variants'][0]['converted']=-1;
test_ab(vp3_marketing_native_ab_project_v1([$bad])['groups']===[],'negative count rejected');
$bad=$a;$bad['variants'][0]['sent']=40;$bad['variants'][0]['converted']=0;
test_ab(vp3_marketing_native_ab_project_v1([$bad])['groups'][0]['variants'][1]['converted']===0,'verified zero count preserved');
if(in_array('sqlite',PDO::getAvailableDrivers(),true)){
    $pdo=new PDO('sqlite::memory:');
    test_ab(vp3_marketing_native_ab_read_v1($pdo,11,42)['state']==='unavailable','missing canonical tables fail closed');
}
echo "Native A/B checks passed\n";
