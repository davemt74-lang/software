<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$pdo=vp3_browser_trust_require_ready_v2080(db());$user=current_user();$userId=(int)($user['id']??0);
$publicId=trim((string)($_GET['id']??$_POST['id']??''));$row=vp3_browser_trust_claim_row_v2080($pdo,$publicId);
if(!$row||!vp3_browser_trust_claim_access_v2080($pdo,$row,$userId)){http_response_code(404);echo 'Claim not found.';exit;}
$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_login();
    if(!verify_csrf())$error='Session expired.';
    else try{
        $action=(string)($_POST['action']??'');
        if($action==='status'){
            vp3_browser_trust_claim_status_v2080($pdo,$userId,$publicId,(string)($_POST['status']??''),(string)($_POST['note']??''));$notice='Claim status updated.';
        }elseif($action==='report'){
            vp3_browser_trust_report_create_v2080($pdo,$userId,'claim',$publicId,(string)($_POST['reason']??''),(string)($_POST['detail']??''));$notice='Report submitted for review.';
        }
        $row=vp3_browser_trust_claim_row_v2080($pdo,$publicId)??$row;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$claim=vp3_browser_trust_claim_public_v2080($pdo,$row,$userId,true);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e((string)$claim['statement'])?> · VP3 Claim</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f5f5f2;color:#171717;font:15px/1.55 Inter,system-ui,sans-serif}.shell{max-width:900px;margin:auto;padding:28px 18px 70px}.top{display:flex;justify-content:space-between;gap:12px}.brand{font-weight:900;text-decoration:none}.card{margin-top:16px;padding:20px;background:#fff;border:1px solid #e1e1dc;border-radius:17px}.pill{display:inline-flex;padding:5px 8px;border-radius:999px;background:#efefe9;font-size:11px;font-weight:800}.meta{color:#6b6b65;font-size:12px}.timeline{display:grid;gap:9px}.event{padding:12px;border-left:3px solid #d6d6cf;background:#fafaf7}.form{display:grid;gap:9px}.form textarea,.form select,.form input{width:100%;padding:10px;border:1px solid #d7d7d0;border-radius:9px;font:inherit}.alert{padding:10px;border-radius:9px;background:#f3f7f3}.alert.error{background:#fff2f1;color:#951b1b}.actions{display:flex;gap:8px;flex-wrap:wrap}.button{display:inline-flex;padding:8px 11px;border:1px solid #ccc;border-radius:8px;background:#fff;color:#111;text-decoration:none;font-weight:700;cursor:pointer}</style></head><body><main class="shell"><div class="top"><a class="brand" href="<?=e(url('/'))?>">VP3</a><div class="actions"><a class="button" href="<?=e(url('/source.php?source='.rawurlencode((string)$claim['source_id'])))?>">Source</a><a class="button" href="<?=e(url('/claims.php?source='.rawurlencode((string)$claim['source_id'])))?>">Claims</a></div></div>
<?php if($notice):?><div class="alert"><?=$notice?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<section class="card"><div class="meta">Claim · <?=e((string)$claim['visibility'])?> · <?=e((string)$claim['created_at'])?></div><h1><?=e((string)$claim['statement'])?></h1><span class="pill"><?=e(str_replace('_',' ',(string)$claim['status']))?></span><p><?=nl2br(e((string)$claim['rationale']))?></p><div class="meta">Filed by <?=e((string)$claim['creator']['name'])?> · Evidence version is pinned and immutable.</div><?php if($claim['resolution_note']):?><p><strong>Resolution note:</strong> <?=nl2br(e((string)$claim['resolution_note']))?></p><?php endif;?></section>
<section class="card"><h2>Status history</h2><div class="timeline"><?php foreach($claim['events'] as $event):?><div class="event"><strong><?=e(str_replace('_',' ',(string)$event['type']))?></strong><div class="meta"><?=e((string)$event['actor'])?> · <?=e((string)$event['created_at'])?><?= $event['to']?' · '.e((string)$event['from']).' → '.e((string)$event['to']):'' ?></div><?php if($event['note']):?><div><?=nl2br(e((string)$event['note']))?></div><?php endif;?></div><?php endforeach;?></div></section>
<?php if($user):?><section class="card"><h2>Actions</h2><?php if($claim['can_moderate']||$claim['can_withdraw']):?><form method="post" class="form"><?=csrf_field()?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=e($publicId)?>"><label>Status<select name="status"><?php foreach(['under_review','resolved','disputed','withdrawn'] as $s):if(!$claim['can_moderate']&&$s!=='withdrawn')continue;?><option value="<?=$s?>"><?=e(ucwords(str_replace('_',' ',$s)))?></option><?php endforeach;?></select></label><label>Note<textarea name="note" maxlength="4000"></textarea></label><button class="button" type="submit">Update status</button></form><?php endif;?>
<form method="post" class="form" style="margin-top:18px"><?=csrf_field()?><input type="hidden" name="action" value="report"><input type="hidden" name="id" value="<?=e($publicId)?>"><label>Report reason<input name="reason" maxlength="190" required placeholder="Spam, harassment, misleading context…"></label><label>Detail<textarea name="detail" maxlength="8000"></textarea></label><button class="button" type="submit">Report claim</button></form></section><?php endif;?>
</main></body></html>