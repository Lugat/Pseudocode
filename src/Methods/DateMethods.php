<?php

declare(strict_types=1);

namespace Jinx\Methods;

use Jinx\Values;

/** Dates and times: date, datetime, time - a changed date keeps its form ("2026-10-20" stays a date) */
final class DateMethods
{
  public static function addDays(mixed $value, mixed $days = 1): string
  {
    return self::add($value, $days, 'days', 'addDays');
  }

  public static function addWeeks(mixed $value, mixed $weeks = 1): string
  {
    return self::add($value, $weeks, 'weeks', 'addWeeks');
  }

  public static function addMonths(mixed $value, mixed $months = 1): string
  {
    return self::add($value, $months, 'months', 'addMonths');
  }

  public static function addYears(mixed $value, mixed $years = 1): string
  {
    return self::add($value, $years, 'years', 'addYears');
  }

  public static function addHours(mixed $value, mixed $hours = 1): string
  {
    return self::add($value, $hours, 'hours', 'addHours');
  }

  public static function addMinutes(mixed $value, mixed $minutes = 1): string
  {
    return self::add($value, $minutes, 'minutes', 'addMinutes');
  }

  public static function year(mixed $value): int
  {
    return (int)Values::date($value, 'year')->format('Y');
  }

  public static function month(mixed $value): int
  {
    return (int)Values::date($value, 'month')->format('n');
  }

  public static function day(mixed $value): int
  {
    return (int)Values::date($value, 'day')->format('j');
  }

  public static function hour(mixed $value): int
  {
    return (int)Values::date($value, 'hour')->format('G');
  }

  public static function minute(mixed $value): int
  {
    return (int)Values::date($value, 'minute')->format('i');
  }

  /** 1 = Monday … 7 = Sunday */
  public static function weekday(mixed $value): int
  {
    return (int)Values::date($value, 'weekday')->format('N');
  }

  /** The letters of PHP's date(): "d.m.Y", "H:i" … */
  public static function formatDate(mixed $value, mixed $format = 'd.m.Y'): string
  {
    return Values::date($value, 'formatDate')->format(Values::text($format));
  }

  /** Days from this date to $other (negative: $other is before) */
  public static function daysUntil(mixed $value, mixed $other = 'today'): int
  {
    return (int)Values::date($value, 'daysUntil')->setTime(0, 0)->diff(Values::date($other, 'daysUntil')->setTime(0, 0))->format('%r%a');
  }

  /** Only the day: "2026-10-20" */
  public static function date(mixed $value): string
  {
    return Values::date($value, 'date')->format('Y-m-d');
  }

  private static function add(mixed $value, mixed $amount, string $unit, string $method): string
  {
    return Values::dateLike(Values::date($value, $method)->modify(sprintf('%+d %s', (int)Values::number($amount, $method), $unit)), $value);
  }
}
