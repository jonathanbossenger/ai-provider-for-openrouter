<?php

/** Optional Xdebug collector. Set OPENROUTER_COVERAGE_DIR to an external directory. */
declare(strict_types=1);

$coverageDirectory = getenv('OPENROUTER_COVERAGE_DIR');
if ($coverageDirectory === false || $coverageDirectory === '' || !function_exists('xdebug_start_code_coverage')) {
    fwrite(STDERR, "Coverage requires OPENROUTER_COVERAGE_DIR and Xdebug coverage mode.\n");
    exit(1);
}

xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, [
    dirname(__DIR__) . '/src/', dirname(__DIR__) . '/ai-provider-for-openrouter.php',
]);
xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE | XDEBUG_CC_BRANCH_CHECK);
register_shutdown_function(static function () use ($coverageDirectory): void {
    $suite = basename($_SERVER['SCRIPT_FILENAME'] ?? 'unknown.php', '.php');
    $file = $coverageDirectory . DIRECTORY_SEPARATOR . $suite . '.ser';
    if (file_put_contents($file, serialize(xdebug_get_code_coverage())) === false) {
        fwrite(STDERR, "Could not write coverage for {$suite}.\n");
    }
});
