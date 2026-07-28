<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Distribution\TemplateStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Шаблоны распределения.
 *
 *   GET    /api/templates          — список
 *   POST   /api/templates          — создать
 *   PUT    /api/templates/{id}     — обновить
 *   DELETE /api/templates/{id}     — удалить
 */
class TemplateController
{
    private TemplateStorage $storage;

    public function __construct()
    {
        $this->storage = new TemplateStorage();
    }

    public function listAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }
        return $this->json($response, ['templates' => array_values($this->storage->all($accountId))]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        $body = (array) $request->getParsedBody();
        if (trim((string) ($body['name'] ?? '')) === '') {
            return $this->json($response, ['error' => 'name required'], 400);
        }

        return $this->json($response, ['template' => $this->storage->create($accountId, $body)], 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        $id  = (string) ($args['id'] ?? '');
        $tpl = $this->storage->update($accountId, $id, (array) $request->getParsedBody());

        if ($tpl === null) {
            return $this->json($response, ['error' => 'template not found'], 404);
        }
        return $this->json($response, ['template' => $tpl]);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if (!$accountId) {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }

        $ok = $this->storage->delete($accountId, (string) ($args['id'] ?? ''));
        if (!$ok) {
            return $this->json($response, ['error' => 'template not found'], 404);
        }
        return $this->json($response, ['status' => 'ok']);
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
