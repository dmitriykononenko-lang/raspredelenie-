<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\AmoCRM\ApiClient;
use DealDist\Config\SettingsStorage;
use DealDist\Distribution\DistributionService;
use DealDist\Distribution\DistributionLog;
use DealDist\Distribution\QueueStorage;
use DealDist\Distribution\ScheduleChecker;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles incoming webhooks from AmoCRM.
 *
 * AmoCRM sends application/x-www-form-urlencoded POST requests.
 * Supported events:
 *   - leads[add][]      — new lead created
 *   - leads[status][]   — lead moved to another pipeline stage
 *
 * Webhook URL to configure in AmoCRM:
 *   POST https://your-server.com/webhook/leads
 */
class WebhookController
{
    public function __construct(private readonly Logger $logger) {}

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $secret = $_ENV['WIDGET_SECRET'] ?? '';
        if ($secret !== '') {
            $headerSecret = $request->getHeaderLine('X-Widget-Secret');
            if (!hash_equals($secret, $headerSecret)) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }
        }

        $body = $request->getParsedBody() ?? [];

        // AmoCRM may nest data under leads[add] or leads[status]
        $addedLeads    = $body['leads']['add']    ?? [];
        $statusedLeads = $body['leads']['status'] ?? [];

        $leads = array_merge(
            $this->normalizeLeads($addedLeads,    'add'),
            $this->normalizeLeads($statusedLeads, 'status'),
        );

        if (empty($leads)) {
            return $this->json($response, ['status' => 'no_leads']);
        }

        // account_id comes from the query string we append when registering the webhook
        $accountId = (string) ($request->getQueryParams()['account_id'] ?? '');
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id query param required'], 400);
        }

        $settings = (new SettingsStorage())->load($accountId);
        $results  = [];

        $service = new DistributionService(
            new ApiClient($this->logger),
            new QueueStorage(),
            new ScheduleChecker(),
            new DistributionLog(),
            $this->logger,
        );

        foreach ($leads as $lead) {
            $payload = [
                'account_id'          => $accountId,
                'lead_id'             => (int) $lead['id'],
                'pipeline_id'         => isset($lead['pipeline_id'])  ? (int) $lead['pipeline_id']  : null,
                'stage_id'            => isset($lead['status_id'])    ? (int) $lead['status_id']    : null,
                'distribution_method' => $settings['distribution_method'] ?? 'round_robin',
                'rules'               => $settings['rules'] ?? [],
                'dp_settings'         => [],
                'event'               => $lead['_event'],
            ];

            try {
                $result      = $service->distribute($payload);
                $results[]   = ['lead_id' => $lead['id'], 'result' => $result ?? 'skipped'];
            } catch (\Throwable $e) {
                $this->logger->error('Webhook distribution error', [
                    'lead_id' => $lead['id'],
                    'error'   => $e->getMessage(),
                ]);
                $results[] = ['lead_id' => $lead['id'], 'error' => $e->getMessage()];
            }
        }

        return $this->json($response, ['status' => 'ok', 'results' => $results]);
    }

    /** AmoCRM sends each lead as a sub-array; normalize to a flat list */
    private function normalizeLeads(array $leads, string $event): array
    {
        return array_map(static function (array $lead) use ($event): array {
            $lead['_event'] = $event;
            return $lead;
        }, $leads);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
