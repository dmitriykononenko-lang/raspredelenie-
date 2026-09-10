<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Защита от двойного распределения (P0).
 *
 * 1) Блокировка на сделку (flock): параллельные обработки одной сделки — например,
 *    повторы вебхука от amoCRM, пришедшие пока первый ещё обрабатывается, — не
 *    получают блокировку и пропускаются.
 * 2) Окно идемпотентности: если по сделке уже было наше назначение недавно
 *    (RECENT_WINDOW сек), повторный вызов пропускается без сдвига очереди.
 *
 * Файлы: STORAGE_PATH/locks/{accountId}/{leadId}.lock,
 *        STORAGE_PATH/recent/{accountId}.json  (lead_id => ts)
 */
class LeadGuard
{
    public const RECENT_WINDOW = 120;      // 2 минуты: ретраи amoCRM укладываются в ~60 с; реальный повторный переход позже — не дубль
    private const RECENT_KEEP  = 86400;    // чистим записи старше суток

    private string $base;

    public function __construct()
    {
        $this->base = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/');
    }

    /** @return resource|null  null = сделка уже обрабатывается другим процессом */
    public function acquire(string $accountId, int $leadId, bool $wait = false)
    {
        $dir = $this->base . '/locks/' . preg_replace('/[^0-9A-Za-z_-]/', '_', $accountId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($dir . '/' . $leadId . '.lock', 'c');
        if ($fh === false) {
            return null;
        }
        $ok = $wait ? flock($fh, LOCK_EX) : flock($fh, LOCK_EX | LOCK_NB);
        if (!$ok) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    /** @param resource|null $fh */
    public function release($fh): void
    {
        if (is_resource($fh)) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Дубль — только если недавно И на том же этапе (переход на другой этап — новое событие). */
    public function recentlyAssigned(string $accountId, int $leadId, ?int $stageId = null, int $window = self::RECENT_WINDOW): bool
    {
        $map = $this->readRecent($accountId);
        $v   = $map[(string) $leadId] ?? null;
        if ($v === null) {
            return false;
        }
        $ts    = is_array($v) ? (int) ($v['ts'] ?? 0) : (int) $v;
        $stage = is_array($v) ? ($v['stage'] ?? null) : null;
        if (time() - $ts >= $window) {
            return false;
        }
        if ($stageId !== null && $stage !== null && (int) $stage !== (int) $stageId) {
            return false;
        }
        return true;
    }

    public function markAssigned(string $accountId, int $leadId, ?int $stageId = null): void
    {
        $file = $this->recentFile($accountId);
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
            $ts = is_array($v) ? (int) ($v['ts'] ?? 0) : (int) $v;
            if ($now - $ts > self::RECENT_KEEP) {
                unset($map[$k]);
            }
        }
        $map[(string) $leadId] = ['ts' => $now, 'stage' => $stageId];
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($map));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private function readRecent(string $accountId): array
    {
        $file = $this->recentFile($accountId);
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        $map = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        return is_array($map) ? $map : [];
    }

    private function recentFile(string $accountId): string
    {
        return $this->base . '/recent/' . preg_replace('/[^0-9A-Za-z_-]/', '_', $accountId) . '.json';
    }
}
