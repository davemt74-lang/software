<?php
declare(strict_types=1);

ob_start();
require __DIR__.'/profile.php';
$html=(string)ob_get_clean();
require_once __DIR__.'/includes/profile-commerce-v900.php';

if(!isset($pdo,$profile,$username)||!$pdo||!is_array($profile)){
    echo $html;
    return;
}

$commerceProducts=profile_commerce_products_for_profile_v900($pdo,$profile,true,40);
$commerceReturn=trim((string)($_GET['commerce']??''));
$commerceHtml='';
if($commerceReturn==='return'){
    $commerceHtml.='<div class="profile-commerce-return-v900" role="status"><strong>Payment return received.</strong><span>VP3 is verifying the provider result against the canonical Commerce order.</span></div>';
}elseif($commerceReturn==='cancelled'){
    $commerceHtml.='<div class="profile-commerce-return-v900 cancelled" role="status"><strong>Checkout cancelled.</strong><span>No purchase is reported as paid until the provider verifies it.</span></div>';
}
if($commerceProducts){
    $commerceHtml.='<section class="profile-card profile-commerce-v900" aria-labelledby="profile-commerce-title"><div class="profile-commerce-heading-v900"><div><span>Commerce</span><h2 id="profile-commerce-title">Products & services</h2><p>Buy, book or explore offers directly from '.e((string)$displayName).'’s profile.</p></div>';
    if(!empty($isOwner))$commerceHtml.='<a class="profile-commerce-manage-v900" href="'.e(url('/profile-commerce-products.php')).'">Manage products</a>';
    $commerceHtml.='</div><div class="profile-commerce-grid-v900">';
    foreach($commerceProducts as $product){
        $commerceHtml.='<article class="profile-commerce-product-v900'.(!empty($product['featured'])?' featured':'').'">';
        $commerceHtml.='<div class="profile-commerce-copy-v900"><div class="profile-commerce-tags-v900"><span>'.e(ucfirst((string)$product['product_type'])).'</span><span>'.e(ucfirst((string)$product['fulfillment_type'])).'</span></div><h3>'.e((string)$product['title']).'</h3>';
        if(trim((string)$product['description'])!=='')$commerceHtml.='<p>'.e(mb_strimwidth((string)$product['description'],0,240,'…')).'</p>';
        $commerceHtml.='</div><div class="profile-commerce-action-v900"><strong>'.e((string)$product['price_label']).'</strong><a href="'.e((string)$product['product_url']).'">'.e((string)$product['cta']).' →</a></div></article>';
    }
    $commerceHtml.='</div></section>';
}elseif(!empty($isOwner)){
    $commerceHtml.='<section class="profile-card profile-commerce-v900 profile-commerce-empty-v900"><div><span>Commerce</span><h2>Products & services</h2><p>No Commerce products are published on your profile yet.</p></div><a class="profile-commerce-manage-v900" href="'.e(url('/profile-commerce-products.php')).'">Publish products</a></section>';
}

if($commerceHtml!==''){
    $replaceCount=0;
    $marker='<div class="profile-grid">';
    if(str_contains($html,$marker))$html=str_replace($marker,$marker.$commerceHtml,$html,$replaceCount);
    else $html=str_replace('</main>',$commerceHtml.'</main>',$html,$replaceCount);
}

$css='<style id="profile-commerce-v900-css">
.profile-commerce-v900{grid-column:1/-1;margin-bottom:18px}.profile-commerce-heading-v900{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:16px}.profile-commerce-heading-v900>div>span,.profile-commerce-empty-v900>div>span{font-size:11px;text-transform:uppercase;letter-spacing:.13em;color:#777a73}.profile-commerce-heading-v900 h2,.profile-commerce-empty-v900 h2{margin:3px 0 4px}.profile-commerce-heading-v900 p,.profile-commerce-empty-v900 p{margin:0;color:#666963}.profile-commerce-manage-v900{display:inline-flex;align-items:center;padding:8px 11px;border:1px solid #d2d4cd;border-radius:10px;color:#171816;text-decoration:none;background:#fff;white-space:nowrap}.profile-commerce-grid-v900{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.profile-commerce-product-v900{border:1px solid #e2e3de;border-radius:16px;padding:17px;display:flex;justify-content:space-between;gap:18px;background:#fafaf8}.profile-commerce-product-v900.featured{border-color:#c7cabf;background:#fff}.profile-commerce-copy-v900 h3{margin:8px 0 5px;font-size:18px}.profile-commerce-copy-v900 p{margin:0;color:#696c65}.profile-commerce-tags-v900{display:flex;gap:6px;flex-wrap:wrap}.profile-commerce-tags-v900 span{font-size:11px;padding:4px 7px;border-radius:999px;background:#eeefeb}.profile-commerce-action-v900{display:flex;flex-direction:column;align-items:flex-end;justify-content:space-between;gap:12px;min-width:130px;text-align:right}.profile-commerce-action-v900 a{display:inline-flex;padding:9px 12px;border-radius:10px;background:#171816;color:#fff;text-decoration:none;font-weight:700}.profile-commerce-return-v900{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;padding:12px 14px;margin-bottom:14px;border-radius:12px;background:#eaf7ee;color:#265d38}.profile-commerce-return-v900.cancelled{background:#f0f1ed;color:#555851}.profile-commerce-empty-v900{display:flex;justify-content:space-between;align-items:center;gap:18px}@media(max-width:760px){.profile-commerce-grid-v900{grid-template-columns:1fr}.profile-commerce-product-v900{display:block}.profile-commerce-action-v900{align-items:flex-start;text-align:left;margin-top:15px}.profile-commerce-heading-v900,.profile-commerce-empty-v900{display:block}.profile-commerce-manage-v900{margin-top:12px}}
</style>';
$replaceCount=0;$html=str_replace('</head>',$css.'</head>',$html,$replaceCount);
echo $html;