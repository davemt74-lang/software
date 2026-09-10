<?php
declare(strict_types=1);

/**
 * Canonical Team lifecycle for VP3 workspaces.
 *
 * workspace_memberships_v350 is durable history. artist_team_members remains an
 * active-only compatibility projection so existing Team, General and DM code
 * fails closed automatically when a membership is suspended or removed.
 */
const VP3_TEAM_WORKSPACE_LIFECYCLE_V350='team-workspace-lifecycle-v350-20260910';

function workspace_team_v350_statuses(): array
{
    return ['active'=>'Active','suspended'=>'Suspended','removed'=>'Removed'];
}

function workspace_team_v350_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        &&table_exists('workspace_memberships_v350')
        &&table_exists('workspace_team_invitations_v350');
}

/** Never run DDL from inside a membership/invitation transaction. */
function workspace_team_v350_require_schema(PDO $pdo): void
{
    if(!workspace_team_v350_schema_ready($pdo)){
        throw new RuntimeException('Run the VP3 database upgrade to enable Team workspace lifecycle.');
    }
}

function workspace_team_v350_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    // MySQL DDL can implicitly commit. Transactional callers may only proceed
    // after the schema has already been installed by setup/upgrade/page boot.
    if($pdo->inTransaction()){
        workspace_team_v350_require_schema($pdo);
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_memberships_v350 (
      workspace_owner_user_id INT UNSIGNED NOT NULL,
      member_user_id INT UNSIGNED NOT NULL,
      team_role VARCHAR(30) NOT NULL,
      membership_status VARCHAR(20) NOT NULL DEFAULT 'active',
      invited_by_user_id INT UNSIGNED NULL,
      activated_at DATETIME NULL,
      suspended_at DATETIME NULL,
      removed_at DATETIME NULL,
      role_changed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (workspace_owner_user_id,member_user_id),
      INDEX idx_workspace_membership_member (member_user_id,membership_status,workspace_owner_user_id),
      INDEX idx_workspace_membership_owner_status (workspace_owner_user_id,membership_status,team_role,member_user_id),
      CONSTRAINT fk_workspace_membership_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_workspace_membership_member FOREIGN KEY (member_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_workspace_membership_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_team_invitations_v350 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      workspace_owner_user_id INT UNSIGNED NOT NULL,
      invited_email VARCHAR(190) NOT NULL,
      existing_user_id INT UNSIGNED NULL,
      team_role VARCHAR(30) NOT NULL,
      token_hash CHAR(64) NOT NULL,
      invitation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
      invited_by_user_id INT UNSIGNED NOT NULL,
      expires_at DATETIME NOT NULL,
      accepted_at DATETIME NULL,
      resolved_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_workspace_invite_token (token_hash),
      INDEX idx_workspace_invite_owner (workspace_owner_user_id,invitation_status,created_at),
      INDEX idx_workspace_invite_email (invited_email,invitation_status,expires_at),
      INDEX idx_workspace_invite_user (existing_user_id,invitation_status,expires_at),
      CONSTRAINT fk_workspace_invite_owner FOREIGN KEY (workspace_owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_workspace_invite_existing_user FOREIGN KEY (existing_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_workspace_invite_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if(table_exists('artist_team_members')){
        // Import historical active rows without ever overwriting a durable
        // suspended/removed lifecycle record on later upgrade passes.
        $pdo->exec("INSERT IGNORE INTO workspace_memberships_v350
          (workspace_owner_user_id,member_user_id,team_role,membership_status,activated_at,created_at,updated_at)
          SELECT artist_user_id,member_user_id,team_role,'active',created_at,created_at,updated_at
          FROM artist_team_members");

        // Keep the legacy table as a strict active authorization projection.
        $pdo->exec("DELETE atm FROM artist_team_members atm
          INNER JOIN workspace_memberships_v350 wm
            ON wm.workspace_owner_user_id=atm.artist_user_id AND wm.member_user_id=atm.member_user_id
          WHERE wm.membership_status<>'active'");
        $pdo->exec("UPDATE artist_team_members atm
          INNER JOIN workspace_memberships_v350 wm
            ON wm.workspace_owner_user_id=atm.artist_user_id AND wm.member_user_id=atm.member_user_id
          SET atm.team_role=wm.team_role,atm.updated_at=wm.updated_at
          WHERE wm.membership_status='active'");
        $pdo->exec("INSERT IGNORE INTO artist_team_members (artist_user_id,member_user_id,team_role,created_at,updated_at)
          SELECT workspace_owner_user_id,member_user_id,team_role,COALESCE(activated_at,created_at),updated_at
          FROM workspace_memberships_v350 WHERE membership_status='active'");
    }
}

function workspace_team_v350_user(PDO $pdo,int $userId,bool $forUpdate=false): ?array
{
    if($userId<1)return null;
    $sql='SELECT id,email,display_name,role,avatar_path,is_active,last_login_at,created_at FROM users WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$userId]);$row=$stmt->fetch();
    if(!$row)return null;
    $row['roles']=function_exists('user_account_types_for_user_id')?user_account_types_for_user_id($userId,(string)$row['role']):[(string)$row['role']];
    return $row;
}

function workspace_team_v350_membership(PDO $pdo,int $ownerId,int $memberId,bool $forUpdate=false): ?array
{
    if($ownerId<1||$memberId<1)return null;
    workspace_team_v350_require_schema($pdo);
    $stmt=$pdo->prepare('SELECT * FROM workspace_memberships_v350 WHERE workspace_owner_user_id=? AND member_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$ownerId,$memberId]);$row=$stmt->fetch();return $row?:null;
}

function workspace_team_v350_members(PDO $pdo,int $ownerId,bool $includeRemoved=false): array
{
    if($ownerId<1)return [];
    workspace_team_v350_require_schema($pdo);
    $sql="SELECT u.id,u.email,u.display_name,u.role,u.avatar_path,u.is_active,u.last_login_at,u.created_at,
      wm.team_role,wm.membership_status,wm.activated_at,wm.suspended_at,wm.removed_at,wm.role_changed_at,wm.created_at team_created_at,wm.updated_at team_updated_at
      FROM workspace_memberships_v350 wm INNER JOIN users u ON u.id=wm.member_user_id
      WHERE wm.workspace_owner_user_id=?".($includeRemoved?'':" AND wm.membership_status<>'removed'")."
      ORDER BY FIELD(wm.membership_status,'active','suspended','removed'),u.display_name,u.id";
    $stmt=$pdo->prepare($sql);$stmt->execute([$ownerId]);return $stmt->fetchAll()?:[];
}

function workspace_team_v350_memberships_for_user(PDO $pdo,int $memberId,string $status='active'): array
{
    if($memberId<1)return [];
    workspace_team_v350_require_schema($pdo);
    $allowed=workspace_team_v350_statuses();if(!isset($allowed[$status]))$status='active';
    $stmt=$pdo->prepare("SELECT wm.workspace_owner_user_id artist_user_id,wm.team_role,wm.membership_status,wm.created_at,wm.updated_at,
      u.display_name artist_name,u.email artist_email
      FROM workspace_memberships_v350 wm INNER JOIN users u ON u.id=wm.workspace_owner_user_id AND u.is_active=1
      WHERE wm.member_user_id=? AND wm.membership_status=? ORDER BY u.display_name,wm.workspace_owner_user_id");
    $stmt->execute([$memberId,$status]);return $stmt->fetchAll()?:[];
}

function workspace_team_v350_sync_projection(PDO $pdo,int $ownerId,int $memberId): void
{
    workspace_team_v350_require_schema($pdo);
    $row=workspace_team_v350_membership($pdo,$ownerId,$memberId);
    if($row&&$row['membership_status']==='active'){
        $stmt=$pdo->prepare('INSERT INTO artist_team_members (artist_user_id,member_user_id,team_role) VALUES (?,?,?) ON DUPLICATE KEY UPDATE team_role=VALUES(team_role),updated_at=NOW()');
        $stmt->execute([$ownerId,$memberId,(string)$row['team_role']]);
    }else{
        $stmt=$pdo->prepare('DELETE FROM artist_team_members WHERE artist_user_id=? AND member_user_id=?');
        $stmt->execute([$ownerId,$memberId]);
    }
}

function workspace_team_v350_assert_can_activate(PDO $pdo,int $ownerId,int $memberId,?array $current=null): array
{
    $owner=workspace_team_v350_user($pdo,$ownerId);$member=workspace_team_v350_user($pdo,$memberId);
    if(!$owner||(int)$owner['is_active']!==1)throw new RuntimeException('Workspace owner is unavailable.');
    if(!$member||(int)$member['is_active']!==1)throw new RuntimeException('That VP3 account is not active.');
    if($ownerId===$memberId)throw new RuntimeException('A workspace owner cannot consume their own Team seat.');

    $current??=workspace_team_v350_membership($pdo,$ownerId,$memberId);
    $alreadyActive=$current&&$current['membership_status']==='active';
    if(!function_exists('team_subscription_state'))return ['authorized'=>true,'included'=>true,'can_add'=>true,'unlimited'=>true];
    $state=team_subscription_state($owner,$pdo);
    if(empty($state['authorized']))throw new RuntimeException('This workspace is not currently authorized to manage Team members.');
    if(empty($state['included']))throw new RuntimeException('The workspace owner’s current product access does not include Team seats.');
    if(!$alreadyActive&&empty($state['can_add']))throw new RuntimeException('No Team seat is currently available for this workspace.');
    return $state;
}

function workspace_team_v350_activate_member(PDO $pdo,int $ownerId,int $memberId,string $teamRole,?int $actorId=null): void
{
    if(!artist_workspace_v104_valid_team_role($teamRole))throw new RuntimeException('Select Manager or Producer.');
    workspace_team_v350_ensure_schema($pdo);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $owner=workspace_team_v350_user($pdo,$ownerId,true);
        if(!$owner||(int)$owner['is_active']!==1)throw new RuntimeException('Workspace owner is unavailable.');
        $member=workspace_team_v350_user($pdo,$memberId,true);
        if(!$member||(int)$member['is_active']!==1)throw new RuntimeException('That VP3 account is not active.');
        $before=workspace_team_v350_membership($pdo,$ownerId,$memberId,true);
        workspace_team_v350_assert_can_activate($pdo,$ownerId,$memberId,$before);
        $beforeRole=(string)($before['team_role']??'');
        if($beforeRole==='producer'&&$teamRole!=='producer')artist_workspace_v104_revoke_producer_assignments($pdo,$ownerId,$memberId);
        $stmt=$pdo->prepare("INSERT INTO workspace_memberships_v350
          (workspace_owner_user_id,member_user_id,team_role,membership_status,invited_by_user_id,activated_at,suspended_at,removed_at,role_changed_at)
          VALUES (?,?,?,'active',?,NOW(),NULL,NULL,NOW())
          ON DUPLICATE KEY UPDATE role_changed_at=IF(team_role<>VALUES(team_role),NOW(),role_changed_at),team_role=VALUES(team_role),membership_status='active',invited_by_user_id=COALESCE(VALUES(invited_by_user_id),invited_by_user_id),activated_at=NOW(),suspended_at=NULL,removed_at=NULL,updated_at=NOW()");
        $stmt->execute([$ownerId,$memberId,$teamRole,$actorId]);
        workspace_team_v350_sync_projection($pdo,$ownerId,$memberId);
        artist_workspace_v104_sync_context_role_permissions($pdo);
        artist_workspace_v104_sync_member_context_roles($pdo,$memberId);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function workspace_team_v350_change_role(PDO $pdo,int $ownerId,int $memberId,string $teamRole): void
{
    if(!artist_workspace_v104_valid_team_role($teamRole))throw new RuntimeException('Select Manager or Producer.');
    workspace_team_v350_ensure_schema($pdo);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $owner=workspace_team_v350_user($pdo,$ownerId,true);
        if(!$owner||(int)$owner['is_active']!==1)throw new RuntimeException('Workspace owner is unavailable.');
        $row=workspace_team_v350_membership($pdo,$ownerId,$memberId,true);
        if(!$row||$row['membership_status']==='removed')throw new RuntimeException('That Team membership is not available.');
        $oldRole=(string)$row['team_role'];
        if($oldRole==='producer'&&$teamRole!=='producer')artist_workspace_v104_revoke_producer_assignments($pdo,$ownerId,$memberId);
        $stmt=$pdo->prepare('UPDATE workspace_memberships_v350 SET role_changed_at=IF(team_role<>?,NOW(),role_changed_at),team_role=?,updated_at=NOW() WHERE workspace_owner_user_id=? AND member_user_id=?');
        $stmt->execute([$teamRole,$teamRole,$ownerId,$memberId]);
        workspace_team_v350_sync_projection($pdo,$ownerId,$memberId);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function workspace_team_v350_set_status(PDO $pdo,int $ownerId,int $memberId,string $status): void
{
    if(!isset(workspace_team_v350_statuses()[$status]))throw new RuntimeException('Choose a valid Team membership state.');
    workspace_team_v350_ensure_schema($pdo);

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $owner=workspace_team_v350_user($pdo,$ownerId,true);
        if(!$owner||(int)$owner['is_active']!==1)throw new RuntimeException('Workspace owner is unavailable.');
        $row=workspace_team_v350_membership($pdo,$ownerId,$memberId,true);
        if(!$row||$row['membership_status']==='removed')throw new RuntimeException('That Team membership is not available.');

        if($status==='active'){
            // Resume uses the role from the locked membership row so a concurrent
            // role change can never be overwritten by stale pre-transaction data.
            workspace_team_v350_activate_member($pdo,$ownerId,$memberId,(string)$row['team_role']);
            if($owns)$pdo->commit();
            return;
        }

        if((string)$row['team_role']==='producer')artist_workspace_v104_revoke_producer_assignments($pdo,$ownerId,$memberId);
        if($status==='suspended'){
            $stmt=$pdo->prepare("UPDATE workspace_memberships_v350 SET membership_status='suspended',suspended_at=NOW(),removed_at=NULL,updated_at=NOW() WHERE workspace_owner_user_id=? AND member_user_id=?");
        }else{
            $stmt=$pdo->prepare("UPDATE workspace_memberships_v350 SET membership_status='removed',removed_at=NOW(),updated_at=NOW() WHERE workspace_owner_user_id=? AND member_user_id=?");
        }
        $stmt->execute([$ownerId,$memberId]);
        workspace_team_v350_sync_projection($pdo,$ownerId,$memberId);
        artist_workspace_v104_sync_member_context_roles($pdo,$memberId);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function workspace_team_v350_pending_invitations(PDO $pdo,int $ownerId): array
{
    workspace_team_v350_require_schema($pdo);
    $pdo->prepare("UPDATE workspace_team_invitations_v350 SET invitation_status='expired',resolved_at=COALESCE(resolved_at,NOW()),updated_at=NOW() WHERE workspace_owner_user_id=? AND invitation_status='pending' AND expires_at<=NOW()")->execute([$ownerId]);
    $stmt=$pdo->prepare("SELECT i.*,u.display_name existing_user_name FROM workspace_team_invitations_v350 i LEFT JOIN users u ON u.id=i.existing_user_id WHERE i.workspace_owner_user_id=? AND i.invitation_status='pending' ORDER BY i.created_at DESC");
    $stmt->execute([$ownerId]);return $stmt->fetchAll()?:[];
}

function workspace_team_v350_create_invitation(PDO $pdo,int $ownerId,string $email,string $teamRole,int $actorId): array
{
    workspace_team_v350_ensure_schema($pdo);
    $email=strtolower(trim($email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email)>190)throw new RuntimeException('Enter a valid email address.');
    if(!artist_workspace_v104_valid_team_role($teamRole))throw new RuntimeException('Select Manager or Producer.');

    $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$existingId=null;$owner=null;$id=0;
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $owner=workspace_team_v350_user($pdo,$ownerId,true);
        if(!$owner||(int)$owner['is_active']!==1)throw new RuntimeException('Workspace owner is unavailable.');
        $state=team_subscription_state($owner,$pdo);
        if(empty($state['authorized'])||empty($state['included']))throw new RuntimeException('This workspace cannot invite Team members with its current product access.');

        $find=$pdo->prepare('SELECT id,email,display_name,is_active FROM users WHERE email=? LIMIT 1 FOR UPDATE');$find->execute([$email]);$existing=$find->fetch()?:null;
        if($existing&&(int)$existing['id']===$ownerId)throw new RuntimeException('You cannot invite your own account.');
        if($existing&&(int)$existing['is_active']!==1)throw new RuntimeException('That VP3 account is currently disabled.');
        if($existing){
            $existingId=(int)$existing['id'];
            $membership=workspace_team_v350_membership($pdo,$ownerId,$existingId,true);
            if($membership&&$membership['membership_status']==='active')throw new RuntimeException('That person is already an active Team member.');
            if($membership&&$membership['membership_status']==='suspended')throw new RuntimeException('That Team member is suspended. Resume the existing membership instead of creating a new invitation.');
        }

        $pdo->prepare("UPDATE workspace_team_invitations_v350 SET invitation_status='revoked',resolved_at=NOW(),updated_at=NOW() WHERE workspace_owner_user_id=? AND invited_email=? AND invitation_status='pending'")->execute([$ownerId,$email]);
        $stmt=$pdo->prepare("INSERT INTO workspace_team_invitations_v350
          (workspace_owner_user_id,invited_email,existing_user_id,team_role,token_hash,invitation_status,invited_by_user_id,expires_at)
          VALUES (?,?,?,?,?,'pending',?,DATE_ADD(NOW(),INTERVAL 7 DAY))");
        $stmt->execute([$ownerId,$email,$existingId,$teamRole,$hash,$actorId]);$id=(int)$pdo->lastInsertId();
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    $link=url('/team-invite.php?token='.rawurlencode($raw));
    if($existingId&&function_exists('create_notification')){
        $ownerName=trim((string)($owner['display_name']??''))?:'A VP3 workspace';
        create_notification($existingId,'team_invitation',$ownerName.' invited you to a workspace','Review the '.$teamRole.' Team invitation.',url('/team-invite.php?id='.$id),'workspace_team_invitation',$id);
    }
    return ['id'=>$id,'token'=>$raw,'link'=>$link,'email'=>$email,'team_role'=>$teamRole,'existing_user_id'=>$existingId,'expires_days'=>7];
}

function workspace_team_v350_invitation(PDO $pdo,int $id,bool $forUpdate=false): ?array
{
    if($id<1)return null;workspace_team_v350_require_schema($pdo);
    $stmt=$pdo->prepare("SELECT i.*,o.display_name workspace_owner_name,o.email workspace_owner_email FROM workspace_team_invitations_v350 i INNER JOIN users o ON o.id=i.workspace_owner_user_id WHERE i.id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$id]);$row=$stmt->fetch();return $row?:null;
}

function workspace_team_v350_invitation_by_token(PDO $pdo,string $token): ?array
{
    if(!preg_match('/^[a-f0-9]{64}$/i',$token))return null;workspace_team_v350_require_schema($pdo);
    $stmt=$pdo->prepare("SELECT i.*,o.display_name workspace_owner_name,o.email workspace_owner_email FROM workspace_team_invitations_v350 i INNER JOIN users o ON o.id=i.workspace_owner_user_id WHERE i.token_hash=? LIMIT 1");
    $stmt->execute([hash('sha256',strtolower($token))]);$row=$stmt->fetch();return $row?:null;
}

function workspace_team_v350_invitation_for_user(PDO $pdo,int $id,int $userId): ?array
{
    $invite=workspace_team_v350_invitation($pdo,$id);$user=workspace_team_v350_user($pdo,$userId);
    if(!$invite||!$user)return null;
    if((int)($invite['existing_user_id']??0)>0&&(int)$invite['existing_user_id']!==$userId)return null;
    return hash_equals(strtolower((string)$invite['invited_email']),strtolower((string)$user['email']))?$invite:null;
}

function workspace_team_v350_accept_invitation(PDO $pdo,int $inviteId,int $userId): void
{
    workspace_team_v350_ensure_schema($pdo);
    $preview=workspace_team_v350_invitation($pdo,$inviteId);
    if(!$preview)throw new RuntimeException('This Team invitation is no longer available.');
    $ownerId=(int)$preview['workspace_owner_user_id'];

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $owner=workspace_team_v350_user($pdo,$ownerId,true);
        if(!$owner||(int)$owner['is_active']!==1)throw new RuntimeException('Workspace owner is unavailable.');
        $invite=workspace_team_v350_invitation($pdo,$inviteId,true);
        if(!$invite||$invite['invitation_status']!=='pending')throw new RuntimeException('This Team invitation is no longer available.');
        if((int)$invite['workspace_owner_user_id']!==$ownerId)throw new RuntimeException('This Team invitation changed unexpectedly.');
        if(strtotime((string)$invite['expires_at'])<=time()){
            $pdo->prepare("UPDATE workspace_team_invitations_v350 SET invitation_status='expired',resolved_at=COALESCE(resolved_at,NOW()),updated_at=NOW() WHERE id=? AND invitation_status='pending'")->execute([$inviteId]);
            if($owns)$pdo->commit();
            throw new RuntimeException('This Team invitation has expired.');
        }
        $user=workspace_team_v350_user($pdo,$userId,true);
        if(!$user||(int)$user['is_active']!==1)throw new RuntimeException('Your VP3 account is unavailable.');
        if(!hash_equals(strtolower((string)$invite['invited_email']),strtolower((string)$user['email'])))throw new RuntimeException('This invitation was sent to a different email address.');
        if((int)($invite['existing_user_id']??0)>0&&(int)$invite['existing_user_id']!==$userId)throw new RuntimeException('This invitation belongs to another VP3 account.');

        workspace_team_v350_activate_member($pdo,$ownerId,$userId,(string)$invite['team_role'],(int)$invite['invited_by_user_id']);
        $stmt=$pdo->prepare("UPDATE workspace_team_invitations_v350 SET existing_user_id=?,invitation_status='accepted',accepted_at=NOW(),resolved_at=NOW(),updated_at=NOW() WHERE id=? AND invitation_status='pending'");
        $stmt->execute([$userId,$inviteId]);
        if($stmt->rowCount()!==1)throw new RuntimeException('This Team invitation is no longer available.');
        if($owns)$pdo->commit();
        if(function_exists('create_notification'))create_notification($ownerId,'team_invitation_accepted',(string)$user['display_name'].' joined your workspace','The Team invitation was accepted.',url('/team.php'),'workspace_team_invitation_accept',$inviteId);
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function workspace_team_v350_decline_invitation(PDO $pdo,int $inviteId,int $userId): void
{
    workspace_team_v350_ensure_schema($pdo);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $invite=workspace_team_v350_invitation($pdo,$inviteId,true);
        $user=workspace_team_v350_user($pdo,$userId,true);
        if(!$invite||$invite['invitation_status']!=='pending'||!$user)throw new RuntimeException('This Team invitation is no longer available.');
        if(strtotime((string)$invite['expires_at'])<=time())throw new RuntimeException('This Team invitation has expired.');
        if((int)($invite['existing_user_id']??0)>0&&(int)$invite['existing_user_id']!==$userId)throw new RuntimeException('This invitation belongs to another VP3 account.');
        if(!hash_equals(strtolower((string)$invite['invited_email']),strtolower((string)$user['email'])))throw new RuntimeException('This invitation was sent to a different email address.');
        $stmt=$pdo->prepare("UPDATE workspace_team_invitations_v350 SET invitation_status='declined',existing_user_id=COALESCE(existing_user_id,?),resolved_at=NOW(),updated_at=NOW() WHERE id=? AND invitation_status='pending'");
        $stmt->execute([$userId,$inviteId]);if($stmt->rowCount()!==1)throw new RuntimeException('This Team invitation is no longer available.');
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function workspace_team_v350_revoke_invitation(PDO $pdo,int $ownerId,int $inviteId): void
{
    workspace_team_v350_ensure_schema($pdo);
    $stmt=$pdo->prepare("UPDATE workspace_team_invitations_v350 SET invitation_status='revoked',resolved_at=NOW(),updated_at=NOW() WHERE id=? AND workspace_owner_user_id=? AND invitation_status='pending'");
    $stmt->execute([$inviteId,$ownerId]);if($stmt->rowCount()<1)throw new RuntimeException('That pending invitation is not available.');
}
