<?php
declare(strict_types=1);

/**
 * VP3 Team Scheduling v6.00
 *
 * Team pools compose the canonical v4.30 scheduling store instead of creating
 * a parallel calendar. Each pool member maps to one owned VP3 schedule/event
 * type, so availability, buffers, external-calendar busy blocks and provider
 * writeback remain authoritative in the existing scheduling runtime.
 */
const VP3_AGENT_TEAM_SCHEDULING_V600 = 'agent-team-scheduling-v600-20260911';

function agent_team_scheduling_schema_ready_v600(?PDO $pdo=null): bool
{
    $pdo ??= db();
    if(!$pdo) return false;
    foreach(['agent_team_scheduling_pools','agent_team_scheduling_members','agent_team_scheduling_bookings','agent_team_scheduling_booking_members'] as $table){
        if(!table_exists($table)) return false;
    }
    return column_exists('agent_team_scheduling_pools','workspace_owner_user_id')
        && column_exists('agent_team_scheduling_pools','mode')
        && column_exists('agent_team_scheduling_members','event_type_id')
        && column_exists('agent_team_scheduling_members','assignment_count')
        && column_exists('agent_team_scheduling_bookings','public_token')
        && column_exists('agent_team_scheduling_booking_members','canonical_booking_id');
}

function agent_team_scheduling_ensure_schema_v600(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo) throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_team_scheduling_pools (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      workspace_owner_user_id INT UNSIGNED NOT NULL,
      agent_id BIGINT UNSIGNED NULL,
      name VARCHAR(190) NOT NULL,
      slug VARCHAR(80) NOT NULL,
      public_key CHAR(64) NOT NULL,
      description TEXT NULL,
      mode VARCHAR(24) NOT NULL DEFAULT 'round_robin',
      timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
      routing_question VARCHAR(500) NOT NULL DEFAULT '',
      public_enabled TINYINT(1) NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_team_schedule_slug (workspace_owner_user_id,slug),
      UNIQUE KEY uq_team_schedule_public (public_key),
      INDEX idx_team_schedule_owner (workspace_owner_user_id,is_active,mode,id),
      INDEX idx_team_schedule_agent (agent_id,is_active,id),
      CONSTRAINT fk_team_schedule_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_team_schedule_agent FOREIGN KEY (agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_team_scheduling_members (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      pool_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      schedule_id BIGINT UNSIGNED NOT NULL,
      event_type_id BIGINT UNSIGNED NOT NULL,
      routing_keywords TEXT NULL,
      priority INT NOT NULL DEFAULT 0,
      assignment_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      last_assigned_at DATETIME NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_team_schedule_member (pool_id,user_id),
      INDEX idx_team_schedule_member_pool (pool_id,enabled,priority,assignment_count,id),
      INDEX idx_team_schedule_member_event (event_type_id,enabled,id),
      CONSTRAINT fk_team_schedule_member_pool FOREIGN KEY (pool_id) REFERENCES agent_team_scheduling_pools(id) ON DELETE CASCADE,
      CONSTRAINT fk_team_schedule_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_team_schedule_member_schedule FOREIGN KEY (schedule_id) REFERENCES agent_scheduling_schedules(id) ON DELETE RESTRICT,
      CONSTRAINT fk_team_schedule_member_event FOREIGN KEY (event_type_id) REFERENCES agent_scheduling_event_types(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_team_scheduling_bookings (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      workspace_owner_user_id INT UNSIGNED NOT NULL,
      pool_id BIGINT UNSIGNED NOT NULL,
      assigned_user_id INT UNSIGNED NULL,
      created_by_agent_id BIGINT UNSIGNED NULL,
      start_at_utc DATETIME NOT NULL,
      end_at_utc DATETIME NOT NULL,
      guest_timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
      guest_name VARCHAR(190) NOT NULL,
      guest_email VARCHAR(190) NOT NULL DEFAULT '',
      guest_phone VARCHAR(80) NOT NULL DEFAULT '',
      routing_answer VARCHAR(500) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'confirmed',
      source VARCHAR(40) NOT NULL DEFAULT 'team_public',
      public_token CHAR(64) NOT NULL,
      cancel_token CHAR(64) NOT NULL,
      cancelled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_team_booking_public (public_token),
      UNIQUE KEY uq_team_booking_cancel (cancel_token),
      INDEX idx_team_booking_owner (workspace_owner_user_id,status,start_at_utc,id),
      INDEX idx_team_booking_pool (pool_id,status,start_at_utc,id),
      CONSTRAINT fk_team_booking_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_team_booking_pool FOREIGN KEY (pool_id) REFERENCES agent_team_scheduling_pools(id) ON DELETE RESTRICT,
      CONSTRAINT fk_team_booking_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_team_booking_agent FOREIGN KEY (created_by_agent_id) REFERENCES user_agents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_team_scheduling_booking_members (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      team_booking_id BIGINT UNSIGNED NOT NULL,
      pool_member_id BIGINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      canonical_booking_id BIGINT UNSIGNED NOT NULL,
      role VARCHAR(24) NOT NULL DEFAULT 'participant',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_team_booking_member (team_booking_id,user_id),
      UNIQUE KEY uq_team_booking_canonical (canonical_booking_id),
      INDEX idx_team_booking_members_pool_member (pool_member_id,team_booking_id),
      CONSTRAINT fk_team_booking_member_booking FOREIGN KEY (team_booking_id) REFERENCES agent_team_scheduling_bookings(id) ON DELETE CASCADE,
      CONSTRAINT fk_team_booking_member_pool_member FOREIGN KEY (pool_member_id) REFERENCES agent_team_scheduling_members(id) ON DELETE RESTRICT,
      CONSTRAINT fk_team_booking_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT fk_team_booking_member_canonical FOREIGN KEY (canonical_booking_id) REFERENCES agent_scheduling_bookings(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_team_scheduling_slug_v600(string $value): string
{
    $value=mb_strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/u','-',$value)??'';
    return substr(trim($value,'-'),0,80);
}

function agent_team_scheduling_user_in_workspace_v600(PDO $pdo,int $workspaceOwnerId,int $userId): bool
{
    if($workspaceOwnerId<1||$userId<1)return false;
    if($workspaceOwnerId===$userId)return true;
    if(!table_exists('team_workspace_memberships'))return false;
    $stmt=$pdo->prepare("SELECT 1 FROM team_workspace_memberships WHERE workspace_owner_user_id=? AND member_user_id=? AND status='active' LIMIT 1");
    $stmt->execute([$workspaceOwnerId,$userId]);
    return (bool)$stmt->fetchColumn();
}

function agent_team_scheduling_workspace_users_v600(PDO $pdo,int $workspaceOwnerId): array
{
    if($workspaceOwnerId<1)return [];
    $ids=[$workspaceOwnerId=>true];
    if(table_exists('team_workspace_memberships')){
        $stmt=$pdo->prepare("SELECT member_user_id FROM team_workspace_memberships WHERE workspace_owner_user_id=? AND status='active' ORDER BY id");
        $stmt->execute([$workspaceOwnerId]);
        foreach($stmt->fetchAll()?:[] as $row)$ids[(int)$row['member_user_id']]=true;
    }
    $idList=array_values(array_filter(array_map('intval',array_keys($ids))));
    if(!$idList)return [];
    $in=implode(',',array_fill(0,count($idList),'?'));
    $stmt=$pdo->prepare("SELECT id,display_name,email FROM users WHERE id IN ({$in}) ORDER BY display_name,email,id");
    $stmt->execute($idList);
    return $stmt->fetchAll()?:[];
}

function agent_team_scheduling_pool_v600(PDO $pdo,int $workspaceOwnerId,int $poolId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_team_scheduling_pools WHERE id=? AND workspace_owner_user_id=? LIMIT 1');
    $stmt->execute([$poolId,$workspaceOwnerId]);
    return $stmt->fetch()?:null;
}

function agent_team_scheduling_public_pool_v600(PDO $pdo,string $publicKey): ?array
{
    $publicKey=strtolower(trim($publicKey));
    if(!preg_match('/^[a-f0-9]{64}$/',$publicKey))return null;
    $stmt=$pdo->prepare("SELECT * FROM agent_team_scheduling_pools WHERE public_key=? AND is_active=1 AND public_enabled=1 LIMIT 1");
    $stmt->execute([$publicKey]);
    return $stmt->fetch()?:null;
}

function agent_team_scheduling_public_url_v600(array $pool): string
{
    $key=strtolower(trim((string)($pool['public_key']??'')));
    return preg_match('/^[a-f0-9]{64}$/',$key)?url('/team-book.php?p='.rawurlencode($key)):'';
}

function agent_team_scheduling_pool_list_v600(PDO $pdo,int $workspaceOwnerId,bool $activeOnly=false): array
{
    $sql='SELECT * FROM agent_team_scheduling_pools WHERE workspace_owner_user_id=?';
    if($activeOnly)$sql.=' AND is_active=1';
    $sql.=' ORDER BY is_active DESC,name,id';
    $stmt=$pdo->prepare($sql);$stmt->execute([$workspaceOwnerId]);
    return $stmt->fetchAll()?:[];
}

function agent_team_scheduling_save_pool_v600(PDO $pdo,array $user,array $input): array
{
    $ownerId=(int)($user['id']??0);if($ownerId<1)throw new RuntimeException('A signed-in workspace owner is required.');
    $poolId=max(0,(int)($input['id']??0));$existing=$poolId>0?agent_team_scheduling_pool_v600($pdo,$ownerId,$poolId):null;
    if($poolId>0&&!$existing)throw new RuntimeException('Team scheduling pool not found.');
    $name=trim(preg_replace('/\s+/u',' ',(string)($input['name']??($existing['name']??'')))??'');
    if($name==='')throw new RuntimeException('Enter a team scheduling name.');
    $mode=(string)($input['mode']??($existing['mode']??'round_robin'));
    if(!in_array($mode,['round_robin','collective'],true))$mode='round_robin';
    $timezone=agent_scheduling_timezone_v430((string)($input['timezone']??($existing['timezone']??($user['timezone']??'UTC'))),'UTC');
    $slug=agent_team_scheduling_slug_v600((string)($input['slug']??($existing['slug']??$name)))?:'team';
    $collision=$pdo->prepare('SELECT id FROM agent_team_scheduling_pools WHERE workspace_owner_user_id=? AND slug=? AND id<>? LIMIT 1');
    $collision->execute([$ownerId,$slug,$poolId]);if($collision->fetchColumn())throw new RuntimeException('That team booking URL slug is already in use.');
    $agentId=max(0,(int)($input['agent_id']??($existing['agent_id']??0)));
    if($agentId>0){$agent=user_agent_get_v236($pdo,$ownerId,$agentId);if(!$agent||empty($agent['is_active']))throw new RuntimeException('Choose one of your active VP3 Agents.');}else $agentId=null;
    $description=trim((string)($input['description']??($existing['description']??'')))?:null;
    $routing=mb_strimwidth(trim((string)($input['routing_question']??($existing['routing_question']??''))),0,500,'');
    $active=array_key_exists('is_active',$input)?(!empty($input['is_active'])?1:0):(int)($existing['is_active']??1);
    $public=array_key_exists('public_enabled',$input)?(!empty($input['public_enabled'])?1:0):(int)($existing['public_enabled']??1);
    if($existing){
        $stmt=$pdo->prepare('UPDATE agent_team_scheduling_pools SET agent_id=?,name=?,slug=?,description=?,mode=?,timezone=?,routing_question=?,public_enabled=?,is_active=? WHERE id=? AND workspace_owner_user_id=?');
        $stmt->execute([$agentId,mb_strimwidth($name,0,190,''),$slug,$description,$mode,$timezone,$routing,$public,$active,$poolId,$ownerId]);
    }else{
        $stmt=$pdo->prepare('INSERT INTO agent_team_scheduling_pools (workspace_owner_user_id,agent_id,name,slug,public_key,description,mode,timezone,routing_question,public_enabled,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$ownerId,$agentId,mb_strimwidth($name,0,190,''),$slug,bin2hex(random_bytes(32)),$description,$mode,$timezone,$routing,$public,$active]);
        $poolId=(int)$pdo->lastInsertId();
    }
    return agent_team_scheduling_pool_v600($pdo,$ownerId,$poolId)?:throw new RuntimeException('Team schedule could not be saved.');
}

function agent_team_scheduling_validate_member_v600(PDO $pdo,array $pool,int $userId,int $eventTypeId): array
{
    $ownerId=(int)$pool['workspace_owner_user_id'];
    if(!agent_team_scheduling_user_in_workspace_v600($pdo,$ownerId,$userId))throw new RuntimeException('That person is not an active member of this Team workspace.');
    $event=agent_scheduling_event_type_v430($pdo,$eventTypeId);
    if(!$event||(int)$event['owner_user_id']!==$userId||empty($event['is_active'])||empty($event['schedule_active']))throw new RuntimeException('Choose an active appointment type owned by that Team member.');
    return $event;
}

function agent_team_scheduling_save_member_v600(PDO $pdo,array $user,int $poolId,array $input): array
{
    $ownerId=(int)($user['id']??0);$pool=agent_team_scheduling_pool_v600($pdo,$ownerId,$poolId);if(!$pool)throw new RuntimeException('Team schedule not found.');
    $userId=max(0,(int)($input['user_id']??0));$eventTypeId=max(0,(int)($input['event_type_id']??0));
    $event=agent_team_scheduling_validate_member_v600($pdo,$pool,$userId,$eventTypeId);
    $keywords=mb_strimwidth(trim((string)($input['routing_keywords']??'')),0,4000,'');$priority=max(-1000,min(1000,(int)($input['priority']??0)));$enabled=!empty($input['enabled'])?1:0;
    $stmt=$pdo->prepare('INSERT INTO agent_team_scheduling_members (pool_id,user_id,schedule_id,event_type_id,routing_keywords,priority,enabled) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE schedule_id=VALUES(schedule_id),event_type_id=VALUES(event_type_id),routing_keywords=VALUES(routing_keywords),priority=VALUES(priority),enabled=VALUES(enabled)');
    $stmt->execute([$poolId,$userId,(int)$event['schedule_id'],$eventTypeId,$keywords,$priority,$enabled]);
    $find=$pdo->prepare('SELECT * FROM agent_team_scheduling_members WHERE pool_id=? AND user_id=? LIMIT 1');$find->execute([$poolId,$userId]);
    return $find->fetch()?:throw new RuntimeException('Team scheduling member could not be saved.');
}

function agent_team_scheduling_remove_member_v600(PDO $pdo,array $user,int $poolId,int $memberId): bool
{
    $pool=agent_team_scheduling_pool_v600($pdo,(int)($user['id']??0),$poolId);if(!$pool)return false;
    $stmt=$pdo->prepare('DELETE FROM agent_team_scheduling_members WHERE id=? AND pool_id=?');$stmt->execute([$memberId,$poolId]);return $stmt->rowCount()>0;
}

function agent_team_scheduling_members_v600(PDO $pdo,array $pool,bool $enabledOnly=true): array
{
    $sql="SELECT m.*,u.display_name,u.email,e.title AS event_title,e.duration_minutes,e.is_active AS event_active,s.name AS schedule_name,s.timezone AS schedule_timezone,s.is_active AS schedule_active
          FROM agent_team_scheduling_members m
          JOIN users u ON u.id=m.user_id
          JOIN agent_scheduling_schedules s ON s.id=m.schedule_id AND s.owner_user_id=m.user_id
          JOIN agent_scheduling_event_types e ON e.id=m.event_type_id AND e.schedule_id=m.schedule_id
          WHERE m.pool_id=?";
    if($enabledOnly)$sql.=' AND m.enabled=1 AND s.is_active=1 AND e.is_active=1';
    $sql.=' ORDER BY m.priority DESC,m.assignment_count ASC,COALESCE(m.last_assigned_at,\'1970-01-01\') ASC,m.id ASC';
    $stmt=$pdo->prepare($sql);$stmt->execute([(int)$pool['id']]);$rows=$stmt->fetchAll()?:[];
    return array_values(array_filter($rows,fn(array $row):bool=>agent_team_scheduling_user_in_workspace_v600($pdo,(int)$pool['workspace_owner_user_id'],(int)$row['user_id'])));
}

function agent_team_scheduling_member_keywords_match_v600(array $member,string $answer): bool
{
    $raw=trim((string)($member['routing_keywords']??''));if($raw==='')return false;
    $answer=mb_strtolower($answer);foreach(preg_split('/[,;\n]+/u',$raw)?:[] as $keyword){$keyword=mb_strtolower(trim($keyword));if($keyword!==''&&str_contains($answer,$keyword))return true;}return false;
}

function agent_team_scheduling_routed_members_v600(array $members,string $answer): array
{
    if(trim($answer)==='')return $members;
    $matched=array_values(array_filter($members,fn(array $member):bool=>agent_team_scheduling_member_keywords_match_v600($member,$answer)));
    return $matched?:$members;
}

function agent_team_scheduling_slots_v600(PDO $pdo,array $pool,string $date,string $routingAnswer=''): array
{
    if(empty($pool['is_active']))return [];
    $members=agent_team_scheduling_members_v600($pdo,$pool,true);if(!$members)return [];
    if((string)$pool['mode']==='round_robin'){
        $members=agent_team_scheduling_routed_members_v600($members,$routingAnswer);$slots=[];
        foreach($members as $member){
            foreach(agent_scheduling_slots_for_date_v430($pdo,(int)$member['event_type_id'],$date,false) as $slot){
                $key=(string)$slot['start_at_utc'];
                if(!isset($slots[$key]))$slots[$key]=['start_at_utc'=>$key,'end_at_utc'=>(string)$slot['end_at_utc'],'timezone'=>(string)$pool['timezone'],'eligible_member_ids'=>[]];
                $slots[$key]['eligible_member_ids'][]=(int)$member['id'];
            }
        }
        ksort($slots);return array_values($slots);
    }
    $durations=array_values(array_unique(array_map(static fn(array $m):int=>(int)$m['duration_minutes'],$members)));
    if(count($durations)!==1)return [];
    $intersection=null;$ends=[];
    foreach($members as $member){
        $set=[];foreach(agent_scheduling_slots_for_date_v430($pdo,(int)$member['event_type_id'],$date,false) as $slot){$set[(string)$slot['start_at_utc']]=true;$ends[(string)$slot['start_at_utc']]=(string)$slot['end_at_utc'];}
        $intersection=$intersection===null?$set:array_intersect_key($intersection,$set);
        if(!$intersection)return [];
    }
    ksort($intersection);$memberIds=array_map(static fn(array $m):int=>(int)$m['id'],$members);$out=[];
    foreach(array_keys($intersection) as $start)$out[]=['start_at_utc'=>$start,'end_at_utc'=>$ends[$start]??'','timezone'=>(string)$pool['timezone'],'eligible_member_ids'=>$memberIds];
    return $out;
}

function agent_team_scheduling_find_slot_v600(PDO $pdo,array $pool,string $startAtUtc,string $routingAnswer=''): ?array
{
    try{$utc=new DateTimeImmutable($startAtUtc,new DateTimeZone('UTC'));$start=$utc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$local=$utc->setTimezone(new DateTimeZone(agent_scheduling_timezone_v430((string)$pool['timezone'])));$date=$local->format('Y-m-d');}catch(Throwable $e){return null;}
    foreach(agent_team_scheduling_slots_v600($pdo,$pool,$date,$routingAnswer) as $slot)if((string)$slot['start_at_utc']===$start)return $slot;
    return null;
}

function agent_team_scheduling_choose_host_v600(array $members,array $eligibleMemberIds): ?array
{
    $eligible=array_fill_keys(array_map('intval',$eligibleMemberIds),true);$rows=array_values(array_filter($members,static fn(array $m):bool=>isset($eligible[(int)$m['id']])));
    usort($rows,static function(array $a,array $b):int{
        $count=(int)$a['assignment_count']<=>(int)$b['assignment_count'];if($count!==0)return $count;
        $lastA=(string)($a['last_assigned_at']??'');$lastB=(string)($b['last_assigned_at']??'');if($lastA!==$lastB)return $lastA<=>$lastB;
        $priority=(int)$b['priority']<=>(int)$a['priority'];return $priority!==0?$priority:(int)$a['id']<=>(int)$b['id'];
    });
    return $rows[0]??null;
}

function agent_team_scheduling_lock_v600(PDO $pdo,string $name): void
{
    $stmt=$pdo->prepare('SELECT GET_LOCK(?,5)');$stmt->execute([$name]);if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('That Team schedule is busy. Please try again.');
}
function agent_team_scheduling_unlock_v600(PDO $pdo,string $name): void
{
    try{$stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$name]);}catch(Throwable $ignored){}
}

function agent_team_scheduling_create_booking_v600(PDO $pdo,array $pool,array $input): array
{
    if(empty($pool['is_active']))throw new RuntimeException('This Team schedule is not active.');
    $guestName=trim(preg_replace('/\s+/u',' ',(string)($input['guest_name']??''))??'');if($guestName==='')throw new RuntimeException('Enter the attendee name.');
    $guestEmail=strtolower(trim((string)($input['guest_email']??'')));if($guestEmail!==''&&!filter_var($guestEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid attendee email.');
    $guestTimezone=agent_scheduling_timezone_v430((string)($input['guest_timezone']??$pool['timezone']),(string)$pool['timezone']);
    $routing=mb_strimwidth(trim((string)($input['routing_answer']??'')),0,500,'');$source=mb_strimwidth(trim((string)($input['source']??'team_public'))?:'team_public',0,40,'');
    $agentId=max(0,(int)($input['created_by_agent_id']??0));if($agentId>0){$agent=user_agent_get_v236($pdo,(int)$pool['workspace_owner_user_id'],$agentId);if(!$agent||empty($agent['is_active']))throw new RuntimeException('Booking Agent is no longer active.');}else $agentId=null;
    try{$start=(new DateTimeImmutable((string)($input['start_at_utc']??''),new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){throw new RuntimeException('Choose a valid Team appointment time.');}
    $poolLock='vp3_team_schedule_'.(int)$pool['id'];agent_team_scheduling_lock_v600($pdo,$poolLock);$heldScheduleLocks=[];
    try{
        $members=agent_team_scheduling_members_v600($pdo,$pool,true);if(!$members)throw new RuntimeException('This Team schedule has no active participants.');
        $slot=agent_team_scheduling_find_slot_v600($pdo,$pool,$start,$routing);if(!$slot)throw new RuntimeException('That Team time is no longer available.');
        $selected=[];
        if((string)$pool['mode']==='round_robin'){$host=agent_team_scheduling_choose_host_v600(agent_team_scheduling_routed_members_v600($members,$routing),(array)$slot['eligible_member_ids']);if(!$host)throw new RuntimeException('No Team host is available for that time.');$selected=[$host];}
        else{$eligible=array_fill_keys(array_map('intval',(array)$slot['eligible_member_ids']),true);$selected=array_values(array_filter($members,static fn(array $m):bool=>isset($eligible[(int)$m['id']])));if(count($selected)!==count($members))throw new RuntimeException('The full Team is no longer available for that time.');}

        if((string)$pool['mode']==='collective'){
            $scheduleIds=array_values(array_unique(array_map(static fn(array $m):int=>(int)$m['schedule_id'],$selected)));sort($scheduleIds,SORT_NUMERIC);
            foreach($scheduleIds as $scheduleId){$name='vp3_schedule_'.$scheduleId;agent_team_scheduling_lock_v600($pdo,$name);$heldScheduleLocks[]=$name;}
        }
        $started=!$pdo->inTransaction();if($started)$pdo->beginTransaction();
        try{
            $canonical=[];$end='';
            foreach($selected as $index=>$member){
                $booking=agent_scheduling_create_booking_v430($pdo,[
                    'event_type_id'=>(int)$member['event_type_id'],'start_at_utc'=>$start,'guest_timezone'=>$guestTimezone,
                    'guest_name'=>$guestName,'guest_email'=>$guestEmail,'guest_phone'=>(string)($input['guest_phone']??''),'guest_notes'=>(string)($input['guest_notes']??''),
                    'created_by_user_id'=>(int)$pool['workspace_owner_user_id'],'created_by_agent_id'=>$agentId,'source'=>$source,
                ]);
                $canonical[]=['member'=>$member,'booking'=>$booking,'role'=>$index===0?'host':'participant'];$end=(string)$booking['end_at_utc'];
            }
            $publicToken=bin2hex(random_bytes(32));$cancelToken=bin2hex(random_bytes(32));$assigned=(string)$pool['mode']==='round_robin'?(int)$selected[0]['user_id']:null;
            $stmt=$pdo->prepare('INSERT INTO agent_team_scheduling_bookings (workspace_owner_user_id,pool_id,assigned_user_id,created_by_agent_id,start_at_utc,end_at_utc,guest_timezone,guest_name,guest_email,guest_phone,routing_answer,status,source,public_token,cancel_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([(int)$pool['workspace_owner_user_id'],(int)$pool['id'],$assigned,$agentId,$start,$end,$guestTimezone,mb_strimwidth($guestName,0,190,''),mb_strimwidth($guestEmail,0,190,''),mb_strimwidth(trim((string)($input['guest_phone']??'')),0,80,''),$routing,'confirmed',$source,$publicToken,$cancelToken]);
            $teamBookingId=(int)$pdo->lastInsertId();$map=$pdo->prepare('INSERT INTO agent_team_scheduling_booking_members (team_booking_id,pool_member_id,user_id,canonical_booking_id,role) VALUES (?,?,?,?,?)');
            foreach($canonical as $item)$map->execute([$teamBookingId,(int)$item['member']['id'],(int)$item['member']['user_id'],(int)$item['booking']['id'],(string)$item['role']]);
            if((string)$pool['mode']==='round_robin')$pdo->prepare('UPDATE agent_team_scheduling_members SET assignment_count=assignment_count+1,last_assigned_at=NOW() WHERE id=? AND pool_id=?')->execute([(int)$selected[0]['id'],(int)$pool['id']]);
            if($started)$pdo->commit();
            $find=$pdo->prepare('SELECT * FROM agent_team_scheduling_bookings WHERE id=? LIMIT 1');$find->execute([$teamBookingId]);$team=$find->fetch();if(!$team)throw new RuntimeException('Team booking could not be loaded.');
            $team['canonical_bookings']=array_map(static fn(array $item):array=>$item['booking'],$canonical);$team['host_name']=(string)($selected[0]['display_name']??'');return $team;
        }catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }finally{
        foreach(array_reverse($heldScheduleLocks) as $name)agent_team_scheduling_unlock_v600($pdo,$name);
        agent_team_scheduling_unlock_v600($pdo,$poolLock);
    }
}

function agent_team_scheduling_booking_v600(PDO $pdo,int $ownerId,int $bookingId): ?array
{
    $stmt=$pdo->prepare('SELECT b.*,p.name AS pool_name,p.mode,p.timezone AS pool_timezone FROM agent_team_scheduling_bookings b JOIN agent_team_scheduling_pools p ON p.id=b.pool_id WHERE b.id=? AND b.workspace_owner_user_id=? LIMIT 1');$stmt->execute([$bookingId,$ownerId]);return $stmt->fetch()?:null;
}
function agent_team_scheduling_booking_by_cancel_token_v600(PDO $pdo,string $token): ?array
{
    $token=strtolower(trim($token));if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;$stmt=$pdo->prepare('SELECT b.*,p.name AS pool_name,p.mode,p.timezone AS pool_timezone,p.public_key FROM agent_team_scheduling_bookings b JOIN agent_team_scheduling_pools p ON p.id=b.pool_id WHERE b.cancel_token=? LIMIT 1');$stmt->execute([$token]);return $stmt->fetch()?:null;
}

function agent_team_scheduling_upcoming_v600(PDO $pdo,int $ownerId,int $poolId=0,int $limit=50): array
{
    $sql="SELECT b.*,p.name AS pool_name,p.mode,u.display_name AS assigned_name FROM agent_team_scheduling_bookings b JOIN agent_team_scheduling_pools p ON p.id=b.pool_id LEFT JOIN users u ON u.id=b.assigned_user_id WHERE b.workspace_owner_user_id=? AND b.status='confirmed' AND b.end_at_utc>=UTC_TIMESTAMP()";$args=[$ownerId];if($poolId>0){$sql.=' AND b.pool_id=?';$args[]=$poolId;}$sql.=' ORDER BY b.start_at_utc,b.id LIMIT '.max(1,min(100,$limit));$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll()?:[];
}

function agent_team_scheduling_cancel_booking_v600(PDO $pdo,array $booking): bool
{
    $bookingId=(int)($booking['id']??0);$ownerId=(int)($booking['workspace_owner_user_id']??0);$poolId=(int)($booking['pool_id']??0);if($bookingId<1||$ownerId<1||$poolId<1||!in_array((string)($booking['status']??''),['pending','confirmed'],true))return false;
    $poolLock='vp3_team_schedule_'.$poolId;agent_team_scheduling_lock_v600($pdo,$poolLock);
    try{
        $stmt=$pdo->prepare('SELECT bm.*,b.status AS canonical_status FROM agent_team_scheduling_booking_members bm JOIN agent_scheduling_bookings b ON b.id=bm.canonical_booking_id WHERE bm.team_booking_id=? ORDER BY bm.id');$stmt->execute([$bookingId]);$members=$stmt->fetchAll()?:[];if(!$members)return false;
        $started=!$pdo->inTransaction();if($started)$pdo->beginTransaction();
        try{
            foreach($members as $member){if(in_array((string)$member['canonical_status'],['pending','confirmed'],true)&&!agent_scheduling_cancel_booking_v430($pdo,(int)$member['canonical_booking_id'],(int)$member['user_id']))throw new RuntimeException('A Team participant booking changed before cancellation could finish.');}
            $update=$pdo->prepare("UPDATE agent_team_scheduling_bookings SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=? AND workspace_owner_user_id=? AND status IN ('pending','confirmed')");$update->execute([$bookingId,$ownerId]);if($update->rowCount()!==1)throw new RuntimeException('The Team booking changed before cancellation could finish.');
            if($started)$pdo->commit();return true;
        }catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }finally{agent_team_scheduling_unlock_v600($pdo,$poolLock);}
}

function agent_team_scheduling_member_event_options_v600(PDO $pdo,int $workspaceOwnerId): array
{
    $out=[];foreach(agent_team_scheduling_workspace_users_v600($pdo,$workspaceOwnerId) as $member){$uid=(int)$member['id'];$stmt=$pdo->prepare("SELECT e.id,e.title,e.duration_minutes,s.id AS schedule_id,s.name AS schedule_name,s.timezone FROM agent_scheduling_event_types e JOIN agent_scheduling_schedules s ON s.id=e.schedule_id WHERE s.owner_user_id=? AND s.is_active=1 AND e.is_active=1 ORDER BY s.is_default DESC,e.sort_order,e.title,e.id");$stmt->execute([$uid]);$out[$uid]=['user'=>$member,'events'=>$stmt->fetchAll()?:[]];}return $out;
}
