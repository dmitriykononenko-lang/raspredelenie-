<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Persists the round-robin queue pointer and per-rule state to the filesystem.
 *
 * File layout:
 *   STORAGE_PATH/queues/{accountId}/{ruleHash}.json
 *
 * JSON structure:
 * {
 *   "managers": [101, 202, 303],
 *   "next_index": 1,
 *   "updated_at": 1712345678
 * }
 */
class QueueStorage
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/queues';
    }

    public function getNextManager(string $accountId, string $ruleHash, array $managerIds): int
    {
        $state = $this->load($accountId, $ruleHash);

        // Re-sync manager list if it changed
        if ($state === null || $state['managers'] !== $managerIds) {
            $nextIndex = 0;
            // If previous list was a subset, preserve pointer position
            if ($state !== null) {
                $nextIndex = $state['next_index'] % count($managerIds);
            }
            $state = ['managers' => $managerIds, 'next_index' => $nextIndex];
        }

        $chosenId  = $managerIds[$state['next_index']];
        $state['next_index'] = ($state['next_index'] + 1) % count($managerIds);
        $state['updated_at'] = time();

        $this->save($accountId, $ruleHash, $state);

        return (int) $chosenId;
    }

    private function load(string $accountId, string $ruleHash): ?array
    {
        $file = $this->filePath($accountId, $ruleHash);
        if (!file_exists($file)) {
            return null;
        }
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function save(string $accountId, string $ruleHash, array $state): void
    {
        $dir = $this->basePath . '/' . $accountId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->filePath($accountId, $ruleHash),
            json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public function resetQueue(string $accountId, string $ruleHash): void
    {
        $file = $this->filePath($accountId, $ruleHash);
        if (!file_exists($file)) {
            return;
        }

        $state = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $state['next_index'] = 0;
        $state['updated_at'] = time();
        file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Return all queue states for an account.
     *
     * @return array<string, array>  [ruleHash => state]
     */
    public function listQueues(string $accountId): array
    {
        $dir = $this->basePath . '/' . $accountId;
        if (!is_dir($dir)) {
            return [];
        }

        $result = [];
        foreach (glob($dir . '/*.json') as $file) {
            $hash   = basename($file, '.json');
            $data   = json_decode(file_get_contents($file), true);
            if ($data !== null) {
                $result[$hash] = $data;
            }
        }
        return $result;
    }

    private function filePath(string $accountId, string $ruleHash): string
    {
        return $this->basePath . '/' . $accountId . '/' . $ruleHash . '.json';
    }
}
