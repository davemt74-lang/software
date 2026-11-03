<?php
declare(strict_types=1);

require_once __DIR__.'/agent-vrss-read-v1.php';

/** Existing Feed writes keep S7 confirmation; reads and mutation previews use current authority. */
function vp3_vrss_agent_query_v2b(string $query,array $user,int $conversationId=0,?callable $remote=null,?PDO $pdo=null): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    $feed=(bool)preg_match('/\b(?:vrss|my feed|rss feed|atom feed)\b/i',$query);
    $social=(bool)preg_match('/\b(?:vrss|authenticated)\b/i',$query);
    $follow=(bool)preg_match('/^\s*(?:please\s+)?(?:follow|subscribe to)\s+https?:\/\//i',$query);
    if(!$feed&&!$follow)return $empty;
    $intent=vp3_vrss_nonexecuting_query_v1($query);
    if($intent==='continue')return $empty;
    if($intent==='inspect')return ['handled'=>true,'answer'=>'The VRSS tools can read your authorized feed, connection and conversation records. Existing follow, refresh, interaction and moderation actions prepare a request for explicit confirmation. This capability description has not read feed records or prepared a change.','actions'=>[],'sources'=>[],
        'tool_receipt'=>vp3_tool_receipt_v1('capabilities.inspect','inspect','read_complete')];
    $conversationIntent=(bool)preg_match('/^\s*(?:follow|unfollow|mute|unmute|show|read)\s+VRSS\s+conversations?\b/i',$query);
    if(!$conversationIntent&&!preg_match('/^\s*(?:please\s+)?(?:comment|reply)\s+VRSS\b/i',$query)&&preg_match('/\b(?:hosting|subdomain|install|upgrade|update app)\b/i',$query))return $empty;
    if((int)($user['id']??0)<1)return $empty;
    $writing=false;
    try{
        $pdo??=db();if(!$pdo)throw new RuntimeException('Storage unavailable.');
        $principal=vp3_capability_control_principal_v1($pdo,$user,$conversationId);
        $action='';$args=[];$commentPreview=[];
        if($social&&preg_match('~^\s*(follow|unfollow|mute|unmute)\s+VRSS\s+conversation\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            $writing=true;$verb=strtolower($m[1]);
            $m[2]=vp3_vrss_url_identity_v1($m[2])??throw new InvalidArgumentException('A canonical VRSS post URL is required.');
            $data=vp3_vrss_preflight_v1($pdo,$user,$principal,$conversationId,'vrss.social.conversations',[],$remote);
            $watch=null;foreach($data['items'] as $item)if($item['object']===$m[2]){$watch=$item;break;}
            if($watch===null){
                if(!$data['complete']||$verb!=='follow')throw new RuntimeException('The requested conversation setting was not reported.');
                // A complete, verified list proves this is a new watch; the existing create contract uses revision zero.
                $watch=['enabled'=>false,'muted'=>false,'revision'=>0];
            }
            $action='vrss.social.conversation';$args=['url'=>$m[2],'enabled'=>in_array($verb,['follow','unfollow'],true)?$verb==='follow':$watch['enabled'],'muted'=>in_array($verb,['mute','unmute'],true)?$verb==='mute':$watch['muted'],'expected_revision'=>$watch['revision'],'request_id'=>bin2hex(random_bytes(16))];
            $commentPreview=['conversation_followed'=>$args['enabled'],'conversation_muted'=>$args['muted'],'revision'=>$args['expected_revision']];
        }elseif($social&&preg_match('~^\s*(?:show|read)\s+VRSS\s+conversation\s+(https://[^\s<>"\x27]+)(?:\s+after\s+([1-9][0-9]{0,15}))?\s*$~i',$query,$m)){
            return vp3_vrss_legacy_read_v1($pdo,$user,$principal,$conversationId,'thread',['url'=>$m[1],'cursor'=>$m[2]??'0'],$remote);
        }elseif($social&&preg_match('~^\s*(?:show|read)\s+VRSS\s+conversations\s*$~i',$query)){
            return vp3_vrss_legacy_read_v1($pdo,$user,$principal,$conversationId,'conversations',[],$remote);
        }elseif($social&&preg_match('~^\s*(?:please\s+)?comment\s+VRSS\s+(https://[^\s<>"\x27]+)\s*:\s+([\s\S]+)$~i',$query,$m)){
            $writing=true;$m[1]=vp3_vrss_url_identity_v1($m[1])??throw new InvalidArgumentException('A canonical VRSS post URL is required.');
            $body=trim($m[2]);if($body===''||strlen($body)>2000)throw new InvalidArgumentException('Include a shorter, nonempty comment.');
            $action='vrss.social.comment';$args=['url'=>$m[1],'body'=>$body,'parent'=>'','request_id'=>bin2hex(random_bytes(16))];$commentPreview=['comment'=>$body,'publication'=>'Awaits post-owner review'];
        }elseif($social&&preg_match('~^\s*(?:please\s+)?reply\s+VRSS\s+(https://[^\s<>"\x27]+)\s+to\s+(https://[^\s<>"\x27]+)\s*:\s+([\s\S]+)$~i',$query,$m)){
            $writing=true;$m[1]=vp3_vrss_url_identity_v1($m[1])??throw new InvalidArgumentException('A canonical VRSS post URL is required.');
            $m[2]=vp3_vrss_url_identity_v1($m[2],'comment')??throw new InvalidArgumentException('A canonical parent comment URL is required.');
            $body=trim($m[3]);if($body===''||strlen($body)>2000)throw new InvalidArgumentException('Include a shorter, nonempty reply.');
            $action='vrss.social.comment';$args=['url'=>$m[1],'body'=>$body,'parent'=>$m[2],'request_id'=>bin2hex(random_bytes(16))];$commentPreview=['comment'=>$body,'parent'=>$m[2],'publication'=>'Awaits post-owner review'];
        }elseif($social&&preg_match('~^\s*(approve|hide|retract)\s+VRSS\s+comment\s+(https://[^\s<>"\x27]+)\s+on\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            $writing=true;$m[2]=vp3_vrss_url_identity_v1($m[2],'comment')??throw new InvalidArgumentException('A canonical comment URL is required.');
            $m[3]=vp3_vrss_url_identity_v1($m[3])??throw new InvalidArgumentException('A canonical VRSS post URL is required.');
            $args=['url'=>$m[3],'comment_id'=>$m[2],'request_id'=>bin2hex(random_bytes(16))];
            if(strtolower($m[1])==='retract')$action='vrss.social.comment_delete';
            else{
                $item=vp3_vrss_preflight_v1($pdo,$user,$principal,$conversationId,'vrss.social.comment_review',['url'=>$m[3],'comment_id'=>$m[2]],$remote);
                $action='vrss.social.moderate';$args+=['decision'=>strtolower($m[1]),'expected_revision'=>$item['revision']];$commentPreview=['author'=>$item['name'],'comment'=>$item['body'],'revision'=>$item['revision']];
            }
        }elseif($social&&preg_match('~^\s*(?:show|read|review)\s+VRSS\s+comments\s+(https://[^\s<>"\x27]+)(?:\s+after\s+([1-9][0-9]{0,15}))?\s*$~i',$query,$m)){
            return vp3_vrss_legacy_read_v1($pdo,$user,$principal,$conversationId,'comments',['url'=>$m[1],'cursor'=>$m[2]??'0'],$remote);
        }elseif($social&&preg_match('~^\s*(?:please\s+)?(repost|undo repost)\s+(?:this\s+)?VRSS(?:\s+post)?\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            $writing=true;$m[2]=vp3_vrss_url_identity_v1($m[2])??throw new InvalidArgumentException('A canonical original post URL is required.');
            $action='vrss.social.repost';$args=['url'=>$m[2],'enabled'=>strtolower($m[1])==='repost','request_id'=>bin2hex(random_bytes(16))];
            $commentPreview=['publication'=>$args['enabled']?'Publish an attributed link; original content stays with its author':'Withdraw your repost; original post is unchanged'];
        }elseif($social&&preg_match('~^\s*(?:show|read|refresh)\s+VRSS\s+reposts\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            return vp3_vrss_legacy_read_v1($pdo,$user,$principal,$conversationId,'reposts',['url'=>$m[1]],$remote);
        }elseif($social&&preg_match('~^\s*(?:please\s+)?(like|unlike)\s+(?:this\s+)?VRSS(?:\s+post)?\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            $writing=true;$m[2]=vp3_vrss_url_identity_v1($m[2])??throw new InvalidArgumentException('A canonical public post URL is required.');
            $action='vrss.social.like';$args=['url'=>$m[2],'enabled'=>strtolower($m[1])==='like','request_id'=>bin2hex(random_bytes(16))];
        }elseif($social&&preg_match('~^\s*(?:show|read|refresh)\s+VRSS\s+likes\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            return vp3_vrss_legacy_read_v1($pdo,$user,$principal,$conversationId,'likes',['url'=>$m[1]],$remote);
        }elseif(preg_match('/^\s*(?:please\s+)?(?:(?:comment|reply|like|unlike|repost|undo repost|approve|hide|retract|mute|unmute|delete)\b|(?:follow|unfollow)\s+VRSS\s+conversation\b)/i',$query)){
            return ['handled'=>true,'answer'=>'Choose the exact VRSS post, comment or conversation and use its canonical URL before preparing this action. No request was prepared.','actions'=>[],'sources'=>[],
                'tool_receipt'=>vp3_tool_receipt_v1('system_apps.manage','prepare','clarification_required')];
        }elseif($social&&preg_match('/\b(?:unfollow|unblock|block|remove follower)\b/i',$query,$command)){
            $writing=true;if(!preg_match('~https?://[^\s<>"\x27]+~i',$query,$m))throw new InvalidArgumentException('A canonical VRSS actor URL is required.');
            $verb=strtolower($command[0]);$action='vrss.social.'.($verb==='remove follower'?'remove':$verb);$args=['actor'=>$m[0]];
        }elseif($social&&preg_match('/\b(?:followers?|connections?|delivery)\b/i',$query)&&!preg_match('/\bfollow\s/i',$query)){
            return vp3_vrss_legacy_read_v1($pdo,$user,$principal,$conversationId,'connections',[],$remote);
        }elseif($follow||preg_match('/\b(?:follow|subscribe)\b/i',$query)){
            $writing=true;if(!preg_match('~https?://[^\s<>"\x27]+~i',$query,$m))throw new InvalidArgumentException('A website, VRSS profile or feed URL is required.');
            $action=$social?'vrss.social.follow':'vrss.follow';$args=['url'=>$m[0]];
        }elseif(preg_match('/^\s*(?:please\s+)?(?:refresh\b|VRSS\s+refresh\b)/i',$query)){
            $writing=true;$action='vrss.refresh_all';
        }elseif(preg_match('/\b(?:show|read|new|latest)\b/i',$query)&&preg_match('/\bposts?\b/i',$query)){
            return vp3_vrss_legacy_read_v1($pdo,$user,$principal,$conversationId,'posts',[],$remote);
        }elseif(preg_match('/\b(?:status|health|subscriptions?|following|last refresh|errors?)\b/i',$query)){
            return vp3_vrss_legacy_read_v1($pdo,$user,$principal,$conversationId,'subscriptions',[],$remote);
        }else{return $empty;}
        if($action!==''){
            $live=vp3_vrss_authority_v1($pdo,$user,$principal,['conversation_id'=>$conversationId]);
            $plan=vp3_system_apps_agent_prepare_v160($live,'vrss.invoke','vp3.feed',['action'=>$action,'arguments'=>$args],
                ['app'=>'VRSS Feed','operation'=>$action,'target'=>(string)($args['url']??$args['actor']??'All followed feeds')]+$commentPreview,$conversationId,null,$pdo);
            return vp3_system_apps_agent_prepare_result_v160($plan,'I prepared the VRSS action for your review.');
        }
        return $empty;
    }catch(Throwable $e){
        if($writing){$failure=vp3_system_app_action_failure_v1($e,'prepare');unset($failure['system_app_plan']);return $failure;}
        return vp3_vrss_failure_v1($e);
    }
}

/** Project only committed, account-bound HomeServer notices into the existing Chat delivery pipeline. */
function workspace_sync_vrss_notices_v2b(PDO $pdo,int $uid,array $records): void
{
    if(!function_exists('notification_create_locked')||!table_exists('notifications'))return;
    foreach($records as $record){
        $n=$record['data']??null;
        if(($record['table']??'')!=='notifications'||!is_array($n)||($n['source_key']??'')!=='vp3.feed'||!in_array($n['event_key']??'',['vrss.new_posts','vrss.new_follower','vrss.new_like','vrss.new_comment','vrss.new_repost','vrss.mention','vrss.thread_reply'],true))continue;
        $key=$n['dedupe_key']??'';$event=$n['event_key'];
        $kind=['vrss.new_follower'=>'follower','vrss.new_like'=>'like','vrss.new_comment'=>'comment','vrss.new_repost'=>'repost','vrss.mention'=>'mention','vrss.thread_reply'=>'thread'][$event]??'posts';
        $type='vrss_new_'.$kind;
        if($kind!=='posts'){
            $meta=json_decode((string)($n['action_json']??''),true);
            $flag=['follower'=>'verified_follow','like'=>'verified_interaction','comment'=>'verified_comment','repost'=>'verified_repost','mention'=>'verified_conversation','thread'=>'verified_conversation'][$kind];
            if(!is_string($key)||!preg_match('/^vrss-'.$kind.':([0-9a-f]{64})$/D',$key,$receipt)||!is_array($meta)
                ||($meta[$flag]??false)!==true||($meta['receipt']??'')!==$receipt[1]
                ||($meta['app_key']??'')!=='vp3.feed'||!is_string($meta['actor']??null)||!vp3_vrss_actor_url_v2c($meta['actor']))continue;
            if(in_array($kind,['like','comment','repost','mention','thread'],true)&&(!is_string($meta['post']??null)||!vp3_vrss_post_url_v2d($meta['post'])))continue;
            if(in_array($kind,['mention','thread'],true)&&(!str_starts_with($meta['post'],substr($meta['actor'],0,-strlen('/actor')).'/posts/')||!is_string($meta['comment']??null)||($meta['comment']!==''&&!vp3_vrss_comment_url_v2e($meta['comment']))||($kind==='thread'&&$meta['comment']==='')))continue;
            if($kind==='repost'&&(!is_string($meta['repost']??null)||!vp3_vrss_repost_url_v2f($meta['repost'])
                ||!str_starts_with($meta['repost'],substr($meta['actor'],0,-strlen('/actor')).'/reposts/')
                ||hash('sha256',$meta['repost'])!==$receipt[1]))continue;
            if($kind==='comment'&&(!is_string($meta['comment']??null)||!vp3_vrss_comment_url_v2e($meta['comment'])
                ||!str_starts_with($meta['comment'],substr($meta['actor'],0,-strlen('/actor')).'/comments/')
                ||hash('sha256',$meta['comment'])!==$receipt[1]))continue;
        }elseif(!is_string($key)||!preg_match('/^vrss:[0-9a-f-]{36}:[0-9]+$/D',$key))continue;
        $source=($kind==='posts'?'vrss_post_event:':'vrss_'.$kind.':').hash('sha256',$key);
        $q=$pdo->prepare('SELECT id FROM notifications WHERE user_id=? AND source_type=? AND source_id=1 AND type=? LIMIT 1');
        $q->execute([$uid,$source,$type]);$existing=$q->fetchColumn();
        $read=!empty($n['read_at'])||!empty($n['dismissed_at'])||!empty($n['archived_at']);
        if(!$existing&&!$read){
            $body=(string)($n['body']??'');$count=preg_match('/^([1-9][0-9]{0,2}) new posts /D',$body,$m)?min(100,(int)$m[1]):1;
            $title=['mention'=>'VRSS mention','thread'=>'New reply in a followed conversation','repost'=>'New VRSS repost','comment'=>'VRSS comment awaiting review','like'=>'New VRSS like','follower'=>'New VRSS follower','posts'=>'New posts in your VRSS feed'][$kind];
            $message=match($kind){
                'mention','thread'=>'A verified public conversation has an update. Ask Agent Chat: show VRSS conversation '.$meta['post'],
                'repost'=>'A verified follower reposted your public post. Ask Agent Chat: show VRSS reposts '.$meta['post'],
                'comment'=>'A verified follower submitted a comment or reply. Ask Agent Chat: show VRSS comments '.$meta['post'],
                'like'=>'A verified VRSS follower liked your post. Ask Agent Chat: show VRSS likes '.$meta['post'],
                'follower'=>'A verified VRSS profile is now following you. Ask Agent Chat to show your VRSS followers.',
                default=>$count.' new posts from a followed feed. Ask Agent Chat to show your VRSS posts.'};
            notification_create_locked($uid,$type,$title,$message,url('/apps.php'),$source,1,
                preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',(string)($n['created_at']??''))?(string)$n['created_at'].' UTC':(string)($n['created_at']??''));
        }elseif($existing&&$read){
            $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$existing,$uid]);
        }
    }
}

/** Metadata is supplied only by an authenticated HomeServer snapshot, never RSS. */
function vp3_vrss_actor_url_v2c(string $value): bool
{
    if(strlen($value)>400||preg_match('/\s/',$value)||!filter_var($value,FILTER_VALIDATE_URL))return false;
    $url=parse_url($value);
    return is_array($url)&&($url['scheme']??'')==='https'&&!empty($url['host'])
        &&!isset($url['user'])&&!isset($url['pass'])&&!isset($url['query'])&&!isset($url['fragment'])
        &&(!isset($url['port'])||$url['port']===443)&&str_ends_with((string)($url['path']??''),'/vrss/v1/actor');
}

function vp3_vrss_post_url_v2d(string $value): bool
{
    if(strlen($value)>400||preg_match('/\s/',$value)||!filter_var($value,FILTER_VALIDATE_URL))return false;
    $url=parse_url($value);
    return is_array($url)&&($url['scheme']??'')==='https'&&!empty($url['host'])
        &&!isset($url['user'])&&!isset($url['pass'])&&!isset($url['query'])&&!isset($url['fragment'])
        &&(!isset($url['port'])||$url['port']===443)
        &&preg_match('~/vrss/v1/posts/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$~D',(string)($url['path']??''))===1;
}

function vp3_vrss_comment_url_v2e(string $value): bool
{
    if(strlen($value)>400||!preg_match('~/vrss/v1/comments/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$~D',$value))return false;
    $base=preg_replace('~/vrss/v1/comments/[^/]+$~D','/vrss/v1/actor',$value);
    return is_string($base)&&vp3_vrss_actor_url_v2c($base);
}

function vp3_vrss_repost_url_v2f(string $value): bool
{
    if(strlen($value)>400||!preg_match('~/vrss/v1/reposts/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$~D',$value))return false;
    $base=preg_replace('~/vrss/v1/reposts/[^/]+$~D','/vrss/v1/actor',$value);
    return is_string($base)&&vp3_vrss_actor_url_v2c($base);
}
