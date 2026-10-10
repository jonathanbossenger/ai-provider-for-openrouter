<?php

/** Prepare a NEW isolated dependency directory; never modify the project's manifests. */
declare(strict_types=1);

$directory = $argv[1] ?? '';
if ($directory === '' || file_exists($directory) || !mkdir($directory, 0700, true)) {
    fwrite(STDERR, "Pass a new, non-existing dependency directory. Nothing is overwritten.\n");
    exit(1);
}
$lockPath = dirname(__DIR__) . '/composer.lock';
$lock = json_decode(file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
$packages = array_merge($lock['packages'], $lock['packages-dev']);
$sdk = array_values(array_filter($packages, static function (array $package): bool {
    return $package['name'] === 'wordpress/php-ai-client';
}));
if (count($sdk) !== 1 || $sdk[0]['version'] !== '0.4.3'
    || $sdk[0]['source']['reference'] !== 'b47c878b161af543f947fdb62999ecc8604998d3'
) {
    throw new RuntimeException('Review the test contract before changing the pinned SDK.');
}
$manifest = [
    'require-dev' => ['wordpress/php-ai-client' => '0.4.3'],
    'config' => ['allow-plugins' => false, 'platform' => ['php' => '7.4']],
];
file_put_contents($directory . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
copy($lockPath, $directory . '/composer.lock');
printf("Prepared locked dependencies in %s (plugins/scripts disabled by documented install).\n", $directory);
