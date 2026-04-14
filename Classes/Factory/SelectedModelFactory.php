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

        return  $extConf['openAiModel'] === 'gpt-3.5-turbo-1106' ||
                $extConf['openAiModel'] === 'gpt-3.5-turbo' ||
                $extConf['openAiModel'] === 'gpt-3.5-turbo-0125' ||
                $extConf['openAiModel'] === 'gpt-4-1106-preview' ||
                $extConf['openAiModel'] === 'gpt-4-turbo-preview' ||
                $extConf['openAiModel'] === 'gpt-4-turbo' ||
                $extConf['openAiModel'] === 'gpt-4o-mini';
    }
}
