<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/TableSelection.php';
echo "Read-only preflight; no table data is modified.\n";
foreach (query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $job) {
    echo "\nJob #{$job['id']}: {$job['name']} | schedule={$job['schedule']} | enabled={$job['enabled']}\n";
    try {
        $source = server((int)$job['source_id']);
        $tables = resolveTables(connect($source), $source['dbname'], json_decode($job['tables_json'],true,512,JSON_THROW_ON_ERROR));
        echo 'Selected tables: ' . implode(', ', $tables) . "\n";
    } catch (Throwable $e) { echo 'SELECTION ERROR: ' . $e->getMessage() . "\n"; continue; }
    foreach (['source_id'=>'SOURCE','target_id'=>'TARGET'] as $field=>$label) {
        try {
            $s=server((int)$job[$field]); $pdo=connect($s);
            echo "$label: {$s['name']} | database={$s['dbname']} | version=".$pdo->query('SELECT VERSION()')->fetchColumn()."\n";
            foreach ($tables as $table) {
                $st=$pdo->prepare('SELECT ENGINE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\''); $st->execute([$s['dbname'],$table]); $meta=$st->fetch(PDO::FETCH_ASSOC);
                if (!$meta) { echo "  $table: MISSING\n"; continue; }
                $count=$pdo->query('SELECT COUNT(*) FROM '.ident($table))->fetchColumn();
                echo "  $table: engine={$meta['ENGINE']} rows=$count\n";
                $st=$pdo->query('SHOW CREATE TABLE '.ident($table)); $ddl=$st->fetch(PDO::FETCH_NUM); echo $ddl[1]."\n";
            }
        } catch(Throwable $e) { echo "$label ERROR: ".$e->getMessage()."\n"; }
    }
}
echo "\nRecent runs:\n";
foreach(query('SELECT job_id,started,finished,status,details FROM runs ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC) as $r) { echo json_encode($r,JSON_UNESCAPED_UNICODE)."\n"; }
