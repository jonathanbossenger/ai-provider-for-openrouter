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
     * @phpstan-param OpenRouterModelData $model Model data from OpenRouter API.
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
     * @phpstan-param OpenRouterModelData $model Model data from OpenRouter API.
     * @return list<CapabilityEnum> List of capabilities.
     */
    protected function determineCapabilities(array $model): array
    {
        $capabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];

        $modality = $model['architecture']['modality'] ?? 'text->text';

        if (str_contains($modality, 'image')) {
            $capabilities[] = CapabilityEnum::imageGeneration();
        }

        return $capabilities;
    }

    /**
     * Determines supported options based on OpenRouter model data.
     *
     * @since 1.0.0
     *
     * @phpstan-param OpenRouterModelData $model Model data from OpenRouter API.
     * @return list<SupportedOption> List of supported options.
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

        $supportedParameters = $this->getSupportedParameters($model);

        $parameterOptions = [
            'top_k' => OptionEnum::topK(),
            'presence_penalty' => OptionEnum::presencePenalty(),
            'frequency_penalty' => OptionEnum::frequencyPenalty(),
            'logprobs' => OptionEnum::logprobs(),
            'top_logprobs' => OptionEnum::topLogprobs(),
        ];
        foreach ($parameterOptions as $parameter => $option) {
            if ($this->supportsParameter($supportedParameters, $parameter)) {
                $options[] = new SupportedOption($option);
            }
        }

        if (
            $this->supportsParameter($supportedParameters, 'response_format') ||
            $this->supportsParameter($supportedParameters, 'structured_outputs')
        ) {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']);
        }
        if ($this->supportsParameter($supportedParameters, 'structured_outputs')) {
            $options[] = new SupportedOption(OptionEnum::outputSchema());
        }
        if ($this->supportsParameter($supportedParameters, 'tools')) {
            $options[] = new SupportedOption(OptionEnum::functionDeclarations());
        }

        $modality = $model['architecture']['modality'] ?? 'text->text';
        $inputModalities = [ModalityEnum::text()];
        $outputModalities = [ModalityEnum::text()];

        if (str_contains($modality, '+image->') || str_contains($modality, 'image+')) {
            $inputModalities[] = ModalityEnum::image();
        }
        if (str_contains($modality, '->text+image') || str_contains($modality, '->image')) {
            $outputModalities[] = ModalityEnum::image();
        }

        $options[] = new SupportedOption(OptionEnum::inputModalities(), [$inputModalities]);
        $options[] = new SupportedOption(OptionEnum::outputModalities(), [$outputModalities]);

        return $options;
    }

    /**
     * Returns normalized API parameters supported by the OpenRouter model.
     *
     * @since 1.0.0
     *
     * @phpstan-param OpenRouterModelData $model Model data from OpenRouter API.
     * @return list<string> List of supported parameter names.
     */
    private function getSupportedParameters(array $model): array
    {
        $supportedParameters = $model['supported_parameters'] ?? [];
        if (!is_array($supportedParameters)) {
            return [];
        }

        $parameters = [];
        foreach ($supportedParameters as $parameter) {
            if (!is_string($parameter)) {
                continue;
            }

            $parameter = strtolower(trim($parameter));
            if ('' !== $parameter) {
                $parameters[] = $parameter;
            }
        }

        return array_values(array_unique($parameters));
    }

    /**
     * Checks whether a normalized supported parameter list contains a parameter.
     *
     * @since 1.0.0
     *
     * @param string[] $supportedParameters Supported parameter names.
     * @param string   $parameter Parameter name.
     * @return bool Whether the parameter is supported.
     */
    private function supportsParameter(array $supportedParameters, string $parameter): bool
    {
        return in_array($parameter, $supportedParameters, true);
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
