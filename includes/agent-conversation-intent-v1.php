<?php
declare(strict_types=1);

/** Incomplete setup requests start a clarification; they never authorize a write. */
function vp3_reminder_setup_request_v1(string $query): bool
{
    return (bool)preg_match('/^(?:(?:can|could|would|will) you (?:please )?|please )?(?:set|create|add|schedule) (?:me )?(?:a |an )?(?:personal )?reminder(?: for me)?(?: please)?[.!?]*$/iu',trim($query));
}
