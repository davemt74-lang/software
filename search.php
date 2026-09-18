<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$pdo=vp3_search_require_ready_v2090(db());$user=current_user();$userId=(int)($user['id']??0);
$q=trim((string)($_GET['q']??$_POST['q']??''));$notice='';$error='';
$rawFilters=[
    'type'=>(string)($_GET['type']??$_POST['type']??''),
    'visibility'=>(string)($_GET['visibility']??$_POST['visibility']??''),
    'domain'=>(string)($_GET['domain']??$_POST['domain']??''),
    'team_id'=>(int)($_GET['team_id']??$_POST['team_id']??0),
    'author_id'=>(int)($_GET['author_id']??$_POST['author_id']??0),
    'claim_status'=>(string)($_GET['claim_status']??$_POST['claim_status']??''),
    'changed'=>(string)($_GET['changed']??$_POST['changed']??''),
    'date_from'=>(string)($_GET['date_from']??$_POST['date_from']??''),
    'date_to'=>(string)($_GET['date_to']??$_POST['date_to']??''),
    'context_source_id'=>(string)($_GET['source']??$_GET['context_source_id']??$_POST['context_source_id']??''),
    'context_domain'=>(string)($_GET['context_domain']??$_POST['context_domain']??''),
    'context_only'=>!empty($_GET['context_only'])||!empty($_POST['context_only']),
];
$filters=vp3_search_filters_v2090($rawFilters);
function search_page_url_v2090(string $q,array $filters): string{
    $params=['q'=>$q];
    foreach(['type','visibility','domain','team_id','author_id','claim_status','changed','date_from','date_to','context_source_id','context_domain','context_only'] as $key){
        $value=$filters[$key]??'';if($key==='types')continue;if($key==='type')$value=($filters['types'][0]??'');
        if($value!==''&&$value!==0&&$value!==false)$params[$key]=$value===true?'1':$value;
    }
    if(!empty($filters['types'][0]))$params['type']=$filters['types'][0];
    if(!empty($filters['context_source_id'])){$params['source']=$filters['context_source_id'];unset($params['context_source_id']);}
    return url('/search.php?'.http_build_query($params));
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if($userId<1){$error='Sign in to manage search actions.';}
    elseif(!verify_csrf()){$error='Session expired.';}
    else try{
        $action=(string)($_POST['action']??'');
        if($action==='save_search'){
            vp3_search_save_query_v2090($pdo,$userId,(string)($_POST['name']??''),$q,$rawFilters);$notice='Search saved.';
        }elseif($action==='delete_saved'){
            vp3_search_delete_saved_v2090($pdo,$userId,(string)($_POST['saved_id']??''));$notice='Saved search removed.';
        }elseif($action==='clear_recent'){
            vp3_search_clear_recent_v2090($pdo,$userId);$notice='Recent searches cleared.';
        }elseif($action==='annotation_state'){
            vp3_browser_source_toggle_share_state_v2050($pdo,$userId,(string)($_POST['target_id']??''),(string)($_POST['kind']??'save'),(string)($_POST['enabled']??'1')==='1');$notice='Annotation updated.';
        }elseif($action==='follow_source'){
            vp3_browser_source_follow_source_v2050($pdo,$userId,(string)($_POST['source_id']??''),(string)($_POST['enabled']??'1')==='1');$notice='Source follow updated.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$options=vp3_search_filter_options_v2090($pdo,$userId);
$search=$q!==''||array_filter($filters,static fn($v): bool=>$v!==''&&$v!==0&&$v!==false&&$v!==[])?vp3_search_query_v2090($pdo,$userId,$q,$rawFilters,75,true):null;
$discovery=$search?null:vp3_search_discover_v2090($pdo,$userId,$rawFilters);
$recent=vp3_search_recent_v2090($pdo,$userId,12);$saved=vp3_search_saved_v2090($pdo,$userId);
function search_e_v2090(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function search_type_label_v2090(string $type): string{return ucfirst($type==='live'?'Live Room':$type);}
function search_result_card_v2090(array $item,int $userId,string $q,array $filters): string{
    $title=search_e_v2090((string)$item['title']);$snippet=search_e_v2090((string)$item['snippet']);$url=search_e_v2090((string)$item['url']);$type=search_e_v2090(search_type_label_v2090((string)$item['type']));
    $meta=[];if(!empty($item['domain']))$meta[]=search_e_v2090((string)$item['domain']);if(!empty($item['visibility']))$meta[]=search_e_v2090(ucfirst((string)$item['visibility']));if(!empty($item['status']))$meta[]=search_e_v2090(ucwords(str_replace('_',' ',(string)$item['status'])));if(!empty($item['source_changed']))$meta[]='Source changed';
    $html='<article class="result"><div class="result-top"><span class="type">'.$type.'</span><span class="score">Relevance '.number_format((float)$item['score'],1).'</span></div><h3><a href="'.$url.'">'.$title.'</a></h3>';
    if($snippet!=='')$html.='<p>'.$snippet.'</p>';$html.='<div class="meta">'.implode(' · ',$meta).'</div><div class="actions"><a href="'.$url.'">Open</a>';
    if($userId>0&&!empty($item['actions']['file_claim'])&&!empty($item['source_id']))$html.='<a href="'.search_e_v2090(url('/claims.php?source='.rawurlencode((string)$item['source_id']))).'">File claim</a>';
    if($userId>0&&$item['type']==='annotation'){$common='<input type="hidden" name="csrf_token" value="'.search_e_v2090(csrf_token()).'"><input type="hidden" name="action" value="annotation_state"><input type="hidden" name="target_id" value="'.search_e_v2090((string)$item['id']).'"><input type="hidden" name="q" value="'.search_e_v2090($q).'">';$html.='<form method="post">'.$common.'<input type="hidden" name="kind" value="save"><input type="hidden" name="enabled" value="1"><button>Save</button></form><form method="post">'.$common.'<input type="hidden" name="kind" value="research"><input type="hidden" name="enabled" value="1"><button>Add to Research</button></form>';}
    if($userId>0&&$item['type']==='source')$html.='<form method="post"><input type="hidden" name="csrf_token" value="'.search_e_v2090(csrf_token()).'"><input type="hidden" name="action" value="follow_source"><input type="hidden" name="source_id" value="'.search_e_v2090((string)$item['id']).'"><input type="hidden" name="enabled" value="1"><input type="hidden" name="q" value="'.search_e_v2090($q).'"><button>Follow source</button></form>';
    $html.='</div></article>';return $html;
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Search & Discovery · VP3</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f6f7f8;color:#17191c;font:14px/1.5 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:inherit}.shell{max-width:1180px;margin:auto;padding:28px 20px 72px}.top{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:22px}.brand{font-size:18px;font-weight:900;text-decoration:none}.top nav{display:flex;gap:8px}.button,.actions a,.actions button{border:1px solid #d7dce2;background:#fff;border-radius:9px;padding:8px 10px;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}.hero{background:#111418;color:#fff;border-radius:22px;padding:28px;margin-bottom:18px}.hero small{letter-spacing:.12em;font-weight:800;color:#aeb6c0}.hero h1{font-size:34px;letter-spacing:-.04em;margin:6px 0}.hero p{max-width:760px;color:#c7cdd5}.search-form{display:grid;grid-template-columns:minmax(260px,1fr) auto;gap:10px}.search-form input[type=search]{width:100%;border:0;border-radius:12px;padding:14px 15px;font:inherit;font-size:16px}.search-form button{border:0;border-radius:12px;padding:0 20px;font-weight:850;cursor:pointer}.filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}.filters label{display:grid;gap:4px;font-size:10px;font-weight:800;color:#c8ced6}.filters select,.filters input{width:100%;border:1px solid #3b4148;background:#1d2228;color:#fff;border-radius:9px;padding:9px}.context{display:flex!important;align-items:center;gap:7px!important;grid-column:span 2}.context input{width:auto}.layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px}.card,.result{background:#fff;border:1px solid #e1e5e9;border-radius:16px}.results{display:grid;gap:10px}.result{padding:17px}.result-top,.meta{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;color:#747b84;font-size:11px}.type{font-weight:850;text-transform:uppercase;letter-spacing:.08em}.score{font-variant-numeric:tabular-nums}.result h3{margin:7px 0 5px;font-size:18px}.result h3 a{text-decoration:none}.result p{margin:0 0 8px;color:#42474e}.actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.actions form{margin:0}.actions a,.actions button{padding:6px 8px;font-size:10px}.side{display:grid;gap:12px;align-content:start}.side .card{padding:16px}.side h3{margin:0 0 9px}.saved,.recent{display:grid;gap:7px}.saved-item,.recent a{padding:9px;border-radius:10px;background:#f5f6f7;text-decoration:none}.saved-item strong{display:block}.saved-item form{margin-top:6px}.section-head{display:flex;justify-content:space-between;align-items:end;gap:12px;margin:3px 0 10px}.section-head h2{margin:0}.empty{padding:30px;text-align:center;color:#777;border:1px dashed #ccd1d7;border-radius:14px}.notice,.error{padding:10px 12px;border-radius:10px;margin-bottom:12px;background:#eef8f1}.error{background:#fff1ef;color:#9a2525}.discovery{display:grid;gap:20px}.discovery-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}@media(max-width:900px){.layout{grid-template-columns:1fr}.side{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.shell{padding:16px 12px 50px}.hero{padding:20px}.hero h1{font-size:28px}.search-form{grid-template-columns:1fr}.search-form button{padding:12px}.filters{grid-template-columns:1fr}.context{grid-column:auto}.side,.discovery-grid{grid-template-columns:1fr}}
</style></head><body><main class="shell"><div class="top"><a class="brand" href="<?=search_e_v2090(url('/'))?>">VP3</a><nav><?php if($userId>0):?><a class="button" href="<?=search_e_v2090(url('/notifications.php'))?>">Notifications</a><?php else:?><a class="button" href="<?=search_e_v2090(url('/login.php'))?>">Sign in</a><?php endif;?></nav></div>
<?php if($notice):?><div class="notice"><?=search_e_v2090($notice)?></div><?php endif;?><?php if($error):?><div class="error"><?=search_e_v2090($error)?></div><?php endif;?>
<section class="hero"><small>SEARCH & DISCOVERY</small><h1>Find the source, context, and conversation.</h1><p>Search Sources, Annotations, Claims, Research, Live Rooms, public profiles, and Teams you can access. Results are re-authorized against the live object before they are shown.</p>
<form method="get" class="search-form"><input type="search" name="q" value="<?=search_e_v2090($q)?>" placeholder="Search sources, claims, research, people…"><button type="submit">Search</button>
<div class="filters"><label>Type<select name="type"><option value="">All types</option><?php foreach($options['types'] as $type):?><option value="<?=search_e_v2090($type)?>" <?=in_array($type,$filters['types'],true)?'selected':''?>><?=search_e_v2090(search_type_label_v2090($type))?></option><?php endforeach;?></select></label>
<label>Visibility<select name="visibility"><option value="">All visibility</option><?php foreach($options['visibilities'] as $v):?><option value="<?=search_e_v2090($v)?>" <?=$filters['visibility']===$v?'selected':''?>><?=search_e_v2090(ucfirst($v))?></option><?php endforeach;?></select></label>
<label>Domain<input name="domain" value="<?=search_e_v2090((string)$filters['domain'])?>" placeholder="example.com"></label>
<label>Claim status<select name="claim_status"><option value="">Any claim status</option><?php foreach($options['claim_statuses'] as $s):?><option value="<?=search_e_v2090($s)?>" <?=$filters['claim_status']===$s?'selected':''?>><?=search_e_v2090(ucwords(str_replace('_',' ',$s)))?></option><?php endforeach;?></select></label>
<?php if($options['teams']):?><label>Team<select name="team_id"><option value="0">Any Team</option><?php foreach($options['teams'] as $team):?><option value="<?=(int)$team['id']?>" <?=$filters['team_id']===(int)$team['id']?'selected':''?>><?=search_e_v2090((string)$team['name'])?></option><?php endforeach;?></select></label><?php endif;?>
<label>Source changed<select name="changed"><option value="">Any</option><option value="1" <?=$filters['changed']==='1'?'selected':''?>>Changed</option><option value="0" <?=$filters['changed']==='0'?'selected':''?>>No recorded change</option></select></label>
<label>From<input type="date" name="date_from" value="<?=search_e_v2090((string)$filters['date_from'])?>"></label><label>To<input type="date" name="date_to" value="<?=search_e_v2090((string)$filters['date_to'])?>"></label>
<?php if($filters['context_source_id']!==''):?><input type="hidden" name="source" value="<?=search_e_v2090((string)$filters['context_source_id'])?>"><label class="context"><input type="checkbox" name="context_only" value="1" <?=$filters['context_only']?'checked':''?>> Search only this Source</label><?php endif;?></div></form></section>
<div class="layout"><section>
<?php if($search):?><div class="section-head"><h2><?= (int)$search['count'] ?> results</h2><span>Weighted relevance · activity · recency · current-source context</span></div><div class="results"><?php foreach($search['items'] as $item)echo search_result_card_v2090($item,$userId,$q,$filters);?><?php if(!$search['items']):?><div class="empty">No authorized results match this search.</div><?php endif;?></div>
<?php else:?><div class="discovery"><section><div class="section-head"><h2>Trending Sources</h2><span>Public activity, change signals, and recency</span></div><div class="discovery-grid"><?php foreach($discovery['trending'] as $item)echo search_result_card_v2090($item,$userId,'',$filters);?></div></section><section><div class="section-head"><h2>Active now</h2><span>Annotations, claims, Research, and Live Rooms</span></div><div class="results"><?php foreach($discovery['active'] as $item)echo search_result_card_v2090($item,$userId,'',$filters);?></div></section></div><?php endif;?>
</section><aside class="side">
<?php if($userId>0):?><section class="card"><h3>Save this search</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_search"><input type="hidden" name="q" value="<?=search_e_v2090($q)?>"><input name="name" maxlength="120" required placeholder="Search name" style="width:100%;padding:9px;border:1px solid #d7dce2;border-radius:9px"><button class="button" style="margin-top:8px" <?=$q===''?'disabled':''?>>Save search</button></form></section>
<section class="card"><h3>Saved searches</h3><div class="saved"><?php foreach($saved as $item):?><div class="saved-item"><a href="<?=search_e_v2090(url('/search.php?'.http_build_query(['q'=>$item['query']]+($item['filters']['types']?[ 'type'=>$item['filters']['types'][0] ]:[]))))?>"><strong><?=search_e_v2090((string)$item['name'])?></strong><?=search_e_v2090((string)$item['query'])?></a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete_saved"><input type="hidden" name="saved_id" value="<?=search_e_v2090((string)$item['id'])?>"><button class="button">Remove</button></form></div><?php endforeach;?><?php if(!$saved):?><div class="meta">No saved searches.</div><?php endif;?></div></section>
<section class="card"><div class="section-head"><h3>Recent</h3><?php if($recent):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="clear_recent"><button class="button">Clear</button></form><?php endif;?></div><div class="recent"><?php foreach($recent as $item):?><a href="<?=search_e_v2090(url('/search.php?q='.rawurlencode((string)$item['query'])))?>"><?=search_e_v2090((string)$item['query'])?><div class="meta"><?=(int)$item['result_count']?> results · <?=search_e_v2090((string)$item['last_used_at'])?></div></a><?php endforeach;?><?php if(!$recent):?><div class="meta">No recent searches.</div><?php endif;?></div></section><?php endif;?>
</aside></div></main></body></html>