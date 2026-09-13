<?php
declare(strict_types=1);
namespace DealDist\Tests\Unit\Distribution;

use DealDist\AmoCRM\ApiClient;
use DealDist\Distribution\DistributionLog;
use DealDist\Distribution\DistributionService;
use DealDist\Distribution\OperationJournal;
use DealDist\Distribution\QueueStorage;
use DealDist\Distribution\ScheduleChecker;
use DealDist\Distribution\StatusStorage;
use DealDist\Tests\Support\FakeApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/** P0: retry/backoff, ошибки в истории, идемпотентность по журналу операций, ручная смена. */
final class P0ReliabilityTest extends TestCase
{
    private string $storage;
    private const ACC = '777';

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/dd-p0-' . bin2hex(random_bytes(4));
        mkdir($this->storage, 0777, true);
        $_ENV['STORAGE_PATH'] = $this->storage;
        unset($_ENV['AMO_LONG_TERM_TOKEN']);
        // токены для ApiClient::request()
        mkdir($this->storage . '/tokens', 0777, true);
        file_put_contents($this->storage . '/tokens/' . self::ACC . '.json', json_encode([
            'access_token' => 'x', 'refresh_token' => 'y', 'base_domain' => 'test.amocrm.ru',
        ]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storage));
    }

    private function apiWith(array $responses, array &$history): ApiClient
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return new ApiClient(new Logger('t', [new NullHandler()]), new Client(['handler' => $stack]));
    }

    private function service(ApiClient $api): DistributionService
    {
        return new DistributionService($api, new QueueStorage(), new ScheduleChecker(), new StatusStorage(), new DistributionLog(), new Logger('t', [new NullHandler()]));
    }

    private function online(int ...$ids): void
    {
        $st = new StatusStorage();
        foreach ($ids as $id) { $st->set(self::ACC, $id, true, 0); }
    }

    private function rule(array $over = []): array
    {
        return $over + ['pipeline_id' => 1, 'stage_id' => 10, 'managers' => [['id' => 1], ['id' => 2]]];
    }

    // Given: amo отвечает 429, затем 200. When: GET лида. Then: результат получен, 2 запроса.
    public function testRetriesOn429ThenSucceeds(): void
    {
        $hist = [];
        $api  = $this->apiWith([
            new Response(429, ['Retry-After' => '0'], '{}'),
            new Response(200, [], json_encode(['id' => 5, 'status_id' => 10, 'pipeline_id' => 1])),
        ], $hist);
        $lead = $api->getLead(self::ACC, 5);
        self::assertSame(5, $lead['id']);
        self::assertCount(2, $hist);
    }

    // Given: amo трижды отвечает 500. When: PATCH. Then: RuntimeException с кодом 500 после 3 попыток.
    public function testGivesUpAfterThreeServerErrors(): void
    {
        $hist = [];
        $api  = $this->apiWith([new Response(500), new Response(500), new Response(500)], $hist);
        try {
            $api->updateLeadResponsible(self::ACC, 5, 1);
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame(500, $e->getCode());
            self::assertCount(3, $hist);
        }
    }

    // Given: 401, затем refresh OK, затем 200. When: запрос. Then: токен обновлён, ответ получен.
    public function testRefreshesTokenOn401(): void
    {
        $_ENV['AMO_CLIENT_ID'] = 'a'; $_ENV['AMO_CLIENT_SECRET'] = 'b'; $_ENV['AMO_REDIRECT_URI'] = 'c';
        $hist = [];
        $api  = $this->apiWith([
            new Response(401),
            new Response(200, [], json_encode(['access_token' => 'new', 'refresh_token' => 'r2'])),
            new Response(200, [], json_encode(['id' => 5])),
        ], $hist);
        self::assertSame(5, $api->getLead(self::ACC, 5)['id']);
        $saved = json_decode(file_get_contents($this->storage . '/tokens/' . self::ACC . '.json'), true);
        self::assertSame('new', $saved['access_token']);
    }

    // Given: правило, менеджеры online, amo падает на PATCH. When: distribute. Then: запись error в истории, исключение проброшено.
    public function testApiErrorIsRecordedInHistory(): void
    {
        $this->online(1, 2);
        $hist = [];
        $api  = $this->apiWith([
            new Response(200, [], json_encode(['id' => 5, 'status_id' => 10, 'pipeline_id' => 1])),
            new Response(503), new Response(503), new Response(503),
        ], $hist);
        $svc = $this->service($api);
        try {
            $svc->distribute(['account_id' => self::ACC, 'lead_id' => 5, 'rules' => [$this->rule()], 'event_key' => 'status:10:100']);
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            $last = (new DistributionLog())->tail(self::ACC, 1)[0];
            self::assertSame('error', $last['reason']);
            self::assertStringContainsString('503', $last['error']);
            self::assertSame('failed', (new OperationJournal())->seen(self::ACC, 5, 'status:10:100')['status']);
        }
    }

    // Given: сделка распределена по событию K. When: то же событие K приходит снова (спустя любое время). Then: skipped_duplicate, второго назначения нет.
    public function testSameEventKeyIsIdempotentWithoutTimeWindow(): void
    {
        $this->online(1, 2);
        $api = new FakeApiClient();
        $api->leads[5] = ['id' => 5, 'status_id' => 10, 'pipeline_id' => 1];
        $svc = $this->service($api);
        $p = ['account_id' => self::ACC, 'lead_id' => 5, 'rules' => [$this->rule()], 'event_key' => 'status:10:100'];
        self::assertNotNull($svc->distribute($p));
        // имитируем «окно прошло»: сбрасываем recent LeadGuard
        @unlink($this->storage . '/recent/' . self::ACC . '.json');
        self::assertNull($svc->distribute($p));
        self::assertCount(1, $api->updates);
        self::assertSame('skipped_duplicate', (new DistributionLog())->tail(self::ACC, 1)[0]['reason']);
    }

    // Given: та же сделка. When: новое событие (другой updated_at / этап). Then: новая операция, назначение выполняется.
    public function testDifferentEventKeyIsNewOperation(): void
    {
        $this->online(1, 2);
        $api = new FakeApiClient();
        $api->leads[5] = ['id' => 5, 'status_id' => 10, 'pipeline_id' => 1];
        $svc = $this->service($api);
        self::assertNotNull($svc->distribute(['account_id' => self::ACC, 'lead_id' => 5, 'rules' => [$this->rule()], 'event_key' => 'status:10:100']));
        @unlink($this->storage . '/recent/' . self::ACC . '.json');
        self::assertNotNull($svc->distribute(['account_id' => self::ACC, 'lead_id' => 5, 'rules' => [$this->rule()], 'event_key' => 'status:10:200']));
        self::assertCount(2, $api->updates);
    }

    // Given: все офлайн, правило queue + manual_change=respect. When: сделка ставится в очередь. Then: в записи очереди сохранён ответственный на момент постановки и политика.
    public function testQueuedEntryStoresResponsibleAndPolicy(): void
    {
        $st = new StatusStorage(); $st->set(self::ACC, 1, false, 0); $st->set(self::ACC, 2, false, 0);
        $api = new FakeApiClient();
        $api->leads[5] = ['id' => 5, 'status_id' => 10, 'pipeline_id' => 1, 'responsible_user_id' => 99];
        $svc = $this->service($api);
        $svc->distribute(['account_id' => self::ACC, 'lead_id' => 5, 'rules' => [$this->rule(['queue_when_unavailable' => true, 'manual_change' => 'respect'])]]);
        $e = (new \DealDist\Distribution\PendingStorage())->all(self::ACC)['5'];
        self::assertSame('waiting', $e['type']);
        self::assertSame(99, $e['resp_at_queue']);
        self::assertSame('respect', $e['manual_change']);
    }
}
