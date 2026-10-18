<?php
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__ . '/../app/bootstrap.php';
echo "Username: "; $name = trim(fgets(STDIN));
echo "Password (visible in this terminal): "; $password = trim(fgets(STDIN));
if (!$name || strlen($password) < 12) { fwrite(STDERR,"Use a username and password with at least 12 characters.\n"); exit(1); }
query('INSERT INTO users(username,password) VALUES(?,?) ON CONFLICT(username) DO UPDATE SET password=excluded.password',[$name,password_hash($password,PASSWORD_DEFAULT)]);
echo "Administrator saved.\n";
