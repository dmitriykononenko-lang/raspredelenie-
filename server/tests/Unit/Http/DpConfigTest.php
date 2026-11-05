<?php
declare(strict_types=1);
namespace DealDist\Tests\Unit\Http;

use DealDist\Http\Controller\WebhookController;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/** 1.3.21: менеджеры из настроек действия Digital Pipeline (поле config). */
final class DpConfigTest extends TestCase
{
    private function norm(array $ws): array
    {
        $m = new \ReflectionMethod(WebhookController::class, 'normalizeDpSettings');
        $m->setAccessible(true);
        return $m->invoke(new WebhookController(new Logger('t')), $ws);
    }

    public function test_json_config(): void
    {
        $r = $this->norm(['config' => '{"managers":[5,3],"check_schedule":true,"check_history":false}']);
        self::assertSame([['id' => 5], ['id' => 3]], $r['managers']);
        self::assertTrue($r['check_schedule']);
        self::assertFalse($r['check_history']);
        self::assertSame('dp:3,5', $r['_queue_key']);
        self::assertArrayNotHasKey('config', $r);
    }

    public function test_plain_ids_and_empty(): void
    {
        self::assertSame([['id' => 1], ['id' => 2]], $this->norm(['config' => '1, 2'])['managers']);
        $empty = $this->norm(['config' => '']);
        self::assertSame([], $empty['managers']);
        self::assertArrayNotHasKey('_queue_key', $empty);
        self::assertSame([], $this->norm([])['managers']);
    }

    public function test_legacy_managers_array_still_works(): void
    {
        self::assertSame([['id' => 7]], $this->norm(['managers' => [['id' => '7'], ['id' => '']]])['managers']);
    }
}
