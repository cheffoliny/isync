<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__ . '/../app/TableSelection.php';
$tables = ['products','sales_docs_202601','sales_docs_202602','salesXdocs_202601','a%b','axb','a.b','__sync_new_leftover'];
$cases = [
    [['sales_docs_2026*'], ['sales_docs_202601','sales_docs_202602']],
    [['products','sales_docs_2026*','sales_docs_202601'], ['products','sales_docs_202601','sales_docs_202602']],
    [['a%b'], ['a%b']],
    [['a.b'], ['a.b']],
    [['sales_docs_202601'], ['sales_docs_202601']],
];
foreach ($cases as [$patterns,$expected]) { if (selectTables($patterns,$tables) !== $expected) { throw new RuntimeException('Unexpected selection: '.json_encode($patterns)); } }
$all = selectTables(['*'],$tables);
if (count($all)!==7 || in_array('__sync_new_leftover',$all,true)) { throw new RuntimeException('All-table selection failed'); }
foreach ([['missing'], ['products','missing'], []] as $patterns) {
    $failed=false;
    try { selectTables($patterns,$tables); } catch (RuntimeException $e) { $failed=true; }
    if (!$failed) { throw new RuntimeException('Expected unmatched-pattern failure'); }
}
foreach ([['missing*'],['Sales_docs*'],['__sync_*']] as $patterns) {
    if (selectTables($patterns,$tables)!==[]) { throw new RuntimeException('Unmatched wildcard should be skipped'); }
}
if (selectTables(['products','sales_2027*'],$tables)!==['products']) { throw new RuntimeException('Unmatched wildcard blocked existing match'); }
if (selectTables(['*'],[])!==[]) { throw new RuntimeException('Empty source should be skipped'); }
echo "PASS: matching, literal names, duplicates, future/empty wildcards, mixed selections and missing exact-name errors. No database connections or writes.\n";
