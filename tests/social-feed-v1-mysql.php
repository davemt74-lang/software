<?php
declare(strict_types=1);
$dsn=(string)getenv('VP3_SECTION7_MYSQL_DSN');if(!$dsn)throw new RuntimeException('An isolated MySQL fixture DSN is required.');
$pdo=new PDO($dsn,'root',(string)getenv('VP3_SECTION7_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='social_feed_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE `'.$database.'`');
function db():PDO{return $GLOBALS['pdo'];}
function url(string $path):string{return '/installed/vp3'.$path;}
function table_exists(string $name):bool{$q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$name]);return (bool)$q->fetchColumn();}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $fn):void{try{$fn();}catch(Throwable $e){check(!db()->inTransaction(),'Denied operation leaked a transaction');return;}throw new RuntimeException('Operation should have been denied');}
require __DIR__.'/../includes/social-network-v320.php';
require __DIR__.'/../includes/profile-agent.php';
require __DIR__.'/../includes/social-feed-v1.php';
try{
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,display_name VARCHAR(120) NOT NULL,is_active TINYINT NOT NULL DEFAULT 1,avatar_path VARCHAR(500) NOT NULL DEFAULT '') ENGINE=InnoDB;
      CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
      INSERT INTO users(id,display_name) VALUES(7,'Owner'),(8,'Other'),(9,'Private'),(10,'Blocked'),(11,'Inactive')");
    social_feed_schema_v1($pdo);social_feed_schema_v1($pdo);
    $dispatch=static fn(int $id,array $input,?callable $check=null):array=>social_feed_dispatch_v1($pdo,['id'=>$id],$input,$check??static function():void{});
    foreach([7,8,9,10,11] as $id)$dispatch($id,['action'=>'bootstrap']);
    $pdo->exec("UPDATE user_profiles SET username=CONCAT('person-',user_id),is_public=1,website_url='https://keep.example' WHERE user_id<>9;UPDATE users SET is_active=0 WHERE id=11");
    $create=static function(int $id,string $body,string $visibility='public')use($dispatch):array{return $dispatch($id,['action'=>'post.create','request_id'=>social_feed_uuid_v1(),'body'=>$body,'visibility'=>$visibility]);};
    $public=$create(8,'Public update');$followers=$create(8,'For followers','followers');$private=$create(8,'Only the author','private');$create(9,'Private profile update');$create(10,'Blocked author');
    $rows=$dispatch(7,['action'=>'feed'])['feed']['items'];check($rows===[],'Following feed included unfollowed authors');
    $dispatch(7,['action'=>'follow','user_id'=>8,'following'=>true]);$dispatch(7,['action'=>'follow','user_id'=>8,'following'=>true]);
    check(vp3_social_following_v320($pdo,7,8),'Follow did not use the canonical VP3 relationship');
    check((int)$pdo->query('SELECT COUNT(*) FROM user_follows')->fetchColumn()===1,'Follow retry duplicated relationship');
    $rows=$dispatch(7,['action'=>'feed'])['feed']['items'];check(count($rows)===2&&$rows[0]['id']===$followers['post_id'],'Following feed lost order/audience or leaked private posts');
    $own=$dispatch(8,['action'=>'feed','mode'=>'profile','user_id'=>8])['feed']['items'];check(count($own)===3,'Author lost private posts');
    $pdo->exec('INSERT INTO user_blocks(blocker_user_id,blocked_user_id) VALUES(10,7)');
    $discover=$dispatch(7,['action'=>'feed','mode'=>'discover'])['feed']['items'];check(count($discover)===2,'Discover leaked a blocked or private-profile post');
    $people=$dispatch(7,['action'=>'people'])['people'];check(array_column($people,'id')===[8],'People search leaked blocked/private/inactive profiles');
    denied(fn()=>$dispatch(7,['action'=>'profile','user_id'=>9]));denied(fn()=>$dispatch(7,['action'=>'follow','user_id'=>10,'following'=>true]));denied(fn()=>$dispatch(7,['action'=>'post.delete','id'=>$public['post_id']]));
    $id=social_feed_uuid_v1();$input=['action'=>'post.create','request_id'=>$id,'body'=>'One write','visibility'=>'public','media'=>[['kind'=>'image','url'=>'https://media.example/photo.png','alt'=>'Photo']]];
    $one=$dispatch(7,$input);$two=$dispatch(7,$input);check($one['post_id']===$two['post_id']&&$two['replayed'],'Post retry was not idempotent');
    denied(fn()=>$dispatch(7,array_replace($input,['body'=>'Changed retry'])));
    $count=(int)$pdo->query('SELECT COUNT(*) FROM social_feed_posts_v1')->fetchColumn();
    $n=0;denied(function()use($dispatch,&$n){$dispatch(7,['action'=>'post.create','request_id'=>social_feed_uuid_v1(),'body'=>'Rollback sentinel'],static function()use(&$n):void{if(++$n===2)throw new RuntimeException('Revoked before commit',403);});});
    check((int)$pdo->query('SELECT COUNT(*) FROM social_feed_posts_v1')->fetchColumn()===$count,'Revoked post committed');
    $dispatch(7,['action'=>'post.delete','id'=>$one['post_id']]);$again=$dispatch(7,$input);check($again['deleted']&&$again['replayed'],'Retry resurrected a deleted post');
    $p=$dispatch(7,['action'=>'profile'])['profile'];
    $updated=$dispatch(7,['action'=>'profile.update','display_name'=>'New name','username'=>'person-7','bio'=>'<script>text only</script>','is_public'=>true,'expected_revision'=>$p['revision']])['profile'];
    check($updated['display_name']==='New name'&&$updated['bio']==='<script>text only</script>','Profile did not save canonical fields');
    check($pdo->query('SELECT website_url FROM user_profiles WHERE user_id=7')->fetchColumn()==='https://keep.example','Profile update erased unrelated settings');
    denied(fn()=>$dispatch(7,['action'=>'profile.update','display_name'=>'Overwrite','username'=>'person-7','bio'=>'','is_public'=>true,'expected_revision'=>$p['revision']]));
    denied(fn()=>$dispatch(7,['action'=>'profile.update','display_name'=>'Owner','username'=>'person-8','bio'=>'','is_public'=>true,'expected_revision'=>$updated['revision']]));
    $pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=8');$dispatch(7,['action'=>'follow','user_id'=>8,'following'=>false]);check(!vp3_social_following_v320($pdo,7,8),'Could not unfollow a now-private profile');
    foreach(['http://example.com/a','https://user:pass@example.com/a','https://127.0.0.1/a','https://host.local/a','javascript:alert(1)'] as $url)denied(fn()=>social_feed_media_v1([['kind'=>'image','url'=>$url]]));
    denied(fn()=>$dispatch(7,['action'=>'post.create','request_id'=>social_feed_uuid_v1(),'body'=>'','media'=>[]]));
    for($i=0;$i<23;$i++)$create(7,'Paged '.$i);
    $first=$dispatch(7,['action'=>'feed','mode'=>'profile','user_id'=>7])['feed'];$second=$dispatch(7,['action'=>'feed','mode'=>'profile','user_id'=>7,'before'=>$first['next_cursor']])['feed'];
    check(count($first['items'])===20&&$first['has_more']&&count($second['items'])===3,'Pagination was not bounded');
    check(!array_intersect(array_column($first['items'],'id'),array_column($second['items'],'id')),'Pagination repeated posts');
    echo "PASS real MySQL posts, shared follows, audiences, blocks, private/inactive profiles, safe media URLs, exact retries, deletion replay, authority rollback, profile conflicts and pagination\n";
}finally{$pdo->exec('DROP DATABASE `'.$database.'`');}
