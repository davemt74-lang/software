<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();

$pdo=db();$user=current_user();$userId=(int)($user['id']??0);
if(!$pdo||$userId<1){http_response_code(503);exit('Team workspaces are unavailable.');}
artist_workspace_v104_ensure_schema();
$memberships=artist_workspace_v104_memberships_for_user($pdo,$userId);
if(!$memberships&&!subscription_is_internal_admin($user)){
    http_response_code(403);exit('No Artist Team workspaces are linked to this account.');
}

$adminTitle='Team Workspaces';$adminActive='team-workspaces';require __DIR__.'/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div><span class="status">Contextual Access</span><h2>Team Workspaces</h2><p class="muted">Choose the Artist relationship you want to work in. Manager and Producer authority applies only inside the selected Artist workspace.</p></div>
  </div>
  <div class="admin-card-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
    <?php foreach($memberships as $membership):
      $artistId=(int)$membership['artist_user_id'];$role=(string)$membership['team_role'];
      $open=$role==='manager'?url('/admin/team-workspace.php?artist_id='.$artistId):url('/admin/producer-tracks.php?artist_id='.$artistId);
    ?>
      <article class="panel" style="margin:0">
        <span class="status"><?= e(ucfirst($role)) ?></span>
        <h3 style="margin:10px 0 4px"><?= e((string)$membership['artist_name']) ?></h3>
        <p class="muted"><?= e((string)$membership['artist_email']) ?></p>
        <p class="muted"><?= $role==='manager'?'Manage this Artist’s private catalog and profile within the relationship scope.':'Open tracks explicitly assigned to you for production.' ?></p>
        <a class="btn primary" href="<?= e($open) ?>">Open Workspace</a>
      </article>
    <?php endforeach;?>
    <?php if(!$memberships):?><p class="muted">No Team relationships found.</p><?php endif;?>
  </div>
</div>
<?php require __DIR__.'/_footer.php'; ?>