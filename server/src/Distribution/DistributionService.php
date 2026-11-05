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
        private readonly StatusStorage   $statusStorage,
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

        $force      = !empty($payload['force']);   // ручной запуск: без окна идемпотентности
        $eventKey   = isset($payload['event_key']) ? (string) $payload['event_key'] : null;
        $source     = (string) ($payload['event'] ?? 'webhook');
        $journal    = new OperationJournal();

        $this->logger->info('Distribute called', compact('accountId', 'leadId', 'pipelineId', 'stageId', 'method', 'force'));

        // ── 0. Защита от двойного распределения (P0) ───────────────────────────
        // amoCRM повторяет вебхук, если ответ не пришёл быстро; повторы приходят
        // параллельно и раньше крутили очередь по 5–7 раз на одну сделку.
        $guard = new LeadGuard();
        $lock  = $guard->acquire($accountId, $leadId, $force);
        if ($lock === null) {
            $this->logger->info('Lead is already being processed — skipping duplicate', compact('leadId'));
            $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_duplicate');
            return null;
        }
        // P0-2: журнал операций — повтор того же события (тот же этап + updated_at)
        // спустя минуты/часы тоже дубль, без единого запроса к amoCRM.
        if (!$force && $eventKey !== null && ($prev = $journal->seen($accountId, $leadId, $eventKey)) !== null) {
            $guard->release($lock);
            $this->logger->info('Duplicate event by journal key — skipping', ['lead_id' => $leadId, 'key' => $eventKey, 'prev' => $prev]);
            $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_duplicate', ['event_key' => $eventKey]);
            return null;
        }
        if (!$force && $guard->recentlyAssigned($accountId, $leadId, $stageId)) {
            $guard->release($lock);
            $this->logger->info('Lead was assigned recently — skipping duplicate', compact('leadId'));
            $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_duplicate');
            return null;
        }

        try {
            $res = $this->doDistribute($guard, $payload, $accountId, $leadId, $pipelineId, $stageId, $method, $rules, $dpSettings);
            if ($eventKey !== null) {
                $journal->mark($accountId, $leadId, $eventKey, $res ? 'done' : 'skipped', $res['assigned_to_id'] ?? null, $source);
            }
            return $res;
        } catch (\Throwable $e) {
            // P0-1: ошибка API/сети — видна администратору в истории, не только в логах.
            $this->logger->error('Distribution failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);
            $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'error', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            if ($eventKey !== null) {
                $journal->mark($accountId, $leadId, $eventKey, 'failed', null, $source, $e->getMessage());
            }
            throw $e;
        } finally {
            $guard->release($lock);
        }
    }

    /** Основная логика; вызывается только под блокировкой сделки. */
    private function doDistribute(
        LeadGuard $guard,
        array $payload,
        string $accountId,
        int $leadId,
        ?int $pipelineId,
        ?int $stageId,
        string $method,
        array $rules,
        array $dpSettings,
    ): ?array {
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

        // ── 2b. Отключённые / удалённые в amoCRM пользователи не получают сделки ──
        // Реальный кейс koagency (13.09–12.10): уволенный сотрудник остался в правиле,
        // amoCRM отвечал NotSupportedChoice, и каждая N-я заявка оставалась без распределения.
        $activeIds = $this->apiClient->getActiveUserIds($accountId);
        if (is_array($activeIds) && $activeIds) {
            $inactive = array_values(array_diff($managerIds, $activeIds));
            if ($inactive) {
                $this->logger->warning('Inactive users in rule — skipped', ['lead_id' => $leadId, 'inactive' => $inactive]);
                $managerIds = array_values(array_intersect($managerIds, $activeIds));
            }
            if (empty($managerIds)) {
                $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_no_managers', ['inactive' => $inactive]);
                return null;
            }
        }

        // ── 3. История клиента: тот же клиент → тот же менеджер ─────────────
        // Кандидаты — ответственные по прошлым сделкам контакта/компании (свежие
        // первыми). Берём первого, кто (а) входит в менеджеров правила,
        // (б) включён в распределении (online) и (в) при включённом графике —
        // сейчас на смене. «Свой» менеджер вне смены сделку не получает —
        // она уходит по очереди тем, кто на смене (решение Дмитрия, 08.09).
        if (!empty($rule['check_history'])) {
            $candidates = [];
            try {
                $candidates = $this->apiClient->getHistoryCandidates($accountId, $leadId, $leadData);
            } catch (\Throwable $e) {
                $this->logger->warning('History lookup failed — falling back to queue', ['lead_id' => $leadId, 'error' => $e->getMessage()]);
            }
            foreach ($candidates as $existingId) {
                if (!in_array($existingId, $managerIds, true)) {
                    continue;
                }
                if (!$this->statusStorage->isOnline($accountId, $existingId)) {
                    continue;
                }
                if (!empty($rule['check_schedule']) && !$this->scheduleChecker->isAvailable($accountId, $existingId)) {
                    $this->logger->info('History candidate is off shift — skipping', ['lead_id' => $leadId, 'user_id' => $existingId]);
                    continue;
                }
                $this->logger->info('History match — assigning to existing responsible', [
                    'lead_id'    => $leadId,
                    'user_id'    => $existingId,
                    'candidates' => $candidates,
                ]);
                $this->apiClient->updateLeadResponsible($accountId, $leadId, $existingId);
                try { $this->apiClient->reassignOpenTasks($accountId, $leadId, $existingId); } catch (\Throwable $t) {}
                $this->log->record($accountId, $leadId, $pipelineId, $stageId, $existingId, $method, 'history_match');
                $guard->markAssigned($accountId, $leadId, $stageId);
                return ['assigned_to_id' => $existingId, 'assigned_to_name' => (string) $existingId];
            }
            if ($candidates) {
                $this->logger->info('History candidates found but none eligible — using queue', ['lead_id' => $leadId, 'candidates' => $candidates]);
            }
        }

        // ── 4. Online-status filter (приоритетно: офлайн не получает сделок) ───
        $availableManagers = array_values(array_filter(
            $managerIds,
            fn(int $id) => $this->statusStorage->isOnline($accountId, $id)
        ));

        $offMode = (string) ($rule['offshift_mode'] ?? (!empty($rule['queue_when_unavailable']) ? 'queue' : 'skip'));
        if (empty($availableManagers) && $offMode === 'assign_anyway') {
            // «Распределить всё равно»: никто не включён — берём всех из правила
            $availableManagers = $managerIds;
            $this->logger->info('Off-shift: assign_anyway (all offline) — using full pool', ['lead_id' => $leadId]);
        }
        if (empty($availableManagers)) {
            $this->logger->warning('All managers are offline — skipping', [
                'lead_id'     => $leadId,
                'manager_ids' => $managerIds,
            ]);
            if ($this->enqueueWaiting($accountId, $leadId, $pipelineId, $stageId, $rule, $managerIds, $method, 'offline', isset($leadData['responsible_user_id']) ? (int) $leadData['responsible_user_id'] : null)) {
                return null;
            }
            $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_offline');
            return null;
        }

        // ── 5. Filter by work schedule ────────────────────────────────────────
        if (!empty($rule['check_schedule'])) {
            $availableManagers = array_values(array_filter(
                $availableManagers,
                fn(int $id) => $this->scheduleChecker->isAvailable($accountId, $id)
            ));

            if (empty($availableManagers) && $offMode === 'assign_anyway') {
                // «Распределить всё равно»: никто не на смене — среди включённых, график игнорируем;
                // offshift_only_next_shift — только среди тех, у кого есть смена завтра
                $pool = array_values(array_filter($managerIds, fn(int $id) => $this->statusStorage->isOnline($accountId, $id)));
                if (!empty($rule['offshift_only_next_shift'])) {
                    $tomorrow = new \DateTime('tomorrow 12:00', new \DateTimeZone('Europe/Moscow'));
                    $next = array_values(array_filter($pool, fn(int $id) => $this->scheduleChecker->isAvailableAt($accountId, $id, $tomorrow)));
                    if ($next) { $pool = $next; }
                }
                $availableManagers = $pool;
                $this->logger->info('Off-shift: assign_anyway — ignoring schedule', ['lead_id' => $leadId, 'pool' => $pool]);
            }
            if (empty($availableManagers)) {
                $this->logger->warning('All managers are outside working hours — skipping', [
                    'lead_id'     => $leadId,
                    'manager_ids' => $managerIds,
                ]);
                if ($this->enqueueWaiting($accountId, $leadId, $pipelineId, $stageId, $rule, $managerIds, $method, 'schedule', isset($leadData['responsible_user_id']) ? (int) $leadData['responsible_user_id'] : null)) {
                    return null;
                }
                $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'skipped_schedule');
                return null;
            }
        }

        // ── 6. Pick manager by strategy ───────────────────────────────────────
        $chosenId = match ($method) {
            'workload' => $this->pickByWorkload($accountId, $availableManagers),
            default    => $this->pickRoundRobin($accountId, $rule, $availableManagers),
        };

        // ── 7. Assign ─────────────────────────────────────────────────────────
        // Если amoCRM отказал (пользователь отключён после кэша) — берём следующего.
        $tried = [];
        while (true) {
            try {
                $this->apiClient->updateLeadResponsible($accountId, $leadId, $chosenId);
                break;
            } catch (\RuntimeException $e) {
                if (!$this->isInactiveUserError($e)) {
                    throw $e;
                }
                $this->apiClient->forgetActiveUsers($accountId);
                $tried[] = $chosenId;
                $this->logger->warning('amoCRM rejected responsible (inactive user) — trying next', ['lead_id' => $leadId, 'user_id' => $chosenId]);
                $this->log->record($accountId, $leadId, $pipelineId, $stageId, $chosenId, $method, 'skipped_inactive_user');
                $availableManagers = array_values(array_diff($availableManagers, $tried));
                if (empty($availableManagers) || count($tried) >= 5) {
                    throw $e;
                }
                $chosenId = match ($method) {
                    'workload' => $this->pickByWorkload($accountId, $availableManagers),
                    default    => $this->pickRoundRobin($accountId, $rule, $availableManagers),
                };
            }
        }
        try { $this->apiClient->reassignOpenTasks($accountId, $leadId, $chosenId); } catch (\Throwable $t) {}

        // ── 8. Log ────────────────────────────────────────────────────────────
        $this->log->record($accountId, $leadId, $pipelineId, $stageId, $chosenId, $method, 'assigned');
        $guard->markAssigned($accountId, $leadId, $stageId);

        $this->logger->info('Deal distributed', [
            'lead_id' => $leadId,
            'user_id' => $chosenId,
            'method'  => $method,
        ]);

        // ── 9. Запланировать перераспределение, если включено в правиле ────
        $redistributeAfter = (int) ($rule['redistribute_after'] ?? 0);
        $pending = new PendingStorage();
        if ($redistributeAfter > 0) {
            $pending->put($accountId, [
                'lead_id'        => $leadId,
                'pipeline_id'    => $pipelineId,
                'stage_id'       => $stageId,
                'manager_id'     => $chosenId,
                'managers'       => $managerIds,
                'method'         => $method,
                'check_schedule' => !empty($rule['check_schedule']),
                'attempts'       => 0,
                'max'            => (int) ($rule['redistribute_max'] ?? 3),
                'after_sec'      => $redistributeAfter * 60,
                'next_at'        => time() + $redistributeAfter * 60,
            ]);
        } else {
            $pending->remove($accountId, $leadId);
        }

        return ['assigned_to_id' => $chosenId, 'assigned_to_name' => (string) $chosenId];
    }


    /**
     * Очередь ожидания (v1.4): заявка, которую сейчас некому отдать (все вне
     * смены / все офлайн), не теряется, а ждёт — тик раздаст её, как только
     * у правила появится менеджер на смене и online. Включается в правиле
     * флагом queue_when_unavailable (по умолчанию выкл — поведение прежнее).
     */
    private function enqueueWaiting(string $accountId, int $leadId, ?int $pipelineId, ?int $stageId, array $rule, array $managerIds, string $method, string $why, ?int $respAtQueue = null): bool
    {
        if (empty($rule['queue_when_unavailable'])) {
            return false;
        }
        $pending = new PendingStorage();
        $existing = $pending->all($accountId)[(string) $leadId] ?? null;
        if ($existing && ($existing['type'] ?? '') === 'waiting') {
            return true; // уже в очереди — порядок (queued_at) не сбиваем
        }
        $pending->put($accountId, [
            'type'           => 'waiting',
            'lead_id'        => $leadId,
            'pipeline_id'    => $pipelineId,
            'stage_id'       => $stageId,
            'managers'       => $managerIds,
            'method'         => $method,
            'check_schedule' => !empty($rule['check_schedule']),
            'check_history'  => !empty($rule['check_history']),
            'manual_change'  => (string) ($rule['manual_change'] ?? 'allow'),   // allow | respect
            'resp_at_queue'  => $respAtQueue,
            'not_before'     => (string) ($rule['queue_release_after'] ?? ''),   // "HH:MM" или ''
            'max_wait_sec'   => (int) ($rule['queue_max_wait_hours'] ?? 36) * 3600,
            'queued_at'      => time(),
            'reason'         => $why,
            'next_at'        => time() + 60,
        ]);
        $this->log->record($accountId, $leadId, $pipelineId, $stageId, null, $method, 'queued');
        $this->logger->info('Lead queued (nobody available now)', ['lead_id' => $leadId, 'why' => $why]);
        return true;
    }

    private function isInactiveUserError(\Throwable $e): bool
    {
        if (str_contains($e->getMessage(), 'NotSupportedChoice')) {
            return true;
        }
        $prev = $e->getPrevious();
        if ($prev instanceof \GuzzleHttp\Exception\RequestException && $prev->hasResponse()) {
            $body = (string) $prev->getResponse()->getBody();
            return str_contains($body, 'NotSupportedChoice') && str_contains($body, 'responsible_user_id');
        }
        return false;
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
