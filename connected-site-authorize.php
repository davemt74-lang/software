<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/vp3-public.php';
$pdo=db();$user=current_user();$client=(string)($_GET['client_id']??$_POST['client_id']??'');$app=vp3_connected_site_app_v100($client);
$redirect=(string)($_GET['redirect_uri']??$_POST['redirect_uri']??'');$state=(string)($_GET['state']??$_POST['state']??'');$scope=(string)($_GET['scope']??$_POST['scope']??'');$mode=(string)($_GET['mode']??$_POST['mode']??'connect');if(strlen($state)>256){http_response_code(400);exit('Authorization state is too long.');}
if(!$app||strlen((string)($app['client_secret']??''))<32||trim((string)($app['redirect_uri']??''))===''){http_response_code(503);exit('This connected application is not configured.');}
try{$redirect=vp3_connected_site_validate_redirect_v100($app,$redirect);$scopes=vp3_connected_site_scopes_v100($app,$scope);}catch(Throwable $e){http_response_code(400);exit(e($e->getMessage()));}
if(!$user){$_SESSION['vp3_connected_site_after_login']=$_SERVER['REQUEST_URI']??'/connected-site-authorize.php';redirect(url('/login.php?return_to='.rawurlencode((string)$_SESSION['vp3_connected_site_after_login'])));}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!verify_csrf())$error='Session expired. Please try again.';
 else{
  $decision=(string)($_POST['decision']??'');
  if($decision==='deny'){header('Location: '.$redirect.'?error=access_denied&state='.rawurlencode($state));exit;}
  if($decision==='approve')try{$grant=vp3_connected_site_authorize_v100($pdo,$user,$app,$scopes,$redirect);$sep=str_contains($redirect,'?')?'&':'?';header('Location: '.$redirect.$sep.'code='.rawurlencode($grant['code']).'&state='.rawurlencode($state));exit;}catch(Throwable $e){$error=$e->getMessage();}
 }
}
vp3_public_header('Connect '.$app['label'].' — VP3','Authorize a connected site to access selected VP3 account data.',['compact'=>true]);
?><main class="vp3-auth-shell"><section class="vp3-auth-visual"><div class="vp3-auth-visual-content"><div class="vp3-kicker">Connected site</div><h1>Connect <?=e((string)$app['label'])?> to VP3.</h1><p><?=e((string)$app['label'])?> will only receive the permissions listed here. You can revoke the connection later from My Account → Connected Sites.</p></div></section><section class="vp3-auth-form-side"><div class="vp3-auth-card"><div class="vp3-kicker"><?=e($mode==='login'?'Sign in with VP3':'Connect account')?></div><h1>Authorize <?=e((string)$app['label'])?></h1><?php if($error):?><div class="vp3-alert error"><?=e($error)?></div><?php endif?><p class="vp3-auth-intro">Signed in as <strong><?=e((string)$user['display_name'])?></strong>.</p><h2 style="font-size:1rem">Requested access</h2><ul><?php foreach($scopes as $s):?><li><?=e((string)$app['scopes'][$s])?></li><?php endforeach?></ul><p style="font-size:.86rem;color:#666">Disconnecting stops future access. It does not delete copies you explicitly imported into Annotated.</p><form method="post" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.3rem"><?=csrf_field()?><input type="hidden" name="client_id" value="<?=e($client)?>"><input type="hidden" name="redirect_uri" value="<?=e($redirect)?>"><input type="hidden" name="state" value="<?=e($state)?>"><input type="hidden" name="scope" value="<?=e(implode(' ',$scopes))?>"><input type="hidden" name="mode" value="<?=e($mode)?>"><button class="vp3-btn primary" name="decision" value="approve">Authorize <?=e((string)$app['label'])?> →</button><button class="vp3-btn" name="decision" value="deny">Cancel</button></form></div></section></main><?php vp3_public_footer();?>
