<?php
declare(strict_types=1);

// Exercise the real signed Campaign editor and private asset transactions.
require __DIR__.'/marketing-campaign-editor-v1.php';
require_once dirname(__DIR__).'/includes/initiative-marketing-brief-campaign-v1.php';

function vp3_coordination_marketing_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    return ['coverage'=>'ready','items'=>$GLOBALS['briefTestSources']??[]];
}
function initiative_work_marketing_draft_v1(PDO $pdo,int $owner,int $product,string $draft,bool $lock=false): array
{
    if($owner!==1||!isset($GLOBALS['briefTestCopies'][$draft]))throw new DomainException('Copy unavailable',404);
    return $GLOBALS['briefTestCopies'][$draft];
}
function initiative_marketing_plans_query_v1(): string
{
    return 'SELECT l.* FROM agent_product_launches_v1 l WHERE l.owner_user_id=? AND l.agent_id=?';
}
function initiative_marketing_plan_view_v1(PDO $pdo,int $owner,array $row): array
{
    return ['stage'=>$GLOBALS['briefTestStage']??'design_review','resume'=>[
        'creative_id'=>$row['creative_id'],'product_id'=>$row['product_id']]];
}
function vp3_coordination_source_item_v1(string $ref,string $specialist,string $title,string $summary,
    string $next,string $question,string $url,string $status,string $priority,array $proof): array
{
    return ['ref'=>$ref,'specialist'=>$specialist,'title'=>$title,'summary'=>$summary,'next_step'=>$next,
        'question'=>$question,'url'=>$url,'status'=>$status,'priority'=>$priority,
        'revision'=>hash('sha256',json_encode($proof,JSON_THROW_ON_ERROR))];
}

$pdo->exec('CREATE TABLE campaign_creative_assets(id INTEGER PRIMARY KEY AUTOINCREMENT,campaign_id INTEGER,
    asset_role TEXT,media_ref TEXT,content_json TEXT,created_by_user_id INTEGER,is_active INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE agent_product_launches_v1(product_id INTEGER PRIMARY KEY,owner_user_id INTEGER,
    agent_id INTEGER,conversation_id INTEGER,draft_key TEXT,creative_id TEXT)');
$scope=['user'=>['id'=>1,'account'=>true],'agent_id'=>7,'conversation_id'=>11];
$sourceRef='marketing:product-plan:10';$sourceRevision=str_repeat('b',64);
$briefTestSources=[$sourceRef=>['ref'=>$sourceRef,'revision'=>$sourceRevision,'specialist'=>'marketing',
    'title'=>'Private mug launch','summary'=>'Review the existing listing and its saved artwork.',
    'next_step'=>'Prepare a private creative option.','question'=>'','priority'=>'normal','status'=>'marketing_review',
    'url'=>'/vp3/chat.php?agent=7&conversation_id=11&marketing_plan=10',
    'marketing_presentation_owned'=>true,'presentation_owner'=>'agent_product_launch']];
$fields['title']='Brief Campaign';$fields['slug']='brief-campaign';
$body=['action'=>'marketing_editor_preview','merchant_id'=>7,'campaign_id'=>0,'revision'=>'new',
    'request_key'=>str_repeat('9',32),'fields'=>$fields,
    'brief_source'=>['source_ref'=>$sourceRef,'source_revision'=>$sourceRevision]];
$preview=vp3_marketing_editor_action_v1($pdo,1,$scope,$body,'secret');
editor_check($preview['preview']['source_brief']==='Private mug launch'
    &&$pdo->query('SELECT COUNT(*) FROM campaign_creative_assets')->fetchColumn()===0,
    'signed preview names the exact source and writes no brief');
$changed=$body;$changed['brief_source']['source_revision']=str_repeat('a',64);
editor_denied(409,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$changed,'secret'),'a stale opportunity cannot initialize a Campaign');
$forged=$body;$forged['brief_source']['objective']='Browser-invented authority';
editor_denied(422,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$forged,'secret'),'browser-supplied brief prose is rejected');
$save=$body;$save['action']='marketing_editor_save';$save['confirmed']=true;$save['review_token']=$preview['review_token'];
$bad=$save;$bad['brief_source']['source_revision']=str_repeat('a',64);
editor_denied(409,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$bad,'secret'),'review token binds the exact source revision');
$missing=$briefTestSources;$briefTestSources=[];
editor_denied(409,fn()=>vp3_marketing_editor_action_v1($pdo,1,$scope,$save,'secret'),'source removal after review blocks Campaign save');
$briefTestSources=$missing;$save['request_key']=str_repeat('8',32);
$again=$body;$again['request_key']=$save['request_key'];
$save['review_token']=vp3_marketing_editor_action_v1($pdo,1,$scope,$again,'secret')['review_token'];
$result=vp3_marketing_editor_action_v1($pdo,1,$scope,$save,'secret');$campaign=$result['draft']['id'];
$row=vp3_marketing_brief_row_v1($pdo,1,$scope,$campaign);
editor_check($row!==null&&$row['record']['source']===$body['brief_source']
    &&$row['record']['brief']['campaign_created']===false&&$row['record']['work']===null,
    'canonical source brief is saved privately with the reviewed Campaign');
$replayed=vp3_marketing_editor_action_v1($pdo,1,$scope,$save,'secret');
editor_check($replayed['draft']['id']===$campaign&&$pdo->query('SELECT COUNT(*) FROM campaign_creative_assets')->fetchColumn()===1,
    'lost-save retry replays the receipt without duplicating the brief');
$held=$briefTestSources;$briefTestSources=[];
editor_check(vp3_marketing_editor_action_v1($pdo,1,$scope,$save,'secret')['draft']['id']===$campaign,
    'a committed save can be recovered after the original opportunity moves on');
$briefTestSources=$held;
$view=vp3_marketing_brief_view_v1($pdo,1,$scope,$campaign);$binding=$view['binding'];
editor_check($view['state']==='brief_saved'&&$view['private_only']&&$view['resume']===null,
    'reopening the Campaign restores its saved source and current objective');
editor_denied(404,fn()=>vp3_marketing_brief_view_v1($pdo,1,array_replace($scope,['conversation_id'=>12]),$campaign),
    'a different Chat cannot read the saved brief');
editor_denied(404,fn()=>vp3_marketing_brief_view_v1($pdo,1,array_replace($scope,['agent_id'=>8]),$campaign),
    'another Agent cannot reuse the Campaign brief');
editor_denied(403,fn()=>vp3_marketing_brief_view_v1($pdo,2,$scope,$campaign),'foreign account cannot read the brief');
editor_denied(409,fn()=>vp3_marketing_brief_context_v1($pdo,1,$scope,$binding,20),
    'a product-plan brief cannot silently change to another product');
$copy=['draft_id'=>str_repeat('d',64),'product_id'=>10,'asset_id'=>3,'campaign_brief'=>$binding];
$briefTestCopies=[$copy['draft_id']=>$copy];
$pdo->beginTransaction();vp3_marketing_brief_attach_work_v1($pdo,1,$scope,$binding,$copy);$pdo->commit();
$copyView=vp3_marketing_brief_view_v1($pdo,1,$scope,$campaign);
editor_check($copyView['state']==='copy_saved'&&$copyView['resume']['draft_id']===$copy['draft_id'],
    'saved private copy is returned through its exact canonical draft');
$pdo->prepare('INSERT INTO agent_product_launches_v1 VALUES(?,?,?,?,?,?)')->execute([10,1,7,11,$copy['draft_id'],str_repeat('c',32)]);
$briefTestStage='creative_ready';
$ready=vp3_marketing_brief_view_v1($pdo,1,$scope,$campaign);
editor_check($ready['state']==='creative_ready'&&$ready['resume']['creative_id']===str_repeat('c',32),
    'canonical native creative state supplies the owner-review checkpoint');
$first=vp3_marketing_brief_sources_v1($pdo,$scope,null,new DateTimeImmutable('2026-11-22T00:00:00Z'));
$second=vp3_marketing_brief_sources_v1($pdo,$scope,null,new DateTimeImmutable('2026-11-23T00:00:00Z'));
$ref='marketing:campaign-brief:'.$campaign;
editor_check(isset($first['items'][$ref])&&$first['items'][$ref]['revision']===$second['items'][$ref]['revision']
    &&str_contains($first['items'][$ref]['summary'],'ready for owner review')
    &&$first['items'][$ref]['trigger']==='creative_review_ready'&&$first['items'][$ref]['question']!=='',
    'Chat and Brain source proof uses semantic creative milestones, not scanner timestamps');
editor_check(vp3_marketing_brief_sources_v1($pdo,$scope,['campaign-brief:999'],new DateTimeImmutable())['items']===[],
    'exact source re-read cannot substitute a different Campaign');
$campaignRows[$campaign]['updated_at']='Changed after generation';
editor_denied(409,fn()=>vp3_marketing_brief_context_v1($pdo,1,$scope,$binding,10),'Campaign edits invalidate generation authority');
$stale=vp3_marketing_brief_view_v1($pdo,1,$scope,$campaign);
editor_check($stale['state']==='needs_review'&&$stale['resume']===null,
    'edited Campaigns retain provenance and require fresh creative review');
$can=false;
editor_denied(403,fn()=>vp3_marketing_brief_view_v1($pdo,1,$scope,$campaign),'revoked Merchant authority withholds the saved brief');
$can=true;$campaignRows[$campaign]['status']='active';
editor_denied(409,fn()=>vp3_marketing_brief_view_v1($pdo,1,$scope,$campaign),'active Campaigns cannot use the private-draft production path');
echo "MARKETING_BRIEF_CAMPAIGN=PASS ($checks checks)\n";
