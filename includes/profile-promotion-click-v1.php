<?php
declare(strict_types=1);

const VP3_PROMOTION_CLICK_LINK_TTL_V1=43200;
const VP3_PROMOTION_CLICK_WINDOW_V1=86400;
const VP3_PROMOTION_CLICK_LINK_BUCKET_V1=900;

function profile_product_promotion_click_payload_v1(string $key,string $placement,int $conversationId,int $expires): string
{
    return 'promotion-link:'.$key.':'.$placement.':'.$conversationId.':'.$expires;
}

/** Attach the placement to a link issued in the visitor's existing browser session. */
function profile_product_promotion_click_url_v1(array $card,string $placement,int $conversationId=0): ?string
{
    if (session_status()!==PHP_SESSION_ACTIVE) return null;
    $key = (string)$card['key'];
    $secret = $_SESSION['vp3_promotion_click_secret_v1'] ??= bin2hex(random_bytes(32));
    $expires = intdiv(time(),VP3_PROMOTION_CLICK_LINK_BUCKET_V1)*VP3_PROMOTION_CLICK_LINK_BUCKET_V1
        + VP3_PROMOTION_CLICK_LINK_TTL_V1;
    $proof = hash_hmac('sha256',profile_product_promotion_click_payload_v1($key,$placement,$conversationId,$expires),$secret);
    return url('/profile-promotion-click.php?'.http_build_query([
        'key'=>$key,'placement'=>$placement,'conversation'=>$conversationId,'expires'=>$expires,'proof'=>$proof,
    ],'', '&',PHP_QUERY_RFC3986));
}

function profile_product_promotion_click_claim_v1(string $key,array $query): ?array
{
    $placement = $query['placement'] ?? null;
    $rawConversation = $query['conversation'] ?? null;
    $rawExpires = $query['expires'] ?? null;
    $proof = $query['proof'] ?? null;
    $secret = $_SESSION['vp3_promotion_click_secret_v1'] ?? null;
    if (!in_array($placement,['profile','profile_agent'],true) || !is_string($rawConversation)
        || !is_string($rawExpires) || !is_string($proof) || !preg_match('/\A[a-f0-9]{64}\z/D',$proof)
        || !is_string($secret)) return null;
    $cid = (int)$rawConversation;
    $expires = (int)$rawExpires;
    $now = time();
    if ((string)$cid!==$rawConversation || ($placement==='profile' ? $cid!==0 : $cid<1)
        || (string)$expires!==$rawExpires || $expires<$now || $expires>$now+VP3_PROMOTION_CLICK_LINK_TTL_V1
        || !hash_equals(hash_hmac('sha256',profile_product_promotion_click_payload_v1($key,$placement,$cid,$expires),$secret),$proof)) return null;

    // Links issued in the same short window share one opaque click ID. A
    // retry stays bound to the original issuance even across a bucket boundary.
    $click = hash_hmac('sha256','promotion-click:'.$key.':'.$placement.':'.$cid.':'.$expires,$secret);
    return ['placement'=>$placement,'conversation_id'=>$cid,'click_id'=>substr($click,0,32)];
}

function profile_product_promotion_click_remember_v1(string $clickId,int $owner,int $product,int $conversationId): void
{
    $clicks = $_SESSION['vp3_promotion_clicks_v1'] ?? [];
    $now = time();
    $clicks = array_filter($clicks,static fn(array $click): bool => $click['expires']>=$now);
    $expires = $clicks[$clickId]['expires'] ?? $now+VP3_PROMOTION_CLICK_WINDOW_V1;
    unset($clicks[$clickId]);
    $clicks[$clickId] = ['owner'=>$owner,'product'=>$product,'conversation'=>$conversationId,'expires'=>$expires];
    $_SESSION['vp3_promotion_clicks_v1'] = array_slice($clicks,-64,null,true);
}

/** Return one exact per-tab click or the latest click in the current visitor Chat. */
function profile_product_promotion_attribution_click_v1(int $owner,int $product,mixed $token=null,?int $conversationId=null): ?string
{
    if ($owner<1 || $product<1 || ($token===null && ($conversationId===null || $conversationId<1))) return null;
    $clicks = $_SESSION['vp3_promotion_clicks_v1'] ?? [];
    if ($token!==null) {
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/D',$token)) return null;
        $clicks = isset($clicks[$token]) ? [$token=>$clicks[$token]] : [];
    } else {
        $clicks = array_reverse($clicks,true);
    }
    $now = time();
    foreach ($clicks as $id=>$click) {
        if ($click['owner']===$owner && $click['product']===$product && $click['expires']>=$now
            && ($conversationId===null || $click['conversation']===$conversationId)) return $id;
    }
    return null;
}
