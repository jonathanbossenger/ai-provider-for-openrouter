<?php

/** Run isolated processes so provider singleton caches never leak between suites. */
declare(strict_types=1);

$autoload = $argv[1] ?? '';
if (!is_file($autoload)) {
    fwrite(STDERR, "Usage: php tests/run.php /path/to/isolated/vendor/autoload.php\n");
    exit(1);
}
foreach (['model-supported-options.php', 'text-contracts.php'] as $suite) {
    $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL '
        . escapeshellarg(__DIR__ . '/' . $suite) . ' ' . escapeshellarg($autoload);
    passthru($command, $result);
    if ($result !== 0) {
        exit($result);
    }
}
