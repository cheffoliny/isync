<?php
declare(strict_types=1);

function compatibleTriggerSqlMode(string $mode, string $targetVersion): string {
    if (stripos($targetVersion,'MariaDB')!==false || !preg_match('/^(\d+)\./',$targetVersion,$match) || (int)$match[1]<8) { return $mode; }
    return implode(',',array_values(array_filter(array_map('trim',explode(',',$mode)),fn($item)=>$item!=='' && strcasecmp($item,'NO_AUTO_CREATE_USER')!==0)));
}

function prepareTriggerModes(PDO $target, array $triggers): array {
    if (!$triggers) { return []; }
    $version=(string)$target->query('SELECT VERSION()')->fetchColumn();
    $original=(string)$target->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    try {
        foreach ($triggers as &$trigger) {
            $trigger['sql_mode']=compatibleTriggerSqlMode($trigger['sql_mode'],$version);
            // Reject incompatible modes before copying or publishing any table.
            $target->exec('SET SESSION sql_mode='.$target->quote($trigger['sql_mode']));
        }
        unset($trigger);
    } finally { $target->exec('SET SESSION sql_mode='.$target->quote($original)); }
    return $triggers;
}

function readTriggers(PDO $source, string $schema, array $tables): array {
    $statement=$source->prepare('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? ORDER BY EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_ORDER');
    $statement->execute([$schema]); $triggers=[];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!in_array($row['EVENT_OBJECT_TABLE'],$tables,true)) { continue; }
        $definition=$source->query('SHOW CREATE TRIGGER '.ident($schema).'.'.ident($row['TRIGGER_NAME']))->fetch(PDO::FETCH_ASSOC);
        if (empty($definition['SQL Original Statement'])) { throw new RuntimeException('Не може да се прочете тригер '.$row['TRIGGER_NAME'].'. Проверете правото TRIGGER на източника.'); }
        $triggers[]=['name'=>$row['TRIGGER_NAME'],'table'=>$row['EVENT_OBJECT_TABLE'],'sql'=>$definition['SQL Original Statement'],'sql_mode'=>$definition['sql_mode'],'charset'=>$definition['character_set_client'],'collation'=>$definition['collation_connection']];
    }
    return $triggers;
}

function installTriggers(PDO $target, array $triggers): void {
    $triggers=prepareTriggerModes($target,$triggers);
    $settings=$target->query('SELECT @@SESSION.sql_mode AS sql_mode,@@SESSION.character_set_client AS charset,@@SESSION.collation_connection AS collation')->fetch(PDO::FETCH_ASSOC);
    try {
        foreach ($triggers as $trigger) {
            try {
                $target->exec('SET SESSION sql_mode='.$target->quote($trigger['sql_mode']));
                $target->exec('SET SESSION character_set_client='.$target->quote($trigger['charset']));
                $target->exec('SET SESSION collation_connection='.$target->quote($trigger['collation']));
                // Preserve original body and DEFINER; never run trigger bodies ourselves.
                $target->exec($trigger['sql']);
            } catch (Throwable $e) { throw new RuntimeException('Данните са прехвърлени, но тригер '.$trigger['name'].' не е създаден: '.$e->getMessage(),0,$e); }
        }
    } finally {
        $target->exec('SET SESSION sql_mode='.$target->quote($settings['sql_mode']));
        $target->exec('SET SESSION character_set_client='.$target->quote($settings['charset']));
        $target->exec('SET SESSION collation_connection='.$target->quote($settings['collation']));
    }
}
