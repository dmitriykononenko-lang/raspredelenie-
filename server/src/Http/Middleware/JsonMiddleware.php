<?php

declare(strict_types=1);

namespace DealDist\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Parses JSON request body and sets JSON Content-Type on all responses.
 */
class JsonMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            if ($body !== '') {
                $parsed = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request = $request->withParsedBody($parsed);
                }
            }
        }

        $response = $handler->handle($request);

        // Respect a Content-Type the handler already set (e.g. text/html for the admin panel).
        // JSON endpoints set application/json themselves, so this only frees non-JSON responses.
        if ($response->hasHeader('Content-Type')) {
            return $response;
        }

        return $response->withHeader('Content-Type', 'application/json');
    }
}
