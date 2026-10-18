<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit; }
$temp=sys_get_temp_dir().'/sync-transfer-'.bin2hex(random_bytes(8));
putenv('SYNC_STORAGE='.$temp);
require __DIR__.'/../app/Transfer.php';
function verify(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
query('INSERT INTO servers(name,host,port,dbname,username,secret) VALUES(?,?,?,?,?,?)',['Source','localhost',3306,'source','reader',seal('example-secret')]);
query('INSERT INTO servers(name,host,port,dbname,username,secret) VALUES(?,?,?,?,?,?)',['Target','localhost',3306,'target','writer',seal('other-secret')]);
query('INSERT INTO jobs(name,source_id,target_id,tables_json,schedule) VALUES(?,?,?,?,?)',['Daily',1,2,'["sales_*"]','08:00,20:00']);
$password='test-archive-password'; $archive=exportConfiguration($password);
verify(!str_contains($archive,'example-secret'),'Plaintext leaked');
verify(importConfiguration($archive,$password)===[2,1],'Counts wrong');
$job=query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
verify((int)$job['enabled']===0 && (int)$job['source_id']===3 && (int)$job['target_id']===4,'Import remapping failed');
verify(unseal(server(3)['secret'])==='example-secret','Password transfer failed');
foreach (['wrong password', $password] as $i=>$pass) {
    $input=$archive;
    if ($i===1) { $e=json_decode($archive,true); $e['cipher']=base64_encode(str_repeat('x',40)); $input=json_encode($e); }
    $failed=false; try { importConfiguration($input,$pass); } catch(Throwable $e) { $failed=true; }
    verify($failed,'Invalid archive accepted');
}
$invalid=unpackConfiguration($archive,$password); $invalid['jobs'][0]['source_id']=999;
try { importConfiguration(packConfiguration($invalid,$password),$password); throw new LogicException('Invalid job accepted'); } catch (RuntimeException $e) {}
verify((int)query('SELECT COUNT(*) FROM servers')->fetchColumn()===4,'Rollback failed');
echo "PASS: encrypted round trip, credentials, ID remapping, disabled jobs, wrong password, tampering, transactional rollback.\nIsolated storage: $temp\n";
