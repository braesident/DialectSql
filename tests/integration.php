<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\SqlRenderer;

$options = getopt('', ['require-all', 'dialect:']);
$dialects = isset($options['dialect']) ? [$options['dialect']] : ['mysql', 'sqlsrv', 'pgsql'];
$required = \array_key_exists('require-all', $options);
$failures = 0;
$executed = 0;

foreach ($dialects as $dialect) {
  if ( ! \in_array($dialect, ['mysql', 'sqlsrv', 'pgsql'], true)) {
    fwrite(STDERR, "Unbekannter Testdialekt.\n");
    exit(1);
  }
  $prefix = 'DIALECTSQL_TEST_'.strtoupper($dialect);
  $dsn = getenv($prefix.'_DSN');
  if (false === $dsn || '' === $dsn) {
    echo "SKIP {$dialect}: {$prefix}_DSN fehlt.\n";
    $failures += $required ? 1 : 0;
    continue;
  }
  if ( ! str_starts_with($dsn, $dialect.':') || ! \in_array($dialect, PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "FAIL {$dialect}: DSN-Typ oder PDO-Treiber passt nicht.\n");
    ++$failures;
    continue;
  }

  $created = [];
  $renderer = null;
  $pdo = null;
  try {
    // Only explicitly configured connections are used; no application configuration is read.
    $pdo = new PDO($dsn, false !== getenv($prefix.'_USER') ? getenv($prefix.'_USER') : null, false !== getenv($prefix.'_PASSWORD') ? getenv($prefix.'_PASSWORD') : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ('sqlsrv' !== $dialect) {
      $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    if ('mysql' === $dialect) {
      $pdo->exec('SET NAMES utf8mb4');
    }
    $renderer = new SqlRenderer($pdo);
    $run = static function (array $definition, array $params = []) use ($pdo, $renderer): array {
      $rows = [];
      foreach ($renderer->renderAll($definition) as $sql) {
        $statement = $pdo->prepare($sql);
        foreach ($params as $name => $value) {
          $type = null === $value ? PDO::PARAM_NULL : (\is_bool($value) ? PDO::PARAM_BOOL : (\is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
          $statement->bindValue($name, $value, $type);
        }
        $statement->execute();
        if ($statement->columnCount() > 0) {
          $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        $statement->closeCursor();
      }

      return $rows;
    };
    $ids = static fn (array $rows): array => array_map(static fn ($row) => (int) $row['id'], $rows);
    $suffix = bin2hex(random_bytes(8));
    $items = 'dialectsql_items_'.$suffix;
    $stock = 'dialectsql_stock_'.$suffix;
    $view = 'dialectsql_view_'.$suffix;

    $run(['type' => 'create_table', 'table' => $items, 'ifNotExists' => false, 'columns' => [
      ['name' => 'id', 'type' => 'int', 'nullable' => false],
      ['name' => 'LabelText', 'type' => 'string', 'length' => 80, 'nullable' => false],
      ['name' => 'price', 'type' => 'decimal', 'precision' => 10, 'scale' => 2, 'default' => 0],
      ['name' => 'active', 'type' => 'boolean', 'nullable' => false, 'default' => false],
      ['name' => 'marker', 'type' => 'string', 'length' => 40]
    ], 'primaryKey' => ['id']]);
    $created[] = ['type' => 'drop_table', 'table' => $items];
    $run(['type' => 'create_table', 'table' => $stock, 'ifNotExists' => false, 'columns' => [
      ['name' => 'id', 'type' => 'int', 'nullable' => false],
      ['name' => 'quantity', 'type' => 'int', 'nullable' => false]
    ], 'primaryKey' => ['id']]);
    $created[] = ['type' => 'drop_table', 'table' => $stock];

    $run(['type' => 'insert', 'table' => $items, 'values' => [
      ['id' => 1, 'LabelText' => 'Öl 東京', 'price' => 10, 'active' => true, 'marker' => null],
      ['id' => 2, 'LabelText' => "O'Brien", 'price' => 20, 'active' => false, 'marker' => ''],
      ['id' => 3, 'LabelText' => 'a\\b', 'price' => 30, 'active' => true, 'marker' => 'x']
    ]]);
    $run(['type' => 'insert', 'table' => $stock, 'values' => [['id' => 2, 'quantity' => 100], ['id' => 3, 'quantity' => -1]]]);
    $query = ['select' => ['id', ['col' => 'LabelText']], 'from' => $items, 'order_by' => 'id'];
    $rows = $run($query);
    sameSql(['Öl 東京', "O'Brien", 'a\\b'], array_column($rows, 'LabelText'), 'Literale müssen auf '.$dialect.' Unicode, Apostrophe und Backslashes erhalten.');
    sameSql([2, 3], $ids($run($query + ['offset' => 1])), 'OFFSET ohne LIMIT muss Zeilen erhalten.');
    sameSql([], $run($query + ['limit' => 0, 'offset' => 2]), 'LIMIT 0 muss eine leere Ergebnismenge liefern.');
    sameSql([], $run(['select' => ['raw' => 'COUNT(*)'], 'from' => $items, 'limit' => 0]), 'Auch aggregierte LIMIT-0-Abfragen müssen leer bleiben.');
    sameSql([], $run($query + ['where' => ['id', 'IN', []]]), 'Leeres IN muss keine Zeilen liefern.');
    sameSql([1, 2, 3], $ids($run($query + ['where' => ['id', 'NOT IN', []]])), 'Leeres NOT IN muss alle Zeilen zulassen.');
    sameSql([1], $ids($run($query + ['where' => ['marker', '=', null]])), 'NULL-Gleichheit muss auf dem Server funktionieren.');
    sameSql([2], $ids($run($query + ['where' => ['id', '=', ['param' => 'id']]], [':id' => 2])), 'Gebundene Parameter müssen ausgeführt werden.');

    $cte = ['with' => [['name' => 'selected', 'query' => ['select' => 'id', 'from' => $items, 'where' => ['id', '>=', 2]]]], 'select' => 'id', 'from' => 'selected', 'order_by' => 'id'];
    sameSql([2, 3], $ids($run($cte)), 'SELECT-CTEs müssen auf '.$dialect.' ausführbar sein.');
    $recursive = ['recursive' => true, 'with' => [['name' => 'seq', 'columns' => ['n'], 'query' => [
      'select' => ['expr' => ['value' => 1], 'as' => 'n'],
      'union_all' => [['select' => ['expr' => ['op' => '+', 'left' => 'n', 'right' => 1], 'as' => 'n'], 'from' => 'seq', 'where' => ['n', '<', 3]]]
    ]]], 'select' => ['col' => 'n'], 'from' => 'seq', 'order_by' => 'n'];
    sameSql([1, 2, 3], array_map('intval', array_column($run($recursive), 'n')), 'Rekursive CTEs müssen auf '.$dialect.' ausführbar sein.');
    $union = ['select' => 'id', 'from' => $items, 'where' => ['id', '<=', 2], 'union_all' => [['select' => 'id', 'from' => $items, 'where' => ['id', '>=', 2]]], 'union_order_by' => [['expr' => 'id', 'dir' => 'DESC']], 'union_limit' => 2, 'union_offset' => 1];
    sameSql([2, 2], $ids($run($union)), 'UNION muss vor globaler Sortierung und Paginierung vollständig ausgewertet werden.');

    $run(['type' => 'update', 'table' => $items, 'as' => 'p', 'join' => [['table' => $stock, 'as' => 's', 'on' => ['p.id', '=', 's.id']]], 'set' => ['price' => ['col' => 's.quantity']], 'where' => ['p.id', '=', 2]]);
    $rows = $run(['select' => 'price', 'from' => $items, 'where' => ['id', '=', 2]]);
    check(100.0 === (float) $rows[0]['price'], 'Join-UPDATE muss den Wert der passenden Quellzeile übernehmen.');
    $run(['type' => 'delete', 'table' => $items, 'as' => 'p', 'join' => [['table' => $stock, 'as' => 's', 'on' => ['p.id', '=', 's.id']]], 'where' => ['s.quantity', '<', 0]]);
    sameSql([1, 2], $ids($run($query)), 'Join-DELETE darf nur die ausgewählten Zielzeilen entfernen.');

    if ('mysql' !== $dialect) {
      $rows = $run(['type' => 'insert', 'table' => $items, 'values' => [['id' => 4, 'LabelText' => 'neu']], 'returning' => ['id', ['col' => 'LabelText', 'as' => 'label']]]);
      check(4 === (int) $rows[0]['id'] && 'neu' === $rows[0]['label'], 'INSERT-Rückgabe muss eingefügte Zielwerte liefern.');
      $rows = $run(['type' => 'update', 'table' => $items, 'set' => ['LabelText' => 'anders'], 'where' => ['id', '=', 4], 'returning' => ['LabelText']]);
      sameSql(['anders'], array_column($rows, 'LabelText'), 'UPDATE-Rückgabe muss den neuen Zustand liefern.');
      $rows = $run(['type' => 'delete', 'table' => $items, 'where' => ['id', '=', 4], 'returning' => ['LabelText']]);
      sameSql(['anders'], array_column($rows, 'LabelText'), 'DELETE-Rückgabe muss den entfernten Zustand liefern.');
    }

    $run(['type' => 'alter_table', 'table' => $items, 'actions' => [['action' => 'add_column', 'column' => ['name' => 'extra', 'type' => 'string', 'length' => 20, 'nullable' => false, 'default' => 'demo']]]]);
    sameSql(['demo', 'demo'], array_column($run(['select' => ['col' => 'extra'], 'from' => $items, 'order_by' => 'id']), 'extra'), 'ADD COLUMN muss bestehende Zeilen mit dem Standardwert ergänzen.');
    $run(['type' => 'alter_table', 'table' => $items, 'actions' => [
      ['action' => 'drop_default', 'columns' => ['extra']], ['action' => 'drop_column', 'name' => 'extra'],
      ['action' => 'add_index', 'name' => 'idx_'.$suffix, 'columns' => ['price']],
      ['action' => 'drop_index', 'name' => 'idx_'.$suffix]
    ]]);
    $run(['type' => 'create_view', 'view' => $view, 'replace' => false, 'query' => ['select' => 'id', 'from' => $items]]);
    $created[] = ['type' => 'drop_view', 'view' => $view];
    sameSql([1, 2], $ids($run(['select' => 'id', 'from' => $view, 'order_by' => 'id'])), 'Views müssen dieselben Datensätze bereitstellen.');
    ++$executed;
    echo "PASS {$dialect}: DDL, DML, Literale, Parameter, CTEs und Paginierung ausgeführt.\n";
  } catch (Throwable $error) {
    ++$failures;
    fwrite(STDERR, "FAIL {$dialect}: ".$error->getMessage()."\n");
  } finally {
    foreach (array_reverse($created) as $definition) {
      try {
        foreach ($renderer->renderAll($definition) as $sql) {
          $pdo->exec($sql);
        }
      } catch (Throwable $error) {
        ++$failures;
        fwrite(STDERR, "FAIL {$dialect}: Testobjekt konnte nicht entfernt werden.\n");
      }
    }
  }
}
echo "Integration: {$executed} Dialekte ausgeführt, {$checks} Prüfungen, {$failures} Fehler.\n";
exit(0 === $failures ? 0 : 1);
