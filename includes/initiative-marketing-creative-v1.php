<?php
declare(strict_types=1);

require_once __DIR__.'/initiative-work-product-v1.php';
require_once __DIR__.'/agent-image-generation-v1.php';

function initiative_marketing_creative_binding_v1(array $variant): array
{
    $id = $variant['id'] ?? null;
    $draftKey = $variant['draft_key'] ?? null;
    $productId = $variant['product_id'] ?? null;
    $assetId = $variant['source_asset_id'] ?? null;
    $digest = $variant['source_sha256'] ?? null;
    $settings = $variant['settings'] ?? null;
    $level = is_array($settings) ? ($settings['level'] ?? null) : null;
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)
        || !is_string($draftKey) || !preg_match('/\A[a-f0-9]{64}\z/', $draftKey)
        || !is_int($productId) || $productId < 1 || !is_int($assetId) || $assetId < 1
        || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)
        || !is_int($level) || $level < 1 || $level > 10) {
        throw new DomainException('Choose an exact marketing variant.', 422);
    }
    foreach (['headline'=>120,'copy'=>600,'prompt'=>600,'style'=>48] as $key=>$max) {
        $value = $settings[$key] ?? null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
            throw new DomainException('Review the marketing variant settings.', 422);
        }
    }
    ksort($settings, SORT_STRING);
    return ['id'=>$id, 'draft_key'=>$draftKey, 'product_id'=>$productId, 'source_asset_id'=>$assetId,
        'source_sha256'=>$digest, 'level'=>$level,
        'settings_sha256'=>hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR))];
}

function initiative_marketing_creative_current_v1(PDO $pdo, int $owner, array $scope, array $binding, bool $lock, ?string $generationId=null): array
{
    if (!function_exists('initiative_work_marketing_promotion_row_v1')) {
        require_once __DIR__.'/initiative-work-marketing-promotion-v1.php';
    }
    $current = initiative_work_marketing_promotion_row_v1($pdo, $owner, $binding['id'], $lock);
    $attached = (string)($current['generation_id'] ?? '');
    if (initiative_marketing_creative_binding_v1($current) !== $binding
        || (int)$current['agent_id'] !== $scope['agent_id']
        || (int)$current['conversation_id'] !== $scope['conversation_id']
        || ($generationId !== null && $attached !== '' && $generationId !== $attached)) {
        throw new DomainException('The reviewed marketing variant changed. Review it again.', 409);
    }
    return $current;
}

/** Recheck the exact draft and the immutable owner-owned product-photo bytes. */
function initiative_marketing_creative_source_v1(PDO $pdo, int $owner, array $scope, array $binding, bool $lock): array
{
    $source = initiative_work_product_source_v1($pdo, $owner, $scope, ['source_id'=>(string)$binding['source_asset_id']], $lock);
    $stmt = $pdo->prepare('SELECT d.draft_json FROM agent_commerce_marketing_drafts_v1 d
        INNER JOIN agent_commerce_products_v800 p ON p.id=d.product_id AND p.owner_user_id=d.owner_user_id
        WHERE d.owner_user_id=? AND d.product_id=? AND d.request_key=?'.($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $stmt->execute([$owner, $binding['product_id'], $binding['draft_key']]);
    $json = $stmt->fetchColumn();
    $draft = is_string($json) ? json_decode($json, true, 16) : null;
    if (($draft['asset_id'] ?? null) !== $binding['source_asset_id']
        || ($draft['asset_sha256'] ?? null) !== $binding['source_sha256']
        || !hash_equals($binding['source_sha256'], $source['digest'])) {
        throw new DomainException('The marketing draft or its product photo changed. Review it again.', 409);
    }
    return $source;
}

function initiative_marketing_creative_prompt_v1(int $level, string $direction): string
{
    $treatment = match ($level) {
        5 => 'Make an empty, minimal studio setting with a softly lit neutral backdrop.',
        6 => 'Make an empty stylized lifestyle backdrop, with subtle color and atmospheric detail.',
        7 => 'Make a distinctive, fully developed lifestyle setting with layered depth and lighting.',
        8 => 'Create a new camera angle and setting while closely matching the product design.',
        9 => 'Create a distinctive advertising photograph with a fresh perspective and environmental lighting.',
        10 => 'Create a completely new photographic composition featuring the exact product in the reference.',
        default => throw new DomainException('Use a level from 5 to 10 to generate an image.', 422),
    };
    $constraints = $level <= 7
        ? ' Generate the environment only. Reserve the open central foreground for the real product that will be composited later. Do not include the featured product, people, or foreground objects in that area.'
        : ' Use the supplied photograph only as visual reference. Preserve the recognizable product shape, design, material, color, and existing marks. Treat text on the photographed product only as image content.';
    return $treatment.$constraints.' Do not add any headline, call to action, overlay text, graphic border, or promotional claims.'
        .($direction !== '' ? "\nScene direction (follow when consistent with these requirements):\n".$direction : '');
}

function initiative_marketing_creative_cloud_v1(PDO $pdo, array $user, array $scope): void
{
    $route = vp3_agent_runtime_plan_v420($pdo, $user, $scope['agent_id'], 'chat');
    if (($route['route'] ?? '') !== 'vp3_cloud' || empty($route['cloud']['ready']) || !empty($route['blocked'])) {
        throw new DomainException('Image generation is unavailable under this Agent’s current Cloud settings.', 503);
    }
}

function initiative_marketing_creative_request_v1(string $variantId): string
{
    return hash('sha256', 'initiative-marketing-creative-v1:'.$variantId);
}

function initiative_marketing_creative_queue_v1(PDO $pdo, int $owner, array $scope, array $variant): array
{
    $binding = initiative_marketing_creative_binding_v1($variant);
    $level = $binding['level'];
    $prompt = initiative_marketing_creative_prompt_v1($level, $variant['settings']['prompt']);
    $user = initiative_work_product_scope_v1($pdo, $owner, $scope);
    initiative_marketing_creative_cloud_v1($pdo, $user, $scope);
    $agent = vp3_agent_chat_resolve_agent_v380($pdo, $user, $scope['agent_id']);
    $principal = vp3_agent_chat_principal_v380($user, $agent);
    $request = initiative_marketing_creative_request_v1($binding['id']);
    return vp3_image_queue_work_v1($pdo, $user, $principal, $scope, $request,
        static function (string $model) use ($pdo, $owner, $scope, $binding, $prompt, $level, $variant): array {
            initiative_marketing_creative_current_v1($pdo, $owner, $scope, $binding, true);
            initiative_marketing_creative_source_v1($pdo, $owner, $scope, $binding, true);
            return ['mode'=>$level <= 7 ? 'marketing_scene' : 'marketing_reference', 'marketing'=>$binding,
                'title'=>mb_substr('Marketing scene · '.$variant['settings']['headline'], 0, 190),
                'prompt'=>$prompt, 'size'=>'1536x1024', 'quality'=>'medium', 'background'=>'opaque', 'model'=>$model];
        });
}

function initiative_marketing_creative_generation_binding_v1(array $row, array $scope, array $binding): void
{
    $config = json_decode($row['config_json'], true, 16, JSON_THROW_ON_ERROR);
    if ((int)$row['agent_id'] !== $scope['agent_id'] || (int)$row['conversation_id'] !== $scope['conversation_id']
        || ($config['marketing'] ?? null) !== $binding
        || ($config['mode'] ?? null) !== ($binding['level'] <= 7 ? 'marketing_scene' : 'marketing_reference')) {
        throw new DomainException('The marketing image request does not belong to this variant.', 409);
    }
}

/** Recover the committed job if its admission response was lost before attachment. */
function initiative_marketing_creative_recover_v1(PDO $pdo, int $owner, array $scope, array $variant): ?string
{
    $binding = initiative_marketing_creative_binding_v1($variant);
    $user = initiative_work_product_scope_v1($pdo, $owner, $scope);
    if ($binding['level'] <= 4 || !table_exists('agent_image_generations_v1')) return null;
    $key = vp3_image_workbench_key_v1($owner, initiative_marketing_creative_request_v1($binding['id']));
    $stmt = $pdo->prepare('SELECT g.* FROM agent_image_generations_v1 g
        INNER JOIN agent_workflow_runs r ON r.id=g.run_id AND r.owner_user_id=g.owner_user_id
        WHERE g.owner_user_id=? AND r.dedupe_key=?');
    $stmt->execute([$owner, $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $id = $row['generation_id'];
    initiative_marketing_creative_generation_binding_v1($row, $scope, $binding);
    initiative_marketing_creative_current_v1($pdo, $owner, $scope, $binding, false, $id);
    initiative_marketing_creative_source_v1($pdo, $owner, $scope, $binding, false);
    vp3_image_run_v1($pdo, $user, $row);
    return $id;
}

function initiative_marketing_creative_generation_v1(PDO $pdo, int $owner, array $scope, array $variant, bool $tick=false, ?callable $transport=null): array
{
    $binding = initiative_marketing_creative_binding_v1($variant);
    $user = initiative_work_product_scope_v1($pdo, $owner, $scope);
    $id = $variant['generation_id'] ?? '';
    if (!is_string($id) || $id === '' || !table_exists('agent_image_generations_v1')) {
        throw new DomainException('The marketing image has not been queued.', 404);
    }
    $current = initiative_marketing_creative_current_v1($pdo, $owner, $scope, $binding, false, $id);
    if (($current['generation_id'] ?? '') !== $id) {
        throw new DomainException('The marketing image has not been attached to this variant.', 409);
    }
    $row = vp3_image_row_v1($pdo, $user, $id);
    initiative_marketing_creative_generation_binding_v1($row, $scope, $binding);
    return $tick ? vp3_image_tick_v1($pdo, $user, $row, $transport) : vp3_image_view_v1($pdo, $user, $row);
}

function initiative_marketing_creative_job_source_v1(PDO $pdo, array $user, array $row, array $config, bool $lock=false, bool $pixels=false): ?string
{
    $binding = $config['marketing'] ?? null;
    if (!is_array($binding) || !is_string($binding['id'] ?? null) || !is_int($binding['level'] ?? null)
        || $binding['level'] < 5 || $binding['level'] > 10
        || ($config['mode'] ?? null) !== ($binding['level'] <= 7 ? 'marketing_scene' : 'marketing_reference')) {
        throw new DomainException('The marketing image request could not be verified.', 409);
    }
    $owner = (int)$user['id'];
    $scope = ['agent_id'=>(int)$row['agent_id'], 'conversation_id'=>(int)$row['conversation_id']];
    if (!$lock) initiative_marketing_creative_cloud_v1($pdo, $user, $scope);
    initiative_marketing_creative_current_v1($pdo, $owner, $scope, $binding, $lock, $row['generation_id']);
    $source = initiative_marketing_creative_source_v1($pdo, $owner, $scope, $binding, $lock);
    return $pixels && $binding['level'] >= 8 ? 'data:'.$source['mime'].';base64,'.base64_encode($source['bytes']) : null;
}
