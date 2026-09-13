<?php
declare(strict_types=1);
namespace DealDist\Tests\Unit\Distribution;

use DealDist\Distribution\{DistributionService, DistributionLog, PendingStorage, QueueStorage, ScheduleChecker, StatusStorage, LeadGuard, MonthScheduleStorage};
use DealDist\Tests\Support\FakeApiClient;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Сценарии Given → When → Then для ядра распределения. Все хранилища — во временном
 * STORAGE_PATH, сеть заменена FakeApiClient. Время смен проверяется через месячный график
 * с искусственными часами вокруг «сейчас».
 */
final class DistributionServiceTest extends TestCase
{
    private string $acc = '100';
    private string $dir;
    private FakeApiClient $api;
    private StatusStorage $status;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dd-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $_ENV['STORAGE_PATH'] = $this->dir;
        $this->api = new FakeApiClient();
        $this->status = new StatusStorage();
        foreach ([1, 2, 3] as $u) $this->status->set($this->acc, $u, true, 0);
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->dir)); }

    private function svc(): DistributionService
    {
        return new DistributionService($this->api, new QueueStorage(), new ScheduleChecker(), $this->status, new DistributionLog(), new Logger('t'));
    }
    private function lead(int $id, int $stage = 500, int $resp = 0): void
    { $this->api->leads[$id] = ['id' => $id, 'pipeline_id' => 10, 'status_id' => $stage, 'responsible_user_id' => $resp, 'created_at' => time() - 60, '_embedded' => ['contacts' => [], 'companies' => []]]; }
    private function rule(array $extra = []): array
    { return array_merge(['pipeline_id' => null, 'stage_id' => null, 'check_history' => false, 'check_schedule' => false, 'redistribute_after' => 0, 'managers' => [['id' => 1], ['id' => 2], ['id' => 3]], 'filters' => []], $extra); }
    private function payload(int $lead, array $rule, array $x = []): array
    { return array_merge(['account_id' => $this->acc, 'lead_id' => $lead, 'pipeline_id' => 10, 'stage_id' => 500, 'distribution_method' => 'round_robin', 'rules' => [$rule], 'dp_settings' => []], $x); }
    private function reasons(): array
    { return array_column((new DistributionLog())->tail($this->acc, 100), 'reason'); }
    /** Месячный график: у всех менеджеров сегодня смена "1" с часами [$from,$to] МСК. */
    private function shiftToday(string $from, string $to, array $users = [1, 2, 3], array $dayOff = []): void
    {
        $ms = new MonthScheduleStorage(); $now = new \DateTime('now', new \DateTimeZone('Europe/Moscow'));
        $grid = []; foreach ($users as $u) $grid[(string) $u] = [$now->format('j') => '1'];
        foreach ($dayOff as $u) $grid[(string) $u] = [$now->format('j') => 'в'];
        $ms->saveShifts($this->acc, ['1' => ['start' => $from, 'end' => $to]], 'Europe/Moscow');
        $ms->saveMonth($this->acc, $now->format('Y-m'), $grid);
    }

    public function test_round_robin_cycles_through_managers(): void
    {
        foreach ([11, 12, 13, 14] as $l) $this->lead($l);
        $got = [];
        foreach ([11, 12, 13, 14] as $l) $got[] = $this->svc()->distribute($this->payload($l, $this->rule()))['assigned_to_id'];
        self::assertSame([1, 2, 3, 1], $got);
    }

    public function test_duplicate_webhook_within_window_is_skipped_and_queue_not_advanced(): void
    {
        $this->lead(21); $this->lead(22);
        $first = $this->svc()->distribute($this->payload(21, $this->rule()));
        $dup   = $this->svc()->distribute($this->payload(21, $this->rule()));
        $next  = $this->svc()->distribute($this->payload(22, $this->rule()));
        self::assertSame(1, $first['assigned_to_id']);
        self::assertNull($dup);
        self::assertSame(2, $next['assigned_to_id'], 'дубль не должен сдвигать очередь');
        self::assertContains('skipped_duplicate', $this->reasons());
    }

    public function test_same_lead_on_new_stage_is_not_a_duplicate(): void
    {
        $this->lead(31);
        $a = $this->svc()->distribute($this->payload(31, $this->rule(), ['stage_id' => 500]));
        $b = $this->svc()->distribute($this->payload(31, $this->rule(), ['stage_id' => 600]));
        self::assertNotNull($a); self::assertNotNull($b);
    }

    public function test_force_bypasses_duplicate_window(): void
    {
        $this->lead(41);
        $this->svc()->distribute($this->payload(41, $this->rule()));
        self::assertNotNull($this->svc()->distribute($this->payload(41, $this->rule(), ['force' => true])));
    }

    public function test_offline_managers_are_skipped(): void
    {
        $this->lead(51); $this->status->set($this->acc, 1, false, 0);
        self::assertSame(2, $this->svc()->distribute($this->payload(51, $this->rule()))['assigned_to_id']);
    }

    public function test_all_offline_without_queue_is_skipped_offline(): void
    {
        $this->lead(61); foreach ([1, 2, 3] as $u) $this->status->set($this->acc, $u, false, 0);
        self::assertNull($this->svc()->distribute($this->payload(61, $this->rule())));
        self::assertContains('skipped_offline', $this->reasons());
        self::assertSame([], (new PendingStorage())->all($this->acc));
    }

    public function test_history_prefers_previous_manager_when_online_and_on_shift(): void
    {
        $this->lead(71); $this->api->history[71] = [3];
        self::assertSame(3, $this->svc()->distribute($this->payload(71, $this->rule(['check_history' => true])))['assigned_to_id']);
        self::assertContains('history_match', $this->reasons());
    }

    public function test_history_candidate_offline_falls_back_to_queue(): void
    {
        $this->lead(81); $this->api->history[81] = [3]; $this->status->set($this->acc, 3, false, 0);
        self::assertSame(1, $this->svc()->distribute($this->payload(81, $this->rule(['check_history' => true])))['assigned_to_id']);
    }

    public function test_history_candidate_off_shift_falls_back_to_queue(): void
    {
        $this->lead(91); $this->api->history[91] = [3];
        $this->shiftToday('00:00', '23:59', [1, 2], [3]);            // 3 — выходной по графику
        $r = $this->svc()->distribute($this->payload(91, $this->rule(['check_history' => true, 'check_schedule' => true])));
        self::assertSame(1, $r['assigned_to_id']);
    }

    public function test_out_of_shift_with_queue_enabled_is_queued_not_assigned(): void
    {
        $this->lead(101);
        $this->shiftToday('00:00', '00:01');                           // смена уже закончилась
        $rule = $this->rule(['check_schedule' => true, 'queue_when_unavailable' => true]);
        self::assertNull($this->svc()->distribute($this->payload(101, $rule)));
        self::assertContains('queued', $this->reasons());
        $p = (new PendingStorage())->all($this->acc);
        self::assertSame('waiting', $p['101']['type']);
        self::assertSame([], $this->api->updates, 'ответственный не должен меняться при постановке в очередь');
    }

    public function test_queued_lead_is_not_requeued_and_keeps_order(): void
    {
        $this->lead(111); $this->shiftToday('00:00', '00:01');
        $rule = $this->rule(['check_schedule' => true, 'queue_when_unavailable' => true]);
        $this->svc()->distribute($this->payload(111, $rule));
        $t1 = (new PendingStorage())->all($this->acc)['111']['queued_at'];
        sleep(1);
        $this->svc()->distribute($this->payload(111, $rule, ['force' => true]));
        self::assertSame($t1, (new PendingStorage())->all($this->acc)['111']['queued_at']);
    }

    public function test_no_matching_rule_logs_skipped_no_rule(): void
    {
        $this->lead(121);
        self::assertNull($this->svc()->distribute($this->payload(121, $this->rule(['pipeline_id' => 999]))));
        self::assertContains('skipped_no_rule', $this->reasons());
    }

    public function test_workload_picks_least_loaded(): void
    {
        $this->lead(131); $this->api->workload = [1 => 5, 2 => 1, 3 => 3];
        self::assertSame(2, $this->svc()->distribute($this->payload(131, $this->rule(), ['distribution_method' => 'workload']))['assigned_to_id']);
    }

    public function test_lead_guard_lock_blocks_parallel_processing(): void
    {
        $g = new LeadGuard(); $h = $g->acquire($this->acc, 141, false);
        self::assertNotNull($h);
        $this->lead(141);
        self::assertNull($this->svc()->distribute($this->payload(141, $this->rule())));
        self::assertContains('skipped_duplicate', $this->reasons());
        $g->release($h);
    }
}
