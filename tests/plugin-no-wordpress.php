<?php

/** The plugin entrypoint must return before hooking anything outside WordPress. */
declare(strict_types=1);

function add_action(): void
{
    throw new RuntimeException('Plugin registered a hook without ABSPATH.');
}

$before = count(spl_autoload_functions() ?: []);
require dirname(__DIR__) . '/ai-provider-for-openrouter.php';
$after = count(spl_autoload_functions() ?: []);
if ($before !== $after) {
    throw new RuntimeException('Plugin registered an autoloader without ABSPATH.');
}
echo "PASS entrypoint returns before autoload and init hook without WordPress\n1 tests, 0 failures\n";
