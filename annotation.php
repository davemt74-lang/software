<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=vp3_browser_source_feed_require_ready_v2050(db());
$user=current_user();
$userId=(int)($user['id']??0);
$publicId=trim((string)($_GET['id']??$_POST['id']??''));
$row=$publicId!==''?vp3_browser_source_share_row_v2050($pdo,$publicId):null;
if(!$row||!vp3_browser_source_share_authorized_v2050($pdo,$row,$userId)){
    http_response_code(404);
    $item=null;
}else{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if($userId<1){http_response_code(401);}
        elseif(!verify_csrf()){http_response_code(419);}
        else{
            try{
                $action=trim((string)($_POST['action']??''));
                if($action==='comment')vp3_browser_source_comment_v2050($pdo,$userId,$publicId,(string)($_POST['body']??''),trim((string)($_POST['parent_id']??'')));
                elseif($action==='save')vp3_browser_source_toggle_share_state_v2050($pdo,$userId,$publicId,'save',(string)($_POST['enabled']??'1')==='1');
                elseif($action==='research')vp3_browser_source_toggle_share_state_v2050($pdo,$userId,$publicId,'research',(string)($_POST['enabled']??'1')==='1');
                elseif($action==='follow_source')vp3_browser_source_follow_source_v2050($pdo,$userId,(string)($row['source_public_id']??''),(string)($_POST['enabled']??'1')==='1');
                elseif($action==='follow_user')vp3_browser_source_follow_user_v2050($pdo,$userId,(int)$row['sender_user_id'],(string)($_POST['enabled']??'1')==='1');
                header('Location: '.url('/annotation.php?id='.rawurlencode($publicId)),true,303);exit;
            }catch(Throwable $e){$pageError=$e->getMessage();}
        }
    }
    $row=vp3_browser_source_share_row_v2050($pdo,$publicId);
    $item=$row?vp3_browser_source_item_v2050($pdo,$row,$userId,true):null;
}
function annotation_page_e_v2050(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=annotation_page_e_v2050((string)($item['source_identity']['title']??'Annotation'))?> · VP3</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f5f2;color:#171717;font:15px/1.55 Inter,system-ui,sans-serif}a{color:inherit}.shell{max-width:780px;margin:0 auto;padding:26px 18px 70px}.top{display:flex;justify-content:space-between;margin-bottom:24px}.brand{font-weight:850;text-decoration:none}.card{padding:22px;background:#fff;border:1px solid #e1e1db;border-radius:18px}.eyebrow{font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#777}.title{font-size:24px;line-height:1.15;letter-spacing:-.035em;margin:7px 0}.meta{color:#6c6c66;font-size:12px}.quote{margin:18px 0 0;padding:14px;border-left:3px solid #ccc;background:#fafaf7;white-space:pre-wrap}.note{margin-top:14px;white-space:pre-wrap}.badges,.actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:14px}.badge,.actions button,.actions a{border:0;border-radius:999px;background:#f0f0eb;padding:6px 9px;font:inherit;font-size:11px;text-decoration:none;cursor:pointer}.comments{margin-top:22px;display:grid;gap:8px}.comment{padding:10px;background:#f7f7f3;border-radius:10px}.reply{margin-left:20px}.comment strong{font-size:11px}.comment p{margin:3px 0 0}.compose{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:12px}.compose input{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px}.compose button{padding:10px 14px}.error{margin-bottom:12px;padding:10px;background:#fff0f0;color:#8a2525;border-radius:10px}.empty{text-align:center;color:#777;padding:50px}
</style></head><body><main class="shell">
<div class="top"><a class="brand" href="<?=annotation_page_e_v2050(url('/'))?>">VP3</a><?php if($item): ?><a href="<?=annotation_page_e_v2050((string)$item['source_identity']['page_url'])?>">Source page</a><?php endif; ?></div>
<?php if(!$item): ?><div class="empty">This annotation is not available.</div><?php else: ?>
<?php if(!empty($pageError)): ?><div class="error"><?=annotation_page_e_v2050((string)$pageError)?></div><?php endif; ?>
<article class="card">
<div class="eyebrow">Annotation</div><h1 class="title"><?=annotation_page_e_v2050((string)($item['source_identity']['title']??'Source'))?></h1>
<div class="meta"><?=annotation_page_e_v2050((string)($item['sender']['name']??'VP3 user'))?> · <?=annotation_page_e_v2050((string)($item['publication']['published_at']??$item['created_at']??''))?></div>
<?php if(!empty($item['selection'])): ?><div class="quote"><?=annotation_page_e_v2050((string)$item['selection'])?></div><?php endif; ?>
<?php if(!empty($item['note'])): ?><div class="note"><?=annotation_page_e_v2050((string)$item['note'])?></div><?php endif; ?>
<div class="badges"><span class="badge"><?=annotation_page_e_v2050(ucfirst((string)($item['publication']['visibility']??'shared')))?></span><span class="badge"><?=annotation_page_e_v2050((string)($item['source_version']['badge']??'Captured snapshot'))?></span></div>
<div class="actions">
<a target="_blank" rel="noopener noreferrer" href="<?=annotation_page_e_v2050((string)$item['source_identity']['canonical_url'])?>">Open original source</a>
<?php if($userId>0): ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=annotation_page_e_v2050(csrf_token())?>"><input type="hidden" name="id" value="<?=annotation_page_e_v2050($publicId)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="enabled" value="<?=!empty($item['interactions']['saved'])?'0':'1'?>"><button><?=!empty($item['interactions']['saved'])?'Unsave':'Save'?></button></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=annotation_page_e_v2050(csrf_token())?>"><input type="hidden" name="id" value="<?=annotation_page_e_v2050($publicId)?>"><input type="hidden" name="action" value="research"><input type="hidden" name="enabled" value="<?=!empty($item['interactions']['in_research'])?'0':'1'?>"><button><?=!empty($item['interactions']['in_research'])?'Remove from Research':'Add to Research'?></button></form>
<a href="<?=annotation_page_e_v2050(url('/browser-share-agent-handoff.php?browser_share_id='.rawurlencode($publicId)))?>">Ask VP3</a>
<?php endif; ?>
</div>
<section class="comments"><div class="eyebrow">Comments</div>
<?php foreach($item['comments']??[] as $comment): ?><div class="comment<?=!empty($comment['parent_id'])?' reply':''?>"><strong><?=annotation_page_e_v2050((string)($comment['user']['name']??'VP3 user'))?></strong><p><?=annotation_page_e_v2050((string)$comment['body'])?></p></div><?php endforeach; ?>
<?php if($userId>0): ?><form class="compose" method="post"><input type="hidden" name="csrf_token" value="<?=annotation_page_e_v2050(csrf_token())?>"><input type="hidden" name="id" value="<?=annotation_page_e_v2050($publicId)?>"><input type="hidden" name="action" value="comment"><input name="body" maxlength="4000" required placeholder="Add a comment…"><button>Comment</button></form><?php endif; ?>
</section></article>
<?php endif; ?></main></body></html>