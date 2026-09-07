<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\AmoCRM\ApiClient;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/amo/bootstrap — данные аккаунта для виджета БЕЗ window.AMOCRM
 * (требование модерации amoМаркета: «Использование константы AMOCRM» запрещено).
 *
 * Вход: ?account_id=<id> | ?subdomain=<sub> | заголовки X-Account-Id / X-Account-Subdomain.
 * Выход: { ok, account_id, subdomain, users:[{id,name,group}], groups:{key:name}, pipelines:[{id,name,statuses:[...]}] }
 *
 * v1.3.3: добавлены группы (отделы) — из /api/v4/account?with=users_groups + rights.group_id у пользователей.
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

        $accountId = $this->resolve($raw, $sub);
        $out = ['ok' => true, 'account_id' => $accountId, 'subdomain' => $sub, 'users' => [], 'groups' => new \stdClass(), 'pipelines' => []];

        if ($accountId === '') {
            $out['ok'] = false;
            $out['error'] = 'account_not_resolved';
            return $this->json($response, $out);
        }

        $api = new ApiClient($this->logger);

        $groups = [];
        try { $groups = $api->getUserGroups($accountId); }
        catch (\Throwable $e) { $this->logger->warning('bootstrap groups failed', ['acc' => $accountId, 'err' => $e->getMessage()]); }

        try { $out['users'] = $api->getUsers($accountId, $groups); }
        catch (\Throwable $e) { $this->logger->warning('bootstrap users failed', ['acc' => $accountId, 'err' => $e->getMessage()]); }

        try { $out['pipelines'] = $api->getPipelines($accountId); }
        catch (\Throwable $e) { $this->logger->warning('bootstrap pipelines failed', ['acc' => $accountId, 'err' => $e->getMessage()]); }

        // groups как объект {"group_0":"Отдел продаж", ...} — тот же формат ключей, что был у AMOCRM.constant('groups')
        $g = [];
        foreach ($groups as $id => $name) { $g['group_' . $id] = $name; }
        $out['groups'] = $g ?: new \stdClass();

        return $this->json($response, $out);
    }

    private function resolve(string $raw, string $sub): string
    {
        if ($raw !== '' && ctype_digit($raw)) return $raw;
        $needle = strtolower($sub !== '' ? $sub : $raw);
        if ($needle === '') return '';

        $ltAcc = trim((string) ($_ENV['AMO_ACCOUNT_ID'] ?? ''));
        $ltDom = strtolower(trim((string) ($_ENV['AMO_BASE_DOMAIN'] ?? '')));
        if ($ltAcc !== '' && $ltDom !== '' && strpos($ltDom, $needle) === 0) return $ltAcc;

        foreach (glob($this->storage . '/tokens/*.json') ?: [] as $f) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (!is_array($d)) continue;
            $bd = strtolower((string) ($d['base_domain'] ?? ''));
            if ($bd !== '' && ($bd === $needle || strpos($bd, $needle . '.') === 0)) {
                return (string) preg_replace('/\.json$/', '', basename($f));
            }
        }
        return '';
    }

    private function json(ResponseInterface $r, array $data, int $status = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $r->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
