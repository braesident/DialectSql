<?php

declare(strict_types=1);

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\WhereBuilder;

/** Uses only namespaces and tables created on the explicitly configured test connection. */
function runOptionsIntegration(string $dialect, string $suffix, callable $run, array &$created): void
{
  $namespace = 'dialectsql_opts_'.$suffix;
  if ('mysql' === $dialect) {
    $database = $run(['select' => ['expr' => SelectBuilder::fn('database'), 'as' => 'db']]);
    $namespace = (string) ($database[0]['db'] ?? '');
    check('' !== $namespace, 'Der MySQL-Test-DSN muss eine Datenbank auswählen.');
  } else {
    $quoted = 'sqlsrv' === $dialect ? '['.$namespace.']' : '"'.$namespace.'"';
    $run(['type' => 'raw', 'sql' => 'CREATE SCHEMA '.$quoted]);
    $created[] = ['type' => 'raw', 'sql' => 'DROP SCHEMA '.$quoted];
  }
  $schemas = [$dialect => $namespace];
  $table = 'dialectsql_search_'.$suffix;
  $run(['type' => 'create_table', 'table' => $table, 'schema' => $schemas, 'ifNotExists' => false, 'columns' => [
    ['name' => 'id', 'type' => 'int', 'nullable' => false, 'autoIncrement' => ['default' => true]],
    ['name' => 'Name', 'type' => 'string', 'length' => ['default' => 80]],
    ['name' => 'schema', 'type' => 'string', 'length' => 80],
    ['name' => 'Flag', 'type' => ['mysql' => 'boolean', 'sqlsrv' => 'boolean', 'pgsql' => 'smallint'], 'nullable' => ['default' => false], 'default' => ['default' => 0]],
    ['name' => 'Updated', 'type' => 'timestamp', 'default' => 'CURRENT_TIMESTAMP', 'onUpdate' => ['mysql' => 'CURRENT_TIMESTAMP']]
  ], 'primaryKey' => ['id']]);
  $created[] = ['type' => 'drop_table', 'table' => $table, 'schema' => $schemas];
  $run(['type' => 'insert', 'table' => $table, 'schema' => $schemas, 'values' => [
    ['Name' => 'Alpha', 'schema' => '{"mysql":"literal"}'], ['Name' => 'alpha', 'schema' => null],
    ['Name' => 'literal_%', 'schema' => null], ['Name' => null, 'schema' => null],
    ['Name' => 'Öl', 'schema' => null], ['Name' => 'öl', 'schema' => null]
  ]]);
  $query = ['select' => ['id', ['col' => 'Name'], ['col' => 'schema']], 'from' => ['table' => $table, 'schema' => $schemas], 'order_by' => 'id'];
  $rows = $run($query);
  check(6 === count($rows) && (int) $rows[0]['id'] > 0, 'Schema-/Spalten-Maps müssen CREATE, Identity und INSERT gemeinsam ausführen.');
  sameSql('{"mysql":"literal"}', $rows[0]['schema'], 'SQL-Daten dürfen nicht als Feld-Maps interpretiert werden.');
  $ci = ['caseSensitive' => false, 'collation' => ['mysql' => 'utf8mb4_unicode_ci', 'sqlsrv' => 'Latin1_General_100_CI_AS']];
  $cs = ['caseSensitive' => true, 'collation' => ['mysql' => 'utf8mb4_bin', 'sqlsrv' => 'Latin1_General_100_BIN2']];
  $where = (new WhereBuilder())->andLikeParam(SelectBuilder::col('Name'), 'a%', 'term', $ci)->andEqualsParam(SelectBuilder::col('Flag'), 0, 'flag');
  sameSql(['Alpha', 'alpha'], array_column($run($query + ['where' => $where->conditions()], $where->params()), 'Name'), 'Explizit case-insensitive Suche muss beide Schreibweisen finden.');
  $where = (new WhereBuilder())->andLikeParam((object) ['col' => 'Name'], 'A%', 'term', $cs);
  sameSql(['Alpha'], array_column($run($query + ['where' => $where->conditions()], $where->params()), 'Name'), 'Explizit case-sensitive Suche darf die andere Schreibweise nicht finden.');
  $where = (new WhereBuilder())->andLikeParam(['col' => 'Name'], 'literal!_!%', 'term', $cs + ['escape' => '!']);
  sameSql(['literal_%'], array_column($run($query + ['where' => $where->conditions()], $where->params()), 'Name'), 'ESCAPE muss Prozent und Unterstrich als Literale behandeln.');
  $where = (new WhereBuilder())->andLike(['col' => 'Name'], 'Ö_', $cs);
  sameSql(['Öl'], array_column($run($query + ['where' => $where->conditions()]), 'Name'), 'Case-sensitive Suche muss Unicode-Zeichen erhalten und Unterstriche als Zeichenwildcard behandeln.');
  $where = (new WhereBuilder())->andLikeParam(['col' => 'Name'], 'a%', 'term', $ci + ['not' => true]);
  check(3 === count($run($query + ['where' => $where->conditions()], $where->params())), 'NOT LIKE/ILIKE muss NULL ausschließen und beide Schreibweisen berücksichtigen.');
  $where = (new WhereBuilder())->andLike(['col' => 'Name'], null, $cs);
  sameSql([], $run($query + ['where' => $where->conditions()]), 'Ein NULL-Suchmuster darf keine Treffer erzeugen.');
  $run(['type' => 'update', 'table' => $table, 'schema' => $schemas, 'set' => ['Flag' => 1], 'where' => SelectBuilder::like(['col' => 'Name'], 'Alpha', $cs)]);
  $where = (new WhereBuilder())->andEqualsParam(['col' => 'Flag'], 1, 'flag');
  sameSql(['Alpha'], array_column($run($query + ['where' => $where->conditions()], $where->params()), 'Name'), 'Direkte LIKE-Prädikate müssen auch im DML-WHERE funktionieren.');
  $run(['type' => 'alter_table', 'table' => $table, 'schema' => $schemas, 'actions' => [['action' => 'add_column', 'column' => ['name' => 'Extra', 'type' => ['default' => 'int'], 'default' => ['default' => 7], 'nullable' => ['default' => false]]]]]);
  $rows = $run(['select' => ['col' => 'Extra'], 'from' => ['table' => $table, 'schema' => $schemas]]);
  sameSql([7, 7, 7, 7, 7, 7], array_map('intval', array_column($rows, 'Extra')), 'ALTER muss Schema- und Spalten-Maps vor DDL-Erzeugung auflösen.');
}
