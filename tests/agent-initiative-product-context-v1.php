<?php
declare(strict_types=1);

require_once __DIR__.'/fixtures/commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

// The shared boundary is tested by the integrated Initiative pipeline. This
// fixture deliberately leaves it permissive to exercise this domain's own
// System/owner/conversation checks over real canonical Commerce/Media reads.
function vp3_initiative_capability_scope_v1(PDO $pdo,array $scope,array $binding): array{return $scope;}
function has_permission(string $permission,array $user): bool{return !in_array($permission,$GLOBALS['product_context_denied'][(int)$user['id']]??[],true);}

function product_context_load_production_function(string $file,string $name): void
{
    $tokens=token_get_all(file_get_contents(dirname(__DIR__).'/includes/'.$file));
    for($i=0;$i<count($tokens);$i++){
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
        if(!is_array($tokens[$j])||$tokens[$j][0]!==T_STRING||$tokens[$j][1]!==$name)continue;
        $body='';$braces=0;$opened=false;
        for($j=$i;$j<count($tokens);$j++){
            $part=is_array($tokens[$j])?$tokens[$j][1]:$tokens[$j];$body.=$part;
            if($part==='{'){$opened=true;$braces++;}
            elseif($part==='}'&&--$braces===0&&$opened){eval($body);return;}
        }
    }
    throw new RuntimeException('Missing production authority reader.');
}
product_context_load_production_function('agent-capability-chat-v1.php','vp3_capability_live_user_v1');
require_once dirname(__DIR__).'/includes/agent-initiative-product-context-v1.php';

$fixture=commerce_fixture_config_pa2();$pdo=commerce_fixture_connect_pa2($fixture);$GLOBALS['pdo']=$pdo;
$checks=0;
function product_context_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function product_context_reject(callable $read,string $label,int $code=0): void
{
    try{$read();}catch(Throwable $e){product_context_check($code===0||$e->getCode()===$code,$label);return;}
    throw new RuntimeException('Expected refusal: '.$label);
}
function product_context_evidence(?array $refs=null,?array $scope=null,?array $binding=null): array
{
    $coverage=[];
    $rows=vp3_initiative_product_capabilities_evidence_v1(db(),$scope??$GLOBALS['product_context_scope'],$binding??$GLOBALS['product_context_binding'],
        new DateTimeImmutable('2026-11-06T01:00:00Z'),$refs,$coverage);
    return [$rows,$coverage];
}
function product_context_read(array $evidence,?array $refs=null,?array $scope=null,?array $binding=null,string $tool='products.content.prepare'): array
{
    $refs??=array_keys($evidence);
    return vp3_initiative_product_capabilities_read_v1(db(),$scope??$GLOBALS['product_context_scope'],$binding??$GLOBALS['product_context_binding'],
        $tool,['refs'=>$refs,'primary'=>$refs[0]??''],$evidence);
}

try{
    commerce_fixture_schema_pa2($pdo);
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,user_agent_id INTEGER)');
    $pdo->exec('INSERT INTO chat_conversations VALUES(7,1,NULL),(8,2,NULL),(9,1,3)');
    $pdo->exec('CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type VARCHAR(12),title VARCHAR(190),mime_type VARCHAR(120),file_path VARCHAR(250),file_size INTEGER,duration_seconds INTEGER,source VARCHAR(80),metadata_json TEXT,created_at VARCHAR(40),updated_at VARCHAR(40))');
    $pdo->exec("INSERT INTO user_media_assets VALUES(301,1,'Owner catalog photo','photo','image/png','private/SECRET-OWNER-PATH.png',512,0,'upload','{\"private_token\":\"SECRET-MEDIA-METADATA\"}','2026-11-01','2026-11-01'),(302,2,'SECRET-FOREIGN-PHOTO','photo','image/jpeg','private/SECRET-FOREIGN-PATH.jpg',1024,0,'upload','{}','2026-11-01','2026-11-01')");
    $insert=$pdo->prepare('INSERT INTO agent_commerce_products_v800(id,owner_user_id,workspace_owner_user_id,product_key,title,description,product_type,fulfillment_type,payment_mode,price_cents,currency,is_active,metadata_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([101,1,null,'owner','Owner service','Recorded useful service description','service','virtual','full',12000,'usd',1,'{"source_asset_id":"301","profile_visibility":"public","private_key":"SECRET-PRODUCT-METADATA"}']);
    $insert->execute([102,1,null,'private','Private draft','Owner draft description','digital','digital','full',800,'usd',0,'{"listing_draft":"product_photo_v1"}']);
    $insert->execute([201,2,1,'workspace-foreign','SECRET-FOREIGN-PRODUCT','SECRET-FOREIGN-DESCRIPTION','service','virtual','full',100,'usd',1,'{}']);
    $scope=['user'=>['id'=>1,'is_active'=>1],'principal'=>['kind'=>'system','agent_id'=>0,'owner_user_id'=>0,'viewer_user_id'=>1],'agent_id'=>0,'conversation_id'=>7];
    $binding=['enabled'=>true,'agent_id'=>0,'conversation_id'=>7];
    $GLOBALS['product_context_scope']=$scope;$GLOBALS['product_context_binding']=$binding;
    $before=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?(int)$pdo->query('SELECT total_changes()')->fetchColumn():null;
    [$all,$coverage]=product_context_evidence();
    product_context_check(array_keys($all)===['product-context:101','product-context:102']&&$coverage['product_context']==='ready','actual catalog discovery includes owned active/private listings and excludes another owner’s workspace listing');
    [$same]=product_context_evidence();
    product_context_check(array_column($same,'revision','ref')===array_column($all,'revision','ref'),'unchanged canonical product/media state has stable semantic revisions');
    $result=product_context_read(['product-context:101'=>$all['product-context:101']]);
    $data=json_decode($result['records'][0]['text'],true,16,JSON_THROW_ON_ERROR);
    product_context_check($data['product']['description']==='Recorded useful service description'&&$data['product']['price_cents']===12000&&$data['recorded_media']['id']===301,'read returns actual owned catalog content and its real recorded media association');
    $serialized=json_encode([$all,$result],JSON_THROW_ON_ERROR);
    product_context_check(!str_contains($serialized,'SECRET-')&&!str_contains($serialized,'file_path')&&!str_contains($serialized,'private_key'),'paths, arbitrary private metadata and foreign products/media are excluded from model records');
    product_context_check($data['coverage']['pixels']==='not_read'&&$data['coverage']['gallery']==='not_assessed'&&$data['coverage']['promotion']==='not_assessed','metadata reads make no image-content, complete-gallery or missing-promotion claim');
    product_context_check(count($result['records'])===1&&count($result['sources'])===2&&strlen($result['records'][0]['text'])<=8000,'read is bound to the exact selected product with bounded content and actual product/media sources');
    product_context_check(!$GLOBALS['pa2_http_calls']&&($before===null||(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before),'discovery and reads perform zero provider calls and zero persistent writes');
    $legacyBinding=$binding;unset($legacyBinding['agent_id']);
    [$legacy]=product_context_evidence(['product-context:101'],null,$legacyBinding);
    $legacyRead=product_context_read($legacy,null,null,$legacyBinding);
    product_context_check($legacy===['product-context:101'=>$all['product-context:101']]&&$legacyRead===$result,
        'the canonical legacy System policy without agent_id admits the same exact owned source and real content read');
    foreach([null,'0',1] as $invalidAgent){
        product_context_reject(fn()=>product_context_evidence(null,null,[...$binding,'agent_id'=>$invalidAgent]),
            'an explicit malformed or non-System binding Agent is rejected instead of defaulted to System',403);
    }
    $old=$all['product-context:101'];
    $pdo->prepare('UPDATE agent_commerce_products_v800 SET description=? WHERE id=101')->execute(['Revised exact catalog description']);
    [$fresh]=product_context_evidence(['product-context:101']);
    product_context_check($fresh['product-context:101']['revision']!==$old['revision'],'description edits invalidate source revision even without an updated_at change');
    product_context_reject(fn()=>product_context_read(['product-context:101'=>$old]),'a stale product revision cannot feed a saved content preparation',409);
    $newRead=product_context_read($fresh);
    product_context_check(str_contains($newRead['records'][0]['text'],'Revised exact catalog description'),'fresh exact reconstruction reads the owner’s revised catalog content');
    $old=$fresh['product-context:101'];$pdo->exec('UPDATE agent_commerce_products_v800 SET price_cents=12500 WHERE id=101');
    [$fresh]=product_context_evidence(['product-context:101']);
    product_context_check($fresh['product-context:101']['revision']!==$old['revision'],'commercial term edits also fence stale product content');
    $old=$fresh['product-context:101'];$pdo->exec("UPDATE user_media_assets SET title='Changed owned photo title' WHERE id=301");
    [$fresh]=product_context_evidence(['product-context:101']);
    product_context_check($fresh['product-context:101']['revision']!==$old['revision'],'owned media metadata edits invalidate the composite revision without relying on a timestamp');
    $pdo->exec("INSERT INTO agent_commerce_product_bindings_v800(product_id,binding_type,binding_id) VALUES(101,'event',5),(101,'digital',7)");
    [$fresh]=product_context_evidence(['product-context:101']);$bindingRead=product_context_read($fresh);$bindingData=json_decode($bindingRead['records'][0]['text'],true);
    product_context_check($bindingData['product']['recorded_fulfillment_bindings']===[['type'=>'digital','id'=>7],['type'=>'event','id'=>5]],'multiple fulfillment bindings use an ordered canonical owner-filtered projection');
    $old=$fresh['product-context:101'];$pdo->exec("UPDATE agent_commerce_product_bindings_v800 SET binding_id=8 WHERE product_id=101 AND binding_type='digital'");
    [$fresh]=product_context_evidence(['product-context:101']);
    product_context_check($fresh['product-context:101']['revision']!==$old['revision'],'binding changes invalidate product evidence independently of the product timestamp');
    $old=$fresh['product-context:101'];$pdo->exec('UPDATE user_media_assets SET user_id=2 WHERE id=301');
    [$fresh]=product_context_evidence(['product-context:101']);$foreignRead=product_context_read($fresh);$foreignData=json_decode($foreignRead['records'][0]['text'],true);
    product_context_check($fresh['product-context:101']['revision']!==$old['revision']&&$foreignData['recorded_media']===null&&$foreignData['coverage']['association']==='recorded_link_unavailable','media ownership transfer removes its metadata and changes the owned source revision');
    product_context_reject(fn()=>product_context_read(['product-context:101'=>$old]),'an old owned-media result cannot survive ownership transfer',409);
    $pdo->exec("UPDATE agent_commerce_products_v800 SET metadata_json='{\"source_asset_id\":302}' WHERE id=101");
    [$fresh]=product_context_evidence(['product-context:101']);$foreignRead=product_context_read($fresh);
    product_context_check(!str_contains(json_encode($foreignRead),'SECRET-FOREIGN')&&count($foreignRead['sources'])===1,'a recorded association to another owner’s asset reveals no foreign metadata or media source link');
    $pdo->exec("UPDATE agent_commerce_products_v800 SET metadata_json='{\"source_asset_id\":\"0301\"}' WHERE id=101");
    [$fresh]=product_context_evidence(['product-context:101']);$invalidRead=product_context_read($fresh);$invalidData=json_decode($invalidRead['records'][0]['text'],true);
    product_context_check($invalidData['recorded_media']===null&&$invalidData['coverage']['association']==='invalid_recorded_association','noncanonical media IDs are not coerced into a different saved asset');
    [$noMedia]=product_context_evidence(['product-context:102']);$noMediaRead=product_context_read($noMedia);$noMediaData=json_decode($noMediaRead['records'][0]['text'],true);
    product_context_check($noMediaData['coverage']['association']==='not_assessed'&&$noMediaData['coverage']['gallery']==='not_assessed','absence of a known association is not evidence of an absent gallery or image');
    $named=[...$scope,'agent_id'=>3,'conversation_id'=>9,'principal'=>['kind'=>'user_agent','agent_id'=>3,'owner_user_id'=>1,'viewer_user_id'=>1]];
    product_context_reject(fn()=>product_context_evidence(null,$named,[...$binding,'agent_id'=>3,'conversation_id'=>9]),'named Agent consent cannot broaden the existing System-only Commerce boundary',403);
    foreach([
        [...$scope,'principal'=>['kind'=>'profile_agent','agent_id'=>3,'owner_user_id'=>1,'viewer_user_id'=>1]],
        [...$scope,'principal'=>['kind'=>'system','agent_id'=>0,'viewer_user_id'=>2]],
        [...$scope,'principal'=>['kind'=>'system','agent_id'=>0,'owner_user_id'=>2,'viewer_user_id'=>1]],
    ] as $forged)product_context_reject(fn()=>product_context_evidence(null,$forged),'foreign or Profile principal cannot read owner catalog context',403);
    product_context_reject(fn()=>product_context_evidence(null,[...$scope,'conversation_id'=>8],[...$binding,'conversation_id'=>8]),'another owner’s conversation cannot bind the catalog read',403);
    product_context_reject(fn()=>product_context_evidence(null,[...$scope,'conversation_id'=>9],[...$binding,'conversation_id'=>9]),'a named-Agent conversation cannot be borrowed by the System principal',403);
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>1];
    product_context_reject(fn()=>product_context_evidence(),'participant context cannot become a private owner Commerce reader',403);
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    product_context_reject(fn()=>product_context_evidence(null,null,[...$binding,'enabled'=>false]),'paused work cannot admit new product work',403);
    [$verification]=product_context_evidence(['product-context:102'],[...$scope,'verification_only'=>true],[...$binding,'enabled'=>false]);
    product_context_check(isset($verification['product-context:102']),'read-only verification of an old owned source can continue after autonomous work is paused');
    $GLOBALS['product_context_denied'][1]=['account.access'];
    product_context_reject(fn()=>product_context_evidence(),'fresh permission revocation blocks catalog reads',403);
    unset($GLOBALS['product_context_denied'][1]);
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    product_context_reject(fn()=>product_context_evidence(),'fresh canonical account deactivation rejects the stale active scope',403);
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
    $pdo->afterSql=static function(string $sql,array $params,PA2CommercePDO $connection): void{
        if(str_contains($sql,'FROM agent_commerce_products_v800 p')&&str_contains($sql,'p.id=?')){
            $connection->afterSql=null;$connection->exec('UPDATE users SET is_active=0 WHERE id=1');
        }
    };
    product_context_reject(fn()=>product_context_evidence(['product-context:102']),'account revocation during a real product read is fenced before evidence is returned',403);
    $pdo->afterSql=null;$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
    foreach([['product-context:01'],['product-context:101','product-context:101'],['product-context:9223372036854775808']] as $invalidRefs){
        product_context_reject(fn()=>product_context_evidence($invalidRefs),'malformed or duplicate exact-source selectors are rejected');
    }
    product_context_reject(fn()=>product_context_read($noMedia,null,null,null,'images.generate'),'the read adapter cannot dispatch an image-generation or other tool');
    product_context_reject(fn()=>product_context_read($noMedia,['product-context:101']),'a source not corroborated by its exact saved evidence cannot be read',409);
    [$foreign]=product_context_evidence(['product-context:201']);
    product_context_check($foreign===[],'exact source reconstruction never admits a foreign workspace listing');
    for($i=1000;$i<1030;$i++)$insert->execute([$i,1,null,'bounded-'.$i,'Another owned product','Recorded description','service','virtual','full',100,'usd',1,'{}']);
    [$bounded,$boundedCoverage]=product_context_evidence();
    product_context_check(count($bounded)===6&&$boundedCoverage['product_context']==='limited'&&!isset($bounded['product-context:102']),'discovery explicitly declares its six-product window instead of a complete inventory');
    [$outside]=product_context_evidence(['product-context:102']);
    product_context_check(isset($outside['product-context:102']),'exact stale-source checks reconstruct a source beyond the discovery window');
    for($i=10;$i<17;$i++)$pdo->prepare('INSERT INTO agent_commerce_product_bindings_v800(product_id,binding_type,binding_id) VALUES(102,?,?)')->execute(['bound-'.$i,$i]);
    [$limitedBindings,$limitedBindingsCoverage]=product_context_evidence(['product-context:102']);$limitedRead=product_context_read($limitedBindings);$limitedData=json_decode($limitedRead['records'][0]['text'],true);
    product_context_check($limitedBindingsCoverage['product_context']==='limited'&&count($limitedData['product']['recorded_fulfillment_bindings'])===6&&$limitedData['coverage']['binding_coverage']==='limited','binding truncation remains explicit in evidence and actual read coverage');
    $pdo->prepare('UPDATE agent_commerce_products_v800 SET metadata_json=? WHERE id=102')->execute([str_repeat('x',65537)]);
    product_context_reject(fn()=>product_context_evidence(['product-context:102']),'oversized metadata is unavailable rather than silently truncated into unsupported facts',503);
    $pdo->exec("UPDATE agent_commerce_products_v800 SET metadata_json='{}' WHERE id=102");
    [$existing]=product_context_evidence(['product-context:102']);$pdo->exec('DELETE FROM agent_commerce_products_v800 WHERE id=102');
    [$deleted]=product_context_evidence(['product-context:102']);
    product_context_check($deleted===[],'a deleted exact catalog source is absent without expanding into other products');
    product_context_reject(fn()=>product_context_read($existing),'a deleted product invalidates an older content preparation',409);
    $pdo->exec("UPDATE agent_commerce_products_v800 SET metadata_json='{\"source_asset_id\":301}' WHERE id=101");
    $pdo->exec('DROP TABLE user_media_assets');
    product_context_reject(fn()=>product_context_evidence(['product-context:101']),'unavailable linked-media storage is not reported as no product image',503);
    $pdo->exec('DROP TABLE agent_commerce_product_bindings_v800');
    product_context_reject(fn()=>product_context_evidence(),'unavailable canonical Commerce schema cannot masquerade as an empty catalog',503);
    product_context_check(!$GLOBALS['pa2_http_calls'],'all mutation, ownership, recovery and coverage probes remain provider-free');
    echo 'AGENT_INITIATIVE_PRODUCT_CONTEXT_V1=PASS ('.$checks.' cases)'.PHP_EOL;
}finally{
    $pdo->afterSql=null;
    commerce_fixture_cleanup_pa2($fixture);
}
