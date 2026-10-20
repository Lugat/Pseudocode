<?php

declare(strict_types=1);

namespace Jinx\Methods;

use Jinx\Values;

/** Texts: string, text, Markdown, e-mail, slug, phone … */
final class TextMethods
{
  /** @return list<string> at spaces, or at $separator */
  public static function split(mixed $value, mixed $separator = null): array
  {
    if (null === $value) {
      return [];
    }
    $separator = Values::text($separator);
    return '' !== $separator ? explode($separator, Values::text($value)) : (preg_split('/\s+/u', trim(Values::text($value)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
  }

  public static function uppercase(mixed $value): string
  {
    return mb_strtoupper(Values::text($value));
  }

  public static function lowercase(mixed $value): string
  {
    return mb_strtolower(Values::text($value));
  }

  public static function capitalize(mixed $value): string
  {
    $text = Values::text($value);
    return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
  }

  public static function trim(mixed $value): string
  {
    return trim(Values::text($value));
  }

  public static function replace(mixed $value, mixed $search = '', mixed $replace = ''): string
  {
    return str_replace(Values::text($search), Values::text($replace), Values::text($value));
  }

  /** The text before the first $needle (all of it if there is none) */
  public static function before(mixed $value, mixed $needle = ''): string
  {
    $text = Values::text($value);
    $at = mb_strpos($text, Values::text($needle));
    return false !== $at ? mb_substr($text, 0, $at) : $text;
  }

  /** The text after the first $needle (empty if there is none) - "jane@example.com".after("@") */
  public static function after(mixed $value, mixed $needle = ''): string
  {
    $text = Values::text($value);
    $needle = Values::text($needle);
    $at = mb_strpos($text, $needle);
    return false !== $at ? mb_substr($text, $at + mb_strlen($needle)) : '';
  }

  public static function startsWith(mixed $value, mixed $start = ''): bool
  {
    return str_starts_with(Values::text($value), Values::text($start));
  }

  public static function endsWith(mixed $value, mixed $end = ''): bool
  {
    return str_ends_with(Values::text($value), Values::text($end));
  }

  public static function padStart(mixed $value, mixed $length = 0, mixed $char = ' '): string
  {
    return self::pad(Values::text($value), $length, $char, 'padStart');
  }

  public static function padEnd(mixed $value, mixed $length = 0, mixed $char = ' '): string
  {
    return self::pad(Values::text($value), $length, $char, 'padEnd');
  }

  public static function truncate(mixed $value, mixed $length = 100, mixed $end = '…'): string
  {
    $text = Values::text($value);
    $max = (int)Values::number($length, 'truncate');
    return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)).Values::text($end) : $text;
  }

  /** URL names: "Über uns & Team!" -> "ueber-uns-team" */
  public static function slug(mixed $value): string
  {
    // German umlauts as words expect them, then any script to ASCII (intl if there, iconv otherwise)
    $text = strtr(Values::text($value), ['Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $ascii = function_exists('transliterator_transliterate') ? transliterator_transliterate('Any-Latin; Latin-ASCII', $text) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$ascii)), '-');
  }

  public static function stripTags(mixed $value): string
  {
    return trim(html_entity_decode(strip_tags(Values::text($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  /** @return list<string> */
  public static function lines(mixed $value): array
  {
    return preg_split('/\R/u', Values::text($value)) ?: [];
  }

  private static function pad(string $text, mixed $length, mixed $char, string $method): string
  {
    $missing = max(0, min(10000, (int)Values::number($length, $method)) - mb_strlen($text));
    $char = Values::text($char);
    $pad = mb_substr(str_repeat('' !== $char ? $char : ' ', $missing), 0, $missing);
    return 'padStart' === $method ? $pad.$text : $text.$pad;
  }
}
