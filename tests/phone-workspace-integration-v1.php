<?php
declare(strict_types=1);

/** Actual header/runtime rendering against current persisted assignment state. No provider calls. */
require dirname(__DIR__).'/includes/phone-workspace-v1.php';
require dirname(__DIR__).'/includes/phone-workspace-ui-v1.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/member-navigation.php';

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,account_access INTEGER,chat_access INTEGER)');
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT)');
$pdo->exec('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider TEXT,owner_user_id INTEGER,agent_id INTEGER,status TEXT,voice_enabled INTEGER)');
$pdo->exec('INSERT INTO users VALUES (1,1,1,1),(2,1,1,1),(3,0,1,1),(4,1,0,1),(5,1,1,0)');
$pdo->exec("INSERT INTO user_agents VALUES (7,1,1,'Owner Agent'),(8,2,1,'Other Agent'),(9,1,0,'Inactive Agent'),(10,3,1,'Inactive owner Agent'),(11,4,1,'No account access'),(12,5,1,'No Chat access')");
$GLOBALS['phoneUiEntitlements']=array_fill_keys([1,2,3,4,5],['enabled'=>true,'limit'=>1,'unlimited'=>false]);
$GLOBALS['phoneUiBase']='/installed-vp3';

function db(): PDO {return $GLOBALS['pdo'];}
function has_permission(string $permission,?array $user=null): bool
{return (bool)($user[$permission==='account.access'?'account_access':'chat_access']??false);}
function subscription_has_entitlement(?array $user,string $key): bool
{throw new LogicException('The composed entitlement decision must govern the Phone entry point.');}
function subscription_effective_entitlement_v340(?array $user,string $key): array
{return $GLOBALS['phoneUiEntitlements'][(int)($user['id']??0)]??['enabled'=>false,'limit'=>0,'unlimited'=>false];}
function url(string $path=''): string {return $GLOBALS['phoneUiBase'].($path===''?'/':$path);}
function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf_token(): string {return $GLOBALS['phoneUiCsrf']??'fixture-</script>-&-token';}
function phone_ui_check(bool $ok,string $message): void
{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
function phone_ui_user(int $id): array
{$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch()?:['id'=>$id,'is_active'=>1,'account_access'=>1,'chat_access'=>1];}
function phone_ui_reset_runtime(): void {unset($GLOBALS['VP3_PHONE_WORKSPACE_RUNTIME_V1']);}

$owner=phone_ui_user(1);
phone_ui_check(!vp3_phone_workspace_available_v1($pdo,$owner),'A plan-only owner cannot use Phone without an assigned number.');
phone_ui_check(vp3_phone_workspace_button_v1($owner)===''&&vp3_phone_workspace_runtime_v1($owner)==='','Unassigned members receive neither a Phone button nor its runtime/configuration.');
phone_ui_check(empty($GLOBALS['VP3_PHONE_WORKSPACE_RUNTIME_V1']),'A denied render does not consume the runtime-once guard.');

$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES (1,'twilio',1,9,'unconfigured',0)");
phone_ui_check(!vp3_phone_workspace_available_v1($pdo,$owner),'An inactive Agent cannot authorize Phone.');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=8 WHERE id=1');
phone_ui_check(!vp3_phone_workspace_available_v1($pdo,$owner),'A foreign Agent cannot authorize Phone for the number owner.');
$pdo->exec("UPDATE phone_cloud_numbers_v100 SET agent_id=7,provider='unsupported' WHERE id=1");
phone_ui_check(!vp3_phone_workspace_available_v1($pdo,$owner),'An unsupported provider cannot create a Phone entry point.');
$pdo->exec("UPDATE phone_cloud_numbers_v100 SET provider='twilio' WHERE id=1");
phone_ui_check(vp3_phone_workspace_available_v1($pdo,$owner),'An administrator-assigned number exposes its workspace while carrier activation remains separately controlled.');
$GLOBALS['phoneUiEntitlements'][1]=['enabled'=>false,'limit'=>1,'unlimited'=>false];
phone_ui_check(!vp3_phone_workspace_available_v1($pdo,$owner)&&vp3_phone_workspace_button_v1($owner)==='','A saved assignment cannot bypass a revoked composed phone entitlement.');
$GLOBALS['phoneUiEntitlements'][1]=['enabled'=>true,'limit'=>0,'unlimited'=>false];
phone_ui_check(!vp3_phone_workspace_available_v1($pdo,$owner)&&vp3_phone_workspace_runtime_v1($owner)==='','Zero number capacity cannot expose Phone even when the entitlement enabled flag is true.');
$GLOBALS['phoneUiEntitlements'][1]=['enabled'=>true,'limit'=>0,'unlimited'=>true];
phone_ui_check(vp3_phone_workspace_available_v1($pdo,$owner),'An explicitly unlimited number allowance still requires and recognizes the actual assignment.');
$GLOBALS['phoneUiEntitlements'][1]=['enabled'=>true,'limit'=>1,'unlimited'=>false];
$button=vp3_phone_workspace_button_v1($owner);
phone_ui_check(str_contains($button,'data-phone-workspace-toggle')&&str_contains($button,'aria-label="Phone"')&&str_contains($button,'aria-expanded="false"'),'Assigned owners receive the accessible shared Phone toggle.');

$runtime=vp3_phone_workspace_runtime_v1($owner,7,42);
phone_ui_check(preg_match('/window\.VP3_PHONE_WORKSPACE_V1=(.*?);<\/script>/s',$runtime,$match)===1,'The assigned member runtime includes a readable configuration.');
$config=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
phone_ui_check(($config['agent_id']??null)===7&&($config['conversation_id']??null)===42,'The rendered configuration preserves the real selected Agent/conversation using the frontend API keys.');
phone_ui_check(($config['endpoint']??'')==='/installed-vp3/api/phone-workspace-v1.php'&&($config['sdk_url']??'')==='/installed-vp3/assets/twilio-voice-2.18.5.min.js','Subdirectory installations use their own API and vendored SDK paths.');
phone_ui_check(($config['csrf']??'')===csrf_token()&&!str_contains($match[1],'</script>'),'CSRF survives JSON decoding without breaking out of the inline configuration.');
phone_ui_check(($config['attention_binding']??'')===vp3_catch_up_page_binding_v1($owner)&&($config['context_endpoint']??'')==='/installed-vp3/api/agent-phone-shell-v1.php','Cross-page context requests use the real account binding and installation path.');
phone_ui_check(($config['continuity_role']??'')==='view'&&($config['continuity_url']??'')==='/installed-vp3/phone-call-window-v1.php?owner=1','Member and Admin runtimes point to the account-bound persistent Phone window.');
phone_ui_check(strpos($runtime,'phone-call-continuity-v1.js')<strpos($runtime,'<script data-phone-workspace-v1'),'Call ownership is established before the native SDK client can initialize.');
phone_ui_check(str_contains($runtime,'data-voice-capture-scope="1"')&&strpos($runtime,'voice-lease-v122.js')<strpos($runtime,'<script data-phone-workspace-v1'),'Every page loads the shared owner microphone lease before the Phone client.');
phone_ui_check(substr_count($runtime,'data-phone-workspace-v1')===2&&preg_match('/\?v=[a-f0-9]{12}/',$runtime)===1,'One render supplies the stylesheet and versioned frontend script.');
phone_ui_check(str_contains($runtime,'/installed-vp3/phone-operator-voice-mixer-v1.js?v=')&&strpos($runtime,'data-phone-operator-voice-mixer')<strpos($runtime,'<script data-phone-workspace-v1'),'The optional outbound mixer loads before the existing phone client.');
phone_ui_check(vp3_phone_workspace_runtime_v1($owner,7,42)==='','Repeated shared header calls do not duplicate the Phone runtime.');

foreach([3=>10,4=>11,5=>12] as $userId=>$agentId){
    $pdo->prepare("INSERT INTO phone_cloud_numbers_v100 VALUES (?,'twilio',?,?,'active',1)")->execute([$userId,$userId,$agentId]);
    $stale=phone_ui_user($userId);$stale['is_active']=1;$stale['account_access']=1;$stale['chat_access']=1;
    phone_ui_reset_runtime();
    phone_ui_check(!vp3_phone_workspace_available_v1($pdo,$stale)&&vp3_phone_workspace_button_v1($stale)===''&&vp3_phone_workspace_runtime_v1($stale)==='','Persisted account status/permissions override stale session data for user '.$userId.'.');
}

$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');
phone_ui_reset_runtime();
phone_ui_check(vp3_phone_workspace_button_v1($owner)===''&&vp3_phone_workspace_runtime_v1($owner)==='','Revoking the last assignment removes both entry point and runtime on the next render.');
phone_ui_check(vp3_phone_workspace_button_v1(phone_ui_user(2))!=='','The actual current assignee receives the entry point.');
phone_ui_check(!vp3_phone_workspace_available_v1($pdo,phone_ui_user(999)),'A deleted account cannot use a stale assignment/session.');

unset($GLOBALS['VP3_AGENT_PHONE_SHELL_ASSETS_V1']);$_SERVER['SCRIPT_NAME']='/chat.php';
$memberAssets=vp3_agent_phone_shell_assets_v1($owner);
phone_ui_check(str_contains($memberAssets,'chat-sidebar-unread-v1.js')&&str_contains($memberAssets,'chat-sidebar-unread-v1.css'),'The existing authenticated Agent bootstrap supplies member sidebar assets.');
phone_ui_check(preg_match('/window\.VP3_CHAT_SIDEBAR_UNREAD_V1=(.*?);<\/script>/s',$memberAssets,$sidebarMatch)===1,'The member sidebar receives an authenticated count configuration.');
$sidebarConfig=json_decode($sidebarMatch[1],true,512,JSON_THROW_ON_ERROR);
phone_ui_check($sidebarConfig===['owner_id'=>1,'binding'=>vp3_catch_up_page_binding_v1($owner),'endpoint'=>'/installed-vp3/api/agent-chat-read-v1.php'],'Sidebar counts reuse the native owner/session binding and installation path.');
unset($GLOBALS['VP3_AGENT_PHONE_SHELL_ASSETS_V1']);$_SERVER['SCRIPT_NAME']='/admin/phone.php';
$adminAssets=vp3_agent_phone_shell_assets_v1($owner);
phone_ui_check(!str_contains($adminAssets,'chat-sidebar-unread-v1')&&str_contains($adminAssets,'agent-phone-shell-v1.js'),'Admin keeps personal phone activity without the public member Chat badge widget.');

foreach($argv as $arg)if(str_starts_with($arg,'--emit-ui=')){
    $target=substr($arg,10);
    if($target===''||!is_dir(dirname($target)))throw new RuntimeException('Choose an existing fixture output directory.');
    // Use the same actual MAIN context and public origin as the backend state
    // fixture, while keeping the normal assertions above on subdirectory URLs.
    $pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7 WHERE id=1');
    $GLOBALS['phoneUiBase']='https://cloud.example';$GLOBALS['phoneUiCsrf']='fixture-csrf';phone_ui_reset_runtime();
    $actualButton=vp3_phone_workspace_button_v1($owner);
    $actualRuntime=vp3_phone_workspace_runtime_v1($owner,0,11);
    if(!preg_match('/window\.VP3_PHONE_WORKSPACE_V1=(.*?);<\/script>/s',$actualRuntime,$actualConfig))throw new RuntimeException('The actual Phone renderer did not emit its configuration.');
    $encoded=json_encode(['button'=>$actualButton,'config'=>json_decode($actualConfig[1],true,512,JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    if(file_put_contents($target,$encoded)!==strlen($encoded))throw new RuntimeException('The actual Phone UI fixture could not be saved.');
}

echo 'PASS Phone workspace release integration: '.($GLOBALS['checks']??0)." checks\n";
