<?php

declare(strict_types=1);

namespace Jinx;

use RuntimeException;

/**
 * A small script language for event steps - no PHP, only values (see README.md):
 *
 *   set record.short = record.name.split().first().uppercase()
 *   set total = record.price * record.quantity
 *   if total > 100 and not record.vip
 *     set shipping = 0
 *   else
 *     set shipping = 4.9
 *   end
 *   each tag in record.tags
 *     set labels = labels.append(tag.uppercase())
 *   end
 *   set names = record.authors.map(it.name).filter(it.length() > 0)
 *   return total + shipping
 *
 * `set` returns a value from run(), `var` keeps it in the script. One statement per line (or `;`); a line that starts with `.` continues the one before. `//` comments.
 * Every run has a budget of steps, so loops cannot run for ever. The methods are in Methods (built-in
 * ones in Methods/*, your own are added with Methods::add), how values are read in Values.
 */
final class Pseudocode
{
  private const KEYWORDS = ['set', 'var', 'unset', 'isset', 'is', 'return', 'if', 'else', 'elseif', 'end', 'each', 'in', 'and', 'or', 'not', 'true', 'false', 'null'];
  private const MAX_LENGTH = 10000;
  private const MAX_STEPS = 100000;

  /** @var list<array{0: string, 1: mixed, 2: int}> type, value, line */
  private array $tokens = [];
  private int $pos = 0;
  /** Inside map() / filter(): `uppercase()` means `it.uppercase()` */
  private int $lambda = 0;
  private int $steps = 0;
  /** @var array<string, true> variables only for the script itself (var, each, now, today) */
  private array $locals = [];
  /** Whether the last run ended with "return <value>" - then that value is the result, not the variables */
  private bool $returned = false;
  private mixed $value = null;
  /** @var list<array> */
  private array $statements = [];

  private function __construct()
  {
  }

  /**
   * @throws RuntimeException with the line of a syntax error
   */
  public static function parse(string $source): self
  {
    if (mb_strlen($source) > self::MAX_LENGTH) {
      throw new RuntimeException(sprintf('The script is too long (at most %d characters).', self::MAX_LENGTH));
    }
    $script = new self();
    $script->tokens = self::tokenize($source);
    $script->statements = $script->block([]);
    $script->tokens = [];
    return $script;
  }

  /**
   * Runs it with the placeholders of a record as variables (plus `now` and `today`).
   *
   * @param array<string, mixed> $variables
   * @return array<string, mixed> the variables afterwards - without the ones only for the script (`var`,
   *   the item of `each`, `now`, `today`). After `return <value>`: see returned() and value()
   * @throws RuntimeException with the line of the error
   */
  public function run(array $variables): array
  {
    $this->locals = array_fill_keys(array_diff(['now', 'today'], array_keys($variables)), true);
    $variables += ['now' => date('Y-m-d H:i:s'), 'today' => date('Y-m-d')];
    $this->steps = 0;
    [$this->returned, $this->value] = [false, null];
    $this->execute($this->statements, $variables);
    return array_diff_key($variables, $this->locals);
  }

  /** The last run ended with "return <value>" (a bare "return" only stops) */
  public function returned(): bool
  {
    return $this->returned;
  }

  public function value(): mixed
  {
    return $this->value;
  }

  // --- Statements

  /**
   * @param list<string> $stop keywords that end the block (not consumed); [] = the end of the script
   * @return list<array>
   */
  private function block(array $stop): array
  {
    $statements = [];
    while (true) {
      while ($this->at('nl')) {
        $this->pos++;
      }
      if ($this->at('end')) {
        if ([] !== $stop) {
          $this->fail('"end"');
        }
        return $statements;
      }
      if (in_array($this->word(), $stop, true)) {
        return $statements;
      }
      $statements[] = $this->statement();
      if (!$this->at('nl') && !$this->at('end')) {
        $this->fail('one statement per line (or ";")');
      }
    }
  }

  private function statement(): array
  {
    $line = $this->tokens[$this->pos][2];
    switch ($this->word()) {
      case 'return':
        $this->pos++;
        return ['return', $line, $this->at('nl') || $this->at('end') ? null : $this->expression()];
      case 'set':
      case 'var':
        // set: returned by run(); var: only for the script
        $local = 'var' === $this->next()[1];
        [$name, $path] = $this->target();
        if ($local && 'record' === $name) {
          $this->fail('another name - the record is always returned, use "set record.…"', -1);
        }
        $this->expect('=');
        return ['set', $line, $name, $path, $this->expression(), $local];
      case 'unset':
        // A value of its own, a field of the record or an item: gone, also from what run() returns
        $this->pos++;
        [$name, $path] = $this->target();
        return ['unset', $line, $name, $path];
      case 'if':
        $branches = [];
        $otherwise = null;
        do {
          $this->pos++;
          $branches[] = [$this->expression(), $this->block(['else', 'elseif', 'end'])];
          // "else if" and "elseif" add a branch, "else" is the last one
          $more = 'elseif' === $this->word() || ('else' === $this->word() && 'if' === ($this->tokens[$this->pos + 1][1] ?? null));
          if ($more && 'else' === $this->word()) {
            $this->pos++;
          }
        } while ($more);
        if ('else' === $this->word()) {
          $this->pos++;
          $otherwise = $this->block(['end']);
        }
        $this->keyword('end');
        return ['if', $line, $branches, $otherwise];
      case 'each':
        $this->pos++;
        $name = $this->variable();
        $this->keyword('in');
        $list = $this->expression();
        $body = $this->block(['end']);
        $this->keyword('end');
        return ['each', $line, $name, $list, $body];
      default:
        $this->fail('"set", "var", "unset", "return", "if" or "each"');
    }
  }

  /**
   * What set, var and unset change: a name and its path (record.short, names[0], list[record.index]).
   *
   * @return array{0: string, 1: list<array>}
   */
  private function target(): array
  {
    $name = $this->variable();
    $path = [];
    while ($this->at('.') || $this->at('[')) {
      $path[] = '.' === $this->next()[0] ? ['value', $this->expect('name')] : $this->closing($this->expression(), ']');
    }
    return [$name, $path];
  }

  /**
   * @param list<array> $statements
   * @return bool whether a return ended the script
   */
  private function execute(array $statements, array &$variables): bool
  {
    foreach ($statements as $statement) {
      $line = $statement[1];
      try {
        switch ($statement[0]) {
          case 'return':
            if (null !== $statement[2]) {
              [$this->returned, $this->value] = [true, $this->evaluate($statement[2], $variables)];
            }
            return true;
          case 'set':
            if ($statement[5]) {
              $this->locals[$statement[2]] = true;
            }
            $keys = array_map(fn(array $key): string|int => $this->key($this->evaluate($key, $variables)), $statement[3]);
            $variables[$statement[2]] = self::assign($variables[$statement[2]] ?? null, $keys, $this->evaluate($statement[4], $variables));
            break;
          case 'unset':
            $keys = array_map(fn(array $key): string|int => $this->key($this->evaluate($key, $variables)), $statement[3]);
            if ([] === $keys) {
              unset($variables[$statement[2]]);
            } elseif (array_key_exists($statement[2], $variables)) {
              $variables[$statement[2]] = self::remove($variables[$statement[2]], $keys);
            }
            break;
          case 'if':
            $body = $statement[3];
            foreach ($statement[2] as [$condition, $then]) {
              if (Values::truthy($this->evaluate($condition, $variables))) {
                $body = $then;
                break;
              }
            }
            if (null !== $body && $this->execute($body, $variables)) {
              return true;
            }
            break;
          case 'each':
            $list = $this->evaluate($statement[3], $variables);
            // The item is only for the loop
            $this->locals[$statement[2]] = true;
            foreach (Values::listOf($list ?? [], 'each') as $item) {
              $variables[$statement[2]] = $item;
              if ($this->execute($statement[4], $variables)) {
                return true;
              }
            }
            break;
        }
      } catch (RuntimeException $e) {
        // Errors of nested statements already name their line
        throw str_starts_with($e->getMessage(), 'Line ') ? $e : new RuntimeException(sprintf('Line %d: %s', $line, $e->getMessage()), 0, $e);
      }
    }
    return false;
  }

  // --- Expressions: or < and < not < comparisons < + - < * / % < -x < .method() [index]

  private function expression(): array
  {
    $node = $this->conjunction();
    while ('or' === $this->word()) {
      $this->pos++;
      $node = ['or', $node, $this->conjunction()];
    }
    return $node;
  }

  private function conjunction(): array
  {
    $node = $this->negation();
    while ('and' === $this->word()) {
      $this->pos++;
      $node = ['and', $node, $this->negation()];
    }
    return $node;
  }

  private function negation(): array
  {
    if ('not' === $this->word()) {
      $this->pos++;
      return ['not', $this->negation()];
    }
    $node = $this->sum();
    // x is null, x is set (= isset), x is empty (null, "", 0, false, []) - each also with "is not"
    if ('is' === $this->word()) {
      $this->pos++;
      $not = 'not' === $this->word();
      $this->pos += $not ? 1 : 0;
      $check = $this->word();
      if (!in_array($check, ['null', 'set', 'empty'], true)) {
        $this->fail('"null", "set" or "empty"');
      }
      $this->pos++;
      return 'empty' === $check ? ['empty', $node, $not] : ['null', $node, $not !== ('set' === $check)];
    }
    foreach (['==', '!=', '<', '<=', '>', '>='] as $operator) {
      if ($this->at($operator)) {
        $this->pos++;
        return ['op', $operator, $node, $this->sum()];
      }
    }
    return $node;
  }

  private function sum(): array
  {
    $node = $this->product();
    while ($this->at('+') || $this->at('-')) {
      $node = ['op', $this->next()[0], $node, $this->product()];
    }
    return $node;
  }

  private function product(): array
  {
    $node = $this->unary();
    while ($this->at('*') || $this->at('/') || $this->at('%')) {
      $node = ['op', $this->next()[0], $node, $this->unary()];
    }
    return $node;
  }

  private function unary(): array
  {
    if ($this->at('-')) {
      $this->pos++;
      return ['op', '-', ['value', 0], $this->unary()];
    }
    return $this->postfix();
  }

  private function postfix(): array
  {
    $node = $this->primary();
    while ($this->at('.') || $this->at('[') || $this->continued()) {
      if ('[' === $this->next()[0]) {
        $node = ['get', $node, $this->closing($this->expression(), ']')];
        continue;
      }
      $name = $this->expect('name');
      $node = $this->at('(') ? $this->call($node, $name) : ['get', $node, ['value', $name]];
    }
    return $node;
  }

  private function primary(): array
  {
    [$type, $value] = $this->next();
    if ('name' === $type && 'isset' === $value) {
      // isset(record.email): there and not null
      $this->expect('(');
      return ['null', $this->closing($this->expression(), ')'), true];
    }
    if ('name' === $type && $this->lambda > 0 && $this->at('(')) {
      // map(uppercase()) = map(it.uppercase())
      return $this->call(['var', 'it'], $value);
    }
    return match ($type) {
      'string', 'number' => ['value', $value],
      'name' => match ($value) {
        'true' => ['value', true],
        'false' => ['value', false],
        'null' => ['value', null],
        default => in_array($value, self::KEYWORDS, true) ? $this->fail('a value', -1) : ['var', $value],
      },
      '(' => $this->closing($this->expression(), ')'),
      '[' => ['list', $this->items(']')],
      '{' => $this->object(),
      default => $this->fail('a value', -1),
    };
  }

  /** At "(" after the name of a method */
  private function call(array $node, string $name): array
  {
    if (!Methods::has($name)) {
      $this->fail('one of the methods '.implode(', ', Methods::names()), -1);
    }
    $this->pos++;
    // The argument of map() and filter() is worked out for every item, with the item as "it"
    $lambda = in_array($name, Methods::LAMBDAS, true) ? 1 : 0;
    $this->lambda += $lambda;
    $args = $this->items(')');
    $this->lambda -= $lambda;
    return ['call', $node, $name, $args];
  }

  /** {"street": record.street, city: "Berlin"} - keys are texts or names */
  private function object(): array
  {
    $pairs = [];
    while (!$this->at('}')) {
      $key = $this->at('string') ? $this->next()[1] : $this->expect('name');
      $this->expect(':');
      $pairs[] = [$key, $this->expression()];
      if (!$this->at('}')) {
        $this->expect(',');
      }
    }
    $this->pos++;
    return ['object', $pairs];
  }

  /** @return list<array> expressions up to $close, separated by commas */
  private function items(string $close): array
  {
    $items = [];
    while (!$this->at($close)) {
      $items[] = $this->expression();
      if (!$this->at($close)) {
        $this->expect(',');
      }
    }
    $this->pos++;
    return $items;
  }

  private function closing(array $node, string $close): array
  {
    $this->expect($close);
    return $node;
  }

  /** The next line starts with "." (after empty lines and comments): it continues this one */
  private function continued(): bool
  {
    $look = $this->pos;
    while ('nl' === $this->tokens[$look][0] && "\n" === $this->tokens[$look][1]) {
      $look++;
    }
    if ($look === $this->pos || '.' !== $this->tokens[$look][0]) {
      return false;
    }
    $this->pos = $look;
    return true;
  }

  private function variable(): string
  {
    $name = $this->expect('name');
    if (in_array($name, self::KEYWORDS, true)) {
      $this->fail('a name ("'.$name.'" is a word of the language)', -1);
    }
    return $name;
  }

  private function word(): ?string
  {
    return $this->at('name') ? $this->tokens[$this->pos][1] : null;
  }

  private function keyword(string $word): void
  {
    if ($this->word() !== $word) {
      $this->fail('"'.$word.'"');
    }
    $this->pos++;
  }

  private function at(string $type): bool
  {
    return $this->tokens[$this->pos][0] === $type;
  }

  private function next(): array
  {
    return $this->tokens[$this->pos++];
  }

  private function expect(string $type): mixed
  {
    if (!$this->at($type)) {
      $this->fail('name' === $type ? 'a name' : '"'.$type.'"');
    }
    return $this->next()[1];
  }

  private function fail(string $expected, int $offset = 0): never
  {
    [$type, $value, $line] = $this->tokens[max(0, $this->pos + $offset)];
    $found = match ($type) {
      'end' => 'the end',
      'nl' => 'the end of the line',
      'string' => 'a text',
      default => '"'.$value.'"',
    };
    throw new RuntimeException(sprintf('Line %d: expected %s, found %s.', $line, $expected, $found));
  }

  /** @return list<array{0: string, 1: mixed, 2: int}> */
  private static function tokenize(string $source): array
  {
    $tokens = [];
    $line = 1;
    $length = strlen($source);
    for ($i = 0; $i < $length;) {
      $char = $source[$i];
      $two = substr($source, $i, 2);
      if ("\n" === $char || ';' === $char) {
        // A line break may continue with ".method()" - a ";" always ends the statement
        $tokens[] = ['nl', $char, $line];
        $line += "\n" === $char ? 1 : 0;
        $i++;
      } elseif (ctype_space($char)) {
        $i++;
      } elseif ('//' === $two) {
        while ($i < $length && "\n" !== $source[$i]) {
          $i++;
        }
      } elseif ('"' === $char || "'" === $char) {
        $text = '';
        for ($i++; $i < $length && $source[$i] !== $char && "\n" !== $source[$i]; $i++) {
          if ('\\' === $source[$i] && $i + 1 < $length) {
            $i++;
            $text .= match ($source[$i]) { 'n' => "\n", 't' => "\t", default => $source[$i] };
          } else {
            $text .= $source[$i];
          }
        }
        if (($source[$i] ?? '') !== $char) {
          throw new RuntimeException(sprintf('Line %d: a text is not closed (%s missing).', $line, $char));
        }
        $tokens[] = ['string', $text, $line];
        $i++;
      } elseif (1 === preg_match('/\G\d+(?:\.\d+)?/', $source, $match, 0, $i)) {
        $tokens[] = ['number', str_contains($match[0], '.') ? (float)$match[0] : (int)$match[0], $line];
        $i += strlen($match[0]);
      } elseif (1 === preg_match('/\G[a-z_][a-z0-9_]*/i', $source, $match, 0, $i)) {
        $tokens[] = ['name', $match[0], $line];
        $i += strlen($match[0]);
      } elseif (in_array($two, ['==', '!=', '<=', '>='], true)) {
        $tokens[] = [$two, $two, $line];
        $i += 2;
      } elseif (str_contains('.[](){}:,=+-*/%<>', $char)) {
        $tokens[] = [$char, $char, $line];
        $i++;
      } else {
        throw new RuntimeException(sprintf('Line %d: "%s" is not allowed here.', $line, $char));
      }
    }
    $tokens[] = ['end', null, $line];
    return $tokens;
  }

  // --- Evaluating

  private function evaluate(array $node, array $variables): mixed
  {
    if (++$this->steps > self::MAX_STEPS) {
      throw new RuntimeException(sprintf('the script takes too long (more than %d steps).', self::MAX_STEPS));
    }
    return match ($node[0]) {
      'value' => $node[1],
      'var' => $variables[$node[1]] ?? null,
      'list' => array_map(fn(array $item) => $this->evaluate($item, $variables), $node[1]),
      'object' => array_combine(array_column($node[1], 0), array_map(fn(array $pair) => $this->evaluate($pair[1], $variables), $node[1])),
      'get' => self::get($this->evaluate($node[1], $variables), $this->key($this->evaluate($node[2], $variables))),
      'not' => !Values::truthy($this->evaluate($node[1], $variables)),
      // "is null" / "is not null", isset()
      'null' => (null === $this->evaluate($node[1], $variables)) !== $node[2],
      'empty' => !Values::truthy($this->evaluate($node[1], $variables)) !== $node[2],
      'and' => Values::truthy($this->evaluate($node[1], $variables)) && Values::truthy($this->evaluate($node[2], $variables)),
      'or' => Values::truthy($this->evaluate($node[1], $variables)) || Values::truthy($this->evaluate($node[2], $variables)),
      'op' => self::operate($node[1], $this->evaluate($node[2], $variables), $this->evaluate($node[3], $variables)),
      'call' => $this->callMethod($node, $variables),
    };
  }

  private function callMethod(array $node, array $variables): mixed
  {
    [, $target, $method, $args] = $node;
    $value = $this->evaluate($target, $variables);
    if ('map' === $method || 'filter' === $method) {
      $each = $args[0] ?? throw new RuntimeException(sprintf('.%s() needs what to do with each item, e.g. .%s(it.name).', $method, $method));
      $result = [];
      foreach (Values::listOf($value ?? [], $method) as $item) {
        $out = $this->evaluate($each, ['it' => $item] + $variables);
        if ('map' === $method) {
          $result[] = $out;
        } elseif (Values::truthy($out)) {
          $result[] = $item;
        }
      }
      return $result;
    }
    return Methods::call($method, $value, array_map(fn(array $arg) => $this->evaluate($arg, $variables), $args));
  }

  private function key(mixed $key): string|int
  {
    return match (true) {
      is_int($key), is_string($key) => $key,
      is_float($key) && floor($key) === $key => (int)$key,
      default => throw new RuntimeException('a position must be a number or a name.'),
    };
  }

  private static function get(mixed $value, string|int $key): mixed
  {
    if (is_object($value)) {
      $value = (array)$value;
    }
    return is_array($value) ? ($value[$key] ?? null) : null;
  }

  /** @param list<string|int> $keys */
  private static function remove(mixed $target, array $keys): mixed
  {
    $key = array_shift($keys);
    if (is_object($target)) {
      $target = (array)$target;
    }
    if (!is_array($target) || !array_key_exists($key, $target)) {
      return $target;
    }
    if ([] === $keys) {
      $list = array_is_list($target);
      unset($target[$key]);
      // An item of a list: the others move up (names[0] is the next one)
      return $list ? array_values($target) : $target;
    }
    $target[$key] = self::remove($target[$key], $keys);
    return $target;
  }

  /** @param list<string|int> $keys */
  private static function assign(mixed $target, array $keys, mixed $value): mixed
  {
    if ([] === $keys) {
      return $value;
    }
    $key = array_shift($keys);
    $target = is_object($target) ? (array)$target : (is_array($target) ? $target : []);
    $target[$key] = self::assign($target[$key] ?? null, $keys, $value);
    return $target;
  }

  private static function operate(string $operator, mixed $a, mixed $b): mixed
  {
    switch ($operator) {
      case '+':
        // Numbers add, lists join, everything else is text
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
          return $a + $b;
        }
        if (is_array($a) && is_array($b) && array_is_list($a) && array_is_list($b)) {
          return [...$a, ...$b];
        }
        $text = Values::text($a).Values::text($b);
        return strlen($text) <= Values::MAX_TEXT ? $text : throw new RuntimeException('the text is getting too long.');
      case '==':
        return Values::same($a, $b);
      case '!=':
        return !Values::same($a, $b);
      case '<': case '<=': case '>': case '>=':
        // Numbers as numbers, everything else as text (dates like 2026-10-20 sort right)
        $order = null !== ($x = Values::numeric($a)) && null !== ($y = Values::numeric($b)) ? $x <=> $y : strcmp(Values::text($a), Values::text($b));
        return match ($operator) { '<' => $order < 0, '<=' => $order <= 0, '>' => $order > 0, default => $order >= 0 };
    }
    $x = Values::numeric($a) ?? throw new RuntimeException(sprintf('"%s" needs numbers.', $operator));
    $y = Values::numeric($b) ?? throw new RuntimeException(sprintf('"%s" needs numbers.', $operator));
    if (('/' === $operator || '%' === $operator) && 0 == $y) {
      throw new RuntimeException('division by zero.');
    }
    return match ($operator) {
      '-' => $x - $y,
      '*' => $x * $y,
      '/' => $x / $y,
      default => is_int($x) && is_int($y) ? $x % $y : fmod((float)$x, (float)$y),
    };
  }
}
