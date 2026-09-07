<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Checks whether a manager is currently within their working hours.
 *
 * Schedules are stored in:
 *   STORAGE_PATH/schedules/{accountId}/{userId}.json
 *
 * JSON structure:
 * {
 *   "timezone": "Europe/Moscow",
 *   "days": {
 *     "mon": {"start": "09:00", "end": "18:00"},
 *     "tue": {"start": "09:00", "end": "18:00"},
 *     "wed": {"start": "09:00", "end": "18:00"},
 *     "thu": {"start": "09:00", "end": "18:00"},
 *     "fri": {"start": "09:00", "end": "18:00"},
 *     "sat": null,
 *     "sun": null
 *   }
 * }
 */
class ScheduleChecker
{
    private const DAY_NAMES = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/schedules';
    }

    /**
     * Returns true if the manager is currently working (or no schedule is defined).
     */
    public function isAvailable(string $accountId, int $userId): bool
    {
        return $this->isAvailableAt($accountId, $userId, new \DateTime('now'));
    }

    /**
     * Same as isAvailable() but uses a specific point in time.
     * Used directly in tests to avoid mocking the clock.
     */
    public function isAvailableAt(string $accountId, int $userId, \DateTime $at): bool
    {
        $schedule = $this->load($accountId, $userId);
        if ($schedule === null) {
            return true; // No schedule configured → always available
        }

        $tz  = new \DateTimeZone($schedule['timezone'] ?? 'UTC');
        $now = (clone $at)->setTimezone($tz);
        $day = self::DAY_NAMES[(int) $now->format('w')];

        $daySchedule = $schedule['days'][$day] ?? null;
        if ($daySchedule === null) {
            return false; // Day off
        }

        $startStr = $daySchedule['start'] ?? '00:00';
        $endStr   = $daySchedule['end']   ?? '23:59';

        $start = \DateTime::createFromFormat('H:i', $startStr, $tz);
        $end   = \DateTime::createFromFormat('H:i', $endStr,   $tz);

        $start->setDate((int) $now->format('Y'), (int) $now->format('m'), (int) $now->format('d'));
        $end->setDate((int) $now->format('Y'), (int) $now->format('m'), (int) $now->format('d'));

        return $now >= $start && $now <= $end;
    }

    public function saveSchedule(string $accountId, int $userId, array $schedule): void
    {
        $dir = $this->basePath . '/' . $accountId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->basePath . '/' . $accountId . '/' . $userId . '.json',
            json_encode($schedule, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public function getSchedule(string $accountId, int $userId): ?array
    {
        return $this->load($accountId, $userId);
    }

    public function deleteSchedule(string $accountId, int $userId): void
    {
        $file = $this->basePath . '/' . $accountId . '/' . $userId . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Return all schedules for the account keyed by user_id.
     *
     * @return array<int, array>
     */
    public function listSchedules(string $accountId): array
    {
        $dir = $this->basePath . '/' . $accountId;
        if (!is_dir($dir)) {
            return [];
        }

        $result = [];
        foreach (glob($dir . '/*.json') as $file) {
            $userId = (int) basename($file, '.json');
            $data   = json_decode(file_get_contents($file), true);
            if ($data !== null) {
                $result[$userId] = $data;
            }
        }
        return $result;
    }

    private function load(string $accountId, int $userId): ?array
    {
        $file = $this->basePath . '/' . $accountId . '/' . $userId . '.json';
        if (!file_exists($file)) {
            return null;
        }
        return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
}
