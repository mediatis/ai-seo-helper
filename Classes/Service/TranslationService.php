<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translates a single standard field of a translated record from its language
 * parent into the record's own language using the configured AI provider.
 */
class TranslationService
{
    protected AiClient $aiClient;
    protected FieldTranslationEligibility $eligibility;

    /** @var array<string, string> */
    protected array $languages;

    /** @var array<string, mixed> */
    protected array $extConf;

    /**
     * @param array<string, string> $languages
     * @param array<string, mixed> $extConf
     */
    public function __construct(
        AiClient $aiClient,
        FieldTranslationEligibility $eligibility,
        array $languages,
        array $extConf
    ) {
        $this->aiClient = $aiClient;
        $this->eligibility = $eligibility;
        $this->languages = $languages;
        $this->extConf = $extConf;
    }

    /**
     * @param bool|null $isRichtextHint client-derived RTE flag (DOM is authoritative); null falls back to static TCA
     * @return array{output: string, isRichtext: bool}
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function translateField(string $table, int $uid, string $field, ?bool $isRichtextHint = null): array
    {
        if (!$this->eligibility->isEnabled()) {
            throw new \RuntimeException('Field translation is disabled.', 1717000010);
        }

        // Re-validate against live TCA: never translate a column the client was not offered.
        $fieldTca = $GLOBALS['TCA'][$table]['columns'][$field] ?? null;
        if (!is_array($fieldTca) || !$this->eligibility->isEligibleField($table, $field, $fieldTca)) {
            throw new \RuntimeException('Field is not eligible for translation.', 1717000011);
        }

        $record = BackendUtility::getRecord($table, $uid);
        if (!is_array($record)) {
            throw new \RuntimeException('Record not found.', 1717000012);
        }

        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? 'sys_language_uid');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        $targetLanguageId = (int)($record[$languageField] ?? 0);
        $parentUid = $parentField !== '' ? (int)($record[$parentField] ?? 0) : 0;

        if ($targetLanguageId <= 0 || $parentUid <= 0) {
            throw new \RuntimeException('Record is not a connected translation.', 1717000013);
        }

        $parent = BackendUtility::getRecord($table, $parentUid);
        if (!is_array($parent)) {
            throw new \RuntimeException('Language parent record not found.', 1717000014);
        }

        $sourceText = (string)($parent[$field] ?? '');
        if (trim($sourceText) === '') {
            throw new \RuntimeException('The language parent field is empty; nothing to translate.', 1717000015);
        }

        $targetLanguageName = $this->resolveLanguageName($table, $uid, $targetLanguageId);
        // RTE can be enabled per content type via columnsOverrides, so the static base-column
        // TCA is unreliable; trust the client's DOM-derived hint when present.
        $staticRichtext = ($fieldTca['config']['type'] ?? '') === 'text' && !empty($fieldTca['config']['enableRichtext']);
        $isRichtext = $isRichtextHint ?? $staticRichtext;

        return [
            'output' => $this->requestTranslation($sourceText, $targetLanguageName, $isRichtext),
            'isRichtext' => $isRichtext,
        ];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function requestTranslation(string $sourceText, string $targetLanguageName, bool $isRichtext): string
    {
        $promptPrefix = trim((string)($this->extConf['aiPromptPrefixTranslate'] ?? 'Translate the following text to'));
        $formatHint = $isRichtext
            ? ' Preserve all HTML markup and structure exactly and translate only the human-readable text. Return only the translated HTML without code fences or commentary.'
            : ' Return only the translated text, without surrounding quotes or commentary.';

        $messages = [
            [
                'role' => 'user',
                'content' => $promptPrefix . ' ' . $targetLanguageName . '.' . $formatHint . "\n\n" . $sourceText,
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

    protected function resolveLanguageName(string $table, int $uid, int $languageId): string
    {
        $siteLanguage = $this->resolveSiteLanguage($table, $uid, $languageId);
        if (!$siteLanguage instanceof SiteLanguage) {
            return (string)$languageId;
        }

        $typo3Version = new Typo3Version();
        $code = $typo3Version->getMajorVersion() > 11
            ? $siteLanguage->getLocale()->getLanguageCode()
            : $siteLanguage->getTwoLetterIsoCode();

        return $this->languages[$code] ?? $siteLanguage->getTitle();
    }

    protected function resolveSiteLanguage(string $table, int $uid, int $languageId): ?SiteLanguage
    {
        $pid = $this->resolvePageId($table, $uid);
        if ($pid <= 0) {
            return null;
        }
        try {
            $siteMatcher = GeneralUtility::makeInstance(SiteMatcher::class);
            $rootLine = BackendUtility::BEgetRootLine($pid);
            $site = $siteMatcher->matchByPageId($pid, $rootLine);
            return $site->getLanguageById($languageId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function resolvePageId(string $table, int $uid): int
    {
        if ($table === 'pages') {
            return $uid;
        }
        $record = BackendUtility::getRecord($table, $uid, 'pid');
        return (int)($record['pid'] ?? 0);
    }
}
