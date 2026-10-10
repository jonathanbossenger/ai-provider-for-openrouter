<?php

/** Offline image contracts against the pinned real SDK. No source URL is resolved. */
declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider\Tests;

use RuntimeException;
use WordPress\AiClient\Builders\PromptBuilder;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WordPress\AiClient\Tools\DTO\WebSearch;
use WordPress\OpenRouterAiProvider\Models\OpenRouterImageGenerationModel;
use WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider;

require __DIR__ . '/harness.php';

function imageFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
}

class ImageFixtureTransport implements HttpTransporterInterface
{
    public array $requests = [];
    public array $responses = [];

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        $this->requests[] = $request;
        if ($request->getUri() === OpenRouterProvider::url('/models')) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode(imageFixture('image-models')));
        }
        expectSame(OpenRouterProvider::url('/chat/completions'), $request->getUri(), 'No source fetch or other route');
        return array_shift($this->responses) ?? imageResponse(imageFixture('image-response'));
    }

    public function generations(): array
    {
        return array_values(array_filter($this->requests, static function (Request $request): bool {
            return $request->getUri() !== OpenRouterProvider::url('/models');
        }));
    }
}

function imageResponse(array $data): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
}

function imageBuilder(ImageFixtureTransport $transport, string $id = 'fixture/dual', $input = 'draw a tree'): PromptBuilder
{
    $registry = new ProviderRegistry();
    $registry->setHttpTransporter($transport);
    $registry->registerProvider(OpenRouterProvider::class);
    $registry->setProviderRequestAuthentication('openrouter', new ApiKeyRequestAuthentication('offline-not-a-key'));
    return (new PromptBuilder($registry, $input))->usingProvider('openrouter')->usingModelPreference($id);
}

function imageMetadata(string $id = 'fixture/dual')
{
    foreach (imageFixture('image-models')['data'] as $model) {
        if ($model['id'] === $id) {
            return (new MetadataDirectoryForTest())->parseFixture($model);
        }
    }
    throw new RuntimeException('Unknown image fixture');
}

function directImage(ImageFixtureTransport $transport, ModelConfig $config): OpenRouterImageGenerationModel
{
    $model = new OpenRouterImageGenerationModel(imageMetadata(), OpenRouterProvider::metadata());
    $model->setConfig($config);
    $model->setHttpTransporter($transport);
    $model->setRequestAuthentication(new ApiKeyRequestAuthentication('offline-not-a-key'));
    return $model;
}

function imageReject(ModelConfig $config, ?array $messages = null): void
{
    $transport = new ImageFixtureTransport();
    $model = directImage($transport, $config);
    expectThrows(static function () use ($model, $messages): void {
        $model->generateImageResult($messages ?? prompt());
    }, 'image');
    expectSame([], $transport->requests, 'Rejected locally before any HTTP');
}

function imageResponseReject(Response $response): void
{
    $transport = new ImageFixtureTransport();
    $transport->responses[] = $response;
    try {
        imageBuilder($transport)->generateImageResult();
    } catch (ResponseException $error) {
        expectSame(1, count($transport->generations()), 'One generation, no result URL fetch');
        return;
    }
    throw new RuntimeException('Expected controlled ResponseException');
}

// Trap SDK/File stream retrieval as well as enforcing the mock transport's route allowlist.
class OptionalImageUrlTrap
{
    public $context;
    public static array $attempts = [];

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        self::$attempts[] = $path;
        throw new RuntimeException('Unexpected image URL download');
    }

    public function url_stat($path, $flags)
    {
        self::$attempts[] = $path;
        throw new RuntimeException('Unexpected image URL stat');
    }
}

function withoutOptionalImageDownloads(callable $action): void
{
    OptionalImageUrlTrap::$attempts = [];
    foreach (['http', 'https'] as $scheme) {
        stream_wrapper_unregister($scheme);
        stream_wrapper_register($scheme, OptionalImageUrlTrap::class);
    }
    try {
        $action();
    } finally {
        foreach (['http', 'https'] as $scheme) {
            stream_wrapper_restore($scheme);
        }
        expectSame([], OptionalImageUrlTrap::$attempts, 'No URL opens/stats');
    }
}

function optionalImageResponse(): array
{
    $data = imageFixture('image-response');
    // Real 1x1 PNG, but the surrounding response remains synthetic/offline.
    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';
    $data['choices'][0]['message']['content'] = null;
    $data['choices'][0]['message']['images'][0]['image_url']['url'] = $png;
    $data['choices'][1]['message']['content'] = 'Second candidate.';
    $data['choices'][1]['message']['images'][0]['image_url']['url'] = $png;
    return $data;
}

$tests = [
    'normal SDK image builder discovers dual and image-only models with exact route payload' => static function (): void {
        foreach (['fixture/dual' => ['text', 'image'], 'fixture/image-only' => ['image']] as $id => $modalities) {
            $transport = new ImageFixtureTransport();
            $result = imageBuilder($transport, $id)->usingSystemInstruction('paint carefully')->generateImageResult();
            expectSame($id, $result->getModelMetadata()->getId(), 'Normal selection, not manual requirements');
            expectSame('image-result-fixture', $result->getId(), 'Result ID');
            $request = $transport->generations()[0];
            expectSame('POST', $request->getMethod()->value, 'POST');
            $data = $request->getData();
            expectSame($id, $data['model'], 'Model');
            expectSame($modalities, $data['modalities'], 'Wire modalities follow metadata');
            expectSame([
                ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'paint carefully']]],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'draw a tree']]],
            ], $data['messages'], 'System and complete prompt');
            expectSame(['model', 'messages', 'modalities'], array_keys($data), 'No tools/schema/options leakage');
            expectSame(2, count($result->getCandidates()), 'Multiple candidates');
            $parts = $result->getCandidates()[0]->getMessage()->getParts();
            expectSame('Here are images.', $parts[0]->getText(), 'Optional text retained');
            expectSame('image/png', $parts[1]->getFile()->getMimeType(), 'PNG MIME');
            expectSame('image/svg+xml', $parts[2]->getFile()->getMimeType(), 'SVG MIME, not PNG lie');
            expectSame('image/webp', $result->getCandidates()[1]->getMessage()->getParts()[0]->getFile()->getMimeType(), 'Next candidate WebP');
            expectSame([10, 20, 30], [$result->getTokenUsage()->getPromptTokens(), $result->getTokenUsage()->getCompletionTokens(), $result->getTokenUsage()->getTotalTokens()], 'Usage names');
            expectSame('fixture/reported-model', $result->getAdditionalData()['model'], 'Reported model retained separately');
        }
    },
    'edit builder preserves text and multiple remote/inline input images without downloading' => static function (): void {
        $transport = new ImageFixtureTransport();
        $uri = imageFixture('image-response')['choices'][0]['message']['images'][0]['image_url']['url'];
        $message = new Message(MessageRoleEnum::user(), [new MessagePart('edit both'),
            new MessagePart(new File('https://fixtures.invalid/input.jpg?x=1', 'image/jpeg')),
            new MessagePart(new File($uri)), new MessagePart('keep the background')]);
        imageBuilder($transport, 'fixture/dual', [$message])->generateImageResult();
        $content = $transport->generations()[0]->getData()['messages'][0]['content'];
        expectSame(['text', 'image_url', 'image_url', 'text'], array_column($content, 'type'), 'Full order retained');
        expectSame('https://fixtures.invalid/input.jpg?x=1', $content[1]['image_url']['url'], 'URL unchanged, not fetched');
        expectSame($uri, $content[2]['image_url']['url'], 'Inline image unchanged');
        expectSame('keep the background', $content[3]['text'], 'Last text not lost');
        expectSame(1, count($transport->generations()), 'No source HTTP');
    },
    'dual class keeps text tools schema and system contract with no image modalities' => static function (): void {
        $transport = new ImageFixtureTransport();
        $transport->responses[] = imageResponse(imageFixture('text-response'));
        $declaration = new FunctionDeclaration('lookup', 'Lookup', ['type' => 'object']);
        $result = imageBuilder($transport)->usingSystemInstruction('system')->usingFunctionDeclarations($declaration)
            ->asJsonResponse(['type' => 'object'])->generateTextResult();
        expectSame('{"answer":"hello"}', $result->toText(), 'Inherited text parser');
        $data = $transport->generations()[0]->getData();
        expectSame(false, isset($data['modalities']), 'Text does not request images');
        expectSame('lookup', $data['tools'][0]['function']['name'], 'Tools');
        expectSame('json_schema', $data['response_format']['type'], 'Schema');
        expectSame(true, $data['provider']['require_parameters'], 'Endpoint guard');
    },
    'metadata exact output sets and directional inputs select only implemented contracts' => static function (): void {
        $config = new ModelConfig();
        $config->setOutputModalities([ModalityEnum::image()]);
        foreach (['fixture/dual', 'fixture/image-only', 'fixture/text-input-image'] as $id) {
            $metadata = imageMetadata($id);
            expectSame(true, ModelRequirements::fromPromptData(CapabilityEnum::imageGeneration(), prompt(), $config)->areMetBy($metadata), 'Image selected');
            expectSame($id === 'fixture/dual', isSelected($metadata, new ModelConfig()), 'Only dual supports text');
            $config->setOutputModalities([ModalityEnum::text(), ModalityEnum::image()]);
            expectSame(false, ModelRequirements::fromPromptData(CapabilityEnum::imageGeneration(), prompt(), $config)->areMetBy($metadata), 'No mixed advertised output');
            $config->setOutputModalities([ModalityEnum::image()]);
        }
        foreach (['fixture/text', 'fixture/missing'] as $id) {
            expectSame(false, ModelRequirements::fromPromptData(CapabilityEnum::imageGeneration(), prompt(), $config)->areMetBy(imageMetadata($id)), 'Text/missing not image');
        }
        $directory = new MetadataDirectoryForTest();
        foreach ([['modality' => 'broken'], ['input_modalities' => ['text'], 'output_modalities' => 'image'], ['input_modalities' => 'text', 'output_modalities' => ['image']], ['modality' => 'image->image']] as $architecture) {
            expectSame(null, $directory->parseOptionalFixture(['id' => 'malformed', 'architecture' => $architecture]), 'No invented supported prompt');
        }
        $legacy = metadata(['architecture' => ['modality' => 'text+image->text+image']]);
        expectSame(true, ModelRequirements::fromPromptData(CapabilityEnum::imageGeneration(), prompt(), $config)->areMetBy($legacy), 'Legacy image selection');
    },
    'optional and partial usage uses explicit unknown sentinel not fabricated zeros; nested usage survives' => static function (): void {
        foreach ([null, [], ['prompt_tokens' => 0], ['completion_tokens_details' => ['image_tokens' => 12], 'cost' => 0.2], ['prompt_tokens' => 3, 'completion_tokens' => 4]] as $usage) {
            $response = imageFixture('image-response');
            unset($response['usage']);
            if ($usage !== null) { $response['usage'] = $usage; }
            $transport = new ImageFixtureTransport();
            $transport->responses[] = imageResponse($response);
            $result = imageBuilder($transport)->generateImageResult();
            $tokens = $result->getTokenUsage();
            expectSame([$usage['prompt_tokens'] ?? -1, $usage['completion_tokens'] ?? -1, $usage['total_tokens'] ?? -1], [$tokens->getPromptTokens(), $tokens->getCompletionTokens(), $tokens->getTotalTokens()], 'Unknown is -1, not zero or inferred sum');
            expectSame($usage, $result->getAdditionalData()['openrouter_usage'], 'Raw optional/nested usage');
        }
    },
    'allowlisted routing/user custom options and empty system instruction preserved' => static function (): void {
        $transport = new ImageFixtureTransport();
        $config = new ModelConfig();
        $config->setSystemInstruction('');
        $custom = ['user' => 'offline-user', 'provider' => ['only' => ['FixtureEndpoint'], 'allow_fallbacks' => false]];
        $config->setCustomOptions($custom);
        imageBuilder($transport)->usingModelConfig($config)->generateImageResult();
        $data = $transport->generations()[0]->getData();
        expectSame('', $data['messages'][0]['content'][0]['text'], 'Empty system not silently dropped');
        expectSame($custom['provider'], $data['provider'], 'Routing retained');
        expectSame('offline-user', $data['user'], 'User retained');
    },
];

$optionalFields = ['tool_calls', 'function_call', 'audio', 'video'];
$placeholders = ['absent' => []];
foreach ($optionalFields as $key) {
    $placeholders[$key . '=null'] = [$key => null];
    $placeholders[$key . '=[]'] = [$key => []];
}
foreach (['fixture/dual' => ['text', 'image'], 'fixture/image-only' => ['image']] as $id => $modalities) {
    foreach ($placeholders as $label => $fields) {
        $tests['normal SDK optional image fields ' . $id . ' ' . $label] = static function () use ($id, $modalities, $fields): void {
            withoutOptionalImageDownloads(static function () use ($id, $modalities, $fields): void {
                $data = optionalImageResponse();
                foreach ($data['choices'] as &$choice) {
                    $choice['message'] = array_merge($choice['message'], $fields);
                }
                unset($choice);
                $transport = new ImageFixtureTransport();
                $transport->responses[] = imageResponse($data);
                $result = imageBuilder($transport, $id)->generateImageResult();
                expectSame($id, $result->getModelMetadata()->getId(), 'Normal SDK model selection');
                expectSame($data['id'], $result->getId(), 'Result ID unchanged');
                expectSame(1, count($transport->generations()), 'Exactly one image request, no tool followup');
                $request = $transport->generations()[0];
                expectSame(OpenRouterProvider::url('/chat/completions'), $request->getUri(), 'Image route');
                expectSame('POST', $request->getMethod()->value, 'POST');
                $wire = json_decode($request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                expectSame([
                    'model' => $id,
                    'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'draw a tree']]]],
                    'modalities' => $modalities,
                ], $wire, 'Actual encoded payload; no tools/schema leakage');
                expectSame(2, count($result->getCandidates()), 'Candidate count retained');
                foreach ($result->getCandidates() as $index => $candidate) {
                    expectSame('stop', $candidate->getFinishReason()->value, 'Finish reason retained');
                    $parts = $candidate->getMessage()->getParts();
                    expectSame(2, count($parts), 'Image-only first candidate, text then image second');
                    if ($index === 1) {
                        expectSame('text', $parts[0]->getType()->value, 'Text precedes image');
                        expectSame('Second candidate.', $parts[0]->getText(), 'Optional text retained');
                        array_shift($parts);
                    }
                    foreach ($parts as $imageIndex => $part) {
                        expectSame('file', $part->getType()->value, 'Only files, no function execution payload');
                        $uri = $data['choices'][$index]['message']['images'][$imageIndex]['image_url']['url'];
                        expectSame($uri, $part->getFile()->getDataUri(), 'Image bytes and order retained');
                        expectSame($index === 0 && $imageIndex === 1 ? 'image/svg+xml' : 'image/png', $part->getFile()->getMimeType(), 'Declared MIME retained');
                    }
                }
                expectSame([10, 20, 30], [$result->getTokenUsage()->getPromptTokens(), $result->getTokenUsage()->getCompletionTokens(), $result->getTokenUsage()->getTotalTokens()], 'Usage unchanged');
                expectSame($data['usage'], $result->getAdditionalData()['openrouter_usage'], 'Raw nested usage unchanged');
                expectSame([], $result->getAdditionalData()['openrouter_unknown_token_counts'], 'No invented unknowns');
                expectSame($data['model'], $result->getAdditionalData()['model'], 'Reported model unchanged');
            });
        };
    }
}
$unsupportedPayloads = [
    'tool_calls' => [['id' => 'call', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']]],
    'function_call' => ['name' => 'lookup', 'arguments' => '{}'],
    'audio' => ['data' => 'YWJj', 'format' => 'mp3'],
    'video' => ['url' => 'https://fixtures.invalid/movie.mp4'],
];
foreach ($optionalFields as $key) {
    foreach ([0, false, '', 'unsupported', [null], $unsupportedPayloads[$key]] as $variant => $value) {
        $tests['optional image field strict rejection ' . $key . ' ' . $variant] = static function () use ($key, $value): void {
            withoutOptionalImageDownloads(static function () use ($key, $value): void {
                foreach ([0, 1] as $index) {
                    $data = optionalImageResponse();
                    $data['choices'][$index]['message'][$key] = $value;
                    $transport = new ImageFixtureTransport();
                    $transport->responses[] = imageResponse($data);
                    try {
                        imageBuilder($transport)->generateImageResult();
                    } catch (ResponseException $error) {
                        expectSame('Unexpected OpenRouter API response: Invalid "choices[' . $index . '].message.' . $key . '" key: Unsupported image response part.', $error->getMessage(), 'Exact controlled field rejection, not another parser failure');
                        expectSame(1, count($transport->generations()), 'One request, no tool or media followup');
                        continue;
                    }
                    throw new RuntimeException('Expected controlled ResponseException for actual/malformed payload');
                }
            });
        };
    }
}

foreach (['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml', 'IMAGE/PNG'] as $mime) {
    $tests['supported result MIME preserved ' . $mime] = static function () use ($mime): void {
        $data = imageFixture('image-response');
        $data['choices'] = [$data['choices'][0]];
        $data['choices'][0]['message']['content'] = null;
        $data['choices'][0]['message']['images'] = [['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,aW1hZ2UtZml4dHVyZQ==']]];
        $transport = new ImageFixtureTransport();
        $transport->responses[] = imageResponse($data);
        $file = imageBuilder($transport)->generateImageResult()->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
        expectSame(strtolower($mime), $file->getMimeType(), 'MIME canonical case');
        expectSame('aW1hZ2UtZml4dHVyZQ==', $file->getBase64Data(), 'Bytes unchanged');
    };
}

foreach ([
    'candidateCount' => 1, 'maxTokens' => 1, 'temperature' => 0.5, 'topP' => 0.9, 'topK' => 1,
    'stopSequences' => ['stop'], 'presencePenalty' => 0.0, 'frequencyPenalty' => 0.0,
    'logprobs' => false, 'topLogprobs' => 1, 'functionDeclarations' => [], 'webSearch' => new WebSearch(),
    'outputFileType' => FileTypeEnum::inline(), 'outputMimeType' => 'image/png', 'outputSchema' => [],
    'outputMediaOrientation' => MediaOrientationEnum::landscape(), 'outputMediaAspectRatio' => '1:1',
    'outputSpeechVoice' => 'voice',
] as $key => $value) {
    $tests['unsupported SDK image config rejected locally ' . $key] = static function () use ($key, $value): void {
        $config = new ModelConfig();
        $setter = 'set' . ucfirst($key);
        $config->$setter($value);
        imageReject($config);
    };
}
foreach (['model', 'models', 'messages', 'prompt', 'modalities', 'stream', 'tools', 'tool_choice', 'response_format', 'image_config', 'n', 'size', 'quality', 'aspect_ratio', 'resolution', 'output_format', 'input_references', 'audio', 'video', 'max_tokens', 'seed', 'plugins', 'unknown'] as $key) {
    $tests['custom image contract/config bypass rejected ' . $key] = static function () use ($key): void {
        $config = new ModelConfig();
        $config->setCustomOptions([$key => null]);
        imageReject($config);
    };
}
foreach ([['options' => ['fixture' => ['size' => '2K']]], ['require_parameters' => true], ['only' => 'bad'], ['sort' => ['by' => 'price']], ['allow_fallbacks' => 'false'], null] as $index => $provider) {
    $tests['nested custom provider bypass/invalid rejected ' . $index] = static function () use ($provider): void {
        $config = new ModelConfig();
        $config->setCustomOptions(['provider' => $provider]);
        imageReject($config);
    };
}
foreach (['application/pdf', 'audio/wav', 'video/mp4', 'image/tiff', 'image/svg+xml'] as $mime) {
    $tests['unsupported image input MIME rejected ' . $mime] = static function () use ($mime): void {
        imageReject(new ModelConfig(), [new Message(MessageRoleEnum::user(), [new MessagePart('edit'), new MessagePart(new File('https://fixtures.invalid/media', $mime))])]);
    };
}
foreach (['ftp://fixtures.invalid/image.png', 'https://user:pass@fixtures.invalid/image.png', 'https://fixtures.invalid/image.png#fragment'] as $url) {
    $tests['unsafe input URL rejected without fetch ' . $url] = static function () use ($url): void {
        // The SDK rejects FTP at construction. Override only the DTO URL accessor
        // to exercise the provider's own guard without filesystem/URL resolution.
        $file = new class($url) extends File {
            private string $fixtureUrl;
            public function __construct(string $url)
            {
                parent::__construct('https://fixtures.invalid/input.png', 'image/png');
                $this->fixtureUrl = $url;
            }
            public function getUrl(): ?string { return $this->fixtureUrl; }
        };
        imageReject(new ModelConfig(), [new Message(MessageRoleEnum::user(), [new MessagePart('edit'), new MessagePart($file)])]);
    };
}
$tests['thought parts and function inputs rejected instead of silently dropped'] = static function (): void {
    foreach ([new MessagePart('secret', MessagePartChannelEnum::thought()), new MessagePart(new FunctionCall('call', 'fn', [])), new MessagePart(new FunctionResponse('call', 'fn', []))] as $part) {
        $role = $part->getType()->isFunctionCall() ? MessageRoleEnum::model() : MessageRoleEnum::user();
        imageReject(new ModelConfig(), [new Message($role, [new MessagePart('edit'), $part])]);
    }
    imageReject(new ModelConfig(), []);
    imageReject(new ModelConfig(), [new Message(MessageRoleEnum::user(), [new MessagePart(new File('https://fixtures.invalid/image.png', 'image/png'))])]);
};
foreach (['data:image/png;base64,', 'data:image/png;base64,%%%=', 'data:image/png;base64,YQ=', 'data:image/png;base64,YR==', 'data:image/png;base64,YQ', 'data:image/png;base64,YQ==\n', 'data:image/png;charset=utf-8;base64,YQ==', 'data:image/tiff;base64,YQ==', 'data:text/plain;base64,YQ==', 'data:image/png,YQ==', 'https://fixtures.invalid/result.png', 'file:///not-read.png'] as $uri) {
    $tests['malformed or unsupported result URI controlled error ' . $uri] = static function () use ($uri): void {
        $data = imageFixture('image-response');
        $data['choices'][0]['message']['images'][0]['image_url']['url'] = $uri;
        imageResponseReject(imageResponse($data));
    };
}
foreach ([null, [], 'bad', [['message' => []]], [['delta' => ['images' => []]]], [['message' => ['images' => []], 'finish_reason' => 'stop']], [['message' => ['content' => 'text only'], 'finish_reason' => 'stop']]] as $index => $choices) {
    $tests['no empty or streaming image success ' . $index] = static function () use ($choices): void {
        $data = imageFixture('image-response');
        $data['choices'] = $choices;
        imageResponseReject(imageResponse($data));
    };
}
foreach (['images-object', 'image-string', 'missing-url', 'wrong-type', 'content-array', 'tool-calls', 'audio', 'bad-finish', 'bad-usage', 'bad-id', 'bad-model'] as $kind) {
    $tests['malformed response controlled error ' . $kind] = static function () use ($kind): void {
        $data = imageFixture('image-response');
        $message =& $data['choices'][0]['message'];
        if ($kind === 'images-object') { $message['images'] = ['x' => $message['images'][0]]; }
        elseif ($kind === 'image-string') { $message['images'][0] = 'bad'; }
        elseif ($kind === 'missing-url') { unset($message['images'][0]['image_url']['url']); }
        elseif ($kind === 'wrong-type') { $message['images'][0]['type'] = 'audio'; }
        elseif ($kind === 'content-array') { $message['content'] = [['type' => 'text', 'text' => 'not implemented']]; }
        elseif ($kind === 'tool-calls') { $message['tool_calls'] = [['id' => 'unexpected']]; }
        elseif ($kind === 'audio') { $message['audio'] = ['data' => 'unexpected']; }
        elseif ($kind === 'bad-finish') { $data['choices'][0]['finish_reason'] = 'tool_calls'; }
        elseif ($kind === 'bad-usage') { $data['usage']['prompt_tokens'] = '10'; }
        elseif ($kind === 'bad-id') { $data['id'] = 5; }
        else { $data['model'] = []; }
        imageResponseReject(imageResponse($data));
    };
}
$tests['invalid JSON and SSE fail with controlled response errors'] = static function (): void {
    imageResponseReject(new Response(200, ['Content-Type' => 'application/json'], '{invalid'));
    imageResponseReject(new Response(200, ['Content-Type' => 'text/event-stream'], 'data: [DONE]'));
};
$tests['documented generated image_url object does not require a type discriminator'] = static function (): void {
    $data = imageFixture('image-response');
    foreach ($data['choices'] as &$choice) {
        foreach ($choice['message']['images'] as &$image) {
            unset($image['type']);
        }
        unset($image);
    }
    unset($choice);
    $transport = new ImageFixtureTransport();
    $transport->responses[] = imageResponse($data);
    expectSame(2, count(imageBuilder($transport)->generateImageResult()->getCandidates()), 'Official schema shape');
};
$tests['malformed inline input data rejected before transport'] = static function (): void {
    foreach (['', 'YQ', 'YR==', '%%%'] as $base64) {
        // File accepts unpadded/noncanonical data; %%% needs a safe accessor override.
        // Never pass malformed paths/URIs to File's filesystem fallback.
        $file = new class($base64) extends File {
            private string $fixtureBase64;
            public function __construct(string $base64)
            {
                parent::__construct('data:image/png;base64,YQ==');
                $this->fixtureBase64 = $base64;
            }
            public function getDataUri(): ?string { return 'data:image/png;base64,' . $this->fixtureBase64; }
        };
        imageReject(new ModelConfig(), [new Message(MessageRoleEnum::user(), [new MessagePart('edit'), new MessagePart($file)])]);
    }
};
$tests['all unimplemented modality/streaming requirements rejected with image-only options bounded'] = static function (): void {
    $metadata = imageMetadata('fixture/image-only');
    $names = array_map(static function ($option): string { return $option->getName()->value; }, $metadata->getSupportedOptions());
    foreach (['maxTokens', 'temperature', 'functionDeclarations', 'outputMimeType', 'outputFileType', 'candidateCount', 'outputMediaAspectRatio'] as $option) {
        expectSame(false, in_array($option, $names, true), 'No text or unimplemented image option');
    }
    foreach ([[ModalityEnum::text(), ModalityEnum::image()], [ModalityEnum::audio()], [ModalityEnum::video()]] as $modalities) {
        $config = new ModelConfig();
        $config->setOutputModalities($modalities);
        imageReject($config);
    }
    expectSame([CapabilityEnum::imageGeneration(), CapabilityEnum::chatHistory()], $metadata->getSupportedCapabilities(), 'Only implemented synchronous capabilities; pinned SDK has no streaming capability');
    $config = new ModelConfig();
    $config->setOutputModalities([ModalityEnum::image()]);
    $withImage = [new Message(MessageRoleEnum::user(), [new MessagePart('edit'), new MessagePart(new File('https://fixtures.invalid/i.png', 'image/png'))])];
    expectSame(false, ModelRequirements::fromPromptData(CapabilityEnum::imageGeneration(), $withImage, $config)->areMetBy(imageMetadata('fixture/text-input-image')), 'Input/output directions separate');
};
$tests['malformed usage counts and identifiers fail instead of coercion'] = static function (): void {
    foreach ([-1, 0.5, '10'] as $count) {
        $data = imageFixture('image-response');
        $data['usage']['total_tokens'] = $count;
        imageResponseReject(imageResponse($data));
    }
    $data = imageFixture('image-response');
    $data['id'] = null;
    imageResponseReject(imageResponse($data));
};
$tests['empty custom provider routing array rejected rather than wrong JSON shape'] = static function (): void {
    $config = new ModelConfig();
    $config->setCustomOptions(['provider' => []]);
    imageReject($config);
};
runTests($tests);
