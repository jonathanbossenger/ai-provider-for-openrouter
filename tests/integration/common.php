<?php
/** Shared, PHP 7.4-compatible WordPress integration helpers. */
declare(strict_types=1);

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider;

function integration_options(array $argv, array $flags): array
{
    $options = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (in_array($arg, ['--help', '--list', '--allow-paid', '--self-test'], true)) {
            $options[substr($arg, 2)] = true;
            continue;
        }
        if (!in_array($arg, $flags, true) || !isset($argv[$i + 1]) || strpos($argv[$i + 1], '--') === 0) {
            throw new InvalidArgumentException('Unknown or incomplete option: ' . $arg);
        }
        $options[substr($arg, 2)] = $argv[++$i];
    }
    return $options;
}

function integration_check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function integration_bootstrap(string $wordpress): void
{
    $root = realpath($wordpress);
    integration_check($root !== false && is_file($root . '/wp-load.php'), 'Invalid disposable WordPress root');
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    require $root . '/wp-load.php';
    integration_check(function_exists('wp_ai_client_prompt'), 'WordPress AI Client unavailable');
    $registry = AiClient::defaultRegistry();
    integration_check($registry->hasProvider('openrouter'), 'OpenRouter provider is not active');
    $expected = realpath(__DIR__ . '/../../src/Provider/OpenRouterProvider.php');
    $actual = (new ReflectionClass(OpenRouterProvider::class))->getFileName();
    integration_check(realpath($actual) === $expected, 'Active OpenRouter plugin is not this checkout');
    echo 'WordPress ' . get_bloginfo('version') . ' SDK ' . AiClient::VERSION . ' PHP ' . PHP_VERSION . "\n";
}

/** WordPress converts preinitialized hooks to WP_Hook during bootstrap. */
function integration_prebootstrap_blocker(): callable
{
    $blocker = static function () {
        return new WP_Error('integration_bootstrap_http', 'HTTP blocked during integration bootstrap');
    };
    $GLOBALS['wp_filter']['pre_http_request'][0][] = ['function' => $blocker, 'accepted_args' => 3];
    return $blocker;
}

function integration_models(): string
{
    $data = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/image-models.json'), true);
    integration_check(is_array($data) && isset($data['data']), 'Invalid image model fixture');
    $data['data'][0]['id'] = 'openai/gpt-4.1-mini';
    $data['data'][0]['architecture'] = ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']];
    $data['data'][0]['supported_parameters'] = ['tools', 'response_format', 'structured_outputs', 'temperature', 'max_tokens'];
    $data['data'][1]['id'] = 'google/gemini-2.5-flash-image';
    $data['data'][1]['architecture'] = ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text', 'image']];
    $data['data'][2]['id'] = 'fixture/image-only';
    $data['data'][2]['architecture'] = ['input_modalities' => ['text', 'image'], 'output_modalities' => ['image']];
    return (string) json_encode($data);
}

function integration_png(): string
{
    return 'data:image/png;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/fixtures/reference.png'));
}

function integration_schema(bool $optional = false): array
{
    $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string', 'enum' => ['hello']]], 'required' => ['answer']];
    if ($optional) {
        $schema['properties']['note'] = ['type' => 'string'];
    } else {
        $schema['additionalProperties'] = false;
    }
    return $schema;
}

function integration_declaration(): FunctionDeclaration
{
    return new FunctionDeclaration('echo_value', 'Return the given string unchanged', [
        'type' => 'object', 'properties' => ['value' => ['type' => 'string']],
        'required' => ['value'], 'additionalProperties' => false,
    ]);
}

function integration_builder(bool $image = false, ?string $model = null)
{
    return wp_ai_client_prompt('Return hello.')->using_provider('openrouter')->using_model_preference(
        $model ?? ($image ? 'google/gemini-2.5-flash-image' : 'openai/gpt-4.1-mini')
    );
}

function integration_history(): array
{
    return [
        new Message(MessageRoleEnum::user(), [new MessagePart('Earlier synthetic context')]),
        new Message(MessageRoleEnum::model(), [new MessagePart('Earlier response')]),
    ];
}

function integration_result($result)
{
    integration_check(!is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
    return $result;
}
