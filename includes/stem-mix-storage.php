<?php
declare(strict_types=1);

/** Merge one owner's saved mix while holding its current row lock. */
function stem_mix_update_scoped(PDO $pdo,int $userId,int $trackId,int $mixId,callable $mutate,?string $name=null): array
{
    if ($userId<1 || $trackId<1 || $mixId<1 || $pdo->inTransaction()) {
        throw new RuntimeException('Saved mix update is unavailable.');
    }
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id,mix_name,mix_json,created_at,updated_at FROM stem_mix_saves WHERE id=? AND user_id=? AND track_id=? LIMIT 1 FOR UPDATE');
        $q->execute([$mixId,$userId,$trackId]);
        $row=$q->fetch();
        if (!$row) throw new RuntimeException('Saved mix not found.');
        $mix=json_decode((string)$row['mix_json'],true);
        if (!is_array($mix)) throw new RuntimeException('Saved mix data is damaged.');
        $next=$mutate($mix);
        if (!is_array($next)) throw new RuntimeException('Saved mix state is invalid.');
        $json=json_encode($next,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($json)>16777216) throw new RuntimeException('Saved mix state is too large.');
        $q=$pdo->prepare('UPDATE stem_mix_saves SET mix_name=?,mix_json=?,updated_at=NOW() WHERE id=? AND user_id=? AND track_id=?');
        $q->execute([$name ?? (string)$row['mix_name'],$json,$mixId,$userId,$trackId]);
        $pdo->commit();
        return ['row'=>$row,'mix'=>$next];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
