<?php

namespace SeerrSyncerr\Controllers;

use SeerrSyncerr\Config;

class SettingsController
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function showForm(): void
    {
        $config = $this->config;
        $webhookUrl = $this->buildWebhookUrl();
        $saved = isset($_GET['saved']);

        require __DIR__ . '/../../templates/settings.php';
    }

    public function save(array $formData): void
    {
        $mainLanguages = array_values(array_filter(array_map('trim', $formData['main_languages'] ?? [])));

        $languageKeywords = [];
        $keywordKeys = $formData['language_keyword_key'] ?? [];
        $keywordValues = $formData['language_keyword_value'] ?? [];
        foreach ($keywordKeys as $i => $key) {
            $key = trim((string) $key);
            $value = trim((string) ($keywordValues[$i] ?? ''));
            if ($key !== '' && $value !== '') {
                $languageKeywords[$key] = $value;
            }
        }

        $syncKeywords = array_values(array_filter(array_map('trim', $formData['sync_keywords'] ?? [])));

        $this->config->save([
            'seerr' => [
                'url' => trim((string) ($formData['seerr_url'] ?? '')),
                'api_key' => $this->keepIfBlank((string) ($formData['seerr_api_key'] ?? ''), 'seerr.api_key'),
            ],
            'radarr' => [
                'url' => trim((string) ($formData['radarr_url'] ?? '')),
                'api_key' => $this->keepIfBlank((string) ($formData['radarr_api_key'] ?? ''), 'radarr.api_key'),
            ],
            'sonarr' => [
                'url' => trim((string) ($formData['sonarr_url'] ?? '')),
                'api_key' => $this->keepIfBlank((string) ($formData['sonarr_api_key'] ?? ''), 'sonarr.api_key'),
            ],
            'bazarr' => [
                'url' => trim((string) ($formData['bazarr_url'] ?? '')),
                'api_key' => $this->keepIfBlank((string) ($formData['bazarr_api_key'] ?? ''), 'bazarr.api_key'),
            ],
            'subtitles' => [
                'main_languages' => $mainLanguages,
                'language_keywords' => $languageKeywords,
                'sync_keywords' => $syncKeywords,
            ],
            'translator' => [
                'adapter' => (string) ($formData['translator_adapter'] ?? 'none'),
                'tool_url' => trim((string) ($formData['translator_tool_url'] ?? '')),
                'custom_callable' => isset($formData['translator_custom_callable']),
                'filename_pattern' => trim((string) ($formData['translator_filename_pattern'] ?? '')),
                'source_language' => trim((string) ($formData['translator_source_language'] ?? 'en')),
            ],
        ]);

        header('Location: /?saved=1');
    }

    /**
     * API key fields are masked in the UI (left blank on render) — an
     * empty submission means "leave the stored key unchanged", not "clear it".
     */
    private function keepIfBlank(string $submitted, string $configKey): string
    {
        $submitted = trim($submitted);
        return $submitted !== '' ? $submitted : (string) $this->config->get($configKey, '');
    }

    private function buildWebhookUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:' . (getenv('PORT') ?: '8070');

        return "{$scheme}://{$host}/webhook";
    }
}
