<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/agent-scheduling-v430.php';

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_true(agent_scheduling_slug_v430('  30 Minute Intro Call  ') === '30-minute-intro-call', 'slug normalization');
expect_true(agent_scheduling_timezone_v430('America/Phoenix') === 'America/Phoenix', 'valid timezone preservation');
expect_true(agent_scheduling_timezone_v430('Not/A_Zone', 'UTC') === 'UTC', 'invalid timezone fallback');

[$weekday, $start, $end] = agent_scheduling_validate_window_v430(1, 540, 1020);
expect_true($weekday === 1 && $start === 540 && $end === 1020, 'valid availability window');

$threw = false;
try {
    agent_scheduling_validate_window_v430(7, 540, 1020);
} catch (RuntimeException $e) {
    $threw = true;
}
expect_true($threw, 'invalid weekday rejection');

$threw = false;
try {
    agent_scheduling_validate_window_v430(1, 1020, 540);
} catch (RuntimeException $e) {
    $threw = true;
}
expect_true($threw, 'invalid reversed window rejection');

echo "Agent Scheduling v4.30 helper tests passed.\n";
