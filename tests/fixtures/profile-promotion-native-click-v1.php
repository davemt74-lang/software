<?php
declare(strict_types=1);

require_once __DIR__.'/profile-conversation-checkout-pa5.php';
require_once dirname(__DIR__,2).'/includes/media-studio-v86.php';
require_once dirname(__DIR__,2).'/includes/profile-product-promotion-v1.php';
require_once dirname(__DIR__,2).'/includes/profile-promotion-native-click-v1.php';

function native_click_fixture_v1(): array
{
    $f=pa5_fixture_init();$pdo=db();$key=str_repeat('a',32);$promotion=str_repeat('b',32);$draft=str_repeat('d',64);
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_blocks (blocker_user_id INTEGER NOT NULL,blocked_user_id INTEGER NOT NULL,PRIMARY KEY(blocker_user_id,blocked_user_id))');
    $pdo->exec('CREATE TABLE user_media_assets(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,
        media_type VARCHAR(12),title VARCHAR(190),mime_type VARCHAR(30),file_path VARCHAR(250),file_size INTEGER NOT NULL,
        duration_seconds INTEGER DEFAULT 0,source VARCHAR(30),metadata_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $directory=STONEFELLOW_ROOT.'/private/user-media/1';
    if(!is_dir($directory))mkdir($directory,0700,true);
    $artwork=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZkAAAAASUVORK5CYII=',true);
    file_put_contents($directory.'/promotion.png',$artwork);
    $asset=pa5_fixture_insert('user_media_assets',['user_id'=>1,'media_type'=>'photo','title'=>'Promotion artwork','mime_type'=>'image/png',
        'file_path'=>'private/user-media/1/promotion.png','file_size'=>strlen($artwork),'source'=>'promotion_design','metadata_json'=>'{}']);
    pa5_fixture_insert('agent_commerce_marketing_drafts_v1',['product_id'=>$f['product'],'owner_user_id'=>1,'request_key'=>$draft,'draft_json'=>'{}']);
    $row=['id'=>$promotion,'product_id'=>$f['product'],'owner_user_id'=>1,'draft_key'=>$draft,'agent_id'=>7,'conversation_id'=>11,
        'source_asset_id'=>$asset+1,'source_sha256'=>hash('sha256','source photo'),'settings_json'=>'{"headline":"Field guide","copy":"A useful guide.","level":1}',
        'asset_id'=>$asset,'asset_sha256'=>hash('sha256',$artwork),'public_key'=>$key,
        'public_product_url'=>'/fixture-owner/product/field-guide','public_cta'=>'View product','status'=>'published'];
    $columns=array_keys($row);$pdo->prepare('INSERT INTO agent_commerce_promotions_v1 ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
    agent_commerce_atomic_pa2($pdo,static fn()=>profile_promotion_attribution_register_v1($pdo,$row));
    $ctx=pa3_context(2,'b');
    // The real public constructor allocates 11. Private Chat 11 already exists;
    // private Chat 12 exists without any corresponding public conversation.
    $pdo->prepare('INSERT INTO profile_agent_conversations (id,owner_user_id,profile_agent_id,profile_session_id,visitor_user_id) VALUES (10,1,7,?,2)')->execute([(int)$ctx['session_id']]);
    $started=vp3_profile_agent_public_start_v1($pdo,$ctx);
    $cid=(int)$started['conversation']['conversation_id'];
    $pdo->exec('DELETE FROM profile_agent_conversations WHERE id=10');
    return $f+compact('key','promotion','cid');
}

function native_click_fixture_query_v1(string $key,int $cid,int $priorBuckets=0,string $placement='profile_agent'): array
{
    // Keep fixture issuances stable when MySQL lock tests cross a real 15-minute
    // boundary. Relative buckets must stay distinct from the first accepted link.
    static $issuances=[];
    parse_str((string)parse_url((string)profile_product_promotion_click_url_v1(['key'=>$key],$placement,$cid),PHP_URL_QUERY),$query);
    $binding=$key.':'.$placement.':'.$cid;
    $issuances[$binding]??=(int)$query['expires'];
    $query['expires']=(string)($issuances[$binding]-$priorBuckets*VP3_PROMOTION_CLICK_LINK_BUCKET_V1);
    $query['proof']=hash_hmac('sha256',profile_product_promotion_click_payload_v1($key,$placement,$cid,(int)$query['expires']),$_SESSION['vp3_promotion_click_secret_v1']);
    return $query;
}
