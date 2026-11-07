<?php
declare(strict_types=1);

require_once __DIR__.'/initiative-work-product-v1.php';
require_once __DIR__.'/initiative-workbench-v1.php';

/** Normalize the terms that will be displayed and signed for this publication. */
function initiative_work_product_publication_input_v1(array $row,array $input): array
{
    $field=static function(string $name,string $default='')use($input):string{
        $value=$input[$name]??$default;
        if(!is_string($value)&&!is_int($value))throw new DomainException('Enter valid listing details.',422);
        return trim((string)$value);
    };
    $revision=$field('revision');
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$revision))throw new DomainException('Reload the draft before reviewing publication.',422);
    $productType=$field('product_type');
    $fulfillmentType=$field('fulfillment_type');
    if(!in_array($productType,['service','digital','membership','event','gift','physical','other'],true)
        ||!in_array($fulfillmentType,['virtual','digital','membership','event','gift','local_pickup','other'],true)){
        throw new DomainException('Choose a supported product and fulfillment type. Shipped products are unavailable.',422);
    }

    $providerMode=$field('provider_mode');
    $fixed=$providerMode==='fixed'?$field('fixed_connection_id'):'0';
    if(!in_array($providerMode,['guest_choice','fixed'],true)||!ctype_digit($fixed)
        ||(string)(int)$fixed!==$fixed||($providerMode==='fixed'&&(int)$fixed<1)){
        throw new DomainException('Choose how a buyer will pay for this listing.',422);
    }
    $showPhoto=$field('show_listing_photo','0');
    if(!in_array($showPhoto,['0','1'],true))throw new DomainException('Choose whether to show the original listing photo.',422);
    $slug=$field('profile_slug');
    $slug=profile_commerce_slug_v900($slug!==''?$slug:(string)$row['title']);
    if($slug==='')throw new DomainException('Choose a valid profile product slug.',422);

    $hold=$field('hold_minutes','30');
    if(!ctype_digit($hold)||strlen($hold)>3||(int)$hold<30||(int)$hold>120){
        throw new DomainException('Set a payment hold between 30 and 120 minutes.',422);
    }
    $policy=initiative_work_product_text_v1($field('cancellation_policy'),1000);
    $cta=initiative_work_product_text_v1($field('profile_cta','Buy'),40);
    try{
        $terms=profile_commerce_generic_terms_v900(['payment_mode'=>$field('payment_mode','full'),'price'=>$field('price'),
            'currency'=>$field('currency'),'hold_minutes'=>$hold,'refund_before_hours'=>0,'cancellation_policy'=>$policy]);
    }catch(RuntimeException $error){
        throw new DomainException($error->getMessage(),422);
    }
    return [[
        'revision'=>$revision,'product_type'=>$productType,'fulfillment_type'=>$fulfillmentType,
        'price'=>agent_commerce_minor_to_decimal_v800($terms['price_cents']),'currency'=>strtoupper($terms['currency']),
        'provider_mode'=>$providerMode,'fixed_connection_id'=>$fixed,'profile_slug'=>$slug,'profile_cta'=>$cta,
        'hold_minutes'=>(string)$terms['hold_minutes'],'cancellation_policy'=>$policy,'show_listing_photo'=>$showPhoto,
    ],$terms];
}

function initiative_work_product_publication_profile_v1(PDO $pdo,int $owner,bool $lock): array
{
    $query=$pdo->prepare('SELECT username,is_public FROM user_profiles WHERE user_id=?'.($lock?agent_commerce_lock_pa2($pdo):''));
    $query->execute([$owner]);
    $profile=$query->fetch(PDO::FETCH_ASSOC);
    if(!$profile||empty($profile['is_public'])||!profile_username_valid((string)$profile['username'])){
        throw new DomainException('Set a username and make your Profile public before publishing the listing.',409);
    }
    return $profile+['user_id'=>$owner,'is_active'=>1];
}

function initiative_work_product_publication_photo_v1(PDO $pdo,array $user,array $metadata,string $origin,bool $lock): ?array
{
    if($origin==='product_text_v1')return null;
    $id=(int)($metadata['source_asset_id']??0);
    if($lock){
        $query=$pdo->prepare("SELECT id FROM user_media_assets WHERE user_id=? AND id=? AND media_type='photo'".agent_commerce_lock_pa2($pdo));
        $query->execute([(int)$user['id'],$id]);
        if(!$query->fetchColumn())throw new DomainException('The original photo changed or is unavailable.',409);
    }
    $photo=initiative_work_product_review_photo_v1($user,$metadata);
    if(!$photo||!in_array($photo['mime_type'],VP3_PRODUCT_PHOTO_MIMES_V1,true)){
        throw new DomainException('The original photo changed or is unavailable.',409);
    }
    return ['id'=>$id,'title'=>(string)$metadata['source_title'],'url'=>media_studio_asset_url($id)];
}

function initiative_work_product_publication_fingerprint_v1(mixed $value): string
{
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR));
}

function initiative_work_product_publication_return_url_v1(): string
{
    try{
        return agent_commerce_absolute_url_v800('/profile-commerce-return.php');
    }catch(RuntimeException){
        throw new DomainException('Configure the Commerce checkout return address before publishing.',409);
    }
}

/** Immutable seller review of one private row, its source, and currently eligible merchants. */
function initiative_work_product_publication_plan_v1(PDO $pdo,array $user,array $row,array $input,bool $lock): array
{
    if(!initiative_work_product_draft_v1($row)||!empty($row['is_active'])||profile_commerce_visibility_v900($row)!=='hidden'
        ||!empty($row['workspace_owner_user_id'])||!empty($row['binding_type'])){
        throw new DomainException('This private product draft is unavailable.',404);
    }
    [$input,$terms]=initiative_work_product_publication_input_v1($row,$input);
    if(!hash_equals(initiative_work_product_edit_revision_v1($row),$input['revision'])){
        throw new DomainException('The draft changed. Review the current listing again.',409);
    }
    $owner=(int)$user['id'];
    $id=(int)$row['id'];
    $profile=initiative_work_product_publication_profile_v1($pdo,$owner,$lock);
    profile_commerce_assert_slug_available_v900($pdo,$owner,$id,$input['profile_slug'],$lock);

    $metadata=profile_commerce_metadata_v900($row);
    $origin=(string)$metadata['listing_draft'];
    $photo=initiative_work_product_publication_photo_v1($pdo,$user,$metadata,$origin,$lock);
    $showPhoto=$input['show_listing_photo']==='1';
    if($showPhoto&&!$photo)throw new DomainException('This draft does not have an original photo to show.',422);
    unset($metadata['listing_draft'],$metadata['public_listing_photo']);
    $metadata['profile_visibility']='public';
    $metadata['profile_slug']=$input['profile_slug'];
    $metadata['profile_cta']=$input['profile_cta'];
    if($showPhoto)$metadata['public_listing_photo']=true;
    $candidate=array_replace($row,$terms,[
        'product_type'=>$input['product_type'],'fulfillment_type'=>$input['fulfillment_type'],
        'provider_mode'=>$input['provider_mode'],'fixed_connection_id'=>(int)$input['fixed_connection_id']?:null,
        'is_active'=>1,'metadata_json'=>json_encode($metadata,JSON_THROW_ON_ERROR),
    ]);
    if(!profile_commerce_checkout_product_eligible_v900($candidate)){
        throw new DomainException('Choose a supported checkout currency and a price within the checkout limit.',422);
    }

    $connections=array_values(array_filter(profile_commerce_checkout_connections_v900($pdo,$candidate,$lock),
        static fn(array $connection):bool=>profile_commerce_checkout_connection_eligible_v900($connection,$candidate)));
    if(!$connections)throw new DomainException('Choose a connected payment provider that can accept this listing’s currency.',409);
    $providers=[];
    $merchants=[];
    foreach($connections as $connection){
        $provider=(string)$connection['provider'];
        $account=(string)$connection['external_account_id'];
        $label=trim((string)$connection['account_label']);
        $providers[]=['id'=>(int)$connection['id'],'provider'=>$provider,
            'label'=>agent_commerce_provider_label_v800($provider).' · '.($label!==''&&$label!==$account?$label.' ('.$account.')':$account)];
        $merchants[]=agent_commerce_checkout_merchant_fingerprint_pa5($connection);
    }
    $product=profile_commerce_product_projection_v900($candidate,(string)$profile['username']);
    $returnUrl=initiative_work_product_publication_return_url_v1();
    $request=initiative_work_product_publication_fingerprint_v1(['listing-publication',$owner,$id,$input,$terms,
        $product['product_url'],$returnUrl,$metadata['source_asset_id']??null,$metadata['source_content_sha256']??null,$providers,$merchants]);
    return [
        'request'=>$request,'profile'=>$profile,'origin'=>$origin,'candidate'=>$candidate,'metadata'=>$metadata,'return_url'=>$returnUrl,
        'review'=>['input'=>$input,'product'=>$product,'source_photo'=>$photo,'providers'=>$providers,
            'provider_mode'=>$input['provider_mode'],'terms'=>$terms,'show_listing_photo'=>$showPhoto],
    ];
}

function initiative_work_product_publication_preview_v1(PDO $pdo,int $owner,int $id,array $input,string $key): array
{
    if($key==='')throw new LogicException('A session review key is required.');
    $user=initiative_work_product_user_v1($pdo,$owner,'',false);
    $row=profile_commerce_owner_product_v900($pdo,$owner,$id);
    if(!$row)throw new DomainException('This private product draft is unavailable.',404);
    $plan=initiative_work_product_publication_plan_v1($pdo,$user,$row,$input,false);
    return $plan['review']+['review_token'=>initiative_workbench_review_token_v1($key,$plan['request'],time()+600)];
}

function initiative_work_product_publication_listing_digest_v1(array $row): string
{
    $metadata=profile_commerce_metadata_v900($row);
    unset($metadata['listing_activation_v1']);
    return initiative_work_product_publication_fingerprint_v1([
        $row['product_key'],$row['title'],$row['description'],$row['product_type'],$row['fulfillment_type'],
        (bool)$row['is_active'],(int)($row['workspace_owner_user_id']??0),$row['binding_type']??null,
        agent_commerce_product_terms_v800($row),$metadata,
    ]);
}

function initiative_work_product_publication_result_v1(PDO $pdo,array $profile,array $row,array $receipt,?array $photo,bool $replayed): array
{
    $slug=profile_commerce_public_slug_v900($row);
    $public=profile_commerce_public_product_v900($pdo,$profile,$slug);
    if(!$public||$public['id']!==(int)$row['id']||$public['product_url']!==$receipt['product_url']){
        throw new DomainException('The reviewed listing is no longer at this public destination.',409);
    }
    if(!profile_commerce_checkout_product_eligible_v900($row)
        ||!profile_commerce_checkout_connections_v900($pdo,$row,true)
        ||initiative_work_product_publication_return_url_v1()!==$receipt['return_url']){
        throw new DomainException('The reviewed listing no longer has an available checkout route.',409);
    }
    if((profile_commerce_metadata_v900($row)['public_listing_photo']??false)===true){
        require_once __DIR__.'/profile-product-photo-v1.php';
        if(profile_product_photo_image_v1($pdo,(string)$profile['username'],$slug,(int)$row['id'])===null){
            throw new DomainException('The approved original photo is unavailable on the public listing.',409);
        }
    }
    return ['product'=>$public,'source_photo'=>$photo,'replayed'=>$replayed];
}

function initiative_work_product_publication_confirm_v1(PDO $pdo,int $owner,int $id,array $input,string $token,string $key): array
{
    if($key==='')throw new LogicException('A session review key is required.');
    return agent_commerce_atomic_pa2($pdo,static function()use($pdo,$owner,$id,$input,$token,$key):array{
        $user=initiative_work_product_user_v1($pdo,$owner,agent_commerce_lock_pa2($pdo),false);
        $row=profile_commerce_owner_product_v900($pdo,$owner,$id,true);
        if(!$row)throw new DomainException('This private product draft is unavailable.',404);
        $metadata=profile_commerce_metadata_v900($row);
        $receipt=$metadata['listing_activation_v1']??null;
        $confirm=['review_token'=>$token,'confirmed'=>true];
        if(is_array($receipt)&&!initiative_work_product_draft_v1($row)){
            // A successful retry only reads the same result. An older approval
            // cannot republish a listing the seller has since changed or hidden.
            initiative_workbench_confirm_v1($confirm,$key,(string)$receipt['review_request'],time());
            [$input]=initiative_work_product_publication_input_v1($row,$input);
            if(!hash_equals((string)$receipt['input_digest'],initiative_work_product_publication_fingerprint_v1($input))
                ||!hash_equals((string)$receipt['listing_digest'],initiative_work_product_publication_listing_digest_v1($row))){
                throw new DomainException('The listing changed after publication. Review its current settings.',409);
            }
            $profile=initiative_work_product_publication_profile_v1($pdo,$owner,true);
            $photo=initiative_work_product_publication_photo_v1($pdo,$user,$metadata,(string)$receipt['source_type'],true);
            return initiative_work_product_publication_result_v1($pdo,$profile,$row,$receipt,$photo,true);
        }

        $plan=initiative_work_product_publication_plan_v1($pdo,$user,$row,$input,true);
        initiative_workbench_confirm_v1($confirm,$key,$plan['request'],time());
        $row=$plan['candidate'];
        $metadata=$plan['metadata'];
        $receipt=['source_type'=>$plan['origin'],'review_request'=>$plan['request'],
            'input_digest'=>initiative_work_product_publication_fingerprint_v1($plan['review']['input']),
            'terms_digest'=>initiative_work_product_publication_fingerprint_v1(agent_commerce_product_terms_v800($row)),
            'listing_digest'=>initiative_work_product_publication_listing_digest_v1($row),
            'product_url'=>$plan['review']['product']['product_url'],'return_url'=>$plan['return_url'],
            'published_at'=>agent_commerce_time_pa2()];
        $metadata['listing_activation_v1']=$receipt;
        $statement=$pdo->prepare('UPDATE agent_commerce_products_v800 SET product_type=?,fulfillment_type=?,payment_mode=?,
            price_cents=?,deposit_cents=?,currency=?,provider_mode=?,fixed_connection_id=?,hold_minutes=?,refund_before_hours=?,
            cancellation_fee_cents=?,cancellation_policy=?,metadata_json=?,is_active=1,updated_at=CURRENT_TIMESTAMP
            WHERE id=? AND owner_user_id=? AND is_active=0');
        $statement->execute([$row['product_type'],$row['fulfillment_type'],$row['payment_mode'],$row['price_cents'],
            $row['deposit_cents'],$row['currency'],$row['provider_mode'],$row['fixed_connection_id'],$row['hold_minutes'],
            $row['refund_before_hours'],$row['cancellation_fee_cents'],$row['cancellation_policy'],
            json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id,$owner]);
        if($statement->rowCount()!==1)throw new DomainException('The private draft changed before publication.',409);
        $row=profile_commerce_owner_product_v900($pdo,$owner,$id,true);
        if(!$row||!hash_equals($receipt['listing_digest'],initiative_work_product_publication_listing_digest_v1($row))){
            throw new DomainException('The published listing did not match the approved review.',409);
        }
        $result=initiative_work_product_publication_result_v1($pdo,$plan['profile'],$row,$receipt,$plan['review']['source_photo'],false);
        if (is_int($metadata['agent_id']??null) && $metadata['agent_id']>=0
            && is_int($metadata['conversation_id']??null) && $metadata['conversation_id']>0) {
            require_once __DIR__.'/initiative-product-launch-v1.php';
            initiative_product_launch_track_v1($pdo,$owner,$metadata,$id);
        }
        return $result;
    });
}
