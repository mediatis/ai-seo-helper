<?php

declare(strict_types=1);

namespace Passionweb\AiSeoHelper\Service\Translation;

use GuzzleHttp\Exception\BadResponseException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Translation backend using the DeepL API (https://developers.deepl.com).
 *
 * Supports DeepL translation memories: when a memory id is configured, DeepL
 * applies stored segment matches above the configured threshold (requires a
 * Pro key and forces the quality-optimized model). Rich text is translated
 * with DeepL HTML tag handling so markup survives verbatim.
 */
class DeepLTranslator implements TranslatorInterface
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function translate(string $sourceText, SiteLanguage $targetLanguage, ?SiteLanguage $sourceLanguage, bool $isRichtext): string
    {
        $apiKey = trim((string)($this->extConf['deeplApiKey'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException(
                'DeepL is selected as translation provider but no DeepL API key is configured.',
                1718000001
            );
        }

        $payload = [
            'text' => [$sourceText],
            'target_lang' => $this->mapTargetLanguage($targetLanguage),
            'preserve_formatting' => true,
        ];

        $sourceLang = $sourceLanguage !== null ? $this->mapSourceLanguage($sourceLanguage) : null;
        if ($sourceLang !== null) {
            $payload['source_lang'] = $sourceLang;
        }
        if ($isRichtext) {
            $payload['tag_handling'] = 'html';
        }

        $formality = trim((string)($this->extConf['deeplFormality'] ?? ''));
        if ($formality !== '' && $formality !== 'default') {
            $payload['formality'] = $formality;
        }

        $memoryId = trim((string)($this->extConf['deeplTranslationMemoryId'] ?? ''));
        if ($memoryId !== '') {
            // Translation memories are only applied by DeepL's quality-optimized (next-gen) models.
            $payload['model_type'] = 'quality_optimized';
            $payload['translation_memory_id'] = $memoryId;
            $payload['translation_memory_threshold'] = min(100, max(0, (int)($this->extConf['deeplTranslationMemoryThreshold'] ?? 75)));
        } else {
            $payload['model_type'] = 'prefer_quality_optimized';
        }

        try {
            $response = $this->requestFactory->request(
                $this->getApiEndpoint($apiKey),
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
                    ],
                    'json' => $payload,
                ]
            );
        } catch (BadResponseException $e) {
            throw $this->toReadableException($e);
        }

        $rawBody = $response->getBody()->getContents();
        $decoded = json_decode($rawBody, true);
        $translated = $decoded['translations'][0]['text'] ?? null;
        if (!is_string($translated)) {
            throw new \RuntimeException('DeepL request failed: ' . substr($rawBody, 0, 500), 1718000002);
        }

        return trim($translated);
    }

    public function getApiEndpoint(string $apiKey): string
    {
        // DeepL API Free keys are marked with the ":fx" suffix.
        $host = str_ends_with($apiKey, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        return $host . '/v2/translate';
    }

    protected function toReadableException(BadResponseException $e): \RuntimeException
    {
        $statusCode = $e->getResponse()->getStatusCode();
        $body = substr((string)$e->getResponse()->getBody(), 0, 500);
        switch ($statusCode) {
            case 403:
                $message = 'DeepL rejected the API key (403). Check the configured DeepL API key.';
                break;
            case 456:
                $message = 'The DeepL translation quota of this billing period is exhausted (456).';
                break;
            default:
                $message = 'DeepL request failed with status ' . $statusCode . ': ' . $body;
        }
        return new \RuntimeException($message, 1718000003, $e);
    }

    /**
     * Maps a site language to a DeepL target_lang code. DeepL expects regional
     * variants for EN/PT and script variants for ZH; all other languages use
     * the plain uppercase ISO 639-1 code.
     */
    protected function mapTargetLanguage(SiteLanguage $siteLanguage): string
    {
        [$language, $region] = $this->extractLanguageAndRegion($siteLanguage);
        switch ($language) {
            case 'en':
                return $region === 'GB' ? 'EN-GB' : 'EN-US';
            case 'pt':
                return $region === 'BR' ? 'PT-BR' : 'PT-PT';
            case 'zh':
                return in_array($region, ['TW', 'HK', 'MO'], true) ? 'ZH-HANT' : 'ZH-HANS';
            default:
                return strtoupper($language);
        }
    }

    /**
     * DeepL source_lang codes are always variant-less (EN, not EN-US).
     */
    protected function mapSourceLanguage(SiteLanguage $siteLanguage): ?string
    {
        [$language] = $this->extractLanguageAndRegion($siteLanguage);
        return $language !== '' ? strtoupper($language) : null;
    }

    /**
     * @return array{0: string, 1: string} lowercase language code, uppercase region code (may be empty)
     */
    protected function extractLanguageAndRegion(SiteLanguage $siteLanguage): array
    {
        $typo3Version = new Typo3Version();
        if ($typo3Version->getMajorVersion() > 11) {
            $locale = $siteLanguage->getLocale();
            return [strtolower($locale->getLanguageCode()), strtoupper((string)$locale->getCountryCode())];
        }
        $region = '';
        if (preg_match('/^[A-Za-z]{2,3}[-_]([A-Za-z]{2})/', (string)$siteLanguage->getLocale(), $matches) === 1) {
            $region = strtoupper($matches[1]);
        }
        return [strtolower($siteLanguage->getTwoLetterIsoCode()), $region];
    }
}
