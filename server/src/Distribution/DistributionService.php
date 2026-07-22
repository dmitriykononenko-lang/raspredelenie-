<?php

declare(strict_types=1);

namespace DealDist\Distribution;

use DealDist\AmoCRM\ApiClient;
use Monolog\Logger;

/**
 * Core deal distribution logic.
 *
 * Steps for each incoming lead:
 *   1. Load full lead data from AmoCRM (for filter evaluation).
 *   2. Find the matching rule (pipeline + stage + deal filters).
 *   3. Optionally check contact/company history → reuse existing manager.
 *   4. Filter managers by work schedule.
 *   5. Pick manager via round-robin or workload strategy.
 *   6. Update lead's responsible_user_id via AmoCRM API.
 *   7. Write an entry to the distribution log.
 */
class DistributionService
{
    public function __construct(
        private readonly ApiClient       $apiClient,
        private readonly QueueStorage    $queueStorage,
        private readonly ScheduleChecker $scheduleChecker,
        private readonly DistributionLog $log,
        private readonly Logger          $logger,
    ) {}

    /**
     * @param array $payload {
     *   account_id:          string,
     *   lead_id:             int,
     *   pipeline_id:         int|null,
     *   stage_id:            int|null,
     *   distribution_method: 'round_robin'|'workload',
     *   rules:               array,
     *   dp_settings:         array,
     *   event:               string  (optional: 'add'|'status')
     * }
     * @return array{assigned_to_id: int, assigned_to_name: string}|null
     */
    public function distribute(array $payload): ?array
    {
        $accountId  = (string) $payload['account_id'];
        $leadId     = (int)    $payload['lead_id'];
        $pipelineId = isset($payload['pipeline_id']) ? (int) $payload['pipeline_id'] : null;
        $stageId    = isset($payload['stage_id'])    ? (int) $payload['stage_id']    : null;
        $method     = $payload['distribution_method'] ?? 'round_robin';
        $rules      = $payload['rules']               ?? [];
        $dpSettings = $payload['dp_settings']         ?? [];

        $this->logger->info('Distribute called', compact('accountId', 'leadId', 'pipelineId', 'stageId', 'method'));

        // ── 1. Load full lead data (needed for filter evaluation) ─────────────
        $leadData = $this->apiClient->getLead($accountId, $leadId, ['tags', 'contacts', 'companies']);

        // Use API-returned pipeline/stage if not provided in payload
        $pipelineId = $pipelineId ?? (isset($leadData['pipeline_id']) ? (int) $leadData['pipeline_id'] : null);
        $stageId    = $stageId    ?? (isset($leadData['status_id'])   ? (int) $leadData['status_id']   : null);

        // ── 2. Find matching rule ─────────────────────────────────────────────
        $filter = new DealFilter();
        $rule   = $this->findMatchingRule($rules, $pipelineId, $stageId, $dpSettings, $leadData, $filter);

        if ($rule === null) {
            $this->logger->info('No matching rule found', compact('leadId', 'pipelineId', 'stageId'));
            $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_no_rule');
            return null;
        }

        $managerIds = array_values(array_map('intval', array_column($rule['managers'] ?? [], 'id')));
        if (empty($managerIds)) {
            $this->logger->warning('Rule matched but managers list is empty', ['rule' => $rule]);
            $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_no_managers');
            return null;
        }

        // ── 3. Check contact/company history ──────────────────────────────────
        if (!empty($rule['check_history'])) {
            $existingId = $this->apiClient->getExistingResponsible($accountId, $leadId, $leadData);
            if ($existingId !== null && in_array($existingId, $managerIds, true)) {
                $this->logger->info('History match — assigning to existing responsible', [
                    'lead_id' => $leadId,
                    'user_id' => $existingId,
                ]);
                $this->apiClient->updateLeadResponsible($accountId, $leadId, $existingId);
                $this->log->record($accountId, $leadId, $pipelineId, $stageId, $existingId, $method, 'history_match');
                return ['assigned_to_id' => $existingId, 'assigned_to_name' => (string) $existingId];
            }
        }

        // ── 4. Filter by work schedule ────────────────────────────────────────
        $availableManagers = $managerIds;
        if (!empty($rule['check_schedule'])) {
            $availableManagers = array_values(array_filter(
                $managerIds,
                fn(int $id) => $this->scheduleChecker->isAvailable($accountId, $id)
            ));

            if (empty($availableManagers)) {
                $this->logger->warning('All managers are outside working hours — skipping', [
                    'lead_id'     => $leadId,
                    'manager_ids' => $managerIds,
                ]);
                $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_schedule');
                return null;
            }
        }

        // ── 5. Pick manager by strategy ───────────────────────────────────────
        $chosenId = match ($method) {
            'workload' => $this->pickByWorkload($accountId, $availableManagers),
            default    => $this->pickRoundRobin($accountId, $rule, $availableManagers),
        };

        // ── 6. Assign ─────────────────────────────────────────────────────────
        $this->apiClient->updateLeadResponsible($accountId, $leadId, $chosenId);

        // ── 7. Log ────────────────────────────────────────────────────────────
        $this->log->record($accountId, $leadId, $pipelineId, $stageId, $chosenId, $method, 'assigned');

        $this->logger->info('Deal distributed', [
            'lead_id' => $leadId,
            'user_id' => $chosenId,
            'method'  => $method,
        ]);

        return ['assigned_to_id' => $chosenId, 'assigned_to_name' => (string) $chosenId];
    }

    // ── Rule matching ─────────────────────────────────────────────────────────

    private function findMatchingRule(
        array     $rules,
        ?int      $pipelineId,
        ?int      $stageId,
        array     $dpSettings,
        array     $leadData,
        DealFilter $filter,
    ): ?array {
        // Digital Pipeline settings have highest priority
        if (!empty($dpSettings['managers'])) {
            return $dpSettings;
        }

        foreach ($rules as $rule) {
            $rulePipelineId = isset($rule['pipeline_id']) ? (int) $rule['pipeline_id'] : null;
            $ruleStageId    = isset($rule['stage_id'])    ? (int) $rule['stage_id']    : null;

            if ($rulePipelineId !== null && $rulePipelineId !== $pipelineId) {
                continue;
            }
            if ($ruleStageId !== null && $ruleStageId !== $stageId) {
                continue;
            }

            // Apply deal-level filter conditions
            if (!$filter->matches($leadData, $rule['filters'] ?? [])) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    // ── Strategies ────────────────────────────────────────────────────────────

    private function pickRoundRobin(string $accountId, array $rule, array $managerIds): int
    {
        $ruleHash = md5(json_encode([
            'pipeline_id' => $rule['pipeline_id'] ?? null,
            'stage_id'    => $rule['stage_id']    ?? null,
        ]));

        return $this->queueStorage->getNextManager($accountId, $ruleHash, $managerIds);
    }

    private function pickByWorkload(string $accountId, array $managerIds): int
    {
        $counts = $this->apiClient->getOpenLeadsCountByUser($accountId, $managerIds);
        asort($counts);
        return (int) array_key_first($counts);
    }
}
