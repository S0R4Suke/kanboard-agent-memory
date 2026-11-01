<?php
require __DIR__.'/../AgentMemory/Model/DecisionTime.php';
use Kanboard\Plugin\AgentMemory\Model\DecisionTime;

$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) { throw new RuntimeException($label); }
    $checks++;
}
function rejects($callback, $label) {
    try { $callback(); } catch (InvalidArgumentException $error) { check(true, $label); return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
foreach (array(
    array('2026-10-30T14:15:37', 'Asia/Tokyo', '2026-10-30T05:15:37Z'),
    array('2026-10-30T14:15', 'Asia/Kathmandu', '2026-10-30T08:30:00Z'),
    array('2024-02-29T23:59:59', 'UTC', '2024-02-29T23:59:59Z'),
    array('2026-01-01T00:00', 'Pacific/Kiritimati', '2025-12-31T10:00:00Z'),
) as $case) {
    check(DecisionTime::toUtc($case[0], $case[1]) === $case[2], 'timezone and precision: '.$case[1]);
}
foreach (array('2026-02-29T12:00', '2026-02-30T12:00', '2026-01-01T24:00', '2026-01-01T12:60', '2026-01-01T12:00:60', '2026-01-01', '2026-01-01T12:00Z', '2026-01-01T12:00:00junk') as $value) {
    rejects(fn() => DecisionTime::toUtc($value, 'UTC'), 'invalid date: '.$value);
}
rejects(fn() => DecisionTime::toUtc('2026-10-30T12:00', 'invalid/zone'), 'invalid zone');
rejects(fn() => DecisionTime::toUtc('2026-03-08T02:30', 'America/New_York'), 'spring DST gap');
rejects(fn() => DecisionTime::toUtc('2011-12-30T12:00', 'Pacific/Apia'), 'skipped calendar day');
$candidates = DecisionTime::candidates('2026-11-01T01:30:45', 'America/New_York');
check(array_keys($candidates) === array('2026-11-01T05:30:45Z', '2026-11-01T06:30:45Z'), 'both overlap offsets available');
rejects(fn() => DecisionTime::toUtc('2026-11-01T01:30:45', 'America/New_York'), 'new overlap requires choice');
check(DecisionTime::toUtc('2026-11-01T01:30:45', 'America/New_York', '2026-11-01T06:30:45Z') === '2026-11-01T06:30:45Z', 'unchanged overlap retains instant');
rejects(fn() => DecisionTime::toUtc('2026-11-01T01:30:46', 'America/New_York', '2026-11-01T06:30:45Z'), 'changed overlap cannot silently switch offsets');
check(DecisionTime::toUtc('2026-11-01T01:30:46', 'America/New_York', '', '2026-11-01T06:30:46Z') === '2026-11-01T06:30:46Z', 'overlap choice selects exact instant');
rejects(fn() => DecisionTime::toUtc('2026-11-01T01:30:46', 'America/New_York', '', '2026-11-01T06:30:45Z'), 'stale or forged choice rejected');
check(count(DecisionTime::candidates('2026-04-05T01:45', 'Australia/Lord_Howe')) === 2, 'half-hour DST overlap detected');
echo "PASS: $checks decision timestamp checks\n";
