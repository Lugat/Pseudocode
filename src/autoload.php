<?php

declare(strict_types=1);

// The classes of Jinx\ without Composer (tests/check.php, demo.php)
spl_autoload_register(static function (string $class): void {
  if (str_starts_with($class, 'Jinx\\')) {
    $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen('Jinx\\'))).'.php';
    if (is_file($file)) {
      require $file;
    }
  }
});
