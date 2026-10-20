<?php

declare(strict_types=1);

// Playground of the script language (local only): http://localhost:8888/pseudocode/demo.php
// The variables as JSON, the script below - "Run" shows what run() gives back.
require __DIR__.'/src/autoload.php';

use Jinx\Pseudocode;

$json = $_POST['json'] ?? <<<'JSON'
{
  "record": {
    "name": "jane mary doe",
    "price": 89.9,
    "quantity": 2,
    "date": "2026-10-20",
    "tags": ["news", "shop"],
    "url": "https://example.com/shop?ref=home"
  }
}
JSON;
$source = $_POST['script'] ?? <<<'SCRIPT'
set record.short = record.name
	.split()
	.first()
	.uppercase()
set names = record.name.split().map(capitalize())

var total = record.price * record.quantity
if total > 100
	set shipping = 0
else
	set shipping = 4.9
end

each tag in record.tags
	set labels = labels.append("#" + tag)
end
set due = record.date.addDays(14).formatDate("d.m.Y")
set link = record.url.withQuery("utm", "mail")

return (total + shipping).round(2)
SCRIPT;

$output = null;
$error = null;
if ('POST' === $_SERVER['REQUEST_METHOD']) {
  $variables = json_decode($json, true);
  if (!is_array($variables)) {
    $error = 'The JSON is no object: '.json_last_error_msg();
  } else {
    try {
      $started = hrtime(true);
      $script = Pseudocode::parse($source);
      $result = $script->run($variables);
      $ms = (hrtime(true) - $started) / 1e6;
      // return <value>: only that value; otherwise the variables (without var, each, now and today)
      $passed = $script->returned() ? $script->value() : $result;
      $output = json_encode($passed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    } catch (RuntimeException $e) {
      $error = $e->getMessage();
    }
  }
}
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pseudocode playground</title>
  <style>
    :root { --bg: #f7f7f8; --panel: #fff; --text: #1d1d22; --muted: #6b6b76; --line: #dcdce2; --accent: #4f46e5; --error: #b42318; --ok: #067647; }
    @media (prefers-color-scheme: dark) { :root { --bg: #15151a; --panel: #1e1e25; --text: #ececf1; --muted: #9a9aa6; --line: #33333d; --accent: #8b85ff; --error: #f97066; --ok: #47cd89; } }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, sans-serif; }
    main { max-width: 1200px; margin: 0 auto; padding: 24px 16px; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    p { margin: 0 0 16px; color: var(--muted); }
    .grid { display: grid; gap: 16px; grid-template-columns: 1fr 1fr; }
    @media (max-width: 800px) { .grid { grid-template-columns: 1fr; } }
    label { display: block; font-weight: 600; margin-bottom: 6px; }
    textarea, pre { width: 100%; margin: 0; padding: 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: var(--text);
      font: 13px/1.55 ui-monospace, SFMono-Regular, Menlo, monospace; tab-size: 2; white-space: pre; overflow: auto; }
    textarea { min-height: 260px; resize: vertical; }
    textarea:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
    #script { min-height: 420px; }
    pre { min-height: 420px; }
    button { margin: 12px 0; padding: 8px 16px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
    .hint { color: var(--muted); font-size: 13px; margin-left: 8px; }
    .error { color: var(--error); border-color: var(--error); }
    .status { font-size: 13px; color: var(--ok); margin-left: 8px; }
  </style>
</head>
<body>
<main>
  <h1>Pseudocode playground</h1>
  <p>The variables as JSON, the script below. <em>Run</em> shows what <code>run()</code> gives back - or the value of <code>return</code>.</p>
  <form method="post" id="form">
    <div class="grid">
      <div>
        <label for="json">Variables (JSON)</label>
        <textarea id="json" name="json" spellcheck="false"><?= $e($json) ?></textarea>
        <label for="script" style="margin-top: 16px">Script</label>
        <textarea id="script" name="script" spellcheck="false"><?= $e($source) ?></textarea>
      </div>
      <div>
        <label>Result<?php if (null !== $output): ?><span class="status"><?= number_format($ms, 1) ?> ms</span><?php endif ?></label>
        <pre class="<?= null !== $error ? 'error' : '' ?>"><?= $e($error ?? $output ?? 'Press Run (or Cmd/Ctrl + Enter).') ?></pre>
        <button type="submit">Run</button><span class="hint">Cmd/Ctrl + Enter · Tab indents</span>
      </div>
    </div>
  </form>
</main>
<script>
  // Tab indents (Shift+Tab removes a tab at the start of the line), Cmd/Ctrl+Enter runs
  for (const area of document.querySelectorAll('textarea')) {
    area.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault()
        document.getElementById('form').requestSubmit()
      } else if (event.key === 'Tab') {
        event.preventDefault()
        const { selectionStart: start, value } = area
        const lineStart = value.lastIndexOf('\n', start - 1) + 1
        if (event.shiftKey) {
          if (value[lineStart] === '\t') {
            area.setRangeText('', lineStart, lineStart + 1, 'end')
            area.selectionStart = area.selectionEnd = Math.max(lineStart, start - 1)
          }
        } else {
          area.setRangeText('\t', start, area.selectionEnd, 'end')
        }
      }
    })
  }
</script>
</body>
</html>
