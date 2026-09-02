<?php

declare(strict_types=1);

namespace DealDist\Http\Controller;

use DealDist\Billing\BillingService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Онлайн-оплата подписки на виджет «Распределение» через ЮKassa.
 * Вся логика — в DealDist\Billing\BillingService; контроллер только HTTP-обёртка.
 *
 *   POST /api/billing/quote     { plan_id, users }                  — расчёт суммы (для UI)
 *   POST /api/billing/checkout  { account_id?, plan_id, users, email? } — создать платёж
 *   POST /webhook/yookassa      { object:{ id } }                   — приём уведомления (тело не доверяем)
 *
 * account_id также берётся из заголовка X-Account-Id (виджет так и шлёт).
 */
final class BillingController
{
    private BillingService $svc;

    public function __construct()
    {
        $this->svc = new BillingService();
    }

    private function json(ResponseInterface $r, array $data, int $status = 200): ResponseInterface
    {
        $r->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $r->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function body(ServerRequestInterface $req): array
    {
        $b = $req->getParsedBody();
        if (is_array($b) && $b) return $b;
        $raw = (string) $req->getBody();
        if ($raw !== '') { $d = json_decode($raw, true); if (is_array($d)) return $d; }
        return $req->getQueryParams();
    }

    /** POST /api/billing/quote  { plan_id, users } */
    public function quote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $b      = $this->body($request);
        $planId = (int) ($b['plan_id'] ?? 0);
        $users  = (int) ($b['users'] ?? 0);
        $q = $this->svc->quote($planId, $users);
        if (!$q['ok']) {
            return $this->json($response, $q, 400);
        }
        return $this->json($response, ['ok' => true, 'quote' => $q, 'plans' => $this->svc->allQuotes($users)]);
    }

    /** POST /api/billing/checkout  { account_id?, plan_id, users, email? } */
    public function checkout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $b       = $this->body($request);
        $account = (string) ($b['account_id'] ?? $request->getHeaderLine('X-Account-Id'));
        $planId  = (int) ($b['plan_id'] ?? 0);
        $users   = (int) ($b['users'] ?? 0);
        $email   = (string) ($b['email'] ?? '');

        $res = $this->svc->createCheckout($account, $planId, $users, $email, 'widget');
        $status = (int) ($res['status'] ?? 200);
        unset($res['status']);
        return $this->json($response, $res, $status);
    }

    /** POST /webhook/yookassa  { event, object:{ id } } — публичный, тело не доверяем. */
    public function webhook(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $b  = $this->body($request);
        $id = (string) ($b['object']['id'] ?? '');
        if ($id !== '') {
            try {
                $this->svc->applyPayment($id);
            } catch (\Throwable $e) {
                // ЮKassa всегда ждёт 200; ошибку глушим (poll подхватит позже).
            }
        }
        return $this->json($response, ['ok' => true]); // всегда 200
    }
}
