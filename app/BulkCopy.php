<?php
declare(strict_types=1);

/** Bound batches by row count, native prepared-statement parameter count and packet budget. */
function rowBatches(iterable $rows, int $columnCount, int $packetBudget, int $maxRows=800): Generator {
    if ($columnCount<1 || $columnCount>60000 || $packetBudget<1) { throw new RuntimeException('Невалидни ограничения за пакетно копиране.'); }
    $limit=max(1,min($maxRows,intdiv(60000,$columnCount)));
    $batch=[]; $bytes=0;
    foreach ($rows as $row) {
        if (count($row)!==$columnCount) { throw new RuntimeException('Невалиден брой колони в ред.'); }
        // Conservative allowance for prepare SQL, value lengths, types and protocol overhead.
        $rowBytes=32+$columnCount*24;
        foreach ($row as $value) { if ($value!==null) { $rowBytes+=strlen((string)$value)*2; } }
        if ($rowBytes>$packetBudget) { throw new RuntimeException('Редът е по-голям от безопасния пакет за MySQL. Проверете max_allowed_packet.'); }
        if ($batch && (count($batch)>=$limit || $bytes+$rowBytes>$packetBudget)) { yield $batch; $batch=[]; $bytes=0; }
        $batch[]=$row; $bytes+=$rowBytes;
    }
    if ($batch) { yield $batch; }
}

function copyInBatches(iterable $rows, PDO $target, string $table, array $columns, int $packetBudget): array {
    $prefix='INSERT INTO '.ident($table).' ('.implode(',',array_map('ident',$columns)).') VALUES ';
    $placeholder='('.implode(',',array_fill(0,count($columns),'?')).')';
    $count=0; $batches=0; $statement=null; $lastSize=0;
    $readSeconds=0.0; $writeSeconds=0.0; $commitSeconds=0.0; $pendingRows=0;
    $waiting=microtime(true);
    try {
      foreach (rowBatches($rows,count($columns),$packetBudget) as $batch) {
        $readSeconds+=microtime(true)-$waiting;
        $writing=microtime(true);
        $size=count($batch);
        if ($size!==$lastSize) { $statement=$target->prepare($prefix.implode(',',array_fill(0,$size,$placeholder))); $lastSize=$size; }
        $values=[];
        foreach ($batch as $row) { foreach ($row as $value) { $values[]=$value; } }
        if (!$target->inTransaction()) { $target->beginTransaction(); }
        $statement->execute($values);
        $writeSeconds+=microtime(true)-$writing;
        $pendingRows+=$size;
        // Staging tables are unpublished: group commits to reduce disk/network round trips.
        if ($pendingRows>=4000) { $committing=microtime(true); $target->commit(); $commitSeconds+=microtime(true)-$committing; $pendingRows=0; }
        $count+=$size; $batches++;
        $waiting=microtime(true);
      }
      $readSeconds+=microtime(true)-$waiting;
      if ($target->inTransaction()) { $committing=microtime(true); $target->commit(); $commitSeconds+=microtime(true)-$committing; }
    } catch (Throwable $e) {
        if ($target->inTransaction()) { $target->rollBack(); } throw $e;
    }
    return ['rows'=>$count,'batches'=>$batches,'read_seconds'=>round($readSeconds,3),'write_seconds'=>round($writeSeconds,3),'commit_seconds'=>round($commitSeconds,3)];
}
