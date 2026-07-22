<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\AmoCRM\ApiClient;
use DealDist\Distribution\DistributionLog;
use DealDist\Distribution\DistributionService;
use DealDist\Distribution\QueueStorage;
use DealDist\Distribution\ScheduleChecker;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DistributeController
{
    public function __construct(private readonly Logger $logger) {}

    public function distribute(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = (array) $request->getParsedBody();

        // Validate required fields
        $accountId = $payload['account_id'] ?? null;
        $leadId    = isset($payload['lead_id']) ? (int) $payload['lead_id'] : null;

        if (!$accountId || !$leadId) {
            return $this->json($response, ['error' => 'account_id and lead_id are required'], 400);
        }

        try {
            $apiClient   = new ApiClient($this->logger);
            $service     = new DistributionService(
                $apiClient,
                new QueueStorage(),
                new ScheduleChecker(),
                new DistributionLog(),
                $this->logger,
            );

            $result = $service->distribute($payload);

            if ($result === null) {
                return $this->json($response, ['status' => 'skipped', 'reason' => 'no_matching_rule_or_available_manager']);
            }

            return $this->json($response, [
                'status'      => 'ok',
                'assigned_to' => $result['assigned_to_name'],
                'user_id'     => $result['assigned_to_id'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Distribution error', ['exception' => $e->getMessage()]);
            return $this->json($response, ['error' => 'internal_error', 'detail' => $e->getMessage()], 500);
        }
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status);
    }
}
