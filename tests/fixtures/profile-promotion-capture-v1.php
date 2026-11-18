<?php
declare(strict_types=1);

// Real native Profile and Commerce stores; PA5 replaces only provider transport.
require_once __DIR__.'/profile-conversation-checkout-pa5.php';
require_once dirname(__DIR__,2).'/includes/media-studio-v86.php';
require_once dirname(__DIR__,2).'/includes/profile-product-promotion-v1.php';
require_once dirname(__DIR__,2).'/includes/profile-promotion-click-v1.php';

function pcap_fixture_init_v1(?callable $reset=null): array
{
    $f=pa5_fixture_init($reset);
    $p=db();
    $p->exec('CREATE TABLE IF NOT EXISTS user_blocks (blocker_user_id INTEGER NOT NULL,blocked_user_id INTEGER NOT NULL,PRIMARY KEY(blocker_user_id,blocked_user_id))');
    $p->exec('CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER,media_type VARCHAR(12),title VARCHAR(190),mime_type VARCHAR(40),file_path VARCHAR(300),file_size INTEGER,duration_seconds INTEGER DEFAULT 0,source VARCHAR(40),metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $directory=STONEFELLOW_ROOT.'/private/user-media/1';
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Fixture artwork directory is unavailable.');
    $art=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZkAAAAASUVORK5CYII=',true);
    foreach([51=>'original',52=>'promotion'] as $id=>$name){
        $path='private/user-media/1/'.$name.'.png';file_put_contents(STONEFELLOW_ROOT.'/'.$path,$art);
        pa5_fixture_insert('user_media_assets',['id'=>$id,'user_id'=>1,'media_type'=>'photo','title'=>$name,'mime_type'=>'image/png',
            'file_path'=>$path,'file_size'=>strlen($art),'source'=>$id===51?'upload':'promotion_design','metadata_json'=>'{}']);
    }
    $f+=['publication_id'=>str_repeat('a',32),'promotion_id'=>str_repeat('b',32),'draft_key'=>str_repeat('c',64)];
    pa5_fixture_insert('agent_commerce_marketing_drafts_v1',['product_id'=>$f['product'],'owner_user_id'=>1,'request_key'=>$f['draft_key'],'draft_json'=>'{}']);
    // Original PRIVATE work is Agent 8 / Chat 12; the public responder is Agent 7.
    pa5_fixture_insert('agent_commerce_promotions_v1',[
        'id'=>$f['promotion_id'],'product_id'=>$f['product'],'owner_user_id'=>1,'draft_key'=>$f['draft_key'],'agent_id'=>8,'conversation_id'=>12,
        'source_asset_id'=>51,'source_sha256'=>hash('sha256',$art),'settings_json'=>'{"headline":"Field guide","copy":"An illustrated local field guide.","level":1}',
        'asset_id'=>52,'asset_sha256'=>hash('sha256',$art),'public_key'=>$f['publication_id'],
        'public_product_url'=>'/fixture-owner/product/field-guide','public_cta'=>'View product','status'=>'published',
    ]);
    agent_commerce_atomic_pa2($p,static function()use($p,$f):void{
        $q=$p->prepare('SELECT * FROM agent_commerce_promotions_v1 WHERE id=?');$q->execute([$f['promotion_id']]);
        profile_promotion_attribution_register_v1($p,$q->fetch());
    });
    return $f;
}

function pcap_fixture_native_v1(int $visitor=2,string $browser='b'): array
{
    $ctx=pa3_context($visitor,$browser);
    $state=vp3_profile_agent_public_start_v1(db(),$ctx);
    return ['context'=>$ctx,'public_conversation_id'=>(int)$state['conversation']['conversation_id'],'cookies'=>$_COOKIE,'visitor'=>$visitor];
}

function pcap_fixture_restore_browser_v1(array $native): void
{
    pa3_login($native['visitor']);$_COOKIE=$native['cookies'];
}

function pcap_fixture_click_v1(string $id): ?array
{
    $q=db()->prepare('SELECT * FROM agent_promotion_clicks_v1 WHERE click_id=?');$q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function pcap_fixture_observed_v1(): array
{
    $out=[];
    foreach(['profile_visit_sessions','profile_agent_conversations','profile_agent_messages','profile_events','notifications','agent_attention_items','agent_promotion_clicks_v1','agent_commerce_orders_v800','agent_commerce_checkout_attempts_v800'] as $table){
        $rows=db()->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        $out[$table]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
    }
    return $out;
}
