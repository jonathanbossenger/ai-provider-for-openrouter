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
 *         modality?: string,
 *         tokenizer?: string,
 *         instruct_type?: string
 *     }
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
     * @param array $model Model data from OpenRouter API.
     * @return ModelMetadata|null Model metadata or null if model should be skipped.
     */
    protected function parseModelToMetadata(array $model): ?ModelMetadata
    {
        if (!isset($model['id']) || empty($model['id'])) {
            return null;
        }

        $modelId = $model['id'];
        $modelName = $model['name'] ?? $modelId;

        $capabilities = $this->determineCapabilities($model);
        $options = $this->determineSupportedOptions($model);

        return new ModelMetadata(
            $modelId,
            $modelName,
            $capabilities,
            $options
        );
    }

    /**
     * Determines model capabilities based on OpenRouter model data.
     *
     * @since 1.0.0
     *
     * @param array $model Model data from OpenRouter API.
     * @return CapabilityEnum[] List of capabilities.
     */
    protected function determineCapabilities(array $model): array
    {
        $modality = $model['architecture']['modality'] ?? 'text->text';
        [, $outputModality] = $this->splitModality($modality);

        $capabilities = [];

        if (str_contains($outputModality, 'text')) {
            $capabilities[] = CapabilityEnum::textGeneration();
            $capabilities[] = CapabilityEnum::chatHistory();
        }

        if (str_contains($outputModality, 'image')) {
            $capabilities[] = CapabilityEnum::imageGeneration();
        }

        if (empty($capabilities)) {
            $capabilities[] = CapabilityEnum::textGeneration();
            $capabilities[] = CapabilityEnum::chatHistory();
        }

        return $capabilities;
    }

    /**
     * Determines supported options based on OpenRouter model data.
     *
     * @since 1.0.0
     *
     * @param array $model Model data from OpenRouter API.
     * @return SupportedOption[] List of supported options.
     */
    protected function determineSupportedOptions(array $model): array
    {
        $options = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        $modality = $model['architecture']['modality'] ?? 'text->text';
        [$inputModality, $outputModality] = $this->splitModality($modality);
        $inputModalities = [];
        $outputModalities = [];

        if (str_contains($inputModality, 'text')) {
            $inputModalities[] = ModalityEnum::text();
        }
        if (str_contains($inputModality, 'image')) {
            $inputModalities[] = ModalityEnum::image();
        }
        if (str_contains($outputModality, 'text')) {
            $outputModalities[] = ModalityEnum::text();
        }
        if (str_contains($outputModality, 'image')) {
            $outputModalities[] = ModalityEnum::image();
        }
        if (empty($inputModalities)) {
            $inputModalities[] = ModalityEnum::text();
        }
        if (empty($outputModalities)) {
            $outputModalities[] = ModalityEnum::text();
        }

        $options[] = new SupportedOption(OptionEnum::inputModalities(), [$inputModalities]);
        $options[] = new SupportedOption(OptionEnum::outputModalities(), [$outputModalities]);

        return $options;
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

    /**
     * Splits a model modality string into input and output modalities.
     *
     * @since 1.0.0
     *
     * @param string $modality Modality string from model architecture.
     * @return array{0: string, 1: string} Input and output modalities.
     */
    protected function splitModality(string $modality): array
    {
        $parts = explode('->', $modality, 2);

        if (count($parts) !== 2) {
            return ['text', 'text'];
        }

        $inputModality = $parts[0] === '' ? 'text' : $parts[0];
        $outputModality = $parts[1] === '' ? 'text' : $parts[1];

        return [$inputModality, $outputModality];
    }
}
