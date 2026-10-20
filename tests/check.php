<?php

declare(strict_types=1);

// Self-check of the script language: php tests/check.php
require __DIR__.'/../src/autoload.php';

use Jinx\Pseudocode;

// Not $ok(): it is switched off on many servers (zend.assertions = -1)
$ok = static fn(bool $condition, string $message = 'failed'): bool => $condition ?: throw new AssertionError($message);
$run = static fn(string $source, array $vars = []): array => Pseudocode::parse($source)->run($vars);
$fails = static function (string $source, string $message, array $vars = []) use ($ok): void {
  try {
    Pseudocode::parse($source)->run($vars);
  } catch (RuntimeException $e) {
    $ok(str_contains($e->getMessage(), $message), $e->getMessage());
    return;
  }
  throw new AssertionError("no error for: {$source}");
};
// The value of "return <value>" - null if the script did not return one
$returned = static function (string $source, array $vars = []): mixed {
  $script = Pseudocode::parse($source);
  $script->run($vars);
  return $script->returned() ? ['value' => $script->value()] : null;
};
$record = ['record' => ['name' => 'jane mary doe', 'price' => '12,50', 'tags' => ['a', 'b']]];

// The examples of the request
$out = $run("set record.newValue = record.name.split().first().uppercase()\nreturn record", $record);
$ok('JANE' === $out['record']['newValue'] && 'jane mary doe' === $out['record']['name']);
$out = $run("set names = record.name.split() // a list", $record);
$ok(['jane', 'mary', 'doe'] === $out['names']);
// return <value> is the result instead of the variables; a bare return only stops
$ok(['value' => ['jane', 'mary', 'doe']] === $returned("set short = 1\nreturn record.name.split();", $record), 'return');
$ok(null === $returned("set a = 1\nreturn\nset b = 2") && null === $returned('set a = 1'), 'no return value');

// Values, methods, +, paths and indexes
$out = $run(implode("\n", [
  "set a = 'x' + 1 + 2",                       // text first: joined
  'set b = 1 + 2.5',
  'set c = record.name.split().slice(1).join("-").capitalize()',
  'set d = record.price.number() + 1',
  'set e = record.missing.default("none")',
  'set f = [record.tags[1], record.tags.length(), "a,b".split(",").last()]',
  'set g.h[0] = "deep"',
  'set i = record.name.contains("mary"); set j = record.tags.contains("z")',
  'set k = (1 + 1) + "!"',
  "set l = 'it\\'s'",
]), $record);
$ok('x12' === $out['a'] && 3.5 === $out['b'] && 'Mary-doe' === $out['c'] && 13.5 === $out['d'] && 'none' === $out['e']);
$ok(['b', 2, 'b'] === $out['f'] && ['h' => [0 => 'deep']] === $out['g'] && true === $out['i'] && false === $out['j'] && '2!' === $out['k'] && "it's" === $out['l']);
$ok(!array_key_exists('result', $out) && null === $returned('set a = 1'));
// Method chains over several lines, indented with tabs or spaces, with comments and empty lines between
$out = $run("set name = record.name\n\t\t.split() // words\n\n                      .first()\n\t.uppercase()\nset after = 1", $record);
$ok('JANE' === $out['name'] && 1 === $out['after'], 'chain');
$fails("set a = record.name;\n.uppercase()", 'expected "set", "var", "unset", "return", "if" or "each"');
// set passes values on, var keeps them in the script - also when set changes them later; the item of each,
// now and today stay inside too
$out = $run("var words = record.name.split()\nset short = words.first()\nvar total = 0\neach w in words\n  set total = total + w.length()\nend\nset days = today.daysUntil(today)\nset record.n = total", $record);
$ok(['days', 'record', 'short'] === (static function (array $keys): array { sort($keys); return $keys; })(array_values(array_intersect(array_keys($out), ['words', 'short', 'total', 'w', 'days', 'now', 'today', 'record']))), 'var');
$ok('jane' === $out['short'] && 11 === $out['record']['n'], 'var values');
$fails('var record = 1', 'the record is always returned');

// unset: a value, a field of the record, an item of a list (the others move up), something not there
$out = $run("set a = 1\nset list = [1, 2, 3]\nunset a\nunset record.price\nunset list[0]\nunset record.nothing.deeper\nunset missing", $record);
$ok(!array_key_exists('a', $out) && !array_key_exists('price', $out['record']) && [2, 3] === $out['list'] && 'jane mary doe' === $out['record']['name'], 'unset');

// isset(), is null, is not null - null only (0, "" and false are there)
$out = $run(implode("\n", [
  'set a = [isset(record.name), isset(record.nothing), isset(record.tags[5]), isset(zero), isset(empty)]',
  'set b = [record.nothing is null, record.name is null, record.name is not null, zero is not null, not record.nothing is null]',
  'if isset(record.name) and record.nothing is null',
  '  set c = "yes"',
  'end',
  'set d = record.tags.filter(it is not null).map(isset(it))',
]), $record + ['zero' => 0, 'empty' => '']);
$ok([true, false, false, true, true] === $out['a'] && [true, false, true, true, false] === $out['b'] && 'yes' === $out['c'] && [true, true] === $out['d'], 'isset');
$fails('set a = 1 is 2', 'expected "null", "set" or "empty"');
// is set / is empty, also with "is not"
$out = $run('set r = [record.name is set, record.nothing is not set, "" is empty, 0 is empty, [] is empty, record.tags is not empty, record.name is empty]', $record);
$ok([true, true, true, true, true, true, false] === $out['r'], 'is set, is empty');
$fails('set isset = 1', 'is a word of the language');

// Arithmetic: precedence, brackets, minus, numeric texts
$out = $run('set a = 2 + 3 * 4 - -1; set b = (2 + 3) * 4 % 7; set c = record.price * 2; set d = 10 / 4; set e = "3" + "4"', $record);
$ok(15 === $out['a'] && 6 === $out['b'] && 25.0 === $out['c'] && 2.5 === $out['d'] && '34' === $out['e'], 'math');
$fails('set a = 1 / 0', 'division by zero');
$fails('set a = "x" * 2', '"*" needs numbers');

// Conditions: comparisons, and / or / not, if - else if - else - end, nested
$branch = static fn(int $n): string => $run("if n > 10 and not small\n  set s = 'big'\nelse if n == 10 or n == '9'\n  set s = 'ten'\nelseif n < 0\n  set s = 'negative'\nelse\n  if n == 0\n    set s = 'zero'\n  else\n    set s = 'small'\n  end\nend", ['n' => $n, 'small' => false])['s'];
$ok(['big', 'ten', 'ten', 'negative', 'zero', 'small'] === array_map($branch, [11, 10, 9, -1, 0, 5]), 'if');
$ok(true === $run('set a = "b" > "a" and "2026-10-20" < "2026-11-01" and 2 != "3" and null == null')['a'], 'compare');
$fails("if 1\n set a = 1", 'expected "end"');
$fails("set end = 1", 'is a word of the language');

// Loops: each, map(), filter(), the shorthand map(uppercase()), return from inside
$out = $run("set total = 0\neach tag in record.tags\n  set total = total + tag.length()\n  set seen = seen.append(tag)\nend", $record);
$ok(2 === $out['total'] && ['a', 'b'] === $out['seen'], 'each');
$out = $run('set up = record.tags.map(uppercase()); set long = record.name.split().filter(it.length() > 3).map(it.capitalize()); set people = [{"name": 1}].map(it.name)', ['record' => $record['record']]);
$ok(['A', 'B'] === $out['up'] && ['Jane', 'Mary'] === $out['long'] && [1] === $out['people'], 'map');
$ok(['street' => 'Main 1', 'city' => 'Berlin'] === $run('set a = {"street": "Main " + 1, city: "Berlin"}')['a'], 'object');
$ok(['value' => 'b'] === $returned("each x in ['a', 'b', 'c']\n  if x == 'b'\n    return x\n  end\nend"), 'return in each');
$fails("each x in [1, 2]\n  each y in [1, 2]\n    each z in [1,2]\n    end\n  end\nend\nset big = [0,0,0,0,0,0,0,0,0,0]\neach a in big\n each b in big\n  each c in big\n   each d in big\n    each e in big\n     set x = 1\n    end\n   end\n  end\n end\nend", 'takes too long');
$fails('set a = uppercase()', 'expected');

// Dates, numbers, texts, addresses, lists
$out = $run(implode("\n", [
  'set due = "2026-01-31".addMonths(1)',
  'set later = "2026-10-20 14:30:00".addHours(2).addMinutes(15)',
  'set german = "2026-10-20".formatDate("d.m.Y")',
  'set parts = [d.year(), d.month(), d.day(), d.weekday()]',
  'set days = "2026-10-20".daysUntil("2026-12-24")',
  'set price = 1234.5.formatNumber(2, ",", ".")',
  'set rounded = [2.345.round(2), 2.5.floor(), 2.1.ceil(), (0 - 3).abs(), [3, 1, 2].max(), [1, 2, 3].average(), 4.min(2)]',
  'set host = "https://example.com/a/b?x=1".urlHost() + record.url.urlPath()',
  'set link = record.url.withQuery("utm", "cms").withoutQuery("x")',
  'set mail = "jane@example.com".after("@") + "|" + "jane@example.com".before("@")',
  'set pad = "7".padStart(3, "0") + "x".padEnd(3, ".") + "Hello world".truncate(5)',
  'set clean = "<p>Hi &amp; bye</p>".stripTags()',
  'set sorted = [10, 9, 2].sort().reverse().unique().join(",")',
  'set obj = {"a": 1}.keys()',
]), ['record' => ['url' => 'https://example.com/shop?x=1&y=2#top'], 'd' => '2026-10-20']);
$ok('2026-03-03' === $out['due'] && '2026-10-20 16:45:00' === $out['later'] && '20.10.2026' === $out['german'], 'dates');
$ok([2026, 10, 20, 2] === $out['parts'] && 65 === $out['days'], 'date parts');
$ok('1.234,50' === $out['price'] && [2.35, 2.0, 3.0, 3, 3, 2, 2] == $out['rounded'], 'numbers');
$ok('example.com/shop' === $out['host'] && 'https://example.com/shop?y=2&utm=cms#top' === $out['link'], 'urls');
$ok('example.com|jane' === $out['mail'] && '007x..Hello…' === $out['pad'] && 'Hi & bye' === $out['clean'] && '10,9,2' === $out['sorted'], 'texts');
$fails('set a = "no date".addDays(1)', 'needs a date');
$ok('ueber-uns-team' === $run('set s = "Über uns & Team!".slug()')['s'], 'slug');

// E-mail addresses
$out = $run('set m = " Jane.Doe@Example.COM "; set r = [m.emailName(), m.emailDomain(), m.isEmail(), "nope@".isEmail(), m.maskEmail(), "x".emailDomain()]');
$ok(['Jane.Doe', 'example.com', true, false, 'J***@example.com', ''] === $out['r'], 'email');

// Your own methods: one by one, or every public method of a class (static or of an object)
use Jinx\Methods;
Methods::add('vat', static fn(mixed $value, mixed $rate = 19): float => round((float)$value * (float)$rate / 100, 2));
Methods::addClass(new class {
  public function greet(mixed $value, mixed $greeting = 'Hello'): string { return $greeting.' '.$value; }
  public static function double(mixed $value): int|float { return 2 * $value; }
  private function hidden(): void {}
});
$out = $run('set a = 100.vat(); set b = 100.vat(7); set c = "Jane".greet("Hi"); set d = [1, 2].map(double())');
$ok(19.0 === $out['a'] && 7.0 === $out['b'] && 'Hi Jane' === $out['c'] && [2, 4] === $out['d'], 'own methods');
Methods::add('vat', static fn(mixed $value): int => 0);
$ok(0 === $run('set a = 1.vat()')['a'], 'an own method may be added again');
$fails('set a = 1.hidden()', 'expected one of the methods');
foreach (['uppercase', 'map'] as $builtIn) {
  try {
    Methods::add($builtIn, static fn() => 1);
    throw new AssertionError("{$builtIn} replaced");
  } catch (InvalidArgumentException) {
  }
}
Methods::add('onlyNumbers', static fn(int $value): int => $value);
$fails('set a = "x".onlyNumbers()', '.onlyNumbers() cannot work with these values');

// return stops
$ok(!isset($run("return 1\nset x = 2")['x']));
// "//" inside a text is no comment
$ok('https://a' === $run('set u = "https://a"')['u']);

// Errors, with the line - when parsing (saving the event) or running
$fails("set a = 1\nset b = ", 'Line 2: expected a value');
$fails('set a = record.name.eval()', 'expected one of the methods');
$fails('print a', 'expected "set", "var", "unset", "return", "if" or "each"');
$fails('set a = "open', 'not closed');
$fails('set a = 1 set b = 2', 'one statement per line');
$fails('set a = $x', '"$" is not allowed');
$fails("set a = 1\nset b = record.tags + 'x'", 'Line 2: a list or an object cannot be used as text', $record);
$fails('set a = "x".join()', '.join() needs a list');
$fails(str_repeat('a', 10001), 'too long');

echo "ok\n";
