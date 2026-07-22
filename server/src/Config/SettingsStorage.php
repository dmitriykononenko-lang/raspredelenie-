<?php

declare(strict_types=1);

namespace DealDist\Config;

/**
 * Persists per-account widget settings (distribution rules, method, etc.) to disk.
 *
 * File: STORAGE_PATH/settings/{accountId}.json
 */
class SettingsStorage
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/settings';
    }

    public function save(string $accountId, array $settings): void
    {
        $dir = $this->basePath;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->basePath . '/' . $accountId . '.json',
            json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public function load(string $accountId): array
    {
        $file = $this->basePath . '/' . $accountId . '.json';
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
