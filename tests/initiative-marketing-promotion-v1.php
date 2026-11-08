<?php
declare(strict_types=1);

require __DIR__.'/fixtures/commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/profile-agent.php';
require_once dirname(__DIR__).'/includes/profile-conversation-checkout-pa5.php';
require_once dirname(__DIR__).'/includes/initiative-workbench-v1.php';
require_once dirname(__DIR__).'/includes/initiative-work-product-publication-v1.php';
require_once dirname(__DIR__).'/includes/initiative-work-marketing-promotion-v1.php';
require_once dirname(__DIR__).'/includes/profile-product-promotion-v1.php';
require_once dirname(__DIR__).'/includes/profile-product-photo-v1.php';

function has_permission(string $capability, array $user): bool
{
    return !empty($user['is_active']) && in_array($capability, ['chat.access','account.access'], true);
}

function vp3_plugin_effective_state_v360(PDO $pdo, array $user, string $key): array
{
    return ['enabled'=>$key==='campaigns_rewards' && ($GLOBALS['fixture_campaign_plugin_enabled'] ?? false)];
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
    vp3_profile_checkout_ensure_schema_pa5($pdo);
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT ''");
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

    $scope = ['agent_id'=>0,'conversation_id'=>11,'user'=>['id'=>1]];
    $secondChat = array_replace($scope,['conversation_id'=>12]);
    $otherOwner = ['agent_id'=>0,'conversation_id'=>21,'user'=>['id'=>2]];
    $sessionKey = str_repeat('1',64);
    $ownerProfile = static fn(): array => profile_for_user($pdo,1);
    $productInput = ['source_id'=>'1','request_id'=>str_repeat('f',64)];
    $draftReview = initiative_work_product_preview_v1($pdo,1,$scope,$productInput);
    $draftProduct = initiative_work_product_create_v1($pdo,1,$scope,$productInput,$draftReview,
        static function(string $prompt,?string $photo) use ($original): string {
            promotion_check_v1($photo==='data:image/png;base64,'.base64_encode($original),
                'photo drafting receives the exact saved original');
            return '{"title":"Blue mug","description":"A ceramic blue mug."}';
        });
    $product = $draftProduct['product_id'];
    $draftRow = profile_commerce_owner_product_v900($pdo,1,$product);
    $lineage = profile_commerce_metadata_v900($draftRow);
    promotion_check_v1($draftProduct['source_id']==='1' && $lineage['conversation_id']===11 && $lineage['agent_id']===0
        && $lineage['source_content_sha256']===hash('sha256',$original)
        && empty($draftRow['is_active']) && (int)$draftRow['price_cents']===0
        && profile_commerce_public_product_v900($pdo,$ownerProfile(),'blue-mug')===null
        && profile_product_photo_image_v1($pdo,'seller','blue-mug',$product)===null,
        'the photo-created draft is private, with its exact source and originating Chat');

    $details = ['revision'=>initiative_work_product_edit_revision_v1($draftRow),
        'price'=>'20.00','currency'=>'USD','product_type'=>'physical','fulfillment_type'=>'local_pickup',
        'provider_mode'=>'guest_choice','profile_slug'=>'blue-mug','profile_cta'=>'See the mug','show_listing_photo'=>'1'];
    $previewListing = static fn(array $input,int $owner=1): array =>
        initiative_work_product_publication_preview_v1($pdo,$owner,$product,$input,$sessionKey);
    $confirmListing = static fn(array $review,?string $key=null): array =>
        initiative_work_product_publication_confirm_v1($pdo,1,$product,$review['input'],$review['review_token'],$key??$sessionKey);
    promotion_reject_v1(fn()=> $previewListing($details,2),404,'another owner cannot review the private listing');
    promotion_reject_v1(fn()=> $previewListing($details),409,'the listing cannot be reviewed without a connected provider');
    $foreignProvider = commerce_fixture_insert_pa2($pdo,'agent_commerce_provider_connections_v800',[
        'owner_user_id'=>2,'provider'=>'stripe','external_account_id'=>'acct_fixture_other','status'=>'connected',
    ]);
    $connection = commerce_fixture_insert_pa2($pdo,'agent_commerce_provider_connections_v800',[
        'owner_user_id'=>1,'provider'=>'stripe','external_account_id'=>'acct_fixture_mug','account_label'=>'Mug shop',
        'status'=>'connected','capabilities_json'=>'{"checkout":true,"refunds":true}',
    ]);
    promotion_reject_v1(fn()=> $previewListing(array_replace($details,[
        'provider_mode'=>'fixed','fixed_connection_id'=>(string)$foreignProvider,
    ])),409,'the fixed provider must belong to the seller');
    $details['provider_mode']='fixed';
    $details['fixed_connection_id']=(string)$connection;
    putenv('VP3_COMMERCE_STRIPE_SECRET_KEY');
    try {
        promotion_reject_v1(fn()=> $previewListing($details),409,
            'a connected account without checkout configuration cannot make the listing sellable');
    } finally {
        putenv('VP3_COMMERCE_STRIPE_SECRET_KEY=fixture-stripe-key');
    }
    promotion_reject_v1(fn()=> $previewListing(array_replace($details,['price'=>'0.00'])),422,
        'a paid listing needs an explicit positive price');
    promotion_reject_v1(fn()=> $previewListing(array_replace($details,['price'=>'1000000.00'])),422,
        'a listing price cannot exceed the supported checkout amount');
    promotion_reject_v1(fn()=> $previewListing(array_replace($details,['currency'=>'JPY'])),422,
        'a currency must be compatible with the two-decimal checkout amounts');
    promotion_reject_v1(fn()=> $previewListing(array_replace($details,['fulfillment_type'=>'physical'])),422,
        'a draft cannot bypass the existing shipping restriction');
    $listingReview = $previewListing($details);
    promotion_check_v1($listingReview['product']['id']===$product
        && $listingReview['product']['product_url']==='/seller/product/blue-mug'
        && $listingReview['product']['price_cents']===2000 && $listingReview['product']['fulfillment_type']==='local_pickup'
        && (int)$listingReview['source_photo']['id']===1
        && array_map('intval',array_column($listingReview['providers'],'id'))===[$connection]
        && profile_commerce_owner_product_v900($pdo,1,$product)===$draftRow,
        'the review shows the exact price, fulfillment, provider, original photo and destination without changing the draft');
    promotion_reject_v1(fn()=> $confirmListing($listingReview,str_repeat('2',64)),409,
        'another session cannot approve the listing');
    $changedReview = $listingReview;
    $changedReview['input']['price']='20.01';
    promotion_reject_v1(fn()=> $confirmListing($changedReview),409,
        'approval cannot change a reviewed commercial term');
    $pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1');
    promotion_reject_v1(fn()=> $confirmListing($listingReview),409,'making the profile private invalidates listing approval');
    $pdo->exec('UPDATE user_profiles SET is_public=1 WHERE user_id=1');
    $listingReview = $previewListing($details);
    $collision = commerce_fixture_insert_pa2($pdo,'agent_commerce_products_v800',[
        'owner_user_id'=>1,'product_key'=>'slug-collision','title'=>'Another product',
        'metadata_json'=>'{"profile_visibility":"public","profile_slug":"blue-mug"}',
    ]);
    promotion_reject_v1(fn()=> $confirmListing($listingReview),409,'a new listing cannot take over the reviewed visitor URL');
    $pdo->prepare('DELETE FROM agent_commerce_products_v800 WHERE id=?')->execute([$collision]);
    $listingReview = $previewListing($details);
    $providerStatus = $pdo->prepare('UPDATE agent_commerce_provider_connections_v800 SET status=? WHERE id=?');
    $providerStatus->execute(['disconnected',$connection]);
    promotion_reject_v1(fn()=> $confirmListing($listingReview),409,'disconnecting the reviewed payment provider blocks activation');
    $providerStatus->execute(['connected',$connection]);
    $listingReview = $previewListing($details);
    $merchantAccount = $pdo->prepare('UPDATE agent_commerce_provider_connections_v800 SET external_account_id=? WHERE id=?');
    $merchantAccount->execute(['acct_fixture_changed',$connection]);
    promotion_reject_v1(fn()=> $confirmListing($listingReview),409,'the same connection ID cannot authorize a changed merchant account');
    $merchantAccount->execute(['acct_fixture_mug',$connection]);
    $listingReview = $previewListing($details);
    file_put_contents($originalFile,$rendered);
    promotion_reject_v1(fn()=> $confirmListing($listingReview),409,'changing the original photo invalidates listing approval');
    file_put_contents($originalFile,$original);
    $listingReview = $previewListing($details);
    initiative_work_product_update_v1($pdo,1,$product,$details['revision'],'Blue mug','A ceramic blue mug, ready for local pickup.');
    promotion_reject_v1(fn()=> $confirmListing($listingReview),409,'editing the private draft requires a new listing review');
    $details['revision'] = initiative_work_product_edit_revision_v1(profile_commerce_owner_product_v900($pdo,1,$product));
    $listingReview = $previewListing($details);
    $beforeApproval = profile_commerce_owner_product_v900($pdo,1,$product);
    $pdo->afterSql = static function(string $sql): void {
        if (preg_match('/\A\s*UPDATE agent_commerce_products_v800\b/i',$sql)) {
            throw new PDOException('Fixture interruption after writing the listing.');
        }
    };
    try {
        $confirmListing($listingReview);
        throw new RuntimeException('Expected an interrupted listing publication.');
    } catch (PDOException) {
        promotion_check_v1(!$pdo->inTransaction()
            && profile_commerce_owner_product_v900($pdo,1,$product)===$beforeApproval
            && profile_commerce_public_product_v900($pdo,$ownerProfile(),'blue-mug')===null,
            'an interrupted approval rolls back the in-place activation and leaves the draft private');
    } finally {
        $pdo->afterSql = null;
    }
    $listing = $confirmListing($listingReview);
    $activated = profile_commerce_owner_product_v900($pdo,1,$product);
    $activatedMeta = profile_commerce_metadata_v900($activated);
    $replayed = $confirmListing($listingReview);
    $publicListing = profile_commerce_public_product_v900($pdo,$ownerProfile(),'blue-mug');
    $visitorCatalog = vp3_profile_checkout_catalog_pa5($pdo,$ownerProfile());
    promotion_check_v1($listing['product']['id']===$product && $publicListing['id']===$product
        && $activated['product_key']===$draftRow['product_key'] && !initiative_work_product_draft_v1($activated)
        && $activatedMeta['source_asset_id']===$lineage['source_asset_id']
        && $activatedMeta['source_content_sha256']===$lineage['source_content_sha256']
        && $activatedMeta['agent_id']===0 && $activatedMeta['conversation_id']===11
        && file_get_contents($originalFile)===$original
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_commerce_products_v800')->fetchColumn()===1,
        'approval activates the same Commerce ID and preserves the exact source, Chat and listing key');
    promotion_check_v1($publicListing['price_cents']===2000 && $publicListing['description']==='A ceramic blue mug, ready for local pickup.'
        && $publicListing['checkout_url']==='/seller/product/blue-mug/checkout'
        && $visitorCatalog['products'][0]['product_id']===$product
        && $visitorCatalog['products'][0]['payment_providers']===[
            ['connection_id'=>$connection,'provider'=>'stripe','provider_label'=>'Stripe'],
        ]
        && profile_product_photo_image_v1($pdo,'seller','blue-mug',$product)===['bytes'=>$original,'mime'=>'image/png']
        && !$listing['replayed'] && $replayed['replayed'] && $replayed['product']===$listing['product']
        && $GLOBALS['pa2_http_calls']===[],
        'the listing resolves the approved checkout and provider, and a retry has no duplicate or provider side effect');
    $providerStatus->execute(['disconnected',$connection]);
    promotion_reject_v1(fn()=> $confirmListing($listingReview),409,
        'a publication replay requires a currently eligible payment provider');
    promotion_check_v1(profile_commerce_owner_product_v900($pdo,1,$product)===$activated
        && profile_commerce_public_product_v900($pdo,$ownerProfile(),'blue-mug')===$publicListing,
        'a payment disconnect does not rewrite the listing during a publication replay');
    $providerStatus->execute(['connected',$connection]);

    $action = static fn(string $operation, array $body=[], int $owner=1, ?array $chat=null, ?string $key=null): array =>
        initiative_work_marketing_promotion_action_v1($pdo,$owner,$chat ?? $scope,$body+['action'=>$operation],$key ?? $sessionKey);
    $setTarget = static fn(string $slug, string $visibility='public'): array =>
        profile_commerce_publish_v900($pdo,1,$product,[
            'profile_visibility'=>$visibility,'profile_slug'=>$slug,'profile_cta'=>'See the mug',
        ]);

    $draftKey = str_repeat('d',64);
    $draftBody = ['source_id'=>'1','product_id'=>$product,'request_id'=>$draftKey];
    $draftPreview = initiative_work_marketing_preview_v1($pdo,1,$scope,$draftBody);
    promotion_check_v1(($draftPreview['details']['linked_product_image']??null)===true,
        'the marketing review recognizes the exact source photo of the activated product');
    file_put_contents($originalFile,$rendered);
    $substitutedPreview = initiative_work_marketing_preview_v1($pdo,1,$scope,$draftBody);
    promotion_check_v1(($substitutedPreview['details']['linked_product_image']??null)===false,
        'the same saved media ID cannot claim the product link after its original bytes change');
    file_put_contents($originalFile,$original);
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
    promotion_reject_v1(fn()=> $confirmListing($listingReview),409,'a consumed listing review cannot undo a later decision to hide the product');
    promotion_check_v1(profile_commerce_public_product_v900($pdo,$ownerProfile(),'blue-mug')===null,
        'the listing stays hidden until the seller deliberately changes visibility again');
    $setTarget('blue-mug');
    file_put_contents($graphicFile,$rendered.'x');
    promotion_reject_v1(fn()=> $action('creative_publish',$approval),409,'a changed artwork file invalidates approval');
    file_put_contents($graphicFile,$rendered);

    $published = $action('creative_publish',$approval)['creative'];
    $publicKey = initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId)['public_key'];
    $card = profile_product_promotion_public_v1($pdo,$ownerProfile(),$publicKey);
    $visitorQuery = 'Can I buy the blue mug?';
    $visitorContext = profile_commerce_agent_context_v900($pdo,$ownerProfile(),$visitorQuery);
    promotion_check_v1($published['status']==='published' && $published['public_url']==='/seller#product-promotion-'.$publicKey
        && $card===['key'=>$publicKey,'product_id'=>$product,'headline'=>$settings['headline'],'copy'=>$settings['copy'],
            'cta'=>'See the mug','product_url'=>'/seller/product/blue-mug','image_url'=>'/profile-promotion-image.php?key='.$publicKey]
        && profile_product_promotion_image_v1($pdo,$publicKey)===$rendered
        && profile_product_promotion_match_v1($pdo,$ownerProfile(),$visitorQuery,$visitorContext)===$publicKey
        && $card['product_url']===$publicListing['product_url']
        && $action('creative_publish',$approval)['creative']===$published,
        'the Profile Agent can show the exact approved graphic and link to the same activated product');
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
        && profile_product_promotion_image_v1($pdo,$publicKey)===null
        && profile_product_promotion_match_v1($pdo,$ownerProfile(),$visitorQuery,$visitorContext)===null
        && profile_commerce_public_product_v900($pdo,$ownerProfile(),'blue-mug')===$publicListing
        && vp3_profile_checkout_catalog_pa5($pdo,$ownerProfile())===$visitorCatalog
        && profile_product_photo_image_v1($pdo,'seller','blue-mug',$product)===['bytes'=>$original,'mime'=>'image/png'],
        'pausing removes the approved graphic and Agent card while the exact sellable listing stays public');
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

    $textInput = ['source_id'=>'description','product_description'=>'A digital guide to ceramic glazing.',
        'request_id'=>str_repeat('e',64)];
    $textDraft = initiative_work_product_create_v1($pdo,1,$scope,$textInput,
        initiative_work_product_preview_v1($pdo,1,$scope,$textInput),
        static function(string $prompt,?string $photo): string {
            promotion_check_v1($photo===null,'the existing description draft path has no photo to disclose');
            return '{"title":"Glazing guide","description":"A digital guide to ceramic glazing."}';
        });
    $textId = $textDraft['product_id'];
    $textRow = profile_commerce_owner_product_v900($pdo,1,$textId);
    $textTerms = array_replace($details,['revision'=>initiative_work_product_edit_revision_v1($textRow),
        'product_type'=>'digital','fulfillment_type'=>'digital','profile_slug'=>'glazing-guide','show_listing_photo'=>'0']);
    $textReview = initiative_work_product_publication_preview_v1($pdo,1,$textId,$textTerms,$sessionKey);
    $textListing = initiative_work_product_publication_confirm_v1($pdo,1,$textId,$textReview['input'],
        $textReview['review_token'],$sessionKey);
    promotion_check_v1($textListing['product']['id']===$textId && $textListing['source_photo']===null
        && profile_commerce_public_product_v900($pdo,$ownerProfile(),'glazing-guide')['id']===$textId
        && profile_product_photo_image_v1($pdo,'seller','glazing-guide',$textId)===null,
        'existing text-only drafts can activate without inventing or releasing a photo');

    // Exercise the canonical Campaigns entitlement, merchant membership and Campaign readers.
    require_once dirname(__DIR__).'/includes/campaigns-rewards-platform-v100.php';
    require_once dirname(__DIR__).'/includes/campaigns-rewards-domain-v100.php';
    require_once dirname(__DIR__).'/includes/campaigns-rewards-v100.php';
    $GLOBALS['fixture_campaign_plugin_enabled'] = true;
    $pdo->exec('CREATE TABLE merchant_accounts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,public_id VARCHAR(36),name VARCHAR(190),status VARCHAR(20),sandbox_mode INTEGER)');
    $pdo->exec('CREATE TABLE merchant_roles(id INTEGER PRIMARY KEY,role_key VARCHAR(30),name VARCHAR(80))');
    $pdo->exec('CREATE TABLE merchant_members(id INTEGER PRIMARY KEY,merchant_id INTEGER,user_id INTEGER,role_id INTEGER,status VARCHAR(20),is_owner INTEGER)');
    $pdo->exec('CREATE TABLE campaign_types(id INTEGER PRIMARY KEY,type_key VARCHAR(80),name VARCHAR(80),supports_public_signup INTEGER,supports_cases INTEGER)');
    $pdo->exec('CREATE TABLE campaigns(id INTEGER PRIMARY KEY,public_id VARCHAR(36),merchant_id INTEGER,campaign_type_id INTEGER,
        name VARCHAR(190),status VARCHAR(20),environment VARCHAR(12),starts_at DATETIME,ends_at DATETIME,current_version_no INTEGER)');
    $pdo->exec('CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY,public_id VARCHAR(36),owner_user_id INTEGER,vp3_user_id INTEGER,
        status VARCHAR(20),lifecycle_stage VARCHAR(20),marketing_status VARCHAR(20),metadata_json TEXT)');
    $pdo->exec('CREATE TABLE reward_claims(id INTEGER PRIMARY KEY,holder_user_id INTEGER,holder_contact_id INTEGER)');
    foreach ([...campaigns_rewards_platform_required_tables_v100(),'workspace_team_access_v1','workspace_team_invitation_scopes_v1'] as $name) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$name` (id INTEGER PRIMARY KEY)");
    }
    $pdo->exec("INSERT INTO merchant_accounts VALUES(1,1,'seller-merchant','Seller shop','active',0),
        (2,2,'other-merchant','Other shop','active',0),(3,1,'restricted-merchant','Restricted shop','active',0),
        (4,1,'sandbox-merchant','Sandbox shop','active',1)");
    $pdo->exec("INSERT INTO merchant_roles VALUES(1,'owner','Owner')");
    $pdo->exec("INSERT INTO merchant_members VALUES(1,1,1,1,'active',1),(2,2,2,1,'active',1),
        (3,3,1,1,'suspended',1),(4,4,1,1,'active',1)");
    $pdo->exec("INSERT INTO campaign_types VALUES(1,'post_purchase','Post-purchase',0,0)");
    $pdo->exec("INSERT INTO campaigns VALUES(11,'summer-campaign',1,1,'Summer table','active','production',NULL,NULL,3),
        (12,'winter-campaign',1,1,'Winter table','active','production',NULL,NULL,2),
        (21,'other-campaign',2,1,'Another shop','active','production',NULL,NULL,1),
        (31,'restricted-campaign',3,1,'Restricted campaign','active','production',NULL,NULL,1),
        (41,'sandbox-campaign',4,1,'Sandbox merchant','active','production',NULL,NULL,1),
        (51,'paused-campaign',1,1,'Paused campaign','paused','production',NULL,NULL,1),
        (61,'test-campaign',1,1,'Sandbox campaign','active','sandbox',NULL,NULL,1),
        (71,'future-campaign',1,1,'Future campaign','active','production','2099-01-01 00:00:00',NULL,1)");
    $choices = $action('creative_open',$selection+['creative_id'=>$replacementId])['campaigns'];
    promotion_check_v1(array_column($choices,'id')===[11,12],'campaign picker lists only eligible owned production campaigns');
    foreach ([21,31,41,51,61,71] as $ineligible) {
        promotion_reject_v1(fn()=> $action('creative_publish_preview',$replacementChoice+['campaign_id'=>$ineligible]),409,
            'another owner, denied access, sandbox, inactive and future campaigns cannot be selected');
    }
    $unlinked = $cards[0]['key'];
    $linkChoice = $replacementChoice+['campaign_id'=>11];
    $linkReview = $action('creative_publish_preview',$linkChoice);
    promotion_check_v1($linkReview['campaign']===['id'=>11,'name'=>'Summer table']
        && profile_promotion_attribution_campaign_v1($pdo,1,$unlinked)===null,
        'the campaign preview is private and does not change the live publication');
    $linkConfirmation = ['confirmed'=>true,'review_token'=>$linkReview['review_token']];
    promotion_reject_v1(fn()=> $action('creative_publish',$replacementChoice+['campaign_id'=>12]+$linkConfirmation),409,
        'the reviewed campaign cannot be substituted at confirmation');
    $pdo->exec('UPDATE campaigns SET current_version_no=4 WHERE id=11');
    promotion_reject_v1(fn()=> $action('creative_publish',$linkChoice+$linkConfirmation),409,
        'a changed Campaign version requires another promotion review');
    $linkReview = $action('creative_publish_preview',$linkChoice);
    $linkConfirmation['review_token'] = $linkReview['review_token'];
    $linked = $action('creative_publish',$linkChoice+$linkConfirmation)['creative'];
    $linkedKey = initiative_work_marketing_promotion_row_v1($pdo,1,$replacementId)['public_key'];
    promotion_check_v1($linked['campaign_id']===11 && $unlinked!==$linkedKey
        && profile_promotion_attribution_campaign_v1($pdo,1,$linkedKey)===11
        && profile_promotion_attribution_campaign_v1($pdo,2,$linkedKey)===null
        && profile_product_promotion_public_v1($pdo,$ownerProfile(),$unlinked)===null
        && $action('creative_publish',$linkChoice+$linkConfirmation)['creative']===$linked,
        'the exact confirmation replaces the public key and retries return the same scoped Campaign publication');
    promotion_reject_v1(fn()=> $action('creative_publish',$replacementApproval),409,
        'an older unlinked approval cannot revert the current Campaign publication');
    $secondChoice = $replacementChoice+['campaign_id'=>12];
    $secondReview = $action('creative_publish_preview',$secondChoice);
    $secondConfirm = ['confirmed'=>true,'review_token'=>$secondReview['review_token']];
    $pdo->exec("UPDATE merchant_members SET status='suspended' WHERE id=1");
    promotion_reject_v1(fn()=> $action('creative_publish',$secondChoice+$secondConfirm),409,
        'Campaign access is checked again before confirming publication');
    $pdo->exec("UPDATE merchant_members SET status='active' WHERE id=1");
    $GLOBALS['fixture_campaign_plugin_enabled'] = false;
    promotion_reject_v1(fn()=> $action('creative_publish',$secondChoice+$secondConfirm),409,
        'disabled Campaigns cannot accept a previously reviewed association');
    $unlinkChoice = $replacementChoice+['campaign_id'=>null];
    $unlinkReview = $action('creative_publish_preview',$unlinkChoice);
    $unlinkedAgain = $action('creative_publish',$unlinkChoice+['confirmed'=>true,'review_token'=>$unlinkReview['review_token']])['creative'];
    promotion_check_v1($unlinkedAgain['campaign_id']===null && profile_promotion_attribution_campaign_v1($pdo,1,$linkedKey)===11
        && profile_product_promotion_public_v1($pdo,$ownerProfile(),$linkedKey)===null,
        'an explicitly unlinked replacement leaves the prior publication attributed to its original campaign');
    echo "INITIATIVE_MARKETING_PROMOTION_V1=PASS ($checks cases, {$fixture['driver']})\n";
} finally {
    foreach ($files as $file) if (is_file($file)) unlink($file);
    foreach (['/private/user-media/1','/private/user-media/2','/private/user-media','/private',''] as $suffix) {
        if (is_dir($root.$suffix)) rmdir($root.$suffix);
    }
    commerce_fixture_cleanup_pa2($fixture);
}
