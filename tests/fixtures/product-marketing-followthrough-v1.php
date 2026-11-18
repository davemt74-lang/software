<?php
declare(strict_types=1);

require_once __DIR__.'/profile-analytics-period-data-v1.php';

/** Additive real SQL fixture; callers may reuse their own native production harness. */
function product_marketing_followthrough_fixture_v1(PDO $pdo,int $owner=1,int $agent=7,int $chat=11): array
{
    $base=profile_analytics_period_fixture_v1($pdo,$owner,$agent,$chat);
    $schemas=[
        'agent_commerce_products_v800'=>['id'=>'BIGINT PRIMARY KEY','description'=>"TEXT NULL",'metadata_json'=>'TEXT NULL','is_active'=>'INTEGER NOT NULL DEFAULT 1','product_type'=>"VARCHAR(24) NOT NULL DEFAULT 'digital'",'fulfillment_type'=>"VARCHAR(24) NOT NULL DEFAULT 'digital'",'payment_mode'=>"VARCHAR(24) NOT NULL DEFAULT 'full'"],
        'agent_commerce_product_bindings_v800'=>['product_id'=>'BIGINT PRIMARY KEY','binding_type'=>'VARCHAR(40)','binding_id'=>'BIGINT NULL'],
        'agent_product_launches_v1'=>['product_id'=>'BIGINT PRIMARY KEY','owner_user_id'=>'BIGINT NOT NULL','agent_id'=>'BIGINT NOT NULL','conversation_id'=>'BIGINT NOT NULL','draft_key'=>'CHAR(64) NULL','creative_id'=>'CHAR(32) NULL','last_stage'=>"VARCHAR(40) NOT NULL DEFAULT ''",'last_notice_key'=>"VARCHAR(64) NOT NULL DEFAULT ''",'next_check_at'=>'DATETIME NULL'],
        'agent_commerce_marketing_drafts_v1'=>['request_key'=>'CHAR(64) PRIMARY KEY','owner_user_id'=>'BIGINT NOT NULL','product_id'=>'BIGINT NOT NULL','draft_json'=>'TEXT NOT NULL','created_at'=>'DATETIME NULL'],
        'agent_commerce_promotions_v1'=>['id'=>'CHAR(32) PRIMARY KEY','owner_user_id'=>'BIGINT NOT NULL','product_id'=>'BIGINT NOT NULL','agent_id'=>'BIGINT NOT NULL','conversation_id'=>'BIGINT NOT NULL','source_asset_id'=>'BIGINT NOT NULL','source_sha256'=>'CHAR(64) NOT NULL','draft_key'=>'CHAR(64) NOT NULL','asset_id'=>'BIGINT NOT NULL','asset_sha256'=>'CHAR(64) NOT NULL','status'=>'VARCHAR(24) NOT NULL','public_key'=>'CHAR(32) NULL','settings_json'=>'TEXT NOT NULL','publication_revision'=>'INTEGER NOT NULL DEFAULT 1','created_at'=>'DATETIME NULL','updated_at'=>'DATETIME NULL'],
        'agent_promotion_publications_v1'=>['publication_id'=>'CHAR(32) PRIMARY KEY','promotion_id'=>'CHAR(32) NOT NULL','owner_user_id'=>'BIGINT NOT NULL','product_id'=>'BIGINT NOT NULL','agent_id'=>'BIGINT NOT NULL','conversation_id'=>'BIGINT NOT NULL','source_asset_id'=>'BIGINT NOT NULL','draft_key'=>'CHAR(64) NOT NULL','created_at'=>'DATETIME NOT NULL','next_review_at'=>'DATETIME NULL','last_review_fingerprint'=>"VARCHAR(64) NOT NULL DEFAULT ''"],
        'agent_promotion_clicks_v1'=>['click_id'=>'CHAR(32) PRIMARY KEY','publication_id'=>'CHAR(32) NOT NULL','placement'=>'VARCHAR(32) NOT NULL','created_at'=>'DATETIME NOT NULL','native_context_json_v1'=>'TEXT NULL'],
        'agent_promotion_orders_v1'=>['order_id'=>'BIGINT PRIMARY KEY','click_id'=>'CHAR(32) NOT NULL'],
        'agent_commerce_checkout_attempts_v800'=>['id'=>'BIGINT PRIMARY KEY','opened_at_utc_ms'=>'BIGINT NULL','checkout_url'=>"VARCHAR(255) NOT NULL DEFAULT ''"],
        'user_media_assets'=>['id'=>'BIGINT PRIMARY KEY','user_id'=>'BIGINT NOT NULL','title'=>'VARCHAR(190) NOT NULL','media_type'=>'VARCHAR(20) NOT NULL','mime_type'=>'VARCHAR(40) NOT NULL','file_path'=>'VARCHAR(255) NOT NULL','file_size'=>'BIGINT NOT NULL','source'=>'VARCHAR(40) NOT NULL','duration_seconds'=>'INTEGER NOT NULL DEFAULT 0','metadata_json'=>'TEXT NULL','created_at'=>'DATETIME NULL','updated_at'=>'DATETIME NULL'],
        'chat_messages'=>['id'=>'BIGINT PRIMARY KEY','conversation_id'=>'BIGINT NOT NULL','user_id'=>'BIGINT NULL','role'=>'VARCHAR(20) NOT NULL','message'=>'TEXT NULL','context_json'=>'TEXT NULL','created_at'=>'DATETIME NULL'],
    ];
    foreach($schemas as $table=>$columns)profile_analytics_period_fixture_table_v1($pdo,$table,$columns);
    if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-product-marketing-'.bin2hex(random_bytes(6)));
    $root=STONEFELLOW_ROOT.'/private/user-media/'.$owner;if(!is_dir($root))mkdir($root,0700,true);
    $chunk=static fn(string $t,string $b):string=>pack('N',strlen($b)).$t.$b.pack('N',crc32($t.$b));
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',32,32,8,6,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\x30\x60\xa0\xff",32),32))).$chunk('IEND','');
    $hash=hash('sha256',$png);$json=static fn(array $a):string=>json_encode($a,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    foreach([997601,997602] as $asset){$path='private/user-media/'.$owner.'/phase6-'.$asset.'.png';file_put_contents(STONEFELLOW_ROOT.'/'.$path,$png);
        profile_analytics_period_fixture_row_v1($pdo,'user_media_assets',['id'=>$asset,'user_id'=>$owner,'title'=>'Saved product image','media_type'=>'photo','mime_type'=>'image/png','file_path'=>$path,'file_size'=>strlen($png),'source'=>'upload','metadata_json'=>'{}','created_at'=>'2026-11-10 00:00:00','updated_at'=>'2026-11-10 00:00:00']);}
    $publications=[str_repeat('a',32),str_repeat('b',32)];
    foreach([[992101,$publications[0],str_repeat('1',32),str_repeat('d',64)],[992102,$publications[1],str_repeat('2',32),str_repeat('e',64)]] as [$pid,$pub,$creative,$draft]){
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_products_v800',['id'=>$pid,'description'=>'Exact saved product','metadata_json'=>$json(['listing_draft'=>'product_photo_v1','profile_visibility'=>'hidden','agent_id'=>$agent,'conversation_id'=>$chat,'source_asset_id'=>997601,'source_content_sha256'=>$hash])]);
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_marketing_drafts_v1',['request_key'=>$draft,'owner_user_id'=>$owner,'product_id'=>$pid,'draft_json'=>$json(['product_id'=>$pid,'asset_id'=>997601,'asset_sha256'=>$hash,'title'=>'Exact saved draft']),'created_at'=>'2026-11-10 00:00:00'],'request_key');
        profile_analytics_period_fixture_row_v1($pdo,'agent_commerce_promotions_v1',['id'=>$creative,'owner_user_id'=>$owner,'product_id'=>$pid,'agent_id'=>$agent,'conversation_id'=>$chat,'source_asset_id'=>997601,'source_sha256'=>$hash,'draft_key'=>$draft,'asset_id'=>997602,'asset_sha256'=>$hash,'status'=>'published','public_key'=>$pub,'settings_json'=>$json(['headline'=>'Exact promotion','copy'=>'Saved copy']),'publication_revision'=>1]);
        profile_analytics_period_fixture_row_v1($pdo,'agent_product_launches_v1',['product_id'=>$pid,'owner_user_id'=>$owner,'agent_id'=>$agent,'conversation_id'=>$chat,'draft_key'=>$draft,'creative_id'=>$creative],'product_id');
        profile_analytics_period_fixture_row_v1($pdo,'agent_promotion_publications_v1',['publication_id'=>$pub,'promotion_id'=>$creative,'owner_user_id'=>$owner,'product_id'=>$pid,'agent_id'=>$agent,'conversation_id'=>$chat,'source_asset_id'=>997601,'draft_key'=>$draft,'created_at'=>'2026-11-14 00:00:00'],'publication_id');
    }
    $clickIds=[str_repeat('1',32),str_repeat('2',32),str_repeat('3',32),str_repeat('4',32)];
    $q=$pdo->query('SELECT * FROM profile_agent_conversations WHERE id=991001');$c=$q->fetch(PDO::FETCH_ASSOC);
    foreach([[$clickIds[0],$publications[0],'profile','2026-11-15 10:00:00'],[$clickIds[1],$publications[0],'profile_agent','2026-11-16 12:00:00'],[$clickIds[2],$publications[0],'profile','2026-11-16 12:30:00'],[$clickIds[3],$publications[1],'profile','2026-11-16 11:00:00']] as [$id,$pub,$placement,$time]){
        $proof=$placement==='profile_agent'?['contract'=>'vp3.profile-promotion-native-click.v1','click_id'=>$id,'owner_user_id'=>$owner,'product_id'=>992101,'publication_id'=>$pub,'public_conversation_id'=>991001,
            'profile_agent_id'=>(int)$c['profile_agent_id'],'profile_session_id'=>990001,'visitor_user_id'=>98002,'credential_hash'=>hash('sha256','analytics-native-browser'),'session_hash'=>hash('sha256','analytics-native-session'),'visitor_auth_revision'=>hash('sha256','98002|fixture-visitor-password')]:null;
        profile_analytics_period_fixture_row_v1($pdo,'agent_promotion_clicks_v1',['click_id'=>$id,'publication_id'=>$pub,'placement'=>$placement,'created_at'=>$time,'native_context_json_v1'=>$proof?$json($proof):null],'click_id');
    }
    foreach([[992201,$clickIds[0],'2026-11-15 10:30:00'],[992202,$clickIds[3],'2026-11-16 11:30:00']] as [$oid,$click,$time]){
        foreach([['agent_commerce_orders_v800','id',$oid],['agent_commerce_order_items_v800','order_id',$oid]] as [$table,$column,$value]){
            $q=$pdo->prepare('SELECT metadata_json FROM '.$table.' WHERE '.$column.'=?');$q->execute([$value]);$meta=json_decode($q->fetchColumn(),true);
            $meta['product_promotion_click_v1']=$click;$meta['product_promotion_order_utc_v1']=$time;$pdo->prepare('UPDATE '.$table.' SET metadata_json=? WHERE '.$column.'=?')->execute([$json($meta),$value]);}
        profile_analytics_period_fixture_row_v1($pdo,'agent_promotion_orders_v1',['order_id'=>$oid,'click_id'=>$click],'order_id');
    }
    foreach([[993401,'2026-11-15 11:30:00'],[993402,'2026-11-16 13:00:00'],[993403,'2026-11-16 13:01:00']] as [$id,$time])$pdo->prepare('UPDATE agent_commerce_checkout_attempts_v800 SET opened_at_utc_ms=?,checkout_url=? WHERE id=?')->execute([(new DateTimeImmutable($time,new DateTimeZone('UTC')))->getTimestamp()*1000,'https://checkout.example.invalid/'.$id,$id]);
    $q=$pdo->query('SELECT context_json FROM profile_agent_messages WHERE id=991202');$context=json_decode($q->fetchColumn(),true);$context['product_promotion_key']=$publications[0];
    $pdo->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=991202')->execute([$json($context)]);
    $marker=['owner_user_id'=>$owner,'agent_id'=>$agent,'conversation_id'=>$chat,'product_id'=>992101,'source_id'=>'997601','draft_id'=>str_repeat('d',64),'creative_id'=>str_repeat('1',32),'stage'=>'promotion_outcomes'];
    profile_analytics_period_fixture_row_v1($pdo,'chat_messages',['id'=>997901,'conversation_id'=>$chat,'user_id'=>null,'role'=>'assistant','message'=>'Recorded native promotion result','context_json'=>$json(['source'=>'agent_promotion_outcome','product_launch'=>$marker,'promotion_outcome'=>['publication_id'=>$publications[0],'as_of_utc'=>'2026-11-17 00:00:00','placements'=>[]]]),'created_at'=>'2026-11-17 00:00:00']);
    return $base+['publications'=>$publications,'product_ids'=>[992101,992102],'click_ids'=>$clickIds,'source_file'=>STONEFELLOW_ROOT.'/private/user-media/'.$owner.'/phase6-997601.png','source_bytes'=>$png,'native_message_id'=>997901];
}
