<?php
declare(strict_types=1);

define('STONEFELLOW_ROOT',sys_get_temp_dir().'/vp3-profile-image-catalog-'.bin2hex(random_bytes(8)));
require dirname(__DIR__).'/includes/profile-image-catalog-v1.php';
function url(string $path): string { return $path; }

$dsn=getenv('VP3_TEST_MYSQL_DSN')?:'';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
if ($dsn!=='') {
    if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('The optional catalog test DSN must use MySQL.');
    $mysqlUser=getenv('VP3_TEST_MYSQL_USER')?:'root';$mysqlPassword=getenv('VP3_TEST_MYSQL_PASS')?:getenv('VP3_TEST_MYSQL_PASSWORD')?:'';
    $admin=new PDO($dsn,$mysqlUser,$mysqlPassword,$options);
    $database='vp3_image_catalog_'.bin2hex(random_bytes(8));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static function()use($admin,$database):void {
        $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    });
    $isolated=preg_replace('/(^|;)dbname=[^;]*/','$1',$dsn);
    // MySQL DSNs often put dbname immediately after the mysql: prefix.
    $isolated=preg_replace('/^mysql:dbname=[^;]*;?/','mysql:',$isolated);
    $pdo=new PDO(rtrim($isolated,';').';dbname='.$database,$mysqlUser,$mysqlPassword,$options+[PDO::ATTR_EMULATE_PREPARES=>false]);
} else {
    $pdo=new PDO('sqlite::memory:',null,null,$options);
    $pdo->exec('PRAGMA foreign_keys=ON');
}
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
$userId=$mysql?'INT UNSIGNED':'INTEGER';$assetId=$mysql?'BIGINT UNSIGNED':'INTEGER';
$pdo->exec("CREATE TABLE users(id $userId PRIMARY KEY,is_active INTEGER NOT NULL)".$tail);
$pdo->exec('INSERT INTO users VALUES(1,1),(2,1)');
$pdo->exec("CREATE TABLE user_media_assets(id $assetId PRIMARY KEY,user_id $userId NOT NULL,media_type TEXT NOT NULL,title TEXT NOT NULL,
    mime_type TEXT NOT NULL,file_path TEXT NOT NULL,file_size INTEGER NOT NULL,source TEXT NOT NULL,updated_at TEXT NOT NULL)".$tail);
$checks=0;
function catalog_check(bool $value,string $label): void { if (!$value) throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n"; }
function catalog_reject(callable $call,int $status,string $label): void {
    try { $call(); } catch (Throwable $error) { catalog_check((int)$error->getCode()===$status,$label.' ('.$error->getMessage().')');return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
function catalog_png(int $color=64,int $padding=0): string {
    $chunk=static fn(string $type,string $bytes):string=>pack('N',strlen($bytes)).$type.$bytes.pack('N',crc32($type.$bytes));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,6,0,0,0))
        .($padding>0?$chunk('tEXt',"Padding\0".str_repeat('a',$padding)):'')
        .$chunk('IDAT',gzcompress("\0".chr($color)."\x60\x80\xff")).$chunk('IEND','');
}
function catalog_asset(int $id,int $owner,string $bytes,?string $path=null): void {
    $path??='private/user-media/'.$owner.'/'.$id.'.png';
    $directory=dirname(STONEFELLOW_ROOT.'/'.$path);if (!is_dir($directory)) mkdir($directory,0700,true);
    file_put_contents(STONEFELLOW_ROOT.'/'.$path,$bytes);
    $GLOBALS['pdo']->prepare('INSERT INTO user_media_assets VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$owner,'photo','Style '.$id,'image/png',$path,strlen($bytes),'upload','2026-11-18 00:00:00']);
}
function catalog_save(array $changes=[]): array {
    $pdo=$GLOBALS['pdo'];$collection=$GLOBALS['collection'];
    $row=vp3_profile_image_catalog_row_v1($pdo,1,$collection);
    return vp3_profile_image_catalog_save_v1($pdo,['id'=>1],array_replace([
        'action'=>'save_catalog','expected_revision'=>$row['revision'],'collection_id'=>$collection,'approved'=>true,
        'style_instructions'=>'Warm muted colors and soft light.','default_price_cents'=>0,
        'references'=>[['asset_id'=>11,'price_override_cents'=>null],['asset_id'=>12,'price_override_cents'=>0]],
    ],$changes));
}
function catalog_settings(bool $enabled,array $changes=[]): array {
    $pdo=$GLOBALS['pdo'];$current=vp3_profile_image_catalog_settings_v1($pdo,1);
    return vp3_profile_image_catalog_save_v1($pdo,['id'=>1],['action'=>'save_settings','expected_revision'=>$current['revision'],
        'settings'=>array_replace(['enabled'=>$enabled,'daily_limit'=>10,'visitor_daily_limit'=>3],$changes)]);
}
try {
    catalog_check(vp3_profile_image_catalog_settings_v1($pdo,1)===['available'=>false,'enabled'=>false,'daily_limit'=>10,'visitor_daily_limit'=>3,'revision'=>0],
        'a pre-upgrade installation exposes disabled defaults without creating schema');
    catalog_check(vp3_profile_image_catalog_public_v1($pdo,1)===[],'missing catalog schema publishes no references');
    vp3_media_collections_install_v1($pdo);
    vp3_profile_image_catalog_install_v1($pdo);vp3_profile_image_catalog_install_v1($pdo);
    catalog_check(vp3_profile_image_catalog_ready_v1($pdo),'Database Upgrade installs the schema idempotently on real '.($mysql?'MySQL':'SQLite'));
    $collection=vp3_media_collection_create_v1($pdo,1,'Warm catalog')['id'];
    $otherCollection=vp3_media_collection_create_v1($pdo,1,'Other catalog')['id'];
    $foreignCollection=vp3_media_collection_create_v1($pdo,2,'Private foreign catalog')['id'];
    foreach ([11,12,13,14] as $id) catalog_asset($id,1,catalog_png($id));
    catalog_asset(21,2,catalog_png(21));
    foreach ([11,12,13] as $id) vp3_media_collection_set_asset_v1($pdo,1,$collection,$id,true);
    vp3_media_collection_set_asset_v1($pdo,1,$otherCollection,14,true);
    vp3_media_collection_set_asset_v1($pdo,2,$foreignCollection,21,true);
    $state=catalog_save();
    catalog_check($state['collections'][1]['name']==='Warm catalog' && $state['selected_collection_id']===$collection,
        'owner configuration retains the selected owned collection and private asset metadata');
    catalog_check(vp3_profile_image_catalog_public_v1($pdo,1)===[],'catalog approval alone cannot enable the public service');
    catalog_settings(true);
    $public=vp3_profile_image_catalog_public_v1($pdo,1);
    catalog_check(count($public)===1 && array_column($public[0]['references'],'asset_id')===[11,12],
        'only explicitly approved exemplars are public choices, not every collection member');
    catalog_check(!str_contains(json_encode($public),'private/') && !str_contains(json_encode($public),'media-library-file') && !str_contains(json_encode($public),'image_url'),
        'public choices reveal no storage paths, authenticated previews, or image pixels');
    catalog_check(!str_contains(json_encode(vp3_profile_image_catalog_owner_state_v1($pdo,['id'=>1])),'Private foreign'),
        'the owner editor cannot read another owner catalog');
    catalog_reject(fn()=>vp3_profile_image_catalog_owner_state_v1($pdo,['id'=>1],$foreignCollection),404,'foreign collection editor reads are denied');
    $snapshot=vp3_profile_image_catalog_resolve_v1($pdo,1,$collection);
    catalog_check(array_column($snapshot['references'],'asset_id')===[11,12] && $snapshot['price_cents']===0
        && $snapshot['references'][0]['byte_size']===strlen(catalog_png(11)) && !str_contains(json_encode($snapshot),'image_url'),
        'admission freezes source identity, byte hash, revision and zero pricing without pixels');
    $pixels=vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot,true);
    catalog_check(count($pixels)===2 && base64_decode(substr($pixels[0]['image_url'],strlen('data:image/png;base64,')))===catalog_png(11),
        'dispatch obtains the exact approved pixels through the verified private-source helper');
    catalog_check(!isset(vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot)[0]['image_url']),
        'publication rechecks need not retain another copy of the source pixels');
    catalog_reject(fn()=>vp3_profile_image_catalog_resolve_v1($pdo,1,$collection,13),409,'an owned but unapproved member is unavailable');
    catalog_reject(fn()=>catalog_save(['collection_id'=>$foreignCollection,'expected_revision'=>0]),404,'another owner collection cannot be approved');
    catalog_reject(fn()=>catalog_save(['references'=>[['asset_id'=>21,'price_override_cents'=>0]]]),404,'another owner image cannot be approved');
    catalog_reject(fn()=>catalog_save(['references'=>[['asset_id'=>14,'price_override_cents'=>0]]]),404,'an image outside the selected owned collection cannot be approved');
    catalog_reject(fn()=>catalog_save(['references'=>[['asset_id'=>11,'price_override_cents'=>null],['asset_id'=>11,'price_override_cents'=>0]]]),422,'duplicate exemplars are rejected');
    catalog_reject(fn()=>catalog_save(['references'=>[]]),422,'an approved catalog needs an explicit exemplar');
    catalog_reject(fn()=>catalog_save(['expected_revision'=>0]),409,'stale catalog edits cannot overwrite a newer saved grant');
    catalog_reject(fn()=>catalog_settings(true,['daily_limit'=>0]),422,'global zero quota is not accepted as unlimited');
    catalog_reject(fn()=>catalog_settings(true,['daily_limit'=>101]),422,'owner daily allowance has a bounded maximum');
    catalog_reject(fn()=>catalog_settings(true,['visitor_daily_limit'=>21]),422,'visitor allowance has a bounded maximum');
    catalog_reject(fn()=>catalog_settings(true,['enabled'=>'true']),422,'a string cannot silently enable public image processing');
    catalog_reject(fn()=>catalog_save(['style_instructions'=>"\xc3\x28"]),422,'style instructions must contain valid UTF-8');
    catalog_reject(fn()=>catalog_save(['style_instructions'=>str_repeat('a',2001)]),422,'style instruction length is bounded before saving');
    foreach ([-1,0.1,'0',VP3_PROFILE_IMAGE_CATALOG_PRICE_MAX_V1+1] as $invalid) {
        catalog_reject(fn()=>catalog_save(['default_price_cents'=>$invalid]),422,'catalog prices require bounded integer cents');
        catalog_reject(fn()=>catalog_save(['references'=>[['asset_id'=>11,'price_override_cents'=>$invalid]]]),422,'image prices require bounded integer cents or null');
    }
    $before=vp3_profile_image_catalog_row_v1($pdo,1,$collection)['revision'];
    catalog_save(['default_price_cents'=>150]);
    $public=vp3_profile_image_catalog_public_v1($pdo,1);
    catalog_check(array_column($public[0]['references'],'asset_id')===[12],
        'a positive catalog default excludes inherited-price references while explicit zero remains free');
    $free=vp3_profile_image_catalog_resolve_v1($pdo,1,$collection);
    catalog_check(array_column($free['references'],'asset_id')===[12] && vp3_profile_image_catalog_resolve_v1($pdo,1,$collection,12)['selection_asset_id']===12,
        'catalog and individual modes select only zero-priced references');
    catalog_reject(fn()=>vp3_profile_image_catalog_resolve_v1($pdo,1,$collection,11),409,'a positive-priced image is blocked without starting checkout or payment');
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot),409,'a changed catalog grant invalidates the old frozen request');
    catalog_save(['references'=>[['asset_id'=>11,'price_override_cents'=>99],['asset_id'=>12,'price_override_cents'=>1]]]);
    catalog_check(vp3_profile_image_catalog_public_v1($pdo,1)===[],'a catalog with only positive-priced references is absent from free choices');
    catalog_reject(fn()=>vp3_profile_image_catalog_resolve_v1($pdo,1,$collection),409,'positive-priced sources cannot fall back to a free reference');
    catalog_save();$snapshot=vp3_profile_image_catalog_resolve_v1($pdo,1,$collection);
    catalog_settings(false);
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot),409,'disabling the service revokes an admitted request before dispatch or publication');
    catalog_settings(true);catalog_save(['approved'=>false]);
    catalog_check(vp3_profile_image_catalog_public_v1($pdo,1)===[],'revoking catalog approval removes all public choices');
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot),409,'a frozen request cannot restore a revoked catalog grant');
    catalog_save();$snapshot=vp3_profile_image_catalog_resolve_v1($pdo,1,$collection);
    vp3_media_collection_set_asset_v1($pdo,1,$collection,11,false);
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot),409,'removing an exemplar from the collection revokes its use');
    vp3_media_collection_set_asset_v1($pdo,1,$collection,11,true);
    catalog_check(array_column(vp3_profile_image_catalog_public_v1($pdo,1)[0]['references'],'asset_id')===[12],
        'readding a removed collection member does not silently restore its previous public approval');
    catalog_save();$snapshot=vp3_profile_image_catalog_resolve_v1($pdo,1,$collection);
    file_put_contents(STONEFELLOW_ROOT.'/private/user-media/1/11.png',catalog_png(64));
    catalog_reject(fn()=>vp3_profile_image_catalog_resolve_v1($pdo,1,$collection),409,'changing approved source bytes requires explicit owner reapproval');
    file_put_contents(STONEFELLOW_ROOT.'/private/user-media/1/11.png','damaged image');
    catalog_save(['approved'=>false]);
    catalog_check(vp3_profile_image_catalog_public_v1($pdo,1)===[],
        'an owner can revoke an existing catalog even after its source file becomes damaged');
    catalog_reject(fn()=>catalog_save(),422,'reenabling a revoked catalog still requires valid source pixels');
    file_put_contents(STONEFELLOW_ROOT.'/private/user-media/1/11.png',catalog_png(11));
    catalog_save();$snapshot=vp3_profile_image_catalog_resolve_v1($pdo,1,$collection);
    $pdo->exec("UPDATE user_media_assets SET updated_at='2026-11-18 00:00:01' WHERE id=11");
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot),409,'changing the source revision invalidates the saved grant');
    catalog_save();$snapshot=vp3_profile_image_catalog_resolve_v1($pdo,1,$collection);
    $forged=$snapshot;$forged['instructions']='Use a different style';
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$forged),409,'a forged style instruction cannot substitute for the frozen approved configuration');
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,2,$snapshot),409,'the frozen grant is bound to the exact catalog owner');
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    catalog_check(vp3_profile_image_catalog_public_v1($pdo,1)===[],'deactivated owners have no public image catalog');
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot),403,'deactivation also blocks dispatch and publication rechecks');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
    catalog_asset(31,1,catalog_png(1,2200000));catalog_asset(32,1,catalog_png(2,2200000));
    vp3_media_collection_set_asset_v1($pdo,1,$collection,31,true);vp3_media_collection_set_asset_v1($pdo,1,$collection,32,true);
    catalog_reject(fn()=>catalog_save(['references'=>[['asset_id'=>31,'price_override_cents'=>0],['asset_id'=>32,'price_override_cents'=>0]]]),422,
        'the combined exemplar byte cap preserves provider memory headroom');
    catalog_asset(33,1,catalog_png(33),'private/user-media/2/wrong-owner.png');
    vp3_media_collection_set_asset_v1($pdo,1,$collection,33,true);
    catalog_reject(fn()=>catalog_save(['references'=>[['asset_id'=>33,'price_override_cents'=>0]]]),409,
        'an owned database row cannot grant another owner private file path');
    $pdo->exec('DELETE FROM user_media_assets WHERE id=11');
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot),409,'deleting a source cannot silently replace the frozen reference set');
    vp3_media_collection_delete_v1($pdo,1,$collection);
    catalog_reject(fn()=>vp3_profile_image_catalog_recheck_v1($pdo,1,$snapshot),404,'deleted catalogs cannot be revived by an earlier request');
    catalog_check((int)$pdo->query('SELECT COUNT(*) FROM profile_image_catalog_refs_v1')->fetchColumn()===0,
        'catalog deletion removes only live approval settings, leaving request snapshots to their owning service');
    echo "PASS $checks Profile image catalog checks\n";
} finally {
    if (is_dir(STONEFELLOW_ROOT)) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname());else unlink($file->getPathname()); }
        rmdir(STONEFELLOW_ROOT);
    }
}
