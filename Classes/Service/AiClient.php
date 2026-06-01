<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\Service;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Thin client around the OpenAI / OpenRouter chat completion endpoint.
 *
 * Owns endpoint resolution, authentication and response normalisation so that
 * both the SEO suggestion flow (ContentService) and the field translation flow
 * (TranslationService) share one request implementation.
 */
class AiClient
{
    protected RequestFactory $requestFactory;

    /** @var array<string, mixed> */
    protected array $extConf;

    /**
     * @param array<string, mixed> $extConf
     */
    public function __construct(RequestFactory $requestFactory, array $extConf)
    {
        $this->requestFactory = $requestFactory;
        $this->extConf = $extConf;
    }

    public function getApiEndpoint(): string
    {
        $provider = $this->extConf['aiProvider'] ?? 'openai';
        if ($provider === 'openrouter') {
            return 'https://openrouter.ai/api/v1/chat/completions';
        }
        return 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * Perform a chat completion and return the raw assistant text.
     *
     * Markdown code fences (```json / ```html) that some providers add around
     * the payload are stripped so callers receive bare content.
     *
     * @param array<int, array<string, mixed>> $messages chat messages ([['role' => ..., 'content' => ...], ...])
     * @param array<string, mixed> $overrides request parameter overrides (e.g. temperature, max_tokens, response_format)
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function chat(array $messages, array $overrides = []): string
    {
        $payload = array_merge(
            [
                'model' => $this->extConf['openAiModel'],
                'temperature' => (float)$this->extConf['openAiTemperature'],
                'max_tokens' => (int)$this->extConf['openAiMaxTokens'],
                'top_p' => (float)$this->extConf['openAiTopP'],
                'frequency_penalty' => (float)$this->extConf['openAiFrequencyPenalty'],
                'presence_penalty' => (float)$this->extConf['openAiPresencePenalty'],
                'messages' => $messages,
            ],
            $overrides
        );

        $response = $this->requestFactory->request(
            $this->getApiEndpoint(),
            'POST',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->extConf['openAiApiKey'],
                ],
                'json' => $payload,
            ]
        );

        $rawBody = $response->getBody()->getContents();
        $decoded = json_decode($rawBody, true);

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if ($content === null) {
            throw new \RuntimeException('AI request failed: ' . substr((string)$rawBody, 0, 500), 1717000001);
        }

        return (string)preg_replace('/^\s*```(?:json|html)?\s*|\s*```\s*$/i', '', trim($content));
    }
}
