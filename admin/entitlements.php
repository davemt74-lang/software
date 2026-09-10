<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
require_permission('users.manage');

$pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
if(!subscription_schema_ready($pdo))subscription_ensure_schema($pdo);
subscription_entitlements_v340_ensure_schema($pdo);
$current=current_user();
$users=$pdo->query('SELECT id,display_name,email,is_active FROM users ORDER BY display_name,email,id')->fetchAll()?:[];
$userId=max(0,(int)($_GET['user']??$_POST['user_id']??0));
if($userId<1&&$users)$userId=(int)$users[0]['id'];
$catalog=array_filter(subscription_capability_catalog(),static fn(array $meta,string $key): bool=>subscription_entitlement_key_is_product_v340($key),ARRAY_FILTER_USE_BOTH);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/admin/entitlements.php?user='.$userId));}
    try{
        $action=(string)($_POST['action']??'grant');
        if($action==='grant'){
            $key=(string)($_POST['capability_key']??'');
            if(!isset($catalog[$key]))throw new RuntimeException('Select a valid product capability.');
            $meta=$catalog[$key];
            $limit=null;
            if(($meta['type']??'boolean')==='limit'){
                $raw=trim((string)($_POST['limit_value']??''));
                if($raw==='')throw new RuntimeException('Enter the add-on limit amount.');
                $limit=max(0,(int)$raw);
            }
            $ends=trim((string)($_POST['ends_at']??''));
            $reason=trim((string)($_POST['reason']??''));
            if($reason==='')throw new RuntimeException('Enter a reason for this entitlement grant.');
            subscription_grant_entitlement_v340($userId,$key,$limit,'admin_addon',null,$ends?:null,(int)($current['id']??0),['reason'=>mb_strimwidth($reason,0,500,'')]);
            flash('notice','Product entitlement granted.');
        }elseif($action==='revoke'){
            $reason=trim((string)($_POST['reason']??''));
            if($reason==='')throw new RuntimeException('Enter a reason for revoking this grant.');
            if(!subscription_revoke_entitlement_grant_v340((int)($_POST['grant_id']??0),$userId,(int)($current['id']??0),$reason))throw new RuntimeException('Active entitlement grant not found.');
            flash('notice','Entitlement grant revoked.');
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect(url('/admin/entitlements.php?user='.$userId));
}

$selected=null;foreach($users as $row)if((int)$row['id']===$userId){$selected=$row;break;}
$base=$selected?subscription_current_for_user_id($userId,$pdo):null;
$stmt=$pdo->prepare('SELECT g.*,u.display_name created_by_name FROM user_entitlement_grants g LEFT JOIN users u ON u.id=g.created_by WHERE g.user_id=? ORDER BY g.status="active" DESC,g.id DESC LIMIT 200');
$stmt->execute([$userId]);$grants=$stmt->fetchAll()?:[];
$adminTitle='Entitlement Grants';$adminActive='packages';require __DIR__.'/_header.php';
?>
<section class="admin-section-heading">
  <div><span class="eyebrow">Monetization</span><h2>Entitlement Grants</h2><p>Add product capabilities or capacity on top of a customer’s base package without changing roles, permissions, or Team membership.</p></div>
  <div class="actions"><a class="button" href="<?=e(url('/admin/packages.php'))?>">Base packages</a></div>
</section>

<section class="admin-card">
  <div class="admin-card-head"><div><h3>Customer</h3><p>Choose the account whose add-ons you want to manage.</p></div></div>
  <form method="get" class="admin-form">
    <label>Account<select name="user" onchange="this.form.submit()"><?php foreach($users as $row):?><option value="<?=(int)$row['id']?>" <?=$userId===(int)$row['id']?'selected':''?>><?=e((string)$row['display_name'].' · '.(string)$row['email'])?></option><?php endforeach;?></select></label>
  </form>
  <?php if($selected):?><p class="muted">Base package: <strong><?=e((string)($base['package_name']??'No active package'))?></strong>. Add-on grants compose with that package and survive package changes until revoked or expired.</p><?php endif;?>
</section>

<?php if($selected):?>
<section class="admin-card" style="margin-top:14px">
  <div class="admin-card-head"><div><h3>Grant product access</h3><p>Boolean capabilities enable access. Limit capabilities add capacity to the base-package amount.</p></div></div>
  <form method="post" class="admin-form"><?=csrf_field()?><input type="hidden" name="action" value="grant"><input type="hidden" name="user_id" value="<?=$userId?>">
    <div class="form-row"><label>Capability<select name="capability_key" required><?php foreach($catalog as $key=>$meta):?><option value="<?=e($key)?>"><?=e((string)($meta['label']??$key))?> · <?=e($key)?></option><?php endforeach;?></select></label><label>Add-on limit<input type="number" min="0" name="limit_value" placeholder="Required for limit capabilities"></label></div>
    <div class="form-row"><label>Ends at <input type="datetime-local" name="ends_at"></label><label>Reason<input name="reason" maxlength="500" required placeholder="Support case, purchased add-on, promotion…"></label></div>
    <button class="button primary" type="submit">Grant entitlement</button>
  </form>
</section>

<section class="admin-card" style="margin-top:14px">
  <div class="admin-card-head"><div><h3>Grant history</h3><p><?=count($grants)?> recorded grant<?=count($grants)===1?'':'s'?>.</p></div></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Capability</th><th>Value</th><th>Source</th><th>Window</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach($grants as $grant):$meta=$catalog[(string)$grant['capability_key']]??['label'=>$grant['capability_key']];?>
    <tr><td><strong><?=e((string)$meta['label'])?></strong><br><small><?=e((string)$grant['capability_key'])?></small></td><td><?=$grant['limit_value']===null?'Enabled':'+'.number_format((int)$grant['limit_value'])?></td><td><?=e((string)$grant['source_kind'])?><br><small><?=e((string)($grant['created_by_name']??'System'))?></small></td><td><?=e((string)$grant['starts_at'])?><br><small><?=e((string)($grant['ends_at']??'No expiry'))?></small></td><td><?=e((string)$grant['status'])?></td><td><?php if((string)$grant['status']==='active'):?><form method="post" class="admin-form"><?=csrf_field()?><input type="hidden" name="action" value="revoke"><input type="hidden" name="user_id" value="<?=$userId?>"><input type="hidden" name="grant_id" value="<?=(int)$grant['id']?>"><input name="reason" maxlength="500" required placeholder="Revocation reason"><button class="button button-small" type="submit">Revoke</button></form><?php endif;?></td></tr>
  <?php endforeach;?>
  <?php if(!$grants):?><tr><td colspan="6">No add-on grants for this account.</td></tr><?php endif;?>
  </tbody></table></div>
</section>
<?php endif;?>
<?php require __DIR__.'/_footer.php'; ?>