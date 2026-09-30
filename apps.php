<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/system-apps-v200.php';
require_login();
require_permission('account.access');
$pdo=db();$user=current_user();
if(!$pdo||!$user)throw new RuntimeException('Apps are unavailable.');
if(!vp3_system_apps_schema_ready_v120($pdo))vp3_system_apps_ensure_schema_v120($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('system_apps_error','Session expired. Refresh and try again.');redirect(url('/apps.php'));}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='acquire'){
            vp3_system_apps_acquire_v100((int)$user['id'],(string)($_POST['app_key']??''),'self_service',null,$pdo);
            flash('system_apps_notice','App added to your account.');
        }elseif($action==='install'){
            $result=vp3_system_apps_install_or_update_v200((int)$user['id'],(string)($_POST['app_key']??''),null,$pdo);
            $message=($result['operation']??'')==='install'
              ?(!empty($result['changed'])?'App installed on HomeServer.':'HomeServer app is already current.')
              :(!empty($result['rolled_back'])?'Update verification failed and HomeServer rolled back to the previous release.':'System App release verified on HomeServer.');
            if(!empty($result['reconcile_pending']))$message.=' Hosting/subdomain reconciliation is pending.';
            flash('system_apps_notice',$message);
        }elseif($action==='release.rollback'){
            $result=vp3_system_apps_release_rollback_v200((int)$user['id'],(string)($_POST['app_key']??''),'owner_requested',null,$pdo);
            $message='System App rolled back to the previous HomeServer release.';
            if(!empty($result['reconcile_pending']))$message.=' Hosting/subdomain reconciliation is pending.';
            flash('system_apps_notice',$message);
        }elseif($action==='reconcile'){
            $result=vp3_system_apps_reconcile_all_v130((int)$user['id'],null,$pdo);
            flash('system_apps_notice','HomeServer app status refreshed for '.number_format((int)$result['count']).' app lifecycle item'.((int)$result['count']===1?'':'s').'.');
        }elseif($action==='hosting.bind'){
            vp3_system_apps_hosting_bind_v120((int)$user['id'],(string)($_POST['app_key']??''),(int)($_POST['site_id']??0),null,$pdo);
            flash('system_apps_notice','App assigned to Hosting.');
        }elseif($action==='hosting.unbind'){
            vp3_system_apps_hosting_unbind_v120((int)$user['id'],(string)($_POST['app_key']??''),null,$pdo);
            flash('system_apps_notice','App removed from Hosting.');
        }else throw new RuntimeException('Unsupported Apps action.');
    }catch(Throwable $e){flash('system_apps_error',$e->getMessage());}
    redirect(url('/apps.php'));
}
$catalog=vp3_system_apps_catalog_v200($user,$pdo);
$notice=flash('system_apps_notice');$error=flash('system_apps_error');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f4f5f7"><title><?=e(system_agent_name())?> | Apps</title>
<link rel="stylesheet" href="<?=e(url('/chat.css?v=82'))?>"><link rel="stylesheet" href="<?=e(url('/system-apps-v100.css?v=3'))?>"></head>
<body><div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='apps';require __DIR__.'/includes/workspace-sidebar-v82.php';?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main system-apps-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Apps';$memberHeaderSubtitle='VP3 system apps for your HomeServer';$memberHeaderActiveKey='apps';require __DIR__.'/includes/member-header.php';?>
<section class="system-apps-canvas"><div class="system-apps-shell">
<header class="system-apps-hero"><div><span>VP3 SYSTEM APPS</span><h1>Your apps.</h1><p>Choose VP3 system apps for your account, then install them on your HomeServer. Cloud owns entitlement; HomeServer owns installation and runtime state.</p></div>
<div class="system-apps-counts"><strong><?=number_format((int)$catalog['counts']['owned'])?></strong><span>Owned</span><strong><?=number_format((int)$catalog['counts']['running'])?></strong><span>Running</span><strong><?=number_format((int)$catalog['counts']['hosted'])?></strong><span>Hosted</span></div></header>
<div class="system-apps-connection <?=e((string)$catalog['connection']['state'])?>"><span class="system-apps-connection-dot"></span><strong>HomeServer <?=e((string)$catalog['connection']['label'])?></strong><?php if(!$catalog['connection']['connected']):?><span>Remote install, update and hosting actions are unavailable until HomeServer reconnects.</span><?php endif;?></div>
<?php if($notice):?><div class="system-apps-alert success"><?=e($notice)?></div><?php endif;?><?php if($error):?><div class="system-apps-alert error"><?=e($error)?></div><?php endif;?>
<div class="system-apps-toolbar"><div class="system-apps-tabs"><button class="active" type="button" data-app-filter="all">All</button><button type="button" data-app-filter="owned">Owned</button><button type="button" data-app-filter="installed">Installed</button><button type="button" data-app-filter="hosted">Hosted</button><button type="button" data-app-filter="available">Available</button></div>
<?php if((int)$catalog['counts']['owned']>0):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reconcile"><button class="button secondary" type="submit">Refresh HomeServer</button></form><?php endif;?></div>
<section class="system-apps-grid">
<?php foreach($catalog['apps'] as $app):$hs=(array)($app['homeserver']??[]);$hosting=(array)($app['hosting']??[]);$ui=(array)($app['ui']??[]);$release=(array)($app['release']??[]);$state=(string)($ui['primary_state']??'available');?>
<?php $filterTags=['all'];if($app['owned'])$filterTags[]='owned';if(!empty($hs['installed']))$filterTags[]='installed';if(!empty($hosting['bound']))$filterTags[]='hosted';if(!$app['owned']&&!empty($app['eligible']))$filterTags[]='available';?>
<article class="system-app-card" data-app-state="<?=e($state)?>" data-app-filter-tags="<?=e(implode(' ',$filterTags))?>">
<div class="system-app-card-top"><div class="system-app-icon">VP3</div><div><span class="system-app-category"><?=e((string)$app['category'])?></span><h2><?=e((string)$app['name'])?></h2></div>
<span class="system-app-badge <?=e((string)($ui['tone']??'neutral'))?>"><?=e((string)($ui['badge']??'Available'))?></span></div>
<p><?=e((string)$app['description'])?></p>
<div class="system-app-meta"><span>Catalog <strong><?=e((string)$app['current_version'])?></strong></span><span>Channel <strong><?=e((string)($release['release_channel']??'stable'))?></strong></span><span>HomeServer <strong><?=e(!empty($hs['installed'])?(string)($hs['installed_version']??$hs['state']):ucfirst((string)($hs['state']??'not synced')))?></strong></span><?php if(!empty($hosting['bound'])):?><span>Hosting <strong><?=e((string)($hosting['hostname']??$hosting['display_name']))?></strong></span><?php endif;?></div>
<?php if(!empty($release['release_notes'])):?><details class="system-app-release-notes"><summary>Release notes</summary><ul><?php foreach((array)$release['release_notes'] as $note):?><li><?=e((string)$note)?></li><?php endforeach;?></ul><?php if(!empty($release['compatibility']['min_homeserver_version'])):?><p>Requires HomeServer <?=e((string)$release['compatibility']['min_homeserver_version'])?>+</p><?php endif;?></details><?php endif;?>
<?php if(!empty($hs['error'])):?><div class="system-app-error"><?=e((string)$hs['error'])?></div><?php endif;?>
<?php if(!empty($hs['installed'])):?><div class="system-app-hosting">
<?php if(!empty($hosting['bound'])):?><div class="system-app-hosting-bound"><span>Hosted at <?=e((string)($hosting['hostname']??$hosting['display_name']))?></span><?php if(!empty($hosting['public_url'])):?><a href="<?=e((string)$hosting['public_url'])?>" target="_blank" rel="noopener">Open</a><?php endif;?><a href="<?=e(url('/hosting.php'))?>">Manage Hosting</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="hosting.unbind"><input type="hidden" name="app_key" value="<?=e((string)$app['app_key'])?>"><button class="button secondary" type="submit">Remove Hosting</button></form></div>
<?php else:?><form method="post" class="system-app-hosting-form"><?=csrf_field()?><input type="hidden" name="action" value="hosting.bind"><input type="hidden" name="app_key" value="<?=e((string)$app['app_key'])?>"><select name="site_id" required><option value="">Assign hosting site…</option><?php foreach($catalog['hosting_sites'] as $site):?><?php if(empty($site['bound_app_id'])):?><option value="<?= (int)$site['id'] ?>"><?=e((string)$site['display_name'])?><?=!empty($site['hostname'])?' · '.e((string)$site['hostname']):''?></option><?php endif;?><?php endforeach;?></select><button class="button secondary" type="submit" <?=empty($ui['can_host'])?'disabled':''?>>Assign Hosting</button><a href="<?=e(url('/hosting.php'))?>">Manage Hosting</a></form><?php endif;?>
</div><?php endif;?>
<div class="system-app-actions">
<?php if($app['owned']):?>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="install"><input type="hidden" name="app_key" value="<?=e((string)$app['app_key'])?>"><button class="button primary" type="submit" <?=empty($ui['can_install'])?'disabled':''?>><?=!empty($hs['update_available'])?'Verified Update':(!empty($hs['installed'])?'Verify Release':'Install on HomeServer')?></button></form>
<span><?=!empty($ui['cleanup_pending'])?'Cleanup will retry when HomeServer reconnects':(!empty($hs['current'])?'Current · '.e((string)$hs['state']):(!empty($hs['installed'])?'HomeServer update or verification available':(!empty($catalog['connection']['connected'])?'Owned · ready to install':'Owned · HomeServer offline')))?></span>
<?php elseif($app['eligible']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="acquire"><input type="hidden" name="app_key" value="<?=e((string)$app['app_key'])?>"><button class="button primary" type="submit">Add to My Apps</button></form><span>Included for this account</span>
<?php else:?><button class="button secondary" type="button" disabled>Not available</button><?php endif;?>
</div></article>
<?php endforeach;?>
</section>
</div></section></main></div>
<script>document.addEventListener('click',e=>{const b=e.target.closest('[data-app-filter]');if(!b)return;document.querySelectorAll('[data-app-filter]').forEach(x=>x.classList.toggle('active',x===b));const f=b.dataset.appFilter;document.querySelectorAll('[data-app-filter-tags]').forEach(x=>x.hidden=f!=='all'&&!String(x.dataset.appFilterTags||'').split(/\s+/).includes(f));});</script>
</body></html>
