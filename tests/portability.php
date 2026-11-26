<?php

declare(strict_types=1);

use Braesident\DialectSql\SelectBuilder;
use Braesident\DialectSql\SqlBuilder;
use Braesident\DialectSql\WhereBuilder;

$booleanColumn = ['name' => 'ActiveFlag', 'type' => 'boolean', 'nullable' => false];
foreach ([[true, 'TRUE'], [false, 'FALSE'], [1, 'TRUE'], [0, 'FALSE'], [1.0, 'TRUE'], [0.0, 'FALSE'], ['1', 'TRUE'], ['0', 'FALSE'], ['true', 'TRUE'], ['false', 'FALSE'], [' YES ', 'TRUE'], ['off', 'FALSE'], ['tr', 'TRUE'], ['of', 'FALSE'], [null, 'NULL']] as [$value, $literal]) {
  $column = $booleanColumn + ['default' => $value];
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'flags', 'columns' => [$column]], 'pgsql');
  check(str_contains($sql, '"ActiveFlag" BOOLEAN NOT NULL DEFAULT '.$literal), 'PostgreSQL muss Boolean-Defaults beim Erstellen typgerecht ausgeben.');
  $sql = SqlBuilder::build(['type' => 'alter_table', 'table' => 'flags', 'actions' => [['action' => 'add_column', 'column' => $column]]], 'pgsql');
  sameSql('ALTER TABLE "flags" ADD COLUMN "ActiveFlag" BOOLEAN NOT NULL DEFAULT '.$literal, $sql, 'ADD COLUMN muss dieselben Boolean-Defaults unterstützen.');
  $sql = SqlBuilder::build(['type' => 'alter_table', 'table' => 'flags', 'actions' => [['action' => 'modify_column', 'column' => $column]]], 'pgsql');
  check(\in_array('ALTER TABLE "flags" ALTER COLUMN "ActiveFlag" SET DEFAULT '.$literal, $sql, true), 'MODIFY mit bekanntem Boolean-Typ muss Defaults ebenso normalisieren.');
}
foreach ([2, -1, 0.5, INF, 'maybe', 'o', '', [], ['raw' => 'TRUE']] as $invalid) {
  $column = $booleanColumn + ['default' => $invalid];
  $reason = \is_array($invalid) && isset($invalid['raw']) ? 'raw' : 'Boolean-Default';
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'create_table', 'table' => 'flags', 'columns' => [$column]], 'pgsql'), 'Ungültige Boolean-Defaults müssen frühzeitig fehlschlagen.', $reason);
  rejectsSql(static fn () => SqlBuilder::build(['type' => 'alter_table', 'table' => 'flags', 'actions' => [['action' => 'modify_column', 'column' => $column]]], 'pgsql'), 'Auch ALTER muss ungültige bekannte Boolean-Defaults ablehnen.', $reason);
}
foreach (['bool', 'boolean'] as $type) {
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'flags', 'columns' => [['name' => 'flag', 'type' => $type, 'default' => 1]]], 'pgsql');
  check(str_contains($sql, 'BOOLEAN NULL DEFAULT TRUE'), 'Beide logischen Boolean-Typnamen müssen funktionieren.');
}
foreach (['pgsql', 'postgres', 'postgresql', 'raw'] as $override) {
  $column = $booleanColumn + [$override => 'SMALLINT', 'default' => 1];
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'flags', 'columns' => [$column]], 'pgsql');
  check(str_contains($sql, 'SMALLINT NOT NULL DEFAULT 1'), 'Numerische Typ-Overrides dürfen nicht anhand des logischen Typs zu TRUE geändert werden.');
  $sql = SqlBuilder::build(['type' => 'alter_table', 'table' => 'flags', 'actions' => [['action' => 'modify_column', 'column' => $column]]], 'pgsql');
  check(\in_array('ALTER TABLE "flags" ALTER COLUMN "ActiveFlag" SET DEFAULT 1', $sql, true), 'ALTER muss den effektiven Typ-Override berücksichtigen.');
}
foreach (['BOOLEAN', ' bool ', 'pg_catalog.bool'] as $override) {
  $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'flags', 'columns' => [['name' => 'flag', 'type' => 'smallint', 'pgsql' => $override, 'default' => 0]]], 'pgsql');
  check(str_contains($sql, 'DEFAULT FALSE'), 'Ein nativer Boolean-Typ als Override muss ebenso erkannt werden.');
}
$sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'flags', 'columns' => [['name' => 'flag', 'type' => 'int', 'default' => 2]]], 'pgsql');
check(str_contains($sql, 'INTEGER NULL DEFAULT 2'), 'Integer-Defaults müssen numerisch bleiben.');
sameSql('ALTER TABLE "flags" ALTER COLUMN "flag" SET DEFAULT 1', SqlBuilder::build(['type' => 'alter_table', 'table' => 'flags', 'actions' => [['action' => 'modify_column', 'column' => ['name' => 'flag', 'default' => 1]]]], 'pgsql'), 'Ohne Typangabe darf ALTER den tatsächlichen Datenbanktyp nicht erraten.');
sameSql('ALTER TABLE "flags" ALTER COLUMN "flag" SET DEFAULT FALSE', SqlBuilder::build(['type' => 'alter_table', 'table' => 'flags', 'actions' => [['action' => 'modify_column', 'column' => ['name' => 'flag', 'default' => false]]]], 'pgsql'), 'Ein expliziter Boolean-Default bleibt auch ohne Typangabe eindeutig.');

foreach (['mysql', 'sqlsrv', 'pgsql'] as $dialect) {
  $name = match ($dialect) { 'mysql' => '`p`.`DisplayName`', 'sqlsrv' => '[p].[DisplayName]', 'pgsql' => '"p"."DisplayName"' };
  $query = ['select' => ['col' => 'p.DisplayName'], 'from' => ['table' => 'products', 'as' => 'p']];
  foreach ([
    [['col' => 'p.DisplayName', 'dir' => 'DESC']],
    ['col' => 'p.DisplayName', 'direction' => 'DESC'],
    [['column' => 'p.DisplayName', 'dir' => 'DESC']],
    [['expr' => ['col' => 'p.DisplayName'], 'dir' => 'DESC']],
    [[['col' => 'p.DisplayName'], 'DESC']]
  ] as $order) {
    $ordered = $query + ['order_by' => $order];
    $sql = SelectBuilder::build($ordered, $dialect);
    check(str_ends_with($sql, ' ORDER BY '.$name.' DESC'), 'ORDER BY muss explizite Spalten in allen Objekt-/Listenformen quoten.');
    sameSql($sql, SelectBuilder::build(json_decode(json_encode($ordered)), $dialect), 'ORDER BY muss verschachtelte JSON-Objekte unverändert interpretieren.');
  }
  $sql = (new SelectBuilder($dialect))->select(SelectBuilder::col('p.DisplayName'))->from('products', 'p')->orderBy(SelectBuilder::col('p.DisplayName'), 'DESC')->statement();
  check(str_ends_with($sql, ' ORDER BY '.$name.' DESC'), 'Fluente Sortierung muss dieselben Spaltenausdrücke erhalten.');
  $escaped = match ($dialect) { 'mysql' => ['Display`Name', '`Display``Name`'], 'sqlsrv' => ['Display]Name', '[Display]]Name]'], 'pgsql' => ['Display"Name', '"Display""Name"'] };
  check(str_ends_with(SelectBuilder::build(['from' => 'products', 'order_by' => [['col' => $escaped[0]]]], $dialect), ' ORDER BY '.$escaped[1]), 'ORDER BY muss Sonderzeichen in expliziten Bezeichnern maskieren.');
  $sql = SelectBuilder::build($query + ['order_by' => [['expr' => ['fn' => 'lower', 'args' => [['col' => 'p.DisplayName']]], 'dir' => 'ASC']]], $dialect);
  check(str_ends_with($sql, ' ORDER BY LOWER('.$name.') ASC'), 'Ausdrücke müssen gegenüber Spaltenkurzformen ihre Bedeutung behalten.');
  $sql = SelectBuilder::build($query + ['order_by' => 'LOWER(p.DisplayName) DESC'], $dialect);
  check(str_ends_with($sql, ' ORDER BY LOWER(p.DisplayName) DESC'), 'Bestehende SQL-Strings dürfen nicht automatisch als Spaltennamen gequotet werden.');
  foreach ([null, '', 42] as $invalid) {
    rejectsSql(static fn () => SelectBuilder::build($query + ['order_by' => [['col' => $invalid]]], $dialect), 'Ungültige explizite ORDER-BY-Spalten müssen fehlschlagen.', null === $invalid || 42 === $invalid ? 'col' : 'Bezeichner');
  }
  $union = ['select' => ['col' => 'DisplayName'], 'from' => 'products', 'union_all' => [['select' => ['col' => 'DisplayName'], 'from' => 'archive']], 'union_order_by' => [['col' => 'DisplayName', 'dir' => 'DESC']]];
  $unqualified = match ($dialect) { 'mysql' => '`DisplayName`', 'sqlsrv' => '[DisplayName]', 'pgsql' => '"DisplayName"' };
  check(str_ends_with(SelectBuilder::build($union, $dialect), ' ORDER BY '.$unqualified.' DESC'), 'Auch die globale UNION-Sortierung muss Spaltenkurzformen erhalten.');

  foreach (['andEquals', 'orEquals', 'andEq', 'orEq'] as $method) {
    $where = new WhereBuilder();
    $where->$method(SelectBuilder::col('p.DisplayName'), 1);
    sameSql($name.' = 1', SelectBuilder::condition($where->conditions(), $dialect), 'WhereBuilder-Vergleichsmethoden müssen Spaltenausdrücke annehmen.');
  }
  foreach (['andEqualsParam', 'orEqualsParam', 'andEqParam', 'orEqParam'] as $method) {
    $where = new WhereBuilder();
    $where->$method((object) ['col' => 'p.DisplayName'], 'Öl', 'label');
    sameSql($name.' = :label', SelectBuilder::condition($where->conditions(), $dialect), 'WhereBuilder-Parametermethoden müssen Spaltenobjekte annehmen.');
    sameSql([':label' => 'Öl'], $where->params(), 'Ausdrucksargumente dürfen die gebundenen Werte nicht verändern.');
  }
  foreach (['andLessThan' => '<', 'orGreaterThanOrEqual' => '>=', 'andNe' => '<>', 'orGt' => '>'] as $method => $operator) {
    $where = new WhereBuilder();
    $where->$method(SelectBuilder::col('p.DisplayName'), 1);
    sameSql($name.' '.$operator.' 1', SelectBuilder::condition($where->conditions(), $dialect), 'Weitere Vergleiche und Aliase müssen Ausdrucksargumente weitergeben.');
  }
  foreach (['andIn', 'orIn'] as $method) {
    $where = new WhereBuilder();
    $where->$method(SelectBuilder::col('p.DisplayName'), ['A', 'B'], 'names');
    sameSql($name.' IN (:names_0, :names_1)', SelectBuilder::condition($where->conditions(), $dialect), 'IN-Methoden müssen Spaltenausdrücke unterstützen.');
    sameSql([':names_0' => 'A', ':names_1' => 'B'], $where->params(), 'IN-Parameter bleiben getrennt und in derselben Reihenfolge.');
    $where = new WhereBuilder();
    $where->$method((object) ['col' => 'p.DisplayName'], []);
    sameSql('1=0', SelectBuilder::condition($where->conditions(), $dialect), 'Auch mit Spaltenobjekt muss ein leeres IN falsch bleiben.');
  }
  $where = (new WhereBuilder())->andParam(['fn' => 'lower', 'args' => [['col' => 'p.DisplayName']]], '=', 'öl', 'label');
  sameSql('LOWER('.$name.') = :label', SelectBuilder::condition($where->conditions(), $dialect), 'WhereBuilder muss verschachtelte Funktionsausdrücke erhalten.');
  $where = (new WhereBuilder())->orValue(['raw' => 'LOWER(p.DisplayName)'], '=', 1);
  sameSql('LOWER(p.DisplayName) = 1', SelectBuilder::condition($where->conditions(), $dialect), 'Bewusstes Raw-SQL muss als Ausdruck verwendbar sein.');
  $where = (new WhereBuilder())->andEqualsParam('LOWER(p.DisplayName)', 'öl', 'label');
  sameSql('LOWER(p.DisplayName) = :label', SelectBuilder::condition($where->conditions(), $dialect), 'Bisherige SQL-Strings müssen kompatibel bleiben.');
  $where = (new WhereBuilder())->andEqualsParam(SelectBuilder::col('p.DisplayName'), 'A', 'same')->andGroup(static function (WhereBuilder $group): void {
    $group->andEqualsParam((object) ['col' => 'p.DisplayName'], 'B', 'same')->orEqualsParam(SelectBuilder::col('p.DisplayName'), 'C', 'same');
  });
  $sql = SelectBuilder::condition($where->conditions(), $dialect);
  check(str_contains($sql, $name.' = :same') && str_contains($sql, $name.' = :same_1') && str_contains($sql, $name.' = :same_2'), 'Gruppierte Ausdrucksbedingungen müssen eindeutige Parameternamen behalten.');
  sameSql([':same' => 'A', ':same_1' => 'B', ':same_2' => 'C'], $where->params(), 'Gruppen müssen Ausdrucksparameter mit dem Elternbuilder teilen.');
}

foreach (['mysql', 'sqlsrv'] as $dialect) {
  foreach ([true, false, 1, 0] as $value) {
    $sql = SqlBuilder::build(['type' => 'create_table', 'table' => 'flags', 'columns' => [$booleanColumn + ['default' => $value]]], $dialect);
    check(str_contains($sql, 'DEFAULT '.(int) $value), 'MySQL-/SQL-Server-Boolean-Defaults müssen weiterhin numerisch ausgegeben werden.');
  }
}
