<?php
declare(strict_types=1);

function historyRetentionDays(PDO $db): int {
    $value=$db->query("SELECT value FROM settings WHERE name='history_retention_days'")->fetchColumn();
    return $value===false?30:max(1,min(3650,(int)$value));
}

function pruneHistory(PDO $db, ?int $now=null): int {
    $now??=time();
    $previous=$db->query("SELECT value FROM settings WHERE name='history_pruned_at'")->fetchColumn();
    if ($previous!==false && $now-(int)$previous>=0 && $now-(int)$previous<86400) { return 0; }
    $cutoff=$now-historyRetentionDays($db)*86400;
    $delete=$db->prepare("DELETE FROM runs WHERE id IN (SELECT id FROM runs WHERE finished IS NOT NULL AND status <> 'running' AND CAST(strftime('%s',finished) AS INTEGER) < ? LIMIT 2000)");
    $total=0;
    do { $delete->bindValue(1,$cutoff,PDO::PARAM_INT); $delete->execute(); $count=$delete->rowCount(); $total+=$count; } while($count===2000);
    $save=$db->prepare("INSERT INTO settings(name,value) VALUES('history_pruned_at',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value");
    $save->execute([(string)$now]);
    return $total;
}
