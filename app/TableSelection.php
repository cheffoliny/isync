<?php
declare(strict_types=1);

/** Only * is special; underscores, percent signs and regex characters are literal. */
function selectTables(array $patterns, array $available): array {
    if (!$patterns) { throw new RuntimeException('Посочете таблици или шаблон *.'); }
    sort($available, SORT_STRING);
    $selected = [];
    foreach ($patterns as $pattern) {
        if (!is_string($pattern) || trim($pattern) === '') { throw new RuntimeException('Невалиден шаблон за таблици.'); }
        $regex = '~\A' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . '\z~sD';
        $matches = [];
        foreach ($available as $table) {
            // Never copy leftovers from our own staging operations.
            if (str_starts_with(strtolower($table), '__sync_')) { continue; }
            if (preg_match($regex, $table) === 1) { $matches[] = $table; }
        }
        if (!$matches && !str_contains($pattern, '*')) { throw new RuntimeException('Няма таблица в източника: ' . $pattern); }
        foreach ($matches as $table) { $selected[$table] = $table; }
    }
    return array_values($selected);
}

function resolveTables(PDO $source, string $schema, array $patterns): array {
    $statement = $source->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    $statement->execute([$schema]);
    return selectTables($patterns, $statement->fetchAll(PDO::FETCH_COLUMN));
}
