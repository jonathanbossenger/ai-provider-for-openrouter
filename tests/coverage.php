<?php

/** Run offline suites with Xdebug coverage; artifacts belong outside this repository. */
declare(strict_types=1);

$autoload = $argv[1] ?? '';
$outputDirectory = $argv[2] ?? '';
$xdebugLibrary = $argv[3] ?? null;
if (!is_file($autoload) || $outputDirectory === '' || !is_dir($outputDirectory)
    || !function_exists('xdebug_start_code_coverage')
    || ($xdebugLibrary !== null && !is_file($xdebugLibrary))
) {
    fwrite(STDERR, "Usage: php -d xdebug.mode=coverage -d zend_extension=/path/to/xdebug.so tests/coverage.php /path/to/vendor/autoload.php /external/output/directory [/path/to/xdebug.so]\n");
    exit(1);
}
$repoRoot = realpath(dirname(__DIR__));
$outputDirectory = realpath($outputDirectory);
if ($outputDirectory === $repoRoot || strpos($outputDirectory, $repoRoot . DIRECTORY_SEPARATOR) === 0) {
    fwrite(STDERR, "Coverage output directory must be outside the repository.\n");
    exit(1);
}

foreach (['model-supported-options.php', 'text-contracts.php', 'image-contracts.php',
    'plugin-no-wordpress.php', 'plugin-bootstrap.php', 'integration/guard-contracts.php'] as $suite) {
    $command = 'OPENROUTER_COVERAGE_DIR=' . escapeshellarg($outputDirectory) . ' '
        . escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL -d memory_limit=1G -d xdebug.mode=coverage '
        . ($xdebugLibrary !== null ? '-d zend_extension=' . escapeshellarg($xdebugLibrary) . ' ' : '')
        . '-d auto_prepend_file=' . escapeshellarg(__DIR__ . '/coverage-start.php') . ' '
        . escapeshellarg(__DIR__ . '/' . $suite) . ' ' . escapeshellarg($autoload);
    passthru($command, $result);
    if ($result !== 0) {
        exit($result);
    }
}

$command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G '
    . escapeshellarg(__DIR__ . '/coverage-report.php')
    . ' ' . escapeshellarg($outputDirectory);
passthru($command, $result);
exit($result);
