<?php

/** Offline integration contracts; all HTTP responses below are synthetic fixtures. */
declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider\Tests;

use RuntimeException;
use WordPress\AiClient\Builders\PromptBuilder;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WordPress\OpenRouterAiProvider\Models\OpenRouterTextGenerationModel;
use WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider;

require __DIR__ . '/harness.php';

function fixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
}

class FixtureTransport implements HttpTransporterInterface
{
    public array $requests = [];
    public array $responses = [];

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        if ($request->getUri() === OpenRouterProvider::url('/models')) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode(fixture('models')));
        }
        expectSame(OpenRouterProvider::url('/chat/completions'), $request->getUri(), 'Only text route allowed');
        $this->requests[] = $request;
        $response = array_shift($this->responses) ?? fixture('text-response');
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
    }
}

function registry(FixtureTransport $transport): ProviderRegistry
{
    $registry = new ProviderRegistry();
    $registry->setHttpTransporter($transport);
    $registry->registerProvider(OpenRouterProvider::class);
    $registry->setProviderRequestAuthentication('openrouter', new ApiKeyRequestAuthentication('offline-fixture-not-a-key'));
    return $registry;
}

function directModel(array $extra, ModelConfig $config, FixtureTransport $transport): OpenRouterTextGenerationModel
{
    $model = new OpenRouterTextGenerationModel(metadata($extra), OpenRouterProvider::metadata());
    $model->setConfig($config);
    $model->setHttpTransporter($transport);
    $model->setRequestAuthentication(new ApiKeyRequestAuthentication('offline-fixture-not-a-key'));
    return $model;
}

function schemaConfig(?array $schema = null): ModelConfig
{
    $config = new ModelConfig();
    $config->setOutputSchema($schema ?? fixture('schema'));
    return $config;
}

function declaration(): FunctionDeclaration
{
    return new FunctionDeclaration('lookup', 'Look up a query', [
        'type' => 'object', 'properties' => ['query' => ['type' => 'string']],
        'required' => ['query'], 'additionalProperties' => false,
    ]);
}

function assertSchemaPayload(array $payload, bool $strict = true, string $name = 'response'): void
{
    expectSame('json_schema', $payload['response_format']['type'] ?? null, 'Schema type');
    expectSame([
        'name' => $name, 'schema' => fixture('schema'), 'strict' => $strict,
    ], $payload['response_format']['json_schema'] ?? null, 'Complete schema envelope');
    expectSame(true, $payload['provider']['require_parameters'] ?? null, 'Endpoint support requirement');
}

function rejectBeforeTransport(array $extra, ModelConfig $config, string $reason, ?array $messages = null): void
{
    $transport = new FixtureTransport();
    $model = directModel($extra, $config, $transport);
    expectThrows(static function () use ($model, $messages): void {
        $model->generateTextResult($messages ?? prompt());
    }, $reason);
    expectSame([], $transport->requests, 'Rejected before transport');
}

$tests = [
    'Roberto combined as_json_response equivalent selects vision model and sends full contract' => static function (): void {
        $transport = new FixtureTransport();
        $builder = (new PromptBuilder(registry($transport), 'hello'))
            ->usingProvider('openrouter')->usingModelPreference('fixture/vision')
            ->usingSystemInstruction('Return an answer after looking up the query.')
            ->usingFunctionDeclarations(declaration())->asJsonResponse(fixture('schema'));
        $result = $builder->generateTextResult();
        expectSame('fixture/vision', $result->getModelMetadata()->getId(), 'Intended model selected');
        expectSame('{"answer":"hello"}', $result->toText(), 'Actual output parsed');
        expectSame(1, count($transport->requests), 'Single request, no fallback');
        $payload = $transport->requests[0]->getData();
        expectSame('fixture/vision', $payload['model'], 'Selected model sent');
        expectSame('system', $payload['messages'][0]['role'], 'System instruction serialized');
        expectSame('Return an answer after looking up the query.', $payload['messages'][0]['content'][0]['text'], 'System text');
        expectSame([['type' => 'function', 'function' => declaration()->toArray()]], $payload['tools'], 'Tools serialized');
        assertSchemaPayload($payload);
    },
    'combined asOutputSchema and direct usingModelConfig paths' => static function (): void {
        foreach (['schema-builder', 'direct-config'] as $path) {
            $transport = new FixtureTransport();
            $builder = (new PromptBuilder(registry($transport), 'hello'))->usingProvider('openrouter')
                ->usingModelPreference('fixture/vision');
            if ($path === 'schema-builder') {
                $builder->usingSystemInstruction('system')->usingFunctionDeclarations(declaration())
                    ->asOutputSchema(fixture('schema'));
            } else {
                $config = schemaConfig();
                $config->setSystemInstruction('system');
                $config->setFunctionDeclarations([declaration()]);
                $builder->usingModelConfig($config);
            }
            $result = $builder->generateTextResult();
            expectSame('fixture/vision', $result->getModelMetadata()->getId(), 'Combined selection');
            $payload = $transport->requests[0]->getData();
            expectSame('system', $payload['messages'][0]['content'][0]['text'], 'System text');
            expectSame('lookup', $payload['tools'][0]['function']['name'], 'Tool name');
            assertSchemaPayload($payload);
        }
    },
    'raw schema direct config with implicit MIME uses default envelope' => static function (): void {
        $config = schemaConfig();
        expectSame('application/json', $config->getOutputMimeType(), 'Pinned SDK default MIME');
        assertSchemaPayload(requestParams(metadata(['supported_parameters' => ['structured_outputs']]), $config));
    },
    'schema with truly unset MIME still sends schema' => static function (): void {
        $config = new class extends ModelConfig {
            public function getOutputMimeType(): ?string { return null; }
        };
        $config->setOutputSchema(fixture('schema'));
        assertSchemaPayload(requestParams(metadata(['supported_parameters' => ['structured_outputs']]), $config));
    },
    'envelope default strict and explicit false retain caller name and schema' => static function (): void {
        foreach ([null, false, true] as $strict) {
            $envelope = ['name' => 'answer', 'schema' => fixture('schema')];
            if ($strict !== null) { $envelope['strict'] = $strict; }
            assertSchemaPayload(requestParams(metadata(['supported_parameters' => ['structured_outputs']]), schemaConfig($envelope)), $strict ?? true, 'answer');
        }
    },
    'schema merges provider preferences without weakening endpoint guard' => static function (): void {
        $config = schemaConfig();
        $config->setCustomOptions(['provider' => ['order' => ['FixtureEndpoint'], 'allow_fallbacks' => false, 'sort' => 'price']]);
        $payload = requestParams(metadata(['supported_parameters' => ['structured_outputs']]), $config);
        assertSchemaPayload($payload);
        expectSame(['order' => ['FixtureEndpoint'], 'allow_fallbacks' => false, 'sort' => 'price', 'require_parameters' => true], $payload['provider'], 'Preferences retained');
    },
    'tools-only metadata selects and serializes declarations' => static function (): void {
        $config = new ModelConfig();
        $config->setFunctionDeclarations([declaration()]);
        $model = metadata(['supported_parameters' => ['tools']]);
        expectSame(true, isSelected($model, $config), 'Tools-only selected');
        expectSame('lookup', requestParams($model, $config)['tools'][0]['function']['name'], 'Tool serialized');
        expectSame(false, isSelected($model, schemaConfig()), 'Not schema capable');
    },
    'JSON-only response_format and structured_outputs each support JSON object mode' => static function (): void {
        foreach (['response_format', 'structured_outputs'] as $parameter) {
            $config = new ModelConfig();
            $config->setOutputMimeType('application/json');
            $model = metadata(['supported_parameters' => [$parameter]]);
            expectSame(true, isSelected($model, $config), 'JSON selected');
            expectSame(['type' => 'json_object'], requestParams($model, $config)['response_format'], 'JSON mode');
        }
    },
    'response_format does not select schema or permit direct schema bypass' => static function (): void {
        $config = schemaConfig();
        expectSame(false, isSelected(metadata(['supported_parameters' => ['response_format']]), $config), 'Schema selection denied');
        rejectBeforeTransport(['supported_parameters' => ['response_format']], $config, 'does not support');
    },
    'explicit incompatible schema MIME fails rather than dropping schema' => static function (): void {
        foreach (['text/plain', 'image/png', 'application/xml'] as $mime) {
            $config = new ModelConfig();
            $config->setOutputMimeType($mime);
            $config->setOutputSchema(fixture('schema'));
            rejectBeforeTransport(['supported_parameters' => ['structured_outputs']], $config, 'application/json');
        }
    },
    'ordinary text MIME remains selectable with missing advanced metadata' => static function (): void {
        $config = new ModelConfig();
        $config->setOutputMimeType('text/plain');
        $model = metadata();
        expectSame(true, isSelected($model, $config), 'Plain text selected');
        expectSame(false, array_key_exists('response_format', requestParams($model, $config)), 'No JSON format');
    },
    'tools output parsing and response-to-second-request round trip' => static function (): void {
        $transport = new FixtureTransport();
        $transport->responses = [fixture('tool-response'), fixture('text-response')];
        $config = new ModelConfig();
        $config->setFunctionDeclarations([declaration()]);
        $model = directModel(['supported_parameters' => ['tools']], $config, $transport);
        $result = $model->generateTextResult(prompt());
        $candidate = $result->getCandidates()[0];
        expectSame(true, $candidate->getFinishReason()->isToolCalls(), 'Tool finish reason');
        $assistant = $candidate->getMessage();
        $call = $assistant->getParts()[0]->getFunctionCall();
        expectSame('call_fixture', $call->getId(), 'Call ID');
        expectSame('lookup', $call->getName(), 'Call name');
        expectSame(['query' => 'hello'], $call->getArgs(), 'Parsed arguments');
        $messages = array_merge(prompt(), [$assistant, new Message(MessageRoleEnum::user(), [
            new MessagePart(new FunctionResponse($call->getId(), $call->getName(), ['answer' => 'hello'])),
        ])]);
        $next = $model->generateTextResult($messages);
        $payload = $transport->requests[1]->getData();
        expectSame('assistant', $payload['messages'][1]['role'], 'Assistant role');
        expectSame([['type' => 'function', 'id' => 'call_fixture', 'function' => ['name' => 'lookup', 'arguments' => '{"query":"hello"}']]], $payload['messages'][1]['tool_calls'], 'Call encoded back');
        expectSame(['role' => 'tool', 'content' => '{"answer":"hello"}', 'tool_call_id' => 'call_fixture'], $payload['messages'][2], 'Tool response encoded');
        expectSame('{"answer":"hello"}', $next->toText(), 'Next text parsed');
    },
];

foreach ([false, null, 0, 'true'] as $value) {
    $tests['reject contradictory provider.require_parameters ' . var_export($value, true)] = static function () use ($value): void {
        $config = schemaConfig();
        $config->setCustomOptions(['provider' => ['require_parameters' => $value]]);
        rejectBeforeTransport(['supported_parameters' => ['structured_outputs']], $config, 'require_parameters');
    };
}
foreach (['provider' => 'not-an-object', 'model' => 'other', 'messages' => [], 'tools' => [], 'response_format' => ['type' => 'text'], 'modalities' => ['image'], 'models' => ['other'], 'stream' => true] as $key => $value) {
    $tests['reject custom contract collision ' . $key] = static function () use ($key, $value): void {
        $config = schemaConfig();
        $config->setCustomOptions([$key => $value]);
        rejectBeforeTransport(['supported_parameters' => ['structured_outputs']], $config, $key);
    };
}
foreach (['missing' => [], 'null' => ['supported_parameters' => null], 'string' => ['supported_parameters' => 'tools,structured_outputs'], 'invalid' => ['supported_parameters' => [false, null, 4, [], new \stdClass()]], 'tool_choice-only' => ['supported_parameters' => ['tool_choice']]] as $name => $extra) {
    $tests['malformed/missing advanced evidence rejects tools and schema: ' . $name] = static function () use ($extra): void {
        foreach (['tools', 'schema', 'json'] as $kind) {
            $config = new ModelConfig();
            if ($kind === 'tools') { $config->setFunctionDeclarations([declaration()]); }
            elseif ($kind === 'schema') { $config->setOutputSchema(fixture('schema')); }
            else { $config->setOutputMimeType('application/json'); }
            expectSame(false, isSelected(metadata($extra), $config), 'No invented advanced support');
            rejectBeforeTransport($extra, $config, 'does not support');
        }
    };
}
foreach (['name-only' => ['name' => 'answer'], 'schema-only' => ['schema' => fixture('schema')], 'bad-name' => ['name' => '', 'schema' => fixture('schema')], 'bad-strict' => ['name' => 'answer', 'schema' => fixture('schema'), 'strict' => 'false'], 'bad-schema' => ['name' => 'answer', 'schema' => 'not-schema']] as $name => $envelope) {
    $tests['invalid schema envelope fails locally: ' . $name] = static function () use ($envelope): void {
        rejectBeforeTransport(['supported_parameters' => ['structured_outputs']], schemaConfig($envelope), 'json_schema');
    };
}
foreach (['array-fields' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']], 'legacy' => ['modality' => 'text+image->text'], 'normalized' => ['input_modalities' => [' TEXT ', 'IMAGE', 'text'], 'output_modalities' => [' TEXT ']]] as $name => $architecture) {
    $tests['vision exact sets select text-only and text+image, not unsafe media: ' . $name] = static function () use ($architecture): void {
        $metadata = metadata(['architecture' => $architecture]);
        expectSame(true, isSelected($metadata, new ModelConfig()), 'Vision text selected');
        $image = new Message(MessageRoleEnum::user(), [new MessagePart('describe'), new MessagePart(new File('https://fixtures.invalid/image.png', 'image/png'))]);
        expectSame(true, ModelRequirements::fromPromptData(CapabilityEnum::textGeneration(), [$image], new ModelConfig())->areMetBy($metadata), 'Vision image selected');
        $transport = new FixtureTransport();
        directModel(['architecture' => $architecture], new ModelConfig(), $transport)->generateTextResult([$image]);
        expectSame('image_url', $transport->requests[0]->getData()['messages'][0]['content'][1]['type'], 'Image input encoded on text route');
        foreach (['application/pdf', 'video/mp4', 'audio/wav'] as $mime) {
            $messages = [new Message(MessageRoleEnum::user(), [new MessagePart('media'), new MessagePart(new File('https://fixtures.invalid/media', $mime))])];
            expectSame(false, ModelRequirements::fromPromptData(CapabilityEnum::textGeneration(), $messages, new ModelConfig())->areMetBy($metadata), 'Unsafe input denied');
            rejectBeforeTransport(['architecture' => $architecture], new ModelConfig(), 'does not support', $messages);
        }
        expectSame(false, in_array(CapabilityEnum::imageGeneration(), $metadata->getSupportedCapabilities(), true), 'No image generation advertised');
        $config = new ModelConfig();
        $config->setOutputModalities([ModalityEnum::text()]);
        expectSame(true, isSelected($metadata, $config), 'Plain text output allowed');
        $config->setOutputModalities([ModalityEnum::text(), ModalityEnum::image()]);
        expectSame(false, isSelected($metadata, $config), 'Mixed output not advertised');
        rejectBeforeTransport(['architecture' => $architecture], $config, 'does not support');
    };
}
$tests['dual output metadata permits ordinary text only, no image generation route'] = static function (): void {
    $model = metadata(['architecture' => ['modality' => 'text+image->text+image']]);
    expectSame(true, isSelected($model, new ModelConfig()), 'Ordinary text on dual-output model');
    expectSame(false, in_array(CapabilityEnum::imageGeneration(), $model->getSupportedCapabilities(), true), 'Image capability paused');
    $config = new ModelConfig();
    $config->setOutputModalities([ModalityEnum::image()]);
    expectSame(false, isSelected($model, $config), 'Image-only output not advertised yet');
};
$tests['explicit supported provider requirement and schema description preserved'] = static function (): void {
    $config = schemaConfig(['name' => 'answer', 'schema' => fixture('schema'), 'description' => 'Answer envelope']);
    $config->setCustomOptions(['provider' => ['require_parameters' => true, 'only' => ['FixtureEndpoint']]]);
    $payload = requestParams(metadata(['supported_parameters' => ['structured_outputs']]), $config);
    expectSame('Answer envelope', $payload['response_format']['json_schema']['description'], 'Description retained');
    expectSame(['require_parameters' => true, 'only' => ['FixtureEndpoint']], $payload['provider'], 'Explicit true and preferences retained');
};
$tests['schema provider list or null rejected before transport'] = static function (): void {
    foreach ([null, ['not-an-object']] as $value) {
        $config = schemaConfig();
        $config->setCustomOptions(['provider' => $value]);
        rejectBeforeTransport(['supported_parameters' => ['structured_outputs']], $config, 'provider');
    }
};
$tests['schema lists and invalid names rejected before transport'] = static function (): void {
    foreach ([[fixture('schema')], ['name' => 'has spaces', 'schema' => fixture('schema')], ['name' => str_repeat('a', 65), 'schema' => fixture('schema')], ['name' => 'answer', 'schema' => [fixture('schema')]]] as $schema) {
        rejectBeforeTransport(['supported_parameters' => ['structured_outputs']], schemaConfig($schema), 'json_schema');
    }
};
$tests['empty object schema remains JSON object in the encoded request'] = static function (): void {
    $transport = new FixtureTransport();
    directModel(['supported_parameters' => ['structured_outputs']], schemaConfig([]), $transport)->generateTextResult(prompt());
    $json = $transport->requests[0]->getBody();
    $encoded = json_decode($json);
    expectSame(true, $encoded->response_format->json_schema->schema instanceof \stdClass, 'Empty schema encoded as object');
};
$tests['unsupported architectures excluded and explicit directional arrays take precedence'] = static function (): void {
    $directory = new MetadataDirectoryForTest();
    foreach ([['modality' => 'text->image'], ['modality' => 'image->text'], ['modality' => 'audio->audio'], ['modality' => 'nonsense'], ['input_modalities' => 'text', 'output_modalities' => ['text']], ['input_modalities' => ['text'], 'output_modalities' => [false, null]], 'malformed'] as $architecture) {
        expectSame(null, $directory->parseOptionalFixture(['id' => 'fixture/unsupported', 'architecture' => $architecture]), 'Unsupported model excluded');
    }
    $model = metadata(['architecture' => ['modality' => 'text+image->image', 'input_modalities' => ['text'], 'output_modalities' => ['text']]]);
    expectSame(true, isSelected($model, new ModelConfig()), 'Explicit arrays preferred');
    $image = [new Message(MessageRoleEnum::user(), [new MessagePart('image'), new MessagePart(new File('https://fixtures.invalid/image.png', 'image/png'))])];
    expectSame(false, ModelRequirements::fromPromptData(CapabilityEnum::textGeneration(), $image, new ModelConfig())->areMetBy($model), 'Input not inferred from output/legacy');
};
runTests($tests);
