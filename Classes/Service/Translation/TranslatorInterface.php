<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\Service\Translation;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Translates a single field value into the given target language.
 *
 * Implementations encapsulate one translation backend (LLM chat, DeepL, ...)
 * including all provider-specific request building and response parsing.
 */
interface TranslatorInterface
{
    /**
     * @param string $sourceText text of the language parent field
     * @param SiteLanguage $targetLanguage language of the translated record
     * @param SiteLanguage|null $sourceLanguage language of the parent record (null lets the provider auto-detect)
     * @param bool $isRichtext whether the field contains RTE HTML that must be preserved
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function translate(string $sourceText, SiteLanguage $targetLanguage, ?SiteLanguage $sourceLanguage, bool $isRichtext): string;
}
