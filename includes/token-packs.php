<?php
declare(strict_types=1);

const VP3_TOKEN_PACK_BUILD='ai-token-packs-20260906';

function token_pack_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();return (bool)$pdo&&table_exists('ai_token_packs')&&table_exists('ai_token_pack_purchases');
}

function token_pack_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!subscription_schema_ready($pdo)||!billing_schema_ready($pdo))throw new RuntimeException('Install subscription and billing storage before token packs.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_token_packs (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(80) NOT NULL,
      name VARCHAR(120) NOT NULL,
      description VARCHAR(500) NOT NULL DEFAULT '',
      token_amount BIGINT UNSIGNED NOT NULL,
      price_cents INT UNSIGNED NOT NULL,
      expires_days SMALLINT UNSIGNED NULL,
      is_public TINYINT(1) NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 100,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_ai_token_pack_slug (slug),
      INDEX idx_ai_token_pack_public (is_active,is_public,sort_order,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_token_pack_purchases (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      token_pack_id INT UNSIGNED NULL,
      pack_name_snapshot VARCHAR(120) NOT NULL,
      token_amount BIGINT UNSIGNED NOT NULL,
      price_cents INT UNSIGNED NOT NULL,
      currency CHAR(3) NOT NULL DEFAULT 'usd',
      expires_days SMALLINT UNSIGNED NULL,
      provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
      provider_session_id VARCHAR(160) NOT NULL DEFAULT '',
      provider_payment_intent_id VARCHAR(160) NOT NULL DEFAULT '',
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      credit_id BIGINT UNSIGNED NULL,
      credited_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_ai_token_purchase_session (provider,provider_session_id),
      INDEX idx_ai_token_purchase_user (user_id,status,created_at,id),
      INDEX idx_ai_token_purchase_pack (token_pack_id,status,id),
      CONSTRAINT fk_ai_token_purchase_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ai_token_purchase_pack FOREIGN KEY (token_pack_id) REFERENCES ai_token_packs(id) ON DELETE SET NULL,
      CONSTRAINT fk_ai_token_purchase_credit FOREIGN KEY (credit_id) REFERENCES ai_token_credits(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function token_pack_slug(string $value): string
{
    $value=strtolower(trim($value));$value=preg_replace('/[^a-z0-9]+/','-',$value)??'';return trim(substr($value,0,80),'-');
}

function token_pack_public(?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!token_pack_schema_ready($pdo))return [];
    return $pdo->query('SELECT * FROM ai_token_packs WHERE is_active=1 AND is_public=1 ORDER BY sort_order,name,id')->fetchAll()?:[];
}

function token_pack_find(int $packId,?PDO $pdo=null,bool $publicOnly=false,bool $forUpdate=false): ?array
{
    $pdo??=db();if(!$pdo||$packId<1||!token_pack_schema_ready($pdo))return null;
    $sql='SELECT * FROM ai_token_packs WHERE id=?'.($publicOnly?' AND is_active=1 AND is_public=1':'').' LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$packId]);$row=$stmt->fetch();return $row?:null;
}

function token_pack_save(array $input): int
{
    $pdo=db();if(!$pdo||!token_pack_schema_ready($pdo))throw new RuntimeException('Token pack storage is unavailable.');
    $id=max(0,(int)($input['id']??0));$name=mb_strimwidth(trim((string)($input['name']??'')),0,120,'');if($name==='')throw new RuntimeException('Token pack name is required.');
    $slug=token_pack_slug((string)($input['slug']??$name));if($slug==='')throw new RuntimeException('Token pack slug is required.');
    $tokens=max(0,(int)($input['token_amount']??0));if($tokens<1000)throw new RuntimeException('Token packs must include at least 1,000 tokens.');
    $price=max(0,(int)($input['price_cents']??0));if($price<50)throw new RuntimeException('Token pack price must be at least $0.50.');
    $expires=trim((string)($input['expires_days']??''));$expires=$expires===''?null:max(1,min(3650,(int)$expires));$description=mb_strimwidth(trim((string)($input['description']??'')),0,500,'');$active=!empty($input['is_active'])?1:0;$public=!empty($input['is_public'])?1:0;$sort=(int)($input['sort_order']??100);
    $pdo->beginTransaction();
    try{
        if($id>0){$stmt=$pdo->prepare('UPDATE ai_token_packs SET slug=?,name=?,description=?,token_amount=?,price_cents=?,expires_days=?,is_public=?,is_active=?,sort_order=?,updated_at=NOW() WHERE id=?');$stmt->execute([$slug,$name,$description,$tokens,$price,$expires,$public,$active,$sort,$id]);if(!$stmt->rowCount()&&!token_pack_find($id,$pdo))throw new RuntimeException('Token pack not found.');}
        else{$stmt=$pdo->prepare('INSERT INTO ai_token_packs (slug,name,description,token_amount,price_cents,expires_days,is_public,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->execute([$slug,$name,$description,$tokens,$price,$expires,$public,$active,$sort]);$id=(int)$pdo->lastInsertId();}
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function token_pack_purchase_by_id(int $purchaseId,int $userId=0,?PDO $pdo=null,bool $forUpdate=false): ?array
{
    $pdo??=db();if(!$pdo||$purchaseId<1||!token_pack_schema_ready($pdo))return null;
    $sql='SELECT * FROM ai_token_pack_purchases WHERE id=?'.($userId>0?' AND user_id=?':'').' LIMIT 1'.($forUpdate?' FOR UPDATE':'');$stmt=$pdo->prepare($sql);$stmt->execute($userId>0?[$purchaseId,$userId]:[$purchaseId]);$row=$stmt->fetch();return $row?:null;
}

function token_pack_purchase_by_session(string $sessionId,?PDO $pdo=null,bool $forUpdate=false): ?array
{
    $pdo??=db();$sessionId=trim($sessionId);if(!$pdo||$sessionId===''||!token_pack_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT * FROM ai_token_pack_purchases WHERE provider='stripe' AND provider_session_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));$stmt->execute([$sessionId]);$row=$stmt->fetch();return $row?:null;
}

function token_pack_open_purchase(int $userId,int $packId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$userId<1||$packId<1)return null;
    $stmt=$pdo->prepare("SELECT p.*,b.checkout_url,b.expires_at checkout_expires_at FROM ai_token_pack_purchases p LEFT JOIN billing_checkout_sessions b ON b.provider='stripe' AND b.provider_session_id=p.provider_session_id WHERE p.user_id=? AND p.token_pack_id=? AND p.status='pending' AND p.created_at>=DATE_SUB(NOW(),INTERVAL 30 MINUTE) ORDER BY p.id DESC LIMIT 1");$stmt->execute([$userId,$packId]);$row=$stmt->fetch();return $row?:null;
}

function token_pack_begin_purchase(array $user,int $packId): array
{
    $pdo=db();$userId=(int)($user['id']??0);if(!$pdo||$userId<1)throw new RuntimeException('Sign in to buy AI tokens.');
    if(!token_pack_schema_ready($pdo)||!billing_schema_ready($pdo)||!billing_stripe_configured()||billing_stripe_webhook_secret()==='')throw new RuntimeException('AI token checkout is not available yet.');
    $existing=token_pack_open_purchase($userId,$packId,$pdo);
    if($existing&&trim((string)($existing['checkout_url']??''))!==''&&(!$existing['checkout_expires_at']||strtotime((string)$existing['checkout_expires_at'])>time()))return ['purchase_id'=>(int)$existing['id'],'url'=>(string)$existing['checkout_url'],'reused'=>true];

    $pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$userId]);if(!$lock->fetchColumn())throw new RuntimeException('Account no longer exists.');
        $pack=token_pack_find($packId,$pdo,true,true);if(!$pack)throw new RuntimeException('That AI token pack is not available.');
        $stmt=$pdo->prepare("INSERT INTO ai_token_pack_purchases (user_id,token_pack_id,pack_name_snapshot,token_amount,price_cents,currency,expires_days,provider,status) VALUES (?,?,?,?,?,? ,?,'stripe','pending')");
        $stmt->execute([$userId,$packId,(string)$pack['name'],(int)$pack['token_amount'],(int)$pack['price_cents'],billing_currency(),$pack['expires_days']]);$purchaseId=(int)$pdo->lastInsertId();
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    try{
        $customerId=billing_stripe_ensure_customer($user,$pdo);$purchase=token_pack_purchase_by_id($purchaseId,$userId,$pdo)??throw new RuntimeException('Token purchase could not be loaded.');
        $metadata=['vp3_purchase_type'=>'ai_token_pack','vp3_token_purchase_id'=>(string)$purchaseId,'vp3_token_pack_id'=>(string)$packId,'vp3_user_id'=>(string)$userId];
        $session=billing_stripe_request('POST','checkout/sessions',[
            'mode'=>'payment','customer'=>$customerId,'client_reference_id'=>(string)$userId,
            'line_items'=>[['price_data'=>['currency'=>(string)$purchase['currency'],'unit_amount'=>(int)$purchase['price_cents'],'product_data'=>['name'=>(string)$purchase['pack_name_snapshot'],'description'=>number_format((int)$purchase['token_amount']).' VP3 AI tokens','metadata'=>['vp3_token_pack_id'=>(string)$packId]]],'quantity'=>1]],
            'success_url'=>billing_absolute_url('/token-packs.php?checkout=success&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url'=>billing_absolute_url('/token-packs.php?checkout=cancelled'),
            'metadata'=>$metadata,'payment_intent_data'=>['metadata'=>$metadata],
        ],'vp3-token-pack-'.$purchaseId);
        $sessionId=trim((string)($session['id']??''));$url=trim((string)($session['url']??''));if($sessionId===''||$url==='')throw new RuntimeException('Stripe did not return a token checkout session.');
        $expires=(int)($session['expires_at']??0);$expiresAt=$expires>0?gmdate('Y-m-d H:i:s',$expires):null;
        $pdo->beginTransaction();
        try{
            $pdo->prepare("UPDATE ai_token_pack_purchases SET provider_session_id=?,updated_at=NOW() WHERE id=? AND user_id=? AND status='pending'")->execute([$sessionId,$purchaseId,$userId]);
            $stmt=$pdo->prepare("INSERT INTO billing_checkout_sessions (user_id,plan_request_id,package_id,provider,session_type,provider_session_id,provider_customer_id,billing_interval,amount_cents,status,checkout_url,expires_at,metadata_json) VALUES (?,NULL,NULL,'stripe','token_pack',?,?, 'one_time',?,'open',?,?,?)");
            $stmt->execute([$userId,$sessionId,$customerId,(int)$purchase['price_cents'],$url,$expiresAt,json_encode(['purchase_id'=>$purchaseId,'token_pack_id'=>$packId,'token_amount'=>(int)$purchase['token_amount']],JSON_UNESCAPED_SLASHES)]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return ['purchase_id'=>$purchaseId,'url'=>$url,'session_id'=>$sessionId,'reused'=>false];
    }catch(Throwable $e){$pdo->prepare("UPDATE ai_token_pack_purchases SET status='failed',updated_at=NOW() WHERE id=? AND status='pending'")->execute([$purchaseId]);throw $e;}
}

function token_pack_process_checkout_session(array $session,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!token_pack_schema_ready($pdo))throw new RuntimeException('Token purchase storage is unavailable.');
    $meta=is_array($session['metadata']??null)?$session['metadata']:[];if((string)($meta['vp3_purchase_type']??'')!=='ai_token_pack')return ['handled'=>false];
    $purchaseId=(int)($meta['vp3_token_purchase_id']??0);$userId=(int)($meta['vp3_user_id']??$session['client_reference_id']??0);$sessionId=trim((string)($session['id']??''));
    if($purchaseId<1||$userId<1||$sessionId==='')throw new RuntimeException('Stripe token purchase identity is incomplete.');
    if((string)($session['status']??'')!=='complete'||(string)($session['payment_status']??'')!=='paid')return ['handled'=>true,'state'=>'payment_pending','purchase_id'=>$purchaseId];
    $amount=(int)($session['amount_total']??-1);$currency=strtolower((string)($session['currency']??''));$paymentIntent=$session['payment_intent']??'';if(is_array($paymentIntent))$paymentIntent=$paymentIntent['id']??'';
    $pdo->beginTransaction();
    try{
        $userLock=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$userLock->execute([$userId]);if(!$userLock->fetchColumn())throw new RuntimeException('Token purchase account no longer exists.');
        $purchase=token_pack_purchase_by_id($purchaseId,$userId,$pdo,true);if(!$purchase)throw new RuntimeException('Token purchase was not found.');
        if((string)$purchase['provider_session_id']!==''&&!hash_equals((string)$purchase['provider_session_id'],$sessionId))throw new RuntimeException('Stripe token purchase session does not match VP3.');
        if((int)$purchase['price_cents']!==$amount||strtolower((string)$purchase['currency'])!==$currency)throw new RuntimeException('Stripe token purchase total does not match the VP3 purchase snapshot.');
        if((string)$purchase['status']==='credited'&&(int)$purchase['credit_id']>0){$pdo->commit();return ['handled'=>true,'state'=>'credited','purchase_id'=>$purchaseId,'credit_id'=>(int)$purchase['credit_id'],'duplicate'=>true];}
        if(!in_array((string)$purchase['status'],['pending','paid'],true))throw new RuntimeException('This token purchase is no longer creditable.');
        $expiresAt=null;if((int)($purchase['expires_days']??0)>0)$expiresAt=(new DateTimeImmutable('now'))->modify('+'.(int)$purchase['expires_days'].' days')->format('Y-m-d H:i:s');
        $creditId=subscription_add_token_credit($userId,(int)$purchase['token_amount'],'purchased_topup','Purchased '.$purchase['pack_name_snapshot'].' AI token pack.',$expiresAt,null);
        $pdo->prepare("UPDATE ai_token_pack_purchases SET status='credited',provider_session_id=?,provider_payment_intent_id=?,credit_id=?,credited_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$sessionId,trim((string)$paymentIntent),$creditId,$purchaseId]);
        $pdo->prepare("UPDATE billing_checkout_sessions SET status='completed',updated_at=NOW() WHERE provider='stripe' AND provider_session_id=? AND session_type='token_pack'")->execute([$sessionId]);
        $pdo->commit();return ['handled'=>true,'state'=>'credited','purchase_id'=>$purchaseId,'credit_id'=>$creditId,'tokens'=>(int)$purchase['token_amount']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function token_pack_expire_checkout_session(array $session,?PDO $pdo=null): array
{
    $pdo??=db();$meta=is_array($session['metadata']??null)?$session['metadata']:[];if((string)($meta['vp3_purchase_type']??'')!=='ai_token_pack')return ['handled'=>false];
    $purchaseId=(int)($meta['vp3_token_purchase_id']??0);$sessionId=trim((string)($session['id']??''));
    if($purchaseId>0)$pdo->prepare("UPDATE ai_token_pack_purchases SET status='expired',updated_at=NOW() WHERE id=? AND status='pending'")->execute([$purchaseId]);
    if($sessionId!=='')$pdo->prepare("UPDATE billing_checkout_sessions SET status='expired',updated_at=NOW() WHERE provider='stripe' AND provider_session_id=? AND session_type='token_pack'")->execute([$sessionId]);
    return ['handled'=>true,'state'=>'expired','purchase_id'=>$purchaseId];
}

function token_pack_is_stripe_event(array $event): bool
{
    $type=(string)($event['type']??'');if(!in_array($type,['checkout.session.completed','checkout.session.expired'],true))return false;
    $object=$event['data']['object']??null;return is_array($object)&&((string)($object['metadata']['vp3_purchase_type']??'')==='ai_token_pack');
}

function token_pack_process_stripe_event(array $event,string $payload,?PDO $pdo=null): array
{
    $pdo??=db();$eventId=trim((string)($event['id']??''));if(!$pdo)throw new RuntimeException('Database unavailable.');
    if(!billing_webhook_begin($event,$payload,$pdo))return ['duplicate'=>true,'event_id'=>$eventId];
    try{
        $type=(string)$event['type'];$object=$event['data']['object']??null;if(!is_array($object))throw new RuntimeException('Stripe token purchase event is missing its Checkout object.');
        $result=$type==='checkout.session.completed'?token_pack_process_checkout_session($object,$pdo):token_pack_expire_checkout_session($object,$pdo);
        billing_webhook_finish($eventId,'processed','',$pdo);return $result;
    }catch(Throwable $e){billing_webhook_finish($eventId,'failed',$e->getMessage(),$pdo);throw $e;}
}

function token_pack_reconcile_return(array $user,string $sessionId): ?array
{
    $pdo=db();$userId=(int)($user['id']??0);$sessionId=trim($sessionId);if(!$pdo||$userId<1||$sessionId===''||!token_pack_schema_ready($pdo)||!billing_stripe_configured())return null;
    $purchase=token_pack_purchase_by_session($sessionId,$pdo);if(!$purchase||(int)$purchase['user_id']!==$userId)return null;
    $session=billing_stripe_checkout_session($sessionId);$result=token_pack_process_checkout_session($session,$pdo);return !empty($result['handled'])?$result:null;
}

function token_pack_purchase_history(int $userId,int $limit=20,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||$userId<1||!token_pack_schema_ready($pdo))return [];$limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare('SELECT * FROM ai_token_pack_purchases WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);$stmt->execute([$userId]);return $stmt->fetchAll()?:[];
}
