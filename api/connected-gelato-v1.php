<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/connected-gelato-v1.php';
require_once dirname(__DIR__).'/includes/connected-stoneward-v1.php';
require_once dirname(__DIR__).'/includes/agent-media-stoneward-v1.php';

function vp3_gelato_api_v1(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function vp3_connected_site_build_v1(): string
{
    $releasePath = dirname(__DIR__).'/VP3_RELEASE.json';
    $manifest = is_readable($releasePath) ? json_decode((string)file_get_contents($releasePath, false, null, 0, 8192), true) : null;
    $overlay = $manifest['overlay_id'] ?? '';
    if (is_string($overlay) && preg_match('/^[a-f0-9]{12}$/D', $overlay)) return $overlay;
    $commit = $manifest['commit'] ?? '';
    return is_string($commit) && preg_match('/^[a-f0-9]{40}$/D', $commit) ? substr($commit, 0, 12) : '';
}

function vp3_stoneward_api_diagnostic_v1(string $origin, ?array $response, string $reason): array
{
    return [
        'origin'=>$origin,
        'checkedAt'=>gmdate(DATE_ATOM),
        'stage'=>'discovery',
        'reason'=>$reason,
        'artistSiteHttpStatus'=>$response['status'] ?? 0,
        'vp3Build'=>vp3_connected_site_build_v1(),
    ] + (isset($response['visit']) ? ['visit'=>$response['visit']] : []);
}

$user = current_user();
if (!$user || !has_permission('account.access', $user) || !has_permission('chat.access', $user)) {
    vp3_gelato_api_v1(403, ['ok'=>false,'error'=>'Sign in to your VP3 account to manage connected sites.']);
}
$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = db();
$providers = [
    'foodmonster'=>$pdo && vp3_gelato_schema_ready_v1($pdo),
    'stoneward'=>$pdo && vp3_stoneward_schema_ready_v1($pdo),
];
$available = in_array(true,$providers,true);
$knowledgeReady = $providers['foodmonster'] && vp3_gelato_knowledge_ready_v1($pdo);
$canReadKnowledge = $knowledgeReady && function_exists('personal_capability_has_v242')
    && personal_capability_has_v242('personal_knowledge.access', $user);
$canCreateKnowledgeFolder = $canReadKnowledge && personal_capability_has_v242('personal_knowledge.manage', $user);
if ($method === 'GET') {
    $report=null;
    if(isset($_GET['agent_id'])||isset($_GET['conversation_id'])){
        $agentId=$_GET['agent_id']??null;$conversationId=$_GET['conversation_id']??null;
        if(!is_string($agentId)||!preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D',$agentId)
            ||!is_string($conversationId)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',$conversationId)){
            vp3_gelato_api_v1(422,['ok'=>false,'error'=>'Open a saved Agent Chat to see this report.']);
        }
        if(!$pdo)vp3_gelato_api_v1(503,['ok'=>false,'error'=>'Connected Sites is currently unavailable.']);
        require_once dirname(__DIR__).'/includes/agent-activity-scope-v1.php';
        require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
        $pageBinding=(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??'');
        if($pageBinding===''||!hash_equals(vp3_catch_up_page_binding_v1($user),$pageBinding)){
            vp3_gelato_api_v1(403,['ok'=>false,'error'=>'The Chat session changed.']);
        }
        try{
            $scope=vp3_agent_activity_scope_v1($pdo,$user,(int)$agentId,(int)$conversationId);
        }catch(RuntimeException $error){
            if($error instanceof PDOException)vp3_gelato_api_v1(503,['ok'=>false,'error'=>'Connected Sites is currently unavailable.']);
            vp3_gelato_api_v1(403,['ok'=>false,'error'=>'This Agent Chat is unavailable.']);
        }
        try{
            require_once dirname(__DIR__).'/includes/connected-sites-reports-v1.php';
            $report=vp3_connected_sites_report_v1($pdo,$scope);
        }catch(Throwable $error){
            $report=['state'=>'unavailable','connections'=>[]];
        }
    }
    try {
        $connections = $providers['foodmonster'] ? vp3_gelato_connections_v1($pdo,$userId) : [];
        $folders = $canReadKnowledge ? vp3_gelato_knowledge_folders_v1($pdo, $userId) : [];
        foreach ($connections as &$connection) {
            $connection['knowledgeFolder'] = $folders[$connection['id']] ?? null;
            if(isset($report['connections'][$connection['id']]))$connection['report']=$report['connections'][$connection['id']];
        }
        unset($connection);
        $artistSites = $providers['stoneward'] ? vp3_stoneward_connections_v1($pdo,$userId) : [];
        if($artistSites){
            $suggestionsReady=vp3_connected_sites_report_ready_v1($pdo);
            $suggestions=$suggestionsReady?vp3_stoneward_media_rows_v1($pdo,$userId):[];
            foreach($artistSites as &$site){
                $row=$suggestions[$site['id']]??null;
                $site['mediaSuggestionsAvailable']=$suggestionsReady;
                $site['mediaSuggestions']=$row!==null;
                if($report!==null){
                    $onDemand=$row===null||$row['state']==='on_demand';
                    $state=$onDemand?'on_demand':vp3_connected_sites_report_state_v1($row,time());
                    $site['report']=['state'=>$state,'datasets'=>[
                        ['id'=>'listening.history.read','label'=>'Personal listening history','access'=>'read',
                            'mode'=>$onDemand?'on_demand':'proactive','state'=>$state],
                    ],'locations'=>[],
                        'lastCheckedAt'=>vp3_connected_sites_report_iso_v1($row['checked_at']??null),
                        'lastVerifiedAt'=>vp3_connected_sites_report_iso_v1($row['verified_at']??null)];
                }
            }
            unset($site);
            if($report!==null&&!$connections)$report['state']='ready';
        }
        $connections=array_merge($connections,$artistSites);
    } catch (Throwable) {
        vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Connected Sites is currently unavailable.']);
    }
    vp3_gelato_api_v1(200, ['ok'=>true,'available'=>$available,'connections'=>$connections,
        'providers'=>$providers,'canCreateKnowledgeFolder'=>$canCreateKnowledgeFolder]
        +($report===null?[]:['connectedSitesReportState'=>$report['state']]));
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    vp3_gelato_api_v1(405, ['ok'=>false,'error'=>'GET or POST required.']);
}
if (!$available) {
    vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Connected Sites is unavailable. Run the VP3 Database Upgrade.']);
}

$raw = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($raw) || strlen($raw) > 4096) {
    vp3_gelato_api_v1(413, ['ok'=>false,'error'=>'Connection request is too large.']);
}
$input = json_decode($raw, true, 12);
if (!is_array($input)) vp3_gelato_api_v1(400, ['ok'=>false,'error'=>'Enter a valid connection request.']);
$csrf = $input['csrf_token'] ?? null;
if (!is_string($csrf) || $csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    vp3_gelato_api_v1(419, ['ok'=>false,'error'=>'Session expired. Refresh and try again.']);
}
$provider = array_key_exists('provider',$input) ? $input['provider'] : 'foodmonster';
if (!is_string($provider) || !array_key_exists($provider,$providers)) {
    vp3_gelato_api_v1(422,['ok'=>false,'error'=>'Choose a supported platform.']);
}
if (!$providers[$provider]) {
    vp3_gelato_api_v1(503,['ok'=>false,'error'=>'Run VP3 Database Upgrade to enable this platform.']);
}

$checkOrigin = null;
$discoveryResponse = null;
$observeDiscovery = static function (int $status, string $contentType, array $headers, string $ip) use (&$discoveryResponse): void {
    $discoveryResponse = ['status'=>$status,'visit'=>vp3_stoneward_diagnostic_visit_v1($contentType, $headers, $ip)];
};

try {
    if ($provider === 'stoneward' && ($input['action'] ?? null) === 'checkConnection'
        && !array_diff(array_keys($input), ['action','csrf_token','provider','origin'])
        && is_string($input['origin'] ?? null)) {
        $checkOrigin = vp3_connected_site_origin_v1($input['origin'], 'Stonefellow');
        vp3_stoneward_check_connection_v1($checkOrigin, $observeDiscovery);
        vp3_gelato_api_v1(200, ['ok'=>true,'diagnostic'=>vp3_stoneward_api_diagnostic_v1($checkOrigin, $discoveryResponse, 'verified')]);
    }
    if (($input['action'] ?? null) === 'connect'
        && !array_diff(array_keys($input), ['action','csrf_token','origin','code','label','provider'])
        && is_string($input['origin'] ?? null) && is_string($input['code'] ?? null)
        && is_string($input['label'] ?? '')) {
        $connection = $provider==='stoneward'
            ? vp3_stoneward_connect_v1($pdo,$userId,$input['origin'],$input['code'],$input['label']??'',observeDiscovery:$observeDiscovery)
            : vp3_gelato_connect_v1($pdo,$userId,$input['origin'],$input['code'],$input['label']??'',
                createKnowledgeFolder:$canCreateKnowledgeFolder);
        vp3_gelato_api_v1(201, ['ok'=>true,'connection'=>$connection]);
    }
    if (($input['action'] ?? null) === 'createKnowledgeFolder'
        && !array_diff(array_keys($input), ['action','csrf_token','connectionId'])
        && is_string($input['connectionId'] ?? null)) {
        if (!$knowledgeReady) {
            vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Knowledge folders require the VP3 database upgrade.']);
        }
        if (!$canCreateKnowledgeFolder) {
            vp3_gelato_api_v1(403, ['ok'=>false,'error'=>'Personal Knowledge management is unavailable for this account.']);
        }
        $folder = vp3_gelato_create_knowledge_folder_v1($pdo, $userId, $input['connectionId']);
        vp3_gelato_api_v1(200, ['ok'=>true,'knowledgeFolder'=>$folder]);
    }
    if ($provider==='stoneward' && ($input['action'] ?? null)==='setMediaSuggestions'
        && !array_diff(array_keys($input),['action','csrf_token','provider','connectionId','enabled'])
        && is_string($input['connectionId']??null) && is_bool($input['enabled']??null)) {
        vp3_stoneward_media_consent_v1($pdo,$user,$input['connectionId'],$input['enabled']);
        vp3_gelato_api_v1(200,['ok'=>true,'mediaSuggestions'=>$input['enabled']]);
    }
    if (($input['action'] ?? null) === 'disconnect'
        && !array_diff(array_keys($input), ['action','csrf_token','connectionId','provider'])
        && is_string($input['connectionId'] ?? null)) {
        $revoked = $provider==='stoneward' ? vp3_stoneward_disconnect_v1($pdo,$userId,$input['connectionId'])
            : vp3_gelato_disconnect_v1($pdo,$userId,$input['connectionId']);
        vp3_gelato_api_v1(200, ['ok'=>true,'revokedRemotely'=>$revoked]);
    }
    vp3_gelato_api_v1(400, ['ok'=>false,'error'=>'Unsupported Connected Sites request.']);
} catch (Vp3StonewardPairingErrorV1 $e) {
    $diagnostic = [];
    if ($e->stage === 'discovery') {
        $origin = $checkOrigin ?? vp3_connected_site_origin_v1($input['origin'], 'Stonefellow');
        $diagnostic['diagnostic'] = vp3_stoneward_api_diagnostic_v1($origin, $discoveryResponse, $e->reason);
    }
    vp3_gelato_api_v1($e->getCode(), ['ok'=>false,'error'=>$e->getMessage(),'pairing'=>[
        'stage'=>$e->stage, 'reason'=>$e->reason, 'artistSiteHttpStatus'=>$e->siteHttpStatus,
        'vp3Build'=>$diagnostic['diagnostic']['vp3Build'] ?? vp3_connected_site_build_v1(),
    ]] + $diagnostic);
} catch (InvalidArgumentException $e) {
    vp3_gelato_api_v1(422, ['ok'=>false,'error'=>$e->getMessage()]);
} catch (RuntimeException $e) {
    if ($checkOrigin !== null && !$e instanceof PDOException) {
        [$status, $reason, $message] = match (true) {
            $e->getCode() === 422 => [422,'public_address_required','The artist domain must resolve only to public internet addresses.'],
            $e->getCode() === 503 => [503,'check_unavailable','VP3 could not run the artist-site check.'],
            empty($discoveryResponse['status']) => [502,'no_http_response','VP3 did not receive an HTTP response from the artist site.'],
            default => [502,'unexpected_response','The artist site did not return a verified Stonefellow discovery response.'],
        };
        vp3_gelato_api_v1($status, ['ok'=>false,'error'=>$message,
            'diagnostic'=>vp3_stoneward_api_diagnostic_v1($checkOrigin, $discoveryResponse, $reason)]);
    }
    $status = $e->getCode();
    if (!$e instanceof PDOException && in_array($status, [403,404,409,422,502,503], true)) {
        vp3_gelato_api_v1($status, ['ok'=>false,'error'=>$e->getMessage()]);
    }
    vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Connected Sites is currently unavailable.']);
} catch (Throwable) {
    vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Connected Sites is currently unavailable.']);
}
