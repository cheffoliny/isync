<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__.'/../app/Schema.php';
function ident(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
$base=['COLUMN_NAME'=>'created_time','COLUMN_TYPE'=>'timestamp','EXTRA'=>'','GENERATION_EXPRESSION'=>''];
$target=$base; $target['EXTRA']='DEFAULT_GENERATED';
if (columnDifferences([$base],[$target]) || isGeneratedColumn($target)) { throw new RuntimeException('Expression default treated as generated column'); }
$base['EXTRA']='on update CURRENT_TIMESTAMP'; $target['EXTRA']='DEFAULT_GENERATED on update CURRENT_TIMESTAMP';
if (columnDifferences([$base],[$target])) { throw new RuntimeException('Default annotation normalization failed'); }
$target['EXTRA']='DEFAULT_GENERATED';
if (!columnDifferences([$base],[$target])) { throw new RuntimeException('Real EXTRA difference ignored'); }
foreach(['VIRTUAL GENERATED','STORED GENERATED'] as $extra) { if (!isGeneratedColumn(['EXTRA'=>$extra])) { throw new RuntimeException('Generated column accepted for insertion'); } }
if (!isGeneratedColumn(['EXTRA'=>'','GENERATION_EXPRESSION'=>'`id` + 1'])) { throw new RuntimeException('Generation expression ignored'); }
echo "PASS: default annotations, writable timestamp, real EXTRA differences, virtual/stored generated columns.\n";
$ddl="CREATE TABLE `buy``docs` (\n `id` int NOT NULL,\n `note` varchar(40) DEFAULT 'buy_docs',\n PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8";
$rewritten=stagingCreateSql($ddl,'__sync_new_test');
if ($rewritten!==str_replace('CREATE TABLE `buy``docs`','CREATE TABLE `__sync_new_test`',$ddl)) { throw new RuntimeException('DDL rewrite changed table definition'); }
$clauses=tableSwapClauses(['existing'=>['stage1','old1'],'missing'=>['stage2',null]]);
if ($clauses!==['`existing` TO `old1`','`stage1` TO `existing`','`stage2` TO `missing`']) { throw new RuntimeException('Mixed existing/missing swap failed'); }
echo "PASS: source DDL preservation and atomic swap plan for existing and missing targets.\n";
