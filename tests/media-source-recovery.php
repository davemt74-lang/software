<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/vp3-media-'.bin2hex(random_bytes(6));
define('STONEFELLOW_ROOT',$root);
mkdir($root.'/uploads/stems/project',0700,true);
require dirname(__DIR__).'/includes/stem-import-media.php';
$checks=0;
function check(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejects(callable $f,string $message):void{try{$f();}catch(RuntimeException $e){check(true,$message);return;}throw new RuntimeException($message);}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW',static fn():string=>'2026-10-26 00:00:00');
$pdo->exec('CREATE TABLE track_stems(id INTEGER PRIMARY KEY,track_id INTEGER,project_id INTEGER,file_path TEXT,is_active INTEGER)');
$path='/uploads/stems/project/original.wav';
$pcm=str_repeat(pack('v',500),8000);
$wav='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($pcm)).$pcm;
file_put_contents($root.$path,$wav);
$pdo->prepare('INSERT INTO track_stems VALUES(?,?,?,?,?)')->execute([1,4,2,$path,0]);
$pdo->exec("INSERT INTO track_stems VALUES(2,4,2,'/uploads/stems/project/missing.wav',0),(3,99,9,'/uploads/stems/project/original.wav',0),(4,4,2,'/uploads/stems/project/original.wav',1)");
try{
 stem_import_validate_staged_media($pdo,4,2,[1],1);check(true,'Real playable source passes before activation');
 rejects(static fn()=>stem_import_validate_staged_media($pdo,4,2,[2],1),'Missing file cannot be activated');
 rejects(static fn()=>stem_import_validate_staged_media($pdo,4,2,[3],1),'Another project cannot be activated');
 rejects(static fn()=>stem_import_validate_staged_media($pdo,4,2,[4],1),'Existing active rows cannot masquerade as staging');
 rejects(static fn()=>stem_import_validate_staged_media($pdo,4,2,[1,1],2),'Duplicate row ids cannot satisfy the imported count');
 rejects(static fn()=>stem_import_validate_staged_media($pdo,4,2,[],0),'Empty imports cannot replace a project');
 file_put_contents($root.$path,'<html>upload error</html>');
 rejects(static fn()=>stem_import_validate_staged_media($pdo,4,2,[1],1),'An HTML/error upload is not registered as audio');
 file_put_contents($root.$path,substr($wav,0,-4));
 rejects(static fn()=>stem_import_validate_staged_media($pdo,4,2,[1],1),'Truncated WAV cannot be registered');
 file_put_contents($root.$path,$wav);
 stem_import_validate_staged_media($pdo,4,2,[1],1);
 check((int)$pdo->query('SELECT COUNT(*) FROM track_stems WHERE is_active=1')->fetchColumn()===1,'Validation failures preserve current registered project');
 require dirname(__DIR__).'/includes/stems-v30.php';
 $api=file_get_contents(dirname(__DIR__).'/api/stem-direct-v79.php');
 $a=strpos($api,"        foreach ((\$save['old_paths'] ?? []) as \$oldPath) {");
 $b=strpos($api,'        direct_stem_cleanup($dir);',$a);
 $save=['old_paths'=>[$path,'/uploads/stems/project/unused.wav']];$track=['audio_path'=>$path];
 file_put_contents($root.'/uploads/stems/project/unused.wav',$wav);
 eval(substr($api,$a,$b-$a));
 check(is_file($root.$path),'Reimport cleanup preserves the referenced original song file');
 check(!is_file($root.'/uploads/stems/project/unused.wav'),'Unreferenced replaced stems still clean up');

 // Execute the actual production catalog synchronization, with authorization
 // and catalog lookup fixtures and real SQL updates against SQLite.
 $source=file_get_contents(dirname(__DIR__).'/includes/music-workspace-resources-v330.php');
 $a=strpos($source,'function music_workspace_resources_v330_ensure_production_track_locked(');
 $b=strpos($source,"\nfunction ",$a+1);eval(substr($source,$a,$b===false?null:$b-$a));
 $pdo->exec('CREATE TABLE tracks(id INTEGER PRIMARY KEY,workspace_id INTEGER,owner_user_id INTEGER,title TEXT,album TEXT,album_id INTEGER,duration TEXT,description TEXT,genre TEXT,audio_path TEXT,cover_path TEXT,visibility TEXT,is_published INTEGER,updated_at TEXT)');
 $pdo->prepare('INSERT INTO tracks(id,workspace_id,audio_path) VALUES(4,1,?)')->execute([$path]);
 $catalog=['source_track_id'=>4,'album_id'=>0,'title'=>'Original Song','description'=>'Updated metadata','audio_path'=>'','visibility'=>'private','is_published'=>1];
 $allowed=true;
 $row=music_workspace_resources_v330_ensure_production_track_locked($pdo,1,7,['id'=>7]);
 check($row['audio_path']===$path,'Empty catalog shadow does not erase original imported audio');
 check($row['description']==='Updated metadata','Normal metadata still updates');
 $catalog['audio_path']='/uploads/artist-music/new-master.wav';
 $row=music_workspace_resources_v330_ensure_production_track_locked($pdo,1,7,['id'=>7]);
 check($row['audio_path']===$catalog['audio_path'],'Explicit replacement audio still updates');
 $allowed=false;
 rejects(static fn()=>music_workspace_resources_v330_ensure_production_track_locked($pdo,1,7,['id'=>8]),'Denied synchronization remains denied');
 echo 'MEDIA_SOURCE_RECOVERY=PASS ('.$checks." assertions)\n";
}finally{
 foreach(glob($root.'/uploads/stems/project/*')?:[] as $file)unlink($file);
 rmdir($root.'/uploads/stems/project');rmdir($root.'/uploads/stems');rmdir($root.'/uploads');rmdir($root);
}
function music_workspace_resources_v330_can_manage(...$args):bool{return $GLOBALS['allowed'];}
function music_workspace_resources_v330_catalog_track(...$args):array{return $GLOBALS['catalog'];}
function music_workspace_resources_v330_workspace_owner_id(...$args):int{return 7;}
function music_workspace_resources_v330_member_role(...$args):string{return 'artist';}
