<?php
declare(strict_types=1);

require __DIR__.'/fixtures/commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/initiative-work-product-v1.php';
require_once dirname(__DIR__).'/includes/ai-settings.php';

function has_permission(string $capability,array $user): bool {return true;}
function profile_username_normalize(string $name): string {return strtolower($name);}
function vp3_participant_scope_v1(): ?array {return $GLOBALS['fixture_participant_scope']??null;}

$fixture=commerce_fixture_config_pa2();
$pdo=commerce_fixture_connect_pa2($fixture);
$root=sys_get_temp_dir().'/vp3-product-photo-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
$paths=[];
$checks=0;
function product_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
    $GLOBALS['checks']++;
}
function product_reject(callable $run,string $label,int $code=0): void
{
    try{$run();}catch(RuntimeException|DomainException $e){product_check($code===0||$e->getCode()===$code,$label);return;}
    throw new RuntimeException('Expected rejection: '.$label);
}

try{
    commerce_fixture_schema_pa2($pdo);
    $pdo->exec("ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
    $pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,user_agent_id INTEGER)');
    $pdo->exec('INSERT INTO chat_conversations VALUES (7,1,NULL),(8,2,NULL),(9,1,3)');
    $pdo->exec('CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type VARCHAR(12),
        title VARCHAR(190),mime_type VARCHAR(30),file_path VARCHAR(250),created_at VARCHAR(40),updated_at VARCHAR(40),
        file_size INTEGER DEFAULT 0,duration_seconds INTEGER DEFAULT 0,source VARCHAR(30),metadata_json TEXT)');
    mkdir($root.'/private/user-media/1',0700,true);
    mkdir($root.'/private/user-media/2',0700,true);
    $jpeg=base64_decode(trim(file_get_contents(__DIR__.'/fixtures/tv-frame.b64')),true);
    $first=$root.'/private/user-media/1/product.jpg';$other=$root.'/private/user-media/2/foreign.jpg';
    $invalid=$root.'/private/user-media/1/invalid.jpg';
    $paths=[$first,$other,$invalid];
    file_put_contents($first,$jpeg);file_put_contents($other,$jpeg);file_put_contents($invalid,'invalid photo');
    $stmt=$pdo->prepare('INSERT INTO user_media_assets (id,user_id,media_type,title,mime_type,file_path,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');
    foreach([
        [1,1,'photo','Original blue mug','image/jpeg','private/user-media/1/product.jpg'],
        [2,2,'photo','Foreign photo','image/jpeg','private/user-media/2/foreign.jpg'],
        [3,1,'photo','Path escape','image/jpeg','private/user-media/2/foreign.jpg'],
        [4,1,'video','A video','video/mp4','private/user-media/1/product.jpg'],
        [5,1,'photo','Corrupt photo','image/jpeg','private/user-media/1/invalid.jpg'],
        [6,1,'photo','Incorrect declared format','image/webp','private/user-media/1/product.jpg'],
    ] as $asset)$stmt->execute([...$asset,'2026-11-04T14:20:00Z','2026-11-04T14:20:00Z']);
    $scope=['agent_id'=>0,'conversation_id'=>7];
    $input=['source_id'=>'1','request_id'=>str_repeat('a',64)];
    $preview=initiative_work_product_preview_v1($pdo,1,$scope,$input);
    $productCount=static fn(): int=>(int)$pdo->query('SELECT COUNT(*) FROM agent_commerce_products_v800')->fetchColumn();
    $sources=initiative_work_product_sources_v1($pdo,1,$scope);
    product_check($preview['source_id']==='1'&&$preview['source_title']==='Original blue mug'&&strlen($preview['revision'])===64
        &&str_contains($preview['details']['photo_use'],'configured Cloud AI provider')&&!str_contains(json_encode($preview),'private/user-media')
        &&$preview['details']['image_url']==='/media-library-file-v86.php?id=1'
        &&$productCount()===0,'preview discloses provider transfer without writing or leaking private image bytes or paths');
    product_check(in_array(['source_id'=>'1','source_title'=>'Original blue mug'],$sources,true)
        &&array_intersect(array_column($sources,'source_id'),['2','3','4'])===[],
        'the source picker filters foreign photos, invalid storage paths and other media');
    product_reject(fn()=>initiative_work_product_preview_v1($pdo,1,['agent_id'=>0,'conversation_id'=>8],$input),'foreign Chat fails the native owner boundary',404);
    product_reject(fn()=>initiative_work_product_preview_v1($pdo,1,['agent_id'=>0,'conversation_id'=>9],$input),'another Agent cannot borrow a different Chat',404);
    product_reject(fn()=>initiative_work_product_preview_v1($pdo,1,$scope,['source_id'=>'2']),'foreign photo is never read',404);
    product_reject(fn()=>initiative_work_product_preview_v1($pdo,1,$scope,['source_id'=>'3']),'an owned row cannot point into another owner’s files',404);
    product_reject(fn()=>initiative_work_product_preview_v1($pdo,1,$scope,['source_id'=>'4']),'non-photo asset is rejected',404);
    product_reject(fn()=>initiative_work_product_preview_v1($pdo,1,$scope,['source_id'=>'5']),'invalid image bytes are rejected',422);
    product_reject(fn()=>initiative_work_product_preview_v1($pdo,1,$scope,['source_id'=>'6']),'declared MIME must match verified photo content',422);
    $GLOBALS['fixture_participant_scope']=['participant_id'=>3];
    product_reject(fn()=>initiative_work_product_preview_v1($pdo,1,$scope,$input),'participant scope cannot use owner draft authority',404);
    unset($GLOBALS['fixture_participant_scope']);

    $fakeVision=static function(string $prompt,string $image) use ($jpeg): string {
        product_check(str_contains($prompt,'Original blue mug')&&str_contains($prompt,'untrusted')
            &&$image==='data:image/jpeg;base64,'.base64_encode($jpeg),
            'only the exact photo and bounded title reach the selected vision model');
        return '{"title":"Blue mug","description":"A blue mug with a curved handle."}';
    };
    $result=initiative_work_product_create_v1($pdo,1,$scope,$input,$preview,$fakeVision);
    $row=agent_commerce_product_v800($pdo,$result['product_id'],1);
    $meta=profile_commerce_metadata_v900($row);
    product_check($productCount()===1&&$result['kind']==='product_listing_draft'&&$result['title']==='Blue mug'
        &&$result['description']==='A blue mug with a curved handle.'&&$result['source_id']==='1'
        &&str_contains($result['review_url'],'#product-listing-draft')&&!str_contains(json_encode($result),'base64')
        &&$meta['source_revision']===$preview['revision']&&$meta['conversation_id']===7&&$meta['agent_id']===0,
        'the reviewable canonical result preserves exact private photo and Chat lineage');
    product_check((int)initiative_work_product_review_photo_v1(['id'=>1],$meta)['id']===1
        &&initiative_work_product_review_photo_v1(['id'=>2],$meta)===null,'native review resolves the exact authorized original photo');
    file_put_contents($first,$jpeg.'changed');
    product_check(initiative_work_product_review_photo_v1(['id'=>1],$meta)===null,'native review cannot display a replacement as the original photo');
    file_put_contents($first,$jpeg);
    product_check(empty($row['is_active'])&&$row['fulfillment_type']==='none'&&(int)$row['price_cents']===0
        &&$row['fixed_connection_id']===null&&profile_commerce_visibility_v900($row)==='hidden'
        &&$GLOBALS['pa2_http_calls']===[],
        'the canonical draft is inactive and hidden without assumed price, fulfillment, provider or payment activity');
    product_check(profile_commerce_products_for_profile_v900($pdo,['user_id'=>1,'username'=>'owner'])===[],
        'the existing public Profile Commerce projection omits the draft');
    product_reject(fn()=>profile_commerce_publish_v900($pdo,1,(int)$row['id'],['profile_visibility'=>'public']),'a canonical publish attempt cannot expose this private draft');
    product_reject(fn()=>profile_commerce_save_generic_product_v900($pdo,1,['product_id'=>(int)$row['id'],'title'=>'Try activation']),'generic paid save cannot activate a private draft');
    product_reject(fn()=>agent_commerce_create_order_v800($pdo,$row,[]),'checkout cannot create an order for an inactive draft');

    $revision=initiative_work_product_edit_revision_v1($row);
    product_reject(fn()=>initiative_work_product_update_v1($pdo,2,(int)$row['id'],$revision,'Foreign edit',''),
        'another owner cannot edit the draft',404);
    initiative_work_product_update_v1($pdo,1,(int)$row['id'],$revision,'Owner-reviewed mug','Verified blue mug.');
    product_reject(fn()=>initiative_work_product_update_v1($pdo,1,(int)$row['id'],$revision,'Stale edit',''),
        'the native editor rejects concurrent stale edits',409);
    $repeat=initiative_work_product_create_v1($pdo,1,$scope,$input,$preview,static fn()=>throw new LogicException('Replay must not call vision.'));
    product_check($repeat['product_id']===$result['product_id']&&$repeat['title']==='Owner-reviewed mug'&&$productCount()===1,
        'a retry reuses the same canonical draft and preserves the owner’s edits');

    $otherInput=['source_id'=>'1','request_id'=>str_repeat('b',64)];
    foreach(['{"title":"Mug","description":"Blue","price":20}','{"title":"Mug","description":"For $20"}',
        '{"title":"<a>Mug</a>","description":"Blue"}'] as $invalidOutput){
        product_reject(fn()=>initiative_work_product_create_v1($pdo,1,$scope,$otherInput,$preview,static fn()=>$invalidOutput),
            'vision output with unexpected commercial terms or markup cannot create a draft',422);
    }
    product_reject(fn()=>initiative_work_product_create_v1($pdo,1,$scope,$otherInput,$preview,static function()use($pdo): string {
        $pdo->exec("UPDATE user_media_assets SET title='Changed while vision ran' WHERE id=1");
        return '{"title":"Mug","description":"Blue"}';
    }),'changing the source during inference fences the write',409);
    $newPreview=initiative_work_product_preview_v1($pdo,1,$scope,$otherInput);
    product_reject(fn()=>initiative_work_product_create_v1($pdo,1,$scope,$otherInput,$newPreview,static function()use($pdo): string {
        $pdo->exec('DELETE FROM chat_conversations WHERE id=7');
        return '{"title":"Mug","description":"Blue"}';
    }),'deleting the scoped Chat during inference fences the write',409);
    product_check($productCount()===1&&$GLOBALS['pa2_http_calls']===[],'failed and stale requests leave no additional draft, payment or provider calls');
    foreach(['image/jpeg','image/png','image/webp'] as $mime){
        product_check(ai_anthropic_inline_image('data:'.$mime.';base64,YWJj')['source']===[
            'type'=>'base64','media_type'=>$mime,'data'=>'YWJj'],"the vision transport preserves the verified $mime format");
    }
    echo "INITIATIVE_WORK_PRODUCT_V1=PASS ($checks cases)\n";
}finally{
    foreach($paths as $path)if(is_file($path))unlink($path);
    foreach(['/private/user-media/1','/private/user-media/2','/private/user-media','/private',''] as $suffix){
        if(is_dir($root.$suffix))rmdir($root.$suffix);
    }
    commerce_fixture_cleanup_pa2($fixture);
}
