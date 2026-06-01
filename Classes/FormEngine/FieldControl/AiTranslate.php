<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\FormEngine\FieldControl;

use Passionweb\AiSeoHelper\Service\JavaScriptModuleService;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Generic field control that triggers AI translation of the current field from
 * its language parent. One node serves every wired field; the concrete table,
 * field and record uid are carried as data attributes for the JS module.
 */
class AiTranslate extends AbstractNode
{
    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $table = (string)($this->data['tableName'] ?? '');
        $field = (string)($this->data['fieldName'] ?? '');
        $uid = (int)($this->data['databaseRow']['uid'] ?? 0);

        $resultArray = [
            'iconIdentifier' => 'actions-localize',
            'title' => LocalizationUtility::translate('LLL:EXT:ai_seo_helper/Resources/Private/Language/backend.xlf:AiSeoHelper.translate.button'),
            'linkAttributes' => [
                'class' => 'ai-seo-helper-translate-btn',
                'data-table' => $table,
                'data-uid' => (string)$uid,
                'data-field-name' => $field,
            ],
        ];

        $javaScriptModuleService = GeneralUtility::makeInstance(JavaScriptModuleService::class);

        return array_merge($resultArray, $javaScriptModuleService->addModules());
    }
}
