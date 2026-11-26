<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\SqlBuilder;
use Braesident\DialectSql\SqlRenderer;
use Braesident\DialectSql\WhereBuilder;

final class DialectConnection extends PDO
{
  public function __construct(private string $driver, private string $version = '8.0.36')
  {
  }

  public function getAttribute(int $attribute): mixed
  {
    return PDO::ATTR_DRIVER_NAME === $attribute ? $this->driver : $this->version;
  }
}

$update = [
  'type' => 'update', 'table' => 'config',
  'set' => ['value' => ['raw' => ':value']],
  'where' => ['id', '=', ['raw' => ':id']],
];
$mysql = SqlBuilder::build($update, 'mysql');
$sqlsrv = SqlBuilder::build($update, 'sqlsrv');
check(str_contains($mysql, '`config`') && str_contains($mysql, '`value` = :value'), 'MySQL muss Bezeichner und Parameter erhalten.');
check(str_contains($sqlsrv, '[dbo].[config]') && str_contains($sqlsrv, '[value] = :value'), 'SQL Server muss Schema und Bezeichner korrekt rendern.');
check(str_contains(SelectBuilder::build(['select' => '*', 'from' => 'config', 'limit' => 2], 'mysql'), 'LIMIT 2'), 'MySQL-Pagination muss erhalten bleiben.');
check(str_contains(SelectBuilder::build(['select' => '*', 'from' => 'config', 'limit' => 2], 'sqlsrv'), 'FETCH NEXT 2 ROWS ONLY'), 'SQL-Server-Pagination muss erhalten bleiben.');
check(str_contains(SelectBuilder::expr(['fn' => 'ifnull', 'args' => ['value', ['value' => '']]], 'sqlsrv'), 'ISNULL('), 'Funktionsübersetzung muss erhalten bleiben.');

$where = new WhereBuilder();
$where->andEqualsParam('id', 42, 'id')->andIn('status', ['active', 'new'], 'status');
check(3 === count($where->params()), 'WhereBuilder muss Werte separat binden.');
check(str_contains(SelectBuilder::condition($where->conditions(), 'mysql'), ':id'), 'WhereBuilder-Bedingungen müssen vom SelectBuilder nutzbar sein.');

foreach (['mysql', 'sqlsrv'] as $dialect) {
  $renderer = new SqlRenderer(new DialectConnection($dialect));
  check($renderer->render($update) === SqlBuilder::build($update, $dialect), 'Renderer muss den PDO-Dialekt erkennen.');
  check(['SELECT 1', 'SELECT 2'] === $renderer->renderAll(['SELECT 1', ['SELECT 2', '']]), 'renderAll muss flache Statement-Listen liefern.');
  $otherDialect = 'mysql' === $dialect ? 'sqlsrv' : 'mysql';
  check([] === $renderer->renderAll(['only' => [$otherDialect], 'sql' => 'SELECT 1']), 'Schritte für andere Dialekte müssen übersprungen werden.');
}

$renderer = new SqlRenderer(new DialectConnection('mysql', '10.11.6-MariaDB'));
check('SELECT 3' === $renderer->render(['only' => ['mariadb'], 'sql' => 'SELECT 3']), 'MariaDB-Filter müssen Produktinformationen berücksichtigen.');
check( ! class_exists('Braesident\\DbMigration\\Migration', false), 'DbMigration darf nicht vorausgesetzt werden.');
check( ! class_exists('Braesident\\CipherPDO\\CipherPDO', false), 'CipherPDO darf nicht vorausgesetzt werden.');
require __DIR__.'/postgresql.php';
require __DIR__.'/correctness.php';
require __DIR__.'/upsert.php';
echo "DialectSql: {$checks} Prüfungen erfolgreich.\n";
