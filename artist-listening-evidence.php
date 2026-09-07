<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/artist-listening.php';
require_once __DIR__.'/includes/artist-listening-transcript.php';

$user=current_user();
if(!$user)redirect(url('/login.php'));
if(!has_permission('artist_listening.access',$user)){http_response_code(403);exit('Artist Listening permission is required.');}
$pdo=db();if(!$pdo){http_response_code(503);exit('Database unavailable.');}
$sessionId=max(0,(int)($_GET['session']??0));
$page=max(1,(int)($_GET['page']??1));
$plugin=preg_replace('/[^a-z0-9_-]/','',strtolower((string)($_GET['plugin']??'')))?:'';
$section=preg_replace('/[^a-z0-9_-]/','',strtolower((string)($_GET['section']??'')))?:'';
$item=mb_strimwidth(trim((string)($_GET['item']??'')),0,190,'');
if($sessionId<1){http_response_code(400);exit('A transcription session is required.');}
try{$payload=artist_listening_transcript_page($pdo,$user,$sessionId,$page);}catch(Throwable $e){http_response_code(404);exit('Transcription evidence not found.');}
$session=$payload['session']??[];$pageData=$payload['page']??[];
$title=trim((string)($session['title']??''))?:('Transcript #'.$sessionId);
$workspace=url('/artist-listening.php?session='.$sessionId);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Artist Listening Evidence · <?= e($title) ?></title>
<style>body{margin:0;background:#fff;color:#171717;font:14px/1.55 Inter,system-ui,sans-serif}.wrap{max-width:980px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:24px}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#777}.top h1{margin:4px 0 6px;font-size:26px}.meta{color:#666;font-size:12px}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;padding:9px 12px;border:1px solid #d8d8d8;border-radius:8px;color:#171717;text-decoration:none;font-weight:700;font-size:12px}.btn.primary{background:#171717;color:#fff;border-color:#171717}.source{margin:0 0 18px;padding:12px 14px;border:1px solid #e4e4e4;border-radius:10px;background:#fafafa}.turns{display:grid;gap:10px}.turn{border:1px solid #e8e8e8;border-radius:10px;padding:14px}.turn header{display:flex;justify-content:space-between;gap:12px;color:#666;font-size:11px;margin-bottom:6px}.turn strong{color:#222}.turn p{margin:0;white-space:pre-wrap}.empty{padding:22px;border:1px dashed #ccc;border-radius:10px;color:#777}@media(max-width:700px){.wrap{padding:18px}.top{display:grid}}</style></head><body><main class="wrap">
<div class="top"><div><div class="eyebrow">Source: Artist Listening</div><h1><?= e($title) ?></h1><div class="meta">Evidence page <?= (int)($pageData['page_number']??$page) ?> of <?= (int)($pageData['page_count']??1) ?><?php if($plugin!==''): ?> · <?= e(str_replace('_',' ',$plugin)) ?><?php endif; ?><?php if($section!==''): ?> · <?= e(str_replace('_',' ',$section)) ?><?php endif; ?></div></div><div class="actions"><a class="btn" href="<?= e(url('/chat.php')) ?>">Main Feed</a><a class="btn primary" href="<?= e($workspace) ?>">Open Artist Listening</a></div></div>
<div class="source"><strong>Provenance</strong><div class="meta">Session #<?= $sessionId ?><?php if($item!==''): ?> · Intelligence item <?= e($item) ?><?php endif; ?> · Reviewed source evidence</div></div>
<section class="turns"><?php $shown=0;foreach((array)($pageData['segments']??[]) as $segment):if((string)($segment['segment_type']??'')!=='transcript')continue;$shown++;$seconds=(int)floor(max(0,(int)($segment['started_ms']??0))/1000);?><article class="turn"><header><strong><?= e((string)($segment['speaker_label']??'Speaker')) ?></strong><span><?= e(gmdate('i:s',$seconds)) ?></span></header><p><?= e((string)($segment['transcript_text']??'')) ?></p></article><?php endforeach;?><?php if(!$shown):?><div class="empty">No transcript text is stored on this evidence page.</div><?php endif;?></section>
</main></body></html>