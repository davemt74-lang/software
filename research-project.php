<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

if(!is_logged_in())redirect(url('/login.php'));
$user=current_user();$pdo=db();
if(!$user||!$pdo)redirect(url('/login.php'));
if(!vp3_research_schema_ready_v2060($pdo))redirect(url('/upgrade.php'));
$uid=(int)$user['id'];$projectId=trim((string)($_GET['project']??$_POST['project_id']??''));
if($projectId==='')redirect(url('/research.php'));
$notice=flash('research_project_notice');$error=flash('research_project_error');

function research_project_redirect_v2060(string $projectId,string $anchor=''): never
{
    redirect(url('/research-project.php?project='.rawurlencode($projectId).($anchor!==''?'#'.$anchor:'')));
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()){flash('research_project_error','Session expired. Try again.');research_project_redirect_v2060($projectId);}
    try{
        $action=(string)($_POST['action']??'');
        if($action==='update_project'){
            vp3_research_update_project_v2060($pdo,$uid,$projectId,['title'=>$_POST['title']??'','description'=>$_POST['description']??'','status'=>$_POST['status']??'active']);
            flash('research_project_notice','Project updated.');research_project_redirect_v2060($projectId);
        }
        if($action==='set_member'){
            vp3_research_set_member_v2060($pdo,$uid,$projectId,max(0,(int)($_POST['user_id']??0)),(string)($_POST['role']??'viewer'));
            flash('research_project_notice','Project member updated.');research_project_redirect_v2060($projectId,'members');
        }
        if($action==='remove_member'){
            vp3_research_remove_member_v2060($pdo,$uid,$projectId,max(0,(int)($_POST['user_id']??0)));
            flash('research_project_notice','Project member removed.');research_project_redirect_v2060($projectId,'members');
        }
        if($action==='create_finding'){
            vp3_research_create_finding_v2060($pdo,$uid,$projectId,(string)($_POST['title']??''),(string)($_POST['body']??''),trim((string)($_POST['evidence_item_id']??'')),(string)($_POST['evidence_role']??'support'));
            flash('research_project_notice','Finding created.');research_project_redirect_v2060($projectId,'findings');
        }
        if($action==='finding_status'){
            vp3_research_set_finding_status_v2060($pdo,$uid,$projectId,trim((string)($_POST['finding_id']??'')),(string)($_POST['status']??'draft'));
            flash('research_project_notice','Finding status updated.');research_project_redirect_v2060($projectId,'findings');
        }
        if($action==='link_evidence'){
            vp3_research_link_evidence_v2060($pdo,$uid,$projectId,trim((string)($_POST['finding_id']??'')),trim((string)($_POST['item_id']??'')),(string)($_POST['role']??'support'),(string)($_POST['note']??''));
            flash('research_project_notice','Evidence linked.');research_project_redirect_v2060($projectId,'findings');
        }
        if($action==='create_report'){
            $report=vp3_research_create_report_v2060($pdo,$uid,$projectId,(string)($_POST['title']??''),(string)($_POST['summary']??''));
            flash('research_project_notice','Report draft created.');
            redirect(url('/research-report-builder.php?project='.rawurlencode($projectId).'&report='.rawurlencode((string)$report['id'])));
        }
    }catch(Throwable $e){flash('research_project_error',$e->getMessage());research_project_redirect_v2060($projectId);}
}

try{$bundle=vp3_research_project_bundle_v2060($pdo,$projectId,$uid);}
catch(Throwable $e){http_response_code(404);exit('Research project was not found.');}
$project=$bundle['project'];$role=(string)$project['role'];
$canResearch=vp3_research_role_at_least_v2060($role,'researcher');$canAdmin=vp3_research_role_at_least_v2060($role,'admin');
$sources=array_values(array_filter($bundle['items'],static fn(array $i): bool=>($i['type']??'')==='source'));
$annotations=array_values(array_filter($bundle['items'],static fn(array $i): bool=>($i['type']??'')==='annotation'));
$allEvidenceItems=$bundle['items'];
$findings=$bundle['findings'];$reports=$bundle['reports'];$members=$bundle['members'];

function research_p_e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function research_p_excerpt(string $v,int $limit=320): string{$v=trim($v);return mb_strlen($v)>$limit?mb_substr($v,0,$limit).'…':$v;}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=research_p_e((string)$project['title'])?> · Research · VP3</title>
<style>
:root{--bg:#f4f4f1;--panel:#fff;--line:#dfdfd8;--text:#171717;--muted:#707068;--soft:#f1f1ec;--green:#eaf7ed;--amber:#fff3d7}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:inherit}.shell{max-width:1240px;margin:0 auto;padding:26px 20px 70px}.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}.brand{font-weight:900;text-decoration:none}.nav{display:flex;gap:14px;color:var(--muted);font-size:12px}.project-head{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;padding:24px;background:#fff;border:1px solid var(--line);border-radius:20px}.eyebrow{font-size:10px;font-weight:850;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}h1{margin:7px 0 8px;font-size:34px;line-height:1.08;letter-spacing:-.04em}.description{max-width:760px;color:var(--muted)}.role{display:inline-flex;margin-top:12px;padding:5px 8px;border-radius:999px;background:var(--soft);font-size:10px}.stats{display:grid;grid-template-columns:repeat(4,82px);gap:7px}.stat{padding:11px;border-radius:12px;background:var(--soft);text-align:center}.stat strong{display:block;font-size:18px}.stat span{font-size:9px;text-transform:uppercase;color:var(--muted)}.layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:18px;margin-top:18px}.main{display:grid;gap:18px}.side{display:grid;gap:12px;align-content:start}.card,.section{background:#fff;border:1px solid var(--line);border-radius:18px;padding:18px}.section-head{display:flex;justify-content:space-between;gap:12px;align-items:end;margin-bottom:12px}.section h2,.card h2{margin:0;font-size:18px;letter-spacing:-.025em}.muted{color:var(--muted);font-size:12px}.notice,.error{margin-bottom:15px;padding:11px 13px;border-radius:11px}.notice{background:var(--green);color:#285c34}.error{background:#fff0f0;color:#8b2929}.source-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.source{padding:13px;border:1px solid var(--line);border-radius:13px}.source-title{font-weight:800}.domain{color:var(--muted);font-size:11px}.version{margin-top:8px;font-size:10px;color:var(--muted)}.annotation{padding:13px;border:1px solid var(--line);border-radius:13px;margin-top:9px}.quote{margin-top:8px;padding:9px 10px;border-left:3px solid #d0d0c8;background:#fafaf7;white-space:pre-wrap}.annotation-note{margin-top:8px}.tags{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.tag,.badge{padding:4px 7px;border-radius:999px;background:var(--soft);font-size:9px;color:var(--muted)}.finding{padding:14px;border:1px solid var(--line);border-radius:14px;margin-top:10px}.finding-head{display:flex;justify-content:space-between;gap:10px}.finding-title{font-weight:850}.finding-body{margin-top:8px;white-space:pre-wrap}.status-confirmed{background:var(--green);color:#285c34}.status-published{background:#e8efff;color:#315a91}.evidence{margin-top:11px;display:grid;gap:6px}.evidence-item{padding:8px 9px;background:#f8f8f5;border-radius:9px;font-size:11px}.actions,.inline{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}input,textarea,select,button{font:inherit}input,textarea,select{width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:9px;background:#fff}textarea{resize:vertical}.field{display:block;margin-top:10px}.field span{display:block;margin-bottom:5px;font-size:11px;font-weight:750}button,.button{border:1px solid var(--line);border-radius:9px;background:#fff;padding:8px 10px;font-weight:700;cursor:pointer;text-decoration:none}.primary{background:#171717;color:#fff;border-color:#171717}.report{display:flex;justify-content:space-between;gap:14px;padding:12px 0;border-top:1px solid var(--line)}.report:first-of-type{border-top:0}.empty{padding:26px;border:1px dashed #d2d2cc;border-radius:13px;text-align:center;color:var(--muted)}.member{display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-top:1px solid var(--line);font-size:12px}.member:first-of-type{border-top:0}@media(max-width:900px){.layout{grid-template-columns:1fr}.project-head{display:block}.stats{margin-top:18px;grid-template-columns:repeat(4,1fr)}.source-grid{grid-template-columns:1fr}}@media(max-width:560px){.shell{padding:18px 12px 60px}.stats{grid-template-columns:1fr 1fr}.project-head{padding:18px}}
</style></head><body><main class="shell">
<div class="top"><a class="brand" href="<?=research_p_e(url('/'))?>">VP3</a><nav class="nav"><a href="<?=research_p_e(url('/research.php'))?>">Research Hub</a><a href="<?=research_p_e(url('/knowledge.php'))?>">Knowledge</a></nav></div>
<?php if($notice): ?><div class="notice"><?=research_p_e($notice)?></div><?php endif; ?><?php if($error): ?><div class="error"><?=research_p_e($error)?></div><?php endif; ?>
<header class="project-head"><div><div class="eyebrow">Research project</div><h1><?=research_p_e((string)$project['title'])?></h1><div class="description"><?=research_p_e((string)$project['description'])?></div><span class="role"><?=research_p_e(ucfirst($role))?> · <?=research_p_e(ucfirst((string)$project['status']))?></span></div>
<div class="stats"><div class="stat"><strong><?=count($sources)?></strong><span>Sources</span></div><div class="stat"><strong><?=count($annotations)?></strong><span>Notes</span></div><div class="stat"><strong><?=count($findings)?></strong><span>Findings</span></div><div class="stat"><strong><?=count($reports)?></strong><span>Reports</span></div></div></header>

<div class="layout"><div class="main">
<section class="section" id="sources"><div class="section-head"><div><div class="eyebrow">Evidence base</div><h2>Sources</h2></div><span class="muted"><?=count($sources)?> canonical sources</span></div>
<?php if(!$sources): ?><div class="empty">Assign an annotation from the Research Inbox. Its canonical Source is added automatically.</div><?php else: ?><div class="source-grid"><?php foreach($sources as $item): ?><article class="source"><div class="source-title"><?=research_p_e((string)($item['source']['title']?:$item['source']['domain']))?></div><div class="domain"><?=research_p_e((string)$item['source']['domain'])?></div><div class="version"><?=research_p_e((string)($item['source']['version']['basis']??''))?> · <?=research_p_e(substr((string)($item['source']['version']['hash']??''),0,12))?></div><div class="actions"><?php if(!empty($item['source']['url'])): ?><a class="button" target="_blank" rel="noopener noreferrer" href="<?=research_p_e((string)$item['source']['url'])?>">Open source</a><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?>
</section>

<section class="section" id="annotations"><div class="section-head"><div><div class="eyebrow">Captured context</div><h2>Annotations</h2></div><span class="muted"><?=count($annotations)?> items</span></div>
<?php if(!$annotations): ?><div class="empty">No annotations have been assigned to this project.</div><?php else: ?><?php foreach($annotations as $item): $a=$item['annotation']??null; ?><article class="annotation"><div class="source-title"><?=research_p_e((string)($item['source']['title']?:$item['source']['domain']))?></div>
<?php if($a&&!empty($a['selection'])): ?><div class="quote"><?=research_p_e((string)$a['selection'])?></div><?php endif; ?><?php if($a&&!empty($a['note'])): ?><div class="annotation-note"><?=research_p_e((string)$a['note'])?></div><?php endif; ?>
<?php if(!empty($item['note'])): ?><div class="annotation-note"><strong>Project note:</strong> <?=research_p_e((string)$item['note'])?></div><?php endif; ?><div class="tags"><?php foreach($item['tags']??[] as $tag): ?><span class="tag"><?=research_p_e((string)$tag)?></span><?php endforeach; ?></div></article><?php endforeach; ?><?php endif; ?>
</section>

<section class="section" id="findings"><div class="section-head"><div><div class="eyebrow">Analysis</div><h2>Findings</h2></div><span class="muted">Draft → Confirmed → Published</span></div>
<?php if(!$findings): ?><div class="empty">No Findings yet. Findings turn evidence into a statement you can confirm and publish.</div><?php endif; ?>
<?php foreach($findings as $finding): ?><article class="finding"><div class="finding-head"><div><div class="finding-title"><?=research_p_e((string)$finding['title'])?></div><div class="muted">Revision <?= (int)$finding['revision'] ?></div></div><span class="badge status-<?=research_p_e((string)$finding['status'])?>"><?=research_p_e(ucfirst((string)$finding['status']))?></span></div><div class="finding-body"><?=research_p_e((string)$finding['body'])?></div>
<?php if(!empty($finding['evidence'])): ?><div class="evidence"><?php foreach($finding['evidence'] as $ev): ?><div class="evidence-item"><strong><?=research_p_e(ucfirst((string)$ev['role']))?>:</strong> <?=research_p_e((string)($ev['item']['source']['title']?:$ev['item']['source']['domain']))?><?php if(!empty($ev['note'])): ?> · <?=research_p_e((string)$ev['note'])?><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if($canResearch&&(string)$finding['status']!=='published'): ?><div class="actions"><form method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_p_e($projectId)?>"><input type="hidden" name="action" value="finding_status"><input type="hidden" name="finding_id" value="<?=research_p_e((string)$finding['id'])?>"><input type="hidden" name="status" value="<?=(string)$finding['status']==='confirmed'?'draft':'confirmed'?>"><button type="submit"><?=(string)$finding['status']==='confirmed'?'Return to draft':'Confirm Finding'?></button></form></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_p_e($projectId)?>"><input type="hidden" name="action" value="link_evidence"><input type="hidden" name="finding_id" value="<?=research_p_e((string)$finding['id'])?>"><div class="inline"><select name="item_id" required><option value="">Add evidence…</option><?php foreach($allEvidenceItems as $item): ?><option value="<?=research_p_e((string)$item['id'])?>"><?=research_p_e(ucfirst((string)$item['type']).' · '.(string)($item['source']['title']?:$item['source']['domain']))?></option><?php endforeach; ?></select><select name="role"><option value="support">Supporting</option><option value="conflict">Conflicting</option><option value="context">Context</option></select><button type="submit">Link</button></div></form><?php endif; ?>
</article><?php endforeach; ?>
</section>

<section class="section" id="reports"><div class="section-head"><div><div class="eyebrow">Publishing</div><h2>Reports</h2></div><span class="muted">Immutable published versions</span></div>
<?php if(!$reports): ?><div class="empty">No report drafts yet.</div><?php endif; ?><?php foreach($reports as $report): ?><div class="report"><div><strong><?=research_p_e((string)$report['title'])?></strong><div class="muted"><?=research_p_e(ucfirst((string)$report['status']))?> · v<?= (int)$report['current_version'] ?> · <?=research_p_e(ucfirst((string)$report['visibility']))?></div></div><div class="actions"><a class="button" href="<?=research_p_e(url('/research-report-builder.php?project='.rawurlencode($projectId).'&report='.rawurlencode((string)$report['id'])))?>">Build</a><?php if((string)$report['status']==='published'): ?><a class="button" href="<?=research_p_e((string)$report['url'])?>">View</a><?php endif; ?></div></div><?php endforeach; ?>
</section>
</div>

<aside class="side">
<?php if($canResearch): ?><form class="card" method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_p_e($projectId)?>"><input type="hidden" name="action" value="create_finding"><div class="eyebrow">New Finding</div><h2>Turn evidence into a claim</h2><label class="field"><span>Finding title</span><input name="title" maxlength="190" required></label><label class="field"><span>Statement / analysis</span><textarea name="body" rows="5"></textarea></label><label class="field"><span>Start with evidence</span><select name="evidence_item_id"><option value="">None yet</option><?php foreach($allEvidenceItems as $item): ?><option value="<?=research_p_e((string)$item['id'])?>"><?=research_p_e(ucfirst((string)$item['type']).' · '.(string)($item['source']['title']?:$item['source']['domain']))?></option><?php endforeach; ?></select></label><label class="field"><span>Evidence role</span><select name="evidence_role"><option value="support">Supporting</option><option value="conflict">Conflicting</option><option value="context">Context</option></select></label><button class="primary" type="submit">Create Finding</button></form>
<form class="card" method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_p_e($projectId)?>"><input type="hidden" name="action" value="create_report"><div class="eyebrow">New report</div><h2>Publish selected research</h2><label class="field"><span>Report title</span><input name="title" maxlength="190" required></label><label class="field"><span>Summary</span><textarea name="summary" rows="4"></textarea></label><button class="primary" type="submit">Create report draft</button></form><?php endif; ?>

<?php if($canAdmin): ?><form class="card" method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_p_e($projectId)?>"><input type="hidden" name="action" value="update_project"><div class="eyebrow">Project settings</div><h2>Project</h2><label class="field"><span>Title</span><input name="title" value="<?=research_p_e((string)$project['title'])?>" maxlength="190" required></label><label class="field"><span>Description</span><textarea name="description" rows="4"><?=research_p_e((string)$project['description'])?></textarea></label><label class="field"><span>Status</span><select name="status"><option value="active" <?=(string)$project['status']==='active'?'selected':''?>>Active</option><option value="archived" <?=(string)$project['status']==='archived'?'selected':''?>>Archived</option></select></label><button type="submit">Save project</button></form>
<section class="card" id="members"><div class="eyebrow">Access</div><h2>Members</h2><?php foreach($members as $member): ?><div class="member"><span><?=research_p_e((string)$member['name'])?> · <?=research_p_e(ucfirst((string)$member['role']))?></span><?php if((string)$member['role']!=='owner'): ?><form method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_p_e($projectId)?>"><input type="hidden" name="action" value="remove_member"><input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>"><button type="submit">Remove</button></form><?php endif; ?></div><?php endforeach; ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=research_p_e($projectId)?>"><input type="hidden" name="action" value="set_member"><label class="field"><span>VP3 user ID</span><input type="number" min="1" name="user_id" required></label><label class="field"><span>Role</span><select name="role"><option value="viewer">Viewer</option><option value="researcher">Researcher</option><option value="admin">Admin</option></select></label><button type="submit">Add / update member</button></form></section><?php endif; ?>
</aside></div>
</main></body></html>