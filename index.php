<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Transfer.php';
require_once __DIR__ . '/app/History.php';
ini_set('session.use_strict_mode','1');
session_name('sync_panel'); session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Strict']); session_start();
header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store'); header("Content-Security-Policy: default-src 'self'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
if (isset($_SESSION['seen']) && time()-$_SESSION['seen'] > 1800) { $_SESSION=[]; session_regenerate_id(true); }
$_SESSION['seen']=time(); $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); $message=$_SESSION['flash']??''; unset($_SESSION['flash']);
try {
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf']??''))) { throw new RuntimeException('Невалидна сесия. Презаредете страницата.'); }
    $action=$_POST['action']??'';
    if ($action==='login') {
        $ip=$_SERVER['REMOTE_ADDR']??'unknown'; query('DELETE FROM attempts WHERE stamp<?',[time()-900]);
        if (query('SELECT COUNT(*) FROM attempts WHERE ip=?',[$ip])->fetchColumn()>=5) { throw new RuntimeException('Твърде много опити. Опитайте след 15 минути.'); }
        $user=query('SELECT * FROM users WHERE username=?',[trim($_POST['username']??'')])->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($_POST['password']??'', $user['password'])) { query('INSERT INTO attempts VALUES(?,?)',[$ip,time()]); throw new RuntimeException('Невалидно име или парола.'); }
        session_regenerate_id(true); $_SESSION['user']=$user['username']; $_SESSION['csrf']=bin2hex(random_bytes(32)); query('DELETE FROM attempts WHERE ip=?',[$ip]); header('Location: index.php'); exit;
    } else {
        if (empty($_SESSION['user'])) { throw new RuntimeException('Необходим е вход.'); }
        if ($action==='export') {
            $archive=exportConfiguration($_POST['archive_password']??'');
            header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="sync-config-'.date('Ymd-His').'.sync"');
            echo $archive; exit;
        }
        if ($action==='import') {
            if (($_FILES['archive']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($_FILES['archive']['size']??0)>2097152) { throw new RuntimeException('Изберете архив до 2 MB.'); }
            [$connections,$tasks]=importConfiguration(file_get_contents($_FILES['archive']['tmp_name']),$_POST['archive_password']??'');
            $message=$connections===0 && $tasks===0?'Архивът вече е внесен или не съдържа нови записи. Няма добавени копия.':"Добавени връзки: $connections; задачи: $tasks. Внесените задачи са спрени — проверете връзките преди включване.";
        }
        if ($action==='logout') { $_SESSION=[]; session_destroy(); header('Location: index.php'); exit; }
        if ($action==='history_settings') {
            $days=filter_var($_POST['retention_days']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3650]]);
            if ($days===false) { throw new RuntimeException('Срокът за историята трябва да е от 1 до 3650 дни.'); }
            $db->beginTransaction();
            try {
                query("INSERT INTO settings(name,value) VALUES('history_retention_days',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value",[(string)$days]);
                query("DELETE FROM settings WHERE name='history_pruned_at'"); $db->commit();
            } catch(Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
            $message="Историята ще се пази $days дни. По-старите приключили изпълнения ще се изчистят при следващото стартиране на worker.";
        }
        if ($action==='server') {
            $name=trim($_POST['name']??''); $host=trim($_POST['host']??''); $database=trim($_POST['dbname']??''); $port=(int)($_POST['port']??3306);
            if (!$name || !$host || !$database || $port<1 || $port>65535 || preg_match('/[;\r\n]/',$host.$database)) { throw new RuntimeException('Попълнете валидни данни за сървъра.'); }
            query('INSERT INTO servers(name,host,port,dbname,username,secret) VALUES(?,?,?,?,?,?)',[$name,$host,$port,$database,trim($_POST['username']),seal($_POST['password']??'')]); $message='Връзката е запазена.';
        }
        if ($action==='test') { $pdo=connect(server((int)$_POST['id'])); $message='Успешна връзка. MySQL '.$pdo->query('SELECT VERSION()')->fetchColumn().'. Таблици: '.implode(', ',$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)); }
        if ($action==='job' || $action==='save_job') {
            $tables=array_values(array_unique(array_filter(array_map('trim',explode(',',$_POST['tables']??''))))); $schedule=trim($_POST['schedule']??'');
            $schedule=normalizeSchedule($schedule);
            $source=(int)$_POST['source_id']; $target=(int)$_POST['target_id']; server($source); server($target);
            if (!$tables || $source===$target || !trim($_POST['name'])) { throw new RuntimeException('Посочете име, таблици и различни връзки.'); }
            if ($action==='save_job') {
                $lock=fopen(STORAGE.'/worker.lock','c');
                if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) { fclose($lock); } throw new RuntimeException('Има работещ процес за синхронизация. Изчакайте да приключи, преди да запазите промените.'); }
                try {
                    $db->beginTransaction();
                    $existing=query('SELECT * FROM jobs WHERE id=?',[(int)($_POST['id']??0)])->fetch(PDO::FETCH_ASSOC);
                    if (!$existing) { throw new RuntimeException('Задачата вече не съществува.'); }
                    query('UPDATE jobs SET name=?,source_id=?,target_id=?,tables_json=?,schedule=?,last_slot=? WHERE id=?',[trim($_POST['name']),$source,$target,json_encode($tables,JSON_UNESCAPED_UNICODE),$schedule,$existing['schedule']===$schedule?$existing['last_slot']:null,$existing['id']]);
                    query('DELETE FROM requests WHERE job_id=?',[$existing['id']]);
                    $db->commit();
                    header('Location: index.php?updated=1'); exit;
                } catch(Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
                finally { flock($lock,LOCK_UN); fclose($lock); }
            } else {
                query('INSERT INTO jobs(name,source_id,target_id,tables_json,schedule) VALUES(?,?,?,?,?)',[trim($_POST['name']),$source,$target,json_encode($tables,JSON_UNESCAPED_UNICODE),$schedule]); $message='Задачата е запазена и включена.';
            }
        }
        if ($action==='toggle') { query('UPDATE jobs SET enabled=1-enabled WHERE id=?',[(int)$_POST['id']]); }
        if ($action==='delete_job') {
            $lock=fopen(STORAGE.'/worker.lock','c');
            if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) { fclose($lock); } throw new RuntimeException('Има работещ процес за синхронизация. Изчакайте да приключи и опитайте отново.'); }
            try {
                $db->beginTransaction();
                query('DELETE FROM requests WHERE job_id=?',[(int)$_POST['id']]);
                $deleted=query('DELETE FROM jobs WHERE id=?',[(int)$_POST['id']]);
                $db->commit();
                $message=$deleted->rowCount()?'Задачата е изтрита. Историята и MySQL данните са запазени.':'Задачата вече не съществува.';
            } catch(Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
            finally { flock($lock,LOCK_UN); fclose($lock); }
        }
        if ($action==='run') { if (!query('SELECT id FROM jobs WHERE id=?',[(int)$_POST['id']])->fetchColumn()) { throw new RuntimeException('Липсва задача.'); } query('INSERT INTO requests(job_id,created) SELECT ?,? WHERE NOT EXISTS(SELECT 1 FROM requests WHERE job_id=?)',[(int)$_POST['id'],date('c'),(int)$_POST['id']]); $message='Задачата е заявена. Ще се изпълни при следващото стартиране на worker процеса.'; }
        if (in_array($action,['import','server','job','toggle','delete_job','run','history_settings'],true)) {
            $_SESSION['flash']=$message; $_SESSION['csrf']=bin2hex(random_bytes(32));
            header('Location: index.php',true,303); exit;
        }
    }
}
} catch(Throwable $e) { $message=$e->getMessage(); }
if (!$message && isset($_GET['updated']) && !empty($_SESSION['user'])) { $message='Промените по задачата са запазени. Чакащите ръчни заявки са отменени. При променен график активната задача ще се изпълни при следващия настъпил час, включително дължим час от днес.'; }
function csrf(): void { echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
?><!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sync · MySQL</title><link rel="stylesheet" href="assets/style.css?v=3"></head><body><main>
<header><div><span class="eyebrow">MYSQL → MYSQL</span><h1>Sync Control</h1><p>Пълно копиране. Ясен график. Проследими резултати.</p></div><?php if(!empty($_SESSION['user'])): ?><form method="post"><?php csrf(); ?><input type="hidden" name="action" value="logout"><button class="secondary">Изход · <?=h($_SESSION['user'])?></button></form><?php endif ?></header>
<?php if($message): ?><div class="notice"><?=h($message)?></div><?php endif ?>
<?php if(empty($_SESSION['user'])): ?><section class="login"><h2>Вход в панела</h2><?php if(!query('SELECT COUNT(*) FROM users')->fetchColumn()): ?><p>Първо създайте администратор чрез <code>php bin/admin.php</code> в терминала на сървъра.</p><?php endif ?><form method="post"><?php csrf(); ?><input type="hidden" name="action" value="login"><label>Потребител<input name="username" autocomplete="username" required></label><label>Парола<input name="password" type="password" autocomplete="current-password" required></label><button>Вход</button></form></section>
<?php else: $servers=query('SELECT * FROM servers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); $jobs=query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); ?>
<?php
$editId=(int)($_GET['edit_job']??0);
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_job' && hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { $editId=(int)($_POST['id']??0); }
$editing=$editId?query('SELECT * FROM jobs WHERE id=?',[$editId])->fetch(PDO::FETCH_ASSOC):false;
if ($editing) {
    $values=['name'=>$editing['name'],'source_id'=>$editing['source_id'],'target_id'=>$editing['target_id'],'tables'=>implode(', ',json_decode($editing['tables_json'],true)),'schedule'=>$editing['schedule']];
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_job' && hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { foreach($values as $key=>$_) { $values[$key]=(string)($_POST[$key]??''); } }
?>
<section id="edit-job"><h2>Редакция на задача „<?=h($editing['name'])?>“</h2><p class="muted">Състоянието включена/спряна и историята се запазват. Чакащите ръчни заявки се отменят при запазване. Промяна на графика може да направи задачата дължима още при следващото стартиране на процеса.</p><form method="post" class="grid"><?php csrf(); ?><input type="hidden" name="action" value="save_job"><input type="hidden" name="id" value="<?=$editing['id']?>"><label>Име<input name="name" value="<?=h($values['name'])?>" required></label><?php foreach(['source_id'=>'Източник','target_id'=>'Цел'] as $field=>$label): ?><label><?=$label?><select name="<?=$field?>" required><option value="">Избери връзка</option><?php foreach($servers as $s): ?><option value="<?=$s['id']?>" <?=(string)$s['id']===(string)$values[$field]?'selected':''?>><?=h($s['name'].' / '.$s['dbname'])?></option><?php endforeach ?></select></label><?php endforeach ?><label>Таблици или шаблони, разделени със запетая<input name="tables" value="<?=h($values['tables'])?>" required><small>* — всички таблици; sales_docs_2026* — по начало на името.</small></label><label>График: 2 minutes, 3 minutes или 08:00,20:00<input name="schedule" value="<?=h(scheduleInput($values['schedule']))?>" required></label><div><button>Запази промените</button> <a class="cancel-link" href="index.php">Отказ</a></div></form></section>
<?php } elseif ($editId) { ?><div class="notice">Задачата вече не съществува.</div><?php } ?>
<?php if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='confirm_delete' && hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))): $toDelete=query('SELECT id,name FROM jobs WHERE id=?',[(int)($_POST['id']??0)])->fetch(PDO::FETCH_ASSOC); if ($toDelete): ?>
<section class="delete-confirm"><h2>Изтриване на задача „<?=h($toDelete['name'])?>“?</h2><p>Задачата и чакащите заявки за изпълнение ще бъдат премахнати. Историята, връзките и данните в MySQL се запазват.</p><form method="post"><?php csrf(); ?><input type="hidden" name="id" value="<?=$toDelete['id']?>"><button class="danger" name="action" value="delete_job">Потвърди изтриването</button> <a class="cancel-link" href="index.php">Отказ</a></form></section>
<?php endif; endif ?>
<div class="stats"><article><strong><?=count($servers)?></strong> сървърни връзки</article><article><strong><?=count($jobs)?></strong> задачи</article><article><strong>А → Б</strong> еднопосочно копиране</article></div>
<section><h2>Съхранение на историята</h2><p class="muted">Показват се последните 50 изпълнения. Worker изчиства старите приключили записи веднъж на 24 часа. Активните изпълнения се запазват. Намаляването на срока води до окончателно изтриване на по-старата история.</p><form method="post"><?php csrf(); ?><input type="hidden" name="action" value="history_settings"><label>Срок на съхранение в дни (1–3650)<input type="number" name="retention_days" min="1" max="3650" value="<?=historyRetentionDays($db)?>" required></label><button>Запази срока</button></form></section>
<section><h2>Пренасяне на конфигурацията</h2><p class="muted">Архивът съдържа връзките с MySQL паролите и задачите. Защитен е с отделна парола. При внос добавяме нови записи; съществуващите се запазват. Новите задачи са спрени. Потребителите и историята не се пренасят.</p><div class="cards"><article><h3>Износ</h3><form method="post"><?php csrf(); ?><input type="hidden" name="action" value="export"><label>Парола за архива (поне 12 символа)<input type="password" name="archive_password" minlength="12" required autocomplete="new-password"></label><button>Изтегли конфигурацията</button></form></article><article><h3>Внос</h3><form method="post" enctype="multipart/form-data"><?php csrf(); ?><input type="hidden" name="action" value="import"><label>Архив .sync (до 2 MB)<input type="file" name="archive" accept=".sync" required></label><label>Парола за архива<input type="password" name="archive_password" required autocomplete="off"></label><button>Добави конфигурацията</button></form></article></div></section>
<section><h2>Сървъри</h2><p class="muted">Всяка връзка сочи към една база данни. Паролите се пазят криптирано.</p><div class="cards"><?php foreach($servers as $s): ?><article><h3><?=h($s['name'])?></h3><p><?=h($s['host'])?>:<?=h($s['port'])?> / <?=h($s['dbname'])?></p><form method="post"><?php csrf(); ?><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=$s['id']?>"><button class="secondary">Провери връзката и таблиците</button></form></article><?php endforeach ?></div>
<details><summary>Добави сървърна връзка</summary><form method="post" class="grid"><?php csrf(); ?><input type="hidden" name="action" value="server"><label>Име<input name="name" required placeholder="Източник"></label><label>Адрес<input name="host" required placeholder="127.0.0.1"></label><label>Порт<input name="port" type="number" value="3306" min="1" max="65535" required></label><label>База данни<input name="dbname" required></label><label>MySQL потребител<input name="username" required autocomplete="off"></label><label>MySQL парола<input name="password" type="password" autocomplete="new-password"></label><button>Запази връзката</button></form></details></section>
<section><h2>Задачи за синхронизация</h2><p class="muted">Целевите данни се заменят изцяло. Структурата, колоните, индексите и кодировката се вземат от източника. Таблиците на източника трябва да са InnoDB. Шаблони без съвпадения се пропускат.</p><?php foreach($jobs as $job): ?><article class="job"><div><h3><?=h($job['name'])?> <span class="badge"><?=$job['enabled']?'Включена':'Спряна'?></span></h3><p><?=h(implode(', ',json_decode($job['tables_json'],true)))?> · <?=h(scheduleLabel($job['schedule']))?></p></div><form method="post"><?php csrf(); ?><input type="hidden" name="id" value="<?=$job['id']?>"><a class="edit-button" href="index.php?edit_job=<?=$job['id']?>#edit-job">Редактирай</a> <button name="action" value="run">Заяви изпълнение</button> <button class="<?=$job['enabled']?'danger':'enable'?>" name="action" value="toggle"><?=$job['enabled']?'Спри':'Включи'?></button> <button class="delete-button" name="action" value="confirm_delete">Изтрий</button></form></article><?php endforeach ?>
<details><summary>Добави задача</summary><form method="post" class="grid"><?php csrf(); ?><input type="hidden" name="action" value="job"><label>Име<input name="name" required></label><?php foreach(['source_id'=>'Източник','target_id'=>'Цел'] as $field=>$label): ?><label><?=$label?><select name="<?=$field?>" required><option value="">Избери връзка</option><?php foreach($servers as $s): ?><option value="<?=$s['id']?>"><?=h($s['name'].' / '.$s['dbname'])?></option><?php endforeach ?></select></label><?php endforeach ?><label>Таблици или шаблони, разделени със запетая<input name="tables" required placeholder="* или products, sales_docs_2026*"><small>* — всички таблици; sales_docs_2026* — по начало на името.</small></label><label>График: 2 minutes, 3 minutes или 08:00,20:00<input name="schedule" value="minute" required></label><button>Създай задача</button></form></details></section>
<section><h2>Последни изпълнения</h2><div class="scroll"><table><thead><tr><th>Задача</th><th>Начало</th><th>Край</th><th>Статус</th><th>Резултат</th></tr></thead><tbody><?php foreach(query('SELECT runs.*,jobs.name FROM runs LEFT JOIN jobs ON jobs.id=runs.job_id ORDER BY runs.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC) as $r): ?><tr><td><?=h($r['name'] ?? 'Изтрита задача #'.$r['job_id'])?></td><td><?=h($r['started'])?></td><td><?=h($r['finished'])?></td><td><span class="badge <?=h($r['status'])?>"><?=h($r['status'])?></span></td><td><?=h($r['details'])?></td></tr><?php endforeach ?></tbody></table></div></section><p class="muted">Графиците са по Europe/Sofia. Worker: <code>php bin/worker.php</code>. Панелът не стартира прехвърляния в HTTP заявката.</p>
<?php endif ?></main></body></html>
