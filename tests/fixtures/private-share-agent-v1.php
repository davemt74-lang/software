<?php
declare(strict_types=1);

/** Real share records for the read-only agent tests; no share service is mocked. */
function private_share_fixture_insert_v1(PDO $pdo,string $table,array $row): void
{
    $fields=array_keys($row);
    $pdo->prepare('INSERT INTO '.$table.' ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')->execute(array_values($row));
}

function private_share_fixture_share_v1(PDO $pdo,int $id,array $overrides=[]): array
{
    $row=array_replace([
        'id'=>$id,'public_id'=>str_pad(dechex($id),32,'0',STR_PAD_LEFT),
        'sender_user_id'=>2,'recipient_user_id'=>1,'app_key'=>'user.fixture'.$id,
        'app_name'=>'Fixture Share '.$id,'app_version'=>'1.0',
        'package_sha256'=>str_repeat('a',64),'publisher_fingerprint'=>str_repeat('e',64),
        'permission_json'=>'["SECRET_PERMISSION_DETAIL"]','data_schema_version'=>'1',
        'grant_code_hash'=>hash('sha256','SECRET_GRANT_CODE_'.$id),'status'=>'pending',
        'expires_at'=>'2099-01-01 00:00:00','redeemed_at'=>null,'revoked_at'=>null,
        'created_at'=>'2020-01-01 08:00:00','updated_at'=>'2020-01-01 08:00:00',
    ],$overrides);
    private_share_fixture_insert_v1($pdo,'vp3_user_app_private_shares_v240',$row);
    return $row;
}

function private_share_agent_fixture_v1(PDO $pdo,bool $seed=true): void
{
    $pdo->exec('CREATE TABLE vp3_user_app_private_shares_v240 (
        id INTEGER PRIMARY KEY, public_id VARCHAR(32), sender_user_id INTEGER, recipient_user_id INTEGER,
        app_key VARCHAR(80), app_name VARCHAR(160), app_version VARCHAR(80), package_sha256 VARCHAR(64),
        publisher_fingerprint VARCHAR(64), permission_json TEXT, data_schema_version VARCHAR(80),
        grant_code_hash VARCHAR(64), status VARCHAR(24), expires_at DATETIME, redeemed_at DATETIME,
        revoked_at DATETIME, created_at DATETIME, updated_at DATETIME
    )');
    $pdo->exec('CREATE TABLE vp3_user_app_share_installs_v250 (
        id INTEGER PRIMARY KEY, share_public_id VARCHAR(32), sender_user_id INTEGER,
        recipient_user_id INTEGER, app_key VARCHAR(80), app_version VARCHAR(80),
        package_sha256 VARCHAR(64), publisher_fingerprint VARCHAR(64), installed_at DATETIME, last_seen_at DATETIME
    )');
    $pdo->exec('CREATE TABLE vp3_user_app_share_updates_v250 (
        id INTEGER PRIMARY KEY, prior_share_public_id VARCHAR(32), update_share_public_id VARCHAR(32),
        sender_user_id INTEGER, recipient_user_id INTEGER, app_key VARCHAR(80),
        from_package_sha256 VARCHAR(64), to_package_sha256 VARCHAR(64),
        permission_delta_json TEXT, schema_from VARCHAR(80), schema_to VARCHAR(80), created_at DATETIME
    )');
    if(!$seed)return;
    private_share_fixture_share_v1($pdo,1,['app_key'=>'user.kitchen','app_name'=>'Kitchen Notes','status'=>'accepted','redeemed_at'=>'2020-01-01 09:00:00']);
    private_share_fixture_share_v1($pdo,2,['app_key'=>'user.weekend','app_name'=>'Weekend Plan','package_sha256'=>str_repeat('b',64)]);
    private_share_fixture_share_v1($pdo,3,['sender_user_id'=>1,'recipient_user_id'=>2,'app_key'=>'user.inventory','app_name'=>'Sent Inventory','package_sha256'=>str_repeat('c',64)]);
    private_share_fixture_share_v1($pdo,4,['sender_user_id'=>2,'recipient_user_id'=>3,'app_key'=>'foreign.secret','app_name'=>'SECRET Foreign Workspace','package_sha256'=>str_repeat('f',64)]);
    private_share_fixture_share_v1($pdo,5,['app_key'=>'user.revoked','app_name'=>'Revoked Diagram','status'=>'revoked','revoked_at'=>'2020-02-01 00:00:00']);
    private_share_fixture_share_v1($pdo,6,['app_key'=>'user.expired','app_name'=>'Expired Planner','expires_at'=>'2001-01-01 00:00:00']);
    private_share_fixture_share_v1($pdo,7,['app_key'=>'user.kitchen','app_name'=>'Kitchen Replacement','package_sha256'=>str_repeat('d',64),'created_at'=>'2020-03-01 00:00:00']);
    private_share_fixture_insert_v1($pdo,'vp3_user_app_share_installs_v250',[
        'id'=>1,'share_public_id'=>str_pad('1',32,'0',STR_PAD_LEFT),'sender_user_id'=>2,'recipient_user_id'=>1,
        'app_key'=>'user.kitchen','app_version'=>'1.0','package_sha256'=>str_repeat('a',64),
        'publisher_fingerprint'=>str_repeat('e',64),'installed_at'=>'2020-01-01 09:15:00','last_seen_at'=>'2020-01-02 09:30:00',
    ]);
    private_share_fixture_insert_v1($pdo,'vp3_user_app_share_updates_v250',[
        'id'=>1,'prior_share_public_id'=>str_pad('1',32,'0',STR_PAD_LEFT),'update_share_public_id'=>str_pad('7',32,'0',STR_PAD_LEFT),
        'sender_user_id'=>2,'recipient_user_id'=>1,'app_key'=>'user.kitchen','from_package_sha256'=>str_repeat('a',64),
        'to_package_sha256'=>str_repeat('d',64),'permission_delta_json'=>'{"added":["SECRET_UPDATE_PERMISSION"],"removed":[],"retained":[],"requires_review":true}',
        'schema_from'=>'1','schema_to'=>'2','created_at'=>'2020-03-01 00:00:00',
    ]);
}

/** A tripwire: a stored Cloud share read must never invoke HomeServer or sync installs. */
function private_share_agent_remote_v1(int $uid,string $operation,array $payload=[]): array
{
    $GLOBALS['private_share_remote_calls'][]=[$uid,$operation,$payload];
    throw new RuntimeException('SECRET forbidden share network call');
}
