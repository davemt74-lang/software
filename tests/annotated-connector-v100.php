<?php
declare(strict_types=1);
function site_config(string $key,mixed $fallback=null): mixed{return $fallback;}
function db(): ?PDO{return null;}
function table_exists(string $name): bool{return false;}
putenv('VP3_ANNOTATED_BASE_URL=https://annotated.example.test');
putenv('VP3_ANNOTATED_REDIRECT_URI=https://annotated.example.test/vp3/callback.php');
putenv('VP3_ANNOTATED_CLIENT_SECRET='.str_repeat('s',64));
require dirname(__DIR__).'/includes/connected-sites-v100.php';
function ok(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$app=vp3_connected_site_app_v100('annotated');ok(is_array($app)&&$app['app_key']==='annotated','registered Annotated connected-site client is available.');
ok(strlen((string)$app['client_secret'])===64,'client secret is configuration-backed and sufficiently long.');
$scopes=vp3_connected_site_scopes_v100($app,'account.identity.read transcriptions.read fake.scope');ok($scopes===['account.identity.read','transcriptions.read'],'requested scopes are intersected with registered scopes.');
ok(vp3_connected_site_scopes_v100($app,'fake.scope')===[],'an invalid non-empty scope request never expands to all permissions.');
ok(count(vp3_connected_site_scopes_v100($app,''))===3,'an omitted scope request uses the registered first-party defaults.');
ok(vp3_connected_site_validate_redirect_v100($app,'https://annotated.example.test/vp3/callback.php')==='https://annotated.example.test/vp3/callback.php','registered callback passes exact redirect validation.');
try{vp3_connected_site_validate_redirect_v100($app,'https://evil.example.test/callback');throw new RuntimeException('FAIL: mismatched redirect was accepted.');}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'FAIL:'))throw $e;echo "PASS: mismatched redirect is rejected.\n";}
echo "Annotated connector v1.0 PHP contract passed.\n";
