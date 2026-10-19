<?php
declare(strict_types=1);

const VP3_SOCIAL_FEED_V1 = 'vp3.social-feed.v1';

function social_feed_schema_v1(PDO $pdo): void
{
    vp3_social_ensure_schema_v320($pdo);
    profile_agent_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS social_feed_posts_v1 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      request_id CHAR(36) NOT NULL,
      content_hash CHAR(64) NOT NULL,
      body TEXT NOT NULL,
      media_json TEXT NOT NULL,
      visibility VARCHAR(16) NOT NULL DEFAULT 'public',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL,
      UNIQUE KEY uq_social_feed_public (public_id),
      UNIQUE KEY uq_social_feed_request (user_id,request_id),
      INDEX idx_social_feed_author (user_id,id),
      CONSTRAINT fk_social_feed_owner FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function social_feed_uuid_v1(): string
{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

function social_feed_text_v1($value,int $max,string $label): string
{
    if(!is_string($value)||str_contains($value,"\0")||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max)throw new InvalidArgumentException($label.' is invalid.');
    return trim($value);
}

function social_feed_media_v1($items): array
{
    if(!is_array($items)||!array_is_list($items)||count($items)>4)throw new InvalidArgumentException('Attach up to four media links.');
    $out=[];
    foreach($items as $item){
        if(!is_array($item)||!in_array($item['kind']??'',['image','audio','video','link'],true))throw new InvalidArgumentException('Choose an image, audio, video or link.');
        $url=social_feed_text_v1($item['url']??'',2000,'Media URL');$p=parse_url($url);
        if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&$p['port']!==443)throw new InvalidArgumentException('Media links must use public HTTPS URLs without credentials.');
        $host=strtolower(trim($p['host'],'[]'));
        if(!str_contains($host,'.')||preg_match('/(?:^|\.)(?:localhost|local|internal)$/D',$host)||filter_var($host,FILTER_VALIDATE_IP)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new InvalidArgumentException('Use a public media host.');
        $out[]=['kind'=>$item['kind'],'url'=>$url,'alt'=>social_feed_text_v1($item['alt']??'',250,'Media description')];
    }
    return $out;
}

function social_feed_blocked_v1(PDO $pdo,int $a,int $b): bool
{
    $q=$pdo->prepare('SELECT 1 FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1');$q->execute([$a,$b,$b,$a]);return (bool)$q->fetchColumn();
}

function social_feed_profile_v1(PDO $pdo,int $viewer,int $id): array
{
    $q=$pdo->prepare('SELECT u.id,u.display_name,u.avatar_path,u.is_active,p.username,p.bio,p.is_public FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    $own=$viewer===$id;
    if(!$r||!$r['is_active']||(!$own&&(!$r['is_public']||social_feed_blocked_v1($pdo,$viewer,$id))))throw new RuntimeException('Profile is unavailable.',404);
    $q=$pdo->prepare('SELECT 1 FROM user_follows WHERE follower_user_id=? AND followed_user_id=?');$q->execute([$viewer,$id]);$following=(bool)$q->fetchColumn();
    $q=$pdo->prepare('SELECT show_followers,show_following FROM user_social_settings WHERE user_id=?');$q->execute([$id]);$privacy=$q->fetch(PDO::FETCH_ASSOC)?:['show_followers'=>1,'show_following'=>1];
    $counts=[];
    foreach(['followers'=>'followed_user_id','following'=>'follower_user_id'] as $key=>$column){
        $q=$pdo->prepare('SELECT COUNT(*) FROM user_follows WHERE '.$column.'=?');$q->execute([$id]);$counts[$key]=($own||$privacy['show_'.$key])?(int)$q->fetchColumn():null;
    }
    $revision=hash('sha256',json_encode([$r['display_name'],$r['username']??'',$r['bio']??'',(bool)$r['is_public']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $avatar='';if($r['is_public']&&preg_match('~/uploads/avatars/([a-zA-Z0-9._-]+\.(?:jpg|jpeg|png|webp))$~i',(string)$r['avatar_path'],$m))$avatar=url('/api/profile-media.php?bucket=avatars&file='.rawurlencode($m[1]));
    return ['id'=>$id,'display_name'=>(string)$r['display_name'],'username'=>(string)($r['username']??''),'bio'=>(string)($r['bio']??''),'avatar_url'=>$avatar,'is_public'=>(bool)$r['is_public'],'is_self'=>$own,'following'=>$following,'can_follow'=>!$own,'counts'=>$counts,'revision'=>$revision];
}

function social_feed_page_v1(PDO $pdo,int $viewer,array $input): array
{
    $mode=$input['mode']??'following';if(!in_array($mode,['following','discover','profile'],true))throw new InvalidArgumentException('Unknown feed.');
    $before=filter_var($input['before']??0,FILTER_VALIDATE_INT);if($before===false||$before<0)throw new InvalidArgumentException('Invalid feed cursor.');
    $author=filter_var($input['user_id']??$viewer,FILTER_VALIDATE_INT);if($author===false||$author<1)throw new InvalidArgumentException('Choose a profile.');
    if($mode==='profile')social_feed_profile_v1($pdo,$viewer,$author);
    $sql="SELECT p.*,u.display_name,up.username FROM social_feed_posts_v1 p JOIN users u ON u.id=p.user_id LEFT JOIN user_profiles up ON up.user_id=p.user_id
      WHERE p.deleted_at IS NULL AND u.is_active=1 AND (?=0 OR p.id<?)
      AND (p.user_id=? OR (up.is_public=1 AND (p.visibility='public' OR (p.visibility='followers' AND EXISTS(SELECT 1 FROM user_follows f WHERE f.follower_user_id=? AND f.followed_user_id=p.user_id)))
      AND NOT EXISTS(SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=p.user_id) OR (b.blocker_user_id=p.user_id AND b.blocked_user_id=?))))";
    $params=[$before,$before,$viewer,$viewer,$viewer,$viewer];
    if($mode==='following'){$sql.=' AND (p.user_id=? OR EXISTS(SELECT 1 FROM user_follows f WHERE f.follower_user_id=? AND f.followed_user_id=p.user_id))';array_push($params,$viewer,$viewer);}
    if($mode==='profile'){$sql.=' AND p.user_id=?';$params[]=$author;}
    $q=$pdo->prepare($sql.' ORDER BY p.id DESC LIMIT 21');$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>20;$rows=array_slice($rows,0,20);
    $items=array_map(static fn(array $r):array=>['id'=>$r['public_id'],'cursor'=>(int)$r['id'],'body'=>$r['body'],'media'=>json_decode($r['media_json'],true,16,JSON_THROW_ON_ERROR),'visibility'=>$r['visibility'],'created_at'=>$r['created_at'].'Z','author'=>['id'=>(int)$r['user_id'],'display_name'=>$r['display_name'],'username'=>$r['username']??''],'can_delete'=>(int)$r['user_id']===$viewer],$rows);
    return ['items'=>$items,'has_more'=>$more,'next_cursor'=>$more?(int)end($rows)['id']:0];
}

function social_feed_dispatch_v1(PDO $pdo,array $user,array $input,callable $recheck): array
{
    $uid=(int)$user['id'];$action=$input['action']??'bootstrap';
    if(!in_array($action,['bootstrap','feed','profile','people','post.create','post.delete','profile.update','follow'],true))throw new InvalidArgumentException('Unknown Feed action.');
    if($action==='bootstrap'){profile_for_user($pdo,$uid,true);return ['viewer'=>social_feed_profile_v1($pdo,$uid,$uid)];}
    if($action==='feed')return ['feed'=>social_feed_page_v1($pdo,$uid,$input)];
    if($action==='profile')return ['profile'=>social_feed_profile_v1($pdo,$uid,(int)($input['user_id']??$uid))];
    if($action==='people'){
        $term=social_feed_text_v1($input['q']??'',80,'Search');
        $q=$pdo->prepare("SELECT u.id FROM users u JOIN user_profiles p ON p.user_id=u.id WHERE u.is_active=1 AND p.is_public=1 AND u.id<>? AND (LOCATE(?,u.display_name)>0 OR LOCATE(?,p.username)>0) AND NOT EXISTS(SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=?)) ORDER BY u.id DESC LIMIT 30");$q->execute([$uid,$term,$term,$uid,$uid]);
        return ['people'=>array_map(fn($id)=>social_feed_profile_v1($pdo,$uid,(int)$id),$q->fetchAll(PDO::FETCH_COLUMN))];
    }
    // DDL belongs outside this transaction. Lock the same account as pairing/revocation.
    if($action==='profile.update')profile_for_user($pdo,$uid,true);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1 FOR UPDATE');$q->execute([$uid]);if(!$q->fetchColumn())throw new RuntimeException('Account is unavailable.',401);$recheck();
        if($action==='post.create'){
            $id=social_feed_text_v1($input['request_id']??'',36,'Request ID');if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$id))throw new InvalidArgumentException('A valid request ID is required.');
            $body=social_feed_text_v1($input['body']??'',10000,'Post');$media=social_feed_media_v1($input['media']??[]);$visibility=$input['visibility']??'public';
            if(!in_array($visibility,['public','followers','private'],true)||($body===''&&!$media))throw new InvalidArgumentException('Write a post or attach media, and choose its audience.');
            $hash=hash('sha256',json_encode([$body,$media,$visibility],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $q=$pdo->prepare('SELECT public_id,content_hash,deleted_at FROM social_feed_posts_v1 WHERE user_id=? AND request_id=?');$q->execute([$uid,$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if($old){if(!hash_equals($old['content_hash'],$hash))throw new RuntimeException('This post request was already used for different content.',409);$result=['post_id'=>$old['public_id'],'replayed'=>true,'deleted'=>(bool)$old['deleted_at']];}
            else{
                $q=$pdo->prepare('SELECT COUNT(*) FROM social_feed_posts_v1 WHERE user_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)');$q->execute([$uid]);if((int)$q->fetchColumn()>=60)throw new RuntimeException('Posting limit reached. Try again later.',429);
                $public=social_feed_uuid_v1();$q=$pdo->prepare('INSERT INTO social_feed_posts_v1(public_id,user_id,request_id,content_hash,body,media_json,visibility,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())');$q->execute([$public,$uid,$id,$hash,$body,json_encode($media,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$visibility]);$result=['post_id'=>$public,'replayed'=>false];
            }
        }elseif($action==='post.delete'){
            $q=$pdo->prepare('SELECT user_id FROM social_feed_posts_v1 WHERE public_id=? FOR UPDATE');$q->execute([social_feed_text_v1($input['id']??'',36,'Post ID')]);if((int)$q->fetchColumn()!==$uid)throw new RuntimeException('Post is unavailable.',404);
            $pdo->prepare('UPDATE social_feed_posts_v1 SET deleted_at=COALESCE(deleted_at,UTC_TIMESTAMP()) WHERE public_id=? AND user_id=?')->execute([$input['id'],$uid]);$result=['deleted'=>true];
        }elseif($action==='profile.update'){
            $q=$pdo->prepare('SELECT user_id FROM user_profiles WHERE user_id=? FOR UPDATE');$q->execute([$uid]);
            $current=social_feed_profile_v1($pdo,$uid,$uid);if(!hash_equals($current['revision'],(string)($input['expected_revision']??'')))throw new RuntimeException('Profile changed. Reload it before saving.',409);
            $name=social_feed_text_v1($input['display_name']??'',120,'Display name');$bio=social_feed_text_v1($input['bio']??'',2000,'Bio');$username=social_feed_text_v1($input['username']??'',60,'Username');$public=$input['is_public']??false;
            if($name===''||!is_bool($public)||($username!==''&&(!profile_username_valid($username)||$username!==profile_username_normalize($username)))||($public&&$username===''))throw new InvalidArgumentException('Choose a name and a valid username before making your profile public.');
            $q=$pdo->prepare('SELECT user_id FROM user_profiles WHERE username=? AND user_id<>?');$q->execute([$username,$uid]);if($username!==''&&$q->fetchColumn())throw new RuntimeException('Username is already in use.',409);
            $pdo->prepare('UPDATE users SET display_name=? WHERE id=?')->execute([$name,$uid]);$pdo->prepare('UPDATE user_profiles SET username=?,bio=?,is_public=? WHERE user_id=?')->execute([$username===''?null:$username,$bio,(int)$public,$uid]);$result=['profile'=>social_feed_profile_v1($pdo,$uid,$uid)];
        }else{
            $id=(int)($input['user_id']??0);if($id===$uid||$id<1||!is_bool($input['following']??null))throw new InvalidArgumentException('Choose another profile and a follow state.');
            if($input['following'])social_feed_profile_v1($pdo,$uid,$id);
            if($input['following'])$pdo->prepare('INSERT IGNORE INTO user_follows(follower_user_id,followed_user_id) VALUES(?,?)')->execute([$uid,$id]);
            else $pdo->prepare('DELETE FROM user_follows WHERE follower_user_id=? AND followed_user_id=?')->execute([$uid,$id]);
            $result=['following'=>$input['following'],'user_id'=>$id];
            try{$result['profile']=social_feed_profile_v1($pdo,$uid,$id);}catch(RuntimeException $e){if($input['following'])throw $e;}
        }
        $recheck();$pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
