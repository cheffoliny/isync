<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Sofia');
require_once __DIR__ . '/Schedule.php';
// Default storage is outside Apache's document root.
$local = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$storage = getenv('SYNC_STORAGE') ?: ($local['storage'] ?? dirname(__DIR__, 3) . '/sync-private');
if (!is_dir($storage) && !mkdir($storage, 0700, true)) { throw new RuntimeException('Cannot create private storage'); }
define('STORAGE', $storage);
$db = new PDO('sqlite:' . STORAGE . '/sync.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout=10000');
$db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT); CREATE TABLE IF NOT EXISTS servers(id INTEGER PRIMARY KEY, name TEXT, host TEXT, port INTEGER, dbname TEXT, username TEXT, secret TEXT); CREATE TABLE IF NOT EXISTS jobs(id INTEGER PRIMARY KEY, name TEXT, source_id INTEGER, target_id INTEGER, tables_json TEXT, schedule TEXT, enabled INTEGER DEFAULT 1, last_slot TEXT); CREATE TABLE IF NOT EXISTS runs(id INTEGER PRIMARY KEY, job_id INTEGER, started TEXT, finished TEXT, status TEXT, details TEXT); CREATE TABLE IF NOT EXISTS requests(id INTEGER PRIMARY KEY, job_id INTEGER, created TEXT); CREATE TABLE IF NOT EXISTS attempts(ip TEXT, stamp INTEGER);');
if (!is_file(STORAGE . '/key')) { $h = @fopen(STORAGE . '/key', 'x'); if ($h) { fwrite($h, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); fclose($h); } }
$db->exec("CREATE INDEX IF NOT EXISTS runs_retention_finished ON runs(CAST(strftime('%s',finished) AS INTEGER)) WHERE finished IS NOT NULL AND status <> 'running'");
function seal(string $value): string { $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, file_get_contents(STORAGE . '/key'))); }
function unseal(string $value): string { $raw = base64_decode($value, true); if ($raw === false) { throw new RuntimeException('Invalid secret'); } $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), file_get_contents(STORAGE . '/key')); if ($plain === false) { throw new RuntimeException('Invalid secret'); } return $plain; }
function query(string $sql, array $args = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($args); return $s; }
function server(int $id): array { $s = query('SELECT * FROM servers WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC); if (!$s) { throw new RuntimeException('Липсва сървър.'); } return $s; }
function connect(array $s): PDO { if (preg_match('/[;\r\n]/', $s['host'] . $s['dbname'])) { throw new RuntimeException('Невалидна връзка.'); } return new PDO("mysql:host={$s['host']};port={$s['port']};dbname={$s['dbname']};charset=utf8mb4", $s['username'], unseal($s['secret']), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>15]); }
function ident(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
