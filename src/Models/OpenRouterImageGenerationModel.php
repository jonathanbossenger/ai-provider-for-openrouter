<?php

declare(strict_types=1);

namespace WordPress\OpenRouterAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for an OpenRouter image generation model with text support.
 *
 * OpenRouter image-capable models can also be used for text generation.
 * This class extends the text generation model and additionally implements
 * image generation support.
 *
 * @since 1.0.0
 */
class OpenRouterImageGenerationModel extends OpenRouterTextGenerationModel implements ImageGenerationModelInterface
{
    /**
     * Generates images from a prompt.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt to generate an image for.
     * @return GenerativeAiResult The generated image result.
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $requestData = [
            'model' => $this->metadata()->getId(),
            'prompt' => $this->prepareImagePrompt($prompt),
        ];

        $candidateCount = $this->getConfig()->getCandidateCount();
        if (null !== $candidateCount) {
            $requestData['n'] = $candidateCount;
        }

        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (isset($requestData[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $requestData[$key] = $value;
        }

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'images',
            ['Content-Type' => 'application/json'],
            $requestData
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);

        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseImageResponseToGenerativeAiResult($response);
    }

    /**
     * Prepares the image prompt parameter.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The prompt messages.
     * @return string The image prompt string.
     */
    protected function prepareImagePrompt(array $messages): string
    {
        if (count($messages) !== 1) {
            throw new InvalidArgumentException(
                'The API requires a single user message as prompt.'
            );
        }

        $message = $messages[0];
        if (!$message->getRole()->isUser()) {
            throw new InvalidArgumentException(
                'The API requires a user message as prompt.'
            );
        }

        foreach ($message->getParts() as $part) {
            $text = $part->getText();
            if (null !== $text) {
                return $text;
            }
        }

        throw new InvalidArgumentException(
            'The API requires a single text message part as prompt.'
        );
    }

    /**
     * Parses an image generation API response to a generative AI result.
     *
     * @since 1.0.0
     *
     * @param Response $response The API response.
     * @return GenerativeAiResult Parsed result.
     * @throws ResponseException If response data is invalid.
     */
    protected function parseImageResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $responseData = $response->getData();
        if (!is_array($responseData) || !isset($responseData['data']) || !is_array($responseData['data'])) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'data');
        }

        $candidates = [];
        foreach ($responseData['data'] as $index => $candidateData) {
            if (!is_array($candidateData)) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "data[{$index}]",
                    'The value must be an associative array.'
                );
            }
            $candidates[] = $this->parseResponseCandidate($candidateData, $index);
        }

        $usage = isset($responseData['usage']) && is_array($responseData['usage'])
            ? $responseData['usage']
            : [];
        $tokenUsage = new TokenUsage(
            isset($usage['input_tokens']) && is_int($usage['input_tokens']) ? $usage['input_tokens'] : 0,
            isset($usage['output_tokens']) && is_int($usage['output_tokens']) ? $usage['output_tokens'] : 0,
            isset($usage['total_tokens']) && is_int($usage['total_tokens']) ? $usage['total_tokens'] : 0
        );

        $resultId = isset($responseData['id']) && is_string($responseData['id'])
            ? $responseData['id']
            : '';

        return new GenerativeAiResult(
            $resultId,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Parses a candidate from the image generation response.
     *
     * @since 1.0.0
     *
     * @param array $candidateData Raw candidate data.
     * @param int   $index Candidate index.
     * @return Candidate Parsed candidate.
     * @throws ResponseException If the candidate data is invalid.
     */
    protected function parseResponseCandidate(array $candidateData, int $index): Candidate
    {
        if (isset($candidateData['b64_json']) && is_string($candidateData['b64_json'])) {
            $image = new File($candidateData['b64_json'], 'image/png');
        } elseif (isset($candidateData['url']) && is_string($candidateData['url'])) {
            $image = new File($candidateData['url'], 'image/png');
        } else {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                "data[{$index}]",
                'The value must contain either a b64_json or url key with a string value.'
            );
        }

        return new Candidate(
            new Message(
                MessageRoleEnum::model(),
                [new MessagePart($image)]
            ),
            FinishReasonEnum::stop()
        );
    }
}
