<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
$user=current_user();$pdo=db();if(!$pdo){http_response_code(503);exit('Database unavailable.');}
vp3_plugin_ensure_schema_v320($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Please try again.');redirect(url('/plugins.php'));}
    $action=(string)($_POST['action']??'');
    try{
        if($action==='music_enable'){
            music_workspace_set_enabled_v320($pdo,$user,true);
            flash('notice','Music Workspace enabled. Your tracks, releases and Team tools are ready.');
        }elseif($action==='music_disable'){
            music_workspace_set_enabled_v320($pdo,$user,false);
            flash('notice','Music Workspace disabled. Your music, releases, Team memberships and history were preserved.');
        }else throw new RuntimeException('Unknown plugin action.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect(url('/plugins.php'));
}

$status=music_workspace_status_v320($user);
$workspaceUrl=!empty($status['enabled'])?url('/music-workspace.php'):'';
$memberHeaderUser=$user;$memberHeaderTitle='Plugins';$memberHeaderSubtitle='Add professional capabilities to your VP3 account';$memberHeaderActions='';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f7f7f8"><title>VP3 | Plugins</title><link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><style>
.plugins-page{background:#f7f7f8;color:#1d1f23}.plugins-main{min-width:0}.plugins-wrap{max-width:1050px;margin:0 auto;padding:34px 24px 70px}.plugins-intro{max-width:700px;margin-bottom:24px}.plugins-intro h1{font-size:clamp(28px,4vw,46px);letter-spacing:-.035em;margin:0 0 8px}.plugins-intro p{color:#6e7177;line-height:1.55}.plugin-card{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:28px;background:#fff;border:1px solid #e3e4e7;border-radius:18px;padding:26px;box-shadow:0 12px 30px rgba(0,0,0,.04)}.plugin-card small{font-size:11px;letter-spacing:.09em;text-transform:uppercase;font-weight:800;color:#888b91}.plugin-card h2{font-size:25px;margin:5px 0 9px}.plugin-card p{max-width:660px;color:#6e7177;line-height:1.5;margin:0}.plugin-features{display:flex;flex-wrap:wrap;gap:7px;margin-top:17px}.plugin-features span{font-size:12px;font-weight:700;background:#f0f1f3;border-radius:99px;padding:7px 10px}.plugin-status{min-width:220px;display:flex;flex-direction:column;align-items:flex-end;justify-content:center;gap:10px}.plugin-status-badge{font-size:12px;font-weight:800;border:1px solid #dfe1e4;border-radius:99px;padding:7px 10px}.plugin-status-badge.on{background:#1d1f23;color:#fff;border-color:#1d1f23}.plugin-status .btn{display:inline-block;text-decoration:none;border:1px solid #d9dbe0;border-radius:11px;background:#fff;color:#1d1f23;padding:10px 14px;font-weight:800;cursor:pointer}.plugin-status .btn.primary{background:#1d1f23;color:#fff;border-color:#1d1f23}.plugin-status form{margin:0}.plugin-note{font-size:11px!important;text-align:right;max-width:240px!important}.plugin-preserve{margin-top:20px;border-top:1px solid #ececef;padding-top:17px;font-size:13px;color:#6e7177}.plugin-preserve strong{color:#24262a}@media(max-width:720px){.plugin-card{grid-template-columns:1fr}.plugin-status{align-items:flex-start}.plugin-note{text-align:left!important}}
</style></head><body class="plugins-page"><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='plugins';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div><main class="chat-main plugins-main"><?php require __DIR__.'/includes/member-header.php'; ?><div class="plugins-wrap"><div class="plugins-intro"><h1>Extend your VP3.</h1><p>VP3 stays your personal agent, transcription and personal-URL account. Plugins add specialized workspaces without changing who you are or creating another account.</p></div>
<section class="plugin-card"><div><small>Professional plugin</small><h2>Music Workspace</h2><p>Turn on the existing VP3 music system for tracks, albums, releases, collaboration, production and music-supervisor workflows.</p><div class="plugin-features"><span>Tracks & albums</span><span>Multiuser player</span><span>Stems & versions</span><span>Release calendar</span><span>Team collaboration</span><span>Producer tools</span><span>Music Supervisor</span></div><div class="plugin-preserve"><strong>Your data is durable.</strong> Turning Music Workspace off hides the plugin but does not delete music, releases, Team memberships, messages or history.</div></div><div class="plugin-status">
<?php if(!empty($status['enabled'])): ?><span class="plugin-status-badge on">Enabled</span><a class="btn primary" href="<?= e($workspaceUrl) ?>">Open Music Workspace</a><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="music_disable"><button class="btn" type="submit">Disable</button></form>
<?php elseif(!empty($status['entitled'])): ?><span class="plugin-status-badge">Available</span><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="music_enable"><button class="btn primary" type="submit">Enable Music Workspace</button></form>
<?php else: ?><span class="plugin-status-badge">Upgrade required</span><a class="btn primary" href="<?= e(url('/subscription.php')) ?>">View plans</a><?php endif; ?>
<p class="plugin-note">Package: <?= e((string)$status['package']) ?><?= !empty($status['legacy_grandfathered'])?' · Existing Music access preserved':'' ?></p></div></section></div></main></div><script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script></body></html>