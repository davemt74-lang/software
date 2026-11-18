<?php
declare(strict_types=1);

require_once __DIR__.'/initiative-work-product-v1.php';
require_once __DIR__.'/initiative-product-launch-state-v1.php';
require_once __DIR__.'/profile-agent.php';

const VP3_MARKETING_PLANS_PAGE_V1 = 12;

function initiative_marketing_plans_ready_v1(): void
{
    foreach (['agent_product_launches_v1','agent_commerce_products_v800','agent_commerce_marketing_drafts_v1',
        'agent_commerce_promotions_v1','user_media_assets'] as $table) {
        if (!table_exists($table)) throw new DomainException('Run Database Upgrade to view product marketing plans.',503);
    }
}

function initiative_marketing_plan_id_v1(mixed $value): int
{
    if (!is_int($value) || $value < 1 || $value > 9007199254740991) {
        throw new DomainException('Choose an exact product marketing plan.',422);
    }
    return $value;
}

/** The original Chat remains the authority; the current Chat may only discover it. */
function initiative_marketing_plans_query_v1(): string
{
    return 'SELECT l.product_id,l.owner_user_id,l.agent_id,l.conversation_id,l.draft_key,l.creative_id
        FROM agent_product_launches_v1 l
        INNER JOIN agent_commerce_products_v800 p ON p.id=l.product_id AND p.owner_user_id=l.owner_user_id
        INNER JOIN chat_conversations c ON c.id=l.conversation_id AND c.user_id=l.owner_user_id
            AND ((l.agent_id=0 AND c.user_agent_id IS NULL) OR (l.agent_id>0 AND c.user_agent_id=l.agent_id))
        WHERE l.owner_user_id=? AND l.agent_id=?';
}

function initiative_marketing_plan_label_v1(string $stage): string
{
    return match ($stage) {
        'listing_review'=>'Review listing',
        'marketing_review'=>'Review promotion',
        'photo_needed'=>'Choose an image',
        'design_review'=>'Design graphic',
        'image_starting'=>'Starting image',
        'image_pending'=>'Creating image',
        'creative_ready'=>'Review graphic',
        'creative_failed'=>'Image needs attention',
        'publication_review'=>'Ready to publish',
        'published'=>'Active',
        'paused'=>'Paused',
        'listing_unavailable'=>'Listing needs attention',
        default=>'Needs attention',
    };
}

/** A view has no approval token, no scheduler tick and no domain write. */
function initiative_marketing_plan_view_v1(PDO $pdo, int $owner, array $row): array
{
    $productId = (int)$row['product_id'];
    $original = ['agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id']];
    $user = initiative_work_product_scope_v1($pdo,$owner,$original);
    $product = profile_commerce_owner_product_v900($pdo,$owner,$productId);
    if (!$product) throw new DomainException('This product marketing plan is unavailable.',404);
    $view = ['product_id'=>$productId,'product_title'=>(string)$product['title']]+$original+[
        'stage'=>'needs_attention','stage_label'=>'Needs attention','needs_attention'=>true,'image'=>null,'resume'=>null,
        'chat_url'=>url('/chat.php?agent='.($original['agent_id'] > 0 ? $original['agent_id'] : 'system')
            .'&conversation_id='.$original['conversation_id'].'&marketing_plan='.$productId),
    ];
    try {
        $state = initiative_product_launch_state_v1($pdo,$owner,$original,$productId,$row['draft_key'],$row['creative_id']);
        $sourceId = $state['source_id'] ?? null;
        if ($state['stage'] === 'listing_review' && $sourceId === null) {
            $metadata = profile_commerce_metadata_v900($product);
            if (isset($metadata['source_asset_id'])) {
                $linked = $metadata['source_asset_id'];
                if (!is_int($linked) && !is_string($linked)) throw new DomainException('The original listing photo is unavailable.',409);
                $photo = initiative_work_product_source_v1($pdo,$owner,$original,['source_id'=>(string)$linked]);
                if (($metadata['source_content_sha256'] ?? null) !== $photo['digest']) {
                    throw new DomainException('The original listing photo changed.',409);
                }
                $sourceId = $photo['id'];
            }
        }
        $image = null;
        if ($sourceId !== null) {
            $asset = media_studio_asset((int)$sourceId,$user);
            if (!$asset || !initiative_work_product_file_v1($owner,(string)$asset['file_path'])) {
                throw new DomainException('The original plan image is unavailable.',409);
            }
            $image = ['id'=>(int)$asset['id'],'url'=>media_studio_asset_url((int)$asset['id']),
                'title'=>initiative_work_product_source_title_v1($asset)];
        }
        $marker = ['owner_user_id'=>$owner]+$original+array_intersect_key($state,array_flip([
            'product_id','source_id','draft_id','creative_id','stage',
        ]));
        $view = array_replace($view, [
            'product_title'=>$state['product_title'],'stage'=>$state['stage'],
            'stage_label'=>initiative_marketing_plan_label_v1($state['stage']),
            'needs_attention'=>false,'image'=>$image,'resume'=>$marker,
        ]);
    } catch (DomainException|RuntimeException|JsonException $error) {
        if ($error instanceof PDOException
            || (!$error instanceof JsonException && !in_array($error->getCode(),[403,404,409,422],true))) throw $error;
        // Source failure is displayable only while the original product and Chat
        // are still authorized. A removed/rebound Chat never becomes a title leak.
        initiative_work_product_scope_v1($pdo,$owner,$original);
        if (!profile_commerce_owner_product_v900($pdo,$owner,$productId)) {
            throw new DomainException('This product marketing plan is unavailable.',404);
        }
    }
    $fresh = $pdo->prepare(initiative_marketing_plans_query_v1().' AND l.product_id=? LIMIT 1');
    $fresh->execute([$owner,$original['agent_id'],$productId]);
    $binding = $fresh->fetch(PDO::FETCH_ASSOC);
    if (!$binding) throw new DomainException('This product marketing plan is unavailable.',404);
    foreach (['conversation_id','draft_key','creative_id'] as $field) {
        if ($binding[$field] !== $row[$field]) throw new DomainException('The product marketing plan changed. Open it again.',409);
    }
    return $view;
}

function initiative_marketing_plans_list_v1(PDO $pdo, int $owner, array $scope, array $body): array
{
    initiative_work_product_scope_v1($pdo,$owner,$scope);
    initiative_marketing_plans_ready_v1();
    $query = initiative_marketing_plans_query_v1();
    $params = [$owner,$scope['agent_id']];
    if (array_key_exists('before',$body)) {
        $query .= ' AND l.product_id<?';
        $params[] = initiative_marketing_plan_id_v1($body['before']);
    }
    $find = $pdo->prepare($query.' ORDER BY l.product_id DESC LIMIT '.(VP3_MARKETING_PLANS_PAGE_V1+1));
    $find->execute($params);
    $rows = $find->fetchAll(PDO::FETCH_ASSOC);
    $next = count($rows) > VP3_MARKETING_PLANS_PAGE_V1 ? (int)$rows[VP3_MARKETING_PLANS_PAGE_V1-1]['product_id'] : null;
    $plans = [];
    foreach (array_slice($rows,0,VP3_MARKETING_PLANS_PAGE_V1) as $row) {
        try {
            $plans[] = initiative_marketing_plan_view_v1($pdo,$owner,$row);
        } catch (DomainException $error) {
            if ($error->getCode() !== 404) throw $error;
            // An original Chat or product removed after the join is no longer visible.
        }
    }
    initiative_work_product_scope_v1($pdo,$owner,$scope);
    return ['plans'=>$plans,'next_before'=>$next];
}

function initiative_marketing_plan_read_v1(PDO $pdo, int $owner, array $scope, array $body): array
{
    initiative_work_product_scope_v1($pdo,$owner,$scope);
    initiative_marketing_plans_ready_v1();
    $product = initiative_marketing_plan_id_v1($body['product_id'] ?? null);
    $find = $pdo->prepare(initiative_marketing_plans_query_v1().' AND l.product_id=? LIMIT 1');
    $find->execute([$owner,$scope['agent_id'],$product]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('This product marketing plan is unavailable.',404);
    $plan = initiative_marketing_plan_view_v1($pdo,$owner,$row);
    initiative_work_product_scope_v1($pdo,$owner,$scope);
    return ['plan'=>$plan];
}
