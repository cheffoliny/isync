<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit; }
require __DIR__.'/../app/History.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE settings(name TEXT PRIMARY KEY,value TEXT NOT NULL); CREATE TABLE runs(id INTEGER PRIMARY KEY,finished TEXT,status TEXT)');
$db->exec("CREATE INDEX runs_retention_finished ON runs(CAST(strftime('%s',finished) AS INTEGER)) WHERE finished IS NOT NULL AND status <> 'running'");
function verifyHistory(bool $ok): void { if (!$ok) { throw new RuntimeException('History retention test failed'); } }
$now=strtotime('2026-10-18T12:00:00+03:00'); $cutoff=$now-30*86400;
verifyHistory(historyRetentionDays($db)===30);
$insert=$db->prepare('INSERT INTO runs(finished,status) VALUES(?,?)');
for($i=0;$i<2001;$i++) { $insert->execute([gmdate('c',$cutoff-1),'success']); }
$insert->execute([date('c',$cutoff),'error']);
$insert->execute([gmdate('c',$now),'skipped']);
$insert->execute([gmdate('c',$cutoff-100),'running']);
$insert->execute([null,'running']);
$insert->execute(['invalid-date','error']);
verifyHistory(pruneHistory($db,$now)===2001);
verifyHistory((int)$db->query('SELECT COUNT(*) FROM runs')->fetchColumn()===5);
$insert->execute([gmdate('c',$cutoff-1),'error']);
verifyHistory(pruneHistory($db,$now+60)===0);
$db->exec("INSERT INTO settings(name,value) VALUES('history_retention_days','1'); DELETE FROM settings WHERE name='history_pruned_at'");
verifyHistory(historyRetentionDays($db)===1);
verifyHistory(pruneHistory($db,$now+60)===2);
verifyHistory((int)$db->query("SELECT COUNT(*) FROM runs WHERE status='running'")->fetchColumn()===2);
echo "PASS: 30-day default, exact cutoff/timezones, chunked deletion, active/unfinished protection, daily throttle and changed retention. In-memory database only.\n";
