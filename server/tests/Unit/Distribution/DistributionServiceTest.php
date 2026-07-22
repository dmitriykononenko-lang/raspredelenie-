<?php

declare(strict_types=1);

namespace DealDist\Tests\Unit\Distribution;

use DealDist\AmoCRM\ApiClient;
use DealDist\Distribution\DistributionLog;
use DealDist\Distribution\DistributionService;
use DealDist\Distribution\QueueStorage;
use DealDist\Distribution\ScheduleChecker;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DealDist\Distribution\DistributionService
 */
class DistributionServiceTest extends TestCase
{
    private ApiClient&MockObject       $apiClient;
    private QueueStorage&MockObject    $queueStorage;
    private ScheduleChecker&MockObject $scheduleChecker;
    private DistributionLog&MockObject $log;
    private DistributionService        $service;

    protected function setUp(): void
    {
        $this->apiClient       = $this->createMock(ApiClient::class);
        $this->queueStorage    = $this->createMock(QueueStorage::class);
        $this->scheduleChecker = $this->createMock(ScheduleChecker::class);
        $this->log             = $this->createMock(DistributionLog::class);

        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $this->service = new DistributionService(
            $this->apiClient,
            $this->queueStorage,
            $this->scheduleChecker,
            $this->log,
            $logger,
        );
    }

    // ── No matching rule ──────────────────────────────────────────────────────

    public function testReturnsNullWhenNoRuleMatches(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        $this->log->expects($this->once())
            ->method('record')
            ->with('acc1', 100, self::anything(), self::anything(), null, 'round_robin', 'skipped_no_rule');

        $result = $this->service->distribute($this->payload(rules: []));

        $this->assertNull($result);
    }

    public function testReturnsNullWhenRulePipelineDoesNotMatch(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData(pipelineId: 999));

        $result = $this->service->distribute($this->payload(
            pipelineId: 999,
            rules: [$this->rule(pipelineId: 111)]
        ));

        $this->assertNull($result);
    }

    public function testReturnsNullWhenRuleStageDoesNotMatch(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData(stageId: 888));

        $result = $this->service->distribute($this->payload(
            stageId: 888,
            rules: [$this->rule(stageId: 222)]
        ));

        $this->assertNull($result);
    }

    // ── Round-robin distribution ──────────────────────────────────────────────

    public function testRoundRobinAssignsManager(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        $this->queueStorage
            ->expects($this->once())
            ->method('getNextManager')
            ->willReturn(201);

        $this->apiClient
            ->expects($this->once())
            ->method('updateLeadResponsible')
            ->with('acc1', 100, 201);

        $this->log->expects($this->once())
            ->method('record')
            ->with('acc1', 100, self::anything(), self::anything(), 201, 'round_robin', 'assigned');

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(managers: [201, 202, 203])]
        ));

        $this->assertSame(201, $result['assigned_to_id']);
    }

    // ── Workload distribution ─────────────────────────────────────────────────

    public function testWorkloadPicksManagerWithFewestLeads(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        $this->apiClient
            ->method('getOpenLeadsCountByUser')
            ->willReturn([201 => 10, 202 => 3, 203 => 7]);

        $this->apiClient
            ->expects($this->once())
            ->method('updateLeadResponsible')
            ->with('acc1', 100, 202); // fewest leads

        $result = $this->service->distribute($this->payload(
            method: 'workload',
            rules:  [$this->rule(managers: [201, 202, 203])]
        ));

        $this->assertSame(202, $result['assigned_to_id']);
    }

    // ── History check ─────────────────────────────────────────────────────────

    public function testHistoryMatchAssignsExistingResponsible(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        $this->apiClient
            ->method('getExistingResponsible')
            ->willReturn(202); // existing responsible

        $this->apiClient
            ->expects($this->once())
            ->method('updateLeadResponsible')
            ->with('acc1', 100, 202);

        $this->log->expects($this->once())
            ->method('record')
            ->with('acc1', 100, self::anything(), self::anything(), 202, 'round_robin', 'history_match');

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(managers: [201, 202, 203], checkHistory: true)]
        ));

        $this->assertSame(202, $result['assigned_to_id']);
    }

    public function testHistoryCheckSkipsIfExistingManagerNotInList(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        $this->apiClient
            ->method('getExistingResponsible')
            ->willReturn(999); // not in managers list

        $this->queueStorage->method('getNextManager')->willReturn(201);

        $this->apiClient
            ->expects($this->once())
            ->method('updateLeadResponsible')
            ->with('acc1', 100, 201);

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(managers: [201, 202], checkHistory: true)]
        ));

        $this->assertSame(201, $result['assigned_to_id']);
    }

    public function testHistoryCheckSkipsIfNoExistingResponsible(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        $this->apiClient
            ->method('getExistingResponsible')
            ->willReturn(null);

        $this->queueStorage->method('getNextManager')->willReturn(201);

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(managers: [201, 202], checkHistory: true)]
        ));

        $this->assertSame(201, $result['assigned_to_id']);
    }

    // ── Schedule check ────────────────────────────────────────────────────────

    public function testScheduleFilterExcludesUnavailableManagers(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        // 201 is off duty, 202 is available
        $this->scheduleChecker
            ->method('isAvailable')
            ->willReturnMap([
                ['acc1', 201, false],
                ['acc1', 202, true],
            ]);

        $this->queueStorage
            ->expects($this->once())
            ->method('getNextManager')
            ->with('acc1', self::anything(), [202]) // 201 filtered out
            ->willReturn(202);

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(managers: [201, 202], checkSchedule: true)]
        ));

        $this->assertSame(202, $result['assigned_to_id']);
    }

    public function testReturnsNullWhenAllManagersAreOffDuty(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        $this->scheduleChecker
            ->method('isAvailable')
            ->willReturn(false);

        $this->log->expects($this->once())
            ->method('record')
            ->with('acc1', 100, self::anything(), self::anything(), null, 'round_robin', 'skipped_schedule');

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(managers: [201, 202], checkSchedule: true)]
        ));

        $this->assertNull($result);
    }

    // ── Deal filters ──────────────────────────────────────────────────────────

    public function testDealFilterSkipsRuleWhenLeadDoesNotMatch(): void
    {
        // Lead price 5000 does not satisfy budget_min: 10000
        $this->apiClient->method('getLead')->willReturn($this->leadData(price: 5_000));

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(filters: ['budget_min' => 10_000])]
        ));

        $this->assertNull($result);
    }

    public function testDealFilterPassesAndAssigns(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData(price: 50_000));
        $this->queueStorage->method('getNextManager')->willReturn(201);

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(filters: ['budget_min' => 10_000, 'budget_max' => 100_000])]
        ));

        $this->assertSame(201, $result['assigned_to_id']);
    }

    // ── Pipeline/stage wildcard ───────────────────────────────────────────────

    public function testRuleWithNoPipelineMatchesAnyPipeline(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData(pipelineId: 555));
        $this->queueStorage->method('getNextManager')->willReturn(201);

        $result = $this->service->distribute($this->payload(
            pipelineId: 555,
            rules: [$this->rule(pipelineId: null)] // null = any pipeline
        ));

        $this->assertSame(201, $result['assigned_to_id']);
    }

    public function testRuleWithNoStageMatchesAnyStage(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData(stageId: 777));
        $this->queueStorage->method('getNextManager')->willReturn(201);

        $result = $this->service->distribute($this->payload(
            stageId: 777,
            rules: [$this->rule(stageId: null)] // null = any stage
        ));

        $this->assertSame(201, $result['assigned_to_id']);
    }

    // ── Digital Pipeline settings take priority ───────────────────────────────

    public function testDpSettingsOverrideRules(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());
        $this->queueStorage->method('getNextManager')->willReturn(301);

        $result = $this->service->distribute($this->payload(
            rules:      [$this->rule(managers: [201])],
            dpSettings: ['managers' => [['id' => 301], ['id' => 302]]]
        ));

        $this->assertSame(301, $result['assigned_to_id']);
    }

    // ── Empty managers list ───────────────────────────────────────────────────

    public function testReturnsNullWhenManagersListEmpty(): void
    {
        $this->apiClient->method('getLead')->willReturn($this->leadData());

        $this->log->expects($this->once())
            ->method('record')
            ->with('acc1', 100, self::anything(), self::anything(), null, 'round_robin', 'skipped_no_managers');

        $result = $this->service->distribute($this->payload(
            rules: [$this->rule(managers: [])]
        ));

        $this->assertNull($result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function payload(
        string  $accountId  = 'acc1',
        int     $leadId     = 100,
        ?int    $pipelineId = 111,
        ?int    $stageId    = 222,
        string  $method     = 'round_robin',
        array   $rules      = [],
        array   $dpSettings = [],
    ): array {
        return [
            'account_id'          => $accountId,
            'lead_id'             => $leadId,
            'pipeline_id'         => $pipelineId,
            'stage_id'            => $stageId,
            'distribution_method' => $method,
            'rules'               => $rules,
            'dp_settings'         => $dpSettings,
        ];
    }

    private function rule(
        ?int   $pipelineId    = 111,
        ?int   $stageId       = 222,
        array  $managers      = [201, 202],
        bool   $checkHistory  = false,
        bool   $checkSchedule = false,
        array  $filters       = [],
    ): array {
        return [
            'pipeline_id'    => $pipelineId,
            'stage_id'       => $stageId,
            'managers'       => array_map(fn(int $id): array => ['id' => $id], $managers),
            'check_history'  => $checkHistory,
            'check_schedule' => $checkSchedule,
            'filters'        => $filters,
        ];
    }

    private function leadData(
        int  $pipelineId = 111,
        int  $stageId    = 222,
        int  $price      = 0,
    ): array {
        return [
            'id'          => 100,
            'name'        => 'Test Lead',
            'price'       => $price,
            'pipeline_id' => $pipelineId,
            'status_id'   => $stageId,
            '_embedded'   => ['tags' => [], 'contacts' => [], 'companies' => []],
            'custom_fields_values' => [],
        ];
    }
}
