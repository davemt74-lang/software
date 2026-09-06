<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_login();
require_permission('users.manage');

$pdo=db();
if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
if(!subscription_schema_ready($pdo))subscription_ensure_schema($pdo);

function admin_package_slug(string $value): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','-',$value)??'';
    return trim(substr($value,0,80),'-');
}

function admin_package_entitlement_catalog(): array
{
    $catalog=subscription_capability_catalog();
    if(function_exists('permission_catalog')){
        foreach(permission_catalog() as $key=>$meta){
            $catalog[subscription_permission_key((string)$key)]=[
                'label'=>'Permission: '.(string)($meta['label']??$key),
                'type'=>'boolean','category'=>'Permissions',
            ];
        }
    }
    uasort($catalog,static fn(array $a,array $b): int=>[(string)($a['category']??''),(string)($a['label']??'')]<=>[(string)($b['category']??''),(string)($b['label']??'')]);
    return $catalog;
}

$error='';
$editId=max(0,(int)($_GET['edit']??$_POST['package_id']??0));
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired. Please try again.';
    else{
        try{
            $action=(string)($_POST['action']??'save');
            if($action==='save'){
                $name=trim((string)($_POST['name']??''));
                $slug=admin_package_slug((string)($_POST['slug']??$name));
                if($name===''||mb_strlen($name)>120)throw new RuntimeException('Enter a package name using 120 characters or fewer.');
                if($slug==='')throw new RuntimeException('Enter a valid package slug.');
                $values=[
                    $slug,$name,trim((string)($_POST['description']??'')),
                    max(0,(int)($_POST['monthly_price_cents']??0)),max(0,(int)($_POST['annual_price_cents']??0)),
                    max(0,(int)($_POST['ai_tokens_monthly']??0)),max(0,min(3650,(int)($_POST['trial_days']??0))),max(0,(int)($_POST['trial_tokens']??0)),
                    !empty($_POST['is_trial'])?1:0,!empty($_POST['is_default'])?1:0,!empty($_POST['is_public'])?1:0,!empty($_POST['is_active'])?1:0,(int)($_POST['sort_order']??100),
                ];
                $pdo->beginTransaction();
                if($editId>0){
                    $stmt=$pdo->prepare('UPDATE subscription_packages SET slug=?,name=?,description=?,monthly_price_cents=?,annual_price_cents=?,ai_tokens_monthly=?,trial_days=?,trial_tokens=?,is_trial=?,is_default=?,is_public=?,is_active=?,sort_order=? WHERE id=?');
                    $stmt->execute([...$values,$editId]);$packageId=$editId;
                }else{
                    $stmt=$pdo->prepare('INSERT INTO subscription_packages (slug,name,description,monthly_price_cents,annual_price_cents,ai_tokens_monthly,trial_days,trial_tokens,is_trial,is_default,is_public,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute($values);$packageId=(int)$pdo->lastInsertId();
                }
                if(!empty($_POST['is_default'])){
                    $pdo->prepare('UPDATE subscription_packages SET is_default=0 WHERE id<>?')->execute([$packageId]);
                    $pdo->prepare('UPDATE subscription_packages SET is_default=1 WHERE id=?')->execute([$packageId]);
                }
                $enabled=is_array($_POST['entitlement_enabled']??null)?$_POST['entitlement_enabled']:[];
                $limits=is_array($_POST['entitlement_limit']??null)?$_POST['entitlement_limit']:[];
                $upsert=$pdo->prepare('INSERT INTO package_entitlements (package_id,capability_key,is_enabled,limit_value) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),limit_value=VALUES(limit_value),updated_at=NOW()');
                foreach(admin_package_entitlement_catalog() as $key=>$meta){
                    $limit=null;
                    if(($meta['type']??'boolean')==='limit'){
                        $raw=trim((string)($limits[$key]??''));$limit=$raw===''?null:max(0,(int)$raw);
                    }
                    $upsert->execute([$packageId,$key,array_key_exists($key,$enabled)?1:0,$limit]);
                }
                $pdo->commit();flash('notice','Package saved.');redirect(url('/admin/packages.php?edit='.$packageId));
            }
            if($action==='duplicate'){
                $source=subscription_package($editId);if(!$source)throw new RuntimeException('Package not found.');
                $slug=admin_package_slug((string)$source['slug'].'-copy-'.date('His'));
                $stmt=$pdo->prepare('INSERT INTO subscription_packages (slug,name,description,monthly_price_cents,annual_price_cents,ai_tokens_monthly,trial_days,trial_tokens,is_trial,is_default,is_public,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,0,0,1,?)');
                $stmt->execute([$slug,(string)$source['name'].' Copy',(string)$source['description'],(int)$source['monthly_price_cents'],(int)$source['annual_price_cents'],(int)$source['ai_tokens_monthly'],(int)$source['trial_days'],(int)$source['trial_tokens'],(int)$source['is_trial'],(int)$source['sort_order']+1]);
                $newId=(int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO package_entitlements (package_id,capability_key,is_enabled,limit_value,metadata_json) SELECT ?,capability_key,is_enabled,limit_value,metadata_json FROM package_entitlements WHERE package_id=?')->execute([$newId,$editId]);
                flash('notice','Package duplicated.');redirect(url('/admin/packages.php?edit='.$newId));
            }
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
    }
}

$packages=subscription_packages(false);$package=$editId>0?subscription_package($editId):null;
if($editId>0&&!$package){$error=$error?:'Package not found.';$editId=0;}
$entitlementMap=[];foreach(($package['entitlements']??[]) as $row)$entitlementMap[(string)$row['capability_key']]=$row;
$catalog=admin_package_entitlement_catalog();
$adminTitle='Packages & Subscriptions';$adminActive='packages';
require __DIR__.'/_header.php';
?>
<div class="admin-section-heading"><div><span class="eyebrow">Monetization</span><h2>Packages & Subscriptions</h2><p>Define account packages, trial capacity, AI tokens, feature entitlements, limits and permission grants.</p></div><a class="button" href="<?= e(url('/admin/packages.php')) ?>">New Package</a></div>
<?php if($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<div class="admin-grid admin-grid-2">
<section class="admin-card"><div class="admin-card-head"><div><h3>Packages</h3><p><?= count($packages) ?> configured</p></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Package</th><th>AI allowance</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($packages as $row): ?><tr><td><strong><?= e((string)$row['name']) ?></strong><br><small><?= e((string)$row['slug']) ?><?= (int)$row['is_default']===1?' · default':'' ?><?= (int)$row['is_trial']===1?' · trial':'' ?></small></td><td><?= (int)$row['is_trial']===1?number_format((int)$row['trial_tokens']).' trial':number_format((int)$row['ai_tokens_monthly']).' / month' ?></td><td><?= (int)$row['is_active']===1?'Active':'Inactive' ?><?= (int)$row['is_public']===1?' · Public':' · Hidden' ?></td><td><a class="button button-small" href="<?= e(url('/admin/packages.php?edit='.(int)$row['id'])) ?>">Edit</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="admin-card"><div class="admin-card-head"><div><h3><?= $package?'Edit Package':'Create Package' ?></h3><p><?= $package?'Entitlement changes affect package access immediately.':'Create a reusable account package.' ?></p></div></div>
<form method="post" class="admin-form"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="package_id" value="<?= (int)$editId ?>">
<div class="form-row"><label>Name<input name="name" maxlength="120" required value="<?= e((string)($package['name']??'')) ?>"></label><label>Slug<input name="slug" maxlength="80" value="<?= e((string)($package['slug']??'')) ?>" placeholder="auto-from-name"></label></div>
<label>Description<textarea name="description" rows="3"><?= e((string)($package['description']??'')) ?></textarea></label>
<div class="form-row"><label>Monthly price (cents)<input type="number" min="0" name="monthly_price_cents" value="<?= (int)($package['monthly_price_cents']??0) ?>"></label><label>Annual price (cents)<input type="number" min="0" name="annual_price_cents" value="<?= (int)($package['annual_price_cents']??0) ?>"></label></div>
<div class="form-row"><label>Monthly AI tokens<input type="number" min="0" name="ai_tokens_monthly" value="<?= (int)($package['ai_tokens_monthly']??0) ?>"></label><label>Sort order<input type="number" name="sort_order" value="<?= (int)($package['sort_order']??100) ?>"></label></div>
<div class="form-row"><label>Trial days<input type="number" min="0" max="3650" name="trial_days" value="<?= (int)($package['trial_days']??0) ?>"></label><label>Trial AI tokens<input type="number" min="0" name="trial_tokens" value="<?= (int)($package['trial_tokens']??0) ?>"></label></div>
<div class="admin-check-grid"><label><input type="checkbox" name="is_trial" value="1" <?= (int)($package['is_trial']??0)===1?'checked':'' ?>> Trial package</label><label><input type="checkbox" name="is_default" value="1" <?= (int)($package['is_default']??0)===1?'checked':'' ?>> Default signup package</label><label><input type="checkbox" name="is_public" value="1" <?= !$package||(int)$package['is_public']===1?'checked':'' ?>> Public</label><label><input type="checkbox" name="is_active" value="1" <?= !$package||(int)$package['is_active']===1?'checked':'' ?>> Active</label></div>
<h4>Feature entitlements & limits</h4><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Capability</th><th>Enabled</th><th>Limit</th></tr></thead><tbody>
<?php $lastCategory='';foreach($catalog as $key=>$meta): $category=(string)($meta['category']??'Other');$state=$entitlementMap[$key]??null;if($category!==$lastCategory):$lastCategory=$category;?><tr><th colspan="3"><?= e($category) ?></th></tr><?php endif; ?><tr><td><strong><?= e((string)$meta['label']) ?></strong><br><small><?= e($key) ?></small></td><td><input type="checkbox" name="entitlement_enabled[<?= e($key) ?>]" value="1" <?= $state&&(int)$state['is_enabled']===1?'checked':'' ?>></td><td><?php if(($meta['type']??'boolean')==='limit'): ?><input type="number" min="0" name="entitlement_limit[<?= e($key) ?>]" value="<?= e($state&&$state['limit_value']!==null?(string)$state['limit_value']:'') ?>"><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><div class="form-actions"><button class="button primary" type="submit">Save Package</button></div></form>
<?php if($package): ?><form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="package_id" value="<?= (int)$editId ?>"><button class="button" type="submit">Duplicate Package</button></form><?php endif; ?></section></div>
<?php if($package): ?><section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><div><h3>Package Simulator</h3><p>Package-level access before team/workspace delegation or internal Admin authority.</p></div></div><div class="admin-table-wrap"><table class="admin-table"><tbody><tr><td>AI allowance</td><td><?= (int)$package['is_trial']===1?number_format((int)$package['trial_tokens']).' total trial tokens':number_format((int)$package['ai_tokens_monthly']).' tokens/month' ?></td></tr><?php foreach($catalog as $key=>$meta):$state=$entitlementMap[$key]??null;if(!$state||(int)$state['is_enabled']!==1)continue;?><tr><td><?= e((string)$meta['label']) ?></td><td><?= ($meta['type']??'boolean')==='limit'?(($state['limit_value']===null)?'Enabled':number_format((int)$state['limit_value'])):'Enabled' ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php require __DIR__.'/_footer.php'; ?>