<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\DataProvider;

use Passionweb\AiSeoHelper\Service\FieldTranslationEligibility;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

/**
 * Attaches the "aiTranslate" field control to every eligible text field of a
 * record at form-render time.
 *
 * Running as a FormDataProvider (rather than static TCA manipulation) means we
 * see the fully built processedTca regardless of extension load order, and we
 * have the concrete record so the buttons only appear on connected translations
 * (sys_language_uid > 0 with a language parent) and never on default-language
 * originals.
 */
class AddAiTranslateControl implements FormDataProviderInterface
{
    protected FieldTranslationEligibility $eligibility;

    public function __construct(FieldTranslationEligibility $eligibility)
    {
        $this->eligibility = $eligibility;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function addData(array $result): array
    {
        if (!$this->eligibility->isEnabled()) {
            return $result;
        }

        $table = (string)($result['tableName'] ?? '');
        if (!$this->eligibility->isTableEnabled($table)) {
            return $result;
        }

        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? '');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        if ($languageField === '' || $parentField === '') {
            return $result;
        }

        $row = $result['databaseRow'] ?? [];
        $languageId = (int)$this->scalar($row[$languageField] ?? 0);
        $parentUid = (int)$this->scalar($row[$parentField] ?? 0);
        if ($languageId <= 0 || $parentUid <= 0) {
            return $result;
        }

        foreach (($result['processedTca']['columns'] ?? []) as $fieldName => $fieldTca) {
            if (!is_array($fieldTca) || !$this->eligibility->isEligibleField($table, (string)$fieldName, $fieldTca)) {
                continue;
            }
            $result['processedTca']['columns'][$fieldName]['config']['fieldControl']['aiTranslate'] = [
                'renderType' => 'aiTranslate',
            ];
        }

        return $result;
    }

    /**
     * databaseRow values for relational fields can be arrays; reduce to a scalar.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function scalar($value)
    {
        if (is_array($value)) {
            return $value[0] ?? 0;
        }
        return $value;
    }
}
