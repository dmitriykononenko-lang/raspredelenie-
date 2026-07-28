<?php

declare(strict_types=1);

namespace DealDist\Tests\Unit\Distribution;

use DealDist\Distribution\TemplateStorage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DealDist\Distribution\TemplateStorage
 */
class TemplateStorageTest extends TestCase
{
    private TemplateStorage $storage;
    private string          $accountId = 'test_tpl_account';

    protected function setUp(): void
    {
        $this->storage = new TemplateStorage();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testEmptyByDefault(): void
    {
        $this->assertSame([], $this->storage->all($this->accountId));
    }

    public function testCreateAssignsIdAndTimestamps(): void
    {
        $tpl = $this->storage->create($this->accountId, [
            'name'     => 'Новые заявки',
            'type'     => 'round_robin',
            'managers' => [['id' => 201], ['id' => 202]],
        ]);

        $this->assertStringStartsWith('tpl_', $tpl['id']);
        $this->assertSame('Новые заявки', $tpl['name']);
        $this->assertSame('round_robin', $tpl['type']);
        $this->assertCount(2, $tpl['managers']);
        $this->assertArrayHasKey('created_at', $tpl);
        $this->assertArrayHasKey('updated_at', $tpl);
    }

    public function testCreatePersists(): void
    {
        $tpl = $this->storage->create($this->accountId, ['name' => 'X', 'managers' => [['id' => 1]]]);

        $other = new TemplateStorage();
        $this->assertNotNull($other->get($this->accountId, $tpl['id']));
    }

    public function testInvalidTypeFallsBackToRoundRobin(): void
    {
        $tpl = $this->storage->create($this->accountId, ['name' => 'X', 'type' => 'nonsense']);
        $this->assertSame('round_robin', $tpl['type']);
    }

    public function testPercentTypeKeepsPercentClamped(): void
    {
        $tpl = $this->storage->create($this->accountId, [
            'name'     => 'VIP',
            'type'     => 'percent',
            'managers' => [['id' => 201, 'percent' => 150], ['id' => 202, 'percent' => 40]],
        ]);

        $this->assertSame(100, $tpl['managers'][0]['percent']); // clamped
        $this->assertSame(40, $tpl['managers'][1]['percent']);
    }

    public function testRoundRobinDropsPercent(): void
    {
        $tpl = $this->storage->create($this->accountId, [
            'name'     => 'RR',
            'type'     => 'round_robin',
            'managers' => [['id' => 201, 'percent' => 60]],
        ]);
        $this->assertArrayNotHasKey('percent', $tpl['managers'][0]);
    }

    public function testUpdateChangesFieldsKeepsCreatedAt(): void
    {
        $tpl = $this->storage->create($this->accountId, ['name' => 'Old', 'managers' => [['id' => 1]]]);
        $createdAt = $tpl['created_at'];

        $updated = $this->storage->update($this->accountId, $tpl['id'], [
            'name'     => 'New',
            'managers' => [['id' => 5]],
        ]);

        $this->assertSame('New', $updated['name']);
        $this->assertSame(5, $updated['managers'][0]['id']);
        $this->assertSame($createdAt, $updated['created_at']);
    }

    public function testUpdateUnknownReturnsNull(): void
    {
        $this->assertNull($this->storage->update($this->accountId, 'tpl_missing', ['name' => 'x']));
    }

    public function testDeleteRemovesTemplate(): void
    {
        $tpl = $this->storage->create($this->accountId, ['name' => 'X']);
        $this->assertTrue($this->storage->delete($this->accountId, $tpl['id']));
        $this->assertNull($this->storage->get($this->accountId, $tpl['id']));
    }

    public function testDeleteUnknownReturnsFalse(): void
    {
        $this->assertFalse($this->storage->delete($this->accountId, 'tpl_missing'));
    }

    public function testInvalidManagersFiltered(): void
    {
        $tpl = $this->storage->create($this->accountId, [
            'name'     => 'X',
            'managers' => [['id' => 0], ['id' => 201], ['nope' => 1]],
        ]);
        $this->assertCount(1, $tpl['managers']);
        $this->assertSame(201, $tpl['managers'][0]['id']);
    }

    private function cleanup(): void
    {
        $base = rtrim($_ENV['STORAGE_PATH'] ?? sys_get_temp_dir(), '/') . '/templates';
        $f = $base . '/' . $this->accountId . '.json';
        if (file_exists($f)) {
            unlink($f);
        }
    }
}
