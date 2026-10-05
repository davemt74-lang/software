<?php
declare(strict_types=1);
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/music-module-fixture-root');
function check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $call,string $message):void{try{$call();}catch(Throwable $e){return;}throw new RuntimeException($message);}
class MusicFixturePDO extends PDO{
    public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
}
$pdo=new MusicFixturePDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('NOW',static fn()=>date('Y-m-d H:i:s'));
function db():PDO{global $pdo;return $pdo;}
function current_user():?array{global $viewer;return $viewer;}
function table_exists(string $table):bool{$s=db()->prepare('SELECT 1 FROM sqlite_master WHERE type=\'table\' AND name=?');$s->execute([$table]);return (bool)$s->fetchColumn();}
function column_exists(string $table,string $column):bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
function user_has_role(string $role,?array $user=null):bool{return ($user??current_user())['role']===$role;}
function music_workspace_enabled_v320(array $user):bool{return !empty($user['enabled']);}
function artist_workspace_v181_schema_ready(?PDO $pdo=null):bool{return true;}
function valid_visibility(string $visibility):bool{return in_array($visibility,['public','members','admin'],true);}
function can_view_visibility(string $visibility,?array $user=null):bool{return $visibility==='public'||($user!==null&&($visibility==='members'||$user['role']==='admin'));}
function can_manage_track_production(array $track,?array $user=null):bool{return music_workspace_resources_v330_can_manage_track(db(),$track,$user);}
function can_view_track(array $track,?array $user=null):bool{return can_manage_track_production($track,$user??current_user())||can_view_visibility((string)$track['visibility'],$user??current_user());}
require dirname(__DIR__).'/includes/functions.php';
require dirname(__DIR__).'/includes/artist-music-v185.php';
require dirname(__DIR__).'/includes/artist-media-v182.php';
require dirname(__DIR__).'/includes/music-workspace-resources-v330.php';
require dirname(__DIR__).'/includes/music-media.php';
$workspaceSource=file_get_contents(dirname(__DIR__).'/includes/artist-workspace-v181.php');
preg_match('/function artist_workspace_v181_public_records\([\s\S]*?\n}/',$workspaceSource,$m);eval($m[0]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER,enabled INTEGER);CREATE TABLE artist_workspaces_v181(id INTEGER PRIMARY KEY,artist_user_id INTEGER);CREATE TABLE artist_team_members(artist_user_id INTEGER,member_user_id INTEGER,team_role TEXT);');
$pdo->exec("INSERT INTO users VALUES(11,'customer',1,1),(12,'customer',1,0),(13,'customer',1,0),(14,'customer',1,1),(15,'admin',1,1);INSERT INTO artist_workspaces_v181 VALUES(1,11),(2,14);INSERT INTO artist_team_members VALUES(11,12,'manager'),(11,13,'producer');");
$owner=['id'=>11,'role'=>'customer'];$manager=['id'=>12,'role'=>'customer'];$producer=['id'=>13,'role'=>'customer'];$outsider=['id'=>14,'role'=>'customer'];$viewer=$owner;
$pdo->exec("CREATE TABLE artist_catalog_tracks_v181(id INTEGER PRIMARY KEY AUTOINCREMENT,workspace_id INTEGER,source_track_id INTEGER,title TEXT,album TEXT DEFAULT '',album_id INTEGER,description TEXT DEFAULT '',genre TEXT DEFAULT '',duration_seconds INTEGER,track_number INTEGER DEFAULT 0,audio_path TEXT DEFAULT '',cover_path TEXT DEFAULT '',cover_photo_id INTEGER,visibility TEXT DEFAULT 'public',is_published INTEGER DEFAULT 1,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE artist_catalog_albums_v181(id INTEGER PRIMARY KEY AUTOINCREMENT,workspace_id INTEGER,source_album_id INTEGER,title TEXT,release_date TEXT,description TEXT DEFAULT '',cover_path TEXT DEFAULT '',cover_photo_id INTEGER,sort_order INTEGER DEFAULT 0,visibility TEXT DEFAULT 'public',is_published INTEGER DEFAULT 1,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE artist_catalog_photos_v181(id INTEGER PRIMARY KEY,workspace_id INTEGER,image_path TEXT);CREATE TABLE tracks(id INTEGER PRIMARY KEY AUTOINCREMENT,workspace_id INTEGER,owner_user_id INTEGER,producer_user_id INTEGER,album_id INTEGER,title TEXT,album TEXT DEFAULT '',duration TEXT DEFAULT '',lyrics TEXT NOT NULL,description TEXT DEFAULT '',genre TEXT DEFAULT '',audio_path TEXT DEFAULT '',cover_path TEXT DEFAULT '',visibility TEXT DEFAULT 'public',is_published INTEGER DEFAULT 1,sort_order INTEGER DEFAULT 0,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE albums(id INTEGER PRIMARY KEY AUTOINCREMENT,workspace_id INTEGER,title TEXT,release_date TEXT,description TEXT DEFAULT '',cover_path TEXT DEFAULT '',visibility TEXT DEFAULT 'public',sort_order INTEGER DEFAULT 0,is_published INTEGER DEFAULT 1);");
$pdo->exec("INSERT INTO artist_catalog_albums_v181(id,workspace_id,title) VALUES(1,1,'Shared title'),(2,2,'Shared title');INSERT INTO artist_catalog_photos_v181 VALUES(1,1,'/uploads/artist-media/1/photos/a.png'),(2,2,'/uploads/artist-media/2/photos/b.png');INSERT INTO artist_catalog_tracks_v181(id,workspace_id,title,album,album_id,audio_path) VALUES(1,1,'Song','Shared title',1,'/uploads/artist-music/1/song.wav'),(2,1,'Native single','',NULL,'/uploads/artist-music/1/second.wav');");
$before=artist_music_v185_track($pdo,1,1);
$input=['id'=>1,'title'=>'Edited','genre'=>'Rock','duration_seconds'=>'123','track_number'=>'2','album_id'=>1,'cover_photo_id'=>1,'visibility'=>'public','is_published'=>1,'revision'=>music_catalog_revision($before)];
music_catalog_write($pdo,1,$manager,'track','save',$input);
$row=artist_music_v185_track($pdo,1,1);$source=(int)$row['source_track_id'];check($source>0,'New catalog save has no real backing');
$backing=$pdo->query('SELECT * FROM tracks WHERE id='.$source)->fetch();check($backing['title']==='Edited'&&$backing['duration']==='2:03','Backing metadata not synchronized');
check(music_workspace_resources_v330_ensure_production_track($pdo,1,1,$owner)['id']===$source,'Duplicate Studio backing was created');
check((int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn()===1,'Repeated materialization duplicated track');
rejects(fn()=>music_catalog_write($pdo,1,$owner,'track','save',$input),'Stale edit overwrote a newer version');
rejects(fn()=>music_catalog_write($pdo,1,$outsider,'track','save',['id'=>1,'title'=>'Foreign','is_published'=>1]),'Foreign account wrote catalog');
rejects(fn()=>music_catalog_write($pdo,1,$producer,'track','save',['id'=>1,'title'=>'Producer','is_published'=>1]),'Producer wrote catalog');
rejects(fn()=>music_catalog_write($pdo,1,$owner,'track','save',['id'=>1,'title'=>'Foreign art','cover_photo_id'=>2,'is_published'=>1]),'Foreign artwork accepted');
rejects(fn()=>music_catalog_write($pdo,1,$owner,'track','save',['id'=>1,'title'=>'Foreign album','album_id'=>2,'is_published'=>1]),'Foreign album accepted');
rejects(fn()=>music_catalog_write($pdo,1,$owner,'track','save',['id'=>1,'title'=>str_repeat('x',191)]),'Oversized title accepted');
check(!db()->inTransaction(),'Failed mutation leaked a transaction');
$pdo->exec("CREATE TRIGGER reject_track_sync BEFORE UPDATE ON tracks BEGIN SELECT RAISE(ABORT,'forced sync failure'); END;");
rejects(fn()=>music_catalog_write($pdo,1,$owner,'track','save',['id'=>1,'title'=>'Rollback','album_id'=>1,'is_published'=>1]),'Sync failure was hidden');
check(artist_music_v185_track($pdo,1,1)['title']==='Edited','Catalog persisted despite backing failure');$pdo->exec('DROP TRIGGER reject_track_sync');
rejects(fn()=>music_catalog_delete_photo($pdo,1,1),'Referenced cover photo was deleted');
check(music_catalog_delete_photo($pdo,2,2)==='/uploads/artist-media/2/photos/b.png','Unused owned photo cannot be removed');
$pdo->exec('UPDATE tracks SET producer_user_id=13,is_published=0 WHERE id='.$source.';UPDATE artist_catalog_tracks_v181 SET is_published=0 WHERE id=1');
check(artist_music_v185_public_track($pdo,1,$producer)!==null,'Assigned producer cannot read draft');
$pdo->exec('UPDATE tracks SET producer_user_id=NULL WHERE id='.$source);check(artist_music_v185_public_track($pdo,1,$producer)===null,'Unassigned producer can read draft');
check(get_track_by_id($source)===null&&get_track_by_id(1000000001)===null,'Unpublished source was resurrected');
$pdo->exec('UPDATE artist_catalog_tracks_v181 SET is_published=1 WHERE id=1');check(artist_music_v185_public_track($pdo,1,$outsider)===null,'Public media resurrected unpublished backing');
$pdo->exec('UPDATE tracks SET is_published=1 WHERE id='.$source.';UPDATE artist_catalog_tracks_v181 SET is_published=1 WHERE id=1');
check(get_track_by_id($source)['title']==='Edited','Detail source differs from Player list');
check(get_track_by_id(1000000001)['id']===1000000001,'Historical artist reference lost its identity');
$merged=merge_player_track_catalogs([$backing],[artist_music_v185_track($pdo,1,1),artist_music_v185_track($pdo,1,2)]);
check(count($merged)===2&&(int)$merged[0]['artist_track_id']===1,'Native art metadata missing from deduplicated backing');
check(music_catalog_player_id(array_column($merged,null,'id'),1)===$source,'Artist favorites lost after Studio materialization');
$pdo->exec("CREATE TABLE playlists(id INTEGER PRIMARY KEY,owner_user_id INTEGER,visibility TEXT);CREATE TABLE playlist_tracks(playlist_id INTEGER,track_id INTEGER,sort_order INTEGER,added_at TEXT);CREATE TABLE artist_workspace_playlist_tracks_v181(playlist_id INTEGER,artist_track_id INTEGER,sort_order INTEGER,added_at TEXT);INSERT INTO playlists VALUES(1,11,'members');INSERT INTO artist_workspace_playlist_tracks_v181 VALUES(1,2,0,'2026-01-01');INSERT INTO playlist_tracks VALUES(1,".$source.",1,'2026-01-01');");
$playlist=music_catalog_playlist_tracks($pdo,1,$owner);check(array_column($playlist,'id')===[1000000002,$source],'Mixed playlist order is wrong');
$pdo->exec('UPDATE artist_catalog_tracks_v181 SET is_published=0 WHERE id=2');check(count(music_catalog_playlist_tracks($pdo,1,$owner))===1,'Draft leaked into playlist');
$pdo->exec('UPDATE artist_catalog_tracks_v181 SET is_published=1 WHERE id=2');
$albumList=music_catalog_albums($pdo,$owner,array_column($merged,null,'id'));foreach($albumList as $album){if((int)$album['workspace_id']===2)check(count($album['tracks'])===0,'Same-title album pulled another workspace songs');}
$sourceAlbum=(int)$row['album_id'];music_catalog_write($pdo,1,$owner,'album','save',['id'=>1,'title'=>'Renamed','cover_photo_id'=>1,'is_published'=>1]);
check($pdo->query('SELECT album FROM tracks WHERE id='.$source)->fetchColumn()==='Renamed','Album rename missed backing tracks');
music_catalog_write($pdo,1,$owner,'album','delete',['id'=>1]);check($pdo->query('SELECT album_id FROM tracks WHERE id='.$source)->fetchColumn()===null,'Album deletion did not detach Studio backing');
music_catalog_write($pdo,1,$owner,'track','delete',['id'=>1]);check((int)$pdo->query('SELECT is_published FROM tracks WHERE id='.$source)->fetchColumn()===0,'Catalog deletion left published audio');check((int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn()===1,'Catalog deletion destroyed Studio history');
$_SESSION=['music_workspace_id'=>1];check(music_workspace_resources_v330_resolve_active($pdo,$owner,2)===null,'Explicit foreign workspace silently fell back');
$pdo->exec('UPDATE users SET enabled=0 WHERE id=11');check(!music_workspace_resources_v330_can_access($pdo,1,$manager),'Disabled workspace still admits manager');$pdo->exec('UPDATE users SET enabled=1 WHERE id=11');
$pdo->exec('DELETE FROM artist_team_members WHERE member_user_id=12');check(!music_workspace_resources_v330_can_access($pdo,1,$manager),'Removed team member still has access');
foreach([['',100,[0,99,200]],['bytes=0-',100,[0,99,206]],['bytes=5-500',100,[5,99,206]],['bytes=-5',100,[95,99,206]],['bytes=-500',100,[0,99,206]],['bytes=100-',100,null],['bytes=-0',100,null],['bytes=-',100,null],['bytes=0-1,3-4',100,null],['other=0-1',100,null],['bytes=10-5',100,null]] as [$request,$size,$expected])check(music_media_range($request,$size)===$expected,'Byte range failure: '.$request);
$path=tempnam(sys_get_temp_dir(),'music-mime-');file_put_contents($path,'not an image');check(music_media_mime($path,'cover')===null,'Unsupported file served as image');unlink($path);
echo "MUSIC_MODULE_INTEGRITY=PASS\n";
