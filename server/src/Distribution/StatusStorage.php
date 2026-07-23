<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Онлайн-статус менеджеров в распределении.
 *
 * Модель «opt-out»: по умолчанию менеджер ВКЛЮЧЁН (online). Если выключить —
 * он не получает сделок, независимо от графика и других настроек (приоритетный
 * статус, как у конкурентов).
 *
 * Раскладка файлов:
 *   STORAGE_PATH/statuses/{accountId}.json          — текущее состояние
 *   STORAGE_PATH/statuses/{accountId}.history.jsonl  — журнал переключений
 *
 * Текущее состояние (JSON):
 * {
 *   "201": {"online": false, "updated_at": 1712345678, "actor_id": 201},
 *   ...
 * }
 */
class StatusStorage
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/statuses';
    }

    /** По умолчанию менеджер онлайн (opt-out). */
    public function isOnline(string $accountId, int $userId): bool
    {
        $all = $this->getAll($accountId);
        return !array_key_exists((string) $userId, $all) || (bool) ($all[(string) $userId]['online'] ?? true);
    }

    /**
     * Все известные статусы аккаунта.
     *
     * @return array<string, array{online: bool, updated_at: int, actor_id: int|null}>
     */
    public function getAll(string $accountId): array
    {
        $file = $this->filePath($accountId);
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Установить статус менеджера. Пишет текущее состояние и добавляет запись
     * в журнал переключений (для «Отчёта по статусам»).
     */
    public function set(string $accountId, int $userId, bool $online, ?int $actorId = null): void
    {
        $all = $this->getAll($accountId);
        $prev = $all[(string) $userId]['online'] ?? null;

        $all[(string) $userId] = [
            'online'     => $online,
            'updated_at' => time(),
            'actor_id'   => $actorId,
        ];
        $this->saveCurrent($accountId, $all);

        // В журнал пишем только реальное изменение.
        if ($prev === null || (bool) $prev !== $online) {
            $this->appendHistory($accountId, [
                'ts'       => time(),
                'user_id'  => $userId,
                'online'   => $online,
                'actor_id' => $actorId,
            ]);
        }
    }

    /**
     * Последние записи журнала переключений (свежие — первыми).
     *
     * @return list<array{ts:int,user_id:int,online:bool,actor_id:int|null}>
     */
    public function history(string $accountId, int $limit = 200): array
    {
        $file = $this->historyPath($accountId);
        if (!file_exists($file)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($file)), 'strlen');
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    // ── internals ──────────────────────────────────────────────────────────────

    private function saveCurrent(string $accountId, array $all): void
    {
        $this->ensureDir();
        file_put_contents(
            $this->filePath($accountId),
            json_encode($all, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function appendHistory(string $accountId, array $entry): void
    {
        $this->ensureDir();
        file_put_contents(
            $this->historyPath($accountId),
            json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    private function filePath(string $accountId): string
    {
        return $this->basePath . '/' . $this->safe($accountId) . '.json';
    }

    private function historyPath(string $accountId): string
    {
        return $this->basePath . '/' . $this->safe($accountId) . '.history.jsonl';
    }

    private function safe(string $accountId): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $accountId) ?: 'unknown';
    }
}
