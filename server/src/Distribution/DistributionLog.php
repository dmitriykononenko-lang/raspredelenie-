<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Append-only log of every distribution decision.
 *
 * Each account gets its own NDJSON file:
 *   STORAGE_PATH/logs/{accountId}.ndjson
 *
 * Each line is a JSON object:
 * {
 *   "ts":          1712345678,
 *   "lead_id":     67890,
 *   "pipeline_id": 111,
 *   "stage_id":    222,
 *   "manager_id":  3,
 *   "method":      "round_robin",
 *   "reason":      "assigned" | "skipped_no_rule" | "skipped_schedule" | "history_match"
 * }
 */
class DistributionLog
{
    private const MAX_LINES = 10_000;
    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/logs';
    }

    public function record(
        string  $accountId,
        int     $leadId,
        ?int    $pipelineId,
        ?int    $stageId,
        ?int    $managerId,
        string  $method,
        string  $reason,
    ): void {
        $dir = $this->basePath;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry = json_encode([
            'ts'          => time(),
            'lead_id'     => $leadId,
            'pipeline_id' => $pipelineId,
            'stage_id'    => $stageId,
            'manager_id'  => $managerId,
            'method'      => $method,
            'reason'      => $reason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        file_put_contents(
            $this->basePath . '/' . $accountId . '.ndjson',
            $entry . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Return the last $limit entries for an account, newest first.
     */
    public function tail(string $accountId, int $limit = 100): array
    {
        $file = $this->basePath . '/' . $accountId . '.ndjson';
        if (!file_exists($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $slice = array_slice($lines, -$limit);
        $rows  = [];
        foreach (array_reverse($slice) as $line) {
            $decoded = json_decode($line, true);
            if ($decoded !== null) {
                $rows[] = $decoded;
            }
        }
        return $rows;
    }

    /**
     * Rotate log: keep only the last MAX_LINES entries.
     * Call periodically (e.g. from a cron job).
     */
    public function rotate(string $accountId): void
    {
        $file = $this->basePath . '/' . $accountId . '.ndjson';
        if (!file_exists($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) <= self::MAX_LINES) {
            return;
        }

        $kept = array_slice($lines, -self::MAX_LINES);
        file_put_contents($file, implode("\n", $kept) . "\n", LOCK_EX);
    }
}
