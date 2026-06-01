<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Single source of truth for "is this a localizable free-text field we may translate".
 *
 * Used both when attaching the translate control (AddAiTranslateControl) and when
 * the AJAX endpoint validates an incoming request (TranslationService) so the client
 * can never trigger translation of an arbitrary column.
 */
class FieldTranslationEligibility
{
    /**
     * eval flags marking a legacy type=input field as non-text (numbers, dates, hashes).
     *
     * @var string[]
     */
    private const NON_TEXT_INPUT_EVAL = ['date', 'datetime', 'time', 'timesec', 'int', 'double2', 'num', 'password', 'md5'];

    /**
     * renderTypes on legacy type=input fields that are not plain text.
     *
     * @var string[]
     */
    private const NON_TEXT_RENDER_TYPES = ['inputLink', 'inputDateTime', 'colorpicker'];

    /** @var array<string, mixed> */
    protected array $extConf;

    /**
     * @param array<string, mixed> $extConf
     */
    public function __construct(array $extConf)
    {
        $this->extConf = $extConf;
    }

    public function isEnabled(): bool
    {
        return (bool)($this->extConf['enableFieldTranslation'] ?? false);
    }

    /**
     * @return string[] lower-cased table names that opted in
     */
    public function enabledTables(): array
    {
        $tables = GeneralUtility::trimExplode(',', (string)($this->extConf['translatableTables'] ?? 'tt_content'), true);
        return array_map('strtolower', $tables);
    }

    public function isTableEnabled(string $table): bool
    {
        return in_array(strtolower($table), $this->enabledTables(), true);
    }

    /**
     * Decide whether a single field of a table is an eligible translation target.
     *
     * @param array<string, mixed> $fieldTca the TCA column definition ([ 'config' => [...], 'l10n_mode' => ... ])
     */
    public function isEligibleField(string $table, string $fieldName, array $fieldTca): bool
    {
        if (!$this->isTableEnabled($table)) {
            return false;
        }

        $denylist = GeneralUtility::trimExplode(',', (string)($this->extConf['translateFieldDenylist'] ?? ''), true);
        if (in_array($fieldName, $denylist, true) || in_array($table . '.' . $fieldName, $denylist, true)) {
            return false;
        }

        // Fields shared from the default language are never translated per language.
        if (($fieldTca['l10n_mode'] ?? '') === 'exclude') {
            return false;
        }

        $config = $fieldTca['config'] ?? [];
        $type = (string)($config['type'] ?? '');

        // textarea / RTE
        if ($type === 'text') {
            return true;
        }

        // single-line text; in v12 links/dates/colors/numbers are their own types and excluded here.
        if ($type === 'input') {
            $renderType = (string)($config['renderType'] ?? '');
            if ($renderType !== '' && in_array($renderType, self::NON_TEXT_RENDER_TYPES, true)) {
                return false;
            }
            $eval = GeneralUtility::trimExplode(',', (string)($config['eval'] ?? ''), true);
            foreach (self::NON_TEXT_INPUT_EVAL as $flag) {
                if (in_array($flag, $eval, true)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}
