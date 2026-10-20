# Pseudocode

A small, safe script language for PHP: a few lines of script compute values from the variables you pass in.

```php
require 'src/autoload.php';

$script = Jinx\Pseudocode::parse($source);
$variables = $script->run(['record' => ['name' => 'jane doe']]);   // $variables['record']['short'], $variables['names'] …
if ($script->returned()) {
  $value = $script->value();                                        // the value of "return <value>"
}
```

```
set record.short = record.name
	.split()
	.first()
	.uppercase()                                  // record.short: "JANE"
set names = record.name.split()                   // names[0], names[1]

var total = record.price * record.quantity         // only for the script
if total > 100 and not record.vip and isset(record.email)
	set shipping = 0
else if total > 50
	set shipping = 2.5
else
	set shipping = 4.9
end

each tag in record.tags
	set labels = labels.append(tag.uppercase())
end
set authors = record.authors.map(it.name).filter(it.length() > 0)
set due = record.date.addDays(14).formatDate("d.m.Y")
set sum = total + shipping                        // sum
```

## Live-Demo

[Try it yourself](https://git.jinx-digital.com/pseudocode)

## The language

- **Statements**, one per line (or `;`): `set <name or path> = <value>` (returned by `run()`),
  `var <name> = <value>` (only for the script), `unset <name or path>` (removes it: `unset record.draft`,
  `unset names[0]` - a list stays a list, missing values change nothing), `if … else if … else … end`, `each <name> in <list> … end`,
  `return [<value>]` (ends the script, see below). A line that
  starts with `.` continues the one before. `//` starts a comment. Indent with tabs or spaces as you like.
- **Values:** `"text"` or `'text'`, numbers, `true`, `false`, `null`, lists `[1, 2]`, objects `{"city": "Berlin"}`,
  paths `record.name`, `names[0]`, `record.image.url`.
- **Variables:** the ones passed to `run()`, `now` and `today`.
- **Operators:** `+` (numbers add, lists join, everything else is joined as text), `-`, `*`, `/`, `%`,
  `==`, `!=`, `<`, `<=`, `>`, `>=` (numbers as numbers, everything else as text, so dates `2026-10-20` compare
  right), `and`, `or`, `not`, brackets. Empty values, `0`, `false` and `null` count as false.
- **Is it there?** `isset(record.email)` or `record.email is set`, `record.email is null` - only `null` (and what
  does not exist) is missing; `0`, `""` and `false` are there. **Is it empty?** `record.email is empty` (null, `""`,
  `0`, `false`, `[]`, like PHP's `empty`). Each also with `is not`: `if record.note is not empty`.
- **map() and filter()** work out their argument for every item, with the item as `it`:
  `names.map(it.uppercase())`, short `names.map(uppercase())`, `prices.filter(it > 10)`.

## Methods

| For | Methods |
| --- | --- |
| Text (string, text, Markdown, e-mail, slug, phone …) | `split(sep?)` (without: at spaces), `join(sep?)`, `uppercase()`, `lowercase()`, `capitalize()`, `trim()`, `replace(a, b)`, `before(x)`, `after(x)` (e.g. the domain of an e-mail: `after("@")`), `startsWith(x)`, `endsWith(x)`, `padStart(n, char)`, `padEnd(n, char)`, `truncate(n, end?)`, `slug()`, `stripTags()`, `lines()` |
| Lists and objects (repeatable fields, JSON, groups, references with include) | `first()`, `last()`, `slice(start, length?)`, `length()`, `contains(x)`, `map(…)`, `filter(…)`, `sort()`, `reverse()`, `unique()`, `append(x, …)`, `sum()`, `average()`, `min()`, `max()`, `keys()`, `values()`, `has(key)`, `isEmpty()` |
| Numbers (integer, decimal) | `round(decimals?)`, `floor()`, `ceil()`, `abs()`, `min(x)`, `max(x)`, `formatNumber(decimals, ",", ".")` |
| Dates and times (date, datetime, time) | `addDays(n)`, `addWeeks(n)`, `addMonths(n)`, `addYears(n)`, `addHours(n)`, `addMinutes(n)` (negative: back), `year()`, `month()`, `day()`, `hour()`, `minute()`, `weekday()` (1 = Monday), `formatDate("d.m.Y")`, `daysUntil(date)`, `date()` |
| E-mail addresses | `emailName()` (before the @), `emailDomain()`, `isEmail()`, `maskEmail()` (`J***@example.com`) |
| Addresses (url) | `urlScheme()`, `urlHost()`, `urlPath()`, `urlQuery(name?)`, `withQuery(name, value)`, `withoutQuery(name)`, `urlEncode()`, `urlDecode()` |
| Any value | `number()` (also `12,50`), `text()`, `bool()`, `default(value)` (for empty values) |

A changed date keeps its form: `"2026-10-20".addDays(1)` is `2026-10-21`, a date with time stays one.

## Your own methods

Add methods one by one or a whole class. Every public method of the class is a method of the script: it gets
the value it is called on, then the arguments.

```php
use Jinx\Methods;
use Jinx\Values;

// record.price.vat(7)
Methods::add('vat', static fn(mixed $value, mixed $rate = 19): float => Values::number($value, 'vat') * Values::number($rate, 'vat') / 100);
// record.sku.stock(), record.sku.warehouse() … - an object, so its methods can use its dependencies
Methods::addClass(new ShopMethods($db));
```

- `Values` reads values the way the built-in methods do: `text()`, `number()`, `numeric()`, `truthy()`,
  `listOf()`, `date()`, `dateLike()`.
- A method that cannot work with a value throws a `RuntimeException` with a short message, which the run shows
  with the line of the script.
- Names start with a-z (then letters and digits). The built-in methods cannot be replaced.
- Add your methods before `parse()`: a script is checked against the methods that exist.

## Structure

`src/Pseudocode.php` (parser and interpreter), `src/Values.php` (how values are read), `src/Methods.php` (the
registry), `src/Methods/*` (the built-in methods: `TextMethods`, `ListMethods`, `NumberMethods`, `DateMethods`,
`UrlMethods`, `EmailMethods`, `TypeMethods`), `src/autoload.php` (without Composer).

## What run() gives back

- **Without `return`:** the variables passed in, with what the script changed in them, and every value set with
  `set`. Values of `var`, the item of `each`, `now` and `today` stay in the script.
- **With `return <value>`:** `returned()` is true and `value()` is that value - use it instead of the variables.
  A bare `return` only stops the script.

## Safety

- Errors come with the line, when parsing or running (`RuntimeException`).
- There is no PHP, no file, network or database access. A run has a budget of 100,000 steps, so a loop cannot run
  for ever.

Self-check: `php tests/check.php`. Playground: `demo.php` in the browser.
