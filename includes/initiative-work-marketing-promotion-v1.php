<?php
declare(strict_types=1);

require_once __DIR__.'/initiative-work-marketing-v1.php';
require_once __DIR__.'/agent-image-provider-v1.php';

function initiative_work_marketing_promotion_ready_v1(): void
{
    if (!table_exists('agent_commerce_promotions_v1')) {
        throw new DomainException('Run Database Upgrade to design and publish promotions.', 503);
    }
}

function initiative_work_marketing_promotion_row_v1(PDO $pdo, int $owner, string $id, bool $lock=false): array
{
    initiative_work_marketing_promotion_ready_v1();
    if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) throw new DomainException('Choose an exact promotion design.', 422);
    $stmt = $pdo->prepare('SELECT * FROM agent_commerce_promotions_v1 WHERE owner_user_id=? AND id=?'
        .($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $stmt->execute([$owner, $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('The promotion design is unavailable.', 404);
    return initiative_work_marketing_promotion_decode_v1($row);
}

function initiative_work_marketing_promotion_decode_v1(array $row): array
{
    foreach (['owner_user_id','product_id','agent_id','conversation_id','source_asset_id','publication_revision'] as $field) {
        $row[$field] = (int)$row[$field];
    }
    $row['settings'] = json_decode($row['settings_json'], true, 8, JSON_THROW_ON_ERROR);
    return $row;
}

function initiative_work_marketing_promotion_scope_v1(array $row, array $scope): void
{
    if ((int)$row['agent_id'] !== $scope['agent_id'] || (int)$row['conversation_id'] !== $scope['conversation_id']) {
        throw new DomainException('Open the Chat where this promotion was designed.', 404);
    }
}

/** The exact photo from the stored draft is the original for every variant. */
function initiative_work_marketing_promotion_source_v1(PDO $pdo, int $owner, array $scope, array $body, bool $lock=false): array
{
    $productId = $body['product_id'] ?? null;
    $draftId = $body['draft_id'] ?? null;
    if (!is_int($productId) || $productId < 1 || !is_string($draftId) || !preg_match('/\A[a-f0-9]{64}\z/', $draftId)) {
        throw new DomainException('Choose an exact saved marketing draft.', 422);
    }
    $current = initiative_work_marketing_context_v1($pdo, $owner, $scope, $body, $lock);
    $stmt = $pdo->prepare('SELECT draft_json FROM agent_commerce_marketing_drafts_v1
        WHERE owner_user_id=? AND product_id=? AND request_key=?'.($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $stmt->execute([$owner, $productId, $draftId]);
    $json = $stmt->fetchColumn();
    if (!is_string($json)) throw new DomainException('The marketing draft is unavailable.', 404);
    $draft = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    if ((string)($draft['asset_id'] ?? '') !== $current['asset']['id']
        || ($draft['asset_sha256'] ?? '') !== $current['asset']['digest']) {
        throw new DomainException('The original product image changed. Save a new marketing draft.', 409);
    }
    $draft = array_replace($draft, ['draft_id'=>$draftId, 'image_url'=>media_studio_asset_url((int)$current['asset']['id'])]);
    return [$draft, $current];
}

function initiative_work_marketing_promotion_settings_v1(array $body): array
{
    $level = $body['level'] ?? null;
    $style = $body['style'] ?? 'clean';
    $remove = $body['remove_background'] ?? false;
    $tolerance = $body['tolerance'] ?? 24;
    if (!is_int($level) || $level < 1 || $level > 10 || !in_array($style, ['clean','bold','warm'], true)
        || !is_bool($remove) || !is_int($tolerance) || $tolerance < 8 || $tolerance > 64) {
        throw new DomainException('Choose an image treatment from 1 to 10 and valid design settings.', 422);
    }
    return ['level'=>$level, 'headline'=>initiative_work_product_text_v1($body['headline'] ?? null,120,true),
        'copy'=>initiative_work_product_text_v1($body['copy'] ?? null,600,true),
        'prompt'=>initiative_work_product_text_v1($body['prompt'] ?? '',600), 'style'=>$style,
        'remove_background'=>$level >= 5 && $level <= 7 && $remove, 'tolerance'=>$tolerance];
}

function initiative_work_marketing_promotion_plan_v1(PDO $pdo, int $owner, array $scope, array $body, bool $lock=false): array
{
    [$draft, $current] = initiative_work_marketing_promotion_source_v1($pdo, $owner, $scope, $body, $lock);
    $settings = initiative_work_marketing_promotion_settings_v1($body);
    $nonce = $body['nonce'] ?? '';
    if (!is_string($nonce) || !preg_match('/\A[a-f0-9]{32}\z/', $nonce)) {
        throw new DomainException('Review a new design request.', 422);
    }
    $request = hash('sha256', json_encode(['promotion-design',$owner,$scope['agent_id'],$scope['conversation_id'],
        $draft['draft_id'],$current['revision'],$settings,$nonce], JSON_THROW_ON_ERROR));
    $row = ['id'=>substr($request,0,32),'owner_user_id'=>$owner,'product_id'=>(int)$draft['product_id'],
        'draft_key'=>$draft['draft_id'],'agent_id'=>$scope['agent_id'],'conversation_id'=>$scope['conversation_id'],
        'source_asset_id'=>(int)$draft['asset_id'],'source_sha256'=>$current['asset']['digest'],
        'settings'=>$settings,'status'=>'designing','publication_revision'=>0];
    return [$request, $row];
}

/** Existing public listing resolution is the eligibility and link authority. */
function initiative_work_marketing_promotion_target_v1(PDO $pdo, int $owner, int $productId, bool $lock=false): ?array
{
    if ($lock && ($clause = agent_commerce_lock_pa2($pdo)) !== '') {
        $profileLock = $pdo->prepare('SELECT user_id FROM user_profiles WHERE user_id=?'.$clause);
        $profileLock->execute([$owner]);
        if (!$profileLock->fetchColumn()) return null;
    }
    $profile = profile_for_user($pdo, $owner);
    if (!$profile || empty($profile['is_active']) || empty($profile['is_public'])) return null;
    $product = profile_commerce_owner_product_v900($pdo, $owner, $productId, $lock);
    if (!$product || empty($product['is_active']) || profile_commerce_visibility_v900($product) !== 'public'
        || profile_commerce_private_listing_draft_v900($product)) return null;
    $resolved = profile_commerce_public_product_v900($pdo, $profile, profile_commerce_public_slug_v900($product));
    if (!$resolved || $resolved['id'] !== $productId) return null;
    return ['profile'=>$profile, 'product_url'=>$resolved['product_url'], 'cta'=>$resolved['cta']];
}

function initiative_work_marketing_promotion_management_v1(int $product): string
{
    return url('/profile-commerce-products.php?workspace_product='.$product.'#product-marketing-drafts');
}

function initiative_work_marketing_promotion_bind_v1(PDO $pdo, int $owner, array $row, string $generationId): array
{
    $bind = $pdo->prepare('UPDATE agent_commerce_promotions_v1 SET generation_id=? WHERE id=? AND owner_user_id=? AND generation_id IS NULL');
    $bind->execute([$generationId,$row['id'],$owner]);
    return initiative_work_marketing_promotion_row_v1($pdo,$owner,$row['id']);
}

function initiative_work_marketing_promotion_recover_v1(PDO $pdo, int $owner, array $scope, array $row): array
{
    if ($row['status'] !== 'designing' || $row['settings']['level'] < 5 || !empty($row['generation_id'])) return $row;
    require_once __DIR__.'/initiative-marketing-creative-v1.php';
    $generationId = initiative_marketing_creative_recover_v1($pdo,$owner,$scope,$row);
    return $generationId ? initiative_work_marketing_promotion_bind_v1($pdo,$owner,$row,$generationId) : $row;
}

function initiative_work_marketing_promotion_view_v1(PDO $pdo, int $owner, array $scope, array $row, bool $tick=false): array
{
    $settings = $row['settings'];
    $status = $row['status'];
    $generatedUrl = '';
    $message = '';
    if ($status === 'designing') {
        if ($settings['level'] < 5) {
            $status = 'ready';
        } elseif (empty($row['generation_id'])) {
            $status = 'starting';
            $message = 'This design has not queued an image. Review the design to try again.';
        } else {
            require_once __DIR__.'/initiative-marketing-creative-v1.php';
            $result = initiative_marketing_creative_generation_v1($pdo, $owner, $scope, $row, $tick);
            $status = match ($result['status']) {
                'completed' => 'ready',
                'paused' => 'paused_job',
                'file_unavailable' => 'failed',
                default => $result['status'],
            };
            $generatedUrl = (string)($result['asset']['url'] ?? '');
            $message = (string)($result['message'] ?? '');
        }
    }
    $target = initiative_work_marketing_promotion_target_v1($pdo,$owner,(int)$row['product_id']);
    $asset = !empty($row['asset_id']) ? media_studio_asset((int)$row['asset_id'],['id'=>$owner]) : null;
    $imageUrl = $asset && media_studio_asset_payload($asset)['available'] ? media_studio_asset_url((int)$row['asset_id']) : '';
    if (!$imageUrl && !empty($row['asset_id'])) $message = 'The saved artwork is unavailable. Choose another design.';
    $published = $status === 'published' && $imageUrl && $target && hash_equals((string)$row['public_product_url'],$target['product_url']);
    return ['id'=>$row['id'],'draft_id'=>$row['draft_key'],'product_id'=>(int)$row['product_id'],'status'=>$status,
        'source_image_url'=>media_studio_asset_url((int)$row['source_asset_id']),
        'generated_url'=>$generatedUrl,'image_url'=>$imageUrl,
        'product_url'=>$target['product_url'] ?? '', 'cta'=>$published ? $row['public_cta'] : ($target['cta'] ?? 'View product'),
        'public_url'=>$published ? profile_public_url($target['profile']['username']).'#product-promotion-'.$row['public_key'] : '',
        'management_url'=>initiative_work_marketing_promotion_management_v1((int)$row['product_id']),
        'message'=>$message] + $settings;
}

function initiative_work_marketing_promotion_open_v1(PDO $pdo, int $owner, array $scope, array $body): array
{
    [$draft] = initiative_work_marketing_promotion_source_v1($pdo,$owner,$scope,$body);
    $productId = (int)$draft['product_id'];
    $target = initiative_work_marketing_promotion_target_v1($pdo,$owner,$productId);
    $draft += ['product_url'=>$target['product_url'] ?? '', 'cta'=>$target['cta'] ?? 'View product',
        'management_url'=>initiative_work_marketing_promotion_management_v1($productId)];
    $find = $pdo->prepare('SELECT * FROM agent_commerce_promotions_v1 WHERE owner_user_id=? AND product_id=?
        AND agent_id=? AND conversation_id=? AND draft_key=? AND source_sha256=?
        ORDER BY created_at DESC,id DESC LIMIT 12');
    $find->execute([$owner,$productId,$scope['agent_id'],$scope['conversation_id'],$draft['draft_id'],$draft['asset_sha256']]);
    $variants = [];
    foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row = initiative_work_marketing_promotion_decode_v1($row);
        $row = initiative_work_marketing_promotion_recover_v1($pdo,$owner,$scope,$row);
        $variants[] = initiative_work_marketing_promotion_view_v1($pdo,$owner,$scope,$row);
    }
    $active = $pdo->prepare("SELECT * FROM agent_commerce_promotions_v1 WHERE owner_user_id=? AND product_id=?
        AND agent_id=? AND conversation_id=? AND status='published' ORDER BY updated_at DESC LIMIT 1");
    $active->execute([$owner,$productId,$scope['agent_id'],$scope['conversation_id']]);
    $row = $active->fetch(PDO::FETCH_ASSOC);
    return ['draft'=>$draft,'creative'=>$variants[0] ?? null,'variants'=>$variants,'promotion'=>$row
        ? initiative_work_marketing_promotion_view_v1($pdo,$owner,$scope,initiative_work_marketing_promotion_decode_v1($row)) : null];
}

function initiative_work_marketing_promotion_start_v1(PDO $pdo, int $owner, array $scope, array $body, string $key): array
{
    $pdo->beginTransaction();
    try {
        [$request, $row] = initiative_work_marketing_promotion_plan_v1($pdo,$owner,$scope,$body,true);
        initiative_workbench_confirm_v1($body,$key,$request,time());
        $find = $pdo->prepare('SELECT * FROM agent_commerce_promotions_v1 WHERE id=? AND owner_user_id=?');
        $find->execute([$row['id'],$owner]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $row = initiative_work_marketing_promotion_decode_v1($existing);
        } else {
            $insert = $pdo->prepare('INSERT INTO agent_commerce_promotions_v1
                (id,product_id,owner_user_id,draft_key,agent_id,conversation_id,source_asset_id,source_sha256,settings_json)
                VALUES (?,?,?,?,?,?,?,?,?)');
            $insert->execute([$row['id'],$row['product_id'],$owner,$row['draft_key'],$scope['agent_id'],
                $scope['conversation_id'],$row['source_asset_id'],$row['source_sha256'],json_encode($row['settings'],JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $row = initiative_work_marketing_promotion_recover_v1($pdo,$owner,$scope,$row);
    if ($row['status'] === 'designing' && $row['settings']['level'] >= 5 && empty($row['generation_id'])) {
        require_once __DIR__.'/initiative-marketing-creative-v1.php';
        $generation = initiative_marketing_creative_queue_v1($pdo,$owner,$scope,$row);
        $row = initiative_work_marketing_promotion_bind_v1($pdo,$owner,$row,$generation['id']);
    }
    return $row;
}

function initiative_work_marketing_promotion_save_v1(PDO $pdo, int $owner, array $scope, array $row, mixed $assetId): array
{
    if (!is_int($assetId) || $assetId < 1 || $assetId === (int)$row['source_asset_id']) {
        throw new DomainException('Save the exact designed PNG as a separate media asset.', 422);
    }
    $pdo->beginTransaction();
    try {
        $user = initiative_work_product_scope_v1($pdo,$owner,$scope,agent_commerce_lock_pa2($pdo));
        $row = initiative_work_marketing_promotion_row_v1($pdo,$owner,$row['id'],true);
        initiative_work_marketing_promotion_scope_v1($row,$scope);
        if ($row['status'] === 'designing') {
            $source = initiative_work_product_source_v1($pdo,$owner,$scope,['source_id'=>(string)$row['source_asset_id']],true);
            if (!hash_equals($row['source_sha256'],$source['digest'])) throw new DomainException('The original image changed. Review a new design.',409);
            $view = initiative_work_marketing_promotion_view_v1($pdo,$owner,$scope,$row);
            if ($view['status'] !== 'ready') throw new DomainException('Finish the selected design before saving it.',409);
            $asset = media_studio_asset($assetId,$user);
            $metadata = json_decode((string)($asset['metadata_json'] ?? ''), true);
            $digest = $asset && ($asset['source'] ?? '') === 'promotion_design'
                && ($metadata['creative_id'] ?? '') === $row['id'] ? initiative_work_marketing_promotion_digest_v1($asset) : null;
            if (!$digest) throw new DomainException('The saved graphic is unavailable for this design.',422);
            $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='saved',asset_id=?,asset_sha256=?,updated_at=CURRENT_TIMESTAMP
                WHERE id=? AND owner_user_id=?")->execute([$assetId,$digest,$row['id'],$owner]);
        } elseif ((int)$row['asset_id'] !== $assetId) {
            throw new DomainException('This version already has a saved graphic. Create another variant to change it.',409);
        }
        $pdo->commit();
        return initiative_work_marketing_promotion_row_v1($pdo,$owner,$row['id']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function initiative_work_marketing_promotion_digest_v1(array $asset): ?string
{
    if ($asset['media_type'] !== 'photo' || $asset['mime_type'] !== 'image/png') return null;
    $path = initiative_work_product_file_v1((int)$asset['user_id'], $asset['file_path']);
    $bytes = $path ? @file_get_contents($path,false,null,0,16*1024*1024+1) : false;
    if ($bytes === false || strlen($bytes) > 16*1024*1024) return null;
    $dimensions = @getimagesizefromstring($bytes);
    if (($dimensions['mime'] ?? '') !== 'image/png' || min($dimensions[0],$dimensions[1]) < 1
        || max($dimensions[0],$dimensions[1]) > 4096) return null;
    try {
        vp3_image_png_check_v1($bytes);
    } catch (RuntimeException) {
        return null;
    }
    return hash('sha256',$bytes);
}

function initiative_work_marketing_promotion_publication_v1(PDO $pdo, int $owner, array $row, bool $lock=false): array
{
    if (!in_array($row['status'],['saved','published','paused'],true)) {
        throw new DomainException('Save the reviewed graphic before publishing.',409);
    }
    $target = initiative_work_marketing_promotion_target_v1($pdo,$owner,(int)$row['product_id'],$lock);
    if (!$target) throw new DomainException('Make the profile and product listing public in Profile Commerce before promoting it.',409);
    $asset = media_studio_asset((int)$row['asset_id'],['id'=>$owner]);
    $digest = $asset ? initiative_work_marketing_promotion_digest_v1($asset) : null;
    if (!$digest || !hash_equals((string)$row['asset_sha256'],$digest)) {
        throw new DomainException('The reviewed graphic changed or is unavailable.',409);
    }
    $request = hash('sha256',json_encode(['promotion-publish',$owner,$row['id'],$row['agent_id'],$row['conversation_id'],
        $row['settings'],$row['publication_revision'],$row['asset_id'],$digest,$target['product_url'],$target['cta']],JSON_THROW_ON_ERROR));
    return [$request,$target];
}

function initiative_work_marketing_promotion_publish_v1(PDO $pdo, int $owner, array $scope, array $row, array $body, string $key): array
{
    require_once __DIR__.'/profile-product-promotion-v1.php';
    $pdo->beginTransaction();
    try {
        initiative_work_product_scope_v1($pdo,$owner,$scope,agent_commerce_lock_pa2($pdo));
        $row = initiative_work_marketing_promotion_row_v1($pdo,$owner,$row['id'],true);
        initiative_work_marketing_promotion_scope_v1($row,$scope);
        [$request,$target] = initiative_work_marketing_promotion_publication_v1($pdo,$owner,$row,true);
        initiative_workbench_confirm_v1($body,$key,$request,time());
        $publicKey = $row['public_key'];
        if ($row['status'] !== 'published' || $row['public_product_url'] !== $target['product_url']
            || $row['public_cta'] !== $target['cta']) {
            $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='paused',publication_revision=publication_revision+1,
                updated_at=CURRENT_TIMESTAMP WHERE owner_user_id=? AND product_id=? AND status='published' AND id<>?")
                ->execute([$owner,$row['product_id'],$row['id']]);
            $publicKey = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='published',public_key=?,public_product_url=?,
                public_cta=?,updated_at=CURRENT_TIMESTAMP WHERE owner_user_id=? AND id=?")
                ->execute([$publicKey,$target['product_url'],$target['cta'],$owner,$row['id']]);
        }
        $public = profile_product_promotion_public_v1($pdo,$target['profile'],$publicKey);
        if (!$public || $public['product_url'] !== $target['product_url'] || profile_product_promotion_image_v1($pdo,$publicKey) === null) {
            throw new DomainException('The visitor view could not verify this promotion. The publication was rolled back.',409);
        }
        $pdo->commit();
        return initiative_work_marketing_promotion_row_v1($pdo,$owner,$row['id']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function initiative_work_marketing_promotion_pause_v1(PDO $pdo, int $owner, string $id, ?array $scope=null): array
{
    $pdo->beginTransaction();
    try {
        if ($scope) initiative_work_product_scope_v1($pdo,$owner,$scope,agent_commerce_lock_pa2($pdo));
        else initiative_work_product_user_v1($pdo,$owner,agent_commerce_lock_pa2($pdo),false);
        $row = initiative_work_marketing_promotion_row_v1($pdo,$owner,$id,true);
        if ($scope) initiative_work_marketing_promotion_scope_v1($row,$scope);
        if (!in_array($row['status'],['published','paused'],true)) throw new DomainException('Only an active promotion can be paused.',409);
        $pdo->prepare("UPDATE agent_commerce_promotions_v1 SET status='paused',publication_revision=publication_revision+1,
            updated_at=CURRENT_TIMESTAMP WHERE owner_user_id=? AND id=? AND status='published'")->execute([$owner,$id]);
        $pdo->commit();
        return initiative_work_marketing_promotion_row_v1($pdo,$owner,$id);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function initiative_work_marketing_promotion_action_v1(PDO $pdo, int $owner, array $scope, array $body, string $key): array
{
    initiative_work_marketing_promotion_ready_v1();
    $action = $body['action'];
    if ($action === 'creative_open') return initiative_work_marketing_promotion_open_v1($pdo,$owner,$scope,$body);
    if ($action === 'creative_prepare') {
        [$request,$row] = initiative_work_marketing_promotion_plan_v1($pdo,$owner,$scope,$body);
        $row['status'] = 'review';
        return ['creative'=>initiative_work_marketing_promotion_view_v1($pdo,$owner,$scope,$row),
            'review_token'=>initiative_workbench_review_token_v1($key,$request,time()+600)];
    }
    if ($action === 'creative_generate') {
        $row = initiative_work_marketing_promotion_start_v1($pdo,$owner,$scope,$body,$key);
        return ['creative'=>initiative_work_marketing_promotion_view_v1($pdo,$owner,$scope,$row)];
    }
    $id = $body['creative_id'] ?? null;
    if (!is_string($id)) throw new DomainException('Choose an exact promotion design.',422);
    $row = initiative_work_marketing_promotion_row_v1($pdo,$owner,$id);
    initiative_work_marketing_promotion_scope_v1($row,$scope);
    if (in_array($action,['creative_status','creative_tick','creative_cancel'],true)) {
        $row = initiative_work_marketing_promotion_recover_v1($pdo,$owner,$scope,$row);
    }
    if ($action === 'creative_publish_preview') {
        [$request,$target] = initiative_work_marketing_promotion_publication_v1($pdo,$owner,$row);
        $view = initiative_work_marketing_promotion_view_v1($pdo,$owner,$scope,$row);
        return ['creative'=>array_replace($view, ['product_url'=>$target['product_url'],'cta'=>$target['cta']]),
            'review_token'=>initiative_workbench_review_token_v1($key,$request,time()+600)];
    }
    if ($action === 'creative_save') {
        $row = initiative_work_marketing_promotion_save_v1($pdo,$owner,$scope,$row,$body['asset_id'] ?? null);
    } elseif ($action === 'creative_publish') {
        $row = initiative_work_marketing_promotion_publish_v1($pdo,$owner,$scope,$row,$body,$key);
    } elseif ($action === 'creative_pause') {
        if (($body['confirmed'] ?? null) !== true) throw new DomainException('Confirm pausing this promotion.',422);
        $row = initiative_work_marketing_promotion_pause_v1($pdo,$owner,$id,$scope);
    } elseif ($action === 'creative_cancel') {
        if (($body['confirmed'] ?? null) !== true || empty($row['generation_id'])) {
            throw new DomainException('Confirm cancellation of the exact image request.',422);
        }
        require_once __DIR__.'/initiative-marketing-creative-v1.php';
        $generation = initiative_marketing_creative_generation_v1($pdo,$owner,$scope,$row);
        if (in_array($generation['status'],['queued','generating','paused'],true)) {
            agent_job_cancel_v1900($pdo,$scope['user'],(int)$generation['job_id']);
        }
    }
    return ['creative'=>initiative_work_marketing_promotion_view_v1($pdo,$owner,$scope,$row,$action === 'creative_tick')];
}
