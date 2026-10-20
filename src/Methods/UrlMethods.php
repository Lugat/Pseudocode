<?php

declare(strict_types=1);

namespace Jinx\Methods;

use Jinx\Values;
use RuntimeException;

/** Addresses: url fields and links in texts */
final class UrlMethods
{
  public static function urlScheme(mixed $value): string
  {
    return (string)parse_url(Values::text($value), PHP_URL_SCHEME);
  }

  public static function urlHost(mixed $value): string
  {
    return (string)parse_url(Values::text($value), PHP_URL_HOST);
  }

  public static function urlPath(mixed $value): string
  {
    return (string)parse_url(Values::text($value), PHP_URL_PATH);
  }

  /** One parameter of the query - without a name all of them as object */
  public static function urlQuery(mixed $value, mixed $name = null): mixed
  {
    $query = self::query($value);
    return null !== $name ? ($query[Values::text($name)] ?? null) : $query;
  }

  /** The address with the parameter set (an existing one keeps its place) */
  public static function withQuery(mixed $value, mixed $name = '', mixed $parameter = ''): string
  {
    return self::withParameters($value, array_replace(self::query($value), [Values::text($name) => Values::text($parameter)]));
  }

  public static function withoutQuery(mixed $value, mixed $name = ''): string
  {
    return self::withParameters($value, array_diff_key(self::query($value), [Values::text($name) => true]));
  }

  public static function urlEncode(mixed $value): string
  {
    return rawurlencode(Values::text($value));
  }

  public static function urlDecode(mixed $value): string
  {
    return rawurldecode(Values::text($value));
  }

  /** @return array<string, mixed> */
  private static function query(mixed $value): array
  {
    parse_str((string)parse_url(Values::text($value), PHP_URL_QUERY), $query);
    return $query;
  }

  /** @param array<string, mixed> $query */
  private static function withParameters(mixed $value, array $query): string
  {
    $parts = parse_url(Values::text($value));
    if (false === $parts) {
      throw new RuntimeException('this is no address.');
    }
    $parts['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    return (isset($parts['scheme']) ? $parts['scheme'].'://' : (isset($parts['host']) ? '//' : ''))
      .(isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '')
      .($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')
      .($parts['path'] ?? '').('' !== $parts['query'] ? '?'.$parts['query'] : '')
      .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
  }
}
