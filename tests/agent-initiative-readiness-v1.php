<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

// SQLite executes the production canonical record readers. Permission/plugin
// availability is fixture-controlled; no AI provider or external service runs.
function db(): PDO{return $GLOBALS['readiness_pdo'];}
function url(string $path=''): string{return '/vp3'.$path;}
function setting(string $key,mixed $fallback=null): mixed{return $fallback;}
function has_permission(string $permission,array $user): bool{return !in_array($permission,$GLOBALS['readiness_denied'][(int)$user['id']]??[],true);}
function table_exists(string $table): bool
{
    $q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return $q->fetchColumn()!==false;
}
function column_exists(string $table,string $column): bool
{
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
function agent_commerce_schema_ready_v800(?PDO $pdo=null): bool{return table_exists('agent_commerce_products_v800')&&table_exists('agent_commerce_product_bindings_v800');}
function agent_scheduling_schema_ready_v430(PDO $pdo): bool{return table_exists('agent_scheduling_schedules');}
function campaigns_rewards_platform_schema_ready_v100(PDO $pdo): bool{return table_exists('merchant_accounts')&&table_exists('merchant_profiles')&&table_exists('campaigns');}
function vp3_plugin_effective_state_v360(PDO $pdo,array $user,string $key): array{return ['enabled'=>!in_array((int)$user['id'],$GLOBALS['readiness_plugin_disabled']??[],true)];}
function campaigns_rewards_platform_can_v100(PDO $pdo,int $merchant,int $owner,string $permission): bool
{
    if(in_array($permission,$GLOBALS['readiness_merchant_denied'][$merchant]??[],true))return false;
    $q=$pdo->prepare('SELECT 1 FROM merchant_accounts WHERE id=? AND owner_user_id=? AND status=?');$q->execute([$merchant,$owner,'active']);return $q->fetchColumn()!==false;
}

/** Load a production reader without loading unrelated writers/transport includes. */
function readiness_production_function(string $file,string $name): void
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
    throw new RuntimeException('Production function missing: '.$name);
}

require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/profile-agent.php';
require_once dirname(__DIR__).'/includes/agent-commerce-v800-part2.php';
require_once dirname(__DIR__).'/includes/campaigns-rewards-v100.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
require_once dirname(__DIR__).'/includes/agent-initiative-readiness-v1.php';
readiness_production_function('agent-capability-chat-v1.php','vp3_capability_live_user_v1');
foreach(['agent_scheduling_tools_ready_v460','agent_scheduling_tools_schedule_v460','agent_scheduling_tools_events_v460'] as $name){
    readiness_production_function('agent-scheduling-tools-v460.php',$name);
}

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$GLOBALS['readiness_pdo']=$pdo;
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,display_name TEXT,role TEXT,is_active INTEGER,avatar_path TEXT);
    INSERT INTO users VALUES(1,'owner@example.test','Owner','member',1,''),(2,'other@example.test','Other owner','member',1,'');
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_key TEXT,display_name TEXT,agent_role TEXT,engine_key TEXT,instructions TEXT,is_default INTEGER,is_profile_agent INTEGER,is_active INTEGER,voice_enabled INTEGER,created_at TEXT,updated_at TEXT);
    INSERT INTO user_agents VALUES(11,1,'a','First Agent','personal','stonefellow','',1,0,1,0,'2026-01-01','2026-01-01'),(12,1,'b','Second Agent','personal','stonefellow','',0,0,1,0,'2026-01-01','2026-01-01'),(21,2,'c','Other Agent','personal','stonefellow','',1,0,1,0,'2026-01-01','2026-01-01');
    CREATE TABLE user_profiles(user_id INTEGER PRIMARY KEY,username TEXT,bio TEXT,is_public INTEGER,profile_agent_instructions TEXT);
    INSERT INTO user_profiles VALUES(1,'','Collects local history',0,'PRIVATE_PROFILE_AGENT_INSTRUCTIONS'),(2,'other','Other profile',0,'');
    CREATE TABLE user_agent_preferences(user_id INTEGER PRIMARY KEY,onboarding_dismissed INTEGER,voice_preference TEXT,onboarding_step TEXT,onboarding_draft_json TEXT,feature_interest_json TEXT,last_trial_notice_threshold INTEGER,last_trial_notice_at TEXT);
    INSERT INTO user_agent_preferences VALUES(1,0,NULL,'profile',NULL,'{\"workflow.booking\":true}',NULL,NULL),(2,0,NULL,'complete',NULL,'{}',NULL,NULL);
    CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
    CREATE TABLE agent_commerce_products_v800(id INTEGER PRIMARY KEY,owner_user_id INTEGER,workspace_owner_user_id INTEGER,product_key TEXT,title TEXT,description TEXT,product_type TEXT,fulfillment_type TEXT,payment_mode TEXT,price_cents INTEGER,is_active INTEGER,metadata_json TEXT,updated_at TEXT);
    INSERT INTO agent_commerce_products_v800 VALUES(101,1,NULL,'draft','Photo listing','', 'digital','none','full',0,0,'{\"listing_draft\":\"product_photo_v1\",\"source_asset_id\":\"301\"}','2026-11-01'),(102,1,NULL,'ready','Ready service','Prepared description','service','virtual','full',12000,1,'{\"profile_visibility\":\"public\"}','2026-11-02'),(201,2,1,'other','Other owner private product','Other private content','digital','digital','full',500,1,'{}','2026-11-03');
    CREATE TABLE agent_commerce_product_bindings_v800(product_id INTEGER,binding_type TEXT,binding_id INTEGER);
    CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER,title TEXT,media_type TEXT,mime_type TEXT,updated_at TEXT);
    INSERT INTO user_media_assets VALUES(301,1,'Owner photo','photo','image/png','2026-11-01'),(302,2,'Other photo','photo','image/png','2026-11-01');
    CREATE TABLE merchant_accounts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,name TEXT,status TEXT,sandbox_mode INTEGER);
    INSERT INTO merchant_accounts VALUES(401,1,'Owner business','active',0),(402,2,'Other business','active',0);
    CREATE TABLE merchant_profiles(merchant_id INTEGER,description TEXT,website_url TEXT,public_contact_json TEXT);
    INSERT INTO merchant_profiles VALUES(401,'','','{}'),(402,'Other private description','https://other.test','{}');
    CREATE TABLE campaigns(id INTEGER PRIMARY KEY,merchant_id INTEGER,name TEXT,status TEXT,environment TEXT,starts_at TEXT,ends_at TEXT,updated_at TEXT);
    CREATE TABLE agent_scheduling_schedules(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,is_active INTEGER,is_default INTEGER);
    CREATE TABLE agent_scheduling_event_types(id INTEGER PRIMARY KEY,schedule_id INTEGER,is_active INTEGER,sort_order INTEGER,title TEXT);
    CREATE TABLE agent_scheduling_bookings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,status TEXT,start_at_utc TEXT,end_at_utc TEXT,
        event_title TEXT,location_value TEXT,organizer_timezone TEXT,source TEXT,schedule_id INTEGER);
    CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,
        end_at_utc TEXT,timezone TEXT,source TEXT,source_reference TEXT,status TEXT,recurrence TEXT,agent_notifications INTEGER);
    INSERT INTO user_calendar_events VALUES(801,2,'Other owner event','','','2026-11-06 12:00:00','2026-11-06 13:00:00','UTC','manual','','active','none',0);");

$checks=0;
function readiness_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function readiness_read(array $user,array $principal,?array $refs=null): array
{
    $coverage=[];$rows=vp3_initiative_readiness_evidence_v1(db(),$user,$principal,new DateTimeImmutable('2026-11-05T12:00:00Z'),$coverage,$refs);
    return [$rows,$coverage];
}
$owner=['id'=>1,'is_active'=>1];$system=vp3_agent_chat_principal_v380($owner,null);$named=vp3_agent_chat_principal_v380($owner,user_agent_get_v236($pdo,1,11));
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
[$all,$coverage]=readiness_read($owner,$system);
readiness_check(isset($all['account-readiness:1'])&&$all['account-readiness:1']['readiness']['missing_fields']===['profile'],'canonical onboarding skill contributes the exact missing required identity choice');
readiness_check(isset($all['scheduling-readiness:1:0'])&&$all['scheduling-readiness:1:0']['readiness']['missing_fields']===['schedule','meeting_type'],'selected booking workflow contributes existing scheduling readiness');
readiness_check(isset($all['product-readiness:101'],$all['product-readiness:102'])&&!isset($all['product-readiness:201']),'canonical product reader filters another owner’s workspace product from private evidence');
readiness_check($all['product-readiness:101']['readiness']['source_asset_id']===301&&in_array('price',$all['product-readiness:101']['readiness']['missing_fields'],true),'private photo listing retains only owned canonical media and unfinished terms');
readiness_check($all['product-readiness:102']['readiness']['product_media_link']==='not_assessed'&&$all['product-readiness:102']['readiness']['promotion_state']==='not_assessed','no recorded media link is not falsely reported as no image or no external promotion');
readiness_check(isset($all['business-readiness:401'])&&!isset($all['business-readiness:402'])&&$all['business-readiness:401']['readiness']['promotion_state']==='no_active_campaign_recorded','owned merchant readiness distinguishes known VP3 campaign state from other accounts');
readiness_check($all['calendar-readiness:1']['readiness']['upcoming_events']===0&&$coverage['calendar_readiness']==='ready',
    'the shared calendar reader verifies an empty Cloud calendar without including another account’s events');
readiness_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,'evidence collection does not create profiles, preferences, products, campaigns or task records');
[$calendarExact]=readiness_read($owner,$system,['calendar-readiness:1']);
readiness_check($calendarExact===['calendar-readiness:1'=>$all['calendar-readiness:1']],
    'the empty calendar ref rebuilds from the same scoped reader');
$pdo->exec("INSERT INTO agent_scheduling_bookings VALUES(901,1,'pending','2026-11-07 12:00:00','2026-11-07 13:00:00','Test booking','','UTC','manual',0)");
[$calendarNow]=readiness_read($owner,$system,['calendar-readiness:1']);
readiness_check(!$calendarNow,'a new pending booking invalidates the absence observation');
$pdo->exec('DELETE FROM agent_scheduling_bookings WHERE id=901');
$pdo->exec("INSERT INTO user_calendar_events VALUES(802,1,'Recurring event','','','2026-10-23 12:00:00','2026-10-23 13:00:00','UTC','manual','','active','weekly',0)");
[$calendarNow]=readiness_read($owner,$system,['calendar-readiness:1']);
readiness_check(!$calendarNow,'a weekly occurrence in the checked window invalidates the absence observation');
$pdo->exec('DELETE FROM user_calendar_events WHERE id=802');
[$again]=readiness_read($owner,$system);
readiness_check(array_column($again,'revision','ref')===array_column($all,'revision','ref'),'source revisions remain stable across unchanged reads');
[$one,$singleCoverage]=readiness_read($owner,$system,['product-readiness:101']);
readiness_check(array_keys($one)===['product-readiness:101']&&array_keys($singleCoverage)===['product_readiness'],'exact source revalidation does not expand into unrelated account or business records');
$revision=$one['product-readiness:101']['revision'];$pdo->exec("UPDATE agent_commerce_products_v800 SET description='Owner revised the listing' WHERE id=101");
[$one]=readiness_read($owner,$system,['product-readiness:101']);
readiness_check($one['product-readiness:101']['revision']!==$revision,'a real listing change invalidates previously observed evidence');
$pdo->exec('UPDATE user_media_assets SET user_id=2 WHERE id=301');
[$one]=readiness_read($owner,$system,['product-readiness:101']);
readiness_check($one['product-readiness:101']['readiness']['source_asset_id']===null&&in_array('source_photo',$one['product-readiness:101']['readiness']['missing_fields'],true),'media ownership changes remove the source photo instead of crossing owner boundaries');
$insert=$pdo->prepare("INSERT INTO agent_commerce_products_v800 VALUES(?,1,NULL,?,'Another product','Prepared description','service','virtual','full',100,1,'{\"profile_visibility\":\"public\"}','2026-11-05')");
for($i=1000;$i<1030;$i++)$insert->execute([$i,'bounded-'.$i]);
[$bounded,$boundedCoverage]=readiness_read($owner,$system);
readiness_check(count(array_filter(array_keys($bounded),static fn($ref)=>str_starts_with($ref,'product-readiness:')))===12&&$boundedCoverage['product_readiness']==='limited'&&!isset($bounded['product-readiness:101']),'discovery is bounded and reports limited coverage instead of complete inventory');
[$one]=readiness_read($owner,$system,['product-readiness:101']);
readiness_check(isset($one['product-readiness:101']),'selected exact source reconstructs outside the discovery window for stale-source checks');
[$one,$singleCoverage]=readiness_read($owner,$system,['business-readiness:402']);
readiness_check($one===[]&&$singleCoverage['business_readiness']==='ready','selected business ref cannot disclose another owner’s merchant profile');
[$namedRows,$namedCoverage]=readiness_read($owner,$named);
readiness_check(isset($namedRows['account-readiness:1'],$namedRows['scheduling-readiness:1:11'])&&$namedCoverage['product_readiness']==='forbidden'&&$namedCoverage['business_readiness']==='forbidden','named Agent receives permitted setup context while existing System-only Commerce boundaries remain intact');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(11,'profile','*','deny')");
[$namedRows,$namedCoverage]=readiness_read($owner,$named,['account-readiness:1']);
readiness_check($namedRows===[]&&$namedCoverage['account_readiness']==='forbidden','named Agent profile policy denial is respected before onboarding reads');
$pdo->exec('UPDATE user_agent_preferences SET onboarding_dismissed=1 WHERE user_id=1');
[$one,$singleCoverage]=readiness_read($owner,$system,['account-readiness:1']);
readiness_check($one===[]&&$singleCoverage['account_readiness']==='ready','dismissed onboarding stays quiet instead of reopening owner choices');
$pdo->exec("UPDATE user_profiles SET username='owner' WHERE user_id=1; UPDATE user_agent_preferences SET onboarding_step='complete' WHERE user_id=1");
[$profileReady]=readiness_read($owner,$system,['account-readiness:1']);
$profileEvidence=$profileReady['account-readiness:1'];
readiness_check($profileEvidence['readiness']['bio']==='Collects local history'&&!$profileEvidence['readiness']['requires_owner_input']
    &&!str_contains(json_encode($profileEvidence,JSON_THROW_ON_ERROR),'PRIVATE_PROFILE_AGENT_INSTRUCTIONS'),
    'completed onboarding can contribute bounded profile conversation context without reopening setup');
[$namedRows,$namedCoverage]=readiness_read($owner,$named,['account-readiness:1']);
readiness_check(!$namedRows&&$namedCoverage['account_readiness']==='forbidden','profile revocation also prevents a completed profile context from being rebuilt');
$pdo->exec("UPDATE user_profiles SET bio='Archives neighborhood photographs' WHERE user_id=1");
[$profileChanged]=readiness_read($owner,$system,['account-readiness:1']);
readiness_check($profileChanged['account-readiness:1']['revision']!==$profileEvidence['revision'],'a saved profile change invalidates a previously selected profile observation');
$pdo->exec("UPDATE user_agent_preferences SET feature_interest_json='{}' WHERE user_id=1");
[$one,$singleCoverage]=readiness_read($owner,$system,['scheduling-readiness:1:0']);
readiness_check(!$one['scheduling-readiness:1:0']['readiness']['workflow_selected']
    &&!$one['scheduling-readiness:1:0']['readiness']['requires_owner_input']&&$singleCoverage['scheduling_readiness']==='ready',
    'verified absence of appointment types is optional context when booking was not selected');
$pdo->exec("UPDATE user_agent_preferences SET feature_interest_json='{\"activation.dismiss.workflow.booking\":true}' WHERE user_id=1");
[$one]=readiness_read($owner,$system,['scheduling-readiness:1:0']);
readiness_check(!$one,'explicitly dismissed booking does not become an optional prompt');
$pdo->exec("UPDATE user_agent_preferences SET feature_interest_json='{\"workflow.booking\":true,\"activation.defer.workflow.booking\":\"2027-01-01T00:00:00Z\"}' WHERE user_id=1");
[$one,$singleCoverage]=readiness_read($owner,$system,['scheduling-readiness:1:0']);
readiness_check($one===[]&&$singleCoverage['scheduling_readiness']==='ready','owner’s saved booking deferral stays quiet at the observation time');
$pdo->exec("UPDATE user_agent_preferences SET feature_interest_json='{\"workflow.booking\":true}' WHERE user_id=1; INSERT INTO agent_scheduling_schedules VALUES(501,1,11,1,1); INSERT INTO agent_scheduling_event_types VALUES(601,501,1,0,'Consultation')");
[$one,$singleCoverage]=readiness_read($owner,$named,['scheduling-readiness:1:11']);
readiness_check($one===[]&&$singleCoverage['scheduling_readiness']==='ready','canonical named schedule and active meeting type resolve the preparation gap');
$pdo->exec("UPDATE merchant_profiles SET description='Owner description',website_url='https://owner.test' WHERE merchant_id=401; INSERT INTO campaigns VALUES(701,401,'Launch','active','production',NULL,NULL,'2026-11-05')");
[$one,$singleCoverage]=readiness_read($owner,$system,['business-readiness:401']);
readiness_check($one===[]&&$singleCoverage['business_readiness']==='ready','business profile and real active campaign resolve the recorded readiness opportunity');
$GLOBALS['readiness_plugin_disabled']=[1];
[$one,$singleCoverage]=readiness_read($owner,$system,['business-readiness:401']);
readiness_check($one===[]&&$singleCoverage['business_readiness']==='forbidden','revoked campaign plugin access is not mistaken for an empty business inventory');
unset($GLOBALS['readiness_plugin_disabled']);
$pdo->exec('DROP TABLE user_media_assets');
[$one,$singleCoverage]=readiness_read($owner,$system,['product-readiness:101']);
readiness_check($one===[]&&$singleCoverage['product_readiness']==='unavailable','missing linked media storage is unavailable rather than evidence that the photo does not exist');
$pdo->exec('DELETE FROM agent_commerce_products_v800 WHERE id=102');
[$one,$singleCoverage]=readiness_read($owner,$system,['product-readiness:102']);
readiness_check($one===[]&&$singleCoverage['product_readiness']==='ready','deleted exact product source becomes stale without widening the read');
[$one]=readiness_read(['id'=>2,'is_active'=>1],vp3_agent_chat_principal_v380(['id'=>2],null),['product-readiness:101']);
readiness_check($one===[],'another owner cannot reconstruct a product ref from the first owner');
$pdo->exec('DELETE FROM agent_commerce_products_v800 WHERE owner_user_id=1');
[$one,$singleCoverage]=readiness_read($owner,$system,['product-readiness:owner:1']);
readiness_check($one['product-readiness:owner:1']['readiness']['owned_products']===0&&$singleCoverage['product_readiness']==='ready',
    'the shared catalog verifies zero owned products without counting a different workspace owner’s product');
[$one,$singleCoverage]=readiness_read($owner,$named,['product-readiness:owner:1']);
readiness_check(!$one&&$singleCoverage['product_readiness']==='forbidden','the absent product projection preserves the System-only commerce permission');
$insert->execute([1100,'first-product']);
[$one]=readiness_read($owner,$system,['product-readiness:owner:1']);
readiness_check(!$one,'the first owned product invalidates empty-catalog advice without disclosing unrelated product records');
$pdo->exec('DROP TABLE agent_scheduling_bookings');
[$one,$singleCoverage]=readiness_read($owner,$system,['calendar-readiness:1']);
readiness_check(!$one&&$singleCoverage['calendar_readiness']==='unavailable','missing Cloud booking storage cannot masquerade as an empty calendar');
$pdo->exec('DROP TABLE user_agent_preferences');
[$one,$singleCoverage]=readiness_read($owner,$system,['account-readiness:1']);
readiness_check($one===[]&&$singleCoverage['account_readiness']==='unavailable','missing canonical onboarding storage is unavailable instead of a fabricated setup gap');
foreach([
    ['id'=>1],['kind'=>'profile_agent','agent_id'=>11,'owner_user_id'=>1,'viewer_user_id'=>1],
    ['kind'=>'user_agent','agent_id'=>21,'owner_user_id'=>2,'viewer_user_id'=>1],
    ['kind'=>'system','agent_id'=>0,'viewer_user_id'=>2],
] as $invalid){
    try{readiness_read($owner,$invalid);throw new RuntimeException('invalid principal accepted');}
    catch(RuntimeException $e){readiness_check($e->getCode()===403,'invalid or cross-owner principal is rejected');}
}
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
try{readiness_read($owner,$system);throw new RuntimeException('stale active user accepted');}
catch(RuntimeException $e){readiness_check($e->getCode()===403,'fresh canonical account read rejects a deactivated owner');}
echo 'INITIATIVE_READINESS=PASS ('.$checks.' cases)'.PHP_EOL;
