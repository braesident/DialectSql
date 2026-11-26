<?php

declare(strict_types=1);

namespace Braesident\DialectSql;

use InvalidArgumentException;

/** @internal Shared dialect, identifier and literal rules. */
final class Dialect
{
  public static function context(string|array $value): array
  {
    $context = \is_array($value) ? $value : ['dialect' => $value];
    $name = $context['dialect'] ?? $context['driver'] ?? $context['name'] ?? null;
    if ( ! \is_string($name) || '' === trim($name)) {
      throw new InvalidArgumentException('SQL-Dialekt fehlt.');
    }
    $name = mb_strtolower(trim($name));
    $context['dialect'] = match ($name) {
      'mysql', 'maria', 'mariadb' => 'mysql',
      'sqlsrv', 'sqlserver', 'mssql', 'dblib' => 'sqlsrv',
      'pgsql', 'postgres', 'postgresql' => 'pgsql',
      default => throw new InvalidArgumentException('Unbekannter SQL-Dialekt: '.$name)
    };
    if (\in_array($name, ['maria', 'mariadb'], true)) {
      $context['serverProduct'] = 'mariadb';
    }

    return $context;
  }

  public static function identifier(string $name, string $dialect): string
  {
    if ('' === trim($name) || str_contains($name, "\0")) {
      throw new InvalidArgumentException('SQL-Bezeichner darf nicht leer sein oder NUL enthalten.');
    }

    return match ($dialect) {
      'mysql' => '`'.str_replace('`', '``', $name).'`',
      'sqlsrv' => '['.str_replace(']', ']]', $name).']',
      'pgsql' => '"'.str_replace('"', '""', $name).'"',
      default => throw new InvalidArgumentException('Unbekannter SQL-Dialekt: '.$dialect)
    };
  }

  /** Resolves explicitly mapped field values without traversing SQL data or expressions. */
  public static function fields(array $value, array $fields, array $context, array $required = []): array
  {
    foreach ($fields as $field) {
      $map = $value[$field] ?? null;
      if (\is_object($map)) {
        $map = get_object_vars($map);
      }
      if ( ! \is_array($map) || $map === array_values($map)) {
        continue;
      }
      $normalized = [];
      foreach ($map as $key => $item) {
        $key = match (mb_strtolower(trim((string) $key))) {
          'mysql' => 'mysql',
          'maria', 'mariadb' => 'mariadb',
          'sqlsrv', 'sqlserver', 'mssql', 'dblib' => 'sqlsrv',
          'pgsql', 'postgres', 'postgresql' => 'pgsql',
          'default' => 'default',
          default => throw new InvalidArgumentException($field.': unbekannter Dialekt "'.$key.'".')
        };
        if (\array_key_exists($key, $normalized) && $normalized[$key] !== $item) {
          throw new InvalidArgumentException($field.': widersprüchliche Dialektangaben für '.$key.'.');
        }
        $normalized[$key] = $item;
      }
      $keys = 'mysql' === $context['dialect'] && 'mariadb' === ($context['serverProduct'] ?? null)
        ? ['mariadb', 'mysql', 'default'] : [$context['dialect'], 'default'];
      $found = false;
      $selected = null;
      foreach ($keys as $key) {
        if (\array_key_exists($key, $normalized)) {
          $selected = $normalized[$key];
          $found = true;
          break;
        }
      }
      if ( ! $found || (null === $selected && 'default' !== $field)) {
        if (\in_array($field, $required, true)) {
          throw new InvalidArgumentException($field.': kein Wert für Dialekt '.$context['dialect'].'.');
        }
        unset($value[$field]);
      } else {
        if ('enum' !== $field && (\is_array($selected) || \is_object($selected))) {
          throw new InvalidArgumentException($field.': Dialekt-Map-Werte müssen skalar oder NULL sein.');
        }
        $value[$field] = $selected;
      }
    }

    return $value;
  }

  public static function schema(mixed $schema, array $context): ?string
  {
    $resolved = self::fields(['schema' => $schema], ['schema'], $context);
    $schema = $resolved['schema'] ?? null;
    if (null === $schema || (\is_string($schema) && '' === trim($schema))) {
      return null;
    }
    if ( ! \is_string($schema)) {
      throw new InvalidArgumentException('schema muss ein String oder eine Dialekt-Map sein.');
    }
    self::identifier($schema, $context['dialect']);

    return trim($schema);
  }

  public static function collation(string $name, string $dialect): string
  {
    if ('pgsql' === $dialect) {
      return self::identifier($name, $dialect);
    }
    if ( ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
      throw new InvalidArgumentException('collation benötigt einen gültigen Kollationsnamen.');
    }

    return $name;
  }

  public static function path(string $name, string $dialect): string
  {
    return implode('.', array_map(static fn ($part) => '*' === $part ? '*' : self::identifier($part, $dialect), explode('.', $name)));
  }

  public static function literal(mixed $value, string $dialect): string
  {
    if (null === $value) {
      return 'NULL';
    }
    if (\is_bool($value)) {
      return 'pgsql' === $dialect ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
    }
    if (\is_int($value) || (\is_float($value) && is_finite($value))) {
      return (string) $value;
    }
    if ( ! \is_string($value) || str_contains($value, "\0")) {
      throw new InvalidArgumentException('SQL-Literal benötigt einen skalaren Wert ohne NUL; Binärdaten als Parameter binden.');
    }
    if ('mysql' === $dialect && str_contains($value, '\\')) {
      // Works with and without NO_BACKSLASH_ESCAPES.
      return "CONVERT(X'".bin2hex($value)."' USING utf8mb4)";
    }
    $prefix = 'sqlsrv' === $dialect ? 'N' : '';
    if ('pgsql' === $dialect && str_contains($value, '\\')) {
      $prefix = 'E';
      $value = str_replace('\\', '\\\\', $value);
    }

    return $prefix."'".str_replace("'", "''", $value)."'";
  }
}
