<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Cortecs API client implementation
 *
 * Cortecs (https://cortecs.ai) is a European ("EU-native") LLM gateway that
 * exposes an OpenAI-compatible chat/completions endpoint, routing to a large
 * pool of EU-hosted providers. Like OpenRouter it lets us hit many models with
 * a single key, but with GDPR-friendly routing and optional Zero Data Retention.
 *
 * Differences from {@see OpenRouterClient} that this client papers over:
 *  - Model IDs carry no provider prefix ("claude-haiku-4-5", not
 *    "anthropic/claude-haiku-4.5").
 *  - Zero Data Retention is requested via the top-level
 *    `allow_zero_data_retention` body parameter (mirrors the account setting).
 *  - The OpenRouter-style `reasoning` object is NOT universally accepted:
 *    Claude models are served via AWS Bedrock, which rejects an unknown
 *    `reasoning` key with HTTP 400. Callers must only pass `reasoning` to
 *    models that support it (e.g. the gpt-oss series). This client therefore
 *    does not inject it on its own.
 */
class CortecsClient implements LlmClientInterface
{
    private const API_URL = 'https://api.cortecs.ai/v1/chat/completions';

    private string $apiKey;
    private bool $zeroDataRetention;
    private RequestFactory $requestFactory;

    /** @var array Full conversation history for multi-turn support */
    private array $conversationHistory = [];

    public function __construct(string $apiKey, bool $zeroDataRetention = true)
    {
        $this->apiKey = $apiKey;
        $this->zeroDataRetention = $zeroDataRetention;
        $this->requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
    }

    public function complete(string $prompt, array $tools, array $options = []): LlmResponse
    {
        // Reset conversation history for new conversation
        $this->conversationHistory = [];

        $model = $options['model'] ?? 'claude-haiku-4-5';
        $temperature = $options['temperature'] ?? 0;
        $maxTokens = $options['max_tokens'] ?? 4000;

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a TYPO3 CMS content management assistant with access to MCP tools. '
                    . 'Execute tasks directly using the available tools — do not ask for confirmation or present options. '
                    . 'When asked to create, update, or modify content, do it immediately.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $this->conversationHistory = $messages;

        $requestBody = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $messages,
            'tools' => $this->convertToolsToOpenAIFormat($tools),
        ];

        return $this->sendRequest($this->applyOptions($requestBody, $options));
    }

    public function completeWithHistory(
        string $initialPrompt,
        LlmResponse $previousResponse,
        array $toolResults,
        array $tools,
        array $options = []
    ): LlmResponse {
        $model = $options['model'] ?? 'claude-haiku-4-5';
        $temperature = $options['temperature'] ?? 0;
        $maxTokens = $options['max_tokens'] ?? 4000;

        // Build full conversation from history
        if (empty($this->conversationHistory)) {
            $this->conversationHistory = [
                [
                    'role' => 'user',
                    'content' => $initialPrompt,
                ],
            ];
        }

        // Add the assistant's response (with tool calls)
        $assistantMessage = $this->buildAssistantMessage($previousResponse);
        $this->conversationHistory[] = $assistantMessage;

        // Add tool results
        $rawResponse = $previousResponse->getRawResponse();
        $rawToolCalls = $rawResponse['choices'][0]['message']['tool_calls'] ?? [];

        foreach ($toolResults as $index => $result) {
            $toolCallId = $rawToolCalls[$index]['id'] ?? ('call_' . $index);

            $this->conversationHistory[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => $result['content'] ?? json_encode($result),
            ];
        }

        $requestBody = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $this->conversationHistory,
            'tools' => $this->convertToolsToOpenAIFormat($tools),
        ];

        return $this->sendRequest($this->applyOptions($requestBody, $options));
    }

    /**
     * Apply optional pass-through parameters (reasoning, cache_control) and the
     * Zero Data Retention flag onto the request body.
     */
    private function applyOptions(array $requestBody, array $options): array
    {
        if (isset($options['reasoning'])) {
            $requestBody['reasoning'] = $options['reasoning'];
        }

        if (isset($options['cache_control'])) {
            $requestBody['cache_control'] = $options['cache_control'];
        }

        if ($this->zeroDataRetention) {
            $requestBody['allow_zero_data_retention'] = true;
        }

        return $requestBody;
    }

    /**
     * Send a request to the Cortecs API with retry on transient failures
     */
    private function sendRequest(array $requestBody): LlmResponse
    {
        $maxRetries = 3;
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 2s, 4s, 8s
                sleep((int)pow(2, $attempt));
            }

            try {
                $response = $this->requestFactory->request(
                    self::API_URL,
                    'POST',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->apiKey,
                            'Content-Type' => 'application/json',
                        ],
                        'body' => json_encode($requestBody),
                    ]
                );

                $statusCode = $response->getStatusCode();

                if ($statusCode === 200) {
                    $responseData = json_decode($response->getBody()->getContents(), true);
                    return $this->parseResponse($responseData);
                }

                $errorBody = $response->getBody()->getContents();

                // Retry on server errors (5xx) and rate limits (429)
                if ($statusCode >= 500 || $statusCode === 429) {
                    $lastException = new \RuntimeException(
                        'Cortecs API error: ' . $statusCode . ' - ' . $errorBody
                    );
                    continue;
                }

                // Client errors (4xx except 429) are not retryable
                throw new \RuntimeException(
                    'Cortecs API error: ' . $statusCode . ' - ' . $errorBody .
                    "\n\nRequest body:\n" . json_encode($requestBody, JSON_PRETTY_PRINT)
                );
            } catch (\GuzzleHttp\Exception\ServerException $e) {
                $lastException = new \RuntimeException(
                    'Cortecs API server error: ' . $e->getMessage(),
                    0,
                    $e
                );
                continue;
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // Retry on 429 Too Many Requests (rate limiting)
                if ($e->getResponse() && $e->getResponse()->getStatusCode() === 429) {
                    $lastException = new \RuntimeException(
                        'Cortecs API rate limited: ' . $e->getMessage(),
                        0,
                        $e
                    );
                    continue;
                }
                throw new \RuntimeException(
                    'Cortecs API client error: ' . $e->getMessage(),
                    0,
                    $e
                );
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                $lastException = new \RuntimeException(
                    'Cortecs API connection error: ' . $e->getMessage(),
                    0,
                    $e
                );
                continue;
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'Failed to call Cortecs API: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        throw $lastException ?? new \RuntimeException('Cortecs API request failed after retries');
    }

    /**
     * Convert tools to OpenAI function calling format
     *
     * Tools are already in OpenAI format from getMcpToolsAsLlmFunctions(),
     * so this is essentially a pass-through with validation.
     */
    private function convertToolsToOpenAIFormat(array $tools): array
    {
        $openAITools = [];

        foreach ($tools as $tool) {
            if ($tool['type'] === 'function') {
                $openAITools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['function']['name'],
                        'description' => $tool['function']['description'] ?? '',
                        'parameters' => $tool['function']['parameters'] ?? [
                            'type' => 'object',
                            'properties' => new \stdClass(),
                        ],
                    ],
                ];
            }
        }

        return $openAITools;
    }

    /**
     * Parse OpenAI-format response into LlmResponse
     */
    private function parseResponse(array $responseData): LlmResponse
    {
        $choice = $responseData['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $content = $message['content'] ?? '';
        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $toolCall) {
            if (($toolCall['type'] ?? '') === 'function') {
                $arguments = $toolCall['function']['arguments'] ?? '{}';

                // Parse JSON arguments string
                if (is_string($arguments)) {
                    $arguments = json_decode($arguments, true) ?? [];
                }

                $toolCalls[] = [
                    'name' => $toolCall['function']['name'],
                    'arguments' => $arguments,
                ];
            }
        }

        return new LlmResponse($content, $toolCalls, $responseData);
    }

    /**
     * Build assistant message from previous response for conversation history
     */
    private function buildAssistantMessage(LlmResponse $previousResponse): array
    {
        $rawResponse = $previousResponse->getRawResponse();
        $message = $rawResponse['choices'][0]['message'] ?? [];

        $assistantMessage = [
            'role' => 'assistant',
        ];

        if (!empty($message['content'])) {
            $assistantMessage['content'] = $message['content'];
        }

        if (!empty($message['tool_calls'])) {
            $assistantMessage['tool_calls'] = $message['tool_calls'];
        }

        return $assistantMessage;
    }
}
