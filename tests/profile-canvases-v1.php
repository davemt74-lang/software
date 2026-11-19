<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/profile-canvases-v1.php';

$dsn = getenv('VP3_TEST_MYSQL_DSN');
$pdo = $dsn
    ? new PDO($dsn, getenv('VP3_TEST_MYSQL_USER'), getenv('VP3_TEST_MYSQL_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])
    : new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($dsn) {
    $database = 'profile_canvas_'.bin2hex(random_bytes(6));
    $pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE=utf8mb4_unicode_ci');
    register_shutdown_function(static fn() => $pdo->exec('DROP DATABASE '.$database));
    $pdo->exec('USE '.$database);
} else {
    $pdo->exec('PRAGMA foreign_keys=ON');
}

function db(): PDO { return $GLOBALS['pdo']; }
function table_exists(string $table): bool
{
    try { db()->query('SELECT 1 FROM '.$table.' WHERE 1=0'); return true; }
    catch (PDOException) { return false; }
}
function column_exists(string $table, string $column): bool
{
    try { db()->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0'); return true; }
    catch (PDOException) { return false; }
}
function has_permission(string $permission, array $user): bool { return $permission === 'chat.access' && ($user['chat'] ?? true); }
function personal_capability_has_v242(string $permission, array $user): bool
{
    return ($user['knowledge'] ?? true) && ($permission !== 'personal_knowledge.manage' || ($user['manage'] ?? true));
}
function personal_knowledge_available(array $user): bool { return $user['knowledge'] ?? true; }
function vp3_participant_scope_v1(): ?array { return $GLOBALS['participant'] ?? null; }
function user_agent_get_v236(PDO $pdo, int $owner, int $agent): ?array
{
    return in_array([$owner,$agent], [[1,11],[1,12],[2,21]], true) ? ['id'=>$agent,'is_active'=>1] : null;
}
function url(string $path): string { return '/vp3'.$path; }

$checks = 0;
function check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
function denied(int $code, callable $action, string $message): void
{
    try { $action(); }
    catch (Throwable $error) {
        check($error->getCode() === $code, $message.' ('.get_class($error).' '.$error->getCode().': '.$error->getMessage().')');
        return;
    }
    throw new RuntimeException($message.' was allowed');
}

$pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT NOT NULL)');
$pdo->exec('INSERT INTO users VALUES(1,1),(2,1)');
$pdo->exec('CREATE TABLE user_profiles(user_id INT UNSIGNED PRIMARY KEY,username VARCHAR(80),is_public TINYINT NOT NULL)');
$pdo->exec("INSERT INTO user_profiles VALUES(1,'owner-one',1),(2,'owner-two',1)");
$pdo->exec('CREATE TABLE artist_transcript_folders_v177(id BIGINT UNSIGNED PRIMARY KEY,created_by_user_id INT UNSIGNED NOT NULL)');
$pdo->exec('INSERT INTO artist_transcript_folders_v177 VALUES(1,1),(2,1),(3,2),(4,1)');
$pdo->exec('CREATE TABLE knowledge_items(id INT UNSIGNED PRIMARY KEY,created_by_user_id INT UNSIGNED,folder_id BIGINT UNSIGNED,knowledge_scope VARCHAR(20),visibility VARCHAR(20),is_published TINYINT,content_text LONGTEXT)');
$pdo->exec("INSERT INTO knowledge_items VALUES(1,1,1,'personal','private',0,'{}'),(2,1,2,'personal','private',0,'{}'),(3,2,3,'personal','private',0,'{}'),(4,1,4,'personal','private',0,'{}')");
vp3_coding_canvas_schema_v1($pdo);
vp3_coding_canvas_publishing_install_v1($pdo);
$ids = array_map(static fn(int $n): string=>'c0000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT), range(1,4));
$canvasInsert = $pdo->prepare('INSERT INTO vp3_coding_canvases_v1(id,owner_user_id,agent_id,conversation_id,folder_id,title,state_item_id,active_revision,draft_revision) VALUES(?,?,?,?,?,?,?,1,2)');
foreach ([[1,11],[1,12],[2,21],[1,11]] as $index=>[$owner,$agent]) {
    $canvasInsert->execute([$ids[$index],$owner,$agent,$index+10,$index+1,'PRIVATE draft '.$index,$index+1]);
}
$snapshot = json_encode(['commands'=>[],'artifact'=>['html'=>'<h1>Published content</h1>','css'=>'h1{color:navy}','js'=>'']], JSON_THROW_ON_ERROR);
$insert = $pdo->prepare('INSERT INTO vp3_coding_canvas_publications_v1(canvas_id,owner_user_id,slug,generation,revision,title,snapshot_json,snapshot_hash) VALUES(?,?,?,1,1,?,?,?)');
foreach ([[1,'front-page'],[1,'another-page'],[2,'other-owner']] as $index=>[$owner,$slug]) {
    $insert->execute([$ids[$index],$owner,$slug,'Published '.$slug,$snapshot,hash('sha256',$snapshot)]);
}
$profile = ['user_id'=>1,'username'=>'owner-one','is_public'=>1,'is_active'=>1];
$otherProfile = ['user_id'=>2,'username'=>'owner-two','is_public'=>1,'is_active'=>1];
$user = ['id'=>1,'is_active'=>1];
$other = ['id'=>2,'is_active'=>1];
$id = $ids[0];
$pub = vp3_coding_canvas_publication_version_v1($id,1);
$unlistedVersion = vp3_profile_canvas_version_v1($id,0);

check(!vp3_profile_canvases_ready_v1($pdo), 'Missing listing schema is unavailable');
check(vp3_profile_canvases_list_v1($pdo,$profile) === [] && vp3_profile_canvas_release_v1($pdo,$profile,'front-page') === null, 'Missing schema is closed on public reads');
denied(503,fn()=>vp3_profile_canvas_status_v1($pdo,$user,11,$id),'Missing schema blocks owner management');
vp3_profile_canvases_install_v1($pdo);
vp3_profile_canvases_install_v1($pdo);
check(vp3_profile_canvases_ready_v1($pdo), 'Profile Canvas schema installs repeatably');
check((int)$pdo->query('SELECT COUNT(*) FROM vp3_profile_canvases_v1')->fetchColumn() === 0, 'Upgrade never opts an existing publication in');
$initial = vp3_profile_canvas_status_v1($pdo,$user,11,$id);
check($initial === ['profile_canvas'=>['listed'=>false,'url'=>null,'version'=>$unlistedVersion],'eligibility'=>['can_list'=>true,'reason'=>null]], 'Eligible published app starts with an explicit off choice');
check(vp3_profile_canvases_list_v1($pdo,$profile) === [] && vp3_profile_canvas_release_v1($pdo,$profile,'front-page') === null, 'A standalone public app is not automatically a Profile Canvas');

denied(404,fn()=>vp3_profile_canvas_status_v1($pdo,$other,21,$id),'Another owner cannot read settings');
denied(404,fn()=>vp3_profile_canvas_set_v1($pdo,$user,12,$id,true,$unlistedVersion,$pub),'An owner cannot substitute another Agent');
denied(403,fn()=>vp3_profile_canvas_set_v1($pdo,$user+['manage'=>false],11,$id,true,$unlistedVersion,$pub),'Publication management permission is required');
denied(403,fn()=>vp3_profile_canvas_status_v1($pdo,$user+['chat'=>false],11,$id),'Chat permission is required');
$GLOBALS['participant'] = ['owner_user_id'=>1];
denied(403,fn()=>vp3_profile_canvas_status_v1($pdo,$user,11,$id),'Participants cannot access Profile publication controls');
unset($GLOBALS['participant']);
denied(422,fn()=>vp3_profile_canvas_set_v1($pdo,$user,11,$id,true,'bad',$pub),'Malformed listing proof is rejected');
denied(409,fn()=>vp3_profile_canvas_set_v1($pdo,$user,11,$id,true,$unlistedVersion,str_repeat('a',64)),'Stale publication proof is rejected');
$draft = vp3_profile_canvas_status_v1($pdo,$user,11,$ids[3]);
check(!$draft['eligibility']['can_list'], 'An unpublished draft cannot be listed');
denied(409,fn()=>vp3_profile_canvas_set_v1($pdo,$user,11,$ids[3],true,$draft['profile_canvas']['version'],vp3_coding_canvas_publication_version_v1($ids[3],0)),'An active private Canvas is not a published source');

$listed = vp3_profile_canvas_set_v1($pdo,$user,11,$id,true,$unlistedVersion,$pub);
check($listed['profile_canvas']['listed'] && $listed['profile_canvas']['url'] === '/vp3/owner-one/canvas/front-page', 'Owner explicitly lists a published Canvas at the Profile route');
check(vp3_profile_canvas_set_v1($pdo,$user,11,$id,true,$unlistedVersion,$pub) === $listed, 'The immediately retried owner choice is idempotent');
check(vp3_profile_canvases_list_v1($pdo,$profile) === [['title'=>'Published front-page','url'=>'/vp3/owner-one/canvas/front-page','slug'=>'front-page','revision'=>1]], 'Gallery contains only the opted-in published title, URL and revision');
$release = vp3_profile_canvas_release_v1($pdo,$profile,'front-page');
check($release['artifact']['html'] === '<h1>Published content</h1>' && $release['revision'] === 1, 'Full page resolves the published snapshot while draft revision 2 stays private');
check(!str_contains(json_encode(vp3_profile_canvases_list_v1($pdo,$profile)), 'PRIVATE'), 'Private Canvas titles never leak into cards');
check(vp3_profile_canvases_list_v1($pdo,$otherProfile) === [] && vp3_profile_canvas_release_v1($pdo,$otherProfile,'front-page') === null, 'Profile owner and Canvas owner must match');
check(vp3_profile_canvas_release_v1($pdo,$profile,'../front-page') === null, 'Untrusted Canvas route slugs are rejected');

foreach ([['user_profiles','is_public',0],['users','is_active',0]] as [$table,$column,$value]) {
    $key = $table === 'users' ? 'id' : 'user_id';
    $pdo->exec("UPDATE $table SET $column=$value WHERE $key=1");
    check(vp3_profile_canvases_list_v1($pdo,$profile) === [] && vp3_profile_canvas_release_v1($pdo,$profile,'front-page') === null, 'Live '.$column.' revocation overrides a stale request profile');
    check(!vp3_profile_canvas_status_v1($pdo,$user,11,$id)['eligibility']['can_list'], 'Owner settings explain unavailable Profile visibility');
    $pdo->exec("UPDATE $table SET $column=1 WHERE $key=1");
}
$pdo->exec("UPDATE user_profiles SET username='renamed-owner' WHERE user_id=1");
check(vp3_profile_canvases_list_v1($pdo,$profile) === [] && vp3_profile_canvas_release_v1($pdo,$profile,'front-page') === null, 'An old username stops serving this owner’s Canvas');
$renamed = array_replace($profile,['username'=>'renamed-owner']);
check(vp3_profile_canvases_list_v1($pdo,$renamed)[0]['url'] === '/vp3/renamed-owner/canvas/front-page', 'Current profile username supplies the canonical Canvas URL');
$pdo->exec("UPDATE user_profiles SET username='owner-one' WHERE user_id=1");

$pdo->prepare('UPDATE vp3_coding_canvas_publications_v1 SET snapshot_hash=? WHERE canvas_id=?')->execute([str_repeat('0',64),$id]);
check(vp3_profile_canvas_release_v1($pdo,$profile,'front-page') === null && vp3_profile_canvases_list_v1($pdo,$profile) === [], 'Invalid published source hashes are not displayed or served');
$pdo->prepare('UPDATE vp3_coding_canvas_publications_v1 SET snapshot_hash=? WHERE canvas_id=?')->execute([hash('sha256',$snapshot),$id]);
$privateSnapshot = json_encode(['commands'=>['gmail'],'artifact'=>['html'=>'<p>Private email</p>','css'=>'','js'=>'']], JSON_THROW_ON_ERROR);
$pdo->prepare('UPDATE vp3_coding_canvas_publications_v1 SET snapshot_json=?,snapshot_hash=? WHERE canvas_id=?')->execute([$privateSnapshot,hash('sha256',$privateSnapshot),$id]);
check(vp3_profile_canvas_release_v1($pdo,$profile,'front-page') === null, 'The existing publication validator still rejects private commands');
$pdo->prepare('UPDATE vp3_coding_canvas_publications_v1 SET snapshot_json=?,snapshot_hash=? WHERE canvas_id=?')->execute([$snapshot,hash('sha256',$snapshot),$id]);

$pdo->prepare('UPDATE vp3_coding_canvas_publications_v1 SET revision=NULL,generation=2 WHERE canvas_id=?')->execute([$id]);
check(vp3_profile_canvas_release_v1($pdo,$profile,'front-page') === null && vp3_profile_canvases_list_v1($pdo,$profile) === [], 'Unpublishing withdraws the full page and gallery card');
$unpublished = vp3_profile_canvas_status_v1($pdo,$user,11,$id);
check($unpublished['profile_canvas']['listed'] && $unpublished['profile_canvas']['url'] === null && !$unpublished['eligibility']['can_list'], 'Saved choice is distinguishable from currently public availability');
denied(409,fn()=>vp3_profile_canvas_set_v1($pdo,$user,11,$id,false,$listed['profile_canvas']['version'],$pub),'Removal also rejects a stale publication review');
$removed = vp3_profile_canvas_set_v1($pdo,$user+['manage'=>false],11,$id,false,$listed['profile_canvas']['version'],vp3_coding_canvas_publication_version_v1($id,2));
check(!$removed['profile_canvas']['listed'], 'Removal remains available after unpublish or loss of management permission');
$pdo->prepare('UPDATE vp3_coding_canvas_publications_v1 SET revision=1,generation=3 WHERE canvas_id=?')->execute([$id]);
denied(409,fn()=>vp3_profile_canvas_set_v1($pdo,$user,11,$id,true,$unlistedVersion,vp3_coding_canvas_publication_version_v1($id,3)),'An old listing version cannot undo a later remove choice');
check(vp3_profile_canvas_release_v1($pdo,$profile,'front-page') === null, 'Republishing does not undo an explicit Profile removal');
$listed = vp3_profile_canvas_set_v1($pdo,$user,11,$id,true,$removed['profile_canvas']['version'],vp3_coding_canvas_publication_version_v1($id,3));
check(vp3_profile_canvas_release_v1($pdo,$profile,'front-page') !== null, 'Owner can reattach after reviewing the latest public version');
vp3_profile_canvases_install_v1($pdo);
check(vp3_profile_canvas_status_v1($pdo,$user,11,$id) === $listed, 'Repeated schema install preserves saved listing and concurrency version');
$pdo->prepare('DELETE FROM vp3_coding_canvases_v1 WHERE id=?')->execute([$id]);
check((int)$pdo->query('SELECT COUNT(*) FROM vp3_profile_canvases_v1')->fetchColumn() === 0 && vp3_profile_canvases_list_v1($pdo,$profile) === [], 'Deleting the source Canvas cascades its Profile association');
check($pdo->query('SELECT content_text FROM knowledge_items WHERE id=1')->fetchColumn() === '{}', 'Profile publication leaves private app data untouched');

echo 'PROFILE_CANVASES_V1=PASS ('.$checks." checks)\n";
