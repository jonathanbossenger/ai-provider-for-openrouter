<?php

/**
 * Plugin Name: AI Provider for OpenRouter
 * Plugin URI: https://github.com/WordPress/ai-provider-for-openrouter
 * Description: AI Provider for OpenRouter for the WordPress AI Client.
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.0
 * Author: WordPress AI Team
 * Author URI: https://make.wordpress.org/ai/
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-openrouter
 *
 * @package WordPress\OpenRouterAiProvider
 */

declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider;

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Provider for OpenRouter with the AI Client.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(OpenRouterProvider::class)) {
        return;
    }

    $registry->registerProvider(OpenRouterProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
