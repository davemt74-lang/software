<?php
declare(strict_types=1);

require __DIR__.'/cloud-hosting-v140-mysql.php';

if(!function_exists('ai_encrypt_secret')){
    function ai_encrypt_secret(string $value): string { return 'enc:'.$value; }
}
require dirname(__DIR__).'/includes/cloud-hosting-agent-v130.php';
require dirname(__DIR__).'/includes/cloud-hosting-domains-v200.php';

$entitlements['hosting.custom_domains']=['enabled'=>true,'limit'=>3,'unlimited'=>false];
vp3_cloud_hosting_agent_v130_ensure_schema($testPdo);
vp3_cloud_hosting_domains_v200_ensure_schema($testPdo);

$site=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(!$site)throw new RuntimeException('V1 fixture site missing.');

$attached=vp3_cloud_hosting_domains_v200_attach($site,$owner,'www.customer-example.com',1,$testPdo);
$domain=(array)$attached['domain'];
$instructions=(array)$attached['instructions'];
if(($domain['verification_state']??'')!=='pending')throw new RuntimeException('Custom domain did not start pending verification.');
if(($instructions['ownership']['type']??'')!=='TXT'||($instructions['routing']['type']??'')!=='CNAME')throw new RuntimeException('Custom-domain DNS instructions are incomplete.');
$verificationValue=(string)($instructions['ownership']['value']??'');
if(!str_starts_with($verificationValue,'vp3-verification=vp3_'))throw new RuntimeException('Custom-domain verification challenge is malformed.');

$raw=$testPdo->query('SELECT verification_token_hash,verification_token_enc FROM cloud_hosting_custom_domains WHERE id='.(int)$domain['id'])->fetch();
if(!$raw)throw new RuntimeException('Custom-domain row missing.');
if(str_contains((string)$raw['verification_token_enc'],$verificationValue))throw new RuntimeException('Verification challenge was stored in plaintext.');
$token=substr($verificationValue,strlen('vp3-verification='));
if(!hash_equals((string)$raw['verification_token_hash'],hash('sha256',$token)))throw new RuntimeException('Verification token hash mismatch.');
if(str_contains(json_encode($domain),$token))throw new RuntimeException('Public custom-domain projection leaked verification token.');

try{
    vp3_cloud_hosting_domains_v200_attach($site,$other,'www.customer-example.com',2,$testPdo);
    throw new RuntimeException('Attached custom domain was hijacked by another account.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Attached custom domain was hijacked by another account.')throw $e;
}

$pendingOwnership=vp3_cloud_hosting_domains_v200_verify_ownership(
    vp3_cloud_hosting_domains_v200_find((int)$domain['id'],1,$testPdo),
    1,
    fn(string $name):array=>[['txt'=>'wrong-token']],
    $testPdo
);
if(($pendingOwnership['verification_state']??'')!=='pending')throw new RuntimeException('Wrong TXT challenge verified ownership.');

$owned=vp3_cloud_hosting_domains_v200_verify_ownership(
    vp3_cloud_hosting_domains_v200_find((int)$domain['id'],1,$testPdo),
    1,
    fn(string $name):array=>[['txt'=>$verificationValue]],
    $testPdo
);
if(($owned['verification_state']??'')!=='verified')throw new RuntimeException('Correct TXT challenge did not verify ownership.');

try{
    vp3_cloud_hosting_domains_v200_set_canonical(
        vp3_cloud_hosting_domains_v200_find((int)$domain['id'],1,$testPdo),true,1,$testPdo
    );
    throw new RuntimeException('Unrouted custom domain became canonical.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Unrouted custom domain became canonical.')throw $e;
}

$routed=vp3_cloud_hosting_domains_v200_verify_routing(
    vp3_cloud_hosting_domains_v200_find((int)$domain['id'],1,$testPdo),
    1,
    fn(string $host,string $target):array=>[
        'records'=>[['type'=>'CNAME','target'=>'hosting-edge.example.net.']],
        'target_records'=>[]
    ],
    $testPdo
);
if(($routed['routing_state']??'')!=='verified')throw new RuntimeException('Matching CNAME did not verify routing.');

$tls=vp3_cloud_hosting_domains_v200_record_tls(
    vp3_cloud_hosting_domains_v200_find((int)$domain['id'],1,$testPdo),
    'active',
    (new DateTimeImmutable('+30 days'))->format(DateTimeInterface::ATOM),
    str_repeat('a',64),
    1,
    $testPdo
);
if(empty($tls['ready']))throw new RuntimeException('Verified custom domain with active TLS did not become ready.');

$canonical=vp3_cloud_hosting_domains_v200_set_canonical(
    vp3_cloud_hosting_domains_v200_find((int)$domain['id'],1,$testPdo),true,1,$testPdo
);
if(empty($canonical['is_canonical']))throw new RuntimeException('Ready custom domain did not become canonical.');

$uiCard=vp3_cloud_hosting_ui_v140_site_card(vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo),$testPdo);
if(($uiCard['public_url']??'')!=='https://www.customer-example.com')throw new RuntimeException('Hosting UI did not prefer the ready canonical custom domain.');

$second=vp3_cloud_hosting_domains_v200_attach(
    vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo),
    $owner,
    'customer-example.com',
    1,
    $testPdo
);
$secondDomain=(array)$second['domain'];
$secondInstructions=(array)$second['instructions'];
$secondVerification=(string)$secondInstructions['ownership']['value'];
vp3_cloud_hosting_domains_v200_verify_ownership(
    vp3_cloud_hosting_domains_v200_find((int)$secondDomain['id'],1,$testPdo),
    1,
    fn(string $name):array=>[['txt'=>$secondVerification]],
    $testPdo
);
$secondRouted=vp3_cloud_hosting_domains_v200_verify_routing(
    vp3_cloud_hosting_domains_v200_find((int)$secondDomain['id'],1,$testPdo),
    1,
    fn(string $host,string $target):array=>[
        'records'=>[['type'=>'A','ip'=>'203.0.113.10']],
        'target_records'=>[['type'=>'A','ip'=>'203.0.113.10']]
    ],
    $testPdo
);
if(($secondRouted['routing_state']??'')!=='verified')throw new RuntimeException('Apex CNAME flattening equivalent did not verify routing.');
vp3_cloud_hosting_domains_v200_record_tls(
    vp3_cloud_hosting_domains_v200_find((int)$secondDomain['id'],1,$testPdo),
    'active',
    (new DateTimeImmutable('+30 days'))->format(DateTimeInterface::ATOM),
    str_repeat('b',64),
    1,
    $testPdo
);

$edge=vp3_cloud_hosting_domains_v200_edge_projection(1,$testPdo);
if(count((array)$edge['domains'])!==2)throw new RuntimeException('Cloud-edge custom-domain projection count mismatch.');
$secondEdge=null;
foreach($edge['domains'] as $entry)if(($entry['hostname']??'')==='customer-example.com')$secondEdge=$entry;
if(!$secondEdge||($secondEdge['redirect_to']??'')!=='https://www.customer-example.com')throw new RuntimeException('Noncanonical domain did not redirect to canonical domain.');

vp3_cloud_hosting_domains_v200_set_redirect(
    vp3_cloud_hosting_domains_v200_find((int)$secondDomain['id'],1,$testPdo),
    false,
    1,
    $testPdo
);
$edgeNoRedirect=vp3_cloud_hosting_domains_v200_edge_projection(1,$testPdo);
foreach($edgeNoRedirect['domains'] as $entry){
    if(($entry['hostname']??'')==='customer-example.com'&&($entry['redirect_to']??null)!==null)throw new RuntimeException('Custom-domain direct-serve policy was not honored.');
}

$secondSiteResult=vp3_cloud_hosting_ui_v140_execute($owner,'site.create',[
    'display_name'=>'Migration Target','requested_hostname'=>'target.sites.example.com','runtime_kind'=>'static','request_key'=>'custom-domain-target'
],null,null,null,null,$testPdo);
$targetSite=vp3_cloud_hosting_site_v100((int)$secondSiteResult['site']['id'],1,$testPdo);
$migrated=vp3_cloud_hosting_domains_v200_migrate(
    vp3_cloud_hosting_domains_v200_find((int)$secondDomain['id'],1,$testPdo),
    $targetSite,
    1,
    $testPdo
);
if((int)$migrated['site_id']!==(int)$targetSite['id']||!empty($migrated['is_canonical']))throw new RuntimeException('Same-account custom-domain migration failed.');
if(($migrated['verification_state']??'')!=='verified'||($migrated['routing_state']??'')!=='verified'||($migrated['tls_state']??'')!=='active')throw new RuntimeException('Safe custom-domain migration lost verified state.');

$agentPrepared=vp3_cloud_hosting_agent_v130_query(
    'attach custom domain blog.customer-example.com to site called Migration Target',
    $owner,701,$remote,null,$testPdo
);
$agentCode=(string)($agentPrepared['hosting_plan']['confirmation_code']??'');
if(!preg_match('/^[A-Z2-9]{8}$/',$agentCode))throw new RuntimeException('Agent custom-domain attach did not require confirmation.');
$agentDone=vp3_cloud_hosting_agent_v130_query('confirm hosting '.$agentCode,$owner,701,$remote,null,$testPdo);
if(empty($agentDone['hosting_plan']['completed']))throw new RuntimeException('Agent custom-domain attach did not complete.');
$stmt=$testPdo->prepare("SELECT COUNT(*) FROM cloud_hosting_custom_domains WHERE owner_user_id=1 AND hostname='blog.customer-example.com' AND detached_at IS NULL");
$stmt->execute();
if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('Agent custom-domain attach did not persist.');

$entitlements['hosting.custom_domains']['limit']=3;
try{
    vp3_cloud_hosting_domains_v200_attach($targetSite,$owner,'fourth.customer-example.com',1,$testPdo);
    throw new RuntimeException('Custom-domain package limit was bypassed.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Custom-domain package limit was bypassed.')throw $e;
    if(!str_contains($e->getMessage(),'custom-domain limit'))throw $e;
}

$detached=vp3_cloud_hosting_domains_v200_detach(
    vp3_cloud_hosting_domains_v200_find((int)$domain['id'],1,$testPdo),1,$testPdo
);
if(empty($detached['detached_at'])||!empty($detached['is_canonical']))throw new RuntimeException('Custom-domain detach failed.');
$edgeAfterDetach=vp3_cloud_hosting_domains_v200_edge_projection(1,$testPdo);
foreach($edgeAfterDetach['domains'] as $entry)if(($entry['hostname']??'')==='www.customer-example.com')throw new RuntimeException('Detached custom domain remained in edge projection.');

$otherSite=vp3_cloud_hosting_create_site_v100($other,[
    'display_name'=>'Other Site','requested_hostname'=>'other.sites.example.com','runtime_kind'=>'static','_creation_key'=>'other-site'
],2);
$reclaimed=vp3_cloud_hosting_domains_v200_attach($otherSite,$other,'www.customer-example.com',2,$testPdo);
if((int)$reclaimed['domain']['site_id']!==(int)$otherSite['id'])throw new RuntimeException('Detached custom domain could not be safely reclaimed by a new owner.');
if(($reclaimed['domain']['verification_state']??'')!=='pending')throw new RuntimeException('Reclaimed custom domain did not require fresh ownership verification.');
$reclaimedInstructions=(array)$reclaimed['instructions'];
if(($reclaimedInstructions['ownership']['value']??'')===$verificationValue)throw new RuntimeException('Reclaimed custom domain reused the former owner verification token.');

$otherProjection=vp3_cloud_hosting_domains_v200_edge_projection(2,$testPdo);
if(count((array)$otherProjection['domains'])!==0)throw new RuntimeException('Unverified reclaimed domain entered the edge projection.');

$cap=vp3_cloud_hosting_domains_v200_capability();
foreach([
    'multiple_domains_per_site','dns_txt_ownership_verification','cname_or_flattening_route_verification',
    'cloud_edge_tls','canonical_domain','noncanonical_redirect_policy','domain_detach',
    'same_account_domain_migration','cloud_edge_rewrites_upstream_host','verification_token_encrypted_at_rest'
] as $key){
    if(empty($cap[$key]))throw new RuntimeException('Custom-domain capability missing '.$key);
}
if(!empty($cap['home_server_alias_engine'])||!empty($cap['verification_token_publicly_exposed']))throw new RuntimeException('Custom-domain authority/privacy contract is invalid.');

$ledger=$testPdo->query("SELECT details_json FROM cloud_hosting_site_events WHERE event_type LIKE 'custom_domain.%'")->fetchAll();
$ledgerJson=json_encode($ledger);
if(str_contains((string)$ledgerJson,'vp3-verification=')||str_contains((string)$ledgerJson,$token))throw new RuntimeException('Custom-domain event ledger leaked verification credentials.');

echo "Cloud Hosting V2 Section 1 custom domains MySQL integration: PASS\n";
