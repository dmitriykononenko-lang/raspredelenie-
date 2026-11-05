<?php

declare(strict_types=1);

namespace DealDist\Http\Middleware;

use DealDist\Accounts\AccountRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Отмечает, что клиент открывал виджет: любой запрос виджета к /api/* с X-Account-Id
 * обновляет first_opened_at / last_seen_at в карточке аккаунта (не чаще раза в 5 минут).
 */
final class ActivityMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        try {
            if (strncmp($request->getUri()->getPath(), '/api/', 5) === 0 && $request->getMethod() !== 'OPTIONS') {
                $acc = preg_replace('/[^0-9]/', '', $request->getHeaderLine('X-Account-Id'));
                if ($acc !== '' && $response->getStatusCode() < 500) {
                    (new AccountRegistry())->touch($acc);
                }
            }
        } catch (\Throwable $e) {
            // учёт активности никогда не ломает ответ
        }
        return $response;
    }
}
