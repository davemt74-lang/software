<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/profile-commerce-ops-v900.php';
require_once __DIR__.'/includes/initiative-work-marketing-v1.php';
require_once __DIR__.'/includes/initiative-work-marketing-promotion-v1.php';
require_once __DIR__.'/includes/initiative-work-product-publication-v1.php';
require_permission('account.access');
$pdo=db();$user=current_user();if(!$pdo||!$user)redirect(url('/login.php'));if(!agent_commerce_schema_ready_v800($pdo))redirect(url('/upgrade.php'));
header('Cache-Control: private, no-store');
$uid=(int)$user['id'];$profile=profile_for_user($pdo,$uid,true);$error='';$notice=trim((string)($_GET['saved']??''));
$listingReview=null;$listingInput=[];$reviewProductId=0;
function profile_commerce_manage_redirect_v900(string $message): never{redirect(url('/profile-commerce-products.php?saved='.rawurlencode($message)));}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else try{
        $action=(string)($_POST['action']??'');
        if(in_array($action,['preview_initiative_publication','confirm_initiative_publication'],true)){
            if(($_POST['owner_key']??null)!==(string)$uid)throw new DomainException('The account changed. Reopen this draft.',409);
            $draftId=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$draftId)throw new DomainException('Choose a saved product draft.',422);
            $reviewProductId=$draftId;
            $listingInput=array_filter($_POST,'is_string');
            $key=$_SESSION['initiative_listing_review_key_v1']??=bin2hex(random_bytes(32));
            if($action==='preview_initiative_publication'){
                $choice=$listingInput['provider_choice']??'';
                $listingInput['provider_mode']=$choice==='guest_choice'?'guest_choice':'fixed';
                $listingInput['fixed_connection_id']=$choice==='guest_choice'?'0':$choice;
                $listingReview=initiative_work_product_publication_preview_v1($pdo,$uid,$draftId,$listingInput,$key);
            }else{
                if(($listingInput['confirm_publish']??'')!=='1')throw new DomainException('Confirm the public listing before publishing.',422);
                initiative_work_product_publication_confirm_v1($pdo,$uid,$draftId,$listingInput,$listingInput['review_token']??'',$key);
                redirect(url('/profile-commerce-products.php?workspace_product='.$draftId.'#product-listing-published'));
            }
        }elseif($action==='pause_promotion'){
            if(($_POST['confirm_pause']??null)!=='1')throw new RuntimeException('Confirm pausing this promotion.');
            $promotion=initiative_work_marketing_promotion_pause_v1($pdo,$uid,(string)($_POST['promotion_id']??''));
            redirect(url('/profile-commerce-products.php?workspace_product='.(int)$promotion['product_id']
                .'&saved='.rawurlencode('Product promotion paused.').'#product-marketing-drafts'));
        }elseif($action==='save_initiative_draft'){
            $draftId=(int)($_POST['product_id']??0);
            initiative_work_product_update_v1($pdo,$uid,$draftId,(string)($_POST['revision']??''),$_POST['title']??null,$_POST['description']??null);
            redirect(url('/profile-commerce-products.php?workspace_product='.$draftId.'&saved='.rawurlencode('Private product draft saved.').'#product-listing-draft'));
        }elseif($action==='save_generic'){
            if((string)($_POST['fulfillment_type']??'')==='physical')throw new RuntimeException('Shipped physical products are not public yet. Use Local pickup until the shipping-address fulfillment adapter is installed.');
            if((string)($_POST['payment_mode']??'full')!=='full')throw new RuntimeException('Generic Profile Commerce products use full payment in Phase 9. Deposits remain available for appointment products.');
            $requested=$_POST;$safe=$_POST;$safe['profile_visibility']='hidden';$safe['payment_mode']='full';$safe['deposit']='0';$safe['refund_before_hours']=0;$safe['cancellation_fee']='0';
            $pdo->beginTransaction();
            try{
                $saved=profile_commerce_save_generic_product_v900($pdo,$uid,$safe);
                profile_commerce_publish_v900($pdo,$uid,(int)$saved['id'],$requested);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            profile_commerce_manage_redirect_v900('Profile Commerce product saved.');
        }elseif($action==='publish'){
            $candidate=profile_commerce_owner_product_v900($pdo,$uid,(int)($_POST['product_id']??0));
            if($candidate&&(string)($_POST['profile_visibility']??'hidden')==='public'){
                if((string)($candidate['fulfillment_type']??'')==='physical')throw new RuntimeException('Shipped physical products cannot be published until the shipping-address fulfillment adapter is installed.');
                if(trim((string)($candidate['binding_type']??''))===''&&(string)($candidate['payment_mode']??'')!=='full')throw new RuntimeException('Generic Profile Commerce products must use full payment before they can be published.');
            }
            profile_commerce_publish_v900($pdo,$uid,(int)($_POST['product_id']??0),$_POST);profile_commerce_manage_redirect_v900('Profile publication settings saved.');
        }elseif($action==='fulfillment'){profile_commerce_set_fulfillment_v900($pdo,$uid,(int)($_POST['order_id']??0),(string)($_POST['fulfillment_status']??''));profile_commerce_manage_redirect_v900('Order fulfillment updated.');}
        elseif($action==='release_payment_review'){
            if(($_POST['confirm_payment_review']??null)!=='1')throw new RuntimeException('Confirm you reviewed the verified payment before starting fulfillment.');
            profile_commerce_set_fulfillment_v900($pdo,$uid,(int)($_POST['order_id']??0),'processing',true);
            redirect(url('/profile-commerce-products.php?saved='.rawurlencode('Payment review approved. Order fulfillment started.').'#orders'));
        }elseif($action==='refund_profile'){$amount=agent_commerce_decimal_to_minor_v800((string)($_POST['refund_amount']??'0'));profile_commerce_refund_order_v900($pdo,$uid,(int)($_POST['order_id']??0),$amount,(string)($_POST['refund_reason']??''),$user,!empty($_POST['confirm_refund']));profile_commerce_manage_redirect_v900('Refund request sent to the original payment provider.');}
        else throw new RuntimeException('Unknown Profile Commerce action.');
    }catch(Throwable $e){
        if($reviewProductId>0&&($e instanceof PDOException||!($e instanceof RuntimeException||$e instanceof DomainException))){
            error_log('VP3 product publication review failed: '.$e->getMessage());
            $error='The listing review could not be verified. Open the draft again to check its current status.';
        }else $error=$e->getMessage();
    }
}
$products=agent_commerce_products_for_owner_v800($pdo,$uid,200);$workspaceProduct=$reviewProductId?:max(0,(int)($_GET['workspace_product']??0));$listingDraft=null;$activatedListing=null;$marketingDrafts=[];$promotions=[];
if($workspaceProduct>0){
    $focused=agent_commerce_product_v800($pdo,$workspaceProduct,$uid);
    if($focused){
        $marketingDrafts=initiative_work_marketing_drafts_v1($pdo,$uid,$workspaceProduct);
        if(table_exists('agent_commerce_promotions_v1')){
            $find=$pdo->prepare("SELECT id,draft_key,agent_id,conversation_id,source_asset_id,status,public_key FROM agent_commerce_promotions_v1
                WHERE owner_user_id=? AND product_id=? ORDER BY CASE WHEN status='published' THEN 0 ELSE 1 END,updated_at DESC LIMIT 24");
            $find->execute([$uid,$workspaceProduct]);
            foreach($find->fetchAll(PDO::FETCH_ASSOC) as $promotion)$promotions[$promotion['draft_key']]??=$promotion;
        }
        $products=array_values(array_filter($products,static fn($p)=>(int)$p['id']!==$workspaceProduct));array_unshift($products,$focused);
        if(initiative_work_product_draft_v1($focused)&&empty($focused['is_active'])&&profile_commerce_visibility_v900($focused)==='hidden')$listingDraft=$focused;
        elseif(!empty(profile_commerce_metadata_v900($focused)['listing_activation_v1']))$activatedListing=$focused;
    }
}
$connections=agent_commerce_connections_v800($pdo,$uid,true);$username=profile_username_normalize((string)($profile['username']??''));$profileOrders=profile_commerce_orders_for_owner_v900($pdo,$uid,60);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e(system_agent_name())?> | Profile Commerce</title><link rel="stylesheet" href="<?=e(url('/chat.css?v=82'))?>"><style>
.pc-main{background:#f7f7f5;min-height:100vh}.pc-canvas{padding:30px}.pc-inner{max-width:1180px;margin:auto}.pc-hero{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:18px}.pc-hero h1{font-size:34px;margin:6px 0}.pc-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}.pc-card{grid-column:span 12;background:#fff;border:1px solid #e3e4df;border-radius:20px;padding:20px}.pc-eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:#72736e}.pc-muted{color:#6f716b}.pc-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.pc-form label{display:flex;flex-direction:column;gap:5px;font-size:13px}.pc-form .wide{grid-column:1/-1}.pc-form input,.pc-form select,.pc-form textarea{padding:10px;border:1px solid #d7d9d2;border-radius:10px;font:inherit;background:#fff}.pc-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #cfd1ca;border-radius:10px;padding:9px 12px;background:#fff;color:#111;text-decoration:none;cursor:pointer}.pc-btn.primary{background:#111;color:#fff;border-color:#111}.pc-btn.danger{color:#8c241d}.pc-row{border-top:1px solid #ecece7;padding:16px 0}.pc-row:first-of-type{border-top:0}.pc-tags{display:flex;gap:6px;flex-wrap:wrap}.pc-tag{padding:4px 8px;border-radius:999px;background:#f0f1ed;font-size:12px}.pc-notice{padding:12px 14px;border-radius:12px;margin:12px 0;background:#eaf7ee}.pc-notice.err{background:#fff0ee;color:#86251f}.pc-order-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.pc-order-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.pc-order-actions form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.pc-order-actions input[type=number],.pc-order-actions input[type=text]{padding:8px;border:1px solid #d7d9d2;border-radius:9px}.pc-check{display:flex!important;flex-direction:row!important;align-items:center;gap:5px!important}@media(max-width:850px){.pc-canvas{padding:18px}.pc-form{grid-template-columns:1fr 1fr}.pc-hero,.pc-order-head{display:block}}
#product-listing-publication-review,#product-listing-published{margin:16px 0}.pc-listing-terms{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:8px 16px}.pc-listing-terms dt{font-weight:600}.pc-listing-terms dd{margin:0;overflow-wrap:anywhere}.pc-form label{min-width:0}.pc-form input,.pc-form select,.pc-form textarea{min-width:0;max-width:100%}@media(max-width:520px){.pc-listing-terms{grid-template-columns:1fr;gap:4px}.pc-listing-terms dd{margin-bottom:8px}}
</style></head><body><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='commerce';require __DIR__.'/includes/workspace-sidebar-v82.php';?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main pc-main"><?php $memberHeaderUser=$user;$memberHeaderTitle='Profile Commerce';$memberHeaderSubtitle='Choose what your public profile and Profile Agent may sell';$memberHeaderActions='<a class="pc-btn" href="'.e(url('/commerce.php')).'">Commerce</a>'.($username!==''?'<a class="pc-btn" href="'.e(profile_public_url($username)).'">View profile</a>':'');require __DIR__.'/includes/member-header.php';?><section class="pc-canvas"><div class="pc-inner">
<section class="pc-hero"><div><span class="pc-eyebrow">Phase 9 · Profile Commerce</span><h1>Publish Commerce on your profile.</h1><p class="pc-muted">Products, orders, payments and refunds remain in canonical Commerce. Profile Commerce controls the public projection and manual fulfillment for products sold from your profile.</p></div></section>
<?php if($notice!==''):?><div class="pc-notice"><?=e($notice)?></div><?php endif;?><?php if($error!==''):?><div class="pc-notice err"><?=e($error)?></div><?php endif;?>
<?php if($listingDraft):
    $listingMeta=profile_commerce_metadata_v900($listingDraft);
    $listingPhoto=initiative_work_product_review_photo_v1($user,$listingMeta);
    $listingRevision=initiative_work_product_edit_revision_v1($listingDraft);
    $providerChoice=$listingInput['provider_choice']??(($listingInput['provider_mode']??'')==='fixed'?($listingInput['fixed_connection_id']??''):'guest_choice');
    $productTypes=['service'=>'Service','digital'=>'Digital product','membership'=>'Membership','event'=>'Event','gift'=>'Gift','physical'=>'Physical product','other'=>'Other'];
    $fulfillmentTypes=['virtual'=>'Virtual service','digital'=>'Digital delivery','membership'=>'Membership','event'=>'Event','gift'=>'Gift','local_pickup'=>'Local pickup','other'=>'Manual fulfillment'];
?>
<section class="pc-card" id="product-listing-draft">
    <span class="pc-eyebrow">Private draft</span><h2>Review your product listing</h2>
    <p class="pc-muted">Review the suggested wording. Price, fulfillment and payment provider still need to be set.</p>
    <?php if($listingPhoto):?>
        <figure style="margin:14px 0"><img src="<?=e(media_studio_asset_url((int)$listingPhoto['id']))?>" alt="<?=e((string)$listingMeta['source_title'])?>" style="width:min(100%,320px);max-height:320px;object-fit:contain;object-position:left"><figcaption><?=e((string)$listingMeta['source_title'])?></figcaption></figure>
    <?php elseif(isset($listingMeta['source_asset_id'])):?><p class="pc-muted">The original photo is no longer available.</p><?php endif;?>
    <form method="post" class="pc-form">
        <?=csrf_field()?><input type="hidden" name="action" value="save_initiative_draft">
        <input type="hidden" name="product_id" value="<?=(int)$listingDraft['id']?>">
        <input type="hidden" name="revision" value="<?=e($listingRevision)?>">
        <label class="wide">Title<input name="title" maxlength="190" required value="<?=e((string)$listingDraft['title'])?>"></label>
        <label class="wide">Description<textarea name="description" rows="4" maxlength="1000"><?=e((string)$listingDraft['description'])?></textarea></label>
        <div class="wide"><button class="pc-btn primary" type="submit">Save private draft</button></div>
    </form>
    <div class="pc-row" id="product-listing-terms">
        <h3>Price, fulfillment and payment</h3>
        <?php if(empty($profile['is_public'])||$username===''): ?>
        <p class="pc-muted">A public profile is required to publish a listing. <a href="<?=e(url('/profile-agent.php'))?>">Open Profile settings</a></p>
        <?php endif; ?>
        <?php if(!$connections): ?>
        <p class="pc-muted">Connect a payment provider in <a href="<?=e(url('/commerce.php'))?>">Commerce</a> to accept payment.</p>
        <?php endif; ?>
        <form method="post" class="pc-form" action="<?=e(url('/profile-commerce-products.php?workspace_product='.$workspaceProduct.'#product-listing-publication-review'))?>">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="preview_initiative_publication">
            <input type="hidden" name="product_id" value="<?=$workspaceProduct?>">
            <input type="hidden" name="owner_key" value="<?=$uid?>">
            <input type="hidden" name="revision" value="<?=e($listingRevision)?>">
            <label>Price<input name="price" type="number" min="0.01" max="<?=e(agent_commerce_minor_to_decimal_v800(VP3_PROFILE_COMMERCE_CHECKOUT_MAX_TOTAL_V900))?>" step="0.01" inputmode="decimal" required value="<?=e($listingInput['price']??'')?>"></label>
            <label>Currency<select name="currency" required>
                <?php foreach(VP3_PROFILE_COMMERCE_CHECKOUT_CURRENCIES_V900 as $code): $code=strtoupper($code); ?>
                <option value="<?=e($code)?>" <?=strtoupper($listingInput['currency']??'USD')===$code?'selected':''?>><?=e($code)?></option>
                <?php endforeach; ?>
            </select></label>
            <label>Product type<select name="product_type" required><option value="">Choose type</option>
                <?php foreach($productTypes as $value=>$label): ?><option value="<?=e($value)?>" <?=($listingInput['product_type']??'')===$value?'selected':''?>><?=e($label)?></option><?php endforeach; ?>
            </select></label>
            <label>Fulfillment<select name="fulfillment_type" required><option value="">Choose fulfillment</option>
                <?php foreach($fulfillmentTypes as $value=>$label): ?><option value="<?=e($value)?>" <?=($listingInput['fulfillment_type']??'')===$value?'selected':''?>><?=e($label)?></option><?php endforeach; ?>
            </select></label>
            <label>Payment provider<select name="provider_choice" required><option value="guest_choice" <?=$providerChoice==='guest_choice'?'selected':''?>>Customer chooses</option>
                <?php foreach($connections as $connection): $connectionId=(string)$connection['id']; ?>
                <option value="<?=e($connectionId)?>" <?=$providerChoice===$connectionId?'selected':''?>><?=e(agent_commerce_provider_label_v800((string)$connection['provider']).' · '.(string)($connection['account_label']?:$connection['external_account_id']))?></option>
                <?php endforeach; ?>
            </select></label>
            <label>Listing URL slug<input name="profile_slug" maxlength="80" value="<?=e($listingInput['profile_slug']??profile_commerce_slug_v900((string)$listingDraft['title']))?>"></label>
            <label>Button label<input name="profile_cta" maxlength="40" value="<?=e($listingInput['profile_cta']??'Buy')?>"></label>
            <label>Payment hold minutes<input name="hold_minutes" type="number" min="30" max="120" value="<?=e($listingInput['hold_minutes']??'30')?>"></label>
            <label class="wide">Cancellation and refund policy<textarea name="cancellation_policy" rows="2" maxlength="1000"><?=e($listingInput['cancellation_policy']??'')?></textarea></label>
            <?php if($listingPhoto): ?>
            <label class="wide pc-check"><input type="checkbox" name="show_listing_photo" value="1" <?=($listingInput['show_listing_photo']??'')==='1'?'checked':''?>> Show this original photo on the public listing</label>
            <?php endif; ?>
            <div class="wide"><button class="pc-btn primary" type="submit">Review public listing</button></div>
        </form>
    </div>
</section>
<?php endif;?>
<?php if($listingReview&&$listingDraft): $reviewedProduct=$listingReview['product']; ?>
<section class="pc-card" id="product-listing-publication-review" aria-labelledby="listing-publication-heading">
    <span class="pc-eyebrow">Approval required</span><h2 id="listing-publication-heading">Review the public listing</h2>
    <h3><?=e((string)$reviewedProduct['title'])?></h3>
    <p><?=e((string)$reviewedProduct['description'])?></p>
    <?php if($listingReview['show_listing_photo']&&$listingReview['source_photo']): ?>
    <figure><img src="<?=e($listingReview['source_photo']['url'])?>" alt="<?=e($listingReview['source_photo']['title'])?>" style="width:min(100%,320px);max-height:320px;object-fit:contain"><figcaption>This photo will be visible on the public listing.</figcaption></figure>
    <?php elseif($listingReview['source_photo']): ?><p>The attached photo stays private for promotion design.</p><?php endif; ?>
    <dl class="pc-listing-terms">
        <dt>Price</dt><dd><?=e((string)$reviewedProduct['price_label'])?> · Full payment</dd>
        <dt>Fulfillment</dt><dd><?=e($fulfillmentTypes[$reviewedProduct['fulfillment_type']]??(string)$reviewedProduct['fulfillment_type'])?></dd>
        <dt>Checkout</dt><dd><?=e($listingReview['provider_mode']==='fixed'?'Fixed provider':'Customer chooses')?> · <?=e(implode(', ',array_column($listingReview['providers'],'label')))?></dd>
        <dt>Payment hold</dt><dd><?=(int)$listingReview['terms']['hold_minutes']?> minutes</dd>
        <dt>Cancellation and refund policy</dt><dd><?=e($listingReview['terms']['cancellation_policy']?:'No additional seller policy')?></dd>
        <dt>Public URL</dt><dd><?=e((string)$reviewedProduct['product_url'])?></dd>
        <dt>Button</dt><dd><?=e((string)$reviewedProduct['cta'])?></dd>
    </dl>
    <form method="post" class="pc-form" action="<?=e(url('/profile-commerce-products.php?workspace_product='.$workspaceProduct.'#product-listing-draft'))?>">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="confirm_initiative_publication">
        <input type="hidden" name="product_id" value="<?=$workspaceProduct?>">
        <input type="hidden" name="owner_key" value="<?=$uid?>">
        <input type="hidden" name="review_token" value="<?=e($listingReview['review_token'])?>">
        <?php foreach($listingReview['input'] as $name=>$value): ?><input type="hidden" name="<?=e($name)?>" value="<?=e((string)$value)?>"><?php endforeach; ?>
        <label class="wide pc-check"><input type="checkbox" name="confirm_publish" value="1" required> Publish this listing with the details shown</label>
        <div class="wide"><button class="pc-btn primary" type="submit">Approve and publish listing</button> <a class="pc-btn" href="#product-listing-terms">Change details</a></div>
    </form>
</section>
<?php endif; ?>
<?php if($activatedListing):
    $publishedMeta=profile_commerce_metadata_v900($activatedListing);
    $publicProduct=!empty($profile['is_active'])&&!empty($profile['is_public'])&&$username!==''
        ?profile_commerce_public_product_v900($pdo,$profile,profile_commerce_public_slug_v900($activatedListing)):null;
    if((int)($publicProduct['id']??0)!==$workspaceProduct)$publicProduct=null;
    $promotionLink='';
    if($publicProduct&&($original=initiative_work_product_review_photo_v1($user,$publishedMeta))){
        $origin=['agent_id'=>$publishedMeta['agent_id']??null,'conversation_id'=>$publishedMeta['conversation_id']??null];
        try{
            initiative_work_product_scope_v1($pdo,$uid,$origin);
            $promotionLink=url('/chat.php?'.http_build_query(['agent'=>$origin['agent_id']?:'system',
                'conversation_id'=>$origin['conversation_id'],'marketing_product'=>$workspaceProduct,'marketing_source'=>(int)$original['id']]));
        }catch(DomainException $e){}
    }
?>
<section class="pc-card" id="product-listing-published">
    <span class="pc-eyebrow">Profile Commerce</span><h2><?=e($publicProduct?'Listing published':'Listing approved')?></h2>
    <h3><?=e((string)$activatedListing['title'])?></h3>
    <?php if($publicProduct): ?><a class="pc-btn" href="<?=e($publicProduct['product_url'])?>">Open public listing</a><?php endif; ?>
    <?php if($promotionLink!==''): ?><a class="pc-btn primary" href="<?=e($promotionLink)?>">Design promotion in Canvas</a><?php endif; ?>
    <?php if($publicProduct&&$promotionLink===''&&isset($publishedMeta['source_asset_id'])): ?><p class="pc-muted">The original photo or Chat is unavailable for the Canvas handoff.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php if($marketingDrafts):?>
<section class="pc-card" id="product-marketing-drafts">
    <h2>Private marketing drafts</h2>
    <?php foreach($marketingDrafts as $draft): $promotion=$promotions[$draft['draft_id']]??null;
        $chatParams=['marketing_product'=>$workspaceProduct,'marketing_source'=>(int)$draft['asset_id'],'marketing_draft'=>$draft['draft_id']];
        if($promotion)$chatParams+=['conversation_id'=>(int)$promotion['conversation_id'],
            'agent'=>(int)$promotion['agent_id']?:'system'];
    ?>
    <article class="pc-row">
        <h3><?=e($draft['title'])?></h3>
        <p><?=e($draft['description'])?></p>
        <p><?=e($draft['campaign_idea'])?></p>
        <p class="pc-muted">Selected image #<?=(int)$draft['asset_id']?> · <?=e($draft['asset_source']==='openai_image'?'AI-generated':'Saved media')?></p>
        <?php if(has_permission('chat.access',$user)): ?><a class="pc-btn" href="<?=e(url('/chat.php?'.http_build_query($chatParams)))?>">Open in Agent Chat Canvas</a><?php endif; ?>
        <?php if(($promotion['status']??'')==='published'):?>
        <form method="post" class="pc-order-actions">
            <?=csrf_field()?><input type="hidden" name="action" value="pause_promotion">
            <input type="hidden" name="promotion_id" value="<?=e($promotion['id'])?>">
            <label><input type="checkbox" name="confirm_pause" value="1" required> Confirm pausing the public promotion</label>
            <button class="pc-btn" type="submit">Pause promotion</button>
        </form>
        <?php endif; ?>
    </article>
    <?php endforeach;?>
</section>
<?php endif;?>
<div class="pc-grid"><section class="pc-card"><span class="pc-eyebrow">Create generic product</span><h2>New profile product</h2><p class="pc-muted">Use this for full-payment services, digital products, memberships, events, gifts or local-pickup products. Shipped physical products and generic deposits stay disabled until their fulfillment/balance-payment adapters are installed. Appointment deposits remain managed by Scheduling.</p><form method="post" class="pc-form"><?=csrf_field()?><input type="hidden" name="action" value="save_generic"><input type="hidden" name="payment_mode" value="full"><input type="hidden" name="deposit" value="0"><input type="hidden" name="refund_before_hours" value="0"><input type="hidden" name="cancellation_fee" value="0"><label>Title<input name="title" maxlength="190" required></label><label>Type<select name="product_type"><option value="service">Service</option><option value="digital">Digital</option><option value="membership">Membership</option><option value="event">Event</option><option value="gift">Gift</option><option value="physical">Physical / pickup</option><option value="other">Other</option></select></label><label>Fulfillment<select name="fulfillment_type"><option value="virtual">Virtual/service</option><option value="digital">Digital</option><option value="membership">Membership</option><option value="event">Event</option><option value="gift">Gift</option><option value="local_pickup">Local pickup</option><option value="other">Other/manual</option><option value="physical" disabled>Shipping — coming later</option></select></label><label>Visibility<select name="profile_visibility"><option value="hidden">Hidden</option><option value="public">Public profile</option></select></label><label class="wide">Description<textarea name="description" rows="3" maxlength="1000"></textarea></label><label>Payment<input value="Full payment" disabled></label><label>Price<input type="number" name="price" min="0.01" step="0.01" required></label><label>Currency<input name="currency" maxlength="3" value="USD"></label><label>Provider<select name="provider_mode"><option value="guest_choice">Customer choice</option><option value="fixed">Fixed provider</option></select></label><label>Fixed provider<select name="fixed_connection_id"><option value="0">None</option><?php foreach($connections as $connection):?><option value="<?=(int)$connection['id']?>"><?=e(agent_commerce_provider_label_v800((string)$connection['provider']).' · '.(string)($connection['account_label']?:$connection['external_account_id']))?></option><?php endforeach;?></select></label><label>URL slug<input name="profile_slug" maxlength="80" placeholder="consultation-package"></label><label>Button label<input name="profile_cta" maxlength="40" value="Buy"></label><label>Sort<input type="number" name="profile_sort" min="0" max="9999" value="100"></label><label><span>Featured</span><span><input type="checkbox" name="profile_featured" value="1"> Show first</span></label><label>Payment hold minutes<input type="number" name="hold_minutes" min="30" max="120" value="30"></label><label class="wide">Seller cancellation / refund policy<textarea name="cancellation_policy" rows="2" maxlength="1000" placeholder="Optional terms shown to the buyer before checkout"></textarea></label><div class="wide"><button class="pc-btn primary" type="submit">Create product</button></div></form></section>
<section class="pc-card"><span class="pc-eyebrow">Canonical products</span><h2>Profile publication</h2><p class="pc-muted">Nothing is published automatically. Existing appointment and generic products stay hidden until you explicitly expose them here.</p><?php if(!$products):?><p class="pc-muted">No Commerce products yet.</p><?php endif;?><?php foreach($products as $product):
if(initiative_work_product_draft_v1($product)):?>
<div class="pc-row"><span class="pc-tag">Private draft</span><h3><?=e((string)$product['title'])?></h3><a class="pc-btn" href="<?=e(url('/profile-commerce-products.php?workspace_product='.(int)$product['id'].'#product-listing-draft'))?>">Review draft</a></div>
<?php continue;endif;$meta=profile_commerce_metadata_v900($product);$visibility=profile_commerce_visibility_v900($product);$genericUnpayable=trim((string)($product['binding_type']??''))===''&&(string)($product['payment_mode']??'')!=='full';$physicalBlocked=(string)($product['fulfillment_type']??'')==='physical';?><div class="pc-row"><div class="pc-tags"><span class="pc-tag"><?=e((string)$product['product_type'])?></span><span class="pc-tag"><?=e((string)$product['fulfillment_type'])?></span><?php if(!empty($product['binding_type'])):?><span class="pc-tag"><?=e((string)$product['binding_type'])?></span><?php endif;?><?php if($genericUnpayable):?><span class="pc-tag">Generic deposit unavailable</span><?php endif;?><?php if($physicalBlocked):?><span class="pc-tag">Shipping unavailable</span><?php endif;?></div><h3><?=e((string)$product['title'])?></h3><p class="pc-muted"><?=e(agent_commerce_money_v800((int)$product['price_cents'],(string)$product['currency']))?> · <?=e((string)$product['payment_mode'])?> · <?=!empty($product['is_active'])?'Active':'Inactive'?></p><form method="post" class="pc-form"><?=csrf_field()?><input type="hidden" name="action" value="publish"><input type="hidden" name="product_id" value="<?=(int)$product['id']?>"><label>Profile visibility<select name="profile_visibility"><option value="hidden" <?=$visibility==='hidden'?'selected':''?>>Hidden</option><option value="public" <?=$visibility==='public'?'selected':''?> <?=($physicalBlocked||$genericUnpayable)?'disabled':''?>>Public</option></select></label><label>URL slug<input name="profile_slug" maxlength="80" value="<?=e(profile_commerce_public_slug_v900($product))?>"></label><label>Button label<input name="profile_cta" maxlength="40" value="<?=e((string)($meta['profile_cta']??''))?>"></label><label>Sort<input type="number" name="profile_sort" min="0" max="9999" value="<?=(int)($meta['profile_sort']??100)?>"></label><label><span>Featured</span><span><input type="checkbox" name="profile_featured" value="1" <?=!empty($meta['profile_featured'])?'checked':''?>> Show first</span></label><div class="wide"><button class="pc-btn" type="submit">Save profile settings</button><?php if($username!==''&&$visibility==='public'):?> <a class="pc-btn" href="<?=e(profile_commerce_product_url_v900($username,$product))?>">Open product</a><?php endif;?><?php if(!empty($meta['listing_activation_v1'])):?> <a class="pc-btn" href="<?=e(url('/profile-commerce-products.php?workspace_product='.(int)$product['id'].'#product-listing-published'))?>">Listing and promotion</a><?php endif;?></div></form></div><?php endforeach;?></section>
<section class="pc-card" id="orders">
    <span class="pc-eyebrow">Profile orders</span>
    <h2>Fulfillment + refunds</h2>
    <p class="pc-muted">These are only orders created from your public Profile Commerce products. Appointment orders stay in Appointment Commerce.</p>
    <?php if(!$profileOrders): ?><p class="pc-muted">No Profile Commerce orders yet.</p><?php endif; ?>
    <?php foreach($profileOrders as $order):
        $item=$order['items'][0]??[];
        $fulfillment=profile_commerce_fulfillment_state_v900($order);
        $paid=$order['payment_status']==='paid';
        $reviewRequired=$paid&&profile_commerce_pa5_review_required($order);
        $remainingRefund=max(0,(int)$order['amount_paid_cents']-(int)$order['amount_refunded_cents']);
    ?>
    <div class="pc-row">
        <div class="pc-order-head">
            <div>
                <div class="pc-tags">
                    <span class="pc-tag"><?= e((string)$order['payment_status']) ?></span>
                    <span class="pc-tag"><?= e($reviewRequired?'Payment review required':$fulfillment) ?></span>
                    <span class="pc-tag"><?= e((string)$order['fulfillment_type']) ?></span>
                </div>
                <h3><?= e((string)($item['title_snapshot']??$order['order_number'])) ?></h3>
                <p class="pc-muted">
                    <?= e((string)$order['order_number']) ?> · <?= e(agent_commerce_money_v800((int)$order['amount_paid_cents'],(string)$order['currency'])) ?> paid
                    <?php if((int)$order['amount_refunded_cents']>0): ?> · <?= e(agent_commerce_money_v800((int)$order['amount_refunded_cents'],(string)$order['currency'])) ?> refunded<?php endif; ?>
                    <?php if(trim((string)$order['payer_email'])!==''): ?> · <?= e((string)$order['payer_email']) ?><?php endif; ?>
                </p>
            </div>
            <small class="pc-muted"><?= e((string)$order['created_at']) ?></small>
        </div>
        <div class="pc-order-actions">
            <?php if($reviewRequired&&profile_commerce_pa5_review_releasable($order)): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release_payment_review">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <label class="pc-check"><input type="checkbox" name="confirm_payment_review" value="1" required> I reviewed the verified late payment and approve fulfillment.</label>
                    <button class="pc-btn" type="submit">Approve and start fulfillment</button>
                </form>
            <?php elseif($reviewRequired): ?>
                <p class="pc-muted">Reconcile the verified payment before fulfilling this order.</p>
            <?php elseif($paid&&$fulfillment!=='fulfilled'): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="fulfillment">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <input type="hidden" name="fulfillment_status" value="<?= $fulfillment==='processing'?'fulfilled':'processing' ?>">
                    <button class="pc-btn" type="submit"><?= $fulfillment==='processing'?'Mark fulfilled':'Start fulfillment' ?></button>
                </form>
            <?php endif; ?>
            <?php if($remainingRefund>0): ?>
                <form method="post" onsubmit="return confirm('Send this refund to the original payment provider?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refund_profile">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <input type="number" name="refund_amount" min="0.01" max="<?= e(number_format($remainingRefund/100,2,'.','')) ?>" step="0.01" value="<?= e(number_format($remainingRefund/100,2,'.','')) ?>" aria-label="Refund amount" required>
                    <input type="text" name="refund_reason" maxlength="500" placeholder="Refund reason" aria-label="Refund reason">
                    <label class="pc-check"><input type="checkbox" name="confirm_refund" value="1" required> Approve refund</label>
                    <button class="pc-btn danger" type="submit">Refund</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</section></div>
</div></section></main></div><script src="<?=e(url('/chat-shell-v82.js'))?>" defer></script></body></html>
