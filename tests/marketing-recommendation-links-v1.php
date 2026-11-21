<?php
declare(strict_types=1);

/** Isolated current-authority and real SQLite persistence fixture for private Campaign links. */
function link_check(bool $yes,string $label):void{
    if(!$yes)throw new RuntimeException('FAIL '.$label);
    $GLOBALS['linkChecks']++; echo 'PASS '.$label.PHP_EOL;
}
function link_reject(callable $action,int $status,string $label):void{
    try{$action();}catch(Throwable $e){link_check((int)$e->getCode()===$status,$label);return;}
    throw new RuntimeException('FAIL rejection '.$label);
}
function has_permission(string $key,array $user):bool{return $key==='account.access'&&($user['account']??false);}
function vp3_coordination_marketing_owner_v1(PDO $pdo,array $scope):?int {
    return ($GLOBALS['authorized']??true)&&($scope['principal']['viewer_user_id']??null)===($scope['user']['id']??null)
        ? (int)$scope['user']['id']:null;
}
function schema_metadata_table_exists(PDO $pdo,string $table):bool{
    $q=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE name=? AND type='table'");$q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function campaigns_rewards_campaign_platform_v100(PDO $pdo,int $campaign,bool $lock=false):?array{
    $q=$pdo->prepare('SELECT * FROM campaigns WHERE id=?');$q->execute([$campaign]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function campaigns_rewards_platform_can_v100(PDO $pdo,int $merchant,int $owner,string $cap):bool {
    return ($GLOBALS['authorized']??true)&&$merchant===17&&$owner===11
        &&in_array($cap,['campaigns.view','campaigns.edit','analytics.view'],true);
}
function vp3_marketing_campaign_outcomes_v1(PDO $pdo,int $owner,int $merchant,int $campaign,DateTimeImmutable $now):array {
    if(!$GLOBALS['accepted']||$owner!==11||$merchant!==17||$campaign!==77)return ['followthrough'=>[]];
    return ['followthrough'=>[['recommendation_id'=>501,'state'=>$GLOBALS['observation'],
        'after'=>['window_end'=>'2026-11-18T00:00:00+00:00']]]];
}
function initiative_marketing_plans_query_v1():string {
    return 'SELECT l.product_id,l.owner_user_id,l.agent_id,l.conversation_id,l.draft_key,l.creative_id
        FROM agent_product_launches_v1 l JOIN agent_commerce_products_v800 p ON p.id=l.product_id AND p.owner_user_id=l.owner_user_id
        JOIN chat_conversations c ON c.id=l.conversation_id AND c.user_id=l.owner_user_id AND c.user_agent_id=l.agent_id
        WHERE l.owner_user_id=? AND l.agent_id=?';
}
function initiative_marketing_plan_view_v1(PDO $pdo,int $owner,array $row):array {
    if($owner!==11||(int)$row['agent_id']!==7)throw new DomainException('Plan unavailable',403);
    return ['product_title'=>'Saved product draft','stage'=>$GLOBALS['stage'],'needs_attention'=>$GLOBALS['stage']==='needs_attention'];
}
function vp3_marketing_campaign_json_v1(PDO $pdo,string $column,string $path,bool $text=false):string{
    return "JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
}
function vp3_coordination_source_ref_valid_v1(string $ref):bool{
    return preg_match('/\Amarketing:campaign-work:[1-9][0-9]{0,17}\z/D',$ref)===1;
}
function vp3_coordination_source_item_v1(string $ref,string $specialist,string $title,string $summary,
    string $next,string $question,string $href,string $status,string $priority,array $revision):array {
    return compact('ref','specialist','title','summary','question','status','priority')+[
        'next_step'=>$next,'url'=>$href,'revision'=>hash('sha256',json_encode([$ref,$revision],JSON_THROW_ON_ERROR))];
}
function url(string $path):string{return '/vp3'.$path;}
require_once __DIR__.'/../includes/initiative-marketing-recommendation-links-v1.php';
$linkChecks=0;$authorized=true;$accepted=true;$stage='creative_ready';$observation='awaiting_change';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE campaigns (id INTEGER PRIMARY KEY,merchant_id INTEGER,merchant_owner_user_id INTEGER,merchant_status TEXT,
 merchant_sandbox_mode INTEGER,environment TEXT,status TEXT,current_version_no INTEGER,name TEXT)');
$pdo->exec("INSERT INTO campaigns VALUES(77,17,11,'active',0,'production','active',3,'Seasonal Campaign')");
$pdo->exec('CREATE TABLE merchant_accounts (id INTEGER PRIMARY KEY,owner_user_id INTEGER,status TEXT,sandbox_mode INTEGER)');
$pdo->exec("INSERT INTO merchant_accounts VALUES(17,11,'active',0)");
$pdo->exec('CREATE TABLE agent_product_launches_v1 (product_id INTEGER,owner_user_id INTEGER,agent_id INTEGER,
 conversation_id INTEGER,draft_key TEXT,creative_id TEXT)');
$pdo->exec('CREATE TABLE agent_commerce_products_v800 (id INTEGER PRIMARY KEY,owner_user_id INTEGER)');
$pdo->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER)');
$pdo->exec('CREATE TABLE agent_commerce_marketing_drafts_v1 (product_id INTEGER,owner_user_id INTEGER,request_key TEXT,draft_json TEXT)');
$pdo->exec('CREATE TABLE campaign_creative_assets (id INTEGER PRIMARY KEY AUTOINCREMENT,campaign_id INTEGER,
 asset_role TEXT,media_ref TEXT,content_json TEXT,created_by_user_id INTEGER,is_active INTEGER DEFAULT 1)');
$pdo->exec('INSERT INTO agent_commerce_products_v800 VALUES(10,11)');
$pdo->exec('INSERT INTO chat_conversations VALUES(33,11,7)');
$draft=str_repeat('a',64);
$pdo->prepare('INSERT INTO agent_product_launches_v1 VALUES (?,?,?,?,?,?)')->execute([10,11,7,33,$draft,null]);
$scope=['user'=>['id'=>11,'account'=>true],'principal'=>['viewer_user_id'=>11],'agent_id'=>7,'conversation_id'=>42];
$base=['campaign_id'=>77,'recommendation_id'=>501,'expected_campaign_version'=>3];
$options=vp3_marketing_recommendation_choices_v1($pdo,11,$scope,77,501)['work_choices'];
link_check($options['campaign_version']===3&&count($options['plans'])===1
    &&$options['plans'][0]['product_id']===10,'only owner-owned saved plan appears as a private link choice');
$write=['action'=>'marketing_work_link']+$base+['product_id'=>10,'expected_plan_revision'=>$options['plans'][0]['revision']];
$result=vp3_marketing_recommendation_action_v1($pdo,11,$scope,$write);
$id=$result['work_saved']['id'];
link_check($result['work_saved']['state']==='linked'&&$result['work_saved']['published']===false
    &&$result['work_saved']['campaign_mutated']===false,'an explicit link saves no publication or Campaign mutation');
link_check(vp3_marketing_recommendation_action_v1($pdo,11,$scope,$write)['work_saved']['id']===$id
    &&(int)$pdo->query('SELECT COUNT(*) FROM campaign_creative_assets')->fetchColumn()===1,
    'exact repeated requests are idempotent, without a duplicate association');
$outcomes=vp3_marketing_campaign_outcomes_v1($pdo,11,17,77,new DateTimeImmutable('now'));
$links=vp3_marketing_recommendation_links_v1($pdo,11,$scope,77,$outcomes);
link_check(count($links)===1&&$links[0]['review_state']==='linked'&&$links[0]['product_title']==='Saved product draft'
    &&$links[0]['implementation']==='not_verified','bound creative is private, reviewable and never claims implementation');
$scan=vp3_marketing_recommendation_sources_v1($pdo,$scope,null,new DateTimeImmutable('now'));
$linkRef='marketing:campaign-work:501';
link_check(($scan['items'][$linkRef]['trigger']??null)==='creative_review_ready'
    &&($scan['items'][$linkRef]['specialist']??null)==='marketing','ready creative enters the existing verified Marketing report source family');
$firstRevision=$scan['items'][$linkRef]['revision'];
link_check(vp3_marketing_recommendation_sources_v1($pdo,$scope,['campaign-work:501'],new DateTimeImmutable('now'))['items'][$linkRef]['revision']===$firstRevision,
    'exact saved-source reread yields the same stable revision');
$linkApproval=['action'=>'marketing_work_approve']+$base+['link_id'=>$id];
link_check(vp3_marketing_recommendation_action_v1($pdo,11,$scope,$linkApproval)['work_saved']['state']==='approved_for_use',
    'owner explicitly approves private use separately from linking');
$links=vp3_marketing_recommendation_links_v1($pdo,11,$scope,77,$outcomes);
link_check($links[0]['review_state']==='approved_for_use'&&$links[0]['implementation']==='not_verified',
    'private-use approval never asserts an implemented Campaign change');
$scan=vp3_marketing_recommendation_sources_v1($pdo,$scope,null,new DateTimeImmutable('now'));
link_check($scan['items'][$linkRef]['trigger']==='creative_approved'&&$scan['items'][$linkRef]['revision']!==$firstRevision,
    'approved creative produces one new semantic source revision');
$observation='observed';
$outcomes=vp3_marketing_campaign_outcomes_v1($pdo,11,17,77,new DateTimeImmutable('now'));
$scan=vp3_marketing_recommendation_sources_v1($pdo,$scope,null,new DateTimeImmutable('now'));
link_check($scan['items'][$linkRef]['trigger']==='creative_results',
    'separate audited results produce a measured-observation review, without causal claims');
$allowed=false;
link_reject(fn()=>vp3_marketing_recommendation_action_v1($pdo,12,
    ['user'=>['id'=>12,'account'=>true],'principal'=>['viewer_user_id'=>12],'agent_id'=>7,'conversation_id'=>42],$write),403,
    'a foreign owner cannot write a private Campaign association');
$authorized=false;
link_reject(fn()=>vp3_marketing_recommendation_action_v1($pdo,11,$scope,$linkApproval),403,
    'revoked Agent/Chat owner authority blocks approval');
$authorized=true;
$pdo->exec('UPDATE campaigns SET current_version_no=4 WHERE id=77');
link_reject(fn()=>vp3_marketing_recommendation_action_v1($pdo,11,$scope,$linkApproval),409,
    'Campaign version changes make old approvals stale');
$pdo->exec('UPDATE campaigns SET current_version_no=3 WHERE id=77');
$pdo->prepare('UPDATE agent_product_launches_v1 SET draft_key=? WHERE product_id=10')->execute([str_repeat('b',64)]);
link_check(vp3_marketing_recommendation_links_v1($pdo,11,$scope,77,$outcomes)[0]['review_state']==='source_changed',
    'a replaced underlying private draft invalidates the association');
link_reject(fn()=>vp3_marketing_recommendation_action_v1($pdo,11,$scope,$linkApproval),409,
    'changed draft cannot retain an old private-use approval');
$unlink=['action'=>'marketing_work_unlink']+$base+['link_id'=>$id];
link_check(vp3_marketing_recommendation_action_v1($pdo,11,$scope,$unlink)['work_saved']['state']==='unlinked',
    'owner can unlink a stale private association without publishing or deleting the draft');
link_check(vp3_marketing_recommendation_links_v1($pdo,11,$scope,77,$outcomes)===[],
    'unlinked association is absent from subsequent private reads');
$accepted=false;
link_reject(fn()=>vp3_marketing_recommendation_action_v1($pdo,11,$scope,$write),409,
    'withdrawn recommendation proof cannot create a new link');
echo 'MARKETING_RECOMMENDATION_LINKS=PASS ('.$linkChecks.' checks)'.PHP_EOL;
