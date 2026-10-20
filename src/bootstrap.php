<?php
declare(strict_types=1);

define('SWEEPLINE_ROOT', dirname(__DIR__));
if (is_file(SWEEPLINE_ROOT . '/vendor/autoload.php')) {
    require SWEEPLINE_ROOT . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'SweepLine\\')) {
            $file = __DIR__ . '/' . substr($class, (strlen('SweepLine') + 1)) . '.php';
            if (is_file($file)) { require $file; }
        }
    });
}
if (is_file(SWEEPLINE_ROOT . '/.env')) {
    foreach (file(SWEEPLINE_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) { continue; }
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) { putenv(trim($key) . '=' . trim($value)); }
    }
}
