<?php
declare(strict_types=1);

// Real launch, Commerce, Chat, image admission, claimant and receipts. Models
// and their provider transport use fixed local results so nothing is billed.
require __DIR__.'/fixtures/commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-worker-cloud-v1910.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/initiative-workbench-v1.php';
require_once dirname(__DIR__).'/includes/initiative-work-product-publication-v1.php';
require_once dirname(__DIR__).'/includes/initiative-work-marketing-promotion-v1.php';
require_once dirname(__DIR__).'/includes/initiative-product-launch-v1.php';
require_once dirname(__DIR__).'/includes/profile-agent.php';
require_once dirname(__DIR__).'/includes/profile-conversation-checkout-pa5.php';
require_once dirname(__DIR__).'/includes/profile-product-promotion-v1.php';

function has_permission(string $key, array $user): bool
{
    return !empty($user['is_active']) && !in_array($key,$GLOBALS['launch_denied']??[],true);
}
function ai_provider_api_key(string $provider): string { return 'fixture-image-key'; }
function ai_provider_enabled(string $provider): bool { return $provider === 'openai'; }
function subscription_has_entitlement(...$args): bool { return true; }
function subscription_ai_preflight(...$args): array { return ['reservation_id'=>23,'max_output_tokens'=>16000]; }
function subscription_ai_commit_usage(...$args): void {}
function subscription_ai_release_reservation(int $id): void {}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agent,string $surface): array
{
    return ['route'=>'vp3_cloud','blocked'=>false,'cloud'=>['ready'=>true]];
}

function launch_check_v1(bool $passed,string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}

function launch_reject_v1(callable $operation,string $label): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        launch_check_v1(in_array($error->getCode(),[404,409],true),$label);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}

function launch_png_v1(int $width,int $height,string $pixel): string
{
    $chunk = static fn(string $type,string $data): string => pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,6,0,0,0))
        .$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat($pixel,$width),$height))).$chunk('IEND','');
}

$fixture = commerce_fixture_config_pa2();
$pdo = commerce_fixture_connect_pa2($fixture);
$root = sys_get_temp_dir().'/vp3-product-launch-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
$checks = 0;
$calls = 0;
try {
    commerce_fixture_schema_pa2($pdo);
    agent_work_control_ensure_schema_v173($pdo);
    vp3_profile_checkout_ensure_schema_pa5($pdo);
    initiative_product_launch_schema_v1($pdo);
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NOT NULL DEFAULT 'Seller'");
    $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'user'");
    $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(150) NOT NULL DEFAULT ''");
    $pdo->exec('ALTER TABLE user_agents ADD COLUMN owner_user_id INTEGER NOT NULL');
    $pdo->exec('ALTER TABLE user_agents ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec("ALTER TABLE user_agents ADD COLUMN display_name VARCHAR(80) NOT NULL DEFAULT 'Launch Agent'");
    $pdo->exec('INSERT INTO user_agents(id,owner_user_id) VALUES(7,1),(8,1),(9,2)');
    $pdo->exec('CREATE TABLE user_profiles(user_id INTEGER PRIMARY KEY,username VARCHAR(80),is_public INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO user_profiles VALUES(1,'seller',1),(2,'other',1)");
    $pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,user_agent_id INTEGER,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(11,1,7),(12,1,7),(13,1,8),(21,2,9)');
    $pdo->exec('CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,
        role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE user_media_assets(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INTEGER,media_type VARCHAR(12),
        title VARCHAR(190),mime_type VARCHAR(30),file_path VARCHAR(250),file_size INTEGER NOT NULL,duration_seconds INTEGER DEFAULT 0,
        source VARCHAR(30),metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE video_editor_projects(id INTEGER PRIMARY KEY,user_id INTEGER)');
    mkdir($root.'/private/user-media/1',0700,true);
    $photo = launch_png_v1(32,32,"\x30\x60\xa0\xff");
    $addAsset = static function(string $bytes,string $source,array $metadata=[]) use ($pdo,$root): array {
        $relative = 'private/user-media/1/asset-'.bin2hex(random_bytes(8)).'.png';
        file_put_contents($root.'/'.$relative,$bytes);
        $pdo->prepare("INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,source,metadata_json)
            VALUES(1,'photo','Fixture photo','image/png',?,?,?,?)")
            ->execute([$relative,strlen($bytes),$source,json_encode($metadata,JSON_THROW_ON_ERROR)]);
        return [(int)$pdo->lastInsertId(),$root.'/'.$relative];
    };
    [$assetId,$photoFile] = $addAsset($photo,'upload');
    [$otherAsset] = $addAsset($photo,'upload');
    $user = ['id'=>1,'is_active'=>1];
    $scope = ['agent_id'=>7,'conversation_id'=>11,'user'=>$user];
    $sameAgentOtherChat = array_replace($scope,['conversation_id'=>12]);
    $otherAgent = array_replace($scope,['agent_id'=>8,'conversation_id'=>13]);
    $otherOwner = ['agent_id'=>9,'conversation_id'=>21,'user'=>['id'=>2,'is_active'=>1]];
    $sessionKey = str_repeat('1',64);
    $action = static fn(string $action,array $body,?array $chat=null,int $owner=1): array =>
        initiative_work_marketing_promotion_action_v1($pdo,$owner,$chat??$scope,$body+['action'=>$action],$sessionKey);
    $notices = static function() use ($pdo): array {
        $messages = $pdo->query("SELECT conversation_id,role,context_json FROM chat_messages ORDER BY id")->fetchAll();
        return array_values(array_filter(array_map(static function(array $message): ?array {
            $context = json_decode((string)$message['context_json'],true,16,JSON_THROW_ON_ERROR);
            return ($context['source']??null) === 'agent_product_launch'
                ? ['conversation_id'=>(int)$message['conversation_id'],'role'=>$message['role']]+$context['product_launch']
                : null;
        },$messages)));
    };
    $productInput = ['source_id'=>(string)$assetId,'request_id'=>str_repeat('a',64)];
    $productDraft = initiative_work_product_create_v1($pdo,1,$scope,$productInput,
        initiative_work_product_preview_v1($pdo,1,$scope,$productInput),
        static fn(): string => '{"title":"Blue mug","description":"A ceramic blue mug."}');
    $productId = $productDraft['product_id'];
    $due = static function() use ($pdo,$productId): void {
        $pdo->prepare('UPDATE agent_product_launches_v1 SET next_check_at=? WHERE product_id=?')
            ->execute(['2000-01-01 00:00:00',$productId]);
    };
    $sweep = static fn(): int => initiative_product_launch_sweep_v1($pdo,20);
    $retry = static function() use ($sweep,$due): int {
        $due();
        return $sweep();
    };
    $sweep();
    $initial = $notices();
    launch_check_v1(count($initial)===1 && $initial[0]['product_id']===$productId
        && $initial[0]['conversation_id']===11 && $initial[0]['role']==='assistant' && $initial[0]['stage']==='listing_review'
        && initiative_work_product_draft_v1(profile_commerce_owner_product_v900($pdo,1,$productId)),
        'the original Agent Chat receives one next step for the exact private draft');
    $retry();
    launch_check_v1($notices()===$initial,'a repeated worker cycle does not duplicate the private draft notice');

    $connection = commerce_fixture_insert_pa2($pdo,'agent_commerce_provider_connections_v800',[
        'owner_user_id'=>1,'provider'=>'stripe','external_account_id'=>'acct_launch_fixture','status'=>'connected',
        'capabilities_json'=>'{"checkout":true}',
    ]);
    $listingInput = ['revision'=>initiative_work_product_edit_revision_v1(profile_commerce_owner_product_v900($pdo,1,$productId)),
        'price'=>'20.00','currency'=>'USD','product_type'=>'physical','fulfillment_type'=>'local_pickup',
        'provider_mode'=>'fixed','fixed_connection_id'=>(string)$connection,'profile_slug'=>'blue-mug',
        'profile_cta'=>'See the mug','show_listing_photo'=>'0'];
    $review = initiative_work_product_publication_preview_v1($pdo,1,$productId,$listingInput,$sessionKey);
    initiative_work_product_publication_confirm_v1($pdo,1,$productId,$review['input'],$review['review_token'],$sessionKey);
    $profile = profile_for_user($pdo,1);
    $listing = profile_commerce_public_product_v900($pdo,$profile,'blue-mug');
    $sweep();
    $listingNotices = $notices();
    launch_check_v1($listing['id']===$productId && $listing['price_cents']===2000
        && profile_product_promotions_public_v1($pdo,$profile)===[] && end($listingNotices)['stage']==='marketing_review',
        'listing approval activates the same product without creating a public promotion');

    $draftId = str_repeat('d',64);
    $draftInput = ['source_id'=>(string)$assetId,'product_id'=>$productId,'request_id'=>$draftId];
    initiative_work_marketing_create_v1($pdo,1,$scope,$draftInput,
        initiative_work_marketing_preview_v1($pdo,1,$scope,$draftInput),
        static fn(): string => '{"headline":"Blue mug","copy":"A ceramic blue mug.","campaign_idea":"Feature the mug on the seller profile."}');
    $selection = ['draft_id'=>$draftId,'source_id'=>(string)$assetId,'product_id'=>$productId];
    $design = $selection+['nonce'=>str_repeat('a',32),'level'=>7,'headline'=>'The morning mug','copy'=>'A blue mug for your first coffee.',
        'prompt'=>'A bright café table','style'=>'clean','remove_background'=>false,'tolerance'=>24];
    $sweep();
    $beforeReview = $notices();
    $draftNotice = end($beforeReview);
    launch_check_v1($draftNotice['stage']==='design_review' && $draftNotice['draft_id']===$draftId
        && $draftNotice['source_id']===(string)$assetId && $draftNotice['conversation_id']===11,
        'the exact saved draft reaches the original Chat to start a private design');
    $designReview = $action('creative_prepare',$design);
    launch_check_v1(!table_exists('agent_image_generations_v1') && $notices()===$beforeReview && $calls===0,
        'a private design review creates neither an image job nor a launch notice');
    $creative = $action('creative_generate',$design+['review_token'=>$designReview['review_token'],'confirmed'=>true])['creative'];
    $creativeId = $creative['id'];
    $variant = initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId);
    $generation = vp3_image_row_v1($pdo,$user,$variant['generation_id']);
    $runId = (int)$generation['run_id'];
    $claim = agent_job_claim_run_v1900($pdo,$user,$runId,'cloud',agent_worker_cloud_worker_id_v1910(1),300);
    $sweep();
    launch_check_v1($creative['status']==='queued' && is_array($claim) && $calls===0,
        'explicit approval admits one exact image job to the shared Cloud worker');

    // The browser stays closed. Interrupt only Chat storage, after the image
    // worker has obtained verified provider bytes through its real executor.
    $result = vp3_image_execute_v1($pdo,$user,$claim,static function(string $url,array $body) use (&$calls): array {
        $calls++;
        launch_check_v1(!isset($body['images']) && str_ends_with($url,'/images/generations'),
            'the approved level seven scene never sends the original product photo');
        [$width,$height] = array_map('intval',explode('x',$body['size']));
        return ['data'=>[['b64_json'=>base64_encode(launch_png_v1($width,$height,"\x40\x60\x80\xff"))]],
            'usage'=>['input_tokens'=>20,'output_tokens'=>200,'total_tokens'=>220]];
    });
    $image = vp3_image_row_v1($pdo,$user,$variant['generation_id']);
    $receiptQuery = $pdo->prepare('SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? AND status=?');
    $receiptQuery->execute([$runId,'completed']);
    $receipts = $receiptQuery->fetchAll();
    $beforeDelivery = $notices();
    $interrupted = 0;
    $pdo->afterSql = static function(string $sql) use (&$interrupted): void {
        if (str_starts_with($sql,'INSERT INTO chat_messages')) {
            $interrupted++;
            throw new PDOException('Fixture interruption after inserting Chat result.');
        }
    };
    $sweep();
    $pdo->afterSql = null;
    launch_check_v1($result['ok'] && count($receipts)===1 && $calls===1 && (int)$image['asset_id']>0
        && agent_workflow_row_v1400($pdo,1,$runId)['status']==='completed' && $interrupted===1
        && $notices()===$beforeDelivery && !$pdo->inTransaction(),
        'image, usage and canonical receipt survive a rolled-back Chat insert without a browser tick');

    foreach ([[2,9,21],[1,7,12],[1,8,13]] as [$owner,$agent,$chat]) {
        $due();
        initiative_product_launch_sweep_v1($pdo,20,$owner,$agent,$chat);
    }
    launch_check_v1($notices()===$beforeDelivery,'foreign owner, Agent and Chat sweeps do not pull the completion');
    [$graphic] = $addAsset(launch_png_v1(32,32,"\x22\x44\x66\xff"),'promotion_design',['creative_id'=>$creativeId]);
    $checkInvalidCreative = static function(string $evidence) use ($action,$selection,$creativeId,$graphic): void {
        $opened = $action('creative_open',$selection+['creative_id'=>$creativeId])['creative'];
        launch_check_v1($opened['id']===$creativeId && $opened['status']==='failed' && $opened['generated_url']==='',
            'the exact resume withholds a preview after '.$evidence);
        launch_reject_v1(fn()=> $action('creative_save',['creative_id'=>$creativeId,'asset_id'=>$graphic]),
            'the selected graphic cannot be saved after '.$evidence);
    };
    $updateReceipt = $pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?');
    $receipt = $receipts[0];
    $proof = json_decode($receipt['result_json'],true,16,JSON_THROW_ON_ERROR);
    $updateReceipt->execute([json_encode(array_replace($proof,['asset_id'=>$otherAsset]),JSON_THROW_ON_ERROR),$receipt['id']]);
    $retry();
    $failedProof = $notices();
    launch_check_v1(!in_array('creative_ready',array_column($failedProof,'stage'),true),
        'a completed status and substituted receipt do not authorize a ready notice');
    $checkInvalidCreative('a substituted receipt');
    $updateReceipt->execute([$receipt['result_json'],$receipt['id']]);
    $generatedPath = $root.'/'.media_studio_asset((int)$image['asset_id'],$user)['file_path'];
    $generated = file_get_contents($generatedPath);
    file_put_contents($generatedPath,substr($generated,0,-1).chr(ord(substr($generated,-1))^1));
    $retry();
    $failedBytes = $notices();
    launch_check_v1(!in_array('creative_ready',array_column($failedBytes,'stage'),true),
        'same-size substituted output bytes do not authorize a ready notice');
    $checkInvalidCreative('same-size substituted output bytes');
    file_put_contents($generatedPath,$generated);
    file_put_contents($photoFile,launch_png_v1(32,32,"\x44\x66\x88\xff"));
    $retry();
    launch_check_v1($notices()===$failedBytes,'a changed original blocks delivery of its earlier generated scene');
    file_put_contents($photoFile,$photo);
    $GLOBALS['launch_denied'] = ['chat.access'];
    $retry();
    unset($GLOBALS['launch_denied']);
    launch_check_v1($notices()===$failedBytes,'a revoked Chat permission prevents private completion delivery');

    $freshReview = $action('creative_prepare',$design);
    $sameDesign = $action('creative_generate',$design+['review_token'=>$freshReview['review_token'],'confirmed'=>true])['creative'];
    launch_check_v1($sameDesign['id']===$creativeId && $sameDesign['status']==='ready' && $calls===1,
        'an exact re-review after restored access rearms the existing design without a provider replay');
    $sweep();
    $delivered = $notices();
    $resume = end($delivered);
    launch_check_v1(count($delivered)===count($failedBytes)+1 && $resume['conversation_id']===11 && $resume['stage']==='creative_ready'
        && $resume['owner_user_id']===1 && $resume['agent_id']===7 && $resume['product_id']===$productId
        && $resume['draft_id']===$draftId && $resume['source_id']===(string)$assetId && $resume['creative_id']===$creativeId,
        'a browser-closed sweep brings the exact product, original, draft and completed design to its original Chat');
    $retry();
    launch_check_v1($notices()===$delivered && $calls===1
        && agent_job_claim_run_v1900($pdo,$user,$runId,'cloud',agent_worker_cloud_worker_id_v1910(1),300)===null
        && initiative_work_marketing_promotion_row_v1($pdo,1,$creativeId)['status']==='designing'
        && profile_product_promotions_public_v1($pdo,$profile)===[],
        'repeated delivery is idempotent, never replays the provider, and never saves or publishes artwork');

    // Older than the normal 12-variant query, with other drafts and sources
    // available to make silent fallback detectable.
    $pdo->prepare('UPDATE agent_commerce_promotions_v1 SET created_at=?,updated_at=? WHERE id=?')
        ->execute(['2000-01-01 00:00:00','2000-01-01 00:00:00',$creativeId]);
    $addVariant = $pdo->prepare('INSERT INTO agent_commerce_promotions_v1
        (id,product_id,owner_user_id,draft_key,agent_id,conversation_id,source_asset_id,source_sha256,settings_json)
        VALUES(?,?,1,?,7,11,?,?,?)');
    $localSettings = json_encode(array_replace($variant['settings'],['level'=>1]),JSON_THROW_ON_ERROR);
    for ($i=1;$i<=13;$i++) {
        $addVariant->execute([sprintf('%032x',$i),$productId,$draftId,$assetId,hash('sha256',$photo),$localSettings]);
    }
    $ordinary = $action('creative_open',$selection);
    $exact = $action('creative_open',$selection+['creative_id'=>$creativeId]);
    launch_check_v1(count($ordinary['variants'])===12 && !in_array($creativeId,array_column($ordinary['variants'],'id'),true)
        && $exact['creative']['id']===$creativeId && $exact['creative']['status']==='ready'
        && in_array($creativeId,array_column($exact['variants'],'id'),true),
        'the completion resumes the exact aged variant even outside the recent variant window');
    foreach ([$sameAgentOtherChat,$otherAgent] as $wrongChat) {
        launch_reject_v1(fn()=> $action('creative_open',$selection+['creative_id'=>$creativeId],$wrongChat),
            'another Chat or Agent cannot open the tracked design');
    }
    launch_reject_v1(fn()=> $action('creative_open',$selection+['creative_id'=>$creativeId],$otherOwner,2),
        'another owner cannot open the tracked design');
    launch_reject_v1(fn()=> $action('creative_open',array_replace($selection+['creative_id'=>$creativeId],['source_id'=>(string)$otherAsset])),
        'a same-byte asset with a different ID cannot open the tracked design');
    launch_reject_v1(fn()=> $action('creative_open',array_replace($selection+['creative_id'=>$creativeId],['draft_id'=>str_repeat('e',64)])),
        'another draft identifier cannot open the tracked design');
    launch_reject_v1(fn()=> $action('creative_open',array_replace($selection+['creative_id'=>$creativeId],['product_id'=>$productId+1])),
        'another product identifier cannot open the tracked design');

    $saved = $action('creative_save',['creative_id'=>$creativeId,'asset_id'=>$graphic])['creative'];
    $sweep();
    $saveNotices = $notices();
    launch_check_v1($saved['status']==='saved' && profile_product_promotions_public_v1($pdo,$profile)===[]
        && end($saveNotices)['stage']==='publication_review',
        'saving the selected graphic still leaves publication for a distinct review');
    $publishReview = $action('creative_publish_preview',['creative_id'=>$creativeId]);
    $live = $action('creative_publish',['creative_id'=>$creativeId,'review_token'=>$publishReview['review_token'],'confirmed'=>true])['creative'];
    $sweep();
    $cards = profile_product_promotions_public_v1($pdo,$profile);
    $publishedNotices = $notices();
    launch_check_v1($live['status']==='published' && count($cards)===1 && $cards[0]['product_id']===$productId
        && $cards[0]['product_url']===$listing['product_url'] && $calls===1 && $GLOBALS['pa2_http_calls']===[]
        && end($publishedNotices)['stage']==='published',
        'only the explicit promotion approval exposes the exact graphic and its same-product listing');
    $action('creative_pause',['creative_id'=>$creativeId,'confirmed'=>true]);
    $sweep();
    $pausedNotices = $notices();
    launch_check_v1(profile_product_promotions_public_v1($pdo,$profile)===[]
        && profile_commerce_public_product_v900($pdo,$profile,'blue-mug')===$listing && end($pausedNotices)['stage']==='paused',
        'pausing the completed launch removes the promotion while the verified listing remains live');

    $textInput = ['source_id'=>'description','product_description'=>'A digital ceramics guide.','request_id'=>str_repeat('b',64)];
    $textDraft = initiative_work_product_create_v1($pdo,1,$scope,$textInput,
        initiative_work_product_preview_v1($pdo,1,$scope,$textInput),
        static fn(): string => '{"title":"Glazing guide","description":"A digital ceramics guide."}');
    $textId = $textDraft['product_id'];
    $sweep();
    $textDraftNotices = $notices();
    launch_check_v1(end($textDraftNotices)['product_id']===$textId && end($textDraftNotices)['stage']==='listing_review',
        'a description-only private draft reaches its original Chat for listing review');
    $textTerms = array_replace($listingInput,[
        'revision'=>initiative_work_product_edit_revision_v1(profile_commerce_owner_product_v900($pdo,1,$textId)),
        'product_type'=>'digital','fulfillment_type'=>'digital','profile_slug'=>'glazing-guide','profile_cta'=>'See the guide',
    ]);
    $textReview = initiative_work_product_publication_preview_v1($pdo,1,$textId,$textTerms,$sessionKey);
    initiative_work_product_publication_confirm_v1($pdo,1,$textId,$textReview['input'],$textReview['review_token'],$sessionKey);
    $sweep();
    $textListing = profile_commerce_public_product_v900($pdo,$profile,'glazing-guide');
    $photoNotices = $notices();
    $photoNotice = end($photoNotices);
    launch_check_v1($textListing['id']===$textId && $textReview['source_photo']===null
        && $photoNotice['product_id']===$textId && $photoNotice['stage']==='photo_needed'
        && empty($photoNotice['source_id']) && $photoNotice['conversation_id']===11,
        'listing approval asks for a specific photo choice and never invents an original');
    $chosenDraft = str_repeat('c',64);
    $chosenInput = ['source_id'=>(string)$otherAsset,'product_id'=>$textId,'request_id'=>$chosenDraft];
    $chosenPreview = initiative_work_marketing_preview_v1($pdo,1,$scope,$chosenInput);
    $sweep();
    launch_check_v1($notices()===$photoNotices && $chosenPreview['source_id']===(string)$otherAsset,
        'choosing a photo for review alone leaves the launch waiting for approval');
    initiative_work_marketing_create_v1($pdo,1,$scope,$chosenInput,$chosenPreview,
        static fn(): string => '{"headline":"Glazing guide","copy":"A digital ceramics guide.","campaign_idea":"Feature the guide on the seller profile."}');
    $sweep();
    $chosenNotices = $notices();
    $chosenNotice = end($chosenNotices);
    launch_check_v1($chosenNotice['stage']==='design_review' && $chosenNotice['product_id']===$textId
        && $chosenNotice['source_id']===(string)$otherAsset && $chosenNotice['draft_id']===$chosenDraft
        && !isset(profile_commerce_metadata_v900(profile_commerce_owner_product_v900($pdo,1,$textId))['source_asset_id'])
        && profile_commerce_public_product_v900($pdo,$profile,'glazing-guide')===$textListing
        && profile_product_promotions_public_v1($pdo,$profile)===[],
        'approved photo and copy continue the exact launch privately without changing the live listing');

    $legacyInput = array_replace($productInput,['request_id'=>str_repeat('f',64)]);
    $legacy = initiative_work_product_create_v1($pdo,1,$scope,$legacyInput,
        initiative_work_product_preview_v1($pdo,1,$scope,$legacyInput),
        static fn(): string => '{"title":"Previous mug draft","description":"A draft from the previous release."}');
    $legacyId = $legacy['product_id'];
    $pdo->prepare('DELETE FROM agent_product_launches_v1 WHERE product_id=?')->execute([$legacyId]);
    $sweep();
    launch_check_v1($notices()===$chosenNotices,'a pre-existing private draft is not announced retroactively');
    $legacyTerms = array_replace($listingInput,[
        'revision'=>initiative_work_product_edit_revision_v1(profile_commerce_owner_product_v900($pdo,1,$legacyId)),
        'profile_slug'=>'previous-mug',
    ]);
    $legacyReview = initiative_work_product_publication_preview_v1($pdo,1,$legacyId,$legacyTerms,$sessionKey);
    initiative_work_product_publication_confirm_v1($pdo,1,$legacyId,$legacyReview['input'],$legacyReview['review_token'],$sessionKey);
    $sweep();
    $legacyNotices = $notices();
    $legacyNotice = end($legacyNotices);
    launch_check_v1($legacyNotice['stage']==='marketing_review' && $legacyNotice['product_id']===$legacyId
        && $legacyNotice['source_id']===(string)$assetId && $legacyNotice['conversation_id']===11,
        'the next confirmed action enrolls a pre-existing draft in its original Chat with the same photo');

    $legacyDraftInput = array_replace($draftInput,['product_id'=>$legacyId]);
    initiative_work_marketing_create_v1($pdo,1,$scope,$legacyDraftInput,
        initiative_work_marketing_preview_v1($pdo,1,$scope,$legacyDraftInput),
        static fn(): string => '{"headline":"Previous mug","copy":"A mug drafted earlier.","campaign_idea":"Feature the mug on the seller profile."}');
    $legacyDesign = array_replace($design,['product_id'=>$legacyId,'nonce'=>str_repeat('c',32)]);
    $legacyDesignReview = $action('creative_prepare',$legacyDesign);
    $legacyApproval = $legacyDesign+['confirmed'=>true,'review_token'=>$legacyDesignReview['review_token']];
    $oldCreative = $action('creative_generate',$legacyApproval)['creative'];
    $oldRun = initiative_work_marketing_promotion_row_v1($pdo,1,$oldCreative['id'])['generation_id'];
    $pdo->prepare('DELETE FROM agent_product_launches_v1 WHERE product_id=?')->execute([$legacyId]);
    $reopened = $action('creative_generate',$legacyApproval)['creative'];
    $launchCreative = $pdo->prepare('SELECT creative_id FROM agent_product_launches_v1 WHERE product_id=?');
    $launchCreative->execute([$legacyId]);
    launch_check_v1($launchCreative->fetchColumn()===$oldCreative['id'] && $reopened['id']===$oldCreative['id']
        && $oldRun===initiative_work_marketing_promotion_row_v1($pdo,1,$oldCreative['id'])['generation_id'] && $calls===1,
        're-review enrolls a pre-upgrade queued design while retaining its original image job');
    $newDesign = array_replace($legacyDesign,['nonce'=>str_repeat('d',32),'level'=>1]);
    $newDesignReview = $action('creative_prepare',$newDesign);
    $newCreative = $action('creative_generate',$newDesign+[
        'confirmed'=>true,'review_token'=>$newDesignReview['review_token'],
    ])['creative'];
    $action('creative_generate',$legacyApproval);
    $launchCreative->execute([$legacyId]);
    launch_check_v1($launchCreative->fetchColumn()===$newCreative['id'] && $newCreative['id']!==$oldCreative['id'],
        'replaying the old confirmation cannot retarget a launch already following a newer design');
    echo "INITIATIVE_PRODUCT_LAUNCH_V1=PASS ($checks cases, {$fixture['driver']})\n";
} finally {
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
    commerce_fixture_cleanup_pa2($fixture);
}
