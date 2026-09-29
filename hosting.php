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
vp3_cloud_hosting_v120_ensure_schema($pdo);

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
}
$notice=flash('hosting_notice');
$error=flash('hosting_error');
$hostingUserMenuLinks=member_navigation_menu_links($user);
$notificationCount=notification_unread_count($user);
$notifications=notification_recent($user,6);

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
<link rel="stylesheet" href="<?= e(url('/cloud-hosting-v140.css?v=2')) ?>">
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
    <header class="chat-topbar">
      <button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open workspace menu">☰</button>
      <div class="chat-topbar-title">
        <strong>Cloud Hosting</strong>
        <span>VP3 Cloud ↔ HomeServer sites</span>
      </div>
      <div class="chat-topbar-actions">
        <a class="hosting-agent-link" href="<?= e(url('/chat.php')) ?>?prompt=<?= e(rawurlencode('Show me the status of my hosted sites')) ?>">Ask Agent</a>
        <div class="chat-top-menu" id="chatNotificationMenu">
          <button class="chat-notification-link" id="chatNotificationButton" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="chatNotificationDropdown">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            <?php if($notificationCount>0): ?><span><?= $notificationCount>99?'99+':(int)$notificationCount ?></span><?php endif; ?>
          </button>
          <div class="chat-top-dropdown chat-notification-dropdown" id="chatNotificationDropdown" hidden>
            <header><strong>Notifications</strong><span><?= (int)$notificationCount ?> unread</span></header>
            <div class="chat-notification-dropdown-list">
              <?php foreach($notifications as $notification): ?>
                <a class="<?= !(int)$notification['is_read']?'unread':'' ?>" href="<?= e(url('/notifications.php?open='.(int)$notification['id'])) ?>">
                  <span class="chat-dropdown-dot"></span><span><strong><?= e((string)$notification['title']) ?></strong><small><?= e((string)$notification['body']) ?></small></span>
                </a>
              <?php endforeach; ?>
              <?php if(!$notifications): ?><div class="chat-dropdown-empty">No notifications yet.</div><?php endif; ?>
            </div>
            <a class="chat-dropdown-all" href="<?= e(url('/notifications.php')) ?>">View all notifications →</a>
          </div>
        </div>
        <div class="chat-top-menu" id="chatProfileMenu">
          <button type="button" class="chat-top-avatar" id="chatProfileButton" aria-label="User menu" aria-expanded="false" aria-controls="chatProfileDropdown">
            <?php if(user_avatar_url($user)!==''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><?= e(user_initials($user)) ?><?php endif; ?>
          </button>
          <div class="chat-top-dropdown chat-profile-dropdown" id="chatProfileDropdown" hidden>
            <div class="chat-profile-summary"><span class="chat-avatar"><?= e(user_initials($user)) ?></span><div><strong><?= e((string)$user['display_name']) ?></strong><small><?= e(role_label((string)$user['role'])) ?></small></div></div>
            <nav class="chat-profile-links">
              <?php foreach($hostingUserMenuLinks as $menuLink): ?>
                <a<?= !empty($menuLink['danger'])?' class="logout"':'' ?> href="<?= e((string)$menuLink['url']) ?>"><span><?= e((string)$menuLink['label']) ?></span><span>↗</span></a>
              <?php endforeach; ?>
            </nav>
          </div>
        </div>
      </div>
    </header>

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
<script src="<?= e(url('/member-shell-v77.js')) ?>"></script>
<script src="<?= e(url('/cloud-hosting-v140.js?v=1')) ?>"></script>
</body>
</html>
