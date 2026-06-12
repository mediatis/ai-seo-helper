<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\Service;

use Passionweb\AiSeoHelper\Service\Translation\AiTranslator;
use Passionweb\AiSeoHelper\Service\Translation\DeepLTranslator;
use Passionweb\AiSeoHelper\Service\Translation\TranslatorInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translates a single standard field of a translated record from its language
 * parent into the record's own language using the configured translation
 * backend (AI chat provider or DeepL).
 */
class TranslationService
{
    protected AiTranslator $aiTranslator;
    protected DeepLTranslator $deepLTranslator;
    protected FieldTranslationEligibility $eligibility;

    /** @var array<string, mixed> */
    protected array $extConf;

    /**
     * @param array<string, mixed> $extConf
     */
    public function __construct(
        AiTranslator $aiTranslator,
        DeepLTranslator $deepLTranslator,
        FieldTranslationEligibility $eligibility,
        array $extConf
    ) {
        $this->aiTranslator = $aiTranslator;
        $this->deepLTranslator = $deepLTranslator;
        $this->eligibility = $eligibility;
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

        $site = $this->resolveSite($table, $uid);
        if (!$site instanceof Site) {
            throw new \RuntimeException('Could not determine the site of the record.', 1718000004);
        }
        try {
            $targetLanguage = $site->getLanguageById($targetLanguageId);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Could not resolve the target site language.', 1718000005, $e);
        }
        try {
            $sourceLanguage = $site->getLanguageById(0);
        } catch (\InvalidArgumentException $e) {
            $sourceLanguage = null;
        }

        // RTE can be enabled per content type via columnsOverrides, so the static base-column
        // TCA is unreliable; trust the client's DOM-derived hint when present.
        $staticRichtext = ($fieldTca['config']['type'] ?? '') === 'text' && !empty($fieldTca['config']['enableRichtext']);
        $isRichtext = $isRichtextHint ?? $staticRichtext;

        return [
            'output' => $this->getTranslator()->translate($sourceText, $targetLanguage, $sourceLanguage, $isRichtext),
            'isRichtext' => $isRichtext,
        ];
    }

    protected function getTranslator(): TranslatorInterface
    {
        $provider = (string)($this->extConf['translationProvider'] ?? 'ai');
        return $provider === 'deepl' ? $this->deepLTranslator : $this->aiTranslator;
    }

    protected function resolveSite(string $table, int $uid): ?Site
    {
        $pid = $this->resolvePageId($table, $uid);
        if ($pid <= 0) {
            return null;
        }
        try {
            $siteMatcher = GeneralUtility::makeInstance(SiteMatcher::class);
            $rootLine = BackendUtility::BEgetRootLine($pid);
            $site = $siteMatcher->matchByPageId($pid, $rootLine);
            return $site instanceof Site ? $site : null;
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
