<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TableSelection.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Triggers.php';
require_once __DIR__ . '/BulkCopy.php';
function synchronize(array $job): array {
    $started=microtime(true); $timings=[];
    $a = server((int)$job['source_id']); $b = server((int)$job['target_id']);
    if (strtolower($a['host']) === strtolower($b['host']) && $a['port'] == $b['port'] && $a['dbname'] === $b['dbname']) { throw new RuntimeException('Източникът и целта съвпадат.'); }
    $src = connect($a); $dst = connect($b);
    $packetBudget=max(1,(int)floor((int)$dst->query('SELECT @@SESSION.max_allowed_packet')->fetchColumn()*0.5)-8192);
    // Detect aliases pointing to the very same MySQL instance and schema.
    if ($a['dbname'] === $b['dbname'] && $src->query('SELECT @@server_uuid')->fetchColumn() === $dst->query('SELECT @@server_uuid')->fetchColumn()) { throw new RuntimeException('Източникът и целта са една и съща база.'); }
    $tables = json_decode($job['tables_json'], true, 512, JSON_THROW_ON_ERROR);
    $tables = resolveTables($src, $a['dbname'], $tables);
    if (!$tables) { return ['_skipped'=>true,'message'=>'Няма съвпадащи таблици в източника. Шаблонът ще се провери при следващото изпълнение.']; }
    // Preserve source interpretation of legacy defaults/dates when creating/loading its schema.
    $sourceMode=compatibleTriggerSqlMode((string)$src->query('SELECT @@SESSION.sql_mode')->fetchColumn(),(string)$dst->query('SELECT VERSION()')->fetchColumn());
    $modes=array_filter(explode(',',$sourceMode));
    if (!in_array('NO_AUTO_VALUE_ON_ZERO',$modes,true)) { $modes[]='NO_AUTO_VALUE_ON_ZERO'; }
    $dst->exec('SET SESSION sql_mode='.$dst->quote(implode(',',$modes)));
    $src->exec('SET SESSION sql_quote_show_create=1');
    $triggers = readTriggers($src, $a['dbname'], $tables);
    $triggers = prepareTriggerModes($dst, $triggers);
    // Detect trigger-name collisions outside the copied tables before changing anything.
    foreach ($triggers as $trigger) {
        $collision=$dst->prepare('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND TRIGGER_NAME=?');
        $collision->execute([$b['dbname'],$trigger['name']]); $owner=$collision->fetchColumn();
        if ($owner!==false && !in_array($owner,$tables,true)) { throw new RuntimeException('Името на тригер '.$trigger['name'].' е заето от друга целева таблица: '.$owner); }
    }
    $mapping = []; $counts = []; $swapped = false;
    $metadata = function(PDO $pdo, string $schema, string $table): array {
        $s = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\''); $s->execute([$schema,$table]);
        if (strtoupper((string)$s->fetchColumn()) !== 'INNODB') { throw new RuntimeException("Таблицата $table трябва да е InnoDB и да съществува."); }
        $s = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'); $s->execute([$schema,$table]); return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    try {
        foreach ($tables as $table) {
            $cols = $metadata($src, $a['dbname'], $table);
            $exists=$dst->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
            $exists->execute([$b['dbname'],$table]); $targetType=$exists->fetchColumn();
            if ($targetType!==false && $targetType!=='BASE TABLE') { throw new RuntimeException("Целевият обект $table не е таблица."); }
            $s = $dst->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME=?)'); $s->execute([$b['dbname'],$table,$b['dbname'],$table]);
            if ($s->fetchColumn()) { throw new RuntimeException("Целевата таблица $table има външни ключове."); }
            $token = bin2hex(random_bytes(10)); $stage = '__sync_new_' . $token; $old = $targetType!==false ? '__sync_old_' . $token : null;
            $fk=$src->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $fk->execute([$a['dbname'],$table]);
            if ($fk->fetchColumn()) { throw new RuntimeException("Копиране на $table с външни ключове не се поддържа."); }
            $ddl=$src->query('SHOW CREATE TABLE '.ident($table))->fetch(PDO::FETCH_NUM);
            try { $dst->exec(stagingCreateSql($ddl[1],$stage)); }
            catch (Throwable $e) { throw new RuntimeException("Неуспешно създаване на структурата от А за $table: ".$e->getMessage(),0,$e); }
            $mapping[$table] = [$stage, $old, array_column(array_filter($cols, fn($c)=>!isGeneratedColumn($c)), 'COLUMN_NAME')];
        }
        $src->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $src->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $setupSeconds=microtime(true)-$started;
        $bufferedQueryAttribute = defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
            ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
            : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
        $src->setAttribute($bufferedQueryAttribute, false);
        foreach ($mapping as $table => [$stage,$old,$columns]) {
            $tableStarted=microtime(true);
            $names = implode(',', array_map('ident', $columns));
            $read = $src->query('SELECT ' . $names . ' FROM ' . ident($table));
            $rows=(function() use ($read): Generator { while ($row=$read->fetch(PDO::FETCH_NUM)) { yield $row; } })();
            try { $result=copyInBatches($rows,$dst,$stage,$columns,$packetBudget); }
            finally { $read->closeCursor(); }
            $count=$result['rows'];
            $verifyStarted=microtime(true);
            if ((int)$dst->query('SELECT COUNT(*) FROM ' . ident($stage))->fetchColumn() !== $count) { throw new RuntimeException("Непълен запис: $table"); }
            $counts[$table] = $count;
            $timings[$table]=['seconds'=>round(microtime(true)-$tableStarted,3),'rows'=>$count,'insert_batches'=>$result['batches'],'read_seconds'=>$result['read_seconds'],'write_seconds'=>$result['write_seconds'],'commit_seconds'=>$result['commit_seconds'],'verify_seconds'=>round(microtime(true)-$verifyStarted,3)];
        }
        $src->commit();
        if ($triggers) {
            // Persist original definitions for recovery if the process stops after table publication.
            $recovery=STORAGE.'/triggers-job-'.$job['id'].'-'.bin2hex(random_bytes(6)).'.json';
            if (file_put_contents($recovery,json_encode($triggers,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false) { throw new RuntimeException('Не може да се запише архивът на тригерите.'); }
        }
        $renames = tableSwapClauses($mapping);
        $dst->exec('SET SESSION lock_wait_timeout=15');
        $dst->exec('RENAME TABLE ' . implode(', ', $renames)); $swapped = true;
        foreach ($mapping as [$stage,$old]) { if ($old===null) { continue; } try { $dst->exec('DROP TABLE ' . ident($old)); } catch (Throwable $e) { throw new RuntimeException('Данните са прехвърлени, но старата таблица '.$old.' не е премахната. Тригерите може да не са възстановени. '.$e->getMessage(),0,$e); } }
        installTriggers($dst,$triggers);
        if ($triggers) { $counts['triggers_copied']=count($triggers); }
        $counts['_performance']=['total_seconds'=>round(microtime(true)-$started,3),'setup_seconds'=>round($setupSeconds,3),'tables'=>$timings];
        return $counts;
    } finally {
        if ($src->inTransaction()) { $src->rollBack(); } if ($dst->inTransaction()) { $dst->rollBack(); }
        if (!$swapped) { foreach ($mapping as [$stage]) { try { $dst->exec('DROP TABLE IF EXISTS ' . ident($stage)); } catch (Throwable $e) {} } }
    }
}
