<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\Service\Translation;

use Passionweb\AiSeoHelper\Service\AiClient;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Translation backend using the configured LLM chat provider (OpenAI/OpenRouter).
 *
 * Builds a translation prompt addressed by human-readable language name and
 * delegates the request to the shared AiClient.
 */
class AiTranslator implements TranslatorInterface
{
    protected AiClient $aiClient;

    /** @var array<string, string> */
    protected array $languages;

    /** @var array<string, mixed> */
    protected array $extConf;

    /**
     * @param array<string, string> $languages
     * @param array<string, mixed> $extConf
     */
    public function __construct(AiClient $aiClient, array $languages, array $extConf)
    {
        $this->aiClient = $aiClient;
        $this->languages = $languages;
        $this->extConf = $extConf;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function translate(string $sourceText, SiteLanguage $targetLanguage, ?SiteLanguage $sourceLanguage, bool $isRichtext): string
    {
        $promptPrefix = trim((string)($this->extConf['aiPromptPrefixTranslate'] ?? 'Translate the following text to'));
        $formatHint = $isRichtext
            ? ' Preserve all HTML markup and structure exactly and translate only the human-readable text. Return only the translated HTML without code fences or commentary.'
            : ' Return only the translated text, without surrounding quotes or commentary.';

        $messages = [
            [
                'role' => 'user',
                'content' => $promptPrefix . ' ' . $this->resolveLanguageName($targetLanguage) . '.' . $formatHint . "\n\n" . $sourceText,
            ],
        ];

        // Faithful translation: low temperature, and enough room for long bodytext.
        $translated = $this->aiClient->chat($messages, [
            'temperature' => 0.2,
            'max_tokens' => max((int)$this->extConf['aiMaxTokens'], 1500),
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
        ]);

        return trim($translated);
    }

    protected function resolveLanguageName(SiteLanguage $siteLanguage): string
    {
        $typo3Version = new Typo3Version();
        $code = $typo3Version->getMajorVersion() > 11
            ? $siteLanguage->getLocale()->getLanguageCode()
            : $siteLanguage->getTwoLetterIsoCode();

        return $this->languages[$code] ?? $siteLanguage->getTitle();
    }
}
