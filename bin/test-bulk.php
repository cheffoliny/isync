<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit; }
require __DIR__.'/../app/BulkCopy.php';
function ident(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
function check(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE sample(id INTEGER PRIMARY KEY, value TEXT, optional TEXT)');
$rows=(function(): Generator { for($i=0;$i<1201;$i++) { yield [$i,"Кирилица ' \\ \0 ".$i,$i%2?null:'']; } })();
$result=copyInBatches($rows,$pdo,'sample',['id','value','optional'],1048576);
check($result['rows']===1201 && $result['batches']===2,'Wrong batch counts');
check((int)$pdo->query('SELECT COUNT(*) FROM sample')->fetchColumn()===1201,'Missing rows');
$saved=$pdo->query('SELECT * FROM sample WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check($saved['value']==="Кирилица ' \\ \0 1" && $saved['optional']===null,'Values changed');
$empty=copyInBatches([],$pdo,'sample',['id','value','optional'],1048576);
check($empty['rows']===0 && $empty['batches']===0,'Empty copy failed');
$sized=iterator_to_array(rowBatches(array_fill(0,1601,[1]),1,1048576));
check(array_map('count',$sized)===[800,800,1],'800-row batch limit failed');
$split=iterator_to_array(rowBatches([['a'],['b'],['c']],1,120));
check(count($split)===2 && count($split[0])===2,'Packet split failed');
$wide=iterator_to_array(rowBatches(array_fill(0,4,array_fill(0,20000,null)),20000,10000000));
check(count($wide)===2 && count($wide[0])===3,'Parameter bound failed');
$failed=false; try { iterator_to_array(rowBatches([[str_repeat('x',1000)]],1,100)); } catch(RuntimeException $e) { $failed=true; }
check($failed,'Oversized row accepted');
$failed=false; try { copyInBatches([[5000,'new',null],[1,'duplicate',null]],$pdo,'sample',['id','value','optional'],1048576); } catch(Throwable $e) { $failed=true; }
check($failed && !$pdo->inTransaction() && (int)$pdo->query('SELECT COUNT(*) FROM sample WHERE id=5000')->fetchColumn()===0,'Batch rollback failed');
$pdo->exec('CREATE TABLE grouped(id INTEGER PRIMARY KEY, value TEXT)');
$grouped=(function(): Generator { for($i=0;$i<4801;$i++) { yield [$i,'test']; } })();
$groupResult=copyInBatches($grouped,$pdo,'grouped',['id','value'],1048576);
check($groupResult['rows']===4801 && $groupResult['batches']===7 && !$pdo->inTransaction(),'Grouped commits failed');
check((int)$pdo->query('SELECT COUNT(*) FROM grouped')->fetchColumn()===4801,'Grouped commit lost rows');
echo "PASS: streaming multi-row copy, partial batch, Unicode/quotes/NUL/NULL, empty tables, packet/parameter bounds, failed-batch rollback.\n";
