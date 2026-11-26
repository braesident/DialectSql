<?php

declare(strict_types=1);

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\SqlBuilder;
use Braesident\DialectSql\SqlRenderer;
use Braesident\DialectSql\WhereBuilder;

$schemaMap = ['mysql' => 'shop_db', 'sqlsrv' => 'shop', 'pgsql' => 'shop'];
$tableRefs = ['mysql' => '`shop_db`.`products`', 'sqlsrv' => '[shop].[products]', 'pgsql' => '"shop"."products"'];
$column = [
  'name' => 'ActiveFlag',
  'type' => ['mysql' => 'boolean', 'sqlsrv' => 'boolean', 'postgresql' => 'smallint'],
  'nullable' => ['default' => false],
  'default' => ['mysql' => 1, 'sqlserver' => 1, 'pgsql' => 0],
  'unsigned' => ['mysql' => false],
  'comment' => ['pgsql' => 'Status']
];
foreach (['mysql', 'sqlsrv', 'pgsql'] as $dialect) {
  $definition = ['type' => 'insert', 'table' => 'products', 'schema' => $schemaMap, 'values' => [['name' => 'Öl']]];
  $sql = SqlBuilder::build($definition, $dialect);
  check(str_starts_with($sql, 'INSERT INTO '.$tableRefs[$dialect]), 'Schema-Maps müssen DML-Ziele dialektgerecht qualifizieren.');
  sameSql($sql, SqlBuilder::build(json_encode($definition), $dialect), 'Schema-Maps müssen aus JSON funktionieren.');
  sameSql($sql, SqlBuilder::build(json_decode(json_encode($definition)), $dialect), 'Schema-Maps müssen als verschachtelte Objekte funktionieren.');
  sameSql($sql, (new SqlRenderer(new DialectConnection($dialect)))->render($definition), 'Der Renderer muss dieselben Schema-Maps verarbeiten.');
  sameSql($schemaMap, $definition['schema'], 'Die Eingabedefinition darf beim Auflösen nicht verändert werden.');
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'schema' => $schemaMap, 'ifNotExists' => false, 'columns' => [$column]], $dialect);
  $statements = \is_array($sql) ? $sql : [$sql];
  check(str_starts_with($statements[0], 'CREATE TABLE '.$tableRefs[$dialect]), 'Schema-Maps müssen DDL-Ziele qualifizieren.');
  $expected = match ($dialect) { 'mysql' => '`ActiveFlag` TINYINT(1) NOT NULL DEFAULT 1', 'sqlsrv' => '[ActiveFlag] BIT NOT NULL DEFAULT 1', 'pgsql' => '"ActiveFlag" SMALLINT NOT NULL DEFAULT 0' };
  check(str_contains($statements[0], $expected), 'Spaltenoptionen müssen vor Typabbildung und Defaultausgabe aufgelöst werden.');
  check('pgsql' === $dialect ? 2 === count($statements) : 1 === count($statements), 'Nur der gewählte Spaltenkommentar darf ausgegeben werden.');
  $sql = SelectBuilder::build(['select' => ['col' => 'p.id'], 'from' => ['table' => 'products', 'schema' => (object) $schemaMap, 'as' => 'p'], 'join' => [['table' => 'products', 'schema' => $schemaMap, 'as' => 'q', 'on' => [['col' => 'p.id'], '=', ['col' => 'q.id']]]]], $dialect);
  check(str_contains($sql, 'FROM '.$tableRefs[$dialect]) && str_contains($sql, 'JOIN '.$tableRefs[$dialect]), 'SELECT-Quellen und Joins müssen Schema-Maps unterstützen.');
  $sql = SqlBuilder::build(['type' => 'update', 'table' => 'products', 'schema' => $schemaMap, 'as' => 'p', 'set' => ['name' => 'neu'], 'join' => [['table' => 'products', 'schema' => $schemaMap, 'as' => 'q', 'on' => [['col' => 'p.id'], '=', ['col' => 'q.id']]]]], $dialect);
  check(substr_count($sql, $tableRefs[$dialect]) >= 2, 'DML-Joins dürfen Schema-Maps nicht verlieren.');

  $timestamp = ['name' => 'updated', 'type' => 'timestamp', 'onUpdate' => ['mysql' => 'CURRENT_TIMESTAMP']];
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [$timestamp]], $dialect);
  check('mysql' === $dialect ? str_contains($sql, 'ON UPDATE CURRENT_TIMESTAMP') : ! str_contains($sql, 'ON UPDATE'), 'Explizit eingegrenzte Spaltenoptionen müssen nur im gewählten Dialekt gelten.');
  if ('mysql' !== $dialect) {
    rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [array_replace($timestamp, ['onUpdate' => 'CURRENT_TIMESTAMP'])]], $dialect), 'Unbedingtes onUpdate darf weiterhin nicht still ignoriert werden.', 'pgsql' === $dialect ? 'ON UPDATE' : 'onUpdate');
  }
  $nullDefault = ['name' => 'value', 'type' => 'int', 'default' => [$dialect => null]];
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [$nullDefault]], $dialect);
  check(str_contains($sql, 'DEFAULT NULL'), 'Ein ausgewählter NULL-Default darf nicht als fehlender Eintrag behandelt werden.');
  $missingDefault = ['name' => 'value', 'type' => 'int', 'default' => ['mariadb' => null]];
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [$missingDefault]], $dialect);
  check( ! str_contains($sql, 'DEFAULT'), 'Ein fehlender optionaler Default muss entfallen.');
  $length = ['name' => 'value', 'type' => 'string', 'length' => ['default' => 31], 'nullable' => ['default' => true]];
  $sql = SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'actions' => [['action' => 'add_column', 'column' => $length]]], $dialect);
  check(str_contains($sql, '(31) NULL'), 'ADD COLUMN muss Längen- und Nullable-Maps vor der Prüfung auflösen.');
  $enum = ['name' => 'status', 'type' => 'string', 'enum' => [$dialect => ['A', 'B']]];
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [$enum]], $dialect);
  check(str_contains($sql, "'A'") && str_contains($sql, "'B'"), 'Enum-Wertelisten innerhalb einer Dialekt-Map müssen erhalten bleiben.');
  foreach ([['type' => ['mysql' === $dialect ? 'pgsql' : 'mysql' => 'int']], ['nullable' => ['default' => 'false']], ['length' => ['default' => 1.5]], ['default' => ['myqsl' => 1]], ['type' => ['pgsql' => 'int', 'postgres' => 'string']]] as $invalid) {
    rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [array_replace(['name' => 'value', 'type' => 'string'], $invalid)]], $dialect), 'Ungültige oder widersprüchliche Spalten-Maps müssen fehlschlagen.', array_key_exists('nullable', $invalid) ? 'boolean' : (array_key_exists('length', $invalid) ? 'Ganzzahl' : (array_key_exists('default', $invalid) ? 'myqsl' : 'type')));
  }
  rejectsSql(static fn () => SelectBuilder::build(['from' => ['table' => 'products', 'schema' => ['pgsl' => 'shop']]], $dialect), 'Schema-Map-Tippfehler müssen Fehler sein.', 'pgsl');
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'schema' => ['default' => 42]], $dialect), 'Schema-Map-Werte müssen Bezeichner sein.', 'schema');
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'insert', 'table' => 'products', 'schema' => ['other' => null], 'values' => [['id' => 1]]], $dialect), 'Auch nicht gewählte Schema-Map-Schlüssel müssen bekannt sein.', 'other');
}

$limited = ['type' => 'delete', 'table' => 'products', 'schema' => ['sqlsrv' => 'dbo']];
sameSql('DELETE FROM "products"', SqlBuilder::build($limited, 'pgsql'), 'Ohne PostgreSQL-Eintrag muss der native Suchpfad gelten.');
sameSql('DELETE [dbo].[products]', SqlBuilder::build($limited, 'sqlsrv'), 'SQL Server muss den ausgewählten dbo-Eintrag verwenden.');
sameSql('DELETE FROM `products`', SqlBuilder::build($limited, 'mysql'), 'Ohne MySQL-Eintrag bleibt die Standarddatenbank maßgeblich.');
sameSql('DELETE FROM "dbo"."products"', SqlBuilder::build(array_replace($limited, ['schema' => 'dbo']), 'pgsql'), 'Ein skalares dbo darf PostgreSQL nicht automatisch entfernt werden.');
sameSql('DELETE FROM `products`', SqlBuilder::build(array_replace($limited, ['schema' => 'dbo']), 'mysql'), 'Bisherige skalare MySQL-Root-Schemaangaben müssen kompatibel bleiben.');
sameSql('DELETE FROM `fixed`.`products`', SqlBuilder::build(array_replace($limited, ['schema' => ['mysql' => 'shop'], 'table' => 'fixed.products']), 'mysql'), 'Ein qualifizierter Zielname hat Vorrang vor einer Schema-Map.');
$versions = ['type' => 'create_table', 'table' => 'products', 'columns' => [['name' => 'value', 'type' => 'int', 'default' => ['mariadb' => 7, 'mysql' => 3, 'default' => 1]]]];
check(str_contains(SqlBuilder::build($versions, 'mariadb'), 'DEFAULT 7'), 'MariaDB benötigt Vorrang vor dem MySQL-Fallback.');
check(str_contains(SqlBuilder::build($versions, 'mysql'), 'DEFAULT 3'), 'MySQL darf MariaDB-Einträge nicht auswählen.');
check(str_contains(SqlBuilder::build($versions, 'pgsql'), 'DEFAULT 1'), 'Explizite Fallbacks müssen für andere Dialekte gelten.');
sameSql('DROP TABLE IF EXISTS `shop_db`.`products`', SqlBuilder::build(['type' => 'DROP_TABLE', 'table' => 'products', 'schema' => $schemaMap], 'mysql'), 'Schema-Maps müssen bei DDL auch normalisierte Typnamen berücksichtigen.');
sameSql('CREATE VIEW `shop_db`.`v` AS SELECT 1', SqlBuilder::build(['type' => 'create_view', 'view' => 'v', 'schema' => $schemaMap, 'replace' => false, 'query' => ['select' => ['value' => 1]]], 'mysql'), 'MySQL-View-Namen müssen aus Schema-Maps qualifiziert werden.');
sameSql('RENAME TABLE `shop_db`.`products` TO `shop_db`.`renamed`', SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'schema' => $schemaMap, 'actions' => [['action' => 'rename_table', 'to' => 'renamed']]], 'mysql'), 'RENAME muss im ausdrücklich gewählten MySQL-Namespace bleiben.');
sameSql('RENAME TABLE `shop_db`.`products` TO `shop_db`.`renamed`', SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'schema' => $schemaMap, 'actions' => [['action' => 'RENAME_TABLE', 'to' => 'renamed']]], 'mysql'), 'Auch ALTER-Aktionsnamen müssen beim Qualifizieren normalisiert werden.');
$foreignKey = SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'schema' => $schemaMap, 'columns' => [['name' => 'parent_id', 'type' => 'int']], 'foreignKeys' => [['columns' => ['parent_id'], 'refTable' => 'parents', 'refColumns' => ['id']]]], 'mysql');
check(str_contains($foreignKey, 'REFERENCES `shop_db`.`parents`'), 'Unqualifizierte MySQL-Fremdschlüsselziele müssen den expliziten Namespace verwenden.');
$escaped = SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'schema' => ['sqlsrv' => "odd]'schema"], 'columns' => [['name' => 'id', 'type' => 'int']]], 'sqlsrv');
check(str_contains($escaped, "[odd]]'schema].[products]") && str_contains($escaped, "OBJECT_ID(N'[odd]]''schema].[products]'"), 'Schema-Identifier und OBJECT_ID-Literale müssen unabhängig maskiert werden.');
sameSql('DELETE FROM "fallback"."products"', SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'schema' => ['default' => 'fallback']], 'pgsql'), 'Schema-Fallbacks müssen ebenfalls gelten.');
sameSql('DELETE FROM "products"', SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'schema' => ['pgsql' => null, 'default' => 'fallback']], 'pgsql'), 'Explizites NULL muss einen Schema-Fallback überstimmen.');

$ci = ['mysql' => 'utf8mb4_unicode_ci', 'sqlsrv' => 'Latin1_General_100_CI_AS'];
$cs = ['mysql' => 'utf8mb4_bin', 'sqlsrv' => 'Latin1_General_100_BIN2'];
foreach (['mysql', 'sqlsrv', 'pgsql'] as $dialect) {
  $name = match ($dialect) { 'mysql' => '`Name`', 'sqlsrv' => '[Name]', 'pgsql' => '"Name"' };
  $insensitive = SelectBuilder::like(['col' => 'Name'], ['param' => 'term'], ['caseSensitive' => false, 'collation' => $ci, 'escape' => '!']);
  $expected = match ($dialect) {
    'mysql' => '(`Name` COLLATE utf8mb4_unicode_ci) LIKE (:term COLLATE utf8mb4_unicode_ci) ESCAPE \'!\'',
    'sqlsrv' => '([Name] COLLATE Latin1_General_100_CI_AS) LIKE (:term COLLATE Latin1_General_100_CI_AS) ESCAPE N\'!\'',
    'pgsql' => '"Name" ILIKE :term ESCAPE \'!\''
  };
  sameSql($expected, SelectBuilder::condition($insensitive, $dialect), 'Explizite Suche muss Case-Steuerung, Kollation und Parameter korrekt übersetzen.');
  sameSql($expected, SelectBuilder::condition(['LIKE' => $insensitive['like']], $dialect), 'LIKE-Bedingungsschlüssel müssen wie AND/OR normalisiert werden.');
  check(str_contains(SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'where' => $insensitive], $dialect), $expected), 'Auch direktes DML-WHERE muss LIKE als Prädikat erkennen.');
  sameSql($expected, SelectBuilder::expr(json_decode(json_encode($insensitive)), $dialect), 'LIKE-Ausdrücke müssen aus verschachtelten Objekten funktionieren.');
  $sensitive = SelectBuilder::like(['col' => 'Name'], ['param' => 'term'], ['caseSensitive' => true, 'collation' => $cs]);
  $sql = SelectBuilder::condition($sensitive, $dialect);
  $collation = match ($dialect) { 'mysql' => 'utf8mb4_bin', 'sqlsrv' => 'Latin1_General_100_BIN2', 'pgsql' => '"C"' };
  check(str_contains($sql, 'COLLATE '.$collation) && str_contains($sql, ' LIKE '), 'Explizite case-sensitive Suche muss eine passende Kollation verwenden.');
  sameSql($name.' LIKE \'%A%\'', str_replace("N'%A%'", "'%A%'", SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], '%A%'), $dialect)), 'Ohne Steuerung muss LIKE sein bisheriges Serververhalten behalten.');
  check(str_contains(SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], "O'Brien"), $dialect), "O''Brien"), 'Skalare Suchmuster müssen Literale sein.');
  check(str_contains(SelectBuilder::condition(['like' => $insensitive['like'] + ['not' => true]], $dialect), ' NOT '), 'NOT muss auch für ILIKE funktionieren.');
  sameSql($name.' LIKE NULL', SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], null), $dialect), 'NULL-Suchmuster müssen ihre SQL-Semantik behalten.');
  check(str_ends_with(SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], null, ['caseSensitive' => true, 'collation' => $cs]), $dialect), ' LIKE NULL'), 'Ein untypisiertes NULL-Literal darf keine COLLATE-Typfehler verursachen.');
  $where = (new WhereBuilder())->andLikeParam(['col' => 'Name'], '%Öl%', 'term', ['caseSensitive' => false, 'collation' => $ci])->orLike(['col' => 'Name'], 'A%');
  $sql = SelectBuilder::condition($where->conditions(), $dialect);
  check(str_contains($sql, ':term') && str_contains($sql, ' OR '), 'WhereBuilder muss LIKE-Prädikate in OR-Gruppen erhalten.');
  sameSql([':term' => '%Öl%'], $where->params(), 'Gebundene Suchmuster dürfen nicht verändert werden.');
  $where = (new WhereBuilder())->andLike(['col' => 'Name'], 'A%')->orLikeParam((object) ['col' => 'Name'], 'B%', 'term');
  check(str_contains(SelectBuilder::condition($where->conditions(), $dialect), ':term'), 'Beide LIKE-Parameterhelfer müssen funktionieren.');
  foreach ([['caseSensitive' => 'false'], ['not' => 1], ['escape' => '!!'], ['escape' => 'ä'], ['escape' => "\0"], ['collation' => ['pgsl' => 'C']], ['expr' => 1]] as $options) {
    rejectsSql(static fn () => SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 'A%', $options), $dialect), 'Ungültige Suchoptionen müssen Fehler sein.', array_key_exists('expr', $options) ? 'expr' : (array_key_exists('collation', $options) ? 'pgsl' : array_key_first($options)));
  }
  rejectsSql(static fn () => SelectBuilder::condition(['like' => ['expr' => ['col' => 'Name']]], $dialect), 'Fehlende Suchmuster müssen fehlschlagen.', 'pattern');
  rejectsSql(static fn () => SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 42), $dialect), 'Nichttextuelle Suchmuster müssen bewusst als Ausdruck angegeben werden.', 'pattern');
  rejectsSql(static fn () => SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], []), $dialect), 'Leere Muster-Ausdrücke dürfen kein ungültiges SQL erzeugen.', 'pattern');
  if ('pgsql' !== $dialect) {
    rejectsSql(static fn () => SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 'A%', ['caseSensitive' => false]), $dialect), 'Fallsteuerung darf ohne passende Kollation nicht geraten werden.', 'collation');
    rejectsSql(static fn () => SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 'A%', ['caseSensitive' => true, 'collation' => $ci]), $dialect), 'Eine CI-Kollation darf keine case-sensitive Suche vortäuschen.', 'collation');
    rejectsSql(static fn () => SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 'A%', ['caseSensitive' => false, 'collation' => $cs]), $dialect), 'Eine CS-Kollation darf keine case-insensitive Suche vortäuschen.', 'collation');
  } else {
    rejectsSql(static fn () => SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 'A%', ['caseSensitive' => true, 'collation' => 'custom']), $dialect), 'Unbekannte PostgreSQL-Kollationen dürfen keine eindeutige Fallunterscheidung vortäuschen.', 'caseSensitive');
  }
  if ('pgsql' === $dialect) {
    check(str_contains(SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 'A%', ['collation' => 'C.utf8']), $dialect), 'COLLATE "C.utf8"'), 'Punkte innerhalb eines PostgreSQL-Kollationsnamens dürfen nicht in einen Schemapfad umgedeutet werden.');
    check(str_contains(SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 'A%', ['collation' => 'bad; DROP TABLE x']), $dialect), 'COLLATE "bad; DROP TABLE x"'), 'PostgreSQL-Kollationsnamen müssen als Bezeichner gequotet werden.');
  } else {
    rejectsSql(static fn () => SelectBuilder::condition(SelectBuilder::like(['col' => 'Name'], 'A%', ['collation' => 'bad; DROP TABLE x']), $dialect), 'MySQL-/SQL-Server-Kollationsnamen müssen geprüft werden.', 'collation');
  }
}
