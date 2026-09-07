<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\AmoCRM\ApiClient;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Данные amoCRM для виджета БЕЗ глобальной константы площадки в коде виджета
 * (требование модерации публичной интеграции).
 *
 * GET /api/amo/bootstrap?subdomain=<sub>
 *   Резолвит числовой account_id по поддомену (из хранилища токенов), затем по
 *   OAuth-токену аккаунта тянет список к лопьзователей и воронок. Один запрос —
 *   виджет наполняет UI (сотрудники, воронки, этапы) без window.AMOCRM.
 *   Всегда отдаёт 200: при отсутствии токена/ошибке возвращает пустые списки,
 *   чтобы страница виджета отрисовалась, а не висела пустой.
 */
class AmoDataController
{
    private string $storage;

    public function __construct(private readonly Logger $logger)
    {
        $this->storage = rtrim($_ENV['STORAGE_PATH'] ?? '/storage', '/');
    }

    public function bootstrap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q   = $request->getQueryParams();
        $raw = trim((string) ($q['account_id'] ?? $request->getHeaderLine('X-Account-Id')));
        $sub = trim((string) ($q['subdomain'] ?? $request->getHeaderLine('X-Account-Subdomain')));

        $accountId = $this->resolveAccountId($raw, $sub);

        $out = [
            'ok'         => true,
            'account_id' => $accountId,
            'subdomain'  => $sub,
            'users'      => [],
            'pipelines'  => [],
        ];

        if ($accountId === '') {
            $out['ok']    = false;
            $out['error'] = 'account_not_resolved';
            return $this->json($response, $out);
        }

        $api = new ApiClient($this->logger);
        try {
            $out['users'] = $api->getUsers($accountId);
        } catch (\Throwable $e) {
            $this->logger->warning('bootstrap: users failed', ['account_id' => $accountId, 'err' => $e->getMessage()]);
        }
        try {
            $out['pipelines'] = $api->getPipelines($accountId);
        } catch (\Throwable $e) {
            $this->logger->warning('bootstrap: pipelines failed', ['account_id' => $accountId, 'err' => $e->getMessage()]);
        }

        return $this->json($response, $out);
    }

    /**
     * Числовой account_id: если пришёл числом — берём как есть; иначе ищем
     * файл токена, чеистосыл base_domain начинается