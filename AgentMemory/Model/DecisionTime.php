<?php

namespace Kanboard\Plugin\AgentMemory\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Strict local form input; the API continues to accept UTC RFC3339 only. */
final class DecisionTime
{
    public static function candidates($value, $timezone)
    {
        if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d)?$/D', $value) || strlen($timezone) > 100) {
            throw new InvalidArgumentException('判断日時の形式を確認してください。');
        }
        $value = strlen($value) === 16 ? $value.':00' : $value;
        try { $zone = new DateTimeZone($timezone); }
        catch (\Exception $error) { throw new InvalidArgumentException('判断日時のタイムゾーンを確認してください。'); }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, $zone);
        if (!$date || $date->format('Y-m-d\TH:i:s') !== $value) {
            throw new InvalidArgumentException('存在しない判断日時です。日付と時刻を確認してください。');
        }
        // Try every offset around this wall time, rather than silently choosing one
        // occurrence of an overlapping hour when daylight saving ends.
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, new DateTimeZone('UTC'))->getTimestamp();
        $offsets = array($date->getOffset());
        foreach ($zone->getTransitions($wall - 172800, $wall + 172800) ?: array() as $transition) {
            $offsets[] = $transition['offset'];
        }
        $candidates = array();
        foreach (array_unique($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@'.($wall - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d\TH:i:s') === $value) {
                $utc = gmdate('Y-m-d\TH:i:s\Z', $candidate->getTimestamp());
                $candidates[$utc] = 'UTC'.$candidate->format('P').' / '.$utc;
            }
        }
        ksort($candidates);
        return $candidates;
    }

    public static function toUtc($value, $timezone, $previous = '', $choice = '')
    {
        $candidates = self::candidates($value, $timezone);
        if ($choice !== '') {
            if (!isset($candidates[$choice])) {
                throw new InvalidArgumentException('判断日時を変更したため、UTCオフセットを選び直してください。');
            }
            return $choice;
        }
        if ($previous !== '' && isset($candidates[$previous])) { return $previous; }
        if (count($candidates) !== 1) {
            throw new InvalidArgumentException('夏時間の切替で重複する日時です。UTCオフセットを選んで保存してください。');
        }
        return key($candidates);
    }
}
