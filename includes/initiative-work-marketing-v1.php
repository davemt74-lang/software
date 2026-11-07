<?php
declare(strict_types=1);

require_once __DIR__.'/initiative-work-product-v1.php';

function initiative_work_marketing_sources_v1(PDO $pdo, int $owner, array $scope): array
{
    if (!table_exists('agent_commerce_marketing_drafts_v1')) {
        throw new DomainException('Run Database Upgrade to use private marketing drafts.',503);
    }
    return array_values(array_filter(initiative_work_product_sources_v1($pdo, $owner, $scope),
        static fn(array $source): bool => $source['source_id'] !== 'description'));
}

function initiative_work_marketing_products_v1(PDO $pdo, int $owner, array $scope): array
{
    initiative_work_product_scope_v1($pdo, $owner, $scope);
    if (!table_exists('agent_commerce_products_v800')) return [];
    $query = $pdo->prepare('SELECT id,title FROM agent_commerce_products_v800 WHERE owner_user_id=? ORDER BY id DESC LIMIT 100');
    $query->execute([$owner]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function initiative_work_marketing_drafts_v1(PDO $pdo, int $owner, int $productId): array
{
    if (!table_exists('agent_commerce_marketing_drafts_v1')) return [];
    $query = $pdo->prepare('SELECT d.request_key,d.draft_json FROM agent_commerce_marketing_drafts_v1 d
        INNER JOIN agent_commerce_products_v800 p ON p.id=d.product_id AND p.owner_user_id=d.owner_user_id
        WHERE d.owner_user_id=? AND d.product_id=? ORDER BY d.created_at DESC,d.request_key LIMIT 12');
    $query->execute([$owner,$productId]);
    $drafts = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $drafts[$row['request_key']] = array_replace(json_decode($row['draft_json'],true,16,JSON_THROW_ON_ERROR),
            ['draft_id'=>$row['request_key']]);
    }
    return $drafts;
}

/** Resolve current owner records and hash the inputs used to draft copy. */
function initiative_work_marketing_context_v1(PDO $pdo, int $owner, array $scope, array $input, bool $lock=false): array
{
    if (!table_exists('agent_commerce_marketing_drafts_v1')) {
        throw new DomainException('Run Database Upgrade to use private marketing drafts.',503);
    }
    $sourceId = $input['source_id'] ?? null;
    $productId = $input['product_id'] ?? null;
    if (!is_string($sourceId) || !ctype_digit($sourceId) || !is_int($productId) || $productId < 1) {
        throw new DomainException('Choose an exact image and product.', 422);
    }
    $asset = initiative_work_product_source_v1($pdo, $owner, $scope, $input, $lock);
    unset($asset['bytes']);
    $product = profile_commerce_owner_product_v900($pdo, $owner, $productId, $lock);
    if (!$product) throw new DomainException('The selected product is unavailable.', 404);
    $query = $pdo->prepare('SELECT source FROM user_media_assets WHERE id=? AND user_id=?');
    $query->execute([$sourceId, $owner]);
    $provenance = $query->fetchColumn();
    if ($provenance === false) throw new DomainException('The selected image is unavailable.', 404);
    $facts = ['title'=>(string)$product['title'], 'description'=>(string)$product['description']];
    $linkedAsset = profile_commerce_metadata_v900($product)['source_asset_id'] ?? null;
    $linked = (is_int($linkedAsset) || is_string($linkedAsset)) && (string)$linkedAsset === $sourceId;
    $revision = hash('sha256', json_encode([$productId, $facts, $asset['revision'], $provenance, $linked], JSON_THROW_ON_ERROR));
    return compact('asset','product','facts','provenance','revision','linked');
}

function initiative_work_marketing_preview_v1(PDO $pdo, int $owner, array $scope, array $input): array
{
    $current = initiative_work_marketing_context_v1($pdo, $owner, $scope, $input);
    $asset = $current['asset'];
    $drafts = array_values(array_filter(initiative_work_marketing_drafts_v1($pdo, $owner, $input['product_id']),
        static fn(array $draft): bool => (int)$draft['asset_id'] === (int)$asset['id']
            && ($draft['asset_sha256'] ?? '') === $asset['digest']));
    return ['source_id'=>$asset['id'], 'source_title'=>$asset['title'], 'revision'=>$current['revision'], 'details'=>[
        'image_url'=>media_studio_asset_url((int)$asset['id']),
        'linked_product_image'=>$current['linked'],
        'summary'=>'Product: '.$current['facts']['title'],
        'drafts'=>$drafts,
        'effect'=>$current['provenance']==='openai_image' ? 'Use a generated image for this draft.' : 'Use this saved image for the draft.',
        'owner_review'=>'Save private copy and a campaign idea with the selected image. Cloud AI receives the product wording and image title.',
    ]];
}

function initiative_work_marketing_create_v1(PDO $pdo, int $owner, array $scope, array $input, array $preview, ?callable $model=null): array
{
    $request = $input['request_id'] ?? null;
    if (!is_string($request) || !preg_match('/\A[a-f0-9]{64}\z/D', $request)) {
        throw new DomainException('Review the marketing draft before creating it.', 422);
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return initiative_work_marketing_save_v1($pdo,$owner,$scope,$input,$preview,$request,$model);
    }
    $name = 'vp3-marketing:'.substr(hash('sha256',$owner.'|'.$request),0,48);
    $lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([$name]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new DomainException('This marketing draft is already being prepared.',409);
    }
    try {
        return initiative_work_marketing_save_v1($pdo,$owner,$scope,$input,$preview,$request,$model);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }
}

function initiative_work_marketing_save_v1(PDO $pdo, int $owner, array $scope, array $input, array $preview, string $request, ?callable $model): array
{
    $resolve = static function(bool $lock=false) use ($pdo,$owner,$scope,$input,$preview): array {
        $current = initiative_work_marketing_context_v1($pdo,$owner,$scope,$input,$lock);
        if (($preview['revision']??null)!==$current['revision'] || ($preview['source_id']??null)!==$current['asset']['id']) {
            throw new DomainException('The product or image changed. Review the draft again.',409);
        }
        return $current;
    };
    $current = $resolve();
    $drafts = initiative_work_marketing_drafts_v1($pdo,$owner,$input['product_id']);
    if (isset($drafts[$request])) return $drafts[$request];
    if (count($drafts) >= 12) throw new DomainException('This product has reached its private marketing draft limit.',429);
    $asset = $current['asset'];
    $prompt = json_encode(['product'=>$current['facts'],'image_title'=>$asset['title'],
        'image_source'=>$current['provenance']],JSON_THROW_ON_ERROR);
    if ($model) {
        $answer = $model($prompt);
    } else {
        $route = vp3_agent_runtime_plan_v420($pdo,$asset['user'],(int)$scope['agent_id'],'chat');
        if (($route['route']??'')!=='vp3_cloud' || !empty($route['blocked'])) {
            throw new DomainException('Cloud drafting is unavailable under your Agent settings.',503);
        }
        $result = ai_generate_chat_response($prompt,[],[],$asset['user'],'main_ai',
            ['surface'=>'tool_json','protocol'=>'vp3.product-marketing-draft.v1']);
        if (empty($result['ok'])) throw new DomainException('Cloud AI could not prepare the marketing draft.',503);
        $answer = $result['answer']??'';
    }
    $copy = vp3_tool_json_object_v1($answer,['headline','copy','campaign_idea'],8192);
    $headline = initiative_work_product_text_v1($copy['headline']??null,120,true);
    $body = initiative_work_product_text_v1($copy['copy']??null,600,true);
    $campaign = initiative_work_product_text_v1($copy['campaign_idea']??null,300,true);
    if (preg_match('~https?://|www\.|(?:[$€£]|\b(?:USD|EUR|GBP)\s*)\d~iu',"$headline $body $campaign")) {
        throw new DomainException('Unexpected offer details or links in the marketing draft.',422);
    }
    $output = ['kind'=>'product_marketing_draft','draft_id'=>$request,'product_id'=>$input['product_id'],'title'=>$headline,
        'description'=>$body,'campaign_idea'=>$campaign,'asset_id'=>(int)$asset['id'],
        'asset_sha256'=>$asset['digest'],'asset_source'=>$current['provenance'],'image_url'=>media_studio_asset_url((int)$asset['id']),
        'review_url'=>url('/profile-commerce-products.php?workspace_product='.$input['product_id'].'#product-marketing-drafts')];
    $pdo->beginTransaction();
    try {
        $resolve(true);
        $drafts = initiative_work_marketing_drafts_v1($pdo,$owner,$input['product_id']);
        if (isset($drafts[$request])) {
            $output = $drafts[$request];
        } else {
            if (count($drafts) >= 12) throw new DomainException('This product has reached its private marketing draft limit.',429);
            $query = $pdo->prepare('INSERT INTO agent_commerce_marketing_drafts_v1
                (product_id,owner_user_id,request_key,draft_json) VALUES (?,?,?,?)');
            $query->execute([$input['product_id'],$owner,$request,json_encode($output,JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit();
        return $output;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
