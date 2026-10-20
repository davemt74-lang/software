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
    if(preg_match('/\b(?:hosting|subdomain|install|upgrade|update app)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    $pdo??=db();if(!$pdo)return $empty;
    $answer='';$result=[];
    try{
        $action='';$args=[];
        if($social&&preg_match('/\b(?:unfollow|unblock|block|remove follower)\b/i',$query,$command)){
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
                ['app'=>'VRSS Feed','operation'=>$action,'target'=>(string)($args['url']??$args['actor']??'All followed feeds')],$conversationId,null,$pdo);
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
        if(($record['table']??'')!=='notifications'||!is_array($n)||($n['source_key']??'')!=='vp3.feed'||!in_array($n['event_key']??'',['vrss.new_posts','vrss.new_follower'],true))continue;
        $key=$n['dedupe_key']??'';
        $follower=($n['event_key']??'')==='vrss.new_follower';
        $type=$follower?'vrss_new_follower':'vrss_new_posts';
        if($follower){
            $meta=json_decode((string)($n['action_json']??''),true);
            if(!is_string($key)||!preg_match('/^vrss-follower:([0-9a-f]{64})$/D',$key,$receipt)||!is_array($meta)
                ||($meta['verified_follow']??false)!==true||($meta['receipt']??'')!==$receipt[1]
                ||($meta['app_key']??'')!=='vp3.feed'||!is_string($meta['actor']??null)
                ||!vp3_vrss_actor_url_v2c($meta['actor']))continue;
        }elseif(!is_string($key)||!preg_match('/^vrss:[0-9a-f-]{36}:[0-9]+$/D',$key))continue;
        $source=($follower?'vrss_follower:':'vrss_post_event:').hash('sha256',$key);
        $q=$pdo->prepare('SELECT id FROM notifications WHERE user_id=? AND source_type=? AND source_id=1 AND type=? LIMIT 1');
        $q->execute([$uid,$source,$type]);$existing=$q->fetchColumn();
        $read=!empty($n['read_at'])||!empty($n['dismissed_at'])||!empty($n['archived_at']);
        if(!$existing&&!$read){
            $body=(string)($n['body']??'');
            $count=preg_match('/^([1-9][0-9]{0,2}) new posts /D',$body,$m)?min(100,(int)$m[1]):1;
            notification_create_locked($uid,$type,$follower?'New VRSS follower':'New posts in your VRSS feed',
                $follower?'A verified VRSS profile is now following you. Ask Agent Chat to show your VRSS followers.':$count.' new posts from a followed feed. Ask Agent Chat to show your VRSS posts.',
                url('/apps.php'),$source,1,preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',(string)($n['created_at']??''))?(string)$n['created_at'].' UTC':(string)($n['created_at']??''));
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
