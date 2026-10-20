<?php

declare(strict_types=1);

namespace Jinx;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * The methods of the script language (`value.name(args)`): the built-in ones and your own.
 * A method gets the value it is called on and its arguments:
 *
 *   Methods::add('vat', static fn(mixed $value, mixed $rate = 19): float => Values::number($value, 'vat') * $rate / 100);
 *   Methods::addClass(new ShopMethods($context));   // every public method: record.sku.stock() …
 *
 * The built-in methods cannot be replaced; adding one of your names again replaces it.
 */
final class Methods
{
  /** Worked out per item by the interpreter (with "it") - no callable */
  public const LAMBDAS = ['map', 'filter'];

  /** @var array<string, callable> */
  private static array $methods = [];
  /** @var array<string, true> names of the built-in methods */
  private static array $core = [];
  private static bool $builtIn = false;

  /**
   * @param callable(mixed, mixed...): mixed $method gets the value and the arguments; throws a RuntimeException with a message for the editor
   */
  public static function add(string $name, callable $method): void
  {
    self::builtIn();
    if (1 !== preg_match('/^[a-z][A-Za-z0-9]{0,39}$/', $name)) {
      throw new InvalidArgumentException(sprintf('"%s" is no name for a script method (a-z first, then letters and digits).', $name));
    }
    if (isset(self::$core[$name]) || in_array($name, self::LAMBDAS, true)) {
      throw new InvalidArgumentException(sprintf('"%s" is a built-in script method and cannot be replaced.', $name));
    }
    self::$methods[$name] = $method;
  }

  /**
   * Every public method of a class (static ones from the name of the class, all from an object).
   *
   * @param object|class-string $class
   */
  public static function addClass(object|string $class): void
  {
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
      if ($method->isConstructor() || str_starts_with($method->getName(), '__') || (is_string($class) && !$method->isStatic())) {
        continue;
      }
      self::add($method->getName(), $method->isStatic() ? [$reflection->getName(), $method->getName()] : [$class, $method->getName()]);
    }
  }

  public static function has(string $name): bool
  {
    self::builtIn();
    return isset(self::$methods[$name]) || in_array($name, self::LAMBDAS, true);
  }

  /** @return list<string> */
  public static function names(): array
  {
    self::builtIn();
    return [...array_keys(self::$methods), ...self::LAMBDAS];
  }

  /** @param list<mixed> $args */
  public static function call(string $name, mixed $value, array $args): mixed
  {
    self::builtIn();
    $method = self::$methods[$name] ?? throw new RuntimeException(sprintf('there is no method .%s().', $name));
    try {
      return $method($value, ...$args);
    } catch (\ArgumentCountError) {
      throw new RuntimeException(sprintf('.%s() got too few values.', $name));
    } catch (\TypeError $e) {
      // A method that wants other values than it got
      throw new RuntimeException(sprintf('.%s() cannot work with these values.', $name), 0, $e);
    }
  }

  /** @internal tests: only the built-in methods again */
  public static function reset(): void
  {
    self::$methods = [];
    self::$core = [];
    self::$builtIn = false;
  }

  private static function builtIn(): void
  {
    if (self::$builtIn) {
      return;
    }
    self::$builtIn = true;
    foreach ([Methods\TextMethods::class, Methods\ListMethods::class, Methods\NumberMethods::class, Methods\DateMethods::class, Methods\UrlMethods::class, Methods\EmailMethods::class, Methods\TypeMethods::class] as $class) {
      self::addClass($class);
    }
    self::$core = array_fill_keys(array_keys(self::$methods), true);
  }
}
