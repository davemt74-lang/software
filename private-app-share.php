<?php
declare(strict_types=1);

require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/system-apps-v240.php';
require_login();
require_permission('account.access');

$pdo=db();$user=current_user();
if(!$pdo||!$user)throw new RuntimeException('Private app shares are unavailable.');

$publicId=strtolower(trim((string)($_GET['id']??$_POST['id']??'')));
$grantCode=strtolower(trim((string)($_GET['code']??$_POST['code']??'')));
$notice='';$error='';$redemption=null;$share=null;

try{
    if($publicId!=='')$share=vp3_user_app_share_lookup_v240((int)$user['id'],$publicId,$pdo);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!verify_csrf())throw new RuntimeException('Session expired. Refresh and try again.');
        if((string)($_POST['action']??'')!=='redeem')throw new RuntimeException('Unsupported private share action.');
        $redemption=vp3_user_app_share_redeem_v240((int)$user['id'],$publicId,$grantCode,$pdo);
        $share=vp3_user_app_share_lookup_v240((int)$user['id'],$publicId,$pdo);
        $notice='Private app install grant accepted. Use the exact package hash below when importing the sender’s .vp3app.zip bundle on HomeServer.';
    }
}catch(Throwable $e){$error=$e->getMessage();}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f4f5f7"><title><?=e(system_agent_name())?> | Private App Share</title>
<link rel="stylesheet" href="<?=e(url('/chat.css?v=82'))?>">
<link rel="stylesheet" href="<?=e(url('/system-apps-v100.css?v=3'))?>">
</head>
<body>
<div class="chat-app">
<?php $workspaceSidebarUser=$user;$workspaceSidebarActive='apps';require __DIR__.'/includes/workspace-sidebar-v82.php';?>
<div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main system-apps-main">
<?php $memberHeaderUser=$user;$memberHeaderTitle='Private App Share';$memberHeaderSubtitle='Recipient-bound VP3 app distribution';$memberHeaderActiveKey='apps';require __DIR__.'/includes/member-header.php';?>
<section class="system-apps-canvas"><div class="system-apps-shell">
<header class="system-apps-hero"><div><span>PRIVATE APP SHARE</span><h1>Install a shared app.</h1><p>VP3 authorizes the recipient and exact package hash. The app bundle itself stays outside Cloud source storage and must match the sender’s exported .vp3app.zip file.</p></div></header>
<?php if($notice):?><div class="system-apps-alert success"><?=e($notice)?></div><?php endif;?>
<?php if($error):?><div class="system-apps-alert error"><?=e($error)?></div><?php endif;?>
<?php if($share):?>
<article class="system-app-card">
<div class="system-app-card-top"><div class="system-app-icon">APP</div><div><span class="system-app-category">PRIVATE USER APP</span><h2><?=e((string)$share['app_name'])?></h2></div><span class="system-app-badge neutral"><?=e((string)$share['status'])?></span></div>
<p>Shared by <?=e((string)$share['sender_name'])?> · <?=e((string)$share['app_key'])?><?php if($share['app_version']!==''):?> · v<?=e((string)$share['app_version'])?><?php endif;?></p>
<div class="system-app-meta"><span>Expires <strong><?=e((string)$share['expires_at'])?></strong></span><span>Schema <strong><?=e((string)$share['data_schema_version'])?></strong></span></div>
<details class="system-app-release-notes" open><summary>Verified package provenance</summary>
<p>Expected package SHA-256:</p><code style="overflow-wrap:anywhere"><?=e((string)$share['package_sha256'])?></code>
<p>Publisher fingerprint:</p><code><?=e((string)$share['publisher_fingerprint'])?></code>
<?php if(!empty($share['permissions'])):?><p>Declared permissions:</p><ul><?php foreach((array)$share['permissions'] as $permission):?><li><?=e((string)$permission)?></li><?php endforeach;?></ul><?php endif;?>
</details>
<?php if((string)$share['status']==='pending'&&$grantCode!==''):?>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="redeem"><input type="hidden" name="id" value="<?=e($publicId)?>"><input type="hidden" name="code" value="<?=e($grantCode)?>"><button class="button primary" type="submit">Accept Private App Share</button></form>
<?php elseif((string)$share['status']==='accepted'):?>
<div class="system-apps-alert success"><strong>Grant accepted.</strong> On HomeServer open Apps → Import App → Private VP3 share. Select the sender’s exported bundle and paste this exact SHA-256:<br><code style="overflow-wrap:anywhere"><?=e((string)$share['package_sha256'])?></code></div>
<?php endif;?>
</article>
<?php elseif(!$error):?>
<div class="panel empty-state">Open the private install link supplied by the sender.</div>
<?php endif;?>
</div></section>
</main></div>
</body></html>
