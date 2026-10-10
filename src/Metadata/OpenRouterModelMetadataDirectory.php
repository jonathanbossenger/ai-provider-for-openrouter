<?php

declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\OpenRouterAiProvider\Provider\OpenRouterProvider;

/**
 * Class for the OpenRouter model metadata directory.
 *
 * Discovers models available from OpenRouter via the /models endpoint.
 *
 * @since 1.0.0
 *
 * @phpstan-type OpenRouterModelData array{
 *     id: string,
 *     name: string,
 *     description?: string,
 *     context_length?: int,
 *     pricing?: array{
 *         prompt?: string,
 *         completion?: string
 *     },
 *     top_provider?: array{
 *         max_completion_tokens?: int,
 *         is_moderated?: bool
 *     },
 *     architecture?: array{
 *         input_modalities?: list<string>,
 *         output_modalities?: list<string>,
 *         modality?: string,
 *         tokenizer?: string,
 *         instruct_type?: string
 *     },
 *     supported_parameters?: list<string>
 * }
 * @phpstan-type OpenRouterModelsResponseData array{
 *     data: list<OpenRouterModelData>
 * }
 */
class OpenRouterModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function sendListModelsRequest(): array
    {
        $httpTransporter = $this->getHttpTransporter();

        $request = new Request(
            HttpMethodEnum::GET(),
            OpenRouterProvider::url($this->getModelsApiPath()),
            [],
            null
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $response = $httpTransporter->send($request);

        $modelsMetadata = $this->parseResponseToModelMetadataList($response);

        $modelMetadataMap = [];
        foreach ($modelsMetadata as $modelMetadata) {
            $modelMetadataMap[$modelMetadata->getId()] = $modelMetadata;
        }

        return $modelMetadataMap;
    }

    /**
     * Parses the OpenRouter API response to a list of model metadata.
     *
     * @since 1.0.0
     *
     * @param Response $response HTTP response from OpenRouter.
     * @return ModelMetadata[] List of model metadata.
     * @throws ResponseException If response is invalid.
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var OpenRouterModelsResponseData $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['data']) || !is_array($responseData['data'])) {
            throw ResponseException::fromMissingData('OpenRouter', 'data');
        }

        $modelsMetadata = [];
        foreach ($responseData['data'] as $model) {
            $modelMetadata = $this->parseModelToMetadata($model);
            if (null !== $modelMetadata) {
                $modelsMetadata[] = $modelMetadata;
            }
        }

        return $modelsMetadata;
    }

    /**
     * Parses a single model from the OpenRouter API response to ModelMetadata.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $model Model data from OpenRouter API.
     * @return ModelMetadata|null Model metadata or null if model should be skipped.
     */
    protected function parseModelToMetadata(array $model): ?ModelMetadata
    {
        if (!isset($model['id']) || !is_string($model['id']) || $model['id'] === '') {
            return null;
        }

        $modelId = $model['id'];
        $modelName = isset($model['name']) && is_string($model['name']) ? $model['name'] : $modelId;

        $modalities = $this->getModalities($model);
        $capabilities = $this->determineCapabilities($modalities);
        if ($capabilities === []) {
            return null;
        }
        $options = $this->determineSupportedOptions($model, $modalities);

        return new ModelMetadata(
            $modelId,
            $modelName,
            $capabilities,
            $options
        );
    }

    /**
     * One normalized input/output interpretation, preferring explicit arrays.
     * Missing legacy architecture retains the text baseline; malformed explicit
     * metadata invents no modality. Future image routes can reuse this owner.
     *
     * @param array<string, mixed> $model Model data.
     * @return array{input: list<string>, output: list<string>}
     */
    protected function getModalities(array $model): array
    {
        $architecture = $model['architecture'] ?? [];
        if (!is_array($architecture)) {
            return ['input' => [], 'output' => []];
        }
        $legacy = $architecture['modality'] ?? 'text->text';
        $parts = is_string($legacy) ? explode('->', $legacy) : [];
        $modalities = [];
        foreach (['input', 'output'] as $index => $direction) {
            $key = $direction . '_modalities';
            $values = array_key_exists($key, $architecture)
                ? $architecture[$key]
                : (count($parts) === 2 ? explode('+', $parts[$index]) : []);
            $modalities[$direction] = $this->normalizeStrings($values);
        }
        return $modalities;
    }

    /**
     * Only the implemented text route is advertised in this slice.
     *
     * @param array{input: list<string>, output: list<string>} $modalities Modalities.
     * @return list<CapabilityEnum> Supported capabilities.
     */
    protected function determineCapabilities(array $modalities): array
    {
        if (!in_array('text', $modalities['input'], true) || !in_array('text', $modalities['output'], true)) {
            return [];
        }
        return [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()];
    }

    /**
     * Serialized options and exact modality sets for the text route.
     *
     * @param array<string, mixed> $model Model data.
     * @param array{input: list<string>, output: list<string>} $modalities Modalities.
     * @return list<SupportedOption> Supported options.
     */
    protected function determineSupportedOptions(array $model, array $modalities): array
    {
        $supportedOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::customOptions()),
        ];
        $supportedParameters = $this->getSupportedParameters($model);
        $parameterOptions = [
            'presence_penalty' => OptionEnum::presencePenalty(),
            'frequency_penalty' => OptionEnum::frequencyPenalty(),
            'logprobs' => OptionEnum::logprobs(),
            'top_logprobs' => OptionEnum::topLogprobs(),
            'tools' => OptionEnum::functionDeclarations(),
        ];
        foreach ($parameterOptions as $parameter => $option) {
            if (in_array($parameter, $supportedParameters, true)) {
                $supportedOptions[] = new SupportedOption($option);
            }
        }
        $schema = in_array('structured_outputs', $supportedParameters, true);
        if ($schema) {
            $supportedOptions[] = new SupportedOption(OptionEnum::outputSchema());
        }
        $mimeTypes = ['text/plain'];
        if ($schema || in_array('response_format', $supportedParameters, true)) {
            $mimeTypes[] = 'application/json';
        }
        $supportedOptions[] = new SupportedOption(OptionEnum::outputMimeType(), $mimeTypes);

        // SDK matches exact sets. Advertise only the implemented prompt forms.
        $inputSets = [[ModalityEnum::text()]];
        if (in_array('image', $modalities['input'], true)) {
            $inputSets[] = [ModalityEnum::text(), ModalityEnum::image()];
        }
        $supportedOptions[] = new SupportedOption(OptionEnum::inputModalities(), $inputSets);
        $supportedOptions[] = new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]);
        return $supportedOptions;
    }

    /**
     * Returns normalized API parameters supported by the OpenRouter model.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $model Model data from OpenRouter API.
     * @return list<string> List of supported parameter names.
     */
    private function getSupportedParameters(array $model): array
    {
        return $this->normalizeStrings($model['supported_parameters'] ?? []);
    }

    /**
     * @param mixed $values Untrusted API metadata.
     * @return list<string> Normalized, nonempty, distinct string values.
     */
    private function normalizeStrings($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = strtolower(trim($value, " \n\r\t\v\0"));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * Gets the API path for listing models.
     *
     * @since 1.0.0
     *
     * @return string API path.
     */
    protected function getModelsApiPath(): string
    {
        return '/models';
    }
}
