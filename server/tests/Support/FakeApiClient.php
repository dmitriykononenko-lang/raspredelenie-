<?php
declare(strict_types=1);
namespace DealDist\Tests\Support;

use DealDist\AmoCRM\ApiClient;
use Monolog\Logger;

/** ApiClient без сети: сделки, история клиента и запись ответственных — в памяти. */
final class FakeApiClient extends ApiClient
{
    public array $leads = [];          // id => lead array
    public array $history = [];        // lead id => candidates
    public array $updates = [];        // [[lead, user], ...]
    public array $workload = [];       // user => open leads count
    public ?array $activeUsers = null; // null = фильтр по активности выключен
    public array $rejectUsers = [];    // amoCRM отвечает NotSupportedChoice на этих

    public function __construct() { parent::__construct(new Logger('test')); }
    public function getLead(string $accountId, int $leadId, array $with = []): array
    {
        if (!isset($this->leads[$leadId])) throw new \RuntimeException("Lead not found: $leadId");
        return $this->leads[$leadId];
    }
    public function getActiveUserIds(string $accountId): ?array { return $this->activeUsers; }
    public function forgetActiveUsers(string $accountId): void {}
    public function updateLeadResponsible(string $accountId, int $leadId, int $responsibleUserId): void
    {
        if (in_array($responsibleUserId, $this->rejectUsers, true)) {
            throw new \RuntimeException('amoCRM 400 PATCH /leads: {"validation-errors":[{"errors":[{"code":"NotSupportedChoice","path":"responsible_user_id"}]}]}', 400);
        }
        $this->updates[] = [$leadId, $responsibleUserId];
        if (isset($this->leads[$leadId])) $this->leads[$leadId]['responsible_user_id'] = $responsibleUserId;
    }
    public function getHistoryCandidates(string $accountId, int $leadId, ?array $leadData = null, int $maxLeads = 50): array
    { return $this->history[$leadId] ?? []; }
    public function reassignOpenTasks(string $accountId, int $leadId, int $newUserId): int { return 0; }
    public function getOpenLeadsCountByUser(string $accountId, array $userIds): array
    { $o = []; foreach ($userIds as $u) $o[$u] = $this->workload[$u] ?? 0; return $o; }
}
