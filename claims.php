<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_login();
$pdo=vp3_browser_trust_require_ready_v2080(db());
$user=current_user();$userId=(int)($user['id']??0);
$sourcePublic=trim((string)($_GET['source']??$_POST['source_id']??''));
$source=$sourcePublic!==''?vp3_browser_source_row_by_public_id_v2050($pdo,$sourcePublic):null;
$notice='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Session expired.';
    else try{
        $action=(string)($_POST['action']??'');
        if($action==='create'){
            $claim=vp3_browser_trust_claim_create_v2080($pdo,$userId,[
                'source_id'=>$sourcePublic,'browser_share_id'=>trim((string)($_POST['browser_share_id']??'')),
                'statement'=>(string)($_POST['statement']??''),'rationale'=>(string)($_POST['rationale']??''),
                'visibility'=>(string)($_POST['visibility']??'public'),'team_id'=>(int)($_POST['team_id']??0),
            ]);
            redirect(url('/claim.php?id='.rawurlencode((string)$claim['id'])));
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$teams=[];
try{$teams=(array)(vp3_browser_share_destinations_v2010($pdo,$userId)['teams']??[]);}catch(Throwable $e){}
$claims=$source?vp3_browser_trust_claims_for_source_v2080($pdo,$userId,$sourcePublic,100):vp3_browser_trust_user_claims_v2080($pdo,$userId,100);
$memberHeaderUser=$user;$memberHeaderTitle='Claims';$memberHeaderSubtitle='Source-linked statements with durable evidence and status history';$memberHeaderActions='<a class="notification-button" href="'.e(url('/notifications.php')).'">Notifications</a>';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VP3 | Claims</title>
<link rel="stylesheet" href="<?=e(url('/chat.css?v=82'))?>"><style>
.claims-main{background:#f7f8fa;min-width:0}.claims-canvas{height:100%;overflow:auto;padding:26px 24px 72px}.claims-wrap{width:min(1000px,100%);margin:auto;display:grid;gap:16px}.claim-card,.claim-form{background:#fff;border:1px solid #e1e5ea;border-radius:16px;padding:18px}.claim-form{display:grid;gap:12px}.claim-form textarea,.claim-form input,.claim-form select{width:100%;padding:10px;border:1px solid #d8dde4;border-radius:9px;font:inherit}.claim-form textarea{min-height:110px;resize:vertical}.claim-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.claim-list{display:grid;gap:10px}.claim-card{text-decoration:none;color:inherit;display:grid;gap:7px}.claim-card:hover{border-color:#c4cad2}.claim-meta{display:flex;gap:8px;flex-wrap:wrap;color:#6b7280;font-size:11px}.pill{padding:4px 7px;border-radius:999px;background:#eef1f4;font-size:10px;font-weight:800}.alert{padding:11px;border-radius:10px;background:#fff7f6;color:#9b1c1c;border:1px solid #efc5c2}.notification-button{display:inline-flex;padding:8px 11px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#111827;text-decoration:none;font-size:11px;font-weight:800}@media(max-width:680px){.claim-grid{grid-template-columns:1fr}.claims-canvas{padding:18px 14px 60px}}
</style></head><body><div class="chat-app"><?php $workspaceSidebarUser=$user;$workspaceSidebarActive='claims';require __DIR__.'/includes/workspace-sidebar-v82.php';?><div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
<main class="chat-main claims-main"><?php require __DIR__.'/includes/member-header.php';?><section class="claims-canvas"><div class="claims-wrap">
<?php if($error):?><div class="alert"><?=e($error)?></div><?php endif;?>
<?php if($source):?><section class="claim-form"><div><small>FILE A CLAIM</small><h2><?=e((string)($source['source_domain']??'Source'))?></h2><p>Claims pin the current source version or selected annotation as evidence. Later source changes do not rewrite that evidence.</p></div>
<form method="post" class="claim-form"><?=csrf_field()?><input type="hidden" name="action" value="create"><input type="hidden" name="source_id" value="<?=e($sourcePublic)?>">
<label>Claim statement<input name="statement" maxlength="1000" required placeholder="State the claim precisely"></label>
<label>Rationale / context<textarea name="rationale" maxlength="12000" placeholder="Why this claim matters, what it asserts, and any qualification"></textarea></label>
<div class="claim-grid"><label>Visibility<select name="visibility" id="claimVisibility"><option value="public">Public</option><option value="team">Team</option><option value="private">Private</option></select></label>
<label>Team<select name="team_id"><option value="0">Choose team if needed</option><?php foreach($teams as $team):?><option value="<?=(int)$team['id']?>"><?=e(preg_replace('/ · General$/','',(string)$team['name'])??(string)$team['name'])?></option><?php endforeach;?></select></label></div>
<button class="notification-button" type="submit">File claim</button></form></section><?php endif;?>
<section><h2><?= $source?'Claims on this source':'My claims' ?></h2><div class="claim-list">
<?php foreach($claims as $claim):?><a class="claim-card" href="<?=e((string)$claim['url'])?>"><strong><?=e((string)$claim['statement'])?></strong><div class="claim-meta"><span class="pill"><?=e(str_replace('_',' ',(string)$claim['status']))?></span><span><?=e((string)$claim['visibility'])?></span><span><?=e((string)$claim['source_domain'])?></span><span><?=e((string)$claim['created_at'])?></span></div></a><?php endforeach;?>
<?php if(!$claims):?><div class="claim-card">No claims yet.</div><?php endif;?></div></section>
</div></section></main></div></body></html>