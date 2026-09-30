<?php
declare(strict_types=1);

if(!function_exists('url')){
    function url(string $path=''): string { return $path; }
}
require dirname(__DIR__).'/includes/system-apps-v190.php';

$prepared=vp3_system_apps_action_card_v190([
  'action_id'=>'appact_test',
  'action_type'=>'install',
  'app_key'=>'vp3.inventory',
  'preview'=>['app'=>'VP3 Inventory','target'=>'HomeServer'],
  'confirmation_code'=>'ABCD2345',
  'expires_in_seconds'=>600,
]);
if(($prepared['status']??'')!=='prepared')throw new RuntimeException('Prepared action card status mismatch.');
if(($prepared['confirmation_code']??'')!=='ABCD2345')throw new RuntimeException('Prepared action card lost confirmation code.');
$confirm=array_values(array_filter($prepared['actions'],static fn(array $a):bool=>(string)($a['type']??'')==='prompt'))[0]??null;
if(!$confirm||($confirm['prompt']??'')!=='confirm app ABCD2345')throw new RuntimeException('Prepared card did not create canonical confirmation prompt.');

$completed=vp3_system_apps_action_card_v190([
  'action_id'=>'appact_done',
  'action_type'=>'hosting.bind',
  'app_key'=>'vp3.inventory',
  'preview'=>['app'=>'VP3 Inventory','hosting_site'=>'Inventory Host','hostname'=>'inventory.example.com'],
  'completed'=>true,
  'result'=>[
    'reconcile_pending'=>true,
    'system_apps'=>[
      'apps'=>[[
        'app_key'=>'vp3.inventory',
        'public_url'=>'https://inventory.example.com',
      ]],
    ],
  ],
]);
if(($completed['status']??'')!=='completed'||empty($completed['reconcile_pending']))throw new RuntimeException('Completed reconcile-pending card mismatch.');
$labels=array_map(static fn(array $a):string=>(string)($a['label']??''),(array)$completed['actions']);
foreach(['Open Apps','Manage Hosting','Open hosted app'] as $label)if(!in_array($label,$labels,true))throw new RuntimeException('Completed card missing '.$label.' action.');

$failed=vp3_system_apps_action_card_v190([
  'status'=>'failed',
  'error'=>'HomeServer offline',
]);
if(($failed['status']??'')!=='failed'||($failed['error']??'')!=='HomeServer offline')throw new RuntimeException('Failed card mismatch.');
foreach((array)$failed['actions'] as $action)if(($action['type']??'')==='prompt')throw new RuntimeException('Failed card exposed confirmation execution.');

$cap=vp3_system_apps_capability_v190();
foreach(['persistent_action_cards','button_confirmation','action_progress_states','reconcile_pending_ux','post_action_navigation','typed_confirmation_fallback'] as $key){
    if(empty($cap[$key]))throw new RuntimeException('Missing action UX capability '.$key);
}

echo "System Apps Agent Integration Section 5 action UX runtime: PASS
";
