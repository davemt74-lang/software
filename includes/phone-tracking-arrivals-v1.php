<?php
declare(strict_types=1);

const VP3_PHONE_ARRIVAL_ENTER_METRES_V1 = 100;
const VP3_PHONE_ARRIVAL_EXIT_METRES_V1 = 200;
const VP3_PHONE_ARRIVAL_MAX_ERROR_V1 = 50;

function vp3_phone_arrival_schema_v1(PDO $pdo, string $options): void
{
    // Phone pairing can be installed on its own. Validate the private Chat scope at runtime too.
    $chatFk = schema_metadata_table_exists($pdo, 'chat_conversations')
        ? ', FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_arrival_rules_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        device_id CHAR(32) NOT NULL, place_id CHAR(32) NOT NULL, generation BIGINT NOT NULL,
        agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL, task_query VARCHAR(64) NOT NULL,
        state VARCHAR(12) NOT NULL, version BIGINT NOT NULL, zone_state VARCHAR(12) NOT NULL,
        accepted_at BIGINT NULL, visit_number BIGINT NOT NULL,
        UNIQUE (owner_user_id,agent_id,conversation_id,device_id,place_id),
        FOREIGN KEY (owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE,
        FOREIGN KEY (owner_user_id,place_id) REFERENCES vp3_phone_places_v1(owner_user_id,id) ON DELETE CASCADE
        $chatFk
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_arrival_events_v1 (
        id CHAR(32) NOT NULL PRIMARY KEY, rule_id CHAR(32) NOT NULL, visit_number BIGINT NOT NULL,
        arrived_at BIGINT NOT NULL, UNIQUE (rule_id,visit_number),
        FOREIGN KEY (rule_id) REFERENCES vp3_phone_arrival_rules_v1(id) ON DELETE CASCADE
    )".$options);
}

function vp3_phone_arrival_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT r.id,r.owner_user_id,r.device_id,r.place_id,r.agent_id,r.conversation_id,r.generation,
            r.task_query,r.state,r.version,r.zone_state,r.accepted_at,r.visit_number,e.id,e.rule_id,e.visit_number,e.arrived_at
            FROM vp3_phone_arrival_rules_v1 r JOIN vp3_phone_arrival_events_v1 e ON e.rule_id=r.id WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** The private Chat coordinator checks the caller's principal. Bind its persisted destination as well. */
function vp3_phone_arrival_scope_v1(PDO $pdo, int $owner, int $agent, int $conversation): void
{
    vp3_phone_account_v1($pdo,$owner,true);
    $scope = $agent === 0 ? 'user_agent_id IS NULL' : 'user_agent_id=?';
    $params = $agent === 0 ? [$conversation,$owner] : [$conversation,$owner,$agent];
    if ($agent < 0 || $conversation < 1 || !vp3_phone_sql_v1($pdo,
        'SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND '.$scope.vp3_phone_lock_v1($pdo),$params)->fetchColumn()) {
        throw new RuntimeException('Choose your private Agent Chat for this arrival.',403);
    }
}

/** Query for phone rules whose original private conversation and account still match. */
function vp3_phone_arrival_rows_v1(PDO $pdo, string $where, array $params): array
{
    return vp3_phone_sql_v1($pdo,'SELECT r.*,d.device_name,p.name AS place_name,
        p.latitude AS place_latitude,p.longitude AS place_longitude,p.accuracy AS place_accuracy
        FROM vp3_phone_arrival_rules_v1 r
        JOIN vp3_phone_devices_v1 d ON d.owner_user_id=r.owner_user_id AND d.id=r.device_id AND d.generation=r.generation
        JOIN vp3_phone_places_v1 p ON p.owner_user_id=r.owner_user_id AND p.id=r.place_id
        JOIN chat_conversations c ON c.id=r.conversation_id AND c.user_id=r.owner_user_id
            AND ((r.agent_id>0 AND c.user_agent_id=r.agent_id) OR (r.agent_id=0 AND c.user_agent_id IS NULL))
        WHERE d.device_status=\'approved\' AND d.allow_location=1 AND d.expires_at>?
            AND d.location_state IN (\'waiting\',\'live\') AND '.$where.' ORDER BY r.id',
        array_merge([time()],$params))->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_phone_arrival_brief_v1(array $rule): array
{
    return ['id'=>$rule['id'],'generation'=>(int)$rule['generation'],'version'=>(int)$rule['version'],
        'state'=>$rule['state'],'place_name'=>$rule['place_name'],'task_query'=>$rule['task_query']];
}

function vp3_phone_arrival_owner_brief_v1(array $rule): array
{
    return vp3_phone_arrival_brief_v1($rule) + ['device_id'=>$rule['device_id'],'device_name'=>$rule['device_name'],
        'place_id'=>$rule['place_id']];
}

function vp3_phone_arrival_list_v1(PDO $pdo, int $owner, int $agent, int $conversation): array
{
    return vp3_phone_transaction_v1($pdo,static function() use($pdo,$owner,$agent,$conversation): array {
        vp3_phone_arrival_scope_v1($pdo,$owner,$agent,$conversation);
        $rules=vp3_phone_arrival_rows_v1($pdo,'r.owner_user_id=? AND r.agent_id=? AND r.conversation_id=?',[$owner,$agent,$conversation]);
        return array_map('vp3_phone_arrival_owner_brief_v1',$rules);
    });
}

function vp3_phone_arrival_request_v1(PDO $pdo, int $owner, int $agent, int $conversation, string $deviceId,
    int $generation, string $placeId, string $taskQuery): array
{
    $topic = trim($taskQuery);
    if (!mb_check_encoding($topic,'UTF-8') || mb_strlen($topic)>64 || preg_match('/[\p{Cc}\p{Cf}]/u',$topic)) {
        throw new RuntimeException('Use a task topic of up to 64 characters.',422);
    }
    return vp3_phone_transaction_v1($pdo,static function() use($pdo,$owner,$agent,$conversation,$deviceId,$generation,$placeId,$topic): array {
        vp3_phone_arrival_scope_v1($pdo,$owner,$agent,$conversation);
        $device=vp3_phone_row_v1($pdo,$owner,$deviceId);
        vp3_phone_generation_v1($device,['generation'=>$generation]);
        if ($device['device_status']!=='approved' || !$device['allow_location'] || (int)$device['expires_at']<=time()
            || !in_array($device['location_state'],['waiting','live'],true)) {
            throw new RuntimeException('Start live sharing on the selected phone first.',409);
        }
        $place=vp3_phone_sql_v1($pdo,'SELECT name,accuracy FROM vp3_phone_places_v1 WHERE owner_user_id=? AND id=?',
            [$owner,$placeId])->fetch(PDO::FETCH_ASSOC);
        if (!$place) throw new RuntimeException('Select a saved place from this account.',404);
        if ((float)$place['accuracy']>VP3_PHONE_ARRIVAL_MAX_ERROR_V1) {
            throw new RuntimeException('Save a more accurate location for this place before using arrival alerts.',409);
        }
        $scope=[$owner,$agent,$conversation,$deviceId,$placeId];
        $rule=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_arrival_rules_v1
            WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND device_id=? AND place_id=?',$scope)->fetch(PDO::FETCH_ASSOC);
        if ($rule && $rule['task_query']!==$topic) {
            throw new RuntimeException('Stop the existing arrival rule before changing its task topic.',409);
        }
        if (!$rule) {
            $count=(int)vp3_phone_sql_v1($pdo,'SELECT COUNT(*) FROM vp3_phone_arrival_rules_v1 WHERE owner_user_id=? AND device_id=?',
                [$owner,$deviceId])->fetchColumn();
            if ($count>=8) throw new RuntimeException('Stop an unused arrival rule on this phone first.',429);
            $id=bin2hex(random_bytes(16));
            vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_arrival_rules_v1
                (owner_user_id,agent_id,conversation_id,device_id,place_id,id,generation,task_query,state,version,zone_state,visit_number)
                VALUES(?,?,?,?,?,?,?,?,'requested',1,'unknown',0)",array_merge($scope,[$id,$generation,$topic]));
            $rule=['id'=>$id,'device_id'=>$deviceId,'place_id'=>$placeId,'generation'=>$generation,'task_query'=>$topic,'state'=>'requested','version'=>1];
        }
        return vp3_phone_arrival_owner_brief_v1($rule+['device_name'=>$device['device_name'],'place_name'=>$place['name']]);
    });
}

function vp3_phone_arrival_cancel_v1(PDO $pdo, int $owner, int $agent, int $conversation, string $ruleId): void
{
    vp3_phone_transaction_v1($pdo,static function() use($pdo,$owner,$agent,$conversation,$ruleId): array {
        vp3_phone_arrival_scope_v1($pdo,$owner,$agent,$conversation);
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_arrival_rules_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND id=?',
            [$owner,$agent,$conversation,$ruleId]);
        return [];
    });
}

function vp3_phone_arrival_clear_v1(PDO $pdo, array $phone): void
{
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_arrival_rules_v1 WHERE owner_user_id=? AND device_id=?',[$phone['owner_user_id'],$phone['id']]);
}

function vp3_phone_arrival_device_rules_v1(PDO $pdo, array $phone): array
{
    $params=[$phone['owner_user_id'],$phone['id'],$phone['generation']];
    // Older private-Chat installations can use live GPS independently. A phone with no rules has nothing to resolve.
    if (!vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_arrival_rules_v1 WHERE owner_user_id=? AND device_id=? AND generation=? LIMIT 1',
        $params)->fetchColumn()) return [];
    return vp3_phone_arrival_rows_v1($pdo,'r.owner_user_id=? AND r.device_id=? AND r.generation=?',$params);
}

function vp3_phone_arrival_holder_v1(PDO $pdo, array $phone, string $action, array $input, int $now): void
{
    if (($input['device_id']??null)!==$phone['id']) throw new RuntimeException('Refresh this phone connection.',403);
    vp3_phone_generation_v1($phone,$input);
    if ($phone['device_status']!=='approved' || !$phone['allow_location'] || !in_array($phone['location_state'],['waiting','live'],true)) {
        throw new RuntimeException('Start live sharing on this phone first.',409);
    }
    $id=$input['rule_id']??null;
    $version=$input['rule_version']??null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) || !is_int($version) || $version<1) {
        throw new RuntimeException('Check this arrival request.',422);
    }
    $rule=array_column(vp3_phone_arrival_device_rules_v1($pdo,$phone),null,'id')[$id]??null;
    if ($action==='accept_arrival') {
        if (!$rule) throw new RuntimeException('This arrival request is unavailable.',409);
        if ($rule['state']==='active' && in_array($version,[(int)$rule['version']-1,(int)$rule['version']],true)) return;
        if ($rule['state']!=='requested' || (int)$rule['version']!==$version) throw new RuntimeException('Refresh this arrival request.',409);
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_arrival_rules_v1 SET state='active',version=version+1,accepted_at=? WHERE id=?",
            [$now,$id]);
        return;
    }
    if (!in_array($action,['decline_arrival','stop_arrival'],true)) throw new RuntimeException('Unknown arrival action.',422);
    // Withdrawing the same immutable rule wins over an acceptance in another tab. Retries cannot touch a new request.
    if (!$rule) return;
    if ($version>(int)$rule['version']) throw new RuntimeException('Refresh this arrival request.',409);
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_arrival_rules_v1 WHERE id=?',[$id]);
}

/** Classify only when the combined uncertainty is fully inside or outside the boundary. */
function vp3_phone_arrival_zone_v1(array $phone, array $rule): ?string
{
    $error=(float)$phone['accuracy'];
    $anchorError=(float)$rule['place_accuracy'];
    if ($error>VP3_PHONE_ARRIVAL_MAX_ERROR_V1 || $anchorError>VP3_PHONE_ARRIVAL_MAX_ERROR_V1) return null;
    $lat=deg2rad((float)$phone['latitude']);
    $anchor=deg2rad((float)$rule['place_latitude']);
    $lonDelta=deg2rad((float)$phone['longitude']-(float)$rule['place_longitude']);
    $a=sin(($lat-$anchor)/2)**2+cos($lat)*cos($anchor)*sin($lonDelta/2)**2;
    $distance=12742000*asin(sqrt(max(0.0,min(1.0,$a))));
    $margin=$error+$anchorError;
    if ($distance+$margin<=VP3_PHONE_ARRIVAL_ENTER_METRES_V1) return 'inside';
    if ($distance-$margin>=VP3_PHONE_ARRIVAL_EXIT_METRES_V1) return 'outside';
    return null;
}

/** Runs on a newly accepted live fix with the native owner/device lock held. No task lookup or Chat delivery here. */
function vp3_phone_arrival_fix_v1(PDO $pdo, array $phone, array $input, int $now): void
{
    $grants=$input['arrival_grants']??null;
    $time=(int)$phone['fix_at'];
    if (!is_array($grants) || !$grants || $time<=$now-VP3_PHONE_FRESH_SECONDS_V1 || $time>$now+5) return;
    foreach (vp3_phone_arrival_device_rules_v1($pdo,$phone) as $rule) {
        $id=$rule['id'];
        if ($rule['state']!=='active' || ($grants[$id]??null)!==(int)$rule['version'] || $time<=(int)$rule['accepted_at']) continue;
        $zone=vp3_phone_arrival_zone_v1($phone,$rule);
        if ($zone===null || $zone===$rule['zone_state']) continue;
        $visit=(int)$rule['visit_number'];
        if ($zone==='inside' && $rule['zone_state']==='outside') {
            ++$visit;
            vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_arrival_events_v1 (id,rule_id,visit_number,arrived_at) VALUES(?,?,?,?)',
                [bin2hex(random_bytes(16)),$id,$visit,$time]);
        }
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_arrival_rules_v1 SET zone_state=?,visit_number=? WHERE id=?',[$zone,$visit,$id]);
    }
}
