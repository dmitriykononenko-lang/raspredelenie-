<?php

declare(strict_types=1);

namespace DealDist\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Проверяет общий секрет виджет↔бэкенд на защищённых маршрутах.
 *
 * Правила (см. docs/AMOCRM-WIDGET-BILLING-LESSONS.md §2.4):
 *   • секрет передаётся заголовком X-Security-Key, не в URL;
 *   • значение должно совпадать с WIDGET_SECRET из .env;
 *   • сравнение — hash_equals (защита от timing-атак).
 *
 * Проверка ОПТ-ИН и включается только когда:
 *   WIDGET_SECURITY_ENFORCE=true  И  WIDGET_SECRET задан (не плейсхолдер).
 * Так текущий деплой (secret сгенерирован, но в виджете ещё не прописан)
 * не ломается — включаешь enforce, когда ключ прописан и в .env, и в настройках виджета.
 *
 * Область: только маршруты /api/*. Вебхук amoCRM (/webhook/leads), /health и
 * /oauth/callback ключ не шлют и не должны блокироваться.
 */
class AuthMiddleware implements MiddlewareInterface
{
    private const PLACEHOLDER = 'change_me_to_a_random_string';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // CORS preflight не несёт заголовков — пропускаем, ответит CorsMiddleware.
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        // Защищаем только /api/*
        if (strncmp($path, '/api/', 5) !== 0) {
            return $handler->handle($request);
        }

        if (!$this->enforceEnabled()) {
            return $handler->handle($request);
        }

        $expected = (string) ($_ENV['WIDGET_SECRET'] ?? '');
        $provided = $request->getHeaderLine('X-Security-Key');

        if ($expected === '' || $expected === self::PLACEHOLDER || !hash_equals($expected, $provided)) {
            $response = new Response(401);
            $response->getBody()->write(json_encode([
                'status'  => 'error',
                'message' => 'Invalid or missing X-Security-Key',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }

    private function enforceEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['WIDGET_SECURITY_ENFORCE'] ?? '')));
        return in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }
}
