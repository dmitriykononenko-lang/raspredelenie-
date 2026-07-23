<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Distribution\StatusStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Онлайн-статусы менеджеров в распределении.
 *
 *   GET  /api/status              — все статусы аккаунта
 *   PUT  /api/status/{userId}     — включить/выключить менеджера
 *   GET  /api/status/history      — журнал переключений
 */
class StatusController
{
    private StatusStorage $storage;

    public function __construct()
    {
        $this->storage = new StatusStorage();
    }

    public function listAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }
        return $this->json($response, ['statuses' => $this->storage->getAll($accountId)]);
    }

    public function set(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        $userId = (int) ($args['userId'] ?? 0);
        if ($userId <= 0) {
            return $this->json($response, ['error' => 'userId required'], 400);
        }

        $body = (array) $request->getParsedBody();
        if (!array_key_exists('online', $body)) {
            return $this->json($response, ['error' => 'online (bool) required'], 400);
        }
        $online  = filter_var($body['online'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($online === null) {
            return $this->json($response, ['error' => 'online must be boolean'], 400);
        }
        $actorId = isset($body['actor_id']) ? (int) $body['actor_id'] : null;

        $this->storage->set($accountId, $userId, $online, $actorId);

        return $this->json($response, [
            'status'  => 'ok',
            'user_id' => $userId,
            'online'  => $online,
        ]);
    }

    public function history(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }
        $limit = (int) ($request->getQueryParams()['limit'] ?? 200);
        return $this->json($response, ['history' => $this->storage->history($accountId, max(1, min(1000, $limit)))]);
    }

    private function accountId(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('X-Account-Id')
            ?: (string) ($request->getQueryParams()['account_id'] ?? '');
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status);
    }
}
