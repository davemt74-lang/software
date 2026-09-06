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

$editingSubscription=$editing?subscription_current_for_user_id((int)$editing['id'],$pdo):null;
$editingBalance=$editing?subscription_ai_balance($editing,$pdo):null;
$editingCredits=$editing?subscription_recent_credits((int)$editing['id'],30):[];
$editingUsage=$editing?subscription_usage_by_scope((int)$editing['id'],30):[];
$editingAudit=[];
if($editing){$stmt=$pdo->prepare('SELECT a.*,op.name old_package_name,np.name new_package_name,actor.display_name actor_name FROM subscription_audit_log a LEFT JOIN subscription_packages op ON op.id=a.old_package_id LEFT JOIN subscription_packages np ON np.id=a.new_package_id LEFT JOIN users actor ON actor.id=a.actor_user_id WHERE a.target_user_id=? ORDER BY a.id DESC LIMIT 30');$stmt->execute([(int)$editing['id']]);$editingAudit=$stmt->fetchAll()?:[];}

$adminTitle='Users';$adminActive='users';require __DIR__.'/_header.php';
?>
<div class="panel">
  <div class="content-library-heading"><div><span class="status">Account Management</span><h2>Users</h2><p class="muted">Identity, package and Team relationships are separate. Artist/Admin are internal authority; Manager/Producer are assigned only inside Artist Team workspaces.</p></div><div class="actions"><a class="btn" href="<?= e(url('/admin/packages.php')) ?>">Packages</a><a class="btn primary" href="<?= e(url('/admin/users.php?new=1#user-form')) ?>">+ Add User</a></div></div>
  <div class="table-wrap"><table><thead><tr><th>User</th><th>Identity</th><th>Package</th><th>AI Remaining</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($users as $row): $sub=$row['subscription'];$balance=$row['ai_balance']; ?>
    <tr><td><div class="admin-user-cell"><span class="admin-user-avatar admin-user-avatar-sm"><?php if(!empty($row['avatar_path'])):?><img src="<?= e(user_avatar_url($row)) ?>" alt=""><?php else:?><span><?= e(user_initials($row)) ?></span><?php endif;?></span><div><strong><?= e((string)$row['display_name']) ?></strong><br><span class="muted"><?= e((string)$row['email']) ?></span></div></div></td>
    <td><?= $row['internal_admin']?'<span class="status">Admin</span>':'' ?> <?= $row['workspace_artist']?'<span class="status">Artist</span>':'' ?><?= !$row['internal_admin']&&!$row['workspace_artist']?'<span class="muted">Member</span>':'' ?></td>
    <td><strong><?= e((string)($sub['package_name']??'No package')) ?></strong><br><span class="muted"><?= e((string)($sub['status']??'unassigned')) ?></span></td>
    <td><?= !empty($balance['unlimited'])?'Unlimited':number_format((int)($balance['remaining']??0)) ?></td><td><span class="status"><?= (int)$row['is_active']===1?'Active':'Disabled' ?></span></td><td><?= $row['last_login_at']?e(date('M j, Y g:i A',strtotime((string)$row['last_login_at']))):'Never' ?></td>
    <td class="actions"><a class="btn" href="<?= e(url('/admin/users.php?edit='.(int)$row['id'].'#user-form')) ?>">Manage</a><?php if((int)$row['id']!==(int)$current['id']):?><form class="inline-form" method="post" onsubmit="return confirm('Delete this user account?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn danger" type="submit">Delete</button></form><?php endif;?></td></tr>
  <?php endforeach;?>
  <?php if(!$users):?><tr><td colspan="7" class="muted">No user accounts have been added yet.</td></tr><?php endif;?>
  </tbody></table></div>
</div>

<?php if($showForm): ?>
<div class="panel" id="user-form"><div class="content-form-heading"><div><span class="status"><?= $editing?'Edit User':'New User' ?></span><h2><?= $editing?'Edit User':'Add User' ?></h2></div><a class="btn" href="<?= e(url('/admin/users.php')) ?>">Close</a></div>
<form class="grid-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><input type="hidden" name="existing_avatar_path" value="<?= e((string)($editing['avatar_path']??'')) ?>">
<div class="field"><label>Display Name</label><input name="display_name" maxlength="120" required value="<?= e((string)($editing['display_name']??'')) ?>"></div>
<div class="field"><label>Email</label><input name="email" type="email" maxlength="190" required value="<?= e((string)($editing['email']??'')) ?>"></div>
<?php if(!$editing):?><div class="field"><label>Package</label><select name="new_package_id" required><option value="">Select package</option><?php foreach($packages as $packageRow):if(!(int)$packageRow['is_active'])continue;?><option value="<?= (int)$packageRow['id'] ?>" <?= (int)$packageRow['is_default']===1?'selected':'' ?>><?= e((string)$packageRow['name']) ?></option><?php endforeach;?></select><small>Package controls commercial feature access and capacity. It does not grant identity or workspace authority.</small></div><?php endif;?>
<div class="field"><label><?= $editing?'New Password (optional)':'Password' ?></label><input name="password" type="password" minlength="12" <?= $editing?'':'required' ?> autocomplete="new-password"><small><?= $editing?'Leave blank to keep the current password.':'Minimum 12 characters.' ?></small></div>
<div class="field full"><label>User Photo</label><div class="admin-avatar-editor"><span class="admin-user-avatar"><?php if(!empty($editing['avatar_path'])):?><img src="<?= e(user_avatar_url($editing)) ?>" alt=""><?php else:?><span><?= e($editing?user_initials($editing):'+') ?></span><?php endif;?></span><div><input name="avatar_file" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><small>JPG, PNG or WEBP, up to 5 MB.</small><?php if(!empty($editing['avatar_path'])):?><label class="admin-inline-check" style="margin-top:8px"><input type="checkbox" name="remove_avatar" value="1"> Remove current photo</label><?php endif;?></div></div></div>
<div class="field full"><label>Internal Identity</label><label class="admin-inline-check"><input name="workspace_artist" type="checkbox" <?= $editing&&admin_user_workspace_artist($pdo,(int)$editing['id'],(string)$editing['role'])?'checked':'' ?>> Artist workspace owner</label><small>Artist is an identity/authorization role. It enables ownership of an Artist workspace but does not select a paid package.</small></div>
<div class="field full"><label class="admin-inline-check"><input name="internal_admin" type="checkbox" <?= $editing&&admin_user_internal_admin($pdo,(int)$editing['id'],(string)$editing['role'])?'checked':'' ?>> Internal Admin authority</label><small>Admin is internal system authority, not a subscription package. Manager/Producer never appear here; assign them through Artist → Team.</small></div>
<div class="field full"><label class="admin-inline-check"><input name="is_active" type="checkbox" <?= !isset($editing['is_active'])||(int)$editing['is_active']===1?'checked':'' ?>> Active account</label></div>
<div class="field full actions"><button class="btn primary" type="submit"><?= $editing?'Save User':'Add User' ?></button><a class="btn" href="<?= e(url('/admin/users.php')) ?>">Cancel</a></div></form></div>
<?php endif;?>

<?php if($editing): ?>
<div class="panel" id="subscription-panel"><div class="content-form-heading"><div><span class="status">Subscription</span><h2><?= e((string)($editingSubscription['package_name']??'No Package')) ?></h2><p class="muted">Assign packages, inspect effective AI capacity, and grant token top-ups without changing identity or Team relationships.</p></div><a class="btn" href="<?= e(url('/admin/packages.php')) ?>">Manage Packages</a></div>
<div class="stats-grid">
<div class="stat"><span>Package allowance</span><strong><?= !empty($editingBalance['unlimited'])?'Unlimited':number_format((int)($editingBalance['package_allowance']??0)) ?></strong></div>
<div class="stat"><span>Top-up balance</span><strong><?= number_format((int)($editingBalance['credits_remaining']??0)) ?></strong></div>
<div class="stat"><span>Used this period</span><strong><?= number_format((int)($editingBalance['used']??0)) ?></strong></div>
<div class="stat"><span>Remaining</span><strong><?= !empty($editingBalance['unlimited'])?'Unlimited':number_format((int)($editingBalance['remaining']??0)) ?></strong></div>
</div>
<div class="admin-grid admin-grid-2" style="margin-top:18px">
<section><h3>Assign / Change Package</h3><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_package"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><div class="field"><label>Package</label><select name="package_id" required><?php foreach($packages as $packageRow):if(!(int)$packageRow['is_active'])continue;?><option value="<?= (int)$packageRow['id'] ?>" <?= (int)($editingSubscription['package_id']??0)===(int)$packageRow['id']?'selected':'' ?>><?= e((string)$packageRow['name']) ?></option><?php endforeach;?></select></div><div class="field"><label>Source</label><select name="assignment_source"><option value="admin_assigned">Admin assigned</option><option value="complimentary">Complimentary</option><option value="trial">Trial</option><option value="partner">Partner</option><option value="enterprise">Enterprise</option></select></div><div class="field"><label>Expires at (optional)</label><input type="datetime-local" name="ends_at"></div><div class="field"><label>Custom AI allowance (optional)</label><input type="number" min="0" name="ai_token_override" placeholder="Use package default"></div><div class="field full"><label>Reason</label><input name="reason" maxlength="500" required placeholder="Why is this package being assigned?"></div><div class="field full"><label class="admin-inline-check"><input type="checkbox" name="billing_required" value="1"> Billing required</label></div><div class="field full actions"><button class="btn primary" type="submit">Assign Package</button></div></form></section>
<section><h3>Add AI Tokens</h3><form class="grid-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_tokens"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><div class="field"><label>Tokens</label><input type="number" min="1" name="amount" required placeholder="50000"></div><div class="field"><label>Source</label><select name="source"><option value="admin_topup">Admin top-up</option><option value="support_credit">Support credit</option><option value="promotion">Promotion</option><option value="trial_extension">Trial extension</option><option value="purchase">Purchase</option><option value="refund">Refund</option></select></div><div class="field"><label>Expires at (optional)</label><input type="datetime-local" name="expires_at"></div><div class="field full"><label>Reason</label><input name="reason" maxlength="500" required placeholder="Reason for the token credit"></div><div class="field full actions"><button class="btn primary" type="submit">Add Tokens</button></div></form></section>
</div>
<?php if($editingSubscription):?><form method="post" style="margin-top:14px" onsubmit="return confirm('Remove this user’s current package?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove_package"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><input name="reason" maxlength="500" required placeholder="Reason for removing package"><button class="btn danger" type="submit">Remove Current Package</button></form><?php endif;?>
</div>

<div class="panel"><h2>Token Credits</h2><div class="table-wrap"><table><thead><tr><th>Granted</th><th>Remaining</th><th>Source</th><th>Expires</th><th>Reason</th><th></th></tr></thead><tbody><?php foreach($editingCredits as $credit):?><tr><td><?= number_format((int)$credit['amount']) ?></td><td><?= number_format((int)$credit['remaining_amount']) ?></td><td><?= e((string)$credit['source']) ?></td><td><?= $credit['expires_at']?e(date('M j, Y g:i A',strtotime((string)$credit['expires_at']))):'Never' ?></td><td><?= e((string)$credit['reason']) ?></td><td><?php if((int)$credit['remaining_amount']>0):?><form method="post" class="inline-form" onsubmit="return confirm('Remove this remaining token credit?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove_credit"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><input type="hidden" name="credit_id" value="<?= (int)$credit['id'] ?>"><input type="hidden" name="reason" value="Admin correction"><button class="btn danger" type="submit">Remove</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$editingCredits):?><tr><td colspan="6" class="muted">No token credits yet.</td></tr><?php endif;?></tbody></table></div></div>
<div class="panel"><h2>AI Usage by Feature</h2><div class="table-wrap"><table><thead><tr><th>Feature</th><th>Requests</th><th>Input</th><th>Output</th><th>Total</th></tr></thead><tbody><?php foreach($editingUsage as $usage):?><tr><td><?= e((string)$usage['scope']) ?></td><td><?= number_format((int)$usage['requests']) ?></td><td><?= number_format((int)$usage['input_tokens']) ?></td><td><?= number_format((int)$usage['output_tokens']) ?></td><td><?= number_format((int)$usage['total_tokens']) ?></td></tr><?php endforeach;?><?php if(!$editingUsage):?><tr><td colspan="5" class="muted">No metered AI usage yet.</td></tr><?php endif;?></tbody></table></div></div>
<div class="panel"><h2>Subscription Audit</h2><div class="table-wrap"><table><thead><tr><th>When</th><th>Action</th><th>Package change</th><th>Admin</th><th>Reason</th></tr></thead><tbody><?php foreach($editingAudit as $audit):?><tr><td><?= e(date('M j, Y g:i A',strtotime((string)$audit['created_at']))) ?></td><td><?= e((string)$audit['action']) ?></td><td><?= e((string)($audit['old_package_name']??'—')) ?> → <?= e((string)($audit['new_package_name']??'—')) ?></td><td><?= e((string)($audit['actor_name']??'System')) ?></td><td><?= e((string)$audit['reason']) ?></td></tr><?php endforeach;?><?php if(!$editingAudit):?><tr><td colspan="5" class="muted">No subscription changes yet.</td></tr><?php endif;?></tbody></table></div></div>
<?php endif;?>
<?php require __DIR__.'/_footer.php'; ?>