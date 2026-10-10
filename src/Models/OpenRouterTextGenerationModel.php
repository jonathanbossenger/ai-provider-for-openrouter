<?php

declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider;

/**
 * Class for an OpenRouter text generation model.
 *
 * OpenRouter provides an OpenAI-compatible Chat Completions API at /api/v1/,
 * so we use the AbstractOpenAiCompatibleTextGenerationModel base class.
 *
 * @since 1.0.0
 */
class OpenRouterTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * Enforce the selected model's contract even for directly configured models.
     *
     * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt Prompt messages.
     * @return array<string, mixed> Chat Completions parameters.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();
        $schema = $config->getOutputSchema();
        $mime = $config->getOutputMimeType();
        if ($schema !== null && $mime !== null && $mime !== 'application/json') {
            throw new InvalidArgumentException('outputSchema requires application/json, not ' . $mime . '.');
        }
        $requirements = ModelRequirements::fromPromptData(CapabilityEnum::textGeneration(), $prompt, $config);
        if (!$requirements->areMetBy($this->metadata())) {
            throw new InvalidArgumentException(
                'Selected OpenRouter model does not support the requested text contract.'
            );
        }
        $custom = $config->getCustomOptions();
        // Do not let custom options bypass typed selection or replace required
        // messages/tools/response format, or switch the implemented text route.
        foreach (['model', 'models', 'messages', 'tools', 'response_format', 'modalities', 'stream'] as $key) {
            if (array_key_exists($key, $custom)) {
                throw new InvalidArgumentException('Custom option conflicts with the text contract: ' . $key . '.');
            }
        }
        $provider = $custom['provider'] ?? [];
        if ($schema !== null) {
            if (
                !is_array($provider) || ($provider !== [] && array_is_list($provider))
                || (array_key_exists('provider', $custom) && $custom['provider'] === null)
            ) {
                throw new InvalidArgumentException('Schema routing provider preferences must be an object.');
            }
            if (array_key_exists('require_parameters', $provider) && $provider['require_parameters'] !== true) {
                throw new InvalidArgumentException('Schema routing requires provider.require_parameters=true.');
            }
            $provider['require_parameters'] = true;
        }
        $params = parent::prepareGenerateTextParams($prompt);
        if ($schema !== null) {
            // The SDK normally supplies JSON MIME for schema configs. Handle a
            // genuinely unset MIME without dropping or downgrading the schema.
            if (!isset($params['response_format'])) {
                $params['response_format'] = $this->prepareResponseFormatParam($schema);
            }
            $params['provider'] = $provider;
        }
        return $params;
    }

    /**
     * OpenRouter expects the OpenAI name/schema/strict envelope, not a raw schema.
     * Accept the SDK's envelope form and as_json_response's raw schema form.
     *
     * @param array<string, mixed>|null $schema Raw JSON schema or SDK envelope.
     * @return array<string, mixed> Response format.
     */
    protected function prepareResponseFormatParam(?array $schema): array
    {
        if ($schema === null) {
            return parent::prepareResponseFormatParam(null);
        }
        if (array_key_exists('schema', $schema) || array_key_exists('name', $schema)) {
            $envelope = $schema;
        } else {
            $envelope = ['name' => 'response', 'schema' => $schema];
        }
        $name = $envelope['name'] ?? null;
        if (
            !is_string($name) || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $name) !== 1
            || !isset($envelope['schema']) || !is_array($envelope['schema'])
            || ($envelope['schema'] !== [] && array_is_list($envelope['schema']))
            || (array_key_exists('strict', $envelope) && !is_bool($envelope['strict']))
        ) {
            throw new InvalidArgumentException('Invalid json_schema name/schema/strict envelope.');
        }
        if ($envelope['schema'] === []) {
            $envelope['schema'] = new \stdClass();
        }
        $envelope['strict'] = $envelope['strict'] ?? true;
        return ['type' => 'json_schema', 'json_schema' => $envelope];
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            OpenRouterProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }
}
