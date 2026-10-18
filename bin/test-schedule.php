<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit; }
require __DIR__.'/../app/Schedule.php';
function checkSchedule(bool $ok): void { if (!$ok) { throw new RuntimeException('Schedule check failed'); } }
foreach(['2'=>'every:2','2 minute'=>'every:2','3 minutes'=>'every:3','every 7 minutes'=>'every:7','5 минути'=>'every:5','every:12'=>'every:12','1'=>'minute','minute'=>'minute','20:00, 08:00,20:00'=>'08:00,20:00'] as $input=>$expected) { checkSchedule(normalizeSchedule((string)$input)===$expected); }
foreach(['0','0 minutes','-2','2.5','24:00','08:99','every:','', 'foo'] as $invalid) { $failed=false; try { normalizeSchedule($invalid); } catch(RuntimeException $e) { $failed=true; } checkSchedule($failed); }
$start=new DateTimeImmutable('2026-10-18T12:00:00+03:00');
checkSchedule(scheduleSlot('2 minutes',$start)===scheduleSlot('2 minutes',$start->modify('+1 minute')));
checkSchedule(scheduleSlot('2 minutes',$start)!==scheduleSlot('2 minutes',$start->modify('+2 minutes')));
$previous=null; $transitions=0;
for($i=0;$i<120;$i++) { $slot=scheduleSlot('7 minutes',$start->modify("+$i minutes")); if ($previous!==null && $slot!==$previous) { checkSchedule((int)substr($slot,9)-(int)substr($previous,9)===420); $transitions++; } $previous=$slot; }
checkSchedule($transitions>15);
checkSchedule(scheduleSlot('08:00,20:00',new DateTimeImmutable('2026-10-18 07:00'))===null);
checkSchedule(scheduleSlot('08:00,20:00',new DateTimeImmutable('2026-10-18 09:00'))==='2026-10-18 08:00');
checkSchedule(scheduleSlot('minute',$start)==='2026-10-18 12:00');
checkSchedule(normalizeSchedule(scheduleInput('every:7'))==='every:7');
echo "PASS: interval aliases/validation, minute compatibility, daily schedules, bucket deduplication and 7-minute intervals across hours.\n";
