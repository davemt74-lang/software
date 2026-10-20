<?php
declare(strict_types=1);

/** Existing System App control, with HomeServer as the only Feed execution authority. */
function vp3_vrss_agent_query_v2b(string $query,array $user,int $conversationId=0,?callable $remote=null,?PDO $pdo=null): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    $feed=(bool)preg_match('/\b(?:vrss|my feed|rss feed|atom feed)\b/i',$query);
    $social=(bool)preg_match('/\b(?:vrss|authenticated)\b/i',$query);
    $follow=(bool)preg_match('/^\s*(?:please\s+)?(?:follow|subscribe to)\s+https?:\/\//i',$query);
    if(!$feed&&!$follow)return $empty;
    if(!preg_match('/^\s*(?:please\s+)?(?:comment|reply)\s+VRSS\b/i',$query)&&preg_match('/\b(?:hosting|subdomain|install|upgrade|update app)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    $pdo??=db();if(!$pdo)return $empty;
    $answer='';$result=[];
    try{
        $action='';$args=[];$commentPreview=[];
        if($social&&preg_match('~^\s*(?:please\s+)?comment\s+VRSS\s+(https://[^\s<>"\x27]+)\s*:\s+([\s\S]+)$~i',$query,$m)){
            if(!vp3_vrss_post_url_v2d($m[1]))throw new RuntimeException('Include the canonical VRSS post URL.');
            $body=trim($m[2]);if($body===''||strlen($body)>2000)throw new RuntimeException('Include a shorter, nonempty comment.');
            $action='vrss.social.comment';$args=['url'=>$m[1],'body'=>$body,'parent'=>'','request_id'=>bin2hex(random_bytes(16))];$commentPreview=['comment'=>$body,'publication'=>'Awaits post-owner review'];
        }elseif($social&&preg_match('~^\s*(?:please\s+)?reply\s+VRSS\s+(https://[^\s<>"\x27]+)\s+to\s+(https://[^\s<>"\x27]+)\s*:\s+([\s\S]+)$~i',$query,$m)){
            if(!vp3_vrss_post_url_v2d($m[1])||!vp3_vrss_comment_url_v2e($m[2]))throw new RuntimeException('Include the canonical post URL and published parent comment ID.');
            $body=trim($m[3]);if($body===''||strlen($body)>2000)throw new RuntimeException('Include a shorter, nonempty reply.');
            $action='vrss.social.comment';$args=['url'=>$m[1],'body'=>$body,'parent'=>$m[2],'request_id'=>bin2hex(random_bytes(16))];$commentPreview=['comment'=>$body,'parent'=>$m[2],'publication'=>'Awaits post-owner review'];
        }elseif($social&&preg_match('~^\s*(approve|hide|retract)\s+VRSS\s+comment\s+(https://[^\s<>"\x27]+)\s+on\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            if(!vp3_vrss_comment_url_v2e($m[2])||!vp3_vrss_post_url_v2d($m[3]))throw new RuntimeException('Include the canonical comment ID and its post URL.');
            $args=['url'=>$m[3],'comment_id'=>$m[2],'request_id'=>bin2hex(random_bytes(16))];
            if(strtolower($m[1])==='retract')$action='vrss.social.comment_delete';
            else{
                $payload=vp3_system_apps_remote_v110($uid,'apps.control.invoke',['app_key'=>'vp3.feed','action'=>'vrss.social.comment_review','arguments'=>['url'=>$m[3],'comment_id'=>$m[2]],'confirmed'=>false],$remote);
                $item=(array)($payload['result']['comment']??[]);
                if(($item['id']??'')!==$m[2]||empty($item['can_moderate'])||!is_int($item['revision']??null))throw new RuntimeException('Refresh this incoming comment before moderating it.');
                $action='vrss.social.moderate';$args+=['decision'=>strtolower($m[1]),'expected_revision'=>$item['revision']];$commentPreview=['author'=>(string)($item['name']??''),'comment'=>(string)($item['body']??''),'revision'=>$item['revision']];
            }
        }elseif($social&&preg_match('~^\s*(?:show|read|review)\s+VRSS\s+comments\s+(https://[^\s<>"\x27]+)(?:\s+after\s+([1-9][0-9]{0,15}))?\s*$~i',$query,$m)){
            if(!vp3_vrss_post_url_v2d($m[1]))throw new RuntimeException('Include the canonical VRSS post URL.');
            $payload=vp3_system_apps_remote_v110($uid,'apps.control.invoke',['app_key'=>'vp3.feed','action'=>'vrss.social.comments','arguments'=>['url'=>$m[1],'cursor'=>$m[2]??'0'],'confirmed'=>false],$remote);
            $result=(array)$payload['result'];$thread=(array)($result['thread']??[]);$answer=!empty($thread['owner'])?'Incoming comments for your review:':'Published VRSS comments:';
            foreach(array_slice((array)($thread['items']??[]),0,5) as $item){$body=mb_substr((string)($item['body']??''),0,2000);$body=str_replace('`',"'",$body);$answer.="\n\n".(string)($item['name']??'Comment').(!empty($thread['owner'])?' · '.(string)($item['state']??''):'')."\n> ".str_replace("\n","\n> ",$body)."\nComment ID: ".(string)($item['id']??'');}
            if(empty($thread['items']))$answer.=' No comments on this page.';
            if(!empty($thread['next_cursor']))$answer.="\nMore: show VRSS comments ".$m[1].' after '.$thread['next_cursor'];
        }elseif($social&&preg_match('~^\s*(?:please\s+)?(repost|undo repost)\s+(?:this\s+)?VRSS(?:\s+post)?\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            if(!vp3_vrss_post_url_v2d($m[2]))throw new RuntimeException('Include the canonical original VRSS post URL.');
            $action='vrss.social.repost';$args=['url'=>$m[2],'enabled'=>strtolower($m[1])==='repost','request_id'=>bin2hex(random_bytes(16))];
            $commentPreview=['publication'=>$args['enabled']?'Publish an attributed link; original content stays with its author':'Withdraw your repost; original post is unchanged'];
        }elseif($social&&preg_match('~^\s*(?:show|read|refresh)\s+VRSS\s+reposts\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            if(!vp3_vrss_post_url_v2d($m[1]))throw new RuntimeException('Include the canonical original VRSS post URL.');
            $payload=vp3_system_apps_remote_v110($uid,'apps.control.invoke',['app_key'=>'vp3.feed','action'=>'vrss.social.reposts','arguments'=>['url'=>$m[1]],'confirmed'=>false],$remote);
            $result=(array)$payload['result'];$repost=(array)($result['repost']??[]);
            $answer=isset($repost['reposts'])?(int)$repost['reposts'].' reposts reported by the original author.':'The original author’s repost count is unavailable.';
            if(!empty($repost['pending']))$answer.=' Your repost update is waiting for delivery.';
            elseif(!empty($repost['reposted']))$answer.=!empty($repost['published'])?' Your attributed repost is published.':' Your repost is paused until its source can be verified.';
        }elseif($social&&preg_match('~^\s*(?:please\s+)?(like|unlike)\s+(?:this\s+)?VRSS(?:\s+post)?\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            if(!vp3_vrss_post_url_v2d($m[2]))throw new RuntimeException('Include the canonical public VRSS post URL.');
            $action='vrss.social.like';$args=['url'=>$m[2],'enabled'=>strtolower($m[1])==='like','request_id'=>bin2hex(random_bytes(16))];
        }elseif($social&&preg_match('~^\s*(?:show|read|refresh)\s+VRSS\s+likes\s+(https://[^\s<>"\x27]+)\s*$~i',$query,$m)){
            if(!vp3_vrss_post_url_v2d($m[1]))throw new RuntimeException('Include the canonical public VRSS post URL.');
            $payload=vp3_system_apps_remote_v110($uid,'apps.control.invoke',['app_key'=>'vp3.feed','action'=>'vrss.social.reactions','arguments'=>['url'=>$m[1]],'confirmed'=>false],$remote);
            $result=(array)$payload['result'];$reaction=(array)($result['reaction']??[]);
            $answer=isset($reaction['likes'])?(int)$reaction['likes'].' like'.((int)$reaction['likes']===1?'':'s').' reported by the post author.':'The author’s like count is not available yet.';
            if(!empty($reaction['pending']))$answer.=' Your '.(!empty($reaction['liked'])?'like':'unlike').' is waiting for delivery.';
        }elseif($social&&preg_match('/\b(?:unfollow|unblock|block|remove follower)\b/i',$query,$command)){
            if(!preg_match('~https?://[^\s<>"\x27]+~i',$query,$m))throw new RuntimeException('Include the canonical VRSS actor URL from your connections.');
            $action='vrss.social.'.($command[0]==='remove follower'?'remove':strtolower($command[0]));$args=['actor'=>$m[0]];
        }elseif($social&&preg_match('/\b(?:followers?|connections?|delivery)\b/i',$query)&&!preg_match('/\bfollow\s/i',$query)){
            $payload=vp3_system_apps_remote_v110($uid,'apps.control.invoke',['app_key'=>'vp3.feed','action'=>'vrss.social.status','arguments'=>[],'confirmed'=>false],$remote);
            $result=(array)$payload['result'];$state=(array)($result['social']??[]);
            $answer='VRSS has '.(int)($state['followers']??0).' verified followers and follows '.(int)($state['following']??0).' profiles. '.(int)($state['pending_deliveries']??count((array)($state['deliveries']??[]))).' connection updates are waiting for delivery.';
            foreach(array_slice((array)($state['relations']??[]),0,10) as $relation){
                if(($relation['state']??'')==='removed'||!empty($relation['blocked']))continue;
                $name=mb_substr(str_replace(["\r","\n"],[' ',' '],(string)($relation['name']??'Profile')),0,120);
                $answer.="\n• ".$name.': '.(($relation['direction']??'')==='in'?'follows you':(string)($relation['state']??'pending'));
            }
        }elseif($follow||preg_match('/\b(?:follow|subscribe)\b/i',$query)){
            if(!preg_match('~https?://[^\s<>"\x27]+~i',$query,$m))throw new RuntimeException('Include the website, VRSS profile or feed URL to follow.');
            $action=$social?'vrss.social.follow':'vrss.follow';$args=['url'=>$m[0]];
        }elseif(preg_match('/\brefresh\b/i',$query)){
            $action='vrss.refresh_all';
        }elseif(preg_match('/\b(?:show|read|new|latest)\b/i',$query)&&preg_match('/\bposts?\b/i',$query)){
            $payload=vp3_system_apps_remote_v110($uid,'apps.control.invoke',['app_key'=>'vp3.feed','action'=>'vrss.feed','arguments'=>[],'confirmed'=>false],$remote);
            $result=(array)$payload['result'];$posts=(array)($result['feed']['items']??[]);
            $lines=[];
            foreach(array_slice($posts,0,8) as $post){
                $title=(string)($post['title']??$post['body']??'Post');
                $lines[]='• '.mb_substr(preg_replace('/[\r\n\t]+/',' ',$title)??'',0,180);
            }
            $answer=$lines?"Recent VRSS posts:\n".implode("\n",$lines):'Your VRSS feed has no posts yet.';
        }elseif(preg_match('/\b(?:status|health|subscriptions?|following|last refresh|errors?)\b/i',$query)){
            $payload=vp3_system_apps_remote_v110($uid,'apps.control.invoke',['app_key'=>'vp3.feed','action'=>'vrss.status','arguments'=>[],'confirmed'=>false],$remote);
            $result=(array)$payload['result'];$subs=(array)($result['subscriptions']??[]);
            $errors=count(array_filter($subs,static fn($s)=>!empty($s['error'])));
            $answer='VRSS follows '.count($subs).' feeds. Automatic checks run about every 15 minutes; '.$errors.' sources are retrying after an error.';
            foreach(array_slice($subs,0,8) as $sub){
                $title=mb_substr(str_replace(["\r","\n","`"],[' ',' ',"'"],(string)($sub['title']??'Feed')),0,120);
                $last=(string)($sub['last_success_at']??'');
                $answer.="\n• `".$title.'`: '.($last!==''?'last updated '.$last:'waiting for first update')
                    .(!empty($sub['error'])?' · retrying after an error':'');
            }
        }else{return $empty;}
        if($action!==''){
            $plan=vp3_system_apps_agent_prepare_v160($user,'vrss.invoke','vp3.feed',['action'=>$action,'arguments'=>$args],
                ['app'=>'VRSS Feed','operation'=>$action,'target'=>(string)($args['url']??$args['actor']??'All followed feeds')]+$commentPreview,$conversationId,null,$pdo);
            return vp3_system_apps_agent_prepare_result_v160($plan,'I prepared the VRSS action for your review.');
        }
    }catch(Throwable $e){$answer='I could not complete the VRSS request: '.mb_substr($e->getMessage(),0,300);}
    return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],
        'sources'=>[['source'=>'homeserver:vrss','title'=>'HomeServer VRSS']],'vrss'=>$result];
}

/** Project only committed, account-bound HomeServer notices into the existing Chat delivery pipeline. */
function workspace_sync_vrss_notices_v2b(PDO $pdo,int $uid,array $records): void
{
    if(!function_exists('notification_create_locked')||!table_exists('notifications'))return;
    foreach($records as $record){
        $n=$record['data']??null;
        if(($record['table']??'')!=='notifications'||!is_array($n)||($n['source_key']??'')!=='vp3.feed'||!in_array($n['event_key']??'',['vrss.new_posts','vrss.new_follower','vrss.new_like','vrss.new_comment','vrss.new_repost'],true))continue;
        $key=$n['dedupe_key']??'';$event=$n['event_key'];
        $kind=['vrss.new_follower'=>'follower','vrss.new_like'=>'like','vrss.new_comment'=>'comment','vrss.new_repost'=>'repost'][$event]??'posts';
        $type='vrss_new_'.$kind;
        if($kind!=='posts'){
            $meta=json_decode((string)($n['action_json']??''),true);
            $flag=['follower'=>'verified_follow','like'=>'verified_interaction','comment'=>'verified_comment','repost'=>'verified_repost'][$kind];
            if(!is_string($key)||!preg_match('/^vrss-'.$kind.':([0-9a-f]{64})$/D',$key,$receipt)||!is_array($meta)
                ||($meta[$flag]??false)!==true||($meta['receipt']??'')!==$receipt[1]
                ||($meta['app_key']??'')!=='vp3.feed'||!is_string($meta['actor']??null)||!vp3_vrss_actor_url_v2c($meta['actor']))continue;
            if(in_array($kind,['like','comment','repost'],true)&&(!is_string($meta['post']??null)||!vp3_vrss_post_url_v2d($meta['post'])))continue;
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
            $title=['repost'=>'New VRSS repost','comment'=>'VRSS comment awaiting review','like'=>'New VRSS like','follower'=>'New VRSS follower','posts'=>'New posts in your VRSS feed'][$kind];
            $message=match($kind){
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
