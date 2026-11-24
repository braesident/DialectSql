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
