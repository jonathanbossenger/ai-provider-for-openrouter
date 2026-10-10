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

## Image generation and editing

The normal SDK `generateImageResult()` operation selects compatible discovered
image-output models. Image-only and dual text/image models use OpenRouter Chat
Completions; ordinary text/tools/schema operations on dual models stay unchanged.
Prompts support text and, where model metadata allows, reference image files.
This adapter does not implement the separate dedicated Image API.

Image calls reject unsupported options locally, including explicit output count,
size/aspect/orientation, MIME/file type, tools/schema, sampling and streaming.
Only system instructions, image-only output requirements, and bounded user/provider
routing custom options are supported. Generated results require valid inline image
data URIs; remote result URLs are rejected rather than fetched or assigned a guessed
MIME. Accompanying text and image/candidate order are retained.

For image responses, optional `tool_calls`, `function_call`, `audio`, and `video`
fields may be absent, `null`, or exactly `[]` (no payload). Every other value,
including falsy scalars (`0`, `false`, `''`) and nonempty arrays/objects, is rejected.
This placeholder tolerance does not add tool execution or audio/video output support.

For image usage, prompt/completion/total token counts remain distinct. SDK 0.4.3
cannot express null counts: missing counts use this provider's explicit -1 unknown
sentinel, never fabricated zero. Do not sum or bill unknown counts. Original usage
is retained in result additional data under `openrouter_usage`, with missing names
in `openrouter_unknown_token_counts`. This is not a universal SDK convention.

See [the offline contract tests](tests/README.md) in a Git checkout for exact supported
forms, limitations, provenance and replay commands. Live OpenRouter/WordPress
acceptance and actual PHP 7.4 runtime verification are not claimed by offline tests.

## Configuration

The provider uses the `OPENROUTER_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('OPENROUTER_API_KEY=your-api-key');
```

## License

GPL-2.0-or-later
