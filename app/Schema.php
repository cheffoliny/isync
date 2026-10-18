<?php
declare(strict_types=1);

function normalizeColumns(array $rows): array {
    foreach ($rows as &$row) {
        $row['COLUMN_TYPE'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i', '$1', $row['COLUMN_TYPE']);
        // MySQL 8 annotates expression defaults; these are still writable columns.
        $row['EXTRA'] = trim(preg_replace('/\s+/', ' ', preg_replace('/\bDEFAULT_GENERATED\b/i', '', $row['EXTRA'] ?? '')));
        if (($row['CHARACTER_SET_NAME'] ?? null) === 'utf8') { $row['CHARACTER_SET_NAME'] = 'utf8mb3'; }
        if (isset($row['COLLATION_NAME'])) { $row['COLLATION_NAME'] = preg_replace('/^utf8_/', 'utf8mb3_', $row['COLLATION_NAME']); }
    }
    return $rows;
}

function isGeneratedColumn(array $column): bool {
    return trim((string)($column['GENERATION_EXPRESSION'] ?? '')) !== ''
        || preg_match('/\b(?:VIRTUAL|STORED) GENERATED\b/i', $column['EXTRA'] ?? '') === 1;
}

function stagingCreateSql(string $ddl, string $stage): string {
    $sql=preg_replace_callback('/\ACREATE TABLE `(?:``|[^`])+`/', fn()=> 'CREATE TABLE '.ident($stage), $ddl, 1, $replaced);
    if ($replaced!==1) { throw new RuntimeException('Неподдържана дефиниция на таблица.'); }
    return $sql;
}

function tableSwapClauses(array $mapping): array {
    $clauses=[];
    foreach ($mapping as $table=>[$stage,$old]) {
        if ($old!==null) { $clauses[]=ident((string)$table).' TO '.ident($old); }
        $clauses[]=ident($stage).' TO '.ident((string)$table);
    }
    return $clauses;
}

function columnDifferences(array $source, array $target): array {
    $source=normalizeColumns($source); $target=normalizeColumns($target); $differences=[];
    foreach ($source as $i=>$column) {
        if (!isset($target[$i])) { $differences[]='Липсва целева колона: '.$column['COLUMN_NAME']; continue; }
        foreach ($column as $field=>$value) {
            if ($value !== ($target[$i][$field]??null)) { $differences[]=$column['COLUMN_NAME'].'.'.$field.': '.json_encode($value,JSON_UNESCAPED_UNICODE).' → '.json_encode($target[$i][$field]??null,JSON_UNESCAPED_UNICODE); }
        }
    }
    if (count($target)>count($source)) { $differences[]='Целта има допълнителни колони.'; }
    return $differences;
}
