<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=vp3_browser_source_feed_require_ready_v2050(db());
$user=current_user();
$userId=(int)($user['id']??0);
$source=null;
$publishedResearch=[];
$liveRooms=[];
$publicId=trim((string)($_GET['source']??''));
$urlInput=trim((string)($_GET['url']??''));

if($publicId!=='')$source=vp3_browser_source_row_by_public_id_v2050($pdo,$publicId);
if(!$source&&$urlInput!==''){
    try{
        $identity=vp3_browser_source_identity_v2050($urlInput,'','');
        $source=vp3_browser_source_row_by_hash_v2050($pdo,(string)$identity['url_hash']);
    }catch(Throwable $e){}
}
if(!$source){
    http_response_code(404);
    $pageTitle='Source not found';
    $feed=['items'=>[]];
}else{
    // Never use mutable global Source title metadata as the public heading.
    // It may have originated from a personalized/private viewer. Promote only
    // a title pinned to an annotation the current viewer is authorized to see.
    $pageTitle=(string)$source['source_domain'];
    $feed=vp3_browser_source_this_page_v2050(
        $pdo,$userId,(string)$source['normalized_url'],(string)$source['canonical_url'],'',50,''
    );
    if(!empty($feed['items'][0]['source_identity']['title'])){
        $pageTitle=(string)$feed['items'][0]['source_identity']['title'];
    }
    if(vp3_research_schema_ready_v2060($pdo)){
        $publishedResearch=vp3_research_reports_for_source_v2060($pdo,(int)$source['id'],$userId);
    }
    if(vp3_live_room_schema_ready_v2070($pdo)){
        $livePayload=vp3_live_room_rooms_for_source_v2070($pdo,$userId,(string)$source['normalized_url'],(string)$source['canonical_url'],'');
        $liveRooms=(array)($livePayload['rooms']??[]);
    }
    // A private follow alone must not publish source metadata to anonymous web
    // visitors. A public Source page requires authorized annotation/research,
    // or a Live Room that the current viewer may enter.
    if($userId<1 && empty($feed['items']) && empty($publishedResearch) && empty($liveRooms)){
        http_response_code(404);
        $source=null;
        $pageTitle='Source not found';
    }
}
function source_page_e_v2050(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function source_page_media_v2050(array $media): string{
    $html='';
    foreach($media as $asset){
        if(!is_array($asset)||($asset['status']??'')!=='ready')continue;
        $content=trim((string)($asset['content_url']??''));
        $kind=(string)($asset['kind']??'');
        if($content==='' )continue;
        if($kind==='screenshot')$html.='<img class="media-image" src="'.source_page_e_v2050($content).'" alt="Annotation screenshot">';
        elseif($kind==='commentary_audio')$html.='<audio controls src="'.source_page_e_v2050($content).'"></audio>';
    }
    return $html;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=source_page_e_v2050($pageTitle)?> · VP3 Source</title>
<style>
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f5f5f2;color:#171717;font:15px/1.55 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:inherit}.shell{max-width:920px;margin:0 auto;padding:28px 18px 70px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:28px}.brand{font-weight:850;letter-spacing:-.04em;text-decoration:none}.source-head{padding:26px;background:#fff;border:1px solid #e1e1dc;border-radius:20px}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#72726b}.source-head h1{font-size:30px;line-height:1.1;letter-spacing:-.04em;margin:8px 0}.domain,.meta{color:#6d6d66;font-size:13px}.source-url{display:block;margin-top:14px;overflow-wrap:anywhere;color:#555}.list{display:grid;gap:12px;margin-top:20px}.card{padding:18px;background:#fff;border:1px solid #e1e1dc;border-radius:16px}.byline{display:flex;justify-content:space-between;gap:12px;color:#696962;font-size:12px}.quote{margin:14px 0 0;padding:12px 14px;border-left:3px solid #d0d0c8;background:#fafaf7;white-space:pre-wrap}.note{margin:12px 0 0;white-space:pre-wrap}.badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.badge{padding:4px 7px;border-radius:999px;background:#f0f0eb;color:#666;font-size:10px;font-weight:750}.badge.changed{background:#fff1cf;color:#755400}.media{display:grid;gap:8px;margin-top:12px}.media-image{max-width:100%;max-height:500px;object-fit:contain;border-radius:12px;background:#eee}.media audio{width:100%}.actions{margin-top:14px;padding-top:12px;border-top:1px solid #ecece6;font-size:12px}.research-section,.live-section{margin-top:24px}.research-card,.live-card{padding:17px;background:#fff;border:1px solid #e1e1dc;border-radius:16px;margin-top:10px}.research-card strong,.live-card strong{display:block}.research-card p{margin:7px 0;color:#666}.research-meta,.live-meta{font-size:11px;color:#777}.live-card .badges{margin-top:8px}.empty{padding:40px 20px;text-align:center;color:#777;border:1px dashed #d3d3cd;border-radius:16px;margin-top:20px}
</style>
</head>
<body><main class="shell">
<div class="top"><a class="brand" href="<?=source_page_e_v2050(url('/'))?>">VP3</a><span class="meta"><?= $userId>0?'Signed in':'Public source view' ?></span></div>
<?php if(!$source): ?>
<div class="empty">This source is not available.</div>
<?php else: ?>
<section class="source-head">
<div class="eyebrow">Source</div>
<h1><?=source_page_e_v2050($pageTitle)?></h1>
<div class="domain"><?=source_page_e_v2050((string)$source['source_domain'])?></div>
<a class="source-url" rel="noopener noreferrer" target="_blank" href="<?=source_page_e_v2050((string)$source['canonical_url'])?>"><?=source_page_e_v2050((string)$source['canonical_url'])?></a>
</section>
<?php if(!empty($publishedResearch)): ?><section class="research-section"><div class="eyebrow">Published Research</div>
<?php foreach($publishedResearch as $report): ?><article class="research-card"><strong><?=source_page_e_v2050((string)$report['title'])?></strong><?php if(!empty($report['summary'])): ?><p><?=source_page_e_v2050((string)$report['summary'])?></p><?php endif; ?><div class="research-meta"><?=source_page_e_v2050(ucfirst((string)$report['visibility']))?> · <?=source_page_e_v2050((string)$report['published_at'])?></div><div class="actions"><a href="<?=source_page_e_v2050((string)$report['url'])?>">Open Research report</a></div></article><?php endforeach; ?></section><?php endif; ?>
<?php if(!empty($liveRooms)): ?><section class="live-section"><div class="eyebrow">Live now</div>
<?php foreach($liveRooms as $room): ?><article class="live-card"><strong><?=source_page_e_v2050((string)$room['title'])?></strong><div class="live-meta"><?=source_page_e_v2050(ucfirst((string)$room['scope']))?> · <?=(int)$room['participant_count']?> present</div><div class="badges"><?php if(!empty($room['allow_cloak'])): ?><span class="badge">Cloak available</span><?php endif; ?><span class="badge"><?=source_page_e_v2050(ucfirst((string)$room['status']))?></span></div><div class="actions"><a href="<?=source_page_e_v2050((string)$room['url'])?>">Join Live Room</a></div></article><?php endforeach; ?></section><?php endif; ?>
<div class="list">
<?php foreach($feed['items']??[] as $item): ?>
<article class="card">
<div class="byline"><strong><?=source_page_e_v2050((string)($item['sender']['name']??'VP3 user'))?></strong><span><?=source_page_e_v2050((string)($item['publication']['published_at']??$item['created_at']??''))?></span></div>
<?php if(trim((string)($item['selection']??''))!==''): ?><div class="quote"><?=source_page_e_v2050((string)$item['selection'])?></div><?php endif; ?>
<?php if(trim((string)($item['note']??''))!==''): ?><div class="note"><?=source_page_e_v2050((string)$item['note'])?></div><?php endif; ?>
<div class="badges">
<span class="badge"><?=source_page_e_v2050(ucfirst((string)($item['publication']['visibility']??'shared')))?></span>
<?php if(!empty($item['source_version']['badge'])): ?><span class="badge<?=!empty($item['source_version']['changed'])?' changed':''?>"><?=source_page_e_v2050((string)$item['source_version']['badge'])?></span><?php endif; ?>
<?php if(!empty($item['comment_count'])): ?><span class="badge"><?= (int)$item['comment_count'] ?> comments</span><?php endif; ?>
</div>
<?php $mediaHtml=source_page_media_v2050($item['media']??[]); if($mediaHtml!==''): ?><div class="media"><?=$mediaHtml?></div><?php endif; ?>
<div class="actions"><a href="<?=source_page_e_v2050((string)$item['annotation_url'])?>">Open annotation context</a></div>
</article>
<?php endforeach; ?>
<?php if(empty($feed['items'])): ?><div class="empty">No annotations are visible to you on this source.</div><?php endif; ?>
</div>
<?php endif; ?>
</main></body></html>