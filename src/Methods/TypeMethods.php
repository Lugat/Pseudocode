<?php

declare(strict_types=1);

namespace Jinx\Methods;

use Jinx\Values;

/** Any value: as number, text or true/false, or a default for empty values */
final class TypeMethods
{
  /** A number - also from "12,50"; null if it is none */
  public static function number(mixed $value): int|float|null
  {
    return Values::numeric($value);
  }

  /** Text - lists and objects as JSON */
  public static function text(mixed $value): string
  {
    return is_array($value) || is_object($value) ? (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : Values::text($value);
  }

  /** "0", "false", "no", "off" are false too */
  public static function bool(mixed $value): bool
  {
    return Values::truthy($value) && !in_array(is_string($value) ? mb_strtolower(trim($value)) : null, ['0', 'false', 'no', 'nein', 'off'], true);
  }

  /** $fallback for empty values (0 and false are no empty values) */
  public static function default(mixed $value, mixed $fallback = null): mixed
  {
    return Values::truthy($value) || 0 === $value || false === $value ? $value : $fallback;
  }
}
