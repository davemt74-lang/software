<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-receipts-v1.php';

/** Reuse canonical member visibility. URLs are server-defined, never model/browser arguments. */
function vp3_navigation_targets_v1(array $user): array
{
    $targets=[];
    if(function_exists('member_navigation_menu_links'))foreach(member_navigation_menu_links($user) as $row){
        if(in_array($row['key']??'',['home','chat','contacts','knowledge','transcriptions','calendar','scheduling','music_workspace','messages'],true))$targets[$row['key']]=['label'=>$row['label'],'url'=>$row['url']];
    }
    if(isset($targets['music_workspace']))foreach(['music_library'=>['Music Library','/music-library.php'],'music_releases'=>['Releases','/music-releases.php'],'music_player'=>['Music Player','/player.php']] as $key=>[$label,$path])$targets[$key]=['label'=>$label,'url'=>url($path)];
    return $targets;
}
function vp3_navigation_last_v1(PDO $pdo,array $user,array $principal,array $ctx): ?array
{
    $cid=(int)($ctx['conversation_id']??0);$aid=($principal['kind']??'')==='user_agent'?(int)$principal['agent_id']:0;if($cid<1)return null;
    $q=$pdo->prepare("SELECT m.id,m.context_json,m.created_at FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id WHERE c.id=? AND c.user_id=? AND ".($aid?'c.user_agent_id=?':'c.user_agent_id IS NULL')." AND m.role='assistant' ORDER BY m.id DESC LIMIT 1");$q->execute($aid?[$cid,(int)$user['id'],$aid]:[$cid,(int)$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||strtotime((string)$row['created_at'].' UTC')<time()-900)return null;
    $q=$pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=? AND role='user' AND id>?");$q->execute([$cid,(int)$row['id']]);if((int)$q->fetchColumn()>1)return null;
    $data=json_decode((string)$row['context_json'],true);$pending=$data['navigation_context']??null;
    if(is_array($pending)&&($pending['expires_at']??0)>=time()&&($pending['destination']??'')==='stem_studio'&&is_int($pending['track_id']??null))return $pending;
    // Existing track offers already persist server-resolved media. A model may
    // resolve the user's yes to an open-Studio offer, but cannot invent its ID.
    $media=is_array($data['media']??null)?$data['media']:[];
    if(count($media)===1&&is_numeric($media[0]['id']??null)&&(int)$media[0]['id']>0)return ['destination'=>'stem_studio','track_id'=>(int)$media[0]['id'],'expires_at'=>time()+60,'from_media_offer'=>true];
    return null;
}
function vp3_navigation_normalize_v1(string $v): string{return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($v))??'');}
function vp3_navigation_tracks_v1(PDO $pdo,array $user,string $query): array
{
    $query=vp3_navigation_normalize_v1($query);if($query==='')return [];
    $rows=$pdo->query('SELECT * FROM tracks ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);$exact=[];$partial=[];
    foreach($rows as $row){if(!vp3_agent_tool_track_manage_v400($pdo,$row,$user))continue;$title=vp3_navigation_normalize_v1((string)$row['title']);if($title===$query)$exact[]=$row;elseif(str_contains($title,$query))$partial[]=$row;}
    return $exact?:$partial;
}
function vp3_navigation_result_v1(string $answer,string $op='open',string $status='read_complete',int $id=0): array
{
    return ['handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[],'navigation_context'=>null,'tool_receipt'=>vp3_tool_receipt_v1('navigation.open',$op,$status,'',$id,$id?1:0)];
}
function vp3_navigation_tool_v1(PDO $pdo,array $user,array $principal,array $plan,array $ctx): array
{
    unset($GLOBALS['vp3_navigation_approved_v1']);
    try{
        if(!knowledge_retrieval_v162_owner_session($user,$principal)||!has_permission('chat.access',$user))throw new RuntimeException('Navigation is unavailable.',403);
        $action=$plan['action'];$destination=$plan['destination']??'';$pending=null;
        if($action==='confirm'){$pending=vp3_navigation_last_v1($pdo,$user,$principal,$ctx);if(!$pending)return vp3_navigation_result_v1('There is no current Studio navigation offer. Which track should I open?','prepare','clarification_required');$destination=$pending['destination'];}
        if($destination==='stem_studio'){
            if(!table_exists('tracks'))return vp3_navigation_result_v1('The Studio track catalog is unavailable.','open','no_matches');
            $id=(int)($pending['track_id']??$plan['track_id']??0);
            if($id){$q=$pdo->prepare('SELECT * FROM tracks WHERE id=?');$q->execute([$id]);$t=$q->fetch(PDO::FETCH_ASSOC);$tracks=$t&&vp3_agent_tool_track_manage_v400($pdo,$t,$user)?[$t]:[];}
            else $tracks=vp3_navigation_tracks_v1($pdo,$user,(string)($plan['query']??''));
            if(count($tracks)!==1){$r=vp3_navigation_result_v1($tracks?'Which Studio track should I open? '.implode('; ',array_map(static fn($t)=>(string)$t['title'],array_slice($tracks,0,8))):'Which authorized Studio track should I open? I found no matching track in the latest 500 catalog entries.','prepare','clarification_required');return $r;}
            $track=$tracks[0];$id=(int)$track['id'];
            if($action==='prepare'){$r=vp3_navigation_result_v1('Open “'.$track['title'].'” in Stem Studio?','prepare','clarification_required',$id);$r['navigation_context']=['destination'=>'stem_studio','track_id'=>$id,'expires_at'=>time()+900];return $r;}
            $r=vp3_navigation_result_v1('Opening Stem Studio for “'.$track['title'].'”.','open','read_complete',$id);
            $target=url('/admin/stems.php?track='.$id);$GLOBALS['vp3_navigation_approved_v1']=['user_id'=>(int)$user['id'],'key'=>'stem_studio','url'=>$target];$r['actions']=[['type'=>'open_url','label'=>'Open Stem Studio','url'=>$target,'navigation_target'=>'stem_studio']];return $r;
        }
        $targets=vp3_navigation_targets_v1($user);if(!isset($targets[$destination]))throw new RuntimeException('That page is not available to this account.',403);
        $t=$targets[$destination];$GLOBALS['vp3_navigation_approved_v1']=['user_id'=>(int)$user['id'],'key'=>$destination,'url'=>$t['url']];$r=vp3_navigation_result_v1('Opening '.$t['label'].'.');$r['actions']=[['type'=>'open_url','label'=>$t['label'],'url'=>$t['url'],'navigation_target'=>$destination]];return $r;
    }catch(Throwable $e){return [...vp3_navigation_result_v1('I could not verify access to that destination.','open','blocked'),'tool_receipt'=>vp3_tool_receipt_v1('navigation.open','open','blocked','permission_denied')];}
}
