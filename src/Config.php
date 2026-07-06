<?php

namespace SeerrSyncerr;

/**
 * Loads and persists /config/config.json — the single source of truth for
 * every setting the web UI exposes (SPEC.md §4). PUID/PGID/TZ/PORT stay as
 * env vars since they're container-level concerns decided before the app
 * even starts; everything else lives here.
 */
class Config
{
    private string $path;
    private array $data;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->data = $this->load();
    }

    private function defaults(): array
    {
        return [
            'seerr' => [
                'url' => '',
                'api_key' => '',
            ],
            'radarr' => [
                'url' => '',
                'api_key' => '',
            ],
            'sonarr' => [
                'url' => '',
                'api_key' => '',
            ],
            'bazarr' => [
                'url' => '',
                'api_key' => '',
            ],
            'subtitles' => [
                'main_languages' => [],
                'language_keywords' => [],
                'sync_keywords' => [],
            ],
            'translator' => [
                // none | bazarr_ai_translate | bazarr_auto_translate | ai_subtitle_translator | custom
                'adapter' => 'none',
                // Shared by the two adapters that actually need a URL
                // (ai_subtitle_translator's own server, or a Custom tool) —
                // SPEC.md §4 describes this as one "conditional field" shown
                // per selection, not a separate field per adapter.
                'tool_url' => '',
                'custom_callable' => false,
                'filename_pattern' => '',
                // Language the external tool translates FROM (SPEC.md §8
                // remediation step 2 needs to know which subtitle is "the
                // source" to re-force) — not called out as its own field in
                // SPEC.md §4's table, added here since §8 requires it and
                // everything else the app needs lives in this same UI.
                'source_language' => 'en',
            ],
            'webhook' => [
                'secret' => bin2hex(random_bytes(24)),
            ],
        ];
    }

    private function load(): array
    {
        if (!file_exists($this->path)) {
            $data = $this->defaults();
            $this->persist($data);
            return $data;
        }

        $raw = file_get_contents($this->path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return $this->mergeDefaults($decoded);
    }

    private function mergeDefaults(array $data): array
    {
        return array_replace_recursive($this->defaults(), $data);
    }

    /**
     * Dot-notation read, e.g. get('seerr.url').
     */
    public function get(string $dotKey, $default = null)
    {
        $segments = explode('.', $dotKey);
        $value = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Merges the given data onto the current config (preserving anything not
     * present, e.g. the webhook secret) and persists it to disk.
     */
    public function save(array $data): void
    {
        $this->data = array_replace_recursive($this->data, $data);
        $this->persist($this->data);
    }

    private function persist(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
