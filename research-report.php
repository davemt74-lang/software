<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=db();
if(!$pdo||!vp3_research_schema_ready_v2060($pdo)){http_response_code(503);exit('Research publishing is unavailable.');}
$user=current_user();$uid=(int)($user['id']??0);
$reportId=trim((string)($_GET['report']??''));
$versionNo=max(0,(int)($_GET['version']??0));
$report=$reportId!==''?vp3_research_report_row_v2060($pdo,$reportId):null;
$version=$report?vp3_research_report_version_v2060($pdo,$report,$uid,$versionNo):null;
if(!$report||!$version){http_response_code(404);$snapshot=null;}
else $snapshot=$version['snapshot'];

function research_r_e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function research_r_source_label(array $source): string{return trim((string)($source['title']??''))?:trim((string)($source['domain']??''))?:'Source';}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=research_r_e((string)($snapshot['report']['title']??'Research report'))?> · VP3 Research</title>
<style>
:root{--bg:#f4f4f1;--panel:#fff;--line:#dfdfd8;--text:#171717;--muted:#6f6f67;--soft:#f0f0eb}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:inherit}.shell{max-width:920px;margin:0 auto;padding:30px 20px 80px}.top{display:flex;justify-content:space-between;gap:12px;margin-bottom:30px}.brand{font-weight:900;letter-spacing:-.04em;text-decoration:none}.meta{color:var(--muted);font-size:12px}.hero{padding:30px;background:#fff;border:1px solid var(--line);border-radius:22px}.eyebrow{font-size:10px;font-weight:850;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}h1{margin:8px 0 12px;font-size:38px;line-height:1.08;letter-spacing:-.045em}.summary{font-size:17px;color:#505049;white-space:pre-wrap}.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:18px}.chip{padding:5px 8px;border-radius:999px;background:var(--soft);font-size:10px;color:var(--muted)}.section{margin-top:30px}.section-head{display:flex;justify-content:space-between;align-items:end;margin-bottom:10px}.section h2{margin:0;font-size:21px;letter-spacing:-.03em}.finding,.source,.annotation{padding:18px;background:#fff;border:1px solid var(--line);border-radius:16px;margin-top:10px}.finding h3{margin:0;font-size:18px}.finding-body{margin-top:10px;white-space:pre-wrap}.evidence{display:grid;gap:7px;margin-top:14px}.evidence-item{padding:10px;border-radius:10px;background:#f8f8f5}.evidence-role{font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:800}.source-title{font-weight:800}.domain,.version{font-size:11px;color:var(--muted)}.source a{display:inline-flex;margin-top:8px;font-size:12px}.quote{margin-top:9px;padding:11px 12px;border-left:3px solid #d0d0c8;background:#fafaf7;white-space:pre-wrap}.note{margin-top:9px}.redacted{color:var(--muted);font-style:italic}.integrity{margin-top:30px;padding:14px 16px;border-radius:13px;background:#ecece7;color:#606059;font-size:11px}.hash{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.empty{padding:24px;border:1px dashed #d1d1cb;border-radius:13px;color:var(--muted);text-align:center}@media(max-width:600px){.shell{padding:20px 13px 60px}.hero{padding:20px}h1{font-size:31px}}
</style></head><body><main class="shell">
<div class="top"><a class="brand" href="<?=research_r_e(url('/'))?>">VP3</a><span class="meta"><?= $uid>0?'Research viewer':'Public Research' ?></span></div>
<?php if(!$snapshot): ?><div class="empty">This Research report is not available.</div><?php else: ?>
<header class="hero"><div class="eyebrow">Research report</div><h1><?=research_r_e((string)$snapshot['report']['title'])?></h1><div class="summary"><?=research_r_e((string)($snapshot['report']['summary']??''))?></div><div class="chips"><span class="chip"><?=research_r_e(ucfirst((string)$version['visibility']))?></span><span class="chip">Version <?= (int)$version['version'] ?></span><span class="chip">Published <?=research_r_e((string)$version['published_at'])?></span></div></header>

<section class="section"><div class="section-head"><div><div class="eyebrow">Conclusions</div><h2>Findings</h2></div><span class="meta"><?=count($snapshot['findings']??[])?></span></div>
<?php if(empty($snapshot['findings'])): ?><div class="empty">This version contains no Findings.</div><?php endif; ?>
<?php foreach($snapshot['findings']??[] as $finding): ?><article class="finding"><h3><?=research_r_e((string)$finding['title'])?></h3><div class="meta">Revision <?= (int)($finding['revision']??1) ?></div><div class="finding-body"><?=research_r_e((string)($finding['body']??''))?></div>
<?php if(!empty($finding['evidence'])): ?><div class="evidence"><?php foreach($finding['evidence'] as $ev): $src=(array)($ev['source']??[]); ?><div class="evidence-item"><div class="evidence-role"><?=research_r_e((string)($ev['role']??'evidence'))?></div><strong><?=research_r_e(research_r_source_label($src))?></strong><?php if(!empty($ev['note'])): ?><div><?=research_r_e((string)$ev['note'])?></div><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?></article><?php endforeach; ?></section>

<section class="section"><div class="section-head"><div><div class="eyebrow">Provenance</div><h2>Sources</h2></div><span class="meta"><?=count($snapshot['sources']??[])?></span></div>
<?php foreach($snapshot['sources']??[] as $source): ?><article class="source"><div class="source-title"><?=research_r_e(research_r_source_label((array)$source))?></div><div class="domain"><?=research_r_e((string)($source['domain']??''))?></div><?php if(!empty($source['version']['hash'])): ?><div class="version">Pinned version <?=research_r_e(substr((string)$source['version']['hash'],0,16))?> · <?=research_r_e((string)($source['version']['captured_at']??''))?></div><?php endif; ?><?php if(!empty($source['url'])): ?><a target="_blank" rel="noopener noreferrer" href="<?=research_r_e((string)$source['url'])?>">Open original source</a><?php endif; ?></article><?php endforeach; ?>
<?php if(empty($snapshot['sources'])): ?><div class="empty">No Sources are listed in this version.</div><?php endif; ?></section>

<?php if(!empty($snapshot['annotations'])): ?><section class="section"><div class="section-head"><div><div class="eyebrow">Published context</div><h2>Annotations</h2></div><span class="meta"><?=count($snapshot['annotations'])?></span></div>
<?php foreach($snapshot['annotations'] as $annotation): ?><article class="annotation"><div class="source-title"><?=research_r_e(research_r_source_label((array)($annotation['source']??[])))?></div><?php if(!empty($annotation['redacted_to_source'])): ?><div class="redacted">Annotation text was not compatible with this report’s visibility. The pinned Source remains in the provenance record.</div><?php else: ?><?php if(!empty($annotation['selection'])): ?><div class="quote"><?=research_r_e((string)$annotation['selection'])?></div><?php endif; ?><?php if(!empty($annotation['note'])): ?><div class="note"><?=research_r_e((string)$annotation['note'])?></div><?php endif; ?><?php endif; ?></article><?php endforeach; ?></section><?php endif; ?>

<div class="integrity"><div><strong>Immutable version <?= (int)$version['version'] ?></strong></div><div class="hash">SHA-256 <?=research_r_e((string)$version['sha256'])?></div><?php if((int)$version['version']>1): ?><div><a href="<?=research_r_e(url('/research-report.php?report='.rawurlencode($reportId).'&version='.((int)$version['version']-1)))?>">Previous version</a></div><?php endif; ?></div>
<?php endif; ?></main></body></html>