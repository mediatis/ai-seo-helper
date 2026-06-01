<?php

namespace Passionweb\AiSeoHelper\Factory;

class SelectedModelFactory
{
    public function checkSelectedModel($extConf): bool
    {
        $provider = $extConf['aiProvider'] ?? 'openai';

        // Non-OpenAI providers handle JSON response format themselves
        if ($provider !== 'openai') {
            return true;
        }

        return  $extConf['aiModel'] === 'gpt-3.5-turbo-1106' ||
                $extConf['aiModel'] === 'gpt-3.5-turbo' ||
                $extConf['aiModel'] === 'gpt-3.5-turbo-0125' ||
                $extConf['aiModel'] === 'gpt-4-1106-preview' ||
                $extConf['aiModel'] === 'gpt-4-turbo-preview' ||
                $extConf['aiModel'] === 'gpt-4-turbo' ||
                $extConf['aiModel'] === 'gpt-4o-mini';
    }
}
