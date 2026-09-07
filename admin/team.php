<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';

$user=current_user();
if(!$user){flash('error','Please sign in to continue.');redirect(url('/login.php'));}
artist_workspace_v104_ensure_schema();

// Internal admins may manage My Team without a commercial package or Artist
// role. Regular users still require Artist identity + persisted Team authority;
// their package controls only commercial Team availability/capacity.
$teamInternalAdmin=function_exists('subscription_is_internal_admin')&&subscription_is_internal_admin($user);
if(!$teamInternalAdmin){
    if(!user_has_role('artist',$user)){
        http_response_code(403);exit('Artist workspace ownership is required to manage a team.');
    }
    if(!has_permission('admin.access',$user)||!has_permission('team.manage',$user)){
        http_response_code(403);exit('Your Artist identity does not have Team management permission.');
    }
}

$pdo=db();if(!$pdo){flash('error','Database unavailable.');redirect(url('/account.php'));}
$artistUserId=(int)$user['id'];
$teamRoles=artist_workspace_v104_team_roles();
$teamState=team_subscription_state($user,$pdo);
$teamLimit=$teamState['limit'];
$editId=max(0,(int)($_GET['edit']??0));
$editing=$editId>0?artist_workspace_v104_team_member($pdo,$artistUserId,$editId):null;
if($editId>0&&!$editing){flash('error','That person is not part of your workspace.');redirect(url('/admin/team.php'));}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/admin/team.php'));}
    $action=(string)($_POST['action']??'add');
    $memberId=max(0,(int)($_POST['id']??0));
    try{
        if($action==='remove'){
            if(!artist_workspace_v104_team_member($pdo,$artistUserId,$memberId))throw new RuntimeException('That team membership is not available.');
            artist_workspace_v104_detach_member($pdo,$artistUserId,$memberId);
            flash('notice','Team member removed. Their VP3 account and subscription were not changed.');
            redirect(url('/admin/team.php'));
        }
        if($action==='update_role'){
            $teamRole=trim((string)($_POST['team_role']??''));
            if(!artist_workspace_v104_valid_team_role($teamRole))throw new RuntimeException('Select Manager or Producer.');
            if(!artist_workspace_v104_team_member($pdo,$artistUserId,$memberId))throw new RuntimeException('That team membership is not available.');
            artist_workspace_v104_attach_member($pdo,$artistUserId,$memberId,$teamRole);
            flash('notice','Team role updated.');redirect(url('/admin/team.php'));
        }

        $email=strtolower(trim((string)($_POST['email']??'')));
        $teamRole=trim((string)($_POST['team_role']??'producer'));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
        if(!artist_workspace_v104_valid_team_role($teamRole))throw new RuntimeException('Select Manager or Producer.');

        $pdo->beginTransaction();
        try{
            // Subscription package changes lock this same account row. Re-read
            // Team state after acquiring it so a concurrent downgrade/upgrade
            // cannot leave this request using a stale seat allowance.
            $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$artistUserId]);
            if(!(int)$lock->fetchColumn())throw new RuntimeException('Workspace owner is unavailable.');
            $lockedTeamState=team_subscription_state($user,$pdo);
            $count=(int)$lockedTeamState['used'];
            if(empty($lockedTeamState['included'])){
                throw new RuntimeException((string)$lockedTeamState['package_name'].' does not include Team seats. Upgrade your package before adding a team member.');
            }
            if(empty($lockedTeamState['unlimited'])&&$count>=(int)$lockedTeamState['limit']){
                throw new RuntimeException('Your '.(string)$lockedTeamState['package_name'].' package includes '.max(0,(int)$lockedTeamState['limit']).' team seats. Upgrade the package or remove a team member before adding another.');
            }

            $find=$pdo->prepare('SELECT id,display_name,email FROM users WHERE email=? LIMIT 1');$find->execute([$email]);$existing=$find->fetch();
            if($existing){
                $newMemberId=(int)$existing['id'];
                if($newMemberId===$artistUserId)throw new RuntimeException('You cannot add your own account as a team member.');
                artist_workspace_v104_attach_member($pdo,$artistUserId,$newMemberId,$teamRole);
                $pdo->commit();
                flash('notice',(string)$existing['display_name'].' was linked to your workspace. Their package and account identity were left unchanged.');
                redirect(url('/admin/team.php'));
            }

            $displayName=trim((string)($_POST['display_name']??''));
            $password=(string)($_POST['password']??'');
            if($displayName===''||mb_strlen($displayName)>120)throw new RuntimeException('For a new account, enter the person’s name.');
            if(strlen($password)<12)throw new RuntimeException('For a new account, set a temporary password with at least 12 characters.');
            $insert=$pdo->prepare("INSERT INTO users (email,password_hash,display_name,role,avatar_path,is_active) VALUES (?,?,?,'fan','',1)");
            $insert->execute([$email,password_hash($password,PASSWORD_DEFAULT),$displayName]);
            $newMemberId=(int)$pdo->lastInsertId();
            if(table_exists('user_account_types')){
                if(column_exists('user_account_types','assigned_explicitly_at'))$pdo->prepare("INSERT IGNORE INTO user_account_types (user_id,role,assigned_explicitly_at) VALUES (?,'fan',NOW())")->execute([$newMemberId]);
                else $pdo->prepare("INSERT IGNORE INTO user_account_types (user_id,role) VALUES (?,'fan')")->execute([$newMemberId]);
            }
            artist_workspace_v104_attach_member($pdo,$artistUserId,$newMemberId,$teamRole);
            $pdo->commit();
            if(function_exists('subscription_assign_default_trial'))subscription_assign_default_trial($newMemberId);
            flash('notice','New VP3 account created and linked to your workspace. Manager/Producer access exists only inside this workspace.');
            redirect(url('/admin/team.php'));
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }catch(Throwable $e){flash('error',$e->getMessage());redirect(url('/admin/team.php'.($memberId>0?'?edit='.$memberId:'')));}
}

$teamMembers=artist_workspace_v104_team_members($pdo,$artistUserId);
$teamState=team_subscription_state($user,$pdo);
$teamCount=(int)$teamState['used'];
$teamCanAdd=!empty($teamState['can_add']);
$teamLimitLabel=team_subscription_limit_label($teamState);
$remainingLabel=!empty($teamState['unlimited'])?'Unlimited':number_format((int)$teamState['remaining']);
$adminTitle='My Team';$adminActive='team';require __DIR__.'/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div><span class="status">Workspace Collaboration</span><h2>My Team</h2><p class="muted">Your subscription package controls Team availability and seat capacity. Manager and Producer remain workspace roles, not subscription types.</p></div>
    <div class="actions"><?php if($teamCanAdd):?><a class="btn primary" href="<?= e(url('/admin/team.php?new=1#team-add')) ?>">+ Add Team Member</a><?php else:?><a class="btn" href="<?= e(url('/subscription.php')) ?>">View Plans</a><?php endif;?></div>
  </div>

  <div class="admin-grid admin-grid-3" style="margin:16px 0">
    <div class="admin-card"><small class="muted">Current package</small><h3 style="margin:6px 0 0"><?= e((string)$teamState['package_name']) ?></h3></div>
    <div class="admin-card"><small class="muted">Team seats</small><h3 style="margin:6px 0 0"><?= number_format($teamCount) ?> / <?= e($teamLimitLabel) ?></h3></div>
    <div class="admin-card"><small class="muted">Available seats</small><h3 style="margin:6px 0 0"><?= e($remainingLabel) ?></h3></div>
  </div>

  <?php if(empty($teamState['included'])):?><div class="notice">Your <strong><?= e((string)$teamState['package_name']) ?></strong> package does not include Team seats. Existing relationships remain intact. <a href="<?= e(url('/subscription.php')) ?>">Compare packages</a> to add Team access.</div>
  <?php elseif(empty($teamState['unlimited'])&&(int)$teamState['limit']===0):?><div class="notice">Your <strong><?= e((string)$teamState['package_name']) ?></strong> package currently includes zero Team seats. Existing relationships remain intact, but you cannot add another member until the package changes.</div>
  <?php elseif(!empty($teamState['over_limit'])):?><div class="notice">Your Team has <strong><?= number_format($teamCount) ?></strong> members, while <strong><?= e((string)$teamState['package_name']) ?></strong> currently allows <strong><?= e($teamLimitLabel) ?></strong>. No one was removed by the package change. Remove members or <a href="<?= e(url('/subscription.php')) ?>">upgrade the package</a> before adding another.</div>
  <?php elseif(!$teamCanAdd&&!empty($teamState['included'])):?><div class="notice">All <?= e($teamLimitLabel) ?> Team seats in <strong><?= e((string)$teamState['package_name']) ?></strong> are in use. Remove a member or <a href="<?= e(url('/subscription.php')) ?>">upgrade the package</a> to add another.</div><?php endif;?>

  <div class="table-wrap"><table><thead><tr><th>Team Member</th><th>Workspace Role</th><th>Account Package</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($teamMembers as $member):$memberSub=subscription_current_for_user_id((int)$member['id'],$pdo);?><tr><td><div class="admin-user-cell"><span class="admin-user-avatar admin-user-avatar-sm"><?php if(!empty($member['avatar_path'])):?><img src="<?= e(user_avatar_url($member)) ?>" alt=""><?php else:?><span><?= e(user_initials($member)) ?></span><?php endif;?></span><div><strong><?= e((string)$member['display_name']) ?></strong><br><span class="muted"><?= e((string)$member['email']) ?></span></div></div></td><td><?= e($teamRoles[(string)$member['team_role']]??ucfirst((string)$member['team_role'])) ?></td><td><?= e((string)($memberSub['package_name']??'No package')) ?></td><td><span class="status"><?= (int)$member['is_active']===1?'Active':'Disabled' ?></span></td><td class="actions"><a class="btn" href="<?= e(url('/admin/team.php?edit='.(int)$member['id'].'#team-edit')) ?>">Role</a><form class="inline-form" method="post" onsubmit="return confirm('Remove this person from your workspace? Their VP3 account will remain intact.')"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$member['id'] ?>"><button class="btn danger" type="submit">Remove</button></form></td></tr><?php endforeach;?>
  <?php if(!$teamMembers):?><tr><td colspan="5" class="muted">No team members yet.</td></tr><?php endif;?></tbody></table></div>
</div>

<?php if(isset($_GET['new'])&&$teamCanAdd):?><div class="panel" id="team-add"><div class="content-form-heading"><div><span class="status">Add Collaborator</span><h2>Link or Create Account</h2><p class="muted">This uses one Team seat from <?= e((string)$teamState['package_name']) ?>. If the email already belongs to a VP3 user, we link that account. Otherwise a new Free Trial account is created.</p></div><a class="btn" href="<?= e(url('/admin/team.php')) ?>">Close</a></div><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add"><div class="field"><label>Email</label><input name="email" type="email" maxlength="190" required></div><div class="field"><label>Workspace Role</label><select name="team_role" required><?php foreach($teamRoles as $role=>$label):?><option value="<?= e($role) ?>"><?= e($label) ?></option><?php endforeach;?></select></div><div class="field"><label>Name <small>(new accounts only)</small></label><input name="display_name" maxlength="120"></div><div class="field"><label>Temporary Password <small>(new accounts only)</small></label><input name="password" type="password" minlength="12" autocomplete="new-password"></div><div class="field full"><small>Adding an existing user never changes their package, password or profile. New accounts receive the configured default Free Trial.</small></div><div class="field full actions"><button class="btn primary" type="submit">Add Team Member</button><a class="btn" href="<?= e(url('/admin/team.php')) ?>">Cancel</a></div></form></div><?php endif;?>

<?php if($editing):?><div class="panel" id="team-edit"><div class="content-form-heading"><div><span class="status">Workspace Role</span><h2><?= e((string)$editing['display_name']) ?></h2><p class="muted">This changes only the person’s role in your workspace and does not change either account’s package.</p></div><a class="btn" href="<?= e(url('/admin/team.php')) ?>">Close</a></div><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_role"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><div class="field"><label>Role</label><select name="team_role" required><?php foreach($teamRoles as $role=>$label):?><option value="<?= e($role) ?>" <?= (string)$editing['team_role']===$role?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></div><div class="field full actions"><button class="btn primary" type="submit">Save Workspace Role</button></div></form></div><?php endif;?>
<?php require __DIR__.'/_footer.php'; ?>