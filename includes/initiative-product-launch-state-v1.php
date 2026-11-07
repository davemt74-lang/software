<?php
declare(strict_types=1);

const VP3_PRODUCT_LAUNCH_STAGES_V1 = [
    'listing_review','marketing_review','photo_needed','design_review','image_starting','image_pending','creative_ready',
    'creative_failed','publication_review','published','paused','listing_unavailable',
];

/** An image is ready only with the exact job's committed result and private bytes. */
function initiative_product_launch_image_state_v1(PDO $pdo, array $user, array $scope, array $variant): array
{
    require_once __DIR__.'/initiative-marketing-creative-v1.php';
    $binding = initiative_marketing_creative_binding_v1($variant);
    if ($binding['level'] < 5) return ['stage'=>'creative_ready'];

    $owner = (int)$user['id'];
    $id = $variant['generation_id'] ?? '';
    if ($id === '') {
        $createdAt = strtotime((string)($variant['created_at'] ?? '').' UTC');
        return ['stage'=>$createdAt && $createdAt <= time()-300 ? 'creative_failed' : 'image_starting'];
    }
    $generation = vp3_image_row_v1($pdo,$user,$id);
    initiative_marketing_creative_generation_binding_v1($generation,$scope,$binding);
    $current = initiative_marketing_creative_current_v1($pdo,$owner,$scope,$binding,false,$id);
    if (($current['generation_id'] ?? null) !== $id || $generation['user_message_id'] !== null) {
        throw new DomainException('The marketing image has a different origin.',409);
    }
    $run = vp3_image_run_v1($pdo,$user,$generation);
    $state = ['generation_id'=>$id];
    if ($run['status'] !== 'completed') {
        return $state+['stage'=>in_array($run['status'],['failed','cancelled'],true) ? 'creative_failed' : 'image_pending'];
    }

    $assetId = (int)$generation['asset_id'];
    if (!initiative_product_launch_image_proof_v1($pdo,$user,$variant['id'],$id,(int)$run['id'],$assetId)) {
        return $state+['stage'=>'creative_failed'];
    }
    return $state+['stage'=>'creative_ready','generated_asset_id'=>$assetId];
}

/** The caller has already resolved this exact generation and its completed run. */
function initiative_product_launch_image_proof_v1(PDO $pdo, array $user, string $creativeId, string $id, int $runId, int $assetId): bool
{
    if ($assetId < 1) return false;
    $owner = (int)$user['id'];
    $proof = $pdo->prepare("SELECT a.id,a.attempt_count,a.result_json,r.receipt_key,r.result_json AS receipt_json
        FROM agent_workflow_actions a INNER JOIN agent_workflow_receipts r
            ON r.run_id=a.run_id AND r.action_id=a.id AND r.owner_user_id=a.owner_user_id AND r.attempt_no=a.attempt_count
        WHERE a.owner_user_id=? AND a.run_id=? AND a.action_key=? AND a.action_type='execute'
          AND a.execution_target='cloud' AND a.capability_key='images.generate'
          AND a.status='completed' AND r.status='completed' AND r.executor='cloud' LIMIT 2");
    $proof->execute([$owner,$runId,$id]);
    $receipts = $proof->fetchAll(PDO::FETCH_ASSOC);
    $receipt = $receipts[0] ?? [];
    $result = json_decode((string)($receipt['result_json'] ?? ''),true);
    $action = (int)($receipt['id'] ?? 0);
    $attempt = (int)($receipt['attempt_count'] ?? 0);
    if (count($receipts) !== 1 || $attempt < 1
        || ($receipt['receipt_key'] ?? null) !== agent_job_receipt_key_v1900($owner,$runId,$action,
            'v1910-cloud-'.$runId.'-'.$action.'-'.$attempt)
        || $result !== json_decode((string)$receipt['receipt_json'],true)
        || ($result['asset_id'] ?? null) !== (string)$assetId || ($result['generation_id'] ?? null) !== $id
        || ($result['effect_state'] ?? null) !== 'image_saved') {
        return false;
    }
    $asset = media_studio_asset($assetId,$user);
    $metadata = json_decode((string)($asset['metadata_json'] ?? ''),true);
    $digest = $metadata['sha256'] ?? null;
    $path = $asset ? initiative_work_product_file_v1($owner,(string)$asset['file_path']) : null;
    $size = $path ? @filesize($path) : false;
    return ($asset['source'] ?? null) === 'openai_image' && ($asset['media_type'] ?? null) === 'photo'
        && ($asset['mime_type'] ?? null) === 'image/png' && ($metadata['generation_id'] ?? null) === $id
        && ($metadata['marketing_variant_id'] ?? null) === $creativeId && is_string($digest)
        && preg_match('/\A[a-f0-9]{64}\z/D',$digest)
        && $size !== false && $size > 0 && $size <= 25*1024*1024 && $size === (int)$asset['file_size']
        && hash_equals($digest,(string)@hash_file('sha256',$path));
}

/** Read an exact server-owned launch checkpoint. Draft-only bindings come from reviewed mutations. */
function initiative_product_launch_state_v1(PDO $pdo, int $owner, array $scope, int $productId, ?string $draftId=null, ?string $creativeId=null): array
{
    require_once __DIR__.'/initiative-work-marketing-promotion-v1.php';
    $user = initiative_work_product_scope_v1($pdo,$owner,$scope);
    $product = profile_commerce_owner_product_v900($pdo,$owner,$productId);
    if (!$product) throw new DomainException('The launch product is unavailable.',404);
    $metadata = profile_commerce_metadata_v900($product);
    $state = ['product_id'=>$productId,'product_title'=>(string)$product['title']];
    $private = initiative_work_product_draft_v1($product);
    $variant = null;
    if ($creativeId !== null) {
        $variant = initiative_work_marketing_promotion_row_v1($pdo,$owner,$creativeId);
        initiative_work_marketing_promotion_scope_v1($variant,$scope);
        if ($variant['product_id'] !== $productId || ($draftId !== null && $variant['draft_key'] !== $draftId)) {
            throw new DomainException('The creative belongs to a different product launch.',409);
        }
        $draftId = $variant['draft_key'];
        $state['creative_id'] = $creativeId;
    }
    if ($draftId !== null) {
        $draft = initiative_work_marketing_drafts_v1($pdo,$owner,$productId)[$draftId] ?? null;
        if (!$draft || ($draft['product_id'] ?? null) !== $productId || !is_int($draft['asset_id'] ?? null)
            || ($variant !== null && ($variant['source_asset_id'] !== $draft['asset_id']
                || $variant['source_sha256'] !== ($draft['asset_sha256'] ?? null)))) {
            throw new DomainException('The launch draft or original photo changed.',409);
        }
        $sourceId = (string)$draft['asset_id'];
        initiative_work_marketing_promotion_source_v1($pdo,$owner,$scope,
            ['product_id'=>$productId,'draft_id'=>$draftId,'source_id'=>$sourceId]);
        $state += ['source_id'=>$sourceId,'draft_id'=>$draftId];
    } else {
        if (($metadata['agent_id'] ?? null) !== $scope['agent_id'] || ($metadata['conversation_id'] ?? null) !== $scope['conversation_id']) {
            throw new DomainException('The launch product belongs to a different Chat.',404);
        }
        if ($private) return $state+['stage'=>'listing_review'];
        $linkedSource = $metadata['source_asset_id'] ?? null;
        if ($linkedSource !== null && $linkedSource !== '') {
            if (!is_int($linkedSource) && !is_string($linkedSource)) throw new DomainException('The linked launch photo is invalid.',409);
            $sourceId = (string)$linkedSource;
            $source = initiative_work_product_source_v1($pdo,$owner,$scope,['source_id'=>$sourceId]);
            if (($metadata['source_content_sha256'] ?? null) !== $source['digest']) {
                throw new DomainException('The launch product photo changed.',409);
            }
            $state['source_id'] = $sourceId;
        }
    }

    if ($variant !== null && $variant['status'] === 'designing') {
        return $state+initiative_product_launch_image_state_v1($pdo,$user,$scope,$variant);
    }
    if ($private) return $state+['stage'=>'listing_review'];
    $target = initiative_work_marketing_promotion_target_v1($pdo,$owner,$productId);
    if (!$target) return $state+['stage'=>'listing_unavailable'];
    if ($variant === null) {
        $stage = $draftId !== null ? 'design_review' : (isset($state['source_id']) ? 'marketing_review' : 'photo_needed');
        return $state+['stage'=>$stage];
    }

    if ($variant['status'] === 'published') {
        require_once __DIR__.'/profile-product-promotion-v1.php';
        $card = profile_product_promotion_public_v1($pdo,$target['profile'],(string)$variant['public_key']);
        if (!$card || $card['product_url'] !== $target['product_url']) return $state+['stage'=>'listing_unavailable'];
        return $state+['stage'=>'published'];
    }
    if ($variant['status'] === 'paused') return $state+['stage'=>'paused'];
    if ($variant['status'] !== 'saved') throw new DomainException('The launch creative status is unavailable.',409);
    initiative_work_marketing_promotion_publication_v1($pdo,$owner,$variant);
    return $state+['stage'=>'publication_review'];
}
