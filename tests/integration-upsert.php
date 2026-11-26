<?php

declare(strict_types=1);

/** Executes upsert checks on objects owned by the integration runner. */
function runUpsertIntegration(string $dialect, string $suffix, callable $run, array &$created): void
{
  $table = 'dialectsql_upsert_'.$suffix;
  $run(['type' => 'create_table', 'table' => $table, 'ifNotExists' => false, 'columns' => [
    ['name' => 'id', 'type' => 'int', 'nullable' => false, 'autoIncrement' => true],
    ['name' => 'tenant', 'type' => 'int', 'nullable' => false],
    ['name' => 'sku', 'type' => 'string', 'length' => 30, 'nullable' => false],
    ['name' => 'external', 'type' => 'string', 'length' => 30, 'nullable' => false],
    ['name' => 'name', 'type' => 'string', 'length' => 80, 'nullable' => false],
    ['name' => 'quantity', 'type' => 'int', 'nullable' => false, 'default' => 0]
  ], 'primaryKey' => ['id'], 'unique' => [
    ['name' => 'uq_upsert_key_'.$suffix, 'columns' => ['tenant', 'sku']],
    ['name' => 'uq_upsert_external_'.$suffix, 'columns' => ['external']]
  ]]);
  $created[] = ['type' => 'drop_table', 'table' => $table];
  $definition = [
    'type' => 'upsert', 'table' => $table,
    'conflictColumns' => ['tenant', 'sku'], 'mysqlConflict' => 'any_unique',
    'values' => [['tenant' => 7, 'sku' => 'A', 'external' => 'X', 'name' => 'Öl', 'quantity' => 2]],
    'set' => [
      'name' => ['incoming' => 'name'],
      'quantity' => ['op' => '+', 'left' => ['col' => 'quantity'], 'right' => ['incoming' => 'quantity']]
    ]
  ];
  if ('mysql' !== $dialect) {
    $definition['returning'] = ['id', 'tenant', 'sku', 'name', 'quantity'];
  }
  $returned = $run($definition);
  $query = ['select' => ['id', 'tenant', 'sku', ['col' => 'name'], 'quantity'], 'from' => $table, 'order_by' => 'tenant'];
  $rows = $run($query);
  $firstId = (int) $rows[0]['id'];
  check(1 === count($rows) && $firstId > 0 && 2 === (int) $rows[0]['quantity'], 'Upsert muss neue Zeilen einschließlich generierter IDs anlegen.');
  if ('mysql' !== $dialect) {
    check(1 === count($returned) && $firstId === (int) $returned[0]['id'], 'Upsert-Rückgabe muss die generierte ID liefern.');
  }

  $definition['values'] = [
    ['tenant' => 7, 'sku' => 'A', 'external' => 'X', 'name' => 'Öl neu', 'quantity' => 3],
    ['quantity' => 4, 'external' => 'Y', 'sku' => 'A', 'name' => '東京', 'tenant' => 8]
  ];
  $returned = $run($definition);
  $rows = $run($query);
  sameSql([7, 8], array_map('intval', array_column($rows, 'tenant')), 'Zusammengesetzte Konfliktschlüssel müssen gleiche SKUs verschiedener Mandanten trennen.');
  sameSql([5, 4], array_map('intval', array_column($rows, 'quantity')), 'SET muss vorhandene Zeilen verändern und neue Zeilen mit den Eingangswerten anlegen.');
  sameSql(['Öl neu', '東京'], array_column($rows, 'name'), 'Mehrzeilen-Upsert muss aktualisierte und neue Unicode-Werte erhalten.');
  check($firstId === (int) $rows[0]['id'] && $firstId !== (int) $rows[1]['id'], 'Upsert darf die Identität einer vorhandenen Zeile nicht ersetzen.');
  if ('mysql' !== $dialect) {
    $tenants = array_map('intval', array_column($returned, 'tenant'));
    sort($tenants);
    sameSql([7, 8], $tenants, 'RETURNING/OUTPUT muss eingefügte und aktualisierte Zeilen liefern, unabhängig von der Reihenfolge.');
  }

  $definition['values'] = [[
    'tenant' => ['param' => 'upsert_tenant'], 'sku' => ['param' => 'upsert_sku'],
    'external' => 'Y', 'name' => ['param' => 'upsert_name'], 'quantity' => ['param' => 'upsert_quantity']
  ]];
  $run($definition, [':upsert_tenant' => 8, ':upsert_sku' => 'A', ':upsert_name' => 'Parameter 東京', ':upsert_quantity' => 6]);
  $rows = $run($query);
  check(10 === (int) $rows[1]['quantity'] && 'Parameter 東京' === $rows[1]['name'], 'Upsert muss gebundene Eingabewerte einmalig binden und im UPDATE wiederverwenden.');

  // MySQL cannot target one selected UNIQUE key. Its explicitly chosen broader behavior is intentional.
  $definition['values'] = [['tenant' => 9, 'sku' => 'B', 'external' => 'X', 'name' => 'secondary', 'quantity' => 1]];
  if ('mysql' === $dialect) {
    $run($definition);
  } else {
    try {
      $run($definition);
      check(false, 'Ein Konflikt mit einem anderen UNIQUE-Schlüssel darf nicht als Zielschlüssel behandelt werden.');
    } catch (PDOException $error) {
      check(str_starts_with((string) $error->getCode(), '23'), 'Ein Konflikt außerhalb des gewählten Schlüssels muss als Constraint-Verletzung fehlschlagen.');
    }
  }
  $rows = $run($query);
  sameSql([7, 8], array_map('intval', array_column($rows, 'tenant')), 'Ein zweiter UNIQUE-Konflikt darf keine zusätzliche Zeile anlegen.');
  check(('mysql' === $dialect ? 6 : 5) === (int) $rows[0]['quantity'], 'Die dokumentierten Unterschiede der Konfliktziele müssen auf dem Server sichtbar sein.');
}
