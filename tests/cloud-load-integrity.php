<?php
declare(strict_types=1);
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
final class MetadataFixturePDO extends PDO {
    public int $reads = 0;
    public function prepare(string $query, array $options=[]): PDOStatement|false { $this->reads++; return parent::prepare($query,$options); }
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false { $this->reads++; return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args); }
}
require dirname(__DIR__).'/includes/schema-metadata.php';
require dirname(__DIR__).'/includes/request-performance.php';
require dirname(__DIR__).'/includes/music-media.php';
$pdo=new MetadataFixturePDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tracks(id INTEGER,workspace_id INTEGER,audio_path TEXT)');
for($i=0;$i<100;$i++) {
    verify(schema_metadata_table_exists($pdo,'tracks'),'Existing table disappeared');
    foreach(['id','workspace_id','audio_path'] as $column) verify(schema_metadata_column_exists($pdo,'tracks',$column),'Existing column disappeared');
}
verify($pdo->reads===2,'Repeated schema checks still hit the database');
verify(!schema_metadata_column_exists($pdo,'tracks','new_column'),'Missing column accepted');
$pdo->exec('ALTER TABLE tracks ADD COLUMN new_column TEXT');
verify(schema_metadata_column_exists($pdo,'tracks','new_column'),'Missing column remained cached after migration');
verify(!schema_metadata_table_exists($pdo,'new_table'),'Missing table accepted');
$pdo->exec('CREATE TABLE new_table(id INTEGER)');
verify(schema_metadata_table_exists($pdo,'new_table'),'Missing table remained cached after migration');
$other=new MetadataFixturePDO('sqlite::memory:');
verify(!schema_metadata_table_exists($other,'tracks'),'Metadata crossed database connections');
verify(!schema_metadata_column_exists($other,'tracks','id'),'Column cache crossed database connections');
// Cache only schema existence. Permission rows, membership and ownership remain live.
$pdo->exec('CREATE TABLE grants(permission TEXT); INSERT INTO grants VALUES("manage")');
verify(schema_metadata_table_exists($pdo,'grants'),'Grants schema missing');
$pdo->exec('DELETE FROM grants');
verify($pdo->query('SELECT COUNT(*) FROM grants')->fetchColumn()===0,'Metadata cached permissions');

$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_FILENAME']='/srv/cloud/media.php';
verify(request_performance_media_read(),'Audio GET must skip optional maintenance');
$_SERVER['REQUEST_METHOD']='HEAD';verify(request_performance_media_read(),'Media HEAD must skip maintenance');
$_SERVER['REQUEST_METHOD']='POST';verify(!request_performance_media_read(),'Mutation maintenance skipped');
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_FILENAME']='/srv/cloud/chat.php';
$_SERVER['REQUEST_URI']='/media.php';verify(!request_performance_media_read(),'Client URL selected maintenance policy');

$root=sys_get_temp_dir().'/cloud-load-media-'.bin2hex(random_bytes(6));mkdir($root);
define('STONEFELLOW_ROOT',$root);
file_put_contents($root.'/song.wav','valid fixture bytes');
$outside=tempnam(sys_get_temp_dir(),'cloud-outside-');file_put_contents($outside,'private');
symlink($outside,$root.'/outside.wav');
try {
    verify(music_media_local_path('/song.wav')===$root.'/song.wav','Owned local media rejected');
    foreach(['','https://example.com/song.wav','php://filter','../'.basename($outside),'/outside.wav',"/song.wav\0",'/missing.wav'] as $path)
        verify(music_media_local_path($path)===null,'Unsafe or missing media accepted: '.json_encode($path));
    session_save_path($root);session_id('cloudload'.bin2hex(random_bytes(6)));verify(session_start(),'Fixture session did not start');$_SESSION['saved']='before-background';
    $id=session_id();request_performance_finish_response();
    verify(session_status()===PHP_SESSION_NONE,'Background work retained session lock');
    session_id($id);verify(session_start(),'Persisted session did not reopen');verify($_SESSION['saved']==='before-background','Session writes were lost');session_destroy();session_write_close();
} finally { unlink($root.'/song.wav');unlink($root.'/outside.wav');rmdir($root);unlink($outside); }
echo "CLOUD_LOAD_INTEGRITY=PASS schema_checks=400 metadata_reads=2\n";
