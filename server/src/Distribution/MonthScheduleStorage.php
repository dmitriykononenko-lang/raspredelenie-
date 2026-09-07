<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Помесячный сменный график (v1.3.0).
 *
 *   STORAGE_PATH/schedules_month/{accountId}/shifts.json   — часы смен + timezone
 *   STORAGE_PATH/schedules_month/{accountId}/{YYYY-MM}.json — сетка месяца:
 *       { "grid": { "<userId>": { "<day>": "<code>", ... }, ... } }
 *
 * Коды ячеек: "1","2","3" — смены (часы берутся из shifts);
 *             "в" выходной, "о" отпуск, "б" болеет, "у" уволен; "" — не задано.
 * Приоритет над недельным шаблоном ScheduleChecker: если на дату есть код —
 * решает он; если записи нет — работает недельный шаблон (fallback).
 */
class MonthScheduleStorage
{
    public const DEFAULT_SHIFTS = [
        '1' => ['start' => '09:00', 'end' => '18:00'],
        '2' => ['start' => '12:00', 'end' => '21:00'],
        '3' => ['start' => '15:00', 'end' => '23:59'],
    ];

    /** Коды «не работает» (в обоих регистрах, кириллица). */
    public const OFF_CODES = ['в', 'о', 'б', 'у', 'В', 'О', 'Б', 'У'];

    private string $base;

    public function __construct()
    {
        $this->base = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/schedules_month';
    }

    private function dir(string $accountId): string
    {
        $d = $this->base . '/' . preg_replace('/[^0-9a-z_-]/i', '', $accountId);
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
        return $d;
    }

    /** @return array{shifts: array, timezone: string} */
    public function getShifts(string $accountId): array
    {
        $f = $this->base . '/' . $accountId . '/shifts.json';
        $d = file_exists($f) ? json_decode((string) file_get_contents($f), true) : null;
        $shifts = (is_array($d) && is_array($d['shifts'] ?? null) && $d['shifts']) ? $d['shifts'] : self::DEFAULT_SHIFTS;
        $tz     = (is_array($d) && !empty($d['timezone'])) ? (string) $d['timezone'] : 'Europe/Moscow';
        return ['shifts' => $shifts, 'timezone' => $tz];
    }

    public function saveShifts(string $accountId, array $shifts, string $timezone): void
    {
        $clean = [];
        foreach ($shifts as $code => $hours) {
            $code = trim((string) $code);
            if ($code === '' || !is_array($hours)) continue;
            $s = (string) ($hours['start'] ?? '');
            $e = (string) ($hours['end'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e)) continue;
            $clean[$code] = ['start' => $s, 'end' => $e];
        }
        if (!$clean) $clean = self::DEFAULT_SHIFTS;
        try { new \DateTimeZone($timezone); } catch (\Throwable $t) { $timezone = 'Europe/Moscow'; }
        file_put_contents(
            $this->dir($accountId) . '/shifts.json',
            json_encode(['shifts' => $clean, 'timezone' => $timezone], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /** @return array<string, array<string,string>>  userId => [day => code] */
    public function getMonth(string $accountId, string $ym): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return [];
        $f = $this->base . '/' . $accountId . '/' . $ym . '.json';
        if (!file_exists($f)) return [];
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d)) return [];
        return is_array($d['grid'] ?? null) ? $d['grid'] : [];
    }

    public function saveMonth(string $accountId, string $ym, array $grid): void
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            throw new \InvalidArgumentException('bad month');
        }
        $clean = [];
        foreach ($grid as $uid => $days) {
            $uid = (string) (int) $uid;
            if ($uid === '0' || !is_array($days)) continue;
            $row = [];
            foreach ($days as $day => $code) {
                $day = (int) $day;
                if ($day < 1 || $day > 31) continue;
                $code = trim((string) $code);
                if ($code === '') continue;
                $row[(string) $day] = substr($code, 0, 6);
            }
            if ($row) $clean[$uid] = $row;
        }
        file_put_contents(
            $this->dir($accountId) . '/' . $ym . '.json',
            json_encode(['grid' => $clean, 'updated_at' => time()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /** Код смены на дату или null, если в месячном графике записи нет. */
    public function codeAt(string $accountId, int $userId, \DateTime $at): ?string
    {
        $grid = $this->getMonth($accountId, $at->format('Y-m'));
        $code = $grid[(string) $userId][(string) (int) $at->format('j')] ?? null;
        return ($code === null || $code === '') ? null : (string) $code;
    }

    /** Список месяцев, для которых есть график. */
    public function listMonths(string $accountId): array
    {
        $dir = $this->base . '/' . $accountId;
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            $n = basename($f, '.json');
            if (preg_match('/^\d{4}-\d{2}$/', $n)) $out[] = $n;
        }
        sort($out);
        return $out;
    }
}
