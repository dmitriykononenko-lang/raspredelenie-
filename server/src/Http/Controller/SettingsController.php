<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Config\SettingsStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SettingsController
{
    private SettingsStorage $storage;

    public function __construct()
    {
        $this->storage = new SettingsStorage();
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body      = (array) $request->getParsedBody();
        $accountId = (string) ($body['account_id'] ?? $request->getHeaderLine('X-Account-Id'));
        $settings  = $body['settings'] ?? [];

        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        $this->storage->save($accountId, $settings);

        return $this->json($response, ['status' => 'ok']);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $request->getHeaderLine('X-Account-Id')
            ?: ($request->getQueryParams()['account_id'] ?? '');

        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        return $this->json($response, $this->storage->load($accountId));
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status);
    }
}
