<?php

declare(strict_types=1);

namespace Jinx\Methods;

use Jinx\Values;

/** Lists and objects: repeatable fields, JSON, groups, included references - some work on texts too */
final class ListMethods
{
  public static function join(mixed $value, mixed $separator = ''): string
  {
    return implode(Values::text($separator), array_map(Values::text(...), Values::listOf($value, 'join')));
  }

  /** The first item - or the first character of a text */
  public static function first(mixed $value): mixed
  {
    return is_array($value) || is_object($value) ? (Values::listOf($value, 'first')[0] ?? null) : mb_substr(Values::text($value), 0, 1);
  }

  public static function last(mixed $value): mixed
  {
    if (!is_array($value) && !is_object($value)) {
      return mb_substr(Values::text($value), -1);
    }
    $items = Values::listOf($value, 'last');
    return $items[count($items) - 1] ?? null;
  }

  public static function slice(mixed $value, mixed $start = 0, mixed $length = null): mixed
  {
    $start = (int)Values::number($start, 'slice');
    $length = null !== $length ? (int)Values::number($length, 'slice') : null;
    return is_array($value) ? array_slice(Values::listOf($value, 'slice'), $start, $length) : mb_substr(Values::text($value), $start, $length);
  }

  public static function length(mixed $value): int
  {
    return is_array($value) || is_object($value) ? count((array)$value) : mb_strlen(Values::text($value));
  }

  public static function contains(mixed $value, mixed $needle = null): bool
  {
    if (is_array($value)) {
      foreach ($value as $item) {
        if (Values::same($item, $needle)) {
          return true;
        }
      }
      return false;
    }
    return str_contains(Values::text($value), Values::text($needle));
  }

  /** Numbers as numbers, the rest in natural order ("item 2" before "item 10") */
  public static function sort(mixed $value): array
  {
    $items = Values::listOf($value, 'sort');
    usort($items, static fn($a, $b): int => null !== ($x = Values::numeric($a)) && null !== ($y = Values::numeric($b)) ? $x <=> $y : strnatcasecmp(Values::text($a), Values::text($b)));
    return $items;
  }

  public static function reverse(mixed $value): mixed
  {
    return is_array($value) ? array_reverse(Values::listOf($value, 'reverse')) : implode('', array_reverse(mb_str_split(Values::text($value))));
  }

  public static function unique(mixed $value): array
  {
    return array_values(array_unique(Values::listOf($value, 'unique'), SORT_REGULAR));
  }

  /** A new list with the items added (an empty value is an empty list) */
  public static function append(mixed $value, mixed ...$items): array
  {
    return [...(null === $value ? [] : Values::listOf($value, 'append')), ...$items];
  }

  public static function sum(mixed $value): int|float
  {
    return array_sum(self::numbers($value, null, 'sum'));
  }

  public static function average(mixed $value): int|float|null
  {
    $numbers = self::numbers($value, null, 'average');
    return [] !== $numbers ? array_sum($numbers) / count($numbers) : null;
  }

  /** The smallest of a list - or of the number and $other */
  public static function min(mixed $value, mixed $other = null): int|float|null
  {
    $numbers = self::numbers($value, $other, 'min');
    return [] !== $numbers ? min($numbers) : null;
  }

  public static function max(mixed $value, mixed $other = null): int|float|null
  {
    $numbers = self::numbers($value, $other, 'max');
    return [] !== $numbers ? max($numbers) : null;
  }

  public static function keys(mixed $value): array
  {
    return array_keys(Values::entries($value));
  }

  public static function values(mixed $value): array
  {
    return array_values(Values::entries($value));
  }

  public static function has(mixed $value, mixed $key = ''): bool
  {
    return array_key_exists(is_int($key) ? $key : Values::text($key), Values::entries($value));
  }

  public static function isEmpty(mixed $value): bool
  {
    return !Values::truthy($value);
  }

  /** @return list<int|float> */
  private static function numbers(mixed $value, mixed $other, string $method): array
  {
    $items = is_array($value) ? Values::listOf($value, $method) : [$value, ...(null !== $other ? [$other] : [])];
    return array_map(static fn($item) => Values::number($item, $method), $items);
  }
}
