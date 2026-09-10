<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/vp3-public.php';

$pdo=db();
if(!$pdo){http_response_code(503);exit('Team invitations are unavailable.');}
workspace_team_v350_ensure_schema($pdo);

$token=trim((string)($_GET['token']??$_POST['token']??$_SESSION['pending_team_invite_token']??''));
$inviteId=max(0,(int)($_GET['id']??$_POST['id']??0));
$invite=null;$error='';$notice=flash('notice');

if($token!==''&&preg_match('/^[a-f0-9]{64}$/i',$token)){
    $invite=workspace_team_v350_invitation_by_token($pdo,$token);
    if($invite&&!is_logged_in())$_SESSION['pending_team_invite_token']=strtolower($token);
}elseif($inviteId>0&&is_logged_in()){
    $invite=workspace_team_v350_invitation_for_user($pdo,$inviteId,(int)(current_user()['id']??0));
}

if(!$invite){
    $error='This Team invitation is not available to this account.';
}else{
    $inviteId=(int)$invite['id'];
    if((string)$invite['invitation_status']==='pending'&&strtotime((string)$invite['expires_at'])<=time())$error='This Team invitation has expired.';
    elseif((string)$invite['invitation_status']!=='pending')$error='This Team invitation has already been '.(string)$invite['invitation_status'].'.';
}

$user=current_user();
$identityMatch=false;
if($invite&&$user){
    $identityMatch=hash_equals(strtolower((string)$invite['invited_email']),strtolower((string)$user['email']))
      &&((int)($invite['existing_user_id']??0)<1||(int)$invite['existing_user_id']===(int)$user['id']);
    if(!$identityMatch&&$error==='')$error='This invitation was sent to a different VP3 account.';
}

if($_SERVER['REQUEST_METHOD']==='POST'&&$invite&&$user&&$identityMatch&&$error===''){
    if(!verify_csrf())$error='Your session expired. Please try again.';
    else{
        $action=(string)($_POST['action']??'accept');
        try{
            if($action==='accept'){
                workspace_team_v350_accept_invitation($pdo,$inviteId,(int)$user['id']);
                unset($_SESSION['pending_team_invite_token']);
                flash('notice','Team invitation accepted. The workspace is now available in your VP3 account.');
                redirect(url('/admin/team-workspaces.php'));
            }
            if($action==='decline'){
                workspace_team_v350_decline_invitation($pdo,$inviteId,(int)$user['id']);
                unset($_SESSION['pending_team_invite_token']);
                flash('notice','Team invitation declined.');
                redirect(url('/chat.php'));
            }
            throw new RuntimeException('Unknown invitation action.');
        }catch(Throwable $e){$error=$e->getMessage();$invite=workspace_team_v350_invitation($pdo,$inviteId)?:$invite;}
    }
}

$ownerName=$invite?trim((string)$invite['workspace_owner_name']):'';
$role=$invite?ucfirst((string)$invite['team_role']):'';
vp3_public_header('Team Invitation — VP3','Review a VP3 workspace invitation.',['compact'=>true]);
?>
<main class="vp3-auth-shell">
  <section class="vp3-auth-visual">
    <div class="vp3-auth-visual-content">
      <div class="vp3-kicker">Workspace collaboration</div>
      <h1>One VP3 identity. Contextual Team access.</h1>
      <p>Accepting an invitation adds a role only inside the named workspace. Your personal VP3 account, package, profile and other workspaces stay unchanged.</p>
    </div>
  </section>
  <section class="vp3-auth-form-side">
    <div class="vp3-auth-card">
      <div class="vp3-kicker">Team invitation</div>
      <?php if($notice):?><div class="vp3-alert success"><?= e($notice) ?></div><?php endif;?>
      <?php if($invite):?>
        <h1><?= e($ownerName!==''?$ownerName:'VP3 Workspace') ?></h1>
        <p class="vp3-auth-intro">You were invited as <strong><?= e($role) ?></strong>. This invitation was sent to <strong><?= e((string)$invite['invited_email']) ?></strong> and expires <?= e(date('M j, Y g:i A',strtotime((string)$invite['expires_at']))) ?>.</p>
      <?php else:?><h1>Invitation unavailable</h1><?php endif;?>
      <?php if($error):?><div class="vp3-alert error" role="alert"><?= e($error) ?></div><?php endif;?>

      <?php if($invite&&!$user&&$error===''):?>
        <p class="vp3-auth-intro">Sign in with the invited email address, or create a VP3 account using that same address. VP3 will return you to this invitation automatically.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <a class="vp3-btn primary" href="<?= e(url('/login.php')) ?>">Sign in →</a>
          <a class="vp3-btn" href="<?= e(url('/signup.php')) ?>">Create account</a>
        </div>
      <?php elseif($invite&&$user&&$identityMatch&&$error===''):?>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $inviteId ?>">
          <?php if($token!==''):?><input type="hidden" name="token" value="<?= e(strtolower($token)) ?>"><?php endif;?>
          <button class="vp3-btn primary" type="submit" name="action" value="accept">Accept invitation →</button>
          <button class="vp3-btn" type="submit" name="action" value="decline">Decline</button>
        </form>
      <?php elseif($user):?>
        <a class="vp3-btn" href="<?= e(url('/chat.php')) ?>">Return to VP3</a>
      <?php endif;?>
    </div>
  </section>
</main>
<?php vp3_public_footer(); ?>
