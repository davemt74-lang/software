<?php
declare(strict_types=1);

require __DIR__.'/fixtures/commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/profile-agent.php';
require_once dirname(__DIR__).'/includes/initiative-workbench-v1.php';
require_once dirname(__DIR__).'/includes/initiative-work-marketing-promotion-v1.php';
require_once dirname(__DIR__).'/includes/profile-product-promotion-v1.php';

function has_permission(string $capability, array $user): bool
{
    return !empty($user['is_active']) && in_array($capability, ['chat.access','account.access'], true);
}

function promotion_fixture_png_v1(string $pixel): string
{
    $chunk = static fn(string $type, string $data): string => pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $row = "\0".str_repeat($pixel,32);
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',32,32,8,6,0,0,0))
        .$chunk('IDAT',gzcompress(str_repeat($row,32))).$chunk('IEND','');
}

function promotion_check_v1(bool $result, string $label): void
{
    if (!$result) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
    $GLOBALS['checks']++;
}

function promotion_reject_v1(callable $run, int $status, string $label): void
{
    try {
        $run();
    } catch (DomainException $error) {
        promotion_check_v1($error->getCode() === $status, $label.' ('.$status.')');
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}

$fixture = commerce_fixture_config_pa2();
$pdo = commerce_fixture_connect_pa2($fixture);
$root = sys_get_temp_dir().'/vp3-promotion-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
$checks = 0;
$files = [];
try {
    commerce_fixture_schema_pa2($pdo);
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NOT NULL DEFAULT 'Seller'");
    $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'user'");
    $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(150) NOT NULL DEFAULT ''");
    $pdo->exec('CREATE TABLE user_profiles(user_id INTEGER PRIMARY KEY,username VARCHAR(80),is_public INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO user_profiles VALUES(1,'seller',1),(2,'other',1)");
    $pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,user_agent_id INTEGER)');
    $pdo->exec('INSERT INTO chat_conversations VALUES(11,1,NULL),(12,1,NULL),(21,2,NULL)');
    $pdo->exec("CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type VARCHAR(12),
        title VARCHAR(190),mime_type VARCHAR(30),file_path VARCHAR(250),created_at VARCHAR(40),updated_at VARCHAR(40),
        file_size INTEGER NOT NULL,duration_seconds INTEGER DEFAULT 0,source VARCHAR(30),metadata_json TEXT)");
    mkdir($root.'/private/user-media/1',0700,true);
    mkdir($root.'/private/user-media/2',0700,true);
    $addAsset = static function(int $id, int $owner, string $bytes, string $source, array $metadata=[]) use ($pdo,$root,&$files): string {
        $relative = 'private/user-media/'.$owner.'/asset-'.$id.'.png';
        $path = $root.'/'.$relative;
        file_put_contents($path,$bytes);
        $files[] = $path;
        $pdo->prepare("INSERT INTO user_media_assets
            (id,user_id,media_type,title,mime_type,file_path,created_at,updated_at,file_size,source,metadata_json)
            VALUES(?,?,'photo',?,'image/png',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?,?,?)")
            ->execute([$id,$owner,'Fixture image '.$id,$relative,strlen($bytes),$source,json_encode($metadata,JSON_THROW_ON_ERROR)]);
        return $path;
    };
    $original = promotion_fixture_png_v1("\x30\x60\xa0\xff");
    $rendered = promotion_fixture_png_v1("\xa0\x60\x30\xff");
    $originalFile = $addAsset(1,1,$original,'upload');
    $addAsset(2,1,$original,'upload');
    $addAsset(3,2,$original,'upload');

    $product = commerce_fixture_insert_pa2($pdo,'agent_commerce_products_v800',[
        'owner_user_id'=>1,'product_key'=>'blue-mug','title'=>'Blue mug','description'=>'A ceramic blue mug.',
        'payment_mode'=>'full','price_cents'=>2000,
        'metadata_json'=>'{"profile_visibility":"public","profile_slug":"blue-mug","profile_cta":"See the mug"}',
    ]);
    $scope = ['agent_id'=>0,'conversation_id'=>11,'user'=>['id'=>1]];
    $secondChat = array_replace($scope,['conversation_id'=>12]);
    $otherOwner = ['agent_id'=>0,'conversation_id'=>21,'user'=>['id'=>2]];
    $sessionKey = str_repeat('1',64);
    $action = static fn(string $operation, array $body=[], int $owner=1, ?array $chat=null, ?string $key=null): array =>
        initiative_work_marketing_promotion_action_v1($pdo,$owner,$chat ?? $scope,$body+['action'=>$operation],$key ?? $sessionKey);
    $ownerProfile = static fn(): array => profile_for_user($pdo,1);
    $targetMetadata = static fn(string $slug, string $visibility='public'): string =>
        json_encode(['profile_visibility'=>$visibility,'profile_slug'=>$slug,'profile_cta'=>'See the mug'],JSON_THROW_ON_ERROR);
    $setTarget = static fn(string $slug, string $visibility='public'): bool =>
        $pdo->prepare('UPDATE agent_commerce_products_v800 SET metadata_json=? WHERE id=?')
            ->execute([$targetMetadata($slug,$visibility),$product]);

    $draftKey = str_repeat('d',64);
    $draftBody = ['source_id'=>'1','product_id'=>$product,'request_id'=>$draftKey];
    $draftPreview = initiative_work_marketing_preview_v1($pdo,1,$scope,$draftBody);
    $draft = initiative_work_marketing_create_v1($pdo,1,$scope,$draftBody,$draftPreview,static fn(): string =>
        '{"headline":"Blue mug","copy":"A ceramic blue mug.","campaign_idea":"Feature the mug on the seller profile."}');
    $selection = ['draft_id'=>$draftKey,'source_id'=>'1','product_id'=>$product];
    $open = $action('creative_open',$selection);
    promotion_check_v1($draft['draft_id']===$draftKey && $open['draft']['draft_id']===$draftKey
        && $open['draft']['asset_id']===1 && $open['creative']===null && $open['variants']===[]
        && $open['promotion']===null,'open selects the stored draft and exact original without publishing');
    promotion_reject_v1(fn()=> $action('creative_open',array_replace($selection,['source_id'=>'2'])),409,
        'identical bytes from a different media asset cannot replace the exact original');
    promotion_reject_v1(fn()=> $action('creative_open',$selection,2,$otherOwner),404,
        'another owner cannot open the draft');

    $settings = ['nonce'=>str_repeat('a',32),'level'=>1,'headline'=>'Blue mug for everyday mornings',
        'copy'=>'A ceramic blue mug with a curved handle.','prompt'=>'','style'=>'clean','remove_background'=>false,'tolerance'=>24];
    $design = $settings + $selection;
    foreach ([0,11,'7',1.5] as $invalid) {
        promotion_reject_v1(fn()=> $action('creative_prepare',array_replace($design,['level'=>$invalid])),422,
            'creative review rejects out-of-range and non-integer slider levels');
    }
    $prepared = $action('creative_prepare',$design);
    $creativeId = $prepared['creative']['id'];
    $confirmDesign = $design + ['review_token'=>$prepared['review_token'],'confirmed'=>true];
    promotion_check_v1($prepared['creative']['source_image_url']==='/media-library-file-v86.php?id=1'
        && !str_contains(json_encode($prepared,JSON_THROW_ON_ERROR),'private/user-media')
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_commerce_promotions_v1')->fetchColumn()===0,
        'design preview is read-only and exposes only the authenticated original URL');
    promotion_reject_v1(fn()=> $action('creative_generate',$design),422,'design requires explicit confirmation');
    promotion_reject_v1(fn()=> $action('creative_generate',array_replace($confirmDesign,['headline'=>'Changed after review'])),409,
        'design confirmation signs the reviewed headline');
    promotion_reject_v1(fn()=> $action('creative_generate',$confirmDesign,1,$secondChat),409,
        'design confirmation is tied to the originating Chat');
    promotion_reject_v1(fn()=> $action('creative_generate',$confirmDesign,1,$scope,str_repeat('2',64)),409,
        'design confirmation is tied to its session');
    file_put_contents($originalFile,$rendered);
    promotion_reject_v1(fn()=> $action('creative_generate',$confirmDesign),409,'design cannot use an original changed after review');
    file_put_contents($originalFile,$original);
    $ready = $action('creative_generate',$confirmDesign)['creative'];
    $repeat = $action('creative_generate',$confirmDesign)['creative'];
    $row = initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId);
    promotion_check_v1($ready===$repeat && $ready['id']===$creativeId && $ready['level']===1 && $ready['status']==='ready'
        && $row['generation_id']===null && $row['source_sha256']===hash('sha256',$original)
        && $GLOBALS['pa2_http_calls']===[],'level 1 uses the exact original without provider work and replays idempotently');
    $variant = ['creative_id'=>$creativeId];
    promotion_reject_v1(fn()=> $action('creative_status',$variant,1,$secondChat),404,
        'another Chat cannot view an existing creative');
    promotion_reject_v1(fn()=> $action('creative_status',$variant,2,$otherOwner),404,
        'another owner cannot view an existing creative');
    promotion_reject_v1(fn()=> $action('creative_save',$variant+['asset_id'=>1]),422,
        'the private original cannot be saved directly as public artwork');
    $addAsset(4,1,$rendered,'promotion_design',['creative_id'=>str_repeat('c',32)]);
    $addAsset(5,2,$rendered,'promotion_design',['creative_id'=>$creativeId]);
    promotion_reject_v1(fn()=> $action('creative_save',$variant+['asset_id'=>4]),422,
        'an uploaded PNG belonging to another creative cannot be saved');
    promotion_reject_v1(fn()=> $action('creative_save',$variant+['asset_id'=>5]),422,
        'an uploaded PNG belonging to another owner cannot be saved');
    promotion_reject_v1(fn()=> $action('creative_publish_preview',$variant),409,'publication requires a saved graphic');

    $graphicFile = $addAsset(6,1,$rendered,'promotion_design',['creative_id'=>$creativeId]);
    $saved = $action('creative_save',$variant+['asset_id'=>6])['creative'];
    promotion_check_v1($saved['status']==='saved' && $saved['image_url']==='/media-library-file-v86.php?id=6'
        && $saved['product_url']==='/seller/product/blue-mug' && $saved['cta']==='See the mug'
        && $saved===$action('creative_save',$variant+['asset_id'=>6])['creative']
        && file_get_contents($originalFile)===$original && profile_product_promotions_public_v1($pdo,$ownerProfile())===[],
        'saving keeps the exact graphic private and leaves the original intact');
    $addAsset(7,1,$rendered,'promotion_design',['creative_id'=>$creativeId]);
    promotion_reject_v1(fn()=> $action('creative_save',$variant+['asset_id'=>7]),409,
        'a saved variant cannot be silently changed to different media');

    $review = $action('creative_publish_preview',$variant);
    $approval = $variant+['confirmed'=>true,'review_token'=>$review['review_token']];
    promotion_reject_v1(fn()=> $action('creative_publish',$variant),422,'publish requires explicit signed approval');
    promotion_reject_v1(fn()=> $action('creative_publish',$approval,1,$scope,str_repeat('2',64)),409,
        'publication approval cannot be replayed in another session');
    $pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
    promotion_reject_v1(fn()=> $action('creative_publish',$approval),409,'a hidden profile blocks publication');
    $pdo->exec('UPDATE user_profiles SET is_public=1 WHERE user_id=1');
    $setTarget('new-mug-link');
    promotion_reject_v1(fn()=> $action('creative_publish',$approval),409,'a changed product URL invalidates approval');
    $setTarget('blue-mug','hidden');
    promotion_reject_v1(fn()=> $action('creative_publish',$approval),409,'a hidden product blocks publication');
    $setTarget('blue-mug');
    file_put_contents($graphicFile,$rendered.'x');
    promotion_reject_v1(fn()=> $action('creative_publish',$approval),409,'a changed artwork file invalidates approval');
    file_put_contents($graphicFile,$rendered);

    $published = $action('creative_publish',$approval)['creative'];
    $publicKey = initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId)['public_key'];
    $card = profile_product_promotion_public_v1($pdo,$ownerProfile(),$publicKey);
    promotion_check_v1($published['status']==='published' && $published['public_url']==='/seller#product-promotion-'.$publicKey
        && $card===['key'=>$publicKey,'product_id'=>$product,'headline'=>$settings['headline'],'copy'=>$settings['copy'],
            'cta'=>'See the mug','product_url'=>'/seller/product/blue-mug','image_url'=>'/profile-promotion-image.php?key='.$publicKey]
        && profile_product_promotion_image_v1($pdo,$publicKey)===$rendered
        && $action('creative_publish',$approval)['creative']===$published,
        'explicit approval publishes the exact graphic and CTA, and retry preserves the public key');
    promotion_check_v1(profile_product_promotion_public_v1($pdo,profile_for_user($pdo,2),$publicKey)===null,
        'the approval cannot project artwork onto another profile');
    $tampered = substr($rendered,0,-1).chr(ord(substr($rendered,-1)) ^ 1);
    file_put_contents($graphicFile,$tampered);
    promotion_check_v1(profile_product_promotion_image_v1($pdo,$publicKey)===null,
        'a same-size file substitution cannot expose unapproved public bytes');
    file_put_contents($graphicFile,$rendered);
    $setTarget('new-mug-link');
    promotion_check_v1(profile_product_promotion_public_v1($pdo,$ownerProfile(),$publicKey)===null
        && profile_product_promotion_image_v1($pdo,$publicKey)===null,'a changed destination removes the approved public promotion');
    $setTarget('blue-mug');
    $pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
    promotion_check_v1(profile_product_promotion_image_v1($pdo,$publicKey)===null,'public image authority is rechecked after a profile becomes private');
    $pdo->exec('UPDATE user_profiles SET is_public=1 WHERE user_id=1');

    promotion_reject_v1(fn()=> $action('creative_pause',$variant),422,'pausing requires explicit confirmation');
    $paused = $action('creative_pause',$variant+['confirmed'=>true])['creative'];
    promotion_check_v1($paused['status']==='paused' && $paused['public_url']===''
        && profile_product_promotions_public_v1($pdo,$ownerProfile())===[]
        && profile_product_promotion_image_v1($pdo,$publicKey)===null,'pausing immediately removes the card and its approved public image');
    promotion_reject_v1(fn()=> $action('creative_publish',$approval),409,'an older publish approval cannot replay after a pause');
    $freshApproval = $action('creative_publish_preview',$variant);
    $action('creative_publish',$variant+['confirmed'=>true,'review_token'=>$freshApproval['review_token']]);
    $newPublicKey = initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId)['public_key'];
    promotion_check_v1($newPublicKey!==$publicKey && profile_product_promotion_image_v1($pdo,$publicKey)===null
        && profile_product_promotion_image_v1($pdo,$newPublicKey)===$rendered,'fresh approval rotates the public image key');

    $second = array_replace($design,['nonce'=>str_repeat('b',32),'headline'=>'The morning mug','level'=>4]);
    $secondPreview = $action('creative_prepare',$second);
    $replacement = $action('creative_generate',$second+['confirmed'=>true,'review_token'=>$secondPreview['review_token']])['creative'];
    $replacementId = $replacement['id'];
    $replacementChoice = ['creative_id'=>$replacementId];
    $open = $action('creative_open',$selection);
    promotion_check_v1(count($open['variants'])===2 && $open['promotion']['id']===$creativeId
        && in_array($replacementId,array_column($open['variants'],'id'),true),
        'reopening a draft retains variants and the separately active promotion');
    $addAsset(8,1,$rendered,'promotion_design',['creative_id'=>$replacementId]);
    $action('creative_save',$replacementChoice+['asset_id'=>8]);
    $replacementReview = $action('creative_publish_preview',$replacementChoice);
    $replacementApproval = $replacementChoice+['confirmed'=>true,'review_token'=>$replacementReview['review_token']];

    $pdo->failSql = "SET status='published'";
    try {
        $action('creative_publish',$replacementApproval);
        throw new RuntimeException('Expected publication write failure.');
    } catch (PDOException) {
        promotion_check_v1(!$pdo->inTransaction() && profile_product_promotion_image_v1($pdo,$newPublicKey)===$rendered
            && initiative_work_marketing_promotion_row_v1($pdo,1,$replacementId)['status']==='saved',
            'failed replacement rolls back without losing the previous live card');
    } finally {
        $pdo->failSql = '';
    }
    $action('creative_publish',$replacementApproval);
    $cards = profile_product_promotions_public_v1($pdo,$ownerProfile());
    promotion_check_v1(count($cards)===1 && $cards[0]['headline']==='The morning mug'
        && initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId)['status']==='paused'
        && profile_product_promotion_image_v1($pdo,$newPublicKey)===null
        && profile_product_promotion_image_v1($pdo,$cards[0]['key'])===$rendered,
        'approving a replacement publishes only the selected variant and revokes the previous artwork');
    echo "INITIATIVE_MARKETING_PROMOTION_V1=PASS ($checks cases, {$fixture['driver']})\n";
} finally {
    foreach ($files as $file) if (is_file($file)) unlink($file);
    foreach (['/private/user-media/1','/private/user-media/2','/private/user-media','/private',''] as $suffix) {
        if (is_dir($root.$suffix)) rmdir($root.$suffix);
    }
    commerce_fixture_cleanup_pa2($fixture);
}
