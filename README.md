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

## JSON responses

JSON mode and schema responses are separate capabilities. `asJsonResponse($schema)`
sends a JSON schema to compatible models. The adapter preserves an explicitly
provided `strict` flag and otherwise lets the provider choose its default. Use
an SDK schema envelope with `strict => true` when you need strict enforcement;
your schema must meet that provider's strict-schema requirements. Omitting `strict`
does not guarantee support for every ordinary JSON schema: a provider may still
reject optional fields or other schema features. The adapter preserves your schema
and reports that error without rewriting it or retrying in a weaker mode.

## Compatibility notes

Normal model discovery advertises implemented text and text-plus-image inputs;
it does not claim PDF, audio, video or streaming support. Explicitly supplied
text models retain the parent SDK's candidate-count and file serialization
behavior. Whether a particular endpoint accepts those inputs remains its own
contract. Existing baseline sampling options remain listed for backward
compatibility; check the selected model's current metadata before using them.

Custom text options may not replace the selected model, prompt or an explicit
tools/schema contract. Text-only `modalities` and `stream => false` are accepted.
Image-output modalities and active streaming remain rejected on the text route
because its result parser cannot preserve those results. Use the image builder
for generated images.

## Image generation and editing

Use the normal SDK `generateImageResult()` builder with a compatible image-output
model, a text prompt, and optional reference images. Generation and editing use
OpenRouter Chat Completions. Accompanying text is preserved. Ordinary text calls
on dual-capability models continue to request text.

To request an image aspect ratio, pass an `image_config` custom option:

```php
$config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
$config->setCustomOptions(['image_config' => ['aspect_ratio' => '16:9']]);
$result = AiClient::prompt('A red square on a white background')
    ->usingProvider('openrouter')
    ->usingModelConfig($config)
    ->generateImageResult();
```

Typed image count, file type, size/orientation, sampling, tools/schema and
streaming options are not implemented and fail locally. Generated results must
contain inline PNG, JPEG, WebP or GIF data; remote URLs and SVG are rejected.
For missing image token counts, the SDK receives a zero compatibility placeholder;
`openrouter_unknown_token_counts` identifies unknown counts and `openrouter_usage`
preserves the original usage. Zero placeholders are not measured usage or proof
that a request was free.

See [the contract-test documentation](tests/README.md) for configuration rules,
compatibility limits and replay commands.

## Configuration

The provider uses the `OPENROUTER_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('OPENROUTER_API_KEY=your-api-key');
```

## License

GPL-2.0-or-later
