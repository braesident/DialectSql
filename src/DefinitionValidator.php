<?php

declare(strict_types=1);

namespace Braesident\DialectSql;

use InvalidArgumentException;

/** @internal Structural validation; raw expressions deliberately remain SQL. */
final class DefinitionValidator
{
  public static function key(string|int $key): string
  {
    return str_replace(['_', '-'], '', mb_strtolower((string) $key));
  }

  public static function keys(array $value, array $allowed, string $label, bool $normalized = false): void
  {
    $allowed = $normalized ? array_map([self::class, 'key'], $allowed) : $allowed;
    foreach ($value as $key => $_) {
      if ( ! \in_array($normalized ? self::key($key) : $key, $allowed, true)) {
        throw new InvalidArgumentException($label.': unbekanntes Feld "'.$key.'".');
      }
    }
  }

  public static function count(mixed $value, string $label): ?int
  {
    if (null === $value) {
      return null;
    }
    if (\is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
      $digits = ltrim($value, '0');
      $digits = '' === $digits ? '0' : $digits;
      if (strlen($digits) < strlen((string) PHP_INT_MAX) || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) <= 0)) {
        return (int) $digits;
      }
    }
    if ( ! \is_int($value) || $value < 0) {
      throw new InvalidArgumentException($label.' muss eine nicht negative Ganzzahl innerhalb von PHP_INT_MAX sein.');
    }

    return $value;
  }

  private static function normalize(array $value, array $allowed, string $label): array
  {
    self::keys($value, $allowed, $label, true);
    $names = [];
    foreach ($allowed as $key) {
      $names[self::key($key)] = $key;
    }
    $normalized = [];
    foreach ($value as $key => $item) {
      $name = $names[self::key($key)];
      if (\array_key_exists($name, $normalized) && $normalized[$name] !== $item) {
        throw new InvalidArgumentException($label.': widersprüchliche Angaben für "'.$name.'".');
      }
      $normalized[$name] = $item;
    }

    return $normalized;
  }

  public static function query(array $query): void
  {
    self::keys($query, ['select', 'from', 'where', 'group_by', 'having', 'order_by', 'join', 'left_join', 'right_join', 'limit', 'offset', 'distinct', 'union', 'union_all', 'with', 'recursive', 'union_order_by', 'union_limit', 'union_offset'], 'SELECT', true);
    foreach (['distinct', 'recursive'] as $key) {
      if (\array_key_exists($key, $query) && ! \is_bool($query[$key])) {
        throw new InvalidArgumentException($key.' muss boolean sein.');
      }
    }
  }

  public static function definition(array $definition, string $type): array
  {
    $common = ['type', 'table', 'schema', 'builder', 'only', 'dialects', 'include', 'except', 'exclude'];
    $joins = ['join', 'inner_join', 'left_join', 'right_join'];
    $allowed = match ($type) {
      'insert' => ['columns', 'column', 'values', 'rows', 'select', 'query', 'returning'],
      'upsert' => ['columns', 'values', 'rows', 'conflictColumns', 'set', 'mysqlConflict', 'returning'],
      'update' => array_merge($joins, ['as', 'alias', 'set', 'where', 'limit', 'returning']),
      'delete' => array_merge($joins, ['as', 'alias', 'where', 'limit', 'returning']),
      'create_table' => ['columns', 'primaryKey', 'unique', 'indexes', 'foreignKeys', 'checks', 'ifNotExists'],
      'drop_table' => ['ifExists'],
      'alter_table' => ['actions', 'action'],
      'create_view' => ['view', 'name', 'replace', 'select', 'query'],
      'drop_view' => ['view', 'name', 'ifExists'],
      'create_trigger', 'alter_trigger' => ['name', 'trigger', 'triggerName', 'timing', 'time', 'events', 'event', 'body', 'statement', 'sql', 'forEachRow', 'wrap', 'wrapBody', 'function', 'functionArgs', 'ifExists'],
      'drop_trigger' => ['name', 'trigger', 'triggerName', 'ifExists'],
      'raw' => ['mysql', 'mariadb', 'sqlsrv', 'sqlserver', 'mssql', 'pgsql', 'postgres', 'postgresql', 'sql', 'default'],
      default => []
    };
    $definition = self::normalize($definition, array_merge($common, $allowed), $type);
    foreach (['ifNotExists', 'ifExists', 'replace', 'forEachRow', 'wrap', 'wrapBody'] as $key) {
      if (\array_key_exists($key, $definition) && ! \is_bool($definition[$key])) {
        throw new InvalidArgumentException($key.' muss boolean sein.');
      }
    }

    return $definition;
  }

  public static function column(array $column): array
  {
    $column = self::normalize($column, ['name', 'type', 'length', 'precision', 'scale', 'nullable', 'default', 'onUpdate', 'unsigned', 'autoIncrement', 'identity', 'enum', 'raw', 'mysql', 'sqlsrv', 'pgsql', 'postgres', 'postgresql', 'comment'], 'Spalte');
    foreach (['nullable', 'unsigned', 'autoIncrement', 'identity'] as $key) {
      if (\array_key_exists($key, $column) && ! \is_bool($column[$key])) {
        throw new InvalidArgumentException($key.' muss boolean sein.');
      }
    }
    foreach (['length', 'precision', 'scale'] as $key) {
      if (isset($column[$key])) {
        $number = self::count($column[$key], $key);
        if ('scale' !== $key && 0 === $number) {
          throw new InvalidArgumentException($key.' muss positiv sein.');
        }
      }
    }
    if (isset($column['precision'], $column['scale']) && (int) $column['scale'] > (int) $column['precision']) {
      throw new InvalidArgumentException('scale darf precision nicht überschreiten.');
    }
    if (\array_key_exists('enum', $column)) {
      if ( ! \is_array($column['enum']) || [] === $column['enum']) {
        throw new InvalidArgumentException('enum benötigt eine nicht leere Liste von Strings.');
      }
      foreach ($column['enum'] as $value) {
        if ( ! \is_string($value)) {
          throw new InvalidArgumentException('enum benötigt Strings.');
        }
      }
    }

    return $column;
  }

  public static function action(array $action): void
  {
    self::keys($action, ['action', 'type', 'column', 'after', 'first', 'name', 'from', 'to', 'old', 'new', 'index', 'columns', 'unique', 'check', 'expression', 'foreignKey', 'refTable', 'refColumns', 'onDelete', 'onUpdate', 'length', 'precision', 'scale', 'nullable', 'default', 'unsigned', 'autoIncrement', 'identity', 'enum', 'raw', 'mysql', 'sqlsrv', 'pgsql', 'postgres', 'postgresql', 'comment'], 'ALTER-Aktion');
  }
}
