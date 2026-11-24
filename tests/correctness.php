<?php

declare(strict_types=1);

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\SqlBuilder;
use Braesident\DialectSql\SqlRenderer;
use Braesident\DialectSql\WhereBuilder;

sameSql('SELECT * FROM `products` LIMIT 20, 18446744073709551615', SelectBuilder::build(['from' => 'products', 'offset' => 20], 'mysql'), 'MySQL OFFSET ohne LIMIT darf nicht überlaufen.');
sameSql('SELECT * FROM `products` LIMIT 0, 18446744073709551615', SelectBuilder::build(['from' => 'products', 'offset' => 0], 'mysql'), 'MySQL OFFSET 0 ohne LIMIT darf keine leere Auswahl erzeugen.');
sameSql('SELECT DISTINCT TOP (0) * FROM [products] ORDER BY id', SelectBuilder::build(['from' => 'products', 'limit' => 0, 'offset' => 3, 'distinct' => true, 'order_by' => 'id'], 'sqlsrv'), 'SQL Server LIMIT 0 muss mit DISTINCT und OFFSET eine gültige leere Auswahl ergeben.');
sameSql('SELECT TOP (0) COUNT(*) FROM [products]', SelectBuilder::build(['select' => ['raw' => 'COUNT(*)'], 'from' => 'products', 'limit' => 0], 'sqlsrv'), 'LIMIT 0 darf auch bei Aggregation keine Zeile liefern.');
rejectsSql(static fn () => SelectBuilder::build(['from' => 'a', 'join' => [['table' => 'b', 'using' => ['id']]]], 'sqlsrv'), 'SQL Server SELECT muss USING ablehnen.', 'USING');

foreach (['mysql', 'sqlsrv', 'pgsql'] as $dialect) {
  foreach ([-1, 1.5, true, '1e3', '92233720368547758070'] as $invalid) {
    rejectsSql(static fn () => SelectBuilder::build(['from' => 'products', 'limit' => $invalid], $dialect), 'Ungültige Limits dürfen nicht gerundet oder ignoriert werden.', 'Ganzzahl');
    rejectsSql(static fn () => SelectBuilder::build(['from' => 'products', 'offset' => $invalid], $dialect), 'Ungültige Offsets dürfen nicht gerundet oder ignoriert werden.', 'Ganzzahl');
  }
  rejectsSql(static fn () => (new SelectBuilder($dialect))->limit(-1), 'Fluente Limits müssen ebenfalls validiert werden.', 'Ganzzahl');
  rejectsSql(static fn () => SelectBuilder::build(['from' => 'products', 'limt' => 2], $dialect), 'SELECT-Tippfehler müssen erkannt werden.', 'limt');
  rejectsSql(static fn () => SelectBuilder::build(['from' => ['table' => 'products', 'Schema' => 'shop']], $dialect), 'Nicht normalisierte Unterobjekte dürfen Felder nicht still ignorieren.', 'Schema');
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'limt' => 2], $dialect), 'DML-Tippfehler müssen erkannt werden.', 'limt');
  rejectsSql(static fn () => SelectBuilder::build(['from' => 'products', 'distinct' => 'false'], $dialect), 'Boolean-Schalter müssen typgeprüft werden.', 'boolean');
  rejectsSql(static fn () => SelectBuilder::build(['from' => 'products', 'order_by' => [['expr' => 'id', 'dir' => 'sideways']]], $dialect), 'Sortierrichtungen müssen geprüft werden.', 'ORDER-BY');
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [['name' => 'id', 'type' => 'int', 'nullabel' => false]]], $dialect), 'Spalten-Tippfehler müssen erkannt werden.', 'nullabel');
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [['name' => 'id', 'type' => 'imaginary']]], $dialect), 'Unbekannte Typen dürfen nicht still zu VARCHAR werden.', 'Spaltentyp');
  sameSql('1=0', SelectBuilder::condition(['id', 'IN', []], $dialect), 'Leeres IN muss eine falsche Bedingung liefern.');
  sameSql('1=1', SelectBuilder::condition(['id', 'NOT IN', []], $dialect), 'Leeres NOT IN muss eine wahre Bedingung liefern.');
  sameSql('id IS NULL', SelectBuilder::condition(['id', '=', null], $dialect), 'NULL-Gleichheit muss IS NULL verwenden.');
  sameSql('id IS NOT NULL', SelectBuilder::condition(['id', '<>', ['value' => null]], $dialect), 'NULL-Ungleichheit muss IS NOT NULL verwenden.');
  sameSql('id IS NULL', SelectBuilder::expr(['op' => '=', 'left' => 'id', 'right' => null], $dialect), 'Ausdrucksobjekte müssen explizites NULL als rechten Operanden erhalten.');
  sameSql(':id', SelectBuilder::expr(SelectBuilder::param('id'), $dialect), 'Parameterausdrücke müssen gültige Platzhalter erzeugen.');
  rejectsSql(static fn () => SelectBuilder::expr(['param' => 'id; SELECT 1'], $dialect), 'Parameternamen dürfen kein SQL enthalten.', 'param');
  rejectsSql(static fn () => SelectBuilder::expr(['value' => INF], $dialect), 'Nicht endliche Zahlen sind keine SQL-Literale.', 'Literal');
  rejectsSql(static fn () => SelectBuilder::expr(INF, $dialect), 'Auch direkte Zahlenausdrücke dürfen nicht unendlich sein.', 'Literal');
  rejectsSql(static fn () => SelectBuilder::expr(['param' => null], $dialect), 'Ein leerer Parameter darf kein Funktionsaufruf werden.', 'param');
  rejectsSql(static fn () => SelectBuilder::expr(['col' => null], $dialect), 'Eine leere Referenz darf kein Funktionsaufruf werden.', 'col');
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'insert', 'table' => 'products', 'values' => [['id' => ['raw' => 1]]]], $dialect), 'Raw-SQL muss als String angegeben werden.', 'raw');
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'actions' => [['action' => 'drop_column', 'name' => 'id', 'cascde' => true]]], $dialect), 'Auch ALTER-Actions müssen unbekannte Felder ablehnen.', 'cascde');
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [['name' => 'amount', 'type' => 'decimal', 'precision' => 2, 'scale' => 3]]], $dialect), 'Dezimalstellen dürfen die Präzision nicht überschreiten.', 'scale');
  rejectsSql(static fn () => SelectBuilder::expr(['value' => "a\0b"], $dialect), 'NUL gehört nicht in SQL-Textliterale.', 'NUL');
  $empty = (new WhereBuilder())->andIn('id', []);
  sameSql('1=0', SelectBuilder::condition($empty->conditions(), $dialect), 'WhereBuilder darf einen leeren Filter nicht entfernen.');
}
sameSql('SELECT * FROM [products] ORDER BY (SELECT 1) OFFSET 0 ROWS FETCH NEXT 2 ROWS ONLY', SelectBuilder::build(['from' => 'products', 'limit' => '002'], 'mssql'), 'Numerische Strings und dokumentierte Dialektaliase bleiben verwendbar.');
sameSql('`p`.`Name`', SelectBuilder::expr(['col' => 'p.Name'], 'mysql'), 'Explizite MySQL-Spalten müssen gequotet werden.');
sameSql('[p].[Name]', SelectBuilder::expr(['col' => 'p.Name'], 'sqlsrv'), 'Explizite SQL-Server-Spalten müssen gequotet werden.');
sameSql('"p"."Name"', SelectBuilder::expr(['col' => 'p.Name'], 'pgsql'), 'Explizite PostgreSQL-Spalten müssen gequotet werden.');
sameSql('`a``b`', SelectBuilder::expr(['col' => 'a`b'], 'mysql'), 'MySQL-Identifier müssen Backticks maskieren.');
sameSql('[a]]b]', SelectBuilder::expr(['col' => 'a]b'], 'sqlsrv'), 'SQL-Server-Identifier müssen Klammern maskieren.');
sameSql('"a""b"', SelectBuilder::expr(['col' => 'a"b'], 'pgsql'), 'PostgreSQL-Identifier müssen Anführungszeichen maskieren.');
sameSql('UPDATE `product list` SET `display name` = \'neu\'', SqlBuilder::build(['type' => 'update', 'table' => 'product list', 'set' => ['display name' => 'neu']], 'mysql'), 'DML-Tabellen und SET-Ziele müssen als Bezeichner behandelt werden.');
sameSql('DELETE [shop].[a]]b]', SqlBuilder::build(['type' => 'delete', 'schema' => 'shop', 'table' => 'a]b'], 'sqlsrv'), 'SQL-Server-DML muss auch Klammern im Tabellennamen maskieren.');
sameSql('INSERT INTO "shop"."product list" ("display name") VALUES (\'neu\')', SqlBuilder::build(['type' => 'insert', 'schema' => 'shop', 'table' => 'product list', 'values' => [['display name' => 'neu']]], 'pgsql'), 'PostgreSQL-DML darf Namen mit Leerzeichen nicht als Raw-SQL behandeln.');
sameSql("N'Größe 東京 O''Brien'", SelectBuilder::expr(['value' => "Größe 東京 O'Brien"], 'sqlsrv'), 'SQL-Server-Literale müssen Unicode erhalten.');
sameSql("CONVERT(X'615c62' USING utf8mb4)", SelectBuilder::expr(['value' => 'a\\b'], 'mysql'), 'MySQL-Literale mit Backslash müssen unabhängig vom SQL-Modus bleiben.');
sameSql("E'a\\\\b'", SelectBuilder::expr(['value' => 'a\\b'], 'pgsql'), 'PostgreSQL-Literale mit Backslash müssen explizite Escape-Syntax verwenden.');
rejectsSql(static fn () => SelectBuilder::build(['from' => 'products'], 'sqlite'), 'Unbekannte Dialekte dürfen nicht MySQL werden.', 'Dialekt');
rejectsSql(static fn () => (new SqlRenderer(new DialectConnection('sqlite')))->render(['from' => 'products']), 'Renderer muss unbekannte PDO-Treiber ablehnen.', 'Dialekt');
foreach (['update', 'delete'] as $type) {
  $base = ['type' => $type, 'table' => 'products'] + ('update' === $type ? ['set' => ['id' => 1]] : []);
  rejectsSql(static fn () => SqlBuilder::build($base + ['limit' => -1], 'mysql'), 'Negative DML-Limits dürfen kein unbegrenztes Statement erzeugen.', 'Ganzzahl');
  rejectsSql(static fn () => SqlBuilder::build($base + ['limit' => 1, 'join' => [['table' => 'stock', 'on' => ['products.id', '=', 'stock.id']]]], 'mysql'), 'MySQL DML-JOIN mit LIMIT muss fehlschlagen.', 'LIMIT');
  rejectsSql(static fn () => SqlBuilder::build($base + ['returning' => ['id']], 'mysql'), 'MySQL darf RETURNING nicht ignorieren.', 'RETURNING');
}
rejectsSql(static fn () => SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'as' => 'p', 'limit' => 1], 'mysql'), 'Auch Alias-DELETE verwendet aktuell die Mehrtabellensyntax.', 'LIMIT');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [['name' => 'id', 'type' => 'int', 'unsigned' => true]]], 'sqlsrv'), 'SQL Server darf unsigned nicht ignorieren.', 'unsigned');
$literalFilter = (new WhereBuilder())->andIn('name', ['', null, 'A']);
sameSql([':p_0' => '', ':p_1' => null, ':p_2' => 'A'], $literalFilter->params(), 'IN-Werte dürfen nicht still herausgefiltert werden.');

$cteQuery = ['with' => [['name' => 'active', 'columns' => ['id'], 'query' => ['select' => 'id', 'from' => 'products', 'where' => ['id', '>', 0]]]], 'select' => 'id', 'from' => 'active'];
foreach (['mysql' => '`', 'sqlsrv' => '[', 'pgsql' => '"'] as $dialect => $quote) {
  $close = '[' === $quote ? ']' : $quote;
  $id = static fn ($value) => $quote.$value.$close;
  $expected = 'WITH '.$id('active').' ('.$id('id').') AS (SELECT id FROM '.$id('products').' WHERE id > 0) SELECT id FROM '.$id('active');
  sameSql($expected, SelectBuilder::build($cteQuery, $dialect), 'SELECT-CTEs müssen dieselbe Definition verwenden.');
  sameSql($expected, SelectBuilder::build(json_decode(json_encode($cteQuery)), $dialect), 'CTEs müssen als verschachtelte JSON-Objekte funktionieren.');
  $fluent = (new SelectBuilder($dialect))->with('active', $cteQuery['with'][0]['query'], ['id'])->select('id')->from('active');
  sameSql($expected, $fluent->statement(), 'Fluente CTEs müssen dem AST entsprechen.');
  $recursiveSql = SelectBuilder::build($cteQuery + ['recursive' => true], $dialect);
  check(str_starts_with($recursiveSql, 'sqlsrv' === $dialect ? 'WITH ' : 'WITH RECURSIVE '), 'SQL Server darf kein RECURSIVE-Schlüsselwort erhalten.');
  $union = ['select' => ['col' => 'id'], 'from' => 'a', 'union_all' => [['select' => ['col' => 'id'], 'from' => 'b']], 'union_order_by' => [['expr' => ['col' => 'id'], 'dir' => 'DESC']], 'union_limit' => 2, 'union_offset' => 1];
  $sql = SelectBuilder::build($union, $dialect);
  check(str_contains($sql, ' AS '.$id('__dialectsql_union').' ORDER BY '.$id('id').' DESC'), 'Globale UNION-Sortierung muss außen stehen.');
  $ending = match ($dialect) { 'mysql' => 'LIMIT 1, 2', 'sqlsrv' => 'OFFSET 1 ROWS FETCH NEXT 2 ROWS ONLY', 'pgsql' => 'LIMIT 2 OFFSET 1' };
  check(str_ends_with($sql, $ending), 'Globale UNION-Paginierung muss den vollständigen Ausdruck begrenzen.');
  $union['union_limit'] = 0;
  if ('sqlsrv' === $dialect) {
    check(str_starts_with(SelectBuilder::build($union, $dialect), 'SELECT TOP (0) * FROM ('), 'Auch globale UNION-Limits müssen 0 korrekt behandeln.');
  }
  rejectsSql(static fn () => SelectBuilder::build(['from' => 'a', 'union_limit' => 1], $dialect), 'Globale UNION-Felder ohne UNION dürfen nicht ignoriert werden.', 'UNION');
  rejectsSql(static fn () => SelectBuilder::build(['from' => 'a', 'recursive' => true], $dialect), 'RECURSIVE ohne CTE ist ungültig.', 'WITH');
}
rejectsSql(static fn () => SelectBuilder::build($cteQuery, ['dialect' => 'mysql', 'serverVersionId' => 50700]), 'CTEs benötigen einen geeigneten MySQL-Server.', 'Version');
rejectsSql(static fn () => SelectBuilder::build(['with' => [['name' => 'nested', 'query' => $cteQuery]], 'from' => 'nested'], 'sqlsrv'), 'SQL Server benötigt CTEs auf der obersten Ebene.', 'WITH');
rejectsSql(static fn () => SelectBuilder::build(['from' => ['query' => $cteQuery, 'as' => 'nested']], 'sqlsrv'), 'SQL Server unterstützt WITH nicht in FROM-Unterabfragen.', 'WITH');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'insert', 'table' => 'target', 'query' => $cteQuery], 'sqlsrv'), 'SQL Server darf WITH nicht hinter INSERT setzen.', 'WITH');

$insert = ['type' => 'insert', 'table' => 'products', 'values' => [['name' => 'Öl']], 'returning' => ['id', ['col' => 'name', 'as' => 'label']]];
sameSql('INSERT INTO "products" ("name") VALUES (\'Öl\') RETURNING "id", "name" AS "label"', SqlBuilder::build($insert, 'pgsql'), 'PostgreSQL-INSERT muss RETURNING anhängen.');
sameSql('INSERT INTO [dbo].[products] ([name]) OUTPUT INSERTED.[id], INSERTED.[name] AS [label] VALUES (N\'Öl\')', SqlBuilder::build($insert, 'sqlsrv'), 'SQL-Server-INSERT muss OUTPUT vor VALUES einfügen.');
sameSql('INSERT INTO [dbo].[products] ([id]) OUTPUT INSERTED.* SELECT id FROM [archive]', SqlBuilder::build(['type' => 'insert', 'table' => 'products', 'columns' => ['id'], 'query' => ['select' => 'id', 'from' => 'archive'], 'returning' => '*'], 'sqlsrv'), 'OUTPUT muss auch vor INSERT SELECT stehen.');
sameSql('UPDATE [p] SET [name] = N\'neu\' OUTPUT INSERTED.[id] FROM [dbo].[products] AS [p] WHERE id = 1', SqlBuilder::build(['type' => 'update', 'table' => 'products', 'as' => 'p', 'set' => ['p.name' => 'neu'], 'where' => ['id', '=', 1], 'returning' => ['id']], 'sqlsrv'), 'UPDATE muss Zielqualifizierungen entfernen und OUTPUT vor FROM setzen.');
sameSql('DELETE [dbo].[products] OUTPUT DELETED.[id] WHERE id = 1', SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'where' => ['id', '=', 1], 'returning' => ['id']], 'sqlsrv'), 'DELETE muss DELETED als Rückgabequelle verwenden.');
sameSql('UPDATE "products" SET "name" = \'neu\' WHERE (id = 1) RETURNING "id"', SqlBuilder::build(['type' => 'update', 'table' => 'products', 'set' => ['name' => 'neu'], 'where' => ['id', '=', 1], 'returning' => ['id']], 'pgsql'), 'PostgreSQL-UPDATE muss RETURNING nach WHERE setzen.');
sameSql('DELETE FROM "products" RETURNING *', SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'returning' => '*'], 'pgsql'), 'PostgreSQL-DELETE muss den alten Zustand zurückgeben können.');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'returning' => []], 'pgsql'), 'Leere Rückgabelisten sind ungültig.', 'Liste');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'returning' => ['p.id']], 'sqlsrv'), 'Die portable Rückgabe beschränkt sich auf Zielspalten.', 'unqualifizierte');
