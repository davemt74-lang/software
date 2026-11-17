<?php
declare(strict_types=1);

require_once __DIR__.'/connected-gelato-v1.php';

function vp3_connected_sites_sales_date_v1(array $location, DateTimeImmutable $now): string
{
    return $now->setTimezone(new DateTimeZone($location['timezone']))->modify('-1 day')->format('Y-m-d');
}

function vp3_connected_sites_sales_rows_v1(PDO $pdo, int $owner): array
{
    $query = $pdo->prepare("SELECT connection_id,location_id,grant_revision,checked_at,verified_at,state,findings_json
        FROM vp3_connected_site_reports_v1 WHERE owner_user_id=? AND provider='foodmonster_sales'");
    $query->execute([$owner]);
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[$row['connection_id']][(int)$row['location_id']] = $row;
    return $rows;
}

function vp3_connected_sites_sales_row_v1(PDO $pdo, int $owner, string $id, int $location, bool $lock=false): ?array
{
    $query = $pdo->prepare("SELECT grant_revision,checked_at,verified_at,state,findings_json FROM vp3_connected_site_reports_v1
        WHERE owner_user_id=? AND provider='foodmonster_sales' AND connection_id=? AND location_id=?"
        .($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([$owner,$id,$location]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Persist only verified aggregates in the provider's decimal representation. */
function vp3_connected_sites_sales_totals_v1(mixed $data): ?array
{
    if (!is_array($data)) return null;
    $totals = [];
    foreach (['netSales','grossSales','refunds'] as $field) {
        $value = $data[$field] ?? null;
        if ($value === null) continue;
        if (!is_string($value) || !preg_match('/\A-?\d{1,15}(?:\.\d{1,4})?\z/D',$value)) return null;
        $totals[$field] = $value;
    }
    if (isset($data['tickets'])) {
        if (!is_int($data['tickets']) || $data['tickets'] < 0) return null;
        $totals['tickets'] = $data['tickets'];
    }
    if (!$totals) return null;
    if (isset($data['currency'])) {
        if (!is_string($data['currency']) || !preg_match('/\A[A-Z]{3}\z/D',$data['currency'])) return null;
        $totals['currency'] = $data['currency'];
    }
    return $totals;
}

function vp3_connected_sites_sales_data_v1(?array $row, string $grant): array
{
    if (($row['grant_revision'] ?? null) !== $grant || !is_string($row['findings_json'] ?? null)) return [];
    $data = json_decode($row['findings_json'],true,12);
    return is_array($data) ? $data : [];
}

/** Read exact, still-authorized closed days without contacting FoodMonster. */
function vp3_connected_sites_sales_history_v1(?array $row, string $grant): array
{
    if (($row['state'] ?? null) === 'authorization_required') return [];
    $data = vp3_connected_sites_sales_data_v1($row,$grant);
    $history = [];
    foreach ((array)($data['history'] ?? []) as $date=>$entry) {
        if (!is_string($date) || !vp3_gelato_business_date_v1($date) || !is_array($entry)
            || !is_int($entry['verified_at'] ?? null) || $entry['verified_at'] < 1) continue;
        $totals = vp3_connected_sites_sales_totals_v1($entry['totals'] ?? null);
        if ($totals === null) continue;
        $history[$date] = ['state'=>'ready','businessDate'=>$date,
            'lastVerifiedAt'=>vp3_connected_sites_report_iso_v1($entry['verified_at']),'totals'=>$totals];
    }
    krsort($history);
    return array_slice($history,0,14,true);
}

function vp3_connected_sites_sales_projection_v1(?array $row, string $grant, string $date): array
{
    $projection = ['state'=>'unchecked','businessDate'=>$date,'lastVerifiedAt'=>null];
    $data = vp3_connected_sites_sales_data_v1($row,$grant);
    if (($data['businessDate'] ?? null) !== $date) return $projection;
    $state = $row['state'] ?? null;
    if ($state === 'ready') {
        $ready = vp3_connected_sites_sales_history_v1($row,$grant)[$date] ?? null;
        return $ready ?? $projection;
    }
    if (in_array($state,['missing','unavailable','authorization_required'],true)) {
        $projection['state'] = $state;
        if ($state === 'missing') $projection['lastVerifiedAt'] = vp3_connected_sites_report_iso_v1($row['verified_at'] ?? null);
    }
    return $projection;
}

function vp3_connected_sites_sales_due_v1(?array $row, string $grant, string $date, int $now): ?int
{
    if (($row['grant_revision'] ?? null) !== $grant) return 0;
    $checked = max(0,(int)($row['checked_at'] ?? 0));
    $data = vp3_connected_sites_sales_data_v1($row,$grant);
    if (($data['businessDate'] ?? null) !== $date) return $checked;
    $state = vp3_connected_sites_sales_projection_v1($row,$grant,$date)['state'];
    if ($state === 'ready' || ($checked > 0 && $checked > $now-900)) return null;
    return $checked;
}

function vp3_connected_sites_sales_snapshot_v1(array $result, array $location, string $date, int $checked): array
{
    $data = $result['data'] ?? null;
    $verified = vp3_connected_sites_report_observation_v1($result['asOf'] ?? null);
    if ($verified === null || $verified < $checked-120 || $verified > $checked+60
        || ($result['location']['timezone'] ?? null) !== $location['timezone']
        || !is_array($data) || ($data['businessDate'] ?? null) !== $date || !is_bool($data['available'] ?? null)) {
        throw new RuntimeException('FoodMonster did not return a verified daily sales snapshot.',502);
    }
    if (!$data['available']) return ['state'=>'missing','verified_at'=>$verified];
    $raw = $data['totals'] ?? null;
    $totals = is_array($raw) ? vp3_connected_sites_sales_totals_v1(['currency'=>$data['currency'] ?? null]+$raw) : null;
    if ($totals === null) throw new RuntimeException('FoodMonster returned invalid daily sales totals.',502);
    return ['state'=>'ready','verified_at'=>$verified,'totals'=>$totals];
}

/** This read has its own checkpoint and never changes the open POS snapshot. */
function vp3_connected_sites_sales_scan_v1(PDO $pdo, array $user, string $id, array $location, string $grant,
    int $expectedChecked, DateTimeImmutable $now, ?callable $transport, ?callable $commitAuthority): array
{
    $owner = vp3_connected_sites_report_owner_v1($user);
    if ($pdo->inTransaction()) throw new LogicException('Connected site reads must run outside a database transaction.');
    $local = $location['id'] ?? null;
    if (!is_int($local) || $local < 1 || $expectedChecked < 0) throw new InvalidArgumentException('Choose a permitted sales location.');
    $connection = vp3_gelato_connection_v1($pdo,$owner,$id);
    $public = vp3_gelato_public_v1($connection);
    $locations = array_column($public['locations'],null,'id');
    if (vp3_connected_sites_report_grant_v1($connection) !== $grant || !in_array('sales.daily.read',$public['scopes'],true)
        || !isset($locations[$local])) {
        throw new RuntimeException('The connected site sales permission changed.',403);
    }
    $location = $locations[$local];
    $date = vp3_connected_sites_sales_date_v1($location,$now);
    $checked = $now->getTimestamp();
    $previous = vp3_connected_sites_sales_row_v1($pdo,$owner,$id,$local);
    if ($expectedChecked >= $checked || vp3_connected_sites_sales_due_v1($previous,$grant,$date,$checked) !== $expectedChecked) {
        return ['state'=>'superseded'];
    }
    try {
        $result = vp3_gelato_operation_v1($pdo,$owner,$id,'sales.daily.read',$local,$date,$transport);
        $snapshot = vp3_connected_sites_sales_snapshot_v1($result,$location,$date,$checked);
    } catch (RuntimeException|JsonException $error) {
        if ($error instanceof PDOException) throw $error;
        if ($error->getCode() === 404) return ['state'=>'superseded'];
        $snapshot = ['state'=>$error->getCode() === 403 ? 'authorization_required' : 'unavailable'];
    }
    return agent_commerce_atomic_pa2($pdo,static function() use (
        $pdo,$owner,$id,$local,$grant,$date,$checked,$expectedChecked,$snapshot,$commitAuthority
    ): array {
        vp3_gelato_knowledge_lock_owner_v1($pdo,$owner);
        if ($commitAuthority !== null) $commitAuthority();
        if (vp3_connected_sites_report_grant_v1(vp3_gelato_connection_v1($pdo,$owner,$id,true)) !== $grant) {
            throw new RuntimeException('The connected site sales permission changed.',403);
        }
        $previous = vp3_connected_sites_sales_row_v1($pdo,$owner,$id,$local,true);
        if (vp3_connected_sites_sales_due_v1($previous,$grant,$date,$checked) !== $expectedChecked) return ['state'=>'superseded'];

        $state = $snapshot['state'];
        $verified = $snapshot['verified_at'] ?? null;
        $priorHistory = vp3_connected_sites_sales_history_v1($previous,$grant);
        $history = [];
        if ($state !== 'authorization_required') {
            foreach ($priorHistory as $day=>$entry) {
                $observed = vp3_connected_sites_report_observation_v1($entry['lastVerifiedAt']);
                if ($observed !== null) $history[$day] = ['verified_at'=>$observed,'totals'=>$entry['totals']];
            }
        }
        if ($state === 'ready') $history[$date] = ['verified_at'=>$verified,'totals'=>$snapshot['totals']];
        krsort($history);
        $json = json_encode(['businessDate'=>$date,'history'=>array_slice($history,0,14,true)],JSON_THROW_ON_ERROR);
        if ($previous === null) {
            $pdo->prepare("INSERT INTO vp3_connected_site_reports_v1
                (owner_user_id,provider,connection_id,location_id,grant_revision,checked_at,verified_at,state,tickets_checked,findings_json)
                VALUES (?,'foodmonster_sales',?,?,?,?,?,?,NULL,?)")->execute([$owner,$id,$local,$grant,$checked,$verified,$state,$json]);
        } else {
            $pdo->prepare("UPDATE vp3_connected_site_reports_v1 SET grant_revision=?,checked_at=?,verified_at=?,state=?,findings_json=?
                WHERE owner_user_id=? AND provider='foodmonster_sales' AND connection_id=? AND location_id=?")
                ->execute([$grant,$checked,$verified,$state,$json,$owner,$id,$local]);
        }

        $priorData = vp3_connected_sites_sales_data_v1($previous,$grant);
        $priorState = $priorData ? ($previous['state'] ?? null) : null;
        $priorDate = $priorData['businessDate'] ?? null;
        $verifiedStates = ['ready','missing'];
        $changed = ($state !== $priorState || $date !== $priorDate
            || ($snapshot['totals'] ?? null) !== ($priorHistory[$date]['totals'] ?? null))
            && (in_array($state,$verifiedStates,true) || in_array($priorState,$verifiedStates,true) || ($state === 'authorization_required' && $priorHistory));
        if ($changed) vp3_coordination_source_changed_v1($pdo,$owner);
        return ['state'=>$state,'businessDate'=>$date,'verified_at'=>$verified,'changed'=>$changed];
    });
}
