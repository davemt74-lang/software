<?php
declare(strict_types=1);

/** Tracky 1F6: ordered, non-biometric Cloud site-status acceptance.
 * No portrait, participant IDs, contact IDs, device signing keys or receipts
 * are stored in this ledger. Site authentication remains in the existing API.
 */
function tracky_v1f6_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_visual_status_order (
      user_id INT UNSIGNED NOT NULL,
      site_id VARCHAR(100) NOT NULL,
      accepted_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      accepted_state VARCHAR(40) NOT NULL DEFAULT '',
      accepted_at DATETIME NULL,
      PRIMARY KEY (user_id,site_id),
      CONSTRAINT fk_tracky_visual_order_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Pure decision, independently testable without a database.
 * Legacy unversioned active claims fail closed. A legacy revocation may only
 * close an unversioned pre-upgrade site (revision 0); it cannot overwrite an
 * already-versioned newer approval. Equal revision with a DIFFERENT state
 * is a conflict, never an implicit permission change.
 */
function tracky_v1f6_decide(
    ?string $incoming, mixed $revision, string $stored, int $storedRevision
): array {
    $valid=['owner_attributed_unverified','revoked'];
    if($incoming!==null&&!in_array($incoming,$valid,true))
        throw new RuntimeException('Visual association state is unsupported.');
    if($stored!==''&&!in_array($stored,$valid,true))
        throw new RuntimeException('Stored visual association state is invalid.');
    if($incoming===null){
        if($revision!==null)
            throw new RuntimeException('Visual status revision requires a visual status.');
        return ['accepted'=>false,'revision'=>$storedRevision,'state'=>$stored,'reason'=>'not_reported'];
    }
    if($revision===null){
        if($incoming==='revoked'&&$storedRevision===0)
            return ['accepted'=>true,'revision'=>1,'state'=>'revoked','reason'=>'legacy_revocation_only'];
        return ['accepted'=>false,'revision'=>$storedRevision,'state'=>$stored,'reason'=>'unversioned_ignored'];
    }
    if(!is_int($revision)||$revision<1||$revision>2147483647)
        throw new RuntimeException('Visual status revision must be a positive bounded integer.');
    if($revision<$storedRevision)
        return ['accepted'=>false,'revision'=>$storedRevision,'state'=>$stored,'reason'=>'stale_revision'];
    if($revision===$storedRevision){
        if(!hash_equals($stored,$incoming))
            return ['accepted'=>false,'revision'=>$storedRevision,'state'=>$stored,'reason'=>'revision_conflict'];
        return ['accepted'=>true,'revision'=>$storedRevision,'state'=>$stored,'reason'=>'idempotent_refresh'];
    }
    return ['accepted'=>true,'revision'=>$revision,'state'=>$incoming,'reason'=>'newer_revision'];
}

/** Must run inside the existing Tracky sync database transaction, before the
 * general site upsert. SELECT FOR UPDATE serializes competing HTTP arrivals,
 * ensuring an older request cannot overwrite a later revocation.
 */
function tracky_v1f6_apply(
    PDO $pdo,int $userId,string $siteId,?string $incoming,mixed $revision
): array {
    if(!$pdo->inTransaction())
        throw new RuntimeException('Visual status ordering requires the sync transaction.');
    $insert=$pdo->prepare("INSERT IGNORE INTO tracky_cloud_visual_status_order
        (user_id,site_id) VALUES (?,?)");
    $insert->execute([$userId,$siteId]);
    $q=$pdo->prepare("SELECT accepted_revision,accepted_state,accepted_at
        FROM tracky_cloud_visual_status_order
        WHERE user_id=? AND site_id=? FOR UPDATE");
    $q->execute([$userId,$siteId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('Visual order ledger is unavailable.');
    $decision=tracky_v1f6_decide(
        $incoming,$revision,(string)$row['accepted_state'],(int)$row['accepted_revision']
    );
    $decision['accepted_at']=$row['accepted_at']??null;
    if($decision['accepted']===true){
        $save=$pdo->prepare("UPDATE tracky_cloud_visual_status_order
            SET accepted_revision=?,accepted_state=?,accepted_at=UTC_TIMESTAMP()
            WHERE user_id=? AND site_id=?");
        $save->execute([$decision['revision'],$decision['state'],$userId,$siteId]);
        // This timestamp changes only for an accepted new or idempotent visual
        // report. Unrelated site heartbeats and stale requests cannot renew it.
        $decision['accepted_at']='CURRENT_REQUEST';
    }
    return $decision;
}
