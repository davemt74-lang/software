<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/initiative-workbench-v1.php';
require_once dirname(__DIR__).'/includes/initiative-marketing-activation-v1.php';

$checks=0;$activationCalls=0;$outbound=0;$issues=0;$authorized=true;$schemaReady=true;
$readinessState='ready';$rewardCount=1;$typeReady=true;$changeDuringRead=false;
$permissions=['campaigns.view'=>true,'campaigns.launch'=>true,'campaigns.publish'=>true];
function activation_check(bool $yes,string $label):void{
    if(!$yes)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function activation_denied(int $status,callable $work,string $label):void{
    try{$work();}catch(Throwable $error){
        activation_check((int)$error->getCode()===$status,$label.' ('.$error->getCode().')');
        return;
    }
    throw new RuntimeException('Expected denied: '.$label);
}
function has_permission(string $name,array $user):bool {
    return $name==='account.access'&&!empty($user['account']);
}
function vp3_coordination_marketing_owner_v1(PDO $pdo,array $scope):?int {
    return $GLOBALS['authorized']&&($scope['agent_id']??null)===7&&($scope['conversation_id']??null)===11
        ?(int)($scope['user']['id']??0):null;
}
function campaigns_rewards_platform_schema_ready_v100(PDO $pdo):bool{return $GLOBALS['schemaReady'];}
function campaigns_rewards_campaign_platform_v100(PDO $pdo,int $id,bool $lock=false):?array{
    if($id!==6)return null;
    return $GLOBALS['campaign'];
}
function campaigns_rewards_platform_can_v100(PDO $pdo,int $merchant,int $owner,string $cap,bool $lock=false):bool{
    return $merchant===7&&$owner===1&&$GLOBALS['authorized']&&!empty($GLOBALS['permissions'][$cap]);
}
function vp3_marketing_campaign_readiness_v1(PDO $pdo,int $owner,int $merchant,int $id,DateTimeImmutable $now):?array {
    if($id!==6||$owner!==1||$merchant!==7)return null;
    if($GLOBALS['changeDuringRead'])$GLOBALS['authorized']=false;
    $state=$GLOBALS['readinessState'];
    return ['state'=>$state,'checks'=>[
        'location'=>['state'=>'ready','detail'=>'Active location verified'],
        'reward'=>['state'=>$state==='ready'?'ready':'limited','detail'=>'Current reward availability'],
        'claim_code'=>['state'=>'ready','detail'=>'Claim path verified'],
        'audience'=>['state'=>'ready','detail'=>'Marketing audience verified']
    ]];
}
function campaigns_rewards_validate_campaign_activation_v118(PDO $pdo,array $campaign):void{
    if(!$GLOBALS['typeReady']||$GLOBALS['rewardCount']<1)
        throw new RuntimeException('Campaign Type requires an active Reward');
}
function campaigns_rewards_campaign_reward_ids_v118(PDO $pdo,int $id):array{
    return $GLOBALS['rewardCount']>0?[18]:[];
}
function campaigns_rewards_idempotency_begin_v100(PDO $pdo,int $merchant,string $op,string $key,array $request=[],bool $lock=false):array{
    $hash=hash('sha256',json_encode($request,JSON_THROW_ON_ERROR));
    $lookup=$pdo->prepare('SELECT * FROM receipts WHERE merchant=? AND operation=? AND request_key=?');
    $lookup->execute([$merchant,$op,$key]);$existing=$lookup->fetch(PDO::FETCH_ASSOC);
    if($existing){
        if(!hash_equals($existing['request_hash'],$hash))throw new DomainException('Different request reused the same nonce.',409);
        return ['new'=>false]+$existing;
    }
    $pdo->prepare("INSERT INTO receipts(merchant,operation,request_key,request_hash,status) VALUES(?,?,?,?,'started')")
        ->execute([$merchant,$op,$key,$hash]);
    return ['new'=>true,'id'=>(int)$pdo->lastInsertId()];
}
function campaigns_rewards_idempotency_complete_v100(PDO $pdo,int $id,string $type,int|string $ref):void{
    $pdo->prepare("UPDATE receipts SET status='completed',result_ref_type=?,result_ref_id=? WHERE id=?")
        ->execute([$type,(string)$ref,$id]);
}
function campaigns_rewards_set_campaign_lifecycle_v100(PDO $pdo,int $id,int $owner,string $status):array{
    if($status!=='active'||$id!==6||$owner!==1)throw new RuntimeException('Wrong lifecycle operation');
    $GLOBALS['activationCalls']++;
    $GLOBALS['campaign']['status']='active';$GLOBALS['campaign']['current_version_no']++;
    $pdo->prepare("UPDATE campaign_landing_pages SET is_published=CASE WHEN visibility='private' THEN 0 ELSE 1 END WHERE campaign_id=?")
        ->execute([$id]);
    return $GLOBALS['campaign'];
}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE campaign_landing_pages (campaign_id INTEGER PRIMARY KEY,visibility TEXT,is_published INTEGER,headline TEXT,cta_label TEXT,updated_at TEXT)");
$pdo->exec("INSERT INTO campaign_landing_pages VALUES (6,'unlisted',0,'Coffee welcome','Visit','2026-11-20 12:00:00')");
$pdo->exec("CREATE TABLE receipts(id INTEGER PRIMARY KEY AUTOINCREMENT, merchant INTEGER, operation TEXT, request_key TEXT,request_hash TEXT,status TEXT,result_ref_type TEXT,result_ref_id TEXT,UNIQUE(merchant,operation,request_key))");
$scope=['agent_id'=>7,'conversation_id'=>11,'user'=>['id'=>1,'account'=>true]];
$campaign=[
    'id'=>6,'merchant_id'=>7,'merchant_owner_user_id'=>1,'merchant_status'=>'active','merchant_sandbox_mode'=>0,
    'environment'=>'production','status'=>'draft','current_version_no'=>0,'updated_at'=>'2026-11-20 11:59:00',
    'name'=>'Coffee welcome','slug'=>'coffee-welcome','campaign_type_key'=>'signup',
    'starts_at'=>null,'ends_at'=>null,
];
$open=vp3_marketing_activation_action_v1($pdo,1,$scope,['action'=>'marketing_activation_open','campaign_id'=>6],'');
$view=$open['activation'];
activation_check($view['id']===6&&$view['status']==='draft'&&$view['readiness']==='ready'&&$view['can_activate'],
    'owner sees current ready Campaign only');
activation_check($view['landing_visibility']==='unlisted'&&$view['landing_published_on_activation']===true,
    'review discloses that unlisted landing becomes published');
activation_check($view['will_send_messages_directly']===false&&$view['will_issue_rewards_directly']===false
    &&$view['may_enable_configured_automations']===true,'side effects and future automation are disclosed');
activation_check($view['reward_count']===1&&count($view['checks'])===4&&strlen($view['revision'])===64,
    'reward count, four readiness checks and stable version digest projected');
activation_check($activationCalls===0&&$outbound===0&&$issues===0&&
    (int)$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn()===0,
    'opening the review performs no write');
$previewBody=['action'=>'marketing_activation_preview','campaign_id'=>6,
    'revision'=>$view['revision'],'request_key'=>str_repeat('a',32)];
$preview=vp3_marketing_activation_action_v1($pdo,1,$scope,$previewBody,'key-one');
activation_check($preview['activation']['can_activate']===true&&
    preg_match('/\A\d{1,12}\.[a-f0-9]{64}\z/D',$preview['review_token'])===1,
    'ready Campaign receives short-lived signed activation preview');
activation_check($activationCalls===0&&(int)$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn()===0,
    'preview cannot activate or mutate Campaign');
$confirm=$previewBody;$confirm['action']='marketing_activation_confirm';
$confirm['confirmed']=true;$confirm['review_token']=$preview['review_token'];
$done=vp3_marketing_activation_action_v1($pdo,1,$scope,$confirm,'key-one');
activation_check($done['activated']===true&&$done['campaign']['status']==='active'&&
    $done['replayed']===false&&$activationCalls===1,'confirmed lifecycle invokes canonical service once');
activation_check((int)$pdo->query('SELECT is_published FROM campaign_landing_pages WHERE campaign_id=6')->fetchColumn()===1,
    'canonical lifecycle publishes eligible saved landing page');
activation_check($outbound===0&&$issues===0,'Chat activation never directly sends or issues Rewards');
$replay=vp3_marketing_activation_action_v1($pdo,1,$scope,$confirm,'key-one');
activation_check($replay['activated']===true&&$replay['replayed']===true&&$activationCalls===1,
    'repeated network submit returns receipt without activating again');
activation_denied(409,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,$previewBody,'key-one'),
    'active Campaign no longer allows another activation review');
$campaign['status']='draft';$campaign['current_version_no']=0;
$campaign['updated_at']='2026-11-20 11:59:00';
$attempt=$previewBody;$attempt['request_key']=str_repeat('b',32);
activation_denied(422,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,
    $attempt+['confirmed'=>true,'review_token'=>'bad'],'key-one'),'missing valid confirmation denied');
$fake=$confirm;$fake['request_key']=str_repeat('b',32);
activation_denied(409,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,$fake,'key-one'),
    'replayed token cannot approve a different nonce');
$foreignScope=$scope;$foreignScope['user']['id']=2;
activation_denied(403,fn()=>vp3_marketing_activation_action_v1($pdo,1,$foreignScope,
    ['action'=>'marketing_activation_open','campaign_id'=>6],''),'foreign owner cannot review Campaign');
$wrongChat=$scope;$wrongChat['conversation_id']=12;
activation_denied(403,fn()=>vp3_marketing_activation_action_v1($pdo,1,$wrongChat,
    ['action'=>'marketing_activation_open','campaign_id'=>6],''),'wrong Chat denied by source scope');
$authorized=false;
activation_denied(403,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,
    ['action'=>'marketing_activation_open','campaign_id'=>6],''),'revoked Marketing permission fails closed');
$authorized=true;$permissions['campaigns.publish']=false;
activation_denied(403,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,
    ['action'=>'marketing_activation_open','campaign_id'=>6],''),'publish permission required for lifecycle exposure');
$permissions['campaigns.publish']=true;
$campaign['merchant_sandbox_mode']=1;
activation_denied(403,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,
    ['action'=>'marketing_activation_open','campaign_id'=>6],''),'sandbox Campaign denied');
$campaign['merchant_sandbox_mode']=0;
$campaign['merchant_owner_user_id']=9;
activation_denied(403,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,
    ['action'=>'marketing_activation_open','campaign_id'=>6],''),'foreign Merchant owner denied');
$campaign['merchant_owner_user_id']=1;
$readinessState='limited';
$blocked=vp3_marketing_activation_action_v1($pdo,1,$scope,['action'=>'marketing_activation_open','campaign_id'=>6],'')['activation'];
activation_check(!$blocked['can_activate']&&$blocked['readiness']==='limited'&&
    count($blocked['blockers'])>=1,'missing evidence visibly blocks activation');
$readinessState='ready';
$campaign['starts_at']='2098-12-01 12:00:00';
$future=vp3_marketing_activation_action_v1($pdo,1,$scope,['action'=>'marketing_activation_open','campaign_id'=>6],'')['activation'];
activation_check(!$future['can_activate']&&count($future['blockers'])>0,'future Campaign must remain scheduled');
$campaign['starts_at']=null;$campaign['ends_at']='2020-01-01 12:00:00';
$past=vp3_marketing_activation_action_v1($pdo,1,$scope,['action'=>'marketing_activation_open','campaign_id'=>6],'')['activation'];
activation_check(!$past['can_activate'],'expired Campaign cannot activate');
$campaign['ends_at']=null;$rewardCount=0;
$noRewards=vp3_marketing_activation_action_v1($pdo,1,$scope,['action'=>'marketing_activation_open','campaign_id'=>6],'')['activation'];
activation_check(!$noRewards['can_activate'],'Campaign Type validation blocks missing Reward');
$rewardCount=1;$changeDuringRead=true;
activation_denied(403,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,
    ['action'=>'marketing_activation_open','campaign_id'=>6],''),'mid-read permission revocation discards evidence');
$changeDuringRead=false;$authorized=true;
$openAgain=vp3_marketing_activation_action_v1($pdo,1,$scope,['action'=>'marketing_activation_open','campaign_id'=>6],'')['activation'];
$stale=$openAgain['revision'];$campaign['updated_at']='2026-11-20 12:05:00';
activation_denied(409,fn()=>vp3_marketing_activation_action_v1($pdo,1,$scope,[
    'action'=>'marketing_activation_preview','campaign_id'=>6,'request_key'=>str_repeat('c',32),'revision'=>$stale
],'key-one'),'stale Campaign revision rejects approval');
activation_check($activationCalls===1&&$outbound===0&&$issues===0,'blocked requests never activate or publish');
echo 'Marketing activation: '.$checks.' safety and lifecycle checks passed'.PHP_EOL;
