<?php
declare(strict_types=1);

function vp3_gelato_business_date_v1(string $value): bool
{
    return (bool)preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$value,$date)
        && checkdate((int)$date[2],(int)$date[3],(int)$date[1]);
}

function vp3_gelato_ticket_number_v1(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^[A-Z0-9][A-Z0-9-]{0,47}$/iD',$value)) return null;
    return strtoupper($value);
}

function vp3_gelato_ticket_number_matches_v1(string $lookup, string $number): bool
{
    return $lookup === $number || (preg_match('/^\d{6}$/D',$lookup)
        && preg_match('/^'.preg_quote($lookup,'/').'-[A-F0-9]{8}$/D',$number));
}
