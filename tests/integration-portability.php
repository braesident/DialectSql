<?php

declare(strict_types=1);

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\WhereBuilder;

/** Checks typed defaults and explicit column expressions on isolated test objects. */
function runPortabilityIntegration(string $dialect, string $suffix, callable $run, array &$created): void
{
  $table = 'dialectsql_portable_'.$suffix;
  $run(['type' => 'create_table', 'table' => $table, 'ifNotExists' => false, 'columns' => [
    ['name' => 'id', 'type' => 'int', 'nullable' => false],
    ['name' => 'DisplayName', 'type' => 'string', 'length' => 80, 'nullable' => false],
    ['name' => 'Enabled', 'type' => 'boolean', 'nullable' => false, 'default' => 1],
    ['name' => 'Disabled', 'type' => 'boolean', 'nullable' => false, 'default' => 0],
    ['name' => 'NumericFlag', 'type' => 'boolean', 'pgsql' => 'SMALLINT', 'nullable' => false, 'default' => 0]
  ], 'primaryKey' => ['id']]);
  $created[] = ['type' => 'drop_table', 'table' => $table];
  $run(['type' => 'insert', 'table' => $table, 'values' => [['id' => 1, 'DisplayName' => 'Zulu'], ['id' => 2, 'DisplayName' => 'Alpha']]]);
  $query = ['select' => ['id', ['col' => 'DisplayName']], 'from' => $table, 'order_by' => [['col' => 'DisplayName', 'dir' => 'ASC']]];
  $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'id'));
  $where = (new WhereBuilder())
    ->andEqualsParam(SelectBuilder::col('Enabled'), true, 'enabled')
    ->andEqualsParam((object) ['col' => 'Disabled'], false, 'disabled')
    ->andEqParam(SelectBuilder::col('NumericFlag'), 0, 'numeric');
  sameSql([2, 1], $ids($run($query + ['where' => $where->conditions()], $where->params())), 'Boolean-Defaults, numerische Overrides und gequotete WhereBuilder-/ORDER-BY-Spalten müssen zusammen ausführbar sein.');
  $where = (new WhereBuilder())->andParam(SelectBuilder::fn('lower', SelectBuilder::col('DisplayName')), '=', 'alpha', 'label');
  sameSql([2], $ids($run($query + ['where' => $where->conditions()], $where->params())), 'WhereBuilder-Funktionsausdrücke müssen auf dem Server funktionieren.');
  $where = (new WhereBuilder())->andIn((object) ['col' => 'DisplayName'], ['Zulu', 'Alpha']);
  sameSql([2, 1], $ids($run($query + ['where' => $where->conditions()], $where->params())), 'IN-Ausdrucksobjekte müssen Parameter und Sortierung erhalten.');

  $union = ['select' => ['id', ['col' => 'DisplayName']], 'from' => $table, 'where' => ['id', '=', 1],
    'union_all' => [['select' => ['id', ['col' => 'DisplayName']], 'from' => $table, 'where' => ['id', '=', 2]]],
    'union_order_by' => [['column' => 'DisplayName', 'direction' => 'DESC']]
  ];
  sameSql([1, 2], $ids($run($union)), 'Globale UNION-Sortierung muss explizite Spalten mit gemischter Groß-/Kleinschreibung erhalten.');
  $run(['type' => 'alter_table', 'table' => $table, 'actions' => [['action' => 'add_column', 'column' => ['name' => 'AddedFlag', 'type' => 'boolean', 'nullable' => false, 'default' => 1]]]]);
  $where = (new WhereBuilder())->andEqualsParam(SelectBuilder::col('AddedFlag'), true);
  sameSql([2, 1], $ids($run($query + ['where' => $where->conditions()], $where->params())), 'ADD COLUMN muss numerische Boolean-Defaults für vorhandene Datensätze anwenden.');
  if ('sqlsrv' !== $dialect) {
    $run(['type' => 'alter_table', 'table' => $table, 'actions' => [['action' => 'modify_column', 'column' => ['name' => 'Enabled', 'type' => 'boolean', 'nullable' => false, 'default' => 0]]]]);
    $run(['type' => 'insert', 'table' => $table, 'values' => [['id' => 3, 'DisplayName' => 'Beta']]]);
    $where = (new WhereBuilder())->andEqualsParam(SelectBuilder::col('id'), 3, 'id')->andEqualsParam(SelectBuilder::col('Enabled'), false, 'enabled');
    sameSql([3], $ids($run($query + ['where' => $where->conditions()], $where->params())), 'MODIFY mit bekanntem Boolean-Typ muss den neuen Default anwenden.');
  }
}
