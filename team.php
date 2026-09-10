<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$user=current_user();
if(!$user){flash('error','Please sign in to continue.');redirect(url('/login.php'));}
artist_workspace_v104_ensure_schema();

$pdo=db();
if(!$pdo){flash('error','Database unavailable.');redirect(url('/account.php'));}
workspace_team_v350_ensure_schema($pdo);
$teamInternalAdmin=function_exists('subscription_is_internal_admin')&&subscription_is_internal_admin($user);
$teamState=team_subscription_state($user,$pdo);
if(empty($teamState['authorized'])){
    http_response_code(403);exit('An enabled VP3 workspace with Team management capability is required to manage a team.');
}

$ownerUserId=(int)$user['id'];
$teamRoles=artist_workspace_v104_team_roles();
$memberId=max(0,(int)($_GET['edit']??0));

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/team.php'));}
    $action=(string)($_POST['action']??'invite');
    $targetId=max(0,(int)($_POST['id']??0));
    try{
        if($action==='revoke_invite'){
            workspace_team_v350_revoke_invitation($pdo,$ownerUserId,$targetId);
            flash('notice','Pending Team invitation revoked.');
            redirect(url('/team.php'));
        }
        if($action==='suspend'){
            workspace_team_v350_set_status($pdo,$ownerUserId,$targetId,'suspended');
            flash('notice','Team member suspended. Workspace, Team General and teammate DM access were revoked immediately; their VP3 account remains intact.');
            redirect(url('/team.php'));
        }
        if($action==='resume'){
            workspace_team_v350_set_status($pdo,$ownerUserId,$targetId,'active');
            flash('notice','Team membership resumed.');
            redirect(url('/team.php'));
        }
        if($action==='remove'){
            workspace_team_v350_set_status($pdo,$ownerUserId,$targetId,'removed');
            flash('notice','Team member removed. Their VP3 account, subscription, profile and membership history were preserved.');
            redirect(url('/team.php'));
        }
        if($action==='update_role'){
            $teamRole=trim((string)($_POST['team_role']??''));
            workspace_team_v350_change_role($pdo,$ownerUserId,$targetId,$teamRole);
            flash('notice','Workspace role updated without changing membership state or the member’s VP3 identity.');
            redirect(url('/team.php'));
        }
        if($action!=='invite')throw new RuntimeException('Unknown Team action.');

        $teamState=team_subscription_state($user,$pdo);
        if(empty($teamState['can_add']))throw new RuntimeException('No Team seat is currently available. Upgrade the workspace capacity or free a seat before inviting another member.');
        $email=strtolower(trim((string)($_POST['email']??'')));
        $teamRole=trim((string)($_POST['team_role']??'producer'));
        $invite=workspace_team_v350_create_invitation($pdo,$ownerUserId,$email,$teamRole,$ownerUserId);
        $_SESSION['team_invite_share_v350']=$invite;
        flash('notice','Team invitation created. Pending invitations do not consume a Team seat; capacity is rechecked when the invitation is accepted.');
        redirect(url('/team.php'));
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect(url('/team.php'.($targetId>0?'?edit='.$targetId:'')));
    }
}

$teamMembers=workspace_team_v350_members($pdo,$ownerUserId,false);
$pendingInvites=workspace_team_v350_pending_invitations($pdo,$ownerUserId);
$teamState=team_subscription_state($user,$pdo);
$teamCount=(int)$teamState['used'];
$teamCanAdd=!empty($teamState['can_add']);
$teamLimitLabel=team_subscription_limit_label($teamState);
$remainingLabel=!empty($teamState['unlimited'])?'Unlimited':number_format((int)$teamState['remaining']);
$editing=null;
foreach($teamMembers as $candidate)if((int)$candidate['id']===$memberId){$editing=$candidate;break;}
if($memberId>0&&!$editing){flash('error','That Team membership is not available.');redirect(url('/team.php'));}
$inviteShare=$_SESSION['team_invite_share_v350']??null;unset($_SESSION['team_invite_share_v350']);
$notice=flash('notice');$errorNotice=flash('error');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f6f7f8">
<title>VP3 | My Team</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/team.css?v=team-front-v1-20260907')) ?>">
<style>
.team-share{display:grid;gap:8px;padding:14px;border:1px solid #d9e0e7;border-radius:12px;background:#f8fafb}.team-share-row{display:flex;gap:8px}.team-share input{flex:1;min-width:0;padding:10px;border:1px solid #ccd5df;border-radius:9px}.team-member-status.suspended{background:#fff3d7;color:#7c5a12}.team-member-status.suspended i{background:#d99b19}.team-invite-meta{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.team-invite-meta small{color:#6d7680}
</style>
</head>
<body class="team-page">
<div class="chat-app">
  <?php $workspaceSidebarUser=$user;$workspaceSidebarActive='team';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main team-main">
    <?php
      $memberHeaderUser=$user;$memberHeaderTitle='My Team';$memberHeaderSubtitle='Workspace roles, invitations and access lifecycle';
      $memberHeaderActions=$teamCanAdd?'<a class="team-button primary" href="'.e(url('/team.php?new=1#team-add')).'">+ Invite Team Member</a>':'<a class="team-button" href="'.e(url('/subscription.php')).'">View Plans</a>';
      require __DIR__.'/includes/member-header.php';
    ?>
    <section class="team-canvas"><div class="team-inner">
      <?php if($notice):?><div class="team-notice success" role="status"><?= e($notice) ?></div><?php endif;?>
      <?php if($errorNotice):?><div class="team-notice error" role="alert"><?= e($errorNotice) ?></div><?php endif;?>
      <?php if(is_array($inviteShare)&&!empty($inviteShare['link'])):?><section class="team-share"><strong>Share this invitation link</strong><span>This one-time link is shown only now. The invited person must use the exact invited email address.</span><div class="team-share-row"><input id="teamInviteShare" readonly value="<?= e((string)$inviteShare['link']) ?>"><button class="team-button" type="button" id="teamInviteCopy">Copy</button></div></section><?php endif;?>

      <section class="team-metrics" aria-label="Team entitlement status">
        <article><span>Current package</span><strong><?= e((string)$teamState['package_name']) ?></strong><small><?= $teamInternalAdmin?'Internal admin access':'Base + add-on entitlement' ?></small></article>
        <article><span>Active Team seats</span><strong><?= number_format($teamCount) ?> / <?= e($teamLimitLabel) ?></strong><small>Suspended and pending people do not consume seats</small></article>
        <article><span>Available seats</span><strong><?= e($remainingLabel) ?></strong><small><?= $teamCanAdd?'Ready for an invitation':'Current effective capacity' ?></small></article>
      </section>

      <?php if(empty($teamState['included'])):?><div class="team-notice">Your current product access does not include Team seats. Existing membership history remains intact. <a href="<?= e(url('/subscription.php')) ?>">Compare packages</a>.</div>
      <?php elseif(!empty($teamState['over_limit'])):?><div class="team-notice">Your workspace is over its current Team capacity. No relationship was deleted by the package change. Suspend/remove members or <a href="<?= e(url('/subscription.php')) ?>">increase capacity</a> before activating another seat.</div><?php endif;?>

      <section class="team-panel">
        <header class="team-panel-head"><div><span>Workspace access</span><h2>Team members</h2><p>Manager and Producer are contextual workspace roles. Suspending or removing a membership immediately removes it from the active Team authorization projection.</p></div><strong><?= number_format($teamCount) ?> active</strong></header>
        <div class="team-list">
          <?php foreach($teamMembers as $member):$memberSub=subscription_current_for_user_id((int)$member['id'],$pdo);$status=(string)$member['membership_status'];?>
            <article class="team-member">
              <div class="team-person"><span class="team-avatar"><?php if(!empty($member['avatar_path'])):?><img src="<?= e(user_avatar_url($member)) ?>" alt=""><?php else:?><?= e(user_initials($member)) ?><?php endif;?></span><div><strong><?= e((string)$member['display_name']) ?></strong><small><?= e((string)$member['email']) ?></small></div></div>
              <div class="team-member-meta"><span>Workspace role</span><strong><?= e($teamRoles[(string)$member['team_role']]??ucfirst((string)$member['team_role'])) ?></strong></div>
              <div class="team-member-meta"><span>Personal package</span><strong><?= e((string)($memberSub['package_name']??'No package')) ?></strong></div>
              <div class="team-member-status <?= e($status) ?>"><i></i><?= e(ucfirst($status)) ?><?= (int)$member['is_active']!==1?' · Account disabled':'' ?></div>
              <div class="team-member-actions">
                <a class="team-button small" href="<?= e(url('/team.php?edit='.(int)$member['id'].'#team-edit')) ?>">Role</a>
                <?php if($status==='active'):?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$member['id'] ?>"><button class="team-button small" type="submit">Suspend</button></form>
                <?php elseif($status==='suspended'):?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><input type="hidden" name="id" value="<?= (int)$member['id'] ?>"><button class="team-button small" type="submit">Resume</button></form><?php endif;?>
                <form method="post" onsubmit="return confirm('Remove this workspace membership? The VP3 account and history will remain intact.')"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$member['id'] ?>"><button class="team-button small danger" type="submit">Remove</button></form>
              </div>
            </article>
          <?php endforeach;?>
          <?php if(!$teamMembers):?><div class="team-empty"><strong>No Team members yet.</strong><span>Invite a Manager or Producer when you are ready to collaborate.</span></div><?php endif;?>
        </div>
      </section>

      <section class="team-panel">
        <header class="team-panel-head"><div><span>Invitation queue</span><h2>Pending invitations</h2><p>An invitation is not a membership and consumes no seat until accepted. Acceptance rechecks identity, expiration and current Team capacity.</p></div><strong><?= number_format(count($pendingInvites)) ?></strong></header>
        <div class="team-list">
          <?php foreach($pendingInvites as $invite):?>
            <article class="team-member"><div class="team-person"><span class="team-avatar">✉</span><div><strong><?= e((string)($invite['existing_user_name']?:$invite['invited_email'])) ?></strong><small><?= e((string)$invite['invited_email']) ?></small></div></div><div class="team-member-meta"><span>Invited role</span><strong><?= e($teamRoles[(string)$invite['team_role']]??ucfirst((string)$invite['team_role'])) ?></strong></div><div class="team-invite-meta"><small>Expires <?= e(date('M j, Y',strtotime((string)$invite['expires_at']))) ?></small></div><div class="team-member-actions"><form method="post" onsubmit="return confirm('Revoke this pending invitation?')"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_invite"><input type="hidden" name="id" value="<?= (int)$invite['id'] ?>"><button class="team-button small danger" type="submit">Revoke</button></form></div></article>
          <?php endforeach;?>
          <?php if(!$pendingInvites):?><div class="team-empty"><strong>No pending invitations.</strong><span>Only accepted, active members consume Team seats.</span></div><?php endif;?>
        </div>
      </section>

      <?php if(isset($_GET['new'])&&$teamCanAdd):?>
      <section class="team-panel team-form-panel" id="team-add">
        <header class="team-panel-head"><div><span>Invite collaborator</span><h2>Send workspace access</h2><p>Do not create a password for someone else. They sign in to their existing VP3 account or create their own account with the invited email.</p></div><a class="team-button small" href="<?= e(url('/team.php')) ?>">Close</a></header>
        <form class="team-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="invite"><label><span>Email</span><input name="email" type="email" maxlength="190" required></label><label><span>Workspace role</span><select name="team_role" required><?php foreach($teamRoles as $role=>$label):?><option value="<?= e($role) ?>"><?= e($label) ?></option><?php endforeach;?></select></label><p>Invitations expire after seven days. Existing users also receive an in-app notification. New users create their own VP3 credentials.</p><div class="team-form-actions"><button class="team-button primary" type="submit">Create Invitation</button><a class="team-button" href="<?= e(url('/team.php')) ?>">Cancel</a></div></form>
      </section><?php endif;?>

      <?php if($editing):?>
      <section class="team-panel team-form-panel" id="team-edit"><header class="team-panel-head"><div><span>Workspace role</span><h2><?= e((string)$editing['display_name']) ?></h2><p>Changing a role never changes a suspended membership back to active.</p></div><a class="team-button small" href="<?= e(url('/team.php')) ?>">Close</a></header><form class="team-form compact" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_role"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><label><span>Role</span><select name="team_role" required><?php foreach($teamRoles as $role=>$label):?><option value="<?= e($role) ?>" <?= (string)$editing['team_role']===$role?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label><div class="team-form-actions"><button class="team-button primary" type="submit">Save Workspace Role</button></div></form></section><?php endif;?>
    </div></section>
  </main>
</div>
<script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script>
<script>
(()=>{const input=document.getElementById('teamInviteShare'),btn=document.getElementById('teamInviteCopy');if(input){try{input.value=new URL(input.value,location.origin).href}catch(e){}}if(btn&&input)btn.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(input.value);btn.textContent='Copied'}catch(e){input.select();document.execCommand('copy');btn.textContent='Copied'}})})();
</script>
</body></html>
