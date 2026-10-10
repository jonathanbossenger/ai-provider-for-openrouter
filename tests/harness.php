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
if (\Composer\InstalledVersions::getPrettyVersion('wordpress/php-ai-client') !== '0.4.3'
    || \Composer\InstalledVersions::getReference('wordpress/php-ai-client') !== 'b47c878b161af543f947fdb62999ecc8604998d3'
) {
    throw new RuntimeException('Tests require the documented SDK pin; review the contract before upgrading.');
}
require dirname(__DIR__) . '/src/autoload.php';

// Expose protected seams only; parsing and serialization execute production code.
class MetadataDirectoryForTest extends OpenRouterModelMetadataDirectory
{
    public function parseOptionalFixture(array $model): ?ModelMetadata
    {
        return $this->parseModelToMetadata($model);
    }

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

function expectThrows(callable $action, string $contains): void
{
    try {
        $action();
    } catch (\WordPress\AiClient\Common\Exception\InvalidArgumentException $error) {
        if (strpos($error->getMessage(), $contains) === false) {
            throw new RuntimeException('Unexpected rejection: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected local rejection: ' . $contains);
}

function runTests(array $tests): void
{
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
}
