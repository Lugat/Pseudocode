<?php

declare(strict_types=1);

namespace Jinx\Methods;

use Jinx\Values;

/** E-mail addresses: "Jane.Doe@Example.com" */
final class EmailMethods
{
  /** The part before the @: "Jane.Doe" */
  public static function emailName(mixed $value): string
  {
    $text = trim(Values::text($value));
    $at = strrpos($text, '@');
    return false !== $at ? substr($text, 0, $at) : '';
  }

  /** The domain, small: "example.com" */
  public static function emailDomain(mixed $value): string
  {
    $text = trim(Values::text($value));
    $at = strrpos($text, '@');
    return false !== $at ? mb_strtolower(substr($text, $at + 1)) : '';
  }

  public static function isEmail(mixed $value): bool
  {
    return false !== filter_var(trim(Values::text($value)), FILTER_VALIDATE_EMAIL);
  }

  /** For messages that should not show the whole address: "J***@example.com" */
  public static function maskEmail(mixed $value): string
  {
    $name = self::emailName($value);
    return '' !== $name ? mb_substr($name, 0, 1).'***@'.self::emailDomain($value) : '';
  }
}
