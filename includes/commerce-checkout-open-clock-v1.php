<?php
declare(strict_types=1);

/** A reservation is not a provider-confirmed checkout opening. No legacy dating. */
const VP3_COMMERCE_CHECKOUT_OPEN_CLOCK_V1='vp3.commerce-checkout-open-clock.v1';

function agent_commerce_checkout_open_clock_ready_v1(PDO $pdo): bool
{
    try{$pdo->query('SELECT opened_at_utc_ms FROM agent_commerce_checkout_attempts_v800 WHERE 1=0');return true;}
    catch(PDOException $error){
        $state=(string)($error->errorInfo[0]??$error->getCode());$native=(int)($error->errorInfo[1]??0);
        if(in_array($state,['42S02','42S22'],true)
            ||($native===1&&preg_match('/no such (?:table|column):/i',$error->getMessage())))return false;
        throw $error;
    }
}

function agent_commerce_checkout_open_clock_install_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new LogicException('Install checkout opening time outside a transaction.');
    if(agent_commerce_checkout_open_clock_ready_v1($pdo))return;
    try{$pdo->exec('ALTER TABLE agent_commerce_checkout_attempts_v800 ADD COLUMN opened_at_utc_ms BIGINT NULL');}
    catch(PDOException $error){if(!agent_commerce_checkout_open_clock_ready_v1($pdo))throw $error;}
}

function agent_commerce_checkout_open_now_v1(): int
{
    return (int)floor(microtime(true)*1000);
}
