<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

if(!is_logged_in())redirect(url('/login.php'));
$user=current_user();$pdo=db();
if(!$user||!$pdo)redirect(url('/login.php'));
if(!vp3_research_schema_ready_v2060($pdo))redirect(url('/upgrade.php'));
$uid=(int)$user['id'];
$projectId=trim((string)($_GET['project']??$_POST['project_id']??''));
$reportId=trim((string)($_GET['report']??$_POST['report_id']??''));
if($projectId===''||$reportId==='')redirect(url('/research.php'));

try{
    $projectRow=vp3_research_project_require_v2060($pdo,$projectId,$uid,'researcher');
    $reportRow=vp3_research_report_row_v2060($pdo,$reportId);
    if(!$reportRow||(int)$reportRow['project_id']!==(int)$projectRow['id'])throw new RuntimeException('Report was not found.');
}catch(Throwable $e){http_response_code(404);exit('Research report was not found.');}

$role=(string)$projectRow['_role'];
$canPublish=vp3_research_role_at_least_v2060($role,'admin');
$notice=flash('report_builder_notice');$error=flash('report_builder_error');

function research_rb_redirect_v2060(string $projectId,string $reportId): never
{
    redirect(url('/research-report-builder.php?project='.rawurlencode($projectId).'&report='.rawurlencode($reportId)));
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('report_builder_error','Session expired. Try again.');research_rb_redirect_v2060($projectId,$reportId);}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='save_metadata'){
            vp3_research_update_report_v2060($pdo,$uid,$projectId,$reportId,(string)($_POST['title']??''),(string)($_POST['summary']??''));
            flash('report_builder_notice','Report draft updated.');research_rb_redirect_v2060($projectId,$reportId);
        }
        if($action==='save_items'){
            $selected=is_array($_POST['items']??null)?$_POST['items']:[];
            $items=[];
            foreach($selected as $raw){
                $raw=(string)$raw;
                [$type,$id]=array_pad(explode(':',$raw,2),2,'');
                if(in_array($type,['finding','source','annotation'],true)&&$id!=='')$items[]=['type'=>$type,'id'=>$id];
            }
            vp3_research_set_report_items_v2060($pdo,$uid,$projectId,$reportId,$items);
            flash('report_builder_notice','Report contents saved.');research_rb_redirect_v2060($projectId,$reportId);
        }
        if($action==='publish'){
            if(!$canPublish)throw new RuntimeException('Admin access is required to publish Research.');
            vp3_research_publish_report_v2060($pdo,$uid,$projectId,$reportId,(string)($_POST['visibility']??'private'),max(0,(int)($_POST['team_id']??0)));
            flash('report_builder_notice','A new immutable report version was published.');research_rb_redirect_v2060($projectId,$reportId);
        }
        if($action==='unpublish'){
            if(!$canPublish)throw new RuntimeException('Admin access is required to unpublish Research.');
            vp3_research_unpublish_report_v2060($pdo,$uid,$projectId,$reportId);
            flash('report_builder_notice','Report unpublished. Historical versions remain intact.');research_rb_redirect_v2060($projectId,$reportId);
        }
    }catch(Throwable $e){flash('report_builder_error',$e->getMessage());research_rb_redirect_v2060($projectId,$reportId);}
}

$bundle=vp3_research_project_bundle_v2060($pdo,$projectId,$uid);
$reportRow=vp3_research_report_row_v2060($pdo,$reportId)??$reportRow;
$report=vp3_research_report_public_v2060($pdo,$reportRow,$uid);
$selected=[];
foreach($report['items']??[] as $entry){
    if(($entry['type']??'')==='finding')$selected['finding:'.(string)$entry['id']]=true;
    elseif(isset($entry['item']['id']))$selected[(string)$entry['type'].':'.(string)$entry['item']['id']]=true;
}
$findings=$bundle['findings'];$items=$bundle['items'];
$teams=[];
try{$teams=vp3_human_team_workspaces_v370($pdo,$uid);}catch(Throwable $e){$teams=[];}
$currentVersion=null;
if((int)($reportRow['current_version_no']??0)>0)$currentVersion=vp3_research_report_version_v2060($pdo,$reportRow,$uid,0);

function research_rb_e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=research_rb_e((string)$report['title'])?> · Report Builder · VP3</title>
<style>
:root{--bg:#f4f4f1;--panel:#fff;--line:#dfdfd8;--text:#171717;--muted:#707068;--soft:#f0f0eb;--green:#eaf7ed;--blue:#e9efff}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:inherit}.shell{max-width:1120px;margin:0 auto;padding:26px 20px 70px}.top{display:flex;justify-content:space-between;gap:14px;margin-bottom:22px}.brand{font-weight:900;text-decoration:none}.nav{display:flex;gap:14px;color:var(--muted);font-size:12px}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;padding:22px;background:#fff;border:1px solid var(--line);border-radius:19px}.eyebrow{font-size:10px;font-weight:850;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}h1{margin:7px 0 5px;font-size:30px;line-height:1.1;letter-spacing:-.04em}.muted{color:var(--muted);font-size:12px}.version-chip{padding:8px 10px;border-radius:999px;background:var(--blue);font-size:11px}.layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:16px;margin-top:16px}.main,.side{display:grid;gap:14px;align-content:start}.card{padding:18px;background:#fff;border:1px solid var(--line);border-radius:16px}.card h2{margin:0;font-size:17px}.field{display:block;margin-top:11px}.field span{display:block;margin-bottom:5px;font-size:11px;font-weight:750}input,textarea,select,button{font:inherit}input,textarea,select{width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:9px;background:#fff}textarea{resize:vertical}button,.button{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:9px;background:#fff;padding:8px 10px;font-weight:700;text-decoration:none;cursor:pointer}.primary{background:#171717;color:#fff;border-color:#171717}.full{width:100%}.notice,.error{margin-bottom:14px;padding:11px 13px;border-radius:10px}.notice{background:var(--green);color:#285c34}.error{background:#fff0f0;color:#8b2929}.group{margin-top:16px}.group-title{display:flex;justify-content:space-between;margin-bottom:7px}.choice{display:grid;grid-template-columns:auto minmax(0,1fr);gap:10px;padding:11px 0;border-top:1px solid var(--line)}.choice:first-of-type{border-top:0}.choice input{width:auto;margin-top:4px}.choice strong{display:block}.choice small{display:block;color:var(--muted);margin-top:2px}.status{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.chip{padding:4px 7px;border-radius:999px;background:var(--soft);font-size:10px;color:var(--muted)}.hash{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:10px;overflow-wrap:anywhere}.warning{padding:10px;border-radius:9px;background:#fff4d9;color:#725900;font-size:11px;margin-top:10px}.publish-actions{display:grid;gap:8px;margin-top:12px}.empty{padding:22px;border:1px dashed #d2d2cc;border-radius:12px;text-align:center;color:var(--muted)}@media(max-width:820px){.layout{grid-template-columns:1fr}.head{display:block}.version-chip{display:inline-flex;margin-top:12px}}@media(max-width:560px){.shell{padding:18px 12px 60px}}
</style></head><body><main class="shell">
<div class="top"><a class="brand" href="<?=research_rb_e(url('/'))?>">VP3</a><nav class="nav"><a href="<?=research_rb_e(url('/research-project.php?project='.rawurlencode($projectId)))?>">Project</a><a href="<?=research_rb_e(url('/research.php'))?>">Research Hub</a></nav></div>
<?php if($notice): ?><div class="notice"><?=research_rb_e($notice)?></div><?php endif; ?><?php if($error): ?><div class="error"><?=research_rb_e($error)?></div><?php endif; ?>
<header class="head"><div><div class="eyebrow">Report Builder</div><h1><?=research_rb_e((string)$report['title'])?></h1><div class="muted"><?=research_rb_e((string)$bundle['project']['title'])?> · <?=research_rb_e(ucfirst((string)$report['status']))?></div></div><div class="version-chip">Published version <?= (int)$report['current_version'] ?: '—' ?></div></header>

<div class="layout"><div class="main">
<form class="card" method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_rb_e($projectId)?>"><input type="hidden" name="report_id" value="<?=research_rb_e($reportId)?>"><input type="hidden" name="action" value="save_metadata"><div class="eyebrow">Draft metadata</div><h2>Title and summary</h2><label class="field"><span>Report title</span><input name="title" maxlength="190" required value="<?=research_rb_e((string)$report['title'])?>"></label><label class="field"><span>Summary</span><textarea name="summary" rows="5"><?=research_rb_e((string)$report['summary'])?></textarea></label><button type="submit">Save draft metadata</button><?php if((string)$report['status']==='published'): ?><div class="warning">These changes affect the next version only. The currently published snapshot stays unchanged until you publish again.</div><?php endif; ?></form>

<form class="card" method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_rb_e($projectId)?>"><input type="hidden" name="report_id" value="<?=research_rb_e($reportId)?>"><input type="hidden" name="action" value="save_items"><div class="eyebrow">Composition</div><h2>Select what this report publishes</h2>
<div class="group"><div class="group-title"><strong>Findings</strong><span class="muted"><?=count($findings)?></span></div>
<?php if(!$findings): ?><div class="empty">No Findings yet.</div><?php endif; ?><?php foreach($findings as $finding): $key='finding:'.(string)$finding['id']; ?><label class="choice"><input type="checkbox" name="items[]" value="<?=research_rb_e($key)?>" <?=isset($selected[$key])?'checked':''?>><span><strong><?=research_rb_e((string)$finding['title'])?></strong><small><?=research_rb_e(ucfirst((string)$finding['status']))?> · revision <?= (int)$finding['revision'] ?><?=(string)$finding['status']==='draft'?' · must be Confirmed before publish':''?></small></span></label><?php endforeach; ?></div>
<div class="group"><div class="group-title"><strong>Sources & annotations</strong><span class="muted"><?=count($items)?></span></div>
<?php if(!$items): ?><div class="empty">No Research items yet.</div><?php endif; ?><?php foreach($items as $item): $key=(string)$item['type'].':'.(string)$item['id']; ?><label class="choice"><input type="checkbox" name="items[]" value="<?=research_rb_e($key)?>" <?=isset($selected[$key])?'checked':''?>><span><strong><?=research_rb_e(ucfirst((string)$item['type']).' · '.(string)($item['source']['title']?:$item['source']['domain']))?></strong><small><?=research_rb_e((string)$item['source']['domain'])?> · <?=research_rb_e(substr((string)($item['source']['version']['hash']??''),0,12))?></small></span></label><?php endforeach; ?></div>
<button class="primary" type="submit">Save report contents</button></form>
</div>

<aside class="side">
<section class="card"><div class="eyebrow">Current publication</div><h2><?=research_rb_e(ucfirst((string)$report['status']))?></h2><div class="status"><span class="chip"><?=research_rb_e(ucfirst((string)$report['visibility']))?></span><span class="chip">v<?= (int)$report['current_version'] ?></span></div>
<?php if($currentVersion): ?><p class="muted">Published <?=research_rb_e((string)$currentVersion['published_at'])?></p><div class="hash">SHA-256 <?=research_rb_e((string)$currentVersion['sha256'])?></div><div class="publish-actions"><a class="button full" href="<?=research_rb_e((string)$report['url'])?>">Open published report</a></div><?php else: ?><p class="muted">No immutable version has been published yet.</p><?php endif; ?></section>

<?php if($canPublish): ?><form class="card" method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_rb_e($projectId)?>"><input type="hidden" name="report_id" value="<?=research_rb_e($reportId)?>"><input type="hidden" name="action" value="publish"><div class="eyebrow">Publish version</div><h2>Visibility</h2><label class="field"><span>Publish as</span><select id="visibility" name="visibility"><option value="private">Private</option><option value="team">Team</option><option value="public">Public</option></select></label><label class="field" id="teamField"><span>Team</span><select name="team_id"><option value="0">Choose team…</option><?php foreach($teams as $team): $tid=(int)($team['owner_user_id']??0);if($tid<1)continue; ?><option value="<?=$tid?>"><?=research_rb_e((string)($team['workspace_name']??'Team'))?></option><?php endforeach; ?></select></label><div class="warning">Publishing creates a new immutable snapshot. Public/Team reports automatically redact incompatible annotation text back to its pinned Source.</div><button class="primary full" type="submit">Publish new version</button></form>
<?php if((string)$report['status']==='published'): ?><form class="card" method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_rb_e($projectId)?>"><input type="hidden" name="report_id" value="<?=research_rb_e($reportId)?>"><input type="hidden" name="action" value="unpublish"><div class="eyebrow">Availability</div><h2>Unpublish report</h2><p class="muted">Stops public/team access. Historical snapshots and hashes remain stored for project history.</p><button type="submit">Unpublish</button></form><?php endif; ?><?php endif; ?>
</aside></div>
<script>
const visibility=document.getElementById('visibility'),teamField=document.getElementById('teamField');
function syncTeam(){if(teamField)teamField.hidden=!visibility||visibility.value!=='team';}
visibility?.addEventListener('change',syncTeam);syncTeam();
</script></main></body></html>