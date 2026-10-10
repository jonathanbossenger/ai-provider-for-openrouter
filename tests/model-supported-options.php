<?php

/**
 * Offline metadata and request-serialization regression tests. See tests/README.md.
 */

declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider\Tests;

use RuntimeException;
use Throwable;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\OpenRouterAiProvider\Metadata\OpenRouterModelMetadataDirectory;
use WordPress\OpenRouterAiProvider\Models\OpenRouterTextGenerationModel;
use WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider;

$autoload = $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Missing SDK autoloader: {$autoload}. See tests/README.md.\n");
    exit(1);
}
require $autoload;
require dirname(__DIR__) . '/src/autoload.php';

// Expose protected seams only; parsing and serialization execute production code.
class MetadataDirectoryForTest extends OpenRouterModelMetadataDirectory
{
    public function parseFixture(array $model): ModelMetadata
    {
        $metadata = $this->parseModelToMetadata($model);
        if ($metadata === null) {
            throw new RuntimeException('Fixture did not produce model metadata.');
        }
        return $metadata;
    }
}

class TextGenerationModelForTest extends OpenRouterTextGenerationModel
{
    public function prepareParams(array $prompt): array
    {
        return $this->prepareGenerateTextParams($prompt);
    }
}

class ProviderForTest extends OpenRouterProvider
{
    public static function fixtureMetadata(): ProviderMetadata
    {
        return static::createProviderMetadata();
    }
}

function metadata(array $extra = []): ModelMetadata
{
    return (new MetadataDirectoryForTest())->parseFixture(array_merge([
        'id' => 'fixture/model',
        'name' => 'Offline fixture',
    ], $extra));
}

function prompt(): array
{
    return [new Message(MessageRoleEnum::user(), [new MessagePart('hello')])];
}

function isSelected(ModelMetadata $metadata, ModelConfig $config): bool
{
    return ModelRequirements::fromPromptData(CapabilityEnum::textGeneration(), prompt(), $config)
        ->areMetBy($metadata);
}

function requestParams(ModelMetadata $metadata, ModelConfig $config): array
{
    $model = new TextGenerationModelForTest($metadata, ProviderForTest::fixtureMetadata());
    $model->setConfig($config);
    return $model->prepareParams(prompt());
}

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function expectOptions(ModelMetadata $metadata, array $extra = []): void
{
    $expected = array_merge([
        'systemInstruction', 'maxTokens', 'temperature', 'topP', 'stopSequences', 'customOptions',
        'inputModalities', 'outputModalities',
    ], $extra);
    $actual = [];
    foreach ($metadata->getSupportedOptions() as $option) {
        $actual[] = $option->getName()->value;
    }
    sort($expected);
    sort($actual);
    expectSame($expected, $actual, 'Supported options (including duplicates) must match.');
}

$tests = [
    'topK is rejected even when the API lists top_k' => static function (): void {
        $metadata = metadata(['supported_parameters' => ['top_k']]);
        $config = new ModelConfig();
        $config->setTopK(7);
        $selected = isSelected($metadata, $config);
        $hasTopK = array_key_exists('top_k', requestParams($metadata, $config));
        printf(
            "  topK=7: selected=%s, request_has_top_k=%s\n",
            $selected ? 'true' : 'false',
            $hasTopK ? 'true' : 'false'
        );
        expectSame(false, $selected, 'Do not select a model for an unsupported SDK topK option.');
        expectOptions($metadata);
    },
    'other sampling mappings remain selectable and serialized' => static function (): void {
        $metadata = metadata(['supported_parameters' => [
            'top_k', 'presence_penalty', 'frequency_penalty', 'logprobs', 'top_logprobs',
        ]]);
        expectOptions($metadata, ['presencePenalty', 'frequencyPenalty', 'logprobs', 'topLogprobs']);
        $config = new ModelConfig();
        $config->setMaxTokens(64);
        $config->setTemperature(0.4);
        $config->setTopP(0.8);
        $config->setStopSequences(['END']);
        $config->setPresencePenalty(0.2);
        $config->setFrequencyPenalty(0.3);
        $config->setLogprobs(true);
        $config->setTopLogprobs(2);
        expectSame(true, isSelected($metadata, $config), 'Supported sampling config must remain selectable.');
        $params = requestParams($metadata, $config);
        foreach ([
            'max_tokens' => 64, 'temperature' => 0.4, 'top_p' => 0.8, 'stop' => ['END'],
            'presence_penalty' => 0.2, 'frequency_penalty' => 0.3, 'logprobs' => true, 'top_logprobs' => 2,
        ] as $name => $value) {
            expectSame($value, $params[$name] ?? null, "Request parameter {$name} must be preserved.");
        }
    },
    'normalized mixed parameters preserve valid mappings without duplicates or topK' => static function (): void {
        expectOptions(metadata(['supported_parameters' => [
            ' TOP_K ', 'top_k', ' PRESENCE_PENALTY ', 'presence_penalty', ' LOGPROBS ',
            ' TOOLS ', 'tools', ' STRUCTURED_OUTPUTS ', ' RESPONSE_FORMAT ',
            '', ' ', null, 17, false, [], new \stdClass(),
        ]]), ['presencePenalty', 'logprobs', 'functionDeclarations', 'outputMimeType', 'outputSchema']);
    },
    'partial tools list preserves baseline options' => static function (): void {
        expectOptions(metadata(['supported_parameters' => ['tools']]), ['functionDeclarations']);
    },
    'response_format alone keeps the existing schema gate' => static function (): void {
        expectOptions(metadata(['supported_parameters' => ['response_format']]), ['outputMimeType']);
    },
    'structured_outputs keeps the existing JSON and schema mappings' => static function (): void {
        expectOptions(metadata(['supported_parameters' => ['structured_outputs']]), ['outputMimeType', 'outputSchema']);
    },
];

foreach ([
    'missing' => [],
    'empty' => ['supported_parameters' => []],
    'null' => ['supported_parameters' => null],
    'string' => ['supported_parameters' => 'top_k,tools'],
    'integer' => ['supported_parameters' => 7],
    'object' => ['supported_parameters' => new \stdClass()],
    'invalid entries' => ['supported_parameters' => [null, false, 7, [], new \stdClass(), '', ' ']],
    'unknown names' => ['supported_parameters' => ['not_a_parameter']],
] as $name => $fixture) {
    $tests["{$name} parameters retain only baseline options"] = static function () use ($fixture): void {
        expectOptions(metadata($fixture));
    };
}

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        printf("PASS %s\n", $name);
    } catch (Throwable $error) {
        $failures++;
        printf("FAIL %s: %s\n", $name, $error->getMessage());
    }
}
printf("%d tests, %d failures\n", count($tests), $failures);
exit($failures === 0 ? 0 : 1);