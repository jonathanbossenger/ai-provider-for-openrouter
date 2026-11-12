<?php

declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Synchronous image generation/editing over OpenRouter Chat Completions.
 *
 * Extending the text implementation preserves text/tools/schema on dual models.
 * Image parsing is deliberately separate from the inherited text parser.
 * Only text and text+image prompts and image-only SDK output are implemented;
 * the wire request may also ask a dual model for accompanying text.
 *
 * @since 1.1.0
 */
class OpenRouterImageGenerationModel extends OpenRouterTextGenerationModel implements ImageGenerationModelInterface
{
    /** Supported declared image MIME types, not a claim of decoded image validity. */
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** Documented Chat Completions input image formats. */
    private const INPUT_IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * SDK 0.4.3 requires integer token counts; absent counts use a compatibility placeholder.
     *
     * @since 1.1.0
     */
    public const MISSING_TOKEN_COUNT_PLACEHOLDER = 0;

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $params = $this->prepareGenerateImageParams($prompt);
        $request = $this->createRequest(HttpMethodEnum::POST(), 'chat/completions', [
            'Content-Type' => 'application/json',
        ], $params);
        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        $this->throwIfNotSuccessful($response);
        return $this->parseImageResponseToGenerativeAiResult($response);
    }

    /**
     * Validate every configured field before constructing or sending a request.
     * Metadata options are shared across capabilities, so dual models need this
     * image-specific guard even when the SDK's metadata selection succeeds.
     *
     * @since 1.1.0
     *
     * @param list<Message> $prompt Prompt messages.
     * @return array<string, mixed> Request parameters.
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $config = $this->getConfig();
        foreach ($config->toArray() as $key => $value) {
            if (!in_array($key, ['systemInstruction', 'outputModalities', 'customOptions'], true)) {
                throw new InvalidArgumentException('Unsupported image configuration: ' . $key . '.');
            }
        }
        $output = $config->getOutputModalities();
        if ($output !== null && (!array_is_list($output) || count($output) !== 1 || !$output[0]->isImage())) {
            throw new InvalidArgumentException('The image route supports only image output, not mixed output.');
        }
        $requirementsConfig = ModelConfig::fromArray($config->toArray());
        if ($output !== null) {
            $requirementsConfig->setOutputModalities([ModalityEnum::image()]);
        }
        $requirements = ModelRequirements::fromPromptData(
            CapabilityEnum::imageGeneration(),
            $prompt,
            $requirementsConfig
        );
        if (!$requirements->areMetBy($this->metadataWithCanonicalModalities())) {
            throw new InvalidArgumentException(
                'Selected OpenRouter model does not support the requested image contract.'
            );
        }
        $this->validateImagePrompt($prompt);
        $custom = $this->validateImageCustomOptions($config->getCustomOptions());
        // Use capabilities from the canonical metadata owner, not a second API
        // modality interpretation. Image-only models must send only [image].
        $dual = false;
        foreach ($this->metadata()->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                $dual = true;
                break;
            }
        }
        // Prepend the system ourselves: the SDK drops an explicitly empty string.
        $messages = $this->prepareMessagesParam($prompt);
        if ($config->getSystemInstruction() !== null) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => [['type' => 'text', 'text' => $config->getSystemInstruction()]],
            ]);
        }
        return array_merge([
            'model' => $this->metadata()->getId(),
            'messages' => $messages,
            'modalities' => $dual ? ['text', 'image'] : ['image'],
        ], $custom);
    }

    /**
     * SDK 0.4.3 compares supported modality objects by identity. Serialized
     * metadata detaches them from its enum cache, so rehydrate only the
     * modality sets before passing metadata to ModelRequirements.
     *
     * @since 1.1.0
     *
     * @return ModelMetadata Metadata with canonical modality enum instances.
     */
    private function metadataWithCanonicalModalities(): ModelMetadata
    {
        $metadata = $this->metadata();
        $options = [];
        foreach ($metadata->getSupportedOptions() as $option) {
            $values = $option->getSupportedValues();
            if (
                $values !== null && ($option->getName()->isInputModalities()
                || $option->getName()->isOutputModalities())
            ) {
                $canonicalSets = [];
                foreach ($values as $set) {
                    if (!is_array($set)) {
                        $canonicalSets[] = $set;
                        continue;
                    }
                    $canonicalSet = [];
                    foreach ($set as $modality) {
                        $canonicalSet[] = $modality instanceof ModalityEnum
                            ? ModalityEnum::from($modality->value) : $modality;
                    }
                    $canonicalSets[] = $canonicalSet;
                }
                $values = $canonicalSets;
            }
            $options[] = new SupportedOption($option->getName(), $values);
        }
        return new ModelMetadata(
            $metadata->getId(),
            $metadata->getName(),
            $metadata->getSupportedCapabilities(),
            $options
        );
    }

    /**
     * No skipped thought/tool/media parts and no local URL retrieval.
     * File DTOs are already constructed by the caller; we never construct an
     * input File from an untrusted path or download a remote File here.
     *
     * @since 1.1.0
     *
     * @param list<Message> $prompt Prompt messages.
     */
    private function validateImagePrompt(array $prompt): void
    {
        $hasText = false;
        if (!array_is_list($prompt) || $prompt === []) {
            throw new InvalidArgumentException('The image prompt must be a nonempty message list.');
        }
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                if (!$part->getChannel()->isContent()) {
                    throw new InvalidArgumentException('Unsupported image prompt channel.');
                }
                if ($part->getType()->isText()) {
                    $hasText = $hasText || $part->getText() !== '';
                    continue;
                }
                $file = $part->getFile();
                if ($file === null || !in_array($file->getMimeType(), self::INPUT_IMAGE_MIME_TYPES, true)) {
                    throw new InvalidArgumentException('Unsupported image prompt part or MIME type.');
                }
                if ($file->isRemote()) {
                    $url = $file->getUrl();
                    $parts = $url !== null ? parse_url($url) : false;
                    if (
                        $url === null || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
                        || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                        || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url) === 1
                    ) {
                        throw new InvalidArgumentException(
                            'Unsupported image input URL; expected HTTP(S) without credentials.'
                        );
                    }
                } else {
                    $uri = $file->getDataUri();
                    if ($uri === null) {
                        throw new InvalidArgumentException('Missing inline image input data.');
                    }
                    $this->validateImageDataUri($uri);
                }
            }
        }
        if (!$hasText) {
            throw new InvalidArgumentException('The image prompt requires text, optionally with reference images.');
        }
    }

    /**
     * Bounded allowlist prevents custom/nested options bypassing typed contracts.
     * User identification, simple endpoint routing, and documented aspect ratio
     * are implemented here; all other provider-specific image options are rejected.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $custom Custom options.
     * @return array<string, mixed> Validated options, unchanged.
     */
    private function validateImageCustomOptions(array $custom): array
    {
        foreach ($custom as $key => $value) {
            if ($key === 'user' && is_string($value) && $value !== '') {
                continue;
            }
            if ($key === 'image_config') {
                if (
                    !is_array($value) || array_keys($value) !== ['aspect_ratio']
                    || !is_string($value['aspect_ratio'])
                    || !in_array($value['aspect_ratio'], [
                        'auto', '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3',
                        '4:5', '5:4', '21:9',
                    ], true)
                ) {
                    throw new InvalidArgumentException('Unsupported image_config; expected a documented aspect_ratio.');
                }
                continue;
            }
            if ($key !== 'provider' || !is_array($value) || array_is_list($value)) {
                throw new InvalidArgumentException('Unsupported or conflicting image custom option: ' . $key . '.');
            }
            foreach ($value as $preference => $setting) {
                if (in_array($preference, ['only', 'order', 'ignore'], true) && is_array($setting)) {
                    if (!array_is_list($setting)) {
                        throw new InvalidArgumentException('Invalid image provider routing list.');
                    }
                    foreach ($setting as $slug) {
                        if (!is_string($slug) || $slug === '') {
                            throw new InvalidArgumentException('Invalid image provider routing slug.');
                        }
                    }
                    continue;
                }
                if ($preference === 'allow_fallbacks' && is_bool($setting)) {
                    continue;
                }
                if ($preference === 'sort' && in_array($setting, ['price', 'throughput', 'latency'], true)) {
                    continue;
                }
                throw new InvalidArgumentException('Unsupported image provider preference: ' . $preference . '.');
            }
        }
        return $custom;
    }

    /**
     * Validate declared MIME and canonical, nonempty base64 without file/URL I/O.
     * Reconstructing a canonical data URI before File construction avoids the
     * SDK's fallback to filesystem probes for malformed data URIs/plain base64.
     * MIME casing is canonicalized; bytes are never changed or transcoded.
     *
     * @since 1.1.0
     *
     * @param string $uri Image data URI.
     * @return array{mime: string, base64: string} Validated inline data.
     */
    private function validateImageDataUri(string $uri): array
    {
        if (preg_match('/\Adata:(image\/[a-z0-9.+-]+);base64,([A-Za-z0-9+\/]+={0,2})\z/i', $uri, $matches) !== 1) {
            throw new InvalidArgumentException('Unsupported image data URI form.');
        }
        $mime = strtolower($matches[1]);
        $base64 = $matches[2];
        $bytes = base64_decode($base64, true);
        if (!in_array($mime, self::IMAGE_MIME_TYPES, true) || $bytes === false || $bytes === '') {
            throw new InvalidArgumentException('Unsupported image MIME or invalid base64 data.');
        }
        if (base64_encode($bytes) !== $base64) {
            throw new InvalidArgumentException('Invalid image base64 data; expected canonical padded encoding.');
        }
        return ['mime' => $mime, 'base64' => $base64];
    }

    /**
     * Parse actual Chat Completions choices[].message.images[], not /images data.
     * All errors use ResponseException and never return a successful empty image.
     *
     * @since 1.1.0
     *
     * @param Response $response HTTP response.
     * @return GenerativeAiResult Parsed images and accompanying text.
     */
    protected function parseImageResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $contentType = $response->getHeaderAsString('Content-Type') ?? '';
        if (stripos($contentType, 'text/event-stream') !== false) {
            throw $this->invalidImageResponse('response', 'Streaming image responses are not implemented.');
        }
        $data = $response->getData();
        if (!is_array($data) || isset($data['error'])) {
            throw $this->invalidImageResponse('response', 'Expected a non-streaming image completion object.');
        }
        $choices = $data['choices'] ?? null;
        if (!is_array($choices) || !array_is_list($choices) || $choices === []) {
            throw $this->invalidImageResponse('choices', 'Expected a nonempty choice list.');
        }
        $candidates = [];
        foreach ($choices as $index => $choice) {
            $candidates[] = $this->parseImageChoice($choice, $index);
        }
        $id = array_key_exists('id', $data) ? $data['id'] : '';
        if (!is_string($id) || (array_key_exists('model', $data) && !is_string($data['model']))) {
            throw $this->invalidImageResponse('id/model', 'Expected string identifiers when present.');
        }
        $usage = $data['usage'] ?? null;
        if ($usage !== null && (!is_array($usage) || ($usage !== [] && array_is_list($usage)))) {
            throw $this->invalidImageResponse('usage', 'Expected optional usage object.');
        }
        $counts = [];
        $unknown = [];
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            $value = $usage[$key] ?? null;
            if ($value !== null && (!is_int($value) || $value < 0)) {
                throw $this->invalidImageResponse('usage.' . $key, 'Expected a nonnegative integer or absent count.');
            }
            $counts[] = $value ?? self::MISSING_TOKEN_COUNT_PLACEHOLDER;
            if ($value === null) {
                $unknown[] = $key;
            }
        }
        // The pinned SDK cannot express missing counts or nested usage. Do not
        // infer token counts from nested counters. The zero placeholder is not
        // billable usage: preserve raw usage and unknown field names separately.
        $additional = $data;
        unset($additional['id'], $additional['choices'], $additional['usage']);
        $additional['openrouter_usage'] = $usage;
        $additional['openrouter_unknown_token_counts'] = $unknown;
        return new GenerativeAiResult(
            $id,
            $candidates,
            new TokenUsage($counts[0], $counts[1], $counts[2]),
            $this->providerMetadata(),
            $this->metadata(),
            $additional
        );
    }

    /**
     * Preserve candidate and image order. Remote result URLs have no trustworthy
     * MIME: reject explicitly instead of guessing PNG or retrieving the URL.
     *
     * @since 1.1.0
     *
     * @param mixed $choice Raw choice.
     * @param int $index Choice index.
     * @return Candidate Parsed candidate.
     */
    private function parseImageChoice($choice, int $index): Candidate
    {
        $path = 'choices[' . $index . ']';
        if (!is_array($choice) || isset($choice['delta']) || !isset($choice['message'])) {
            throw $this->invalidImageResponse($path, 'Expected a completed message, not a streaming delta.');
        }
        $message = $choice['message'];
        if (!is_array($message) || (isset($message['role']) && $message['role'] !== 'assistant')) {
            throw $this->invalidImageResponse($path . '.message', 'Expected an assistant message object.');
        }
        // Optional placeholders carry no payload; never treat malformed falsy values as absent.
        foreach (['tool_calls', 'function_call', 'audio', 'video'] as $key) {
            if (array_key_exists($key, $message) && $message[$key] !== null && $message[$key] !== []) {
                throw $this->invalidImageResponse($path . '.message.' . $key, 'Unsupported image response part.');
            }
        }
        $content = $message['content'] ?? null;
        if ($content !== null && !is_string($content)) {
            throw $this->invalidImageResponse($path . '.message.content', 'Expected optional text content.');
        }
        $images = $message['images'] ?? null;
        if (!is_array($images) || !array_is_list($images) || $images === []) {
            throw $this->invalidImageResponse($path . '.message.images', 'Expected at least one image per candidate.');
        }
        $parts = [];
        if ($content !== null && $content !== '') {
            $parts[] = new MessagePart($content);
        }
        foreach ($images as $imageIndex => $image) {
            $imagePath = $path . '.message.images[' . $imageIndex . ']';
            if (
                !is_array($image) || (array_key_exists('type', $image) && $image['type'] !== 'image_url')
                || !isset($image['image_url']) || !is_array($image['image_url'])
                || !isset($image['image_url']['url']) || !is_string($image['image_url']['url'])
            ) {
                throw $this->invalidImageResponse($imagePath, 'Expected an image_url object with a string URL.');
            }
            $uri = $image['image_url']['url'];
            if (preg_match('/\Ahttps?:/i', $uri) === 1) {
                throw $this->invalidImageResponse(
                    $imagePath,
                    'Remote image result MIME is unknown; URL retrieval is disabled.'
                );
            }
            try {
                $inline = $this->validateImageDataUri($uri);
            } catch (InvalidArgumentException $error) {
                throw $this->invalidImageResponse($imagePath, $error->getMessage());
            }
            $file = new File('data:' . $inline['mime'] . ';base64,' . $inline['base64']);
            $parts[] = new MessagePart($file);
        }
        $finish = $choice['finish_reason'] ?? null;
        if ($finish === null || $finish === 'stop') {
            $reason = FinishReasonEnum::stop();
        } elseif ($finish === 'length') {
            $reason = FinishReasonEnum::length();
        } elseif ($finish === 'content_filter') {
            $reason = FinishReasonEnum::contentFilter();
        } else {
            throw $this->invalidImageResponse($path . '.finish_reason', 'Unsupported or missing image finish reason.');
        }
        return new Candidate(new Message(MessageRoleEnum::model(), $parts), $reason);
    }

    /**
     * @since 1.1.0
     *
     * @param string $path Invalid response field (never the untrusted URL/data).
     * @param string $reason Safe explanatory message.
     * @return ResponseException Controlled API response exception.
     */
    private function invalidImageResponse(string $path, string $reason): ResponseException
    {
        return ResponseException::fromInvalidData($this->providerMetadata()->getName(), $path, $reason);
    }
}
