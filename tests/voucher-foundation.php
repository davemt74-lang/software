<?php
declare(strict_types=1);

// Run with PDO MySQL against an empty, disposable loopback database:
// VP3_TEST_MYSQL_DSN, VP3_TEST_MYSQL_USER, VP3_TEST_MYSQL_PASS.
require_once __DIR__.'/../includes/crm-v180.php';
require_once __DIR__.'/../includes/campaigns-rewards-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-platform-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-domain-v100.php';
require_once __DIR__.'/../includes/campaigns-rewards-v110.php';

function db(): PDO
{
    return $GLOBALS['voucherFixturePdo'];
}

function fixture_connect(string $dsn): PDO
{
    return new PDO($dsn, (string)getenv('VP3_TEST_MYSQL_USER'), (string)getenv('VP3_TEST_MYSQL_PASS'), [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
}

function table_exists(string $table): bool
{
    return (bool)fixture_query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table])->fetchColumn();
}

function column_exists(string $table, string $column): bool
{
    return (bool)fixture_query('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [$table, $column])->fetchColumn();
}

function url(string $path): string
{
    return $path;
}

function user_has_role(string $role, array $user): bool
{
    return false;
}

function fixture_query(string $sql, array $args=[]): PDOStatement
{
    $stmt=db()->prepare($sql);
    $stmt->execute($args);
    return $stmt;
}

function fixture_insert(string $sql, array $args=[]): int
{
    fixture_query($sql, $args);
    return (int)db()->lastInsertId();
}

function fixture_check(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException('FAIL: '.$description);
    echo 'PASS: ', $description, PHP_EOL;
}

function fixture_reject(callable $action, string $description, string $reason=''): void
{
    try {
        $action();
    } catch (PDOException $error) {
        throw $error;
    } catch (RuntimeException $error) {
        fixture_check(str_contains($error->getMessage(), $reason), $description.' ('.$error->getMessage().')');
        return;
    }
    throw new RuntimeException('Expected rejection: '.$description);
}

function fixture_claim(string $credential, string $code, int $location, string $key='', int $actor=1): array
{
    return campaigns_rewards_process_claim_v100(db(), $credential, $code, $actor, [
        'online'=>true, 'expected_merchant_id'=>1, 'location_id'=>$location, 'idempotency_key'=>$key,
    ]);
}

function fixture_issue(bool $transferable=true, bool $regiftable=false, string $currency='USD', ?int $holder=2, int $quantity=1, bool $present=true): array
{
    $credential=campaigns_rewards_secret_v100();
    $terms=json_encode(compact('transferable','regiftable'), JSON_THROW_ON_ERROR);
    $id=fixture_insert("INSERT INTO reward_issuances
        (public_id,merchant_id,campaign_id,campaign_version_id,reward_product_id,recipient_contact_id,recipient_user_id,
         credential_hash,credential_last4,face_value_minor,currency,terms_snapshot_json,quantity,remaining_quantity)
        VALUES (UUID(),1,1,1,1,?,?,?,?,500,?,?,?,?)", [
        $GLOBALS['voucherFixtureOriginalContact'], $holder, campaigns_rewards_secret_hash_v100($credential), substr($credential,-4),
        $currency, $terms, $quantity, $quantity,
    ]);
    if ($present && $holder!==null) {
        $credential=campaigns_rewards_rotate_reward_credential_v100(db(), $id, $holder)['credential'];
    }
    return [$id,$credential];
}

function fixture_card(int $user, string $bucket, int $id): ?array
{
    foreach (campaigns_rewards_reward_tray_v110(db(), $user)[$bucket] as $card) {
        if ((int)$card['id']===$id) return $card;
    }
    return null;
}

function fixture_count(string $table, int $issuance): int
{
    return (int)fixture_query("SELECT COUNT(*) FROM $table WHERE reward_issuance_id=?", [$issuance])->fetchColumn();
}

function fixture_action(array $action): array
{
    if ($action[0]==='send') {
        [, $id,$sender,$contact,$key]=$action;
        return campaigns_rewards_transfer_reward_v110(db(), $id, $sender, $contact, '', $key);
    }
    return fixture_claim(...array_slice($action, 1));
}

/** Queue each independent connection on the same issuance row before releasing it. */
function fixture_race(int $issuance, array ...$actions): array
{
    $workers=[];
    $connectionIds=[];
    db()->beginTransaction();
    try {
        fixture_query('SELECT id FROM reward_issuances WHERE id=? FOR UPDATE', [$issuance])->fetchColumn();
        foreach ($actions as $action) {
            $process=proc_open([PHP_BINARY, __FILE__, 'worker', json_encode($action, JSON_THROW_ON_ERROR)],
                [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Could not start fixture worker.');
            $workers[]=['process'=>$process, 'pipes'=>$pipes];
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            if (!preg_match('/^READY (\d+)$/D', trim((string)fgets($pipes[1])), $matches)) {
                throw new RuntimeException('Fixture worker did not connect.');
            }
            $connectionIds[]=(int)$matches[1];
        }

        $ids=implode(',', $connectionIds);
        $deadline=microtime(true)+5;
        do {
            $pending=(int)fixture_query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID IN ($ids) AND INFO LIKE '%FOR UPDATE%'")->fetchColumn();
            if ($pending===count($actions)) break;
            usleep(10000);
        } while (microtime(true)<$deadline);
        if ($pending!==count($actions)) throw new RuntimeException('Fixture workers did not contend for the issuance lock.');
        db()->commit();

        $results=[];
        foreach ($workers as $index=>$worker) {
            $output=trim((string)stream_get_contents($worker['pipes'][1]));
            $diagnostic=trim((string)stream_get_contents($worker['pipes'][2]));
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $status=proc_close($worker['process']);
            unset($workers[$index]);
            if ($status!==0 || $diagnostic!=='') throw new RuntimeException('Fixture worker failed: '.$output.' '.$diagnostic);
            $results[]=json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($workers as $worker) {
            proc_terminate($worker['process']);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
        }
    }
}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=(?:profile_fixture|voucher_fixture)(?:;charset=utf8mb4)?$/D', $dsn)
    || getenv('VP3_TEST_MYSQL_USER')!=='fixture') {
    throw new RuntimeException('A disposable loopback fixture database is required.');
}
$voucherFixturePdo=fixture_connect($dsn);
if (($argv[1]??'')==='worker') {
    echo 'READY ', fixture_query('SELECT CONNECTION_ID()')->fetchColumn(), PHP_EOL;
    fflush(STDOUT);
    try {
        $result=fixture_action(json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR));
        echo json_encode(['id'=>(int)$result['id']], JSON_THROW_ON_ERROR), PHP_EOL;
    } catch (PDOException $error) {
        fwrite(STDERR, $error->getMessage().PHP_EOL);
        exit(1);
    } catch (RuntimeException $error) {
        echo json_encode(['error'=>$error->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;
    }
    exit;
}

if ((int)fixture_query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0) {
    throw new RuntimeException('Start the voucher fixture on an empty database.');
}
// Exercise recipient matching when an existing users table uses a different collation from CRM.
db()->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY,is_active TINYINT NOT NULL,
    email VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL UNIQUE,
    display_name VARCHAR(190) NOT NULL,role VARCHAR(30) NOT NULL DEFAULT 'member',avatar_path VARCHAR(300) NOT NULL DEFAULT '') ENGINE=InnoDB");
db()->exec("INSERT INTO users (id,is_active,email,display_name) VALUES
    (1,1,'owner@fixture.test','Merchant owner'),(2,1,'sender@fixture.test','Sender'),(3,1,'recipient@fixture.test','Recipient'),
    (4,1,'other@fixture.test','Other'),(5,0,'inactive@fixture.test','Inactive'),(6,1,'operator@fixture.test','Operator')");
db()->exec('CREATE TABLE workspace_team_access_v1 (id INT PRIMARY KEY)');
db()->exec('CREATE TABLE workspace_team_invitation_scopes_v1 (id INT PRIMARY KEY)');
crm_v180_ensure_schema(db());
campaigns_rewards_platform_ensure_schema_v100(db());
campaigns_rewards_platform_ensure_schema_v100(db());
fixture_check(campaigns_rewards_v110_schema_ready(db()), 'Rewards schema is ready after a repeated install');

fixture_query("INSERT INTO merchant_accounts (id,public_id,owner_user_id,name,slug) VALUES
    (1,UUID(),1,'Fixture Merchant','fixture-merchant'),(2,UUID(),4,'Foreign Merchant','foreign-merchant')");
fixture_query("INSERT INTO merchant_members (merchant_id,user_id,role_id,is_owner)
    SELECT 1,1,id,1 FROM merchant_roles WHERE merchant_id IS NULL AND role_key='owner'");
fixture_query("INSERT INTO merchant_members (merchant_id,user_id,role_id,is_owner)
    SELECT 2,4,id,1 FROM merchant_roles WHERE merchant_id IS NULL AND role_key='owner'");
$operatorMember=fixture_insert("INSERT INTO merchant_members (merchant_id,user_id,role_id,is_owner)
    SELECT 1,6,id,0 FROM merchant_roles WHERE merchant_id IS NULL AND role_key='claim_processor'");
fixture_query("INSERT INTO campaigns (id,public_id,public_code,merchant_id,campaign_type_id,name,slug,status,owner_user_id) VALUES
    (1,UUID(),'fixture',1,1,'Fixture Campaign','fixture','active',1),
    (2,UUID(),'other-campaign',1,1,'Other Campaign','other-campaign','active',1)");
fixture_query("INSERT INTO reward_products (id,public_id,merchant_id,reward_type_id,name,transferable,regiftable)
    VALUES (1,UUID(),1,1,'Fixture voucher',1,1)");
$store=(int)campaigns_rewards_save_platform_location_v100(db(), 1, 1, ['name'=>'Main store','timezone'=>'America/Chicago'])['id'];
$branch=(int)campaigns_rewards_save_platform_location_v100(db(), 1, 1, ['name'=>'Other branch'])['id'];
$inactive=(int)campaigns_rewards_save_platform_location_v100(db(), 1, 1, ['name'=>'Inactive store','is_active'=>false])['id'];
$online=(int)campaigns_rewards_save_platform_location_v100(db(), 1, 1, ['name'=>'Online','location_type'=>'online'])['id'];
$foreign=fixture_insert("INSERT INTO merchant_locations (public_id,merchant_id,name) VALUES (UUID(),2,'Foreign store')");
fixture_query('INSERT INTO merchant_member_locations (merchant_member_id,location_id) VALUES (?,?)', [$operatorMember,$store]);

$voucherFixtureOriginalContact=crm_v180_upsert_contact(db(), ['owner_user_id'=>1,'vp3_user_id'=>2,'email'=>'sender@fixture.test']);
$target=crm_v180_upsert_contact(db(), ['owner_user_id'=>2,'vp3_user_id'=>3,'email'=>'recipient@fixture.test']);
$alternate=crm_v180_upsert_contact(db(), ['owner_user_id'=>2,'email'=>'other@fixture.test']);
$next=crm_v180_upsert_contact(db(), ['owner_user_id'=>3,'vp3_user_id'=>4,'email'=>'other@fixture.test']);
$inactiveTarget=crm_v180_upsert_contact(db(), ['owner_user_id'=>2,'vp3_user_id'=>5,'email'=>'inactive@fixture.test']);
$missingTarget=crm_v180_upsert_contact(db(), ['owner_user_id'=>2,'email'=>'missing@fixture.test']);
$mismatch=crm_v180_upsert_contact(db(), ['owner_user_id'=>2,'vp3_user_id'=>3,'email'=>'operator@fixture.test']);
$self=crm_v180_upsert_contact(db(), ['owner_user_id'=>2,'vp3_user_id'=>2,'email'=>'sender@fixture.test']);
$contactIds=array_map('intval', array_column(campaigns_rewards_send_contacts_v110(db(), 2), 'id'));
fixture_check(in_array($target,$contactIds,true) && in_array($alternate,$contactIds,true)
    && !array_intersect([$inactiveTarget,$missingTarget,$mismatch,$self,$next], $contactIds),
    'Send picker lists only eligible VP3 accounts from the sender’s contacts');

$blank=campaigns_rewards_create_claim_code_v100(db(), 1, 1, [
    'display_name'=>'Default limits','daily_claim_limit'=>'','total_claim_limit'=>'','max_value_minor'=>'',
]);
$general=$blank['code'];
fixture_check($blank['daily_claim_limit']===null && $blank['total_claim_limit']===null && $blank['max_value_minor']===null,
    'Blank code limits persist as unlimited');
$legacyIssuances=[fixture_issue()[0],fixture_issue(true,false,'USD',null)[0]];
db()->exec('ALTER TABLE reward_claims DROP INDEX idx_reward_claim_holder, DROP COLUMN holder_user_id, DROP COLUMN holder_contact_id');
$voucherFixturePdo=fixture_connect($dsn); // Start the legacy upgrade with a fresh schema metadata cache.
foreach ($legacyIssuances as $legacyId) {
    fixture_query("INSERT INTO reward_claims
        (public_id,merchant_id,campaign_id,reward_issuance_id,claim_code_id,location_id,claimed_at)
        VALUES (UUID(),1,1,?,?,?,'2026-01-01 09:00:00')", [$legacyId,$blank['id'],$store]);
    fixture_query("UPDATE reward_issuances SET status='claimed',remaining_quantity=0 WHERE id=?", [$legacyId]);
}
fixture_query('UPDATE crm_contacts SET vp3_user_id=4 WHERE id=?', [$voucherFixtureOriginalContact]);
fixture_check(!campaigns_rewards_v110_schema_ready(db()), 'Legacy claim table is not reported as voucher-ready');
campaigns_rewards_platform_ensure_schema_v100(db());
campaigns_rewards_platform_ensure_schema_v100(db());
foreach ($legacyIssuances as $index=>$legacyId) {
    $historical=fixture_query('SELECT holder_user_id,holder_contact_id FROM reward_claims WHERE reward_issuance_id=?', [$legacyId])->fetch();
    fixture_check(($index===0?(int)$historical['holder_user_id']===2:$historical['holder_user_id']===null)
        && (int)$historical['holder_contact_id']===$voucherFixtureOriginalContact && fixture_card(4,'claimed',$legacyId)===null,
        'Historical claim backfill uses the persisted holder and contact without following a CRM relink');
}
fixture_check(campaigns_rewards_v110_schema_ready(db()) && fixture_card(2,'claimed',$legacyIssuances[0])!==null,
    'Repeated migration preserves legacy Claim history for the recorded holder');
fixture_query('UPDATE crm_contacts SET vp3_user_id=2 WHERE id=?', [$voucherFixtureOriginalContact]);
$bound=campaigns_rewards_create_claim_code_v100(db(), 1, 1, ['display_name'=>'Main store','location_id'=>$store])['code'];
$restricted=campaigns_rewards_create_claim_code_v100(db(), 1, 1, ['display_name'=>'Assigned operator','merchant_member_id'=>$operatorMember])['code'];
$campaignCode=campaigns_rewards_create_claim_code_v100(db(), 1, 1, ['display_name'=>'Other campaign','campaign_ids'=>[2]])['code'];
$usdCap=campaigns_rewards_create_claim_code_v100(db(), 1, 1, ['display_name'=>'USD cap','currency'=>'USD','max_value_minor'=>600])['code'];
$foreignCode=campaigns_rewards_create_claim_code_v100(db(), 2, 4, ['display_name'=>'Foreign code'])['code'];

[$id,$oldCredential]=fixture_issue();
foreach ([$inactiveTarget,$missingTarget,$mismatch,$self,$next] as $invalidTarget) {
    fixture_reject(fn()=>campaigns_rewards_transfer_reward_v110(db(), $id, 2, $invalidTarget, '', 'invalid-target'), 'Ineligible or unowned target cannot receive voucher');
}
fixture_reject(fn()=>campaigns_rewards_transfer_reward_v110(db(), $id, 3, $next, '', 'not-owner'), 'Non-holder cannot send');
fixture_check(fixture_count('reward_transfers',$id)===0 && fixture_card(2,'inbox',$id)!==null, 'Rejected sends leave ownership and transfer ledger intact');

$sent=campaigns_rewards_transfer_reward_v110(db(), $id, 2, $target, 'Same note', 'send-retry');
$receipt=campaigns_rewards_transfer_reward_v110(db(), $id, 2, $target, 'Same note', 'send-retry');
fixture_check((int)$sent['to_user_id']===3 && (int)$receipt['id']===(int)$sent['id'] && !empty($receipt['duplicate'])
    && fixture_count('reward_transfers',$id)===1, 'Send retries recover exactly one transfer receipt after ownership moves');
fixture_reject(fn()=>campaigns_rewards_transfer_reward_v110(db(), $id, 2, $alternate, 'Same note', 'send-retry'), 'Sender key cannot be reused for another target');
fixture_reject(fn()=>campaigns_rewards_transfer_reward_v110(db(), $id, 2, $target, 'Different note', 'send-retry'), 'Sender key cannot be reused for another note');
[$differentVoucher]=fixture_issue();
fixture_reject(fn()=>campaigns_rewards_transfer_reward_v110(db(), $differentVoucher, 2, $target, 'Same note', 'send-retry'),
    'Sender key cannot be reused for another issuance', 'different request');
fixture_reject(fn()=>campaigns_rewards_transfer_reward_v110(db(), $id, 3, $next, '', 'send-retry'), 'New holder cannot recover the old sender receipt or regift a first-send-only voucher');
fixture_check((int)fixture_query('SELECT recipient_user_id FROM reward_issuances WHERE id=?', [$id])->fetchColumn()===3
    && fixture_card(2,'inbox',$id)===null && fixture_card(2,'sent',(int)$sent['id'])!==null && fixture_card(3,'inbox',$id)!==null,
    'The same issuance moves from sender Inbox to recipient Inbox while Sent retains the transfer');
fixture_check(empty(fixture_card(3,'inbox',$id)['transferable']), 'Recipient Inbox reflects onward-send eligibility');
fixture_reject(fn()=>fixture_claim($oldCredential, $general, $store, 'old-credential'), 'Transfer invalidates old holder credential', 'invalid');
fixture_reject(fn()=>campaigns_rewards_rotate_reward_credential_v100(db(), $id, 2), 'Prior holder cannot display a new credential');

fixture_query('UPDATE crm_contacts SET vp3_user_id=4 WHERE id=?', [(int)$sent['to_contact_id']]);
fixture_reject(fn()=>campaigns_rewards_rotate_reward_credential_v100(db(), $id, 4), 'CRM relink cannot give another account voucher authority');
fixture_check(fixture_card(4,'inbox',$id)===null && fixture_card(3,'inbox',$id)!==null, 'CRM relink does not change the sole holder’s Inbox');
$credential=campaigns_rewards_rotate_reward_credential_v100(db(), $id, 3)['credential'];

[$noSend]=fixture_issue(false,true);
fixture_reject(fn()=>campaigns_rewards_transfer_reward_v110(db(), $noSend, 2, $target, '', 'first-send'), 'Regiftable alone and mutable catalog flags cannot authorize a first send');
[$regift]=fixture_issue(true,true);
$first=campaigns_rewards_transfer_reward_v110(db(), $regift, 2, $target, '', 'shared-key');
$second=campaigns_rewards_transfer_reward_v110(db(), $regift, 3, $next, '', 'shared-key');
fixture_query("UPDATE reward_transfers SET transferred_at='2026-01-01 10:00:00' WHERE id=?", [(int)$first['id']]);
fixture_query("UPDATE reward_issuances SET sent_at='2026-01-02 10:00:00' WHERE id=?", [$regift]);
fixture_check((int)$first['id']!==(int)$second['id'] && (int)$second['to_user_id']===4 && fixture_count('reward_transfers',$regift)===2
    && fixture_card(2,'sent',(int)$first['id'])['sent_at']==='2026-01-01 10:00:00',
    'An allowed onward send has its own actor-scoped receipt and preserves the original Sent timestamp');
[$unlinked]=fixture_issue();
$resolved=campaigns_rewards_transfer_reward_v110(db(), $unlinked, 2, $alternate, '', 'resolved-user');
fixture_check((int)$resolved['to_user_id']===4, 'An unlinked CRM contact resolves to a matching active VP3 account');

[$nestedSend]=fixture_issue();
db()->beginTransaction();
try {
    fixture_reject(fn()=>campaigns_rewards_transfer_reward_v110(db(), $nestedSend, 2, $inactiveTarget, '', 'nested-retry'),
        'Rejected nested transfer preserves the caller transaction');
    $nestedReceipt=campaigns_rewards_transfer_reward_v110(db(), $nestedSend, 2, $target, '', 'nested-retry');
    db()->commit();
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
fixture_check((int)$nestedReceipt['to_user_id']===3 && fixture_count('reward_transfers',$nestedSend)===1,
    'Nested send failure leaves the same request key available for a corrected retry');

foreach ([0,$inactive,$online,$foreign,999999] as $badLocation) {
    fixture_reject(fn()=>fixture_claim($credential, $general, $badLocation, 'invalid-location'), 'Claim requires an active same-merchant in-person location', 'Location');
}
fixture_reject(fn()=>fixture_claim($credential, $bound, $branch, 'wrong-code-location'), 'Location-bound code rejects another branch', 'assigned Location');
fixture_reject(fn()=>fixture_claim($credential, $general, $branch, 'unassigned-branch', 6), 'Location-scoped operator cannot process a different branch', 'assigned');
fixture_reject(fn()=>fixture_claim($credential, $general, $store, 'holder-only', 3), 'Holder cannot self-redeem with code and credential', 'claims.process');
fixture_reject(fn()=>fixture_claim($credential, $restricted, $store, 'wrong-operator'), 'Member-bound code rejects another operator', 'Team Member');
fixture_reject(fn()=>fixture_claim($credential, $foreignCode, $store, 'wrong-merchant-code'), 'Claim code cannot cross merchant boundaries', 'Merchant');
fixture_reject(fn()=>fixture_claim($credential, $campaignCode, $store, 'wrong-campaign'), 'Campaign-bound code rejects another campaign', 'Campaign');
[$unshown,$unshownCredential]=fixture_issue(present: false);
fixture_reject(fn()=>fixture_claim($unshownCredential, $general, $store, 'unshown'), 'Account voucher requires credential display by its holder');
fixture_check(fixture_count('reward_claims',$unshown)===0, 'Undisplayed account voucher remains unspent');
[, $eurCredential]=fixture_issue(true,false,'EUR');
fixture_reject(fn()=>fixture_claim($eurCredential, $usdCap, $store, 'wrong-currency'), 'Currency-bound value limit rejects a different currency', 'currency');
[, $quantityCredential]=fixture_issue(quantity: 2);
fixture_reject(fn()=>fixture_claim($quantityCredential, $usdCap, $store, 'over-value'), 'Value limit covers all redeemed units', 'value limit');

foreach (['daily','total'] as $limit) {
    $limited=campaigns_rewards_create_claim_code_v100(db(), 1, 1, ['display_name'=>$limit.' limit',$limit.'_claim_limit'=>1]);
    [, $firstCredential]=fixture_issue();
    [$secondIssuance,$secondCredential]=fixture_issue();
    fixture_claim($firstCredential, $limited['code'], $store, $limit.'-first');
    fixture_reject(fn()=>fixture_claim($secondCredential, $limited['code'], $store, $limit.'-second'), 'Configured '.$limit.' limit applies across vouchers', $limit.' limit');
    fixture_check(fixture_count('reward_claims',$secondIssuance)===0, 'Code limit rejection does not consume the voucher');
}

$claim=fixture_claim($credential, $general, $store, 'claim-retry', 6);
$replay=fixture_claim($credential, $general, $store, 'claim-retry', 6);
fixture_reject(fn()=>fixture_claim($credential, $general, $store, 'claim-retry', 1), 'Another authorized operator cannot recover the first operator’s receipt');
fixture_reject(fn()=>fixture_claim($credential, $bound, $store, 'claim-retry', 6), 'Same operator key cannot be replayed with a different code');
fixture_reject(fn()=>fixture_claim($credential, $general, $store, 'second-claim', 6), 'A new request cannot redeem the same voucher again');
fixture_check((int)$claim['id']===(int)$replay['id'] && fixture_count('reward_claims',$id)===1 && (int)$claim['holder_user_id']===3
    && (int)$claim['merchant_id']===1 && (int)$claim['location_id']===$store && (int)$claim['processed_by_user_id']===6
    && $claim['location_name']==='Main store' && $claim['location_timezone']==='America/Chicago' && !empty($claim['claimed_at']),
    'Redemption and retry record one receipt with holder, merchant, location, time and operator');
$claimedCard=fixture_card(3,'claimed',$id);
fixture_check($claimedCard!==null && fixture_card(3,'inbox',$id)===null && fixture_card(2,'claimed',$id)===null && fixture_card(4,'claimed',$id)===null
    && $claimedCard['claimed_at']===$claim['claimed_at'] && $claimedCard['merchant_name']==='Fixture Merchant'
    && $claimedCard['claim_location_name']==='Main store' && $claimedCard['claim_location_timezone']==='America/Chicago',
    'Claimed projects the redemption only to the holder at checkout');
campaigns_rewards_save_platform_location_v100(db(), 1, 1, ['name'=>'Renamed store','timezone'=>'UTC'], $store);
fixture_check(fixture_card(3,'claimed',$id)['claim_location_name']==='Main store', 'Historical claim retains the location name recorded at checkout');

campaigns_rewards_attach_reward_v100(db(), 2, 1, 1);
$campaignContact=crm_v180_upsert_contact(db(), ['owner_user_id'=>1,'email'=>'operator@fixture.test']);
$externalContact=crm_v180_upsert_contact(db(), ['owner_user_id'=>1,'email'=>'external@fixture.test']);
$mismatchedContact=crm_v180_upsert_contact(db(), ['owner_user_id'=>1,'email'=>'stale-link@fixture.test','vp3_user_id'=>4]);
fixture_reject(fn()=>campaigns_rewards_issue_reward_v100(db(), 2, 1, $mismatchedContact, 1),
    'A stale CRM link cannot create an external bearer voucher', 'linked VP3 account');
$accountIssuance=campaigns_rewards_issue_reward_v100(db(), 2, 1, $campaignContact, 1);
$externalIssuance=campaigns_rewards_issue_reward_v100(db(), 2, 1, $externalContact, 1);
fixture_check((int)$accountIssuance['recipient_user_id']===6 && fixture_card(6,'inbox',(int)$accountIssuance['id'])!==null
    && $externalIssuance['recipient_user_id']===null && fixture_card(4,'inbox',(int)$externalIssuance['id'])===null,
    'Campaign issuance binds only a matching active account and keeps external certificates out of VP3 Inbox');
fixture_check(empty($accountIssuance['credential']) && empty($accountIssuance['credential_last4']) && !array_key_exists('credential_hash',$accountIssuance)
    && !empty($externalIssuance['credential']) && !array_key_exists('credential_hash',$externalIssuance),
    'Merchant-issued account voucher hides credential data and the external voucher can still be delivered');
$publicAccountContact=crm_v180_upsert_contact(db(), ['owner_user_id'=>1,'email'=>'owner@fixture.test']);
$publicExternalContact=crm_v180_upsert_contact(db(), ['owner_user_id'=>1,'email'=>'public-external@fixture.test']);
$publicAccount=campaigns_rewards_issue_reward_v100(db(), 2, 1, $publicAccountContact, 0, ['actor_type'=>'public']);
$publicExternal=campaigns_rewards_issue_reward_v100(db(), 2, 1, $publicExternalContact, 0, ['actor_type'=>'public']);
fixture_check((int)$publicAccount['recipient_user_id']===1 && empty($publicAccount['credential']) && empty($publicAccount['credential_last4'])
    && !array_key_exists('credential_hash',$publicAccount) && fixture_card(1,'inbox',(int)$publicAccount['id'])!==null,
    'Publicly issued account voucher is delivered into Inbox without revealing its credential');
fixture_check($publicExternal['recipient_user_id']===null && !empty($publicExternal['credential'])
    && $publicExternal['credential_last4']===substr($publicExternal['credential'],-4) && !array_key_exists('credential_hash',$publicExternal),
    'Publicly issued external voucher returns only the bearer credential and last four characters');
$legacyReceipt=fixture_claim($externalIssuance['credential'], $campaignCode, $store);
$legacyReplay=fixture_claim($externalIssuance['credential'], $campaignCode, $store);
fixture_check((int)$legacyReceipt['id']===(int)$legacyReplay['id'] && $legacyReceipt['holder_user_id']===null
    && (int)$legacyReceipt['holder_contact_id']===$externalContact && fixture_count('reward_claims',(int)$externalIssuance['id'])===1,
    'External campaign certificate still redeems at the merchant terminal and a legacy no-key retry returns its receipt');

[$inline]=fixture_issue(holder:1, present:false);
$prepared=campaigns_rewards_prepare_claim_v110(db(),$inline,1);
$inlineContext=['location_id'=>$store,'idempotency_key'=>'inline-claim','reward_credential'=>$prepared['credential']];
$inlineReceipt=campaigns_rewards_claim_from_tray_v110(db(),$inline,1,$general,$inlineContext);
$inlineReplay=campaigns_rewards_claim_from_tray_v110(db(),$inline,1,$general,$inlineContext);
fixture_check($prepared['can_process']&&$inlineReplay['duplicate']&&$inlineReceipt['id']===$inlineReplay['id']
    && fixture_count('reward_claims',$inline)===1,'Tray operator redeems and recovers the original prepared claim');

[$legacyInline]=fixture_issue(holder:1, present:false);
$legacyInlineContext=['location_id'=>$store];
$legacyInlineReceipt=campaigns_rewards_claim_from_tray_v110(db(),$legacyInline,1,$general,$legacyInlineContext);
$legacyInlineReplay=campaigns_rewards_claim_from_tray_v110(db(),$legacyInline,1,$general,$legacyInlineContext);
fixture_check($legacyInlineReplay['duplicate']&&$legacyInlineReceipt['id']===$legacyInlineReplay['id'],
    'Legacy tray client retries with the authenticated holder and operator authority');

[$recover,$recoverCredential]=fixture_issue();
db()->exec("CREATE TRIGGER fixture_fail_claim_event BEFORE INSERT ON campaign_activity_events FOR EACH ROW
    BEGIN IF NEW.event_type='claim.accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture event failure'; END IF; END");
try {
    fixture_claim($recoverCredential, $general, $store, 'recover-claim');
    throw new LogicException('Expected claim event failure.');
} catch (PDOException $error) {
    fixture_check(str_contains($error->getMessage(), 'fixture event failure') && fixture_count('reward_claims',$recover)===0
        && fixture_card(2,'inbox',$recover)!==null, 'Claim receipt and issuance roll back when the accepted event cannot persist');
} finally {
    db()->exec('DROP TRIGGER fixture_fail_claim_event');
}
fixture_claim($recoverCredential, $general, $store, 'recover-claim');
fixture_check(fixture_count('reward_claims',$recover)===1, 'Retry after failed commit creates one redemption');

[$sendRace]=fixture_issue();
$results=fixture_race($sendRace, ['send',$sendRace,2,$target,'send-a'], ['send',$sendRace,2,$alternate,'send-b']);
fixture_check(count(array_column($results,'id'))===1 && count(array_column($results,'error'))===1
    && fixture_count('reward_transfers',$sendRace)===1, 'Concurrent sends have one winner and one transfer');
[$sendRetryRace]=fixture_issue();
$sameSend=['send',$sendRetryRace,2,$target,'same-send'];
$ids=array_column(fixture_race($sendRetryRace, $sameSend, $sameSend), 'id');
fixture_check(count($ids)===2 && $ids[0]===$ids[1] && fixture_count('reward_transfers',$sendRetryRace)===1,
    'Concurrent retries of the same send recover the same transfer');
[$claimRace,$claimRaceCredential]=fixture_issue();
$results=fixture_race($claimRace, ['claim',$claimRaceCredential,$general,$store,'claim-a'], ['claim',$claimRaceCredential,$general,$store,'claim-b']);
fixture_check(count(array_column($results,'id'))===1 && count(array_column($results,'error'))===1
    && fixture_count('reward_claims',$claimRace)===1, 'Concurrent claims have one winner and one redemption');
[$claimRetryRace,$claimRetryCredential]=fixture_issue();
$sameClaim=['claim',$claimRetryCredential,$general,$store,'same-claim'];
$ids=array_column(fixture_race($claimRetryRace, $sameClaim, $sameClaim), 'id');
fixture_check(count($ids)===2 && $ids[0]===$ids[1] && fixture_count('reward_claims',$claimRetryRace)===1,
    'Concurrent retries of the same claim recover the same redemption');
[$mixedRace,$mixedCredential]=fixture_issue();
$results=fixture_race($mixedRace, ['send',$mixedRace,2,$target,'race-send'], ['claim',$mixedCredential,$general,$store,'race-claim']);
fixture_check(count(array_column($results,'id'))===1 && count(array_column($results,'error'))===1
    && fixture_count('reward_claims',$mixedRace)+fixture_count('reward_transfers',$mixedRace)===1,
    'A concurrent transfer and redemption cannot both succeed');

echo "VOUCHER_FOUNDATION=PASS\n";
