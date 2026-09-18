<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

if(!is_logged_in())redirect(url('/login.php'));
$user=current_user();$pdo=db();
if(!$user||!$pdo)redirect(url('/login.php'));
if(!vp3_research_schema_ready_v2060($pdo))redirect(url('/upgrade.php'));
$uid=(int)$user['id'];
$notice=flash('research_notice');$error=flash('research_error');

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('research_error','Session expired. Try again.');redirect(url('/research.php'));}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='create_project'){
            $project=vp3_research_create_project_v2060($pdo,$uid,(string)($_POST['title']??''),(string)($_POST['description']??''),max(0,(int)($_POST['team_id']??0)));
            flash('research_notice','Research project created.');
            redirect(url('/research-project.php?project='.rawurlencode((string)$project['id'])));
        }
        if($action==='assign'){
            $projectId=trim((string)($_POST['project_id']??''));
            $shareId=trim((string)($_POST['browser_share_id']??''));
            vp3_research_assign_share_v2060($pdo,$uid,$projectId,$shareId,(string)($_POST['note']??''),(string)($_POST['tags']??''));
            flash('research_notice','Added to Research project.');
            redirect(url('/research.php'));
        }
        if($action==='remove_inbox'){
            $share=vp3_browser_source_authorized_row_v2050($pdo,$uid,trim((string)($_POST['browser_share_id']??'')));
            $pdo->prepare('DELETE FROM browser_research_queue_v2050 WHERE user_id=? AND browser_share_id=?')->execute([$uid,(int)$share['id']]);
            flash('research_notice','Removed from Research Inbox.');
            redirect(url('/research.php'));
        }
    }catch(Throwable $e){flash('research_error',$e->getMessage());redirect(url('/research.php'));}
}

$projects=vp3_research_projects_for_user_v2060($pdo,$uid,false);
$researcherProjects=array_values(array_filter($projects,static fn(array $p): bool=>vp3_research_role_at_least_v2060((string)$p['role'],'researcher')));
$inbox=vp3_research_inbox_v2060($pdo,$uid,100);
$teams=[];
try{$teams=vp3_human_team_workspaces_v370($pdo,$uid);}catch(Throwable $e){$teams=[];}

function research_h_e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research · VP3</title>
<style>
:root{color-scheme:light;--bg:#f4f4f1;--panel:#fff;--line:#e1e1db;--text:#171717;--muted:#707068;--soft:#f1f1ec}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:inherit}.shell{max-width:1180px;margin:0 auto;padding:28px 20px 70px}.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px}.brand{font-size:18px;font-weight:900;letter-spacing:-.04em;text-decoration:none}.nav{display:flex;gap:14px;color:var(--muted);font-size:12px}.hero{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:start}.hero-copy{padding:10px 0}.eyebrow{font-size:10px;font-weight:850;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}h1{margin:8px 0 10px;font-size:38px;line-height:1.05;letter-spacing:-.045em}.lead{max-width:680px;color:var(--muted);font-size:16px}.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:18px}.card h2,.section h2{margin:0;font-size:18px;letter-spacing:-.025em}.field{display:block;margin-top:12px}.field span{display:block;margin-bottom:5px;font-size:11px;font-weight:750}input,textarea,select,button{font:inherit}input,textarea,select{width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;background:#fff}textarea{resize:vertical}button,.button{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:10px;background:#fff;padding:9px 12px;font-weight:700;text-decoration:none;cursor:pointer}.primary{background:#171717;color:#fff;border-color:#171717}.full{width:100%}.notice,.error{margin-bottom:16px;padding:11px 13px;border-radius:11px}.notice{background:#eaf7ed;color:#285c34}.error{background:#fff0f0;color:#892a2a}.section{margin-top:34px}.section-head{display:flex;align-items:end;justify-content:space-between;margin-bottom:12px}.count{color:var(--muted);font-size:12px}.project-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.project{display:flex;flex-direction:column;min-height:180px;text-decoration:none}.project-title{font-size:17px;font-weight:800;letter-spacing:-.02em}.project-desc{margin-top:7px;color:var(--muted);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:auto;padding-top:16px}.stat{padding:8px;border-radius:9px;background:var(--soft);text-align:center}.stat strong{display:block}.stat span{font-size:9px;color:var(--muted);text-transform:uppercase}.role{display:inline-flex;margin-top:10px;padding:4px 7px;border-radius:999px;background:var(--soft);font-size:10px;color:var(--muted)}.inbox{display:grid;gap:10px}.inbox-item{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:16px}.source-title{font-weight:800}.domain{font-size:11px;color:var(--muted)}.quote{margin-top:10px;padding:10px 12px;border-left:3px solid #d1d1ca;background:#fafaf7;white-space:pre-wrap}.annotation-note{margin-top:9px}.assign{padding-left:16px;border-left:1px solid var(--line)}.assign-actions{display:flex;gap:7px;margin-top:9px}.empty{padding:32px;border:1px dashed #d1d1ca;border-radius:16px;text-align:center;color:var(--muted);background:rgba(255,255,255,.5)}@media(max-width:900px){.hero{grid-template-columns:1fr}.project-grid{grid-template-columns:1fr 1fr}.inbox-item{grid-template-columns:1fr}.assign{padding-left:0;border-left:0;border-top:1px solid var(--line);padding-top:12px}}@media(max-width:620px){.shell{padding:20px 14px 60px}.project-grid{grid-template-columns:1fr}h1{font-size:31px}.nav{gap:8px}}
</style></head><body><main class="shell">
<div class="top"><a class="brand" href="<?=research_h_e(url('/'))?>">VP3</a><nav class="nav"><a href="<?=research_h_e(url('/knowledge.php'))?>">Knowledge</a><a href="<?=research_h_e(url('/messages.php'))?>">Messages</a></nav></div>
<?php if($notice): ?><div class="notice"><?=research_h_e($notice)?></div><?php endif; ?>
<?php if($error): ?><div class="error"><?=research_h_e($error)?></div><?php endif; ?>
<section class="hero">
<div class="hero-copy"><div class="eyebrow">Research</div><h1>Turn what you find into evidence.</h1><p class="lead">Browser Companion captures land in your Research Inbox. Move them into projects, organize canonical Sources, develop Findings, and publish versioned reports without separating the browser from the website data model.</p></div>
<form class="card" method="post">
<?=csrf_field()?><input type="hidden" name="action" value="create_project">
<h2>New project</h2>
<label class="field"><span>Project title</span><input name="title" maxlength="190" required placeholder="Market landscape"></label>
<label class="field"><span>Description</span><textarea name="description" rows="3" placeholder="What are you trying to establish?"></textarea></label>
<label class="field"><span>Workspace</span><select name="team_id"><option value="0">Personal</option><?php foreach($teams as $team): $tid=(int)($team['owner_user_id']??0); if($tid<1)continue; ?><option value="<?=$tid?>"><?=research_h_e((string)($team['workspace_name']??'Team'))?></option><?php endforeach; ?></select></label>
<button class="primary full" type="submit">Create Research project</button>
</form>
</section>

<section class="section">
<div class="section-head"><div><div class="eyebrow">Projects</div><h2>Your Research</h2></div><span class="count"><?=count($projects)?> active</span></div>
<?php if(!$projects): ?><div class="empty">No Research projects yet. Create one above, then assign something from the Inbox.</div><?php else: ?>
<div class="project-grid"><?php foreach($projects as $project): ?><a class="card project" href="<?=research_h_e((string)$project['url'])?>">
<div class="project-title"><?=research_h_e((string)$project['title'])?></div><div class="project-desc"><?=research_h_e((string)$project['description'])?></div><span class="role"><?=research_h_e(ucfirst((string)$project['role']))?></span>
<div class="stats"><div class="stat"><strong><?= (int)$project['counts']['sources'] ?></strong><span>Sources</span></div><div class="stat"><strong><?= (int)$project['counts']['annotations'] ?></strong><span>Notes</span></div><div class="stat"><strong><?= (int)$project['counts']['findings'] ?></strong><span>Findings</span></div><div class="stat"><strong><?= (int)$project['counts']['reports'] ?></strong><span>Reports</span></div></div>
</a><?php endforeach; ?></div><?php endif; ?>
</section>

<section class="section">
<div class="section-head"><div><div class="eyebrow">Inbox</div><h2>Added from Browser Companion</h2></div><span class="count"><?=count($inbox)?> waiting</span></div>
<?php if(!$inbox): ?><div class="empty">Your Research Inbox is clear. Use <strong>Add to Research</strong> from Browser Companion to collect an annotation here.</div><?php else: ?><div class="inbox">
<?php foreach($inbox as $item): ?><article class="card inbox-item">
<div><div class="source-title"><?=research_h_e((string)($item['source_identity']['title']??$item['source']['title']??'Source'))?></div><div class="domain"><?=research_h_e((string)($item['source_identity']['domain']??$item['source']['domain']??''))?></div>
<?php if(!empty($item['selection'])): ?><div class="quote"><?=research_h_e((string)$item['selection'])?></div><?php endif; ?>
<?php if(!empty($item['note'])): ?><div class="annotation-note"><?=research_h_e((string)$item['note'])?></div><?php endif; ?></div>
<div class="assign">
<?php if($researcherProjects): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="assign"><input type="hidden" name="browser_share_id" value="<?=research_h_e((string)$item['id'])?>">
<label class="field"><span>Add to project</span><select name="project_id" required><?php foreach($researcherProjects as $project): ?><option value="<?=research_h_e((string)$project['id'])?>"><?=research_h_e((string)$project['title'])?></option><?php endforeach; ?></select></label>
<label class="field"><span>Project note <small>optional</small></span><input name="note" maxlength="10000" placeholder="Why this matters"></label>
<label class="field"><span>Tags <small>comma separated</small></span><input name="tags" placeholder="pricing, competitor"></label>
<div class="assign-actions"><button class="primary" type="submit">Add to project</button></form>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="remove_inbox"><input type="hidden" name="browser_share_id" value="<?=research_h_e((string)$item['id'])?>"><button type="submit">Dismiss</button></form></div>
<?php else: ?><p class="count">Create a project where you have Researcher access to assign this item.</p><?php endif; ?>
</div></article><?php endforeach; ?></div><?php endif; ?>
</section>
</main></body></html>