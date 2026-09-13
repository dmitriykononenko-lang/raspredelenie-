<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Журнал операций по сделке (P0-2, идемпотентность без окна времени).
 *
 * amoCRM повторяет вебхук при сбое/медленном ответе — иногда спустя минуты и
 * часы. Окно LeadGuard (120 с) такие повторы не ловит. Здесь каждое событие
 * получает ключ (этап + updated_at из тела вебхука, либо источник+время) и
 * записывается в STORAGE_PATH/operations/{account}/{lead}.json:
 *
 *   { "<key>": {"ts": 1712345678, "status": "done|failed|skipped", "manager_id": 3, "source": "webhook"} , ... }
 *
 * Повтор с тем же ключом → skipped_duplicate без единого запроса к amoCRM.
 * Файл на сделку маленький; чистится по возрасту записей (KEEP_SEC).
 */
class OperationJournal
{
    private const KEEP_SEC = 30 * 86400;
    private string $base;

    public function __construct()
    {
        $this->base = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/operations';
    }

    /** Ключ события из тела вебхука: этап + updated_at (одинаков у всех ретраев amo). */
    public static function keyFromWebhookLead(array $lead, string $event): ?string
    {
        $stage = (string) ($lead['status_id'] ?? '');
        $ts    = (string) ($lead['updated_at'] ?? $lead['last_modified'] ?? $lead['created_at'] ?? '');
        if ($ts === '') {
            return null; // нет метки — работаем по окну LeadGuard как раньше
        }
        return $event . ':' . $stage . ':' . $ts;
    }

    public function seen(string $accountId, int $leadId, string $key): ?array
    {
        $map = $this->read($accountId, $leadId);
        return $map[$key] ?? null;
    }

    /** Последнее наше успешное назначение по сделке (для политики ручной смены). */
    public function lastAssigned(string $accountId, int $leadId): ?int
    {
        $best = null; $bestTs = 0;
        foreach ($this->read($accountId, $leadId) as $op) {
            if (($op['status'] ?? '') === 'done' && !empty($op['manager_id']) && (int) ($op['ts'] ?? 0) >= $bestTs) {
                $best = (int) $op['manager_id']; $bestTs = (int) $op['ts'];
            }
        }
        return $best;
    }

    public function mark(string $accountId, int $leadId, string $key, string $status, ?int $managerId = null, string $source = 'webhook', ?string $error = null): void
    {
        $file = $this->file($accountId, $leadId);
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return;
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $map = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        $now = time();
        foreach ($map as $k => $v) {
            if ($now - (int) ($v['ts'] ?? 0) > self::KEEP_SEC) {
                unset($map[$k]);
            }
        }
        $map[$key] = array_filter([
            'ts'         => $now,
            'status'     => $status,
            'manager_id' => $managerId,
            'source'     => $source,
            'error'      => $error !== null ? mb_substr($error, 0, 300) : null,
        ], static fn($v) => $v !== null);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($map, JSON_UNESCAPED_UNICODE));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private function read(string $accountId, int $leadId): array
    {
        $file = $this->file($accountId, $leadId);
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        $map = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        return is_array($map) ? $map : [];
    }

    private function file(string $accountId, int $leadId): string
    {
        return $this->base . '/' . preg_replace('/[^0-9A-Za-z_-]/', '_', $accountId) . '/' . $leadId . '.json';
    }
}
