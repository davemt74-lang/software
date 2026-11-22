<?php
declare(strict_types=1);

// Real product/photo bytes, private drafts, native plan state and transactions.
// Only model transport and the pre-existing Campaign authority are controlled.
require __DIR__.'/fixtures/commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/profile-agent.php';
require_once dirname(__DIR__).'/includes/profile-conversation-checkout-pa5.php';
require_once dirname(__DIR__).'/includes/initiative-workbench-v1.php';
require_once dirname(__DIR__).'/includes/initiative-work-marketing-v1.php';
require_once dirname(__DIR__).'/includes/initiative-marketing-creative-v1.php';
require_once dirname(__DIR__).'/includes/initiative-marketing-brief-campaign-v1.php';
require_once dirname(__DIR__).'/includes/initiative-work-marketing-promotion-v1.php';
require_once dirname(__DIR__).'/includes/agent-marketing-opportunity-portfolio-v1.php';
require_once dirname(__DIR__).'/includes/agent-marketing-private-brief-v1.php';

function has_permission(string $capability,array $user): bool{return !empty($user['is_active']);}
function vp3_marketing_editor_scope_v1(PDO $pdo,int $owner,array $scope): void
{
    if(!($GLOBALS['briefCreativeAuthority']??true)||$owner!==1||($scope['user']['id']??null)!==1)
        throw new DomainException('Campaign permission changed',403);
}
function vp3_marketing_editor_draft_v1(PDO $pdo,int $owner,int $merchant,int $id,bool $lock=false): array
{
    $q=$pdo->prepare('SELECT * FROM campaigns WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||(int)$row['merchant_id']!==$merchant||(int)$row['owner_user_id']!==$owner||$row['status']!=='draft')
        throw new DomainException('Current private Campaign required',409);
    return ['id'=>$id,'merchant_id'=>$merchant,'status'=>'draft','revision'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),
        'fields'=>['title'=>$row['name'],'objective'=>$row['objective'],'description'=>'Private Campaign'],
        'manage_url'=>'/campaigns.php?merchant='.$merchant.'&edit_campaign='.$id.'#campaign-editor'];
}
function bc_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function bc_deny(int $status,callable $run,string $label): void
{
    try{$run();}catch(DomainException $e){bc_check($e->getCode()===$status,$label.' ('.$e->getCode().')');return;}
    throw new RuntimeException('Expected denial: '.$label);
}

$fixture=commerce_fixture_config_pa2();$pdo=commerce_fixture_connect_pa2($fixture);
$root=sys_get_temp_dir().'/vp3-brief-creative-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);$checks=0;$calls=0;
try{
    commerce_fixture_schema_pa2($pdo);vp3_profile_checkout_ensure_schema_pa5($pdo);
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NOT NULL DEFAULT 'Seller'");
    $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'user'");
    $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(150) NOT NULL DEFAULT ''");
    $pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,user_agent_id INTEGER)');
    $pdo->exec('INSERT INTO chat_conversations VALUES(11,1,NULL),(12,1,NULL),(21,2,NULL)');
    $pdo->exec("CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type VARCHAR(12),
        title VARCHAR(190),mime_type VARCHAR(30),file_path VARCHAR(250),created_at VARCHAR(40),updated_at VARCHAR(40),
        file_size INTEGER NOT NULL,duration_seconds INTEGER DEFAULT 0,source VARCHAR(30),metadata_json TEXT)");
    mkdir($root.'/private/user-media/1',0700,true);
    $bytes=base64_decode(trim(file_get_contents(__DIR__.'/fixtures/tv-frame.b64')),true);
    $path=$root.'/private/user-media/1/source.jpg';file_put_contents($path,$bytes);
    $pdo->prepare("INSERT INTO user_media_assets VALUES(1,1,'photo','Original mug','image/jpeg',
        'private/user-media/1/source.jpg',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?,0,'upload','{}')")->execute([strlen($bytes)]);
    $scope=['user'=>['id'=>1,'is_active'=>1],'agent_id'=>0,'conversation_id'=>11];
    $productInput=['source_id'=>'1','request_id'=>str_repeat('a',64)];
    $productPreview=initiative_work_product_preview_v1($pdo,1,$scope,$productInput);
    $created=initiative_work_product_create_v1($pdo,1,$scope,$productInput,$productPreview,
        static fn():string=>' {"title":"Studio mug","description":"A ceramic mug for the studio."}');
    $product=$created['product_id'];
    $pdo->exec('CREATE TABLE campaigns(id INTEGER PRIMARY KEY,merchant_id INTEGER,owner_user_id INTEGER,
        name VARCHAR(190),objective VARCHAR(500),status VARCHAR(30))');
    $pdo->exec("INSERT INTO campaigns VALUES(7,3,1,'Studio Campaign','Introduce the mug to studio visitors','draft')");
    $pdo->exec('CREATE TABLE campaign_creative_assets(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT,
        asset_role VARCHAR(60),media_ref VARCHAR(255),content_json TEXT,created_by_user_id INTEGER,is_active INTEGER NOT NULL DEFAULT 1)');
    $opportunity=['source_ref'=>'marketing:product-plan:'.$product,'source_revision'=>str_repeat('b',64),
        'kind'=>'product-plan','source_id'=>$product,'title'=>'Studio mug launch','finding'=>'Review the saved product and artwork.',
        'next_step'=>'Prepare a private visual.','coverage'=>'ready','notification_owner'=>'product_launch',
        'source_href'=>'/chat.php?agent=system&conversation_id=11&marketing_plan='.$product];
    $opportunity['private_brief']=vp3_marketing_private_brief_v1($opportunity);
    $pdo->beginTransaction();vp3_marketing_brief_save_v1($pdo,1,$scope,7,$opportunity);$pdo->commit();
    $view=vp3_marketing_brief_view_v1($pdo,1,$scope,7);$binding=$view['binding'];
    $input=initiative_workbench_input_v1(['tool'=>'marketing','source_id'=>'1','product_id'=>$product,'campaign_brief'=>$binding]);
    $preview=initiative_work_marketing_preview_v1($pdo,1,$scope,$input);
    bc_check(str_contains($preview['details']['owner_review'],'Introduce the mug to studio visitors'),
        'the real workbench preview names the current saved Campaign objective');
    $input['request_id']=initiative_workbench_request_v1(1,$scope,'marketing',$input,$preview);
    $model=static function(string $prompt)use(&$calls):string{
        $calls++;$context=json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
        bc_check(($context['campaign_brief']['objective']??null)==='Introduce the mug to studio visitors'
            &&($context['product']['title']??null)==='Studio mug',
            'the model receives canonical Campaign objective and product facts');
        return '{"headline":"A mug for the studio","copy":"Meet the ceramic studio mug.","campaign_idea":"Prepare a private studio introduction."}';
    };
    $copy=initiative_work_marketing_create_v1($pdo,1,$scope,$input,$preview,$model);
    bc_check(($copy['campaign_brief']??null)===$binding&&$calls===1,
        'real private copy is persisted with the exact Campaign and source-brief binding');
    $replay=initiative_work_marketing_create_v1($pdo,1,$scope,$input,$preview,$model);
    bc_check($replay['draft_id']===$copy['draft_id']&&$calls===1,
        'retrying saved creative does not call the model again');
    $saved=vp3_marketing_brief_view_v1($pdo,1,$scope,7);
    bc_check($saved['state']==='copy_saved'&&$saved['resume']['draft_id']===$copy['draft_id']
        &&$saved['resume']['product_id']===$product,
        'the real native plan and saved private copy restore into the Campaign review');
    $imageBinding=['source_asset_id'=>1,'source_sha256'=>hash('sha256',$bytes),
        'product_id'=>$product,'draft_key'=>$copy['draft_id']];
    $source=initiative_marketing_creative_source_v1($pdo,1,$scope,$imageBinding,false);
    bc_check($source['digest']===hash('sha256',$bytes),'image production rechecks the exact owner photo and Campaign');
    bc_deny(409,fn()=>initiative_work_marketing_promotion_publication_v1($pdo,1,
        ['product_id'=>$product,'draft_key'=>$copy['draft_id'],'status'=>'saved']),
        'canonical publication cannot bypass Campaign review for brief-bound graphics');
    $foreign=array_replace($scope,['conversation_id'=>12]);
    bc_deny(404,fn()=>initiative_marketing_creative_source_v1($pdo,1,$foreign,$imageBinding,false),
        'another owner Chat cannot generate from the saved Campaign brief');
    $pdo->exec("UPDATE campaigns SET objective='Owner changed the Campaign objective' WHERE id=7");
    bc_deny(409,fn()=>initiative_work_marketing_create_v1($pdo,1,$scope,$input,$preview,$model),
        'Campaign changes reject stale copy requests before model execution');
    bc_deny(409,fn()=>initiative_marketing_creative_source_v1($pdo,1,$scope,$imageBinding,false),
        'Campaign changes invalidate image-provider source admission');
    bc_check($calls===1,'stale context cannot spend another model request');
    $changed=vp3_marketing_brief_view_v1($pdo,1,$scope,7);
    bc_check($changed['state']==='needs_review'&&$changed['resume']===null,
        'the Campaign view retains provenance and reports that its old creative needs review');
    $freshInput=$input;$freshInput['campaign_brief']=$changed['binding'];unset($freshInput['request_id']);
    $freshPreview=initiative_work_marketing_preview_v1($pdo,1,$scope,$freshInput);
    $freshInput['request_id']=initiative_workbench_request_v1(1,$scope,'marketing',$freshInput,$freshPreview);
    unlink($path);
    bc_deny(404,fn()=>initiative_work_marketing_create_v1($pdo,1,$scope,$freshInput,$freshPreview,$model),
        'removed source bytes stop the real private-copy pipeline');
    bc_check($calls===1,'opening views, retrying or losing sources never invents extra provider calls');
    echo "MARKETING_BRIEF_CREATIVE=PASS ($checks checks, {$fixture['driver']})\n";
}finally{
    if(isset($path)&&is_file($path))unlink($path);
    foreach(['/private/user-media/1','/private/user-media','/private',''] as $suffix)if(is_dir($root.$suffix))rmdir($root.$suffix);
    commerce_fixture_cleanup_pa2($fixture);
}
