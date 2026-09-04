<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('admin.access');

$user = current_user();
$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}
if (!release_v105_schema_ready()) {
    $adminTitle='Credits Graph';$adminActive='credits';require __DIR__.'/_header.php';
    echo '<div class="panel"><span class="status">Stonefellow v105</span><h2>Credits Graph needs the v105 database upgrade.</h2><p class="muted">Import <code>upgrade-stonefellow-v105.sql</code>, then reload.</p></div>';
    require __DIR__.'/_footer.php';exit;
}

$uid=(int)$user['id'];
$isAdmin=user_has_role('admin',$user);
$allTracks=$pdo->query('SELECT id,title,album,owner_user_id,producer_user_id,updated_at FROM tracks ORDER BY updated_at DESC,title LIMIT 500')->fetchAll();
$tracks=$isAdmin?$allTracks:array_values(array_filter($allTracks,static fn(array $row):bool=>permission_v105_track_allowed($row,$user)));
$requestedTrackId=max(0,(int)($_GET['track']??$_POST['track_id']??0));
$track=null;
if($requestedTrackId>0){
    foreach($tracks as $candidate){if((int)$candidate['id']===$requestedTrackId){$track=get_track_by_id($requestedTrackId);break;}}
}elseif($tracks){
    $requestedTrackId=(int)$tracks[0]['id'];
    $track=get_track_by_id($requestedTrackId);
}
$trackId=$track?(int)$track['id']:0;
$canEdit=$track && permission_v105_track_allowed($track,$user)
    && ($isAdmin||permission_v105_has('credits.manage',$user)||can_manage_track_production($track,$user));

$allUsers=$pdo->query('SELECT id,display_name FROM users WHERE is_active=1 ORDER BY display_name LIMIT 500')->fetchAll();
$users=$isAdmin?$allUsers:array_values(array_filter($allUsers,static fn(array $row):bool=>permission_v105_workspace_user_allowed((int)$row['id'],$user)));
$allowedUserIds=array_fill_keys(array_map(static fn(array $row):int=>(int)$row['id'],$users),true);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('error','Session expired. Try again.');redirect(url('/admin/credits.php?track='.$trackId));}
    try{
        if(!$track||!$canEdit)throw new RuntimeException('Credits are not editable for this track.');
        $action=(string)($_POST['action']??'add');
        if($action==='delete'){
            $id=max(0,(int)($_POST['credit_id']??0));
            $stmt=$pdo->prepare("DELETE FROM track_credits WHERE id=? AND track_id=? AND source_kind='manual'");
            $stmt->execute([$id,$trackId]);
            agent_tool_log($user,'credits.delete','Delete track credit','success',['track_id'=>$trackId,'credit_id'=>$id]);
            flash('notice','Credit removed.');
        }else{
            $personId=max(0,(int)($_POST['user_id']??0));
            $displayName=trim((string)($_POST['display_name']??''));
            $role=trim((string)($_POST['contribution_role']??''));
            $detail=trim((string)($_POST['contribution_detail']??''));
            if($role==='')throw new RuntimeException('Contribution role is required.');
            if($personId>0){
                if(!$isAdmin&&!isset($allowedUserIds[$personId]))throw new RuntimeException('That contributor account is outside this Artist workspace.');
                $s=$pdo->prepare('SELECT display_name FROM users WHERE id=? AND is_active=1 LIMIT 1');$s->execute([$personId]);$resolved=(string)$s->fetchColumn();
                if($resolved==='')throw new RuntimeException('Contributor account is unavailable.');
                $displayName=$resolved;
            }
            if($displayName==='')throw new RuntimeException('Choose a Stonefellow user or enter a contributor name.');
            $stmt=$pdo->prepare('INSERT INTO track_credits (track_id,user_id,display_name,contribution_role,contribution_detail,source_kind) VALUES (?,?,?,?,?,"manual")');
            $stmt->execute([$trackId,$personId?:null,$displayName,mb_substr($role,0,120),mb_substr($detail,0,500)]);
            agent_tool_log($user,'credits.add',$displayName.' · '.$role,'success',['track_id'=>$trackId,'credit_id'=>(int)$pdo->lastInsertId()]);
            flash('notice','Credit added.');
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect(url('/admin/credits.php?track='.$trackId));
}

$credits=$track?credits_v105_rows($user,$trackId):[];
$manual=[];
if($track){$stmt=$pdo->prepare("SELECT tc.*,COALESCE(NULLIF(tc.display_name,''),u.display_name,'Unknown') AS resolved_name FROM track_credits tc LEFT JOIN users u ON u.id=tc.user_id WHERE tc.track_id=? AND tc.source_kind='manual' ORDER BY tc.sort_order,tc.id");$stmt->execute([$trackId]);$manual=$stmt->fetchAll();}

$adminTitle='Credits Graph';$adminActive='credits';require __DIR__.'/_header.php';
?>
<style>
.credits-v105-layout{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(320px,.8fr);gap:18px}.credits-v105-graph{position:relative;min-height:560px;border:1px solid rgba(255,255,255,.08);border-radius:16px;overflow:hidden;background:radial-gradient(circle at center,rgba(255,255,255,.045),transparent 55%)}.credits-v105-graph svg{position:absolute;inset:0;width:100%;height:100%}.credits-v105-node{position:absolute;transform:translate(-50%,-50%);width:150px;padding:10px;border-radius:12px;border:1px solid rgba(255,255,255,.1);background:rgba(12,12,12,.94);text-align:center;z-index:2}.credits-v105-node.track{width:180px;padding:15px;background:rgba(255,255,255,.08)}.credits-v105-node strong,.credits-v105-node span{display:block}.credits-v105-node span{font-size:11px;margin-top:4px;opacity:.7}.credits-v105-list{display:grid;gap:8px}.credits-v105-row{padding:11px;border:1px solid rgba(255,255,255,.07);border-radius:11px}.credits-v105-row small{display:block;margin-top:4px;opacity:.65}@media(max-width:980px){.credits-v105-layout{grid-template-columns:1fr}.credits-v105-graph{min-height:680px}}
</style>
<div class="panel">
  <div class="content-library-heading"><div><span class="status">Production Identity</span><h2>Credits Graph</h2><p class="muted">Stonefellow automatically combines track ownership, Producer assignment, project imports, production-note participation and structured credits.</p></div><form method="get"><select name="track" onchange="this.form.submit()"><?php foreach($tracks as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)$row['id']===$trackId?'selected':'' ?>><?= e((string)$row['title']) ?></option><?php endforeach; ?></select></form></div>
</div>
<?php if($track): ?>
<div class="credits-v105-layout">
  <div class="panel"><div class="credits-v105-graph" id="creditsGraphV105" data-track-title="<?= e((string)$track['title']) ?>" data-credits='<?= e(json_encode($credits,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>'></div></div>
  <aside>
    <div class="panel"><span class="status"><?= count($credits) ?> Contributors / Roles</span><h2><?= e((string)$track['title']) ?></h2><div class="credits-v105-list"><?php foreach($credits as $row): ?><div class="credits-v105-row"><strong><?= e((string)$row['display_name']) ?></strong><span><?= e((string)$row['contribution_role']) ?></span><?php if(trim((string)$row['contribution_detail'])!==''): ?><small><?= e((string)$row['contribution_detail']) ?></small><?php endif; ?><small><?= e((string)$row['source_kind']) ?></small></div><?php endforeach; ?><?php if(!$credits): ?><p class="muted">No contributors have been detected yet.</p><?php endif; ?></div></div>
    <?php if($canEdit): ?><div class="panel"><h2>Add Credit</h2><form method="post" class="grid-form"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="track_id" value="<?= $trackId ?>"><div class="field full"><label>Stonefellow User</label><select name="user_id"><option value="">External / manual contributor</option><?php foreach($users as $person): ?><option value="<?= (int)$person['id'] ?>"><?= e((string)$person['display_name']) ?></option><?php endforeach; ?></select></div><div class="field full"><label>Contributor Name</label><input name="display_name" maxlength="190" placeholder="Used when no account is selected"></div><div class="field full"><label>Contribution</label><input name="contribution_role" required maxlength="120" placeholder="Songwriter, vocals, guitar, mix engineer…"></div><div class="field full"><label>Details</label><textarea name="contribution_detail" maxlength="500"></textarea></div><div class="field full"><button class="btn primary" type="submit">Add Credit</button></div></form><?php if($manual): ?><h3>Manual Credits</h3><?php foreach($manual as $row): ?><form method="post" class="actions" style="margin-top:7px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="track_id" value="<?= $trackId ?>"><input type="hidden" name="credit_id" value="<?= (int)$row['id'] ?>"><span><?= e((string)$row['resolved_name'].' · '.(string)$row['contribution_role']) ?></span><button class="btn danger" type="submit">Remove</button></form><?php endforeach; ?><?php endif; ?></div><?php endif; ?>
  </aside>
</div>
<script src="<?= e(url('/admin/credits-v105.js?v=105')) ?>"></script>
<?php else: ?><div class="panel"><p class="muted">No tracks are available to this account.</p></div><?php endif; ?>
<?php require __DIR__.'/_footer.php'; ?>
