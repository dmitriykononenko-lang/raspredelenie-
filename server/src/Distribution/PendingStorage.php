<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Очередь сделок, ожидающих возможного ПЕРЕраспределения (redistribution).
 *
 * Файл: STORAGE_PATH/pending/{accountId}.json — карта lead_id => запись.
 * Запись:
 * {
 *   "lead_id": int, "pipeline_id": int|null, "stage_id": int|null,
 *   "manager_id": int,            // кому сейчас назначено (наш последний выбор)
 *   "managers": [int,...],        // пул правила
 *   "method": "round_robin"|"workload",
 *   "check_schedule": bool,
 *   "attempts": int,              // сколько раз уже перекинули
 *   "max": int,                   // максимум перекидываний (обычно 3)
 *   "after_sec": int,             // таймаут в секундах
 *   "next_at": int                // когда проверять в следующий раз
 * }
 */
class PendingStorage
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/pending';
    }

    /** @return array<string,array> */
    public function all(string $accountId): array
    {
        $f = $this->file($accountId);
        if (!file_exists($f)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    public function put(string $accountId, array $entry): void
    {
        $all = $this->all($accountId);
        $all[(string) $entry['lead_id']] = $entry;
        $this->saveAll($accountId, $all);
    }

    public function remove(string $accountId, int $leadId): void
    {
        $all = $this->all($accountId);
        if (isset($all[(string) $leadId])) {
            unset($all[(string) $leadId]);
            $this->saveAll($accountId, $all);
        }
    }

    public function saveAll(string $accountId, array $all): void
    {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
        file_put_contents(
            $this->file($accountId),
            json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /** @return string[] account ids that have a pending file */
    public function listAccounts(): array
    {
        if (!is_dir($this->basePath)) {
            return [];
        }
        $out = [];
        foreach (glob($this->basePath . '/*.json') ?: [] as $p) {
            $out[] = basename($p, '.json');
        }
        return $out;
    }

    private function file(string $accountId): string
    {
        return $this->basePath . '/' . $accountId . '.json';
    }
}
