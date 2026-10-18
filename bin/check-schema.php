<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/TableSelection.php';
require __DIR__.'/../app/Schema.php';
foreach(query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $job) {
    echo "Job #{$job['id']} {$job['name']}\n";
    try {
        $a=server((int)$job['source_id']); $b=server((int)$job['target_id']); $src=connect($a); $dst=connect($b);
        $tables=resolveTables($src,$a['dbname'],json_decode($job['tables_json'],true,512,JSON_THROW_ON_ERROR));
        foreach($tables as $table) {
            $columns=[];
            foreach([[$src,$a['dbname']],[$dst,$b['dbname']]] as [$pdo,$schema]) {
                $s=$pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'); $s->execute([$schema,$table]); $columns[]=$s->fetchAll(PDO::FETCH_ASSOC);
            }
            if (!$columns[1]) { echo $table.": missing on target — will be created from source\n"; continue; }
            $diff=columnDifferences(...$columns); echo $table.': '.($diff?implode('; ',$diff):'compatible')."\n";
        }
    } catch(Throwable $e) { echo 'ERROR: '.$e->getMessage()."\n"; }
}
