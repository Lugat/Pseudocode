<?php

declare(strict_types=1);

namespace Jinx\Methods;

use Jinx\Values;

/** Numbers: integer, decimal (and numeric texts like "12,50") */
final class NumberMethods
{
  public static function round(mixed $value, mixed $decimals = 0): float
  {
    return round(Values::number($value, 'round'), (int)Values::number($decimals, 'round'));
  }

  public static function floor(mixed $value): float
  {
    return floor(Values::number($value, 'floor'));
  }

  public static function ceil(mixed $value): float
  {
    return ceil(Values::number($value, 'ceil'));
  }

  public static function abs(mixed $value): int|float
  {
    return abs(Values::number($value, 'abs'));
  }

  /** 1234.5.formatNumber(2, ",", ".") is "1.234,50" */
  public static function formatNumber(mixed $value, mixed $decimals = 0, mixed $point = '.', mixed $thousands = ','): string
  {
    return number_format((float)Values::number($value, 'formatNumber'), (int)Values::number($decimals, 'formatNumber'), Values::text($point), Values::text($thousands));
  }
}
