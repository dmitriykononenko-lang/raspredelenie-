<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Distribution\QueueStorage;
use DealDist\Distribution\DistributionLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Inspect and manage queue state and distribution history.
 *
 * GET  /api/queue                   — list all queue states for the account
 * POST /api/queue/{ruleHash}/reset  — reset a specific rule's queue pointer to 0
 * GET  /api/log                     — last N distribution log entries
 */
class QueueController
{
    public function listQueues(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        $storage = new QueueStorage();
        return $this->json($response, $storage->listQueues($accountId));
    }

    public function resetQueue(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        $ruleHash  = $args['ruleHash'] ?? '';

        if (!$accountId || !$ruleHash) {
            return $this->json($response, ['error' => 'account_id and ruleHash required'], 400);
        }

        $storage = new QueueStorage();
        $storage->resetQueue($accountId, $ruleHash);

        return $this->json($response, ['status' => 'ok', 'rule_hash' => $ruleHash]);
    }

    public function getLog(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        $limit = min((int) ($request->getQueryParams()['limit'] ?? 100), 500);
        $log   = new DistributionLog();

        return $this->json($response, $log->tail($accountId, $limit));
    }

    private function accountId(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('X-Account-Id')
            ?: (string) ($request->getQueryParams()['account_id'] ?? '');
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
