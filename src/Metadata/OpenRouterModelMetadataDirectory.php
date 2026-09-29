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
     * @param array<string, mixed> $model Model data from OpenRouter API.
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
     * @param array<string, mixed> $model Model data from OpenRouter API.
     * @return CapabilityEnum[] List of capabilities.
     */
    protected function determineCapabilities(array $model): array
    {
        $capabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];

        // Only image *output* makes a model an image generator; a model that
        // merely accepts images as input is still text-only.
        $outputModalities = array_map(
            static fn (ModalityEnum $modality): string => $modality->value,
            $this->determineModalities($model, 'output')
        );
        if (in_array(ModalityEnum::image()->value, $outputModalities, true)) {
            $capabilities[] = CapabilityEnum::imageGeneration();
        }

        return $capabilities;
    }

    /**
     * Determines supported options based on OpenRouter model data.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $model Model data from OpenRouter API.
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

        // Without a parameter list, keep advertising these rather than
        // hiding every model from prompts that use them.
        $parameters = isset($model['supported_parameters']) && is_array($model['supported_parameters'])
            ? array_filter($model['supported_parameters'], 'is_string')
            : null;
        $supportsParameter = static function (string ...$names) use ($parameters): bool {
            return null === $parameters || [] !== array_intersect($names, $parameters);
        };

        if ($supportsParameter('tools')) {
            $options[] = new SupportedOption(OptionEnum::functionDeclarations());
        }
        if ($supportsParameter('response_format', 'structured_outputs')) {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']);
            $options[] = new SupportedOption(OptionEnum::outputSchema());
        } else {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), ['text/plain']);
        }

        $options[] = new SupportedOption(
            OptionEnum::inputModalities(),
            $this->textCombinations($this->determineModalities($model, 'input'))
        );
        $options[] = new SupportedOption(
            OptionEnum::outputModalities(),
            $this->textCombinations($this->determineModalities($model, 'output'))
        );

        return $options;
    }

    /**
     * Determines the non-text modalities a model accepts or produces.
     *
     * Reads the `input_modalities` / `output_modalities` lists, falling back
     * to parsing the `modality` string (e.g. `text+image->text`).
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $model Model data from OpenRouter API.
     * @param string               $side  Either 'input' or 'output'.
     * @return ModalityEnum[] Modalities other than text.
     */
    protected function determineModalities(array $model, string $side): array
    {
        $architecture = isset($model['architecture']) && is_array($model['architecture'])
            ? $model['architecture']
            : [];

        $names = $architecture[$side . '_modalities'] ?? null;
        if (!is_array($names)) {
            $modality = isset($architecture['modality']) && is_string($architecture['modality'])
                ? $architecture['modality']
                : 'text->text';
            $parts = explode('->', $modality, 2);
            $names = explode('+', 'input' === $side ? $parts[0] : ($parts[1] ?? 'text'));
        }

        $map = [
            'image' => ModalityEnum::image(),
            'audio' => ModalityEnum::audio(),
            'video' => ModalityEnum::video(),
            'file' => ModalityEnum::document(),
        ];

        $modalities = [];
        foreach ($names as $name) {
            if (is_string($name) && isset($map[$name])) {
                $modalities[$map[$name]->value] = $map[$name];
            }
        }

        return array_values($modalities);
    }

    /**
     * Lists every modality combination that includes text.
     *
     * The AI client matches a prompt's modalities against each supported
     * value as an exact set, so a vision model must list `[text]` as well
     * as `[text, image]` to stay eligible for a text-only prompt.
     *
     * @since n.e.x.t
     *
     * @param ModalityEnum[] $extra Modalities other than text.
     * @return list<list<ModalityEnum>> Supported combinations.
     */
    protected function textCombinations(array $extra): array
    {
        $combinations = [[ModalityEnum::text()]];
        foreach ($extra as $modality) {
            foreach ($combinations as $combination) {
                $combination[] = $modality;
                $combinations[] = $combination;
            }
        }

        return $combinations;
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
