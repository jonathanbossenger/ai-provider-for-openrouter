<?php

declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
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
     * Guard advanced contracts for direct models while retaining the SDK's
     * ordinary Chat Completions options and supported file serialization.
     *
     * @since 1.1.0
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
        if ($schema !== null && !$this->supportsOption(OptionEnum::outputSchema())) {
            throw new InvalidArgumentException('Selected OpenRouter model does not support output schema.');
        }
        if ($mime === 'application/json' && !$this->supportsJsonOutput()) {
            throw new InvalidArgumentException('Selected OpenRouter model does not support JSON output.');
        }
        if ($config->getFunctionDeclarations() !== null && !$this->supportsOption(OptionEnum::functionDeclarations())) {
            throw new InvalidArgumentException('Selected OpenRouter model does not support tools.');
        }
        if ($config->getTopK() !== null) {
            // SDK 0.4.3 has no Chat Completions serializer for this option.
            throw new InvalidArgumentException('Selected OpenRouter text route does not support topK.');
        }
        $outputModalities = $config->getOutputModalities();
        if ($outputModalities !== null) {
            foreach ($outputModalities as $modality) {
                if (!$modality->isText()) {
                    throw new InvalidArgumentException(
                        'Selected OpenRouter text route does not support non-text output modality.'
                    );
                }
            }
        }
        $custom = $config->getCustomOptions();
        // These keys can replace the selected model or the serialized prompt.
        foreach (['model', 'models', 'messages'] as $key) {
            if (array_key_exists($key, $custom)) {
                throw new InvalidArgumentException('Custom option conflicts with the text contract: ' . $key . '.');
            }
        }
        if (array_key_exists('tools', $custom)) {
            if (
                $config->getFunctionDeclarations() !== null
                || !$this->supportsOption(OptionEnum::functionDeclarations())
            ) {
                throw new InvalidArgumentException('Custom option conflicts with the text contract: tools.');
            }
            if (!is_array($custom['tools']) || !array_is_list($custom['tools'])) {
                throw new InvalidArgumentException('Custom option tools must be a list of functions.');
            }
            foreach ($custom['tools'] as $tool) {
                if (
                    !is_array($tool) || ($tool['type'] ?? null) !== 'function'
                    || !isset($tool['function']) || !is_array($tool['function'])
                ) {
                    throw new InvalidArgumentException('Custom option tools must be a list of functions.');
                }
            }
        }
        if (array_key_exists('response_format', $custom)) {
            if (
                $schema !== null || $mime === 'application/json' || !$this->supportsJsonOutput()
                || !is_array($custom['response_format']) || $custom['response_format'] !== ['type' => 'json_object']
            ) {
                throw new InvalidArgumentException('Custom option conflicts with the text contract: response_format.');
            }
        }
        if (array_key_exists('modalities', $custom) && $custom['modalities'] !== ['text']) {
            throw new InvalidArgumentException('Custom option conflicts with the text contract: modalities.');
        }
        if (array_key_exists('stream', $custom) && $custom['stream'] !== false) {
            throw new InvalidArgumentException('Custom option conflicts with the text contract: stream.');
        }
        $provider = array_key_exists('provider', $custom) ? $custom['provider'] : [];
        if ($schema !== null) {
            if (
                !is_array($provider) || ($provider !== [] && array_is_list($provider))
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
     * @since 1.1.0
     *
     * @param OptionEnum $option Advanced option to look up.
     * @return bool Whether model metadata advertises the option.
     */
    private function supportsOption(OptionEnum $option): bool
    {
        foreach ($this->metadata()->getSupportedOptions() as $supported) {
            if ($supported->getName()->value === $option->value) {
                return true;
            }
        }
        return false;
    }

    /**
     * @since 1.1.0
     *
     * @return bool Whether JSON output is advertised.
     */
    private function supportsJsonOutput(): bool
    {
        foreach ($this->metadata()->getSupportedOptions() as $supported) {
            if ($supported->getName()->value === OptionEnum::outputMimeType()->value) {
                return $supported->isSupportedValue('application/json');
            }
        }
        return false;
    }

    /**
     * OpenRouter expects the OpenAI name/schema/strict envelope, not a raw schema.
     * Accept the SDK's envelope form and as_json_response's raw schema form.
     *
     * @since 1.1.0
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
