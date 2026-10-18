<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function packConfiguration(array $data, string $password): string {
    if (strlen($password) < 12) { throw new RuntimeException('Паролата за архива трябва да има поне 12 символа.'); }
    $salt=random_bytes(16); $nonce=random_bytes(24);
    $key=hash_pbkdf2('sha256',$password,$salt,310000,32,true);
    $cipher=sodium_crypto_secretbox(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$nonce,$key);
    sodium_memzero($key);
    return json_encode(['format'=>'sync-config','version'=>1,'salt'=>base64_encode($salt),'nonce'=>base64_encode($nonce),'cipher'=>base64_encode($cipher)],JSON_THROW_ON_ERROR);
}

function unpackConfiguration(string $archive, string $password): array {
    if (strlen($archive)>2097152) { throw new RuntimeException('Максимален размер на архива: 2 MB.'); }
    $envelope=json_decode($archive,true,32,JSON_THROW_ON_ERROR);
    if (($envelope['format']??null)!=='sync-config' || ($envelope['version']??null)!==1) { throw new RuntimeException('Неподдържан формат на архив.'); }
    $salt=base64_decode($envelope['salt']??'',true); $nonce=base64_decode($envelope['nonce']??'',true); $cipher=base64_decode($envelope['cipher']??'',true);
    if ($salt===false || strlen($salt)!==16 || $nonce===false || strlen($nonce)!==24 || $cipher===false || strlen($cipher)<16) { throw new RuntimeException('Повреден архив.'); }
    $key=hash_pbkdf2('sha256',$password,$salt,310000,32,true);
    $plain=sodium_crypto_secretbox_open($cipher,$nonce,$key); sodium_memzero($key);
    if ($plain===false) { throw new RuntimeException('Грешна парола или повреден архив.'); }
    $data=json_decode($plain,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($data) || !is_array($data['servers']??null) || !is_array($data['jobs']??null) || count($data['servers'])>500 || count($data['jobs'])>1000) { throw new RuntimeException('Невалидна конфигурация.'); }
    return $data;
}

function exportConfiguration(string $password): string {
    $servers=query('SELECT * FROM servers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach($servers as &$s) { $s['password']=unseal($s['secret']); unset($s['secret']); } unset($s);
    return packConfiguration(['servers'=>$servers,'jobs'=>query('SELECT name,source_id,target_id,tables_json,schedule FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)],$password);
}

function importConfiguration(string $archive, string $password): array {
    global $db;
    $data=unpackConfiguration($archive,$password); $ids=[];
    $text=function(array $row,string $key): string { if (!isset($row[$key]) || !is_string($row[$key])) { throw new RuntimeException('Невалидно поле: '.$key); } return $row[$key]; };
    $db->beginTransaction();
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS imports (archive_hash TEXT PRIMARY KEY, imported_at TEXT NOT NULL)');
        $claimed=query('INSERT OR IGNORE INTO imports(archive_hash,imported_at) VALUES(?,?)',[hash('sha256',$archive),date('c')]);
        if (!$claimed->rowCount()) { $db->commit(); return [0,0]; }
        foreach($data['servers'] as $s) {
            if (!is_array($s)) { throw new RuntimeException('Невалидна връзка.'); }
            $old=$s['id']??null;
            if (!is_int($old) || $old<1 || isset($ids[$old])) { throw new RuntimeException('Невалиден идентификатор на връзка.'); }
            $name=$text($s,'name'); $host=$text($s,'host'); $schema=$text($s,'dbname'); $port=$s['port']??0;
            if (!$name || !$host || !$schema || !is_int($port) || $port<1 || $port>65535 || preg_match('/[;\r\n]/',$host.$schema)) { throw new RuntimeException('Невалидна връзка.'); }
            query('INSERT INTO servers(name,host,port,dbname,username,secret) VALUES(?,?,?,?,?,?)',[$name,$host,$port,$schema,$text($s,'username'),seal($text($s,'password'))]);
            $ids[$old]=(int)$db->lastInsertId();
        }
        foreach($data['jobs'] as $j) {
            if (!is_array($j)) { throw new RuntimeException('Невалидна задача.'); }
            $source=$j['source_id']??null; $target=$j['target_id']??null;
            if (!is_int($source) || !is_int($target) || !isset($ids[$source],$ids[$target]) || $source===$target) { throw new RuntimeException('Невалидни връзки в задача.'); }
            $name=$text($j,'name'); $tables=$text($j,'tables_json'); $patterns=json_decode($tables,true,16,JSON_THROW_ON_ERROR);
            if (!$name || !is_array($patterns) || !$patterns) { throw new RuntimeException('Невалидна задача.'); }
            foreach($patterns as $p) { if (!is_string($p) || trim($p)==='') { throw new RuntimeException('Невалиден шаблон.'); } }
            $schedule=$text($j,'schedule');
            $schedule=normalizeSchedule($schedule);
            query('INSERT INTO jobs(name,source_id,target_id,tables_json,schedule,enabled) VALUES(?,?,?,?,?,0)',[$name,$ids[$source],$ids[$target],$tables,$schedule]);
        }
        $db->commit(); return [count($data['servers']),count($data['jobs'])];
    } catch(Throwable $e) { $db->rollBack(); throw $e; }
}
