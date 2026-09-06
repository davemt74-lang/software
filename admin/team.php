<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';

$user=current_user();
if(!$user){flash('error','Please sign in to continue.');redirect(url('/login.php'));}
artist_workspace_v104_ensure_schema();

$isInternalAdmin=user_has_role('admin',$user);
$isLegacyArtist=user_has_role('artist',$user);
$packageTeamManage=function_exists('subscription_package_grants_permission')&&subscription_package_grants_permission($user,'team.manage');
$packageAdminAccess=function_exists('subscription_package_grants_permission')&&subscription_package_grants_permission($user,'admin.access');
if(!$isInternalAdmin&&!$isLegacyArtist&&!($packageTeamManage&&$packageAdminAccess)){
    http_response_code(403);exit('Team management is not included in your current package.');
}

$pdo=db();if(!$pdo){flash('error','Database unavailable.');redirect(url('/account.php'));}
$artistUserId=(int)$user['id'];
$teamLimit=artist_workspace_v104_team_limit($user);
$teamRoles=artist_workspace_v104_team_roles();
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
            // Serialize seat allocation so concurrent requests cannot exceed the package limit.
            $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$artistUserId]);
            if(!(int)$lock->fetchColumn())throw new RuntimeException('Workspace owner is unavailable.');
            $count=artist_workspace_v104_team_count($pdo,$artistUserId);
            if($teamLimit<1||$count>=$teamLimit)throw new RuntimeException('Your current package includes '.max(0,$teamLimit).' team seats. Upgrade the package or remove a team member before adding another.');

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
            if(table_exists('user_account_types'))$pdo->prepare("INSERT IGNORE INTO user_account_types (user_id,role) VALUES (?,'fan')")->execute([$newMemberId]);
            artist_workspace_v104_attach_member($pdo,$artistUserId,$newMemberId,$teamRole);
            $pdo->commit();
            // New identity receives the same default trial as any other signup.
            if(function_exists('subscription_assign_default_trial'))subscription_assign_default_trial($newMemberId);
            flash('notice','New VP3 account created and linked to your workspace. Manager/Producer access exists only inside this workspace.');
            redirect(url('/admin/team.php'));
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }catch(Throwable $e){flash('error',$e->getMessage());redirect(url('/admin/team.php'.($memberId>0?'?edit='.$memberId:'')));}
}

$teamMembers=artist_workspace_v104_team_members($pdo,$artistUserId);$teamCount=count($teamMembers);
$adminTitle='Team';$adminActive='team';require __DIR__.'/_header.php';
?>
<div class="panel">
  <div class="content-library-heading"><div><span class="status">Workspace Collaboration</span><h2>Your Team</h2><p class="muted">Manager and Producer are workspace roles, not subscription types. Existing VP3 users can be linked without creating a duplicate account.</p></div><div class="actions"><span class="status">Seats: <?= (int)$teamCount ?> / <?= (int)$teamLimit ?></span><?php if($teamCount<$teamLimit):?><a class="btn primary" href="<?= e(url('/admin/team.php?new=1#team-add')) ?>">+ Add Team Member</a><?php endif;?></div></div>
  <?php if($teamLimit===0):?><div class="notice">Your current package does not include team seats. Add <strong>Team Seats</strong> to the package in Admin Packages or upgrade this account.</div><?php endif;?>
  <div class="table-wrap"><table><thead><tr><th>Team Member</th><th>Workspace Role</th><th>Account Package</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($teamMembers as $member):$memberSub=subscription_current_for_user_id((int)$member['id'],$pdo);?><tr><td><div class="admin-user-cell"><span class="admin-user-avatar admin-user-avatar-sm"><?php if(!empty($member['avatar_path'])):?><img src="<?= e(user_avatar_url($member)) ?>" alt=""><?php else:?><span><?= e(user_initials($member)) ?></span><?php endif;?></span><div><strong><?= e((string)$member['display_name']) ?></strong><br><span class="muted"><?= e((string)$member['email']) ?></span></div></div></td><td><?= e($teamRoles[(string)$member['team_role']]??ucfirst((string)$member['team_role'])) ?></td><td><?= e((string)($memberSub['package_name']??'No package')) ?></td><td><span class="status"><?= (int)$member['is_active']===1?'Active':'Disabled' ?></span></td><td class="actions"><a class="btn" href="<?= e(url('/admin/team.php?edit='.(int)$member['id'].'#team-edit')) ?>">Role</a><form class="inline-form" method="post" onsubmit="return confirm('Remove this person from your workspace? Their VP3 account will remain intact.')"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$member['id'] ?>"><button class="btn danger" type="submit">Remove</button></form></td></tr><?php endforeach;?>
  <?php if(!$teamMembers):?><tr><td colspan="5" class="muted">No team members yet.</td></tr><?php endif;?></tbody></table></div>
</div>

<?php if(isset($_GET['new'])&&$teamCount<$teamLimit):?><div class="panel" id="team-add"><div class="content-form-heading"><div><span class="status">Add Collaborator</span><h2>Link or Create Account</h2><p class="muted">If the email already belongs to a VP3 user, we link that account. Otherwise a new Free Trial account is created.</p></div><a class="btn" href="<?= e(url('/admin/team.php')) ?>">Close</a></div><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add"><div class="field"><label>Email</label><input name="email" type="email" maxlength="190" required></div><div class="field"><label>Workspace Role</label><select name="team_role" required><?php foreach($teamRoles as $role=>$label):?><option value="<?= e($role) ?>"><?= e($label) ?></option><?php endforeach;?></select></div><div class="field"><label>Name <small>(new accounts only)</small></label><input name="display_name" maxlength="120"></div><div class="field"><label>Temporary Password <small>(new accounts only)</small></label><input name="password" type="password" minlength="12" autocomplete="new-password"></div><div class="field full"><small>Adding an existing user never changes their package, password or profile. New accounts receive the configured default Free Trial.</small></div><div class="field full actions"><button class="btn primary" type="submit">Add Team Member</button><a class="btn" href="<?= e(url('/admin/team.php')) ?>">Cancel</a></div></form></div><?php endif;?>

<?php if($editing):?><div class="panel" id="team-edit"><div class="content-form-heading"><div><span class="status">Workspace Role</span><h2><?= e((string)$editing['display_name']) ?></h2><p class="muted">This changes only the person’s role in your workspace.</p></div><a class="btn" href="<?= e(url('/admin/team.php')) ?>">Close</a></div><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_role"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><div class="field"><label>Role</label><select name="team_role" required><?php foreach($teamRoles as $role=>$label):?><option value="<?= e($role) ?>" <?= (string)$editing['team_role']===$role?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></div><div class="field full actions"><button class="btn primary" type="submit">Save Workspace Role</button></div></form></div><?php endif;?>
<?php require __DIR__.'/_footer.php'; ?>