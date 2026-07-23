<?php

declare(strict_types=1);

namespace DealDist\Tests\Unit\Distribution;

use DealDist\Distribution\StatusStorage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DealDist\Distribution\StatusStorage
 */
class StatusStorageTest extends TestCase
{
    private StatusStorage $storage;
    private string        $accountId = 'test_status_account';

    protected function setUp(): void
    {
        $this->storage = new StatusStorage();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    // ── Default (opt-out) ──────────────────────────────────────────────────────

    public function testUnknownManagerIsOnlineByDefault(): void
    {
        $this->assertTrue($this->storage->isOnline($this->accountId, 777));
    }

    public function testEmptyAccountHasNoStatuses(): void
    {
        $this->assertSame([], $this->storage->getAll($this->accountId));
    }

    // ── Set / get ──────────────────────────────────────────────────────────────

    public function testSetOfflineMakesManagerOffline(): void
    {
        $this->storage->set($this->accountId, 201, false, 201);
        $this->assertFalse($this->storage->isOnline($this->accountId, 201));
    }

    public function testSetOnlineAgainMakesManagerOnline(): void
    {
        $this->storage->set($this->accountId, 201, false);
        $this->storage->set($this->accountId, 201, true);
        $this->assertTrue($this->storage->isOnline($this->accountId, 201));
    }

    public function testGetAllReturnsStoredStatuses(): void
    {
        $this->storage->set($this->accountId, 201, false, 201);
        $all = $this->storage->getAll($this->accountId);

        $this->assertArrayHasKey('201', $all);
        $this->assertFalse($all['201']['online']);
        $this->assertSame(201, $all['201']['actor_id']);
    }

    // ── Persistence ────────────────────────────────────────────────────────────

    public function testStatusPersistsBetweenInstances(): void
    {
        $this->storage->set($this->accountId, 202, false);

        $other = new StatusStorage();
        $this->assertFalse($other->isOnline($this->accountId, 202));
    }

    // ── History ────────────────────────────────────────────────────────────────

    public function testHistoryRecordsToggles(): void
    {
        $this->storage->set($this->accountId, 201, false, 201);
        $this->storage->set($this->accountId, 201, true, 500);

        $history = $this->storage->history($this->accountId);

        $this->assertCount(2, $history);
        // свежие — первыми
        $this->assertTrue($history[0]['online']);
        $this->assertSame(500, $history[0]['actor_id']);
        $this->assertFalse($history[1]['online']);
    }

    public function testHistorySkipsNoOpChanges(): void
    {
        $this->storage->set($this->accountId, 201, false);
        $this->storage->set($this->accountId, 201, false); // тот же статус — не логируем

        $this->assertCount(1, $this->storage->history($this->accountId));
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function cleanup(): void
    {
        $base = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/statuses';
        foreach ([
            $base . '/' . $this->accountId . '.json',
            $base . '/' . $this->accountId . '.history.jsonl',
        ] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }
}
