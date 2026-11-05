<?php
declare(strict_types=1);
namespace DealDist\Tests\Unit\Distribution;

use DealDist\Accounts\AccountRegistry;
use DealDist\Config\SettingsStorage;
use DealDist\Distribution\{DistributionService, DistributionLog, QueueStorage, ScheduleChecker, StatusStorage};
use DealDist\Tests\Support\FakeApiClient;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/** 05.11.2026: отключённые сотрудники, защита правил, карточка клиента. */
final class ClientSafetyTest extends TestCase
{
    private string $acc = '200';
    private string $dir;
    private FakeApiClient $api;
    private StatusStorage $status;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dd-cs-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $_ENV['STORAGE_PATH'] = $this->dir;
        $this->api = new FakeApiClient();
        $this->status = new StatusStorage();
        foreach ([1, 2, 3, 4] as $u) $this->status->set($this->acc, $u, true, 0);
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->dir)); }

    private function svc(): DistributionService
    { return new DistributionService($this->api, new QueueStorage(), new ScheduleChecker(), $this->status, new DistributionLog(), new Logger('t')); }
    private function lead(int $id): void
    { $this->api->leads[$id] = ['id' => $id, 'pipeline_id' => 10, 'status_id' => 500, 'responsible_user_id' => 0, 'created_at' => time() - 60, '_embedded' => ['contacts' => [], 'companies' => []]]; }
    private function payload(int $lead): array
    {
        $rule = ['pipeline_id' => null, 'stage_id' => null, 'check_history' => false, 'check_schedule' => false, 'redistribute_after' => 0,
                 'managers' => [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], 'filters' => []];
        return ['account_id' => $this->acc, 'lead_id' => $lead, 'pipeline_id' => 10, 'stage_id' => 500, 'distribution_method' => 'round_robin', 'rules' => [$rule], 'dp_settings' => [], 'force' => true];
    }

    public function test_inactive_user_never_gets_a_lead(): void
    {
        $this->api->activeUsers = [1, 2, 4];   // 3 уволен
        $got = [];
        foreach ([31, 32, 33, 34, 35, 36] as $l) { $this->lead($l); $got[] = $this->svc()->distribute($this->payload($l))['assigned_to_id']; }
        self::assertNotContains(3, $got);
        self::assertCount(6, $got);
    }

    public function test_rejected_user_falls_through_to_next_manager(): void
    {
        $this->api->rejectUsers = [1];         // кэш активных устарел, amoCRM отказывает
        $this->lead(41);
        $res = $this->svc()->distribute($this->payload(41));
        self::assertNotNull($res);
        self::assertNotSame(1, $res['assigned_to_id']);
        $reasons = array_column((new DistributionLog())->tail($this->acc, 10), 'reason');
        self::assertContains('skipped_inactive_user', $reasons);
        self::assertContains('assigned', $reasons);
    }

    public function test_empty_rules_do_not_wipe_existing(): void
    {
        $s = new SettingsStorage();
        $s->save($this->acc, ['distribution_method' => 'round_robin', 'rules' => [['managers' => [['id' => 1]]]]]);
        $s->save($this->acc, ['distribution_method' => 'round_robin', 'rules' => []]);
        self::assertCount(1, $s->load($this->acc)['rules']);
        $s->save($this->acc, ['distribution_method' => 'round_robin', 'rules' => [], 'allow_empty_rules' => true]);
        self::assertSame([], $s->load($this->acc)['rules']);
        self::assertArrayNotHasKey('allow_empty_rules', $s->load($this->acc));
    }

    public function test_registry_stage_progression(): void
    {
        $r = new AccountRegistry();
        $r->save($this->acc, ['installed_at' => time() - 3 * 86400, 'domain' => 'client.kommo.com']);
        self::assertSame('installed', $r->stage($this->acc)['stage']);
        self::assertTrue($r->stage($this->acc)['stuck']);
        $r->touch($this->acc);
        self::assertSame('opened', $r->stage($this->acc)['stage']);
        (new SettingsStorage())->save($this->acc, ['rules' => [['managers' => [['id' => 1]]]]]);
        self::assertSame('rules', $r->stage($this->acc)['stage']);
        $r->markUninstalled($this->acc, true);
        self::assertSame('uninstalled', $r->stage($this->acc)['stage']);
    }

    public function test_internal_accounts_detected(): void
    {
        self::assertTrue(AccountRegistry::isInternal('30119215', []));
        self::assertTrue(AccountRegistry::isInternal('1', ['domain' => 'aat45.kommo.com']));
        self::assertTrue(AccountRegistry::isInternal('1', ['installer' => ['email' => 'hello@koagency.me']]));
        self::assertFalse(AccountRegistry::isInternal('36387859', ['domain' => 'estesie.kommo.com', 'installer' => ['email' => 'admin@estesiedental.com']]));
    }
}
