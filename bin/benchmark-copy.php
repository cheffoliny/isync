<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit; }
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/BulkCopy.php';
if (!isset($argv[1])) { fwrite(STDERR,"Usage: php bin/benchmark-copy.php TARGET_CONNECTION_ID\nUses synthetic rows in a connection-local TEMPORARY TABLE only.\n"); exit(1); }
$pdo=connect(server((int)$argv[1]));
$table='__sync_benchmark_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE TEMPORARY TABLE '.ident($table).' (id INT PRIMARY KEY, value TEXT, optional_value TEXT) ENGINE=InnoDB');
try {
    $rows=[]; for($i=0;$i<200;$i++) { $rows[]=[$i,"Тест ' \\ ".$i,$i%2?null:'']; }
    $start=microtime(true); $pdo->beginTransaction();
    $insert=$pdo->prepare('INSERT INTO '.ident($table).' VALUES(?,?,?)'); foreach($rows as $row) { $insert->execute($row); } $pdo->commit();
    $single=microtime(true)-$start;
    $pdo->exec('DELETE FROM '.ident($table));
    $start=microtime(true); $packet=max(1,(int)floor((int)$pdo->query('SELECT @@SESSION.max_allowed_packet')->fetchColumn()*0.5)-8192);
    $result=copyInBatches($rows,$pdo,$table,['id','value','optional_value'],$packet); $bulk=microtime(true)-$start;
    $saved=$pdo->query('SELECT id,value,optional_value FROM '.ident($table).' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    foreach($saved as &$row) { $row[0]=(int)$row[0]; } unset($row);
    if ($saved!==$rows) { throw new RuntimeException('Value verification failed'); }
    echo json_encode(['synthetic_rows'=>count($rows),'single_row_seconds'=>round($single,3),'bulk_seconds'=>round($bulk,3),'bulk_insert_queries'=>$result['batches'],'values_verified'=>true],JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } $pdo->exec('DROP TEMPORARY TABLE '.ident($table)); }
