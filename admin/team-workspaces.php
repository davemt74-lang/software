<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();

$pdo=db();$user=current_user();$userId=(int)($user['id']??0);
if(!$pdo||$userId<1){http_response_code(503);exit('Team workspaces are unavailable.');}
artist_workspace_v104_ensure_schema();workspace_team_v350_ensure_schema($pdo);
$memberships=workspace_team_v350_memberships_for_user($pdo,$userId,'active');
if(!$memberships&&!subscription_is_internal_admin($user)){
    http_response_code(403);exit('No active Team workspaces are linked to this account.');
}

$adminTitle='Team Workspaces';$adminActive='team-workspaces';require __DIR__.'/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div><span class="status">Contextual Access</span><h2>Team Workspaces</h2><p class="muted">Choose the workspace you want to work in. Manager and Producer authority applies only inside the selected active membership.</p></div>
  </div>
  <div class="admin-card-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
    <?php foreach($memberships as $membership):
      $ownerId=(int)$membership['artist_user_id'];$role=(string)$membership['team_role'];$workspaceId=0;
      if(table_exists('artist_workspaces_v181')){$ws=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE artist_user_id=? LIMIT 1');$ws->execute([$ownerId]);$workspaceId=(int)$ws->fetchColumn();}
      if($role==='manager'){
          if($workspaceId<1)continue;
          $open=url('/music-workspace.php?workspace='.$workspaceId);
          $description='Manage the workspace catalog, production and releases through the canonical Music Workspace.';
      }elseif($role==='producer'){
          $open=url('/admin/producer-tracks.php?artist_id='.$ownerId);
          $description='Open only production tracks explicitly assigned to you in this workspace.';
      }else{
          continue;
      }
    ?>
      <article class="panel" style="margin:0">
        <span class="status"><?= e(ucfirst($role)) ?> · Active</span>
        <h3 style="margin:10px 0 4px"><?= e((string)$membership['artist_name']) ?></h3>
        <p class="muted"><?= e((string)$membership['artist_email']) ?></p>
        <p class="muted"><?= e($description) ?></p>
        <a class="btn primary" href="<?= e($open) ?>">Open Workspace</a>
      </article>
    <?php endforeach;?>
    <?php if(!$memberships):?><p class="muted">No active Team relationships found.</p><?php endif;?>
  </div>
</div>
<?php require __DIR__.'/_footer.php'; ?>
