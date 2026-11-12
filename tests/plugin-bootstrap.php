<?php

/** Exercise late SDK activation and idempotent provider registration. */
declare(strict_types=1);

define('ABSPATH', '/offline-wordpress/');
$hooks = [];
function add_action($hook, $callback, $priority = 10): void
{
    global $hooks;
    $hooks[] = [$hook, $callback, $priority];
}

require dirname(__DIR__) . '/ai-provider-for-openrouter.php';
if ($hooks !== [['init', 'WordPress\\OpenRouterAiProvider\\register_provider', 5]]) {
    throw new RuntimeException('Plugin did not register the expected init hook.');
}

\WordPress\OpenRouterAiProvider\register_provider();
echo "PASS missing SDK defers registration without error\n";

require __DIR__ . '/harness.php';
$registry = \WordPress\AiClient\AiClient::defaultRegistry();
if ($registry->hasProvider(\WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider::class)) {
    throw new RuntimeException('Offline registry unexpectedly pre-registered the provider.');
}

\WordPress\OpenRouterAiProvider\register_provider();
if (!$registry->hasProvider(\WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider::class)) {
    throw new RuntimeException('Provider was not registered when SDK became available.');
}
\WordPress\OpenRouterAiProvider\register_provider();
echo "PASS late SDK registration and repeated callback are idempotent\n2 tests, 0 failures\n";
