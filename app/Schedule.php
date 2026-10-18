<?php
declare(strict_types=1);

function normalizeSchedule(string $input): string {
    $input=trim($input);
    if ($input==='minute') { return 'minute'; }
    if (preg_match('/^(?:every[:\s]+)?([0-9]{1,9})(?:\s+(?:minutes?|минути|минута|мин))?$/iu',$input,$match)) {
        $minutes=(int)$match[1];
        if ($minutes<1) { throw new RuntimeException('Интервалът трябва да е поне 1 минута.'); }
        return $minutes===1?'minute':'every:'.$minutes;
    }
    $times=array_unique(array_map('trim',explode(',',$input)));
    foreach($times as $time) { if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)) { throw new RuntimeException('График: 2 minutes (на всеки 2 минути), minute или часове като 08:00,20:00.'); } }
    sort($times); return implode(',',$times);
}

function scheduleSlot(string $schedule, DateTimeImmutable $now): ?string {
    $schedule=normalizeSchedule($schedule);
    if ($schedule==='minute') { return $now->format('Y-m-d H:i'); }
    if (str_starts_with($schedule,'every:')) {
        $seconds=(int)substr($schedule,6)*60;
        // Absolute time buckets preserve N-minute spacing across hours, days and DST.
        return 'interval:'.sprintf('%020d',intdiv($now->getTimestamp(),$seconds)*$seconds);
    }
    $slot=null;
    foreach(explode(',',$schedule) as $time) { if ($now->format('H:i') >= $time) { $slot=$now->format('Y-m-d').' '.$time; } }
    return $slot;
}

function scheduleLabel(string $schedule): string {
    $schedule=normalizeSchedule($schedule);
    if ($schedule==='minute') { return 'Всяка минута'; }
    return str_starts_with($schedule,'every:')?'На всеки '.substr($schedule,6).' минути':$schedule;
}

function scheduleInput(string $schedule): string {
    return str_starts_with($schedule,'every:')?substr($schedule,6).' minutes':$schedule;
}
