<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Distribution\MonthScheduleStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Помесячный сменный график (v1.3.0).
 *
 *   GET /api/schedule-month?month=YYYY-MM
 *       → { month, timezone, shifts: {code:{start,end}}, grid: {userId:{day:code}}, months: [...] }
 *   PUT /api/schedule-month  { month, grid, shifts?, timezone? }
 *       → { status: "ok" }
 *
 * account_id: заголовок X-Account-Id (виджет так и шлёт), либо ?account_id= / в теле.
 */
class MonthScheduleController
{
    private MonthScheduleStorage $storage;

    public function __construct()
    {
        $this->storage = new MonthScheduleStorage();
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if ($accountId === '') {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }
        $q  = $request->getQueryParams();
        $ym = (string) ($q['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            return $this->json($response, ['error' => 'bad month, expected YYYY-MM'], 400);
        }
        $cfg = $this->storage->getShifts($accountId);
        return $this->json($response, [
            'month'    => $ym,
            'timezone' => $cfg['timezone'],
            'shifts'   => $cfg['shifts'],
            'grid'     => $this->storage->getMonth($accountId, $ym),
            'months'   => $this->storage->listMonths($accountId),
        ]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = $this->accountId($request);
        if ($accountId === '') {
            return $this->json($response, ['error' => 'account_id required'], 400);
        }
        $body = (array) $request->getParsedBody();
        $ym   = (string) ($body['month'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            return $this->json($response, ['error' => 'bad month, expected YYYY-MM'], 400);
        }
        if (isset($body['shifts']) && is_array($body['shifts'])) {
            $cfg = $this->storage->getShifts($accountId);
            $this->storage->saveShifts($accountId, $body['shifts'], (string) ($body['timezone'] ?? $cfg['timezone']));
        }
        $grid = is_array($body['grid'] ?? null) ? $body['grid'] : [];
        $this->storage->saveMonth($accountId, $ym, $grid);
        return $this->json($response, ['status' => 'ok', 'month' => $ym]);
    }

    private function accountId(ServerRequestInterface $request): string
    {
        $body = (array) $request->getParsedBody();
        $acc  = (string) ($body['account_id'] ?? '');
        if ($acc === '') $acc = (string) $request->getHeaderLine('X-Account-Id');
        if ($acc === '') $acc = (string) ($request->getQueryParams()['account_id'] ?? '');
        return preg_replace('/[^0-9]/', '', $acc);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
