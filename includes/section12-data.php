<?php
declare(strict_types=1);

function section12_lock_owner(PDO $pdo,int $ownerId): void
{
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $sql=$ownerId>0?'SELECT id,is_active FROM users WHERE id=?':'SELECT id,is_active FROM users ORDER BY id LIMIT 1';
    $s=$pdo->prepare($sql.$lock);$s->execute($ownerId>0?[$ownerId]:[]);$row=$s->fetch();
    if(!$row||($ownerId>0&&(int)$row['is_active']!==1))throw new RuntimeException('The account is unavailable.');
}

function section12_owner_transaction(PDO $pdo,int $ownerId,callable $write): mixed
{
    $owned=!$pdo->inTransaction();if($owned)$pdo->beginTransaction();
    try{section12_lock_owner($pdo,$ownerId);$result=$write();if($owned)$pdo->commit();return $result;}
    catch(Throwable $e){if($owned&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function section12_revision(array $row): string
{
    return hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function section12_assert_revision(array $row,?string $expected): void
{
    if($expected!==null&&(!preg_match('/^[0-9a-f]{64}$/',$expected)||!hash_equals(section12_revision($row),$expected)))throw new RuntimeException('This record changed. Reload before saving or removing it.');
}
