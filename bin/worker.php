<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../app/Sync.php';
require_once __DIR__ . '/../app/History.php';
$lock = fopen(STORAGE . '/worker.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
query("UPDATE runs SET status='error', finished=?, details=? WHERE status='running'", [date('c'), 'Предишният процес е прекъснат. Проверете целта за помощни таблици.']);
try { $removed=pruneHistory($db); if ($removed) { echo "History: removed $removed expired runs.\n"; } }
catch(Throwable $e) { fwrite(STDERR,'History cleanup failed: '.$e->getMessage()."\n"); }
$requests = query('SELECT * FROM requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$manual = array_column($requests, 'job_id');
$failed = false;
foreach (query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $job) {
    $slot = scheduleSlot($job['schedule'], new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia')));
    $requested = in_array($job['id'], $manual);
    if (!$requested && (!$job['enabled'] || !$slot || ($job['last_slot'] !== null && $slot <= $job['last_slot']))) { continue; }
    if ($slot) { query('UPDATE jobs SET last_slot=? WHERE id=?', [$slot,$job['id']]); }
    foreach ($requests as $r) { if ($r['job_id'] == $job['id']) { query('DELETE FROM requests WHERE id=?',[$r['id']]); } }
    query('INSERT INTO runs(job_id,started,status) VALUES(?,?,?)',[$job['id'],date('c'),'running']); $id = $db->lastInsertId();
    try { $result = synchronize($job); $status=!empty($result['_skipped'])?'skipped':'success'; query('UPDATE runs SET finished=?,status=?,details=? WHERE id=?',[date('c'),$status,json_encode($result,JSON_UNESCAPED_UNICODE),$id]); echo $job['name'] . ($status==='skipped'?": SKIPPED — ".$result['message']."\n":": OK\n"); }
    catch (Throwable $e) { $failed = true; query('UPDATE runs SET finished=?,status=?,details=? WHERE id=?',[date('c'),'error',$e->getMessage(),$id]); fwrite(STDERR, $job['name'] . ': ' . $e->getMessage() . "\n"); }
}
flock($lock, LOCK_UN); exit($failed ? 1 : 0);
