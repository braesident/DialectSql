<?php

declare(strict_types=1);

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\SqlBuilder;
use Braesident\DialectSql\SqlRenderer;

$upsert = [
  'type' => 'upsert', 'table' => 'products', 'conflictColumns' => ['sku'],
  'values' => [['sku' => 'A', 'name' => 'Öl', 'stock' => 5]],
  'set' => [
    'name' => ['incoming' => 'name'],
    'stock' => ['op' => '+', 'left' => ['col' => 'stock'], 'right' => ['incoming' => 'stock']]
  ],
  'mysqlConflict' => 'any_unique'
];
$expected = [
  'mysql' => "INSERT INTO `products` (`sku`, `name`, `stock`) VALUES ('A', 'Öl', 5) AS `__dialectsql_incoming` ON DUPLICATE KEY UPDATE `name` = `__dialectsql_incoming`.`name`, `stock` = `stock` + `__dialectsql_incoming`.`stock`",
  'pgsql' => 'INSERT INTO "products" AS "__dialectsql_target" ("sku", "name", "stock") VALUES (\'A\', \'Öl\', 5) ON CONFLICT ("sku") DO UPDATE SET "name" = "excluded"."name", "stock" = "__dialectsql_target"."stock" + "excluded"."stock"',
  'sqlsrv' => 'MERGE INTO [dbo].[products] WITH (HOLDLOCK) AS [__dialectsql_target] USING (VALUES (N\'A\', N\'Öl\', 5)) AS [__dialectsql_incoming] ([sku], [name], [stock]) ON [__dialectsql_target].[sku] = [__dialectsql_incoming].[sku] WHEN MATCHED THEN UPDATE SET [name] = [__dialectsql_incoming].[name], [stock] = [__dialectsql_target].[stock] + [__dialectsql_incoming].[stock] WHEN NOT MATCHED THEN INSERT ([sku], [name], [stock]) VALUES ([__dialectsql_incoming].[sku], [__dialectsql_incoming].[name], [__dialectsql_incoming].[stock]);'
];
foreach (['mysql', 'sqlsrv', 'pgsql'] as $dialect) {
  sameSql($expected[$dialect], SqlBuilder::build($upsert, $dialect), 'Upsert muss Incoming- und Bestandswerte für '.$dialect.' unterscheiden.');
  sameSql($expected[$dialect], SqlBuilder::build(json_encode($upsert), $dialect), 'JSON-Upserts müssen dieselbe SQL-Struktur erzeugen.');
  sameSql($expected[$dialect], SqlBuilder::build(json_decode(json_encode($upsert)), $dialect), 'Verschachtelte Upsert-Objekte müssen funktionieren.');
  $renderer = new SqlRenderer(new DialectConnection($dialect));
  sameSql($expected[$dialect], $renderer->render($upsert), 'Renderer muss Upserts direkt verarbeiten.');
  sameSql([$expected[$dialect]], $renderer->renderAll(['builder' => 'sql', 'definition' => $upsert]), 'Renderer muss Upsert-Builder-Steps verarbeiten.');
  $positional = $upsert;
  $positional['columns'] = ['sku', 'name', 'stock'];
  $positional['values'] = [['A', 'Öl', 5]];
  sameSql($expected[$dialect], SqlBuilder::build($positional, $dialect), 'Positionale Upsert-Zeilen müssen mit expliziten Spalten funktionieren.');
  $one = $upsert;
  $one['values'] = $one['values'][0];
  sameSql($expected[$dialect], SqlBuilder::build($one, $dialect), 'Eine einzelne Objektzeile muss verwendbar sein.');
  $alias = $upsert;
  $alias['conflict_columns'] = $alias['conflictColumns'];
  $alias['mysql_conflict'] = $alias['mysqlConflict'];
  $alias['rows'] = $alias['values'];
  unset($alias['conflictColumns'], $alias['mysqlConflict'], $alias['values']);
  sameSql($expected[$dialect], SqlBuilder::build($alias, $dialect), 'Bestehende Feldnormalisierung und rows müssen auch für Upsert gelten.');

  $multi = $upsert;
  $multi['values'][] = ['stock' => 7, 'name' => 'Wasser', 'sku' => 'B'];
  $sql = SqlBuilder::build($multi, $dialect);
  $row = 'sqlsrv' === $dialect ? "(N'B', N'Wasser', 7)" : "('B', 'Wasser', 7)";
  check(str_contains($sql, ', '.$row), 'Mehrzeilen-Upsert muss Objektspalten in definierter Reihenfolge rendern.');
  $composite = ['type' => 'upsert', 'table' => 'stock', 'schema' => 'shop', 'conflictColumns' => ['tenant', 'sku'], 'values' => [['tenant' => 7, 'sku' => 'A', 'amount' => 1]], 'set' => ['amount' => SelectBuilder::incoming('amount')], 'mysqlConflict' => 'any_unique'];
  $sql = SqlBuilder::build($composite, $dialect);
  if ('sqlsrv' === $dialect) {
    check(str_contains($sql, 'MERGE INTO [shop].[stock]') && str_contains($sql, '[__dialectsql_target].[tenant] = [__dialectsql_incoming].[tenant] AND [__dialectsql_target].[sku] = [__dialectsql_incoming].[sku]'), 'MERGE muss Schema und alle Konfliktspalten verwenden.');
  } elseif ('pgsql' === $dialect) {
    check(str_contains($sql, 'INSERT INTO "shop"."stock"') && str_contains($sql, 'ON CONFLICT ("tenant", "sku")'), 'PostgreSQL muss zusammengesetzte Konfliktziele verwenden.');
  } else {
    check(str_contains($sql, 'ON DUPLICATE KEY UPDATE'), 'MySQL benötigt ausdrücklich den Any-Unique-Modus.');
  }

  $params = $upsert;
  $params['values'] = [['sku' => ['param' => 'sku'], 'name' => ['param' => 'name'], 'stock' => ['param' => 'stock']]];
  $sql = SqlBuilder::build($params, $dialect);
  foreach (['sku', 'name', 'stock'] as $param) {
    check(1 === substr_count($sql, ':'.$param), 'Jeder Eingabeparameter muss trotz Verwendung im UPDATE nur einmal vorkommen.');
  }

  $expression = $upsert;
  $expression['set'] = [
    'name' => ['fn' => 'coalesce', 'args' => [['incoming' => 'name'], ['col' => 'name']]],
    'stock' => ['case' => ['when' => [['cond' => [['incoming' => 'stock'], '>', 0], 'then' => ['incoming' => 'stock']]], 'else' => ['col' => 'stock']]]
  ];
  $sql = SqlBuilder::build($expression, $dialect);
  check(str_contains($sql, 'COALESCE(') && str_contains($sql, 'CASE WHEN'), 'Incoming-Werte müssen in verschachtelten Funktionen und CASE verwendbar sein.');
  $literal = $upsert;
  $literal['set'] = ['name' => 'incoming', 'stock' => null];
  $sql = SqlBuilder::build($literal, $dialect);
  check(str_contains($sql, "'incoming'") && str_contains($sql, '= NULL'), 'SET-Strings und NULL müssen ihre Literalbedeutung behalten.');

  foreach (['conflictColumns', 'values', 'set'] as $missing) {
    $invalid = $upsert;
    unset($invalid[$missing]);
    rejectsSql(static fn () => SqlBuilder::build($invalid, $dialect), 'Erforderliche Upsert-Felder müssen geprüft werden.', $missing);
  }
  foreach ([[], ['missing'], ['sku', 'sku'], ['p.sku'], [null]] as $key) {
    rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['conflictColumns' => $key]), $dialect), 'Ungültige Konfliktspalten müssen fehlschlagen.', 'upsert');
  }
  foreach ([[], ['sku', 'sku', 'stock'], ['sku', [], 'stock'], ['sku', 1, 'stock']] as $columns) {
    rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['columns' => $columns]), $dialect), 'Ungültige Eingabespalten müssen ohne PHP-Warnung fehlschlagen.', 'upsert');
  }
  foreach ([null, ['value' => null], ['val' => ['literal' => null]]] as $null) {
    $invalid = $upsert;
    $invalid['values'][0]['sku'] = $null;
    rejectsSql(static fn () => SqlBuilder::build($invalid, $dialect), 'Bekannte NULL-Konfliktschlüssel sind nicht portabel.', 'NULL');
  }
  $duplicate = $upsert;
  $duplicate['values'][] = ['sku' => ['value' => 'A'], 'name' => 'doppelt', 'stock' => 2];
  rejectsSql(static fn () => SqlBuilder::build($duplicate, $dialect), 'Ein Upsert darf denselben bekannten Quellschlüssel nicht mehrfach enthalten.', 'doppelte Konfliktschlüssel');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => ['sku' => ['incoming' => 'sku']]]), $dialect), 'Upsert darf seinen Konfliktschlüssel nicht ändern.', 'conflictColumns');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => []]), $dialect), 'Leeres SET darf keine uneinheitliche DO-NOTHING-Emulation auslösen.', 'set');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => ['stock' => []]]), $dialect), 'Leere SET-Ausdrücke dürfen kein ungültiges SQL erzeugen.', 'leeren');
  $emptyCell = $upsert;
  $emptyCell['values'][0]['name'] = [];
  rejectsSql(static fn () => SqlBuilder::build($emptyCell, $dialect), 'Leere Eingabeausdrücke müssen fehlschlagen.', 'leeren');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => [['stock', 1], ['stock', 2]]]), $dialect), 'Doppelte Zielzuweisungen müssen fehlschlagen.', 'doppelte Spalten');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => ['stock' => ['incoming' => 'missing']]]), $dialect), 'Incoming muss eine vorhandene Quellspalte bezeichnen.', 'incoming');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => ['stock' => ['col' => 'p.stock']]]), $dialect), 'Portable Bestandsreferenzen sind unqualifiziert.', 'unqualifizierten');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => ['stock' => ['query' => ['select' => ['value' => 1]]]]]), $dialect), 'Unterabfragen im SET benötigen eine bewusste native Definition.', 'Unterabfragen');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => ['stock' => ['expr' => (new SelectBuilder($dialect))->select(['value' => 1])]]]), $dialect), 'Auch verschachtelte Builder dürfen die Unterabfragegrenze nicht umgehen.', 'Unterabfragen');
  foreach ([['default' => true], ['value' => ['default' => true]]] as $default) {
    $invalid = $upsert;
    $invalid['values'][0]['stock'] = $default;
    rejectsSql(static fn () => SqlBuilder::build($invalid, $dialect), 'DEFAULT ist in einer MERGE-Quelle nicht portabel.', 'DEFAULT');
  }
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['set' => ['stock' => ['default' => true]]]), $dialect), 'Auch SET DEFAULT ist nicht Teil der portablen Upsert-API.', 'DEFAULT');
  rejectsSql(static fn () => SqlBuilder::build($upsert + ['where' => ['stock', '>', 0]], $dialect), 'Upsert darf WHERE nicht still ignorieren.', 'where');
  rejectsSql(static fn () => SqlBuilder::build($upsert + ['query' => ['select' => '*', 'from' => 'archive']], $dialect), 'INSERT-SELECT-Upserts sind vorerst nicht unterstützt.', 'query');
  rejectsSql(static fn () => SqlBuilder::build($upsert + ['rows' => $upsert['values']], $dialect), 'values und rows dürfen nicht gleichzeitig gesetzt sein.', 'gleichzeitig');
  rejectsSql(static fn () => SqlBuilder::build(array_replace($upsert, ['mysqlConflict' => 'only_key']), $dialect), 'Nicht darstellbare MySQL-Konfliktmodi müssen immer fehlschlagen.', 'mysqlConflict');
  rejectsSql(static fn () => SelectBuilder::expr(['incoming' => 'sku'], $dialect), 'Incoming ist außerhalb eines Upsert-SET kein gültiger Ausdruck.', 'incoming');
}

foreach (['postgres', 'postgresql', 'mssql', 'mariadb'] as $dialect) {
  check(\is_string(SqlBuilder::build($upsert, $dialect)), 'Dialektaliase müssen auch für Upsert funktionieren.');
}
$noMysqlMode = $upsert;
unset($noMysqlMode['mysqlConflict']);
rejectsSql(static fn () => SqlBuilder::build($noMysqlMode, 'mysql'), 'MySQLs abweichendes Konfliktverhalten muss ausdrücklich gewählt werden.', 'mysqlConflict');
check(str_contains(SqlBuilder::build($noMysqlMode, 'pgsql'), 'ON CONFLICT'), 'PostgreSQL benötigt keine MySQL-spezifische Option.');
check(str_contains(SqlBuilder::build($noMysqlMode, 'sqlsrv'), 'MERGE'), 'SQL Server benötigt keine MySQL-spezifische Option.');
foreach ([['dialect' => 'mysql', 'serverVersionId' => 50744], ['dialect' => 'mysql', 'serverVersionId' => 80018], ['dialect' => 'mariadb', 'serverVersionId' => 101100]] as $context) {
  $sql = SqlBuilder::build($upsert, $context);
  check(str_contains($sql, 'VALUES(`name`)') && ! str_contains($sql, ' AS `__dialectsql_incoming`'), 'Bekannte ältere MySQL-Versionen und MariaDB müssen VALUES(column) verwenden.');
}
check(str_contains(SqlBuilder::build($upsert, ['dialect' => 'mysql', 'serverVersionId' => 80019]), ' AS `__dialectsql_incoming`'), 'MySQL ab 8.0.19 muss den nicht veralteten Zeilenalias verwenden.');
$collision = array_replace($upsert, ['table' => '__dialectsql_incoming']);
check(str_contains(SqlBuilder::build($collision, 'mysql'), ' AS `__dialectsql_incoming_`'), 'MySQL-Zeilenalias darf nicht dem Tabellennamen entsprechen.');
$excluded = array_replace($upsert, ['table' => 'excluded', 'returning' => ['sku', ['col' => 'name', 'as' => 'label']]]);
$sql = SqlBuilder::build($excluded, 'pgsql');
check(str_contains($sql, 'INSERT INTO "excluded" AS "__dialectsql_target"') && str_ends_with($sql, ' RETURNING "sku", "name" AS "label"'), 'PostgreSQL muss auch bei einer Tabelle namens excluded die Bestands- und Eingabewerte unterscheiden.');
$sql = SqlBuilder::build($excluded, 'sqlsrv');
check(str_ends_with($sql, ' OUTPUT INSERTED.[sku], INSERTED.[name] AS [label];'), 'MERGE muss OUTPUT vor dem abschließenden Semikolon einfügen.');
rejectsSql(static fn () => SqlBuilder::build($excluded, 'mysql'), 'MySQL-Upsert bietet keine automatische RETURNING-Emulation.', 'RETURNING');
