<?php

declare(strict_types=1);

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\SqlBuilder;
use Braesident\DialectSql\SqlRenderer;

function sameSql(string|array|null $expected, string|array|null $actual, string $message): void
{
  check($expected === $actual, $message."\nErwartet: ".var_export($expected, true)."\nErhalten: ".var_export($actual, true));
}

function rejectsSql(callable $build, string $message, string $reason): void
{
  try {
    $build();
  } catch (InvalidArgumentException $exception) {
    check(str_contains($exception->getMessage(), $reason), $message.' (Fehlergrund)');

    return;
  }
  check(false, $message);
}

$page = ['select' => '*', 'from' => 'products', 'order_by' => 'id', 'limit' => 10, 'offset' => 20];
sameSql('SELECT * FROM `products` ORDER BY id LIMIT 20, 10', SelectBuilder::build($page, 'mysql'), 'MySQL-Paginierung muss unverändert bleiben.');
sameSql('SELECT * FROM [products] ORDER BY id OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', SelectBuilder::build($page, 'sqlsrv'), 'SQL Server nutzt bei SELECT OFFSET/FETCH.');
foreach (['pgsql', 'postgres', 'postgresql', 'PostgreSQL'] as $dialect) {
  sameSql('SELECT * FROM "products" ORDER BY id LIMIT 10 OFFSET 20', SelectBuilder::build($page, $dialect), 'PostgreSQL-Aliasse müssen dieselbe Paginierung erzeugen.');
  sameSql('UPDATE "config" SET "value" = :value WHERE (id = :id)', SqlBuilder::build($update, $dialect), 'DML muss PostgreSQL-Aliasse und Parameter unterstützen.');
  sameSql('SELECT TRUE', SelectBuilder::build(['postgres' => 'SELECT TRUE'], $dialect), 'SELECT-Dialekt-Maps müssen PostgreSQL-Aliasse erkennen.');
  sameSql('SELECT TRUE', SqlBuilder::build(['postgresql' => 'SELECT TRUE'], $dialect), 'Raw-Dialekt-Maps müssen PostgreSQL erkennen.');
}
sameSql('SELECT * FROM "products" LIMIT 0', SelectBuilder::build(['from' => 'products', 'limit' => 0], 'pgsql'), 'LIMIT 0 muss eine leere Auswahl ermöglichen.');
sameSql('SELECT * FROM "products" OFFSET 20', SelectBuilder::build(['from' => 'products', 'offset' => 20], ['driver' => 'postgres']), 'OFFSET ohne LIMIT benötigt keinen MySQL-Ersatzwert.');
sameSql('SELECT "p"."ProductId" AS "Id" FROM "shop"."products" AS "p"', SelectBuilder::build([
  'select' => ['col' => 'p.ProductId', 'as' => 'Id'],
  'from' => ['table' => 'products', 'schema' => 'shop', 'as' => 'p']
], 'pgsql'), 'Explizite Spalten und Aliasse müssen ihre Großschreibung behalten.');
sameSql('SELECT TRUE, FALSE, TRUE', SelectBuilder::build(['select' => [true, false, ['value' => true]]], 'pgsql'), 'PostgreSQL-Boolesche Werte dürfen keine Zahlen werden.');
sameSql('SELECT * FROM "a" INNER JOIN "b" USING ("id")', SelectBuilder::build(['from' => 'a', 'join' => [['table' => 'b', 'using' => ['id']]]], 'pgsql'), 'SELECT JOIN USING muss unterstützt werden.');
sameSql('(SELECT id FROM "a") UNION ALL (SELECT id FROM "b")', SelectBuilder::build(['select' => 'id', 'from' => 'a', 'union_all' => [['select' => 'id', 'from' => 'b']]], 'pgsql'), 'UNION ALL muss mit PostgreSQL nutzbar sein.');
sameSql('SELECT * FROM "products" WHERE "Price" BETWEEN 10 AND 20', SelectBuilder::build(json_decode('{"from":"products","where":{"between":{"expr":{"col":"Price"},"min":10,"max":20}}}'), 'pgsql'), 'Verschachtelte SELECT-Bedingungen müssen auch als JSON-Objekte funktionieren.');
sameSql('COALESCE(value, FALSE)', SelectBuilder::expr(['fn' => 'ifnull', 'args' => ['value', ['value' => false]]], 'pgsql'), 'IFNULL muss zu COALESCE werden.');
foreach (['now' => 'CURRENT_TIMESTAMP', 'curdate' => 'CURRENT_DATE', 'curtime' => 'CURRENT_TIME', 'len' => 'CHAR_LENGTH(value)'] as $fn => $expected) {
  sameSql($expected, SelectBuilder::expr(['fn' => $fn, 'args' => ['value']], 'pgsql'), 'Datum-/Stringfunktion '.$fn.' muss übersetzt werden.');
}
sameSql('GREATEST(a, b)', SelectBuilder::expr(['fn' => 'greatest', 'args' => ['a', 'b']], 'pgsql'), 'GREATEST ist in PostgreSQL nativ.');
sameSql('TO_CHAR(created_at, \'YYYY"-"MM"-"DD"T"HH24":"MI":"SS\')', SelectBuilder::expr(['fn' => 'date_format', 'args' => ['created_at', '%Y-%m-%dT%H:%i:%s']], 'pgsql'), 'Datumformatierung muss Tokens und literalem Text unterscheiden.');
sameSql('TO_CHAR(created_at, \'\')', SelectBuilder::expr(['fn' => 'date_format', 'args' => ['created_at', '']], 'pgsql'), 'Ein leeres Datumformat ist zulässig.');
sameSql("(created_at + (2) * INTERVAL '1 day')", SelectBuilder::expr(['fn' => 'date_add', 'args' => ['created_at', 2, 'day']], 'pgsql'), 'Datumsaddition muss PostgreSQL-Intervalle erzeugen.');
sameSql("(created_at - (:amount) * INTERVAL '3 months')", SelectBuilder::expr(['fn' => 'date_sub', 'args' => ['expr' => 'created_at', 'amount' => ['raw' => ':amount'], 'unit' => 'quarter']], 'pgsql'), 'Datumsarithmetik muss auch strukturierte Argumente und Quartale unterstützen.');
sameSql('DATEADD(DAY, 2, created_at)', SelectBuilder::expr(['fn' => 'date_add', 'args' => ['expr' => 'created_at', 'amount' => 2, 'unit' => 'day']], 'sqlsrv'), 'Strukturierte Datumsargumente müssen auch bei SQL Server funktionieren.');

$insert = ['type' => 'insert', 'schema' => 'shop', 'table' => 'products', 'values' => [
  ['name' => "O'Brien", 'active' => true, 'id' => ['default' => true]],
  ['active' => false, 'id' => ['raw' => ':id'], 'name' => 'Öl']
]];
sameSql("INSERT INTO \"shop\".\"products\" (\"name\", \"active\", \"id\") VALUES\n  ('O''Brien', TRUE, DEFAULT),\n  ('Öl', FALSE, :id)", SqlBuilder::build($insert, 'pgsql'), 'INSERT muss Spaltenreihenfolge, Umlaute, Literale und DEFAULT erhalten.');
sameSql(SqlBuilder::build($insert, 'pgsql'), SqlBuilder::build(json_decode(json_encode($insert)), 'pgsql'), 'Verschachtelte PHP-Objekte müssen genauso wie Arrays funktionieren.');
sameSql(SqlBuilder::build($insert, 'pgsql'), SqlBuilder::build(json_encode($insert), 'pgsql'), 'JSON-Definitionen müssen dasselbe SQL erzeugen.');
sameSql('INSERT INTO "products" ("id") SELECT id FROM "archive" LIMIT 1', SqlBuilder::build([
  'type' => 'insert', 'table' => 'products', 'columns' => ['id'], 'query' => ['select' => 'id', 'from' => 'archive', 'limit' => 1]
], 'pgsql'), 'INSERT SELECT muss die gemeinsame Query-Struktur nutzen.');
foreach (['mysql', 'sqlsrv', 'pgsql'] as $dialect) {
  $source = (new SelectBuilder($dialect))->select('id')->from('archive');
  $definition = ['type' => 'insert', 'table' => 'products', 'columns' => ['id'], 'query' => $source];
  $sql = SqlBuilder::build($definition, $dialect);
  check(str_ends_with($sql, $source->statement()), 'Verschachtelte SelectBuilder-Instanzen dürfen durch Objektnormalisierung nicht verloren gehen.');
}
sameSql('UPDATE "products" SET "active" = FALSE, "name" = \'neu\' WHERE ((active = TRUE AND name IS NULL))', SqlBuilder::build([
  'type' => 'update', 'table' => 'products', 'set' => ['active' => false, 'name' => 'neu'], 'where' => ['active' => true, 'name' => null]
], 'pgsql'), 'UPDATE muss boolesche Werte und WHERE-Kurzformen unterstützen.');

$joined = [
  'table' => 'products', 'schema' => 'shop', 'as' => 'p',
  'join' => [['table' => 'stock', 'schema' => 'shop', 'as' => 's', 'on' => ['p.id', '=', 's.product_id']]],
  'where' => ['or' => [['s.amount', '>', 0], ['p.id', '=', 1]]]
];
sameSql('UPDATE "shop"."products" AS "p" SET "active" = TRUE FROM "shop"."stock" AS "s" WHERE (p.id = s.product_id) AND ((s.amount > 0 OR p.id = 1))', SqlBuilder::build($joined + ['type' => 'update', 'set' => ['p.active' => true]], 'pgsql'), 'Join-Update muss FROM verwenden und OR-Gruppierungen erhalten.');
sameSql('DELETE FROM "shop"."products" AS "p" USING "shop"."stock" AS "s" WHERE (p.id = s.product_id) AND ((s.amount > 0 OR p.id = 1))', SqlBuilder::build($joined + ['type' => 'delete'], 'pgsql'), 'Join-Delete muss USING verwenden.');
sameSql('DELETE FROM "products" AS "p" USING "stock" AS "s" WHERE ("p"."id" = "s"."id")', SqlBuilder::build([
  'type' => 'delete', 'table' => 'products', 'as' => 'p', 'join' => [['table' => 'stock', 'as' => 's', 'using' => ['id']]]
], 'pgsql'), 'DML JOIN USING muss zu Gleichheitsbedingungen werden.');
sameSql('UPDATE "products" SET "active" = TRUE', SqlBuilder::build(['type' => 'update', 'table' => 'products', 'set' => [['col' => 'active', 'value' => true]]], 'pgsql'), 'UPDATE muss Listen-Zuweisungen unterstützen.');
sameSql('DELETE FROM "shop"."products"', SqlBuilder::build(['type' => 'delete', 'table' => 'shop.products'], 'pgsql'), 'Schemaqualifizierte Tabellen müssen erhalten bleiben.');
foreach (['mysql' => 'LIMIT 2', 'sqlsrv' => 'TOP (2)'] as $dialect => $fragment) {
  check(str_contains(SqlBuilder::build(['type' => 'delete', 'table' => 'products', 'limit' => 2], $dialect), $fragment), 'DML-Limits vorhandener Dialekte müssen erhalten bleiben.');
}

$table = [
  'type' => 'create_table', 'table' => 'products', 'schema' => 'shop',
  'columns' => [
    ['name' => 'id', 'type' => 'int', 'autoIncrement' => true],
    ['name' => 'active', 'type' => 'boolean', 'nullable' => false, 'default' => true],
    ['name' => 'state', 'type' => 'string', 'enum' => ['neu', "O'Brien"]],
    ['name' => 'data', 'type' => 'jsonb', 'comment' => 'Größe'],
    ['name' => 'category_id', 'type' => 'int']
  ],
  'primaryKey' => ['id'],
  'unique' => [['name' => 'uq_state', 'columns' => ['state']]],
  'foreignKeys' => [['name' => 'fk_category', 'columns' => ['category_id'], 'refTable' => 'categories', 'refColumns' => ['id'], 'onDelete' => 'set_null']],
  'checks' => [['name' => 'ck_id', 'expression' => 'id > 0']],
  'indexes' => [['name' => 'idx_active', 'columns' => ['active']]]
];
$ddl = SqlBuilder::build($table, 'pgsql');
check(\is_array($ddl) && 3 === count($ddl), 'Tabellenerzeugung muss separate Kommentare und Indizes liefern.');
foreach (['CREATE TABLE IF NOT EXISTS "shop"."products"', '"id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL', '"active" BOOLEAN NOT NULL DEFAULT TRUE', '"state" VARCHAR(7) NULL CHECK ("state" IN (\'neu\', \'O\'\'Brien\'))', 'CONSTRAINT "uq_state" UNIQUE ("state")', 'REFERENCES "shop"."categories" ("id") ON DELETE SET NULL', 'CONSTRAINT "ck_id" CHECK (id > 0)'] as $fragment) {
  check(str_contains($ddl[0], $fragment), 'PostgreSQL CREATE TABLE muss '.$fragment.' enthalten.');
}
sameSql('COMMENT ON COLUMN "shop"."products"."data" IS \'Größe\'', $ddl[1], 'Spaltenkommentare benötigen COMMENT ON.');
sameSql('CREATE INDEX IF NOT EXISTS "idx_active" ON "shop"."products" ("active")', $ddl[2], 'PostgreSQL-Indizes müssen eigenständige Statements sein.');
sameSql($ddl, SqlBuilder::build(json_decode(json_encode($table)), 'postgresql'), 'DDL muss verschachtelte JSON-Objekte unterstützen.');
foreach (['mysql', 'sqlsrv'] as $dialect) {
  $definition = ['type' => 'create_table', 'table' => 'children', 'ifNotExists' => false, 'columns' => [['name' => 'id', 'type' => 'int']], 'primaryKey' => ['id'], 'foreignKeys' => [['columns' => ['id'], 'refTable' => 'parents', 'refColumns' => ['id']]]];
  $renderer = new SqlRenderer(new DialectConnection($dialect));
  sameSql(SqlBuilder::build($definition, $dialect), $renderer->render($definition), 'Renderer muss camelCase-DDL-Felder auch in bestehenden Dialekten erhalten.');
  sameSql(SqlBuilder::build($definition, $dialect), SqlBuilder::build(json_decode(json_encode($definition)), $dialect), 'Verschachtelte DDL-Objekte müssen auch in bestehenden Dialekten funktionieren.');
}
sameSql('DROP TABLE IF EXISTS "shop"."products"', SqlBuilder::build(['type' => 'drop_table', 'table' => 'products', 'schema' => 'shop'], 'pgsql'), 'DROP TABLE muss IF EXISTS und Schema unterstützen.');
sameSql('DROP VIEW "products"', SqlBuilder::build(['type' => 'drop_view', 'view' => 'products', 'ifExists' => false], 'pgsql'), 'DROP VIEW muss ohne IF EXISTS möglich sein.');
sameSql('CREATE OR REPLACE VIEW "shop"."active_products" AS SELECT * FROM "shop"."products"', SqlBuilder::build([
  'type' => 'create_view', 'schema' => 'shop', 'view' => 'active_products', 'query' => ['from' => 'shop.products']
], 'pgsql'), 'Views müssen Query-AST und Schema unterstützen.');
sameSql('CREATE VIEW "v" AS SELECT TRUE', SqlBuilder::build(['type' => 'create_view', 'view' => 'v', 'replace' => false, 'select' => ['pgsql' => 'SELECT TRUE']], 'pgsql'), 'View-SELECTs müssen Dialekt-Maps unterstützen.');

$alter = ['type' => 'alter_table', 'schema' => 'shop', 'table' => 'products', 'actions' => [
  ['action' => 'add_column', 'column' => ['name' => 'label', 'type' => 'string', 'length' => 30, 'comment' => 'Name']],
  ['action' => 'modify_column', 'column' => ['name' => 'label', 'type' => 'text', 'nullable' => false, 'default' => 'neu']],
  ['action' => 'rename_column', 'from' => 'label', 'to' => 'title'],
  ['action' => 'rename_table', 'to' => 'articles'],
  ['action' => 'drop_column', 'name' => 'title'],
  ['action' => 'add_index', 'name' => 'idx_id', 'columns' => ['id']],
  ['action' => 'drop_index', 'name' => 'idx_id'],
  ['action' => 'add_unique', 'name' => 'uq_id', 'columns' => ['id']],
  ['action' => 'drop_unique', 'name' => 'uq_id'],
  ['action' => 'add_check', 'name' => 'ck_id', 'expression' => 'id > 0'],
  ['action' => 'drop_check', 'name' => 'ck_id'],
  ['action' => 'add_primary_key', 'name' => 'pk_id', 'columns' => ['id']],
  ['action' => 'drop_primary_key', 'name' => 'pk_id'],
  ['action' => 'add_foreign_key', 'name' => 'fk_category', 'columns' => ['category_id'], 'refTable' => 'categories', 'refColumns' => ['id']],
  ['action' => 'drop_foreign_key', 'name' => 'fk_category'],
  ['action' => 'drop_default', 'columns' => ['active', 'category_id']]
]];
sameSql([
  'ALTER TABLE "shop"."products" ADD COLUMN "label" VARCHAR(30) NULL',
  'COMMENT ON COLUMN "shop"."products"."label" IS \'Name\'',
  'ALTER TABLE "shop"."products" ALTER COLUMN "label" TYPE TEXT',
  'ALTER TABLE "shop"."products" ALTER COLUMN "label" SET NOT NULL',
  'ALTER TABLE "shop"."products" ALTER COLUMN "label" SET DEFAULT \'neu\'',
  'ALTER TABLE "shop"."products" RENAME COLUMN "label" TO "title"',
  'ALTER TABLE "shop"."products" RENAME TO "articles"',
  'ALTER TABLE "shop"."articles" DROP COLUMN "title"',
  'CREATE INDEX "idx_id" ON "shop"."articles" ("id")',
  'DROP INDEX "shop"."idx_id"',
  'ALTER TABLE "shop"."articles" ADD CONSTRAINT "uq_id" UNIQUE ("id")',
  'ALTER TABLE "shop"."articles" DROP CONSTRAINT "uq_id"',
  'ALTER TABLE "shop"."articles" ADD CONSTRAINT "ck_id" CHECK (id > 0)',
  'ALTER TABLE "shop"."articles" DROP CONSTRAINT "ck_id"',
  'ALTER TABLE "shop"."articles" ADD CONSTRAINT "pk_id" PRIMARY KEY ("id")',
  'ALTER TABLE "shop"."articles" DROP CONSTRAINT "pk_id"',
  'ALTER TABLE "shop"."articles" ADD CONSTRAINT "fk_category" FOREIGN KEY ("category_id") REFERENCES "shop"."categories" ("id")',
  'ALTER TABLE "shop"."articles" DROP CONSTRAINT "fk_category"',
  'ALTER TABLE "shop"."articles" ALTER COLUMN "active" DROP DEFAULT',
  'ALTER TABLE "shop"."articles" ALTER COLUMN "category_id" DROP DEFAULT'
], SqlBuilder::build($alter, 'pgsql'), 'Alle vorhandenen ALTER-Actions benötigen gültige PostgreSQL-Entsprechungen.');
sameSql('ALTER TABLE "products" ALTER COLUMN "label" DROP NOT NULL', SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'actions' => [
  ['action' => 'modify_column', 'column' => ['name' => 'label', 'nullable' => true]]
]], 'pgsql'), 'PostgreSQL-Spaltenänderungen dürfen nicht implizit den Typ überschreiben.');

foreach (['int' => 'INTEGER', 'tinyint' => 'SMALLINT', 'smallint' => 'SMALLINT', 'bigint' => 'BIGINT', 'boolean' => 'BOOLEAN', 'text' => 'TEXT', 'mediumtext' => 'TEXT', 'longtext' => 'TEXT', 'blob' => 'BYTEA', 'tinyblob' => 'BYTEA', 'mediumblob' => 'BYTEA', 'longblob' => 'BYTEA', 'datetime' => 'TIMESTAMP WITHOUT TIME ZONE', 'timestamp' => 'TIMESTAMP WITHOUT TIME ZONE', 'date' => 'DATE', 'time' => 'TIME WITHOUT TIME ZONE', 'decimal' => 'NUMERIC(10,2)', 'json' => 'JSON', 'jsonb' => 'JSONB', 'uuid' => 'UUID', 'string' => 'VARCHAR(255)'] as $type => $sqlType) {
  sameSql('CREATE TABLE "types" ('."\n  ".'"value" '.$sqlType.' NULL'."\n)", SqlBuilder::build(['type' => 'create_table', 'table' => 'types', 'ifNotExists' => false, 'columns' => [['name' => 'value', 'type' => $type]]], 'pgsql'), 'PostgreSQL-Typabbildung für '.$type.' muss stimmen.');
}

$trigger = ['type' => 'create_trigger', 'name' => 'trg_products', 'schema' => 'shop', 'table' => 'products', 'timing' => ['mysql' => 'after', 'postgresql' => 'before'], 'events' => ['insert', 'update'], 'function' => 'shop.audit_product', 'functionArgs' => ['Änderung']];
$triggerSql = 'CREATE TRIGGER "trg_products" BEFORE INSERT OR UPDATE ON "shop"."products" FOR EACH ROW EXECUTE PROCEDURE "shop"."audit_product"(\'Änderung\')';
sameSql($triggerSql, SqlBuilder::build($trigger, 'pgsql'), 'PostgreSQL-Trigger müssen auf bestehende Funktionen verweisen.');
sameSql(['DROP TRIGGER IF EXISTS "trg_products" ON "shop"."products"', $triggerSql], SqlBuilder::build(array_replace($trigger, ['type' => 'alter_trigger']), 'pgsql'), 'Triggeränderung muss DROP und CREATE liefern.');
sameSql('DROP TRIGGER "trg_products" ON "shop"."products"', SqlBuilder::build(['type' => 'drop_trigger', 'name' => 'trg_products', 'table' => 'products', 'schema' => 'shop', 'ifExists' => false], 'pgsql'), 'DROP TRIGGER benötigt in PostgreSQL die Tabelle.');

$pgRenderer = new SqlRenderer(new DialectConnection('pgsql', '18.0'));
sameSql(SqlBuilder::build($update, 'pgsql'), $pgRenderer->render($update), 'PDO pgsql muss automatisch erkannt werden.');
sameSql(SelectBuilder::build($page, 'pgsql'), $pgRenderer->render(json_decode(json_encode($page))), 'Renderer muss PostgreSQL-SELECT-Objekte erkennen.');
sameSql($ddl, $pgRenderer->renderAll($table), 'Renderer muss PostgreSQL-DDL-Listen flach liefern.');
sameSql('SELECT TRUE', $pgRenderer->render(['mysql' => 'SELECT 1', 'sqlsrv' => 'SELECT 2', 'pgsql' => 'SELECT TRUE']), 'Renderer muss den richtigen Dialektzweig wählen.');
foreach (['pgsql', 'postgres', 'postgresql'] as $alias) {
  sameSql('SELECT TRUE', $pgRenderer->render(['only' => [$alias], $alias => 'SELECT TRUE']), 'Renderer-Filter müssen PostgreSQL-Aliasse akzeptieren.');
  sameSql([], $pgRenderer->renderAll(['exclude' => $alias, 'sql' => 'SELECT 1']), 'Renderer muss PostgreSQL ausschließen können.');
}
sameSql([], $pgRenderer->renderAll(['only' => ['mysql', 'sqlsrv'], 'sql' => 'SELECT 1']), 'Fremde Schritte müssen für PostgreSQL übersprungen werden.');
sameSql('SELECT 1', $pgRenderer->render(['include' => '*', 'sql' => 'SELECT 1']), 'Generische SQL-Fallbacks müssen erhalten bleiben.');

rejectsSql(static fn () => SelectBuilder::build(['from' => 'products', 'limit' => -1], 'pgsql'), 'Negative PostgreSQL-Limits müssen fehlschlagen.', 'negativ');
rejectsSql(static fn () => SelectBuilder::build(['from' => 'products', 'offset' => -1], 'pgsql'), 'Negative PostgreSQL-Offsets müssen fehlschlagen.', 'negativ');
rejectsSql(static fn () => SelectBuilder::expr(['fn' => 'date_format', 'args' => ['created_at', '%U']], 'pgsql'), 'Nicht übersetzbare Datumsformate müssen fehlschlagen.', '%U');
rejectsSql(static fn () => SelectBuilder::expr(['fn' => 'date_format', 'args' => ['created_at', ['col' => 'format']]], 'pgsql'), 'Dynamische Datumsformate müssen fehlschlagen.', 'literal');
foreach (['update', 'delete'] as $type) {
  rejectsSql(static fn () => SqlBuilder::build(['type' => $type, 'table' => 'products', 'set' => ['active' => true], 'limit' => 1], 'pgsql'), 'PostgreSQL-DML darf LIMIT nicht still ignorieren.', 'limit');
  rejectsSql(static fn () => SqlBuilder::build(['type' => $type, 'table' => 'products', 'set' => ['active' => true], 'left_join' => [['table' => 'stock', 'on' => ['products.id', '=', 'stock.id']]]], 'pgsql'), 'DML-Outer-Joins dürfen keine andere Semantik erhalten.', 'INNER JOIN');
}
foreach ([['unsigned' => true], ['onUpdate' => 'CURRENT_TIMESTAMP'], ['autoIncrement' => true, 'default' => 1], ['autoIncrement' => true, 'nullable' => true]] as $option) {
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [array_merge(['name' => 'id', 'type' => 'int'], $option)]], 'pgsql'), 'Nicht unterstützte Spaltenkombinationen müssen fehlschlagen.', 'PostgreSQL');
}
foreach (['drop_index', 'drop_unique', 'drop_foreign_key', 'drop_primary_key'] as $action) {
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'actions' => [['action' => $action, 'columns' => ['id']]]], 'pgsql'), 'PostgreSQL benötigt bekannte Namen zum Entfernen von Indizes/Constraints.', 'name');
}
rejectsSql(static fn () => SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'actions' => [['action' => 'add_column', 'column' => ['name' => 'id', 'type' => 'int'], 'first' => true]]], 'pgsql'), 'FIRST darf nicht still ignoriert werden.', 'FIRST/AFTER');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'actions' => [['action' => 'modify_column', 'column' => ['name' => 'state', 'enum' => ['neu']]]]], 'pgsql'), 'ENUM-Änderung benötigt eine explizite Constraint-Migration.', 'enum/identity');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'alter_table', 'table' => 'products', 'actions' => [['action' => 'drop_default', 'name' => 'df_active']]], 'pgsql'), 'PostgreSQL hat keine benannten Default-Constraints.', 'columns');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_trigger', 'name' => 'trg', 'table' => 'products', 'event' => 'insert', 'body' => 'SELECT 1'], 'pgsql'), 'Inline-Trigger-Bodies sind nicht automatisch übertragbar.', 'function');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'drop_trigger', 'name' => 'trg'], 'pgsql'), 'DROP TRIGGER benötigt die Tabelle.', 'Bezeichner');
rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'products', 'columns' => [['name' => 'id', 'type' => 'imaginary']]], 'pgsql'), 'Unbekannte PostgreSQL-Typen dürfen nicht zu VARCHAR werden.', 'Spaltentyp');
