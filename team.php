<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user=current_user();
if(!$user){flash('error','Please sign in to continue.');redirect(url('/login.php'));}
artist_workspace_v104_ensure_schema();

$pdo=db();
if(!$pdo){flash('error','Database unavailable.');redirect(url('/account.php'));}
$teamInternalAdmin=function_exists('subscription_is_internal_admin')&&subscription_is_internal_admin($user);
$teamState=team_subscription_state($user,$pdo);
if(empty($teamState['authorized'])){
    http_response_code(403);exit('An enabled VP3 workspace with Team management capability is required to manage a team.');
}

$ownerUserId=(int)$user['id'];
$teamRoles=artist_workspace_v104_team_roles();
$editId=max(0,(int)($_GET['edit']??0));
$editing=$editId>0?artist_workspace_v104_team_member($pdo,$ownerUserId,$editId):null;
if($editId>0&&!$editing){flash('error','That person is not part of your workspace.');redirect(url('/team.php'));}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/team.php'));}
    $action=(string)($_POST['action']??'add');
    $memberId=max(0,(int)($_POST['id']??0));
    try{
        if($action==='remove'){
            if(!artist_workspace_v104_team_member($pdo,$ownerUserId,$memberId))throw new RuntimeException('That team membership is not available.');
            artist_workspace_v104_detach_member($pdo,$ownerUserId,$memberId);
            flash('notice','Team member removed. Their VP3 account and subscription were not changed.');
            redirect(url('/team.php'));
        }
        if($action==='update_role'){
            $teamRole=trim((string)($_POST['team_role']??''));
            if(!artist_workspace_v104_valid_team_role($teamRole))throw new RuntimeException('Select Manager or Producer.');
            if(!artist_workspace_v104_team_member($pdo,$ownerUserId,$memberId))throw new RuntimeException('That team membership is not available.');
            artist_workspace_v104_attach_member($pdo,$ownerUserId,$memberId,$teamRole);
            flash('notice','Team role updated.');
            redirect(url('/team.php'));
        }

        $email=strtolower(trim((string)($_POST['email']??'')));
        $teamRole=trim((string)($_POST['team_role']??'producer'));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
        if(!artist_workspace_v104_valid_team_role($teamRole))throw new RuntimeException('Select Manager or Producer.');

        $pdo->beginTransaction();
        try{
            $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([$ownerUserId]);
            if(!(int)$lock->fetchColumn())throw new RuntimeException('Workspace owner is unavailable.');

            $lockedTeamState=team_subscription_state($user,$pdo);
            $count=(int)$lockedTeamState['used'];
            if(empty($lockedTeamState['included'])){
                throw new RuntimeException((string)$lockedTeamState['package_name'].' does not include Team seats. Upgrade your package before adding a team member.');
            }
            if(empty($lockedTeamState['unlimited'])&&$count>=(int)$lockedTeamState['limit']){
                throw new RuntimeException('Your '.(string)$lockedTeamState['package_name'].' package includes '.max(0,(int)$lockedTeamState['limit']).' team seats. Upgrade the package or remove a team member before adding another.');
            }

            $find=$pdo->prepare('SELECT id,display_name,email FROM users WHERE email=? LIMIT 1');
            $find->execute([$email]);
            $existing=$find->fetch();
            if($existing){
                $newMemberId=(int)$existing['id'];
                if($newMemberId===$ownerUserId)throw new RuntimeException('You cannot add your own account as a team member.');
                artist_workspace_v104_attach_member($pdo,$ownerUserId,$newMemberId,$teamRole);
                $pdo->commit();
                flash('notice',(string)$existing['display_name'].' was linked to your workspace. Their package and account identity were left unchanged.');
                redirect(url('/team.php'));
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
            artist_workspace_v104_attach_member($pdo,$ownerUserId,$newMemberId,$teamRole);
            $pdo->commit();
            if(function_exists('subscription_assign_default_trial'))subscription_assign_default_trial($newMemberId);
            flash('notice','New VP3 account created and linked to your workspace. Manager/Producer access exists only inside this workspace.');
            redirect(url('/team.php'));
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        redirect(url('/team.php'.($memberId>0?'?edit='.$memberId:'')));
    }
}

$teamMembers=artist_workspace_v104_team_members($pdo,$ownerUserId);
$teamState=team_subscription_state($user,$pdo);
$teamCount=(int)$teamState['used'];
$teamCanAdd=!empty($teamState['can_add']);
$teamLimitLabel=team_subscription_limit_label($teamState);
$remainingLabel=!empty($teamState['unlimited'])?'Unlimited':number_format((int)$teamState['remaining']);
$notice=flash('notice');
$errorNotice=flash('error');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f6f7f8">
<title>VP3 | My Team</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/team.css?v=team-front-v1-20260907')) ?>">
</head>
<body class="team-page">
<div class="chat-app">
  <?php
    $workspaceSidebarUser=$user;
    $workspaceSidebarActive='team';
    require __DIR__ . '/includes/workspace-sidebar-v82.php';
  ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main team-main">
    <?php
      $memberHeaderUser=$user;
      $memberHeaderTitle='My Team';
      $memberHeaderSubtitle='People who can work inside your VP3 workspace';
      $memberHeaderActions=$teamCanAdd
        ? '<a class="team-button primary" href="'.e(url('/team.php?new=1#team-add')).'">+ Add Team Member</a>'
        : '<a class="team-button" href="'.e(url('/subscription.php')).'">View Plans</a>';
      require __DIR__ . '/includes/member-header.php';
    ?>

    <section class="team-canvas">
      <div class="team-inner">
        <?php if($notice):?><div class="team-notice success" role="status"><?= e($notice) ?></div><?php endif;?>
        <?php if($errorNotice):?><div class="team-notice error" role="alert"><?= e($errorNotice) ?></div><?php endif;?>

        <section class="team-metrics" aria-label="Team package status">
          <article><span>Current package</span><strong><?= e((string)$teamState['package_name']) ?></strong><small><?= $teamInternalAdmin?'Internal admin access':'Subscription authority' ?></small></article>
          <article><span>Team seats</span><strong><?= number_format($teamCount) ?> / <?= e($teamLimitLabel) ?></strong><small>Active members consuming seats</small></article>
          <article><span>Available seats</span><strong><?= e($remainingLabel) ?></strong><small><?= $teamCanAdd?'Ready for another teammate':'Current package capacity' ?></small></article>
        </section>

        <?php if(empty($teamState['included'])):?><div class="team-notice">Your <strong><?= e((string)$teamState['package_name']) ?></strong> package does not include Team seats. Existing relationships remain intact. <a href="<?= e(url('/subscription.php')) ?>">Compare packages</a> to add Team access.</div>
        <?php elseif(empty($teamState['unlimited'])&&(int)$teamState['limit']===0):?><div class="team-notice">Your <strong><?= e((string)$teamState['package_name']) ?></strong> package currently includes zero Team seats. Existing relationships remain intact, but you cannot add another member until the package changes.</div>
        <?php elseif(!empty($teamState['over_limit'])):?><div class="team-notice">Your Team has <strong><?= number_format($teamCount) ?></strong> active members, while <strong><?= e((string)$teamState['package_name']) ?></strong> currently allows <strong><?= e($teamLimitLabel) ?></strong>. No one was removed by the package change. Remove members or <a href="<?= e(url('/subscription.php')) ?>">upgrade the package</a> before adding another.</div>
        <?php elseif(!$teamCanAdd&&!empty($teamState['included'])):?><div class="team-notice">All <?= e($teamLimitLabel) ?> Team seats in <strong><?= e((string)$teamState['package_name']) ?></strong> are in use. Remove a member or <a href="<?= e(url('/subscription.php')) ?>">upgrade the package</a> to add another.</div><?php endif;?>

        <section class="team-panel">
          <header class="team-panel-head"><div><span>Workspace access</span><h2>Team members</h2><p>Manager and Producer are workspace roles. A member’s own VP3 package and profile remain separate.</p></div><strong><?= number_format($teamCount) ?></strong></header>
          <div class="team-list">
            <?php foreach($teamMembers as $member):$memberSub=subscription_current_for_user_id((int)$member['id'],$pdo);?>
              <article class="team-member">
                <div class="team-person">
                  <span class="team-avatar"><?php if(!empty($member['avatar_path'])):?><img src="<?= e(user_avatar_url($member)) ?>" alt=""><?php else:?><?= e(user_initials($member)) ?><?php endif;?></span>
                  <div><strong><?= e((string)$member['display_name']) ?></strong><small><?= e((string)$member['email']) ?></small></div>
                </div>
                <div class="team-member-meta"><span>Workspace role</span><strong><?= e($teamRoles[(string)$member['team_role']]??ucfirst((string)$member['team_role'])) ?></strong></div>
                <div class="team-member-meta"><span>Account package</span><strong><?= e((string)($memberSub['package_name']??'No package')) ?></strong></div>
                <div class="team-member-status <?= (int)$member['is_active']===1?'active':'disabled' ?>"><i></i><?= (int)$member['is_active']===1?'Active':'Disabled' ?></div>
                <div class="team-member-actions">
                  <a class="team-button small" href="<?= e(url('/team.php?edit='.(int)$member['id'].'#team-edit')) ?>">Role</a>
                  <form method="post" onsubmit="return confirm('Remove this person from your workspace? Their VP3 account will remain intact.')"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$member['id'] ?>"><button class="team-button small danger" type="submit">Remove</button></form>
                </div>
              </article>
            <?php endforeach;?>
            <?php if(!$teamMembers):?><div class="team-empty"><strong>No team members yet.</strong><span><?= $teamCanAdd?'Add a Manager or Producer when you are ready to collaborate.':'Your package does not currently have an open Team seat.' ?></span></div><?php endif;?>
          </div>
        </section>

        <?php if(isset($_GET['new'])&&$teamCanAdd):?>
        <section class="team-panel team-form-panel" id="team-add">
          <header class="team-panel-head"><div><span>Add collaborator</span><h2>Link or create an account</h2><p>This uses one Team seat from <?= e((string)$teamState['package_name']) ?>. Existing VP3 accounts are linked without changing their package.</p></div><a class="team-button small" href="<?= e(url('/team.php')) ?>">Close</a></header>
          <form class="team-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
            <label><span>Email</span><input name="email" type="email" maxlength="190" required></label>
            <label><span>Workspace role</span><select name="team_role" required><?php foreach($teamRoles as $role=>$label):?><option value="<?= e($role) ?>"><?= e($label) ?></option><?php endforeach;?></select></label>
            <label><span>Name <small>new accounts only</small></span><input name="display_name" maxlength="120"></label>
            <label><span>Temporary password <small>new accounts only</small></span><input name="password" type="password" minlength="12" autocomplete="new-password"></label>
            <p>New accounts receive the configured default Free Trial. Existing accounts keep their current package, password and profile.</p>
            <div class="team-form-actions"><button class="team-button primary" type="submit">Add Team Member</button><a class="team-button" href="<?= e(url('/team.php')) ?>">Cancel</a></div>
          </form>
        </section>
        <?php endif;?>

        <?php if($editing):?>
        <section class="team-panel team-form-panel" id="team-edit">
          <header class="team-panel-head"><div><span>Workspace role</span><h2><?= e((string)$editing['display_name']) ?></h2><p>Changing this role affects only access inside your workspace.</p></div><a class="team-button small" href="<?= e(url('/team.php')) ?>">Close</a></header>
          <form class="team-form compact" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_role"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <label><span>Role</span><select name="team_role" required><?php foreach($teamRoles as $role=>$label):?><option value="<?= e($role) ?>" <?= (string)$editing['team_role']===$role?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
            <div class="team-form-actions"><button class="team-button primary" type="submit">Save Workspace Role</button></div>
          </form>
        </section>
        <?php endif;?>
      </div>
    </section>
  </main>
</div>
<script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script>
</body>
</html>