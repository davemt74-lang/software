<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/initiative-workbench-v1.php';
require_once dirname(__DIR__).'/includes/initiative-marketing-editor-v1.php';

$checks=0;$revision=0;$writes=0;$can=true;
function editor_check(bool $good,string $label): void {
    if(!$good)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function editor_denied(int $status,callable $action,string $label): void {
    try{$action();}catch(Throwable $error){
        editor_check((int)$error->getCode()===$status,$label.' ('.$error->getMessage().')');return;
    }
    throw new RuntimeException('Expected denial: '.$label);
}
function url(string $path): string{return '/vp3'.$path;}
function has_permission(string $p,array $user): bool{return $p==='account.access'&&!empty($user['account']);}
function vp3_coordination_marketing_owner_v1(PDO $pdo,array $scope): ?int{
    return $GLOBALS['can']?(int)($scope['user']['id']??0):null;
}
function campaigns_rewards_platform_schema_ready_v100(PDO $pdo): bool{return true;}
function campaigns_rewards_platform_can_v100(PDO $pdo,int $m,int $o,string $p,bool $lock=false): bool{
    return $GLOBALS['can']&&$m===7&&$o===1&&in_array($p,['campaigns.view','campaigns.create','campaigns.edit'],true);
}
function campaigns_rewards_platform_assert_can_v100(PDO $pdo,int $m,int $o,string $p,bool $lock=false): void{
    if(!campaigns_rewards_platform_can_v100($pdo,$m,$o,$p,$lock))throw new RuntimeException('Denied',403);
}
function campaigns_rewards_reward_products_v110(PDO $pdo,int $m,int $o): array {
    return [['id'=>3,'name'=>'Free coffee','is_active'=>1,'reward_type_name'=>'Free product'],
        ['id'=>8,'name'=>'Inactive coupon','is_active'=>0],['id'=>9,'name'=>'Private cost $40','is_active'=>1,'internal_cost_minor'=>4000]];
}
function campaigns_rewards_locations_v100(PDO $pdo,int $m): array {return [['id'=>4,'name'=>'Flagship']];}
function campaigns_rewards_campaign_v100(PDO $pdo,int $id,bool $lock=false): ?array{return $GLOBALS['campaignRows'][$id]??null;}
function campaigns_rewards_campaign_reward_ids_v118(PDO $pdo,int $id): array{return $GLOBALS['campaignRewards'][$id]??[];}
function campaigns_rewards_slug_v100(string $s,int $max=120): string{
    return substr(trim(preg_replace('/[^a-z0-9]+/','-',strtolower($s)),'-'),0,$max);
}
function campaigns_rewards_idempotency_begin_v100(PDO $pdo,int $m,string $op,string $key,array $req=[],bool $lock=false): array{
    $handle=$m.':'.$op.':'.$key;$hash=hash('sha256',json_encode($req,JSON_THROW_ON_ERROR));
    if(isset($GLOBALS['receipts'][$handle])){
        $row=$GLOBALS['receipts'][$handle];if($row['request_hash']!==$hash)throw new DomainException('Conflict',409);
        return ['new'=>false]+$row;
    }
    $row=['id'=>count($GLOBALS['receipts']??[])+1,'status'=>'started','request_hash'=>$hash];
    $GLOBALS['receipts'][$handle]=$row;$GLOBALS['receiptIndex'][$row['id']]=$handle;
    return ['new'=>true]+$row;
}
function campaigns_rewards_idempotency_complete_v100(PDO $pdo,int $receipt,string $type,int|string $id): void{
    $key=$GLOBALS['receiptIndex'][$receipt];$GLOBALS['receipts'][$key]['status']='completed';
    $GLOBALS['receipts'][$key]['result_ref_type']=$type;$GLOBALS['receipts'][$key]['result_ref_id']=(string)$id;
}
function campaigns_rewards_save_campaign_v100(PDO $pdo,int $m,int $o,array $input,int $id=0): array{
    if($m!==7||$o!==1||!$GLOBALS['can']||($input['profile_visible']??null)!==false
        ||($input['reward_selection_present']??null)!==1
        ||isset($input['status'])||isset($input['environment']))throw new RuntimeException('Unsafe canonical input');
    $GLOBALS['writes']++;
    if($id===0){
        $pdo->prepare('INSERT INTO campaigns(merchant_id) VALUES(?)')->execute([$m]);
        $id=(int)$pdo->lastInsertId();
    }
    $GLOBALS['revision']++;
    $GLOBALS['campaignRows'][$id]=[
        'id'=>$id,'merchant_id'=>$m,'merchant_owner_user_id'=>$o,
        'status'=>'draft','environment'=>'production','title'=>$input['title'],
        'campaign_type_key'=>$input['campaign_type'],'slug'=>$input['slug'],
        'subtitle'=>$input['subtitle'],'cta_label'=>$input['cta_label'],
        'objective'=>$input['objective'],'description'=>$input['description'],'terms'=>$input['terms'],
        'starts_at'=>$input['starts_at'],'ends_at'=>$input['ends_at'],
        'location_id'=>$input['location_id'],'updated_at'=>(string)$GLOBALS['revision'],
        'current_version_no'=>0];
    $GLOBALS['campaignRewards'][$id]=$input['reward_ids'];
    return $GLOBALS['campaignRows'][$id];
}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE merchant_accounts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,name TEXT,status TEXT,sandbox_mode INTEGER)');
$pdo->exec("INSERT INTO merchant_accounts VALUES(7,1,'Owner Coffee','active',0),(8,2,'Foreign merchant','active',0)");
$pdo->exec('CREATE TABLE campaign_types(type_key TEXT,name TEXT,description TEXT,reward_rules_schema_json TEXT,landing_schema_json TEXT,merchant_id INTEGER,is_active INTEGER,is_system INTEGER)');
$pdo->exec("INSERT INTO campaign_types VALUES('signup','Signup','Public signup','{\"requires_reward\":0}','{\"cta\":\"Sign up\"}',NULL,1,1)");
$pdo->exec('CREATE TABLE campaigns(id INTEGER PRIMARY KEY AUTOINCREMENT,merchant_id INTEGER)');
$scope=['user'=>['id'=>1,'account'=>true],'agent_id'=>7,'conversation_id'=>11];
$catalog=vp3_marketing_editor_action_v1($pdo,1,$scope,['action'=>'marketing_editor_options'],'secret');
editor_check(count($catalog['catalog']['merchants'])===1&&$catalog['catalog']['merchant_id']===7,'merchant catalog excludes foreign business');
editor_check(count($catalog['catalog']['rewards'])===2&&!isset($catalog['catalog']['rewards'][0]['internal_cost_minor']),'only public Reward selector fields');
$fields=['title'=>'Free Coffee Week','campaign_type'=>'signup','slug'=>'free-coffee-week',
    'subtitle'=>'Limited time','cta_label'=>'Join','objective'=>'Welcome local guests',
    'description'=>'An owner-approved draft','terms'=>'Use once',
    'starts_at'=>'2026-12-01T12:00','ends_at'=>'2026-12-03T12:00',
    'location_id'=>4,'reward_ids'=>[3]];
$body=['action'=>'marketing_editor_preview','merchant_id'=>7,'campaign_id'=>0,
    'revision'=>'new','request_key'=>str_repeat('a',32),'fields'=>$fields];
$preview=vp3_marketing_editor_action_v1($pdo,1,$scope,$body,'secret');
editor_check($preview['preview']['status']==='draft'&&$preview['preview']['will_publish']===false,'preview is private and read-only');
editor_check($writes===0,'preview has no database write');
$saveBody=$body; $saveBody['action']='marketing_editor_save';$saveBody['confirmed']=true;
$saveBody['review_token']=$preview['review_token'];
$saved=vp3_marketing_editor_action_v1($pdo,1,$scope,$saveBody,'secret');
$id=$saved['draft']['id'];
editor_check($saved['saved']&&$saved['published']===false&&$id>0&&$writes===1,'verified private draft saved once');
editor_check($campaignRows[$id]['status']==='draft'&&$campaignRewards[$id]===[3],'canonical fields and selected Reward persisted');
$again=vp3_marketing_editor_action_v1($pdo,1,$scope,$saveBody,'secret');
editor_check($again['draft']['id']===$id&&$writes===1,'repeat submit is idempotent');
$open=vp3_marketing_editor_action_v1($pdo,1,$scope,['action'=>'marketing_editor_open','campaign_id'=>$id],'');
editor_check($open['draft']['revision']===$saved['draft']['revision']
    &&$open['draft']['fields']['reward_ids']===[3],'existing draft includes revision and selected reward');
$edit=$body;$edit['campaign_id']=$id;$edit['revision']=$open['draft']['revision'];
$edit['request_key']=str_repeat('b',32);$edit['fields']['title']='Updated Coffee';
$p2=vp3_marketing_editor_action_v1($pdo,1,$scope,$edit,'secret');
$edit['action']='marketing_editor_save';$edit['confirmed']=true;$edit['review_token']=$p2['review_token'];
$changed=vp3_marketing_editor_action_v1($pdo,1,$scope,$edit,'secret');
editor_check($changed['draft']['fields']['title']==='Updated Coffee'&&$writes===2,'explicit edit updates only an owned draft');
$stale=$edit;$stale['request_key']=str_repeat('c',32);
editor_denied(409,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$stale,'secret'),'stale revision refused');
$active=$id;$campaignRows[$active]['status']='active';
editor_denied(409,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,['action'=>'marketing_editor_open','campaign_id'=>$active],''),
    'published or active Campaign cannot be inline edited');
$campaignRows[$active]['status']='draft';
$illegal=$body;$illegal['fields']['reward_ids']=[999];
editor_denied(422,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$illegal,'secret'),'foreign Reward rejected');
$illegal=$body;$illegal['fields']['status']='active';
editor_denied(422,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$illegal,'secret'),'status injection rejected');
$illegal=$body;$illegal['fields']['starts_at']='2026-12-99T12:00';
editor_denied(422,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$illegal,'secret'),'invalid date rejected');
$illegal=$body;$illegal['fields']['ends_at']='2026-11-30T12:00';
editor_denied(422,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$illegal,'secret'),'reversed schedule rejected');
$fake=$saveBody;$fake['review_token']=str_repeat('e',64);
editor_denied(422,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$fake,'secret'),'unsigned save rejected');
$can=false;
editor_denied(403,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,['action'=>'marketing_editor_options'],''),
    'revoked owner permission blocks catalog');
$can=true;
editor_denied(403,fn()=>vp3_marketing_editor_action_v1($pdo,2,$scope,['action'=>'marketing_editor_options'],''),
    'wrong owner account denied');
editor_check($writes===2,'denied and preview operations never mutate Campaign');
echo "Private Campaign editor: $checks checks passed".PHP_EOL;
