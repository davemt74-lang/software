<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('users.manage');

if (!access_schema_ready()) redirect(url('/upgrade.php'));
$pdo=db();
if(!$pdo){flash('error','Database unavailable.');redirect(url('/admin/index.php'));}
if(!subscription_schema_ready($pdo))subscription_ensure_schema($pdo);

$current=current_user();
$editId=(int)($_GET['edit']??0);
$showNewForm=isset($_GET['new']);
$showForm=$showNewForm||$editId>0;
$editing=null;
$packages=subscription_packages(false);

function admin_user_internal_admin(PDO $pdo,int $userId,string $fallbackRole='fan'): bool
{
    return in_array('admin',user_account_types_for_user_id($userId,$fallbackRole),true);
}

function admin_user_workspace_artist(PDO $pdo,int $userId,string $fallbackRole='fan'): bool
{
    return in_array('artist',user_account_types_for_user_id($userId,$fallbackRole),true);
}

function admin_user_require_reason(string $value): string
{
    $value=trim($value);
    if($value==='')throw new RuntimeException('Enter a reason for this account adjustment.');
    return mb_strimwidth($value,0,500,'');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/admin/users.php'.($editId?'?edit='.$editId:'')));}
    $action=(string)($_POST['action']??'save');
    $id=(int)($_POST['id']??0);
    try{
        if($action==='assign_package'){
            $packageId=(int)($_POST['package_id']??0);
            $reason=admin_user_require_reason((string)($_POST['reason']??''));
            $endsAt=trim((string)($_POST['ends_at']??''));
            $overrideRaw=trim((string)($_POST['ai_token_override']??''));
            $override=$overrideRaw===''?null:max(0,(int)$overrideRaw);
            subscription_assign_package($id,$packageId,(string)($_POST['assignment_source']??'admin_assigned'),(int)$current['id'],$endsAt?:null,$override,!empty($_POST['billing_required']),$reason);
            flash('notice','Package assigned.');redirect(url('/admin/users.php?edit='.$id.'#subscription-panel'));
        }
        if($action==='remove_package'){
            $reason=admin_user_require_reason((string)($_POST['reason']??''));
            subscription_remove_package($id,(int)$current['id'],$reason);
            flash('notice','Package removed.');redirect(url('/admin/users.php?edit='.$id.'#subscription-panel'));
        }
        if($action==='add_tokens'){
            $amount=max(0,(int)($_POST['amount']??0));
            $reason=admin_user_require_reason((string)($_POST['reason']??''));
            $expiresAt=trim((string)($_POST['expires_at']??''));
            subscription_add_token_credit($id,$amount,(string)($_POST['source']??'admin_topup'),$reason,$expiresAt?:null,(int)$current['id']);
            flash('notice',number_format($amount).' AI tokens added.');redirect(url('/admin/users.php?edit='.$id.'#subscription-panel'));
        }
        if($action==='remove_credit'){
            $reason=admin_user_require_reason((string)($_POST['reason']??''));
            subscription_remove_token_credit((int)($_POST['credit_id']??0),$id,(int)$current['id'],$reason);
            flash('notice','Token credit balance removed.');redirect(url('/admin/users.php?edit='.$id.'#subscription-panel'));
        }
        if($action==='delete'){
            if($id===(int)$current['id'])throw new RuntimeException('You cannot delete your own account.');
            $target=$pdo->prepare('SELECT id,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');$target->execute([$id]);$row=$target->fetch();
            if($row&&admin_user_internal_admin($pdo,$id,(string)$row['role'])&&(int)$row['is_active']===1&&active_admin_user_count($pdo)<=1)throw new RuntimeException('You cannot delete the last active Admin account.');
            if($row)delete_local_upload((string)($row['avatar_path']??''));
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);flash('notice','User deleted.');redirect(url('/admin/users.php'));
        }

        $displayName=trim((string)($_POST['display_name']??''));
        $email=strtolower(trim((string)($_POST['email']??'')));
        $password=(string)($_POST['password']??'');
        $isActive=isset($_POST['is_active'])?1:0;
        $internalAdmin=isset($_POST['internal_admin']);
        $workspaceArtist=isset($_POST['workspace_artist']);
        $avatarPath=trim((string)($_POST['existing_avatar_path']??''));
        if($displayName==='')throw new RuntimeException('Display name is required.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
        if($id===(int)$current['id']&&$isActive!==1)throw new RuntimeException('You cannot deactivate your own account.');
        $check=$pdo->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');$check->execute([$email,$id]);if($check->fetch())throw new RuntimeException('That email address is already in use.');

        $existing=null;$existingRoles=[];
        if($id>0){
            $stmt=$pdo->prepare('SELECT id,role,is_active FROM users WHERE id=? LIMIT 1');$stmt->execute([$id]);$existing=$stmt->fetch();if(!$existing)throw new RuntimeException('User account not found.');
            $existingRoles=user_account_types_for_user_id($id,(string)$existing['role']);
            if(in_array('admin',$existingRoles,true)&&(int)$existing['is_active']===1&&!$internalAdmin&&active_admin_user_count($pdo,$id)<1)throw new RuntimeException('Create another active Admin before removing Admin access from the last Admin.');
            if(in_array('artist',$existingRoles,true)&&!$workspaceArtist){
                $teamCount=table_exists('artist_team_members')?(int)$pdo->query('SELECT COUNT(*) FROM artist_team_members WHERE artist_user_id='.(int)$id)->fetchColumn():0;
                $workspaceCount=table_exists('artist_workspaces_v181')?(int)$pdo->query('SELECT COUNT(*) FROM artist_workspaces_v181 WHERE artist_user_id='.(int)$id)->fetchColumn():0;
                if($teamCount>0||$workspaceCount>0)throw new RuntimeException('This user owns an Artist workspace. Move/archive the workspace and Team relationships before removing Artist identity.');
            }
        }
        if(!empty($_POST['remove_avatar'])){delete_local_upload($avatarPath);$avatarPath='';}
        global $config;
        $upload=upload_file($_FILES['avatar_file']??[],['jpg','jpeg','png','webp'],['image/jpeg','image/png','image/webp'],(int)($config['uploads']['max_image_bytes']??5242880),'avatars');
        if($upload){delete_local_upload($avatarPath);$avatarPath=$upload;}

        if($id>0){
            if($password!==''&&strlen($password)<12)throw new RuntimeException('New passwords must contain at least 12 characters.');
            // Packages never create identity. Manager/Producer are contextual Team
            // relationships and are deliberately stripped from global account roles.
            $roles=array_values(array_unique(array_filter($existingRoles,static fn(string $role): bool=>!in_array($role,['admin','artist','manager','producer'],true))));
            if($workspaceArtist)$roles[]='artist';
            if($internalAdmin)$roles[]='admin';
            if(!$roles)$roles=['fan'];
            $roles=array_values(array_unique($roles));
            $primary=$internalAdmin?'admin':($workspaceArtist?'artist':((string)($roles[0]??'fan')));
            $pdo->beginTransaction();
            try{
                if($password!==''){$stmt=$pdo->prepare('UPDATE users SET display_name=?,email=?,avatar_path=?,is_active=?,password_hash=? WHERE id=?');$stmt->execute([$displayName,$email,$avatarPath,$isActive,password_hash($password,PASSWORD_DEFAULT),$id]);}
                else{$stmt=$pdo->prepare('UPDATE users SET display_name=?,email=?,avatar_path=?,is_active=? WHERE id=?');$stmt->execute([$displayName,$email,$avatarPath,$isActive,$id]);}
                sync_user_account_types($pdo,$id,$roles,$primary);$pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            if($id===(int)$current['id'])reset_current_user_cache();
            flash('notice','User identity updated. Package and Team memberships were not changed.');redirect(url('/admin/users.php?edit='.$id.'#user-form'));
        }

        if(strlen($password)<12)throw new RuntimeException('New accounts require a password with at least 12 characters.');
        $packageId=(int)($_POST['new_package_id']??0);if($packageId<1)throw new RuntimeException('Select a package for the new user.');
        $primary=$internalAdmin?'admin':($workspaceArtist?'artist':'fan');
        $roles=$workspaceArtist?['fan','artist']:['fan'];if($internalAdmin)$roles[]='admin';$roles=array_values(array_unique($roles));
        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare('INSERT INTO users (email,password_hash,display_name,role,avatar_path,is_active) VALUES (?,?,?,?,?,?)');$stmt->execute([$email,password_hash($password,PASSWORD_DEFAULT),$displayName,$primary,$avatarPath,$isActive]);
            $newId=(int)$pdo->lastInsertId();sync_user_account_types($pdo,$newId,$roles,$primary);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        subscription_assign_package($newId,$packageId,'admin_assigned',(int)$current['id'],null,null,false,'Account created by Admin');
        flash('notice','User created with separate identity and package settings.');redirect(url('/admin/users.php?edit='.$newId));
    }catch(Throwable $e){flash('error',$e->getMessage());redirect(url('/admin/users.php'.($id>0?'?edit='.$id:'')));}
}

if($editId>0){
    $stmt=$pdo->prepare('SELECT id,email,display_name,role,avatar_path,is_active,last_login_at,created_at FROM users WHERE id=? LIMIT 1');$stmt->execute([$editId]);$editing=$stmt->fetch()?:null;
}
$users=$pdo->query('SELECT id,email,display_name,role,avatar_path,is_active,last_login_at,created_at FROM users ORDER BY display_name ASC,id ASC')->fetchAll()?:[];
foreach($users as &$row){
    $sub=subscription_current_for_user_id((int)$row['id'],$pdo);$row['subscription']=$sub;
    $row['internal_admin']=admin_user_internal_admin($pdo,(int)$row['id'],(string)$row['role']);
    $row['workspace_artist']=admin_user_workspace_artist($pdo,(int)$row['id'],(string)$row['role']);
    $row['ai_balance']=subscription_ai_balance($row,$pdo);
}unset($row);

$userMetrics=['total'=>count($users),'active'=>0,'packaged'=>0,'admins'=>0,'artists'=>0,'recent'=>0,'finite_ai'=>0,'unlimited_ai'=>0];
$recentCutoff=strtotime('-30 days');
foreach($users as $row){
    if((int)$row['is_active']===1)$userMetrics['active']++;
    if(!empty($row['subscription']))$userMetrics['packaged']++;
    if(!empty($row['internal_admin']))$userMetrics['admins']++;
    if(!empty($row['workspace_artist']))$userMetrics['artists']++;
    if(!empty($row['last_login_at'])&&strtotime((string)$row['last_login_at'])>=$recentCutoff)$userMetrics['recent']++;
    if(!empty($row['ai_balance']['unlimited']))$userMetrics['unlimited_ai']++;
    else $userMetrics['finite_ai']+=max(0,(int)($row['ai_balance']['remaining']??0));
}

$editingSubscription=$editing?subscription_current_for_user_id((int)$editing['id'],$pdo):null;
$editingBalance=$editing?subscription_ai_balance($editing,$pdo):null;
$editingCredits=$editing?subscription_recent_credits((int)$editing['id'],30):[];
$editingUsage=$editing?subscription_usage_by_scope((int)$editing['id'],30):[];
$editingAudit=[];
if($editing){$stmt=$pdo->prepare('SELECT a.*,op.name old_package_name,np.name new_package_name,actor.display_name actor_name FROM subscription_audit_log a LEFT JOIN subscription_packages op ON op.id=a.old_package_id LEFT JOIN subscription_packages np ON np.id=a.new_package_id LEFT JOIN users actor ON actor.id=a.actor_user_id WHERE a.target_user_id=? ORDER BY a.id DESC LIMIT 30');$stmt->execute([(int)$editing['id']]);$editingAudit=$stmt->fetchAll()?:[];}

$adminTitle='Users';$adminActive='users';require __DIR__.'/_header.php';
?>
<style>
.users-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin:0 0 18px}.users-kpi{padding:15px;border:1px solid var(--admin-line);border-radius:10px;background:#fff}.users-kpi small{display:block;color:#7c8590;font-size:.59rem;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.users-kpi strong{display:block;margin-top:6px;color:#111318;font-size:1.45rem;line-height:1;font-weight:850;letter-spacing:-.04em}.users-kpi span{display:block;margin-top:7px;color:#7a838d;font-size:.61rem;line-height:1.4}.users-toolbar{display:flex;align-items:center;gap:10px;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}.users-search{position:relative;flex:1 1 280px;max-width:430px}.users-search input{width:100%;padding-left:34px}.users-search:before{content:'⌕';position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#87909a;font-size:16px;pointer-events:none}.users-filters{display:flex;gap:6px;flex-wrap:wrap}.users-filter{border:1px solid var(--admin-line);border-radius:999px;background:#fff;padding:6px 9px;color:#68717b;font-size:.61rem;font-weight:800;cursor:pointer}.users-filter.is-active{background:#111318;border-color:#111318;color:#fff}.users-count{margin:0 0 8px;color:#7a838d;font-size:.63rem}.users-table-card{padding:0;overflow:hidden}.users-table-card .admin-card-head{padding:16px 16px 0}.users-table-card .admin-table-wrap{border:0;border-radius:0}.users-state{display:inline-flex;align-items:center;gap:6px;font-size:.64rem;font-weight:800}.users-state:before{content:'';width:7px;height:7px;border-radius:50%;background:#a4abb3}.users-state.active:before{background:#28a267}.users-access{display:flex;gap:5px;flex-wrap:wrap}.users-pill{display:inline-flex;align-items:center;border:1px solid #dfe3e7;border-radius:999px;background:#f8f9fa;padding:3px 7px;color:#555f69;font-size:.58rem;font-weight:800}.users-pill.strong{background:#eef1f3;color:#20252b}.users-user-meta{display:flex;align-items:center;gap:10px}.users-user-meta small{display:block;margin-top:2px}.users-login{white-space:nowrap}.users-workspace{margin-top:18px}.users-selected{display:flex;align-items:center;gap:12px}.users-selected-copy h3{margin:0}.users-selected-copy p{margin:4px 0 0}.users-form-card{margin-top:12px}.users-form-card .grid-form{margin-top:4px}.users-subscription-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0 0}.users-sub-stat{padding:13px;border:1px solid var(--admin-line);border-radius:9px;background:#fafbfb}.users-sub-stat span{display:block;color:#7c8590;font-size:.58rem;font-weight:850;text-transform:uppercase;letter-spacing:.05em}.users-sub-stat strong{display:block;margin-top:5px;font-size:1.15rem;letter-spacing:-.03em}.users-action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}.users-action-card{padding:16px;border:1px solid var(--admin-line);border-radius:10px;background:#fff}.users-action-card h3{margin-top:0}.users-section{margin-top:16px}.users-section .admin-card-head p{max-width:760px}.users-danger-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid var(--admin-line)}.users-empty{padding:22px;text-align:center;color:#7a838d}.users-ai-note{font-size:.61rem;color:#7a838d}.users-hidden{display:none!important}
@media(max-width:1280px){.users-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:900px){.users-action-grid{grid-template-columns:1fr}.users-subscription-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.users-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.users-toolbar{align-items:stretch}.users-search{max-width:none}.users-filters{width:100%}}@media(max-width:460px){.users-kpis,.users-subscription-grid{grid-template-columns:1fr}}
</style>

<div class="content-library-heading">
  <div>
    <span class="status">Accounts &amp; access</span>
    <h2>Users</h2>
    <p class="muted">Operate VP3 accounts without mixing identity, package entitlement, Team authority or AI capacity.</p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(url('/admin/packages.php')) ?>">Packages</a>
    <a class="btn" href="<?= e(url('/admin/billing.php')) ?>">Billing</a>
    <a class="btn primary" href="<?= e(url('/admin/users.php?new=1#user-form')) ?>">+ Add User</a>
  </div>
</div>

<div class="users-kpis">
  <section class="users-kpi"><small>Total accounts</small><strong><?= number_format((int)$userMetrics['total']) ?></strong><span>All VP3 user identities.</span></section>
  <section class="users-kpi"><small>Active</small><strong><?= number_format((int)$userMetrics['active']) ?></strong><span><?= number_format(max(0,(int)$userMetrics['total']-(int)$userMetrics['active'])) ?> disabled account<?= max(0,(int)$userMetrics['total']-(int)$userMetrics['active'])===1?'':'s' ?>.</span></section>
  <section class="users-kpi"><small>Package coverage</small><strong><?= number_format((int)$userMetrics['packaged']) ?></strong><span><?= number_format(max(0,(int)$userMetrics['total']-(int)$userMetrics['packaged'])) ?> without a current package.</span></section>
  <section class="users-kpi"><small>Internal authority</small><strong><?= number_format((int)$userMetrics['admins']) ?></strong><span><?= number_format((int)$userMetrics['artists']) ?> Artist workspace owner<?= (int)$userMetrics['artists']===1?'':'s' ?>.</span></section>
  <section class="users-kpi"><small>Logged in · 30d</small><strong><?= number_format((int)$userMetrics['recent']) ?></strong><span>Accounts active in the last 30 days.</span></section>
  <section class="users-kpi"><small>AI balance</small><strong><?= number_format((int)$userMetrics['finite_ai']) ?></strong><span><?= number_format((int)$userMetrics['unlimited_ai']) ?> unlimited account<?= (int)$userMetrics['unlimited_ai']===1?'':'s' ?> excluded.</span></section>
</div>

<section class="admin-card users-table-card">
  <div class="admin-card-head">
    <div><h3>Account directory</h3><p>Search and segment users by account state, authority and package assignment.</p></div>
    <span class="muted" id="users-visible-count"><?= number_format(count($users)) ?> shown</span>
  </div>
  <div style="padding:14px 16px 4px">
    <div class="users-toolbar">
      <label class="users-search"><span class="sr-only">Search users</span><input id="users-search" type="search" placeholder="Search name, email or package" autocomplete="off"></label>
      <div class="users-filters" role="group" aria-label="Filter users">
        <button class="users-filter is-active" type="button" data-filter="all">All</button>
        <button class="users-filter" type="button" data-filter="active">Active</button>
        <button class="users-filter" type="button" data-filter="disabled">Disabled</button>
        <button class="users-filter" type="button" data-filter="admin">Admin</button>
        <button class="users-filter" type="button" data-filter="artist">Artist</button>
        <button class="users-filter" type="button" data-filter="no-package">No package</button>
      </div>
    </div>
    <p class="users-count">Identity and subscription are deliberately separate: packages never grant Admin or Artist authority.</p>
  </div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Access</th><th>Package</th><th>AI remaining</th><th>Last login</th><th>State</th><th></th></tr></thead><tbody id="users-table-body">
  <?php foreach($users as $row): $sub=$row['subscription'];$balance=$row['ai_balance'];$filters=[(int)$row['is_active']===1?'active':'disabled'];if($row['internal_admin'])$filters[]='admin';if($row['workspace_artist'])$filters[]='artist';if(!$sub)$filters[]='no-package';$searchText=strtolower(trim((string)$row['display_name'].' '.(string)$row['email'].' '.(string)($sub['package_name']??''))); ?>
    <tr data-user-row data-filters="<?= e(implode(' ',$filters)) ?>" data-search="<?= e($searchText) ?>">
      <td><div class="users-user-meta"><span class="admin-user-avatar admin-user-avatar-sm"><?php if(!empty($row['avatar_path'])):?><img src="<?= e(user_avatar_url($row)) ?>" alt=""><?php else:?><span><?= e(user_initials($row)) ?></span><?php endif;?></span><div><strong><?= e((string)$row['display_name']) ?></strong><small class="muted"><?= e((string)$row['email']) ?></small></div></div></td>
      <td><div class="users-access"><?php if($row['internal_admin']):?><span class="users-pill strong">Admin</span><?php endif;?><?php if($row['workspace_artist']):?><span class="users-pill strong">Artist</span><?php endif;?><?php if(!$row['internal_admin']&&!$row['workspace_artist']):?><span class="users-pill">Member</span><?php endif;?></div></td>
      <td><strong><?= e((string)($sub['package_name']??'No package')) ?></strong><br><small class="muted"><?= e((string)($sub['status']??'unassigned')) ?></small></td>
      <td><strong><?= !empty($balance['unlimited'])?'Unlimited':number_format((int)($balance['remaining']??0)) ?></strong><?php if(empty($balance['unlimited'])):?><div class="users-ai-note"><?= number_format((int)($balance['credits_remaining']??0)) ?> top-up</div><?php endif;?></td>
      <td class="users-login"><?= $row['last_login_at']?e(date('M j, Y',strtotime((string)$row['last_login_at']))):'<span class="muted">Never</span>' ?></td>
      <td><span class="users-state <?= (int)$row['is_active']===1?'active':'' ?>"><?= (int)$row['is_active']===1?'Active':'Disabled' ?></span></td>
      <td class="actions"><a class="btn" href="<?= e(url('/admin/users.php?edit='.(int)$row['id'].'#user-form')) ?>">Manage</a><?php if((int)$row['id']!==(int)$current['id']):?><form class="inline-form" method="post" onsubmit="return confirm('Delete this user account?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn danger" type="submit">Delete</button></form><?php endif;?></td>
    </tr>
  <?php endforeach;?>
  <?php if(!$users):?><tr><td colspan="7" class="users-empty">No user accounts have been added yet.</td></tr><?php endif;?>
  </tbody></table></div>
</section>

<?php if($showForm): ?>
<section class="admin-card users-workspace" id="user-form">
  <div class="admin-card-head">
    <div><?php if($editing):?><div class="users-selected"><span class="admin-user-avatar"><?php if(!empty($editing['avatar_path'])):?><img src="<?= e(user_avatar_url($editing)) ?>" alt=""><?php else:?><span><?= e(user_initials($editing)) ?></span><?php endif;?></span><div class="users-selected-copy"><span class="status">Account workspace</span><h3><?= e((string)$editing['display_name']) ?></h3><p class="muted"><?= e((string)$editing['email']) ?> · User #<?= (int)$editing['id'] ?></p></div></div><?php else:?><span class="status">New account</span><h3>Add User</h3><p>Create identity and initial package assignment as separate account properties.</p><?php endif;?></div>
    <a class="btn" href="<?= e(url('/admin/users.php')) ?>">Close</a>
  </div>
  <div class="users-form-card">
    <form class="grid-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><input type="hidden" name="existing_avatar_path" value="<?= e((string)($editing['avatar_path']??'')) ?>">
      <div class="field"><label>Display Name</label><input name="display_name" maxlength="120" required value="<?= e((string)($editing['display_name']??'')) ?>"></div>
      <div class="field"><label>Email</label><input name="email" type="email" maxlength="190" required value="<?= e((string)($editing['email']??'')) ?>"></div>
      <?php if(!$editing):?><div class="field"><label>Package</label><select name="new_package_id" required><option value="">Select package</option><?php foreach($packages as $packageRow):if(!(int)$packageRow['is_active'])continue;?><option value="<?= (int)$packageRow['id'] ?>" <?= (int)$packageRow['is_default']===1?'selected':'' ?>><?= e((string)$packageRow['name']) ?></option><?php endforeach;?></select><small>Package controls commercial feature access and capacity. It does not grant identity or workspace authority.</small></div><?php endif;?>
      <div class="field"><label><?= $editing?'New Password (optional)':'Password' ?></label><input name="password" type="password" minlength="12" <?= $editing?'':'required' ?> autocomplete="new-password"><small><?= $editing?'Leave blank to keep the current password.':'Minimum 12 characters.' ?></small></div>
      <div class="field full"><label>User Photo</label><div class="admin-avatar-editor"><span class="admin-user-avatar"><?php if(!empty($editing['avatar_path'])):?><img src="<?= e(user_avatar_url($editing)) ?>" alt=""><?php else:?><span><?= e($editing?user_initials($editing):'+') ?></span><?php endif;?></span><div><input name="avatar_file" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><small>JPG, PNG or WEBP, up to 5 MB.</small><?php if(!empty($editing['avatar_path'])):?><label class="admin-inline-check" style="margin-top:8px"><input type="checkbox" name="remove_avatar" value="1"> Remove current photo</label><?php endif;?></div></div></div>
      <div class="field full"><label>Internal identity</label><label class="admin-inline-check"><input name="workspace_artist" type="checkbox" <?= $editing&&admin_user_workspace_artist($pdo,(int)$editing['id'],(string)$editing['role'])?'checked':'' ?>> Artist workspace owner</label><small>Artist is identity/authorization. It does not select a paid package.</small></div>
      <div class="field full"><label class="admin-inline-check"><input name="internal_admin" type="checkbox" <?= $editing&&admin_user_internal_admin($pdo,(int)$editing['id'],(string)$editing['role'])?'checked':'' ?>> Internal Admin authority</label><small>Admin is internal system authority. Manager and Producer remain contextual Artist Team relationships.</small></div>
      <div class="field full"><label class="admin-inline-check"><input name="is_active" type="checkbox" <?= !isset($editing['is_active'])||(int)$editing['is_active']===1?'checked':'' ?>> Active account</label></div>
      <div class="field full actions"><button class="btn primary" type="submit"><?= $editing?'Save User':'Add User' ?></button><a class="btn" href="<?= e(url('/admin/users.php')) ?>">Cancel</a></div>
    </form>
  </div>
</section>
<?php endif;?>

<?php if($editing): ?>
<section class="admin-card users-section" id="subscription-panel">
  <div class="admin-card-head"><div><span class="status">Subscription &amp; AI</span><h3><?= e((string)($editingSubscription['package_name']??'No Package')) ?></h3><p>Assign packages, inspect effective AI capacity, and grant token top-ups without changing identity or Team relationships.</p></div><a class="btn" href="<?= e(url('/admin/packages.php')) ?>">Manage Packages</a></div>
  <div class="users-subscription-grid">
    <div class="users-sub-stat"><span>Package allowance</span><strong><?= !empty($editingBalance['unlimited'])?'Unlimited':number_format((int)($editingBalance['package_allowance']??0)) ?></strong></div>
    <div class="users-sub-stat"><span>Top-up balance</span><strong><?= number_format((int)($editingBalance['credits_remaining']??0)) ?></strong></div>
    <div class="users-sub-stat"><span>Used this period</span><strong><?= number_format((int)($editingBalance['used']??0)) ?></strong></div>
    <div class="users-sub-stat"><span>Remaining</span><strong><?= !empty($editingBalance['unlimited'])?'Unlimited':number_format((int)($editingBalance['remaining']??0)) ?></strong></div>
  </div>
  <div class="users-action-grid">
    <section class="users-action-card"><h3>Assign / change package</h3><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_package"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><div class="field"><label>Package</label><select name="package_id" required><?php foreach($packages as $packageRow):if(!(int)$packageRow['is_active'])continue;?><option value="<?= (int)$packageRow['id'] ?>" <?= (int)($editingSubscription['package_id']??0)===(int)$packageRow['id']?'selected':'' ?>><?= e((string)$packageRow['name']) ?></option><?php endforeach;?></select></div><div class="field"><label>Source</label><select name="assignment_source"><option value="admin_assigned">Admin assigned</option><option value="complimentary">Complimentary</option><option value="trial">Trial</option><option value="partner">Partner</option><option value="enterprise">Enterprise</option></select></div><div class="field"><label>Expires at (optional)</label><input type="datetime-local" name="ends_at"></div><div class="field"><label>Custom AI allowance (optional)</label><input type="number" min="0" name="ai_token_override" placeholder="Use package default"></div><div class="field full"><label>Reason</label><input name="reason" maxlength="500" required placeholder="Why is this package being assigned?"></div><div class="field full"><label class="admin-inline-check"><input type="checkbox" name="billing_required" value="1"> Billing required</label></div><div class="field full actions"><button class="btn primary" type="submit">Assign Package</button></div></form></section>
    <section class="users-action-card"><h3>Add AI tokens</h3><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_tokens"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><div class="field"><label>Tokens</label><input type="number" min="1" name="amount" required placeholder="50000"></div><div class="field"><label>Source</label><select name="source"><option value="admin_topup">Admin top-up</option><option value="support_credit">Support credit</option><option value="promotion">Promotion</option><option value="trial_extension">Trial extension</option><option value="purchase">Purchase</option><option value="refund">Refund</option></select></div><div class="field"><label>Expires at (optional)</label><input type="datetime-local" name="expires_at"></div><div class="field full"><label>Reason</label><input name="reason" maxlength="500" required placeholder="Reason for the token credit"></div><div class="field full actions"><button class="btn primary" type="submit">Add Tokens</button></div></form></section>
  </div>
  <?php if($editingSubscription):?><div class="users-danger-row"><form method="post" class="inline-form" onsubmit="return confirm('Remove this user’s current package?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove_package"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><input name="reason" maxlength="500" required placeholder="Reason for removing package"><button class="btn danger" type="submit">Remove Current Package</button></form><span class="muted">Removing a package does not remove the user identity.</span></div><?php endif;?>
</section>

<section class="admin-card users-section">
  <div class="admin-card-head"><div><h3>Token credits</h3><p>Recent top-ups and their remaining balances.</p></div><span class="muted"><?= number_format(count($editingCredits)) ?> recent</span></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Granted</th><th>Remaining</th><th>Source</th><th>Expires</th><th>Reason</th><th></th></tr></thead><tbody><?php foreach($editingCredits as $credit):?><tr><td><?= number_format((int)$credit['amount']) ?></td><td><strong><?= number_format((int)$credit['remaining_amount']) ?></strong></td><td><?= e((string)$credit['source']) ?></td><td><?= $credit['expires_at']?e(date('M j, Y g:i A',strtotime((string)$credit['expires_at']))):'Never' ?></td><td><?= e((string)$credit['reason']) ?></td><td><?php if((int)$credit['remaining_amount']>0):?><form method="post" class="inline-form" onsubmit="return confirm('Remove this remaining token credit?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove_credit"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><input type="hidden" name="credit_id" value="<?= (int)$credit['id'] ?>"><input type="hidden" name="reason" value="Admin correction"><button class="btn danger" type="submit">Remove</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$editingCredits):?><tr><td colspan="6" class="users-empty">No token credits yet.</td></tr><?php endif;?></tbody></table></div>
</section>

<section class="admin-card users-section">
  <div class="admin-card-head"><div><h3>AI usage by feature</h3><p>30-day metered usage for the selected account.</p></div></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Feature</th><th>Requests</th><th>Input</th><th>Output</th><th>Total</th></tr></thead><tbody><?php foreach($editingUsage as $usage):?><tr><td><strong><?= e((string)$usage['scope']) ?></strong></td><td><?= number_format((int)$usage['requests']) ?></td><td><?= number_format((int)$usage['input_tokens']) ?></td><td><?= number_format((int)$usage['output_tokens']) ?></td><td><strong><?= number_format((int)$usage['total_tokens']) ?></strong></td></tr><?php endforeach;?><?php if(!$editingUsage):?><tr><td colspan="5" class="users-empty">No metered AI usage yet.</td></tr><?php endif;?></tbody></table></div>
</section>

<section class="admin-card users-section">
  <div class="admin-card-head"><div><h3>Subscription audit</h3><p>Administrative package changes retained with actor and reason.</p></div><span class="muted"><?= number_format(count($editingAudit)) ?> recent</span></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>When</th><th>Action</th><th>Package change</th><th>Admin</th><th>Reason</th></tr></thead><tbody><?php foreach($editingAudit as $audit):?><tr><td><?= e(date('M j, Y g:i A',strtotime((string)$audit['created_at']))) ?></td><td><strong><?= e((string)$audit['action']) ?></strong></td><td><?= e((string)($audit['old_package_name']??'—')) ?> → <?= e((string)($audit['new_package_name']??'—')) ?></td><td><?= e((string)($audit['actor_name']??'System')) ?></td><td><?= e((string)$audit['reason']) ?></td></tr><?php endforeach;?><?php if(!$editingAudit):?><tr><td colspan="5" class="users-empty">No subscription changes yet.</td></tr><?php endif;?></tbody></table></div>
</section>
<?php endif;?>

<script>
(()=>{
  const rows=[...document.querySelectorAll('[data-user-row]')];
  const search=document.getElementById('users-search');
  const filters=[...document.querySelectorAll('.users-filter')];
  const count=document.getElementById('users-visible-count');
  if(!rows.length||!search||!filters.length)return;
  let activeFilter='all';
  const apply=()=>{
    const query=search.value.trim().toLowerCase();
    let visible=0;
    rows.forEach(row=>{
      const filterMatch=activeFilter==='all'||(` ${row.dataset.filters||''} `).includes(` ${activeFilter} `);
      const searchMatch=!query||(row.dataset.search||'').includes(query);
      const show=filterMatch&&searchMatch;
      row.classList.toggle('users-hidden',!show);
      if(show)visible++;
    });
    if(count)count.textContent=`${visible.toLocaleString()} shown`;
  };
  search.addEventListener('input',apply);
  filters.forEach(button=>button.addEventListener('click',()=>{
    activeFilter=button.dataset.filter||'all';
    filters.forEach(item=>item.classList.toggle('is-active',item===button));
    apply();
  }));
})();
</script>
<?php require __DIR__.'/_footer.php'; ?>