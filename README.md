# AI Provider for OpenRouter

An AI Provider for OpenRouter for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

## Requirements

- PHP 7.4 or higher
- When using with WordPress, requires WordPress 7.0 or higher
    - If using an older WordPress release, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed

## Installation

### As a Composer Package

```bash
composer require wordpress/ai-provider-for-openrouter
```

### As a WordPress Plugin

1. Download the plugin files
2. Upload to `/wp-content/plugins/ai-provider-for-openrouter/`
3. Ensure the PHP AI Client plugin is installed and activated
4. Activate the plugin through the WordPress admin

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply ensure both plugins are active and configure your API key:

```php
// Set your OpenRouter API key (or use the OPENROUTER_API_KEY environment variable)
putenv('OPENROUTER_API_KEY=your-api-key');

// Use the provider
$result = AiClient::prompt('Hello, world!')
    ->usingProvider('openrouter')
    ->generateTextResult();
```

### As a Standalone Package

```php
use WordPress\AiClient\AiClient;
use WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(OpenRouterProvider::class);

// Set your API key
putenv('OPENROUTER_API_KEY=your-api-key');

// Generate text
$result = AiClient::prompt('Explain quantum computing')
    ->usingProvider('openrouter')
    ->generateTextResult();

echo $result->toText();
```

## Supported Models

Available models are dynamically discovered from the OpenRouter API. This includes hundreds of models from providers like OpenAI, Anthropic, Google, Meta, Mistral, and many more. See the [OpenRouter documentation](https://openrouter.ai/models) for the full list of available models.

## Configuration

The provider uses the `OPENROUTER_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('OPENROUTER_API_KEY=your-api-key');
```

## License

GPL-2.0-or-later
