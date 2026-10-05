<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/system-apps-v290.php';

function check16(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
}
function rejects16(callable $operation,string $label): void {
    try{$operation();}catch(RuntimeException $e){return;}
    throw new RuntimeException($label);
}
$key='control.demo';
$settings=['contract'=>'vp3.app.settings.v1','app_key'=>$key,
 'schema'=>['fields'=>[['key'=>'api_token','secret'=>true]]],
 'values'=>['api_token'=>['configured'=>true,'secret'=>true]],'secret_values_exposed'=>false];
$reply=static fn(int $uid,string $op,array $body): array=>$settings;
check16(vp3_app_control_settings_v290(1,$key,$reply)['values']===$settings['values'],'valid settings failed');
foreach([[],['ok'=>false,'app_key'=>$key],array_replace($settings,['app_key'=>'other.app']),array_diff_key($settings,['app_key'=>true])] as $bad){
    rejects16(fn()=>vp3_app_control_settings_v290(1,$key,fn()=>$bad),'invalid acknowledgement accepted');
}
$leak=$settings;$leak['values']['api_token']='do-not-expose';
rejects16(fn()=>vp3_app_control_settings_v290(1,$key,fn()=>$leak),'secret leak accepted');
$status=['app_key'=>$key,'settings'=>$settings,'manifest'=>[],'compatibility'=>[],'hosting'=>[]];
check16(vp3_app_control_status_v290(1,$key,fn()=>$status)['app_key']===$key,'valid status failed');
$receipt=['contract'=>'vp3.app.agent-action-result.v1','app_key'=>$key,'action'=>'demo.status','risk'=>'read','result'=>[]];
check16(vp3_app_control_invoke_v290(1,$key,'demo.status',[],false,fn()=>$receipt)===$receipt,'valid action failed');
foreach(['action'=>'demo.reset','contract'=>'unknown','app_key'=>'other.app'] as $field=>$wrong){
    $bad=array_replace($receipt,[$field=>$wrong]);
    rejects16(fn()=>vp3_app_control_invoke_v290(1,$key,'demo.status',[],false,fn()=>$bad),'mismatched receipt accepted');
}
rejects16(fn()=>vp3_app_control_settings_set_v290(1,$key,[],false,$reply),'unconfirmed settings accepted');
$calls=0;
$write=vp3_app_control_settings_set_v290(1,$key,['theme'=>'dark'],true,function($uid,$op,$body)use(&$calls,$settings){
    check16($op==='apps.control.settings.set'&&$body['confirmed']===true,'confirmation not transported');
    $calls++;return $settings;
});
check16($calls===1&&$write===$settings,'confirmed settings failed');
echo "Section 16 Cloud app control receipts: PASS\n";
