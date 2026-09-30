<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_permission('account.access');

$pdo=db();
$user=current_user();
if(!$pdo||!$user){
    flash('hosting_error','Your account could not be loaded.');
    redirect(url('/login.php'));
}
if(!vp3_cloud_hosting_v120_schema_ready($pdo))vp3_cloud_hosting_v120_ensure_schema($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){
        flash('hosting_error','Your session expired. Please try again.');
        redirect(url('/hosting.php'));
    }
    try{
        $action=(string)($_POST['action']??'');
        $packageBytes=null;
        if($action==='deployment.deploy'){
            $file=(array)($_FILES['deployment_zip']??[]);
            $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
            if($error!==UPLOAD_ERR_OK)throw new RuntimeException($error===UPLOAD_ERR_NO_FILE?'Choose a deployment ZIP package.':'The deployment ZIP upload failed.');
            $name=(string)($file['name']??'');
            if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip')throw new RuntimeException('Deployment package must use the .zip extension.');
            $size=(int)($file['size']??0);
            if($size<1||$size>VP3_CLOUD_HOSTING_MAX_PACKAGE_BYTES)throw new RuntimeException('Deployment ZIP must be between 1 byte and 64 MiB.');
            $tmp=(string)($file['tmp_name']??'');
            if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Deployment upload could not be verified.');
            $packageBytes=file_get_contents($tmp);
            if(!is_string($packageBytes))throw new RuntimeException('Deployment ZIP could not be read.');
        }
        vp3_cloud_hosting_ui_v140_execute($user,$action,$_POST,$packageBytes,null,null,null,$pdo);
        $messages=[
            'site.create'=>'Hosted site created.',
            'site.activate'=>'Hosted site activation reconciled with HomeServer.',
            'site.suspend'=>'Hosted site suspended and reconciled.',
            'site.reconcile'=>'Cloud and HomeServer hosting state reconciled.',
            'dns.provision'=>'cPanel DNS provisioning completed.',
            'dns.verify'=>'DNS verification completed.',
            'deployment.deploy'=>'Deployment ZIP transferred and activated on HomeServer.',
            'deployment.rollback'=>'Hosted site rolled back to the previous release.',
            'deployment.promote'=>'Historical release promoted on HomeServer.',
            'deployment.prune'=>'Release retention applied on HomeServer.',
            'health.check'=>'Hosting health check completed.',
            'health.policy'=>'Hosting health recovery policy updated.',
            'domain.attach'=>'Custom domain attached. Add the ownership and routing DNS records shown below.',
            'domain.verify_ownership'=>'Custom-domain ownership verification checked.',
            'domain.verify_routing'=>'Custom-domain routing verification checked.',
            'domain.canonical'=>'Canonical custom domain updated.',
            'domain.redirect'=>'Custom-domain redirect policy updated.',
            'domain.detach'=>'Custom domain detached.',
            'domain.migrate'=>'Custom domain moved to the selected hosted site.',
        ];
        flash('hosting_notice',$messages[$action]??'Hosting action completed.');
    }catch(Throwable $e){
        flash('hosting_error',$e->getMessage());
    }
    $siteId=max(0,(int)($_POST['site_id']??0));
    redirect(url('/hosting.php'.($siteId>0?'#site-'.$siteId:'')));
}

$dashboard=vp3_cloud_hosting_ui_v140_dashboard($user,$pdo);
$entitlements=(array)$dashboard['entitlements'];
$sites=(array)$dashboard['sites'];
foreach($sites as $siteIndex=>$siteRow){
    $sites[$siteIndex]['release_catalog']=['releases'=>[],'active_release_id'=>$siteRow['active_release_id']??null,'previous_release_id'=>$siteRow['previous_release_id']??null];
    $sites[$siteIndex]['release_catalog_error']='';
    $sites[$siteIndex]['diagnostics']=null;
    $sites[$siteIndex]['diagnostics_error']='';
    $sites[$siteIndex]['health_recovery']=null;
    $sites[$siteIndex]['health_recovery_error']='';
    try{
        $owned=vp3_cloud_hosting_site_v100((int)$siteRow['id'],(int)$user['id'],$pdo);
        if($owned&&function_exists('vp3_cloud_hosting_releases_v220_catalog')){
            $sites[$siteIndex]['release_catalog']=vp3_cloud_hosting_releases_v220_catalog($owned,null,$pdo);
        }
    }catch(Throwable $releaseCatalogError){
        $sites[$siteIndex]['release_catalog_error']=mb_substr($releaseCatalogError->getMessage(),0,240);
    }
    try{
        $owned=$owned??vp3_cloud_hosting_site_v100((int)$siteRow['id'],(int)$user['id'],$pdo);
        if($owned&&function_exists('vp3_cloud_hosting_diagnostics_v230_summary')){
            $sites[$siteIndex]['diagnostics']=vp3_cloud_hosting_diagnostics_v230_summary($owned,60,12,null,$pdo);
        }
    }catch(Throwable $diagnosticsError){
        $sites[$siteIndex]['diagnostics_error']=mb_substr($diagnosticsError->getMessage(),0,240);
    }
    try{
        $owned=$owned??vp3_cloud_hosting_site_v100((int)$siteRow['id'],(int)$user['id'],$pdo);
        if($owned&&function_exists('vp3_cloud_hosting_health_v240_summary')){
            $sites[$siteIndex]['health_recovery']=vp3_cloud_hosting_health_v240_summary($owned,null,$pdo);
        }
    }catch(Throwable $healthRecoveryError){
        $sites[$siteIndex]['health_recovery_error']=mb_substr($healthRecoveryError->getMessage(),0,240);
    }
}
$notice=flash('hosting_notice');
$error=flash('hosting_error');
$limitText=static fn($value): string=>$value===null?'Unlimited':number_format((int)$value);
$bytesText=static function(int $bytes):string{
    if($bytes<=0)return 'Package default';
    if($bytes>=1073741824)return number_format($bytes/1073741824,1).' GB';
    return number_format($bytes/1048576).' MB';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f4f5f7">
<title><?= e(system_agent_name()) ?> | Cloud Hosting</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/cloud-hosting-v140.css?v=3')) ?>">
</head>
<body>
<div class="chat-app">
  <?php
    $workspaceSidebarUser=$user;
    $workspaceSidebarActive='hosting';
    require __DIR__.'/includes/workspace-sidebar-v82.php';
  ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main hosting-main">
    <?php
      $memberHeaderUser=$user;
      $memberHeaderTitle='Cloud Hosting';
      $memberHeaderSubtitle='Hosting & Subdomains · VP3 Cloud ↔ HomeServer';
      $memberHeaderActiveKey='hosting';
      $memberHeaderActions='<a class="hosting-agent-link" href="'.e(url('/chat.php?prompt='.rawurlencode('Show me the status of my hosted sites'))).'">Ask Agent</a>';
      require __DIR__.'/includes/member-header.php';
    ?>

    <section class="hosting-canvas">
      <div class="hosting-shell">
        <section class="hosting-hero">
          <div>
            <span class="hosting-kicker">Cloud Hosting V2</span>
            <h1>Sites on your HomeServer.</h1>
            <p>Create and deploy sites from VP3 Cloud while HomeServer remains authoritative for runtime execution, release activation, SQLite migrations and recovery.</p>
          </div>
          <a class="hosting-primary" href="#new-site">+ New Hosted Site</a>
        </section>

        <?php if($notice): ?><div class="hosting-alert success"><?= e($notice) ?></div><?php endif; ?>
        <?php if($error): ?><div class="hosting-alert error"><?= e($error) ?></div><?php endif; ?>

        <section class="hosting-metrics" aria-label="Hosting package limits">
          <article><span>Hosted Sites</span><strong><?= number_format((int)$dashboard['site_count']) ?> / <?= e($limitText($entitlements['sites'])) ?></strong></article>
          <article><span>Subdomains</span><strong><?= e($limitText($entitlements['subdomains'])) ?></strong></article>
          <article><span>Custom Domains</span><strong><?= e($limitText($entitlements['custom_domains']??0)) ?></strong></article>
          <article><span>Storage / Site</span><strong><?= $entitlements['storage_mb_per_site']===null?'Unlimited':number_format((int)$entitlements['storage_mb_per_site']).' MB' ?></strong></article>
          <article><span>SQLite / Site</span><strong><?= $entitlements['sqlite_mb_per_site']===null?'Unlimited':number_format((int)$entitlements['sqlite_mb_per_site']).' MB' ?></strong></article>
          <article><span>PHP Runtime</span><strong><?= !empty($entitlements['php'])?'Included':'Not included' ?></strong></article>
        </section>

        <?php if(empty($entitlements['access'])): ?>
          <section class="hosting-empty">
            <h2>Cloud Hosting is not included in this package.</h2>
            <p>Your existing hosted-site data is not deleted by package changes. Open Plan & Usage to review the current Hosting entitlement.</p>
            <a href="<?= e(url('/subscription.php')) ?>">View Plan & Usage</a>
          </section>
        <?php else: ?>
          <section class="hosting-create" id="new-site">
            <div class="hosting-section-head">
              <div><span>New Site</span><h2>Create a hosted site</h2><p>The site is registered in Cloud first. DNS, deployment and activation stay separate so nothing public happens unexpectedly.</p></div>
            </div>
            <form method="post" class="hosting-create-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="site.create">
              <input type="hidden" name="request_key" value="<?= e(vp3_cloud_hosting_ui_v140_request_key('create')) ?>">
              <label><span>Site Name</span><input name="display_name" maxlength="160" required placeholder="My Website"></label>
              <label><span>Subdomain / Hostname</span><input name="requested_hostname" maxlength="253" placeholder="site.example.com"></label>
              <label><span>Runtime</span><select name="runtime_kind"><option value="static">Static</option><?php if(!empty($entitlements['php'])): ?><option value="php">PHP</option><?php endif; ?></select></label>
              <button class="hosting-primary" type="submit">Create Site</button>
            </form>
          </section>

          <section class="hosting-sites">
            <div class="hosting-section-head">
              <div><span>Sites</span><h2><?= count($sites) ?> Hosted Site<?= count($sites)===1?'':'s' ?></h2><p>Cloud desired state and HomeServer observed state are shown separately so reconciliation is visible.</p></div>
            </div>

            <?php if(!$sites): ?>
              <div class="hosting-empty"><h3>No hosted sites yet.</h3><p>Create your first site above. Nothing is publicly routed until DNS, TLS, deployment and activation are ready.</p></div>
            <?php endif; ?>

            <?php foreach($sites as $site): ?>
              <?php
                $route=(array)($site['route']??[]);
                $deployment=(array)($site['deployment']??[]);
                $sync=(array)($site['sync']??[]);
                $customDomains=(array)($site['custom_domains']??[]);
                $releaseCatalog=(array)($site['release_catalog']??[]);
                $releaseRows=(array)($releaseCatalog['releases']??[]);
                $releaseCatalogError=(string)($site['release_catalog_error']??'');
                $diagnostics=(array)($site['diagnostics']??[]);
                $diagnosticsError=(string)($site['diagnostics_error']??'');
                $diagnosticRows=(array)($diagnostics['recent']??[]);
                $healthRecovery=(array)($site['health_recovery']??[]);
                $healthRecoveryError=(string)($site['health_recovery_error']??'');
                $healthPolicy=(array)($healthRecovery['policy']??[]);
                $healthIncident=(array)($healthRecovery['incident']??[]);
                $healthHistory=(array)($healthRecovery['history']??[]);
                $isActive=(string)$site['desired_state']==='active';
                $routeReady=!empty($sync['public_route_ready']);
              ?>
              <article class="hosting-site-card" id="site-<?= (int)$site['id'] ?>">
                <header>
                  <div>
                    <div class="hosting-site-title-row">
                      <h3><?= e((string)$site['display_name']) ?></h3>
                      <span class="hosting-state <?= e((string)$site['observed_state']) ?>"><?= e((string)$site['observed_state']) ?></span>
                    </div>
                    <p><?= $site['hostname']!==''?e((string)$site['hostname']):'No hostname assigned' ?> · <?= e(strtoupper((string)$site['runtime_kind'])) ?></p>
                  </div>
                  <?php if(!empty($site['public_url'])&&$routeReady): ?><a class="hosting-open-site" href="<?= e((string)$site['public_url']) ?>" target="_blank" rel="noopener">Open Site ↗</a><?php endif; ?>
                </header>

                <?php if(!empty($site['issues'])): ?>
                  <div class="hosting-issues"><?php foreach((array)$site['issues'] as $issue): ?><span><?= e((string)$issue) ?></span><?php endforeach; ?></div>
                <?php endif; ?>

                <div class="hosting-status-grid">
                  <div><span>Cloud Desired</span><strong><?= e((string)$site['desired_state']) ?></strong><small>rev <?= (int)$site['desired_revision'] ?></small></div>
                  <div><span>HomeServer Observed</span><strong><?= e((string)$site['observed_state']) ?></strong><small>rev <?= (int)$site['observed_revision'] ?></small></div>
                  <div><span>DNS</span><strong><?= e((string)($route['dns_state']??'not provisioned')) ?></strong><small><?= !empty($route['record_value'])?'→ '.e((string)$route['record_value']):'cPanel route' ?></small></div>
                  <div><span>TLS</span><strong><?= e((string)($site['certificate']['tls_state']??$route['tls_state']??'pending')) ?></strong><small>Cloud edge</small></div>
                  <div><span>Active Release</span><strong><?= e((string)($site['active_release_id']??'none')) ?></strong><small><?= !empty($site['previous_release_id'])?'Previous: '.e((string)$site['previous_release_id']):'No previous release' ?></small></div>
                  <div><span>Limits</span><strong><?= e($bytesText((int)$site['storage_limit_bytes'])) ?></strong><small>SQLite <?= e($bytesText((int)$site['sqlite_limit_bytes'])) ?></small></div>
                </div>

                <section class="hosting-diagnostics">
                  <div class="hosting-diagnostics-head">
                    <div><strong>Traffic & Runtime</strong><span>Last 60 minutes from HomeServer runtime telemetry.</span></div>
                    <a href="<?= e(url('/chat.php')) ?>?prompt=<?= e(rawurlencode('Why is my hosted site '.(string)$site['display_name'].' down or unhealthy?')) ?>">Ask Agent Why</a>
                  </div>
                  <?php if($diagnosticsError!==''): ?>
                    <small class="hosting-diagnostics-error">Diagnostics unavailable: <?= e($diagnosticsError) ?></small>
                  <?php elseif(!$diagnostics): ?>
                    <small class="hosting-diagnostics-empty">No HomeServer diagnostics are available yet.</small>
                  <?php else: ?>
                    <div class="hosting-diagnostics-grid">
                      <div><span>Requests</span><strong><?= number_format((int)$diagnostics['requests_total']) ?></strong><small><?= number_format((int)$diagnostics['client_error_total']) ?> 4xx · <?= number_format((int)$diagnostics['server_error_total']) ?> 5xx</small></div>
                      <div><span>Latency</span><strong><?= number_format((float)$diagnostics['average_duration_ms'],1) ?> ms</strong><small>p95 <?= number_format((float)$diagnostics['p95_duration_ms'],1) ?> ms</small></div>
                      <div><span>Runtime</span><strong><?= !empty($diagnostics['serving_ready'])?'Ready':'Not ready' ?></strong><small><?= number_format((int)$diagnostics['php_failure_total']) ?> PHP failures · <?= number_format((int)$diagnostics['slow_request_total']) ?> slow</small></div>
                      <div><span>Storage</span><strong><?= e($bytesText((int)$diagnostics['storage_bytes'])) ?></strong><small>SQLite <?= e($bytesText((int)$diagnostics['sqlite_bytes'])) ?> · <?= !empty($diagnostics['sqlite_healthy'])?'healthy':'unhealthy' ?></small></div>
                      <div><span>Route</span><strong><?= e((string)$diagnostics['cloud_dns_state']) ?></strong><small>TLS <?= e((string)$diagnostics['cloud_tls_state']) ?> · <?= !empty($diagnostics['homeserver_route_ready'])?'HomeServer ready':'HomeServer pending' ?></small></div>
                      <div><span>Last Deploy</span><strong><?= !empty($diagnostics['last_deploy']['app_version'])?e((string)$diagnostics['last_deploy']['app_version']):(!empty($diagnostics['last_deploy']['release_id'])?e((string)$diagnostics['last_deploy']['release_id']):'None') ?></strong><small><?= !empty($diagnostics['last_deploy']['created_at'])?e((string)$diagnostics['last_deploy']['created_at']):'No active release metadata' ?></small></div>
                    </div>
                    <?php if(!empty($diagnostics['issues'])): ?>
                      <div class="hosting-diagnostic-issues"><?php foreach((array)$diagnostics['issues'] as $issue): ?><span><?= e((string)$issue) ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if($diagnosticRows): ?>
                      <div class="hosting-request-log" aria-label="Recent hosting requests">
                        <?php foreach($diagnosticRows as $requestRow): ?>
                          <div><code><?= e((string)$requestRow['method']) ?></code><span><?= e((string)$requestRow['path']) ?></span><strong class="<?= (int)$requestRow['status']>=500?'error':((int)$requestRow['status']>=400?'warn':'') ?>"><?= (int)$requestRow['status'] ?></strong><small><?= number_format((float)$requestRow['duration_ms'],1) ?> ms</small></div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </section>

                <section class="hosting-health-recovery">
                  <div class="hosting-health-head">
                    <div><strong>Health & Recovery</strong><span>HomeServer automated monitoring, incident response and bounded recovery.</span></div>
                    <span class="hosting-health-state <?= e((string)($healthRecovery['health_state']??'unknown')) ?>"><?= e((string)($healthRecovery['health_state']??'unknown')) ?></span>
                  </div>
                  <?php if($healthRecoveryError!==''): ?>
                    <small class="hosting-diagnostics-error">Health recovery unavailable: <?= e($healthRecoveryError) ?></small>
                  <?php elseif(!$healthRecovery): ?>
                    <small class="hosting-diagnostics-empty">No HomeServer health recovery state is available yet.</small>
                  <?php else: ?>
                    <div class="hosting-health-grid">
                      <div><span>Monitor</span><strong><?= !empty($healthPolicy['enabled'])?'Enabled':'Disabled' ?></strong><small>Every <?= (int)($healthPolicy['interval_seconds']??60) ?> sec</small></div>
                      <div><span>Failure Threshold</span><strong><?= (int)($healthPolicy['failure_threshold']??3) ?> checks</strong><small>Before incident recovery</small></div>
                      <div><span>Recovery Attempts</span><strong><?= (int)($healthPolicy['max_recovery_attempts']??2) ?></strong><small>Bounded automatic actions</small></div>
                      <div><span>Rollback</span><strong><?= !empty($healthPolicy['auto_rollback'])?'Automatic':'Manual' ?></strong><small>Previous known-good release</small></div>
                      <div><span>Restore</span><strong><?= !empty($healthPolicy['auto_restore'])?'Automatic':'Manual only' ?></strong><small>Verified recovery point</small></div>
                      <div><span>Latest Incident</span><strong><?= $healthIncident?e((string)($healthIncident['state']??'unknown')):'None' ?></strong><small><?= $healthIncident?e((string)($healthIncident['created_at']??'')):'No active incident' ?></small></div>
                    </div>

                    <div class="hosting-health-actions">
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="health.check">
                        <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                        <button type="submit">Run Health Check</button>
                      </form>
                      <form method="post" data-hosting-confirm="Update this site's automatic recovery policy?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="health.policy">
                        <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                        <input type="hidden" name="confirmed" value="0" data-hosting-confirmed>
                        <input type="hidden" name="enabled" value="<?= !empty($healthPolicy['enabled'])?'1':'0' ?>">
                        <input type="hidden" name="auto_reactivate" value="<?= !empty($healthPolicy['auto_reactivate'])?'1':'0' ?>">
                        <input type="hidden" name="auto_restore" value="<?= !empty($healthPolicy['auto_restore'])?'1':'0' ?>">
                        <label><span>Check</span><select name="interval_seconds"><?php foreach([30,60,120,300,600] as $seconds): ?><option value="<?= $seconds ?>"<?= (int)($healthPolicy['interval_seconds']??60)===$seconds?' selected':'' ?>><?= $seconds ?>s</option><?php endforeach; ?></select></label>
                        <label><span>Failures</span><select name="failure_threshold"><?php foreach([1,2,3,4,5] as $threshold): ?><option value="<?= $threshold ?>"<?= (int)($healthPolicy['failure_threshold']??3)===$threshold?' selected':'' ?>><?= $threshold ?></option><?php endforeach; ?></select></label>
                        <label><span>Attempts</span><select name="max_recovery_attempts"><?php foreach([1,2,3,4,5] as $attempts): ?><option value="<?= $attempts ?>"<?= (int)($healthPolicy['max_recovery_attempts']??2)===$attempts?' selected':'' ?>><?= $attempts ?></option><?php endforeach; ?></select></label>
                        <label><span>Cooldown</span><select name="cooldown_seconds"><?php foreach([30,60,120,300,600] as $seconds): ?><option value="<?= $seconds ?>"<?= (int)($healthPolicy['cooldown_seconds']??300)===$seconds?' selected':'' ?>><?= $seconds ?>s</option><?php endforeach; ?></select></label>
                        <label class="hosting-health-toggle"><input type="checkbox" name="auto_rollback" value="1"<?= !empty($healthPolicy['auto_rollback'])?' checked':'' ?>><span>Auto rollback</span></label>
                        <button type="submit">Save Recovery Policy</button>
                      </form>
                    </div>

                    <?php if($healthHistory): ?>
                      <div class="hosting-health-history">
                        <?php foreach(array_slice($healthHistory,0,8) as $healthEvent): ?>
                          <div><strong><?= e((string)($healthEvent['state']??'')) ?></strong><span><?= e((string)($healthEvent['action']??$healthEvent['event_type']??'')) ?></span><small><?= e((string)($healthEvent['created_at']??'')) ?></small></div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </section>

                <div class="hosting-actions">
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="site.reconcile"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="request_key" value="<?= e(vp3_cloud_hosting_ui_v140_request_key('reconcile')) ?>"><button type="submit">Reconcile</button></form>
                  <?php if(!$isActive): ?>
                    <form method="post" data-hosting-confirm="Activate this hosted site?"><?= csrf_field() ?><input type="hidden" name="confirmed" value="0" data-hosting-confirmed><input type="hidden" name="action" value="site.activate"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><button type="submit">Activate</button></form>
                  <?php else: ?>
                    <form method="post" data-hosting-confirm="Suspend this hosted site and stop public serving?"><?= csrf_field() ?><input type="hidden" name="confirmed" value="0" data-hosting-confirmed><input type="hidden" name="action" value="site.suspend"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><button type="submit">Suspend</button></form>
                  <?php endif; ?>
                  <?php if(!$route): ?>
                    <form method="post" data-hosting-confirm="Provision this site's cPanel DNS CNAME?"><?= csrf_field() ?><input type="hidden" name="confirmed" value="0" data-hosting-confirmed><input type="hidden" name="action" value="dns.provision"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="request_key" value="<?= e(vp3_cloud_hosting_ui_v140_request_key('dns')) ?>"><button type="submit">Provision DNS</button></form>
                  <?php elseif((string)($route['dns_state']??'')!=='verified'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="dns.verify"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><button type="submit">Verify DNS</button></form>
                  <?php endif; ?>
                  <?php if(!empty($site['previous_release_id'])): ?>
                    <form method="post" data-hosting-confirm="Roll this site back to its previous HomeServer release?"><?= csrf_field() ?><input type="hidden" name="confirmed" value="0" data-hosting-confirmed><input type="hidden" name="action" value="deployment.rollback"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="request_key" value="<?= e(vp3_cloud_hosting_ui_v140_request_key('rollback')) ?>"><button type="submit">Rollback</button></form>
                  <?php endif; ?>
                </div>

                <section class="hosting-releases">
                  <div class="hosting-releases-head">
                    <div><strong>Release History</strong><span>Retained releases live on HomeServer. Promoting an older release creates a recovery point before activation.</span></div>
                    <form method="post" class="hosting-retention" data-hosting-confirm="Prune old retained releases? Active and previous releases are always protected.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="confirmed" value="0" data-hosting-confirmed>
                      <input type="hidden" name="action" value="deployment.prune">
                      <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                      <input type="hidden" name="request_key" value="<?= e(vp3_cloud_hosting_ui_v140_request_key('prune')) ?>">
                      <label><span>Keep</span><select name="keep"><?php foreach([2,3,5,10,20] as $keep): ?><option value="<?= $keep ?>"<?= $keep===5?' selected':'' ?>><?= $keep ?></option><?php endforeach; ?></select></label>
                      <button type="submit">Apply Retention</button>
                    </form>
                  </div>
                  <?php if($releaseCatalogError!==''): ?>
                    <small class="hosting-release-error">Release history unavailable: <?= e($releaseCatalogError) ?></small>
                  <?php elseif(!$releaseRows): ?>
                    <small class="hosting-release-empty">No retained HomeServer releases yet.</small>
                  <?php else: ?>
                    <div class="hosting-release-list">
                      <?php foreach($releaseRows as $release): ?>
                        <article class="hosting-release-row<?= !empty($release['active'])?' active':'' ?>">
                          <div>
                            <strong><?= e((string)($release['app_version']!==''?$release['app_version']:$release['release_id'])) ?></strong>
                            <span><?= e((string)$release['release_id']) ?></span>
                            <small><?= e(strtoupper((string)$release['runtime'])) ?><?= !empty($release['created_at'])?' · '.e((string)$release['created_at']):'' ?><?= !empty($release['package_sha256'])?' · '.e(substr((string)$release['package_sha256'],0,12)).'…':'' ?></small>
                          </div>
                          <div class="hosting-release-row-actions">
                            <?php if(!empty($release['active'])): ?><span class="hosting-release-badge active">Active</span><?php endif; ?>
                            <?php if(!empty($release['previous'])): ?><span class="hosting-release-badge">Previous</span><?php endif; ?>
                            <?php if(empty($release['active'])): ?>
                              <form method="post" data-hosting-confirm="Promote this retained HomeServer release? A recovery point will be created first.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="confirmed" value="0" data-hosting-confirmed>
                                <input type="hidden" name="action" value="deployment.promote">
                                <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                                <input type="hidden" name="release_id" value="<?= e((string)$release['release_id']) ?>">
                                <input type="hidden" name="request_key" value="<?= e(vp3_cloud_hosting_ui_v140_request_key('promote')) ?>">
                                <button type="submit">Promote</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </article>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </section>

                <section class="hosting-domains">
                  <div class="hosting-domains-head">
                    <div><strong>Custom Domains</strong><span>Cloud-edge aliases with DNS ownership verification. HomeServer keeps the canonical upstream route.</span></div>
                    <span class="hosting-domain-count"><?= count($customDomains) ?> / <?= e($limitText($entitlements['custom_domains']??0)) ?></span>
                  </div>

                  <?php if(($entitlements['custom_domains']??0)!==0): ?>
                    <form method="post" class="hosting-domain-attach">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="domain.attach">
                      <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                      <label><span>Attach Domain</span><input name="hostname" maxlength="253" required placeholder="www.example.com"></label>
                      <button type="submit">Attach</button>
                    </form>
                  <?php else: ?>
                    <small class="hosting-domain-note">This package does not currently include custom domains. An admin can set the <code>hosting.custom_domains</code> package quantity.</small>
                  <?php endif; ?>

                  <?php foreach($customDomains as $customDomain): ?>
                    <?php
                      $rawCustomDomain=vp3_cloud_hosting_domains_v200_find((int)$customDomain['id'],(int)$user['id'],$pdo);
                      $domainInstructions=[];
                      if($rawCustomDomain){
                        try{$domainInstructions=vp3_cloud_hosting_domains_v200_instructions($rawCustomDomain);}catch(Throwable $domainInstructionError){$domainInstructions=[];}
                      }
                      $ownership=(array)($domainInstructions['ownership']??[]);
                      $routing=(array)($domainInstructions['routing']??[]);
                    ?>
                    <article class="hosting-domain-card">
                      <header>
                        <div>
                          <strong><?= e((string)$customDomain['hostname']) ?></strong>
                          <?php if(!empty($customDomain['is_canonical'])): ?><span class="hosting-domain-badge">Canonical</span><?php endif; ?>
                          <?php if(!empty($customDomain['ready'])): ?><span class="hosting-domain-badge ready">Ready</span><?php endif; ?>
                        </div>
                        <span>Ownership <?= e((string)$customDomain['verification_state']) ?> · Route <?= e((string)$customDomain['routing_state']) ?> · TLS <?= e((string)$customDomain['tls_state']) ?></span>
                      </header>

                      <?php if((string)$customDomain['verification_state']!=='verified'): ?>
                        <div class="hosting-dns-instructions">
                          <span>Ownership TXT</span>
                          <code><?= e((string)($ownership['name']??'')) ?></code>
                          <code><?= e((string)($ownership['value']??'')) ?></code>
                        </div>
                      <?php endif; ?>

                      <?php if((string)$customDomain['routing_state']!=='verified'): ?>
                        <div class="hosting-dns-instructions">
                          <span>Routing <?= e((string)($routing['type']??'CNAME')) ?></span>
                          <code><?= e((string)($routing['name']??'')) ?></code>
                          <code><?= e((string)($routing['value']??'')) ?></code>
                          <?php if(!empty($routing['apex_note'])): ?><small><?= e((string)$routing['apex_note']) ?></small><?php endif; ?>
                        </div>
                      <?php endif; ?>

                      <div class="hosting-domain-actions">
                        <?php if((string)$customDomain['verification_state']!=='verified'): ?>
                          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="domain.verify_ownership"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="domain_id" value="<?= (int)$customDomain['id'] ?>"><button type="submit">Verify Ownership</button></form>
                        <?php endif; ?>
                        <?php if((string)$customDomain['verification_state']==='verified'&&(string)$customDomain['routing_state']!=='verified'): ?>
                          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="domain.verify_routing"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="domain_id" value="<?= (int)$customDomain['id'] ?>"><button type="submit">Verify Routing</button></form>
                        <?php endif; ?>
                        <?php if(!empty($customDomain['ready'])&&empty($customDomain['is_canonical'])): ?>
                          <form method="post" data-hosting-confirm="Make this the canonical public domain for the site?"><?= csrf_field() ?><input type="hidden" name="confirmed" value="0" data-hosting-confirmed><input type="hidden" name="action" value="domain.canonical"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="domain_id" value="<?= (int)$customDomain['id'] ?>"><input type="hidden" name="redirect_others" value="1"><button type="submit">Make Canonical</button></form>
                        <?php endif; ?>
                        <?php if(empty($customDomain['is_canonical'])): ?>
                          <form method="post" data-hosting-confirm="Change redirect behavior for this custom domain?"><?= csrf_field() ?><input type="hidden" name="confirmed" value="0" data-hosting-confirmed><input type="hidden" name="action" value="domain.redirect"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="domain_id" value="<?= (int)$customDomain['id'] ?>"><input type="hidden" name="enabled" value="<?= !empty($customDomain['redirect_to_canonical'])?'0':'1' ?>"><button type="submit"><?= !empty($customDomain['redirect_to_canonical'])?'Serve Directly':'Redirect to Canonical' ?></button></form>
                        <?php endif; ?>
                        <?php if(count($sites)>1): ?>
                          <form method="post" class="hosting-domain-migrate" data-hosting-confirm="Move this custom domain to another hosted site?"><?= csrf_field() ?><input type="hidden" name="confirmed" value="0" data-hosting-confirmed><input type="hidden" name="action" value="domain.migrate"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="domain_id" value="<?= (int)$customDomain['id'] ?>"><select name="target_site_id" required><option value="">Move to…</option><?php foreach($sites as $targetSite): ?><?php if((int)$targetSite['id']!==(int)$site['id']): ?><option value="<?= (int)$targetSite['id'] ?>"><?= e((string)$targetSite['display_name']) ?></option><?php endif; ?><?php endforeach; ?></select><button type="submit">Move</button></form>
                        <?php endif; ?>
                        <form method="post" data-hosting-confirm="Detach this custom domain? Existing DNS records will not be deleted automatically."><?= csrf_field() ?><input type="hidden" name="confirmed" value="0" data-hosting-confirmed><input type="hidden" name="action" value="domain.detach"><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><input type="hidden" name="domain_id" value="<?= (int)$customDomain['id'] ?>"><button type="submit">Detach</button></form>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </section>

                <section class="hosting-deploy">
                  <div>
                    <strong>Deploy ZIP</strong>
                    <span>Up to 64 MiB · HomeServer validates package checksum, manifest, runtime and release safety before activation.</span>
                  </div>
                  <form method="post" enctype="multipart/form-data" data-hosting-deploy-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirmed" value="0" data-hosting-confirmed>
                    <input type="hidden" name="action" value="deployment.deploy">
                    <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                    <input type="hidden" name="request_key" value="<?= e(vp3_cloud_hosting_ui_v140_request_key('deploy')) ?>">
                    <label class="hosting-file"><input type="file" name="deployment_zip" accept=".zip,application/zip" required><span>Choose ZIP</span></label>
                    <button class="hosting-primary" type="submit">Deploy</button>
                  </form>
                  <?php if($deployment): ?><small class="hosting-deployment-meta">Latest: <?= e((string)$deployment['operation']) ?> · <?= e((string)$deployment['state']) ?><?= !empty($deployment['release_id'])?' · '.e((string)$deployment['release_id']):'' ?> · <?= e((string)$deployment['updated_at']) ?></small><?php endif; ?>
                </section>
              </article>
            <?php endforeach; ?>
          </section>
        <?php endif; ?>
      </div>
    </section>
  </main>
</div>
<script src="<?= e(url('/member-shell-v77.js?v=universal-member-header-20260905')) ?>"></script>
<script src="<?= e(url('/cloud-hosting-v140.js?v=1')) ?>"></script>
</body>
</html>
