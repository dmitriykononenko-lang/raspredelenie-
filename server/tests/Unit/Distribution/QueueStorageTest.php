<?php

declare(strict_types=1);

namespace DealDist\Tests\Unit\Distribution;

use DealDist\Distribution\QueueStorage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DealDist\Distribution\QueueStorage
 */
class QueueStorageTest extends TestCase
{
    private QueueStorage $storage;
    private string       $accountId = 'test_account';
    private string       $ruleHash  = 'abc123';

    protected function setUp(): void
    {
        $this->storage = new QueueStorage();
        $this->storage->resetQueue($this->accountId, $this->ruleHash);
    }

    // ── Round-robin order ──────────────────────────────────────────────────────

    public function testFirstCallReturnsFirstManager(): void
    {
        $chosen = $this->storage->getNextManager($this->accountId, $this->ruleHash, [10, 20, 30]);
        $this->assertSame(10, $chosen);
    }

    public function testSecondCallReturnsSecondManager(): void
    {
        $this->storage->getNextManager($this->accountId, $this->ruleHash, [10, 20, 30]);
        $chosen = $this->storage->getNextManager($this->accountId, $this->ruleHash, [10, 20, 30]);
        $this->assertSame(20, $chosen);
    }

    public function testQueueWrapsAround(): void
    {
        $managers = [10, 20, 30];
        $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers); // 10
        $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers); // 20
        $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers); // 30
        $chosen = $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers); // wraps → 10
        $this->assertSame(10, $chosen);
    }

    public function testFullCycleCoverAllManagers(): void
    {
        $managers = [1, 2, 3, 4];
        $results  = [];
        foreach (range(1, 4) as $_) {
            $results[] = $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers);
        }
        $this->assertSame([1, 2, 3, 4], $results);
    }

    public function testSingleManagerAlwaysReturnsItself(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $chosen = $this->storage->getNextManager($this->accountId, $this->ruleHash, [99]);
            $this->assertSame(99, $chosen);
        }
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function testPointerPersistsBetweenInstances(): void
    {
        $managers = [10, 20, 30];
        $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers); // 10

        // Create a new instance — should continue from saved state
        $storage2  = new QueueStorage();
        $chosen    = $storage2->getNextManager($this->accountId, $this->ruleHash, $managers);
        $this->assertSame(20, $chosen);
    }

    // ── Reset ─────────────────────────────────────────────────────────────────

    public function testResetRestartsCycleFromFirst(): void
    {
        $managers = [10, 20, 30];
        $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers); // 10
        $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers); // 20

        $this->storage->resetQueue($this->accountId, $this->ruleHash);

        $chosen = $this->storage->getNextManager($this->accountId, $this->ruleHash, $managers);
        $this->assertSame(10, $chosen);
    }

    public function testResetOnNonExistentQueueIsNoop(): void
    {
        // Should not throw
        $this->storage->resetQueue($this->accountId, 'nonexistent_hash');
        $this->assertTrue(true);
    }

    // ── Manager list changes ──────────────────────────────────────────────────

    public function testManagerListChangeResyncsQueue(): void
    {
        $this->storage->getNextManager($this->accountId, $this->ruleHash, [10, 20]); // 10
        $this->storage->getNextManager($this->accountId, $this->ruleHash, [10, 20]); // 20

        // Manager list changes — queue re-initializes
        $chosen = $this->storage->getNextManager($this->accountId, $this->ruleHash, [10, 20, 30]);
        $this->assertContains($chosen, [10, 20, 30]);
    }

    // ── Isolation between rules ───────────────────────────────────────────────

    public function testDifferentRulesHaveIndependentQueues(): void
    {
        $managers  = [10, 20, 30];
        $hashA     = 'rule_a';
        $hashB     = 'rule_b';

        $this->storage->getNextManager($this->accountId, $hashA, $managers); // 10
        $this->storage->getNextManager($this->accountId, $hashA, $managers); // 20

        // Rule B starts from the beginning
        $chosen = $this->storage->getNextManager($this->accountId, $hashB, $managers);
        $this->assertSame(10, $chosen);

        // Cleanup
        $this->storage->resetQueue($this->accountId, $hashA);
        $this->storage->resetQueue($this->accountId, $hashB);
    }

    // ── listQueues ────────────────────────────────────────────────────────────

    public function testListQueuesReturnsAllQueues(): void
    {
        $this->storage->getNextManager($this->accountId, 'hash1', [1, 2]);
        $this->storage->getNextManager($this->accountId, 'hash2', [3, 4]);

        $queues = $this->storage->listQueues($this->accountId);

        $this->assertArrayHasKey('hash1', $queues);
        $this->assertArrayHasKey('hash2', $queues);

        // Cleanup
        $this->storage->resetQueue($this->accountId, 'hash1');
        $this->storage->resetQueue($this->accountId, 'hash2');
    }
}
