<?php
declare(strict_types=1);

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
$user=(string)getenv('VP3_TEST_MYSQL_USER');
$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
if($dsn==='')throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
$testPdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

function db(): ?PDO { global $testPdo; return $testPdo; }
function table_exists(string $table): bool {
    $stmt=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    $stmt->execute([$table]);return (bool)$stmt->fetchColumn();
}
function column_exists(string $table,string $column): bool {
    $stmt=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    $stmt->execute([$table,$column]);return (bool)$stmt->fetchColumn();
}
function current_user(): ?array { return $GLOBALS['testCurrentUser']??null; }
function user_has_role(string $role,?array $user=null): bool { return $role==='admin'&&(($user['role']??'')==='admin'); }
function music_workspace_resources_v330_workspace_owner_id(PDO $pdo,int $workspaceId): int {
    $stmt=$pdo->prepare('SELECT artist_user_id FROM artist_workspaces_v181 WHERE id=?');
    $stmt->execute([$workspaceId]);return (int)$stmt->fetchColumn();
}
function music_workspace_resources_v330_member_role(PDO $pdo,int $workspaceId,int $userId): string {
    $owner=music_workspace_resources_v330_workspace_owner_id($pdo,$workspaceId);
    if($owner===$userId)return 'owner';
    $stmt=$pdo->prepare('SELECT team_role FROM artist_team_members WHERE artist_user_id=? AND member_user_id=? LIMIT 1');
    $stmt->execute([$owner,$userId]);return (string)($stmt->fetchColumn()?:'');
}
function music_workspace_resources_v330_can_access(PDO $pdo,int $workspaceId,?array $user=null): bool {
    $user??=current_user();if(!$user)return false;
    if(user_has_role('admin',$user))return true;
    return music_workspace_resources_v330_member_role($pdo,$workspaceId,(int)($user['id']??0))!=='';
}
function music_workspace_resources_v330_can_manage(PDO $pdo,int $workspaceId,string $capability,?array $user=null): bool {
    $user??=current_user();if(!$user)return false;
    if(user_has_role('admin',$user))return true;
    $role=music_workspace_resources_v330_member_role($pdo,$workspaceId,(int)($user['id']??0));
    if($role==='owner')return true;
    if($role==='manager')return in_array($capability,['profile','tracks','albums','releases','shows','credits','media','team'],true);
    if($role==='producer')return in_array($capability,['production','credits'],true);
    return false;
}

$testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['music_artist_authority_events_v100','music_artist_memberships_v100','music_artists_v100','artist_team_members','artist_workspaces_v181','users'] as $table)$testPdo->exec('DROP TABLE IF EXISTS '.$table);
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');

$testPdo->exec("CREATE TABLE users (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  display_name VARCHAR(190) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE artist_workspaces_v181 (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  artist_user_id INT UNSIGNED NOT NULL,
  workspace_name VARCHAR(190) NOT NULL,
  profile_slug VARCHAR(190) NULL,
  bio TEXT NULL,
  profile_image_path VARCHAR(500) NOT NULL DEFAULT '',
  cover_image_path VARCHAR(500) NOT NULL DEFAULT '',
  website_url VARCHAR(500) NOT NULL DEFAULT '',
  instagram_url VARCHAR(500) NOT NULL DEFAULT '',
  tiktok_url VARCHAR(500) NOT NULL DEFAULT '',
  youtube_url VARCHAR(500) NOT NULL DEFAULT '',
  spotify_url VARCHAR(500) NOT NULL DEFAULT '',
  apple_music_url VARCHAR(500) NOT NULL DEFAULT '',
  UNIQUE KEY uq_artist_workspace_user (artist_user_id),
  UNIQUE KEY uq_artist_workspace_profile_slug (profile_slug),
  CONSTRAINT fk_test_workspace_user FOREIGN KEY (artist_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE artist_team_members (
  artist_user_id INT UNSIGNED NOT NULL,
  member_user_id INT UNSIGNED NOT NULL,
  team_role VARCHAR(30) NOT NULL,
  PRIMARY KEY(artist_user_id,member_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$testPdo->exec("INSERT INTO users(id,display_name) VALUES (1,'Stonefellow Owner'),(2,'Manager'),(3,'Producer'),(4,'Outsider')");
$testPdo->exec("INSERT INTO artist_workspaces_v181(artist_user_id,workspace_name,profile_slug,bio) VALUES (1,'Stonefellow','stonefellow','Existing profile bio')");
$workspaceId=(int)$testPdo->lastInsertId();
$testPdo->exec("INSERT INTO artist_team_members(artist_user_id,member_user_id,team_role) VALUES (1,2,'manager'),(1,3,'producer')");

require dirname(__DIR__).'/includes/music-artist-v100.php';

music_artist_v100_ensure_schema($testPdo);
if(!music_artist_v100_schema_ready($testPdo))throw new RuntimeException('Music Artist schema did not become ready.');

$primary=music_artist_v100_primary_for_workspace($testPdo,$workspaceId);
if(!$primary)throw new RuntimeException('Existing workspace did not receive a primary artist.');
if((string)$primary['name']!=='Stonefellow'||(string)$primary['slug']!=='stonefellow')throw new RuntimeException('Legacy artist identity was not preserved.');
if((string)$primary['bio']!=='Existing profile bio')throw new RuntimeException('Legacy artist profile metadata was not preserved.');
$primaryId=(int)$primary['id'];

$ownerMembership=music_artist_v100_membership($testPdo,$primaryId,1);
if((string)($ownerMembership['artist_role']??'')!=='owner')throw new RuntimeException('Workspace owner did not receive canonical artist ownership.');

$manager=['id'=>2,'role'=>'user'];
$producer=['id'=>3,'role'=>'user'];
$outsider=['id'=>4,'role'=>'user'];
if(!music_artist_v100_can_manage($testPdo,$primaryId,'catalog',$manager))throw new RuntimeException('Primary artist did not preserve Manager authority.');
if(!music_artist_v100_can_manage($testPdo,$primaryId,'production',$producer))throw new RuntimeException('Primary artist did not preserve Producer authority.');
if(music_artist_v100_can_manage($testPdo,$primaryId,'profile',$producer))throw new RuntimeException('Producer gained profile authority.');
if(music_artist_v100_can_access($testPdo,$primaryId,$outsider))throw new RuntimeException('Workspace outsider gained artist access.');

$secondary=music_artist_v100_create($testPdo,$workspaceId,$manager,'Side Project','side-project');
$secondaryId=(int)$secondary['id'];
if(!empty($secondary['is_primary']))throw new RuntimeException('Second artist incorrectly became primary.');
if(!music_artist_v100_can_manage($testPdo,$secondaryId,'catalog',$manager))throw new RuntimeException('Creator manager did not receive secondary artist authority.');
if(music_artist_v100_can_access($testPdo,$secondaryId,$producer))throw new RuntimeException('Unassigned Producer gained secondary artist access.');

music_artist_v100_set_membership($testPdo,$secondaryId,3,'producer','active',$manager);
if(!music_artist_v100_can_manage($testPdo,$secondaryId,'production',$producer))throw new RuntimeException('Explicit Producer membership did not grant production authority.');
if(music_artist_v100_can_manage($testPdo,$secondaryId,'profile',$producer))throw new RuntimeException('Artist Producer gained profile authority.');

try{
    music_artist_v100_set_membership($testPdo,$secondaryId,2,'owner','active',['id'=>1,'role'=>'user']);
    throw new RuntimeException('Non-workspace owner was allowed to become canonical artist owner.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Non-workspace owner was allowed to become canonical artist owner.')throw $e;
}

music_artist_v100_ensure_schema($testPdo);
$count=(int)$testPdo->query('SELECT COUNT(*) FROM music_artists_v100 WHERE workspace_id='.$workspaceId)->fetchColumn();
if($count!==2)throw new RuntimeException('Idempotent schema ensure duplicated artist identities.');

$events=(int)$testPdo->query('SELECT COUNT(*) FROM music_artist_authority_events_v100')->fetchColumn();
if($events<3)throw new RuntimeException('Artist authority events were not durably recorded.');

echo "Music Platform V1 Section 1 MySQL integration: PASS\n";
