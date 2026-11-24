<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
  $prefix = 'Braesident\\DialectSql\\';
  if (str_starts_with($class, $prefix)) {
    require dirname(__DIR__).'/src/'.substr($class, strlen($prefix)).'.php';
  }
});

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
  if (error_reporting() & $severity) {
    throw new ErrorException($message, 0, $severity, $file, $line);
  }

  return false;
});

$checks = 0;
function check(bool $condition, string $message): void
{
  global $checks;
  if ( ! $condition) {
    throw new RuntimeException($message);
  }
  ++$checks;
}

function sameSql(string|array|null $expected, string|array|null $actual, string $message): void
{
  check($expected === $actual, $message."\nErwartet: ".var_export($expected, true)."\nErhalten: ".var_export($actual, true));
}

function rejectsSql(callable $build, string $message, string $reason): void
{
  try {
    $build();
  } catch (InvalidArgumentException $exception) {
    check(str_contains($exception->getMessage(), $reason), $message.' (Fehlergrund: '.$exception->getMessage().')');

    return;
  }
  check(false, $message);
}
