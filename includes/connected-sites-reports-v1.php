<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/agent-coordination-source-change-v1.php';
require_once __DIR__.'/schema-metadata.php';
require_once __DIR__.'/connected-sites-sales-v1.php';

const VP3_CONNECTED_SITES_CHECK_INTERVAL_V1 = 300;
const VP3_CONNECTED_SITES_REPORT_TTL_V1 = 900;

function vp3_connected_sites_report_schema_v1(PDO $pdo): void
{
    $options = agent_commerce_sqlite_pa2($pdo) ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_connected_site_reports_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        provider VARCHAR(32) NOT NULL,
        connection_id CHAR(32) NOT NULL,
        location_id BIGINT UNSIGNED NOT NULL,
        grant_revision CHAR(64) NOT NULL,
        checked_at BIGINT UNSIGNED NOT NULL,
        verified_at BIGINT UNSIGNED NULL,
        state VARCHAR(32) NOT NULL,
        tickets_checked INT UNSIGNED NULL,
        findings_json TEXT NOT NULL,
        PRIMARY KEY (owner_user_id,provider,connection_id,location_id),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )".$options);
}

function vp3_connected_sites_report_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT grant_revision,verified_at,findings_json FROM vp3_connected_site_reports_v1 WHERE 1=0') !== false;
    } catch (PDOException) {
        return false;
    }
}

function vp3_connected_sites_report_forget_v1(PDO $pdo, int $owner, string $connectionId): void
{
    if (!vp3_connected_sites_report_ready_v1($pdo)) return;
    $query = $pdo->prepare("DELETE FROM vp3_connected_site_reports_v1 WHERE owner_user_id=? AND provider IN ('foodmonster','foodmonster_sales') AND connection_id=?");
    $query->execute([$owner,$connectionId]);
}

function vp3_connected_sites_report_owner_v1(array $user): int
{
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1 || !has_permission('account.access',$user) || !has_permission('chat.access',$user)
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
        throw new RuntimeException('Private Agent Chat access is required.',403);
    }
    return $owner;
}

function vp3_connected_sites_report_grant_v1(array $connection): string
{
    $proof = array_intersect_key($connection,array_flip([
        'id','owner_user_id','instance_id','remote_connection_id','origin','agent_name','scopes_json','locations_json','token_ciphertext',
    ]));
    ksort($proof);
    return hash('sha256',json_encode($proof,JSON_THROW_ON_ERROR));
}

function vp3_connected_sites_report_connections_v1(PDO $pdo, int $owner): array
{
    if (!schema_metadata_table_exists($pdo,'vp3_gelato_connections_v1')) return [];
    $query = $pdo->prepare('SELECT * FROM vp3_gelato_connections_v1 WHERE owner_user_id=? ORDER BY agent_name,id');
    $query->execute([$owner]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) require_once __DIR__.'/connected-gelato-v1.php';
    return $rows;
}

function vp3_connected_sites_report_rows_v1(PDO $pdo, int $owner,bool $includeFindings=true): array
{
    $columns='connection_id,location_id,grant_revision,checked_at,verified_at,state,tickets_checked';
    if($includeFindings)$columns.=',findings_json';
    $query = $pdo->prepare("SELECT $columns FROM vp3_connected_site_reports_v1 WHERE owner_user_id=? AND provider='foodmonster'");
    $query->execute([$owner]);
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[$row['connection_id']][(int)$row['location_id']] = $row;
    return $rows;
}

function vp3_connected_sites_report_row_v1(PDO $pdo,int $owner,string $id,int $location,bool $lock=false): ?array
{
    $query = $pdo->prepare("SELECT * FROM vp3_connected_site_reports_v1 WHERE owner_user_id=? AND provider='foodmonster' AND connection_id=? AND location_id=?"
        .($lock?agent_commerce_lock_pa2($pdo):''));
    $query->execute([$owner,$id,$location]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Select due locations without a source request. The shared worker owns the job lease. */
function vp3_connected_sites_due_v1(PDO $pdo, array $user, DateTimeImmutable $now): array
{
    $owner = vp3_connected_sites_report_owner_v1($user);
    if (!vp3_connected_sites_report_ready_v1($pdo)) return [];
    $rows = vp3_connected_sites_report_rows_v1($pdo,$owner,false);
    $sales = vp3_connected_sites_sales_rows_v1($pdo,$owner);
    $targets = [];
    foreach (vp3_connected_sites_report_connections_v1($pdo,$owner) as $private) {
        $connection = vp3_gelato_public_v1($private);
        $ticketsAllowed = in_array('tickets.open.read',$connection['scopes'],true);
        $salesAllowed = in_array('sales.daily.read',$connection['scopes'],true);
        $grant = vp3_connected_sites_report_grant_v1($private);
        foreach ($connection['locations'] as $location) {
            $id = $connection['id'];
            $locationId = $location['id'];
            $row = $rows[$id][$locationId] ?? null;
            $checked = ($row['grant_revision'] ?? null) === $grant ? (int)$row['checked_at'] : 0;
            if ($ticketsAllowed && $checked <= $now->getTimestamp() - VP3_CONNECTED_SITES_CHECK_INTERVAL_V1) {
                $targets[] = ['connection_id'=>$id,'location_id'=>$locationId,'checked_at'=>$checked,'grant_revision'=>$grant];
            }
            if (!$salesAllowed) continue;
            $date = vp3_connected_sites_sales_date_v1($location,$now);
            $checked = vp3_connected_sites_sales_due_v1($sales[$id][$locationId] ?? null,$grant,$date,$now->getTimestamp());
            if ($checked !== null) {
                $targets[] = ['connection_id'=>$id,'location_id'=>$locationId,'checked_at'=>$checked,'grant_revision'=>$grant,'dataset'=>'sales'];
            }
        }
    }
    usort($targets,static fn(array $a,array $b): int => [$a['checked_at'],$a['connection_id'],$a['location_id']]
        <=> [$b['checked_at'],$b['connection_id'],$b['location_id']]);
    return array_slice($targets,0,12);
}

function vp3_connected_sites_report_observation_v1(mixed $value): ?int
{
    if (!is_string($value) || !preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?(?:Z|\+00:00)\z/D',$value,$match)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$match[1],new DateTimeZone('UTC'));
    return $date && $date->format('Y-m-d\TH:i:s') === $match[1] ? $date->getTimestamp() : null;
}

/** Normalize only the source's KDS target exceptions and leave all raw ticket data at the site. */
function vp3_connected_sites_ticket_snapshot_v1(array $result, string $connection, int $location, int $checked): array
{
    $data = $result['data'] ?? null;
    $time = vp3_connected_sites_report_observation_v1($result['asOf'] ?? null);
    if ($time === null || $time > $checked+60 || $time < $checked-120
        || !is_array($data) || !is_array($data['tickets'] ?? null) || !array_is_list($data['tickets'])
        || count($data['tickets']) > 50 || !is_bool($data['truncated'] ?? null)) {
        throw new RuntimeException('FoodMonster did not return a current POS snapshot.',502);
    }
    $partial = $data['truncated'];
    $findings = [];
    $seen = [];
    foreach ($data['tickets'] as $ticket) {
        $identity = $ticket['checkId'] ?? null;
        $number = $ticket['checkNumber'] ?? null;
        $late = $ticket['kitchen']['late'] ?? null;
        if (!is_string($identity) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$identity)
            || isset($seen[$identity]) || !is_bool($late)) {
            $partial = true;
            continue;
        }
        $seen[$identity] = true;
        if (!$late) continue;
        if (!is_string($number) || !preg_match('/\A[A-Za-z0-9_-]{1,35}\z/D',$number)) {
            $partial = true;
            continue;
        }
        $ref = hash('sha256','foodmonster|'.$connection.'|'.$location.'|pos-kitchen-target|'.$identity);
        $findings[$ref] = ['kind'=>'pos_kitchen_target','ticket_number'=>$number,'status'=>'open','verified_at'=>$time];
    }
    return ['state'=>$partial?'partial':'ready','verified_at'=>$time,'tickets_checked'=>count($seen),'findings'=>$findings];
}

function vp3_connected_sites_saved_findings_v1(?array $row): array
{
    $findings = $row ? json_decode($row['findings_json'],true,8,JSON_THROW_ON_ERROR) : [];
    return is_array($findings) ? $findings : [];
}

function vp3_connected_sites_material_v1(array $findings): array
{
    ksort($findings);
    return array_map(static fn(array $finding): array => [$finding['status'],$finding['generation'],$finding['ticket_number']],$findings);
}

/** Read outside locks, then commit only while the active job and the exact paired grant still match. */
function vp3_connected_sites_scan_v1(PDO $pdo, array $user, ?DateTimeImmutable $now = null,
    ?callable $transport = null, ?array $target = null, ?callable $commitAuthority = null): array
{
    $owner = vp3_connected_sites_report_owner_v1($user);
    if ($pdo->inTransaction()) throw new LogicException('Connected site reads must run outside a database transaction.');
    if (!vp3_connected_sites_report_ready_v1($pdo)) return ['state'=>'upgrade_required'];
    $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $target ??= vp3_connected_sites_due_v1($pdo,$user,$now)[0] ?? null;
    if ($target === null) return ['state'=>'no_due_source'];
    $id = $target['connection_id'] ?? null;
    $location = $target['location_id'] ?? null;
    if (!is_string($id) || !is_int($location) || $location < 1) throw new InvalidArgumentException('Choose one connected site and location.');
    require_once __DIR__.'/connected-gelato-v1.php';
    $connection = vp3_gelato_connection_v1($pdo,$owner,$id);
    $grant = vp3_connected_sites_report_grant_v1($connection);
    $public = vp3_gelato_public_v1($connection);
    $locationScope = array_column($public['locations'],null,'id')[$location] ?? null;
    $sales = ($target['dataset'] ?? null) === 'sales';
    $capability = $sales ? 'sales.daily.read' : 'tickets.open.read';
    if (($target['grant_revision'] ?? $grant) !== $grant || !in_array($capability,$public['scopes'],true) || $locationScope === null) {
        throw new RuntimeException('The connected site read permission changed.',403);
    }
    if ($sales) {
        return vp3_connected_sites_sales_scan_v1($pdo,$user,$id,$locationScope,$grant,$target['checked_at'],$now,$transport,$commitAuthority);
    }
    $checked = $now->getTimestamp();
    $before = vp3_connected_sites_report_row_v1($pdo,$owner,$id,$location);
    $expectedChecked = ($before['grant_revision'] ?? null) === $grant ? (int)$before['checked_at'] : 0;
    if ($expectedChecked >= $checked || ($target['checked_at'] ?? $expectedChecked) !== $expectedChecked) return ['state'=>'superseded'];
    try {
        $result = vp3_gelato_operation_v1($pdo,$owner,$id,'tickets.open.read',$location,request:$transport,limit:50);
        $snapshot = vp3_connected_sites_ticket_snapshot_v1($result,$id,$location,$checked);
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException || $error->getCode() === 404) throw $error;
        $snapshot = ['state'=>$error->getCode()===403?'authorization_required':'unavailable'];
    }

    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$owner,$id,$location,$grant,$checked,$expectedChecked,$snapshot,$commitAuthority): array {
        vp3_gelato_knowledge_lock_owner_v1($pdo,$owner);
        if ($commitAuthority !== null) $commitAuthority();
        if (vp3_connected_sites_report_grant_v1(vp3_gelato_connection_v1($pdo,$owner,$id,true)) !== $grant) {
            throw new RuntimeException('The connected site read permission changed.',403);
        }
        $previous = vp3_connected_sites_report_row_v1($pdo,$owner,$id,$location,true);
        $sameGrant = ($previous['grant_revision'] ?? null) === $grant;
        $current = $sameGrant ? $previous : null;
        if ((int)($current['checked_at'] ?? 0) !== $expectedChecked
            || (isset($snapshot['verified_at']) && $snapshot['verified_at'] < (int)($current['verified_at'] ?? 0))) {
            return ['state'=>'superseded'];
        }

        $state = $snapshot['state'];
        $priorFindings = vp3_connected_sites_saved_findings_v1($current);
        $findings = $state === 'authorization_required' ? [] : $priorFindings;
        $seenFindings = $snapshot['findings'] ?? [];
        foreach ($seenFindings as $ref=>&$finding) {
            $prior = $priorFindings[$ref] ?? null;
            $finding['generation'] = ($prior['status'] ?? null) === 'open' ? $prior['generation'] : $snapshot['verified_at'];
        }
        unset($finding);
        if ($state === 'ready') {
            foreach (array_diff_key($findings,$snapshot['findings']) as $ref=>$finding) {
                if ($finding['status'] !== 'open') continue;
                $findings[$ref]['status'] = 'resolved';
                $findings[$ref]['verified_at'] = $snapshot['verified_at'];
            }
        }
        $findings = $seenFindings + $findings;
        $findings = array_filter($findings,static fn(array $finding): bool => $finding['verified_at'] >= $checked-7*86400);
        uasort($findings,static fn(array $a,array $b): int => [$a['status']!=='open',-$a['verified_at']] <=> [$b['status']!=='open',-$b['verified_at']]);
        $findings = array_slice($findings,0,80,true);
        $verified = $state === 'authorization_required' ? null : ($snapshot['verified_at'] ?? $current['verified_at'] ?? null);
        $count = $state === 'authorization_required' ? null : ($snapshot['tickets_checked'] ?? $current['tickets_checked'] ?? null);
        $json = json_encode($findings,JSON_THROW_ON_ERROR);
        if ($previous === null) {
            $pdo->prepare("INSERT INTO vp3_connected_site_reports_v1
                (owner_user_id,provider,connection_id,location_id,grant_revision,checked_at,verified_at,state,tickets_checked,findings_json)
                VALUES (?,'foodmonster',?,?,?,?,?,?,?,?)")->execute([$owner,$id,$location,$grant,$checked,$verified,$state,$count,$json]);
        } else {
            $pdo->prepare("UPDATE vp3_connected_site_reports_v1 SET grant_revision=?,checked_at=?,verified_at=?,state=?,tickets_checked=?,findings_json=?
                WHERE owner_user_id=? AND provider='foodmonster' AND connection_id=? AND location_id=?")
                ->execute([$grant,$checked,$verified,$state,$count,$json,$owner,$id,$location]);
        }
        $changed = ($current['state'] ?? null) !== $state
            || vp3_connected_sites_material_v1($priorFindings) !== vp3_connected_sites_material_v1($findings);
        if ($changed) vp3_coordination_source_changed_v1($pdo,$owner);
        return ['state'=>$state,'connection_id'=>$id,'location_id'=>$location,'tickets_checked'=>$count,
            'verified_at'=>$verified === null ? null : (int)$verified,'findings'=>count($seenFindings),'changed'=>$changed];
    });
}

function vp3_connected_sites_report_iso_v1(mixed $value): ?string
{
    return (int)$value > 0 ? gmdate('Y-m-d\TH:i:s\Z',(int)$value) : null;
}

function vp3_connected_sites_report_state_v1(?array $row,int $now): string
{
    if ($row === null) return 'unchecked';
    if (in_array($row['state'],['unchecked','on_demand','authorization_required'],true)) return $row['state'];
    if ((int)($row['verified_at'] ?? 0) < 1) return 'unavailable';
    if ($row['state'] === 'unavailable' || (int)$row['verified_at'] < $now-VP3_CONNECTED_SITES_REPORT_TTL_V1) return 'stale';
    return $row['state'];
}

function vp3_connected_sites_display_finding_v1(string $ref,array $finding,array $connection,array $location): array
{
    $ticket = '#'.$finding['ticket_number'];
    $resolved = $finding['status'] === 'resolved';
    $name = $connection['label'] ?: $connection['agentName'];
    return [
        'ref'=>'connected-sites:'.$ref,'kind'=>$finding['kind'],'title'=>$name.' · Ticket '.$ticket,
        'summary'=>$resolved ? 'The latest complete POS scan at '.$location['name'].' no longer reports a kitchen target exception for ticket '.$ticket.'.'
            : 'FoodMonster reports that an item on ticket '.$ticket.' at '.$location['name'].' has exceeded its kitchen station’s target time.',
        'next_step'=>$resolved ? '' : 'Review ticket '.$ticket.' in Point of Sale to confirm whether it needs attention.',
        'question'=>$resolved ? '' : 'Do you want to check what is holding up ticket '.$ticket.' at '.$location['name'].'?',
        'status'=>$finding['status'],'priority'=>$resolved?'normal':'high','url'=>$connection['origin'].'/pos.php',
        'location_id'=>$location['id'],'verified_at'=>vp3_connected_sites_report_iso_v1($finding['verified_at']),
        'generation'=>$finding['generation'],
    ];
}

function vp3_connected_sites_sales_ref_v1(string $id,int $location,string $date): string
{
    return 'connected-sites:'.hash('sha256','foodmonster|'.$id.'|'.$location.'|daily-sales|'.$date);
}

/** Authorized display of the same reports used by Top of Mind. Never calls the site. */
function vp3_connected_sites_report_v1(
    PDO $pdo,array $scope,?DateTimeImmutable $now=null,?array $refs=null,bool $includeFindings=true
): array
{
    $user = $scope['user'] ?? [];
    $owner = vp3_connected_sites_report_owner_v1($user);
    if (!function_exists('knowledge_retrieval_v162_owner_session')) require_once __DIR__.'/knowledge-retrieval-v162.php';
    if (!is_string($scope['principal']['kind'] ?? null) || !knowledge_retrieval_v162_owner_session($user,$scope['principal'])) {
        throw new RuntimeException('Private Agent Chat access is required.',403);
    }
    $clock = $now ?? new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $now = $clock->getTimestamp();
    $connections = vp3_connected_sites_report_connections_v1($pdo,$owner);
    if (!$connections) return ['state'=>'unconfigured','connections'=>[]];
    $ready = vp3_connected_sites_report_ready_v1($pdo);
    $saved = $ready ? vp3_connected_sites_report_rows_v1($pdo,$owner,$includeFindings) : [];
    $savedSales = $ready ? vp3_connected_sites_sales_rows_v1($pdo,$owner) : [];
    $reports = [];
    foreach ($connections as $private) {
        $connection = vp3_gelato_public_v1($private);
        $id = $connection['id'];
        $grant = vp3_connected_sites_report_grant_v1($private);
        $proactive = in_array('tickets.open.read',$connection['scopes'],true);
        $salesAllowed = in_array('sales.daily.read',$connection['scopes'],true);
        $locations = $findings = [];
        $posStates = $salesStates = [];
        $lastChecked = $lastVerified = 0;
        foreach ($connection['locations'] as $location) {
            $row = $proactive ? ($saved[$id][$location['id']] ?? null) : null;
            if ($row && $row['grant_revision'] !== $grant) $row = null;
            $state = match (true) {
                !$ready=>'upgrade_required',
                !$proactive=>'authorization_required',
                default=>vp3_connected_sites_report_state_v1($row,$now),
            };
            $lastChecked = max($lastChecked,(int)($row['checked_at'] ?? 0));
            $lastVerified = max($lastVerified,(int)($row['verified_at'] ?? 0));
            $tickets = $includeFindings ? vp3_connected_sites_saved_findings_v1($row) : [];
            $ticketCount = isset($row['tickets_checked']) ? (int)$row['tickets_checked'] : null;
            $current = $state === 'ready';
            $locationView = ['id'=>$location['id'],'name'=>$location['name'],'state'=>$state,
                'lastCheckedAt'=>vp3_connected_sites_report_iso_v1($row['checked_at'] ?? null),
                'lastVerifiedAt'=>vp3_connected_sites_report_iso_v1($row['verified_at'] ?? null),
                'ticketsChecked'=>$ticketCount,'openTickets'=>$current ? $ticketCount : null,
                'lateTickets'=>$current && $includeFindings ? count(array_filter($tickets,static fn(array $item):bool=>$item['status']==='open')) : null];
            if ($proactive) $posStates[] = $state;
            if ($salesAllowed) {
                $salesRow = $savedSales[$id][$location['id']] ?? null;
                $date = vp3_connected_sites_sales_date_v1($location,$clock);
                $sales = $ready ? vp3_connected_sites_sales_projection_v1($salesRow,$grant,$date)
                    : ['state'=>'upgrade_required','businessDate'=>$date,'lastVerifiedAt'=>null];
                $locationView['sales'] = $sales;
                $salesStates[] = $sales['state'];
                if (($salesRow['grant_revision'] ?? null) === $grant) $lastChecked = max($lastChecked,(int)$salesRow['checked_at']);
                if ($sales['lastVerifiedAt']) $lastVerified = max($lastVerified,(int)strtotime($sales['lastVerifiedAt']));
                if ($refs !== null) {
                    foreach (vp3_connected_sites_sales_history_v1($salesRow,$grant) as $day=>$entry) {
                        $ref = vp3_connected_sites_sales_ref_v1($id,$location['id'],$day);
                        if (in_array(substr($ref,strlen('connected-sites:')),$refs,true)) $locationView['salesHistory'][$ref] = $entry;
                    }
                }
            }
            $locations[] = $locationView;
            foreach ($tickets as $ref=>$finding) {
                if ($refs !== null && !in_array($ref,$refs,true)) continue;
                $display = vp3_connected_sites_display_finding_v1($ref,$finding,$connection,$location);
                $display['source_state'] = $state;
                $findings[] = $display;
            }
        }
        $states = array_values(array_unique([...$posStates,...$salesStates]));
        $state = !$ready ? 'upgrade_required' : (count($states)>1 ? 'partial' : ($states[0] ?? 'on_demand'));
        $datasets = [];
        foreach (['tickets.open.read'=>'Open POS tickets and kitchen status','tickets.detail.read'=>'Individual POS tickets',
            'sales.daily.read'=>'Daily sales history','orders.read'=>'Active pickup orders','menu'=>'Menu','recipe'=>'Recipes'] as $capability=>$label) {
            if (!in_array($capability,$connection['scopes'],true)) continue;
            $scanStates = match ($capability) {'tickets.open.read'=>$posStates,'sales.daily.read'=>$salesStates,default=>null};
            $datasetStates = array_values(array_unique($scanStates ?? []));
            $datasets[] = ['id'=>$capability,'label'=>$label,'access'=>'read','mode'=>$scanStates!==null?'proactive':'on_demand',
                'state'=>$scanStates===null?'on_demand':(!$ready?'upgrade_required':(count($datasetStates)>1?'partial':($datasetStates[0]??'unchecked')))];
        }
        usort($findings,static fn(array $a,array $b): int => (($a['status']!=='open') <=> ($b['status']!=='open'))
            ?: strcmp($b['verified_at'],$a['verified_at']) ?: strcmp($a['ref'],$b['ref']));
        $reports[$id] = ['state'=>$state,'lastCheckedAt'=>vp3_connected_sites_report_iso_v1($lastChecked),
            'lastVerifiedAt'=>vp3_connected_sites_report_iso_v1($lastVerified),'datasets'=>$datasets,'locations'=>$locations,
            'findings'=>$refs===null?array_slice($findings,0,40):$findings];
    }
    return ['state'=>$ready?'ready':'upgrade_required','connections'=>$reports];
}

function vp3_coordination_connected_sites_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    $report = vp3_connected_sites_report_v1($pdo,$scope,$now,$refs);
    $coverage = match($report['state']) {'upgrade_required'=>'unavailable','unconfigured'=>'unconfigured',default=>'ready'};
    if ($coverage === 'unavailable') return ['items'=>[],'coverage'=>$coverage,'failure'=>'storage_unavailable'];
    $items = [];
    foreach ($report['connections'] as $id=>$connection) {
        if (in_array($connection['state'],['partial','unchecked','stale','missing','unavailable','authorization_required'],true)) $coverage = 'limited';
        foreach ($connection['findings'] as $finding) {
            if ($finding['status'] !== 'open' || $finding['source_state'] !== 'ready') continue;
            $ref = $finding['ref'];
            $item = vp3_coordination_source_item_v1($ref,'connected-sites',$finding['title'],$finding['summary'],
                $finding['next_step'],$finding['question'],$finding['url'],'open','high',
                [$finding['kind'],$finding['generation'],$finding['summary']]);
            $items[$ref] = $item + ['trigger'=>'pos_attention','response_revision'=>hash('sha256',$ref.'|'.$finding['generation'])];
        }
        foreach ($connection['locations'] as $location) {
            $currentSales = $location['sales'] ?? null;
            $reports = $refs === null && ($currentSales['state'] ?? null) === 'ready'
                ? [vp3_connected_sites_sales_ref_v1($id,$location['id'],$currentSales['businessDate'])=>$currentSales]
                : ($location['salesHistory'] ?? []);
            foreach ($reports as $ref=>$sales) {
                $totals = $sales['totals'];
                $currency = isset($totals['currency']) ? ' '.$totals['currency'] : ' (currency unrecorded)';
                $lines = [];
                foreach (['netSales'=>'net sales','grossSales'=>'gross sales','refunds'=>'refunds'] as $field=>$label) {
                    if (isset($totals[$field])) $lines[] = $label.' '.$totals[$field].$currency;
                }
                if (isset($totals['tickets'])) $lines[] = $totals['tickets'].' closed '.($totals['tickets']===1?'ticket':'tickets');
                $title = 'FoodMonster daily sales · '.$location['name'].' · '.$sales['businessDate'];
                $summary = 'FoodMonster verified '.implode(', ',$lines).' at '.$location['name'].' for '.$sales['businessDate'].'.';
                $item = vp3_coordination_source_item_v1($ref,'connected-sites',$title,$summary,'','','','open','normal',
                    ['daily_sales',$sales['businessDate'],$location['name'],$totals]);
                $items[$ref] = $item + ['trigger'=>'daily_sales',
                    'history_only'=>$sales['businessDate']!==($currentSales['businessDate'] ?? null)];
            }
        }
    }
    return ['items'=>$items,'coverage'=>$coverage];
}
