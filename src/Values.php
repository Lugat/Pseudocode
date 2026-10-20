<?php

declare(strict_types=1);

namespace Jinx;

use DateTimeImmutable;
use RuntimeException;

/**
 * How the script language reads values - for the interpreter and every method (also your own
 * methods): a value as text, number, list, date, true or false.
 */
final class Values
{
  /** Longest text a script may build */
  public const MAX_TEXT = 1000000;

  public static function text(mixed $value): string
  {
    return match (true) {
      null === $value => '',
      is_bool($value) => $value ? 'true' : 'false',
      is_scalar($value) => (string)$value,
      default => throw new RuntimeException('a list or an object cannot be used as text - use .join() or a field of it.'),
    };
  }

  /** A number or a numeric text ("12", "12.5", "12,5"); null otherwise */
  public static function numeric(mixed $value): int|float|null
  {
    if (is_int($value) || is_float($value)) {
      return $value;
    }
    if (is_string($value) && is_numeric($n = str_replace(',', '.', trim($value)))) {
      return 0 + $n;
    }
    return null;
  }

  public static function number(mixed $value, string $method): int|float
  {
    return self::numeric($value) ?? throw new RuntimeException(sprintf('.%s() needs a number.', $method));
  }

  /** Empty values, 0, false and null are false */
  public static function truthy(mixed $value): bool
  {
    return !(null === $value || false === $value || 0 === $value || 0.0 === $value || '' === $value || [] === $value);
  }

  /** Equal as numbers if both are numbers, otherwise as text */
  public static function same(mixed $a, mixed $b): bool
  {
    if (null !== ($x = self::numeric($a)) && null !== ($y = self::numeric($b))) {
      return $x == $y;
    }
    return is_scalar($a) && is_scalar($b) && !is_bool($a) && !is_bool($b) ? self::text($a) === self::text($b) : $a == $b;
  }

  /** @return list<mixed> */
  public static function listOf(mixed $value, string $method): array
  {
    if (is_object($value)) {
      $value = (array)$value;
    }
    return is_array($value) ? array_values($value) : throw new RuntimeException(sprintf('%s needs a list.', 'each' === $method ? 'each … in' : '.'.$method.'()'));
  }

  /** @return array<string|int, mixed> an object or list as array, anything else empty */
  public static function entries(mixed $value): array
  {
    return is_object($value) ? (array)$value : (is_array($value) ? $value : []);
  }

  public static function date(mixed $value, string $method): DateTimeImmutable
  {
    $text = trim(self::text($value));
    try {
      return '' !== $text ? new DateTimeImmutable($text) : throw new \Exception();
    } catch (\Exception) {
      throw new RuntimeException(sprintf('.%s() needs a date, e.g. "2026-10-20" or "2026-10-20 14:30".', $method));
    }
  }

  /** A changed date in the form of the original: a date stays a date, a time stays a time */
  public static function dateLike(DateTimeImmutable $date, mixed $original): string
  {
    $text = trim(self::text($original));
    return $date->format(match (true) {
      1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) || 1 === preg_match('/^\d{1,2}\.\d{1,2}\.\d{2,4}$/', $text) => 'Y-m-d',
      1 === preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $text) => 'H:i:s',
      default => 'Y-m-d H:i:s',
    });
  }
}
