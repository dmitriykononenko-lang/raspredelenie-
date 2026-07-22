<?php

declare(strict_types=1);

namespace DealDist\Tests\Unit\Distribution;

use DealDist\Distribution\ScheduleChecker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DealDist\Distribution\ScheduleChecker
 */
class ScheduleCheckerTest extends TestCase
{
    private ScheduleChecker $checker;
    private string          $accountId = 'test_account';
    private int             $userId    = 42;

    protected function setUp(): void
    {
        $this->checker = new ScheduleChecker();
        // Ensure clean state
        $this->checker->deleteSchedule($this->accountId, $this->userId);
    }

    protected function tearDown(): void
    {
        $this->checker->deleteSchedule($this->accountId, $this->userId);
    }

    // ── No schedule → always available ────────────────────────────────────────

    public function testNoScheduleIsAlwaysAvailable(): void
    {
        $this->assertTrue($this->checker->isAvailable($this->accountId, $this->userId));
    }

    // ── Weekday schedule ──────────────────────────────────────────────────────

    public function testAvailableWithinWorkingHours(): void
    {
        // Force "Monday 12:00 UTC"
        $this->checker->saveSchedule($this->accountId, $this->userId, $this->schedule('09:00', '18:00'));

        $available = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-01 12:00:00', new \DateTimeZone('UTC')) // Monday
        );

        $this->assertTrue($available);
    }

    public function testUnavailableBeforeWorkingHours(): void
    {
        $this->checker->saveSchedule($this->accountId, $this->userId, $this->schedule('09:00', '18:00'));

        $available = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-01 08:59:59', new \DateTimeZone('UTC')) // Monday 08:59
        );

        $this->assertFalse($available);
    }

    public function testUnavailableAfterWorkingHours(): void
    {
        $this->checker->saveSchedule($this->accountId, $this->userId, $this->schedule('09:00', '18:00'));

        $available = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-01 18:01:00', new \DateTimeZone('UTC')) // Monday 18:01
        );

        $this->assertFalse($available);
    }

    public function testAvailableAtExactStartBoundary(): void
    {
        $this->checker->saveSchedule($this->accountId, $this->userId, $this->schedule('09:00', '18:00'));

        $available = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-01 09:00:00', new \DateTimeZone('UTC'))
        );

        $this->assertTrue($available);
    }

    public function testAvailableAtExactEndBoundary(): void
    {
        $this->checker->saveSchedule($this->accountId, $this->userId, $this->schedule('09:00', '18:00'));

        $available = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-01 18:00:00', new \DateTimeZone('UTC'))
        );

        $this->assertTrue($available);
    }

    // ── Day off ───────────────────────────────────────────────────────────────

    public function testUnavailableOnDayOff(): void
    {
        // Schedule with Saturday and Sunday as null (day off)
        $schedule = $this->schedule('09:00', '18:00');
        $schedule['days']['sat'] = null;
        $schedule['days']['sun'] = null;
        $this->checker->saveSchedule($this->accountId, $this->userId, $schedule);

        // Saturday 2024-04-06
        $available = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-06 12:00:00', new \DateTimeZone('UTC'))
        );

        $this->assertFalse($available);
    }

    public function testAvailableOnWorkdayWhenWeekendIsOff(): void
    {
        $schedule = $this->schedule('09:00', '18:00');
        $schedule['days']['sat'] = null;
        $schedule['days']['sun'] = null;
        $this->checker->saveSchedule($this->accountId, $this->userId, $schedule);

        // Monday 2024-04-01
        $available = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-01 14:00:00', new \DateTimeZone('UTC'))
        );

        $this->assertTrue($available);
    }

    // ── Timezone ──────────────────────────────────────────────────────────────

    public function testTimezoneOffsetApplied(): void
    {
        // Manager works 09:00-18:00 Moscow time (UTC+3)
        $schedule = $this->schedule('09:00', '18:00', 'Europe/Moscow');
        $this->checker->saveSchedule($this->accountId, $this->userId, $schedule);

        // 07:00 UTC = 10:00 Moscow → within working hours
        $available = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-01 07:00:00', new \DateTimeZone('UTC')) // Monday
        );
        $this->assertTrue($available);

        // 17:00 UTC = 20:00 Moscow → outside working hours
        $notAvailable = $this->checker->isAvailableAt(
            $this->accountId,
            $this->userId,
            new \DateTime('2024-04-01 17:00:00', new \DateTimeZone('UTC'))
        );
        $this->assertFalse($notAvailable);
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function testSaveAndLoad(): void
    {
        $schedule = $this->schedule('10:00', '19:00', 'Europe/Moscow');
        $this->checker->saveSchedule($this->accountId, $this->userId, $schedule);

        $loaded = $this->checker->getSchedule($this->accountId, $this->userId);

        $this->assertNotNull($loaded);
        $this->assertSame('Europe/Moscow', $loaded['timezone']);
        $this->assertSame('10:00', $loaded['days']['mon']['start']);
        $this->assertSame('19:00', $loaded['days']['mon']['end']);
    }

    public function testDeleteSchedule(): void
    {
        $this->checker->saveSchedule($this->accountId, $this->userId, $this->schedule());
        $this->checker->deleteSchedule($this->accountId, $this->userId);

        $this->assertNull($this->checker->getSchedule($this->accountId, $this->userId));
        // After deletion: always available
        $this->assertTrue($this->checker->isAvailable($this->accountId, $this->userId));
    }

    public function testListSchedules(): void
    {
        $this->checker->saveSchedule($this->accountId, 10, $this->schedule());
        $this->checker->saveSchedule($this->accountId, 20, $this->schedule('10:00', '20:00'));

        $all = $this->checker->listSchedules($this->accountId);
        $this->assertArrayHasKey(10, $all);
        $this->assertArrayHasKey(20, $all);

        // Cleanup
        $this->checker->deleteSchedule($this->accountId, 10);
        $this->checker->deleteSchedule($this->accountId, 20);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function schedule(
        string $start    = '09:00',
        string $end      = '18:00',
        string $timezone = 'UTC',
    ): array {
        $slot = ['start' => $start, 'end' => $end];
        return [
            'timezone' => $timezone,
            'days'     => [
                'mon' => $slot,
                'tue' => $slot,
                'wed' => $slot,
                'thu' => $slot,
                'fri' => $slot,
                'sat' => $slot,
                'sun' => $slot,
            ],
        ];
    }
}
