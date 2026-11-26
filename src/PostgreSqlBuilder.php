<?php

declare(strict_types=1);

namespace Braesident\DialectSql;

use InvalidArgumentException;

/** @internal PostgreSQL-specific DDL; applications use SqlBuilder. */
final class PostgreSqlBuilder
{
  public static function build(array $definition, array $context): string|array
  {
    return match (mb_strtolower((string) ($definition['type'] ?? ''))) {
      'create_table' => self::createTable($definition),
      'drop_table' => self::dropRelation($definition, 'TABLE', 'table'),
      'alter_table' => self::alterTable($definition),
      'create_view' => 'CREATE '.(($definition['replace'] ?? true) ? 'OR REPLACE ' : '')
        .'VIEW '.self::relation($definition, 'view').' AS '
        .SelectBuilder::build($definition['select'] ?? $definition['query'] ?? null, $context),
      'drop_view' => self::dropRelation($definition, 'VIEW', 'view'),
      'create_trigger' => self::createTrigger($definition),
      'alter_trigger' => [self::dropTrigger($definition), self::createTrigger($definition)],
      'drop_trigger' => self::dropTrigger($definition),
      default => throw new InvalidArgumentException('Unbekannte PostgreSQL-DDL-Definition.')
    };
  }

  private static function createTable(array $definition): string|array
  {
    $table = self::relation($definition);
    $columns = $definition['columns'] ?? [];
    if ( ! \is_array($columns) || [] === $columns) {
      throw new InvalidArgumentException('create_table benötigt "columns".');
    }

    $lines = [];
    $extra = [];
    foreach ($columns as $column) {
      $column = DefinitionValidator::column($column, 'pgsql');
      $lines[] = self::column($column);
      if (\array_key_exists('comment', $column)) {
        $extra[] = self::columnComment($table, $column);
      }
    }
    if ( ! empty($definition['primaryKey'])) {
      $lines[] = 'PRIMARY KEY ('.self::columns($definition['primaryKey']).')';
    }
    foreach ($definition['unique'] ?? [] as $unique) {
      $lines[] = self::unique($unique);
    }
    foreach ($definition['foreignKeys'] ?? [] as $foreignKey) {
      $lines[] = self::foreignKey($foreignKey, $definition);
    }
    foreach ($definition['checks'] ?? [] as $check) {
      $lines[] = self::check($check);
    }

    $sql = 'CREATE TABLE '.(($definition['ifNotExists'] ?? true) ? 'IF NOT EXISTS ' : '')
      .$table." (\n  ".implode(",\n  ", $lines)."\n)";
    foreach ($definition['indexes'] ?? [] as $index) {
      $extra[] = self::index($index, $table, (bool) ($definition['ifNotExists'] ?? true));
    }

    return self::statements(array_merge([$sql], $extra));
  }

  private static function dropRelation(array $definition, string $type, string $key): string
  {
    return 'DROP '.$type.' '.(($definition['ifExists'] ?? true) ? 'IF EXISTS ' : '').self::relation($definition, $key);
  }

  private static function alterTable(array $definition): string|array
  {
    $table = self::relation($definition);
    $actions = $definition['actions'] ?? $definition['action'] ?? null;
    if ( ! \is_array($actions) || [] === $actions) {
      throw new InvalidArgumentException('alter_table benötigt "actions".');
    }
    if ( ! self::isList($actions)) {
      $actions = [$actions];
    }

    $statements = [];
    foreach ($actions as $action) {
      if ( ! \is_array($action)) {
        throw new InvalidArgumentException('alter_table Action muss array sein.');
      }
      DefinitionValidator::action($action);
      $type = mb_strtolower((string) ($action['action'] ?? $action['type'] ?? ''));
      $prefix = 'ALTER TABLE '.$table.' ';
      switch ($type) {
        case 'add_column':
        case 'modify_column':
          if ( ! empty($action['first']) || isset($action['after'])) {
            throw new InvalidArgumentException('PostgreSQL unterstützt kein FIRST/AFTER für Spalten.');
          }
          $column = $action['column'] ?? $action;
          if ( ! isset($action['column'])) {
            unset($column['action'], $column['type']);
          }
          $column = DefinitionValidator::column($column, 'pgsql');
          if ('add_column' === $type) {
            $statements[] = $prefix.'ADD COLUMN '.self::column($column);
          } else {
            // Validate unsupported options before returning any statements.
            self::column($column);
            if (isset($column['enum']) || ! empty($column['autoIncrement']) || ! empty($column['identity'])) {
              throw new InvalidArgumentException('PostgreSQL modify_column unterstützt kein enum/identity; nutze raw.');
            }
            $name = self::quote(self::required($column, 'name'));
            $sqlType = null;
            if (isset($column['type']) || isset($column['raw']) || isset($column['pgsql']) || isset($column['postgres']) || isset($column['postgresql'])) {
              $sqlType = self::columnType($column);
              $statements[] = $prefix.'ALTER COLUMN '.$name.' TYPE '.$sqlType;
            }
            if (\array_key_exists('nullable', $column)) {
              $statements[] = $prefix.'ALTER COLUMN '.$name.($column['nullable'] ? ' DROP' : ' SET').' NOT NULL';
            }
            if (\array_key_exists('default', $column)) {
              $statements[] = $prefix.'ALTER COLUMN '.$name.' SET DEFAULT '.self::defaultValue($column['default'], $sqlType);
            }
          }
          if (\array_key_exists('comment', $column)) {
            $statements[] = self::columnComment($table, $column);
          }
          break;

        case 'drop_column':
          $statements[] = $prefix.'DROP COLUMN '.self::quote(self::required($action, 'name'));
          break;

        case 'rename_column':
          $from = $action['from'] ?? $action['old'] ?? '';
          $to = $action['to'] ?? $action['new'] ?? '';
          $statements[] = $prefix.'RENAME COLUMN '.self::quote($from).' TO '.self::quote($to);
          break;

        case 'rename_table':
          $newName = $action['to'] ?? $action['new'] ?? $action['name'] ?? '';
          if (str_contains($newName, '.')) {
            throw new InvalidArgumentException('PostgreSQL rename_table erwartet einen Namen ohne Schema.');
          }
          $statements[] = $prefix.'RENAME TO '.self::quote($newName);
          $parts = explode('.', (string) $definition['table']);
          array_pop($parts);
          $parts[] = $newName;
          $definition['table'] = implode('.', $parts);
          $table = self::relation($definition);
          break;

        case 'add_index':
          $statements[] = self::index($action['index'] ?? $action, $table);
          break;

        case 'drop_index':
          $name = self::required($action, 'name');
          $schema = self::schema($definition);
          $statements[] = 'DROP INDEX '.self::path($name, $schema);
          break;

        case 'add_unique':
          $statements[] = $prefix.'ADD '.self::unique($action['unique'] ?? $action);
          break;

        case 'add_check':
          $statements[] = $prefix.'ADD '.self::check($action['check'] ?? $action);
          break;

        case 'add_primary_key':
          $statements[] = $prefix.'ADD '.self::constraintName($action).'PRIMARY KEY ('.self::columns($action['columns'] ?? []).')';
          break;

        case 'add_foreign_key':
          $statements[] = $prefix.'ADD '.self::foreignKey($action['foreignKey'] ?? $action, $definition);
          break;

        case 'drop_unique':
        case 'drop_check':
        case 'drop_primary_key':
        case 'drop_foreign_key':
          $statements[] = $prefix.'DROP CONSTRAINT '.self::quote(self::required($action, 'name'));
          break;

        case 'drop_default':
          $columns = $action['columns'] ?? $action['column'] ?? null;
          if (\is_string($columns)) {
            $columns = [$columns];
          }
          if ( ! \is_array($columns) || [] === $columns) {
            throw new InvalidArgumentException('PostgreSQL drop_default benötigt "columns", keine Constraint-Namen.');
          }
          foreach ($columns as $column) {
            $statements[] = $prefix.'ALTER COLUMN '.self::quote($column).' DROP DEFAULT';
          }
          break;

        default:
          throw new InvalidArgumentException('Unbekannte PostgreSQL alter_table Action: '.$type);
      }
    }

    if ([] === $statements) {
      throw new InvalidArgumentException('alter_table enthält keine Änderung.');
    }

    return self::statements($statements);
  }

  private static function column(array $column): string
  {
    $column = DefinitionValidator::column($column, 'pgsql');
    $name = self::quote(self::required($column, 'name'));
    if ( ! empty($column['unsigned'])) {
      throw new InvalidArgumentException('PostgreSQL hat kein UNSIGNED; nutze einen passenden Typ mit CHECK.');
    }
    if ( ! empty($column['onUpdate'])) {
      throw new InvalidArgumentException('PostgreSQL hat kein ON UPDATE für Spalten; nutze einen Trigger.');
    }
    $type = self::columnType($column);
    $identity = (bool) ($column['autoIncrement'] ?? $column['identity'] ?? false);
    $sql = $name.' '.$type;
    if ($identity) {
      if ( ! \in_array($type, ['SMALLINT', 'INTEGER', 'BIGINT'], true)) {
        throw new InvalidArgumentException('PostgreSQL identity benötigt smallint, int oder bigint.');
      }
      if (\array_key_exists('default', $column) || true === ($column['nullable'] ?? false)) {
        throw new InvalidArgumentException('PostgreSQL identity unterstützt kein default/nullable=true.');
      }
      $sql .= ' GENERATED BY DEFAULT AS IDENTITY';
    }
    $sql .= ($column['nullable'] ?? ! $identity) ? ' NULL' : ' NOT NULL';
    if (\array_key_exists('default', $column)) {
      $sql .= ' DEFAULT '.self::defaultValue($column['default'], $type);
    }
    if ( ! empty($column['enum'])) {
      $values = array_map(static fn ($value) => self::literal((string) $value), $column['enum']);
      $sql .= ' CHECK ('.$name.' IN ('.implode(', ', $values).'))';
    }

    return $sql;
  }

  private static function columnType(array $column): string
  {
    foreach (['pgsql', 'postgres', 'postgresql', 'raw'] as $key) {
      if (isset($column[$key])) {
        return self::required($column, $key);
      }
    }
    if ( ! empty($column['enum'])) {
      return 'VARCHAR('.max(1, max(array_map('mb_strlen', $column['enum']))).')';
    }
    $type = mb_strtolower((string) ($column['type'] ?? 'string'));
    $length = (int) ($column['length'] ?? 255);
    $precision = (int) ($column['precision'] ?? 10);
    $scale = (int) ($column['scale'] ?? 2);

    return match ($type) {
      'tinyint', 'smallint' => 'SMALLINT',
      'int', 'integer' => 'INTEGER',
      'bigint' => 'BIGINT',
      'bool', 'boolean' => 'BOOLEAN',
      'string', 'varchar', 'nvarchar' => $length > 0 ? 'VARCHAR('.$length.')' : throw new InvalidArgumentException('length muss positiv sein.'),
      'char' => $length > 0 ? 'CHAR('.$length.')' : throw new InvalidArgumentException('length muss positiv sein.'),
      'text', 'tinytext', 'mediumtext', 'longtext' => 'TEXT',
      'tinyblob', 'blob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea' => 'BYTEA',
      'decimal', 'numeric' => $precision > 0 && $scale >= 0 && $scale <= $precision
        ? 'NUMERIC('.$precision.','.$scale.')' : throw new InvalidArgumentException('Ungültige NUMERIC-Präzision/Skala.'),
      'float', 'real' => 'REAL',
      'double' => 'DOUBLE PRECISION',
      'date' => 'DATE',
      'time' => 'TIME WITHOUT TIME ZONE',
      'datetime', 'timestamp' => 'TIMESTAMP WITHOUT TIME ZONE',
      'timestamptz' => 'TIMESTAMP WITH TIME ZONE',
      'json' => 'JSON',
      'jsonb' => 'JSONB',
      'uuid' => 'UUID',
      default => throw new InvalidArgumentException('Unbekannter PostgreSQL-Spaltentyp: '.$type.'; nutze pgsql/raw.')
    };
  }

  private static function unique(array $definition): string
  {
    return self::constraintName($definition).'UNIQUE ('.self::columns($definition['columns'] ?? $definition).')';
  }

  private static function check(array $definition): string
  {
    return self::constraintName($definition).'CHECK ('.self::required($definition, 'expression').')';
  }

  private static function foreignKey(array $definition, array $table): string
  {
    $sql = self::constraintName($definition).'FOREIGN KEY ('.self::columns($definition['columns'] ?? []).')'
      .' REFERENCES '.self::path(self::required($definition, 'refTable'), self::schema($table))
      .' ('.self::columns($definition['refColumns'] ?? []).')';
    foreach (['onDelete' => 'DELETE', 'onUpdate' => 'UPDATE'] as $key => $operation) {
      if ( ! empty($definition[$key])) {
        $action = mb_strtoupper(str_replace(['_', '-'], ' ', trim($definition[$key])));
        $action = ['SETNULL' => 'SET NULL', 'SETDEFAULT' => 'SET DEFAULT', 'NOACTION' => 'NO ACTION'][$action] ?? $action;
        if ( ! \in_array($action, ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL', 'SET DEFAULT'], true)) {
          throw new InvalidArgumentException('Ungültige PostgreSQL-Foreign-Key-Aktion: '.$action);
        }
        $sql .= ' ON '.$operation.' '.$action;
      }
    }

    return $sql;
  }

  private static function index(array $definition, string $table, bool $ifNotExists = false): string
  {
    $columns = self::columns($definition['columns'] ?? $definition);
    // PostgreSQL index names live in the table's schema; CREATE INDEX does not accept a qualified name.
    $name = $definition['name'] ?? 'idx_'.md5($table.'|'.$columns);

    return 'CREATE INDEX '.($ifNotExists ? 'IF NOT EXISTS ' : '').self::quote($name).' ON '.$table.' ('.$columns.')';
  }

  private static function createTrigger(array $definition): string
  {
    $name = self::triggerName($definition);
    $function = self::dialectValue($definition['function'] ?? null);
    if ( ! \is_string($function) || '' === trim($function)) {
      throw new InvalidArgumentException('PostgreSQL trigger benötigt "function" als bestehende Triggerfunktion; body ist nicht direkt übersetzbar.');
    }
    $timing = mb_strtoupper(trim((string) self::dialectValue($definition['timing'] ?? $definition['time'] ?? 'after')));
    if ( ! \in_array($timing, ['BEFORE', 'AFTER', 'INSTEAD OF'], true)) {
      throw new InvalidArgumentException('PostgreSQL trigger timing muss BEFORE, AFTER oder INSTEAD OF sein.');
    }
    $events = $definition['events'] ?? $definition['event'] ?? [];
    if (\is_string($events)) {
      $events = preg_split('/\s*,\s*|\s+/', trim($events));
    }
    if ( ! \is_array($events) || [] === $events) {
      throw new InvalidArgumentException('PostgreSQL trigger benötigt event/events.');
    }
    $events = array_values(array_unique(array_map('strtoupper', $events)));
    foreach ($events as $event) {
      if ( ! \in_array($event, ['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'], true)) {
        throw new InvalidArgumentException('Unbekanntes PostgreSQL trigger event: '.$event);
      }
    }
    $row = (bool) ($definition['forEachRow'] ?? true);
    if (($row && \in_array('TRUNCATE', $events, true)) || ('INSTEAD OF' === $timing && ! $row)) {
      throw new InvalidArgumentException('PostgreSQL TRUNCATE benötigt Statement-Trigger, INSTEAD OF einen Row-Trigger.');
    }
    $arguments = $definition['functionArgs'] ?? [];
    if ( ! \is_array($arguments) || ! self::isList($arguments)) {
      throw new InvalidArgumentException('PostgreSQL functionArgs muss eine Liste von Strings sein.');
    }
    foreach ($arguments as $argument) {
      if ( ! \is_string($argument)) {
        throw new InvalidArgumentException('PostgreSQL functionArgs benötigt Strings.');
      }
    }
    $args = array_map([self::class, 'literal'], $arguments);

    // EXECUTE PROCEDURE is accepted for trigger functions on PostgreSQL 10 and newer.
    return 'CREATE TRIGGER '.$name.' '.$timing.' '.implode(' OR ', $events).' ON '.self::relation($definition)
      .' FOR EACH '.($row ? 'ROW' : 'STATEMENT').' EXECUTE PROCEDURE '.self::path($function).'('.implode(', ', $args).')';
  }

  private static function dropTrigger(array $definition): string
  {
    return 'DROP TRIGGER '.(($definition['ifExists'] ?? true) ? 'IF EXISTS ' : '').self::triggerName($definition)
      .' ON '.self::relation($definition);
  }

  private static function triggerName(array $definition): string
  {
    return self::quote((string) ($definition['name'] ?? $definition['trigger'] ?? $definition['triggerName'] ?? ''));
  }

  private static function dialectValue(mixed $value): mixed
  {
    if (\is_array($value)) {
      foreach (['pgsql', 'postgres', 'postgresql', 'sql', 'default'] as $key) {
        if (\array_key_exists($key, $value)) {
          return $value[$key];
        }
      }
      throw new InvalidArgumentException('Kein Wert für PostgreSQL gefunden.');
    }

    return $value;
  }

  private static function defaultValue(mixed $value, ?string $sqlType = null): string
  {
    if (null !== $sqlType && \in_array(mb_strtoupper(trim($sqlType)), ['BOOLEAN', 'BOOL', 'PG_CATALOG.BOOL'], true)) {
      if (null === $value || \is_bool($value)) {
        return self::literal($value);
      }
      if (\in_array($value, [0, 1, 0.0, 1.0], true)) {
        return $value ? 'TRUE' : 'FALSE';
      }
      if (\is_string($value)) {
        $token = mb_strtolower(trim($value));
        if (\in_array($token, ['1', 'true', 't', 'tr', 'tru', 'yes', 'y', 'ye', 'on'], true)) {
          return 'TRUE';
        }
        if (\in_array($token, ['0', 'false', 'f', 'fa', 'fal', 'fals', 'no', 'n', 'off', 'of'], true)) {
          return 'FALSE';
        }
      }

      throw new InvalidArgumentException('Boolean-Default benötigt true/false, 0/1, NULL oder einen gültigen PostgreSQL-Boolean-String.');
    }
    if (\is_string($value) && \in_array(mb_strtoupper($value), ['NOW', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'], true)) {
      return 'NOW' === mb_strtoupper($value) ? 'CURRENT_TIMESTAMP' : mb_strtoupper($value);
    }

    return self::literal($value);
  }

  private static function literal(mixed $value): string
  {
    return SelectBuilder::expr(['value' => $value], 'pgsql');
  }

  private static function columnComment(string $table, array $column): string
  {
    return 'COMMENT ON COLUMN '.$table.'.'.self::quote(self::required($column, 'name')).' IS '.self::literal($column['comment']);
  }

  private static function constraintName(array $definition): string
  {
    return isset($definition['name']) ? 'CONSTRAINT '.self::quote($definition['name']).' ' : '';
  }

  private static function columns(array $columns): string
  {
    if ([] === $columns || ! self::isList($columns)) {
      throw new InvalidArgumentException('columns muss eine nicht leere Liste sein.');
    }

    return implode(', ', array_map([self::class, 'quote'], $columns));
  }

  private static function relation(array $definition, string $key = 'table'): string
  {
    $name = $definition[$key] ?? ('view' === $key ? ($definition['name'] ?? '') : '');

    return self::path($name, $definition['schema'] ?? null);
  }

  private static function schema(array $definition): ?string
  {
    $parts = explode('.', (string) ($definition['table'] ?? ''));

    return \count($parts) > 1 ? implode('.', \array_slice($parts, 0, -1)) : ($definition['schema'] ?? null);
  }

  private static function path(string $name, ?string $schema = null): string
  {
    if (null !== $schema && '' !== $schema && ! str_contains($name, '.')) {
      $name = $schema.'.'.$name;
    }

    return implode('.', array_map([self::class, 'quote'], explode('.', $name)));
  }

  private static function quote(string $name): string
  {
    return Dialect::identifier($name, 'pgsql');
  }

  private static function required(array $definition, string $key): string
  {
    if ( ! isset($definition[$key]) || ! \is_string($definition[$key]) || '' === trim($definition[$key])) {
      throw new InvalidArgumentException('PostgreSQL-Definition benötigt "'.$key.'".');
    }

    return $definition[$key];
  }

  private static function statements(array $statements): string|array
  {
    return 1 === \count($statements) ? $statements[0] : $statements;
  }

  private static function isList(array $value): bool
  {
    return [] === $value || array_keys($value) === range(0, \count($value) - 1);
  }
}
