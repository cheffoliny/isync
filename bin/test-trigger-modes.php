<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit; }
require __DIR__.'/../app/Triggers.php';
$cases=[
    ['STRICT_TRANS_TABLES,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION','8.4.7','STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'],
    ['NO_AUTO_CREATE_USER','8.0.40',''],
    ['no_auto_create_user, ANSI_QUOTES','8.4.7','ANSI_QUOTES'],
    ['NO_AUTO_CREATE_USER,STRICT_ALL_TABLES','5.7.23-log','NO_AUTO_CREATE_USER,STRICT_ALL_TABLES'],
    ['NO_AUTO_CREATE_USER','10.11.0-MariaDB','NO_AUTO_CREATE_USER'],
    ['ANSI_QUOTES,NO_BACKSLASH_ESCAPES','8.4.7','ANSI_QUOTES,NO_BACKSLASH_ESCAPES'],
];
foreach($cases as [$mode,$version,$expected]) { if (compatibleTriggerSqlMode($mode,$version)!==$expected) { throw new RuntimeException('Incorrect trigger SQL mode translation'); } }
echo "PASS: MySQL 8 removed mode filtered; all other modes preserved; MySQL 5.7/MariaDB unchanged.\n";
